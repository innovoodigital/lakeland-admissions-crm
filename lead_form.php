<?php
error_reporting(E_ALL);
ini_set('display_errors', '1');
ini_set('display_startup_errors', '1');

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/helpers.php';

require_admin();

$db = get_db();

$id = isset($_GET['id'])
    ? (int) $_GET['id']
    : (isset($_POST['id']) ? (int) $_POST['id'] : 0);

/*
|--------------------------------------------------------------------------
| Compatibility helpers
|--------------------------------------------------------------------------
| This page checks the live database columns before saving. This prevents
| the generic "lead could not be saved" error when the PHP file and the
| database are not yet on exactly the same version.
*/

function lead_form_table_exists($db, $table)
{
    try {
        $stmt = $db->query("SHOW TABLES LIKE " . $db->quote($table));
        return (bool) $stmt->fetchColumn();
    } catch (Throwable $error) {
        return false;
    }
}

function lead_form_table_columns($db, $table)
{
    try {
        $tableExistsStmt = $db->query("SHOW TABLES LIKE " . $db->quote($table));

        if (!$tableExistsStmt->fetchColumn()) {
            return [];
        }

        $rows = $db->query("SHOW COLUMNS FROM `{$table}`")->fetchAll(PDO::FETCH_ASSOC);
        return array_column($rows, 'Field');
    } catch (Throwable $error) {
        return [];
    }
}

function lead_form_has_column($columns, $column)
{
    return in_array($column, $columns, true);
}

$leadColumns = lead_form_table_columns($db, 'leads');
$inquiryColumns = lead_form_table_columns($db, 'lead_inquiries');
$hasInquiryTable = !empty($inquiryColumns);

$contactStatusLabels = [
    'new' => 'New',
    'contacted' => 'Contacted',
    'follow_up_needed' => 'Follow-up Needed',
    'follow_up_required' => 'Follow-up Required',
    'visit_scheduled' => 'Visit Scheduled',
    'visited' => 'Visited',
    'not_reached' => 'Not Reached',
];

$inquiryStatusLabels = [
    'possible' => 'Possible',
    'not_possible' => 'Not Possible',
    'can_consider' => 'Can Consider',
    'adjustable' => 'Can Adjust',
    'recommended' => 'Recommended',
    'management_approval' => 'Need Management Approval',
    'alternative_available' => 'Alternative Available',
    'pending' => 'Pending',
    'completed' => 'Completed',
];

$parentResponseLabels = defined('PARENT_RESPONSE_LABELS')
    ? PARENT_RESPONSE_LABELS
    : [
        'interested' => 'Parent Interested',
        'still_considering' => 'Parent Still Considering',
        'call_back_later' => 'Parent Asked to Call Later',
        'will_call_back' => 'Parent Will Call Back',
        'pending' => 'Pending',
        'no_response' => 'Parent Did Not Respond',
        'not_reached' => 'Parent Not Reached',
        'number_not_working' => 'Parent Number Not Working',
        'not_interested' => 'Parent Not Interested',
        'wrong_lead' => 'Wrong Lead',
        'accidental_lead' => 'Accidental Lead',
        'job_inquiry' => 'Job Inquiry',
        'rejected' => 'Rejected',
    ];
$parentResponseOptions = array_diff_key(
    $parentResponseLabels,
    array_flip(['positive', 'neutral', 'negative', 'random_click'])
);
$workflowStatusOptions = array_diff_key(
    STATUS_LABELS,
    array_flip(['follow_up_needed', 'follow_up', 'converted', 'high_quality'])
);

$lead = [
    'received_date' => date('Y-m-d'),
    'received_time' => date('H:i'),
    'source' => 'call_in',
    'grade' => '',
    'contact' => '',
    'contact_status' => 'new',
    'parent_name' => '',
    'child_name' => '',
    'current_school' => '',
    'location' => '',
    'fb_name' => '',
    'inquiry_notes' => '',
    'transfer_period' => '',
    'reason' => '',
    'status' => 'new',
    'parent_response' => 'pending',
    'rejection_reason' => '',
    'visit_date' => '',
    'converted_date' => '',
];

