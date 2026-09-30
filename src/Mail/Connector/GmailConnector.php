<?php

namespace ApiGoat\Mail\Connector;

use ApiGoat\Google\ErrorMapper;
use ApiGoat\Google\HttpTransport;
use ApiGoat\Mail\BaseConnector;
use ApiGoat\Mail\FetchResult;
use ApiGoat\Mail\FolderLister;
use ApiGoat\Mail\FolderListing;
use ApiGoat\Mail\FolderRole;
use ApiGoat\Mail\HeaderRecord;
use ApiGoat\Mail\MailBody;
use ApiGoat\Mail\MailboxState;
use ApiGoat\Mail\MailConnector;
use ApiGoat\Mail\MessageState;
use ApiGoat\Mail\StateWriter;
use ApiGoat\Mail\TokenSource;
use ApiGoat\Sync\Exceptions\AuthFailed;
use ApiGoat\Sync\Exceptions\TransientError;

/**
 * Gmail over the REST API. ONE class for both auth modes — the
 * {@see TokenSource} is the only thing that differs between Domain-Wide
 * Delegation and per-user OAuth.
 *
 * $folder is a Gmail label id ('INBOX', 'SENT', 'Label_12'); provider id is
 * the Gmail message id; thread_id is the Gmail threadId; labels are the
 * message's labelIds at fetch time.
 *
 * Cursor rules (MailboxState: history_id [+ page_token]):
 *   - no cursor / no history_id       → cold start 'initial': messages.list bounded to
 *                                        newer_than:{coldStartDays}d, newest first, page_token
 *                                        carried in the cursor until the window is drained.
 *                                        The history_id is pinned from users.getProfile BEFORE
 *                                        listing so nothing between is lost.
 *   - history.list → 404              → historyId expired (Google keeps ~a week): cold start
 *                                        'history_expired', coldStart=true — surface it.
 *   - otherwise                       → history.list startHistoryId, messageAdded only.
 */
class GmailConnector extends BaseConnector implements FolderLister, StateWriter
{
    public const BASE = 'https://gmail.googleapis.com/gmail/v1/users/me';
    public const METADATA_HEADERS = ['From', 'To', 'Cc', 'Subject', 'Date', 'Message-ID', 'In-Reply-To', 'Authentication-Results'];
    /** listIds() page cap: 200 x 500 ids. A folder bigger than that is reported incomplete, never truncated silently. */
    public const LIST_IDS_MAX_PAGES = 200;

    /** @var callable */
    private $http;
    private int $coldStartDays;

    /** @param array{cold_start_days?:int} $options */
    public function __construct(private TokenSource $tokens, ?callable $transport = null, array $options = [])
    {
        $this->http          = $transport ?? new HttpTransport();
        $this->coldStartDays = max(1, (int) ($options['cold_start_days'] ?? 30));
    }

    /**
     * No CAP_BACKFILL: history walking needs a stable "older than X" cursor.
     * The Gmail list API pages newest-first with an opaque pageToken that is
     * not resumable across runs, so fetchBefore() stays unsupported here
     * (BaseConnector throws) until a q=before:<date> walk is written.
     */
    public function capabilities(): array
    {
        return [
            self::CAP_LIST_FOLDERS, self::CAP_FETCH_BODY, self::CAP_THREADS, self::CAP_LABELS,
            self::CAP_MARK_READ, self::CAP_MOVE, self::CAP_TRASH,
        ];
    }

    public function verify(): void
    {
        $p = $this->call('GET', '/profile');
        if (empty($p['emailAddress'])) {
            throw new AuthFailed('Gmail profile returned no emailAddress for ' . $this->tokens->describe());
        }
    }

    public function listFolders(): array
    {
        $out = [];
        foreach ($this->call('GET', '/labels')['labels'] ?? [] as $l) {
            $id   = (string) ($l['id'] ?? '');
            $type = (string) ($l['type'] ?? '');
            $out[] = ['id' => $id, 'name' => (string) ($l['name'] ?? ''), 'type' => $type] + FolderRole::fromGmailLabel($id, $type);
        }
        return $out;
    }

