# Aurora Form Workflow — Phase 5 Handoff
**Date:** 2026-04-16  
**Prepared by:** Build session (Nitish + Claude)

---

## What was completed in Phase 4

| Feature | File | Notes |
|---|---|---|
| Submissions list page | submissions.php | Status tabs, form filter, admin/non-admin views |
| Production SMTP | config.php | Google Workspace SMTP, systems@ as SMTP_USER |
| Dashboard improvements | dashboard.php | Stat cards, pending queue, recent submissions |
| User management | users.php | Promote/demote admin, activate/deactivate |
| Inline approval on portal | status.php | Approvers who lost email can act from the portal |
| Nav gating | includes/header.php | Admin-only nav items hidden from users |

---

## What was completed in Phase 5

### Files created or modified

| File | What changed |
|---|---|
| `submissions.php` | Added approver display: ✓/✗ icons + names under stage name; "Awaiting response" if no decisions |
| `migrations/2026-04-16_phase5_schema.sql` | **Run this in Supabase** — adds 6 new columns (see below) |
| `form-stages.php` | Modal has Reminders & Escalation section; stage cards show reminder/escalation days |
| `includes/email.php` | 4 new email functions added |
| `cron.php` | NEW — daily reminder + escalation processor |
| `sign.php` | NEW — token-gated signature capture page |
| `includes/pdf.php` | NEW — FPDF wrapper for signed document PDFs |
| `includes/workflow.php` | wf_kickOffStage() now handles 'signature' stage type |

### Schema changes (run migration before testing)

**form_stages:**
- `reminder_days INTEGER` — send reminder to pending approvers every N days (NULL = off)
- `escalation_days INTEGER` — alert admin after N days (NULL = off)

**submission_stages:**
- `last_reminder_sent_at TIMESTAMPTZ` — tracks when last reminder was sent
- `reminder_count INTEGER DEFAULT 0` — total reminders sent
- `signature_data TEXT` — base64 PNG from signature_pad.js
- `signed_at TIMESTAMPTZ` — timestamp of signature
- `signer_email TEXT` — email of the signer

### New email functions (includes/email.php)

| Function | When sent |
|---|---|
| `sendReminderEmail()` | cron.php — re-notifies pending approvers every reminder_days days |
| `sendEscalationEmail()` | cron.php — alerts all admin users when a stage is overdue |
| `sendSignatureRequestEmail()` | wf_kickOffStage() — sends sign link when signature stage opens |
| `sendSignedPDFEmail()` | sign.php — emails signed PDF to all previous-stage approvers |

### How the signature flow works

1. Admin creates a stage with `stage_type = 'signature'`
2. When that stage is reached, `wf_kickOffStage()` creates a `sign` token per recipient and emails them
3. Recipient clicks link → `sign.php` shows the form summary + signature canvas
4. They draw and submit → signature stored in `submission_stages.signature_data`
5. An `approved` record is inserted in `approvals`
6. If FPDF is installed, a PDF is generated and emailed to all previous-stage approvers
7. `wf_checkStageCompletion()` is called → workflow advances

### How reminders and escalations work

- Configured **per stage** in the form-stages.php modal
- `cron.php` runs daily (SiteGround cPanel cron) and:
  - Sends **reminders** if `days_elapsed >= reminder_days` and enough time has passed since last reminder
  - Sends **escalation** emails to all admins if `days_elapsed >= escalation_days` (once only per stage, logged in audit_log)
  - **Re-notifies pending approvers** alongside escalation

---

## Pending actions before Phase 5 is fully live

### 1. Run the migration in Supabase
Open Supabase → SQL Editor → paste and run:
```
migrations/2026-04-16_phase5_schema.sql
```

### 2. Install FPDF (for signature PDFs)
- Download from https://www.fpdf.org (fpdf183.zip or later)
- Extract and upload so that `includes/fpdf/fpdf.php` exists on SiteGround
- If not installed, sign.php will still work — it just won't generate a PDF

