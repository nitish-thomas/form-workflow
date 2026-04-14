# ADR-002 — Confirmed Feature Additions Beyond formapprovals.com

**Status:** Accepted
**Date:** 2026-04-15
**Decision-maker:** Nitish

## Context

Aurora Approvals is positioned as a clone of formapprovals.com — but since we're building from scratch, we have the opportunity to include features the original either lacks or handles poorly. These decisions were made during the Phase 2 planning discussion and affect the schema.

## Decisions

### Parity with formapprovals.com (schema-affecting)

**Recommend stage type — accepted.** A non-blocking opinion. The stage contributes input to subsequent approvers but does not hold up the workflow. Added to `form_stages.stage_type` via migration `2026-04-15_phase2_features.sql`.

**Acknowledge stage type — accepted.** The approver must confirm receipt but doesn't approve or reject. All acknowledgers must respond for the stage to clear.

**Quorum approvals — accepted.** `N of M` approval mode. Added as a new value for `approval_mode` plus a `quorum_count` column on `form_stages`. A stage-level CHECK constraint enforces that `quorum_count` is set iff `approval_mode = 'quorum'`.

**Dynamic recipients — accepted.** A stage recipient can be resolved at submission time from the value of a Google Form field. Added via a new `field_key` column on `stage_recipients` and a three-way XOR check constraint ensuring exactly one of `user_id`, `group_id`, `field_key` is set.

### New features beyond formapprovals.com

**Escalation rules — accepted.** A stage can have 0..N escalation rules, each firing after a configurable number of hours. This is a major differentiator — formapprovals.com only has reminders. New `escalation_rules` table.

**Delegation — accepted.** Approvers can nominate a delegate for a date range. When resolving approvers, the system checks this table and substitutes the delegate if an active delegation covers "now." New `delegations` table with constraints preventing self-delegation and inverted windows.

### Deferred (schema non-breaking, can add later)

- Analytics dashboard (approval rate, time-to-decision, bottlenecks)
- Bulk approval actions from the dashboard
- Email digest for approvers
- Approval commenting visible to requestor
- Google Calendar integration on approval
- Slack notifications

These can all be added without further schema migration.

## Schema impact

See `../../migrations/2026-04-15_phase2_features.sql`. Two new tables, two new columns, and two extended CHECK constraints. Non-destructive — existing Phase 1 data is untouched.

## Why these specifically

Recommend and Acknowledge cover real Aurora workflows (a team lead endorsing before a director signs off; HR being looped in without veto power). Quorum covers committee-style approvals. Dynamic recipients solve the single biggest limitation of static approver lists — "who is your manager?" forms. Escalation and delegation are the two recurring complaints in every review of formapprovals.com and similar tools.
