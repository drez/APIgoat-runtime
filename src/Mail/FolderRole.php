<?php

namespace ApiGoat\Mail;

/**
 * What a server folder IS to a mail client, and whether a client should poll it.
 *
 * Roles are the location labels of the mail store (apigmail
 * mail_message.location / mailbox_folder.role), in their enum order:
 * Inbox, Archive, Trash, Spam, Sent, Drafts, Other.
 *
 * IMAP (RFC 6154): a special-use attribute is the server's own word and
 * always wins. A name is only a fallback, for a role no folder of the
 * account declares, and only at the top level or directly under INBOX /
 * [Gmail] / [Google Mail]. "ai.spam" is a user's folder, not the junk
 * folder.
 *
 * Pollable is false for:
 *  - \Noselect / \NonExistent: nothing to select;
 *  - \All, \Flagged, \Important: views of mail that lives elsewhere, so
 *    polling them ingests every message twice;
 *  - an Other folder on a server that exposes \All (Gmail): those are
 *    labels, so a message in INBOX and in "Clients" would become two rows;
 *  - an Other folder the server reports as unsubscribed.
 *
 * Gmail API: the five system labels that are locations map to roles.
 * STARRED/UNREAD/IMPORTANT/CATEGORY_* are properties of a message, not
 * places. User labels are labels (sub-project T), not folders.
 */
final class FolderRole
{
    public const INBOX   = 'Inbox';
    public const ARCHIVE = 'Archive';
    public const TRASH   = 'Trash';
    public const SPAM    = 'Spam';
    public const SENT    = 'Sent';
    public const DRAFTS  = 'Drafts';
    public const OTHER   = 'Other';

    /** Enum order of mail_message.location / mailbox_folder.role. Append only. */
    public const ROLES = [self::INBOX, self::ARCHIVE, self::TRASH, self::SPAM, self::SENT, self::DRAFTS, self::OTHER];

    public const SOURCE_SPECIAL_USE = 'special_use';
    public const SOURCE_NAME        = 'name';
    public const SOURCE_PROVIDER    = 'provider';
    public const SOURCE_NONE        = 'none';

    /** RFC 6154 attribute (lower-case, no backslash) → role. */
    private const SPECIAL_USE = [
        'archive' => self::ARCHIVE, 'all' => self::ARCHIVE, 'junk' => self::SPAM,
        'sent' => self::SENT, 'drafts' => self::DRAFTS, 'trash' => self::TRASH,
    ];

    /** Attributes of a folder that is a VIEW of other folders. */
    private const VIEW_ATTRS = ['all', 'flagged', 'important'];

    /** Leaf names (lower-case) per role, for servers that declare nothing. */
    private const NAMES = [
        self::SENT    => ['sent', 'sent mail', 'sent items', 'sent messages', 'envoyés', 'envoyes', 'éléments envoyés', 'elements envoyes', 'gesendet', 'gesendete objekte', 'enviados', 'elementos enviados'],
        self::TRASH   => ['trash', 'deleted', 'deleted items', 'deleted messages', 'bin', 'corbeille', 'éléments supprimés', 'papierkorb', 'papelera'],
        self::SPAM    => ['spam', 'junk', 'junk e-mail', 'junk email', 'bulk mail', 'courrier indésirable', 'pourriel', 'indésirables'],
        self::DRAFTS  => ['drafts', 'draft', 'brouillons', 'entwürfe', 'borradores'],
        self::ARCHIVE => ['archive', 'archives', 'all mail', 'archiv', 'archivo'],
    ];

    /** Parents under which a name fallback is trusted (the provider's own namespace). */
    private const TRUSTED_ROOTS = ['inbox', '[gmail]', '[google mail]'];

    /** Leaf names (lower-case) of Gmail's virtual folders, for listings that carry no attributes. */
    private const GMAIL_VIEW_NAMES = ['all mail', 'tous les messages', 'starred', 'important'];

    /** Gmail system labels that are places. */
    private const GMAIL_SYSTEM = ['INBOX' => self::INBOX, 'SENT' => self::SENT, 'DRAFT' => self::DRAFTS, 'SPAM' => self::SPAM, 'TRASH' => self::TRASH];

