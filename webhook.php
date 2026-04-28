<?php
/**
 * webhook.php — Google Forms submission receiver
 *
 * Called by the Google Apps Script installed on each Google Form.
 * Validates the incoming request, creates a submission record, and
 * kicks off Stage 1 of the matching approval workflow.
 *
 * Expected POST body (JSON):
 * {
 *   "webhook_secret":  "...",            // must match WEBHOOK_SECRET in config.php
 *   "google_form_id":  "1BxiMVs0...",   // the Google Form's own ID
 *   "submitter_email": "staff@...",      // from getRespondentEmail()
 *   "submitted_at":    "2026-04-16T...", // ISO 8601 timestamp
 *   "form_data": {                       // question title → answer
 *     "Full Name":      "Jane Smith",
 *     "Manager Email":  "manager@...",
 *     ...
 *   }
 * }
 *
 * Responses:
 *   200 OK    — submission accepted and workflow started
 *   400       — malformed or missing required fields
 *   403       — wrong webhook secret
 *   404       — no active form found for this google_form_id
 *   405       — non-POST request
 *   500       — unexpected server error
 *
 * Security: requests are authenticated by WEBHOOK_SECRET only.
 * This endpoint must be reachable without a login session.
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/supabase.php';
require_once __DIR__ . '/includes/workflow.php'; // also loads email.php

// Initialise Supabase with the secret (service role) key so we can write rows
$sb = new Supabase(SUPABASE_SECRET_KEY);

// ── Only accept POST ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

// ── Parse JSON body ───────────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!is_array($body)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Request body must be JSON']);
    exit;
}

// ── Validate webhook secret ───────────────────────────────────────────────────
$incomingSecret = $body['webhook_secret'] ?? '';
if (!hash_equals(WEBHOOK_SECRET, $incomingSecret)) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Forbidden']);
    // Log the attempt (without revealing the secret)
    error_log('[Aurora Webhook] 403 — invalid webhook_secret. IP: ' . ($_SERVER['REMOTE_ADDR'] ?? 'unknown'));
    exit;
}

// ── Validate required fields ──────────────────────────────────────────────────
$googleFormId   = trim($body['google_form_id']  ?? '');
$submitterEmail = trim($body['submitter_email'] ?? '');
$submittedAt    = trim($body['submitted_at']    ?? '');
$formData       = $body['form_data']            ?? [];

if (!$googleFormId || !$submitterEmail || !$submittedAt || !is_array($formData)) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Missing required fields: google_form_id, submitter_email, submitted_at, form_data']);
    exit;
}

// ── Look up the workflow form by Google Form ID ───────────────────────────────
$formRows = $sb->from('forms')
    ->select('*')
    ->eq('google_form_id', $googleFormId)
    ->eq('status', 'active')
    ->execute();

if (!$formRows || !isset($formRows[0])) {
    http_response_code(404);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No active workflow found for this Google Form ID']);
    error_log("[Aurora Webhook] 404 — no form found for google_form_id: $googleFormId");
    exit;
}

$form = $formRows[0];

// ── Load Stage 1 ──────────────────────────────────────────────────────────────
$stageRows = $sb->from('form_stages')
    ->select('*')
    ->eq('form_id', $form['id'])
    ->order('stage_order', true)
    ->limit(1)
    ->execute();

if (!$stageRows || !isset($stageRows[0])) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Workflow has no stages configured']);
    error_log("[Aurora Webhook] No stages for form {$form['id']} ({$form['name']})");
    exit;
}

$firstStage = $stageRows[0];

// ── Try to match submitter to a users record ──────────────────────────────────
// If they have never logged into the system, submitted_by will be NULL.
// The submitter_email column always stores their raw email for notifications.
$submittedBy = null;
$userRows    = $sb->from('users')
    ->select('*')
    ->eq('email', $submitterEmail)
    ->execute();

if ($userRows && isset($userRows[0])) {
    $submittedBy = $userRows[0]['id'];
}

// ── Deduplication check ───────────────────────────────────────────────────────
// If two Apps Script triggers fire for the same form response (a common
// misconfiguration), they will POST the exact same google_form_id +
// submitter_email + submitted_at.  If a submission with those three values
// already exists we return 200 immediately — the workflow was already started
// by the first request, so no action is needed.
$existingRows = $sb->from('submissions')
    ->select('id, status')
    ->eq('form_id',         $form['id'])
    ->eq('submitter_email', $submitterEmail)
    ->eq('submitted_at',    $submittedAt)
    ->limit(1)
    ->execute();

if ($existingRows && isset($existingRows[0])) {
    $existing = $existingRows[0];
    error_log("[Aurora Webhook] Duplicate webhook ignored — submission {$existing['id']} already exists for form {$form['id']} / $submitterEmail / $submittedAt");
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
        'success'       => true,
        'submission_id' => $existing['id'],
        'duplicate'     => true,
        'message'       => 'Duplicate webhook call — submission already recorded',
    ]);
    exit;
}

// ── Create the submission record ──────────────────────────────────────────────
$insertData = [
    'form_id'         => $form['id'],
    'submitted_by'    => $submittedBy,   // null if not in users table — that's fine
    'submitter_email' => $submitterEmail,
    'form_data'       => json_encode($formData),
    'status'          => 'in_progress',
    'submitted_at'    => $submittedAt,
];

$submissionRows = $sb->from('submissions')->insert($insertData);

if (!$submissionRows || !isset($submissionRows[0])) {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Failed to create submission record']);
    error_log('[Aurora Webhook] Failed to insert submission for form ' . $form['id']);
    exit;
}

$submission   = $submissionRows[0];
$submissionId = $submission['id'];

// ── Audit log — submission received ──────────────────────────────────────────
$sb->from('audit_log')->insert([
    'submission_id' => $submissionId,
    'action'        => 'submitted',
    'detail'        => json_encode([
        'form_name'       => $form['title'] ?? $form['name'] ?? '',
        'google_form_id'  => $googleFormId,
        'submitter_email' => $submitterEmail,
        'matched_user_id' => $submittedBy,
    ]),
    'ip_address'    => $_SERVER['REMOTE_ADDR'] ?? null,
]);

// ── Kick off Stage 1 ──────────────────────────────────────────────────────────
// This resolves recipients, sends approval emails, and creates tokens.
// Everything happens synchronously — Apps Script will wait up to 30 seconds.
try {
    wf_kickOffStage($submissionId, $firstStage);
} catch (Throwable $e) {
    // Log but still return 200 — the submission was saved successfully
    error_log('[Aurora Workflow] wf_kickOffStage threw: ' . $e->getMessage());
}

// ── Respond to Apps Script ────────────────────────────────────────────────────
http_response_code(200);
header('Content-Type: application/json');
echo json_encode([
    'success'       => true,
    'submission_id' => $submissionId,
    'form'          => $form['name'],
    'stage'         => $firstStage['name'],
]);
