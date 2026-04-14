# Architecture Overview

Aurora Approvals is an internal approval workflow system for Aurora Early Education. It wraps approval flows around existing Google Forms without replacing them — staff continue to use Google Forms exactly as they do today, and administrators configure the approval behaviour through a separate PHP web app.

## The three tiers

**Apps Script trigger layer.** A small standalone Apps Script project owned inside Aurora's Google Workspace. Its only responsibility is to listen for `onFormSubmit` events on registered forms and forward the submission payload to the PHP webhook. It is deliberately thin — no logic, no storage.

**PHP web app (formworkflow.auroraearlyeducation.com.au).** Hosted on SiteGround. Provides the admin configuration UI (create forms, define stages, set approvers, build routing rules), the approver dashboard, and the token-based approve/deny endpoints that email links point to. All stage advancement logic, email sending, PDF generation, and cron-driven reminders live here.

**Supabase (PostgreSQL).** The single source of truth for workflow configuration, submissions, approval history, audit log, and all derived state. The PHP backend connects using the service role key, which is the trust boundary — there is no per-user RLS because the PHP session gatekeeper controls who can do what.

## Request flow — submission to approval

1. User submits a Google Form
2. Apps Script `onFormSubmit` fires, POSTs JSON payload to PHP webhook
3. PHP looks up the form in Supabase, creates a `submissions` record, evaluates routing rules, and creates `submission_stages` rows
4. PHP resolves recipients for stage 1 — static users, group members, dynamic field lookups, delegation substitutions — and sends approval emails containing tokenised links
5. Approver clicks link → `approve.php` validates the token, records the decision in `approvals`, advances or halts the workflow
6. When all stages complete, submission is marked approved (or rejected at any point)
7. Throughout: cron job scans for stale stages and fires reminders + escalations

## Why not Apps Script alone

An all-Apps-Script clone of formapprovals.com would be simpler to deploy but trades away: proper relational data, BigQuery export, PDF generation at scale, non-trivial admin UI (sidebar panels are cramped), and analytical querying. At Aurora's scale those tradeoffs are worth it — see [[../decisions/ADR-001-path-b-hybrid]].

## Why not a full SPA

The PHP + server-rendered HTML + Alpine.js stack is deliberate. It matches Nitish's skill level, avoids a JavaScript build step, and deploys to SiteGround via straightforward file upload. Tailwind handles styling; Alpine handles the little interactivity that matters (drag-to-reorder, conditional form fields). No React or Vue framework needed for this app's complexity.
