<?php

namespace Icinga\Module\X509\Model\Behavior;

use ipl\Orm\Behavior\Binary;
use ipl\Orm\Query;
use ipl\Sql\Adapter\Pgsql;
use ipl\Stdlib\Filter\Condition;

/**
 * Support automatically transformation of human-readable IP addresses into their respective packed
 * binary representation and vice versa.
 */
class Ip extends Binary
{
    /** @var bool Whether the query is using a pgsql adapter */
    protected $isPostgres = true;

    public function fromDb($value, $key, $_)
    {
        $value = parent::fromDb($value, $key, $_);
        if ($value === null) {
            return null;
        }

        $ipv4 = ltrim($value, "\0");
        if (strlen($ipv4) === 4) {
            $value = $ipv4;
        }

        return inet_ntop($value);
    }

    public function toDb($value, $key, $_)
    {
        if ($value === null || $value === '*' || ! ctype_print($value)) {
            return $value;
        }

        $pad = str_pad(inet_pton($value), 16, "\0", STR_PAD_LEFT);
        if (! $this->isPostgres) {
            return $pad;
        }

        return parent::toDb($pad, $key, $_);
    }

    public function setQuery(Query $query)
    {
        $this->rewriteSubjects = $this->properties;

        if (! $query->getDb()->getAdapter() instanceof Pgsql) {
            // Only process properties if the adapter is PostgreSQL.
            $this->isPostgres = false;
        }
    }

    public function rewriteCondition(Condition $condition, $relation = null)
    {
        if (! $this->isPostgres) {
            // Only for PostgreSQL.
            return;
        }

        parent::rewriteCondition($condition, $relation);
    }
}
