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
 *
 * Session-learned overlay: config/mcp.identity.local.php (editable, never
 * regenerated, excluded from gc deploy so each host keeps what it learned):
 *   ['about' => string|null, 'keywords' => string[], 'removed' => string[]]
 * written by the gc_identity_update tool when the model finds a routing term
 * the schema lacks. read() merges it over the built manifest: a non-empty
 * local `about` replaces the built one; keywords = built ∪ learned − removed.
 * Promote learned terms into the schema with `gc mcp --setup` (it pre-fills
 * from the merged view).
 */
final class McpIdentity
{
    public const FILE       = 'config/Built/mcp.identity.php';
    public const LOCAL_FILE = 'config/mcp.identity.local.php';
    public const ABOUT_MAX  = 300;

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
        $built = self::readBuilt($base);
        return $built === null ? null : self::merge($built, self::readLocal($base));
    }

    /** The built manifest alone (no overlay), or null when absent. @return array{name:string,about:string,keywords:string[],missing:string[]}|null */
    public static function readBuilt(?string $baseDir = null): ?array
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
     * The session-learned overlay, or null when absent / not an array.
     *
     * @return array{about:string,keywords:string[],removed:string[]}|null
     */
    public static function readLocal(?string $baseDir = null): ?array
    {
        $base = $baseDir ?? (defined('_BASE_DIR') ? _BASE_DIR : null);
        if ($base === null || $base === '') {
            return null;
        }
        $path = rtrim($base, '/\\') . '/' . self::LOCAL_FILE;
        if (!is_file($path)) {
            return null;
        }
        $v = require $path;
        return is_array($v) ? self::normalizeLocal($v) : null;
    }

    /**
     * Write (or, when empty, remove) the overlay. Returns the file path.
     *
     * @param array{about?:string,keywords?:string[],removed?:string[]} $local
     */
    public static function writeLocal(array $local, ?string $baseDir = null): string
    {
        $base = $baseDir ?? (defined('_BASE_DIR') ? _BASE_DIR : null);
        if ($base === null || $base === '') {
            throw new \RuntimeException('No project base dir to write ' . self::LOCAL_FILE);
        }
        $path  = rtrim($base, '/\\') . '/' . self::LOCAL_FILE;
        $local = self::normalizeLocal($local);
        if ($local['about'] === '' && $local['keywords'] === [] && $local['removed'] === []) {
            if (is_file($path)) {
                @unlink($path);
            }
            return $path;
        }
        $php = "<?php\n// Session-learned MCP identity overlay (gc_identity_update). Editable; merged over\n"
             . "// config/Built/mcp.identity.php by ApiGoat\\Mcp\\McpIdentity::read(). Promote into the\n"
             . "// schema's with_mcp with `gc mcp --setup`.\nreturn " . var_export($local, true) . ";\n";
        $tmp = $path . '.tmp' . getmypid();
        if (file_put_contents($tmp, $php) === false || !rename($tmp, $path)) {
            @unlink($tmp);
            throw new \RuntimeException('Cannot write ' . $path);
        }
        return $path;
    }

    /**
     * Built identity + overlay → effective identity. Pure.
     *
     * @param array{name:string,about:string,keywords:string[],missing:string[]} $built
     * @param array{about:string,keywords:string[],removed:string[]}|null $local
     * @return array{name:string,about:string,keywords:string[],missing:string[]}
     */
    public static function merge(array $built, ?array $local): array
    {
        if ($local === null) {
            return $built;
        }
        $removed  = array_map('mb_strtolower', $local['removed']);
        $keywords = [];
        foreach (array_merge($built['keywords'], $local['keywords']) as $k) {
            $lk = mb_strtolower($k);
            if (in_array($lk, $removed, true) || in_array($lk, $keywords, true)) {
                continue;
            }
            $keywords[] = $lk;
        }
        $about = $local['about'] !== '' ? $local['about'] : $built['about'];
        $missing = array_values(array_filter($built['missing'], static function (string $f) use ($about, $keywords): bool {
            return !($f === 'about' && $about !== '') && !($f === 'keywords' && $keywords !== []);
        }));
        return ['name' => $built['name'], 'about' => $about, 'keywords' => $keywords, 'missing' => $missing];
    }

    /** Lowercased, trimmed, de-duplicated routing terms from an array or a comma-separated string. @return string[] */
    public static function keywordList($v): array
    {
        if (is_string($v)) {
            $v = explode(',', $v);
        }
        $out = [];
        foreach (self::stringList($v) as $k) {
            $k = mb_strtolower(self::clean($k));
            if ($k !== '' && !in_array($k, $out, true)) {
                $out[] = $k;
            }
        }
        return $out;
    }

    /**
     * One-paragraph model-facing preamble naming this server's project; null
     * when there is no identity or no name. Pure: no I/O, no globals.
     */
    public static function preamble(?array $identity, bool $canLearn = false): ?string
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
            . 'for the rest of the conversation unless the user switches.'
            . ($canLearn
                ? ' Whenever the user had to tell you to use this server, or referred to this project by a '
                  . 'name or term that is not in that list, call gc_identity_update to add the term (it '
                  . 'echoes the resulting identity without confirm — show it to the user and re-call with '
                  . 'confirm:true once they approve) so the next conversation routes here on its own.'
                : '');
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

    /** @return array{about:string,keywords:string[],removed:string[]} */
    private static function normalizeLocal(array $v): array
    {
        $about = is_scalar($v['about'] ?? null) ? self::clean($v['about']) : '';
        return [
            'about'    => mb_substr($about, 0, self::ABOUT_MAX),
            'keywords' => self::keywordList($v['keywords'] ?? []),
            'removed'  => self::keywordList($v['removed'] ?? []),
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
