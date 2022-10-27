<?php

// Icinga Web 2 X.509 Module | (c) 2018 Icinga GmbH | GPLv2

namespace Icinga\Module\X509;

use DateTime;
use Exception;
use Generator;
use Icinga\Application\Logger;
use Icinga\Data\ConfigObject;
use Icinga\Module\X509\Common\Database;
use Icinga\Module\X509\Common\JobUtils;
use Icinga\Module\X509\React\StreamOptsCaptureConnector;
use Icinga\Util\Json;
use ipl\Scheduler\Contract\Task;
use ipl\Sql\Connection;
use ipl\Sql\Expression;
use ipl\Sql\Select;
use ipl\Stdlib\Str;
use LogicException;
use Ramsey\Uuid\Uuid;
use React\EventLoop\Loop;
use React\Promise;
use React\Socket\ConnectionInterface;
use React\Socket\Connector;
use React\Socket\ConnectorInterface;
use React\Socket\SecureConnector;
use React\Socket\TimeoutConnector;
use Throwable;

class Job implements Task
{
    use Database;
    use JobUtils;

    /** @var Connection x509 database connection */
    private $db;

    /** @var DbTool Database utils for marshalling and unmarshalling binary data */
    private $dbTool;

    private $pendingTargets = 0;
    private $totalTargets = 0;
    private $finishedTargets = 0;

    /** @var Generator */
    private $targets;
    private $snimap;

    protected $jobId;

    /** @var Promise\Deferred React promise deferred instance used to resolve the running promise */
    protected $deferred;

    /** @var int Used to control how many targets can be scanned in parallel */
    protected $parallel;

    /** @var DateTime A formatted date time of this job start time */
    protected $jobRunStart;

    /** @var array A list of excluded IP addresses and host names */
    protected $excludedTargets = [];

    /** @var DateTime Since last scan threshold used to filter out scan targets */
    protected $sinceLastScan;

    /** @var bool Whether job run should only perform a rescan */
    protected $rescan = false;

    public function __construct(string $name, ConfigObject $config, array $snimap)
    {
        $this->db = $this->getDb();
        $this->dbTool = new DbTool($this->db);
        $this->snimap = $snimap;

        $this->setConfig($config);
        $this->setName($name);
        $this->setUuid(Uuid::fromBytes($this->getChecksum()));
    }

    /**
     * Get excluded IPs and host names
     *
     * @return array
     */
    protected function getExcludes(): array
    {
        if (empty($this->excludedTargets)) {
            $this->excludedTargets = array_flip(Str::trimSplit($this->getConfig()->get('exclude_targets')));
        }

        return $this->excludedTargets;
    }

    private function getConnector($peerName)
    {
        $simpleConnector = new Connector();
        $streamCaptureConnector = new StreamOptsCaptureConnector($simpleConnector);
        $secureConnector = new SecureConnector($streamCaptureConnector, null, [
            'verify_peer'             => false,
            'verify_peer_name'        => false,
            'capture_peer_cert_chain' => true,
            'SNI_enabled'             => true,
            'peer_name'               => $peerName
        ]);
        return [new TimeoutConnector($secureConnector, 5.0), $streamCaptureConnector];
    }

    /**
     * Get whether this task has been completed
     *
     * @return bool
     */
    public function isFinished(): bool
    {
        return ! $this->targets->valid() && $this->pendingTargets === 0;
    }

    public function getParallel(): int
    {
        return $this->parallel;
    }

    public function setParallel(int $parallel): self
    {
        $this->parallel = $parallel;

        return $this;
    }

    /**
     * Get whether this job run should do only a rescan
     *
     * @return bool
     */
    public function isRescan(): bool
    {
        return $this->rescan;
    }

    /**
     * Set whether this job run should do only a rescan or full scan
     *
     * @param bool $rescan
     *
     * @return $this
     */
    public function setRescan(bool $rescan): self
    {
        $this->rescan = $rescan;

        return $this;
    }

    /**
     * Set since last scan threshold for the targets to rescan
     *
     * @param ?DateTime $dateTime
     *
     * @return $this
     */
    public function setLastScan(DateTime $dateTime = null): self
    {
        $this->sinceLastScan = $dateTime;

        return $this;
    }

    protected function updateLastScan($target)
    {
        if (! $this->isRescan()) {
            return;
        }

        $this->db->update('x509_target', [
            'last_scan' => new Expression('UNIX_TIMESTAMP()')
        ], ['id = ?' => $target->id]);
    }

    protected function getChecksum()
    {
        $config = $this->getConfig()->toArray();
        ksort($config);

        return md5($this->getName() . Json::encode($config), true);
    }