$inquiries = [
    [
        'inquiry_title' => '',
        'inquiry_details' => '',
        'inquiry_status' => 'pending',
    ],
];

$errors = [];

/*
|--------------------------------------------------------------------------
| Load existing lead and inquiries
|--------------------------------------------------------------------------
*/

if ($id > 0) {
    $stmt = $db->prepare('SELECT * FROM leads WHERE id = ?');
    $stmt->execute([$id]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$existing) {
        flash_set('Lead not found.', 'error');
        header('Location: leads.php');
        exit;
    }

    $lead = array_merge($lead, $existing);

    if (!empty($lead['received_time'])) {
        $lead['received_time'] = substr((string) $lead['received_time'], 0, 5);
    }

    if ($hasInquiryTable) {
        $selectFields = array_values(array_intersect(
            ['inquiry_title', 'inquiry_details', 'inquiry_status'],
            $inquiryColumns
        ));

        if (
            in_array('lead_id', $inquiryColumns, true)
            && count($selectFields) === 3
        ) {
            $orderParts = [];

            if (in_array('created_at', $inquiryColumns, true)) {
                $orderParts[] = 'created_at ASC';
            }

            if (in_array('id', $inquiryColumns, true)) {
                $orderParts[] = 'id ASC';
            }

            $sql = 'SELECT inquiry_title, inquiry_details, inquiry_status
                    FROM lead_inquiries
                    WHERE lead_id = ?';

            if ($orderParts) {
                $sql .= ' ORDER BY ' . implode(', ', $orderParts);
            }

            $inquiryStmt = $db->prepare($sql);
            $inquiryStmt->execute([$id]);
            $savedInquiries = $inquiryStmt->fetchAll(PDO::FETCH_ASSOC);

            if ($savedInquiries) {
                $inquiries = $savedInquiries;
            } elseif (!empty($lead['inquiry_notes'])) {
                $inquiries = [[
                    'inquiry_title' => 'Initial inquiry',
                    'inquiry_details' => $lead['inquiry_notes'],
                    'inquiry_status' => 'pending',
                ]];
            }
        }
    } elseif (!empty($lead['inquiry_notes'])) {
        $inquiries = [[
            'inquiry_title' => 'Initial inquiry',
            'inquiry_details' => $lead['inquiry_notes'],
            'inquiry_status' => 'pending',
        ]];
    }
}

