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

    // Build a readable summary of form data (first 5 fields)
    $formData = $submission['form_data'] ?? [];
    if (is_string($formData)) {
        $formData = json_decode($formData, true) ?? [];
    }
    $summaryRows = '';
    $count = 0;
    foreach ($formData as $key => $val) {
        if ($count >= 5) {
            $summaryRows .= '<tr><td colspan="2" style="font-size:12px;color:#6b7280;padding:4px 0;">…and more fields. <a href="' . $statusUrl . '" style="color:#1e3a5f;">View full submission</a></td></tr>';
            break;
        }
        $summaryRows .= '<tr>
          <td style="font-size:13px;color:#6b7280;padding:4px 12px 4px 0;white-space:nowrap;vertical-align:top;">' . htmlspecialchars($key) . '</td>
          <td style="font-size:13px;color:#111827;padding:4px 0;">' . htmlspecialchars((string)$val) . '</td>
        </tr>';
        $count++;
    }

    $body = _emailShell('
      <p style="margin:0 0 8px;font-size:16px;font-weight:bold;color:#111827;">Approval Required</p>
      <p style="margin:0 0 24px;font-size:14px;color:#374151;">Hi ' . $approverName . ', your approval is needed for the following submission.</p>

      <!-- Submission details -->
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
            <p style="margin:0 0 16px;font-size:14px;color:#374151;">' . $submittedAt . '</p>

            <p style="margin:0 0 8px;font-size:11px;font-weight:bold;color:#6b7280;text-transform:uppercase;letter-spacing:.05em;">Form data</p>
            <table cellpadding="0" cellspacing="0">' . $summaryRows . '</table>
          </td>
        </tr>
      </table>

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
                       . "Submitted by: $submitterEmail on $submittedAt\n\n"
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

      <a href="' . $statusUrl . '" style="display:inline-block;background:#1e3a5f;color:#ffffff;font-size:14px;font-weight:bold;padding:12px 24px;border-radius:6px;text-decoration:none;">View Submission</a>
    ');

    try {
        $mail = _createMailer();
        $mail->addAddress($recipient['email'], $recipient['display_name'] ?? '');
        $mail->Subject = '[FYI] ' . $formName . ' — ' . $stageName;
        $mail->isHTML(true);
        $mail->Body    = $body;
        $mail->AltBody = "Notification: $formName ($stageName)\nSubmitted by $submitterEmail on $submittedAt\nView: $statusUrl";
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
            <p style="margin:0 0 16px;font-size:14px;color:' . $outcomeColor . ';font-weight:bold;">' . $outcomeLabel . ' — ' . $completedAt . '</p>
          </td>
        </tr>
      </table>

      <a href="' . $statusUrl . '" style="display:inline-block;background:#1e3a5f;color:#ffffff;font-size:14px;font-weight:bold;padding:12px 24px;border-radius:6px;text-decoration:none;">View Submission Details</a>
    ');

    try {
        $mail = _createMailer();
        $mail->addAddress($toEmail);
        $mail->Subject = '[Aurora Form Workflow] Your submission has been ' . strtolower($outcomeLabel) . ' — ' . $formName;
        $mail->isHTML(true);
        $mail->Body    = $body;
        $mail->AltBody = "Your $formName submission has been $outcomeLabel.\n$outcomeMsg\nView: $statusUrl";
        $mail->send();
        return true;
    } catch (Exception $e) {
        error_log('[Aurora Email] sendSubmissionOutcomeEmail failed for ' . $toEmail . ': ' . $e->getMessage());
        return false;
    }
}
