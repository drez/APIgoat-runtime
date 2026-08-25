<?php

namespace ApiGoat\Ai;

/**
 * One row per outbound AI HTTP call, success AND failure, into the table
 * with_ai emits (default `ai_call_log`).
 *
 * Best-effort by contract: this observes the API call and must NEVER break
 * it. Every failure path here swallows and error_log()s.
 */
final class AiUsageLogger
{
    /** Map an API path to the log table's `operation` enum label. */
    public static function operationFor(string $path): string
    {
        return match (true) {
            \str_contains($path, '/chat/completions')        => 'chat',
            \str_contains($path, '/images/generations')      => 'image_generate',
            \str_contains($path, '/images/edits')            => 'image_edit',
            \str_contains($path, '/embeddings')              => 'embed',
            \str_contains($path, '/audio/speech')            => 'tts',
            \str_contains($path, '/audio/transcriptions')    => 'stt',
            \str_contains($path, '/realtime/client_secrets') => 'realtime_mint',
            \str_contains($path, '/models')                  => 'ping',
            default                                          => 'other',
        };
    }

    /** ok | http_error | transport_error, from the HTTP status alone. */
    public static function outcomeFor(int $httpStatus): string
    {
        if ($httpStatus === 0) {
            return 'transport_error';
        }

        return ($httpStatus >= 200 && $httpStatus < 300) ? 'ok' : 'http_error';
    }

    /**
     * Pull token counts and a usage-derived cost out of a decoded response.
     *
     * Shapes differ per endpoint: chat/embeddings report
     * usage.prompt_tokens / usage.completion_tokens, the responses API uses
     * input_tokens / output_tokens, and audio/image endpoints report nothing
     * at all. Returns zeros rather than nulls so a caller can always add.
     *
     * @param mixed $decoded
     * @return array{0:int,1:int}
     */
    public static function tokensOf($decoded): array
    {
        if (!\is_array($decoded) || !isset($decoded['usage']) || !\is_array($decoded['usage'])) {
            return [0, 0];
        }
        $u = $decoded['usage'];
        $in  = (int) ($u['prompt_tokens'] ?? $u['input_tokens'] ?? 0);
        $out = (int) ($u['completion_tokens'] ?? $u['output_tokens'] ?? 0);

        return [$in, $out];
    }

    /**
     * Write one row. Silently does nothing when the behavior is not declared
     * or the generated model is absent.
     *
     * @param mixed $decoded decoded response body, for token counts + error text
     */
    public static function log(
        string $path,
        ?string $model,
        int $httpStatus,
        float $seconds,
        string $transportError = '',
        $decoded = null,
        ?float $cost = null
    ): void {
        // Server-Timing span, recorded HERE rather than at each call site:
        // every outbound call funnels through AiGateway::post(), so a new
        // endpoint added later is instrumented for free. Outside the try on
        // purpose — the telemetry INSERT is allowed to fail, but a failed
        // insert must not also lose the timing.
        if (\class_exists(\ApiGoat\Utility\Timing::class)) {
            \ApiGoat\Utility\Timing::add('ai', $seconds * 1000);
        }

        $table = AiManifest::logTable();
        if ($table === '') {
            return;
        }
        $class = '\\App\\' . self::phpName($table);
        if (!\class_exists($class)) {
            return;
        }

        try {
            $row = new $class();
            $row->setOperation(self::operationFor($path));
            $row->setEndpoint(\substr($path, 0, 80));
            if ($model !== null && $model !== '') {
                $row->setModel(\substr($model, 0, 64));
            }
            $row->setHttpStatus($httpStatus);
            $row->setLatencyMs((int) \round($seconds * 1000));
            $row->setOutcome(self::outcomeFor($httpStatus));

            [$in, $out] = self::tokensOf($decoded);
            if (\method_exists($row, 'setInputTokens')) {
                $row->setInputTokens($in);
                $row->setOutputTokens($out);
            }
            if ($cost !== null && \method_exists($row, 'setCost')) {
                $row->setCost($cost);
            }

            $err = $transportError !== '' ? $transportError : self::errorMessage($decoded, $httpStatus);
            if ($err !== '') {
                $row->setError(\mb_substr($err, 0, 255));
            }
            $row->setCreatedAt(\date('Y-m-d H:i:s'));
            $row->save();
        } catch (\Throwable $e) {
            \error_log('AiUsageLogger failed (non-fatal): ' . $e->getMessage());
        }
    }

    /** table_name -> TableName, matching Propel's PhpName generation. */
    public static function phpName(string $table): string
    {
        return \str_replace(' ', '', \ucwords(\str_replace('_', ' ', $table)));
    }

    /** @param mixed $decoded */
    private static function errorMessage($decoded, int $httpStatus): string
    {
        if ($httpStatus < 400 || !\is_array($decoded)) {
            return '';
        }

        return (string) ($decoded['error']['message'] ?? \json_encode($decoded));
    }
}