/*
|--------------------------------------------------------------------------
| Save lead
|--------------------------------------------------------------------------
*/

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    $editableFields = [
        'received_date',
        'received_time',
        'source',
        'grade',
        'contact',
        'contact_status',
        'parent_name',
        'child_name',
        'current_school',
        'location',
        'fb_name',
        'inquiry_notes',
        'transfer_period',
        'reason',
        'status',
        'parent_response',
        'rejection_reason',
        'visit_date',
        'converted_date',
    ];

    foreach ($editableFields as $field) {
        $lead[$field] = trim((string) ($_POST[$field] ?? $lead[$field] ?? ''));
    }

    $titles = $_POST['inquiry_title'] ?? [];
    $details = $_POST['inquiry_details'] ?? [];
    $statuses = $_POST['inquiry_status'] ?? [];

    $inquiries = [];

    $rowCount = max(count($titles), count($details), count($statuses));

    for ($index = 0; $index < $rowCount; $index++) {
        $title = trim((string) ($titles[$index] ?? ''));
        $detail = trim((string) ($details[$index] ?? ''));
        $status = trim((string) ($statuses[$index] ?? 'pending'));

        if ($title === '' && $detail === '') {
            continue;
        }

        $inquiries[] = [
            'inquiry_title' => $title,
            'inquiry_details' => $detail,
            'inquiry_status' => $status,
        ];
    }

    /*
    |--------------------------------------------------------------------------
    | Validation
    |--------------------------------------------------------------------------
    */

    if ($lead['received_date'] === '') {
        $errors[] = 'Received date is required.';
    }

    if ($lead['contact'] === '') {
        $errors[] = 'Contact number is required.';
    }

    if (!array_key_exists($lead['source'], SOURCE_LABELS)) {
        $errors[] = 'Please select a valid lead source.';
    }

    if (!array_key_exists($lead['status'], STATUS_LABELS)) {
        $errors[] = 'Please select a valid workflow status.';
    }

    if (
        lead_form_has_column($leadColumns, 'contact_status')
        && !array_key_exists($lead['contact_status'], $contactStatusLabels)
    ) {
        $errors[] = 'Please select a valid contact status.';
    }

    if (
        lead_form_has_column($leadColumns, 'parent_response')
        && !array_key_exists($lead['parent_response'], $parentResponseLabels)
    ) {
        $errors[] = 'Please select a valid parent response.';
    }

    foreach ($inquiries as $index => $inquiry) {
        if ($inquiry['inquiry_title'] === '') {
            $errors[] = 'Please enter a title for inquiry item ' . ($index + 1) . '.';
        }

        if (!array_key_exists($inquiry['inquiry_status'], $inquiryStatusLabels)) {
            $errors[] = 'Please select a valid status for inquiry item ' . ($index + 1) . '.';
        }
    }

    $reasonRequiredResponses = [
        'not_interested',
        'wrong_lead',
        'accidental_lead',
        'job_inquiry',
        'rejected',
    ];

    if (
        lead_form_has_column($leadColumns, 'parent_response')
        && in_array($lead['parent_response'], $reasonRequiredResponses, true)
        && $lead['rejection_reason'] === ''
    ) {
        $errors[] = 'Please enter a reason for this parent response.';
    }

    /*
    |--------------------------------------------------------------------------
    | Prepare values
    |--------------------------------------------------------------------------
    */

    if (!$errors) {
        foreach (['received_time', 'visit_date', 'converted_date', 'rejection_reason'] as $nullable) {
            if (($lead[$nullable] ?? '') === '') {
                $lead[$nullable] = null;
            }
        }

        if (
            lead_form_has_column($leadColumns, 'parent_response')
            && !in_array($lead['parent_response'], $reasonRequiredResponses, true)
        ) {
            $lead['rejection_reason'] = null;
        }

        if ($inquiries) {
            $legacyNotes = [];

            foreach ($inquiries as $inquiry) {
                $line = $inquiry['inquiry_title'];

                if ($inquiry['inquiry_details'] !== '') {
                    $line .= ': ' . $inquiry['inquiry_details'];
                }

                $legacyNotes[] = $line;
            }

            $lead['inquiry_notes'] = implode("\n", $legacyNotes);
        }

        try {
            $db->beginTransaction();

            /*
            | Only save fields that actually exist in the current database.
            */
            $saveFields = array_values(array_filter(
                $editableFields,
                static function ($field) use ($leadColumns) {
                    return in_array($field, $leadColumns, true);
                }
            ));

            if (!$saveFields) {
                throw new RuntimeException('No compatible columns were found in the leads table.');
            }

            if ($id > 0) {
                $assignments = implode(
                    ', ',
                    array_map(
                        static function ($field) {
                            return "`{$field}` = ?";
                        },
                        $saveFields
                    )
                );

                $values = array_map(
                    static function ($field) use ($lead) {
                        return isset($lead[$field]) ? $lead[$field] : null;
                    },
                    $saveFields
                );

                $values[] = $id;

                $sql = "UPDATE leads SET {$assignments} WHERE id = ?";
                $db->prepare($sql)->execute($values);
                $leadId = $id;
            } else {
                $columnSql = implode(
                    ', ',
                    array_map(
                        static function ($field) {
                            return "`{$field}`";
                        },
                        $saveFields
                    )
                );

                $placeholders = implode(', ', array_fill(0, count($saveFields), '?'));

                $values = array_map(
                    static function ($field) use ($lead) {
                        return isset($lead[$field]) ? $lead[$field] : null;
                    },
                    $saveFields
                );

                $sql = "INSERT INTO leads ({$columnSql}) VALUES ({$placeholders})";
                $db->prepare($sql)->execute($values);
                $leadId = (int) $db->lastInsertId();
            }

            /*
            |--------------------------------------------------------------------------
            | Replace inquiry rows only when the table and columns exist
            |--------------------------------------------------------------------------
            */

            $requiredInquiryColumns = [
                'lead_id',
                'inquiry_title',
                'inquiry_details',
                'inquiry_status',
            ];

            $canSaveInquiries = $hasInquiryTable
                && empty(array_diff($requiredInquiryColumns, $inquiryColumns));

            if ($canSaveInquiries) {
                $deleteInquiries = $db->prepare(
                    'DELETE FROM lead_inquiries WHERE lead_id = ?'
                );
                $deleteInquiries->execute([$leadId]);

                if ($inquiries) {
                    $insertInquiry = $db->prepare(
                        'INSERT INTO lead_inquiries (
                            lead_id,
                            inquiry_title,
                            inquiry_details,
                            inquiry_status
                        ) VALUES (?, ?, ?, ?)'
                    );

                    foreach ($inquiries as $inquiry) {
                        $insertInquiry->execute([
                            $leadId,
                            $inquiry['inquiry_title'],
                            $inquiry['inquiry_details'] !== ''
                                ? $inquiry['inquiry_details']
                                : null,
                            $inquiry['inquiry_status'],
                        ]);
                    }
                }
            }

            $db->commit();

            flash_set(
                $id > 0
                    ? 'Lead updated successfully.'
                    : 'Lead added successfully.'
            );

            header('Location: lead_view.php?id=' . $leadId);
            exit;
        } catch (Throwable $error) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }

            error_log('Lead save failed: ' . $error->getMessage());

            /*
            | Show the real error to administrators while fixing the CRM.
            | Remove the detailed part after the issue is confirmed fixed.
            */
            $errors[] = 'The lead could not be saved. Database error: ' . $error->getMessage();
        }
    }
}

