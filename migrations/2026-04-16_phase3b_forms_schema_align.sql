-- ============================================================
-- Migration: Align forms table with the Phase 2 admin UI
-- Date:      2026-04-16
-- Author:    Aurora Form Workflow build (Nitish)
--
-- The original schema.sql created forms with:
--   name       TEXT NOT NULL
--   slug       TEXT UNIQUE NOT NULL
--   is_active  BOOLEAN NOT NULL DEFAULT TRUE
--
-- But the Phase 2 admin UI (forms.php) was written expecting:
--   title      TEXT NOT NULL
--   status     TEXT  ('draft' | 'active' | 'paused')
--   slug       not required from the UI
--
-- This migration bridges that gap non-destructively:
--   1. Adds a `title` column (the UI label) — kept in sync with `name`
--   2. Adds a `status` column replacing the boolean is_active
--   3. Makes `slug` nullable (auto-generated server-side if needed)
--   4. Makes `name` nullable (title is now the canonical label)
--
-- Safe to run on an existing schema. Non-destructive.
-- ============================================================

BEGIN;

-- ------------------------------------------------------------
-- 1. Add `title` column
-- ------------------------------------------------------------
ALTER TABLE forms
    ADD COLUMN IF NOT EXISTS title TEXT;

-- Backfill title from name for any existing rows
UPDATE forms SET title = name WHERE title IS NULL AND name IS NOT NULL;

-- ------------------------------------------------------------
-- 2. Add `status` column (replaces is_active boolean)
-- ------------------------------------------------------------
ALTER TABLE forms
    ADD COLUMN IF NOT EXISTS status TEXT NOT NULL DEFAULT 'draft'
    CHECK (status IN ('draft', 'active', 'paused'));

-- Backfill: rows that were is_active = true → 'active'
UPDATE forms SET status = 'active' WHERE is_active = TRUE AND status = 'draft';

-- ------------------------------------------------------------
-- 3. Make `slug` nullable
--    The UI doesn't generate slugs; we'll auto-generate server-side
--    if needed in future, but for now remove the hard requirement.
-- ------------------------------------------------------------
ALTER TABLE forms
    ALTER COLUMN slug DROP NOT NULL;

-- ------------------------------------------------------------
-- 4. Make `name` nullable
--    `title` is now the canonical display field.
--    `name` is kept for backward compatibility but not required.
-- ------------------------------------------------------------
ALTER TABLE forms
    ALTER COLUMN name DROP NOT NULL;

-- ------------------------------------------------------------
-- Verify
-- ------------------------------------------------------------
-- SELECT column_name, is_nullable, data_type, column_default
-- FROM   information_schema.columns
-- WHERE  table_name = 'forms'
-- ORDER  BY ordinal_position;
-- ============================================================

COMMIT;
