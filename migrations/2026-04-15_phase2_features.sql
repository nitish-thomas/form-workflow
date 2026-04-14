-- ============================================================
-- Migration: Phase 2 Feature Additions
-- Date:      2026-04-15
-- Author:    Aurora Approvals build (Nitish)
--
-- Adds support for:
--   1. Recommend + Acknowledge stage types
--   2. Quorum approvals (X of N must approve)
--   3. Dynamic recipients (resolved from Google Form field values)
--   4. Escalation rules (auto-escalate stale approvals)
--   5. Delegation (approver can nominate a delegate during absence)
--
-- Safe to run on an existing Aurora Approvals schema from
-- schema.sql. Non-destructive — only adds columns, tables,
-- and check constraints.
--
-- To run: paste this entire file into Supabase SQL Editor.
-- ============================================================

BEGIN;

-- ------------------------------------------------------------
-- 1. STAGE TYPE — add 'recommend' and 'acknowledge'
-- ------------------------------------------------------------
-- Drop the old CHECK constraint and re-add with new values.
-- Supabase/Postgres doesn't let us ALTER a CHECK constraint in-place.

ALTER TABLE form_stages
    DROP CONSTRAINT IF EXISTS form_stages_stage_type_check;

ALTER TABLE form_stages
    ADD CONSTRAINT form_stages_stage_type_check
    CHECK (stage_type IN (
        'approval',      -- standard approve/reject (existing)
        'notification',  -- informational only, no action required (existing)
        'signature',     -- must sign (existing)
        'recommend',     -- NEW: non-blocking opinion, workflow proceeds
        'acknowledge'    -- NEW: must confirm receipt; blocking but no approve/reject
    ));

-- ------------------------------------------------------------
-- 2. QUORUM APPROVALS — add 'quorum' mode + threshold column
-- ------------------------------------------------------------

ALTER TABLE form_stages
    DROP CONSTRAINT IF EXISTS form_stages_approval_mode_check;

ALTER TABLE form_stages
    ADD CONSTRAINT form_stages_approval_mode_check
    CHECK (approval_mode IN ('any', 'all', 'quorum'));

ALTER TABLE form_stages
    ADD COLUMN IF NOT EXISTS quorum_count INT;

-- Enforce: quorum_count is required when mode is 'quorum', must be positive.
ALTER TABLE form_stages
    DROP CONSTRAINT IF EXISTS form_stages_quorum_count_check;

ALTER TABLE form_stages
    ADD CONSTRAINT form_stages_quorum_count_check
    CHECK (
        (approval_mode <> 'quorum' AND quorum_count IS NULL)
        OR
        (approval_mode = 'quorum'  AND quorum_count IS NOT NULL AND quorum_count > 0)
    );

COMMENT ON COLUMN form_stages.quorum_count IS
    'When approval_mode = quorum, this many recipients must approve for the stage to pass. Must be NULL otherwise.';

-- ------------------------------------------------------------
-- 3. DYNAMIC RECIPIENTS — resolved from form field values
-- ------------------------------------------------------------
-- Existing stage_recipients supports (user_id OR group_id).
-- Add a third option: resolve an approver from a form field.
--
-- Example: a leave request form has a "Manager email" question.
-- At submission time, PHP reads form_data[field_key] and routes
-- the approval to the user matching that email.

ALTER TABLE stage_recipients
    ADD COLUMN IF NOT EXISTS field_key TEXT;

COMMENT ON COLUMN stage_recipients.field_key IS
    'Google Form question ID or key (e.g. entry.12345678) whose value resolves to an approver email at submission time. Used only for dynamic recipients.';

-- Drop the old CHECK (user_id XOR group_id) and replace with a
-- three-way XOR: exactly one of user_id, group_id, field_key is set.

ALTER TABLE stage_recipients
    DROP CONSTRAINT IF EXISTS stage_recipients_check;

