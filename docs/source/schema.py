"""
LedgerFlow ERP - single source of truth for the relational schema.

Every table, column, key and index in docs/04-Database-Design.md (data dictionary,
ER diagrams, FK list, relationship matrix, migration order) is generated from this
file by build_docs.py, so the diagrams cannot drift from the dictionary.

Conventions (see 04-Database-Design.md section 2):
- snake_case plural table names; BIGINT UNSIGNED surrogate `id` primary keys.
- Tenant tables carry company_id (FK companies, RESTRICT) and are always filtered by it.
- Money in base currency (BDT): DECIMAL(18,2). Foreign amounts: DECIMAL(18,2).
  Quantities: DECIMAL(18,3). Unit costs: DECIMAL(18,4). FX rates: DECIMAL(18,6).
  Percentages: DECIMAL(7,4). Never FLOAT/DOUBLE.
- Instants are DATETIME stored in UTC (not TIMESTAMP, whose range ends 2038-01-19).
- FK actions: ON UPDATE RESTRICT everywhere (surrogate keys never change, and MySQL 8.0 forbids
  CHECK constraints on columns used by a cascading FK action, error 3823). ON DELETE RESTRICT unless
  stated; users are never hard-deleted (deactivated instead), so every actor column is RESTRICT.
- Enumerations are VARCHAR(30) + CHECK constraint, mirrored by PHP backed enums.
"""

ID = "BIGINT UNSIGNED"
MONEY = "DECIMAL(18,2)"
QTY = "DECIMAL(18,3)"
COST = "DECIMAL(18,4)"
RATE = "DECIMAL(18,6)"
PCT = "DECIMAL(7,4)"
ENUM = "VARCHAR(30)"
BOOL = "BOOLEAN"
DATE = "DATE"
TS = "DATETIME"
JSON = "JSON"
TEXT = "TEXT"


def V(n):
    return f"VARCHAR({n})"


class Col:
    def __init__(self, name, type_, null=False, default=None, fk=None, on_delete="RESTRICT",
                 on_update="RESTRICT", note="", enum=None, generated=None):
        self.name, self.type, self.null, self.default = name, type_, null, default
        self.generated = generated  # STORED generated-column expression
        self.fk, self.on_delete, self.on_update, self.note, self.enum = fk, on_delete, on_update, note, enum


def c(*a, **k):
    return Col(*a, **k)


def fk(name, table, null=False, on_delete="RESTRICT", note="", ref="id"):
    return Col(name, ID if ref == "id" else "CHAR(3)", null=null, fk=f"{table}.{ref}", on_delete=on_delete, note=note)


def enum(name, values, default=None, null=False, note=""):
    return Col(name, ENUM, null=null, default=default, enum=values, note=note)


class Table:
    def __init__(self, name, module, purpose, cols, uniques=(), indexes=(), checks=(), tenant=True,
                 timestamps=True, audit_cols=True, version=False, junction=False, pk=None, append_only=False,
                 soft_delete=False):
        self.name, self.module, self.purpose = name, module, purpose
        self.uniques, self.indexes, self.checks = list(uniques), list(indexes), list(checks)
        self.tenant, self.junction, self.append_only = tenant, junction, append_only
        cols_out = []
        if pk is None:
            cols_out.append(Col("id", ID, note="Primary key, auto-increment"))
            self.pk = ["id"]
        else:
            self.pk = pk
        if tenant:
            cols_out.append(fk("company_id", "companies", note="Tenant owner. Every query is scoped by it."))
        cols_out += cols
        if version:
            cols_out.append(Col("lock_version", "INT UNSIGNED", default="1",
                                note="Optimistic concurrency counter; incremented on every update (If-Match)"))
        if audit_cols:
            cols_out.append(fk("created_by", "users", null=True, note="User who created the row"))
            cols_out.append(fk("updated_by", "users", null=True, note="User who last changed the row"))
        if timestamps:
            cols_out.append(Col("created_at", TS, null=True, note="Set by Laravel"))
            if not append_only:
                cols_out.append(Col("updated_at", TS, null=True, note="Set by Laravel"))
        if soft_delete:
            cols_out.append(Col("deleted_at", TS, null=True, note="Soft delete (master data only, never financial rows)"))
        self.cols = cols_out
        # every FK gets an index unless it leads a unique/index already
        for col in self.cols:
            if col.fk and self.pk[0] != col.name and not any(u[0] == col.name for u in self.uniques) and not any(i[0] == col.name for i in self.indexes):
                if not (tenant and col.name == "company_id"):
                    self.indexes.append([col.name])
        if tenant and not any(i[0] == "company_id" for i in self.indexes + self.uniques):
            self.indexes.insert(0, ["company_id"])

    def col(self, n):
        return next(x for x in self.cols if x.name == n)


T = Table
TABLES = []


def add(*tables):
    TABLES.extend(tables)


DOC_STATUS = ["draft", "posted", "cancelled"]
PAY_STATUS = ["unpaid", "part_paid", "paid"]

