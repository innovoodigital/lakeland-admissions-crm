<?php
require __DIR__ . '/../includes/db.php';

if ($argc < 2) {
    fwrite(STDERR, "Usage: php tools/sync-leads-from-csv.php <csv-path>\n");
    exit(1);
}

$csvPath = $argv[1];
if (!is_file($csvPath)) {
    fwrite(STDERR, "CSV file not found: $csvPath\n");
    exit(1);
}

function sync_date(string $raw, string $fallbackYear = '2026'): ?string
{
    $raw = trim($raw);
    if ($raw === '' || $raw === '-') return null;

    $clean = preg_replace('/(\d+)(st|nd|rd|th)/i', '$1', $raw);
    if (!preg_match('/\d{4}/', $clean)) {
        $clean .= ' ' . $fallbackYear;
    }

    $ts = strtotime($clean);
    return $ts ? date('Y-m-d', $ts) : null;
}

function sync_cell(array $row, ?int $index): string
{
    return $index === null ? '' : trim((string)($row[$index] ?? ''));
}

function sync_find_col(array $header, string $name): ?int
{
    $needle = strtolower($name);
    foreach ($header as $index => $value) {
        if (strtolower(trim((string)$value)) === $needle) {
            return $index;
        }
    }
    return null;
}

function sync_status(string $sheetStatus): string
{
    return match (strtolower(trim($sheetStatus))) {
        'interested' => 'high_quality',
        'not interested' => 'not_interested',
        'no response' => 'contacted',
        'pending' => 'follow_up',
        default => 'new',
    };
}

function sync_limit(string $value, int $limit): string
{
    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, $limit);
    }
    return substr($value, 0, $limit);
}

function sync_table_exists(PDO $db, string $table): bool
{
    $stmt = $db->prepare(
        'SELECT COUNT(*)
         FROM information_schema.tables
         WHERE table_schema = DATABASE()
           AND table_name = ?'
    );
    $stmt->execute([$table]);
    return (int)$stmt->fetchColumn() > 0;
}

