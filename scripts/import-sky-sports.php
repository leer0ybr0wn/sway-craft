#!/usr/bin/env php
<?php
/**
 * One-off/cron-able importer for Sky Sports ICS fixture feeds into the `events` section.
 *
 * Usage:
 *   php scripts/import-sky-sports.php /path/to/fixtures.ics [--dry-run]
 */

require dirname(__DIR__) . '/bootstrap.php';

/** @var craft\console\Application $app */
$app = require CRAFT_VENDOR_PATH . '/craftcms/cms/bootstrap/console.php';

use craft\elements\Entry;

$args = array_slice($argv, 1);
$dryRun = in_array('--dry-run', $args, true);
$args = array_values(array_filter($args, fn($a) => $a !== '--dry-run'));

if (empty($args[0])) {
    fwrite(STDERR, "Usage: php scripts/import-sky-sports.php /path/to/fixtures.ics [--dry-run]\n");
    exit(1);
}

$icsPath = $args[0];
if (!is_file($icsPath)) {
    fwrite(STDERR, "File not found: {$icsPath}\n");
    exit(1);
}

/**
 * Unfold ICS content-lines (RFC 5545 §3.1): a line beginning with a space/tab
 * is a continuation of the previous line, with the leading whitespace stripped.
 */
function unfoldIcsLines(string $content): array
{
    $rawLines = preg_split('/\r\n|\r|\n/', $content);
    $lines = [];
    foreach ($rawLines as $line) {
        if ($line === '') {
            continue;
        }
        if (($line[0] === ' ' || $line[0] === "\t") && !empty($lines)) {
            $lines[count($lines) - 1] .= substr($line, 1);
        } else {
            $lines[] = $line;
        }
    }
    return $lines;
}

/** Reverse the backslash-escaping ICS uses for text values. */
function unescapeIcsText(string $value): string
{
    return strtr($value, ['\\n' => "\n", '\\N' => "\n", '\\,' => ',', '\\;' => ';', '\\\\' => '\\']);
}

/** Normalize a team name for matching so "Brighton & Hove Albion" == "Brighton and Hove Albion". */
function normalizeTeamName(string $name): string
{
    $name = mb_strtolower(trim($name));
    $name = str_replace('&', 'and', $name);
    $name = preg_replace('/[^a-z0-9 ]/', '', $name);
    return preg_replace('/\s+/', ' ', $name);
}

/** Split "NAME;PARAM=X:value" into [name, params, value]. */
function parseIcsLine(string $line): array
{
    $colonPos = strpos($line, ':');
    $namePart = substr($line, 0, $colonPos);
    $value = substr($line, $colonPos + 1);
    $segments = explode(';', $namePart);
    $name = array_shift($segments);
    $params = [];
    foreach ($segments as $segment) {
        [$k, $v] = array_pad(explode('=', $segment, 2), 2, null);
        if ($k !== null) {
            $params[strtoupper($k)] = $v;
        }
    }
    return [strtoupper($name), $params, $value];
}

function parseIcsDateTime(string $value, array $params): DateTimeImmutable
{
    if (str_ends_with($value, 'Z')) {
        return DateTimeImmutable::createFromFormat('Ymd\THis\Z', $value, new DateTimeZone('UTC'));
    }
    $tz = isset($params['TZID']) ? new DateTimeZone($params['TZID']) : new DateTimeZone('Europe/London');
    return DateTimeImmutable::createFromFormat('Ymd\THis', $value, $tz);
}

// --- Parse the ICS file into an array of VEVENT property maps ---

$lines = unfoldIcsLines(file_get_contents($icsPath));
$events = [];
$current = null;

foreach ($lines as $line) {
    if ($line === 'BEGIN:VEVENT') {
        $current = [];
        continue;
    }
    if ($line === 'END:VEVENT') {
        if ($current !== null) {
            $events[] = $current;
        }
        $current = null;
        continue;
    }
    if ($current === null || !str_contains($line, ':')) {
        continue;
    }
    [$name, $params, $value] = parseIcsLine($line);
    $current[$name] = ['value' => $value, 'params' => $params];
}

echo 'Parsed ' . count($events) . " VEVENT entries from {$icsPath}\n";

// --- Preload lookups ---

$primarySite = Craft::$app->getSites()->getPrimarySite();

