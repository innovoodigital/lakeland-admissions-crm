<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';
require_admin();

$db = get_db();

const TARGET_FIELDS = [
    ''                => '— Skip this column —',
    'received_date'   => 'Date received',
    'source'          => 'Source (lead form / WhatsApp / etc.)',
    'grade'           => 'Grade applying for',
    'contact'         => 'Contact number',
    'parent_name'     => 'Parent name',
    'child_name'      => 'Child name',
    'current_school'  => 'Current school',
    'location'        => 'Location / town',
    'fb_name'         => 'Facebook name',
    'inquiry_notes'   => 'Inquiry notes',
    'transfer_period' => 'Transfer timing',
    'reason'          => 'Reason for transfer',
    'follow_up'       => '→ Turn into a follow-up entry',
];

function loose_date(string $raw, string $fallbackYear): ?string {
    $raw = trim($raw);
    if ($raw === '' || $raw === '-') return null;
    $clean = preg_replace('/(\d+)(st|nd|rd|th)/i', '$1', $raw);
    if (!preg_match('/\d{4}/', $clean)) $clean .= ' ' . $fallbackYear;
    $ts = strtotime($clean);
    return $ts ? date('Y-m-d', $ts) : null;
}

function to_utf8(string $raw): string {
    if (substr($raw, 0, 2) === "\xFF\xFE" || substr($raw, 0, 2) === "\xFE\xFF") {
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($raw, 'UTF-8', 'UTF-16');
        }
        $from = substr($raw, 0, 2) === "\xFF\xFE" ? 'UTF-16LE' : 'UTF-16BE';
        return @iconv($from, 'UTF-8//IGNORE', $raw) ?: $raw;
    }
    $isUtf8 = function_exists('mb_check_encoding') ? mb_check_encoding($raw, 'UTF-8') : (preg_match('//u', $raw) === 1);
    if (!$isUtf8) {
        if (function_exists('mb_convert_encoding')) {
            return mb_convert_encoding($raw, 'UTF-8', 'auto');
        }
        return @iconv('Windows-1252', 'UTF-8//IGNORE', $raw) ?: $raw;
    }
    return $raw;
}

function read_upload_as_rows(string $path): array {
    $raw = file_get_contents($path);
    $raw = to_utf8($raw);
    $raw = str_replace(["\r\n", "\r"], "\n", $raw);
    $lines = array_values(array_filter(explode("\n", $raw), fn($l) => trim($l) !== ''));
    if (!$lines) return [];

    // Detect delimiter from the header line.
    $delim = substr_count($lines[0], "\t") > substr_count($lines[0], ',') ? "\t" : ',';

    $rows = [];
    foreach ($lines as $line) {
        $rows[] = str_getcsv($line, $delim, '"', '\\');
    }
    return $rows;
}