# ---------------------------------------------------------------- 1. Platform & identity
add(
    T("currencies", "Platform", "ISO 4217 reference list (BDT base, USD/EUR/CNY for imports). Global reference data.",
      [c("code", "CHAR(3)", note="ISO 4217 code, primary key"), c("name", V(60)), c("symbol", V(8)),
       c("minor_units", "TINYINT UNSIGNED", default="2", note="Decimal places")],
      pk=["code"], tenant=False, audit_cols=False),
    T("users", "Platform", "A person who can sign in. Global; membership of companies is in company_users.",
      [c("name", V(120)), c("email", V(190), note="Login identifier, stored lower-case"), c("phone", V(20), null=True),
       c("password_hash", V(255), note="Argon2id hash; never returned by the API"),
       enum("status", ["active", "invited", "disabled"], default="'invited'"),
       c("email_verified_at", TS, null=True),
       c("two_factor_secret", TEXT, null=True, note="TOTP secret, encrypted with APP_KEY (Laravel encrypted cast)"),
       c("two_factor_recovery_codes", TEXT, null=True, note="Encrypted JSON array of hashed recovery codes"),
       c("two_factor_confirmed_at", TS, null=True),
       c("password_changed_at", TS, null=True), c("last_login_at", TS, null=True),
       c("failed_login_count", "SMALLINT UNSIGNED", default="0"), c("locked_until", TS, null=True,
                                                                  note="Brute-force lockout end")],
      uniques=[["email"]], tenant=False, audit_cols=False),
    T("companies", "Platform", "A business (tenant). All business data hangs off a company.",
      [c("legal_name", V(190)), c("trading_name", V(190), null=True),
       enum("business_type", ["private_limited", "sole_proprietorship", "partnership", "public_limited"]),
       c("trade_licence_no", V(60), null=True), c("tax_registration_no", V(40), null=True, note="BIN/TIN as entered; format to be confirmed"),
       c("incorporated_on", DATE, null=True), c("registered_address", V(255), null=True), c("phone", V(30), null=True),
       c("email", V(190), null=True), c("website", V(190), null=True), c("logo_path", V(255), null=True, note="Storage path of logo image"),
       fk("base_currency_code", "currencies", ref="code", note="Locked once any journal entry exists"),
       c("fy_start_month", "TINYINT UNSIGNED", default="7", note="1-12; July for Bangladesh FY"),
       c("timezone", V(40), default="'Asia/Dhaka'"),
       enum("status", ["active", "suspended", "archived"], default="'active'"),
       c("seat_limit", "SMALLINT UNSIGNED", default="10", note="Users allowed (K8 shows 7 of 10)")],
      tenant=False, version=True),
    T("branches", "Platform", "A company location (head office, depot, outlet). Used for reporting filters and optional user restriction.",
      [c("code", V(20)), c("name", V(120)), c("kind", V(120), null=True, note="Free text, e.g. 'Office, warehouse, retail counter'"),
       c("address", V(255), null=True), c("phone", V(30), null=True), c("is_head_office", BOOL, default="0"), c("is_active", BOOL, default="1")],
      uniques=[["company_id", "code"]], version=True),
    T("payment_terms", "Platform", "Named credit terms (Cash, Net 7, Net 14, Net 15, Net 30, Net 45).",
      [c("name", V(40)), c("days", "SMALLINT UNSIGNED", note="Due date = document date + days"), c("is_default", BOOL, default="0")],
      uniques=[["company_id", "name"]]),
    T("fiscal_years", "Accounting", "Financial year (FY 2026-27 = 1 Jul 2026 - 30 Jun 2027).",
      [c("name", V(20)), c("start_date", DATE), c("end_date", DATE), enum("status", ["open", "closing", "closed"], default="'open'"),
       c("closed_at", TS, null=True), fk("closed_by", "users", null=True)],
      uniques=[["company_id", "start_date"], ["company_id", "name"]], checks=["end_date > start_date"]),
    T("accounting_periods", "Accounting", "Monthly posting period. Locked periods reject new, edited or reversed entries dated inside them.",
      [fk("fiscal_year_id", "fiscal_years", on_delete="CASCADE"), c("name", V(20)), c("start_date", DATE), c("end_date", DATE),
       enum("status", ["future", "open", "locked"], default="'future'"), c("locked_at", TS, null=True),
       fk("locked_by", "users", null=True), c("lock_note", V(255), null=True)],
      uniques=[["company_id", "start_date"]], indexes=[["company_id", "status"]], checks=["end_date >= start_date"]),
    T("roles", "Access", "A named set of permissions inside one company (Owner, Finance manager, Accountant, Sales staff, Store keeper, Viewer, custom).",
      [c("name", V(60)), c("slug", V(60)), c("description", V(255), null=True),
       c("is_system", BOOL, default="0", note="Owner role: cannot be edited or deleted"),
       c("payment_approval_limit", MONEY, null=True, note="Payments above this need approval; NULL = no limit, allowed only on the system Owner role (R-10)"),
       c("journal_approval_limit", MONEY, null=True, note="Journal vouchers above this need approval; NULL = no limit, allowed only on the system Owner role (R-10)")],
      uniques=[["company_id", "slug"]], version=True,
      checks=["is_system = 1 OR (payment_approval_limit IS NOT NULL AND journal_approval_limit IS NOT NULL)",
              "payment_approval_limit IS NULL OR payment_approval_limit >= 0", "journal_approval_limit IS NULL OR journal_approval_limit >= 0"]),
    T("permissions", "Access", "Global catalogue of permission keys, e.g. sales.create, accounting.approve, inventory.view_costs.",
      [c("key", V(80)), c("module", V(40)), c("action", V(30)), c("description", V(255))],
      uniques=[["key"]], tenant=False, audit_cols=False),
    T("role_permissions", "Access", "Junction: which permissions a role grants (many-to-many roles to permissions).",
      [fk("role_id", "roles", on_delete="CASCADE"), fk("permission_id", "permissions", on_delete="CASCADE")],
      pk=["role_id", "permission_id"], tenant=False, junction=True, timestamps=False, audit_cols=False),
    T("company_users", "Access", "Membership: a user belongs to a company with exactly one role (junction users to companies with payload).",
      [fk("user_id", "users", on_delete="CASCADE"), fk("role_id", "roles"),
       enum("status", ["active", "invited", "deactivated"], default="'invited'"),
       c("joined_at", TS, null=True), c("deactivated_at", TS, null=True), fk("deactivated_by", "users", null=True)],
      uniques=[["company_id", "user_id"]], junction=True, version=True),
    T("company_user_branches", "Access", "Optional branch restriction (many-to-many memberships to branches). No rows = all branches.",
      [fk("company_user_id", "company_users", on_delete="CASCADE"),
       fk("branch_id", "branches", note="RESTRICT: deleting a branch must not silently widen a restricted user to all branches")],
      pk=["company_user_id", "branch_id"], tenant=False, junction=True, timestamps=False, audit_cols=False),
    T("user_invitations", "Access", "Pending invitation e-mail (link valid 7 days).",
      [c("email", V(190)), c("name", V(120), null=True), fk("role_id", "roles"), c("token_hash", "CHAR(64)", note="SHA-256 of the emailed token"),
       c("expires_at", TS), c("accepted_at", TS, null=True), c("revoked_at", TS, null=True)],
      uniques=[["token_hash"]], indexes=[["company_id", "email"]]),
    T("user_invitation_branches", "Access", "Branch restriction chosen on the invitation (R-18); copied to company_user_branches on accept.",
      [fk("user_invitation_id", "user_invitations", on_delete="CASCADE"), fk("branch_id", "branches")],
      pk=["user_invitation_id", "branch_id"], tenant=False, junction=True, timestamps=False, audit_cols=False),
    T("user_sessions", "Access", "Refresh-token session per device. Enables 'Where you're signed in' and 'Sign out everywhere'.",
      [fk("user_id", "users", on_delete="CASCADE"), c("refresh_token_hash", "CHAR(64)", note="SHA-256 of the opaque refresh token"),
       c("family_id", "CHAR(36)", note="UUID shared by a rotation chain; reuse of an old token revokes the family"),
       fk("replaced_by_id", "user_sessions", null=True, on_delete="SET NULL", note="Next token in the rotation chain"),
       c("device_label", V(120), null=True), c("user_agent", V(255), null=True), c("ip_address", V(45), null=True),
       c("last_seen_at", TS, null=True), c("expires_at", TS), c("revoked_at", TS, null=True), c("revoked_reason", V(60), null=True)],
      uniques=[["refresh_token_hash"]], indexes=[["user_id", "revoked_at"], ["family_id"]], tenant=False, audit_cols=False),
    T("password_reset_tokens", "Access", "Single-use password reset tokens (60 minutes).",
      [fk("user_id", "users", on_delete="CASCADE"), c("token_hash", "CHAR(64)"), c("expires_at", TS), c("used_at", TS, null=True)],
      uniques=[["token_hash"]], tenant=False, audit_cols=False),
    T("user_preferences", "Access", "Per-user appearance settings (K13) and last company used.",
      [fk("user_id", "users", on_delete="CASCADE"), enum("theme", ["light", "dark", "system"], default="'system'"),
       enum("density", ["default", "compact"], default="'default'"), enum("text_size", ["s", "m", "l"], default="'m'"),
       c("reduce_motion", BOOL, default="0"), c("sidebar_collapsed", BOOL, default="0"),
       fk("last_company_id", "companies", null=True, on_delete="SET NULL")],
      uniques=[["user_id"]], tenant=False, audit_cols=False),
)

# ---------------------------------------------------------------- 2. Chart of accounts
add(
    T("account_groups", "Accounting", "Hierarchical chart-of-accounts groups (Primary > Group > Sub-group). Nature drives reports.",
      [fk("parent_id", "account_groups", null=True, note="NULL for the five primary groups"),
       c("code", V(20)), c("name", V(120)),
       enum("nature", ["asset", "liability", "equity", "income", "expense"]),
       enum("normal_balance", ["debit", "credit"]),
       enum("report_section", ["balance_sheet", "profit_and_loss"]),
       enum("cash_flow_category", ["operating", "investing", "financing", "cash"], null=True, note="Used by the indirect cash flow"),
       c("is_system", BOOL, default="0", note="Seeded groups cannot be deleted"), c("used_in_cash_vouchers", BOOL, default="0"),
       c("sort_order", "INT", default="0")],
      uniques=[["company_id", "code"]], version=True),
    T("tax_rules", "Accounting", "User-defined tax rule (K6). LedgerFlow never assumes rates.",
      [c("name", V(80)), c("rate", PCT, note="Entered by the user, e.g. 7.5000"), enum("applies_to", ["sales", "purchases", "both"]),
       fk("ledger_id", "ledgers", note="Liability or asset ledger where the tax collects"),
       c("is_recoverable", BOOL, default="1", note="Purchases: recoverable input tax posts to the rule's ledger; otherwise it is added to the line cost (R-14)"),
       c("is_active", BOOL, default="1", note="Rules used on any line are deactivated, never deleted")],
      uniques=[["company_id", "name"]], checks=["rate >= 0 AND rate <= 100"], version=True),
    T("ledgers", "Accounting", "A posting account. Customers, suppliers, banks, cash and warehouse inventory each get one.",
      [fk("account_group_id", "account_groups"), c("code", V(20)), c("name", V(150)), c("description", V(255), null=True),
       enum("ledger_kind", ["general", "party", "bank", "cash", "inventory", "tax", "system"], default="'general'"),
       c("track_bills", BOOL, default="0", note="Bill-by-bill allocation (parties)"),
       c("show_in_cash_vouchers", BOOL, default="0"),
       c("is_system", BOOL, default="0", note="Mapped system ledger: cannot be deleted or regrouped across natures"),
       c("is_active", BOOL, default="1", note="Ledgers with entries are deactivated, never deleted")],
      uniques=[["company_id", "code"]], indexes=[["company_id", "ledger_kind"], ["company_id", "name"]], version=True),
    T("account_mappings", "Accounting", "Configurable posting rules: maps a posting role (e.g. cogs, sales_returns) to a ledger or group. Replaces hard-coded account IDs.",
      [c("mapping_key", V(60), note="See 06 section 3 for the full list"),
       fk("ledger_id", "ledgers", null=True), fk("account_group_id", "account_groups", null=True, note="For group mappings such as sundry_debtors")],
      uniques=[["company_id", "mapping_key"]], checks=["(ledger_id IS NULL) <> (account_group_id IS NULL)"]),
    T("number_sequences", "Accounting", "Document number series per company, document type and financial year (INV-2026-0588).",
      [fk("fiscal_year_id", "fiscal_years", null=True, note="NULL for never-reset series (CUS, SUP)"), c("document_type", V(40)), c("prefix", V(20)),
       c("next_number", "INT UNSIGNED", default="1"), c("padding", "TINYINT UNSIGNED", default="4"),
       enum("reset_policy", ["fiscal_year", "never"], default="'fiscal_year'"),
       c("fy_scope", ID, generated="IFNULL(`fiscal_year_id`, 0)", note="Generated: 0 for never-reset series, so the unique key also covers them (R-15)")],
      uniques=[["company_id", "fy_scope", "document_type"]], version=True,
      checks=["(reset_policy = 'never') = (fiscal_year_id IS NULL)", "next_number >= 1"]),
)