$page_title = $id ? 'Edit Lead' : 'Add Lead';
$active = $id ? 'leads' : 'add';

require __DIR__ . '/includes/layout_top.php';
?>

<div class="topbar">
    <div>
        <div class="eyebrow">
            <?= $id ? 'Editing lead #' . $id : 'New entry' ?>
        </div>

        <h1><?= $id ? 'Edit lead' : 'Add a new lead' ?></h1>
    </div>
</div>

<?php if ($errors): ?>
    <div class="flash error">
        <?= implode('<br>', array_map('e', $errors)) ?>
    </div>
<?php endif; ?>

<form method="post" class="card" id="leadForm">
    <input type="hidden" name="csrf" value="<?= e(csrf_token()) ?>">

    <?php if ($id): ?>
        <input type="hidden" name="id" value="<?= $id ?>">
    <?php endif; ?>

    <h2>Lead received</h2>

    <div class="form-row">
        <div class="field">
            <label for="received_date">Date received</label>
            <input
                type="date"
                id="received_date"
                name="received_date"
                value="<?= e((string) $lead['received_date']) ?>"
                required
            >
        </div>

        <?php if (lead_form_has_column($leadColumns, 'received_time')): ?>
            <div class="field">
                <label for="received_time">Time received</label>
                <input
                    type="time"
                    id="received_time"
                    name="received_time"
                    value="<?= e((string) ($lead['received_time'] ?? '')) ?>"
                >
            </div>
        <?php endif; ?>

        <div class="field">
            <label for="source">How it came in</label>
            <select id="source" name="source" required>
                <?php foreach (SOURCE_LABELS as $key => $label): ?>
                    <option
                        value="<?= e($key) ?>"
                        <?= $lead['source'] === $key ? 'selected' : '' ?>
                    >
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <h2>Student and parent details</h2>

    <div class="form-row">
        <div class="field">
            <label for="child_name">Child name</label>
            <input
                type="text"
                id="child_name"
                name="child_name"
                value="<?= e((string) $lead['child_name']) ?>"
            >
        </div>

        <div class="field">
            <label for="parent_name">Parent name</label>
            <input
                type="text"
                id="parent_name"
                name="parent_name"
                value="<?= e((string) $lead['parent_name']) ?>"
            >
        </div>

        <div class="field">
            <label for="grade">Grade applying for</label>
            <select id="grade" name="grade">
                <option value="">Select grade</option>
                <?php for ($gradeNumber = 1; $gradeNumber <= 11; $gradeNumber++): ?>
                    <?php $gradeLabel = 'Grade ' . $gradeNumber; ?>
                    <option
                        value="<?= e($gradeLabel) ?>"
                        <?= (string) $lead['grade'] === $gradeLabel ? 'selected' : '' ?>
                    >
                        <?= e($gradeLabel) ?>
                    </option>
                <?php endfor; ?>
                <?php if (
                    trim((string) $lead['grade']) !== ''
                    && !preg_match('/^Grade (?:[1-9]|1[01])$/', (string) $lead['grade'])
                ): ?>
                    <option value="<?= e((string) $lead['grade']) ?>" selected>
                        <?= e((string) $lead['grade']) ?>
                    </option>
                <?php endif; ?>
            </select>
        </div>
    </div>

    <div class="form-row">
        <div class="field">
            <label for="contact">Contact number</label>
            <input
                type="text"
                id="contact"
                name="contact"
                value="<?= e((string) $lead['contact']) ?>"
                required
            >
        </div>

        <?php if (lead_form_has_column($leadColumns, 'contact_status')): ?>
            <div class="field">
                <label for="contact_status">Contact status</label>
                <select id="contact_status" name="contact_status">
                    <?php foreach ($contactStatusLabels as $key => $label): ?>
                        <option
                            value="<?= e($key) ?>"
                            <?= ($lead['contact_status'] ?? 'new') === $key
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="field">
            <label for="fb_name">Facebook name</label>
            <input
                type="text"
                id="fb_name"
                name="fb_name"
                value="<?= e((string) $lead['fb_name']) ?>"
            >
        </div>
    </div>

    <div class="form-row">
        <div class="field">
            <label for="current_school">Current school</label>
            <input
                type="text"
                id="current_school"
                name="current_school"
                value="<?= e((string) $lead['current_school']) ?>"
            >
        </div>

        <div class="field">
            <label for="location">Location / town</label>
            <input
                type="text"
                id="location"
                name="location"
                value="<?= e((string) $lead['location']) ?>"
            >
        </div>
    </div>

    <div class="form-row">
        <div class="field">
            <label for="transfer_period">Planning to transfer</label>
            <input
                type="text"
                id="transfer_period"
                name="transfer_period"
                value="<?= e((string) $lead['transfer_period']) ?>"
                placeholder="e.g. Next term or within one month"
            >
        </div>

        <div class="field">
            <label for="reason">Reason for transfer</label>
            <input
                type="text"
                id="reason"
                name="reason"
                value="<?= e((string) $lead['reason']) ?>"
                placeholder="e.g. Better academic support"
            >
        </div>
    </div>

    <section id="inquiries" class="lead-inquiry-form-section">
        <div class="section-title-row">
            <div>
                <div class="eyebrow">Parent requirements</div>
                <h2>Initial inquiries</h2>
                <p style="color:var(--slate); margin-top:4px;">
                    Add each requirement separately and select whether
                    the school can support it.
                </p>
            </div>

            <button
                type="button"
                class="btn btn-outline"
                onclick="addInquiryRow()"
            >
                + Add inquiry
            </button>
        </div>

        <div id="inquiryRows">
            <?php foreach ($inquiries as $index => $inquiry): ?>
                <div class="inquiry-form-row">
                    <div class="inquiry-form-row-header">
                        <strong>
                            Inquiry
                            <span class="inquiry-row-number"><?= $index + 1 ?></span>
                        </strong>

                        <button
                            type="button"
                            class="btn btn-sm btn-danger"
                            onclick="removeInquiryRow(this)"
                        >
                            Remove
                        </button>
                    </div>

                    <div class="form-row">
                        <div class="field">
                            <label>Requirement / inquiry</label>
                            <input
                                type="text"
                                name="inquiry_title[]"
                                value="<?= e((string) ($inquiry['inquiry_title'] ?? '')) ?>"
                                placeholder="e.g. Looking for English-medium education"
                            >
                        </div>

                        <div class="field">
                            <label>Inquiry status</label>
                            <select name="inquiry_status[]">
                                <?php foreach ($inquiryStatusLabels as $statusKey => $statusLabel): ?>
                                    <option
                                        value="<?= e($statusKey) ?>"
                                        <?= ($inquiry['inquiry_status'] ?? 'pending') === $statusKey
                                            ? 'selected'
                                            : '' ?>
                                    >
                                        <?= e($statusLabel) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <div class="field">
                        <label>Additional details</label>
                        <textarea
                            name="inquiry_details[]"
                            rows="3"
                            placeholder="Add useful information shared by the parent."
                        ><?= e((string) ($inquiry['inquiry_details'] ?? '')) ?></textarea>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>

        <input
            type="hidden"
            id="inquiry_notes"
            name="inquiry_notes"
            value="<?= e((string) $lead['inquiry_notes']) ?>"
        >
    </section>

    <h2>Status and progress</h2>

    <div class="form-row">
        <?php if (lead_form_has_column($leadColumns, 'parent_response')): ?>
            <div class="field">
                <label for="parent_response">Parent response</label>
                <select
                    id="parent_response"
                    name="parent_response"
                    onchange="updateStatusFields()"
                >
                    <?php foreach ($parentResponseOptions as $key => $label): ?>
                        <option
                            value="<?= e($key) ?>"
                            <?= ($lead['parent_response'] ?? 'pending') === $key
                                ? 'selected'
                                : '' ?>
                        >
                            <?= e($label) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        <?php endif; ?>

        <div class="field">
            <label for="status">Workflow status</label>
            <select id="status" name="status" required onchange="updateStatusFields()">
                <?php foreach ($workflowStatusOptions as $key => $label): ?>
                    <option
                        value="<?= e($key) ?>"
                        <?= $lead['status'] === $key ? 'selected' : '' ?>
                    >
                        <?= e($label) ?>
                    </option>
                <?php endforeach; ?>
            </select>

            <small>
                This tracks the admissions progress, such as contacted,
                visit scheduled, joined, or closed.
            </small>
        </div>

        <div
            class="field"
            id="rejection_wrap"
            style="display:none;"
        >
            <label for="rejection_reason">Reason</label>
            <input
                type="text"
                id="rejection_reason"
                name="rejection_reason"
                value="<?= e((string) ($lead['rejection_reason'] ?? '')) ?>"
                placeholder="Enter the reason"
            >
        </div>
    </div>

    <div class="form-row">
        <div class="field">
            <label for="visit_date">School visit date</label>
            <input
                type="date"
                id="visit_date"
                name="visit_date"
                value="<?= e((string) ($lead['visit_date'] ?? '')) ?>"
            >
        </div>

        <div class="field">
            <label for="converted_date">Joined date</label>
            <input
                type="date"
                id="converted_date"
                name="converted_date"
                value="<?= e((string) ($lead['converted_date'] ?? '')) ?>"
            >
        </div>
    </div>

    <div style="display:flex; gap:8px; flex-wrap:wrap; margin-top:18px;">
        <button type="submit" class="btn btn-primary">
            <?= $id ? 'Save changes' : 'Add lead' ?>
        </button>

        <a
            href="<?= $id ? 'lead_view.php?id=' . $id : 'leads.php' ?>"
            class="btn btn-outline"
        >
            Cancel
        </a>
    </div>