$step = $_POST['step'] ?? ($_SERVER['REQUEST_METHOD'] === 'POST' ? '' : 'upload');
$message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'parse') {
    csrf_check();
    if (!empty($_FILES['csv_file']['tmp_name']) && is_uploaded_file($_FILES['csv_file']['tmp_name'])) {
        $rows = read_upload_as_rows($_FILES['csv_file']['tmp_name']);
        if (count($rows) < 2) {
            $message = 'Could not find any data rows in that file.';
            $step = 'upload';
        } else {
            $_SESSION['import_header'] = $rows[0];
            $_SESSION['import_rows'] = array_slice($rows, 1);
            $step = 'map';
        }
    } else {
        $message = 'Please choose a file to upload.';
        $step = 'upload';
    }
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['step'] ?? '') === 'commit') {
    csrf_check();
    $map = $_POST['map'] ?? [];
    $year = preg_replace('/\D/', '', $_POST['default_year'] ?? date('Y')) ?: date('Y');
    $header = $_SESSION['import_header'] ?? [];
    $rows = $_SESSION['import_rows'] ?? [];
    $inserted = 0;

    foreach ($rows as $row) {
        $lead = [
            'received_date' => date('Y-m-d'), 'source' => 'other', 'grade' => '', 'contact' => '',
            'parent_name' => '', 'child_name' => '', 'current_school' => '', 'location' => '',
            'fb_name' => '', 'inquiry_notes' => '', 'transfer_period' => '', 'reason' => '',
        ];
        $followup_texts = [];

        foreach ($map as $colIndex => $target) {
            if ($target === '' || !isset($row[$colIndex])) continue;
            $value = trim($row[$colIndex]);
            if ($target === 'follow_up') {
                if ($value !== '' && $value !== '-' && strtolower($value) !== 'n/a') {
                    $followup_texts[] = trim(($header[$colIndex] ?? 'Note') . ': ' . $value);
                }
            } elseif ($target === 'received_date') {
                $lead['received_date'] = loose_date($value, $year) ?: $lead['received_date'];
            } elseif (array_key_exists($target, $lead)) {
                $lead[$target] = $value;
            }
        }

        if (!$lead['contact'] && !$lead['parent_name'] && !$lead['child_name']) continue; // skip empty rows

        $db->prepare("INSERT INTO leads (received_date, source, grade, contact, parent_name, child_name,
                       current_school, location, fb_name, inquiry_notes, transfer_period, reason, status)
                       VALUES (?,?,?,?,?,?,?,?,?,?,?,?, 'new')")
           ->execute(array_values($lead));
        $leadId = $db->lastInsertId();

        $num = 1;
        foreach ($followup_texts as $text) {
            $db->prepare('INSERT INTO follow_ups (lead_id, followup_number, followup_date, notes) VALUES (?,?,?,?)')
               ->execute([$leadId, $num, $lead['received_date'], $text]);
            $num++;
        }
        $inserted++;
    }

    unset($_SESSION['import_header'], $_SESSION['import_rows']);
    flash_set("Imported $inserted lead(s).");
    header('Location: leads.php'); exit;
}

$page_title = 'Import CSV';
$active = 'import';
require __DIR__ . '/includes/layout_top.php';
?>

<div class="topbar">
  <div>
    <div class="eyebrow">Bulk import</div>
    <h1>Import leads from a spreadsheet</h1>
  </div>
</div>

<?php if ($message): ?><div class="flash error"><?= e($message) ?></div><?php endif; ?>

<?php if ($step === 'upload'): ?>
  <div class="card">
    <p>Upload the CSV export of your lead sheet (comma or tab separated — Google Sheets exports both fine). On the next step you'll match each column to a field, so any sheet layout works.</p>
    <form method="post" enctype="multipart/form-data">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="step" value="parse">
      <div class="field">
        <label for="csv_file">CSV file</label>
        <input type="file" id="csv_file" name="csv_file" accept=".csv,.tsv,.txt" required>
      </div>
      <button type="submit" class="btn btn-primary">Continue</button>
    </form>
  </div>

<?php elseif ($step === 'map'): ?>
  <?php $header = $_SESSION['import_header'] ?? []; $rowCount = count($_SESSION['import_rows'] ?? []); ?>
  <div class="card">
    <p><?= $rowCount ?> row(s) found. Match each of your sheet's columns to a field below. Any column with dated status notes (e.g. "Status - 22nd") can map to <strong>→ Turn into a follow-up entry</strong> — each non-empty value becomes a logged follow-up.</p>
    <form method="post">
      <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">
      <input type="hidden" name="step" value="commit">
      <div class="field" style="max-width:220px;">
        <label for="default_year">Year to assume for dates without one</label>
        <input type="text" id="default_year" name="default_year" value="<?= date('Y') ?>">
      </div>
      <table>
        <thead><tr><th>Your column</th><th>Sample value</th><th>Maps to</th></tr></thead>
        <tbody>
        <?php foreach ($header as $i => $colName): ?>
          <tr>
            <td><strong><?= e($colName ?: '(column ' . ($i+1) . ')') ?></strong></td>
            <td style="color:var(--slate);"><?= e(function_exists('mb_substr') ? mb_substr(($_SESSION['import_rows'][0][$i] ?? ''), 0, 40) : substr(($_SESSION['import_rows'][0][$i] ?? ''), 0, 40)) ?></td>
            <td>
              <select name="map[<?= $i ?>]">
                <?php foreach (TARGET_FIELDS as $val => $label): ?>
                  <option value="<?= e($val) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
              </select>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <div style="margin-top:16px;">
        <button type="submit" class="btn btn-primary">Import <?= $rowCount ?> lead(s)</button>
        <a href="import_csv.php" class="btn btn-outline">Start over</a>
      </div>
    </form>
  </div>
<?php endif; ?>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
