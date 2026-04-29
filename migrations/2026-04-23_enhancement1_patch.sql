-- =============================================================================
-- Enhancement 1 — PATCH (run this if the main migration failed with:
--   "constraint stage_recipients_recipient_check already exists")
--
-- What happened: steps 1, 2a, 2b ran fine; step 2c failed because
-- the constraint name already existed from a prior attempt. Steps 3–6
-- may not have run. This patch finishes the job safely.
-- =============================================================================


-- ── Step 2c (fixed) — drop-then-recreate the recipient check constraint ───────
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


-- ── Step 3 — stage_templates (IF NOT EXISTS = safe to re-run) ────────────────
CREATE TABLE IF NOT EXISTS stage_templates (
    id              UUID        PRIMARY KEY DEFAULT uuid_generate_v4(),
    name            TEXT        NOT NULL,
    stage_type      TEXT        NOT NULL DEFAULT 'approval'
        CHECK (stage_type IN ('approval', 'notification', 'signature')),
    approval_mode   TEXT        NOT NULL DEFAULT 'any'
        CHECK (approval_mode IN ('any', 'all')),
    description     TEXT,
    reminder_days   INTEGER     DEFAULT NULL,
    escalation_days INTEGER     DEFAULT NULL,
    archived_at     TIMESTAMPTZ DEFAULT NULL,
    created_by      UUID        REFERENCES users(id) ON DELETE SET NULL,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX IF NOT EXISTS idx_stage_templates_archived
    ON stage_templates (archived_at);


-- ── Step 4 — stage_template_recipients ───────────────────────────────────────
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


-- ── Step 5 — RLS ─────────────────────────────────────────────────────────────
ALTER TABLE stage_templates           ENABLE ROW LEVEL SECURITY;
ALTER TABLE stage_template_recipients ENABLE ROW LEVEL SECURITY;

DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE tablename = 'stage_templates' AND policyname = 'service_role_all'
    ) THEN
        CREATE POLICY "service_role_all" ON stage_templates
            FOR ALL USING (TRUE) WITH CHECK (TRUE);
    END IF;

    IF NOT EXISTS (
        SELECT 1 FROM pg_policies
        WHERE tablename = 'stage_template_recipients' AND policyname = 'service_role_all'
    ) THEN
        CREATE POLICY "service_role_all" ON stage_template_recipients
            FOR ALL USING (TRUE) WITH CHECK (TRUE);
    END IF;
END $$;


-- ── Step 6 — updated_at trigger ──────────────────────────────────────────────
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_trigger
        WHERE tgname = 'trg_stage_templates_updated'
    ) THEN
        CREATE TRIGGER trg_stage_templates_updated
            BEFORE UPDATE ON stage_templates
            FOR EACH ROW EXECUTE FUNCTION set_updated_at();
    END IF;
END $$;


-- ── Verify everything landed ──────────────────────────────────────────────────
-- Run these SELECT statements after the patch to confirm:

-- 1. stage_recipients now has email_address + correct constraint:
SELECT column_name, data_type, is_nullable
  FROM information_schema.columns
 WHERE table_name = 'stage_recipients'
 ORDER BY ordinal_position;

-- 2. Constraint definition (should show the 3-way OR):
SELECT conname, pg_get_constraintdef(oid)
  FROM pg_constraint
 WHERE conrelid = 'stage_recipients'::regclass AND contype = 'c';

-- 3. Both new tables exist:
SELECT table_name FROM information_schema.tables
 WHERE table_name IN ('stage_templates', 'stage_template_recipients')
 ORDER BY table_name;
