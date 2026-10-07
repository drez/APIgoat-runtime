<?php

namespace ApiGoat\Mail\Connector;

use ApiGoat\Mail\BackfillResult;
use ApiGoat\Mail\BaseConnector;
use ApiGoat\Mail\DraftStore;
use ApiGoat\Mail\FetchResult;
use ApiGoat\Mail\FolderListing;
use ApiGoat\Mail\FolderLister;
use ApiGoat\Mail\FolderWriter;
use ApiGoat\Mail\HeaderRecord;
use ApiGoat\Mail\MailBody;
use ApiGoat\Mail\MailboxState;
use ApiGoat\Mail\MailConnector;
use ApiGoat\Mail\MessageState;
use ApiGoat\Mail\MimeBodyParser;
use ApiGoat\Mail\MimeStructure;
use ApiGoat\Mail\PartDecoder;
use ApiGoat\Mail\PartReader;
use ApiGoat\Mail\StateWriter;
use ApiGoat\Mail\TokenSource;
use ApiGoat\Microsoft\GraphHttp;
use ApiGoat\Sync\Exceptions\AuthFailed;
use ApiGoat\Sync\Exceptions\TransientError;
use ApiGoat\Sync\Exceptions\ValidationRejected;

/**
 * Microsoft 365 mail over Microsoft Graph. ONE class for both auth modes:
 * the {@see TokenSource} and $basePath differ (`/users/<email>` for app-only,
 * `/me` for delegated).
 *
 * $folder is a Graph folder id or a well-known name ('inbox', 'sentitems', …);
 * '' means inbox. Provider id is the (immutable) Graph message id; thread_id
 * is the conversationId.
 *
 * Cursor rules (MailboxState: delta [+ next] + folder):
 *   - no cursor / neither delta nor next → cold start 'initial': a delta query filtered
 *                                           on receivedDateTime >= now - cold_start_days.
 *   - next set                           → follow it (a delta still paging); the delta
 *                                           watermark only moves when a deltaLink arrives.
 *   - delta set                          → follow it; 410 ⇒ cold start 'delta_expired'.
 */
class GraphConnector extends BaseConnector implements FolderLister, StateWriter, FolderWriter, DraftStore, PartReader
{
    public const SELECT = 'id,conversationId,internetMessageId,subject,from,toRecipients,ccRecipients,replyTo,receivedDateTime,sentDateTime,bodyPreview,isRead,hasAttachments,flag,parentFolderId,internetMessageHeaders';

    /** Graph wellKnownName → role. */
    public const WELL_KNOWN_ROLES = [
        'inbox' => 'inbox', 'sentitems' => 'sent', 'drafts' => 'drafts',
        'deleteditems' => 'trash', 'archive' => 'archive', 'junkemail' => 'junk',
    ];

    private GraphHttp $graph;
    private int $coldStartDays;
    /** @var array<string,string>|null folder id → role */
    private ?array $roles = null;
    /** One-entry cache of the last downloaded MIME: structure + part = one download. */
    private ?string $rawId = null;
    private string $rawMime = '';

    /** @param array{cold_start_days?:int} $options */
    public function __construct(private TokenSource $tokens, private string $basePath, ?callable $transport = null, array $options = [])
    {
        $this->graph         = new GraphHttp($tokens, $transport);
        $this->coldStartDays = max(1, (int) ($options['cold_start_days'] ?? 30));
    }

    public function capabilities(): array
    {
        return [
            self::CAP_LIST_FOLDERS, self::CAP_FETCH_BODY, self::CAP_MARK_READ,
            self::CAP_MOVE, self::CAP_TRASH, self::CAP_SEND, self::CAP_BACKFILL,
        ];
    }

    public function verify(): void
    {
        $f = $this->graph->call('GET', $this->basePath . '/mailFolders/inbox?$select=id');
        if (empty($f['id'])) {
            throw new AuthFailed('Graph returned no inbox folder for ' . $this->tokens->describe());
        }
    }

    public function listFolders(): array
    {
        $roles = $this->wellKnownRoles();
        $out   = [];
        $this->walkFolders($this->basePath . '/mailFolders?$top=100', '', $roles, $out);
        return $out;
    }

