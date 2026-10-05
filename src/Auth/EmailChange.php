<?php

namespace ApiGoat\Auth;

/**
 * Verified account email change, fleet-wide (2026-10-05). An account PATCH
 * (template AccountServiceWrapper) no longer swaps authy.email: it mails a
 * confirmation link to the NEW address, and only opening it applies the
 * change — so everything keyed on an account's email (password reset, Google
 * auto-link, invites) can trust that the user controls that mailbox. The old
 * address then gets the "your email was changed" notice.
 *
 * Stateless — no schema change in any project: the link carries a signed,
 * expiring token {u: user id, e: new email, o: sha256 of the CURRENT email,
 * x: expiry} under an HMAC key derived from JWT_SECRET. Binding `o` makes it
 * single-use (once the email changed, the token no longer matches) and voids
 * older links when another change lands first.
 *
 * The landing is the backend page Authy/confirmEmail (goatcheese login
 * controller → page()); a project with its own front end can point the link
 * there instead with .env GC_AUTH_EMAIL_CONFIRM_URL ("/{langpath}x/{token}",
 * a leading '/' = relative to MY_PROJECT_URL's origin) and call confirm().
 */
final class EmailChange
{
    public const TTL = 172800; // 48 h

    /** Mail the confirmation link to $newEmail. The account is not modified. */
    public static function request(object $authy, string $newEmail): bool
    {
        $token = self::sign([
            'u' => (int) $authy->getIdAuthy(),
            'e' => $newEmail,
            'o' => self::fingerprint((string) $authy->getEmail()),
            'x' => time() + self::TTL,
        ]);
        if ($token === null) {
            error_log('EmailChange: JWT_SECRET missing — cannot sign a confirmation link');
            return false;
        }
        $url = self::confirmUrl($token, $authy);
        $html = '<p>' . htmlspecialchars(sprintf(_('You asked to use %s as the sign-in email of your account.'), $newEmail), ENT_QUOTES) . '</p>'
            . '<p><a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . htmlspecialchars(_('Confirm my new email'), ENT_QUOTES) . '</a></p>'
            . '<p>' . htmlspecialchars(_('The link expires in 2 days. Until you confirm, your account keeps its current email. Did not ask for this? Ignore this email.'), ENT_QUOTES) . '</p>';
        return \ApiGoat\Notify\Mailer::send($newEmail, _('Confirm your new email'), $html);
    }

    /**
     * Apply the change carried by $token.
     *
     * @return array{status:string, code:string}  code: changed | invalid | expired | taken
     */
    public static function confirm(string $token): array
    {
        $p = self::verify($token);
        if ($p === null) {
            return ['status' => 'error', 'code' => 'invalid'];
        }
        if ((int) $p['x'] < time()) {
            return ['status' => 'error', 'code' => 'expired'];
        }
        $user = \App\AuthyQuery::create()->findPk((int) $p['u']);
        if ($user === null || !hash_equals((string) $p['o'], self::fingerprint((string) $user->getEmail()))) {
            return ['status' => 'error', 'code' => 'invalid']; // used, superseded, or account gone
        }
        $new = (string) $p['e'];
        if (\App\AuthyQuery::create()->filterByEmail($new)->filterByIdAuthy((int) $user->getIdAuthy(), \Criteria::NOT_EQUAL)->count() > 0) {
            return ['status' => 'error', 'code' => 'taken'];
        }
        $old = (string) $user->getEmail();
        $user->setEmail($new);
        $user->save();
        self::notifyPrevious($old, $new);
        return ['status' => 'success', 'code' => 'changed'];
    }

    /**
     * Backend landing (Authy/confirmEmail): GET shows a Confirm button, POST
     * applies — a mail scanner prefetching the link changes nothing.
     *
     * @return array{html:string}
     */
    public static function page(array $data, bool $isPost): array
    {
        $token = (string) ($data['t'] ?? '');
        if (!$isPost) {
            $p = self::verify($token);
            if ($p === null || (int) $p['x'] < time()) {
                return self::card(_('Link invalid'), _('This confirmation link is invalid or has expired. Change your email again from your account to get a new one.'));
            }
            $csrf = '';
            $s = $_SESSION[_AUTH_VAR] ?? null;
            // A signed-in browser's POST passes the session CSRF gate with it.
            if (is_object($s) && method_exists($s, 'getCsrf') && (string) $s->getCsrf() !== '') {
                $csrf = '<input type="hidden" name="csrf" value="' . htmlspecialchars((string) $s->getCsrf(), ENT_QUOTES) . '">';
            }
            return self::card(_('Confirm your new email'),
                sprintf(_('Use %s as the sign-in email of your account?'), (string) $p['e']),
                '<form method="post" action="' . _SITE_URL . 'Authy/confirmEmail">'
                . '<input type="hidden" name="t" value="' . htmlspecialchars($token, ENT_QUOTES) . '">' . $csrf
                . '<button type="submit" style="padding:10px 18px;background:var(--colorMain,#00d1b2);color:#fff;border:0;border-radius:6px;cursor:pointer;">'
                . htmlspecialchars(_('Confirm my new email'), ENT_QUOTES) . '</button></form>');
        }
        $r = self::confirm($token);
        $msg = [
            'changed' => [_('Email updated'), _('Your account now uses this email to sign in.')],
            'expired' => [_('Link expired'), _('This link has expired. Change your email again from your account to get a new one.')],
            'taken'   => [_('Email unavailable'), _('This email is now used by another account.')],
            'invalid' => [_('Link invalid'), _('This link is not valid or was already used.')],
        ][$r['code']] ?? [_('Link invalid'), ''];
        return self::card($msg[0], $msg[1]);
    }

