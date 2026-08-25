<?php

namespace ApiGoat\Notify;

/**
 * Render a `template` row against a record, send it, and log the send.
 *
 * The trio — list templates, render one bound to a record, send and write a
 * journal/log row — was written four times: byte-identical in aexpert and
 * apigoatacc (ClientServiceWrapper::getEmailTemplateBody / ::sendEmail), a
 * near-copy in ut911, and the same idea over a different transport in apicrm
 * (ContactServiceWrapper, which routes through the Gmail API and an
 * OutboundQueue and writes an email_message row with state='Queued').
 *
 * Transport is therefore a parameter, not a hardcoded PHPMailer call: apicrm's
 * queued path is a legitimate second implementation, not drift to be flattened.
 */
final class TemplatedSend
{
    /**
     * Render a template body/subject with the project's MatchReplace, binding
     * each named object.
     *
     * MatchReplace lives in the project (App\Domains\Template\MatchReplace) —
     * it ships from the template, so it is present in every project but is not
     * runtime code. Absent, the raw text is returned rather than throwing.
     *
     * @param array<string,object> $objects e.g. ['Client' => $client]
     */
    public static function render(string $text, array $objects): string
    {
        if ($text === '' || !\class_exists('\App\Domains\Template\MatchReplace')) {
            return $text;
        }
        try {
            $mr = new \App\Domains\Template\MatchReplace($text);
            foreach ($objects as $name => $obj) {
                $mr->setDataObj($name, $obj);
            }

            return (string) $mr->getContent();
        } catch (\Throwable $e) {
            \error_log('TemplatedSend::render failed: ' . $e->getMessage());

            return $text;
        }
    }

    /**
     * Load a template row and render both its subject and body.
     *
     * @param array<string,object> $objects
     * @return array{subject:string, html:string}|null null when the template is missing
     */
    public static function fromTemplate(int $idTemplate, array $objects): ?array
    {
        if (!\class_exists('\App\TemplateQuery')) {
            return null;
        }
        $template = \App\TemplateQuery::create()->findPk($idTemplate);
        if (!$template) {
            return null;
        }

        return [
            'subject' => self::render((string) ($template->getSubject() ?? ''), $objects),
            'html'    => self::render((string) ($template->getBody() ?? ''), $objects),
        ];
    }

    /**
     * Render a template and send it.
     *
     * @param string|string[] $to
     * @param array<string,object> $objects
     * @param array<string,mixed> $opts forwarded to Mailer::send, plus:
     *                                  transport — callable(to, subject, html, opts): bool
     * @return bool false when the template is missing or the send failed
     */
    public static function send(int $idTemplate, string|array $to, array $objects, array $opts = []): bool
    {
        $msg = self::fromTemplate($idTemplate, $objects);
        if ($msg === null) {
            \error_log('TemplatedSend: template ' . $idTemplate . ' not found');

            return false;
        }

        $transport = $opts['transport'] ?? null;
        unset($opts['transport']);
        if (\is_callable($transport)) {
            return (bool) $transport($to, $msg['subject'], $msg['html'], $opts);
        }

        return Mailer::send($to, $msg['subject'], $msg['html'], $opts);
    }
}
