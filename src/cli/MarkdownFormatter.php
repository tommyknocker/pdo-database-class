<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli;

/**
 * Formats markdown text for console output.
 */
class MarkdownFormatter
{
    protected bool $useColors;

    public function __construct(?bool $useColors = null)
    {
        if ($useColors === null) {
            // Check if terminal supports colors and NO_COLOR is not set
            $this->useColors = function_exists('posix_isatty') && posix_isatty(STDOUT) && getenv('NO_COLOR') === false;
        } else {
            $this->useColors = $useColors;
        }
    }

    /**
     * Format markdown text for console output.
     *
     * @param string $markdown Markdown text
     *
     * @return string Formatted text for console
     */
    public function format(string $markdown): string
    {
        $text = $markdown;

        // First, protect code blocks (```code```) by replacing them with placeholders
        $codeBlocks = [];
        $codeBlockIndex = 0;
        $text = preg_replace_callback('/```(\w+)?\n(.*?)```/s', function (array $matches) use (&$codeBlocks, &$codeBlockIndex): string {
            // preg_replace_callback guarantees indices 1 and 2 exist for this pattern
            $code = $matches[2];
            $lang = $matches[1] !== '' ? $matches[1] : '';
            $placeholder = "\x00CODEBLOCK" . $codeBlockIndex . "\x00";
            $codeBlocks[$placeholder] = $this->formatCodeBlock($code, $lang);
            $codeBlockIndex++;
            return $placeholder;
        }, $text);
        if ($text === null) {
            $text = $markdown;
        }
        $text = (string)$text;

        // Protect inline code blocks (`code`) by replacing them with placeholders
        // This protects content from markdown formatting (like asterisks and underscores)
        $inlineCode = [];
        $inlineCodeIndex = 0;

        // First, handle double backticks (``code``) - these can contain single backticks
        // Pattern: `` followed by content (which can include `) followed by ``
        // Match the shortest possible sequence to avoid greedy matching
        $text = preg_replace_callback('/``(.*?)``/s', function (array $matches) use (&$inlineCode, &$inlineCodeIndex): string {
            // preg_replace_callback guarantees index 1 exists
            $code = $matches[1];
            $placeholder = "\x00INLINECODE" . $inlineCodeIndex . "\x00";
            $inlineCode[$placeholder] = $this->useColors ? "\033[36m{$code}\033[0m" : "[{$code}]";
            $inlineCodeIndex++;
            return $placeholder;
        }, $text);
        if ($text === null) {
            $text = '';
        }
        $text = (string)$text;

        // Then handle single backticks (`code`) - these cannot contain backticks
        // Use non-greedy matching to handle cases correctly
        $text = preg_replace_callback('/`([^`\n]+?)`/', function (array $matches) use (&$inlineCode, &$inlineCodeIndex): string {
            // preg_replace_callback guarantees index 1 exists
            $code = $matches[1];
            $placeholder = "\x00INLINECODE" . $inlineCodeIndex . "\x00";
            $inlineCode[$placeholder] = $this->useColors ? "\033[36m{$code}\033[0m" : "[{$code}]";
            $inlineCodeIndex++;
            return $placeholder;
        }, $text);
        if ($text === null) {
            $text = '';
        }
        $text = (string)$text;

        // Format headers
        $text = (string)preg_replace('/^### (.*)$/m', $this->formatHeader(3, '$1'), $text);
        $text = (string)preg_replace('/^## (.*)$/m', $this->formatHeader(2, '$1'), $text);
        $text = (string)preg_replace('/^# (.*)$/m', $this->formatHeader(1, '$1'), $text);

        // Format bold text
        $text = (string)preg_replace('/\*\*(.+?)\*\*/', $this->useColors ? "\033[1m\$1\033[0m" : '**$1**', $text);
        $text = (string)preg_replace('/__(.+?)__/', $this->useColors ? "\033[1m\$1\033[0m" : '__$1__', $text);

        // Format unordered lists BEFORE italic formatting to avoid conflicts
        // This ensures list markers are processed before italic markers
        $replaced = preg_replace_callback('/^(\s*)[-*+] (.+)$/m', function (array $matches): string {
            // preg_replace_callback guarantees these indices exist
            $indent = $matches[1];
            $content = $matches[2];
            $bullet = $this->useColors ? "\033[0;33m•\033[0m" : '•';
            return $indent . $bullet . ' ' . $content;
        }, $text);
        $text = $replaced !== null ? $replaced : $text;
        $text = (string)$text;

        // Format italic text AFTER lists to avoid interfering with list markers
        // Only format if underscores are around words (not inside words like users_created_at)
        // Pattern: _word_ but not word_word or _word_word
        // Exclude asterisks that are list markers (at start of line with optional whitespace)
        $replaced = preg_replace('/(?<!^|\n)(?<!\S)\*(.+?)\*(?!\S)/', $this->useColors ? "\033[3m\$1\033[0m" : '*$1*', $text);
        $text = $replaced !== null ? $replaced : $text;
        // Match _text_ only if not preceded/followed by word characters (letters, digits, underscores)
        $replaced = preg_replace('/(?<![a-zA-Z0-9_])_(.+?)_(?![a-zA-Z0-9_])/', $this->useColors ? "\033[3m\$1\033[0m" : '_$1_', $text);
        $text = $replaced !== null ? $replaced : $text;

        // Restore inline code blocks
        foreach ($inlineCode as $placeholder => $formatted) {
            $text = str_replace($placeholder, $formatted, $text);
        }

        // Restore code blocks
        foreach ($codeBlocks as $placeholder => $formatted) {
            $text = str_replace($placeholder, $formatted, $text);
        }

        // Format ordered lists
        $text = preg_replace_callback('/^(\s*)(\d+)\. (.+)$/m', function (array $matches): string {
            // preg_replace_callback guarantees these indices exist
            $indent = $matches[1];
            $number = $matches[2];
            $content = $matches[3];
            $formattedNumber = $this->useColors ? "\033[0;33m{$number}.\033[0m" : "{$number}.";
            return $indent . $formattedNumber . ' ' . $content;
        }, $text);
        if ($text === null) {
            $text = '';
        }
        $text = (string)$text;

        // Format horizontal rules
        $text = preg_replace('/^---$/m', str_repeat('─', 80), $text);
        $text = ($text === null ? '' : (string)$text);
        $text = preg_replace('/^\*\*\*$/m', str_repeat('─', 80), $text);
        $text = ($text === null ? '' : (string)$text);

        // Format blockquotes
        $text = preg_replace_callback('/^> (.+)$/m', function (array $matches): string {
            // preg_replace_callback guarantees this index exists
            $content = $matches[1];
            $prefix = $this->useColors ? "\033[0;90m│\033[0m " : '│ ';
            return $prefix . $content;
        }, $text);
        if ($text === null) {
            $text = '';
        }
        $text = (string)$text;

        // Format links (show URL in parentheses)
        $text = preg_replace('/\[([^\]]+)\]\(([^)]+)\)/', '$1 ($2)', $text);
        $text = ($text === null ? '' : (string)$text);

        // Format tables (before other formatting to avoid conflicts)
        $text = $this->formatTables($text);

        // Clean up multiple blank lines
        $text = preg_replace('/\n{3,}/', "\n\n", $text);
        $text = ($text === null ? '' : (string)$text);

        return trim($text);
    }