    /**
     * @param array<string,string> $roles
     * @param array<int,array<string,mixed>> $out
     */
    private function walkFolders(string $url, string $prefix, array $roles, array &$out): void
    {
        while ($url !== '') {
            $page = $this->graph->call('GET', $url);
            foreach ($page['value'] ?? [] as $f) {
                $id = (string) ($f['id'] ?? '');
                if ($id === '') continue;
                $name = $prefix . (string) ($f['displayName'] ?? '');
                $row  = ['id' => $id, 'name' => $name];
                if (isset($roles[$id])) $row['role'] = $roles[$id];
                $out[] = $row;
                if ((int) ($f['childFolderCount'] ?? 0) > 0) {
                    $this->walkFolders($this->basePath . '/mailFolders/' . rawurlencode($id) . '/childFolders?$top=100', $name . '/', $roles, $out);
                }
            }
            $url = (string) ($page['@odata.nextLink'] ?? '');
        }
    }

    /** @return array<string,string> folder id → role, resolved once per connector */
    private function wellKnownRoles(): array
    {
        if ($this->roles !== null) return $this->roles;
        $this->roles = [];
        foreach (self::WELL_KNOWN_ROLES as $wk => $role) {
            try {
                $f = $this->graph->call('GET', $this->basePath . '/mailFolders/' . $wk . '?$select=id');
            } catch (TransientError $e) {
                if ($e->getCode() === 404) continue; // folder not provisioned (e.g. archive)
                throw $e;
            }
            if (!empty($f['id'])) $this->roles[(string) $f['id']] = $role;
        }
        return $this->roles;
    }

    public function fetchHeaders(string $folder, ?MailboxState $cursor, int $max): FetchResult
    {
        $folder = $folder !== '' ? $folder : 'inbox';
        $max    = self::clampMax($max);

        if ($cursor === null || ($cursor->deltaLink() === null && $cursor->nextLink() === null)) {
            return $this->delta($folder, $max, null, FetchResult::REASON_INITIAL, null);
        }
        $cold = $cursor->get('cold_start');
        try {
            return $this->delta($folder, $max, $cursor->nextLink() ?? $cursor->deltaLink(), $cold ? (string) $cold : null, $cursor);
        } catch (TransientError $e) {
            if ($e->getCode() === 410) {
                return $this->delta($folder, $max, null, FetchResult::REASON_DELTA_EXPIRED, null);
            }
            throw $e;
        }
    }

    /**
     * Page a delta query until $max ids are collected or a deltaLink arrives.
     *
     * @param ?string $link   absolute delta/next link to follow; null ⇒ start a cold-start query
     * @param ?string $cold   cold-start reason when this call is part of one
     */
    private function delta(string $folder, int $max, ?string $link, ?string $cold, ?MailboxState $prev): FetchResult
    {
        $prefer = ['Prefer: odata.maxpagesize=' . $max];
        if ($link === null) {
            $since = gmdate('Y-m-d\TH:i:s\Z', time() - $this->coldStartDays * 86400);
            $link  = $this->basePath . '/mailFolders/' . rawurlencode($folder) . '/messages/delta?$filter=receivedDateTime+ge+' . $since . '&$select=id';
        }
        $ids       = [];
        $deltaLink = null;
        $nextLink  = null;
        do {
            $page = $this->graph->call('GET', $link, null, $prefer);
            foreach ($page['value'] ?? [] as $m) {
                $id = (string) ($m['id'] ?? '');
                if ($id === '' || isset($m['@removed'])) continue;
                $ids[$id] = true;
            }
            $deltaLink = (string) ($page['@odata.deltaLink'] ?? '');
            $nextLink  = (string) ($page['@odata.nextLink'] ?? '');
            if ($deltaLink === '' && $nextLink === '') {
                throw new TransientError('Graph delta page carried neither nextLink nor deltaLink');
            }
            $link = $nextLink;
        } while ($deltaLink === '' && count($ids) < $max);

        $rows = $this->messages(array_keys($ids), $folder);
        usort($rows, static fn ($a, $b) => strcmp((string) $a['date_sent'], (string) $b['date_sent']));

        if ($deltaLink !== '') {
            return new FetchResult($rows, MailboxState::graph($deltaLink, $folder), true, $cold !== null, $cold);
        }
        $state = ($prev ?? new MailboxState(['folder' => $folder]))->with('next', $nextLink);
        if ($cold !== null) {
            $state = $state->with('cold_start', $cold);
        }
        return new FetchResult($rows, $state, false, $cold !== null, $cold);
    }

