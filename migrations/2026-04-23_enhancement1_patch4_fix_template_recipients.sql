-- =============================================================================
-- Enhancement 1 — PATCH 4: Fix stage_template_recipients + users.auth_id
-- Date: 2026-04-23
--
-- Patch 3 failed on stage_template_recipients because field_key didn't exist
-- there yet (patch 2 was never run). Patch 3 steps 1–3 (stage_recipients +
-- users.auth_id) already completed — do NOT re-run those.
--
-- This patch finishes what patch 3 couldn't:
--   1. Adds field_key to stage_template_recipients (was missing)
--   2. Drops email_address from stage_template_recipients
--   3. Recreates the constraint as a clean 3-way XOR
--   4. Makes users.auth_id nullable (may already be done — IF NOT already run)
-- =============================================================================


-- ── 1. Add field_key to stage_template_recipients ─────────────────────────────
ALTER TABLE stage_template_recipients
    ADD COLUMN IF NOT EXISTS field_key TEXT DEFAULT NULL;


-- ── 2. Drop email_address ─────────────────────────────────────────────────────
ALTER TABLE stage_template_recipients
    DROP COLUMN IF EXISTS email_address;


-- ── 3. Recreate the 3-way constraint ─────────────────────────────────────────
ALTER TABLE stage_template_recipients
    DROP CONSTRAINT IF EXISTS stage_template_recipients_recipient_check;

ALTER TABLE stage_template_recipients
    ADD CONSTRAINT stage_template_recipients_recipient_check CHECK (
        (user_id IS NOT NULL AND group_id IS NULL  AND field_key IS NULL)
        OR
        (user_id IS NULL  AND group_id IS NOT NULL AND field_key IS NULL)
        OR
        (user_id IS NULL  AND group_id IS NULL     AND field_key IS NOT NULL)
    );


-- ── 4. users.auth_id nullable (safe to re-run — no-ops if already nullable) ───
ALTER TABLE users
    ALTER COLUMN auth_id DROP NOT NULL;


-- ── Verify all four things ────────────────────────────────────────────────────

-- stage_template_recipients: email_address gone, field_key present
SELECT column_name, data_type, is_nullable
  FROM information_schema.columns
 WHERE table_name = 'stage_template_recipients'
 ORDER BY ordinal_position;

-- stage_template_recipients constraint (3-way OR):
SELECT conname, pg_get_constraintdef(oid)
  FROM pg_constraint
 WHERE conrelid = 'stage_template_recipients'::regclass AND contype = 'c';

-- users.auth_id should now show is_nullable = YES:
SELECT column_name, is_nullable
  FROM information_schema.columns
 WHERE table_name = 'users' AND column_name = 'auth_id';