    /**
     * Format markdown tables for console output.
     *
     * @param string $text Text with markdown tables
     *
     * @return string Text with formatted tables
     */
    protected function formatTables(string $text): string
    {
        // Match markdown tables (more flexible pattern)
        // Matches: | col1 | col2 |\n|------|------|\n| val1 | val2 |
        $pattern = '/(\|[^\n]+\|\s*\n(?:\|[-: ]+\|\s*\n)?(?:\|[^\n]+\|\s*\n?)+)/';

        $result = preg_replace_callback($pattern, function (array $matches): string {
            // preg_replace_callback guarantees index 0 exists
            $table = $matches[0];
            $formatted = $this->formatTable($table);
            // Add blank line before and after table for better readability
            return "\n" . $formatted . "\n";
        }, $text);

        return $result !== null ? $result : $text;
    }

    /**
     * Format a single markdown table.
     *
     * @param string $table Markdown table
     *
     * @return string Formatted table
     */
    protected function formatTable(string $table): string
    {
        $lines = explode("\n", trim($table));
        $rows = [];
        $headerRow = null;
        $foundSeparator = false;

        // Parse table rows
        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }

            // Check if this is a header separator (|---|---| or |:---|:---:|)
            if (preg_match('/^\|[-: ]+\|$/', $line)) {
                $foundSeparator = true;
                continue;
            }