### 3. Set up the daily cron job on SiteGround
1. Log in to SiteGround cPanel → Cron Jobs
2. Set frequency: **Daily at 8:00 AM** (adjust for AEDT/AEST)
3. Command: `/usr/bin/php /home/USERNAME/public_html/cron.php`
4. Add to `config.php`: `define('CRON_SECRET', 'some-long-random-string');`
5. To test via browser: `https://formworkflow.auroraearlyeducation.com.au/cron.php?key=your-secret`

### 4. Git
```bash
cd "C:\Users\nitis\OneDrive\Documents\Claude\Projects\Form Workflow"
git init
# Create .gitignore with: config.php, includes/fpdf/, includes/phpmailer/, *.log
git add .
git commit -m "feat: Phase 1-5 complete — Aurora Form Workflow"
# Create private GitHub repo, then:
git remote add origin https://github.com/YOUR_USERNAME/form-workflow.git
git push -u origin main
```

---

## Current environment state

| Setting | Value |
|---|---|
| APP_ENV | production |
| SMTP | Google Workspace (systems@auroraearlyeducation.com.au) |
| Admin user | systems@auroraearlyeducation.com.au |
| Phase 5 migration | NOT YET RUN — run before testing signature/reminder features |
| FPDF | NOT YET INSTALLED — download from fpdf.org |
| Cron | NOT YET SET UP — manual HTTP trigger available once CRON_SECRET added |

---

## Complete file map

```
formworkflow.auroraearlyeducation.com.au/
├── config.php              — all constants (add CRON_SECRET)
├── supabase.php            — Supabase REST wrapper
├── index.php               — login page (Google OAuth)
├── auth-callback.php       — OAuth callback
├── dashboard.php           — home after login (stat cards, pending queue, recent submissions)
├── forms.php               — CRUD for forms
├── form-stages.php         — CRUD for stages (now includes reminder/escalation fields)
├── recipients.php          — CRUD for stage recipients
├── groups.php              — CRUD for recipient groups
├── delegations.php         — CRUD for delegations
├── routing-rules.php       — CRUD for routing rules
├── users.php               — Admin user management (promote/demote, activate)
├── submissions.php         — Submissions list (with approver display)
├── webhook.php             — receives Apps Script POSTs
├── approve.php             — email approval handler (no login)
├── sign.php                — NEW: email signature handler (no login)
├── status.php              — submission timeline + inline approval panel
├── cron.php                — NEW: daily reminder + escalation processor
├── apps-script/code.gs     — install on each Google Form
├── migrations/             — run in Supabase SQL Editor in date order
│   ├── 2026-04-16_phase3_schema.sql
│   ├── 2026-04-16_phase3b_forms_schema_align.sql
│   ├── 2026-04-16_phase3c_stages_schema_align.sql
│   └── 2026-04-16_phase5_schema.sql  ← run this
└── includes/
    ├── auth-check.php      — session guard + $currentUser + $sb
    ├── header.php          — nav bar
    ├── footer.php          — closing tags + shared JS (api(), showToast())
    ├── email.php           — all email functions (7 total)
    ├── workflow.php        — stage engine (kickOff, checkCompletion, advance)
    ├── pdf.php             — NEW: FPDF wrapper for signed PDFs
    ├── phpmailer/          — PHPMailer library
    └── fpdf/               — FPDF library (download separately)
```

---

## How to start the next session

Tell Claude:
> "We're continuing the Aurora Form Workflow build. Phases 1–5 are complete. Read HANDOFF_phase5.md in the workspace folder. Here's what I want to work on next: [describe next feature]."

### Ideas for future phases
- **Recommend + Acknowledge** stage types (lighter-weight than approval)
- **Bulk actions** on submissions list (bulk approve, export to CSV)
- **BigQuery export** for analytics across all submissions
- **Email digest** — daily summary of pending approvals for each user
- **Form-level settings UI** — reminder/escalation defaults per form, not just per stage
