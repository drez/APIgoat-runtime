<?php

namespace ApiGoat\Model;

class Authy
{
    /**
     * Re-entrancy guard. recomputeRightsFromGroups() saves the authy row; this
     * flag is the authoritative protection against the save() re-triggering the
     * recompute (the postSave trigger acts on a per-instance flag set in
     * preSave, which this method never sets on the rows it writes — but $busy
     * guarantees termination regardless of call site).
     */
    private static $busy = false;

    /**
     * Recompute a user's stored rights as the union of every group they belong
     * to (primary group + all authy_group_x memberships). Groups are the sole
     * source of truth: the union is rebuilt from scratch on every change.
     */
    static function recomputeRightsFromGroups(int $idAuthy): void
    {
        if (self::$busy) {
            return;
        }
        self::$busy = true;
        try {
            // Read committed DB state, not pooled instances. Production triggers
            // fire right after a same-request membership / primary-group / group
            // mutation; without this the pooled (pre-mutation) row would yield a
            // stale union that then persists. (Tests had to clearInstancePool()
            // around every recompute for the same reason.)
            \App\AuthyPeer::clearInstancePool();
            \App\AuthyGroupPeer::clearInstancePool();
            \App\AuthyGroupXPeer::clearInstancePool();

            $authy = \App\AuthyQuery::create()->findPk($idAuthy);
            if (!$authy) {
                return;
            }

            // Collect group ids: primary group + every membership, de-duplicated.
            // Order the memberships deterministically so the union is stable.
            $groupIds = [];
            if ($authy->getIdAuthyGroup()) {
                $groupIds[$authy->getIdAuthyGroup()] = true;
            }
            $memberships = \App\AuthyGroupXQuery::create()
                ->filterByIdAuthy($idAuthy)
                ->orderByIdAuthyGroup()
                ->find();
            foreach ($memberships as $membership) {
                if ($membership->getIdAuthyGroup()) {
                    $groupIds[$membership->getIdAuthyGroup()] = true;
                }
            }

            // Gather each group's three rights buckets.
            $allJson   = [];
            $ownerJson = [];
            $groupJson = [];
            foreach (array_keys($groupIds) as $groupId) {
                $group = \App\AuthyGroupQuery::create()->findPk($groupId);
                if (!$group) {
                    continue;
                }
                $allJson[]   = $group->getRightsAll();
                $ownerJson[] = $group->getRightsOwner();
                $groupJson[] = $group->getRightsGroup();
            }

            $authy->setRightsAll(self::mergeRightsBuckets($allJson));
            $authy->setRightsOwner(self::mergeRightsBuckets($ownerJson));
            $authy->setRightsGroup(self::mergeRightsBuckets($groupJson));
            $authy->save();
        } finally {
            self::$busy = false;
        }
    }

    /**
     * Union one bucket's worth of group rights JSON. Each input is a single
     * group's bucket (rights_all / rights_owner / rights_group) as a JSON
     * string; NULL/'' is treated as {}. Per model key the permission letters
     * are unioned and normalized to the canonical r,w,a,d order so the encoded
     * output is stable and diff-friendly. Returns a JSON object string.
     */
    static function mergeRightsBuckets(array $jsonStrings): string
    {
        $canonical = ['r', 'w', 'a', 'd'];

        // model => [letter => true]
        $merged = [];
        foreach ($jsonStrings as $json) {
            if ($json === null || $json === '') {
                continue;
            }
            $decoded = json_decode($json, true);
            if (!is_array($decoded)) {
                continue;
            }
            foreach ($decoded as $model => $letters) {
                if (!isset($merged[$model])) {
                    $merged[$model] = [];
                }
                foreach (str_split((string) $letters) as $letter) {
                    $merged[$model][$letter] = true;
                }
            }
        }

        $out = [];
        foreach ($merged as $model => $letterSet) {
            $ordered = '';
            foreach ($canonical as $letter) {
                if (isset($letterSet[$letter])) {
                    $ordered .= $letter;
                    unset($letterSet[$letter]);
                }
            }
            // Defensive: keep any non-canonical letters, sorted for determinism.
            $residual = array_keys($letterSet);
            sort($residual);
            foreach ($residual as $letter) {
                $ordered .= $letter;
            }
            $out[$model] = $ordered;
        }

        return json_encode($out, JSON_FORCE_OBJECT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE) ?: '{}';
    }
}