ALTER TABLE stage_recipients
    ADD CONSTRAINT stage_recipients_recipient_check
    CHECK (
        (user_id   IS NOT NULL)::int
      + (group_id  IS NOT NULL)::int
      + (field_key IS NOT NULL)::int
        = 1
    );

-- ------------------------------------------------------------
-- 4. ESCALATION RULES — auto-escalate stale approvals
-- ------------------------------------------------------------
-- A stage can have 0..N escalation rules. Each rule fires when a
-- pending stage has been open longer than trigger_after_hours.
--
-- Multiple rules per stage allow tiered escalation:
--   - After 24 hours → notify backup approver
--   - After 72 hours → notify department head

CREATE TABLE IF NOT EXISTS escalation_rules (
    id                    UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    stage_id              UUID NOT NULL REFERENCES form_stages(id) ON DELETE CASCADE,
    trigger_after_hours   INT  NOT NULL CHECK (trigger_after_hours > 0),
    escalate_to_user_id   UUID REFERENCES users(id) ON DELETE SET NULL,
    notify_requester      BOOLEAN NOT NULL DEFAULT FALSE,
    notify_admin          BOOLEAN NOT NULL DEFAULT FALSE,
    is_active             BOOLEAN NOT NULL DEFAULT TRUE,
    created_at            TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_escalation_stage  ON escalation_rules (stage_id);
CREATE INDEX IF NOT EXISTS idx_escalation_active ON escalation_rules (is_active);

COMMENT ON TABLE escalation_rules IS
    'Rules that fire when a submission_stage has been pending longer than trigger_after_hours. Checked by the reminder cron.';

-- ------------------------------------------------------------
-- 5. DELEGATION — approver nominates a delegate during absence
-- ------------------------------------------------------------
-- When resolving approvers for a stage, the system checks this
-- table first. If the assigned approver has an active delegation
-- covering "now", the approval routes to the delegate instead.
-- The audit_log records both names.

CREATE TABLE IF NOT EXISTS delegations (
    id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    delegator_id  UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    delegate_id   UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    starts_at     TIMESTAMPTZ NOT NULL,
    ends_at       TIMESTAMPTZ NOT NULL,
    reason        TEXT,
    is_active     BOOLEAN NOT NULL DEFAULT TRUE,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),

    CONSTRAINT delegation_no_self
        CHECK (delegator_id <> delegate_id),

    CONSTRAINT delegation_valid_window
        CHECK (ends_at > starts_at)
);

CREATE INDEX IF NOT EXISTS idx_delegations_delegator ON delegations (delegator_id);
CREATE INDEX IF NOT EXISTS idx_delegations_delegate  ON delegations (delegate_id);
CREATE INDEX IF NOT EXISTS idx_delegations_active    ON delegations (is_active, starts_at, ends_at);

COMMENT ON TABLE delegations IS
    'Temporary reassignment of approval duties. When delegator_id would receive an approval request and is_active=true and NOW() is within [starts_at, ends_at], the request routes to delegate_id instead.';

-- ------------------------------------------------------------
-- 6. RLS — enable on the two new tables, add service_role policy
-- ------------------------------------------------------------
ALTER TABLE escalation_rules ENABLE ROW LEVEL SECURITY;
ALTER TABLE delegations      ENABLE ROW LEVEL SECURITY;

DROP POLICY IF EXISTS "service_role_all" ON escalation_rules;
DROP POLICY IF EXISTS "service_role_all" ON delegations;

CREATE POLICY "service_role_all" ON escalation_rules FOR ALL USING (TRUE) WITH CHECK (TRUE);
CREATE POLICY "service_role_all" ON delegations      FOR ALL USING (TRUE) WITH CHECK (TRUE);

-- ============================================================
-- END OF MIGRATION
-- ============================================================
-- Verify with:
--   SELECT column_name, data_type FROM information_schema.columns
--   WHERE table_name IN ('form_stages','stage_recipients');
--
--   SELECT table_name FROM information_schema.tables
--   WHERE table_name IN ('escalation_rules','delegations');
-- ============================================================

COMMIT;
