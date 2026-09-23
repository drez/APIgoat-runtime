<?php

namespace ApiGoat\ACL;

/**
 * Auth-table privilege rules for the generic JSON API (Api::setJson /
 * deleteJson) — the runtime mirror of the emitter's Common/AuthyPrivilege,
 * which enforces the same rules on the generated HTML (Form / Service) path.
 *
 * Security review 2026-09-23: the GUI path was closed in the emitter, but the
 * API/MCP path still let a caller holding Authy 'w' (through api_rbac) edit an
 * Admin's email / username, promote a user by writing IdAuthyGroup, flip a
 * group's Admin flag, or add itself to an Admin group through authy_group_x.
 *
 *  - privileged columns (authy.IdAuthyGroup / Deactivate, authy_group.Admin /
 *    DefaultGroup) are writable by root or an Admin only. The rights matrix is
 *    already denied to everyone by Api::RIGHTS_COLUMNS.
 *  - an IsRoot / IsSystem user row may be updated or deleted by root only; an
 *    Admin user (primary group or any member group with Admin = 'Yes') by
 *    root or an Admin only.
 *  - group membership rows (authy_group_x) are written / deleted by root or an
 *    Admin only: membership grants the group's rights.
 *
 * Model names are the ones AuthySession already hard-codes (\App\Authy,
 * \App\AuthyGroup, \App\AuthyGroupX). Every decision fails closed: no session,
 * a lookup that throws or a group that does not load counts as "denied".
 *
 * Why the emitter does not call this class: every project carries its own,
 * often older, runtime clone, so generated code that referenced a new runtime
 * class would fatal until `gc upgrade`. The emitted code stays self-contained
 * and this class mirrors it (keep the two in step).
 */
final class AuthyRowGuard
{
    public const AUTH_TABLE = 'Authy';
    public const GROUP_TABLE = 'AuthyGroup';
    public const MEMBERSHIP_TABLE = 'AuthyGroupX';

    /**
     * Root-or-Admin-only columns per model, normalised like Api's column lists
     * (lowercase, underscores dropped).
     */
    public const PRIVILEGED_COLUMNS = [
        self::AUTH_TABLE => ['idauthygroup', 'deactivate'],
        self::GROUP_TABLE => ['admin', 'defaultgroup'],
    ];

    /** The current session, or null (no session = unprivileged). */
    private static function session($session)
    {
        if ($session !== null) {
            return $session;
        }
        if (!\defined('_AUTH_VAR') || !isset($_SESSION[\_AUTH_VAR]) || !\is_object($_SESSION[\_AUTH_VAR])) {
            return null;
        }
        return $_SESSION[\_AUTH_VAR];
    }

    private static function sessionIs($session, string $method): bool
    {
        $s = self::session($session);
        return $s !== null && \method_exists($s, $method) && (bool) $s->$method();
    }

    /** Root or an Admin: may manage privileges. */
    public static function isPrivilegedCaller($session = null): bool
    {
        return self::sessionIs($session, 'isRoot') || self::sessionIs($session, 'isAdmin');
    }

    public static function isPrivilegedColumn(string $model, string $column): bool
    {
        $norm = \strtolower(\str_replace('_', '', $column));
        return \in_array($norm, self::PRIVILEGED_COLUMNS[$model] ?? [], true);
    }

    /** May the caller NOT write $column of $model? */
    public static function columnDenied(string $model, string $column, $session = null): bool
    {
        return self::isPrivilegedColumn($model, $column) && !self::isPrivilegedCaller($session);
    }

    /** A write / delete on the membership junction by a non-privileged caller. */
    public static function membershipWriteDenied(string $model, $session = null): bool
    {
        return $model === self::MEMBERSHIP_TABLE && !self::isPrivilegedCaller($session);
    }

    private static function flagSet($row, string $getter): bool
    {
        if (!\method_exists($row, $getter)) {
            return false;
        }
        $v = $row->$getter();
        return $v === 'Yes' || $v === true || $v === 1 || $v === '1';
    }

    /**
     * May the caller NOT update / delete this stored $row of $model? Only the
     * auth table has locked rows. $row must be the row as loaded (before any
     * column is set), so the group read is the stored one.
     *
     * @param string $groupQuery      group Query class (tests inject a fake)
     * @param string $membershipQuery membership Query class (tests inject a fake)
     */
    public static function rowLocked(
        string $model,
        $row,
        $session = null,
        string $groupQuery = '\\App\\AuthyGroupQuery',
        string $membershipQuery = '\\App\\AuthyGroupXQuery'
    ): bool {
        if ($model !== self::AUTH_TABLE || !\is_object($row)) {
            return false;
        }
        if (self::sessionIs($session, 'isRoot')) {
            return false;
        }
        if (self::flagSet($row, 'getIsRoot') || self::flagSet($row, 'getIsSystem')) {
            return true;
        }
        if (self::sessionIs($session, 'isAdmin')) {
            return false;
        }
        try {
            return self::targetIsAdmin($row, $groupQuery, $membershipQuery);
        } catch (\Throwable $e) {
            return true;
        }
    }

    /**
     * Is the user $row an Admin: its primary group or any group it is a member
     * of has Admin = 'Yes'? A group id that does not load counts as Admin.
     */
    public static function targetIsAdmin($row, string $groupQuery = '\\App\\AuthyGroupQuery', string $membershipQuery = '\\App\\AuthyGroupXQuery'): bool
    {
        $ids = [];
        if (\method_exists($row, 'getIdAuthyGroup') && $row->getIdAuthyGroup()) {
            $ids[] = $row->getIdAuthyGroup();
        }
        if (\class_exists($membershipQuery) && \method_exists($row, 'getPrimaryKey')) {
            foreach ($membershipQuery::create()->filterByIdAuthy($row->getPrimaryKey())->find() as $x) {
                $ids[] = $x->getIdAuthyGroup();
            }
        }
        $ids = \array_values(\array_unique(\array_filter($ids)));
        if ($ids === []) {
            return false;
        }
        if (!\class_exists($groupQuery)) {
            return true;
        }
        $groups = $groupQuery::create()->filterByPrimaryKeys($ids)->find();
        if (\count($groups) < \count($ids)) {
            return true;
        }
        foreach ($groups as $g) {
            if ($g->getAdmin() === 'Yes') {
                return true;
            }
        }
        return false;
    }
}
