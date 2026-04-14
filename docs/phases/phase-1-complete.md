# Phase 1 — Foundation (Complete)

**Status:** Complete and deployed
**Dates:** Completed before 2026-04-15
**Deliverables:** 5 files

## What was built

- `schema.sql` — 16-table schema designed to support all 7 phases of the roadmap
- `config.php` — environment config, session setup, Supabase keys (gitignored)
- `supabase.php` — chainable REST API wrapper (`$sb->from()->select()->eq()->execute()`) plus static OAuth helpers using PKCE
- `index.php` — login page with Google sign-in; detects `?code=` from Supabase and forwards to `auth-callback.php`; redirects to dashboard if session exists
- `auth-callback.php` — receives auth code, retrieves PKCE verifier from session, exchanges for Supabase session, upserts user into `users` table, stores session, redirects to dashboard

## What works

- Google OAuth login via Supabase Auth (PKCE flow)
- User auto-provisioning on first login
- PHP session management with secure cookie settings
- Supabase REST API access from PHP using the service role key

## Known not-yet-built

- `dashboard.php` (404 after login, expected — Phase 2)
- `logout.php` (Phase 2)
- All admin configuration UI (Phase 2)
- The Apps Script webhook bridge (Phase 4)