    /**
     * @param string[] $ids
     * @return array<int,array<string,mixed>>
     */
    private function messages(array $ids, string $folder): array
    {
        $rows = [];
        foreach ($ids as $id) {
            try {
                $msg = $this->graph->call('GET', $this->basePath . '/messages/' . rawurlencode($id) . '?$select=' . self::SELECT);
            } catch (TransientError $e) {
                if ($e->getCode() === 404) continue; // deleted between delta and get
                throw $e;
            }
            $rows[] = self::normalise($msg, $folder);
        }
        return $rows;
    }

    private function msgPath(string $providerId): string
    {
        return $this->basePath . '/messages/' . rawurlencode($providerId);
    }

    private function folderPath(string $folder): string
    {
        return $this->basePath . '/mailFolders/' . rawurlencode($folder);
    }

    public function markRead(string $providerId, bool $read): void
    {
        self::assertResolved($providerId, 'markRead');
        $this->graph->call('PATCH', $this->msgPath($providerId), ['isRead' => $read]);
    }

    public function setFlag(string $providerId, bool $flagged): void
    {
        self::assertResolved($providerId, 'setFlag');
        $this->graph->call('PATCH', $this->msgPath($providerId), ['flag' => ['flagStatus' => $flagged ? 'flagged' : 'notFlagged']]);
    }

    /** @param string $folder a Graph folder id or well-known name. Immutable ids ⇒ the id survives the move. */
    public function move(string $providerId, string $folder): string
    {
        self::assertResolved($providerId, 'move');
        $r = $this->graph->call('POST', $this->msgPath($providerId) . '/move', ['destinationId' => $folder]);
        return (string) ($r['id'] ?? $providerId);
    }

    public function trash(string $providerId): string
    {
        return $this->move($providerId, 'deleteditems');
    }

    public function archive(string $providerId, string $archiveFolder = ''): string
    {
        return $this->move($providerId, $archiveFolder !== '' ? $archiveFolder : 'archive');
    }

    public function untrash(string $providerId, string $toFolder = ''): string
    {
        return $this->move($providerId, $toFolder !== '' ? $toFolder : 'inbox');
    }

    public function messageState(string $providerId): ?MessageState
    {
        self::assertResolved($providerId, 'messageState');
        try {
            $m = $this->graph->call('GET', $this->msgPath($providerId) . '?$select=isRead,flag,parentFolderId');
        } catch (TransientError $e) {
            if ($e->getCode() === 404) return null;
            throw $e;
        }
        $parent = (string) ($m['parentFolderId'] ?? '');
        return new MessageState(
            (bool) ($m['isRead'] ?? false),
            ($m['flag']['flagStatus'] ?? '') === 'flagged',
            $parent,
            $this->wellKnownRoles()[$parent] ?? null
        );
    }

    /**
     * Every id in the folder, walked by receivedDateTime with the same keyset as
     * {@see fetchBefore()}: a message leaving the folder mid-walk never shifts
     * another one out of the listing (an offset page would — and a missing id
     * reads as "left the folder" to the caller).
     */
    public function listIds(string $folder): FolderListing
    {
        $folder = $folder !== '' ? $folder : 'inbox';
        $ids    = [];
        $token  = null;
        do {
            [$rows, $token] = $this->keysetPage($folder, 'id,receivedDateTime', $token, 500);
            foreach ($rows as $m) {
                $ids[(string) $m['id']] = true;
            }
        } while ($token !== null);
        return new FolderListing($ids, null, true);
    }

    public function ensureFolder(string $wanted): string
    {
        $parent = '';
        foreach (array_filter(explode('/', $wanted), fn ($s) => $s !== '') as $seg) {
            $children = $parent === '' ? $this->basePath . '/mailFolders' : $this->folderPath($parent) . '/childFolders';
            $filter   = rawurlencode("displayName eq '" . str_replace("'", "''", $seg) . "'");
            $found    = $this->findChild($children, $filter, $seg);
            if ($found === '') {
                try {
                    $created = $this->graph->call('POST', $children, ['displayName' => $seg]);
                    $found   = (string) ($created['id'] ?? '');
                } catch (TransientError $e) {
                    if ($e->getCode() !== 409) throw $e;
                    $found = $this->findChild($children, $filter, $seg); // lost a create race
                    if ($found === '') throw $e;
                }
                if ($found === '') {
                    throw new TransientError('Graph created folder "' . $seg . '" but returned no id');
                }
            }
            $parent = $found;
        }
        if ($parent === '') {
            throw new \InvalidArgumentException('ensureFolder needs a non-empty folder name');
        }
        return $parent;
    }

