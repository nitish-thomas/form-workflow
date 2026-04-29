-- Migration: Add is_active toggle to form_stages
-- Run this in the Supabase SQL editor before deploying the form-stages.php update.

ALTER TABLE form_stages
    ADD COLUMN IF NOT EXISTS is_active boolean NOT NULL DEFAULT true;

-- All existing stages default to active (true), so this is safe to run on live data.
