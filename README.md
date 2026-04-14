# Aurora Approvals

Internal Google Forms approval workflow system for Aurora Early Education. Wraps approval flows around existing Google Forms. Clone of formapprovals.com built on Aurora's own infrastructure.

**Domain:** https://formworkflow.auroraearlyeducation.com.au
**Stack:** PHP (SiteGround) · Supabase (Postgres + Auth) · Google Apps Script bridge · Google Workspace OAuth

## Layout

```
├── *.php                   # Deployed PHP files (live on SiteGround)
├── schema.sql              # Phase 1 baseline schema (frozen — never edit)
├── config.example.php      # Template; copy to config.php and fill in secrets
├── migrations/             # Incremental schema changes
└── docs/                   # Obsidian vault — open this folder in Obsidian
    ├── architecture/       # How the system fits together
    ├── decisions/          # ADRs — one per significant decision
    ├── schema/             # DB reference
    ├── phases/             # Per-phase planning and retrospectives
    └── build-log/          # Dated session notes
```

## Running the schema in Supabase

1. On a fresh database: paste `schema.sql` into the Supabase SQL Editor and run
2. Then for each file in `migrations/` (in date order): paste and run

## Local dev

1. Clone the repo
2. `cp config.example.php config.php` and fill in your Supabase URL and keys
3. Deploy to SiteGround — keep `config.php` above `public_html` or `.htaccess`-protected

## Current phase

Phase 1 complete (auth foundation, 16-table schema deployed). Phase 2 (admin configuration UI) in planning — see `docs/phases/phase-2-plan.md`.
