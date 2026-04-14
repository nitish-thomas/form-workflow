-- ============================================================
-- Aurora Approval Workflow — schema.sql
-- Run this in Supabase SQL Editor (Dashboard → SQL → New Query)
-- ============================================================

-- --------------------------------------------------------
-- 0. EXTENSIONS
-- --------------------------------------------------------
CREATE EXTENSION IF NOT EXISTS "uuid-ossp";

-- --------------------------------------------------------
-- 1. USERS  (synced from Supabase Auth on first login)
-- --------------------------------------------------------
CREATE TABLE users (
    id            UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    auth_id       UUID UNIQUE NOT NULL,            -- supabase auth.users.id
    email         TEXT UNIQUE NOT NULL,
    display_name  TEXT,
    avatar_url    TEXT,
    role          TEXT NOT NULL DEFAULT 'user'      -- 'admin' | 'user'
        CHECK (role IN ('admin', 'user')),
    is_active     BOOLEAN NOT NULL DEFAULT TRUE,
    created_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_users_auth_id ON users (auth_id);
CREATE INDEX idx_users_email   ON users (email);

-- --------------------------------------------------------
-- 2. FORMS  (the approval form definitions)
-- --------------------------------------------------------
CREATE TABLE forms (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name            TEXT NOT NULL,
    slug            TEXT UNIQUE NOT NULL,           -- URL-friendly key
    description     TEXT,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    allow_resubmit  BOOLEAN NOT NULL DEFAULT FALSE, -- opt-in per form
    created_by      UUID REFERENCES users(id),
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_forms_slug ON forms (slug);

-- --------------------------------------------------------
-- 3. FORM STAGES  (ordered approval stages per form)
-- --------------------------------------------------------
CREATE TABLE form_stages (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    form_id         UUID NOT NULL REFERENCES forms(id) ON DELETE CASCADE,
    stage_order     INT NOT NULL,                  -- 1, 2, 3 …
    name            TEXT NOT NULL,                 -- e.g. "Manager Approval"
    stage_type      TEXT NOT NULL DEFAULT 'approval'
        CHECK (stage_type IN ('approval', 'notification', 'signature')),
    approval_mode   TEXT NOT NULL DEFAULT 'any'
        CHECK (approval_mode IN ('any', 'all')),   -- any-one vs all-must-approve
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (form_id, stage_order)
);

CREATE INDEX idx_form_stages_form ON form_stages (form_id);

-- --------------------------------------------------------
-- 4. RECIPIENT GROUPS
-- --------------------------------------------------------
CREATE TABLE recipient_groups (
    id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    name        TEXT NOT NULL,
    description TEXT,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE group_members (
    id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    group_id    UUID NOT NULL REFERENCES recipient_groups(id) ON DELETE CASCADE,
    user_id     UUID NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    UNIQUE (group_id, user_id)
);

-- --------------------------------------------------------
-- 5. STAGE RECIPIENTS  (who approves at each stage)
--    A recipient is either a single user OR a group.
-- --------------------------------------------------------
CREATE TABLE stage_recipients (
    id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    stage_id    UUID NOT NULL REFERENCES form_stages(id) ON DELETE CASCADE,
    user_id     UUID REFERENCES users(id) ON DELETE CASCADE,
    group_id    UUID REFERENCES recipient_groups(id) ON DELETE CASCADE,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    CHECK (
        (user_id IS NOT NULL AND group_id IS NULL)
        OR
        (user_id IS NULL AND group_id IS NOT NULL)
    )
);

CREATE INDEX idx_stage_recipients_stage ON stage_recipients (stage_id);

-- --------------------------------------------------------
-- 6. ROUTING RULES  (conditional stage overrides)
-- --------------------------------------------------------
CREATE TABLE routing_rules (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    form_id         UUID NOT NULL REFERENCES forms(id) ON DELETE CASCADE,
    name            TEXT NOT NULL,
    description     TEXT,
    condition_json  JSONB NOT NULL DEFAULT '{}',    -- { "field": "amount", "op": ">", "value": 5000 }
    target_stage_id UUID REFERENCES form_stages(id) ON DELETE SET NULL,
    priority        INT NOT NULL DEFAULT 0,         -- higher = checked first
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_routing_rules_form ON routing_rules (form_id);

-- --------------------------------------------------------
-- 7. SUBMISSIONS  (a user submits a form for approval)
-- --------------------------------------------------------
CREATE TABLE submissions (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    form_id         UUID NOT NULL REFERENCES forms(id),
    submitted_by    UUID NOT NULL REFERENCES users(id),
    form_data       JSONB NOT NULL DEFAULT '{}',    -- the actual field values
    status          TEXT NOT NULL DEFAULT 'pending'
        CHECK (status IN (
            'pending',      -- awaiting first stage
            'in_progress',  -- moving through stages
            'approved',     -- all stages passed
            'rejected',     -- permanently closed
            'more_info',    -- parked — waiting for resubmit
            'cancelled'     -- withdrawn by submitter
        )),
    current_stage_id UUID REFERENCES form_stages(id),
    submitted_at    TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    completed_at    TIMESTAMPTZ,
    updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_submissions_form     ON submissions (form_id);
CREATE INDEX idx_submissions_user     ON submissions (submitted_by);
CREATE INDEX idx_submissions_status   ON submissions (status);

-- --------------------------------------------------------
-- 8. SUBMISSION STAGES  (tracks progress per stage)
-- --------------------------------------------------------
CREATE TABLE submission_stages (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    submission_id   UUID NOT NULL REFERENCES submissions(id) ON DELETE CASCADE,
    stage_id        UUID NOT NULL REFERENCES form_stages(id),
    status          TEXT NOT NULL DEFAULT 'pending'
        CHECK (status IN ('pending', 'approved', 'rejected', 'skipped', 'more_info')),
    started_at      TIMESTAMPTZ,
    completed_at    TIMESTAMPTZ,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_sub_stages_submission ON submission_stages (submission_id);
CREATE INDEX idx_sub_stages_status     ON submission_stages (status);

-- --------------------------------------------------------
-- 9. APPROVALS  (individual approve / reject / more-info actions)
-- --------------------------------------------------------
CREATE TABLE approvals (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    submission_stage_id UUID NOT NULL REFERENCES submission_stages(id) ON DELETE CASCADE,
    approver_id         UUID NOT NULL REFERENCES users(id),
    decision            TEXT NOT NULL
        CHECK (decision IN ('approved', 'rejected', 'more_info')),
    comments            TEXT,
    decided_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_approvals_stage    ON approvals (submission_stage_id);
CREATE INDEX idx_approvals_approver ON approvals (approver_id);

-- --------------------------------------------------------
-- 10. APPROVAL TOKENS  (secure one-click email actions)
-- --------------------------------------------------------
CREATE TABLE approval_tokens (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    submission_stage_id UUID NOT NULL REFERENCES submission_stages(id) ON DELETE CASCADE,
    recipient_user_id   UUID NOT NULL REFERENCES users(id),
    token               TEXT UNIQUE NOT NULL,
    action              TEXT NOT NULL
        CHECK (action IN ('approve', 'reject', 'more_info', 'view')),
    is_used             BOOLEAN NOT NULL DEFAULT FALSE,
    expires_at          TIMESTAMPTZ NOT NULL,
    created_at          TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_tokens_token   ON approval_tokens (token);
CREATE INDEX idx_tokens_expires ON approval_tokens (expires_at);

-- --------------------------------------------------------
-- 11. SIGNATURES  (Phase 4 — signature pad data)
-- --------------------------------------------------------
CREATE TABLE signatures (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    approval_id     UUID NOT NULL REFERENCES approvals(id) ON DELETE CASCADE,
    signature_data  TEXT NOT NULL,               -- base64 PNG from sig pad
    ip_address      TEXT,
    user_agent      TEXT,
    signed_at       TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- --------------------------------------------------------
-- 12. EMAIL TEMPLATES  (Phase 3)
-- --------------------------------------------------------
CREATE TABLE email_templates (
    id          UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    form_id     UUID REFERENCES forms(id) ON DELETE CASCADE,   -- NULL = global default
    stage_id    UUID REFERENCES form_stages(id) ON DELETE CASCADE, -- NULL = form-level
    event_type  TEXT NOT NULL
        CHECK (event_type IN (
            'approval_request', 'approved', 'rejected',
            'more_info', 'reminder', 'completed', 'cancelled'
        )),
    subject     TEXT NOT NULL,
    body_html   TEXT NOT NULL,
    created_at  TIMESTAMPTZ NOT NULL DEFAULT NOW(),
    updated_at  TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_email_tpl_form ON email_templates (form_id);

-- --------------------------------------------------------
-- 13. REMINDERS  (Phase 5 — cron-driven)
-- --------------------------------------------------------
CREATE TABLE reminders (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    form_id         UUID REFERENCES forms(id) ON DELETE CASCADE,
    stage_id        UUID REFERENCES form_stages(id) ON DELETE CASCADE,
    interval_hours  INT NOT NULL DEFAULT 24,
    max_reminders   INT NOT NULL DEFAULT 3,
    is_active       BOOLEAN NOT NULL DEFAULT TRUE,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE TABLE reminder_log (
    id                  UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    submission_stage_id UUID NOT NULL REFERENCES submission_stages(id) ON DELETE CASCADE,
    reminder_count      INT NOT NULL DEFAULT 1,
    sent_at             TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

-- --------------------------------------------------------
-- 14. AUDIT LOG  (Phase 6 — full trail)
-- --------------------------------------------------------
CREATE TABLE audit_log (
    id              UUID PRIMARY KEY DEFAULT uuid_generate_v4(),
    submission_id   UUID REFERENCES submissions(id) ON DELETE SET NULL,
    actor_id        UUID REFERENCES users(id) ON DELETE SET NULL,
    action          TEXT NOT NULL,                 -- e.g. 'submitted', 'approved', 'rejected'
    detail          JSONB DEFAULT '{}',
    ip_address      TEXT,
    created_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
);

CREATE INDEX idx_audit_submission ON audit_log (submission_id);
CREATE INDEX idx_audit_created    ON audit_log (created_at);

-- --------------------------------------------------------
-- 15. HELPER: auto-update updated_at columns
-- --------------------------------------------------------
CREATE OR REPLACE FUNCTION set_updated_at()
RETURNS TRIGGER AS $$
BEGIN
    NEW.updated_at = NOW();
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_users_updated       BEFORE UPDATE ON users             FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_forms_updated       BEFORE UPDATE ON forms             FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_form_stages_updated BEFORE UPDATE ON form_stages       FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_submissions_updated BEFORE UPDATE ON submissions       FOR EACH ROW EXECUTE FUNCTION set_updated_at();
CREATE TRIGGER trg_email_tpl_updated   BEFORE UPDATE ON email_templates   FOR EACH ROW EXECUTE FUNCTION set_updated_at();

-- --------------------------------------------------------
-- 16. ROW-LEVEL SECURITY  (enabled, policies added later)
-- --------------------------------------------------------
ALTER TABLE users             ENABLE ROW LEVEL SECURITY;
ALTER TABLE forms             ENABLE ROW LEVEL SECURITY;
ALTER TABLE form_stages       ENABLE ROW LEVEL SECURITY;
ALTER TABLE submissions       ENABLE ROW LEVEL SECURITY;
ALTER TABLE submission_stages ENABLE ROW LEVEL SECURITY;
ALTER TABLE approvals         ENABLE ROW LEVEL SECURITY;
ALTER TABLE approval_tokens   ENABLE ROW LEVEL SECURITY;
ALTER TABLE audit_log         ENABLE ROW LEVEL SECURITY;

-- Allow service_role full access (PHP backend uses service_role key)
-- These are permissive policies; the PHP backend is the trust boundary.
CREATE POLICY "service_role_all" ON users             FOR ALL USING (TRUE) WITH CHECK (TRUE);
CREATE POLICY "service_role_all" ON forms             FOR ALL USING (TRUE) WITH CHECK (TRUE);
CREATE POLICY "service_role_all" ON form_stages       FOR ALL USING (TRUE) WITH CHECK (TRUE);
CREATE POLICY "service_role_all" ON submissions       FOR ALL USING (TRUE) WITH CHECK (TRUE);
CREATE POLICY "service_role_all" ON submission_stages FOR ALL USING (TRUE) WITH CHECK (TRUE);
CREATE POLICY "service_role_all" ON approvals         FOR ALL USING (TRUE) WITH CHECK (TRUE);
CREATE POLICY "service_role_all" ON approval_tokens   FOR ALL USING (TRUE) WITH CHECK (TRUE);
CREATE POLICY "service_role_all" ON audit_log         FOR ALL USING (TRUE) WITH CHECK (TRUE);

-- ============================================================
-- DONE — Run this entire file in the Supabase SQL Editor.
-- ============================================================
