/**
 * code.gs — Aurora Form Workflow · Google Apps Script
 *
 * Install this on each Google Form that needs an approval workflow:
 *   1. Open the Google Form
 *   2. Click the three-dot menu → Script Editor
 *   3. Paste this entire file, replacing any existing content
 *   4. Save (Ctrl+S / Cmd+S) — name the project "Aurora Webhook"
 *   5. Set the WEBHOOK_SECRET Script Property (see SETUP below)
 *   6. Add the onFormSubmit trigger (see SETUP below)
 *
 * ─── SETUP ────────────────────────────────────────────────────────────────────
 *
 * A) Set the webhook secret (do this once per form):
 *    - In the Script Editor, click Project Settings (gear icon, left sidebar)
 *    - Scroll to "Script Properties" → Add property
 *    - Name:  WEBHOOK_SECRET
 *    - Value: [paste the same value as WEBHOOK_SECRET in your PHP config.php]
 *    - Click Save
 *
 * B) Add the trigger (do this once per form):
 *    - In the Script Editor, click Triggers (clock icon, left sidebar)
 *    - Click "+ Add Trigger" (bottom right)
 *    - Choose function:    onFormSubmit
 *    - Event source:       From form
 *    - Event type:         On form submit
 *    - Click Save
 *    - Google will ask you to authorise the script — click through and allow
 *
 * ─── HOW IT WORKS ─────────────────────────────────────────────────────────────
 *
 * Every time someone submits the form, onFormSubmit() fires automatically.
 * It collects:
 *   - The Google Form's own ID (uniquely identifies the form)
 *   - The submitter's email (from "Collect email addresses" setting)
 *   - All question/answer pairs as a flat object
 *
 * It then POSTs this to your PHP webhook endpoint over HTTPS.
 * Results (success or error) are written to the Apps Script execution log,
 * which you can view in the Script Editor → Executions (left sidebar).
 *
 * ─── IMPORTANT NOTES ─────────────────────────────────────────────────────────
 *
 * - "Collect email addresses" must be ON in the form settings.
 *   Set it to "Verified" for Aurora staff (Google Workspace) or
 *   "Responder input" for external respondents.
 *
 * - This script must be authorised by a Google account that has edit access
 *   to the form. The trigger runs under that account's permissions.
 *
 * - If you update this script, re-authorise it via Triggers if prompted.
 */

// ─── CONFIGURATION ────────────────────────────────────────────────────────────

var WEBHOOK_URL = 'https://formworkflow.auroraearlyeducation.com.au/webhook.php';

// ─────────────────────────────────────────────────────────────────────────────

/**
 * Main trigger function — fires on every form submission.
 *
 * @param {Object} e - The form submit event object from Google Apps Script.
 */
