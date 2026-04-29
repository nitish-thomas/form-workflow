-- ============================================================
-- Migration: Align form_stages table with the Phase 2 admin UI
-- Date:      2026-04-16
-- Author:    Aurora Form Workflow build (Nitish)
--
-- The original schema.sql created form_stages with:
--   name  TEXT NOT NULL
--
-- But the Phase 2 admin UI (form-stages.php) inserts and reads:
--   stage_name  TEXT
--
-- This migration adds the stage_name column, backfills it from
-- name, and makes name nullable for backward compatibility.
-- ============================================================

BEGIN;

ALTER TABLE form_stages
    ADD COLUMN IF NOT EXISTS stage_name TEXT;

-- Backfill from name for any existing rows
UPDATE form_stages SET stage_name = name WHERE stage_name IS NULL AND name IS NOT NULL;

-- Make original name column nullable (stage_name is now canonical)
ALTER TABLE form_stages
    ALTER COLUMN name DROP NOT NULL;

COMMIT;
