<?php

declare(strict_types=1);

namespace tommyknocker\pdodb\cli\ui;

/**
 * What the TUI dashboard is showing: which pane is active, fullscreen or in its detail view, the selection, scroll
 * position and search per pane, and the monitoring data fetched last.
 */
final class DashboardState
{
    /**
     * Active pane index.
     *
     * @var int
     */
    public int $activePane = Layout::PANE_QUERIES;

    /**
     * Fullscreen pane index (-1 if none).
     *
     * @var int
     */
    public int $fullscreenPane = -1;

    /**
     * Detail view pane index (-1 if none).
     *
     * @var int
     */
    public int $detailViewPane = -1;

    /**
     * Selected item index per pane.
     *
     * @var array<int, int>
     */
    public array $selectedIndex = [
        Layout::PANE_QUERIES => 0,
        Layout::PANE_CONNECTIONS => 0,
        Layout::PANE_CACHE => 0,
        Layout::PANE_METRICS => 0,
        Layout::PANE_SCHEMA => 0,
        Layout::PANE_MIGRATIONS => 0,
        Layout::PANE_VARIABLES => 0,
        Layout::PANE_SCRATCHPAD => 0,
    ];

    /**
     * Scroll offset per pane.
     *
     * @var array<int, int>
     */
    public array $scrollOffset = [
        Layout::PANE_QUERIES => 0,
        Layout::PANE_CONNECTIONS => 0,
        Layout::PANE_CACHE => 0,
        Layout::PANE_METRICS => 0,
        Layout::PANE_SCHEMA => 0,
        Layout::PANE_MIGRATIONS => 0,
        Layout::PANE_VARIABLES => 0,
        Layout::PANE_SCRATCHPAD => 0,
    ];

    /**
     * Search filter per pane.
     *
     * @var array<int, string>
     */
    public array $searchFilter = [
        Layout::PANE_QUERIES => '',
        Layout::PANE_CONNECTIONS => '',
        Layout::PANE_CACHE => '',
        Layout::PANE_METRICS => '',
        Layout::PANE_SCHEMA => '',
        Layout::PANE_MIGRATIONS => '',
        Layout::PANE_VARIABLES => '',
        Layout::PANE_SCRATCHPAD => '',
    ];

    /**
     * Search mode per pane (true when user is typing search query).
     *
     * @var array<int, bool>
     */
    public array $searchMode = [
        Layout::PANE_QUERIES => false,
        Layout::PANE_CONNECTIONS => false,
        Layout::PANE_CACHE => false,
        Layout::PANE_METRICS => false,
        Layout::PANE_SCHEMA => false,
        Layout::PANE_MIGRATIONS => false,
        Layout::PANE_VARIABLES => false,
        Layout::PANE_SCRATCHPAD => false,
    ];

    /**
     * Cached queries data.
     *
     * @var array<int, array<string, mixed>>
     */
    public array $cachedQueries = [];

    /**
     * Cached connections data.
     *
     * @var array<string, mixed>
     */
    public array $cachedConnections = [];

    /**
     * Cached server metrics data.
     *
     * @var array<string, mixed>
     */
    public array $cachedServerMetrics = [];
}