    /** messages.list leaves SPAM and TRASH out unless asked: asking for those labels means asking for them. @return array<string,string> */
    private static function labelParams(string $folder): array
    {
        return in_array($folder, ['SPAM', 'TRASH'], true) ? ['includeSpamTrash' => 'true'] : [];
    }

    public function fetchHeaders(string $folder, ?MailboxState $cursor, int $max): FetchResult
    {
        $folder = $folder !== '' ? $folder : 'INBOX';
        $max    = self::clampMax($max);

        if ($cursor === null || $cursor->historyId() === null) {
            return $this->coldStart($folder, $max, FetchResult::REASON_INITIAL, $cursor);
        }
        if ($cursor->get('cold_start')) {
            // Still draining a cold-start window: keep paging with the pinned history id.
            return $this->coldStart($folder, $max, (string) $cursor->get('cold_start'), $cursor);
        }
        try {
            return $this->incremental($folder, $cursor, $max);
        } catch (TransientError $e) {
            if ($e->getCode() === 404) {
                return $this->coldStart($folder, $max, FetchResult::REASON_HISTORY_EXPIRED, null);
            }
            throw $e;
        }
    }

    public function fetchBody(string $providerId): MailBody
    {
        $msg = $this->call('GET', '/messages/' . rawurlencode($providerId) . '?format=full');
        $text = '';
        $html = '';
        $attachments = [];
        self::walk($msg['payload'] ?? [], $text, $html, $attachments);
        $headers = [];
        foreach ($msg['payload']['headers'] ?? [] as $h) {
            if (isset($h['name'])) $headers[strtolower((string) $h['name'])] = (string) ($h['value'] ?? '');
        }
        return new MailBody((string) ($msg['id'] ?? $providerId), $text, $html, $attachments, $headers, (int) ($msg['sizeEstimate'] ?? 0));
    }

    public function markRead(string $providerId, bool $read): void
    {
        $this->modify($providerId, $read ? [] : ['UNREAD'], $read ? ['UNREAD'] : []);
    }

    /** Gmail "move" = swap the folder label; the id never changes. */
    public function move(string $providerId, string $folder): string
    {
        $current = $this->call('GET', '/messages/' . rawurlencode($providerId) . '?format=minimal');
        $remove  = array_values(array_filter((array) ($current['labelIds'] ?? []), static fn ($l) => in_array($l, ['INBOX', 'SPAM', 'TRASH'], true) || str_starts_with((string) $l, 'Label_')));
        $remove  = array_values(array_diff($remove, [$folder]));
        $this->modify($providerId, [$folder], $remove);
        return $providerId;
    }

    public function trash(string $providerId): string
    {
        $this->call('POST', '/messages/' . rawurlencode($providerId) . '/trash', []);
        return $providerId;
    }

    // ------------------------------------------------------------ StateWriter

    public function messageState(string $providerId): ?MessageState
    {
        self::assertResolved($providerId, 'messageState');
        try {
            $labels = $this->labelsOf($providerId);
        } catch (TransientError $e) {
            if ($e->getCode() === 404) {
                return null;
            }
            throw $e;
        }
        [$folder, $role] = self::locationOf($labels);
        return new MessageState(!in_array('UNREAD', $labels, true), in_array('STARRED', $labels, true), $folder, $role, $labels);
    }

    /**
     * Where a message IS, by its system labels: TRASH > SPAM > INBOX > DRAFT > SENT,
     * otherwise archived ('' — All Mail has no label).
     *
     * @param string[] $labels
     * @return array{0:string,1:string} [system label or '', role]
     */
    public static function locationOf(array $labels): array
    {
        foreach (['TRASH' => FolderRole::TRASH, 'SPAM' => FolderRole::SPAM, 'INBOX' => FolderRole::INBOX, 'DRAFT' => FolderRole::DRAFTS, 'SENT' => FolderRole::SENT] as $label => $role) {
            if (in_array($label, $labels, true)) {
                return [$label, $role];
            }
        }
        return ['', FolderRole::ARCHIVE];
    }