# ---------------------------------------------------------------- 3. Settings
add(
    T("company_settings", "Platform", "One row per company: preferences, controls, currency display and invoice design (K3, K5, K6, K7, K14, K15).",
      [fk("default_warehouse_id", "warehouses", null=True, on_delete="SET NULL"), fk("default_payment_term_id", "payment_terms", null=True, on_delete="SET NULL"),
       enum("valuation_method", ["weighted_average", "fifo"], default="'weighted_average'", note="FIFO reserved for a later phase"),
       enum("invoice_rounding", ["none", "nearest_1", "nearest_5"], default="'nearest_1'"),
       c("allow_negative_stock", BOOL, default="0"), c("require_approval", BOOL, default="1"),
       c("backdate_warning_days", "SMALLINT UNSIGNED", default="7"), c("prices_include_tax", BOOL, default="0"),
       c("show_tax_no_on_invoice", BOOL, default="0"), enum("symbol_position", ["before", "after"], default="'before'"),
       c("table_decimals", "TINYINT UNSIGNED", default="2"), enum("negative_format", ["parentheses", "minus"], default="'parentheses'"),
       enum("number_grouping", ["lakh", "million"], default="'lakh'"), c("date_format", V(20), default="'d M Y'"),
       enum("week_start", ["saturday", "sunday", "monday"], default="'saturday'"), enum("print_language", ["en", "bn", "both"], default="'en'"),
       enum("invoice_template", ["classic", "banded", "minimal"], default="'classic'"), c("invoice_accent_color", "CHAR(7)", default="'#0b6b5e'"),
       c("invoice_show_logo", BOOL, default="1"), c("invoice_show_words", BOOL, default="1"), c("invoice_show_bank", BOOL, default="1"),
       c("invoice_show_signature", BOOL, default="1"), c("invoice_footer_note", V(255), null=True),
       c("require_two_factor", BOOL, default="0"), c("idle_timeout_minutes", "SMALLINT UNSIGNED", null=True, default="60", note="NULL = never"),
       c("backup_enabled", BOOL, default="1"), c("backup_retention_days", "SMALLINT UNSIGNED", default="90")],
      uniques=[["company_id"]], version=True),
    T("notification_preferences", "Platform", "Per user, company and event: in-app, e-mail and SMS switches (K12).",
      [fk("user_id", "users", on_delete="CASCADE"), c("event_key", V(60)), c("in_app", BOOL, default="1"), c("email", BOOL, default="0"), c("sms", BOOL, default="0")],
      uniques=[["company_id", "user_id", "event_key"]], audit_cols=False),
    T("notifications", "Platform", "In-app notification feed (L3) including approval requests.",
      [fk("user_id", "users", on_delete="CASCADE"), c("event_key", V(60)), c("title", V(190)), c("body", V(500), null=True),
       enum("category", ["approvals", "alerts", "info"], default="'info'"), c("link_type", V(60), null=True), c("link_id", ID, null=True),
       c("data", JSON, null=True), c("read_at", TS, null=True)],
      indexes=[["company_id", "user_id", "read_at"]], audit_cols=False),
    T("audit_logs", "Platform", "Append-only audit trail of every create/update/post/approve/reverse/cancel/delete and security event.",
      [fk("company_id", "companies", null=True, note="NULL for global security events"), fk("user_id", "users", null=True),
       c("action", V(40), note="created, updated, posted, approved, rejected, reversed, cancelled, deleted, voided_number, login, login_failed, ..."),
       c("auditable_type", V(60)), c("auditable_id", ID, null=True), c("old_values", JSON, null=True), c("new_values", JSON, null=True),
       c("ip_address", V(45), null=True), c("user_agent", V(255), null=True), c("request_id", "CHAR(36)", null=True)],
      indexes=[["company_id", "auditable_type", "auditable_id"], ["company_id", "created_at"]], tenant=False, audit_cols=False, append_only=True),
    T("attachments", "Platform", "Uploaded file metadata (JPG/PNG/PDF up to 10 MB). Polymorphic owner; files live in private object storage.",
      [c("attachable_type", V(60)), c("attachable_id", ID), c("original_name", V(255)), c("mime_type", V(100)),
       c("size_bytes", "INT UNSIGNED"), c("storage_disk", V(30)), c("storage_path", V(255)), c("sha256", "CHAR(64)")],
      indexes=[["company_id", "attachable_type", "attachable_id"]], checks=["size_bytes <= 10485760"]),
    T("idempotency_keys", "Platform", "Replay protection for POST/PUT that create or post documents (Idempotency-Key header).",
      [fk("company_id", "companies", null=True, note="RESTRICT (feeds a generated column). NULL for requests without company context, e.g. POST /companies (R-21)"),
       fk("user_id", "users", on_delete="CASCADE"), c("idempotency_key", V(80)), c("method", V(8)), c("path", V(255)),
       c("request_hash", "CHAR(64)"), c("response_status", "SMALLINT UNSIGNED", null=True), c("response_body", JSON, null=True),
       c("locked_until", TS, null=True), c("expires_at", TS),
       c("scope_company_id", ID, generated="IFNULL(`company_id`, 0)", note="Generated: 0 when there is no company")],
      uniques=[["scope_company_id", "user_id", "idempotency_key"]], indexes=[["expires_at"]], tenant=False, audit_cols=False),
    T("approval_requests", "Accounting", "A document waiting for approval because it is above the role limit (L4 drawer).",
      [c("document_type", V(40)), c("document_id", ID), c("amount", MONEY), c("required_permission", V(80)),
       enum("status", ["pending", "approved", "rejected", "cancelled"], default="'pending'"),
       fk("requested_by", "users"), c("requested_at", TS), fk("decided_by", "users", null=True),
       c("decided_at", TS, null=True), c("comment", V(500), null=True)],
      indexes=[["company_id", "status"], ["company_id", "document_type", "document_id"]]),
    T("company_backups", "Platform", "Per-company logical snapshot (automatic daily, manual, before restore, before period lock).",
      [enum("kind", ["automatic", "manual", "pre_restore", "pre_lock"]), enum("status", ["running", "complete", "failed", "expired"]),
       c("size_bytes", ID, null=True), c("storage_path", V(255), null=True), c("sha256", "CHAR(64)", null=True),
       c("started_at", TS), c("finished_at", TS, null=True), c("expires_at", TS, null=True), c("error", V(500), null=True)],
      indexes=[["company_id", "started_at"]]),
    T("export_jobs", "Platform", "Asynchronous export (month-end pack, report exports).",
      [enum("kind", ["export_pack", "report"]), c("parameters", JSON), enum("status", ["queued", "running", "complete", "failed"], default="'queued'"),
       c("file_path", V(255), null=True), c("finished_at", TS, null=True), c("error", V(500), null=True)],
      indexes=[["company_id", "status"]]),
)

# ---------------------------------------------------------------- 4. Parties
add(
    T("party_groups", "Parties", "Customer or supplier group used for filtering and reports (Wholesale, Edible oils, ...).",
      [enum("party_type", ["customer", "supplier"]), c("name", V(80))],
      uniques=[["company_id", "party_type", "name"]]),
    T("parties", "Parties", "Customer, prospect or supplier (local or international). Each has its own control-group ledger.",
      [enum("party_type", ["customer", "supplier"]), c("code", V(20), note="CUS-0012 / SUP-0004, generated"),
       c("name", V(190)), enum("category", ["business", "individual", "walk_in", "manufacturer", "distributor", "service_provider"], null=True),
       fk("party_group_id", "party_groups", null=True, on_delete="SET NULL"),
       c("contact_person", V(120), null=True), c("mobile", V(20), null=True, note="Required for local parties: 01XXXXXXXXX"),
       c("email", V(190), null=True), c("alt_phone", V(20), null=True), c("address", V(255), null=True),
       c("district", V(60), null=True), c("area", V(80), null=True), c("country_code", "CHAR(2)", default="'BD'"),
       fk("default_currency_code", "currencies", ref="code", note="BDT for local parties"),
       fk("payment_term_id", "payment_terms", null=True, on_delete="SET NULL"),
       c("credit_limit", MONEY, null=True, note="Customers only; warn before exceeding"),
       enum("price_tier", ["standard", "wholesale"], default="'standard'"),
       fk("default_purchase_ledger_id", "ledgers", null=True, on_delete="SET NULL", note="Suppliers: ledger for non-stock lines"),
       fk("ledger_id", "ledgers", note="The party's own ledger (1:1)"),
       c("tax_registration_no", V(40), null=True), c("port_of_loading", V(120), null=True, note="International suppliers"),
       c("notes", TEXT, null=True), enum("status", ["active", "inactive", "prospect"], default="'active'")],
      uniques=[["company_id", "code"], ["ledger_id"]], indexes=[["company_id", "party_type", "status"], ["company_id", "name"], ["company_id", "mobile"]],
      checks=["credit_limit IS NULL OR credit_limit >= 0"], version=True, soft_delete=True),
    T("party_bank_accounts", "Parties", "Bank details of a supplier (pay-to) or advising bank of an international supplier.",
      [fk("party_id", "parties", on_delete="CASCADE"), c("bank_name", V(120)), c("branch", V(120), null=True), c("account_name", V(150), null=True),
       c("account_number", V(40)), c("routing_number", V(20), null=True), c("swift_code", V(11), null=True), c("is_default", BOOL, default="0")]),
)

