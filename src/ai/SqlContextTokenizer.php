<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\ai;

/** Small lexical scanner, not a general SQL parser or an anonymization boundary. */
final class SqlContextTokenizer
{
    /** @return list<array{text: string, kind: string}> */
    public static function tokens(string $sql): array
    {
        $tokens = [];
        $length = strlen($sql);
        for ($i = 0; $i < $length;) {
            $start = $i;
            $char = $sql[$i];
            if (ctype_space($char)) {
                ++$i;
                continue;
            }
            if (substr($sql, $i, 2) === '--' || $char === '#') {
                $end = strpos($sql, "\n", $i);
                $i = $end === false ? $length : $end;
                continue;
            }
            if (substr($sql, $i, 2) === '/*') {
                $depth = 1;
                $i += 2;
                while ($i < $length && $depth > 0) {
                    if (substr($sql, $i, 2) === '/*') {
                        ++$depth;
                        $i += 2;
                    } elseif (substr($sql, $i, 2) === '*/') {
                        --$depth;
                        $i += 2;
                    } else {
                        ++$i;
                    }
                }
                continue;
            }
            if ($char === '$' && preg_match('/\G\$(?:[a-zA-Z_][a-zA-Z0-9_]*)?\$/A', $sql, $m, 0, $i)) {
                $end = strpos($sql, $m[0], $i + strlen($m[0]));
                $i = $end === false ? $length : $end + strlen($m[0]);
                $tokens[] = ['text' => substr($sql, $start, $i - $start), 'kind' => 'literal'];
                continue;
            }
            // Oracle alternative quoted strings, including paired delimiters.
            if (($char === 'q' || $char === 'Q') && substr($sql, $i + 1, 1) === "'" && $i + 2 < $length) {
                $open = $sql[$i + 2];
                $close = ['[' => ']', '(' => ')', '{' => '}', '<' => '>'][$open] ?? $open;
                $end = strpos($sql, $close . "'", $i + 3);
                $i = $end === false ? $length : $end + 2;
                $tokens[] = ['text' => substr($sql, $start, $i - $start), 'kind' => 'literal'];
                continue;
            }
            if (in_array($char, ["'", '"', '`', '['], true)) {
                $endChar = $char === '[' ? ']' : $char;
                ++$i;
                while ($i < $length) {
                    if ($sql[$i] === '\\' && $char === "'") {
                        $i += 2;
                    } elseif ($sql[$i] === $endChar) {
                        ++$i;
                        if (($sql[$i] ?? '') !== $endChar) {
                            break;
                        }
                        ++$i;
                    } else {
                        ++$i;
                    }
                }
                $tokens[] = ['text' => substr($sql, $start, $i - $start), 'kind' => $char === "'" ? 'literal' : 'identifier'];
            } elseif (preg_match('/\G(?:\d+(?:\.\d*)?|\.\d+)(?:[eE][+-]?\d+)?/A', $sql, $m, 0, $i)) {
                $i += strlen($m[0]);
                $tokens[] = ['text' => $m[0], 'kind' => 'literal'];
            } elseif (preg_match('/\G(?::[a-zA-Z_][a-zA-Z0-9_]*|\$\d+|@[a-zA-Z_][a-zA-Z0-9_]*|\?)/A', $sql, $m, 0, $i)) {
                $i += strlen($m[0]);
                $tokens[] = ['text' => $m[0], 'kind' => 'parameter'];
            } elseif (preg_match('/\G[a-zA-Z_][a-zA-Z0-9_$]*/A', $sql, $m, 0, $i)) {
                $i += strlen($m[0]);
                $tokens[] = ['text' => $m[0], 'kind' => 'word'];
            } else {
                $tokens[] = ['text' => $char, 'kind' => 'symbol'];
                ++$i;
            }
        }
        return $tokens;
    }

    public static function redact(string $sql): string
    {
        return implode(' ', array_map(static fn ($token) => $token['kind'] === 'literal' ? '?' : $token['text'], self::tokens($sql)));
    }

    /** @return array{schema: ?string, table: string}|null */
    public static function table(string $reference): ?array
    {
        $tokens = self::tokens($reference);
        if (!isset($tokens[0]) || !in_array($tokens[0]['kind'], ['word', 'identifier'], true)) {
            return null;
        }
        $first = self::unquote($tokens[0]['text']);
        if (($tokens[1]['text'] ?? '') === '.' && isset($tokens[2]) && in_array($tokens[2]['kind'], ['word', 'identifier'], true)) {
            return ['schema' => $first, 'table' => self::unquote($tokens[2]['text'])];
        }
        return ['schema' => null, 'table' => $first];
    }

    public static function unquote(string $identifier): string
    {
        $first = $identifier[0] ?? '';
        $end = $first === '[' ? ']' : $first;
        return in_array($first, ['"', '`', '['], true)
            ? str_replace($end . $end, $end, substr($identifier, 1, -1)) : $identifier;
    }

    /** Best-effort discovery; explicit physical tables are available for ambiguous SQL.
     * @return list<string>
     */
    public static function tables(string $sql): array
    {
        $tokens = self::tokens($sql);
        $ctes = [];
        $tables = [];
        foreach ($tokens as $i => $token) {
            if (in_array($token['kind'], ['word', 'identifier'], true) && strtoupper($tokens[$i + 1]['text'] ?? '') === 'AS' && ($tokens[$i + 2]['text'] ?? '') === '(') {
                $ctes[] = strtolower(self::unquote($token['text']));
            }
        }
        $depth = 0;
        $fromDepths = [];
        foreach ($tokens as $i => $token) {
            if ($token['text'] === '(') {
                ++$depth;
            } elseif ($token['text'] === ')') {
                unset($fromDepths[$depth]);
                --$depth;
            }
            $keyword = $token['kind'] === 'word' ? strtoupper($token['text']) : '';
            if (in_array($keyword, ['WHERE', 'GROUP', 'HAVING', 'ORDER', 'UNION', 'LIMIT', 'OFFSET', 'EXCEPT', 'INTERSECT'], true)) {
                unset($fromDepths[$depth]);
            }
            if ($keyword === 'FROM') {
                $fromDepths[$depth] = true;
            }
            if (!in_array($keyword, ['FROM', 'JOIN'], true) && !($token['text'] === ',' && isset($fromDepths[$depth]))) {
                continue;
            }
            $next = $tokens[$i + 1] ?? null;
            if ($next === null || !in_array($next['kind'], ['word', 'identifier'], true)) {
                continue;
            }
            $ref = $next['text'];
            if (($tokens[$i + 2]['text'] ?? '') === '.') {
                $ref .= '.' . ($tokens[$i + 3]['text'] ?? '');
            } elseif (in_array(strtolower(self::unquote($ref)), $ctes, true) || ($tokens[$i + 2]['text'] ?? '') === '(') {
                continue;
            }
            $tables[] = $ref;
        }
        return array_values(array_unique($tables));
    }
}