$eventsSection = Craft::$app->getEntries()->getSectionByHandle('events');
if (!$eventsSection) {
    fwrite(STDERR, "No 'events' section found.\n");
    exit(1);
}
$eventEntryType = $eventsSection->getEntryTypes()[0] ?? null;
if (!$eventEntryType) {
    fwrite(STDERR, "'events' section has no entry types.\n");
    exit(1);
}

$existingByImportId = [];
foreach (Entry::find()->section('events')->site($primarySite)->status(null)->all() as $entry) {
    if ($entry->importId) {
        $existingByImportId[$entry->importId] = $entry;
    }
}

$teamsByTitle = [];
foreach (Entry::find()->section('teams')->site($primarySite)->status(null)->all() as $team) {
    $teamsByTitle[normalizeTeamName($team->title)] = $team;
}

// --- Import ---

$created = 0;
$updated = 0;
$skipped = 0;
$ignoredPast = 0;
$now = new DateTimeImmutable();

foreach ($events as $props) {
    $uid = $props['UID']['value'] ?? null;
    $summary = isset($props['SUMMARY']['value']) ? unescapeIcsText($props['SUMMARY']['value']) : null;
    $dtstart = $props['DTSTART'] ?? null;

    if (!$uid || !$summary || !$dtstart) {
        $skipped++;
        continue;
    }

    [$homeTeamName, $awayTeamName] = array_pad(preg_split('/\s+v\s+/', $summary, 2), 2, null);

    $competition = null;
    if (isset($props['DESCRIPTION']['value'])) {
        $descParts = array_map('trim', explode(' - ', unescapeIcsText($props['DESCRIPTION']['value'])));
        $competition = $descParts[2] ?? null;
    }

    $startTime = parseIcsDateTime($dtstart['value'], $dtstart['params']);
    $endTime = isset($props['DTEND']) ? parseIcsDateTime($props['DTEND']['value'], $props['DTEND']['params']) : null;
    $cancelled = ($props['STATUS']['value'] ?? null) === 'CANCELLED';

    // Mirror the front end's fallback (no DTEND = 2 hours after kickoff) to decide if it's already over.
    $effectiveEndTime = $endTime ?: $startTime->modify('+2 hours');
    if ($effectiveEndTime < $now && !isset($existingByImportId[$uid])) {
        $ignoredPast++;
        continue;
    }

    $entry = $existingByImportId[$uid] ?? null;
    $isNew = $entry === null;
    if ($isNew) {
        $entry = new Entry();
        $entry->sectionId = $eventsSection->id;
        $entry->typeId = $eventEntryType->id;
        $entry->siteId = $primarySite->id;
    }

    $homeTeam = $homeTeamName ? ($teamsByTitle[normalizeTeamName($homeTeamName)] ?? null) : null;
    $awayTeam = $awayTeamName ? ($teamsByTitle[normalizeTeamName($awayTeamName)] ?? null) : null;

    $entry->title = $summary;
    $entry->importId = $uid;
    $entry->eventCategory = 'football';
    $entry->venue = 'swaySports';
    $entry->startTime = $startTime;
    $entry->endTime = $endTime;
    $entry->competition = $competition;
    $entry->homeTeam = $homeTeam ? [$homeTeam->id] : [];
    $entry->awayTeam = $awayTeam ? [$awayTeam->id] : [];
    // Only fall back to free-text team names when there's no matching Team entry.
    $entry->homeTeamText = $homeTeam ? null : $homeTeamName;
    $entry->awayTeamText = $awayTeam ? null : $awayTeamName;
    $entry->eventEnabled = !$cancelled;

    if ($dryRun) {
        echo ($isNew ? '[create] ' : '[update] ') . "{$summary} @ {$startTime->format(DATE_ATOM)}\n";
    } elseif (!Craft::$app->getElements()->saveElement($entry)) {
        fwrite(STDERR, "Failed to save '{$summary}': " . json_encode($entry->getErrors()) . "\n");
        $skipped++;
        continue;
    }

    $isNew ? $created++ : $updated++;
}

echo ($dryRun ? '[dry-run] ' : '') . "Created: {$created}, Updated: {$updated}, Skipped: {$skipped}, Ignored (past): {$ignoredPast}\n";
exit(0);