# ---------------------------------------------------------------- 5. Inventory
add(
    T("units", "Inventory", "Unit of measure (piece, pack, kg, bag, jar, bottle, case, trip).",
      [c("name", V(30)), c("decimals", "TINYINT UNSIGNED", default="0", note="Quantity precision allowed")],
      uniques=[["company_id", "name"]]),
    T("stock_groups", "Inventory", "Reporting hierarchy for stock valuation (D6). Does not choose the ledger (see GAP-20).",
      [fk("parent_id", "stock_groups", null=True), c("name", V(80)), c("sort_order", "INT", default="0")],
      uniques=[["company_id", "name"]]),
    T("product_categories", "Inventory", "Browsing and pricing categories, two levels (D5).",
      [fk("parent_id", "product_categories", null=True), c("name", V(80)), c("code_prefix", V(10), null=True),
       fk("default_unit_id", "units", null=True, on_delete="SET NULL"), fk("default_stock_group_id", "stock_groups", null=True, on_delete="SET NULL"),
       c("sort_order", "INT", default="0")],
      uniques=[["company_id", "name"]]),
    T("warehouses", "Inventory", "A place that holds stock (godown, depot, outlet, van). Owns its inventory ledger.",
      [fk("branch_id", "branches"), c("code", V(20)), c("name", V(120)), c("address", V(255), null=True),
       fk("manager_user_id", "users", null=True), c("phone", V(30), null=True),
       fk("inventory_ledger_id", "ledgers", note="Inventory ledger, e.g. 1301-01 Inventory, Tejgaon warehouse"),
       c("is_default_for_sales", BOOL, default="0"), enum("status", ["active", "planned", "inactive"], default="'active'"),
       c("opens_on", DATE, null=True), c("capacity_used_pct", PCT, null=True)],
      uniques=[["company_id", "code"]], version=True),
    T("products", "Inventory", "A physical product (stock-tracked) or a service (never stocked).",
      [enum("item_type", ["product", "service"]), c("code", V(30)), c("name", V(190)),
       fk("category_id", "product_categories", null=True, on_delete="SET NULL"), fk("stock_group_id", "stock_groups", null=True, on_delete="SET NULL"),
       fk("unit_id", "units"), c("barcode", V(40), null=True), c("last_purchase_cost", COST, null=True, note="Informational; valuation uses stock_balances"),
       c("selling_price", MONEY), c("wholesale_price", MONEY, null=True), fk("tax_rule_id", "tax_rules", null=True, on_delete="SET NULL"),
       fk("income_ledger_id", "ledgers", note="Sales income ledger for this item"),
       fk("expense_ledger_id", "ledgers", null=True, note="Services/non-stock purchases"),
       c("track_stock", BOOL, default="1", note="Always 0 for services"), fk("preferred_supplier_id", "parties", null=True, on_delete="SET NULL"),
       c("is_active", BOOL, default="1")],
      uniques=[["company_id", "code"], ["company_id", "barcode"]], indexes=[["company_id", "item_type", "is_active"], ["company_id", "name"]],
      checks=["selling_price >= 0", "item_type = 'product' OR track_stock = 0"], version=True, soft_delete=True),
    T("product_warehouse_settings", "Inventory", "Per-warehouse reorder level and reorder quantity.",
      [fk("product_id", "products", on_delete="CASCADE"), fk("warehouse_id", "warehouses", on_delete="CASCADE"),
       c("reorder_level", QTY, default="0"), c("reorder_qty", QTY, default="0")],
      uniques=[["product_id", "warehouse_id"]], checks=["reorder_level >= 0 AND reorder_qty >= 0"]),
    T("stock_balances", "Inventory", "Quantity, value and moving weighted average cost per product and warehouse (R-02). Maintained only by stock posting, locked FOR UPDATE. Company valuation = SUM over warehouses.",
      [fk("product_id", "products"), fk("warehouse_id", "warehouses"), c("qty_on_hand", QTY, default="0"), c("qty_in_transit_out", QTY, default="0"),
       c("total_value", MONEY, default="0", note="Inventory value of qty_on_hand; equals the warehouse ledger's share"),
       c("avg_unit_cost", COST, default="0", note="total_value / qty_on_hand, recomputed on every stock-in (BR-017)")],
      uniques=[["product_id", "warehouse_id"]], indexes=[["company_id", "warehouse_id"]], checks=["qty_in_transit_out >= 0", "avg_unit_cost >= 0"], audit_cols=False),
    T("stock_movements", "Inventory", "Immutable stock ledger: one row per quantity or value change, linked to its source document and journal.",
      [fk("product_id", "products"), fk("warehouse_id", "warehouses"),
       enum("movement_type", ["opening", "purchase_in", "sale_out", "sales_return_in", "purchase_return_out", "adjustment_in", "adjustment_out",
                              "transfer_out", "transfer_in", "landed_cost", "reversal"]),
       c("moved_on", DATE), c("quantity_in", QTY, default="0"), c("quantity_out", QTY, default="0"), c("unit_cost", COST),
       c("value_in", MONEY, default="0"), c("value_out", MONEY, default="0"),
       c("qty_balance_after", QTY, note="Warehouse quantity after this movement"), c("avg_cost_after", COST, note="Warehouse average cost after this movement"),
       c("source_type", V(40)), c("source_id", ID), c("source_line_id", ID, null=True),
       fk("journal_entry_id", "journal_entries", null=True), fk("reverses_movement_id", "stock_movements", null=True)],
      indexes=[["company_id", "product_id", "warehouse_id", "moved_on"], ["company_id", "source_type", "source_id"]],
      checks=["quantity_in >= 0 AND quantity_out >= 0", "NOT (quantity_in > 0 AND quantity_out > 0)"], append_only=True),
    T("stock_adjustments", "Inventory", "Count correction, damage, expiry, theft or samples (D10). Posts value difference.",
      [c("number", V(30)), c("adjustment_date", DATE), fk("warehouse_id", "warehouses"),
       enum("reason", ["count_difference", "damaged", "expired", "theft_or_loss", "free_samples"]), c("notes", TEXT, null=True),
       enum("status", ["draft", "posted", "reversed"], default="'draft'"), c("net_value", MONEY, default="0"),
       fk("journal_entry_id", "journal_entries", null=True), c("posted_at", TS, null=True), fk("posted_by", "users", null=True)],
      uniques=[["company_id", "number"], ["journal_entry_id"]], version=True),
    T("stock_adjustment_lines", "Inventory", "Counted vs system quantity per product.",
      [fk("stock_adjustment_id", "stock_adjustments", on_delete="CASCADE"), c("line_no", "SMALLINT UNSIGNED"), fk("product_id", "products"),
       c("system_qty", QTY), c("counted_qty", QTY), c("difference_qty", QTY, note="counted - system (signed)"), c("unit_cost", COST), c("value", MONEY, note="Signed")],
      uniques=[["stock_adjustment_id", "line_no"]], tenant=False, audit_cols=False),
    T("stock_transfers", "Inventory", "Move quantity between warehouses: Draft > Dispatched > Received (D11).",
      [c("number", V(30)), fk("from_warehouse_id", "warehouses"), fk("to_warehouse_id", "warehouses"), c("dispatch_date", DATE, null=True),
       c("received_date", DATE, null=True), c("vehicle", V(60), null=True), c("driver", V(120), null=True), c("notes", TEXT, null=True),
       enum("status", ["draft", "dispatched", "received", "cancelled"], default="'draft'"),
       fk("dispatch_entry_id", "journal_entries", null=True, note="Only when warehouses use different inventory ledgers"),
       fk("receive_entry_id", "journal_entries", null=True)],
      uniques=[["company_id", "number"], ["dispatch_entry_id"], ["receive_entry_id"]], checks=["from_warehouse_id <> to_warehouse_id"], version=True),
    T("stock_transfer_lines", "Inventory", "Products and quantities in a transfer.",
      [fk("stock_transfer_id", "stock_transfers", on_delete="CASCADE"), c("line_no", "SMALLINT UNSIGNED"), fk("product_id", "products"),
       c("quantity", QTY), c("unit_cost", COST, null=True, note="Average cost frozen at dispatch"), c("received_quantity", QTY, null=True)],
      uniques=[["stock_transfer_id", "line_no"]], checks=["quantity > 0"], tenant=False, audit_cols=False),
)