    protected function getScanTargets(): Generator
    {
        if (! $this->isRescan()) {
            yield from $this->generateTargets();
        } else {
            $targets = (new Select())
                ->columns(['id', 'ip', 'hostname', 'port'])
                ->from('x509_target');

            if ($this->sinceLastScan) {
                $targets->where(new Expression('last_scan < %d', [$this->sinceLastScan->getTimestamp() * 1000.0]));
            }

            foreach ($this->db->select($targets) as $target) {
                $addr = gmp_import(DbTool::unmarshalBinary($target->ip));
                $addrFound = false;
                foreach ($this->getCidrs() as $cidr) {
                    list($subnet, $mask) = $cidr;
                    if (static::isAddrInside($addr, $subnet, $mask)) {
                        $target->ip = static::numberToAddr($addr, static::isIPV6($subnet));
                        $addrFound = true;

                        break;
                    }
                }

                if ($addrFound) {
                    yield $target;
                }
            }
        }
    }

    private function generateTargets()
    {
        $excludes = $this->getExcludes();
        foreach ($this->getCidrs() as $cidr) {
            list($startIp, $prefix) = $cidr;
            $ipv6 = static::isIPV6($startIp);
            $subnet = $ipv6 ? 128 : 32;
            $numIps = pow(2, ($subnet - $prefix)) - 2;

            Logger::info('Scanning %d IPs in the CIDR %s', $numIps, implode('/', $cidr));

            $start = static::addrToNumber($startIp);
            for ($i = 0; $i < $numIps; $i++) {
                $ip = static::numberToAddr(gmp_add($start, $i), $ipv6);
                if (isset($excludes[$ip])) {
                    Logger::debug('Excluding IP %s from scan', $ip);
                    continue;
                }

                foreach ($this->getPorts() as $portRange) {
                    list($startPort, $endPort) = $portRange;
                    foreach (range($startPort, $endPort) as $port) {
                        foreach ($this->snimap[$ip] ?? [] as $hostname) {
                            if (isset($excludes[$hostname])) {
                                Logger::debug('Excluding host %s from scan', $hostname);
                                continue;
                            }

                            $target = (object) [];
                            $target->ip = $ip;
                            $target->port = $port;
                            $target->hostname = $hostname;

                            yield $target;
                        }
                    }
                }
            }
        }
    }

    public function updateJobStats(bool $finished = false): void
    {
        $fields = ['finished_targets' => $this->finishedTargets];

        if ($finished) {
            $fields['end_time'] = new Expression('NOW()');
            $fields['total_targets'] = $this->totalTargets;
        }

        $this->db->update(
            'x509_job_run',
            $fields,
            ['id = ?' => $this->jobId]
        );
    }

    private static function formatTarget($target)
    {
        $result = "tls://[{$target->ip}]:{$target->port}";

        if ($target->hostname !== null) {
            $result .= " [SNI hostname: {$target->hostname}]";
        }

        return $result;
    }

    private function finishTarget()
    {
        $this->pendingTargets--;
        $this->finishedTargets++;
        $this->startNextTarget();
    }

    private function startNextTarget()
    {
        if ($this->isFinished()) {
            // No targets to process anymore, so we can now resolve the promise
            $this->deferred->resolve($this->finishedTargets);

            return;
        }

        if (! $this->targets->valid()) {
            // When nothing is yielded, and it's still not finished yet, just get the next target
            return;
        }

        $target = $this->targets->current();
        $this->targets->next();

        $this->totalTargets++;
        $this->pendingTargets++;

        $url = "tls://[{$target->ip}]:{$target->port}";
        Logger::debug("Connecting to %s", static::formatTarget($target));

        /** @var ConnectorInterface $connector */
        /** @var StreamOptsCaptureConnector $streamCapture */
        list($connector, $streamCapture) = $this->getConnector($target->hostname);
        $connector->connect($url)->then(
            function (ConnectionInterface $conn) use ($target, $streamCapture) {
                Logger::info("Connected to %s", static::formatTarget($target));

                // Close connection in order to capture stream context options
                $conn->close();

                $capturedStreamOptions = $streamCapture->getCapturedStreamOptions();

                $this->processChain($target, $capturedStreamOptions['ssl']['peer_certificate_chain']);

                $this->finishTarget();
            },
            function (Exception $exception) use ($target, $streamCapture) {
                Logger::debug("Cannot connect to server: %s", $exception->getMessage());

                $capturedStreamOptions = $streamCapture->getCapturedStreamOptions();

                if (isset($capturedStreamOptions['ssl']['peer_certificate_chain'])) {
                    // The scanned target presented its certificate chain despite throwing an error
                    // This is the case for targets which require client certificates for example
                    $this->processChain($target, $capturedStreamOptions['ssl']['peer_certificate_chain']);
                } else {
                    $this->db->update(
                        'x509_target',
                        ['latest_certificate_chain_id' => null],
                        [
                            'hostname = ?' => $target->hostname,
                            'ip = ?'       => $this->dbTool->marshalBinary(static::binary($target->ip)),
                            'port = ?'     => $target->port
                        ]
                    );
                }

                $step = max($this->totalTargets / 100, 1);

                if ($this->finishedTargets % (int) $step == 0) {
                    $this->updateJobStats();
                }

                $this->finishTarget();
            }
        )->always(function () use ($target) {
            $this->updateLastScan($target);
        })->otherwise(function (Throwable $e) {
            Logger::error($e->getMessage());
            Logger::error($e->getTraceAsString());
        });
    }

