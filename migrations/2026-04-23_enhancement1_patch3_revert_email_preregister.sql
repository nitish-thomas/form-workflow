-- =============================================================================
-- Enhancement 1 — PATCH 3: Revert email_address columns + enable pre-registration
-- Date: 2026-04-23
--
-- Decision: dropped the manual email_address approach in favour of pre-registering
-- Aurora staff in the users table. All recipients will be real users rows.
-- External/non-Aurora recipients are not a use case.
--
-- This patch:
--   1. Removes email_address from stage_recipients + restores 3-way CHECK
--   2. Removes email_address from stage_template_recipients + restores 3-way CHECK
--      (field_key stays on both tables — dynamic recipients still supported)
--   3. Makes users.auth_id nullable so admins can pre-register staff who
--      haven't logged in yet. When they log in via OAuth, auth-callback.php
--      matches by email and fills in auth_id.
-- =============================================================================


-- ── 1. stage_recipients — drop email_address, restore 3-way constraint ────────
ALTER TABLE stage_recipients
    DROP CONSTRAINT IF EXISTS stage_recipients_recipient_check;

ALTER TABLE stage_recipients
    DROP COLUMN IF EXISTS email_address;

-- 3-way: specific user | recipient group | dynamic field key
ALTER TABLE stage_recipients
    ADD CONSTRAINT stage_recipients_recipient_check CHECK (
        (user_id IS NOT NULL AND group_id IS NULL  AND field_key IS NULL)
        OR
        (user_id IS NULL  AND group_id IS NOT NULL AND field_key IS NULL)
        OR
        (user_id IS NULL  AND group_id IS NULL     AND field_key IS NOT NULL)
    );


-- ── 2. stage_template_recipients — drop email_address, restore 3-way CHECK ────
ALTER TABLE stage_template_recipients
    DROP CONSTRAINT IF EXISTS stage_template_recipients_recipient_check;

ALTER TABLE stage_template_recipients
    DROP COLUMN IF EXISTS email_address;

ALTER TABLE stage_template_recipients
    ADD CONSTRAINT stage_template_recipients_recipient_check CHECK (
        (user_id IS NOT NULL AND group_id IS NULL  AND field_key IS NULL)
        OR
        (user_id IS NULL  AND group_id IS NOT NULL AND field_key IS NULL)
        OR
        (user_id IS NULL  AND group_id IS NULL     AND field_key IS NOT NULL)
    );


-- ── 3. users.auth_id — make nullable for pre-registration ─────────────────────
--
-- Pre-registered users (added by admin before first login) will have auth_id = NULL.
-- The UNIQUE constraint stays — Postgres treats NULL as distinct, so multiple
-- pre-registered users with auth_id = NULL are fine.
-- auth-callback.php must match by email on login and UPDATE auth_id at that point.
ALTER TABLE users
    ALTER COLUMN auth_id DROP NOT NULL;


-- ── Verify ────────────────────────────────────────────────────────────────────
-- stage_recipients columns (email_address should be gone):
SELECT column_name, data_type, is_nullable
  FROM information_schema.columns
 WHERE table_name = 'stage_recipients'
 ORDER BY ordinal_position;

-- stage_recipients constraint (should show 3-way OR with field_key):
SELECT conname, pg_get_constraintdef(oid)
  FROM pg_constraint
 WHERE conrelid = 'stage_recipients'::regclass AND contype = 'c';

-- stage_template_recipients columns (email_address should be gone):
SELECT column_name, data_type, is_nullable
  FROM information_schema.columns
 WHERE table_name = 'stage_template_recipients'
 ORDER BY ordinal_position;

-- users.auth_id nullable (is_nullable should now be YES):
SELECT column_name, is_nullable
  FROM information_schema.columns
 WHERE table_name = 'users' AND column_name = 'auth_id';
