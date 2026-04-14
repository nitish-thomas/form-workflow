# Aurora Approvals — Documentation Vault

Open this `docs/` folder as an Obsidian vault to get linked navigation, backlinks, and graph view across all project notes.

## Quick links

- [[architecture/overview|Architecture overview]]
- [[architecture/path-b-integration|Apps Script ↔ PHP integration]]
- [[schema/overview|Database schema reference]]
- [[phases/phase-1-complete|Phase 1 — complete]]
- [[phases/phase-2-plan|Phase 2 — plan]]
- [[decisions/ADR-001-path-b-hybrid|ADR-001 — Path B hybrid architecture]]
- [[decisions/ADR-002-feature-additions|ADR-002 — Confirmed feature additions]]
- [[build-log/2026-04-15|Build log — 2026-04-15]]

## Folder structure

- `architecture/` — how the system fits together
- `decisions/` — Architecture Decision Records (ADRs), one per major decision
- `schema/` — database reference, kept in sync with `../migrations/`
- `phases/` — per-phase planning and retrospective notes
- `build-log/` — dated session notes (what was done, what was learned)

## Conventions

- One ADR per significant decision. Never edit a past ADR — supersede it with a new one.
- All dates are absolute (YYYY-MM-DD), never relative.
- Schema changes happen via files in `../migrations/`, never by editing `schema.sql`. The original `schema.sql` is the Phase 1 baseline and stays frozen.
