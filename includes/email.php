<?php
/**
 * email.php — PHPMailer wrapper for Aurora Form Workflow
 *
 * Provides three functions used by workflow.php:
 *   sendApprovalRequestEmail()  — asks an approver to act on a submission
 *   sendNotificationEmail()     — informs a recipient (no action required)
 *   sendSubmissionOutcomeEmail() — tells the submitter the final result
 *
 * All functions return true on success, false on failure.
 * Failures are written to PHP's error_log — check SiteGround's error log.
 *
 * Requires: config.php (SMTP_*, MAIL_FROM, MAIL_FROM_NAME, APP_URL)
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/phpmailer/Exception.php';
require_once __DIR__ . '/phpmailer/PHPMailer.php';
require_once __DIR__ . '/phpmailer/SMTP.php';

// ─────────────────────────────────────────────────────────────
// INTERNAL: create and configure a PHPMailer instance
// ─────────────────────────────────────────────────────────────

function _createMailer(): PHPMailer
{
    $mail = new PHPMailer(true); // true = throw exceptions on error

    $mail->isSMTP();
    $mail->Host       = SMTP_HOST;
    $mail->SMTPAuth   = true;
    $mail->Username   = SMTP_USER;
    $mail->Password   = SMTP_PASS;
    $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
    $mail->Port       = SMTP_PORT;
    $mail->CharSet    = 'UTF-8';

    $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);

    return $mail;
}

// ─────────────────────────────────────────────────────────────
// INTERNAL: normalise submission form_data into a PHP array
// The column is TEXT in Supabase and we store JSON, so a
// JSON-decoded associative array is what callers expect.
// ─────────────────────────────────────────────────────────────

function _normaliseFormData($raw): array
{
    if (is_array($raw)) return $raw;
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return $decoded;
    }
    return [];
}

// ─────────────────────────────────────────────────────────────
// INTERNAL: render the full submitted form as an HTML section
// Shows every question/answer pair with no truncation.
// Returns empty string when form_data is empty so callers can
// concatenate without extra branching.
// ─────────────────────────────────────────────────────────────

function _formDataSection($formData, string $heading = 'Form Submission'): string
{
    $data = _normaliseFormData($formData);
    if (empty($data)) return '';

    $rows = '';
    foreach ($data as $key => $val) {
        // ── File upload fields ────────────────────────────────────
        if (is_array($val) && isset($val['type']) && $val['type'] === 'files') {
            $files = $val['files'] ?? [];
            if (empty($files)) {
                $valSafe = '<span style="color:#9ca3af;">(no files)</span>';
            } else {
                $links = '';
                foreach ($files as $f) {
                    $links .= '<a href="' . htmlspecialchars($f['url'] ?? '#')
                           . '" style="color:#1e3a5f;display:block;">'
                           . htmlspecialchars($f['name'] ?? 'File') . '</a>';
                }
                $valSafe = $links;
            }
            $rows .= '<tr>
              <td style="font-size:12px;color:#6b7280;padding:8px 14px 8px 0;white-space:nowrap;vertical-align:top;border-bottom:1px solid #f1f5f9;font-weight:600;">'
                  . htmlspecialchars((string)$key) .
              '</td>
              <td style="font-size:13px;color:#111827;padding:8px 0;vertical-align:top;border-bottom:1px solid #f1f5f9;">'
                  . $valSafe .
              '</td>
            </tr>';
            continue;
        }

        // Flatten array values (grid questions already flattened upstream, but be defensive)
        if (is_array($val)) {
            $val = implode(', ', array_map(
                fn($v) => is_array($v) ? implode(', ', $v) : (string)$v,
                $val
            ));
        }
        $valStr = (string)$val;
        // Preserve line breaks for long text answers
        $valSafe = nl2br(htmlspecialchars($valStr));
        if ($valSafe === '') {
            $valSafe = '<span style="color:#9ca3af;">(blank)</span>';
        }
        $rows .= '<tr>
          <td style="font-size:12px;color:#6b7280;padding:8px 14px 8px 0;white-space:nowrap;vertical-align:top;border-bottom:1px solid #f1f5f9;font-weight:600;">'
              . htmlspecialchars((string)$key) .
          '</td>
          <td style="font-size:13px;color:#111827;padding:8px 0;vertical-align:top;border-bottom:1px solid #f1f5f9;">'
              . $valSafe .
          '</td>
        </tr>';
    }

    return '
      <p style="margin:0 0 8px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;">' . htmlspecialchars($heading) . '</p>
      <table cellpadding="0" cellspacing="0" style="width:100%;background:#ffffff;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:24px;">
        <tr><td style="padding:8px 16px;">
          <table cellpadding="0" cellspacing="0" style="width:100%;">' . $rows . '</table>
        </td></tr>
      </table>';
}

// ─────────────────────────────────────────────────────────────
// INTERNAL: render a plain-text version of the form submission
// Used for the AltBody of emails so recipients on text-only
// clients still see the form contents.
// ─────────────────────────────────────────────────────────────

function _formDataPlain($formData): string
{
    $data = _normaliseFormData($formData);
    if (empty($data)) return '';
    $lines = ['', '— Form submission —'];
    foreach ($data as $key => $val) {
        // File upload fields: render as "Name: URL" lines
        if (is_array($val) && isset($val['type']) && $val['type'] === 'files') {
            $files = $val['files'] ?? [];
            if (empty($files)) {
                $lines[] = $key . ': (no files)';
            } else {
                foreach ($files as $f) {
                    $lines[] = $key . ': ' . ($f['name'] ?? 'File') . ' — ' . ($f['url'] ?? '');
                }
            }
            continue;
        }
        if (is_array($val)) {
            $val = implode(', ', array_map(
                fn($v) => is_array($v) ? implode(', ', $v) : (string)$v,
                $val
            ));
        }
        $lines[] = $key . ': ' . (string)$val;
    }
    return implode("\n", $lines) . "\n";
}

// ─────────────────────────────────────────────────────────────
// INTERNAL: wrap HTML body in the Aurora email shell
// ─────────────────────────────────────────────────────────────

function _emailShell(string $bodyContent): string
{
    return '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Aurora Form Workflow</title>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 0;">
    <tr>
      <td align="center">
        <table width="600" cellpadding="0" cellspacing="0" style="background:#ffffff;border-radius:8px;overflow:hidden;box-shadow:0 1px 3px rgba(0,0,0,.1);">

          <!-- Header -->
          <tr>
            <td style="background:#1e3a5f;padding:24px 32px;">
              <p style="margin:0;color:#ffffff;font-size:18px;font-weight:bold;">Aurora Early Education</p>
              <p style="margin:4px 0 0;color:#93c5fd;font-size:13px;">Form Workflow</p>
            </td>
          </tr>

          <!-- Body -->
          <tr>
            <td style="padding:32px;">
              ' . $bodyContent . '
            </td>
          </tr>

          <!-- Footer -->
          <tr>
            <td style="background:#f9fafb;padding:16px 32px;border-top:1px solid #e5e7eb;">
              <p style="margin:0;font-size:12px;color:#9ca3af;">
                This email was sent by the Aurora Form Workflow system.<br>
                Do not reply to this email — use the links above to respond.
              </p>
            </td>
          </tr>

        </table>
      </td>
    </tr>
  </table>
</body>
</html>';
}

// ─────────────────────────────────────────────────────────────
// PUBLIC: Approval request email
// Sent to each approver when a stage opens.
//
// $approver       — users row for the person receiving this email
// $submission     — submissions row
// $stage          — form_stages row
// $form           — forms row
// $approveToken   — plain token string (action = 'approve')
// $rejectToken    — plain token string (action = 'reject')
// ─────────────────────────────────────────────────────────────

function sendApprovalRequestEmail(
    array  $approver,
    array  $submission,
    array  $stage,
    ?array $form,
    string $approveToken,
    string $rejectToken
): bool {
    $approveUrl = APP_URL . '/approve.php?token=' . urlencode($approveToken);
    $rejectUrl  = APP_URL . '/approve.php?token=' . urlencode($rejectToken);
    $statusUrl  = APP_URL . '/status.php?id='    . urlencode($submission['id']);

    $formName     = htmlspecialchars($form['title'] ?? $form['name'] ?? 'Form'           ?? 'Form');
    $stageName    = htmlspecialchars($stage['name']          ?? 'Stage');
    $submitterEmail = htmlspecialchars($submission['submitter_email'] ?? 'Unknown submitter');
    $submittedAt  = date('j F Y, g:i a', strtotime($submission['submitted_at']));
    $approverName = htmlspecialchars($approver['display_name'] ?? $approver['email']);
    $expiryHours  = TOKEN_EXPIRY_HOURS;

    $formDataHtml  = _formDataSection($submission['form_data'] ?? [], 'Form Submission');
    $formDataPlain = _formDataPlain($submission['form_data'] ?? []);

    $body = _emailShell('
      <p style="margin:0 0 8px;font-size:16px;font-weight:bold;color:#111827;">Approval Required</p>
      <p style="margin:0 0 24px;font-size:14px;color:#374151;">Hi ' . $approverName . ', your approval is needed for the following submission.</p>

      <!-- Submission meta -->
      <table cellpadding="0" cellspacing="0" style="width:100%;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:24px;">
        <tr>
          <td style="padding:16px;">
            <p style="margin:0 0 4px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;">Form</p>
            <p style="margin:0 0 16px;font-size:15px;color:#111827;font-weight:bold;">' . $formName . '</p>

            <p style="margin:0 0 4px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;">Stage</p>
            <p style="margin:0 0 16px;font-size:14px;color:#374151;">' . $stageName . '</p>

            <p style="margin:0 0 4px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;">Submitted by</p>
            <p style="margin:0 0 16px;font-size:14px;color:#374151;">' . $submitterEmail . '</p>

            <p style="margin:0 0 4px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;">Submitted at</p>
            <p style="margin:0;font-size:14px;color:#374151;">' . $submittedAt . '</p>
          </td>
        </tr>
      </table>

      ' . $formDataHtml . '

      <!-- Action buttons -->
      <p style="margin:0 0 12px;font-size:14px;color:#374151;">Click a button below to record your decision. You can add comments on the next page.</p>
      <table cellpadding="0" cellspacing="0">
        <tr>
          <td style="padding-right:12px;">
            <a href="' . $approveUrl . '" style="display:inline-block;background:#16a34a;color:#ffffff;font-size:14px;font-weight:bold;padding:12px 24px;border-radius:6px;text-decoration:none;">✓ Approve</a>
          </td>
          <td>
            <a href="' . $rejectUrl . '" style="display:inline-block;background:#dc2626;color:#ffffff;font-size:14px;font-weight:bold;padding:12px 24px;border-radius:6px;text-decoration:none;">✗ Reject</a>
          </td>
        </tr>
      </table>

      <p style="margin:24px 0 0;font-size:12px;color:#9ca3af;">
        These links expire in ' . $expiryHours . ' hours.<br>
        <a href="' . $statusUrl . '" style="color:#1e3a5f;">View the full submission</a>
      </p>
    ');

    try {
        $mail = _createMailer();
        $mail->addAddress($approver['email'], $approver['display_name'] ?? '');
        $mail->Subject = '[Action Required] ' . $formName . ' — ' . $stageName;
        $mail->isHTML(true);
        $mail->Body    = $body;
        $mail->AltBody = "Approval required for: $formName ($stageName)\n\n"
                       . "Submitted by: $submitterEmail on $submittedAt\n"
                       . $formDataPlain . "\n"
                       . "Approve: $approveUrl\n"
                       . "Reject:  $rejectUrl\n\n"
                       . "Links expire in {$expiryHours} hours.";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('[Aurora Email] sendApprovalRequestEmail failed for ' . $approver['email'] . ': ' . $e->getMessage());
        return false;
    }
}

// ─────────────────────────────────────────────────────────────
// PUBLIC: Notification email (no action required)
// Sent when stage_type = 'notification'.
// ─────────────────────────────────────────────────────────────

function sendNotificationEmail(
    array  $recipient,
    array  $submission,
    array  $stage,
    ?array $form
): bool {
    $statusUrl    = APP_URL . '/status.php?id=' . urlencode($submission['id']);
    $formName     = htmlspecialchars($form['title'] ?? $form['name'] ?? 'Form'              ?? 'Form');
    $stageName    = htmlspecialchars($stage['name']             ?? 'Stage');
    $submitterEmail = htmlspecialchars($submission['submitter_email'] ?? 'Unknown');
    $submittedAt  = date('j F Y, g:i a', strtotime($submission['submitted_at']));
    $recipientName = htmlspecialchars($recipient['display_name'] ?? $recipient['email']);

    $formDataHtml  = _formDataSection($submission['form_data'] ?? [], 'Form Submission');
    $formDataPlain = _formDataPlain($submission['form_data'] ?? []);

    $body = _emailShell('
      <p style="margin:0 0 8px;font-size:16px;font-weight:bold;color:#111827;">Notification</p>
      <p style="margin:0 0 24px;font-size:14px;color:#374151;">Hi ' . $recipientName . ', this is an automated notification — no action is required from you.</p>

      <table cellpadding="0" cellspacing="0" style="width:100%;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:24px;">
        <tr>
          <td style="padding:16px;">
            <p style="margin:0 0 4px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;">Form</p>
            <p style="margin:0 0 16px;font-size:15px;color:#111827;font-weight:bold;">' . $formName . '</p>

            <p style="margin:0 0 4px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;">Stage</p>
            <p style="margin:0 0 16px;font-size:14px;color:#374151;">' . $stageName . '</p>

            <p style="margin:0 0 4px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;">Submitted by</p>
            <p style="margin:0;font-size:14px;color:#374151;">' . $submitterEmail . ' on ' . $submittedAt . '</p>
          </td>
        </tr>
      </table>

      ' . $formDataHtml . '

      <a href="' . $statusUrl . '" style="display:inline-block;background:#1e3a5f;color:#ffffff;font-size:14px;font-weight:bold;padding:12px 24px;border-radius:6px;text-decoration:none;">View Submission</a>
    ');

    try {
        $mail = _createMailer();
        $mail->addAddress($recipient['email'], $recipient['display_name'] ?? '');
        $mail->Subject = '[FYI] ' . $formName . ' — ' . $stageName;
        $mail->isHTML(true);
        $mail->Body    = $body;
        $mail->AltBody = "Notification: $formName ($stageName)\nSubmitted by $submitterEmail on $submittedAt\n"
                       . $formDataPlain . "\nView: $statusUrl";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('[Aurora Email] sendNotificationEmail failed for ' . $recipient['email'] . ': ' . $e->getMessage());
        return false;
    }
}

// ─────────────────────────────────────────────────────────────
// PUBLIC: Outcome email to the submitter
// Sent when the submission is fully approved or rejected.
//
// $outcome — 'approved' | 'rejected'
// ─────────────────────────────────────────────────────────────

function sendSubmissionOutcomeEmail(
    array  $submission,
    ?array $form,
    string $outcome
): bool {
    $toEmail   = $submission['submitter_email'] ?? null;
    if (!$toEmail) return false;

    $statusUrl    = APP_URL . '/status.php?id=' . urlencode($submission['id']);
    $formName     = htmlspecialchars($form['title'] ?? $form['name'] ?? 'Form' ?? 'Form');
    $completedAt  = date('j F Y, g:i a');

    $isApproved   = ($outcome === 'approved');
    $outcomeLabel = $isApproved ? 'Approved' : 'Rejected';
    $outcomeColor = $isApproved ? '#16a34a'  : '#dc2626';
    $outcomeIcon  = $isApproved ? '✓'        : '✗';
    $outcomeMsg   = $isApproved
        ? 'Your submission has been fully approved. No further action is required.'
        : 'Your submission has been rejected by one or more approvers. Please check the submission for comments.';

    $formDataHtml  = _formDataSection($submission['form_data'] ?? [], 'Your Submission');
    $formDataPlain = _formDataPlain($submission['form_data'] ?? []);

    $body = _emailShell('
      <p style="margin:0 0 24px;">
        <span style="display:inline-block;background:' . $outcomeColor . ';color:#fff;font-size:13px;font-weight:bold;padding:4px 12px;border-radius:9999px;">' . $outcomeIcon . ' ' . $outcomeLabel . '</span>
      </p>
      <p style="margin:0 0 8px;font-size:16px;font-weight:bold;color:#111827;">Your submission has been ' . strtolower($outcomeLabel) . '</p>
      <p style="margin:0 0 24px;font-size:14px;color:#374151;">' . $outcomeMsg . '</p>

      <table cellpadding="0" cellspacing="0" style="width:100%;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:24px;">
        <tr>
          <td style="padding:16px;">
            <p style="margin:0 0 4px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;">Form</p>
            <p style="margin:0 0 16px;font-size:15px;color:#111827;font-weight:bold;">' . $formName . '</p>

            <p style="margin:0 0 4px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;">Outcome</p>
            <p style="margin:0;font-size:14px;color:' . $outcomeColor . ';font-weight:bold;">' . $outcomeLabel . ' — ' . $completedAt . '</p>
          </td>
        </tr>
      </table>

      ' . $formDataHtml . '

      <a href="' . $statusUrl . '" style="display:inline-block;background:#1e3a5f;color:#ffffff;font-size:14px;font-weight:bold;padding:12px 24px;border-radius:6px;text-decoration:none;">View Submission Details</a>
    ');

    try {
        $mail = _createMailer();
        $mail->addAddress($toEmail);
        $mail->Subject = '[Aurora Form Workflow] Your submission has been ' . strtolower($outcomeLabel) . ' — ' . $formName;
        $mail->isHTML(true);
        $mail->Body    = $body;
        $mail->AltBody = "Your $formName submission has been $outcomeLabel.\n$outcomeMsg\n"
                       . $formDataPlain . "\nView: $statusUrl";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('[Aurora Email] sendSubmissionOutcomeEmail failed for ' . $toEmail . ': ' . $e->getMessage());
        return false;
    }
}

// ─────────────────────────────────────────────────────────────
// PUBLIC: Action request email
// Sent when stage_type = 'action'.
// The recipient clicks a single "Mark as Done" link — no approve/reject.
//
// $recipient      — users row (or stub with email key for raw-email recipients)
// $submission     — submissions row
// $stage          — form_stages row
// $form           — forms row
// $completeToken  — plain token string (action = 'complete')
// ─────────────────────────────────────────────────────────────

function sendActionRequestEmail(
    array  $recipient,
    array  $submission,
    array  $stage,
    ?array $form,
    string $completeToken
): bool {
    $actionUrl      = APP_URL . '/action.php?token=' . urlencode($completeToken);
    $statusUrl      = APP_URL . '/status.php?id='   . urlencode($submission['id']);
    $formName       = htmlspecialchars($form['title'] ?? $form['name'] ?? 'Form');
    $stageName      = htmlspecialchars($stage['stage_name'] ?? $stage['name'] ?? 'Stage');
    $submitterEmail = htmlspecialchars($submission['submitter_email'] ?? 'Unknown');
    $submittedAt    = date('j F Y, g:i a', strtotime($submission['submitted_at']));
    $recipientName  = htmlspecialchars($recipient['display_name'] ?? $recipient['email']);
    $stageDesc      = htmlspecialchars($stage['description'] ?? '');
    $expiryHours    = TOKEN_EXPIRY_HOURS;

    $formDataHtml  = _formDataSection($submission['form_data'] ?? [], 'Form Submission');
    $formDataPlain = _formDataPlain($submission['form_data'] ?? []);

    $body = _emailShell('
      <p style="margin:0 0 8px;font-size:16px;font-weight:bold;color:#111827;">Action Required</p>
      <p style="margin:0 0 24px;font-size:14px;color:#374151;">Hi ' . $recipientName . ', please complete the following action to allow the workflow to continue.</p>

      <table cellpadding="0" cellspacing="0" style="width:100%;background:#fff7ed;border:1px solid #fed7aa;border-radius:6px;margin-bottom:24px;">
        <tr>
          <td style="padding:16px;">
            <p style="margin:0 0 4px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;">Form</p>
            <p style="margin:0 0 16px;font-size:15px;color:#111827;font-weight:bold;">' . $formName . '</p>

            <p style="margin:0 0 4px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;">Action Stage</p>
            <p style="margin:0 0 16px;font-size:14px;color:#374151;">' . $stageName . '</p>

            ' . ($stageDesc ? '
            <p style="margin:0 0 4px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;">Instructions</p>
            <p style="margin:0 0 16px;font-size:14px;color:#374151;">' . $stageDesc . '</p>
            ' : '') . '

            <p style="margin:0 0 4px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;">Submitted by</p>
            <p style="margin:0;font-size:14px;color:#374151;">' . $submitterEmail . ' on ' . $submittedAt . '</p>
          </td>
        </tr>
      </table>

      ' . $formDataHtml . '

      <p style="margin:0 0 16px;font-size:14px;color:#374151;">Once you have completed the task, click the button below to notify the system and continue the workflow.</p>
      <a href="' . $actionUrl . '" style="display:inline-block;background:#ea580c;color:#ffffff;font-size:14px;font-weight:bold;padding:12px 28px;border-radius:6px;text-decoration:none;">✓ Mark as Done</a>

      <p style="margin:24px 0 0;font-size:12px;color:#9ca3af;">
        This link expires in ' . $expiryHours . ' hours.<br>
        <a href="' . $statusUrl . '" style="color:#1e3a5f;">View the full submission</a>
      </p>
    ');

    try {
        $mail = _createMailer();
        $mail->addAddress($recipient['email'], $recipient['display_name'] ?? '');
        $mail->Subject = '[Action Required] ' . $formName . ' — ' . $stageName;
        $mail->isHTML(true);
        $mail->Body    = $body;
        $mail->AltBody = "Action required: $formName ($stageName)\n\n"
                       . "Submitted by: $submitterEmail on $submittedAt\n"
                       . $formDataPlain . "\n"
                       . "Mark as done: $actionUrl\n\n"
                       . "This link expires in {$expiryHours} hours.";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('[Aurora Email] sendActionRequestEmail failed for ' . $recipient['email'] . ': ' . $e->getMessage());
        return false;
    }
}

// ─────────────────────────────────────────────────────────────
// PUBLIC: Reminder email
// Re-sent to pending approvers every reminder_days days.
//
// $approver       — users row
// $submission     — submissions row
// $stage          — form_stages row (may have reminder_message set)
// $form           — forms row
// $approveToken   — plain token string (action = 'approve')
// $rejectToken    — plain token string (action = 'reject')
// $reminderNumber — which reminder this is (1, 2, 3 …)
// $daysOverdue    — how many days the stage has been pending (0 = don't show banner)
// ─────────────────────────────────────────────────────────────

function sendReminderEmail(
    array  $approver,
    array  $submission,
    array  $stage,
    ?array $form,
    string $approveToken,
    string $rejectToken,
    int    $reminderNumber = 1,
    int    $daysOverdue    = 0
): bool {
    $approveUrl   = APP_URL . '/approve.php?token=' . urlencode($approveToken);
    $rejectUrl    = APP_URL . '/approve.php?token=' . urlencode($rejectToken);
    $statusUrl    = APP_URL . '/status.php?id='    . urlencode($submission['id']);
    $formName     = htmlspecialchars($form['title'] ?? $form['name'] ?? 'Form');
    $stageName    = htmlspecialchars($stage['stage_name'] ?? $stage['name'] ?? 'Stage');
    $submitterEmail = htmlspecialchars($submission['submitter_email'] ?? 'Unknown');
    $submittedAt  = date('j F Y, g:i a', strtotime($submission['submitted_at']));
    $approverName = htmlspecialchars($approver['display_name'] ?? $approver['email']);
    $ordinal      = $reminderNumber === 1 ? '1st' : ($reminderNumber === 2 ? '2nd' : ($reminderNumber === 3 ? '3rd' : $reminderNumber . 'th'));

    // Custom message set on the stage (optional)
    $customMessage = trim($stage['reminder_message'] ?? '');

    $formDataHtml  = _formDataSection($submission['form_data'] ?? [], 'Form Submission');
    $formDataPlain = _formDataPlain($submission['form_data'] ?? []);

    // Amber overdue banner — shown only when days overdue is known
    $overdueBanner = '';
    if ($daysOverdue > 0) {
        $overdueBanner = '
      <table cellpadding="0" cellspacing="0" style="width:100%;background:#fffbeb;border:1px solid #fcd34d;border-radius:6px;margin-bottom:16px;">
        <tr>
          <td style="padding:12px 16px;">
            <p style="margin:0;font-size:13px;color:#92400e;">⚠&nbsp; This approval has been pending for <strong>' . $daysOverdue . ' ' . ($daysOverdue === 1 ? 'day' : 'days') . '</strong>. Please action it as soon as possible.</p>
          </td>
        </tr>
      </table>';
    }

    // Custom stage message block — shown when set in form-stages config
    $customMessageBlock = '';
    if ($customMessage !== '') {
        $customMessageBlock = '
      <table cellpadding="0" cellspacing="0" style="width:100%;background:#eef2ff;border-left:4px solid #6366f1;border-radius:0 6px 6px 0;margin-bottom:24px;">
        <tr>
          <td style="padding:12px 16px;">
            <p style="margin:0;font-size:13px;color:#3730a3;">' . nl2br(htmlspecialchars($customMessage)) . '</p>
          </td>
        </tr>
      </table>';
    }

    $body = _emailShell('
      <p style="margin:0 0 8px;">
        <span style="display:inline-block;background:#f59e0b;color:#ffffff;font-size:12px;font-weight:bold;padding:3px 10px;border-radius:9999px;">⏰ Reminder ' . $ordinal . '</span>
      </p>
      <p style="margin:8px 0 8px;font-size:16px;font-weight:bold;color:#111827;">Action Still Required</p>
      <p style="margin:0 0 24px;font-size:14px;color:#374151;">Hi ' . $approverName . ', this is a reminder that the following submission is still awaiting your approval.</p>

      ' . $overdueBanner . '

      ' . $customMessageBlock . '

      <table cellpadding="0" cellspacing="0" style="width:100%;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:24px;">
        <tr>
          <td style="padding:16px;">
            <p style="margin:0 0 4px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;">Form</p>
            <p style="margin:0 0 16px;font-size:15px;color:#111827;font-weight:bold;">' . $formName . '</p>

            <p style="margin:0 0 4px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;">Stage</p>
            <p style="margin:0 0 16px;font-size:14px;color:#374151;">' . $stageName . '</p>

            <p style="margin:0 0 4px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;">Submitted by</p>
            <p style="margin:0;font-size:14px;color:#374151;">' . $submitterEmail . ' on ' . $submittedAt . '</p>
          </td>
        </tr>
      </table>

      ' . $formDataHtml . '

      <table cellpadding="0" cellspacing="0">
        <tr>
          <td style="padding-right:12px;">
            <a href="' . $approveUrl . '" style="display:inline-block;background:#16a34a;color:#ffffff;font-size:14px;font-weight:bold;padding:12px 24px;border-radius:6px;text-decoration:none;">✓ Approve</a>
          </td>
          <td>
            <a href="' . $rejectUrl . '" style="display:inline-block;background:#dc2626;color:#ffffff;font-size:14px;font-weight:bold;padding:12px 24px;border-radius:6px;text-decoration:none;">✗ Reject</a>
          </td>
        </tr>
      </table>

      <p style="margin:24px 0 0;font-size:12px;color:#9ca3af;">
        <a href="' . $statusUrl . '" style="color:#1e3a5f;">View the full submission</a>
      </p>
    ');

    try {
        $mail = _createMailer();
        $mail->addAddress($approver['email'], $approver['display_name'] ?? '');
        $mail->Subject = '[Reminder ' . $ordinal . '] ' . $formName . ' — awaiting your approval';
        $mail->isHTML(true);
        $mail->Body    = $body;
        $mail->AltBody = "Reminder #{$reminderNumber}: $formName ($stageName) still awaits your approval."
                       . ($daysOverdue > 0 ? " This has been pending for {$daysOverdue} days." : '') . "\n"
                       . ($customMessage  ? "\nNote: $customMessage\n" : '')
                       . $formDataPlain . "\nApprove: $approveUrl\nReject: $rejectUrl";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('[Aurora Email] sendReminderEmail failed for ' . $approver['email'] . ': ' . $e->getMessage());
        return false;
    }
}

// ─────────────────────────────────────────────────────────────
// PUBLIC: Escalation email
// Sent to the designated escalation contact for each pending
// approver (or to all admins as fallback).
//
// $escalationContact  — users row for the person receiving this email
// $submission         — submissions row
// $stage              — form_stages row
// $form               — forms row
// $daysElapsed        — how many days the stage has been pending
// $pendingApprovers   — array of ['name' => ..., 'email' => ...] for each overdue approver
// $isFallback         — true when no escalation contact was set → sent to all admins
// ─────────────────────────────────────────────────────────────

function sendEscalationEmail(
    array  $escalationContact,
    array  $submission,
    array  $stage,
    ?array $form,
    int    $daysElapsed,
    array  $pendingApprovers = [],
    bool   $isFallback       = false
): bool {
    $contactEmail   = $escalationContact['email'] ?? null;
    if (!$contactEmail) return false;

    $contactName    = $escalationContact['display_name'] ?? $contactEmail;
    $statusUrl      = APP_URL . '/status.php?id=' . urlencode($submission['id']);
    $formName       = htmlspecialchars($form['title'] ?? $form['name'] ?? 'Form');
    $stageName      = htmlspecialchars($stage['stage_name'] ?? $stage['name'] ?? 'Stage');
    $submitterEmail = htmlspecialchars($submission['submitter_email'] ?? 'Unknown');
    $submittedAt    = date('j F Y', strtotime($submission['submitted_at']));
    $contactNameSafe = htmlspecialchars($contactName);

    // Build a human-readable list of overdue approvers
    $approverNames = array_map(fn($a) => $a['name'] ?? $a['email'] ?? '?', $pendingApprovers);
    $approverListHtml = '';
    foreach ($pendingApprovers as $pa) {
        $name  = htmlspecialchars($pa['name']  ?? $pa['email'] ?? '?');
        $email = htmlspecialchars($pa['email'] ?? '');
        $approverListHtml .= '<li style="font-size:13px;color:#374151;margin-bottom:4px;">'
            . $name . ($email && $email !== $name ? ' <span style="color:#9ca3af;">(' . $email . ')</span>' : '')
            . '</li>';
    }
    $approverSection = $approverListHtml
        ? '<p style="margin:16px 0 6px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;">Pending Approver(s)</p>
           <ul style="margin:0;padding-left:16px;">' . $approverListHtml . '</ul>'
        : '';

    // Contextual intro depending on fallback vs assigned contact
    if ($isFallback) {
        $introLine = 'A submission has been waiting for approval for <strong>' . $daysElapsed . ' days</strong>. '
                   . 'No escalation contact has been assigned for the pending approver(s), so this notice is being sent to all administrators.';
    } else {
        $approverNamesStr = count($approverNames) === 1
            ? htmlspecialchars($approverNames[0])
            : implode(', ', array_map('htmlspecialchars', array_slice($approverNames, 0, -1)))
              . ' and ' . htmlspecialchars(end($approverNames));
        $introLine = 'You are listed as the escalation contact for <strong>' . $approverNamesStr . '</strong>. '
                   . 'Their approval has been pending for <strong>' . $daysElapsed . ' days</strong> and requires urgent follow-up.';
    }

    $formDataHtml  = _formDataSection($submission['form_data'] ?? [], 'Form Submission');
    $formDataPlain = _formDataPlain($submission['form_data'] ?? []);

    $body = _emailShell('
      <p style="margin:0 0 8px;">
        <span style="display:inline-block;background:#dc2626;color:#ffffff;font-size:12px;font-weight:bold;padding:3px 10px;border-radius:9999px;">🚨 Escalation</span>
      </p>
      <p style="margin:8px 0 8px;font-size:16px;font-weight:bold;color:#111827;">Approval Overdue</p>
      <p style="margin:0 0 24px;font-size:14px;color:#374151;">Hi ' . $contactNameSafe . ', ' . $introLine . '</p>

      <table cellpadding="0" cellspacing="0" style="width:100%;background:#fef2f2;border:1px solid #fecaca;border-radius:6px;margin-bottom:24px;">
        <tr>
          <td style="padding:16px;">
            <p style="margin:0 0 4px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;">Form</p>
            <p style="margin:0 0 16px;font-size:15px;color:#111827;font-weight:bold;">' . $formName . '</p>

            <p style="margin:0 0 4px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;">Stuck at Stage</p>
            <p style="margin:0 0 16px;font-size:14px;color:#374151;">' . $stageName . '</p>

            <p style="margin:0 0 4px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;">Submitted by</p>
            <p style="margin:0 0 16px;font-size:14px;color:#374151;">' . $submitterEmail . ' on ' . $submittedAt . '</p>

            <p style="margin:0 0 4px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;">Days Pending</p>
            <p style="margin:0 0 0;font-size:14px;color:#dc2626;font-weight:bold;">' . $daysElapsed . ' days</p>
            ' . $approverSection . '
          </td>
        </tr>
      </table>

      ' . $formDataHtml . '

      <p style="margin:0 0 16px;font-size:14px;color:#374151;">Please follow up with the approver(s) to ensure this submission is actioned promptly.</p>

      <a href="' . $statusUrl . '" style="display:inline-block;background:#1e3a5f;color:#ffffff;font-size:14px;font-weight:bold;padding:12px 24px;border-radius:6px;text-decoration:none;">View Submission</a>
    ');

    $approverNamesPlain = implode(', ', $approverNames);
    $fallbackNote       = $isFallback ? ' (no escalation contact assigned — fallback to admins)' : '';

    try {
        $mail = _createMailer();
        $mail->addAddress($contactEmail, $contactName);
        $mail->Subject = '[Escalation] ' . $formName . ' — ' . $stageName . ' overdue by ' . $daysElapsed . ' days';
        $mail->isHTML(true);
        $mail->Body    = $body;
        $mail->AltBody = "ESCALATION{$fallbackNote}: $formName ($stageName) has been pending for {$daysElapsed} days.\n"
                       . "Pending approver(s): $approverNamesPlain\n"
                       . "Submitted by: $submitterEmail\n"
                       . $formDataPlain . "\nView: $statusUrl";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('[Aurora Email] sendEscalationEmail failed for ' . $contactEmail . ': ' . $e->getMessage());
        return false;
    }
}

// ─────────────────────────────────────────────────────────────
// PUBLIC: Signature request email
// Sent to the recipient of a signature stage.
//
// $recipient  — users row (or email+name array for external)
// $submission — submissions row
// $stage      — form_stages row
// $form       — forms row
// $signToken  — plain token string (action = 'sign')
// ─────────────────────────────────────────────────────────────

function sendSignatureRequestEmail(
    array  $recipient,
    array  $submission,
    array  $stage,
    ?array $form,
    string $signToken
): bool {
    $signUrl        = APP_URL . '/sign.php?token=' . urlencode($signToken);
    $statusUrl      = APP_URL . '/status.php?id=' . urlencode($submission['id']);
    $formName       = htmlspecialchars($form['title'] ?? $form['name'] ?? 'Form');
    $stageName      = htmlspecialchars($stage['stage_name'] ?? $stage['name'] ?? 'Stage');
    $submitterEmail = htmlspecialchars($submission['submitter_email'] ?? 'Unknown');
    $submittedAt    = date('j F Y', strtotime($submission['submitted_at']));
    $recipientName  = htmlspecialchars($recipient['display_name'] ?? $recipient['email']);

    $formDataHtml  = _formDataSection($submission['form_data'] ?? [], 'Form Submission');
    $formDataPlain = _formDataPlain($submission['form_data'] ?? []);

    $body = _emailShell('
      <p style="margin:0 0 8px;font-size:16px;font-weight:bold;color:#111827;">Signature Required</p>
      <p style="margin:0 0 24px;font-size:14px;color:#374151;">Hi ' . $recipientName . ', your signature is required on the following submission.</p>

      <table cellpadding="0" cellspacing="0" style="width:100%;background:#f5f3ff;border:1px solid #ddd6fe;border-radius:6px;margin-bottom:24px;">
        <tr>
          <td style="padding:16px;">
            <p style="margin:0 0 4px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;">Form</p>
            <p style="margin:0 0 16px;font-size:15px;color:#111827;font-weight:bold;">' . $formName . '</p>

            <p style="margin:0 0 4px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;">Stage</p>
            <p style="margin:0 0 16px;font-size:14px;color:#374151;">' . $stageName . '</p>

            <p style="margin:0 0 4px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;">Submitted by</p>
            <p style="margin:0;font-size:14px;color:#374151;">' . $submitterEmail . ' on ' . $submittedAt . '</p>
          </td>
        </tr>
      </table>

      ' . $formDataHtml . '

      <a href="' . $signUrl . '" style="display:inline-block;background:#7c3aed;color:#ffffff;font-size:14px;font-weight:bold;padding:12px 28px;border-radius:6px;text-decoration:none;">✍ Sign Now</a>

      <p style="margin:24px 0 0;font-size:12px;color:#9ca3af;">
        This link is unique to you and can only be used once.<br>
        <a href="' . $statusUrl . '" style="color:#1e3a5f;">View the full submission</a>
      </p>
    ');

    try {
        $mail = _createMailer();
        $mail->addAddress($recipient['email'], $recipient['display_name'] ?? '');
        $mail->Subject = '[Signature Required] ' . $formName . ' — ' . $stageName;
        $mail->isHTML(true);
        $mail->Body    = $body;
        $mail->AltBody = "Signature required for: $formName ($stageName)\nSubmitted by: $submitterEmail\n"
                       . $formDataPlain . "\nSign here: $signUrl";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('[Aurora Email] sendSignatureRequestEmail failed for ' . $recipient['email'] . ': ' . $e->getMessage());
        return false;
    }
}

// ─────────────────────────────────────────────────────────────
// PUBLIC: Signed PDF email
// Sent to all previous-stage approvers after a signature is captured.
//
// $recipients — array of users rows (people who previously approved)
// $submission — submissions row
// $stage      — form_stages row (the signature stage)
// $form       — forms row
// $pdfPath    — absolute filesystem path to the generated PDF
// $signerEmail — email of the person who signed
// ─────────────────────────────────────────────────────────────

function sendSignedPDFEmail(
    array  $recipients,
    array  $submission,
    array  $stage,
    ?array $form,
    string $pdfPath,
    string $signerEmail
): bool {
    if (empty($recipients)) return true;

    $statusUrl      = APP_URL . '/status.php?id=' . urlencode($submission['id']);
    $formName       = htmlspecialchars($form['title'] ?? $form['name'] ?? 'Form');
    $stageName      = htmlspecialchars($stage['stage_name'] ?? $stage['name'] ?? 'Stage');
    $signerSafe     = htmlspecialchars($signerEmail);
    $signedAt       = date('j F Y, g:i a');

    $body = _emailShell('
      <p style="margin:0 0 8px;font-size:16px;font-weight:bold;color:#111827;">Signed Document</p>
      <p style="margin:0 0 24px;font-size:14px;color:#374151;">The <strong>' . $stageName . '</strong> stage has been signed. A copy of the signed document is attached to this email.</p>

      <table cellpadding="0" cellspacing="0" style="width:100%;background:#f9fafb;border:1px solid #e5e7eb;border-radius:6px;margin-bottom:24px;">
        <tr>
          <td style="padding:16px;">
            <p style="margin:0 0 4px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;">Form</p>
            <p style="margin:0 0 16px;font-size:15px;color:#111827;font-weight:bold;">' . $formName . '</p>

            <p style="margin:0 0 4px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;">Signed by</p>
            <p style="margin:0 0 16px;font-size:14px;color:#374151;">' . $signerSafe . '</p>

            <p style="margin:0 0 4px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;">Signed at</p>
            <p style="margin:0;font-size:14px;color:#374151;">' . $signedAt . '</p>
          </td>
        </tr>
      </table>

      <a href="' . $statusUrl . '" style="display:inline-block;background:#1e3a5f;color:#ffffff;font-size:14px;font-weight:bold;padding:12px 24px;border-radius:6px;text-decoration:none;">View Submission</a>
    ');

    $allSent = true;
    foreach ($recipients as $recipient) {
        try {
            $mail = _createMailer();
            $mail->addAddress($recipient['email'], $recipient['display_name'] ?? '');
            $mail->Subject = '[Signed] ' . $formName . ' — ' . $stageName;
            $mail->isHTML(true);
            $mail->Body    = $body;
            $mail->AltBody = "Signed document for $formName ($stageName)\nSigned by: $signerSafe on $signedAt\nView: $statusUrl";
            if (file_exists($pdfPath)) {
                $mail->addAttachment($pdfPath, basename($pdfPath));
            }
            $mail->send();
        } catch (Exception $e) {
            error_log('[Aurora Email] sendSignedPDFEmail failed for ' . $recipient['email'] . ': ' . $e->getMessage());
            $allSent = false;
        }
    }
    return $allSent;
}
