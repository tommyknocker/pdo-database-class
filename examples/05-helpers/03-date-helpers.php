<?php
/**
 * Example: Date and Time Helper Functions
 * 
 * Demonstrates date/time manipulation and extraction
 */

require_once __DIR__ . '/../../vendor/autoload.php';
require_once __DIR__ . '/../helpers.php';

use tommyknocker\pdodb\helpers\Db;

$db = createExampleDb();
$driver = getCurrentDriver($db);

echo "=== Date and Time Helpers Example (on $driver) ===\n\n";

// Setup using fluent API (cross-dialect)
$schema = $db->schema();
recreateTable($db, 'events', [
    'id' => $schema->primaryKey(),
    'title' => $schema->string(255),
    'event_date' => $schema->date(),
    'event_time' => $schema->time(),
    'created_at' => $schema->datetime()->defaultExpression('CURRENT_TIMESTAMP'),
]);

echo "1. Inserting events with current timestamp...\n";
$db->find()->table('events')->insertMulti([
    ['title' => 'Morning Meeting', 'event_date' => '2025-10-20', 'event_time' => '09:00:00'],
    ['title' => 'Lunch Break', 'event_date' => '2025-10-20', 'event_time' => '12:30:00'],
    ['title' => 'Code Review', 'event_date' => '2025-10-21', 'event_time' => '14:00:00'],
    ['title' => 'Team Standup', 'event_date' => '2025-10-19', 'event_time' => '10:00:00'],
]);

echo "✓ Inserted 4 events\n\n";

// Example 2: Extract date parts
echo "2. Extracting date parts (YEAR, MONTH, DAY)...\n";
$events = $db->find()
    ->from('events')
    ->select([
        'title',
        'event_date',
        'year' => Db::year('event_date'),
        'month' => Db::month('event_date'),
        'day' => Db::day('event_date')
    ])
    ->limit(2)
    ->get();

foreach ($events as $event) {
    echo "  • {$event['title']}: Year={$event['year']}, Month={$event['month']}, Day={$event['day']}\n";
}
echo "\n";

// Example 3: Extract time parts
echo "3. Extracting time parts (HOUR, MINUTE)...\n";
$events = $db->find()
    ->from('events')
    ->select([
        'title',
        'event_time',
        'hour' => Db::hour('event_time'),
        'minute' => Db::minute('event_time')
    ])
    ->get();

foreach ($events as $event) {
    $minute = str_pad($event['minute'], 2, '0', STR_PAD_LEFT);
    echo "  • {$event['title']} at {$event['hour']}:$minute\n";
}
echo "\n";

// Example 4: Filter by date parts
echo "4. Finding events on day 20...\n";
$dayEvents = $db->find()
    ->from('events')
    ->select(['title', 'event_date'])
    ->where(Db::day('event_date'), 20)
    ->get();

foreach ($dayEvents as $event) {
    echo "  • {$event['title']} ({$event['event_date']})\n";
}
echo "\n";

// Example 5: Filter by time of day
echo "5. Finding morning events (before noon)...\n";
$morningEvents = $db->find()
    ->from('events')
    ->select(['title', 'event_time'])
    ->where(Db::hour('event_time'), 12, '<')
    ->orderBy('event_time')
    ->get();

foreach ($morningEvents as $event) {
    echo "  • {$event['title']} at {$event['event_time']}\n";
}
echo "\n";

// Example 6: Current date and time functions
echo "6. Using current date/time functions...\n";
$db->find()->table('events')->insert([
    'title' => 'Auto Event',
    'event_date' => Db::curDate(),
    'event_time' => Db::curTime()
]);

$autoEvent = $db->find()
    ->from('events')
    ->select([
        'title',
        'cur_date' => Db::curDate(),
        'cur_time' => Db::curTime(),
        'now_timestamp' => Db::now()
    ])
    ->where('title', 'Auto Event')
    ->getOne();

echo "  • Event: {$autoEvent['title']}\n";
echo "  • Current date: {$autoEvent['cur_date']}\n";
echo "  • Current time: {$autoEvent['cur_time']}\n";
echo "  • Current timestamp: {$autoEvent['now_timestamp']}\n\n";