</form>

<template id="inquiryRowTemplate">
    <div class="inquiry-form-row">
        <div class="inquiry-form-row-header">
            <strong>
                Inquiry
                <span class="inquiry-row-number"></span>
            </strong>

            <button
                type="button"
                class="btn btn-sm btn-danger"
                onclick="removeInquiryRow(this)"
            >
                Remove
            </button>
        </div>

        <div class="form-row">
            <div class="field">
                <label>Requirement / inquiry</label>
                <input
                    type="text"
                    name="inquiry_title[]"
                    placeholder="e.g. Looking for English-medium education"
                >
            </div>

            <div class="field">
                <label>Inquiry status</label>
                <select name="inquiry_status[]">
                    <?php foreach ($inquiryStatusLabels as $statusKey => $statusLabel): ?>
                        <option
                            value="<?= e($statusKey) ?>"
                            <?= $statusKey === 'pending' ? 'selected' : '' ?>
                        >
                            <?= e($statusLabel) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="field">
            <label>Additional details</label>
            <textarea
                name="inquiry_details[]"
                rows="3"
                placeholder="Add useful information shared by the parent."
            ></textarea>
        </div>
    </div>
</template>

<script>
function getLocalDate() {
    const today = new Date();

    return [
        today.getFullYear(),
        String(today.getMonth() + 1).padStart(2, '0'),
        String(today.getDate()).padStart(2, '0')
    ].join('-');
}