# ---------------------------------------------------------------- 6. Journal (accounting core)
add(
    T("journal_entries", "Accounting", "Voucher header for every posting: manual vouchers (JV/PV/RV/CV), income/expense entries and system entries generated by documents.",
      [fk("branch_id", "branches", null=True), fk("fiscal_year_id", "fiscal_years"), fk("accounting_period_id", "accounting_periods"),
       enum("entry_type", ["journal", "payment", "receipt", "contra", "expense", "income", "sales_invoice", "sales_return", "credit_note",
                           "purchase_bill", "purchase_return", "debit_note", "stock_adjustment", "stock_transfer", "lc_charge", "lc_payment",
                           "landed_cost", "cash_count", "opening_balance", "year_end_close", "reversal", "cancellation"]),
       c("voucher_number", V(30)), c("entry_date", DATE), c("reference", V(60), null=True), c("narration", V(1000), null=True),
       enum("status", ["draft", "submitted", "approved", "rejected", "posted", "reversed"], default="'draft'"),
       c("total_debit", MONEY, default="0"), c("total_credit", MONEY, default="0"),
       c("source_type", V(40), null=True, note="Originating document type for system entries"), c("source_id", ID, null=True),
       fk("reverses_entry_id", "journal_entries", null=True, note="Set on a reversing entry"),
       c("is_system_generated", BOOL, default="0"), c("template_key", V(40), null=True),
       c("submitted_at", TS, null=True), fk("submitted_by", "users", null=True),
       c("approved_at", TS, null=True), fk("approved_by", "users", null=True),
       c("posted_at", TS, null=True), fk("posted_by", "users", null=True)],
      uniques=[["company_id", "voucher_number"], ["reverses_entry_id"]],
      indexes=[["company_id", "entry_date", "status"], ["company_id", "entry_type", "status"], ["company_id", "source_type", "source_id"]],
      checks=["status NOT IN ('posted', 'reversed') OR total_debit = total_credit", "total_debit >= 0 AND total_credit >= 0"], version=True),
    T("journal_lines", "Accounting", "Debit or credit line of a journal entry. Posted lines are immutable.",
      [fk("journal_entry_id", "journal_entries", on_delete="CASCADE", note="CASCADE only matters for drafts; posted entries are never deleted"),
       c("line_no", "SMALLINT UNSIGNED"), fk("ledger_id", "ledgers"),
       c("debit", MONEY, default="0"), c("credit", MONEY, default="0"), c("line_narration", V(500), null=True),
       c("against_reference", V(60), null=True), fk("tax_rule_id", "tax_rules", null=True, note="RESTRICT: posted lines are immutable"),
       fk("foreign_currency_code", "currencies", null=True, ref="code"), c("foreign_amount", MONEY, null=True), c("exchange_rate", RATE, null=True),
       fk("warehouse_id", "warehouses", null=True), fk("letter_of_credit_id", "letters_of_credit", null=True, note="Tags LC clearing balances"),
       c("entry_date", DATE, note="Copy of header date for ledger reports"), c("is_posted", BOOL, default="0", note="Copy of header status = posted/reversed")],
      uniques=[["journal_entry_id", "line_no"]], indexes=[["company_id", "ledger_id", "is_posted", "entry_date"], ["letter_of_credit_id"]],
      checks=["debit >= 0 AND credit >= 0", "(debit = 0) <> (credit = 0)"], tenant=True, audit_cols=False),
    T("bill_allocations", "Accounting", "Applies a settlement line (receipt, payment, credit/debit note, advance) to an invoice or bill.",
      [fk("journal_line_id", "journal_lines", note="The party-ledger line that settles"), fk("sales_invoice_id", "sales_invoices", null=True),
       fk("purchase_bill_id", "purchase_bills", null=True), c("amount", MONEY), c("allocated_on", DATE),
       c("reversed_at", TS, null=True, note="Set when the settling entry is reversed or the document cancelled")],
      indexes=[["sales_invoice_id"], ["purchase_bill_id"], ["journal_line_id"]],
      checks=["amount > 0", "(sales_invoice_id IS NULL) <> (purchase_bill_id IS NULL)"]),
    T("money_accounts", "Money", "Cash, bank, mobile-wallet or card account; 1:1 with its ledger.",
      [fk("ledger_id", "ledgers"), enum("account_type", ["cash", "bank", "wallet", "card", "cash_credit"],
                                         note="cash_credit = bank CC/overdraft; its ledger sits under current liabilities (R-13)"), c("name", V(120)),
       c("overdraft_limit", MONEY, null=True, note="Bank or cash-credit limit; payments may take the balance down to minus this (BR-031)"),
       c("bank_name", V(120), null=True), c("branch_name", V(120), null=True), c("account_name", V(150), null=True),
       c("account_number", V(40), null=True), c("routing_number", "CHAR(9)", null=True),
       fk("currency_code", "currencies", ref="code"), fk("custodian_user_id", "users", null=True),
       c("use_for_receipts", BOOL, default="1"), c("use_for_payments", BOOL, default="1"), c("show_on_invoices", BOOL, default="0"),
       c("last_reconciled_on", DATE, null=True), c("is_active", BOOL, default="1")],
      uniques=[["ledger_id"]], indexes=[["company_id", "account_type"]], checks=["overdraft_limit IS NULL OR overdraft_limit >= 0"], version=True),
    T("payment_details", "Money", "Cash/bank detail of a receipt, payment, contra, income or expense entry (1:1 with journal_entries).",
      [fk("journal_entry_id", "journal_entries", on_delete="CASCADE"), enum("direction", ["in", "out", "transfer"]),
       fk("party_id", "parties", null=True), c("payee_text", V(150), null=True, note="Payer/payee when not a party"),
       fk("money_account_id", "money_accounts"), fk("counter_money_account_id", "money_accounts", null=True, note="Transfers"),
       enum("method", ["cash", "cheque", "bank_transfer", "beftn", "rtgs", "npsb", "bkash", "nagad", "card"]),
       c("cheque_number", V(20), null=True), c("cheque_date", DATE, null=True), c("drawn_on", V(120), null=True),
       c("transaction_reference", V(60), null=True), c("amount", MONEY), c("bank_charge", MONEY, default="0"),
       c("handed_to", V(120), null=True), c("unapplied_amount", MONEY, default="0", note="Advance left on the party ledger")],
      uniques=[["journal_entry_id"]], indexes=[["company_id", "money_account_id"], ["party_id"]], checks=["amount > 0", "bank_charge >= 0"]),
    T("ie_categories", "Money", "Income or expense category with its own ledger and optional monthly budget (I7).",
      [enum("kind", ["income", "expense"]), c("name", V(80)), c("description", V(255), null=True), fk("ledger_id", "ledgers"),
       c("monthly_budget", MONEY, null=True), c("warn_at_percent", PCT, default="90"), c("is_active", BOOL, default="1")],
      uniques=[["company_id", "kind", "name"], ["ledger_id"]]),
    T("recurring_templates", "Money", "Repeating expense/income/journal; each run creates a draft for confirmation (never auto-posts).",
      [enum("kind", ["expense", "income", "journal"]), enum("frequency", ["weekly", "monthly", "quarterly", "yearly"]),
       c("next_run_on", DATE), enum("ends_type", ["never", "after_count", "on_date"]), c("ends_after_count", "SMALLINT UNSIGNED", null=True),
       c("ends_on", DATE, null=True), c("occurrences_done", "SMALLINT UNSIGNED", default="0"), c("payload", JSON, note="Validated draft body"),
       fk("last_entry_id", "journal_entries", null=True, on_delete="SET NULL"), c("is_active", BOOL, default="1")],
      indexes=[["company_id", "is_active", "next_run_on"]], version=True),
    T("cash_counts", "Money", "Physical cash count by denomination (G2); a difference posts to Cash short/over.",
      [fk("money_account_id", "money_accounts"), c("counted_at", TS), fk("counted_by", "users"), c("book_balance", MONEY),
       c("counted_total", MONEY), c("difference", MONEY, note="counted - book"), c("note", V(255), null=True),
       enum("status", ["recorded", "adjusted", "reversed"], default="'recorded'"),
       fk("adjustment_entry_id", "journal_entries", null=True)],
      uniques=[["adjustment_entry_id"]]),
    T("cash_count_denominations", "Money", "Note/coin lines of a cash count.",
      [fk("cash_count_id", "cash_counts", on_delete="CASCADE"), c("denomination", MONEY), c("count", "INT UNSIGNED"), c("value", MONEY)],
      uniques=[["cash_count_id", "denomination"]], tenant=False, audit_cols=False),
    T("bank_reconciliations", "Money", "One reconciliation session per bank account and statement date.",
      [fk("money_account_id", "money_accounts"), c("statement_date", DATE), c("statement_balance", MONEY), c("book_balance", MONEY),
       c("difference", MONEY), enum("status", ["in_progress", "finished"], default="'in_progress'"), c("finished_at", TS, null=True),
       fk("finished_by", "users", null=True)],
      uniques=[["money_account_id", "statement_date"]], checks=["status <> 'finished' OR difference = 0"], version=True),
    T("bank_statement_imports", "Money", "An imported CSV/Excel statement file.",
      [fk("money_account_id", "money_accounts"), c("original_name", V(255)), c("file_path", V(255)), c("period_from", DATE), c("period_to", DATE),
       c("line_count", "INT UNSIGNED"), c("file_sha256", "CHAR(64)", note="Rejects re-import of the same file"),
       enum("status", ["to_match", "reconciled"], default="'to_match'")],
      uniques=[["money_account_id", "file_sha256"]]),
    T("bank_statement_lines", "Money", "A statement line and how it was matched to the books.",
      [fk("bank_statement_import_id", "bank_statement_imports", on_delete="CASCADE"), fk("money_account_id", "money_accounts"),
       c("line_no", "INT UNSIGNED"), c("txn_date", DATE), c("description", V(255)), c("bank_reference", V(80), null=True),
       c("amount", MONEY, note="Signed: + money in, - money out"),
       enum("status", ["unmatched", "matched", "created", "paired", "ignored"], default="'unmatched'"),
       fk("matched_journal_line_id", "journal_lines", null=True), fk("paired_line_id", "bank_statement_lines", null=True),
       fk("bank_reconciliation_id", "bank_reconciliations", null=True), fk("matched_by", "users", null=True),
       c("matched_at", TS, null=True)],
      uniques=[["bank_statement_import_id", "line_no"], ["matched_journal_line_id"]], indexes=[["company_id", "money_account_id", "status"]],
      audit_cols=False),
)


