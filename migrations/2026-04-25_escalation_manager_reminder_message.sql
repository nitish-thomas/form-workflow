-- Migration: 2026-04-25_escalation_manager_reminder_message.sql
--
-- 1. escalation_manager_id on users
--    The person who receives escalation emails when THIS user's approvals are overdue.
--    Does not have to be their real-life manager — e.g. CEO's escalation contact
--    is their EA. Falls back to all admins if NULL.
--
-- 2. reminder_message on form_stages
--    Optional custom wording shown in reminder emails for a specific stage.
--    Leave NULL to use the default message.
--
-- Run in Supabase: SQL Editor → paste → Run

ALTER TABLE users
    ADD COLUMN IF NOT EXISTS escalation_manager_id UUID REFERENCES users(id) ON DELETE SET NULL;

ALTER TABLE form_stages
    ADD COLUMN IF NOT EXISTS reminder_message TEXT;
