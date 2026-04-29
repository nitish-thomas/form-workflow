-- =============================================================================
-- Phase 5 Schema Migration — 2026-04-16
-- Signature stage, per-stage reminders & escalation, cron tracking
-- Run once in Supabase SQL editor (or via psql).
-- =============================================================================

-- ── form_stages additions ─────────────────────────────────────────────────────
-- reminder_days   : send a reminder to pending approvers every N days (NULL = off)
-- escalation_days : escalate to admin after N days of no action (NULL = off)

ALTER TABLE form_stages
    ADD COLUMN IF NOT EXISTS reminder_days   INTEGER DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS escalation_days INTEGER DEFAULT NULL;

-- ── submission_stages additions ───────────────────────────────────────────────
-- last_reminder_sent_at : tracks when the last reminder was sent
-- reminder_count        : total reminders sent for this stage
-- signature_data        : base64 PNG from signature_pad.js
-- signed_at             : timestamp of signature capture
-- signer_email          : email of the person who signed

ALTER TABLE submission_stages
    ADD COLUMN IF NOT EXISTS last_reminder_sent_at TIMESTAMPTZ DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS reminder_count         INTEGER     NOT NULL DEFAULT 0,
    ADD COLUMN IF NOT EXISTS signature_data         TEXT        DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS signed_at              TIMESTAMPTZ DEFAULT NULL,
    ADD COLUMN IF NOT EXISTS signer_email           TEXT        DEFAULT NULL;

-- ── approval_tokens: add 'sign' action type ───────────────────────────────────
-- The existing 'action' column should already allow text values.
-- If it has a CHECK constraint limiting it to 'approve'/'reject', drop it first:
--
--   ALTER TABLE approval_tokens DROP CONSTRAINT IF EXISTS approval_tokens_action_check;
--   ALTER TABLE approval_tokens ADD CONSTRAINT approval_tokens_action_check
--       CHECK (action IN ('approve', 'reject', 'sign'));
--
-- If there is no CHECK constraint, no change needed — 'sign' will be stored fine.
-- Uncomment the two lines above only if you see a constraint violation when testing sign.php.

-- ── Verify ────────────────────────────────────────────────────────────────────
-- SELECT column_name, data_type FROM information_schema.columns
-- WHERE table_name = 'form_stages' ORDER BY ordinal_position;

-- SELECT column_name, data_type FROM information_schema.columns
-- WHERE table_name = 'submission_stages' ORDER BY ordinal_position;
