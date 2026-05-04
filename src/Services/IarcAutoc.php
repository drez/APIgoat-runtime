<?php

namespace ApiGoat\Services;

/**
 * IARC impersonation autocomplete handler.
 *
 * Searches Authy users (username/email/fullname LIKE) for the impersonation
 * dropdown rendered by BuilderLayout/BuilderMenus. Gated by isRoot + the
 * IarcCsrf token stashed in session by AuthyMiddleware::checkUserSwitch().
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
    public static function handle(array $request): string
    {
        return json_encode(self::respond($request));
    }

    private static function respond(array $request): array
    {
        if (! isset($_SESSION[_AUTH_VAR]) || ! is_object($_SESSION[_AUTH_VAR])) {
            return ['count' => 0, 'data' => [], '_why' => 'no_session'];
        }
        if (! $_SESSION[_AUTH_VAR]->get('isRoot')) {
            return ['count' => 0, 'data' => [], '_why' => 'not_root'];
        }

        $sessionCsrf   = (string) ($_SESSION[_AUTH_VAR]->sessVar['IarcCsrf'] ?? '');
        $submittedCsrf = (string) ($request['iarc_csrf'] ?? $request['data']['iarc_csrf'] ?? '');
        if ($sessionCsrf === '' || $submittedCsrf === '' || ! hash_equals($sessionCsrf, $submittedCsrf)) {
            error_log('iarc autoc rejected: csrf mismatch from ' . ($_SERVER['REMOTE_ADDR'] ?? '?'));
            return [
                'count' => 0,
                'data' => [],
                '_why' => 'csrf_mismatch',
                '_session_csrf_len' => strlen($sessionCsrf),
                '_submitted_csrf_len' => strlen($submittedCsrf),
                '_request_keys' => array_keys($request),
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
                '_term' => $term,
                '_request_keys' => array_keys($request),
                '_data_keys' => isset($request['data']) && is_array($request['data']) ? array_keys($request['data']) : null,
            ];
        }

        $maxRows = (int) ($request['maxRows'] ?? 12);
        if ($maxRows < 1 || $maxRows > 50) {
            $maxRows = 12;
        }

        $like = '%' . $term . '%';
        $rows = \App\AuthyQuery::create()
            ->filterByUsername($like)
            ->_or()->filterByEmail($like)
            ->_or()->filterByFullname($like)
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