    public static function confirmUrl(string $token, ?object $authy = null): string
    {
        $tpl = function_exists('env') ? trim((string) env('GC_AUTH_EMAIL_CONFIRM_URL')) : '';
        if ($tpl === '') {
            return _SITE_URL . 'Authy/confirmEmail?t=' . rawurlencode($token);
        }
        $lang = $authy !== null && method_exists($authy, 'getLanguage') ? (string) $authy->getLanguage() : '';
        $langpath = stripos($lang, 'fr') === 0 ? 'fr/' : '';
        $url = str_replace(['{token}', '{langpath}'], [rawurlencode($token), $langpath], $tpl);
        if ($url !== '' && $url[0] === '/' && preg_match('#^https?://[^/]+#i', (string) env('MY_PROJECT_URL'), $m)) {
            $url = $m[0] . $url;
        }
        return $url;
    }

    private static function card(string $title, string $text, string $extra = ''): array
    {
        $logo = class_exists('\ApiGoat\Utility\Branding') ? \ApiGoat\Utility\Branding::logoUrl() : '';
        return ['html' => '<div class="login-wrapper"><div class="login-content" style="max-width:360px;margin:48px auto;text-align:center;">'
            . ($logo !== '' ? '<div class="ac-client-logo"><img src="' . htmlspecialchars($logo, ENT_QUOTES) . '" alt="" style="max-width:180px;height:auto;margin-bottom:16px;"></div>' : '')
            . '<h2>' . htmlspecialchars($title, ENT_QUOTES) . '</h2>'
            . ($text !== '' ? '<p>' . htmlspecialchars($text, ENT_QUOTES) . '</p>' : '')
            . $extra . '</div></div>'];
    }

    private static function notifyPrevious(string $old, string $new): void
    {
        if (!filter_var($old, FILTER_VALIDATE_EMAIL)) {
            return;
        }
        $masked = (string) preg_replace('/(?<=^.).*(?=@)/u', '***', $new);
        \ApiGoat\Notify\Mailer::send(
            $old,
            _('Your account email was changed'),
            '<p>' . htmlspecialchars(sprintf(_('The sign-in email of your account was changed to %s.'), $masked), ENT_QUOTES) . '</p>'
            . '<p>' . htmlspecialchars(_('If you did not make this change, contact your administrator immediately.'), ENT_QUOTES) . '</p>'
        );
    }

    private static function fingerprint(string $email): string
    {
        return hash('sha256', mb_strtolower(trim($email)));
    }

    private static function key(): ?string
    {
        $secret = function_exists('env') ? (string) env('JWT_SECRET') : (string) getenv('JWT_SECRET');
        return $secret === '' ? null : hash_hmac('sha256', 'gc-email-change-v1', $secret, true);
    }

    private static function b64(string $s): string
    {
        return rtrim(strtr(base64_encode($s), '+/', '-_'), '=');
    }

    private static function sign(array $payload): ?string
    {
        $key = self::key();
        if ($key === null) {
            return null;
        }
        $body = self::b64((string) json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        return $body . '.' . self::b64(hash_hmac('sha256', $body, $key, true));
    }

    /** @return array{u:int,e:string,o:string,x:int}|null */
    private static function verify(string $token): ?array
    {
        $key = self::key();
        if ($key === null || strlen($token) > 2048 || !preg_match('/^[A-Za-z0-9_-]+\.[A-Za-z0-9_-]+$/', $token)) {
            return null;
        }
        [$body, $sig] = explode('.', $token, 2);
        if (!hash_equals(self::b64(hash_hmac('sha256', $body, $key, true)), $sig)) {
            return null;
        }
        $p = json_decode((string) base64_decode(strtr($body, '-_', '+/')), true);
        if (!is_array($p) || !isset($p['u'], $p['e'], $p['o'], $p['x'])
            || !filter_var((string) $p['e'], FILTER_VALIDATE_EMAIL)) {
            return null;
        }
        return $p;
    }
}