    private function findChild(string $children, string $encodedFilter, string $seg): string
    {
        foreach ($this->graph->call('GET', $children . '?$filter=' . $encodedFilter)['value'] ?? [] as $f) {
            if (strcasecmp((string) ($f['displayName'] ?? ''), $seg) === 0 && !empty($f['id'])) {
                return (string) $f['id'];
            }
        }
        return '';
    }

    /** Upload RFC 822 as base64, then clear the "unsent draft" flag Graph sets on created messages. */
    public function append(string $folder, string $raw, bool $seen): string
    {
        $folder  = $folder !== '' ? $folder : 'inbox';
        $created = $this->graph->postRaw($this->folderPath($folder) . '/messages', base64_encode($raw), 'text/plain');
        $id      = (string) ($created['id'] ?? '');
        if ($id === '') {
            throw new TransientError('Graph accepted the appended message but returned no id');
        }
        try {
            $this->graph->call('PATCH', $this->msgPath($id), [
                'singleValueExtendedProperties' => [['id' => 'Integer 0x0E07', 'value' => '1']], // MSGFLAG_READ
                'isRead'                        => $seen,
            ]);
        } catch (\Throwable $e) {
            try {
                $this->graph->call('DELETE', $this->msgPath($id));
            } catch (\Throwable) {
                // best effort: the original failure is what matters
            }
            throw $e;
        }
        return $id;
    }

    /**
     * Keyset paging, newest first: `$orderby=receivedDateTime desc` with `$filter=receivedDateTime lt
     * <boundary + 1s>`. $before is our own opaque token `<iso>|<id,id,…>`: the boundary instant and the
     * ids already returned AT that instant (skipped when they come back). A message leaving the
     * folder between pages cannot shift another one out of the walk (an offset nextLink could);
     * messages sharing a second are each returned once, however Graph orders them.
     */
    public function fetchBefore(string $folder, ?string $before, int $max): BackfillResult
    {
        $folder = $folder !== '' ? $folder : 'inbox';
        [$rows, $next] = $this->keysetPage($folder, self::SELECT, $before !== '' ? $before : null, self::clampMax($max));
        $out = [];
        foreach ($rows as $m) {
            $out[] = self::normalise($m, $folder);
        }
        return new BackfillResult($out, $next, $next === null);
    }

    /** Graph's largest $top on a message collection. */
    private const TOP_CEILING = 1000;
    /** A boundary token: ISO-8601 UTC instant | comma-separated Graph ids. */
    private const TOKEN_RE = '/^(\d{4}-\d{2}-\d{2}T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?Z)\|([A-Za-z0-9_=+\/-]+(?:,[A-Za-z0-9_=+\/-]+)*)$/';

    /**
     * One keyset page: up to $max rows not returned before, and the token for the next one
     * (null = the folder is exhausted). Rows without a usable receivedDateTime cannot be
     * paged past: the walk refuses rather than silently drop them.
     *
     * @return array{0: list<array<string,mixed>>, 1: ?string}
     */
    private function keysetPage(string $folder, string $select, ?string $token, int $max): array
    {
        $boundary = null;
        $seen     = [];
        if ($token !== null) {
            [$boundary, $seen] = self::parseToken($token);
        }
        $top = min(self::TOP_CEILING, $max + count($seen));
        $url = $this->folderPath($folder) . '/messages?$select=' . $select
            . ($boundary !== null ? '&$filter=' . rawurlencode('receivedDateTime lt ' . self::nextSecond($boundary)) : '')
            . '&$orderby=receivedDateTime%20desc&$top=' . $top;

        $out  = [];
        $last = null;   // [iso, ts] of the oldest row this page saw
        $atLast = [];
        do {
            $page = $this->graph->call('GET', $url);
            foreach ((array) ($page['value'] ?? []) as $m) {
                $id  = (string) ($m['id'] ?? '');
                $iso = (string) ($m['receivedDateTime'] ?? '');
                $ts  = self::instant($iso);
                if ($id === '') {
                    continue;
                }
                if ($ts === null) {
                    throw new TransientError('Graph returned message ' . $id . ' without a receivedDateTime: cannot page past it');
                }
                if ($last === null || $ts !== $last[1]) {
                    $last   = [$iso, $ts];
                    $atLast = [];
                }
                $atLast[$id] = true;
                if ($boundary !== null && $ts === self::instant($boundary) && isset($seen[$id])) {
                    continue;   // returned by the previous page
                }
                $out[] = $m;
            }
            $more = (string) ($page['@odata.nextLink'] ?? '');
            // A full page of rows we had already returned (more than TOP_CEILING on one instant):
            // follow Graph's own link inside this call until something new comes.
            $url = ($out === [] && $more !== '') ? $more : '';
        } while ($url !== '');

        if ($more === '' || $last === null) {
            return [$out, null];
        }
        if ($boundary !== null && $last[1] === self::instant($boundary)) {
            $atLast += $seen;   // still on the same instant: the set grows, the walk moves
        }
        return [$out, $last[0] . '|' . implode(',', array_keys($atLast))];
    }