function onFormSubmit(e) {
  try {
    // ── Guard: handle manual runs from the editor ────────────
    // If you click Run (▶) on onFormSubmit directly from the Script Editor,
    // Apps Script calls this function with no event object, and e is undefined.
    // That looks like a broken trigger but isn't — the trigger only fires when
    // someone actually submits the form.
    if (!e || !e.response) {
      Logger.log('[Aurora] onFormSubmit was invoked without a form-submit event. ' +
                 'This usually means you clicked Run (▶) from the editor. ' +
                 'To test the webhook without submitting a form, run testWebhook() instead. ' +
                 'To test the real flow, submit the form — the trigger will fire automatically.');
      return;
    }

    var form     = FormApp.getActiveForm();
    var response = e.response;

    // ── Collect the submitter's email ────────────────────────
    // getRespondentEmail() works when "Collect email addresses" is enabled.
    var submitterEmail = response.getRespondentEmail();
    if (!submitterEmail) {
      Logger.log('[Aurora] Warning: could not get respondent email. ' +
                 'Check that "Collect email addresses" is enabled on this form.');
    }

    // ── Collect all question/answer pairs ────────────────────
    // Keys are the question titles exactly as written in the form.
    var formData       = {};
    var itemResponses  = response.getItemResponses();

    for (var i = 0; i < itemResponses.length; i++) {
      var item     = itemResponses[i];
      var key      = item.getItem().getTitle();
      var value    = item.getResponse();
      var itemType = item.getItem().getType();

      // ── File upload fields ──────────────────────────────────
      // FILE_UPLOAD responses are JSON arrays of Google Drive file IDs.
      // We convert each ID to a {name, url} object so the PHP portal can
      // render clickable links instead of raw IDs.
      if (itemType === FormApp.ItemType.FILE_UPLOAD) {
        var fileIds = [];
        try {
          fileIds = JSON.parse(value);  // value is a JSON array string like '["id1","id2"]'
        } catch (e) {
          fileIds = Array.isArray(value) ? value : [value];
        }

        var files = [];
        for (var j = 0; j < fileIds.length; j++) {
          var fileId = fileIds[j];
          try {
            var driveFile = DriveApp.getFileById(fileId);
            // Share with anyone in the Aurora domain who has the link
            driveFile.setSharing(
              DriveApp.Access.DOMAIN_WITH_LINK,
              DriveApp.Permission.VIEW
            );
            files.push({
              name: driveFile.getName(),
              url:  'https://drive.google.com/file/d/' + fileId + '/view'
            });
          } catch (fileErr) {
            Logger.log('[Aurora] Could not access Drive file ' + fileId + ': ' + fileErr.toString());
            files.push({ name: fileId, url: 'https://drive.google.com/file/d/' + fileId + '/view' });
          }
        }

        // Store as a structured object so PHP can detect and render as links
        formData[key] = { type: 'files', files: files };
        continue;
      }

      // Grid questions return a 2D array — flatten to a readable string
      if (Array.isArray(value)) {
        value = Array.isArray(value[0])
          ? value.map(function(row) { return row.join(', '); }).join(' | ')
          : value.join(', ');
      }

      formData[key] = value;
    }

    // ── Read the webhook secret from Script Properties ────────
    var scriptProperties = PropertiesService.getScriptProperties();
    var webhookSecret    = scriptProperties.getProperty('WEBHOOK_SECRET');

    if (!webhookSecret) {
      Logger.log('[Aurora] ERROR: WEBHOOK_SECRET Script Property is not set. ' +
                 'Go to Project Settings → Script Properties and add it.');
      return;
    }

    // ── Build the payload ─────────────────────────────────────
    var payload = {
      webhook_secret:  webhookSecret,
      google_form_id:  form.getId(),
      submitter_email: submitterEmail || '',
      submitted_at:    response.getTimestamp().toISOString(),
      form_data:       formData
    };

    // ── POST to the PHP webhook ───────────────────────────────
    var options = {
      method:             'post',
      contentType:        'application/json',
      payload:            JSON.stringify(payload),
      muteHttpExceptions: true  // prevents Apps Script from throwing on 4xx/5xx
    };

    var httpResponse = UrlFetchApp.fetch(WEBHOOK_URL, options);
    var statusCode   = httpResponse.getResponseCode();
    var body         = httpResponse.getContentText();

    if (statusCode === 200) {
      Logger.log('[Aurora] Webhook SUCCESS (' + statusCode + '): ' + body);
    } else {
      Logger.log('[Aurora] Webhook ERROR (' + statusCode + '): ' + body);
      // Non-fatal: the form submission itself still succeeded
    }

  } catch (err) {
    // Log the error — does not affect the form submission
    Logger.log('[Aurora] Unexpected error in onFormSubmit: ' + err.toString());
  }
}

/**
 * testWebhook() — run this manually from the Script Editor to test
 * the connection without submitting a real form response.
 *
 * How to run:
 *   1. In the Script Editor, select "testWebhook" from the function dropdown
 *   2. Click Run (▶)
 *   3. Check the Execution Log for the result
 */
function testWebhook() {
  var form   = FormApp.getActiveForm();
  var secret = PropertiesService.getScriptProperties().getProperty('WEBHOOK_SECRET');

  if (!secret) {
    Logger.log('[Aurora Test] WEBHOOK_SECRET is not set. Cannot run test.');
    return;
  }

  var payload = {
    webhook_secret:  secret,
    google_form_id:  form.getId(),
    submitter_email: 'test@auroraearlyeducation.com.au',
    submitted_at:    new Date().toISOString(),
    form_data: {
      'Full Name':    'Test User',
      'Test Field':   'Test Value (sent from Apps Script testWebhook)'
    }
  };

  var options = {
    method:             'post',
    contentType:        'application/json',
    payload:            JSON.stringify(payload),
    muteHttpExceptions: true
  };

  try {
    var response   = UrlFetchApp.fetch(WEBHOOK_URL, options);
    var statusCode = response.getResponseCode();
    var body       = response.getContentText();
    Logger.log('[Aurora Test] Response ' + statusCode + ': ' + body);
  } catch (err) {
    Logger.log('[Aurora Test] Error: ' + err.toString());
  }
}
