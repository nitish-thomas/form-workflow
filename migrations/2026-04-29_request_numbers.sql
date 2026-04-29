-- Migration: 2026-04-29_request_numbers.sql
--
-- Adds per-form sequential request numbers to submissions.
--
-- Each form gets:
--   request_prefix  — short prefix for the form (e.g. "SFA", "SLR"). Admin-set.
--                     Defaults to the first 3 uppercase letters of the form title.
--   request_counter — auto-incrementing integer, bumped atomically on each new submission.
--
-- Each submission gets:
--   request_number  — the assigned sequence number (integer, e.g. 1, 2, 3…)
--   request_ref     — the human-readable reference (e.g. "SFA-0001"), computed column.
--
-- Usage in PHP:  read $submission['request_ref'] for display everywhere.
-- Atomic increment: done in webhook.php using a Supabase RPC call to
--   increment_form_counter(form_id UUID) which bumps request_counter and returns
--   the new value. This avoids race conditions on concurrent submissions.
-- ─────────────────────────────────────────────────────────────────────────────

-- 1. Add prefix + counter columns to forms
ALTER TABLE forms
    ADD COLUMN IF NOT EXISTS request_prefix  TEXT    NOT NULL DEFAULT '',
    ADD COLUMN IF NOT EXISTS request_counter INTEGER NOT NULL DEFAULT 0;

-- 2. Back-fill prefix for existing forms from the first 3 chars of the title
UPDATE forms
SET request_prefix = UPPER(REGEXP_REPLACE(SUBSTRING(title FROM 1 FOR 20), '[^A-Za-z]', '', 'g'))
WHERE request_prefix = '';

-- Trim to at most 5 chars
UPDATE forms
SET request_prefix = UPPER(LEFT(REGEXP_REPLACE(title, '[^A-Za-z]', '', 'g'), 5))
WHERE LENGTH(request_prefix) > 5 OR request_prefix = '';

-- 3. Add request_number to submissions
ALTER TABLE submissions
    ADD COLUMN IF NOT EXISTS request_number INTEGER;

-- 4. Back-fill existing submissions with sequential numbers per form
--    (ordered by submitted_at so numbering matches submission order)
WITH numbered AS (
    SELECT
        id,
        ROW_NUMBER() OVER (PARTITION BY form_id ORDER BY submitted_at ASC, created_at ASC) AS rn
    FROM submissions
    WHERE request_number IS NULL
)
UPDATE submissions s
SET request_number = n.rn
FROM numbered n
WHERE s.id = n.id;

-- 5. Update each form's counter to match its highest submission number
UPDATE forms f
SET request_counter = COALESCE((
    SELECT MAX(request_number)
    FROM submissions s
    WHERE s.form_id = f.id
), 0);

-- 6. Create the atomic-increment RPC function
--    Called from webhook.php: $sb->rpc('increment_form_counter', ['p_form_id' => $formId])
--    Returns the new counter value (integer) as a single-column result.
CREATE OR REPLACE FUNCTION increment_form_counter(p_form_id UUID)
RETURNS INTEGER
LANGUAGE plpgsql
SECURITY DEFINER
AS $$
DECLARE
    v_new_counter INTEGER;
BEGIN
    UPDATE forms
    SET request_counter = request_counter + 1
    WHERE id = p_form_id
    RETURNING request_counter INTO v_new_counter;

    RETURN v_new_counter;
END;
$$;

-- 7. Grant execute to service role (used by PHP backend)
GRANT EXECUTE ON FUNCTION increment_form_counter(UUID) TO service_role;
