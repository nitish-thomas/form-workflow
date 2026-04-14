# Phase 2 — Admin Configuration UI (Planned)

**Status:** Not started
**Prerequisites:** Schema migration `2026-04-15_phase2_features.sql` run in Supabase

## Deliverables

1. **`dashboard.php`** — post-login landing page. Session gate + shared nav for all subsequent pages. Placeholder body until Phase 5 fills in submission lists and timelines.

2. **`logout.php`** — destroy session, redirect to `index.php`.

3. **`forms.php`** — CRUD for approval workflows. Register a Google Form (paste URL or ID), name it, set `allow_resubmit`, activate/deactivate. Listing view + new/edit form.

4. **`form-stages.php`** — manage ordered stages within a form. Drag-to-reorder via SortableJS. Set `stage_type` (approval, notification, signature, recommend, acknowledge), `approval_mode` (any, all, quorum), and `quorum_count` when applicable.

5. **`recipients.php`** — assign recipients to each stage. Three modes: individual user, recipient group, or dynamic field (paste a Google Form question ID). UI should make clear that exactly one mode applies per row.

6. **`groups.php`** — manage recipient groups and their members. Simple CRUD.

7. **`routing-rules.php`** — build conditional routing rules per form. Visual condition builder (field + operator + value), stored as JSONB in `routing_rules.condition_json`. Priority ordering.

## New in Phase 2 beyond the original plan

- UI surfaces for the extended stage types (recommend, acknowledge)
- Quorum count input on form-stages
- Dynamic recipient mode on recipients
- Management screens for `escalation_rules` (per stage, under form-stages)
- Management screens for `delegations` (likely a user-profile or settings page)

## UI direction

- Tailwind CSS for styling, Alpine.js for interactivity, SortableJS for drag-and-drop
- Shared layout with a sidebar nav
- Card-based list views; modal-or-page edit forms
- Visual workflow diagram on the form detail page (Phase 2 stretch — defer if time-constrained)

## Order of build

1. Shared layout + nav + session gate → dashboard.php + logout.php
2. forms.php (simplest CRUD)
3. groups.php
4. form-stages.php (this is where the drag-reorder work happens)
5. recipients.php (the trickiest — three input modes)
6. routing-rules.php (most complex UI)
7. Escalation management panel
8. Delegation management panel

## Definition of done for Phase 2

An admin can log in, register a Google Form, define a multi-stage workflow with a mix of stage types, assign recipients (including dynamic ones), set up a routing rule, and configure an escalation policy — all without touching the database directly.

No Apps Script or webhook work in Phase 2. The workflows configured here won't actually *run* until Phase 4.