    /**
     * Graph answers receivedDateTime in whole seconds while Exchange may hold sub-second times, so
     * `le <boundary>` would hide a message stored at boundary+0.4s. `lt <boundary + 1s>` keeps the
     * whole boundary second; the seen-ids skip removes what the previous page already returned.
     */
    private static function nextSecond(string $iso): string
    {
        return gmdate('Y-m-d\TH:i:s\Z', (int) floor((float) (new \DateTimeImmutable($iso))->format('U.u')) + 1);
    }

    /** @return array{0:string, 1:array<string,true>} */
    private static function parseToken(string $token): array
    {
        if (!preg_match(self::TOKEN_RE, $token, $m) || self::instant($m[1]) === null) {
            throw new \InvalidArgumentException('Backfill token is not <ISO-8601 UTC>|<ids>');
        }
        return [$m[1], array_fill_keys(explode(',', $m[2]), true)];
    }

    /** A Graph DateTimeOffset as a comparable string ('U.u'), null when it is not one. */
    private static function instant(string $iso): ?string
    {
        if (!preg_match('/^(\d{4})-(\d{2})-(\d{2})T\d{2}:\d{2}:\d{2}(?:\.\d{1,7})?Z$/', $iso, $p) || !checkdate((int) $p[2], (int) $p[3], (int) $p[1])) {
            return null;
        }
        try {
            return (new \DateTimeImmutable($iso))->format('U.u');
        } catch (\Exception) {
            return null;
        }
    }

    public function fetchBody(string $providerId): MailBody
    {
        return MimeBodyParser::parse($this->fetchRaw($providerId), $providerId);
    }

    public function fetchRaw(string $providerId): string
    {
        self::assertResolved($providerId, 'fetchRaw');
        if ($this->rawId === $providerId) {
            return $this->rawMime;
        }
        $raw = $this->downloadRaw($providerId);
        $this->rawId   = $providerId;
        $this->rawMime = $raw;
        return $raw;
    }

    private function downloadRaw(string $providerId): string
    {
        return (string) $this->graph->call('GET', $this->basePath . '/messages/' . rawurlencode($providerId) . '/$value', null, [], true);
    }

    /** Graph creates a message POSTed as MIME in Drafts as an unsent draft. Returns its (immutable) id. */
    public function appendDraft(string $draftsFolder, string $raw): string
    {
        $folder  = $draftsFolder !== '' ? $draftsFolder : 'drafts';
        $created = $this->graph->postRaw($this->folderPath($folder) . '/messages', base64_encode($raw), 'text/plain');
        $id      = (string) ($created['id'] ?? '');
        if ($id === '') {
            throw new TransientError('Graph created the draft but returned no id');
        }
        return $id;
    }

    public function deleteDraft(string $providerId, string $draftsFolder, string $expectedMessageId): void
    {
        self::assertResolved($providerId, 'deleteDraft');
        $m = $this->graph->call('GET', $this->msgPath($providerId) . '?$select=internetMessageId,isDraft'); // 404 → TransientError 404
        if ((string) ($m['internetMessageId'] ?? '') !== $expectedMessageId) {
            throw new ValidationRejected('Graph message ' . $providerId . ' no longer holds our draft (Message-ID '
                . (string) ($m['internetMessageId'] ?? 'none') . ') — not deleted', 409);
        }
        $this->graph->call('DELETE', $this->msgPath($providerId));
    }

    public function findByMessageId(string $folder, string $messageId): ?string
    {
        $filter = rawurlencode("internetMessageId eq '" . str_replace("'", "''", $messageId) . "'");
        $page   = $this->graph->call('GET', $this->folderPath($folder !== '' ? $folder : 'inbox') . '/messages?$filter=' . $filter . '&$select=id&$top=1');
        $id     = (string) ($page['value'][0]['id'] ?? '');
        return $id !== '' ? $id : null;
    }

