# Apps Script ↔ PHP Integration

This note covers the integration seam between Google Forms and the PHP backend. Everything else in the system depends on this working correctly, so it's documented first.

## The Apps Script responsibilities

The Apps Script has exactly three jobs:

1. Receive `onFormSubmit` events from any form it has a trigger on
2. Extract the form ID, response timestamp, and field answers
3. POST a JSON payload to the PHP webhook with a shared-secret header for auth

It does not talk to Supabase. It does not decide whether a form is "active." It does not make any routing decisions. All of that lives in PHP.

## Installation model

For Aurora's scale (2–3 forms), a single standalone Apps Script project is installed once by an admin. For each form that needs approvals, the admin opens the form's Apps Script editor and adds an installable trigger that points at the shared script's `onFormSubmit` function. This is a 30-second manual step per form.

An alternative — pushing the Apps Script via Google Workspace Admin Console — is overkill at this scale but is the path if the system ever needs to support more forms.

## Payload shape (PHP webhook)

```json
{
  "google_form_id": "1FAIpQLSc...",
  "submitted_at":   "2026-04-15T10:32:04+10:00",
  "responses": [
    { "question_id": "entry.12345678", "question_title": "Manager email", "value": "manager@aurora.com" },
    { "question_id": "entry.87654321", "question_title": "Amount",        "value": "5000" }
  ]
}
```

`question_id` is the key for dynamic recipient resolution. A `stage_recipients` row with `recipient_type='dynamic_field'` and `field_key='entry.12345678'` means: at submission time, find the response whose `question_id` matches and look up the user by that email.

## Security

- Webhook validates a shared secret in the `X-Aurora-Token` header before accepting any POST
- HTTPS enforced (SiteGround provides the cert)
- The Apps Script must not expose the shared secret in client-side code — it lives in Apps Script Properties

## What happens when something goes wrong

- Apps Script has retry semantics; if the POST fails, Google retries the trigger. PHP webhook must therefore be idempotent on `(google_form_id, submitted_at)`.
- If a form submits but has no matching record in the `forms` table, PHP logs and drops it — do not 500 back to Apps Script or you'll get retry loops.
