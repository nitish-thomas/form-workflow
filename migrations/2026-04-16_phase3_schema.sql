-- ============================================================
-- Migration: Phase 3 Schema Changes
-- Date:      2026-04-16
-- Author:    Aurora Form Workflow build (Nitish)
--
-- Prepares the database for the live workflow engine:
--   1. Makes submissions.submitted_by nullable
--      (submitter may not have a users record yet)
--   2. Adds submissions.submitter_email
--      (raw email from Google Form — always present)
--
-- Run AFTER: 2026-04-15_phase2b_forms_google_form_id.sql
-- To run:    paste into Supabase SQL Editor → Run
-- ============================================================

BEGIN;

-- ------------------------------------------------------------
-- 1. Make submitted_by nullable
--    The webhook looks up the submitter by email. If they have
--    never logged into the approval system, there is no users row
--    yet. We store NULL and show the raw email in the UI instead.
-- ------------------------------------------------------------
ALTER TABLE submissions
    ALTER COLUMN submitted_by DROP NOT NULL;

COMMENT ON COLUMN submissions.submitted_by IS
    'FK to users.id. NULL when the submitter could not be matched
     to a registered user at submission time. Always check
     submitter_email for their address.';

-- ------------------------------------------------------------
-- 2. Add submitter_email
--    The Google Form always provides the respondent email.
--    We store it here so we can notify the submitter of the
--    outcome regardless of whether they have a users record.
-- ------------------------------------------------------------
ALTER TABLE submissions
    ADD COLUMN IF NOT EXISTS submitter_email TEXT;

COMMENT ON COLUMN submissions.submitter_email IS
    'Raw email address from the Google Form response (getRespondentEmail).
     Used to notify the submitter and to attempt matching to users.id.
     Always set by the webhook; never NULL in practice.';

-- Index for fast lookups (e.g. "show me all submissions by this email")
CREATE INDEX IF NOT EXISTS idx_submissions_submitter_email
    ON submissions (submitter_email);

-- ------------------------------------------------------------
-- Verify
-- ------------------------------------------------------------
-- SELECT column_name, is_nullable, data_type
-- FROM   information_schema.columns
-- WHERE  table_name = 'submissions'
--   AND  column_name IN ('submitted_by', 'submitter_email');
--
-- Expected:
--   submitted_by    YES   uuid
--   submitter_email YES   text
-- ============================================================

COMMIT;
