<?php

namespace ApiGoat\Notify;

use PHPMailer\PHPMailer\PHPMailer;

/**
 * The one PHPMailer bootstrap.
 *
 * The same ~35-line block — read the `email` array from config/settings.php,
 * branch isSMTP()/isSendmail(), set Host/Port/SMTPSecure/Username/Password,
 * setFrom, addAddress, msgHTML, send — was written about fifteen times across
 * six projects (aexpert, apigoatacc, ut911, apichatbot, apigTutor, vidifye).
 *
 * The copies disagreed on exactly one thing, and it is a security setting:
 * every one of them hardcoded
 *
 *     SMTPOptions = ['ssl' => ['verify_peer' => false, ...]]
 *
 * disabling TLS certificate verification unconditionally — except
 * p/apichatbot's App\Domains\Notify\Mailer, which gates it behind an explicit
 * email.allow_self_signed opt-in. This takes apichatbot's behaviour: peer
 * verification stays ON (PHPMailer's secure default) unless an operator opts
 * in for an internal relay with a self-signed certificate.
 *
 * Never throws. Returns false and logs, so one bad address cannot abort a
 * batch — the contract every calling cron already relied on.
 */
final class Mailer
{
    /** @var array<string,mixed>|null */
    private static ?array $configMemo = null;

    /** @return array<string,mixed> the project `email` config block */
    public static function config(): array
    {
        if (self::$configMemo === null) {
            try {
                self::$configMemo = (new \Selective\Config\Configuration(
                    require _BASE_DIR . 'config/settings.php'
                ))->getArray('email');
            } catch (\Throwable $e) {
                \error_log('Notify\Mailer: email config unavailable — ' . $e->getMessage());

                return [];
            }
        }

        return self::$configMemo;
    }

    /** Test seam. */
    public static function reset(): void
    {
        self::$configMemo = null;
    }

    /**
     * Should TLS peer verification be relaxed?
     *
     * Pure predicate, extracted so the one setting the fleet disagreed on is
     * unit-testable and cannot silently regress to "always off".
     *
     * @param array<string,mixed> $cfg
     */
    public static function allowsSelfSigned(array $cfg): bool
    {
        return !empty($cfg['allow_self_signed']);
    }

    /**
     * Send one HTML email.
     *
     * @param string|string[] $to
     * @param array<string,mixed> $opts from, from_name, reply_to, cc, bcc,
     *                                  alt_body, attachments[{name,data,mime}]
     */
    public static function send(string|array $to, string $subject, string $html, array $opts = []): bool
    {
        $cfg = self::config();
        $m = new PHPMailer(true);
        try {
            if (!empty($cfg['host'])) {
                $m->isSMTP();
                $m->SMTPAuth    = true;
                $m->SMTPAutoTLS = false;
                $m->Host        = $cfg['host'];
                $m->Port        = $cfg['port'] ?? 587;
                $m->SMTPSecure  = $cfg['smtp_secure'] ?? '';
                $m->Username    = $cfg['username'] ?? '';
                $m->Password    = $cfg['password'] ?? '';
                if (self::allowsSelfSigned($cfg)) {
                    $m->SMTPOptions = ['ssl' => [
                        'verify_peer'       => false,
                        'verify_peer_name'  => false,
                        'allow_self_signed' => true,
                    ]];
                }
            } else {
                $m->isSendmail();
            }

            $m->CharSet = $cfg['charset'] ?? 'UTF-8';
            $m->setFrom(
                (string) ($opts['from'] ?? $cfg['default_from'] ?? ''),
                (string) ($opts['from_name'] ?? $cfg['default_from_name'] ?? '')
            );
            foreach ((array) $to as $addr) {
                $m->addAddress((string) $addr);
            }
            foreach ((array) ($opts['cc'] ?? []) as $addr) {
                $m->addCC((string) $addr);
            }
            foreach ((array) ($opts['bcc'] ?? []) as $addr) {
                $m->addBCC((string) $addr);
            }
            if (!empty($opts['reply_to'])) {
                $m->addReplyTo((string) $opts['reply_to']);
            }

            $m->Subject = $subject;
            $m->msgHTML($html);
            if (!empty($opts['alt_body'])) {
                $m->AltBody = (string) $opts['alt_body'];
            }

            // In-memory attachments (a generated PDF, a CSV export) — the
            // aexpert/apigoatacc invoice senders all did this by hand.
            foreach ((array) ($opts['attachments'] ?? []) as $att) {
                if (!isset($att['data'], $att['name'])) {
                    continue;
                }
                $m->addStringAttachment(
                    (string) $att['data'],
                    (string) $att['name'],
                    PHPMailer::ENCODING_BASE64,
                    (string) ($att['mime'] ?? 'application/octet-stream')
                );
            }

            $m->send();

            return true;
        } catch (\Throwable $e) {
            $addr = \is_array($to) ? \implode(', ', $to) : $to;
            \error_log('Notify\Mailer::send failed to ' . $addr . ': ' . $e->getMessage());

            return false;
        }
    }
}
