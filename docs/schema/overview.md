# Database Schema Reference

This doc is a human-readable map of the Aurora Approvals database. For the authoritative definitions, see `../../schema.sql` (Phase 1 baseline) and `../../migrations/*.sql` (all changes since).

## Tables at a glance

### Identity and configuration

- `users` — synced from Supabase Auth on first login. Has `auth_id`, `email`, `display_name`, `role` (admin/user), `is_active`.
- `forms` — an approval workflow definition. Registered Google Forms are represented here. Key field: `allow_resubmit`.
- `form_stages` — ordered approval stages within a form. Key fields: `stage_order`, `stage_type`, `approval_mode`, `quorum_count`.
- `stage_recipients` — who approves at each stage. Exactly one of `user_id` / `group_id` / `field_key` is set (three-way XOR).
- `recipient_groups` + `group_members` — reusable approver groups.
- `routing_rules` — conditional stage overrides based on form field values. Condition stored as JSONB.

### Submissions and decisions

- `submissions` — an instance of a form being submitted. Has `form_data` (JSONB of the actual answers) and a `status`.
- `submission_stages` — per-stage progress tracking for each submission.
- `approvals` — individual decisions (approved / rejected / more_info) by a specific approver on a specific stage.
- `approval_tokens` — single-use signed URLs emailed to approvers.
- `signatures` — base64 PNG signature pad output linked to approvals.

### Delivery and reminders

- `email_templates` — customisable email content per form / stage / event.
- `reminders` + `reminder_log` — configuration and history of reminder emails.
- `escalation_rules` — auto-escalation when stages go stale.
- `delegations` — temporary reassignment of approval duties.

### Observability

- `audit_log` — append-only record of every meaningful action.

## Key constraints worth knowing

- `form_stages.stage_type` must be one of: `approval`, `notification`, `signature`, `recommend`, `acknowledge`
- `form_stages.approval_mode` must be one of: `any`, `all`, `quorum`
- `form_stages.quorum_count` is NULL unless `approval_mode = 'quorum'`, and must be a positive integer when set
- `stage_recipients` has a three-way XOR: exactly one of `user_id`, `group_id`, `field_key` is set
- `delegations` blocks self-delegation and inverted date windows
- `escalation_rules.trigger_after_hours` must be > 0

## Migrations

| Date       | File                                              | Summary                                                                                  |
|------------|---------------------------------------------------|------------------------------------------------------------------------------------------|
| (baseline) | `schema.sql`                                      | Initial 16-table schema (Phase 1)                                                        |
| 2026-04-15 | `migrations/2026-04-15_phase2_features.sql`       | Recommend/Acknowledge types, quorum, dynamic recipients, escalation_rules, delegations   |

## Things to remember

- The PHP backend uses the Supabase service role key. RLS is enabled but the policies are permissive (`service_role_all`). The PHP session layer is the real access control.
- Every table with `updated_at` has a trigger that refreshes it on UPDATE. Don't set it manually in application code.
- `audit_log` is append-only by convention. Never UPDATE or DELETE rows there.
