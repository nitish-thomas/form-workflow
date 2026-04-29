-- =============================================================================
-- Enhancement 1 — PATCH 2: Fix stage_recipients CHECK to include field_key
-- Date: 2026-04-23
--
-- The constraint added in the main migration only covered 3 recipient types
-- (user_id | group_id | email_address). The table also has a field_key column
-- for "Dynamic Recipients" — the system reads an email from a submitted form
-- field at runtime. Without this fix, inserting a dynamic recipient would fail
-- with a constraint violation.
-- =============================================================================


-- ── stage_recipients — 4-way XOR constraint ───────────────────────────────────
ALTER TABLE stage_recipients
    DROP CONSTRAINT IF EXISTS stage_recipients_recipient_check;

ALTER TABLE stage_recipients
    ADD CONSTRAINT stage_recipients_recipient_check CHECK (
        -- Specific user
        (user_id IS NOT NULL AND group_id IS NULL    AND email_address IS NULL AND field_key IS NULL)
        OR
        -- Recipient group
        (user_id IS NULL    AND group_id IS NOT NULL AND email_address IS NULL AND field_key IS NULL)
        OR
        -- Manual email address (Enhancement 1)
        (user_id IS NULL    AND group_id IS NULL     AND email_address IS NOT NULL AND field_key IS NULL)
        OR
        -- Dynamic field key (reads from submitted form data at runtime)
        (user_id IS NULL    AND group_id IS NULL     AND email_address IS NULL AND field_key IS NOT NULL)
    );


-- ── stage_template_recipients — also add field_key for consistency ────────────
-- Templates can include dynamic recipients too (field name gets copied into
-- the form stage when the template is applied).
ALTER TABLE stage_template_recipients
    ADD COLUMN IF NOT EXISTS field_key TEXT DEFAULT NULL;

ALTER TABLE stage_template_recipients
    DROP CONSTRAINT IF EXISTS stage_template_recipients_recipient_check;

ALTER TABLE stage_template_recipients
    ADD CONSTRAINT stage_template_recipients_recipient_check CHECK (
        (user_id IS NOT NULL AND group_id IS NULL    AND email_address IS NULL AND field_key IS NULL)
        OR
        (user_id IS NULL    AND group_id IS NOT NULL AND email_address IS NULL AND field_key IS NULL)
        OR
        (user_id IS NULL    AND group_id IS NULL     AND email_address IS NOT NULL AND field_key IS NULL)
        OR
        (user_id IS NULL    AND group_id IS NULL     AND email_address IS NULL AND field_key IS NOT NULL)
    );


-- ── Verify ────────────────────────────────────────────────────────────────────
-- Constraint should now show 4 OR branches:
SELECT conname, pg_get_constraintdef(oid)
  FROM pg_constraint
 WHERE conrelid = 'stage_recipients'::regclass AND contype = 'c';

-- stage_template_recipients should now have field_key:
SELECT column_name, data_type, is_nullable
  FROM information_schema.columns
 WHERE table_name = 'stage_template_recipients'
 ORDER BY ordinal_position;