function updateStatusFields() {
    const workflowStatus = document.getElementById('status')?.value || '';
    const parentResponse = document.getElementById('parent_response')?.value || '';

    const rejectionWrap = document.getElementById('rejection_wrap');
    const rejectionReason = document.getElementById('rejection_reason');

    const reasonResponses = [
        'not_interested',
        'wrong_lead',
        'accidental_lead',
        'job_inquiry',
        'rejected'
    ];

    const needsReason = reasonResponses.includes(parentResponse);

    if (rejectionWrap && rejectionReason) {
        rejectionWrap.style.display = needsReason ? 'block' : 'none';
        rejectionReason.required = needsReason;

        if (!needsReason) {
            rejectionReason.value = '';
        }
    }

    const visitStatuses = [
        'visit_scheduled',
        'visited',
        'placement_test_scheduled',
        'placement_test_completed'
    ];

    const successStatuses = ['joined'];

    const visitDate = document.getElementById('visit_date');
    const convertedDate = document.getElementById('converted_date');

    if (
        visitDate
        && visitStatuses.includes(workflowStatus)
        && !visitDate.value
    ) {
        visitDate.value = getLocalDate();
    }

    if (
        convertedDate
        && successStatuses.includes(workflowStatus)
        && !convertedDate.value
    ) {
        convertedDate.value = getLocalDate();
    }
}