function sync_columns(PDO $db, string $table): array
{
    if (!sync_table_exists($db, $table)) return [];

    $stmt = $db->prepare(
        'SELECT column_name
         FROM information_schema.columns
         WHERE table_schema = DATABASE()
           AND table_name = ?'
    );
    $stmt->execute([$table]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

$handle = fopen($csvPath, 'r');
if (!$handle) {
    fwrite(STDERR, "Could not open CSV file.\n");
    exit(1);
}

$currentHeader = null;
$parsed = [];

while (($row = fgetcsv($handle, 0, ',', '"', '\\')) !== false) {
    $row = array_map(static fn($value) => trim((string)$value), $row);
    $lower = array_map('strtolower', $row);

    if (in_array('date', $lower, true) && in_array('contact', $lower, true)) {
        $currentHeader = $row;
        continue;
    }

    if ($currentHeader === null) {
        continue;
    }

    $dateIndex = sync_find_col($currentHeader, 'Date');
    $contactIndex = sync_find_col($currentHeader, 'Contact');
    $parentIndex = sync_find_col($currentHeader, 'parent');
    $childIndex = sync_find_col($currentHeader, 'child');

    $date = sync_date(sync_cell($row, $dateIndex));
    $contact = sync_cell($row, $contactIndex);
    $parent = sync_cell($row, $parentIndex);
    $child = sync_cell($row, $childIndex);

    if ($date === null || ($contact === '' && $parent === '' && $child === '')) {
        continue;
    }

    $locationIndex = sync_find_col($currentHeader, 'location');
    $statusIndexes = [];
    foreach ($currentHeader as $index => $name) {
        $normalized = strtolower(trim((string)$name));
        if ($index > 5 && $locationIndex !== null && $index < $locationIndex && str_starts_with($normalized, 'status')) {
            $statusIndexes[$index] = trim((string)$name) ?: 'Status';
        }
    }

    $reasonIndex = sync_find_col($currentHeader, 'reason');

    $parsed[] = [
        'received_date' => $date,
        'sheet_status' => sync_cell($row, 1),
        'grade' => sync_limit(sync_cell($row, sync_find_col($currentHeader, 'current grade')), 50),
        'contact' => sync_limit($contact, 50),
        'inquiry_notes' => sync_cell($row, sync_find_col($currentHeader, 'inquiry')),
        'location' => sync_limit(sync_cell($row, $locationIndex), 120),
        'parent_name' => sync_limit($parent, 120),
        'child_name' => sync_limit($child, 120),
        'current_school' => sync_limit(sync_cell($row, sync_find_col($currentHeader, 'current school')), 180),
        'fb_name' => sync_limit(sync_cell($row, sync_find_col($currentHeader, 'Fb Name')), 120),
        'transfer_period' => sync_limit(sync_cell($row, sync_find_col($currentHeader, 'Transfering Time Period')), 120),
        'reason' => sync_limit(sync_cell($row, $reasonIndex), 150),
        'followups' => array_values(array_filter(array_map(
            static function (int $index, string $label) use ($row): ?string {
                $note = trim((string)($row[$index] ?? ''));
                if ($note === '' || $note === '-' || strtolower($note) === 'n/a') {
                    return null;
                }
                return $label . ': ' . $note;
            },
            array_keys($statusIndexes),
            array_values($statusIndexes)
        ))),
    ];
}
fclose($handle);

if (!$parsed) {
    fwrite(STDERR, "No valid leads found in CSV.\n");
    exit(1);
}

$db = get_db();
$timestamp = date('Ymd_His');
$leadBackupTable = 'leads_backup_' . $timestamp;
$followupBackupTable = 'follow_ups_backup_' . $timestamp;

$leadColumns = sync_columns($db, 'leads');
$followupColumns = sync_columns($db, 'follow_ups');

try {
    $db->exec("CREATE TABLE `$leadBackupTable` AS SELECT * FROM leads");
    $db->exec("CREATE TABLE `$followupBackupTable` AS SELECT * FROM follow_ups");

    $db->beginTransaction();

    foreach (['lead_reminders', 'followup_schedule_options', 'lead_inquiries'] as $optionalTable) {
        if (sync_table_exists($db, $optionalTable)) {
            $db->exec("DELETE FROM `$optionalTable`");
        }
    }

    $db->exec('DELETE FROM follow_ups');
    $db->exec('DELETE FROM leads');

    $insertLead = $db->prepare(
        "INSERT INTO leads (received_date, source, grade, contact, parent_name, child_name,
         current_school, location, fb_name, inquiry_notes, transfer_period, reason, status)
         VALUES (?, 'lead_form', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $insertFollowup = $db->prepare(
        'INSERT INTO follow_ups (lead_id, followup_number, followup_date, notes) VALUES (?, ?, ?, ?)'
    );

    $insertedFollowups = 0;
    foreach ($parsed as $lead) {
        $insertLead->execute([
            $lead['received_date'],
            $lead['grade'],
            $lead['contact'],
            $lead['parent_name'],
            $lead['child_name'],
            $lead['current_school'],
            $lead['location'],
            $lead['fb_name'],
            $lead['inquiry_notes'],
            $lead['transfer_period'],
            $lead['reason'],
            sync_status($lead['sheet_status']),
        ]);

        $leadId = (int)$db->lastInsertId();
        $number = 1;
        foreach ($lead['followups'] as $note) {
            $insertFollowup->execute([$leadId, $number, $lead['received_date'], $note]);
            $number++;
            $insertedFollowups++;
        }
    }

    $db->commit();
} catch (Throwable $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    fwrite(STDERR, "Sync failed: " . $e->getMessage() . "\n");
    exit(1);
}

echo "CSV leads found: " . count($parsed) . PHP_EOL;
echo "Database now contains only CSV leads." . PHP_EOL;
echo "Inserted leads: " . count($parsed) . PHP_EOL;
echo "Inserted follow-ups: $insertedFollowups" . PHP_EOL;
echo "Backup tables: $leadBackupTable, $followupBackupTable" . PHP_EOL;
