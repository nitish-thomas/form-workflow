-- migrations/2026-04-24_nullable_recipient_user_id.sql
--
-- Allows approval_tokens.recipient_user_id to be NULL so that dynamic
-- recipients whose email is not in the users table can still receive emails
-- and have a token created for them.
--
-- Run in Supabase SQL Editor before deploying the PHP changes for item 4a.

ALTER TABLE approval_tokens
  ALTER COLUMN recipient_user_id DROP NOT NULL;
