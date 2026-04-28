# Aurora Form Workflow

A full-stack approval workflow system built for **Aurora Early Education** — replacing a manual email-based approval process with a structured, audit-ready platform.

Live at: [formworkflow.auroraearlyeducation.com.au](https://formworkflow.auroraearlyeducation.com.au)

---

## The problem it solves

Aurora's teams were managing form approvals through email chains — no visibility into pending items, no audit trail, no escalation when people didn't respond. This system replaces that entirely.

---

## Tech stack

| Layer | Technology |
|---|---|
| Backend | PHP (hosted on SiteGround) |
| Database | PostgreSQL via Supabase |
| Auth | Google OAuth 2.0 (PKCE flow) |
| Email | PHPMailer + Google Workspace SMTP |
| Form integration | Google Apps Script (onFormSubmit webhook) |
| Frontend | Tailwind CSS |
| PDF generation | FPDF |
| Digital signatures | signature_pad.js |

---

## Key features

**Approval workflow engine**
- Multi-stage approval flows configurable per Google Form
- Token-based email approvals — approvers approve/reject directly from email, no login required
- Support for approval, signature, and action stage types
- Routing rules to conditionally skip or redirect stages based on form data

**Audit & compliance**
- Immutable audit log for every stage transition, approval decision, and escalation event
- Full submission timeline visible to approvers and admins
- CSV export of all submission data with dynamic columns per form

**Escalation & reminders**
- Daily cron job processes overdue approvals
- Per-stage configurable reminder cadence and custom reminder messages
- Escalates to a designated escalation contact per approver, with admin fallback

**Admin dashboard**
- Live KPI cards: submissions this month, pending approvals, active forms
- Pending approvals queue with one-click drill-down
- Role-based access: admins see full controls, approvers see their own queue

**File handling**
- Google Drive file upload links captured from Google Forms and rendered as clickable links throughout the system
- Digital signature capture (base64 PNG) with PDF generation

**User management**
- Google Workspace OAuth auto-registers staff on first login
- Admins can pre-register staff (no login required) and assign escalation contacts
- Group-based recipient targeting for stages

---

## Architecture

```
Google Form
    │
    ▼  onFormSubmit (Apps Script)
    │
    ▼  POST /webhook.php  ←── WEBHOOK_SECRET verification
    │
    ▼  PHP Workflow Engine
    │   ├── wf_kickOffStage()         — starts a stage, mints tokens, sends emails
    │   ├── wf_checkStageCompletion() — checks if all approvers responded
    │   └── wf_advanceSubmission()    — moves to next stage or marks complete
    │
    ▼  Supabase (PostgreSQL)
        ├── submissions
        ├── submission_stages
        ├── approval_tokens
        └── audit_log
```

---

## Getting started

```bash
git clone https://github.com/nitish-thomas/form-workflow.git
cp config.example.php config.php   # fill in your Supabase URL, keys, SMTP config
```

Run `schema.sql` in Supabase SQL Editor, then each file in `migrations/` in date order.

Deploy PHP files to SiteGround — keep `config.php` above `public_html` or `.htaccess`-protected.

---

## What I learned building this

- Designing a relational schema that models a real-world process (multi-stage approval with branching logic) and evolving it through migrations as requirements grew
- Token-based auth patterns for no-login actions (approve/reject from email)
- SMTP configuration with Google Workspace — aliases can receive but cannot authenticate; the primary account must be the SMTP user
- Writing a cron-based background processor that safely handles edge cases (no escalation contact, already-resolved stages)
- The value of a deduplication guard on webhooks — Google Apps Script can fire `onFormSubmit` more than once per submission

---

*Built April 2026 · PHP · PostgreSQL (Supabase) · Google Workspace · Apps Script*
