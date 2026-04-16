-- ============================================================
-- Migration: Add google_form_id to forms table
-- Date:      2026-04-15
-- Author:    Aurora Approvals build (Nitish)
--
-- The forms table needs to store the Google Form's own ID so
-- the Phase 4 Apps Script webhook can match an incoming
-- submission to the correct Aurora approval workflow.
--
-- Admins will paste either the full Google Form URL or just
-- the form ID — the PHP forms.php page handles parsing.
--
-- Run AFTER: 2026-04-15_phase2_features.sql
-- ============================================================

ALTER TABLE forms
    ADD COLUMN IF NOT EXISTS google_form_id TEXT;

-- Unique: one workflow per Google Form
ALTER TABLE forms
    DROP CONSTRAINT IF EXISTS forms_google_form_id_key;

ALTER TABLE forms
    ADD CONSTRAINT forms_google_form_id_key UNIQUE (google_form_id);

-- Index for fast webhook lookups
CREATE INDEX IF NOT EXISTS idx_forms_google_form_id ON forms (google_form_id);

COMMENT ON COLUMN forms.google_form_id IS
    'The Google Form identifier (e.g. 1FAIpQLSc...). Used by the Apps Script webhook to route incoming submissions to this workflow. NULL until the admin links a Google Form.';

-- ============================================================
-- DONE
-- ============================================================
