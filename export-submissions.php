<?php
/**
 * export-submissions.php — Export submissions to CSV
 *
 * Admin-only. Mirrors the access rules of submissions.php — non-admins
 * cannot reach this page (redirected to dashboard). Admins export all
 * submissions matching the current form + status filter.
 *
 * GET params:
 *   status   — 'all' | 'pending' | 'in_progress' | 'approved' | 'rejected'
 *   form_id  — UUID of a specific form (optional)
 *
 * Output: text/csv attachment named submissions-YYYY-MM-DD.csv
 *
 * Columns:
 *   Submission ID, Form, Submitted By (name), Submitted By (email),
 *   Status, Submitted At, Completed At,
 *   [then one column per form-data field — union of all field keys across rows]
 *
 * File upload fields ({type: "files", ...}) are exported as a
 * comma-separated list of URLs.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/includes/auth-check.php'; // sets $currentUser, $sb

// ── Admin gate ────────────────────────────────────────────────────────────────
if ($currentUser['role'] !== 'admin') {
    header('Location: /dashboard.php');
    exit;
}

// ── Filters (same as submissions.php) ────────────────────────────────────────
$filterStatus = $_GET['status']  ?? 'all';
$filterFormId = trim($_GET['form_id'] ?? '');

$validStatuses = ['all', 'pending', 'in_progress', 'approved', 'rejected'];
if (!in_array($filterStatus, $validStatuses)) $filterStatus = 'all';

// ── Fetch form map ────────────────────────────────────────────────────────────
$allForms = $sb->from('forms')->select('*')->execute() ?? [];
$formMap  = [];
foreach ($allForms as $f) {
    $formMap[$f['id']] = $f['title'] ?? $f['name'] ?? '—';
}

// ── Fetch submissions ─────────────────────────────────────────────────────────
$query = $sb->from('submissions')->select('*');

if ($filterFormId) {
    $query = $query->eq('form_id', $filterFormId);
}

$allSubmissions = $query->order('submitted_at', false)->limit(5000)->execute() ?? [];

// Apply status filter in PHP (same pattern as submissions.php)
if ($filterStatus !== 'all') {
    $allSubmissions = array_values(array_filter(
        $allSubmissions,
        fn($s) => ($s['status'] ?? '') === $filterStatus
    ));
}

// ── Fetch submitter user names ────────────────────────────────────────────────
// Keyed by email for quick lookup. Some submitters may not be in users table
// if they submitted from outside (e.g. before OAuth was required).
$submitterEmails = array_values(array_unique(array_column($allSubmissions, 'submitter_email')));
$submitterNameMap = [];
if (!empty($submitterEmails)) {
    // Supabase REST doesn't support IN on text easily via our wrapper, so batch in chunks
    foreach ($submitterEmails as $email) {
        $rows = $sb->from('users')->select('email,display_name')->eq('email', $email)->execute();
        if ($rows && isset($rows[0])) {
            $submitterNameMap[$email] = $rows[0]['display_name'] ?? $email;
        }
    }
}

// ── Discover all form-data field keys (union across all rows) ─────────────────
// We do two passes: first collect all keys, then build rows.
$allFieldKeys = [];
$parsedFormData = []; // cache decoded form_data per submission id

foreach ($allSubmissions as $s) {
    $raw = $s['form_data'] ?? '';
    $data = is_string($raw) ? (json_decode($raw, true) ?? []) : (is_array($raw) ? $raw : []);
    $parsedFormData[$s['id']] = $data;
    foreach (array_keys($data) as $key) {
        if (!in_array($key, $allFieldKeys, true)) {
            $allFieldKeys[] = $key;
        }
    }
}

// ── Build CSV ─────────────────────────────────────────────────────────────────

// Helper: escape a value for CSV
function csvVal($v): string
{
    if (is_null($v)) return '';
    $str = (string)$v;
    // Quote if it contains comma, newline, or double quote
    if (str_contains($str, ',') || str_contains($str, "\n") || str_contains($str, '"')) {
        $str = '"' . str_replace('"', '""', $str) . '"';
    }
    return $str;
}

// Helper: render a form_data value as a plain string for CSV
function csvFormDataValue($val): string
{
    if (is_null($val)) return '';

    // File upload fields: export as comma-separated list of URLs
    if (is_array($val) && isset($val['type']) && $val['type'] === 'files') {
        $files = $val['files'] ?? [];
        if (empty($files)) return '';
        return implode(', ', array_map(fn($f) => $f['url'] ?? '', $files));
    }

    // Regular array (e.g. checkbox group)
    if (is_array($val)) {
        return implode(', ', array_map(
            fn($v) => is_array($v) ? implode(', ', $v) : (string)$v,
            $val
        ));
    }

    return (string)$val;
}

// ── Output headers ────────────────────────────────────────────────────────────
$filename = 'submissions-' . date('Y-m-d') . '.csv';

header('Content-Type: text/csv; charset=UTF-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// UTF-8 BOM so Excel opens without garbled characters
echo "\xEF\xBB\xBF";

$out = fopen('php://output', 'w');

// ── Header row ────────────────────────────────────────────────────────────────
$fixedHeaders = [
    'Submission ID',
    'Form',
    'Submitted By (name)',
    'Submitted By (email)',
    'Status',
    'Submitted At',
    'Completed At',
];
fputcsv($out, array_merge($fixedHeaders, $allFieldKeys));

// ── Data rows ─────────────────────────────────────────────────────────────────
foreach ($allSubmissions as $s) {
    $submitterEmail = $s['submitter_email'] ?? '';
    $submitterName  = $submitterNameMap[$submitterEmail] ?? $submitterEmail;
    $formName       = $formMap[$s['form_id'] ?? ''] ?? '—';
    $status         = ucfirst(str_replace('_', ' ', $s['status'] ?? ''));
    $submittedAt    = !empty($s['submitted_at'])  ? date('Y-m-d H:i:s', strtotime($s['submitted_at']))  : '';
    $completedAt    = !empty($s['completed_at'])  ? date('Y-m-d H:i:s', strtotime($s['completed_at']))  : '';

    $fixedCols = [
        $s['id']        ?? '',
        $formName,
        $submitterName,
        $submitterEmail,
        $status,
        $submittedAt,
        $completedAt,
    ];

    $formDataCols = [];
    $data = $parsedFormData[$s['id']] ?? [];
    foreach ($allFieldKeys as $key) {
        $formDataCols[] = isset($data[$key]) ? csvFormDataValue($data[$key]) : '';
    }

    fputcsv($out, array_merge($fixedCols, $formDataCols));
}

fclose($out);
exit;
