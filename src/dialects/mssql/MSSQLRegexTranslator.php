<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\dialects\mssql;

use tommyknocker\pdodb\exceptions\UnsupportedOperationException;

/**
 * SQL Server (before 2025) has no regular expressions, only LIKE patterns. This translates the part of regex syntax
 * that LIKE can express exactly and rejects the rest, so a pattern never matches differently than it would elsewhere.
 *
 * Supported: literal characters, escaped characters (\. \- \\ ...), \d, "." (one character), ".*" and ".+", bracket
 * classes ([abc] [a-z] [^0-9]), and ^ / $ at the very start / end. Quantifiers on anything but ".", alternation,
 * groups, \w \s \b and {n,m} have no LIKE equivalent and raise UnsupportedOperationException.
 */
final class MSSQLRegexTranslator
{
    /**
     * @return array{like: string, literal: string|null} The LIKE pattern, and the text it matches when the regex is
     *                                                   a plain literal (no anchors, wildcards or classes)
     */
    public static function translate(string $regex): array
    {
        $chars = preg_split('//u', $regex, -1, PREG_SPLIT_NO_EMPTY);
        if ($chars === false) {
            throw self::unsupported($regex, 'it is not valid UTF-8');
        }

        $count = count($chars);
        $i = 0;
        $anchoredStart = false;
        $anchoredEnd = false;
        $like = '';
        $literal = '';
        $isLiteral = true;

        if ($count > 0 && $chars[0] === '^') {
            $anchoredStart = true;
            $isLiteral = false;
            $i = 1;
        }

        for (; $i < $count; $i++) {
            $c = $chars[$i];
            $next = $chars[$i + 1] ?? null;

            if ($c === '$' && $i === $count - 1) {
                $anchoredEnd = true;
                $isLiteral = false;
                continue;
            }

            if ($c === '\\') {
                if ($next === null) {
                    throw self::unsupported($regex, 'it ends with a backslash');
                }
                $i++;
                if ($next === 'd') {
                    $like .= '[0-9]';
                    $isLiteral = false;
                } elseif (ctype_alnum($next)) {
                    throw self::unsupported($regex, "\\{$next} has no LIKE equivalent");
                } else {
                    $like .= self::literalChar($next);
                    $literal .= $next;
                }
                self::rejectQuantifier($regex, $chars[$i + 1] ?? null);
                continue;
            }

            if ($c === '.') {
                $isLiteral = false;
                if ($next === '*') {
                    $like .= '%';
                    $i++;
                } elseif ($next === '+') {
                    $like .= '_%';
                    $i++;
                } else {
                    $like .= '_';
                }
                self::rejectQuantifier($regex, $chars[$i + 1] ?? null);
                continue;
            }

            if ($c === '[') {
                $isLiteral = false;
                $class = '[';
                for ($i++; $i < $count && $chars[$i] !== ']'; $i++) {
                    if ($chars[$i] === '\\') {
                        throw self::unsupported($regex, 'escapes inside [...] have no LIKE equivalent');
                    }
                    $class .= $chars[$i];
                }
                if ($i >= $count || $class === '[' || $class === '[^') {
                    throw self::unsupported($regex, 'a [...] class is empty or not closed');
                }
                $like .= $class . ']';
                self::rejectQuantifier($regex, $chars[$i + 1] ?? null);
                continue;
            }

            if (str_contains('*+?{}()|^$', $c)) {
                throw self::unsupported($regex, "'{$c}' has no LIKE equivalent");
            }

            $like .= self::literalChar($c);
            $literal .= $c;
            self::rejectQuantifier($regex, $next);
        }

        return [
            'like' => ($anchoredStart ? '' : '%') . $like . ($anchoredEnd ? '' : '%'),
            'literal' => $isLiteral ? $literal : null,
        ];
    }

    /**
     * A character as it is written inside a LIKE pattern.
     */
    private static function literalChar(string $char): string
    {
        // ']' is an ordinary character outside a set; inside "[]]" it would not be one
        return in_array($char, ['%', '_', '['], true) ? '[' . $char . ']' : $char;
    }

    private static function rejectQuantifier(string $regex, ?string $next): void
    {
        if ($next !== null && str_contains('*+?{', $next)) {
            throw self::unsupported($regex, "the quantifier '{$next}' has no LIKE equivalent");
        }
    }

    private static function unsupported(string $regex, string $reason): UnsupportedOperationException
    {
        return new UnsupportedOperationException(
            "The regular expression '{$regex}' cannot be used on SQL Server before 2025: {$reason}. "
            . 'SQL Server has only LIKE patterns; use a simpler pattern, move the filter to PHP, or run on SQL Server 2025+.'
        );
    }
}