def doc_header(prefix, party_note, extra, status_values=DOC_STATUS, payment=True, version=True):
    cols = [fk("branch_id", "branches"), c("number", V(30), note=f"{prefix} number, reserved at first save, never reused"),
            fk("party_id", "parties", note=party_note),
            c("party_snapshot", JSON, null=True, note="Name, address, phone, tax no. frozen at posting/sending for reprints (R-30)")] + extra
    return cols


def totals(foreign=False):
    cols = [c("subtotal", MONEY, default="0", note="Sum of quantity x price before discount"),
            c("discount_total", MONEY, default="0"), c("tax_total", MONEY, default="0"),
            c("rounding_adjustment", MONEY, default="0"), c("total", MONEY, default="0", note="Base currency (BDT)")]
    return cols


def lines(parent, parent_tbl, extra):
    return ([fk(parent, parent_tbl, on_delete="CASCADE"), c("line_no", "SMALLINT UNSIGNED"), fk("product_id", "products"),
             c("product_code", V(30), note="Snapshot at save (R-30)"), c("product_name", V(190), note="Snapshot at save"),
             c("unit_name", V(30), note="Snapshot at save"),
             c("description", V(255), null=True), c("quantity", QTY)] + extra)


LINE_PRICING = [c("discount_pct", PCT, default="0"), c("discount_amount", MONEY, default="0"),
                fk("tax_rule_id", "tax_rules", null=True, note="RESTRICT: posted lines are immutable"), c("tax_rate", PCT, default="0", note="Snapshot at save"),
                c("tax_amount", MONEY, default="0"), c("net_amount", MONEY, note="qty x price - discount"), c("line_total", MONEY, note="net + tax")]

# ---------------------------------------------------------------- 7. Sales
add(
    T("quotations", "Sales", "Offer to a customer or prospect (E10). Never posts.",
      doc_header("QT", "Customer or prospect", [c("quotation_date", DATE), c("valid_until", DATE), fk("warehouse_id", "warehouses", null=True),
                                               fk("payment_term_id", "payment_terms", null=True), c("notes", TEXT, null=True), c("internal_note", TEXT, null=True)])
      + totals() + [enum("status", ["draft", "sent", "accepted", "declined", "expired", "converted"], default="'draft'"), c("sent_at", TS, null=True)],
      uniques=[["company_id", "number"]], indexes=[["company_id", "status", "valid_until"]], version=True),
    T("quotation_lines", "Sales", "Items quoted.",
      lines("quotation_id", "quotations", [c("unit_price", MONEY)] + LINE_PRICING),
      uniques=[["quotation_id", "line_no"]], checks=["quantity > 0"], tenant=False, audit_cols=False),
    T("sales_invoices", "Sales", "Sales invoice (E3-E6). Posting moves stock out and creates its journal entry.",
      doc_header("INV", "Customer", [c("customer_po_ref", V(60), null=True), c("invoice_date", DATE), c("due_date", DATE),
                                    fk("payment_term_id", "payment_terms", null=True), fk("warehouse_id", "warehouses", note="Dispatch from"),
                                    fk("quotation_id", "quotations", null=True, note="Created from quotation"), c("notes", TEXT, null=True),
                                    c("internal_note", TEXT, null=True)])
      + totals() + [c("amount_settled", MONEY, default="0", note="Sum of live bill_allocations"), c("balance_due", MONEY, default="0"),
                    enum("status", DOC_STATUS, default="'draft'"), enum("payment_status", PAY_STATUS, default="'unpaid'"),
                    fk("journal_entry_id", "journal_entries", null=True), fk("cancellation_entry_id", "journal_entries", null=True),
                    c("cancel_reason", V(60), null=True), c("cancelled_at", TS, null=True), fk("cancelled_by", "users", null=True),
                    c("challan_no", V(30), null=True), c("vehicle_no", V(40), null=True), c("delivered_at", TS, null=True),
                    c("posted_at", TS, null=True), fk("posted_by", "users", null=True)],
      uniques=[["company_id", "number"], ["journal_entry_id"], ["cancellation_entry_id"]],
      indexes=[["company_id", "status", "due_date"], ["company_id", "invoice_date"], ["party_id", "payment_status"]],
      checks=["due_date >= invoice_date", "balance_due >= 0", "total >= 0"], version=True),
    T("sales_invoice_lines", "Sales", "Invoice line with price, discount, tax and the cost snapshot used for COGS.",
      lines("sales_invoice_id", "sales_invoices", [c("unit_price", MONEY)] + LINE_PRICING +
            [fk("income_ledger_id", "ledgers", note="Snapshot of product income ledger"),
             c("unit_cost", COST, null=True, note="Average cost at posting (stocked items)"), c("cost_amount", MONEY, null=True),
             c("returned_quantity", QTY, default="0")]),
      uniques=[["sales_invoice_id", "line_no"]], checks=["quantity > 0", "unit_price >= 0", "returned_quantity <= quantity"], tenant=False, audit_cols=False),
    T("sales_returns", "Sales", "Goods coming back from a customer against an invoice (E8).",
      doc_header("SR", "Customer of the original invoice", [fk("sales_invoice_id", "sales_invoices"), c("return_date", DATE), fk("warehouse_id", "warehouses"),
                                                          enum("stock_action", ["restock", "write_off"]), c("notes", TEXT, null=True)])
      + [c("total", MONEY, default="0"), enum("status", DOC_STATUS, default="'draft'"), fk("journal_entry_id", "journal_entries", null=True, note="Stock part (restock only)"),
         c("posted_at", TS, null=True)],
      uniques=[["company_id", "number"], ["journal_entry_id"]], version=True),
    T("sales_return_lines", "Sales", "Returned quantity per original invoice line.",
      [fk("sales_return_id", "sales_returns", on_delete="CASCADE"), c("line_no", "SMALLINT UNSIGNED"), fk("sales_invoice_line_id", "sales_invoice_lines"),
       fk("product_id", "products"), c("quantity", QTY), c("unit_price", MONEY), c("value", MONEY), c("unit_cost", COST, null=True),
       enum("reason", ["expired", "damaged_in_transit", "leaking", "wrong_item", "not_needed"])],
      uniques=[["sales_return_id", "line_no"]], checks=["quantity > 0"], tenant=False, audit_cols=False),
    T("credit_notes", "Sales", "Reduces what a customer owes (E9): applied to an invoice, kept as credit, or refunded.",
      doc_header("CN", "Customer", [fk("sales_return_id", "sales_returns", null=True), fk("sales_invoice_id", "sales_invoices", null=True, note="Invoice it was created against"),
                                   c("note_date", DATE), enum("reason", ["goods_returned", "price_correction", "damaged_on_delivery", "goodwill"]),
                                   c("amount", MONEY), enum("usage", ["apply", "credit", "refund"])])
      + [enum("status", DOC_STATUS, default="'draft'"), fk("journal_entry_id", "journal_entries", null=True), c("posted_at", TS, null=True)],
      uniques=[["company_id", "number"], ["journal_entry_id"], ["sales_return_id"]], checks=["amount > 0"], version=True),
)

