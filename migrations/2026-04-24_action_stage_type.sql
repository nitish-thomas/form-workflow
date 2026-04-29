-- migrations/2026-04-24_action_stage_type.sql
--
-- Adds 'action' as a fourth stage type (checklist item / mark-as-done).
-- Also adds 'complete' as a valid action value in approval_tokens.
--
-- Run in Supabase SQL Editor before deploying the PHP changes.

-- ── 1. form_stages: allow stage_type = 'action' ──────────────────────────────
-- Supabase names constraints automatically; DROP IF EXISTS is safe.
ALTER TABLE form_stages
  DROP CONSTRAINT IF EXISTS form_stages_stage_type_check;

ALTER TABLE form_stages
  ADD CONSTRAINT form_stages_stage_type_check
  CHECK (stage_type IN ('approval', 'notification', 'signature', 'action'));

-- ── 2. stage_templates: same constraint ──────────────────────────────────────
ALTER TABLE stage_templates
  DROP CONSTRAINT IF EXISTS stage_templates_stage_type_check;

ALTER TABLE stage_templates
  ADD CONSTRAINT stage_templates_stage_type_check
  CHECK (stage_type IN ('approval', 'notification', 'signature', 'action'));

-- ── 3. approval_tokens: allow action = 'complete' ────────────────────────────
-- Existing values: 'approve', 'reject', 'sign'
ALTER TABLE approval_tokens
  DROP CONSTRAINT IF EXISTS approval_tokens_action_check;

ALTER TABLE approval_tokens
  ADD CONSTRAINT approval_tokens_action_check
  CHECK (action IN ('approve', 'reject', 'sign', 'complete'));

-- No changes needed to approvals.decision — action stages reuse 'approved'
-- when the recipient marks the item done.