            // Parse cells - handle both |cell| and | cell | formats
            if (preg_match('/^\|(.+)\|$/', $line, $cellMatches)) {
                // Split by | and filter empty strings
                $rawCells = explode('|', $cellMatches[1]);
                $cells = [];
                foreach ($rawCells as $cell) {
                    $trimmed = trim($cell);
                    if ($trimmed !== '') {
                        $cells[] = $trimmed;
                    }
                }

                if (empty($cells)) {
                    continue;
                }

                if ($headerRow === null && !$foundSeparator) {
                    $headerRow = $cells;
                } else {
                    $rows[] = $cells;
                }
            }
        }

        if ($headerRow === null) {
            return $table; // Return original if parsing failed
        }

        // Calculate column widths (ensure all rows have same number of columns)
        $columnCount = count($headerRow);
        $widths = array_fill(0, $columnCount, 0);

        // Calculate width for header
        foreach ($headerRow as $i => $cell) {
            $widths[$i] = max($widths[$i], mb_strlen($cell));
        }

        // Calculate width for all rows
        foreach ($rows as $row) {
            for ($i = 0; $i < $columnCount; $i++) {
                $cell = $row[$i] ?? '';
                $widths[$i] = max($widths[$i], mb_strlen($cell));
            }
        }

        // Ensure minimum width of 1
        foreach ($widths as $i => $width) {
            $widths[$i] = max(1, $width);
        }

        // Build formatted table
        $result = [];
        $topBorder = $this->buildTableTopBorder($widths);
        $separatorBorder = $this->buildTableSeparator($widths);
        $bottomBorder = $this->buildTableBottomBorder($widths);

        // Top border
        if ($this->useColors) {
            $result[] = "\033[0;90m{$topBorder}\033[0m";
        } else {
            $result[] = $topBorder;
        }

        // Header
        $headerLine = $this->buildTableRow($headerRow, $widths, true);
        if ($this->useColors) {
            $result[] = "\033[0;90m│\033[0m {$headerLine} \033[0;90m│\033[0m";
            $result[] = "\033[0;90m{$separatorBorder}\033[0m";
        } else {
            $result[] = "│ {$headerLine} │";
            $result[] = $separatorBorder;
        }

        // Rows
        foreach ($rows as $row) {
            $rowData = array_pad(array_slice($row, 0, $columnCount), $columnCount, '');
            $rowLine = $this->buildTableRow($rowData, $widths, false);
            if ($this->useColors) {
                $result[] = "\033[0;90m│\033[0m {$rowLine} \033[0;90m│\033[0m";
            } else {
                $result[] = "│ {$rowLine} │";
            }
        }

        // Bottom border
        if ($this->useColors) {
            $result[] = "\033[0;90m{$bottomBorder}\033[0m";
        } else {
            $result[] = $bottomBorder;
        }

        return implode("\n", $result);
    }

    /**
     * Build table row.
     *
     * @param array<int, string> $cells Row cells
     * @param array<int, int> $widths Column widths
     * @param bool $isHeader Whether this is a header row
     *
     * @return string Formatted row
     */
    protected function buildTableRow(array $cells, array $widths, bool $isHeader): string
    {
        $parts = [];
        for ($i = 0; $i < count($widths); $i++) {
            $cell = $cells[$i] ?? '';
            $width = $widths[$i];
            $padded = str_pad($cell, $width, ' ', STR_PAD_RIGHT);

            if ($isHeader && $this->useColors) {
                $parts[] = "\033[1;33m{$padded}\033[0m";
            } else {
                $parts[] = $padded;
            }

            if ($i < count($widths) - 1) {
                if ($this->useColors) {
                    $parts[] = " \033[0;90m│\033[0m ";
                } else {
                    $parts[] = ' │ ';
                }
            }
        }

        return implode('', $parts);
    }

    /**
     * Build table top border.
     *
     * @param array<int, int> $widths Column widths
     *
     * @return string Top border string
     */
    protected function buildTableTopBorder(array $widths): string
    {
        $parts = [];
        foreach ($widths as $width) {
            $parts[] = str_repeat('─', $width + 2);
        }
        return '┌' . implode('┬', $parts) . '┐';
    }

    /**
     * Build table separator (between header and rows).
     *
     * @param array<int, int> $widths Column widths
     *
     * @return string Separator string
     */
    protected function buildTableSeparator(array $widths): string
    {
        $parts = [];
        foreach ($widths as $width) {
            $parts[] = str_repeat('─', $width + 2);
        }
        return '├' . implode('┼', $parts) . '┤';
    }

    /**
     * Build table bottom border.
     *
     * @param array<int, int> $widths Column widths
     *
     * @return string Bottom border string
     */
    protected function buildTableBottomBorder(array $widths): string
    {
        $parts = [];
        foreach ($widths as $width) {
            $parts[] = str_repeat('─', $width + 2);
        }
        return '└' . implode('┴', $parts) . '┘';
    }

    /**
     * Format code block.
     *
     * @param string $code Code content
     * @param string $lang Language (optional)
     *
     * @return string Formatted code block
     */
    protected function formatCodeBlock(string $code, string $lang = ''): string
    {
        $lines = explode("\n", trim($code));
        $maxLength = 0;
        foreach ($lines as $line) {
            $maxLength = max($maxLength, mb_strlen($line));
        }

        $langLabel = $lang !== '' ? " {$lang}" : '';
        // Calculate border width: max of code width + 2 (for padding) and lang label width + 2
        // But ensure it doesn't exceed 80 characters
        $langWidth = $lang !== '' ? mb_strlen($langLabel) : 0;
        $borderWidth = min(max($maxLength + 2, $langWidth + 2), 80);
        $border = str_repeat('─', $borderWidth);

        $result = [];
        if ($this->useColors) {
            $result[] = "\033[0;90m┌{$border}┐\033[0m";
            if ($lang !== '') {
                $langPadding = max(0, $borderWidth - mb_strlen($langLabel) - 2);
                $result[] = "\033[0;90m│\033[0m\033[0;36m{$langLabel}\033[0m" . str_repeat(' ', $langPadding) . "\033[0;90m│\033[0m";
                $result[] = "\033[0;90m├{$border}┤\033[0m";
            }
        } else {
            $result[] = "┌{$border}┐";
            if ($lang !== '') {
                $langPadding = max(0, $borderWidth - mb_strlen($langLabel) - 2);
                $result[] = "│{$langLabel}" . str_repeat(' ', $langPadding) . '│';
                $result[] = "├{$border}┤";
            }
        }

        foreach ($lines as $line) {
            // Pad line to border width - 2 (for left and right padding spaces)
            $linePadding = max(0, $borderWidth - mb_strlen($line) - 2);
            $padded = $line . str_repeat(' ', $linePadding);
            if ($this->useColors) {
                $result[] = "\033[0;90m│\033[0m \033[36m{$padded}\033[0m \033[0;90m│\033[0m";
            } else {
                $result[] = "│ {$padded} │";
            }
        }

        if ($this->useColors) {
            $result[] = "\033[0;90m└{$border}┘\033[0m";
        } else {
            $result[] = "└{$border}┘";
        }

        return implode("\n", $result);
    }

    /**
     * Format header.
     *
     * @param int $level Header level (1-6)
     * @param string $text Header text
     *
     * @return string Formatted header
     */
    protected function formatHeader(int $level, string $text): string
    {
        $text = trim($text);

        if ($level === 1) {
            $underline = str_repeat('=', mb_strlen($text));
            if ($this->useColors) {
                return "\033[1;33m{$text}\033[0m\n\033[0;33m{$underline}\033[0m";
            }
            return "{$text}\n{$underline}";
        }

        if ($level === 2) {
            $underline = str_repeat('─', mb_strlen($text));
            if ($this->useColors) {
                return "\033[1;33m{$text}\033[0m\n\033[0;33m{$underline}\033[0m";
            }
            return "{$text}\n{$underline}";
        }

        // Level 3 and below
        $prefix = str_repeat('#', $level) . ' ';
        if ($this->useColors) {
            return "\033[1;36m{$prefix}{$text}\033[0m";
        }
        return "{$prefix}{$text}";
    }
}