    /**
     * Role and pollability of every folder of ONE IMAP account, in two
     * passes. The first pass collects the roles the server declares, so the
     * second never lets a name claim one of them.
     *
     * @param array<int,array{id:string,name?:string,delimiter?:string,attributes?:string[],subscribed?:bool|null}> $folders
     * @return list<array{id:string,name:string,delimiter:?string,attributes:string[],subscribed:?bool,role:string,role_source:string,pollable:bool,reason:?string}>
     */
    public static function assignImap(array $folders): array
    {
        $claimed     = [];
        $labelServer = false;
        foreach ($folders as $f) {
            if (self::isGoogleRoot((string) ($f['id'] ?? ''), $f['delimiter'] ?? null)) {
                $labelServer = true;
            }
            foreach (self::attrs((array) ($f['attributes'] ?? [])) as $a) {
                if ($a === 'all') {
                    $labelServer = true;
                }
                if (isset(self::SPECIAL_USE[$a])) {
                    $claimed[self::SPECIAL_USE[$a]] = true;
                }
            }
        }

        $out = [];
        foreach ($folders as $f) {
            $id    = (string) ($f['id'] ?? '');
            $attrs = self::attrs((array) ($f['attributes'] ?? []));
            $delim = isset($f['delimiter']) && $f['delimiter'] !== '' ? (string) $f['delimiter'] : null;
            $sub   = array_key_exists('subscribed', $f) && $f['subscribed'] !== null ? (bool) $f['subscribed'] : null;

            $role   = self::OTHER;
            $source = self::SOURCE_NONE;
            if (strcasecmp($id, 'INBOX') === 0) {
                $role   = self::INBOX;
                $source = self::SOURCE_NAME;
            } else {
                foreach ($attrs as $a) {
                    if (isset(self::SPECIAL_USE[$a])) {
                        $role   = self::SPECIAL_USE[$a];
                        $source = self::SOURCE_SPECIAL_USE;
                        break;
                    }
                }
                if ($source === self::SOURCE_NONE) {
                    $byName = self::fromName($id, $delim);
                    if ($byName !== self::OTHER && !isset($claimed[$byName])) {
                        $role   = $byName;
                        $source = self::SOURCE_NAME;
                    }
                }
            }

            $reason = null;
            if (in_array('noselect', $attrs, true) || in_array('nonexistent', $attrs, true)) {
                $reason = 'not selectable';
            } elseif (array_intersect($attrs, self::VIEW_ATTRS) !== []) {
                $reason = 'virtual folder (a view of mail stored in other folders)';
            } elseif ($labelServer && self::isGoogleRoot($id, $delim) && in_array(self::leaf($id, $delim), self::GMAIL_VIEW_NAMES, true)) {
                $reason = 'virtual folder (a view of mail stored in other folders)';
            } elseif ($role === self::OTHER && $labelServer) {
                $reason = 'label folder on a label server (would duplicate mail)';
            } elseif ($role === self::OTHER && $sub === false) {
                $reason = 'not subscribed';
            }

            $out[] = [
                'id' => $id, 'name' => (string) ($f['name'] ?? $id), 'delimiter' => $delim, 'attributes' => $attrs,
                'subscribed' => $sub, 'role' => $role, 'role_source' => $source, 'pollable' => $reason === null, 'reason' => $reason,
            ];
        }
        return $out;
    }

    /** The role a folder NAME suggests; Other when the name proves nothing. */
    public static function fromName(string $path, ?string $delimiter = null): string
    {
        $raw   = $delimiter !== null && $delimiter !== '' ? explode($delimiter, $path) : (preg_split('#[./]#', $path) ?: []);
        $parts = array_values(array_filter(array_map('trim', $raw), static fn ($p) => $p !== ''));
        if ($parts === []) {
            return self::OTHER;
        }
        if (count($parts) === 1 && strcasecmp($parts[0], 'INBOX') === 0) {
            return self::INBOX;
        }
        if (count($parts) > 2 || (count($parts) === 2 && !in_array(mb_strtolower($parts[0]), self::TRUSTED_ROOTS, true))) {
            return self::OTHER;
        }
        $leaf = mb_strtolower((string) end($parts));
        foreach (self::NAMES as $role => $names) {
            if (in_array($leaf, $names, true)) {
                return $role;
            }
        }
        return self::OTHER;
    }

    /** @return array{role:string, role_source:string, pollable:bool, reason:?string} */
    public static function fromGmailLabel(string $id, string $type): array
    {
        if (isset(self::GMAIL_SYSTEM[$id])) {
            return ['role' => self::GMAIL_SYSTEM[$id], 'role_source' => self::SOURCE_PROVIDER, 'pollable' => true, 'reason' => null];
        }
        if (strtolower($type) === 'user') {
            return ['role' => self::OTHER, 'role_source' => self::SOURCE_PROVIDER, 'pollable' => false, 'reason' => 'Gmail label (labels are not folders)'];
        }
        return ['role' => self::OTHER, 'role_source' => self::SOURCE_PROVIDER, 'pollable' => false, 'reason' => 'not a location'];
    }

    /** True when the folder is [Gmail] / [Google Mail] or sits under it (delimiter-aware first segment). */
    private static function isGoogleRoot(string $id, ?string $delimiter): bool
    {
        $first = self::segments($id, $delimiter)[0] ?? '';
        return in_array(mb_strtolower($first), ['[gmail]', '[google mail]'], true);
    }

    private static function leaf(string $id, ?string $delimiter): string
    {
        $parts = self::segments($id, $delimiter);
        return mb_strtolower((string) end($parts));
    }

    /** @return string[] */
    private static function segments(string $id, ?string $delimiter): array
    {
        $raw = $delimiter !== null && $delimiter !== '' ? explode($delimiter, $id) : (preg_split('#[./]#', $id) ?: []);
        return array_values(array_filter(array_map('trim', $raw), static fn ($p) => $p !== ''));
    }

    /** @param array<int,mixed> $attrs @return string[] lower-case, no backslash */
    private static function attrs(array $attrs): array
    {
        return array_values(array_map(static fn ($a) => strtolower(ltrim(trim((string) $a), '\\')), $attrs));
    }
}
