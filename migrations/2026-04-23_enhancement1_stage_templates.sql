-- =============================================================================
-- Enhancement 1 — Stage Templates + Manual Email Recipients
-- Date: 2026-04-23
-- Run once in Supabase SQL Editor.
-- =============================================================================


-- ── 1. Add archived_at to form_stages ────────────────────────────────────────
--    Mirrors the pattern used on forms (soft-archive instead of hard-delete).
--    NULL = active, TIMESTAMPTZ = archived on that date.

ALTER TABLE form_stages
    ADD COLUMN IF NOT EXISTS archived_at TIMESTAMPTZ DEFAULT NULL;


-- ── 2. Extend stage_recipients to allow bare email addresses ──────────────────
--
--    The original schema only allowed user_id XOR group_id.
--    We now add a third option: a plain email_address (external / non-user recipient).
--
--    Step 2a — add the column (safe, nullable, no constraint conflicts yet)
ALTER TABLE stage_recipients
    ADD COLUMN IF NOT EXISTS email_address TEXT DEFAULT NULL;

--    Step 2b — drop the old unnamed CHECK constraint.
--
--    The schema.sql CHECK was unnamed, so Postgres auto-generated the name.
--    It is almost always "stage_recipients_check". If the ALTER below fails with
--    "constraint does not exist", run this diagnostic first to find the real name:
--
--      SELECT conname FROM pg_constraint
--       WHERE conrelid = 'stage_recipients'::regclass AND contype = 'c';
--
--    Then replace 'stage_recipients_check' below with whatever it returns.
ALTER TABLE stage_recipients
    DROP CONSTRAINT IF EXISTS stage_recipients_check;

--    Step 2c — add the updated 3-way CHECK: user XOR group XOR email
--    Drop first in case a prior partial run already created this constraint name.
ALTER TABLE stage_recipients
    DROP CONSTRAINT IF EXISTS stage_recipients_recipient_check;

ALTER TABLE stage_recipients
    ADD CONSTRAINT stage_recipients_recipient_check CHECK (
        (user_id IS NOT NULL AND group_id IS NULL    AND email_address IS NULL)
        OR
        (user_id IS NULL    AND group_id IS NOT NULL AND email_address IS NULL)
        OR
        (user_id IS NULL    AND group_id IS NULL     AND email_address IS NOT NULL)
    );


-- ── 3. New table: stage_templates ────────────────────────────────────────────
--
--    Reusable stage blueprints. Editing a template does NOT retroactively
--    change existing form_stages — templates are copied (snapshotted) on use.

CREATE TABLE IF NOT EXISTS stage_templates (
    id              UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
    name            TEXT        NOT NULL,
    stage_type      TEXT        NOT NULL DEFAULT 'approval'
        CHECK (stage_type IN ('approval', 'notification', 'signature')),
    approval_mode   TEXT        NOT NULL DEFAULT 'any'
        CHECK (approval_mode IN ('any', 'all')),
    description     TEXT,
    reminder_days   INTEGER     DEFAULT NULL,   -- NULL = off
    escalation_days INTEGER     DEFAULT NULL,   -- NULL = off
    archived_at     TIMESTAMPTZ DEFAULT NULL,   -- NULL = active
    created_by      UUID        REFERENCES users(id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_stage_templates_archived
    ON stage_templates (archived_at);


-- ── 4. New table: stage_template_recipients ───────────────────────────────────
--
--    Recipients attached to a template. Same 3-way XOR rule as stage_recipients:
--    a row holds exactly one of (user_id | group_id | email_address).

CREATE TABLE IF NOT EXISTS stage_template_recipients (
    id                UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
    stage_template_id UUID        NOT NULL REFERENCES stage_templates(id) ON DELETE CASCADE,
    user_id           UUID        REFERENCES users(id) ON DELETE CASCADE,
    group_id          UUID        REFERENCES recipient_groups(id) ON DELETE CASCADE,
    email_address     TEXT        DEFAULT NULL,
    created_at        TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CONSTRAINT stage_template_recipients_recipient_check CHECK (
        (user_id IS NOT NULL AND group_id IS NULL    AND email_address IS NULL)
        OR
        (user_id IS NULL    AND group_id IS NOT NULL AND email_address IS NULL)
        OR
        (user_id IS NULL    AND group_id IS NULL     AND email_address IS NOT NULL)
    )
);

CREATE INDEX IF NOT EXISTS idx_stage_template_recipients_template
    ON stage_template_recipients (stage_template_id);


-- ── 5. RLS — allow service_role full access on the new tables ─────────────────
--    (mirrors the pattern set in schema.sql for all other tables)

ALTER TABLE stage_templates           ENABLE ROW LEVEL SECURITY;
ALTER TABLE stage_template_recipients ENABLE ROW LEVEL SECURITY;

CREATE POLICY "service_role_all" ON stage_templates
    FOR ALL USING (TRUE) WITH CHECK (TRUE);

CREATE POLICY "service_role_all" ON stage_template_recipients
    FOR ALL USING (TRUE) WITH CHECK (TRUE);


-- ── 6. updated_at trigger for stage_templates ─────────────────────────────────
--    set_updated_at() was defined in schema.sql and is already present.

CREATE TRIGGER trg_stage_templates_updated
    BEFORE UPDATE ON stage_templates
    FOR EACH ROW EXECUTE FUNCTION set_updated_at();


-- ── Verify ────────────────────────────────────────────────────────────────────
-- After running, paste these to confirm everything landed:
--
-- -- New columns on stage_recipients:
-- SELECT column_name, data_type, is_nullable
--   FROM information_schema.columns
--  WHERE table_name = 'stage_recipients'
--  ORDER BY ordinal_position;
--
-- -- Constraint name (should be stage_recipients_recipient_check):
-- SELECT conname, pg_get_constraintdef(oid)
--   FROM pg_constraint
--  WHERE conrelid = 'stage_recipients'::regclass AND contype = 'c';
--
-- -- New tables exist:
-- SELECT table_name FROM information_schema.tables
--  WHERE table_name IN ('stage_templates', 'stage_template_recipients');
