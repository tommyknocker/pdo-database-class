<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\ui;

use tommyknocker\pdodb\cli\TableManager;
use tommyknocker\pdodb\helpers\exporters\CsvExporter;
use tommyknocker\pdodb\helpers\exporters\JsonExporter;
use tommyknocker\pdodb\PdoDb;

/**
 * The SQL scratchpad of the TUI dashboard: editor, autocomplete, query history, execution and results export, with the
 * screens it draws. {@see Dashboard} owns the terminal loop and hands this class the keys pressed while the scratchpad
 * is fullscreen.
 */
class SqlScratchpad
{
    /**
     * SQL Scratchpad query history (last 50 queries).
     *
     * @var array<int, string>
     */
    protected array $scratchpadHistory = [];
    /**
     * Current SQL query in editor.
     *
     * @var string
     */
    protected string $scratchpadQuery = '';
    /**
     * Cursor position in SQL editor (line, column).
     *
     * @var array{line: int, col: int}
     */
    protected array $scratchpadCursor = ['line' => 0, 'col' => 0];
    /**
     * History navigation index (-1 when not navigating).
     *
     * @var int
     */
    protected int $scratchpadHistoryIndex = -1;
    /**
     * Autocomplete active state.
     *
     * @var bool
     */
    protected bool $scratchpadAutocompleteActive = false;
    /**
     * Autocomplete options list.
     *
     * @var array<int, string>
     */
    protected array $scratchpadAutocompleteOptions = [];
    /**
     * Autocomplete selected index.
     *
     * @var int
     */
    protected int $scratchpadAutocompleteIndex = 0;
    /**
     * Autocomplete prefix (text to match).
     *
     * @var string
     */
    protected string $scratchpadAutocompletePrefix = '';
    /**
     * Cached table list for autocomplete.
     *
     * @var array<int, string>|null
     */
    protected ?array $scratchpadTableCache = null;
    /**
     * Cached columns for tables (table => columns).
     *
     * @var array<string, array<int, string>>
     */
    protected array $scratchpadColumnCache = [];
    /**
     * Scratchpad mode: 'editor' or 'results'.
     *
     * @var string
     */
    protected string $scratchpadMode = 'editor';
    /**
     * Transaction mode (auto-commit if false).
     *
     * @var bool
     */
    protected bool $scratchpadTransactionMode = false;
    /**
     * Save history to file mode.
     *
     * @var bool
     */
    protected bool $scratchpadSaveHistory = false;
    /**
     * Export results mode.
     *
     * @var bool
     */
    protected bool $scratchpadExportMode = false;
    /**
     * Last query result.
     *
     * @var array<int, array<string, mixed>>|null
     */
    protected ?array $scratchpadLastResult = null;
    /**
     * Last query execution time (seconds).
     *
     * @var float
     */
    protected float $scratchpadLastQueryTime = 0.0;
    /**
     * Last query error message.
     *
     * @var string|null
     */
    protected ?string $scratchpadLastError = null;
    /**
     * Last query affected rows count.
     *
     * @var int
     */
    protected int $scratchpadAffectedRows = 0;

    /**
     * @param PdoDb $db Database the queries run on
     * @param \Closure(): int $getScrollOffset Scroll position of the results, kept by the dashboard
     * @param \Closure(int): void $setScrollOffset
     * @param \Closure(): void $closeFullscreen Leave fullscreen (Esc)
     * @param \Closure(string, int): void $showMessage Show a short message over the screen
     */
    public function __construct(
        protected PdoDb $db,
        private readonly \Closure $getScrollOffset,
        private readonly \Closure $setScrollOffset,
        private readonly \Closure $closeFullscreen,
        private readonly \Closure $showMessage,
    ) {
    }

    /**
     * Queries run so far, oldest first.
     *
     * @return array<int, string>
     */
    public function history(): array
    {
        return $this->scratchpadHistory;
    }

    protected function getScrollOffset(): int
    {
        return ($this->getScrollOffset)();
    }

    protected function setScrollOffset(int $offset): void
    {
        ($this->setScrollOffset)($offset);
    }

