<?php
/**
 * Example: Date and Time Helper Functions
 * 
 * Demonstrates date/time manipulation and extraction
 */

require_once __DIR__ . '/../../vendor/autoload.php';

use tommyknocker\pdodb\PdoDb;
use tommyknocker\pdodb\helpers\Db;

$db = new PdoDb('sqlite', ['path' => ':memory:']);

echo "=== Date and Time Helpers Example ===\n\n";

// Setup
$db->rawQuery("
    CREATE TABLE events (
        id INTEGER PRIMARY KEY,
        title TEXT,
        event_date DATE,
        event_time TIME,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )
");

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
    echo "  • {$event['title']} at {$event['hour']}:{$event['minute']:02d}\n";
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

// Example 6: Current date and time
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
        'current_date' => Db::curDate(),
        'current_time' => Db::curTime()
    ])
    ->where('title', 'Auto Event')
    ->getOne();

echo "  • Event: {$autoEvent['title']}\n";
echo "  • Current date: {$autoEvent['current_date']}\n";
echo "  • Current time: {$autoEvent['current_time']}\n\n";

// Example 7: Group by month
echo "7. Grouping events by month...\n";
$byMonth = $db->find()
    ->from('events')
    ->select([
        'month' => Db::month('event_date'),
        'event_count' => Db::raw('COUNT(*)')
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

echo "\nDate and time helpers example completed!\n";