# ---------------------------------------------------------------- 8. Purchases
add(
    T("purchase_orders", "Purchases", "Order to a supplier (F8), local (BDT) or import (foreign currency). Never posts.",
      doc_header("PO", "Supplier", [c("order_date", DATE), c("expected_date", DATE, null=True), fk("warehouse_id", "warehouses", note="Deliver to"),
                                   fk("payment_term_id", "payment_terms", null=True), fk("currency_code", "currencies", ref="code"),
                                   c("exchange_rate", RATE, default="1", note="Planning rate for foreign POs"), c("source_note", V(120), null=True),
                                   c("notes", TEXT, null=True), c("internal_note", TEXT, null=True)])
      + totals() + [c("total_foreign", MONEY, null=True), enum("status", ["draft", "sent", "partly_received", "received", "closed", "cancelled"], default="'draft'")],
      uniques=[["company_id", "number"]], indexes=[["company_id", "status"]], version=True),
    T("purchase_order_lines", "Purchases", "Items ordered and quantity received so far.",
      lines("purchase_order_id", "purchase_orders", [c("unit_cost", COST, note="In PO currency")] + LINE_PRICING + [c("received_quantity", QTY, default="0")]),
      uniques=[["purchase_order_id", "line_no"]], checks=["quantity > 0", "received_quantity >= 0"], tenant=False, audit_cols=False),
    T("letters_of_credit", "LC", "Import letter of credit (M3/M4). Shipment lots, documents, amendments, charges and payments hang off it.",
      [fk("branch_id", "branches"), c("lc_number", V(30), note="Internal LC-2026-022"), fk("party_id", "parties", note="Beneficiary (supplier)"),
       fk("purchase_order_id", "purchase_orders", null=True), c("intended_import", V(255), null=True, note="When not yet linked to a PO"),
       c("goods_description", V(500)), c("quantity", QTY, null=True), c("quantity_unit", V(20), null=True), c("import_authorization_ref", V(60), null=True),
       enum("lc_type", ["irrevocable", "irrevocable_transferable", "standby"], default="'irrevocable'"),
       enum("tenor", ["sight", "usance"], default="'sight'"), c("usance_days", "SMALLINT UNSIGNED", null=True),
       c("incoterm", V(40)), fk("currency_code", "currencies", ref="code"), c("amount", MONEY, note="LC amount in LC currency"),
       c("tolerance_pct", PCT, default="0"), c("planning_rate", RATE, note="BDT per unit of LC currency, estimate only"),
       c("partial_shipments", BOOL, default="0"), c("transhipment", BOOL, default="0"), c("confirmation_required", BOOL, default="0"),
       fk("issuing_bank_account_id", "money_accounts", null=True, note="Our bank that issues the LC; required once submitted (BR-039)"), c("advising_bank", V(190), null=True),
       c("margin_pct", PCT, default="0"), fk("margin_account_id", "money_accounts", null=True),
       c("application_date", DATE, null=True), c("latest_shipment_date", DATE, null=True), c("expiry_date", DATE, null=True, note="Required once submitted (BR-039)"),
       c("presentation_days", "SMALLINT UNSIGNED", default="21"), c("maturity_date", DATE, null=True, note="Usance LCs"),
       enum("opening_status", ["not_applied", "submitted", "opened", "refused"], default="'not_applied'"),
       c("bank_reference", V(60), null=True, note="Required once opened"), c("opened_on", DATE, null=True),
       enum("status", ["draft", "awaiting_bank", "opened", "partly_shipped", "documents_presented", "payment_due", "closed", "cancelled", "expired"], default="'draft'"),
       c("record_shipments", BOOL, default="1"), c("bank_action_note", V(255), null=True), c("closed_on", DATE, null=True),
       fk("closed_by", "users", null=True)],
      uniques=[["company_id", "lc_number"]], indexes=[["company_id", "status", "expiry_date"]],
      checks=["amount > 0", "tolerance_pct >= 0", "tenor = 'sight' OR usance_days IS NOT NULL",
              "status = 'draft' OR (issuing_bank_account_id IS NOT NULL AND expiry_date IS NOT NULL)"], version=True),
    T("lc_shipments", "LC", "Shipment lot of an LC (lives inside LC Details; not a separate module).",
      [fk("letter_of_credit_id", "letters_of_credit", on_delete="CASCADE"), c("lot_no", "SMALLINT UNSIGNED"),
       enum("status", ["not_shipped", "booking_confirmed", "at_sea", "arrived", "arrived_cleared", "not_shipped_expired"], default="'not_shipped'"),
       c("booking_ref", V(60), null=True), c("bl_number", V(60), null=True), c("vessel", V(120), null=True),
       c("port_of_loading", V(120), null=True), c("port_of_discharge", V(120), null=True), enum("mode", ["sea", "air", "road"], default="'sea'"),
       c("etd", DATE, null=True), c("eta", DATE, null=True), c("shipped_on", DATE, null=True), c("arrived_on", DATE, null=True),
       c("quantity", QTY, null=True), c("value_foreign", MONEY, null=True)],
      uniques=[["letter_of_credit_id", "lot_no"]], version=True),
    T("purchase_bills", "Purchases", "Supplier bill (F3/F4) = goods receipt. Import mode links an LC and lot and carries a foreign currency.",
      doc_header("BILL", "Supplier", [c("supplier_bill_no", V(60), note="Required; unique per supplier"), c("bill_date", DATE), c("due_date", DATE),
                                     fk("payment_term_id", "payment_terms", null=True), fk("warehouse_id", "warehouses", note="Receive into"),
                                     fk("purchase_order_id", "purchase_orders", null=True), fk("letter_of_credit_id", "letters_of_credit", null=True),
                                     fk("lc_shipment_id", "lc_shipments", null=True), fk("currency_code", "currencies", ref="code"),
                                     c("exchange_rate", RATE, default="1", note="BDT per unit; 1 for BDT bills"), c("notes", TEXT, null=True),
                                     c("internal_note", TEXT, null=True), fk("received_by_user_id", "users", null=True),
                                     c("receipt_condition_note", V(255), null=True)])
      + totals() + [c("total_foreign", MONEY, null=True), c("amount_settled", MONEY, default="0"), c("balance_due", MONEY, default="0"),
                    enum("status", DOC_STATUS, default="'draft'"), enum("payment_status", PAY_STATUS, default="'unpaid'"),
                    fk("journal_entry_id", "journal_entries", null=True), fk("cancellation_entry_id", "journal_entries", null=True),
                    c("cancel_reason", V(60), null=True), c("cancelled_at", TS, null=True), fk("cancelled_by", "users", null=True),
                    c("posted_at", TS, null=True), fk("posted_by", "users", null=True)],
      uniques=[["company_id", "number"], ["company_id", "party_id", "supplier_bill_no"], ["journal_entry_id"], ["cancellation_entry_id"]],
      indexes=[["company_id", "status", "due_date"], ["company_id", "bill_date"], ["party_id", "payment_status"]],
      checks=["due_date >= bill_date", "exchange_rate > 0", "balance_due >= 0"], version=True),
    T("purchase_bill_lines", "Purchases", "Bill line: stocked product (debits inventory) or non-stock/service (debits expense ledger).",
      lines("purchase_bill_id", "purchase_bills", [c("unit_cost", COST, note="In bill currency")] + LINE_PRICING +
            [c("base_net_amount", MONEY, note="net_amount x exchange_rate, BDT"), fk("debit_ledger_id", "ledgers", note="Inventory or expense ledger snapshot"),
             fk("purchase_order_line_id", "purchase_order_lines", null=True), c("landed_cost_amount", MONEY, default="0"),
             c("returned_quantity", QTY, default="0")]),
      uniques=[["purchase_bill_id", "line_no"]], checks=["quantity > 0", "unit_cost >= 0", "returned_quantity <= quantity"], tenant=False, audit_cols=False),
    T("purchase_returns", "Purchases", "Goods going back to a supplier (F6).",
      doc_header("PR", "Supplier of the original bill", [fk("purchase_bill_id", "purchase_bills"), c("return_date", DATE), fk("warehouse_id", "warehouses"),
                                                       enum("stock_action", ["send_back", "already_disposed"]), c("notes", TEXT, null=True)])
      + [c("total", MONEY, default="0"), enum("status", DOC_STATUS, default="'draft'"), fk("journal_entry_id", "journal_entries", null=True), c("posted_at", TS, null=True)],
      uniques=[["company_id", "number"], ["journal_entry_id"]], version=True),
    T("purchase_return_lines", "Purchases", "Returned quantity per original bill line.",
      [fk("purchase_return_id", "purchase_returns", on_delete="CASCADE"), c("line_no", "SMALLINT UNSIGNED"), fk("purchase_bill_line_id", "purchase_bill_lines"),
       fk("product_id", "products"), c("quantity", QTY), c("unit_cost", COST, note="Original bill cost in BDT"), c("value", MONEY),
       enum("reason", ["leaking", "broken", "short_supply", "stale_stock", "torn_packs", "quality_claim"])],
      uniques=[["purchase_return_id", "line_no"]], checks=["quantity > 0"], tenant=False, audit_cols=False),
    T("debit_notes", "Purchases", "Reduces what you owe a supplier (F7).",
      doc_header("DN", "Supplier", [fk("purchase_return_id", "purchase_returns", null=True), fk("purchase_bill_id", "purchase_bills", null=True),
                                   c("note_date", DATE), enum("reason", ["goods_returned", "short_supply", "price_difference", "quality_claim"]),
                                   c("amount", MONEY), enum("usage", ["apply", "credit", "refund"])])
      + [enum("status", DOC_STATUS, default="'draft'"), fk("journal_entry_id", "journal_entries", null=True), c("posted_at", TS, null=True)],
      uniques=[["company_id", "number"], ["journal_entry_id"], ["purchase_return_id"]], checks=["amount > 0"], version=True),
)