    public function setFlag(string $providerId, bool $flagged): void
    {
        self::assertResolved($providerId, 'setFlag');
        $this->modify($providerId, $flagged ? ['STARRED'] : [], $flagged ? [] : ['STARRED']);
    }

    /** Drop INBOX and SPAM; a trashed message is untrashed first. $archiveFolder is ignored: All Mail is not a label. */
    public function archive(string $providerId, string $archiveFolder = ''): string
    {
        self::assertResolved($providerId, 'archive');
        $labels = $this->labelsOf($providerId);
        if (in_array('TRASH', $labels, true)) {
            $this->call('POST', '/messages/' . rawurlencode($providerId) . '/untrash', []);
        }
        $this->modify($providerId, [], array_values(array_intersect(['INBOX', 'SPAM'], $labels)));
        return $providerId;
    }

    public function untrash(string $providerId, string $toFolder = ''): string
    {
        self::assertResolved($providerId, 'untrash');
        $to     = $toFolder !== '' ? $toFolder : 'INBOX';
        $labels = $this->labelsOf($providerId);
        if (in_array('TRASH', $labels, true)) {
            $this->call('POST', '/messages/' . rawurlencode($providerId) . '/untrash', []);
        }
        $remove = ($to !== 'SPAM' && in_array('SPAM', $labels, true)) ? ['SPAM'] : [];
        $this->modify($providerId, in_array($to, $labels, true) ? [] : [$to], $remove);
        return $providerId;
    }

    /** @return string[] */
    private function labelsOf(string $providerId): array
    {
        $m = $this->call('GET', '/messages/' . rawurlencode($providerId) . '?format=minimal');
        return array_values(array_map('strval', (array) ($m['labelIds'] ?? [])));
    }