function addInquiryRow() {
    const container = document.getElementById('inquiryRows');
    const template = document.getElementById('inquiryRowTemplate');
    const row = template.content.firstElementChild.cloneNode(true);

    container.appendChild(row);
    updateInquiryNumbers();

    const input = row.querySelector('input[name="inquiry_title[]"]');

    if (input) {
        input.focus();
    }
}

function removeInquiryRow(button) {
    const row = button.closest('.inquiry-form-row');

    if (!row) {
        return;
    }

    const rows = document.querySelectorAll(
        '#inquiryRows .inquiry-form-row'
    );

    if (rows.length === 1) {
        row.querySelectorAll('input, textarea').forEach(function (field) {
            field.value = '';
        });

        const select = row.querySelector('select');

        if (select) {
            select.value = 'pending';
        }

        return;
    }

    row.remove();
    updateInquiryNumbers();
}

function updateInquiryNumbers() {
    document
        .querySelectorAll('#inquiryRows .inquiry-form-row')
        .forEach(function (row, index) {
            const number = row.querySelector('.inquiry-row-number');

            if (number) {
                number.textContent = index + 1;
            }
        });
}

document.addEventListener('DOMContentLoaded', function () {
    updateStatusFields();
    updateInquiryNumbers();
});
</script>

<?php require __DIR__ . '/includes/layout_bottom.php'; ?>