# ---------------------------------------------------------------- 9. LC detail tables
add(
    T("lc_document_templates", "LC", "Named checklist template (Default import checklist, Agricultural goods, ...).",
      [c("name", V(80)), c("is_default", BOOL, default="0")], uniques=[["company_id", "name"]]),
    T("lc_document_template_items", "LC", "Document line of a template.",
      [fk("lc_document_template_id", "lc_document_templates", on_delete="CASCADE"), c("name", V(120)), c("provided_by", V(80)),
       c("copies", "TINYINT UNSIGNED", default="1"), c("note", V(255), null=True), c("required_by_default", BOOL, default="1"), c("sort_order", "INT", default="0")],
      tenant=False, audit_cols=False),
    T("lc_documents", "LC", "Required document of one LC and its status (scans in attachments).",
      [fk("letter_of_credit_id", "letters_of_credit", on_delete="CASCADE"), fk("lc_shipment_id", "lc_shipments", null=True, on_delete="SET NULL"),
       c("name", V(120)), c("provided_by", V(80)), c("copies", "TINYINT UNSIGNED", default="1"), c("note", V(255), null=True), c("is_required", BOOL, default="1"),
       enum("status", ["not_yet_due", "pending", "received", "missing", "discrepant", "not_required"], default="'not_yet_due'"),
       c("due_date", DATE, null=True), c("received_date", DATE, null=True), c("discrepancy_note", V(500), null=True)],
      indexes=[["letter_of_credit_id", "status"]], version=True),
    T("lc_amendments", "LC", "Bank amendment to LC terms (requested > issued > accepted/refused).",
      [fk("letter_of_credit_id", "letters_of_credit", on_delete="CASCADE"), c("amendment_no", "SMALLINT UNSIGNED"), c("requested_on", DATE),
       c("issued_on", DATE, null=True), c("accepted_on", DATE, null=True),
       enum("status", ["requested", "issued", "accepted", "refused"], default="'requested'"), c("reason", V(500), null=True),
       c("other_change", V(255), null=True), c("bank_fee", MONEY, default="0")],
      uniques=[["letter_of_credit_id", "amendment_no"]], version=True),
    T("lc_amendment_changes", "LC", "Field-level before/after of an amendment. Accepted changes update the LC.",
      [fk("lc_amendment_id", "lc_amendments", on_delete="CASCADE"), enum("field_key", ["expiry_date", "latest_shipment_date", "amount", "tolerance_pct", "other"]),
       c("old_value", V(120), null=True), c("new_value", V(120))], tenant=False, audit_cols=False),
    T("landed_cost_allocations", "LC", "Spreads LC charges and import expenses over the goods of an import bill.",
      [fk("letter_of_credit_id", "letters_of_credit"), fk("purchase_bill_id", "purchase_bills"), c("allocation_date", DATE),
       enum("method", ["value", "quantity"], default="'value'"), c("total_amount", MONEY),
       enum("status", ["draft", "posted", "reversed"], default="'draft'"), fk("journal_entry_id", "journal_entries", null=True)],
      uniques=[["journal_entry_id"]], checks=["total_amount > 0"], version=True),
    T("landed_cost_allocation_lines", "LC", "Share of a landed-cost allocation per bill line; split between stock on hand and COGS.",
      [fk("landed_cost_allocation_id", "landed_cost_allocations", on_delete="CASCADE"), fk("purchase_bill_line_id", "purchase_bill_lines"),
       fk("product_id", "products"), fk("warehouse_id", "warehouses"), c("basis_value", MONEY), c("allocated_amount", MONEY),
       c("qty_received", QTY), c("qty_on_hand", QTY), c("amount_to_inventory", MONEY), c("amount_to_cogs", MONEY)],
      checks=["amount_to_inventory + amount_to_cogs = allocated_amount"], tenant=False, audit_cols=False),
    T("lc_charges", "LC", "Bank charge or import expense of an LC (opening commission, insurance, C&F, port, duties as assessed, margin deposit, ...).",
      [fk("letter_of_credit_id", "letters_of_credit"), fk("lc_amendment_id", "lc_amendments", null=True),
       fk("lc_shipment_id", "lc_shipments", null=True),
       enum("charge_type", ["margin_deposit", "opening_commission", "amendment_fee", "swift_postage", "stamp_documentation", "acceptance_commission",
                            "discrepancy_fee", "marine_insurance", "cf_agent_fee", "port_handling", "customs_duty_taxes", "inland_transport", "other"]),
       c("charge_date", DATE), c("paid_to", V(150), null=True), fk("payee_party_id", "parties", null=True, note="When payable to a supplier ledger (C&F agent)"),
       c("amount", MONEY, note="BDT"), enum("status", ["unpaid", "paid"], default="'unpaid'"),
       enum("posting_status", ["draft", "posted", "reversed"], default="'posted'", note="Reversed through POST /lc-charges/{id}/reverse (R-03)"),
       fk("paid_from_account_id", "money_accounts", null=True), c("include_in_landed_cost", BOOL, default="1"),
       c("is_refundable", BOOL, default="0", note="Margin deposit: asset, never landed cost"), c("reference", V(60), null=True),
       fk("journal_entry_id", "journal_entries", null=True), fk("landed_cost_allocation_id", "landed_cost_allocations", null=True, on_delete="SET NULL")],
      uniques=[["journal_entry_id"]], indexes=[["letter_of_credit_id", "status"]],
      checks=["amount > 0", "NOT (is_refundable = 1 AND include_in_landed_cost = 1)"], version=True),
    T("lc_payments", "LC", "Payment to the beneficiary through the bank, at the bank's rate (sight or usance maturity).",
      [fk("letter_of_credit_id", "letters_of_credit"), fk("lc_shipment_id", "lc_shipments", null=True), fk("purchase_bill_id", "purchase_bills", null=True),
       enum("payment_kind", ["sight_payment", "usance_acceptance", "usance_settlement"], default="'sight_payment'",
            note="Usance: acceptance books the bank liability, settlement pays it at maturity"),
       c("payment_date", DATE), c("description", V(150), null=True), c("amount_foreign", MONEY), c("bank_rate", RATE), c("amount_bdt", MONEY, note="amount_foreign x bank_rate, rounded"),
       fk("money_account_id", "money_accounts", note="Debited from"), c("margin_applied", MONEY, default="0"), c("bank_reference", V(60), null=True),
       enum("status", ["draft", "posted", "reversed"], default="'draft'"), fk("journal_entry_id", "journal_entries", null=True)],
      uniques=[["journal_entry_id"]], checks=["amount_foreign > 0", "bank_rate > 0", "margin_applied >= 0"], version=True),
)

MODULE_ORDER = ["Platform", "Access", "Accounting", "Parties", "Inventory", "Money", "Sales", "Purchases", "LC"]

# Columns ending in _id that deliberately have no FK constraint (polymorphic or soft references).
# The validator fails on any other *_id column without a FK.
POLYMORPHIC = {
    "notifications.link_id": "Target of the notification link, typed by link_type (navigation only)",
    "audit_logs.auditable_id": "Audited row, typed by auditable_type (Eloquent morph)",
    "attachments.attachable_id": "Owning document or master record, typed by attachable_type (Eloquent morph)",
    "approval_requests.document_id": "Document awaiting approval, typed by document_type",
    "stock_movements.source_id": "Source document, typed by source_type; integrity via journal_entry_id and the document's own FKs",
    "stock_movements.source_line_id": "Source document line, typed by source_type",
    "journal_entries.source_id": "Originating document of a system entry, typed by source_type; the document holds the hard FK back",
}
