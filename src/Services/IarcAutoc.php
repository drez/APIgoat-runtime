<?php

namespace ApiGoat\Services;

/**
 * IARC impersonation autocomplete handler.
 *
 * Searches Authy users (username/email/fullname LIKE) for the impersonation
 * dropdown rendered by BuilderLayout/BuilderMenus. Gated by isRoot + the
 * IarcCsrf token stashed in session by AuthyMiddleware::checkUserSwitch(),
 * read from the X-Iarc-Csrf request header (iarc_csrf query/body fallback for
 * un-upgraded clients).
 *
 * Designed to be invoked from the existing modern route
 * `_SUB_DIR_URL . 'Authy/autoc'` via AuthyServiceWrapper. The wrapper short-
 * circuits in getResponse() when $request['a'] === 'autoc' and returns this
 * handler's output verbatim with Content-Type: application/json.
 *
 * Per-project wiring is one method override in AuthyServiceWrapper:
 *
 *     public function getResponse()
 *     {
 *         if (($this->request['a'] ?? '') === 'autoc') {
 *             $this->contentType = 'application/json';
 *             return \ApiGoat\Services\IarcAutoc::handle($this->request);
 *         }
 *         return parent::getResponse();
 *     }
 */
class IarcAutoc
{
    /** Request header the client sends the switch token in (kept off the URL). */
    public const CSRF_HEADER = 'X-Iarc-Csrf';

    /**
     * @param string|null $headerCsrf the X-Iarc-Csrf header value; null reads
     *        it from $_SERVER (HTTP_X_IARC_CSRF), so existing wrappers that call
     *        handle($this->request) get the header path without changes.
     */
    public static function handle(array $request, ?string $headerCsrf = null): string
    {
        return json_encode(self::respond($request, $headerCsrf));
    }

    /**
     * The submitted switch token: the X-Iarc-Csrf header first (a GET query
     * string lands in access logs, history and the Referer), then — for
     * clients not yet upgraded to send the header — the iarc_csrf query/body
     * field.
     */
    public static function submittedCsrf(array $request, ?string $headerCsrf = null): string
    {
        $header = $headerCsrf ?? (string) ($_SERVER['HTTP_X_IARC_CSRF'] ?? '');
        if ($header !== '') {
            return $header;
        }
        return (string) ($request['iarc_csrf'] ?? $request['data']['iarc_csrf'] ?? '');
    }

    private static function respond(array $request, ?string $headerCsrf = null): array
    {
        if (! isset($_SESSION[_AUTH_VAR]) || ! is_object($_SESSION[_AUTH_VAR])) {
            return ['count' => 0, 'data' => [], '_why' => 'no_session'];
        }
        // Root, or a session a root switched into (so it can switch on/back).
        if (! \ApiGoat\Middlewares\AuthyMiddleware::canSwitchUser($_SESSION[_AUTH_VAR])) {
            return ['count' => 0, 'data' => [], '_why' => 'not_root'];
        }

        $sessionCsrf   = (string) ($_SESSION[_AUTH_VAR]->sessVar['IarcCsrf'] ?? '');
        $submittedCsrf = self::submittedCsrf($request, $headerCsrf);
        if ($sessionCsrf === '' || $submittedCsrf === '' || ! hash_equals($sessionCsrf, $submittedCsrf)) {
            error_log('iarc autoc rejected: csrf mismatch from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
            return [
                'count' => 0,
                'data' => [],
                '_why' => 'csrf_mismatch',
            ];
        }

        $term = trim((string) (
            $request['term']
            ?? $request['Username']
            ?? $request['Email']
            ?? $request['data']['term']
            ?? $request['data']['Username']
            ?? $request['data']['Email']
            ?? ''
        ));
        if (strlen($term) < 3) {
            return [
                'count' => 0,
                'data' => [],
                '_why' => 'term_too_short',
            ];
        }

        $maxRows = (int) ($request['maxRows'] ?? 12);
        if ($maxRows < 1 || $maxRows > 50) {
            $maxRows = 12;
        }

        $like = '%' . $term . '%';
        $rows = \App\AuthyQuery::create()
            ->filterByUsername($like, \Criteria::LIKE)
            ->_or()->filterByEmail($like, \Criteria::LIKE)
            ->_or()->filterByFullname($like, \Criteria::LIKE)
            ->limit($maxRows)
            ->orderByUsername()
            ->find();

        $out = [];
        foreach ($rows as $row) {
            $label = $row->getUsername();
            $extras = [];
            if (method_exists($row, 'getFullname') && $row->getFullname()) {
                $extras[] = $row->getFullname();
            }
            if (method_exists($row, 'getEmail') && $row->getEmail()) {
                $extras[] = $row->getEmail();
            }
            if ($extras) {
                $label .= ' (' . implode(' — ', $extras) . ')';
            }
            $out[] = [
                'id'   => $row->getIdAuthy(),
                'show' => $label,
            ];
        }

        return ['count' => count($out), 'data' => $out];
    }
}
