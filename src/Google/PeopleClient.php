<?php

namespace ApiGoat\Google;

use ApiGoat\Mail\TokenSource;

/**
 * Google People API reader — the address book behind a per-user OAuth grant.
 *
 * Two endpoints, one shape:
 *
 *   connections()    people/me/connections   the user's own contacts       (contacts.readonly)
 *   otherContacts()  otherContacts           addresses Google learned from (contacts.other.readonly)
 *                                            their mail but that were never saved as a contact
 *
 * Both return `array{people: list<array{resource,name,emails,org}>, next: ?string}`;
 * the caller pages by handing `next` back until it is null. Normalisation
 * happens here so the consumer never sees the People wire format: addresses
 * are lower-cased and trimmed, a person with no usable address is dropped
 * (a phone-only contact says nothing about mail), and a missing name is null
 * rather than an empty string.
 *
 * Auth mirrors {@see \ApiGoat\Mail\Connector\GmailConnector::call()}: a 401
 * invalidates the TokenSource and retries EXACTLY once (an access token can
 * die before its stated expiry), everything else goes through
 * {@see ErrorMapper} so the queue's retry taxonomy is the same as the mail
 * connectors'. The transport is injectable — tests never reach Google.
 */
final class PeopleClient
{
    public const CONNECTIONS_URL    = 'https://people.googleapis.com/v1/people/me/connections';
    public const OTHER_CONTACTS_URL = 'https://people.googleapis.com/v1/otherContacts';

    /** People API caps both endpoints at 1000 per page. */
    public const PAGE_SIZE = 1000;

    public const PERSON_FIELDS = 'names,emailAddresses,organizations';
    /** otherContacts supports names/emailAddresses/phoneNumbers/metadata only — no organizations. */
    public const OTHER_READ_MASK = 'names,emailAddresses';

    /** @var callable */
    private $http;

    public function __construct(private TokenSource $tokens, ?callable $transport = null)
    {
        $this->http = $transport ?? new HttpTransport();
    }

    /**
     * One page of the user's own contacts.
     *
     * @return array{people: list<array{resource:string, name:?string, emails:list<string>, org:?string}>, next:?string}
     */
    public function connections(?string $pageToken = null): array
    {
        $data = $this->call(self::CONNECTIONS_URL, [
            'personFields' => self::PERSON_FIELDS,
            'pageSize'     => self::PAGE_SIZE,
        ], $pageToken);

        return self::normalisePage($data, 'connections');
    }

    /**
     * One page of "other contacts" — people the user has corresponded with
     * who are not in the saved address book. For triage this is the more
     * valuable half: it is exactly the set of humans this mailbox writes to.
     *
     * @return array{people: list<array{resource:string, name:?string, emails:list<string>, org:?string}>, next:?string}
     */
    public function otherContacts(?string $pageToken = null): array
    {
        $data = $this->call(self::OTHER_CONTACTS_URL, [
            'readMask' => self::OTHER_READ_MASK,
            'pageSize' => self::PAGE_SIZE,
        ], $pageToken);

        return self::normalisePage($data, 'otherContacts');
    }

    /** Short label for logs/errors — whatever the token source calls itself. */
    public function describe(): string
    {
        return $this->tokens->describe();
    }

    // ------------------------------------------------------------------ HTTP

    /**
     * @param array<string,string|int> $query
     * @return array<string,mixed>
     */
    private function call(string $url, array $query, ?string $pageToken, bool $retried = false): array
    {
        if ($pageToken !== null && $pageToken !== '') {
            $query['pageToken'] = $pageToken;
        }
        $full = $url . '?' . http_build_query($query);

        $r      = ($this->http)('GET', $full, [
            'Authorization: Bearer ' . $this->tokens->accessToken(),
            'Accept: application/json',
        ], null);
        $status = (int) $r['status'];

        if ($status >= 200 && $status < 300) {
            $data = $r['body'] === '' ? [] : json_decode((string) $r['body'], true);
            return is_array($data) ? $data : [];
        }
        if ($status === 401 && !$retried) {
            $this->tokens->invalidate();
            return $this->call($url, $query, $pageToken, true);
        }
        ErrorMapper::fail(
            'People GET ' . $url . ' (' . $this->tokens->describe() . ')',
            $status,
            (string) ($r['headers'] ?? ''),
            (string) $r['body']
        );
    }

    // --------------------------------------------------------- normalisation

    /**
     * @param array<string,mixed> $data
     * @return array{people: list<array{resource:string, name:?string, emails:list<string>, org:?string}>, next:?string}
     */
    private static function normalisePage(array $data, string $key): array
    {
        $people = [];
        foreach ($data[$key] ?? [] as $person) {
            if (!is_array($person)) {
                continue;
            }
            $one = self::normalisePerson($person);
            if ($one !== null) {
                $people[] = $one;
            }
        }
        $next = (string) ($data['nextPageToken'] ?? '');

        return ['people' => $people, 'next' => $next !== '' ? $next : null];
    }

    /**
     * @param array<string,mixed> $person
     * @return array{resource:string, name:?string, emails:list<string>, org:?string}|null null when it carries no address
     */
    private static function normalisePerson(array $person): ?array
    {
        $emails = [];
        foreach ($person['emailAddresses'] ?? [] as $e) {
            $addr = strtolower(trim((string) ($e['value'] ?? '')));
            if ($addr === '' || !str_contains($addr, '@')) {
                continue;
            }
            $emails[$addr] = $addr; // a person can list the same address twice
        }
        if ($emails === []) {
            return null;
        }

        $name = trim((string) ($person['names'][0]['displayName'] ?? ''));
        $org  = trim((string) ($person['organizations'][0]['name'] ?? ''));

        return [
            'resource' => (string) ($person['resourceName'] ?? ''),
            'name'     => $name !== '' ? $name : null,
            'emails'   => array_values($emails),
            'org'      => $org !== '' ? $org : null,
        ];
    }
}