    /**
     * Render SQL scratchpad fullscreen.
     */
    public function renderFullscreen(): void
    {
        Terminal::clear();
        [$rows, $cols] = Terminal::getSize();

        // Render header
        Terminal::moveTo(1, 1);
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_CYAN);
        }
        $status = [];
        if ($this->scratchpadTransactionMode) {
            $status[] = 'TX';
        }
        if ($this->scratchpadSaveHistory) {
            $status[] = 'SAVE';
        }
        if ($this->scratchpadExportMode) {
            $status[] = 'EXPORT';
        }
        $statusText = !empty($status) ? ' [' . implode(', ', $status) . ']' : '';
        Stdout::write('SQL Scratchpad (Fullscreen)' . $statusText);
        Terminal::reset();

        // Calculate editor and results areas (40% editor, 60% results)
        $editorHeight = (int)floor(($rows - 3) * 0.4);
        $resultsHeight = $rows - 3 - $editorHeight;
        $editorStartRow = 3;
        $resultsStartRow = $editorStartRow + $editorHeight + 1;

        // Render editor
        $this->renderScratchpadEditor($editorStartRow, $editorHeight, $cols);

        // Render separator
        Terminal::moveTo($resultsStartRow - 1, 1);
        Stdout::write(str_repeat('─', $cols));

        // Render results
        $this->renderScratchpadResults($resultsStartRow, $resultsHeight, $cols);

        // Render autocomplete overlay if active (after everything else, so it's on top)
        if ($this->scratchpadAutocompleteActive && !empty($this->scratchpadAutocompleteOptions)) {
            // Calculate cursor position in editor
            $editorScrollOffset = $this->getScrollOffset();
            $cursorLineInEditor = $this->scratchpadCursor['line'] - $editorScrollOffset;
            $cursorRow = $editorStartRow + 1 + $cursorLineInEditor;

            // Try to render autocomplete below cursor line in editor
            $autocompleteStartRow = $cursorRow + 1;

            // If not enough space below in editor, render in results area
            if ($autocompleteStartRow + 11 > $resultsStartRow - 1) {
                // Render in results area instead
                $autocompleteStartRow = $resultsStartRow + 1;
            }

            // Make sure it fits on screen
            if ($autocompleteStartRow + 11 > $rows - 1) {
                $autocompleteStartRow = max(1, $rows - 12);
            }

            $this->renderAutocomplete($autocompleteStartRow, $cols);
        }

        // Render footer
        $this->renderScratchpadFooter($rows, $cols);
    }

    /**
     * Render SQL editor.
     *
     * @param int $startRow Start row
     * @param int $height Height
     * @param int $width Width
     */
    protected function renderScratchpadEditor(int $startRow, int $height, int $width): void
    {
        Terminal::moveTo($startRow, 1);
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_YELLOW);
        }
        Stdout::write('SQL Editor:');
        Terminal::reset();

        $lines = explode("\n", $this->scratchpadQuery);
        $numLines = count($lines);

        // Calculate scroll offset for editor
        $editorScrollOffset = $this->getScrollOffset();
        if ($this->scratchpadMode === 'editor') {
            // Auto-scroll to cursor line
            $cursorLine = $this->scratchpadCursor['line'];
            $visibleHeight = $height - 1;
            if ($cursorLine < $editorScrollOffset) {
                $editorScrollOffset = max(0, $cursorLine);
            } elseif ($cursorLine >= $editorScrollOffset + $visibleHeight) {
                $editorScrollOffset = max(0, $cursorLine - $visibleHeight + 1);
            }
            $this->setScrollOffset($editorScrollOffset);
        }

        $startLine = $editorScrollOffset;
        $endLine = min($startLine + $height - 1, $numLines);
        $displayLines = array_slice($lines, $startLine, $endLine - $startLine);

        for ($i = 0; $i < $height - 1; $i++) {
            Terminal::moveTo($startRow + 1 + $i, 1);
            Terminal::clearLine();
            $lineIndex = $startLine + $i;
            if ($lineIndex < $numLines && isset($displayLines[$i])) {
                $line = $displayLines[$i];
                $displayLine = mb_substr($line, 0, $width - 1, 'UTF-8');
                if ($this->scratchpadMode === 'editor' && $lineIndex === $this->scratchpadCursor['line']) {
                    // Highlight current line
                    if (Terminal::supportsColors()) {
                        Terminal::color(Terminal::BG_BLUE);
                        Terminal::color(Terminal::COLOR_WHITE);
                    }
                }
                Stdout::write($displayLine);
                Terminal::reset();
            } elseif ($lineIndex >= $numLines && $i === 0 && $numLines > 0) {
                // Show continuation indicator if there are more lines above
                if ($startLine > 0) {
                    if (Terminal::supportsColors()) {
                        Terminal::color(Terminal::COLOR_YELLOW);
                    }
                    Stdout::write('... (more lines above)');
                    Terminal::reset();
                }
            }
        }

        // Autocomplete is now rendered in renderScratchpadFullscreen() after results
    }

    /**
     * Render query results.
     *
     * @param int $startRow Start row
     * @param int $height Height
     * @param int $width Width
     */
    protected function renderScratchpadResults(int $startRow, int $height, int $width): void
    {
        Terminal::moveTo($startRow, 1);
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_YELLOW);
        }
        Stdout::write('Results:');
        Terminal::reset();

        if ($this->scratchpadLastError !== null) {
            Terminal::moveTo($startRow + 1, 1);
            if (Terminal::supportsColors()) {
                Terminal::color(Terminal::COLOR_RED);
                Terminal::bold();
            }
            Stdout::write('Error: ' . mb_substr($this->scratchpadLastError, 0, $width - 8, 'UTF-8'));
            Terminal::reset();
            return;
        }

        if ($this->scratchpadLastResult === null) {
            Terminal::moveTo($startRow + 1, 1);
            Stdout::write('No query executed yet. Press F5 or Enter to execute.');
            return;
        }

        if (empty($this->scratchpadLastResult)) {
            Terminal::moveTo($startRow + 1, 1);
            Stdout::write('Query returned no results. Affected: ' . $this->scratchpadAffectedRows);
            return;
        }

        // Display results in table format
        $scrollOffset = $this->getScrollOffset();
        $visibleRows = $height - 2; // Reserve space for header and footer
        $displayRows = array_slice($this->scratchpadLastResult, $scrollOffset, $visibleRows);

        if (empty($displayRows)) {
            return;
        }

        // Get column names
        $columns = array_keys($displayRows[0]);
        $numColumns = count($columns);

        // Calculate column widths
        $colWidths = [];
        foreach ($columns as $col) {
            $colName = is_string($col) ? $col : (string)$col;
            $colWidths[$col] = mb_strlen($colName, 'UTF-8');
        }
        foreach ($displayRows as $row) {
            foreach ($columns as $col) {
                $cellValue = $row[$col] ?? null;
                if (is_array($cellValue)) {
                    $jsonResult = json_encode($cellValue);
                    $valueStr = $jsonResult !== false ? $jsonResult : '[array]';
                } elseif (is_object($cellValue)) {
                    try {
                        if (method_exists($cellValue, '__toString')) {
                            $valueStr = (string)$cellValue;
                        } else {
                            $valueStr = get_class($cellValue);
                        }
                    } catch (\Throwable $e) {
                        $valueStr = get_class($cellValue);
                    }
                } elseif (is_bool($cellValue)) {
                    $valueStr = $cellValue ? '1' : '0';
                } elseif ($cellValue === null) {
                    $valueStr = 'NULL';
                } else {
                    $valueStr = (string)$cellValue;
                }
                $len = mb_strlen($valueStr, 'UTF-8');
                if ($len > $colWidths[$col]) {
                    $colWidths[$col] = min($len, 30); // Max 30 chars per column
                }
            }
        }

        // Adjust column widths to fit screen
        $totalWidth = array_sum($colWidths) + ($numColumns - 1) * 3; // 3 for separators
        if ($totalWidth > $width - 2) {
            $scale = ($width - 2) / $totalWidth;
            foreach ($colWidths as $col => $w) {
                $colWidths[$col] = max(5, (int)floor($w * $scale));
            }
        }

        // Render header
        $headerRow = $startRow + 1;
        Terminal::moveTo($headerRow, 1);
        if (Terminal::supportsColors()) {
            Terminal::bold();
            Terminal::color(Terminal::COLOR_CYAN);
        }
        $headerParts = [];
        foreach ($columns as $col) {
            $colName = is_string($col) ? $col : (string)$col;
            $headerParts[] = str_pad(mb_substr($colName, 0, $colWidths[$col], 'UTF-8'), $colWidths[$col], ' ', STR_PAD_RIGHT);
        }
        Stdout::write(implode(' │ ', $headerParts));
        Terminal::reset();

        // Render separator
        Terminal::moveTo($headerRow + 1, 1);
        Stdout::write(str_repeat('─', min($width - 2, $totalWidth)));

        // Render data rows
        $dataStartRow = $headerRow + 2;
        for ($i = 0; $i < count($displayRows) && $i < $visibleRows - 3; $i++) {
            $row = $displayRows[$i];
            Terminal::moveTo($dataStartRow + $i, 1);
            $rowParts = [];
            foreach ($columns as $col) {
                $cellValue = $row[$col] ?? null;
                if (is_array($cellValue)) {
                    $jsonResult = json_encode($cellValue);
                    $valueStr = $jsonResult !== false ? $jsonResult : '[array]';
                } elseif (is_object($cellValue)) {
                    try {
                        if (method_exists($cellValue, '__toString')) {
                            $valueStr = (string)$cellValue;
                        } else {
                            $valueStr = get_class($cellValue);
                        }
                    } catch (\Throwable $e) {
                        $valueStr = get_class($cellValue);
                    }
                } elseif (is_bool($cellValue)) {
                    $valueStr = $cellValue ? '1' : '0';
                } elseif ($cellValue === null) {
                    $valueStr = 'NULL';
                } else {
                    $valueStr = (string)$cellValue;
                }
                $displayValue = mb_substr($valueStr, 0, $colWidths[$col], 'UTF-8');
                if (mb_strlen($valueStr, 'UTF-8') > $colWidths[$col]) {
                    $displayValue = mb_substr($displayValue, 0, $colWidths[$col] - 3, 'UTF-8') . '...';
                }
                $rowParts[] = str_pad($displayValue, $colWidths[$col], ' ', STR_PAD_RIGHT);
            }
            Stdout::write(implode(' │ ', $rowParts));
        }

        // Show stats
        $statsRow = $startRow + $height - 1;
        Terminal::moveTo($statsRow, 1);
        if (Terminal::supportsColors()) {
            Terminal::color(Terminal::COLOR_YELLOW);
        }
        $stats = sprintf(
            'Rows: %d | Time: %.3fs | Affected: %d',
            count($this->scratchpadLastResult),
            $this->scratchpadLastQueryTime,
            $this->scratchpadAffectedRows
        );
        if ($scrollOffset > 0 || count($this->scratchpadLastResult) > $visibleRows) {
            $stats .= sprintf(' | Scroll: %d-%d/%d', $scrollOffset + 1, min($scrollOffset + $visibleRows, count($this->scratchpadLastResult)), count($this->scratchpadLastResult));
        }
        Stdout::write(mb_substr($stats, 0, $width - 1, 'UTF-8'));
        Terminal::reset();
    }

    /**
     * Render scratchpad footer.
     *
     * @param int $rows Terminal rows
     * @param int $cols Terminal columns
     */
    protected function renderScratchpadFooter(int $rows, int $cols): void
    {
        Terminal::moveTo($rows, 1);
        Terminal::clearLine();

        if (Terminal::supportsColors()) {
            Terminal::color(Terminal::BG_BLUE);
            Terminal::color(Terminal::COLOR_WHITE);
        }

        if ($this->scratchpadMode === 'editor') {
            $help = 'F5:Run | PgUp/Dn:History | Tab:Results | F2:TX | F3:Save | F4:Export | Esc:Exit';
        } else {
            $help = '↑↓:Scroll | PgUp/PgDn:Page | Tab:Editor | F4:Export | Esc:Exit';
        }

        // Truncate if too long
        if (mb_strlen($help, 'UTF-8') > $cols) {
            $help = mb_substr($help, 0, $cols, 'UTF-8');
        }

        Stdout::write($help);
        Terminal::reset();
    }

    /**
     * Handle keyboard input for SQL Scratchpad.
     *
     * @param string $key Key pressed
     *
     * @return bool True if should exit
     */
    public function handleKey(string $key): bool
    {
        // Handle ESC: close the autocomplete list first, then leave fullscreen
        if ($key === 'esc') {
            if ($this->scratchpadMode === 'editor' && $this->scratchpadAutocompleteActive) {
                $this->scratchpadAutocompleteActive = false;
                return false;
            }
            ($this->closeFullscreen)();
            return false;
        }

        // Toggle modes (use F-keys to avoid conflicts with text input)
        if ($key === 'f2') {
            $this->scratchpadTransactionMode = !$this->scratchpadTransactionMode;
            return false;
        }
        if ($key === 'f3') {
            $this->scratchpadSaveHistory = !$this->scratchpadSaveHistory;
            if ($this->scratchpadSaveHistory) {
                $this->loadScratchpadHistory();
            }
            return false;
        }
        if ($key === 'f4') {
            $this->scratchpadExportMode = !$this->scratchpadExportMode;
            if ($this->scratchpadExportMode && $this->scratchpadLastResult !== null) {
                $this->exportScratchpadResults();
            }
            return false;
        }

        // Switch between editor and results (only if autocomplete is not active)
        if ($key === 'tab') {
            if ($this->scratchpadMode === 'editor' && $this->scratchpadAutocompleteActive) {
                // In autocomplete mode, Tab selects current option
                $this->selectAutocompleteOption();
                return false;
            }
            $this->scratchpadMode = $this->scratchpadMode === 'editor' ? 'results' : 'editor';
            $this->scratchpadHistoryIndex = -1; // Reset history navigation
            $this->scratchpadAutocompleteActive = false; // Close autocomplete when switching modes
            return false;
        }

        if ($this->scratchpadMode === 'editor') {
            return $this->handleScratchpadEditorKey($key);
        } else {
            return $this->handleScratchpadResultsKey($key);
        }
    }

    /**
     * Handle keyboard input in SQL editor mode.
     *
     * @param string $key Key pressed
     *
     * @return bool True if should exit
     */
    protected function handleScratchpadEditorKey(string $key): bool
    {
        // Handle autocomplete navigation
        if ($this->scratchpadAutocompleteActive) {
            if ($key === 'up') {
                if ($this->scratchpadAutocompleteIndex > 0) {
                    $this->scratchpadAutocompleteIndex--;
                } else {
                    $this->scratchpadAutocompleteIndex = count($this->scratchpadAutocompleteOptions) - 1;
                }
                return false;
            }
            if ($key === 'down') {
                if ($this->scratchpadAutocompleteIndex < count($this->scratchpadAutocompleteOptions) - 1) {
                    $this->scratchpadAutocompleteIndex++;
                } else {
                    $this->scratchpadAutocompleteIndex = 0;
                }
                return false;
            }
            if ($key === 'enter' || $key === 'tab') {
                $this->selectAutocompleteOption();
                return false;
            }
            if ($key === 'esc') {
                $this->scratchpadAutocompleteActive = false;
                return false;
            }
        }

        // Walk through the queries run before
        if ($key === 'pageup' || $key === 'pagedown') {
            $this->navigateScratchpadHistory($key === 'pageup' ? -1 : 1);
            $this->scratchpadAutocompleteActive = false;
            return false;
        }

        // Execute query (F5 or Enter when query is not empty)
        if ($key === 'f5') {
            $this->executeScratchpadQuery();
            $this->scratchpadMode = 'results';
            $this->scratchpadAutocompleteActive = false;
            // Return false - the main loop will re-render immediately after handleKey returns
            return false;
        }

        // Navigation in editor (up/down arrows move cursor between lines, unless autocomplete is active)
        if ($key === 'up') {
            if (!$this->scratchpadAutocompleteActive && $this->scratchpadCursor['line'] > 0) {
                $this->scratchpadCursor['line']--;
                $lines = explode("\n", $this->scratchpadQuery);
                $currentLine = $lines[$this->scratchpadCursor['line']] ?? '';
                $this->scratchpadCursor['col'] = min($this->scratchpadCursor['col'], mb_strlen($currentLine, 'UTF-8'));
            }
            $this->scratchpadHistoryIndex = -1; // Reset history navigation
            return false;
        }
        if ($key === 'down') {
            if (!$this->scratchpadAutocompleteActive) {
                $lines = explode("\n", $this->scratchpadQuery);
                if ($this->scratchpadCursor['line'] < count($lines) - 1) {
                    $this->scratchpadCursor['line']++;
                    $currentLine = $lines[$this->scratchpadCursor['line']] ?? '';
                    $this->scratchpadCursor['col'] = min($this->scratchpadCursor['col'], mb_strlen($currentLine, 'UTF-8'));
                }
            }
            $this->scratchpadHistoryIndex = -1; // Reset history navigation
            return false;
        }
        if ($key === 'left') {
            if ($this->scratchpadCursor['col'] > 0) {
                $this->scratchpadCursor['col']--;
            }
            return false;
        }
        if ($key === 'right') {
            $lines = explode("\n", $this->scratchpadQuery);
            $currentLine = $lines[$this->scratchpadCursor['line']] ?? '';
            if ($this->scratchpadCursor['col'] < mb_strlen($currentLine, 'UTF-8')) {
                $this->scratchpadCursor['col']++;
            }
            return false;
        }

        // Clear editor
        if (($key === 'l' || $key === 'k') && $this->isCtrlPressed()) {
            $this->scratchpadQuery = '';
            $this->scratchpadCursor = ['line' => 0, 'col' => 0];
            $this->scratchpadHistoryIndex = -1;
            return false;
        }

        // Regular text input
        if (strlen($key) === 1 && ord($key) >= 32 && ord($key) <= 126) {
            $this->insertScratchpadChar($key);
            $this->scratchpadHistoryIndex = -1; // Reset history navigation when typing
            // Trigger autocomplete after typing
            $this->triggerAutocomplete();
            return false;
        }

        // Backspace
        if ($key === 'backspace') {
            $this->deleteScratchpadChar();
            // Update autocomplete after deletion
            $this->triggerAutocomplete();
            return false;
        }

        // Enter (new line)
        if ($key === 'enter') {
            $this->insertScratchpadChar("\n");
            return false;
        }

        return false;
    }

    /**
     * Handle keyboard input in results mode.
     *
     * @param string $key Key pressed
     *
     * @return bool True if should exit
     */
    protected function handleScratchpadResultsKey(string $key): bool
    {
        if ($key === 'up') {
            if ($this->getScrollOffset() > 0) {
                $this->setScrollOffset($this->getScrollOffset() - 1);
            }
            return false;
        }
        if ($key === 'down') {
            if ($this->scratchpadLastResult !== null) {
                $maxScroll = max(0, count($this->scratchpadLastResult) - 10);
                if ($this->getScrollOffset() < $maxScroll) {
                    $this->setScrollOffset($this->getScrollOffset() + 1);
                }
            }
            return false;
        }
        if ($key === 'pageup') {
            return false;
        }
        if ($key === 'pagedown') {
            return false;
        }
        if ($key === 'home') {
            $this->setScrollOffset(0);
            return false;
        }
        if ($key === 'end') {
            if ($this->scratchpadLastResult !== null) {
                $this->setScrollOffset(max(0, count($this->scratchpadLastResult) - 10));
            }
            return false;
        }

        return false;
    }

    /**
     * Insert character at cursor position in SQL editor.
     *
     * @param string $char Character to insert
     */
    protected function insertScratchpadChar(string $char): void
    {
        $lines = explode("\n", $this->scratchpadQuery);
        $line = $this->scratchpadCursor['line'];
        $col = $this->scratchpadCursor['col'];

        if ($char === "\n") {
            // Insert new line
            $currentLine = $lines[$line] ?? '';
            $before = mb_substr($currentLine, 0, $col, 'UTF-8');
            $after = mb_substr($currentLine, $col, null, 'UTF-8');
            $lines[$line] = $before;
            array_splice($lines, $line + 1, 0, $after);
            $this->scratchpadCursor['line']++;
            $this->scratchpadCursor['col'] = 0;
        } else {
            // Insert character
            $currentLine = $lines[$line] ?? '';
            $before = mb_substr($currentLine, 0, $col, 'UTF-8');
            $after = mb_substr($currentLine, $col, null, 'UTF-8');
            $lines[$line] = $before . $char . $after;
            $this->scratchpadCursor['col']++;
        }

        $this->scratchpadQuery = implode("\n", $lines);
    }

    /**
     * Delete character at cursor position in SQL editor.
     */
    protected function deleteScratchpadChar(): void
    {
        if ($this->scratchpadQuery === '') {
            return;
        }

        $lines = explode("\n", $this->scratchpadQuery);
        $line = $this->scratchpadCursor['line'];
        $col = $this->scratchpadCursor['col'];

        if ($col > 0) {
            // Delete character before cursor
            $currentLine = $lines[$line] ?? '';
            $before = mb_substr($currentLine, 0, $col - 1, 'UTF-8');
            $after = mb_substr($currentLine, $col, null, 'UTF-8');
            $lines[$line] = $before . $after;
            $this->scratchpadCursor['col']--;
        } elseif ($line > 0) {
            // Merge with previous line
            $prevLine = $lines[$line - 1] ?? '';
            $currentLine = $lines[$line] ?? '';
            $lines[$line - 1] = $prevLine . $currentLine;
            array_splice($lines, $line, 1);
            $this->scratchpadCursor['line']--;
            $this->scratchpadCursor['col'] = mb_strlen($prevLine, 'UTF-8');
        }

        $this->scratchpadQuery = implode("\n", $lines);
    }

    /**
     * Navigate query history.
     *
     * @param int $direction Direction (-1 for up, 1 for down)
     */
    protected function navigateScratchpadHistory(int $direction): void
    {
        if (empty($this->scratchpadHistory)) {
            return;
        }

        if ($this->scratchpadHistoryIndex === -1) {
            // Start navigating from current query
            $this->scratchpadHistoryIndex = count($this->scratchpadHistory) - 1;
        } else {
            $this->scratchpadHistoryIndex += $direction;
        }

        if ($this->scratchpadHistoryIndex < 0) {
            $this->scratchpadHistoryIndex = -1;
            return; // Don't load anything, keep current query
        }

        if ($this->scratchpadHistoryIndex >= count($this->scratchpadHistory)) {
            $this->scratchpadHistoryIndex = count($this->scratchpadHistory) - 1;
        }

        if ($this->scratchpadHistoryIndex >= 0) {
            $this->scratchpadQuery = $this->scratchpadHistory[$this->scratchpadHistoryIndex];
            $lines = explode("\n", $this->scratchpadQuery);
            $lastLine = end($lines);
            $this->scratchpadCursor = ['line' => count($lines) - 1, 'col' => $lastLine !== false ? mb_strlen($lastLine, 'UTF-8') : 0];
        }
    }

    /**
     * Run one statement and report what it produced.
     *
     * @return array{0: array<int, array<string, mixed>>, 1: int, 2: bool} Rows (empty for a statement that returns none),
     *                                                                     the number of rows read or changed, and
     *                                                                     whether the statement returns rows at all
     */
    protected function runScratchpadStatement(string $sql): array
    {
        $statement = $this->db->find()->executeStatement($sql);

        try {
            if ($statement->columnCount() === 0) {
                return [[], $statement->rowCount(), false];
            }

            $connection = $this->db->connection;
            $rows = $connection->getDialect()->normalizeRowKeys($statement->fetchAll(\PDO::FETCH_ASSOC), $connection->getOptions());

            return [$rows, count($rows), true];
        } finally {
            $statement->closeCursor();
        }
    }

    /**
     * Execute SQL query from scratchpad.
     */
    protected function executeScratchpadQuery(): void
    {
        $query = $this->scratchpadQuery;
        // Remove trailing whitespace but keep structure
        $query = rtrim($query);
        if ($query === '') {
            return;
        }

        // Split query by semicolons (but be careful with semicolons in strings/comments)
        // For now, simple approach: split by semicolon and execute each non-empty statement
        $statements = $this->splitSqlStatements($query);

        // If multiple statements, execute them all (or just the first one for SELECT)
        if (count($statements) > 1) {
            // Execute all statements, but only show results from the last SELECT
            $lastResult = null;
            $lastError = null;
            $totalAffected = 0;

            foreach ($statements as $stmt) {
                $stmt = trim($stmt);
                if ($stmt === '') {
                    continue;
                }

                try {
                    [$result, $totalAffected, $returnsRows] = $this->runScratchpadStatement($stmt);
                    if ($returnsRows) {
                        $lastResult = $result;
                    }
                } catch (\Throwable $e) {
                    $lastError = $e->getMessage();
                    // Continue with next statement or stop?
                    // For now, stop on first error
                    break;
                }
            }

            if ($lastError !== null) {
                $this->scratchpadLastError = $lastError;
                $this->scratchpadLastResult = null;
            } else {
                $this->scratchpadLastResult = $lastResult;
                $this->scratchpadAffectedRows = $totalAffected;
            }

            // Add to history
            if (!in_array($query, $this->scratchpadHistory, true)) {
                $this->scratchpadHistory[] = $query;
                if (count($this->scratchpadHistory) > 50) {
                    array_shift($this->scratchpadHistory);
                }
            }

            if ($this->scratchpadSaveHistory) {
                $this->saveScratchpadHistory();
            }

            return;
        }

        // Single statement - execute normally

        // Add to history
        if (!in_array($query, $this->scratchpadHistory, true)) {
            $this->scratchpadHistory[] = $query;
            // Keep only last 50 queries
            if (count($this->scratchpadHistory) > 50) {
                array_shift($this->scratchpadHistory);
            }
        }

        // Save history if enabled
        if ($this->scratchpadSaveHistory) {
            $this->saveScratchpadHistory();
        }

        $this->scratchpadLastError = null;
        $this->scratchpadLastResult = null;
        $this->scratchpadAffectedRows = 0;
        $this->setScrollOffset(0);

        $startTime = microtime(true);

        try {
            // Check if transaction mode
            if ($this->scratchpadTransactionMode && !$this->db->connection->inTransaction()) {
                $this->db->startTransaction();
            }

            // Execute query
            [$result, $affected] = $this->runScratchpadStatement($query);
            $this->scratchpadLastResult = $result;
            $this->scratchpadAffectedRows = $affected;

            // Commit if in transaction mode
            if ($this->scratchpadTransactionMode && $this->db->connection->inTransaction()) {
                $this->db->commit();
            }

            $this->scratchpadLastQueryTime = microtime(true) - $startTime;
        } catch (\Throwable $e) {
            $this->scratchpadLastError = $e->getMessage();
            $this->scratchpadLastQueryTime = microtime(true) - $startTime;

            // If error contains "multiple statements" or similar, suggest splitting
            if (stripos($e->getMessage(), 'multiple statements') !== false ||
                stripos($e->getMessage(), 'syntax error') !== false) {
                // Check if query has multiple semicolons
                $semicolonCount = substr_count($query, ';');
                if ($semicolonCount > 1) {
                    $this->scratchpadLastError .= ' (Hint: Multiple statements detected. Each statement should be executed separately or use transaction mode)';
                }
            }

            // Rollback if in transaction
            if ($this->scratchpadTransactionMode && $this->db->connection->inTransaction()) {
                try {
                    $this->db->rollback();
                } catch (\Throwable $rollbackError) {
                    // Ignore rollback errors
                }
            }
        }
    }

    /**
     * Load scratchpad history from file.
     */
    protected function loadScratchpadHistory(): void
    {
        $historyFile = $this->getScratchpadHistoryFile();
        if (!file_exists($historyFile)) {
            return;
        }

        $content = @file_get_contents($historyFile);
        if ($content === false) {
            return;
        }

        $history = json_decode($content, true);
        if (is_array($history)) {
            $this->scratchpadHistory = $history;
        }
    }

    /**
     * Save scratchpad history to file.
     */
    protected function saveScratchpadHistory(): void
    {
        $historyFile = $this->getScratchpadHistoryFile();
        $dir = dirname($historyFile);
        if (!is_dir($dir)) {
            @mkdir($dir, 0755, true);
        }

        @file_put_contents($historyFile, json_encode($this->scratchpadHistory, JSON_PRETTY_PRINT));
    }

    /**
     * Split SQL query into individual statements.
     *
     * @param string $query SQL query (may contain multiple statements)
     *
     * @return array<int, string> Array of SQL statements
     */
    protected function splitSqlStatements(string $query): array
    {
        // Simple splitting by semicolon (doesn't handle semicolons in strings/comments perfectly)
        // For now, this is a basic implementation
        $statements = [];
        $current = '';
        $inString = false;
        $stringChar = null;
        $len = strlen($query);

        for ($i = 0; $i < $len; $i++) {
            $char = $query[$i];
            $nextChar = $i + 1 < $len ? $query[$i + 1] : '';

            // Handle strings
            if (!$inString && ($char === '"' || $char === "'")) {
                $inString = true;
                $stringChar = $char;
                $current .= $char;
            } elseif ($inString && $char === $stringChar && ($i === 0 || $query[$i - 1] !== '\\')) {
                $inString = false;
                $stringChar = null;
                $current .= $char;
            } elseif ($char === ';' && !$inString) {
                // End of statement
                $stmt = trim($current);
                if ($stmt !== '') {
                    $statements[] = $stmt;
                }
                $current = '';
            } else {
                $current .= $char;
            }
        }

        // Add remaining statement
        $stmt = trim($current);
        if ($stmt !== '') {
            $statements[] = $stmt;
        }

        return $statements;
    }

    /**
     * Get scratchpad history file path.
     *
     * @return string
     */
    protected function getScratchpadHistoryFile(): string
    {
        $home = getenv('HOME') ?: (getenv('USERPROFILE') ?: sys_get_temp_dir());
        return $home . '/.pdodb_scratchpad_history.json';
    }

    /**
     * Export scratchpad results to CSV or JSON.
     */
    protected function exportScratchpadResults(): void
    {
        if ($this->scratchpadLastResult === null || empty($this->scratchpadLastResult)) {
            return;
        }

        $exportDir = sys_get_temp_dir() . '/pdodb_exports';
        if (!is_dir($exportDir)) {
            @mkdir($exportDir, 0755, true);
        }

        $timestamp = date('Y-m-d_H-i-s');
        $csvFile = $exportDir . '/scratchpad_' . $timestamp . '.csv';
        $jsonFile = $exportDir . '/scratchpad_' . $timestamp . '.json';

        try {
            // Export to CSV
            $csvExporter = new CsvExporter();
            $csvContent = $csvExporter->export($this->scratchpadLastResult, ',', '"', '\\');
            @file_put_contents($csvFile, $csvContent);

            // Export to JSON
            $jsonExporter = new JsonExporter();
            $jsonContent = $jsonExporter->export($this->scratchpadLastResult, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE, 512);
            @file_put_contents($jsonFile, $jsonContent);

            ($this->showMessage)("Results exported to:\nCSV: {$csvFile}\nJSON: {$jsonFile}", Terminal::COLOR_GREEN);
        } catch (\Throwable $e) {
            ($this->showMessage)('Export failed: ' . $e->getMessage(), Terminal::COLOR_RED);
        }
    }

    /**
     * Trigger autocomplete based on current cursor position.
     */
    protected function triggerAutocomplete(): void
    {
        // Force reset autocomplete state first
        $this->scratchpadAutocompleteActive = false;
        $this->scratchpadAutocompleteOptions = [];

        $lines = explode("\n", $this->scratchpadQuery);
        $line = $lines[$this->scratchpadCursor['line']] ?? '';
        $col = $this->scratchpadCursor['col'];

        // Get text before cursor on current line
        $beforeCursor = mb_substr($line, 0, $col, 'UTF-8');

        // Extract current word/prefix being typed
        $prefix = '';
        if (preg_match('/([a-zA-Z_][a-zA-Z0-9_]*)$/', $beforeCursor, $matches)) {
            $prefix = $matches[1];
        } else {
            $this->scratchpadAutocompleteActive = false;
            return;
        }

        // Step 1: Get all SQL keywords and filter by prefix
        $allKeywords = [
            'SELECT', 'FROM', 'WHERE', 'JOIN', 'INNER JOIN', 'LEFT JOIN', 'RIGHT JOIN',
            'ON', 'AS', 'AND', 'OR', 'NOT', 'IN', 'LIKE', 'BETWEEN', 'IS NULL', 'IS NOT NULL',
            'ORDER BY', 'GROUP BY', 'HAVING', 'LIMIT', 'OFFSET',
            'INSERT', 'INTO', 'VALUES', 'UPDATE', 'SET', 'DELETE',
            'COUNT', 'SUM', 'AVG', 'MAX', 'MIN', 'DISTINCT',
            'CASE', 'WHEN', 'THEN', 'ELSE', 'END',
            'UNION', 'ALL', 'EXISTS', 'EXCEPT', 'INTERSECT',
        ];

        $prefixLower = mb_strtolower($prefix, 'UTF-8');
        $keywordOptions = [];
        foreach ($allKeywords as $keyword) {
            $keywordLower = mb_strtolower($keyword, 'UTF-8');
            if (mb_strlen($keywordLower, 'UTF-8') >= mb_strlen($prefixLower, 'UTF-8')) {
                $keywordPrefix = mb_substr($keywordLower, 0, mb_strlen($prefixLower, 'UTF-8'), 'UTF-8');
                if ($keywordPrefix === $prefixLower && $keywordLower !== $prefixLower) {
                    $keywordOptions[] = $keyword;
                }
            }
        }
        sort($keywordOptions);

        // Step 2: Get tables and columns, filter by prefix
        $tables = $this->getTablesForAutocomplete();
        $tableColumnOptions = [];

        // Filter tables
        foreach ($tables as $table) {
            $tableLower = mb_strtolower($table, 'UTF-8');
            if (mb_strlen($tableLower, 'UTF-8') >= mb_strlen($prefixLower, 'UTF-8')) {
                $tablePrefix = mb_substr($tableLower, 0, mb_strlen($prefixLower, 'UTF-8'), 'UTF-8');
                if ($tablePrefix === $prefixLower && $tableLower !== $prefixLower) {
                    $tableColumnOptions[] = $table;
                }
            }
        }

        // Filter columns
        foreach ($tables as $table) {
            $columns = $this->getColumnsForAutocomplete($table);
            foreach ($columns as $col) {
                $colLower = mb_strtolower($col, 'UTF-8');
                if (mb_strlen($colLower, 'UTF-8') >= mb_strlen($prefixLower, 'UTF-8')) {
                    $colPrefix = mb_substr($colLower, 0, mb_strlen($prefixLower, 'UTF-8'), 'UTF-8');
                    if ($colPrefix === $prefixLower && $colLower !== $prefixLower) {
                        if (!in_array($col, $tableColumnOptions, true)) {
                            $tableColumnOptions[] = $col;
                        }
                    }
                }
            }
        }
        sort($tableColumnOptions);

        // Step 3: Combine - keywords first, then tables/columns
        $options = array_merge($keywordOptions, $tableColumnOptions);

        // Limit to 20
        if (count($options) > 20) {
            $keywordCount = count($keywordOptions);
            if ($keywordCount >= 20) {
                $options = array_slice($keywordOptions, 0, 20);
            } else {
                $options = array_merge(
                    $keywordOptions,
                    array_slice($tableColumnOptions, 0, 20 - $keywordCount)
                );
            }
        }

        // Set autocomplete
        if (!empty($options)) {
            $this->scratchpadAutocompleteActive = true;
            $this->scratchpadAutocompleteOptions = $options;
            $this->scratchpadAutocompletePrefix = $prefix;
            $this->scratchpadAutocompleteIndex = 0;
        } else {
            $this->scratchpadAutocompleteActive = false;
        }
    }

    /**
     * Get tables for autocomplete (cached).
     *
     * @return array<int, string>
     */
    protected function getTablesForAutocomplete(): array
    {
        if ($this->scratchpadTableCache === null) {
            try {
                $this->scratchpadTableCache = TableManager::listTables($this->db);
            } catch (\Throwable $e) {
                $this->scratchpadTableCache = [];
            }
        }

        return $this->scratchpadTableCache;
    }

    /**
     * Get columns for table (cached).
     *
     * @param string $table Table name
     *
     * @return array<int, string>
     */
    protected function getColumnsForAutocomplete(string $table): array
    {
        if (!isset($this->scratchpadColumnCache[$table])) {
            try {
                $columns = TableManager::describe($this->db, $table);
                $columnNames = [];
                foreach ($columns as $col) {
                    $colName = is_array($col) ? ($col['Field'] ?? $col['column_name'] ?? $col['name'] ?? '') : (string)$col;
                    if ($colName !== '') {
                        $columnNames[] = $colName;
                    }
                }
                $this->scratchpadColumnCache[$table] = $columnNames;
            } catch (\Throwable $e) {
                $this->scratchpadColumnCache[$table] = [];
            }
        }

        return $this->scratchpadColumnCache[$table];
    }

    /**
     * Select current autocomplete option and insert it.
     */
    protected function selectAutocompleteOption(): void
    {
        if (!$this->scratchpadAutocompleteActive || empty($this->scratchpadAutocompleteOptions)) {
            return;
        }

        $selected = $this->scratchpadAutocompleteOptions[$this->scratchpadAutocompleteIndex] ?? '';
        if ($selected === '') {
            return;
        }

        $lines = explode("\n", $this->scratchpadQuery);
        $line = $lines[$this->scratchpadCursor['line']] ?? '';
        $col = $this->scratchpadCursor['col'];

        // Get text before and after cursor
        $before = mb_substr($line, 0, $col, 'UTF-8');
        $after = mb_substr($line, $col, null, 'UTF-8');

        // Replace prefix with selected option
        if ($this->scratchpadAutocompletePrefix !== '') {
            $prefixLen = mb_strlen($this->scratchpadAutocompletePrefix, 'UTF-8');
            $before = mb_substr($before, 0, -$prefixLen, 'UTF-8');
        }

        // Insert selected option
        $newLine = $before . $selected . $after;
        $lines[$this->scratchpadCursor['line']] = $newLine;
        $this->scratchpadQuery = implode("\n", $lines);

        // Update cursor position
        $this->scratchpadCursor['col'] = mb_strlen($before, 'UTF-8') + mb_strlen($selected, 'UTF-8');

        // Close autocomplete
        $this->scratchpadAutocompleteActive = false;
    }

    /**
     * Render autocomplete suggestions.
     *
     * @param int $startRow Start row for autocomplete
     * @param int $width Width
     */
    protected function renderAutocomplete(int $startRow, int $width): void
    {
        if (empty($this->scratchpadAutocompleteOptions)) {
            return;
        }

        [$rows, $cols] = Terminal::getSize();

        // Calculate available space (leave at least 1 row for footer if needed)
        $availableHeight = max(1, $rows - $startRow - 1);
        $maxHeight = min(10, $availableHeight);
        $numOptions = count($this->scratchpadAutocompleteOptions);

        // Calculate scroll offset (start from 0, no offset initially)
        $scrollOffset = 0;
        if ($this->scratchpadAutocompleteIndex >= $maxHeight) {
            $scrollOffset = $this->scratchpadAutocompleteIndex - $maxHeight + 1;
        }

        // Render header (only if it fits)
        if ($startRow < $rows) {
            Terminal::moveTo($startRow, 1);
            Terminal::clearLine();
            if (Terminal::supportsColors()) {
                Terminal::bold();
                Terminal::color(Terminal::COLOR_CYAN);
            }
            Stdout::write('Autocomplete:');
            Terminal::reset();
        }

        // Render options starting from index 0 (no scroll offset initially)
        $visibleOptions = array_slice($this->scratchpadAutocompleteOptions, $scrollOffset, $maxHeight);
        foreach ($visibleOptions as $i => $option) {
            $actualIndex = $scrollOffset + $i;
            $row = $startRow + 1 + $i;

            // Make sure we don't go beyond screen
            if ($row >= $rows) {
                break;
            }

            Terminal::moveTo($row, 1);
            Terminal::clearLine();

            if ($actualIndex === $this->scratchpadAutocompleteIndex) {
                // Highlight selected option
                if (Terminal::supportsColors()) {
                    Terminal::color(Terminal::BG_BLUE);
                    Terminal::color(Terminal::COLOR_WHITE);
                }
                Stdout::write('> ');
            } else {
                Stdout::write('  ');
            }

            $displayOption = mb_substr($option, 0, $width - 3, 'UTF-8');
            Stdout::write($displayOption);
            Terminal::reset();
        }

        // Show hint
        if ($numOptions > $maxHeight) {
            Terminal::moveTo($startRow + $maxHeight + 1, 1);
            if (Terminal::supportsColors()) {
                Terminal::color(Terminal::COLOR_YELLOW);
            }
            Stdout::write(sprintf('(%d more, ↑↓:Navigate, Tab/Enter:Select, Esc:Cancel)', $numOptions - $maxHeight));
            Terminal::reset();
        }
    }

    /**
     * Check if Ctrl key is pressed (simplified - in real TUI this is complex).
     *
     * @return bool
     */
    protected function isCtrlPressed(): bool
    {
        // In TUI, we can't easily detect Ctrl key without escape sequences
        // For now, we'll use a different approach: detect special key combinations
        // This is a placeholder - actual implementation would need InputHandler support
        return false;
    }
}