    /**
     * messages.list over the label, ids only (500 per call, no
     * messages.get). Spam and Trash are excluded by the API default, so a
     * trashed message is absent — which is the point. Gmail ids never
     * renumber: generation is null.
     */
    public function listIds(string $folder): FolderListing
    {
        $folder = $folder !== '' ? $folder : 'INBOX';
        $ids    = [];
        $token  = null;
        for ($page = 0; $page < self::LIST_IDS_MAX_PAGES; $page++) {
            $params = ['labelIds' => $folder, 'maxResults' => '500', 'fields' => 'messages/id,nextPageToken'] + self::labelParams($folder);
            if ($token !== null) {
                $params['pageToken'] = $token;
            }
            $list = $this->call('GET', '/messages?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
            foreach ($list['messages'] ?? [] as $m) {
                if (!empty($m['id'])) $ids[(string) $m['id']] = true;
            }
            $token = (string) ($list['nextPageToken'] ?? '');
            if ($token === '') {
                return new FolderListing($ids, null, true);
            }
        }
        return new FolderListing($ids, null, false);
    }

    // ------------------------------------------------------------------ fetch internals

    private function coldStart(string $folder, int $max, string $reason, ?MailboxState $cursor): FetchResult
    {
        $pinned = $cursor?->historyId();
        if ($pinned === null) {
            $pinned = (string) ($this->call('GET', '/profile')['historyId'] ?? '');
            if ($pinned === '') {
                throw new TransientError('Gmail profile returned no historyId');
            }
        }
        $params = [
            'labelIds'   => $folder,
            'q'          => 'newer_than:' . $this->coldStartDays . 'd',
            'maxResults' => (string) $max,
        ] + self::labelParams($folder);
        if ($cursor?->pageToken()) {
            $params['pageToken'] = $cursor->pageToken();
        }
        $list = $this->call('GET', '/messages?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        $ids  = [];
        foreach ($list['messages'] ?? [] as $m) {
            if (!empty($m['id'])) $ids[(string) $m['id']] = true;
        }
        $rows = $this->metadata(array_keys($ids), $folder);
        $next = (string) ($list['nextPageToken'] ?? '');
        $complete = $next === '';
        $state = MailboxState::gmail($pinned, $complete ? null : $next);
        if (!$complete) {
            $state = $state->with('cold_start', $reason);
        }
        return new FetchResult(array_reverse($rows), $state, $complete, true, $reason);
    }

    private function incremental(string $folder, MailboxState $cursor, int $max): FetchResult
    {
        $params = [
            'startHistoryId' => $cursor->historyId(),
            'labelId'        => $folder,
            'historyTypes'   => 'messageAdded',
            'maxResults'     => (string) $max,
        ];
        if ($cursor->pageToken()) {
            $params['pageToken'] = $cursor->pageToken();
        }
        $resp = $this->call('GET', '/history?' . http_build_query($params, '', '&', PHP_QUERY_RFC3986));
        $ids  = [];
        foreach ($resp['history'] ?? [] as $h) {
            foreach ($h['messagesAdded'] ?? [] as $added) {
                $id = (string) ($added['message']['id'] ?? '');
                if ($id !== '') $ids[$id] = true;
            }
        }
        $rows = $this->metadata(array_keys($ids), $folder);
        $next = (string) ($resp['nextPageToken'] ?? '');
        $complete = $next === '';
        // While paging, the start history id stays the same; the response historyId is the new watermark only once drained.
        $historyId = $complete ? (string) ($resp['historyId'] ?? $cursor->historyId()) : (string) $cursor->historyId();
        return new FetchResult($rows, MailboxState::gmail($historyId, $complete ? null : $next), $complete, false, null);
    }

    /**
     * @param string[] $ids
     * @return array<int,array<string,mixed>>
     */
    private function metadata(array $ids, string $folder): array
    {
        $rows = [];
        $qs   = '?format=metadata&' . implode('&', array_map(static fn ($h) => 'metadataHeaders=' . $h, self::METADATA_HEADERS));
        foreach ($ids as $id) {
            try {
                $msg = $this->call('GET', '/messages/' . rawurlencode($id) . $qs);
            } catch (TransientError $e) {
                if ($e->getCode() === 404) continue; // deleted between list and get
                throw $e;
            }
            $rows[] = self::normalise($msg, $folder);
        }
        return $rows;
    }

    /**
     * A users.messages.get(format=metadata) payload → normalised header record.
     *
     * @param array<string,mixed> $msg
     * @return array<string,mixed>
     */
    public static function normalise(array $msg, string $folder): array
    {
        $h      = $msg['payload']['headers'] ?? [];
        $from   = HeaderRecord::parseAddress(self::header($h, 'From'));
        $labels = array_values(array_map('strval', (array) ($msg['labelIds'] ?? [])));
        $date   = self::header($h, 'Date');
        if ($date === '' && !empty($msg['internalDate'])) {
            $date = (int) floor(((int) $msg['internalDate']) / 1000);
        }
        return HeaderRecord::normalise([
            'provider_message_id' => (string) ($msg['id'] ?? ''),
            'thread_id'           => $msg['threadId'] ?? null,
            'message_id_header'   => self::header($h, 'Message-ID'),
            'in_reply_to'         => self::header($h, 'In-Reply-To'),
            'from_addr'           => $from['addr'],
            'from_name'           => $from['name'],
            'to'                  => self::header($h, 'To'),
            'cc'                  => self::header($h, 'Cc'),
            'subject'             => self::header($h, 'Subject'),
            'date_sent'           => $date,
            'snippet'             => (string) ($msg['snippet'] ?? ''),
            'size_bytes'          => (int) ($msg['sizeEstimate'] ?? 0),
            'has_attachments'     => self::payloadHasAttachments($msg['payload'] ?? []),
            'folder_at_fetch'     => $folder,
            'was_read_at_fetch'   => !in_array('UNREAD', $labels, true),
            'labels'              => $labels,
            // header() returns the first match and Gmail lists headers in
            // message order, so this is the TOPMOST one — mx.google.com's own
            // verdict, not a copy the sender typed further down.
            'auth_results'        => self::header($h, HeaderRecord::AUTH_RESULTS_HEADER),
        ]);
    }

    /** @param array<int,array{name?:string,value?:string}> $headers */
    private static function header(array $headers, string $name): string
    {
        foreach ($headers as $h) {
            if (isset($h['name']) && strcasecmp((string) $h['name'], $name) === 0) {
                return (string) ($h['value'] ?? '');
            }
        }
        return '';
    }

    /** format=metadata still carries the part tree (without data) — a filename anywhere means an attachment. */
    private static function payloadHasAttachments(array $payload): bool
    {
        if (!empty($payload['filename'])) return true;
        foreach ($payload['parts'] ?? [] as $p) {
            if (is_array($p) && self::payloadHasAttachments($p)) return true;
        }
        return false;
    }

    /** Depth-first walk of a format=full payload. */
    private static function walk(array $payload, string &$text, string &$html, array &$attachments): void
    {
        $mime     = strtolower((string) ($payload['mimeType'] ?? ''));
        $filename = (string) ($payload['filename'] ?? '');
        $body     = $payload['body'] ?? [];
        if ($filename !== '' || (!empty($body['attachmentId']))) {
            $attachments[] = [
                'filename' => $filename,
                'mime'     => $mime,
                'size'     => (int) ($body['size'] ?? 0),
                'part_id'  => (string) ($body['attachmentId'] ?? ($payload['partId'] ?? '')),
            ];
        } elseif (isset($body['data'])) {
            if ($mime === 'text/plain' && $text === '') $text = self::b64url((string) $body['data']);
            elseif ($mime === 'text/html' && $html === '') $html = self::b64url((string) $body['data']);
        }
        foreach ($payload['parts'] ?? [] as $p) {
            if (is_array($p)) self::walk($p, $text, $html, $attachments);
        }
    }

    public static function b64url(string $data): string
    {
        $d = base64_decode(strtr($data, '-_', '+/'), false);
        return $d === false ? '' : $d;
    }

    /** @param string[] $add  @param string[] $remove */
    private function modify(string $id, array $add, array $remove): void
    {
        $payload = [];
        if ($add !== []) $payload['addLabelIds'] = array_values($add);
        if ($remove !== []) $payload['removeLabelIds'] = array_values($remove);
        if ($payload === []) return;
        $this->call('POST', '/messages/' . rawurlencode($id) . '/modify', $payload);
    }

    // ------------------------------------------------------------------ HTTP

    /**
     * One authenticated call. A 401 invalidates the token source and retries
     * exactly once (tokens die early when a key is rotated); everything else
     * goes through {@see ErrorMapper}.
     *
     * @return array<string,mixed>
     */
    private function call(string $method, string $path, ?array $payload = null, bool $retried = false): array
    {
        $url     = str_starts_with($path, 'http') ? $path : self::BASE . $path;
        $headers = ['Authorization: Bearer ' . $this->tokens->accessToken(), 'Accept: application/json'];
        $body    = null;
        if ($payload !== null) {
            $headers[] = 'Content-Type: application/json';
            $body      = (string) json_encode($payload);
        }
        $r = ($this->http)($method, $url, $headers, $body);
        $status = (int) $r['status'];
        if ($status >= 200 && $status < 300) {
            $data = $r['body'] === '' ? [] : json_decode((string) $r['body'], true);
            return is_array($data) ? $data : [];
        }
        if ($status === 401 && !$retried) {
            $this->tokens->invalidate();
            return $this->call($method, $path, $payload, true);
        }
        ErrorMapper::fail("Gmail {$method} {$path} (" . $this->tokens->describe() . ')', $status, (string) ($r['headers'] ?? ''), (string) $r['body']);
    }
}