    public function run(): Promise\ExtendedPromiseInterface
    {
        $this->jobRunStart = new DateTime();
        // Update the job statistics regardless of whether the job was successful, failed, or canceled.
        // Otherwise, some database columns might remain null.
        $updateJobStats = function () {
            $this->updateJobStats(true);
        };
        $this->deferred = new Promise\Deferred($updateJobStats);
        $this->deferred->promise()->always($updateJobStats);

        Loop::futureTick(function () {
            if (! $this->db->ping()) {
                $this->deferred->reject(new LogicException('Lost connection to database and failed to reconnect'));

                return;
            }

            // Reset those statistics for the next run! Is only necessary when
            // running this job using the scheduler
            $this->totalTargets = 0;
            $this->finishedTargets = 0;
            $this->pendingTargets = 0;

            $this->db->insert('x509_job_run', [
                'name'             => $this->getName(),
                'start_time'       => $this->jobRunStart->format('Y-m-d H:i:s'),
                'ctime'            => new Expression('NOW()'),
                'total_targets'    => 0,
                'finished_targets' => 0
            ]);

            $this->jobId = $this->db->lastInsertId();

            $this->targets = $this->getScanTargets();

            if ($this->isFinished()) {
                // There are no targets to scan, so we can resolve the promise earlier
                $this->deferred->resolve(0);

                return;
            }

            // Start scanning the first couple of targets...
            for ($i = 0; $i < $this->getParallel() && ! $this->isFinished(); $i++) {
                $this->startNextTarget();
            }
        });

        return $this->deferred->promise();
    }

    protected function processChain($target, $chain)
    {
        if ($target->hostname === null) {
            $hostname = gethostbyaddr($target->ip);

            if ($hostname !== false) {
                $target->hostname = $hostname;
            }
        }

        $this->db->transaction(function () use ($target, $chain) {
            $row = $this->db->select(
                (new Select())
                    ->columns(['id'])
                    ->from('x509_target')
                    ->where([
                        'ip = ?'       => $this->dbTool->marshalBinary(static::binary($target->ip)),
                        'port = ?'     => $target->port,
                        'hostname = ?' => $target->hostname
                    ])
            )->fetch();

            if ($row === false) {
                $this->db->insert(
                    'x509_target',
                    [
                        'ip'        => $this->dbTool->marshalBinary(static::binary($target->ip)),
                        'port'      => $target->port,
                        'hostname'  => $target->hostname,
                        'last_scan' => new Expression('UNIX_TIMESTAMP()')
                    ]
                );
                $targetId = $this->db->lastInsertId();
            } else {
                $targetId = $row->id;
            }

            $chainUptodate = false;

            $lastChain = $this->db->select(
                (new Select())
                    ->columns(['id'])
                    ->from('x509_certificate_chain')
                    ->where(['target_id = ?' => $targetId])
                    ->orderBy('id', SORT_DESC)
                    ->limit(1)
            )->fetch();

            if ($lastChain !== false) {
                $lastFingerprints = $this->db->select(
                    (new Select())
                        ->columns(['c.fingerprint'])
                        ->from('x509_certificate_chain_link l')
                        ->join('x509_certificate c', 'l.certificate_id = c.id')
                        ->where(['l.certificate_chain_id = ?' => $lastChain->id])
                        ->orderBy('l.order')
                )->fetchAll();

                foreach ($lastFingerprints as &$lastFingerprint) {
                    $lastFingerprint = $lastFingerprint->fingerprint;
                }

                $currentFingerprints = [];

                foreach ($chain as $cert) {
                    $currentFingerprints[] = openssl_x509_fingerprint($cert, 'sha256', true);
                }

                $chainUptodate = $currentFingerprints === $lastFingerprints;
            }

            if ($chainUptodate) {
                $chainId = $lastChain->id;
            } else {
                $this->db->insert(
                    'x509_certificate_chain',
                    [
                        'target_id' => $targetId,
                        'length'    => count($chain)
                    ]
                );

                $chainId = $this->db->lastInsertId();

                foreach ($chain as $index => $cert) {
                    $certId = CertificateUtils::findOrInsertCert($this->db, $cert);

                    $this->db->insert(
                        'x509_certificate_chain_link',
                        [
                            'certificate_chain_id'              => $chainId,
                            $this->db->quoteIdentifier('order') => $index,
                            'certificate_id'                    => $certId
                        ]
                    );
                }
            }

            $this->db->update(
                'x509_target',
                ['latest_certificate_chain_id' => $chainId],
                ['id = ?' => $targetId]
            );
        });
    }
}