    /** Send a fully built RFC 822 message as the mailbox (Graph sendMail, MIME form; 202). Saved to Sent Items by Graph. */
    public function sendRaw(string $raw): void
    {
        $this->graph->postRaw($this->basePath . '/sendMail', base64_encode($raw), 'text/plain');
    }

    public function send(array $message): string
    {
        throw $this->unsupported('send'); // SendWorker builds the MIME and calls sendRaw()
    }

    public function fetchStructure(string $providerId): array
    {
        return MimeStructure::leaves($this->rawOrGone($providerId));
    }

    public function fetchPart(string $providerId, string $section, string $encoding, int $maxBytes, callable $sink): int
    {
        $encoded = MimeStructure::part($this->rawOrGone($providerId), $section);
        if ($encoded === null) {
            throw new ValidationRejected('Graph message ' . $providerId . ' has no part ' . $section, 404);
        }
        $dec = new PartDecoder($encoding, $maxBytes, $sink);
        for ($o = 0, $n = strlen($encoded); $o < $n; $o += 65536) {
            $dec->write(substr($encoded, $o, 65536));
        }
        return $dec->finish();
    }

    private function rawOrGone(string $providerId): string
    {
        try {
            return $this->fetchRaw($providerId);
        } catch (TransientError $e) {
            if ($e->getCode() === 404) {
                throw new ValidationRejected('Graph message ' . $providerId . ' is gone', 404, $e);
            }
            throw $e;
        }
    }

    /**
     * A Graph message resource → normalised header record.
     *
     * @param array<string,mixed> $msg
     * @return array<string,mixed>
     */
    public static function normalise(array $msg, string $folder): array
    {
        $h    = (array) ($msg['internetMessageHeaders'] ?? []);
        $from = $msg['from']['emailAddress'] ?? [];
        return HeaderRecord::normalise([
            'provider_message_id' => (string) ($msg['id'] ?? ''),
            'thread_id'           => $msg['conversationId'] ?? null,
            'message_id_header'   => (string) ($msg['internetMessageId'] ?? ''),
            'in_reply_to'         => self::header($h, 'In-Reply-To'),
            'from_addr'           => (string) ($from['address'] ?? ''),
            'from_name'           => (string) ($from['name'] ?? ''),
            'to'                  => self::addresses($msg['toRecipients'] ?? []),
            'cc'                  => self::addresses($msg['ccRecipients'] ?? []),
            'reply_to'            => self::addresses($msg['replyTo'] ?? []),
            'subject'             => (string) ($msg['subject'] ?? ''),
            'date_sent'           => ($msg['sentDateTime'] ?? null) ?: ($msg['receivedDateTime'] ?? null),
            'snippet'             => (string) ($msg['bodyPreview'] ?? ''),
            'size_bytes'          => 0,
            'has_attachments'     => (bool) ($msg['hasAttachments'] ?? false),
            'folder_at_fetch'     => $folder,
            'was_read_at_fetch'   => (bool) ($msg['isRead'] ?? false),
            'labels'              => (($msg['flag']['flagStatus'] ?? '') === 'flagged') ? ['flagged'] : [],
            'auth_results'        => self::header($h, HeaderRecord::AUTH_RESULTS_HEADER),
            'list_id'             => self::header($h, 'List-Id'),
            'list_unsubscribe'    => self::header($h, 'List-Unsubscribe'),
            'precedence'          => self::header($h, 'Precedence'),
            'auto_submitted'      => self::header($h, 'Auto-Submitted'),
            'references'          => self::header($h, 'References'),
        ]);
    }

    /** @param array<int,mixed> $recipients @return string[] "Name <addr>" (or bare addr) */
    private static function addresses(array $recipients): array
    {
        $out = [];
        foreach ($recipients as $r) {
            $addr = trim((string) ($r['emailAddress']['address'] ?? ''));
            if ($addr === '') continue;
            $name = trim((string) ($r['emailAddress']['name'] ?? ''));
            if ($name === '' || strcasecmp($name, $addr) === 0) {
                $out[] = $addr;
            } else {
                $out[] = (preg_match('/[",;<>()\\\\]/', $name) ? '"' . addcslashes($name, '"\\') . '"' : $name) . ' <' . $addr . '>';
            }
        }
        return $out;
    }

    /** First occurrence, case-insensitive name (topmost = the hop that handed the message to our mailbox). */
    private static function header(array $headers, string $name): string
    {
        foreach ($headers as $h) {
            if (isset($h['name']) && strcasecmp((string) $h['name'], $name) === 0) {
                return (string) ($h['value'] ?? '');
            }
        }
        return '';
    }
}
