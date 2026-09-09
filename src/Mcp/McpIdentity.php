<?php
namespace ApiGoat\Mcp;

/**
 * Build-time MCP project identity: config/Built/mcp.identity.php.
 *
 * `gc build` emits it for projects with with_mcp:
 *   ['name' => string, 'about' => string, 'keywords' => string[], 'missing' => string[]]
 * (all keys present; values may be empty; the file is absent when the project
 * has no with_mcp). McpServer::initialize prefixes the instructions with the
 * preamble() so a client that has several project MCP servers connected knows
 * which project THIS server is the first source for, and uses the name as the
 * serverInfo title fallback.
 */
final class McpIdentity
{
    public const FILE = 'config/Built/mcp.identity.php';

    /**
     * Normalized identity, or null when the file is absent / not an array.
     *
     * @param string|null $baseDir project .admin dir; defaults to _BASE_DIR
     * @return array{name:string,about:string,keywords:string[],missing:string[]}|null
     */
    public static function read(?string $baseDir = null): ?array
    {
        $base = $baseDir ?? (defined('_BASE_DIR') ? _BASE_DIR : null);
        if ($base === null || $base === '') {
            return null;
        }
        $path = rtrim($base, '/\\') . '/' . self::FILE;
        if (!is_file($path)) {
            return null;
        }
        $v = require $path;
        return is_array($v) ? self::normalize($v) : null;
    }

    /**
     * One-paragraph model-facing preamble naming this server's project; null
     * when there is no identity or no name. Pure: no I/O, no globals.
     */
    public static function preamble(?array $identity): ?string
    {
        if ($identity === null) {
            return null;
        }
        $name = self::clean($identity['name'] ?? '');
        if ($name === '') {
            return null;
        }
        $about    = self::clean($identity['about'] ?? '');
        $keywords = self::cleanList($identity['keywords'] ?? []);

        return 'This server is ' . $name
            . ($about !== '' ? ' — ' . $about : '')
            . '. It is the FIRST and authoritative source for anything about'
            . ($keywords !== [] ? ': ' . implode(', ', $keywords) : ' this project')
            . '. Use it whenever the user mentions this project by name or any of those terms, and never '
            . 'answer questions about it from another server. If several project MCP servers are connected '
            . 'and the request names no project or could belong to more than one, list the candidate servers '
            . 'and ask the user which one to use BEFORE calling any tool; then keep using the chosen server '
            . 'for the rest of the conversation unless the user switches.';
    }

    /** @return array{name:string,about:string,keywords:string[],missing:string[]} */
    private static function normalize(array $v): array
    {
        return [
            'name'     => is_scalar($v['name'] ?? null) ? trim((string) $v['name']) : '',
            'about'    => is_scalar($v['about'] ?? null) ? trim((string) $v['about']) : '',
            'keywords' => self::stringList($v['keywords'] ?? []),
            'missing'  => self::stringList($v['missing'] ?? []),
        ];
    }

    /** @return string[] scalar items as trimmed strings, empties dropped, reindexed */
    private static function stringList($list): array
    {
        if (!is_array($list)) {
            return [];
        }
        $out = [];
        foreach ($list as $item) {
            if (is_scalar($item) && trim((string) $item) !== '') {
                $out[] = trim((string) $item);
            }
        }
        return $out;
    }

    /** Control chars / newlines → single spaces; trimmed. */
    private static function clean($s): string
    {
        if (!is_scalar($s)) {
            return '';
        }
        $s = preg_replace('/[\x00-\x1F\x7F]+/u', ' ', (string) $s) ?? '';
        return trim(preg_replace('/\s+/u', ' ', $s) ?? '');
    }

    /** @return string[] */
    private static function cleanList($list): array
    {
        $out = [];
        foreach (self::stringList($list) as $item) {
            $c = self::clean($item);
            if ($c !== '') {
                $out[] = $c;
            }
        }
        return $out;
    }
}