// Example 7: Group by month
echo "7. Grouping events by month...\n";
$byMonth = $db->find()
    ->from('events')
    ->select([
        'month' => Db::month('event_date'),
        'event_count' => Db::count()
    ])
    ->groupBy(Db::month('event_date'))
    ->orderBy(Db::month('event_date'))
    ->get();

foreach ($byMonth as $month) {
    echo "  • Month {$month['month']}: {$month['event_count']} events\n";
}
echo "\n";

// Example 8: Order by time
echo "8. Events ordered by time of day...\n";
$sorted = $db->find()
    ->from('events')
    ->select(['title', 'event_time'])
    ->orderBy(Db::hour('event_time'))
    ->orderBy(Db::minute('event_time'))
    ->get();

echo "  Events chronologically:\n";
foreach ($sorted as $event) {
    echo "  • {$event['event_time']} - {$event['title']}\n";
}
echo "\n";

// Example 9: Date arithmetic and time differences
echo "9. Date arithmetic and time differences...\n";
$events = $db->find()
    ->from('events')
    ->select([
        'title',
        'event_date',
        'created_at'
    ])
    ->limit(3)
    ->get();

foreach ($events as $event) {
    echo "  • {$event['title']}: {$event['event_date']}\n";
    echo "    Created: {$event['created_at']}\n";
}
echo "\n";

// Example 10: Date arithmetic with addInterval/subInterval
echo "10. Date arithmetic with addInterval/subInterval...\n";
$events = $db->find()
    ->from('events')
    ->select([
        'title',
        'created_at',
        // Use created_at as base to ensure cross-dialect stability
        'future_timestamp' => Db::addInterval('created_at', '1', 'DAY'),
        'past_timestamp' => Db::subInterval('created_at', '2', 'HOUR')
    ])
    ->limit(2)
    ->get();

foreach ($events as $event) {
    echo "  • {$event['title']}\n";
    echo "    Created: {$event['created_at']}\n";
    echo "    Future (+1 day): {$event['future_timestamp']}\n";
    echo "    Past (-2 hours): {$event['past_timestamp']}\n";
}
echo "\n";

// Example 11: Complex date filtering (helpers only, cross-dialect)
echo "11. Complex date filtering...\n";

$recentEvents = $db->find()
    ->from('events')
    ->select(['title', 'event_date', 'event_time'])
    // Same year as current (compare to PHP current year for cross-dialect safety)
    ->where(Db::year('event_date'), (int)date('Y'))
    // Month >= current month
    ->andWhere(Db::month('event_date'), (int)date('m'), '>=')
    ->orderBy('event_date')
    ->orderBy('event_time')
    ->get();

echo "  Events in current year, current month or later:\n";
foreach ($recentEvents as $event) {
    echo "  • {$event['event_date']} {$event['event_time']} - {$event['title']}\n";
}
echo "\n";

// Example 12: Date and time extraction combinations (helpers with PG fallback)
echo "12. Date and time extraction combinations...\n";
$driver = getCurrentDriver($db);

$events = $db->find()
    ->from('events')
    ->select([
        'title',
        'event_date',
        'event_time',
        'date_only' => Db::date('created_at'),
        'time_only' => Db::time('created_at'),
    ])
    ->get();

foreach ($events as $event) {
    echo "  • {$event['title']}\n";
    echo "    Date: {$event['date_only']}, Time: {$event['time_only']}\n";
    echo "    Event time: {$event['event_time']}\n";
}

echo "\nDate and time helpers example completed!\n";
echo "\nKey Takeaways:\n";
echo "  • Use YEAR, MONTH, DAY to extract date parts\n";
echo "  • Use HOUR, MINUTE, SECOND to extract time parts\n";
echo "  • Use NOW() for current timestamp with optional differences\n";
echo "  • Use CURDATE() and CURTIME() for current date/time\n";
echo "  • Use DATE() and TIME() to extract date/time from datetime\n";
echo "  • Combine date functions for complex filtering and grouping\n";

