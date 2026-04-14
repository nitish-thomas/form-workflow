# ADR-001 — Path B: Hybrid Architecture (Apps Script + PHP + Supabase)

**Status:** Accepted
**Date:** 2026-04-15
**Decision-maker:** Nitish

## Context

Aurora needs an approval workflow system that wraps around Google Forms — essentially a clone of formapprovals.com but under Aurora's control, on Aurora's infrastructure, with no per-user licensing cost. Two viable approaches were considered.

## Options considered

**Path A — Pure Google Apps Script Add-on.** Everything lives inside Google's infrastructure. Admin UI is a sidebar in the Google Forms editor. Data in Google Sheets or Apps Script Properties. Approvals handled by an Apps Script web app `doGet` endpoint. No external hosting.

**Path B — Hybrid (Apps Script + PHP + Supabase).** Apps Script is a thin `onFormSubmit` bridge. All logic lives in a PHP web app on SiteGround backed by a Supabase Postgres database.

## Decision

**Path B.**

## Why

- Phase 1 is already built on Path B. Switching would discard working code.
- Aurora needs an audit trail, PDF generation with signatures, and (later) BigQuery export. A proper relational database makes these straightforward; Sheets-as-database makes them painful.
- The admin UI for multi-stage workflows, drag-to-reorder, and a visual routing rules builder needs more space than an Apps Script sidebar provides.
- Hosting is already paid for (SiteGround) so there's no marginal cost to running a PHP app.
- PHP matches Nitish's current skill level; learning Apps Script deeply would be a detour.

## Tradeoffs accepted

- Two systems to maintain (Apps Script + PHP) instead of one.
- A webhook call between Google and SiteGround adds a failure point — mitigated by Apps Script's built-in retry semantics and an idempotent PHP webhook.
- The Apps Script must be installed on each form that needs approvals — manageable at 2–3 forms, would need revisiting at 20+.

## Revisit if

- Form count grows past ~10 and per-form Apps Script installation becomes a burden
- Latency between Apps Script POST and PHP response causes user-visible delays
- SiteGround hosting becomes unreliable or too expensive
