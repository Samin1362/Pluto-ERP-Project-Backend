"""API registry: every REST endpoint of LedgerFlow /api/v1, consumed by build_api.py to produce
docs/05-API-Specification.md and docs/openapi.yaml.

E(method, path, summary, perm, tables, pages, ...)
  perm   : permission key from registry.ALL_PERMS, or "public" / "auth" (authenticated, no company perm)
  tables : database tables read/written (must exist in schema.py)
  pages  : page codes from pages.py that call the endpoint ("system" for background/internal use)
  req    : request schema name (components.schemas) or None
  resp   : response schema name; list endpoints use "Page<Schema>"
  idem   : True when Idempotency-Key is honoured (create/post endpoints)
  tx     : transaction / concurrency note
"""

EP = []


def E(method, path, summary, perm, tables, pages, rules=(), req=None, resp=None, params=(), idem=False, tx="", fr=(),
      codes=None, tag=None, ifmatch=False, desc="", perm_map=None, ops=None, errors=(), security=None, added=None):
    """perm_map : contextual permission (R-09) - {condition: permission}; perm is then "auth.self" and the
                  handler checks the mapped permission (x-permission-mode: contextual).
    ops        : database-operation overrides {table: "RCUDL"} merged over the derived defaults (contract.db_ops).
    errors     : extra application error codes (errors.py) beyond those derived from rules and method.
    security   : OpenAPI security override, e.g. "refreshCookie" for /auth/refresh and /auth/logout.
    added      : contract-review reference for endpoints added on 18 Oct 2026 (shown in the change log)."""
    EP.append(dict(method=method, path=path, summary=summary, perm=perm, tables=list(tables), pages=list(pages), rules=list(rules),
                   req=req, resp=resp, params=list(params), idem=idem, tx=tx, fr=list(fr), codes=codes, tag=tag, ifmatch=ifmatch, desc=desc,
                   perm_map=perm_map, ops=ops or {}, errors=list(errors), security=security, added=added))


LISTQ = ["page", "per_page", "sort", "q"]
DATEQ = ["date_from", "date_to"]
TXP = "One DB transaction; SELECT ... FOR UPDATE on the document row, number_sequences, stock_balances in a fixed order (sequence -> document -> products by id) to avoid deadlocks."

# ---------------------------------------------------------------- Auth & me
T = "Auth"
E("POST", "/auth/login", "Sign in with e-mail and password", "public", ["users", "user_sessions", "audit_logs", "company_users"], ["S1"],
  rules=["BR-061"], req="LoginRequest", resp="LoginResponse", tag=T, fr=["FR-001"],
  desc='Returns an access token, or a two-factor challenge when 2FA is on. Sets the refresh cookie `lf_rt` (HttpOnly, Secure, SameSite=Strict, Path=/api/v1/auth). Throttled per (e-mail, source IP): after 5 failures in 15 minutes that source gets 429 with Retry-After; wrong credentials and locked accounts both answer 401 `invalid_credentials` (no account enumeration, R-12).',
  codes=[200, 401, 422, 429], errors=['invalid_credentials', 'rate_limited'])
E("POST", "/auth/two-factor", "Complete sign-in with a TOTP or recovery code", "public", ["users", "user_sessions", "audit_logs"], ["S1"],
  req="TwoFactorRequest", resp="LoginResponse", tag=T, fr=["FR-001"], codes=[200, 401, 422, 429], errors=['two_factor_failed', 'rate_limited'])
E("POST", "/auth/refresh", "Rotate the refresh cookie and issue a new access token", "public", ["user_sessions"], ["system"], resp="TokenResponse",
  tag=T, fr=["FR-001"], tx="Locks the session row; a reused (already rotated) token revokes the whole family.", codes=[200, 401],
  security='refreshCookie', errors=['refresh_token_invalid', 'refresh_reuse_detected'])
E("POST", "/auth/logout", "Sign out this session (revokes refresh token, clears cookie)", 'public', ["user_sessions", "audit_logs"], ["S0"], tag=T,
  fr=["FR-002"], codes=[204], security='refreshCookie',
  desc='Authenticated by the refresh cookie alone, so it works after the access token has expired (R-36). Always 204; clears the cookie.')
E("POST", "/auth/password/forgot", "Send a password-reset link", "public", ["users", "password_reset_tokens"], ["S2"], req="ForgotPasswordRequest", tag=T, fr=["FR-003"],
  desc="Always 202 to avoid account enumeration.", codes=[202, 422, 429])
E("POST", "/auth/password/reset", "Set a new password with a reset token", "public", ["users", "password_reset_tokens", "user_sessions"], ["S2"], rules=["BR-061"],
  req="ResetPasswordRequest", tag=T, fr=["FR-003"], codes=[204, 422])
E("GET", "/invitations/{token}", "Show an invitation (company, role, e-mail)", "public", ["user_invitations", "companies", "roles"], ["S3"],
  resp="InvitationPublic", tag=T, fr=["FR-006"], codes=[200, 404, 410], errors=['gone'])
E("POST", "/invitations/{token}/accept", "Accept an invitation (creates or links the user)", "public",
  ['user_invitations', 'user_invitation_branches', 'users', 'company_users', 'company_user_branches'], ["S3"], req="AcceptInvitationRequest",
  resp="LoginResponse", tag=T, fr=["FR-006"], tx="Locks the invitation; seat limit re-checked.", rules=["BR-059"], codes=[200, 404, 409, 410, 422],
  errors=['gone', 'seat_limit_reached'])
T = "Me"
E("GET", "/me", "Current user, memberships and effective permissions in the active company", "auth", ["users", "company_users", "roles", "role_permissions", "permissions"], ["S0"],
  resp="Me", tag=T, fr=["FR-005"], desc="The X-Company-Id header is optional here; without it, permissions are omitted.")
E("PUT", "/me/password", "Change own password (revokes other sessions)", "auth", ["users", "user_sessions", "audit_logs"], ["K14"], rules=["BR-061"], req="ChangePasswordRequest", tag=T, fr=["FR-086"], codes=[204, 422])
E("POST", "/me/two-factor", "Start 2FA set-up (returns otpauth URI and QR)", "auth", ["users"], ["K14"], resp="TwoFactorSetup", tag=T, fr=["FR-004"],
  desc="Replaces an unconfirmed set-up. Refused with 409 `invalid_transition` while 2-step is on (turn it off first).", codes=[200, 409], errors=['invalid_transition'])
E("POST", "/me/two-factor/confirm", "Confirm 2FA with a code; returns recovery codes once", "auth", ["users", "audit_logs"], ["K14"],
  req="TwoFactorConfirm", resp="RecoveryCodes", tag=T, fr=["FR-004"], codes=[201, 401, 409, 422, 429], errors=['two_factor_failed', 'invalid_transition', 'rate_limited'])
E("DELETE", "/me/two-factor", "Turn off 2FA (requires password; blocked if the company requires 2FA)", "auth", ["users", "audit_logs"], ["K14"],
  req="PasswordConfirm", tag=T, fr=["FR-004"], codes=[204, 403, 422], errors=['two_factor_required_by_company', 'invalid_credentials'])
E("GET", "/me/sessions", "List own signed-in sessions", "auth", ["user_sessions"], ["K14"], resp="Page<Session>", tag=T, fr=["FR-009"])
E("DELETE", "/me/sessions/{id}", "Revoke one session", "auth", ["user_sessions", "audit_logs"], ["K14"], tag=T, fr=["FR-009"], codes=[204, 404])
E("POST", "/me/sessions/revoke-others", "Sign out everywhere else", "auth", ["user_sessions", "audit_logs"], ["K14"], tag=T, fr=["FR-002"], codes=[204])
E("GET", "/me/preferences", "Get appearance preferences", "auth", ["user_preferences"], ["K13"], resp="UserPreferences", tag=T, fr=["FR-086"])
E("PUT", "/me/preferences", "Save appearance preferences", "auth", ["user_preferences"], ["K13"], req="UserPreferences", resp="UserPreferences", tag=T, fr=["FR-086"])
E("GET", "/me/notification-preferences", "Get own notification switches for the active company", "auth.self", ["notification_preferences"], ["K12"], resp="NotificationPreferences", tag=T, fr=["FR-013"])
E("PUT", "/me/notification-preferences", "Save own notification switches", "auth.self", ["notification_preferences"], ["K12"], req="NotificationPreferences", resp="NotificationPreferences", tag=T, fr=["FR-013"])

# ---------------------------------------------------------------- Company & settings
T = "Company"
E("POST", "/companies", "Create a company (the creator becomes Owner; chart of accounts seeded)", "auth",
  ["companies", "company_settings", "company_users", "roles", "role_permissions", "fiscal_years", "accounting_periods", "account_groups", "ledgers", "account_mappings", "number_sequences", "payment_terms", "branches", "warehouses"],
  ["S0"], req="CompanyCreate", resp="Company", idem=True, tag=T, fr=["FR-005"], tx="CompanyProvisioner runs in one transaction (04 section 11.2).", codes=[201, 422])
E("GET", "/company", "Active company profile and locations", "settings.view", ["companies", "branches"], ["K1"], resp="Company", tag=T, fr=["FR-080"])
E("PATCH", "/company", "Edit company identity and contact", "settings.edit", ["companies", "audit_logs"], ["K2"], req="CompanyUpdate", resp="Company", tag=T, fr=["FR-080"], ifmatch=True)
E("PUT", "/company/logo", "Upload or replace the logo (PNG/SVG)", "settings.edit", ["companies"], ["K2"], req="FileUpload", resp="Company", tag=T,
  fr=["FR-080"], rules=["BR-051"], ifmatch=True)
E("DELETE", "/company/logo", "Remove the logo", "settings.edit", ["companies"], ["K2"], tag=T, fr=["FR-080"], codes=[204])
E("GET", "/company/settings", "Preferences, controls, currency display, tax display, invoice design, security and backup settings", "auth.self", ["company_settings"],
  ["K3", "K5", "K6", "K7", "K14", "K15", "S0"], resp="CompanySettings", tag=T, fr=["FR-081", "FR-083", "FR-085"], desc="Readable by every member (formatting depends on it); fields restricted by permission are omitted.")
E("PATCH", "/company/settings", "Update settings (partial)", "settings.edit", ["company_settings", "audit_logs", "journal_entries"], ["K3", "K5", "K6", "K7", "K14", "K15"],
  rules=["BR-035"], req="CompanySettingsUpdate", resp="CompanySettings", tag=T, fr=["FR-081", "FR-083", "FR-084", "FR-085"], ifmatch=True,
  desc="`require_two_factor` and backup fields additionally need Owner. Base currency cannot change (BR-035).")
E("GET", "/branches", "List locations (pickers and report filters)", "auth.self", ["branches"], ["K1", "S0"], resp="Page<Branch>", params=LISTQ, tag=T, fr=["FR-080"])
E("POST", "/branches", "Add a location", "settings.edit", ["branches"], ["K1"], req="BranchInput", resp="Branch", idem=True, tag=T, fr=["FR-080"], codes=[201, 422])
E("PATCH", "/branches/{id}", "Edit a location", "settings.edit", ["branches"], ["K1"], req="BranchInput", resp="Branch", ifmatch=True, tag=T, fr=["FR-080"])
E("GET", "/payment-terms", "List payment terms", "auth.self", ["payment_terms"], ["B2", "C2", "E3", "F3", "K3"], resp="Page<PaymentTerm>", tag=T, fr=["FR-081"])
E("GET", "/number-sequences", "Document numbering series for the current FY", "settings.view", ["number_sequences"], ["K3"], resp="Page<NumberSequence>", tag=T, fr=["FR-081"])
E("PATCH", "/number-sequences/{id}", "Change prefix, padding or next number (never below the last used)", "settings.edit", ["number_sequences", "audit_logs"], ["K3"], rules=["BR-006"],
  req="NumberSequenceUpdate", resp="NumberSequence", ifmatch=True, tag=T, fr=["FR-081"], tx="Locks the sequence row.")
E("GET", "/tax-rules", "List tax rules", "auth.self", ["tax_rules", "ledgers"], ["K6", "D2", "E3", "F3", "I6"], resp="Page<TaxRule>", tag=T, fr=["FR-084"])
E("POST", "/tax-rules", "Add a tax rule (user-entered rate)", "settings.edit", ["tax_rules", "ledgers"], ["K6"], rules=["BR-036"], req="TaxRuleInput", resp="TaxRule", idem=True, tag=T, fr=["FR-084"], codes=[201, 422])
E("PATCH", "/tax-rules/{id}", "Edit or deactivate a tax rule (rate changes apply to new documents only)", "settings.edit", ["tax_rules"], ["K6"], rules=["BR-036"], req="TaxRuleInput", resp="TaxRule", ifmatch=True, tag=T, fr=["FR-084"])
E("GET", "/account-mappings", "Posting-rule ledger mappings", "accounting.view", ["account_mappings", "ledgers", "account_groups"], ["K16"], resp="AccountMappings", tag=T, fr=["FR-075"])
E("PUT", "/account-mappings", "Save mappings (nature-checked per key)", "settings.edit", ["account_mappings", "audit_logs"], ["K16"], rules=["BR-064"], req="AccountMappings", resp="AccountMappings", tag=T, fr=["FR-075"])
T = "Fiscal years"
E("GET", "/fiscal-years", "Financial years with period status", "auth.self", ["fiscal_years", "accounting_periods"], ["K4", "S0"], resp="Page<FiscalYear>", tag=T, fr=["FR-082"])
E("POST", "/accounting-periods/{id}/lock", "Lock a period (earlier periods must be locked)", "accounting.lock_period", ["accounting_periods", "company_backups", "audit_logs"], ["K4"], rules=["BR-033"],
  req="PeriodLockRequest", resp="AccountingPeriod", tag=T, fr=["FR-082"], tx="Locks period rows of the FY; queues a pre_lock backup.")
E("POST", "/accounting-periods/{id}/unlock", "Unlock a period with a reason (later periods must be open)", "accounting.lock_period", ["accounting_periods", "audit_logs"], ["K4"], rules=["BR-033"],
  req="ReasonRequest", resp="AccountingPeriod", tag=T, fr=["FR-082"])
E("POST", "/fiscal-years/{id}/close", "Close the financial year (posts year-end entry, opens next FY)", "accounting.close_year",
  ["fiscal_years", "accounting_periods", "journal_entries", "journal_lines", "number_sequences", "audit_logs"], ["K4"], rules=["BR-034", "BR-001"], idem=True, resp="FiscalYear", tag=T, fr=["FR-082"], tx=TXP)
T = "Users and roles"
E("GET", "/users", "Members of the company with role and status", "settings.users", ["company_users", "users", "roles", "user_invitations"], ["K8"], params=LISTQ, resp="Page<Member>", tag=T, fr=["FR-006"])
E("GET", "/users/{id}", "Member details, recent activity and role permissions", "settings.users", ["company_users", "users", "roles", "audit_logs"], ["K10"], resp="Member", tag=T, fr=["FR-008"])
E("PATCH", "/users/{id}", "Change a member's role or branch restriction", "settings.users", ["company_users", "company_user_branches", "audit_logs"], ["K10"], rules=["BR-060", "BR-038"], req="MemberUpdate", resp="Member", ifmatch=True, tag=T, fr=["FR-007"])
E("POST", "/users/{id}/deactivate", "Deactivate a member (revokes sessions; their pending approvals stay queued)", "settings.users", ["company_users", "user_sessions", "audit_logs"], ["K10"], rules=["BR-061", "BR-060"], resp="Member", tag=T, fr=["FR-008"])
E("POST", "/users/{id}/reactivate", "Reactivate a member (seat check)", "settings.users", ["company_users"], ["K10"], rules=["BR-059"], resp="Member", tag=T, fr=["FR-008"])
E("POST", "/invitations", "Invite someone by e-mail with a role", "settings.users",
  ['user_invitations', 'user_invitation_branches', 'company_users', 'companies', 'branches'], ["K9"], rules=["BR-059"], req="InvitationCreate",
  resp="Invitation", idem=True, tag=T, fr=["FR-006"], codes=[201, 409, 422])
E("POST", "/invitations/{id}/resend", "Resend an invitation (new token, new 7-day expiry)", "settings.users", ["user_invitations"], ["K10"], resp="Invitation", tag=T, fr=["FR-006"])
E("DELETE", "/invitations/{id}", "Revoke an invitation", "settings.users", ["user_invitations"], ["K8"], tag=T, fr=["FR-006"], codes=[204])
E("GET", "/roles", "Roles with member counts", "settings.roles", ["roles", "company_users"], ["K11", "K9"], resp="Page<Role>", tag=T, fr=["FR-007"])
E("GET", "/roles/{id}", "Role with permission matrix and approval limits", "settings.roles", ["roles", "role_permissions", "permissions"], ["K11"], resp="Role", tag=T, fr=["FR-007"])
E("POST", "/roles", "Create a role (optionally copying another)", "settings.roles", ["roles", "role_permissions"], ["K11"], req="RoleInput", resp="Role", idem=True, tag=T, fr=["FR-007"], codes=[201, 422])
E("PUT", "/roles/{id}", "Replace a role's permissions and limits (Owner role is locked)", "settings.roles", ["roles", "role_permissions", "audit_logs"], ["K11"], rules=["BR-060"], req="RoleInput", resp="Role", ifmatch=True, tag=T, fr=["FR-007"], codes=[200, 403, 409, 422])
E("DELETE", "/roles/{id}", "Delete a role with no members", "settings.roles", ["roles", "role_permissions"], ["K11"], tag=T, fr=["FR-007"], codes=[204, 409])
E("GET", "/permissions", "Permission catalogue", "settings.roles", ["permissions"], ["K11"], resp="PermissionList", tag=T, fr=["FR-007"])

# ---------------------------------------------------------------- Platform services
T = "Platform"
E("GET", "/search", "Global search across parties, documents, vouchers, ledgers and products (permission-filtered)", "auth.self",
  ["parties", "sales_invoices", "purchase_bills", "journal_entries", "ledgers", "products", "letters_of_credit"], ["L1", "L2", "S0"], rules=["BR-037", "BR-052"],
  params=["q", "type", "limit"], resp="SearchResults", tag=T, fr=["FR-012"], desc="`q` >= 2 characters; numbers and amounts matched exactly, names by prefix. Types the user cannot view are omitted.")
E("GET", "/notifications", "Notification feed", "auth.self", ["notifications"], ["L3", "S0"], params=["page", "per_page", "category", "unread"], resp="Page<Notification>", tag=T, fr=["FR-013"])
E("POST", "/notifications/{id}/read", "Mark a notification read", "auth.self", ["notifications"], ["L3"], tag=T, fr=["FR-013"], codes=[204])
E("POST", "/notifications/read-all", "Mark all read", "auth.self", ["notifications"], ["L3"], tag=T, fr=["FR-013"], codes=[204])
E("GET", "/approvals", "Approval requests (pending for me, or all)", "auth.self", ["approval_requests", "journal_entries"], ['L3', 'L4', 'H1'],
  params=["status", "page"], resp="Page<ApprovalRequest>", tag=T, fr=["FR-014"])
E("GET", "/approvals/{id}", "One approval request with the document facts shown in the L4 drawer", "auth.self", ["approval_requests", "journal_entries", "journal_lines", "users"], ["L4", "H13"], resp="ApprovalRequest", tag=T, fr=["FR-014"], perm_map={"any": "the requester, or a holder of the request's required_permission"}, added="C-03")
E("POST", "/approvals/{id}/approve", "Approve and post the document", 'auth.self',
  ["approval_requests", "journal_entries", "journal_lines", "bill_allocations", "audit_logs", "notifications"], ["L4", "H13"],
  rules=["BR-007", "BR-008", "BR-001", "BR-004"], req="ApprovalDecision", resp="ApprovalRequest", idem=True, tx=TXP, tag=T, fr=["FR-014"],
  codes=[200, 403, 409, 422],
  desc="Permission is module-specific: `banking.approve` for cash vouchers, `purchases.approve` for supplier payments, `accounting.approve` for journals (the request's `required_permission`).",
  perm_map={'any': 'the request\'s required_permission (accounting.approve for journals, banking.approve for cash vouchers and cash-count differences, purchases.approve for supplier payments); never the submitter (BR-008)'},
  errors=['self_approval_forbidden', 'not_draft'])
E("POST", "/approvals/{id}/reject", "Reject with a required comment (document returns to draft as rejected)", 'auth.self',
  ["approval_requests", "journal_entries", "audit_logs", "notifications"], ["L4", "H13"], rules=["BR-008"], req="ApprovalDecision",
  resp="ApprovalRequest", tag=T, fr=["FR-014"],
  perm_map={'any': 'the request\'s required_permission (accounting.approve for journals, banking.approve for cash vouchers and cash-count differences, purchases.approve for supplier payments); never the submitter (BR-008)'},
  errors=['comment_required', 'self_approval_forbidden'])
E("POST", "/attachments", "Upload a file to a record (multipart)", "auth.self", ["attachments"],
  ["E5", "F4", "G6", "G7", "I3", "I6", "I2", "I5", "M4", "H13"], rules=["BR-051"], req="AttachmentUpload", resp="Attachment", idem=True, tag=T,
  fr=["FR-066"], desc="Caller needs edit permission on the target record; 10 MB limit; content-type sniffed.", codes=[201, 413, 415, 422],
  perm_map={'any': '<module>.edit of the record the file belongs to (create for a draft)'}, errors=['file_type_not_allowed', 'file_too_large'])
E("GET", "/attachments/{id}/url", "Short-lived signed download URL (5 minutes)", "auth.self", ["attachments"], ["E5", "F4", "I5", "M4"],
  resp="SignedUrl", tag=T, fr=["FR-066"], perm_map={'any': '<module>.view of the record named by type/id (e.g. sales.view for a sales_invoice)'})
E("DELETE", "/attachments/{id}", "Remove an attachment (not from posted vouchers in locked periods)", "auth.self", ["attachments", "audit_logs"],
  ["I6", "M4"], tag=T, fr=["FR-066"], codes=[204, 409], perm_map={'any': '<module>.edit of the record the file belongs to (create for a draft)'})
E("GET", "/audit-logs", "Activity timeline of one record", "auth.self", ["audit_logs", "users"], ["E5", "F4", "B4", "C4", "H13", "M4", "K10", "D3"],
  params=["auditable_type", "auditable_id", "page"], resp="Page<AuditLog>", tag=T, fr=["FR-074"],
  desc="Requires view permission on the audited record's module.",
  perm_map={'any': '<module>.view of the record named by type/id (e.g. sales.view for a sales_invoice)'})
E("GET", "/dashboard", "Main dashboard data for a period", "dashboard.view", ["journal_lines", "ledgers", "sales_invoices", "purchase_bills", "money_accounts", "stock_balances", "product_warehouse_settings", "journal_entries"], ["A1"],
  params=["period"], resp="DashboardSummary", tag=T, fr=["FR-010"], desc="Cached per company and period for 60 seconds; invalidated by postings.")
E("GET", "/dashboard/lenses/{lens}", "Dashboard lens data (financial, sales, expenses, receivables, payables, cash, inventory)", "dashboard.view",
  ["journal_lines", "sales_invoices", "purchase_bills", "ie_categories", "money_accounts", "stock_balances", "product_warehouse_settings", "parties"], ["A2", "A3", "A4", "A5", "A6", "A7", "A8"],
  params=["period"], resp="LensData", tag=T, fr=["FR-011"],
  desc="Each lens also requires the source module's view permission (financial: reports.view; sales: sales.view; expenses and cash: banking.view; receivables/payables: parties.view; inventory: inventory.view); otherwise 403 and the lens tab is hidden.")
E("GET", "/backups", "Backup history and settings", "settings.backup", ["company_backups"], ["K15"], resp="Page<Backup>", tag=T, fr=["FR-087"])
E("POST", "/backups", "Take a manual backup now (queued)", "settings.backup", ["company_backups"], ["K15"], resp="Backup", idem=True, tag=T, fr=["FR-087"], codes=[202])
E("GET", "/backups/{id}/download", "Signed URL of a backup file (Owner only: snapshots hold the whole company, R-11)", "settings.restore", ["company_backups", "audit_logs"], ["K15"], resp="SignedUrl", tag=T, fr=["FR-087"])
E("POST", "/backups/{id}/restore", "Restore the company from a backup (Owner; confirmation text RESTORE)", "settings.restore", ["company_backups", "audit_logs"], ["K15"], rules=["BR-062"],
  req="RestoreRequest", resp="Backup", idem=True, tag=T, fr=["FR-087"], tx="Company placed in maintenance; pre_restore snapshot first; queued job.", codes=[202, 403, 422])
E("POST", "/export-jobs", "Prepare the month-end export pack or a large report export", "reports.export", ["export_jobs"], ["K15", "J1"], req="ExportJobCreate", resp="ExportJob", idem=True, tag=T, fr=["FR-087", "FR-076"], codes=[202, 422])
E("GET", "/export-jobs/{id}", "Export job status and signed download URL when complete", "reports.export", ["export_jobs"], ["K15", "J1"], resp="ExportJob", tag=T, fr=["FR-087"])

# ---------------------------------------------------------------- Parties
T = "Parties"
E("GET", "/parties", "List customers or suppliers", "parties.view", ["parties", "party_groups", "payment_terms", "sales_invoices", "purchase_bills"],
  ['B1', 'C1', 'E3', 'F3', 'E7', 'F5', 'M3'], params=LISTQ + ["type", "status", "balance", "area", "group_id"], resp="Page<Party>", tag=T,
  fr=["FR-020"], desc="`type` (customer|supplier) is required. Balance and overdue are computed from posted documents.")
E("POST", "/parties", "Create a customer or supplier (and its ledger, and opening balance entry)", "parties.create",
  ["parties", "party_bank_accounts", "ledgers", "account_mappings", "number_sequences", "journal_entries", "journal_lines", "audit_logs"], ["B2", "C2", "E3", "L5"],
  rules=["BR-063", "BR-028", "BR-006"], req="PartyInput", resp="Party", idem=True, tx="One transaction: code from sequence, ledger under mapped group, opening_balance entry if > 0.", tag=T, fr=["FR-021"], codes=[201, 422])
E("GET", "/parties/{id}", "Party with contact, terms, bank details, KPIs and credit used", "parties.view", ["parties", "party_bank_accounts", "ledgers", "journal_lines"], ["B3", "B4", "C3", "C4"], resp="Party", tag=T, fr=["FR-022"])
E("PATCH", "/parties/{id}", "Edit a party (opening balance locked after first document)", "parties.edit", ["parties", "party_bank_accounts", "ledgers", "journal_entries", "journal_lines", "audit_logs"], ["B3", "C3"],
  rules=["BR-028", "BR-063", "BR-049"], req="PartyInput", resp="Party", ifmatch=True, tag=T, fr=["FR-021"])
E("POST", "/parties/{id}/deactivate", "Make inactive (hidden from new documents)", "parties.edit", ["parties", "ledgers", "audit_logs"], ["B3", "C3", "B1", "C1"], rules=["BR-026"], resp="Party", tag=T, fr=["FR-025"])
E("POST", "/parties/{id}/reactivate", "Make active again", "parties.edit", ["parties", "ledgers"], ["B3", "C3"], resp="Party", tag=T, fr=["FR-025"])
E("POST", "/parties/bulk-deactivate", "Make several parties inactive (each with history is deactivated, never deleted)", "parties.edit", ["parties", "ledgers", "audit_logs"], ["B1", "C1"], rules=["BR-026"], req="BulkIds", resp="BulkResult", tag=T, fr=["FR-020"], codes=[200, 422], added="C-04")
E("DELETE", "/parties/{id}", "Delete a party with no history (type the name to confirm in UI)", "parties.delete", ["parties", "ledgers", "journal_lines", "audit_logs"], ["L7"], rules=["BR-026"], tag=T, fr=["FR-025"], codes=[204, 409])
E("GET", "/parties/{id}/open-documents", "Open invoices or bills with balance, oldest due first", "parties.view",
  ['sales_invoices', 'purchase_bills', 'bill_allocations', 'payment_details', 'journal_lines', 'credit_notes', 'debit_notes'],
  ["B4", "C4", "E7", "F5"], resp="OpenDocuments", tag=T, fr=["FR-022", "FR-043"],
  desc='Open invoices or bills, oldest due first, and the open credits that can be applied to them (unapplied receipts/payments, advances, posted notes with usage credit), each with the journal_line_id to send to POST /allocations (R-08).')
E("GET", "/parties/{id}/transactions", "Document history of a party", "parties.view", ["sales_invoices", "purchase_bills", "journal_entries", "credit_notes", "debit_notes", "sales_returns", "purchase_returns", "quotations", "purchase_orders"], ["B5", "C5"],
  params=LISTQ + DATEQ + ["document_type"], resp="Page<PartyTransaction>", tag=T, fr=["FR-023"])
E("GET", "/parties/{id}/ledger", "Party ledger with opening, running and closing balance", "parties.view", ["journal_lines", "journal_entries", "ledgers"], ["B6", "C6", "B8", "C8"],
  params=DATEQ + ["density", "page"], resp="LedgerStatement", tag=T, fr=["FR-023"])
E("GET", "/parties/outstanding", "Open documents across parties by age bucket", "parties.view", ["sales_invoices", "purchase_bills", "parties"],
  ['B7', 'C7'], params=LISTQ + ["type", "bucket", "as_of"], resp="OutstandingReport", tag=T, fr=["FR-023"])
E("GET", "/parties/{id}/statement", 'Statement of account (JSON for the screen)', "parties.view",
  ["journal_lines", "journal_entries", "parties", "companies", "sales_invoices", "purchase_bills"], ["B8", "C8", "J6", "J7"],
  params=DATEQ + ["aging"], resp="PartyStatement", tag=T, fr=["FR-024"])
E("GET", "/parties/{id}/statement/pdf", "Statement of account as PDF (same parameters as the JSON statement)", "parties.view", ["journal_lines", "journal_entries", "parties", "companies"], ["B8", "C8"], params=DATEQ + ["aging"], resp="Binary", tag=T, fr=["FR-024"], added="C-01")
E("POST", "/parties/statements/send", "E-mail statements to one or more parties (queued)", "parties.view", ["parties", "export_jobs", "audit_logs"],
  ['B1', 'B8', 'C8'], req="StatementSendRequest", resp="ExportJob", idem=True, tag=T, fr=["FR-024"], codes=[202, 422])
E("GET", "/party-groups", "Customer or supplier groups", "parties.view", ["party_groups"], ["B2", "C2", "B1"], params=["type"], resp="Page<PartyGroup>", tag=T, fr=["FR-021"])
E("POST", "/party-groups", "Add a group", "parties.create", ["party_groups"], ["B2", "C2"], req="PartyGroupInput", resp="PartyGroup", tag=T, fr=["FR-021"], codes=[201, 422])

# ---------------------------------------------------------------- Inventory
T = "Inventory"
E("GET", "/products", "List products and services with stock status", "inventory.view", ["products", "product_categories", "units", "stock_balances", "product_warehouse_settings"],
  ["D1", "E3", "F3", "D10", "D11", "E10", "F8"], rules=["BR-052"], params=LISTQ + ["item_type", "category_id", "warehouse_id", "stock_status", "active"], resp="Page<Product>", tag=T, fr=["FR-030"])
E("POST", "/products", "Create a product or service (optional opening stock per warehouse)", "inventory.create",
  ["products", "product_warehouse_settings", "stock_balances", "stock_movements", "journal_entries", "journal_lines", "audit_logs"], ["D2", "L5"],
  rules=["BR-028", "BR-017"], req="ProductInput", resp="Product", idem=True, tx=TXP, tag=T, fr=["FR-031"], codes=[201, 422])
E("POST", "/products/import", "Import products from CSV/XLSX: dry_run=true validates and previews, false creates in one transaction", "inventory.create", ["products", "product_categories", "units", "ledgers", "tax_rules", "product_warehouse_settings", "audit_logs"], ["D1"], rules=["BR-036", "BR-026"], req="ProductImport", resp="ImportResult", params=["dry_run"], idem=True, tag=T, fr=["FR-030"], codes=[200, 201, 413, 415, 422], added="C-05")
E("GET", "/products/{id}", "Product with stock by warehouse, costs (if permitted) and price history", "inventory.view", ["products", "stock_balances", "product_warehouse_settings", "audit_logs"], ["D3", "D4"],
  rules=["BR-052"], resp="Product", tag=T, fr=["FR-032"])
E("PATCH", "/products/{id}", "Edit a product (quantities never change here)", "inventory.edit", ["products", "product_warehouse_settings", "audit_logs"], ["D3"], rules=["BR-026", "BR-049"], req="ProductInput", resp="Product", ifmatch=True, tag=T, fr=["FR-031"])
E("DELETE", "/products/{id}", "Delete an unused product; used products are deactivated instead", "inventory.delete", ["products", "stock_movements", "sales_invoice_lines", "purchase_bill_lines"], ["D3"], rules=["BR-026"], tag=T, fr=["FR-031"], codes=[204, 409])
E("GET", "/stock-movements", "All stock movements", "inventory.view", ["stock_movements", "products", "warehouses"], ["D9"],
  params=LISTQ + DATEQ + ["movement_type", "warehouse_id", "product_id"], resp="Page<StockMovement>", tag=T, fr=["FR-034"], rules=['BR-052'])
for res, name, pages_, perm_extra in [("product-categories", "category", ["D5", "D1", "D2"], ["product_categories"]), ("stock-groups", "stock group", ["D6", "D2"], ["stock_groups"]),
                                     ("units", "unit", ["D2", "D5"], ["units"])]:
    E("GET", f"/{res}", f"List {name}s", "inventory.view", perm_extra, pages_, resp=f"Page<Lookup>", params=LISTQ, tag=T, fr=["FR-033"])
    E("POST", f"/{res}", f"Create a {name}", "inventory.create", perm_extra, pages_[:1], req="UnitInput" if res == "units" else "LookupInput", resp="Lookup", tag=T, fr=["FR-033"], codes=[201, 422])
    if res == "units":
        continue  # units are seeded and added inline in D2; no screen edits or deletes them (contract review 18 Oct 2026)
    E("PATCH", f"/{res}/{{id}}", f"Edit a {name}", "inventory.edit", perm_extra, pages_[:1], req="LookupInput", resp="Lookup", tag=T, fr=["FR-033"])
    E("DELETE", f"/{res}/{{id}}", f"Delete an unused {name}", "inventory.delete", perm_extra + ["products"], pages_[:1], rules=["BR-026"], tag=T, fr=["FR-033"], codes=[204, 409])
E("GET", "/warehouses", "Warehouses with value, products, below-reorder count", "inventory.view",
  ["warehouses", "stock_balances", "product_warehouse_settings"], ["D7", "E3", "F3", "D10", "D11", "S0"], resp="Page<Warehouse>", tag=T,
  fr=["FR-033"], rules=['BR-052'])
E("POST", "/warehouses", "Create a warehouse (inventory ledger created under 1301 unless given)", "inventory.create", ["warehouses", "ledgers", "account_mappings"], ["D7"], req="WarehouseInput", resp="Warehouse", idem=True, tag=T, fr=["FR-033"], codes=[201, 422])
E("GET", "/warehouses/{id}", "Warehouse details and recent transfers", "inventory.view", ["warehouses", "stock_transfers"], ["D8"], resp="Warehouse", tag=T, fr=["FR-033"])
E("PATCH", "/warehouses/{id}", "Edit a warehouse", "inventory.edit", ["warehouses"], ["D8"], req="WarehouseInput", resp="Warehouse", ifmatch=True, tag=T, fr=["FR-033"])
E("GET", "/warehouses/{id}/stock", "Stock in one warehouse", "inventory.view", ["stock_balances", "products", "product_warehouse_settings"], ["D8"], rules=["BR-052"], params=LISTQ + ["below_reorder"], resp="Page<StockLevel>", tag=T, fr=["FR-033"])
for res, doc, pg, rules_, fr_ in [("stock-adjustments", "StockAdjustment", "D10", ["BR-029", "BR-004", "BR-006"], "FR-035"), ("stock-transfers", "StockTransfer", "D11", ["BR-030", "BR-006"], "FR-036")]:
    tbls = (["stock_adjustments", "stock_adjustment_lines"] if "adj" in res else ["stock_transfers", "stock_transfer_lines"])
    E("GET", f"/{res}", f"List {doc} documents", "inventory.view", tbls, [pg, "D8"], rules=["BR-052"], params=LISTQ + DATEQ + ["status", "warehouse_id"], resp=f"Page<{doc}>", tag=T, fr=[fr_])
    E("POST", f"/{res}", f"Create a draft {doc}", "inventory.create", tbls + ["number_sequences", "stock_balances"], [pg], rules=rules_ + ["BR-052"], req=f"{doc}Input", resp=doc, idem=True, tag=T, fr=[fr_], codes=[201, 422])
    E("GET", f"/{res}/{{id}}", f"Get a {doc}", "inventory.view", tbls, [pg], rules=["BR-052"], resp=doc, tag=T, fr=[fr_])
    E("PATCH", f"/{res}/{{id}}", f"Edit a draft {doc}", "inventory.edit", tbls, [pg], rules=["BR-025", "BR-049", "BR-052"], req=f"{doc}Input", resp=doc, ifmatch=True, tag=T, fr=[fr_])
    E("DELETE", f"/{res}/{{id}}", f"Delete a draft {doc} (number voided)", "inventory.delete", tbls + ["audit_logs"], [pg], rules=["BR-025", "BR-006"], tag=T, fr=[fr_], codes=[204, 409])
E("POST", "/stock-adjustments/{id}/post", "Post an adjustment: stock movements + value journal", "inventory.create",
  ["stock_adjustments", "stock_adjustment_lines", "stock_balances", "stock_movements", "journal_entries", "journal_lines", "account_mappings", "accounting_periods"],
  ["D10"], rules=['BR-029', 'BR-001', 'BR-004', 'BR-009', 'BR-052'], idem=True, resp="StockAdjustment", tx=TXP, tag=T, fr=["FR-035"],
  codes=[200, 409, 422])
E("POST", "/stock-adjustments/{id}/reverse", "Reverse a posted adjustment", "inventory.approve", ["stock_adjustments", "stock_movements", "stock_balances", "journal_entries", "journal_lines"], ["D10"],
  rules=["BR-003", "BR-004", "BR-014"], req="ReasonRequest", idem=True, resp="StockAdjustment", tx=TXP, tag=T, fr=["FR-035"])
E("POST", "/stock-adjustments/count-sheet", "Parse an uploaded count sheet into adjustment lines (no save)", "inventory.create", ["products", "stock_balances"], ["D10"], req="FileUpload", resp="CountSheetPreview", tag=T, fr=["FR-035"])
E("POST", "/stock-transfers/{id}/dispatch", "Dispatch: stock leaves the source (in transit)", "inventory.create", ["stock_transfers", "stock_transfer_lines", "stock_balances", "stock_movements", "journal_entries", "journal_lines", "warehouses"], ["D11"],
  rules=["BR-030", "BR-014", "BR-004", "BR-009"], idem=True, resp="StockTransfer", tx=TXP, tag=T, fr=["FR-036"], codes=[200, 409, 422])
E("POST", "/stock-transfers/{id}/receive", "Receive at destination (optionally with received quantities)", "inventory.create", ["stock_transfers", "stock_transfer_lines", "stock_balances", "stock_movements", "journal_entries", "journal_lines"], ["D11", "D8"],
  rules=["BR-030", "BR-004", "BR-009"], req="TransferReceive", idem=True, resp="StockTransfer", tx=TXP, tag=T, fr=["FR-036"])
E("POST", "/stock-transfers/{id}/cancel", "Cancel a draft or dispatched transfer (dispatched stock returns to source)", "inventory.edit", ["stock_transfers", "stock_balances", "stock_movements", "journal_entries", "journal_lines"], ["D11"],
  rules=["BR-003"], req="ReasonRequest", idem=True, resp="StockTransfer", tx=TXP, tag=T, fr=["FR-036"])
E("GET", "/reports/inventory/summary", "Inventory summary (value by group, flow, movers, attention)", "inventory.view",
  ["stock_balances", "stock_movements", "stock_groups", "sales_invoice_lines"], ['D14'], rules=["BR-052"], resp="ReportData", tag="Reports",
  fr=["FR-037"])
E("GET", "/reports/inventory/low-stock", "Items at or below reorder level with suggested quantities", "inventory.view",
  ["stock_balances", "product_warehouse_settings", "products", "parties"], ['D12'], params=["warehouse_id", "status", "group_by_supplier"],
  resp="ReportData", tag="Reports", fr=["FR-037"])
E("GET", "/reports/inventory/valuation", "Stock valuation at weighted average cost as of a date", "inventory.view_costs", ["stock_movements", "products", "stock_groups"], ["D13", "J1"], rules=["BR-016"], params=["as_of", "warehouse_id", "level", "format"], resp="ReportData", tag="Reports", fr=["FR-037"])

# ---------------------------------------------------------------- Sales
T = "Sales"
E("GET", "/sales/overview", "Sales overview tiles, months, status breakdown, recent, top products", "sales.view",
  ["sales_invoices", "sales_invoice_lines", "quotations"], ['E1'], params=["period"], resp="ReportData", tag=T, fr=["FR-040"])
E("POST", "/sales-invoices/calculate", "Authoritative totals for an unsaved invoice (no write)", "sales.create", ["products", "tax_rules", "company_settings", "parties", "stock_balances"], ["E3", "E6", "E10"],
  rules=["BR-010", "BR-011", "BR-012", "BR-015", "BR-014"], req="SalesInvoiceInput", resp="DocumentCalculation", tag=T, fr=["FR-041"],
  desc="Returns line amounts, totals, amount in words, party balance after, credit-limit and stock warnings. Debounced by the UI (300 ms). Also used by quotations.")
E("GET", "/sales-invoices", "List invoices", "sales.view", ["sales_invoices", "parties"], ["E2", "E1", "B5"], rules=["BR-037", "BR-038"], params=LISTQ + DATEQ + ["status", "payment_status", "overdue", "party_id"], resp="Page<SalesInvoice>", tag=T, fr=["FR-040"])
E("POST", "/sales-invoices", "Create a draft invoice (number reserved)", "sales.create", ["sales_invoices", "sales_invoice_lines", "number_sequences", "parties", "products", "audit_logs"], ["E3", "E10"],
  rules=["BR-013", "BR-006", "BR-010", "BR-011", "BR-055"], req="SalesInvoiceInput", resp="SalesInvoice", idem=True, tx="Locks number_sequences row.", tag=T, fr=["FR-041"], codes=[201, 422])
E("GET", "/sales-invoices/{id}", "Invoice with lines, payments (allocations), ledger entries and delivery", "sales.view", ["sales_invoices", "sales_invoice_lines", "bill_allocations", "journal_entries", "journal_lines"], ["E4", "E5", "E6"], resp="SalesInvoice", tag=T, fr=["FR-042"])
E("PUT", "/sales-invoices/{id}", "Replace a draft invoice (header and lines)", "sales.edit", ["sales_invoices", "sales_invoice_lines", "audit_logs"], ["E6"], rules=["BR-025", "BR-013", "BR-049"], req="SalesInvoiceInput", resp="SalesInvoice", ifmatch=True, tag=T, fr=["FR-041"])
E("PATCH", "/sales-invoices/{id}/delivery", "Record challan, vehicle and delivery time (allowed after posting)", "sales.edit", ["sales_invoices", "audit_logs"], ["E5"], req="DeliveryUpdate", resp="SalesInvoice", ifmatch=True, tag=T, fr=["FR-042"])
E("DELETE", "/sales-invoices/{id}", "Delete a draft (number voided, never reused)", "sales.delete", ["sales_invoices", "sales_invoice_lines", "audit_logs"], ["Sal-DlgDelete", "E6"], rules=["BR-025", "BR-006"], tag=T, fr=["FR-046"], codes=[204, 409])
E("POST", "/sales-invoices/{id}/post", "Post: stock out at average cost, journal entry, customer balance", "sales.create",
  ['sales_invoices', 'sales_invoice_lines', 'stock_balances', 'stock_movements', 'journal_entries', 'journal_lines', 'account_mappings', 'accounting_periods', 'payment_details', 'bill_allocations', 'money_accounts', 'number_sequences', 'audit_logs'],
  ["Sal-DlgPost", "E3", "E4", "E6"],
  rules=['BR-013', 'BR-014', 'BR-016', 'BR-064', 'BR-001', 'BR-004', 'BR-009', 'BR-012', 'BR-053', 'BR-065', 'BR-020'], req="PostRequest", idem=True,
  resp="SalesInvoice", tx=TXP, tag=T, fr=["FR-041"], codes=[200, 409, 422],
  desc='With `receipt` in the body (cash sale), the invoice and its RV are posted in the same transaction and the invoice is settled (R-28).')
E("POST", "/sales-invoices/bulk-post", "Post several drafts (each in its own transaction; per-item results)", "sales.create", ["sales_invoices", "stock_balances", "stock_movements", "journal_entries", "journal_lines"], ["L6", "E2"],
  rules=["BR-009", "BR-014", "BR-001"], req="BulkIds", idem=True, resp="BulkResult", tx="Each invoice posts in its own transaction; failures are reported, not rolled across.", tag=T, fr=["FR-041"], codes=[200, 422])
E("POST", "/sales-invoices/{id}/cancel", "Cancel a posted invoice (reversal + stock back; payments become advance)", "sales.edit",
  ["sales_invoices", "journal_entries", "journal_lines", "stock_movements", "stock_balances", "bill_allocations", "audit_logs"], ["Sal-DlgCancel", "E5"],
  rules=["BR-024", "BR-003", "BR-004"], req="CancelRequest", idem=True, resp="SalesInvoice", tx=TXP, tag=T, fr=["FR-046"], codes=[200, 409, 422])
E("GET", "/sales-invoices/{id}/pdf", "Invoice PDF per invoice design (also challan with ?variant=challan)", "sales.view", ["sales_invoices", "sales_invoice_lines", "company_settings", "money_accounts"], ["E4", "E5"], params=["variant"], resp="Binary", tag=T, fr=["FR-042"])
E("POST", "/sales-invoices/{id}/send", "E-mail the invoice PDF to the customer (queued)", "sales.edit", ["sales_invoices", "audit_logs"], ["E5"], req="EmailRequest", idem=True, tag=T, fr=["FR-042"], codes=[202])
E("POST", "/customer-receipts", "Record a customer payment with allocations (creates RV)", "sales.create",
  ['journal_entries', 'journal_lines', 'payment_details', 'bill_allocations', 'sales_invoices', 'money_accounts', 'number_sequences', 'audit_logs'],
  ["E7", "E5", "B4"], rules=['BR-020', 'BR-021', 'BR-065', 'BR-001', 'BR-004', 'BR-053', 'BR-009'], req="PartyPaymentInput", idem=True,
  resp="Voucher", tx=TXP + " Each allocated invoice row is locked; amount_settled/balance_due/payment_status updated.", tag=T, fr=["FR-043"],
  codes=[201, 409, 422], desc='Receipts never need approval (BR-007 covers payments and journals only, R-22).')
E("GET", "/customer-receipts", "List customer receipts (RV) with party, account, method and allocation status", "sales.view",
  ["journal_entries", "payment_details", "bill_allocations", "parties", "money_accounts"], ['E14'],
  params=LISTQ + DATEQ + ["party_id", "money_account_id", "unapplied"], resp="Page<Voucher>", tag=T, fr=["FR-043"], added="R-07")
E("GET", "/customer-receipts/{id}", "Get a customer receipt with its allocations", "sales.view", ["journal_entries", "journal_lines", "payment_details", "bill_allocations", "sales_invoices"], ["E14", "E7"], resp="Voucher", tag=T, fr=["FR-043"], added="R-07", desc="Lets sales users see receipts without accounting.view.")
E("GET", "/customer-receipts/{id}/pdf", "Printable receipt voucher", "sales.view", ["journal_entries", "journal_lines", "payment_details", "companies"], ["E7", "E14"], resp="Binary", tag=T, fr=["FR-043"], added="C-01")
E("POST", "/allocations", "Apply an advance, unapplied receipt/payment or open credit/debit note to an invoice or bill", "auth.self", ["bill_allocations", "journal_lines", "payment_details", "sales_invoices", "purchase_bills", "credit_notes", "debit_notes", "audit_logs"], ["B4", "C4", "E5", "F4"], rules=["BR-020", "BR-021", "BR-023", "BR-004"], req="AllocationCreate", resp="Allocation", idem=True, tx=TXP + " The settling line, payment detail and document rows are locked.", tag=T, fr=["FR-043", "FR-052"], codes=[201, 409, 422], perm_map={"party is a customer": "sales.create", "party is a supplier": "purchases.create"}, added="R-08")
E("DELETE", "/allocations/{id}", "Remove an allocation (sets reversed_at; balances restored)", "auth.self", ["bill_allocations", "payment_details", "sales_invoices", "purchase_bills", "audit_logs"], ["B4", "C4", "E5", "F4"], rules=["BR-020", "BR-004"], tx=TXP, tag=T, fr=["FR-043", "FR-052"], codes=[204, 404, 409], perm_map={"party is a customer": "sales.edit", "party is a supplier": "purchases.edit"}, added="R-08")
E("GET", "/quotations", "List quotations", "sales.view", ["quotations", "parties"], ['E10', 'E1', 'E13'],
  params=LISTQ + DATEQ + ["status", "party_id"], resp="Page<Quotation>", tag=T, fr=["FR-045"])
E("POST", "/quotations", "Create a quotation", "sales.create", ["quotations", "quotation_lines", "number_sequences"], ["E10"], rules=["BR-056", "BR-006"], req="QuotationInput", resp="Quotation", idem=True, tag=T, fr=["FR-045"], codes=[201, 422])
E("GET", "/quotations/{id}", "Get a quotation", "sales.view", ["quotations", "quotation_lines"], ["E10"], resp="Quotation", tag=T, fr=["FR-045"])
E("PUT", "/quotations/{id}", "Replace a draft or sent quotation", "sales.edit", ["quotations", "quotation_lines"], ["E10"], req="QuotationInput", resp="Quotation", ifmatch=True, tag=T, fr=["FR-045"])
E("POST", "/quotations/{id}/send", "Mark sent and e-mail it", "sales.edit", ["quotations", "audit_logs"], ["E10"], req="EmailRequest", resp="Quotation", tag=T, fr=["FR-045"])
E("POST", "/quotations/{id}/convert", "Convert an accepted quotation into a draft invoice", "sales.create", ["quotations", "sales_invoices", "sales_invoice_lines", "number_sequences"], ["E10"], rules=["BR-056"], idem=True, resp="SalesInvoice", tag=T, fr=["FR-045"], codes=[201, 409])
E("POST", "/quotations/{id}/status", "Set accepted, declined or expired", "sales.edit", ["quotations"], ["E10"], req="StatusChange", resp="Quotation", tag=T, fr=["FR-045"])
E("DELETE", "/quotations/{id}", "Delete a draft quotation (number voided and audited)", "sales.delete", ["quotations", "quotation_lines", "audit_logs"], ["E13"], rules=["BR-025", "BR-006"], tag=T, fr=["FR-045"], added="C-02")
E("GET", "/quotations/{id}/pdf", "Printable quotation (also attached when sent)", "sales.view", ["quotations", "quotation_lines", "parties", "companies", "company_settings"], ["E10", "E13"], resp="Binary", tag=T, fr=["FR-045"], added="C-01")
E("GET", "/sales-returns", "List sales returns", "sales.view", ["sales_returns", "credit_notes"], ['E8', 'E15'],
  params=LISTQ + DATEQ + ["party_id", "reason"], resp="Page<Return>", tag=T, fr=["FR-047"])
E("POST", "/sales-returns", "Create a draft return against an invoice", "sales.create", ["sales_returns", "sales_return_lines", "sales_invoice_lines", "number_sequences"], ["E8"], rules=["BR-022", "BR-021", "BR-006"], req="ReturnInput", resp="Return", idem=True, tag=T, fr=["FR-044"], codes=[201, 422])
E("GET", "/sales-returns/{id}", "Get a return", "sales.view", ["sales_returns", "sales_return_lines"], ["E8"], resp="Return", tag=T, fr=["FR-044"])
E("PUT", "/sales-returns/{id}", "Replace a draft return", "sales.edit", ["sales_returns", "sales_return_lines"], ["E8"], rules=["BR-022"], req="ReturnInput", resp="Return", ifmatch=True, tag=T, fr=["FR-044"])
E("POST", "/sales-returns/{id}/post", "Post the return (stock back if restock) and create a draft credit note", "sales.create",
  ["sales_returns", "sales_return_lines", "sales_invoice_lines", "stock_movements", "stock_balances", "journal_entries", "journal_lines", "credit_notes", "number_sequences"], ["E8"],
  rules=["BR-022", "BR-001", "BR-004", "BR-009", "BR-017"], idem=True, resp="Return", tx=TXP, tag=T, fr=["FR-044"], codes=[200, 409, 422])
E("DELETE", "/sales-returns/{id}", "Delete a draft sales return", "sales.delete", ["sales_returns", "sales_return_lines", "audit_logs"], ["E15"], rules=["BR-025", "BR-006"], tag=T, fr=["FR-044"], added="C-02")
E("POST", "/sales-returns/{id}/cancel", "Cancel a posted return: mirror stock entry; its credit note is cancelled if unapplied", "sales.edit", ["sales_returns", "sales_return_lines", "sales_invoice_lines", "stock_movements", "stock_balances", "journal_entries", "journal_lines", "credit_notes", "number_sequences", "audit_logs"], ["E15"], rules=["BR-003", "BR-004", "BR-009", "BR-024"], req="ReasonRequest", idem=True, resp="Return", tx=TXP, tag=T, fr=["FR-044"], codes=[200, 409, 422], errors=["note_applied"], added="R-03")
E("GET", "/credit-notes/{id}", "Get a credit note", "sales.view", ["credit_notes"], ["E9"], resp="Note", tag=T, fr=["FR-044"])
E("PUT", "/credit-notes/{id}", "Edit a draft credit note (reason, amount, usage)", "sales.edit", ["credit_notes"], ["E9"], rules=["BR-023"], req="NoteInput", resp="Note", ifmatch=True, tag=T, fr=["FR-044"])
E("POST", "/credit-notes", "Create a credit note without a return (price correction, goodwill)", "sales.create", ["credit_notes", "number_sequences"], ["E9", "E5"], req="NoteInput", resp="Note", idem=True, tag=T, fr=["FR-044"], codes=[201, 422])
E("POST", "/credit-notes/{id}/post", "Post the credit note and apply it per usage", "sales.create", ["credit_notes", "journal_entries", "journal_lines", "bill_allocations", "sales_invoices", "account_mappings"], ["E9"],
  rules=["BR-023", "BR-001", "BR-004", "BR-009", "BR-020"], idem=True, resp="Note", tx=TXP, tag=T, fr=["FR-044"], codes=[200, 409, 422])
E("GET", "/credit-notes", "List credit notes", "sales.view", ["credit_notes", "parties", "sales_invoices"], ["E15"], params=LISTQ + DATEQ + ["party_id", "status", "usage"], resp="Page<Note>", tag=T, fr=["FR-044"], added="R-07")
E("DELETE", "/credit-notes/{id}", "Delete a draft credit note", "sales.delete", ["credit_notes", "audit_logs"], ["E15"], rules=["BR-025", "BR-006"], tag=T, fr=["FR-044"], added="C-02")
E("POST", "/credit-notes/{id}/cancel", "Cancel a posted credit note (mirror entry; its allocations are reversed)", "sales.edit", ["credit_notes", "journal_entries", "journal_lines", "bill_allocations", "sales_invoices", "number_sequences", "audit_logs"], ["E15"], rules=["BR-003", "BR-004", "BR-009", "BR-020"], req="ReasonRequest", idem=True, resp="Note", tx=TXP, tag=T, fr=["FR-044"], codes=[200, 409, 422], added="R-03")
E("GET", "/credit-notes/{id}/pdf", "Printable credit note", "sales.view", ["credit_notes", "parties", "companies"], ["E9", "E15"], resp="Binary", tag=T, fr=["FR-044"], added="C-01")

# ---------------------------------------------------------------- Purchases
T = "Purchases"
E("GET", "/purchases/overview", "Purchase overview", "purchases.view", ["purchase_bills", "purchase_orders"], ["F1"], params=["period"], resp="ReportData", tag=T, fr=["FR-050"])
E("POST", "/purchase-bills/calculate", "Authoritative totals for an unsaved bill (incl. foreign currency)", "purchases.create", ["products", "tax_rules", "company_settings", "parties"], ["F3", "F8"], rules=["BR-010", "BR-011"], req="PurchaseBillInput", resp="DocumentCalculation", tag=T, fr=["FR-051"])
E("GET", "/purchase-bills", "List bills", "purchases.view", ["purchase_bills", "parties"], ["F2", "F1"], rules=["BR-037", "BR-038"], params=LISTQ + DATEQ + ["status", "payment_status", "overdue", "party_id", "letter_of_credit_id"], resp="Page<PurchaseBill>", tag=T, fr=["FR-050"])
E("POST", "/purchase-bills", "Create a draft bill (local or import mode)", "purchases.create", ["purchase_bills", "purchase_bill_lines", "number_sequences", "parties", "products", "letters_of_credit", "lc_shipments"], ["F3", "M4"],
  rules=["BR-018", "BR-006", "BR-010", "BR-011", "BR-055"], req="PurchaseBillInput", resp="PurchaseBill", idem=True, tag=T, fr=["FR-051", "FR-098"], codes=[201, 422])
E("GET", "/purchase-bills/{id}", "Bill with lines, payments, ledger entries, receipt", "purchases.view", ["purchase_bills", "purchase_bill_lines", "bill_allocations", "journal_entries", "journal_lines"], ["F4"], resp="PurchaseBill", tag=T, fr=["FR-051"])
E("PUT", "/purchase-bills/{id}", "Replace a draft bill", "purchases.edit", ["purchase_bills", "purchase_bill_lines"], ["F3"], rules=["BR-025", "BR-018", "BR-049"], req="PurchaseBillInput", resp="PurchaseBill", ifmatch=True, tag=T, fr=["FR-051"])
E("DELETE", "/purchase-bills/{id}", "Delete a draft bill", "purchases.delete", ["purchase_bills", "purchase_bill_lines", "audit_logs"], ["F3"], rules=["BR-025"], tag=T, fr=["FR-051"], codes=[204, 409])
E("POST", "/purchase-bills/{id}/post", "Post: stock in, average cost update, journal, supplier balance, PO received qty", "purchases.create",
  ["purchase_bills", "purchase_bill_lines", "stock_balances", "stock_movements", "journal_entries", "journal_lines", "purchase_order_lines", "purchase_orders", "account_mappings", "accounting_periods", "audit_logs", "products"],
  ["F3"], rules=["BR-018", "BR-019", "BR-017", "BR-001", "BR-004", "BR-009", "BR-056", "BR-053"], req="PostRequest", idem=True, resp="PurchaseBill", tx=TXP, tag=T, fr=["FR-051"], codes=[200, 409, 422])
E("POST", "/purchase-bills/{id}/cancel", "Cancel a posted bill (reverse; stock out at bill cost; payments to advance)", "purchases.edit",
  ["purchase_bills", "journal_entries", "journal_lines", "stock_movements", "stock_balances", "bill_allocations"], ["F4"], rules=["BR-024", "BR-014", "BR-003"], req="CancelRequest", idem=True, resp="PurchaseBill", tx=TXP, tag=T, fr=["FR-051"])
E("POST", "/supplier-payments", "Record a supplier payment with allocations (creates PV; may need approval)", "purchases.create",
  ["journal_entries", "journal_lines", "payment_details", "bill_allocations", "purchase_bills", "money_accounts", "number_sequences", "approval_requests", "roles"], ["F5", "Pur-DlgPay", "F4", "C4"],
  rules=["BR-020", "BR-021", "BR-031", "BR-065", "BR-007", "BR-043", "BR-001", "BR-004", "BR-009"], req="PartyPaymentInput", idem=True, resp="Voucher", tx=TXP, tag=T, fr=["FR-052"], codes=[201, 202, 409, 422],
  desc="Returns 201 when posted, or 202 with status=submitted when above the role's payment limit.")
E("GET", "/supplier-payments", "List supplier payments (PV) with party, account, method and status", "purchases.view",
  ["journal_entries", "payment_details", "bill_allocations", "parties", "money_accounts", "approval_requests"], ['F12'],
  params=LISTQ + DATEQ + ["party_id", "money_account_id", "status"], resp="Page<Voucher>", tag=T, fr=["FR-052"], added="R-07")
E("GET", "/supplier-payments/{id}", "Get a supplier payment with its allocations", "purchases.view", ["journal_entries", "journal_lines", "payment_details", "bill_allocations", "purchase_bills", "approval_requests"], ["F12", "F5"], resp="Voucher", tag=T, fr=["FR-052"], added="R-07")
E("GET", "/supplier-payments/{id}/pdf", "Printable payment voucher", "purchases.view", ["journal_entries", "journal_lines", "payment_details", "companies"], ["F5", "F12"], resp="Binary", tag=T, fr=["FR-052"], added="C-01")
E("GET", "/purchase-orders", "List purchase orders", "purchases.view", ["purchase_orders", "parties"], ['F8', 'F1', 'M3', 'F3', 'F11'],
  params=LISTQ + DATEQ + ["status", "party_id", "currency"], resp="Page<PurchaseOrder>", tag=T, fr=["FR-054"])
E("POST", "/purchase-orders", "Create a purchase order", "purchases.create", ["purchase_orders", "purchase_order_lines", "number_sequences"], ["F8"], rules=["BR-056", "BR-006"], req="PurchaseOrderInput", resp="PurchaseOrder", idem=True, tag=T, fr=["FR-054"], codes=[201, 422])
E("POST", "/purchase-orders/from-low-stock", "Create draft POs grouped by preferred supplier from selected low-stock rows", "purchases.create",
  ["purchase_orders", "purchase_order_lines", "product_warehouse_settings", "stock_balances", "products"], ['D12'], req="LowStockOrderRequest",
  resp="BulkResult", idem=True, tag=T, fr=["FR-037", "FR-054"], codes=[201, 422])
E("GET", "/purchase-orders/{id}", "Get a purchase order", "purchases.view", ["purchase_orders", "purchase_order_lines"], ["F8"], resp="PurchaseOrder", tag=T, fr=["FR-054"])
E("PUT", "/purchase-orders/{id}", "Replace a draft or sent order", "purchases.edit", ["purchase_orders", "purchase_order_lines"], ["F8"], req="PurchaseOrderInput", resp="PurchaseOrder", ifmatch=True, tag=T, fr=["FR-054"])
E("POST", "/purchase-orders/{id}/send", "Mark sent and e-mail it", "purchases.edit", ["purchase_orders"], ["F8"], req="EmailRequest", resp="PurchaseOrder", tag=T, fr=["FR-054"])
E("POST", "/purchase-orders/{id}/convert", "Create a draft bill from remaining quantities", "purchases.create", ["purchase_orders", "purchase_order_lines", "purchase_bills", "purchase_bill_lines", "number_sequences"], ["F8"], rules=["BR-056"], idem=True, resp="PurchaseBill", tag=T, fr=["FR-054"], codes=[201, 409])
E("POST", "/purchase-orders/{id}/status", "Close or cancel an order", "purchases.edit", ["purchase_orders"], ["F8"], req="StatusChange", resp="PurchaseOrder", tag=T, fr=["FR-054"])
E("DELETE", "/purchase-orders/{id}", "Delete a draft purchase order", "purchases.delete", ["purchase_orders", "purchase_order_lines", "audit_logs"], ["F11"], rules=["BR-025", "BR-006"], tag=T, fr=["FR-054"], added="C-02")
E("GET", "/purchase-orders/{id}/pdf", "Printable purchase order (also attached when sent)", "purchases.view", ["purchase_orders", "purchase_order_lines", "parties", "companies"], ["F8", "F11"], resp="Binary", tag=T, fr=["FR-054"], added="C-01")
E("GET", "/purchase-returns", "List purchase returns", "purchases.view", ["purchase_returns", "debit_notes"], ['F6', 'F13'],
  params=LISTQ + DATEQ + ["party_id"], resp="Page<Return>", tag=T, fr=["FR-055"])
E("POST", "/purchase-returns", "Create a draft purchase return", "purchases.create", ["purchase_returns", "purchase_return_lines", "purchase_bill_lines", "number_sequences"], ["F6"], rules=["BR-022", "BR-021"], req="ReturnInput", resp="Return", idem=True, tag=T, fr=["FR-053"], codes=[201, 422])
E("GET", "/purchase-returns/{id}", "Get a purchase return", "purchases.view", ["purchase_returns", "purchase_return_lines"], ["F6"], resp="Return", tag=T, fr=["FR-053"])
E("PUT", "/purchase-returns/{id}", "Replace a draft purchase return", "purchases.edit", ["purchase_returns", "purchase_return_lines"], ["F6"], req="ReturnInput", resp="Return", ifmatch=True, tag=T, fr=["FR-053"])
E("POST", "/purchase-returns/{id}/post",
  'Post the return: stock out at bill cost and Dr supplier / Cr inventory; the debit note is created posted, sharing that journal entry, and applied per usage',
  "purchases.create",
  ['purchase_returns', 'purchase_return_lines', 'purchase_bill_lines', 'stock_movements', 'stock_balances', 'journal_entries', 'journal_lines', 'debit_notes', 'bill_allocations', 'purchase_bills', 'number_sequences', 'audit_logs'],
  ["F6"], rules=['BR-022', 'BR-057', 'BR-014', 'BR-023', 'BR-001', 'BR-004', 'BR-009'], idem=True, resp="Return", tx=TXP, tag=T, fr=["FR-053"],
  desc='The supplier is debited once: the debit note`s journal_entry_id equals the return`s (R-04).')
E("DELETE", "/purchase-returns/{id}", "Delete a draft purchase return", "purchases.delete", ["purchase_returns", "purchase_return_lines", "audit_logs"], ["F13"], rules=["BR-025", "BR-006"], tag=T, fr=["FR-053"], added="C-02")
E("POST", "/purchase-returns/{id}/cancel", "Cancel a posted purchase return: stock back at the same cost, supplier credited, its debit note cancelled", "purchases.edit", ["purchase_returns", "purchase_return_lines", "purchase_bill_lines", "stock_movements", "stock_balances", "journal_entries", "journal_lines", "debit_notes", "bill_allocations", "number_sequences", "audit_logs"], ["F13"], rules=["BR-003", "BR-004", "BR-009", "BR-024"], req="ReasonRequest", idem=True, resp="Return", tx=TXP, tag=T, fr=["FR-053"], codes=[200, 409, 422], added="R-03")
E("GET", "/debit-notes/{id}", "Get a debit note", "purchases.view", ["debit_notes"], ["F7"], resp="Note", tag=T, fr=["FR-053"])
E("PUT", "/debit-notes/{id}", "Edit a draft debit note", "purchases.edit", ["debit_notes"], ["F7"], req="NoteInput", resp="Note", ifmatch=True, tag=T, fr=["FR-053"])
E("POST", "/debit-notes", "Create a debit note without a return (price difference, quality claim)", "purchases.create", ["debit_notes", "number_sequences"], ["F7"], req="NoteInput", resp="Note", idem=True, tag=T, fr=["FR-053"], codes=[201, 422])
E("POST", "/debit-notes/{id}/post",
  'Post a debit note that was not created from a return (price difference, short supply, quality claim) and apply it per usage', "purchases.create",
  ["debit_notes", "journal_entries", "journal_lines", "bill_allocations", "purchase_bills"], ["F7"],
  rules=["BR-023", "BR-057", "BR-001", "BR-004", "BR-009"], idem=True, resp="Note", tx=TXP, tag=T, fr=["FR-053"], errors=['created_from_return'])
E("GET", "/debit-notes", "List debit notes", "purchases.view", ["debit_notes", "parties", "purchase_bills"], ["F13"], params=LISTQ + DATEQ + ["party_id", "status", "usage"], resp="Page<Note>", tag=T, fr=["FR-053"], added="R-07")
E("DELETE", "/debit-notes/{id}", "Delete a draft debit note", "purchases.delete", ["debit_notes", "audit_logs"], ["F13"], rules=["BR-025", "BR-006"], tag=T, fr=["FR-053"], added="C-02")
E("POST", "/debit-notes/{id}/cancel", "Cancel a posted debit note not created from a return (mirror entry; allocations reversed)", "purchases.edit", ["debit_notes", "journal_entries", "journal_lines", "bill_allocations", "purchase_bills", "number_sequences", "audit_logs"], ["F13"], rules=["BR-003", "BR-004", "BR-009", "BR-020"], req="ReasonRequest", idem=True, resp="Note", tx=TXP, tag=T, fr=["FR-053"], codes=[200, 409, 422], errors=["created_from_return"], added="R-03")
E("GET", "/debit-notes/{id}/pdf", "Printable debit note", "purchases.view", ["debit_notes", "parties", "companies"], ["F7", "F13"], resp="Binary", tag=T, fr=["FR-053"], added="C-01")

# ---------------------------------------------------------------- Banking & income/expense
T = "Banking"
E("GET", "/banking/overview", "Accounts, combined balance series, quick stats, recent movements", "banking.view", ["money_accounts", "journal_lines", "bank_statement_lines"], ["G1"], resp="ReportData", tag=T, fr=["FR-060"])
E("GET", "/money-accounts", "Cash, bank, wallet and card accounts with book balance and reconciliation status", "auth.self",
  ["money_accounts", "ledgers", "journal_lines", "bank_statement_lines"],
  ['G3', 'E7', 'F5', 'G6', 'G7', 'G8', 'G9', 'H10', 'H11', 'H12', 'I3', 'I6', 'M3', 'LC-DlgPay', 'E14', 'F12'], params=["type", "purpose"],
  resp="Page<MoneyAccount>", tag=T, fr=["FR-060"],
  desc="Open to every member so receipt and payment forms can pick an account (e.g. Sales staff on E7). Without `banking.view` only id, name, type and use-for flags are returned (no balances, numbers or reconciliation data).")
E("POST", "/money-accounts", "Add a bank, wallet or card account (ledger + opening balance)", "banking.create", ["money_accounts", "ledgers", "journal_entries", "journal_lines", "account_mappings"], ["G4"], rules=["BR-028"], req="MoneyAccountInput", resp="MoneyAccount", idem=True, tx="One transaction.", tag=T, fr=["FR-061"], codes=[201, 422])
E("GET", "/money-accounts/{id}", "Account details, statement imports, last cash count", "banking.view", ["money_accounts", "bank_statement_imports", "cash_counts", "cash_count_denominations"], ["G2", "G5"], resp="MoneyAccount", tag=T, fr=["FR-060"])
E("PATCH", "/money-accounts/{id}", "Edit account details and use-for switches", "banking.edit", ["money_accounts"], ["G5"], req="MoneyAccountInput", resp="MoneyAccount", ifmatch=True, tag=T, fr=["FR-061"])
E("GET", "/money-accounts/{id}/book", "Cash book or bank book with running balance", "banking.view", ["journal_lines", "journal_entries", "payment_details"], ["G2", "G5", "G11", "G12"], params=DATEQ + ["view", "page"], resp="LedgerStatement", tag=T, fr=["FR-060"])
E("GET", "/money-transactions", "Cash and bank transactions across accounts", "banking.view", ["journal_lines", "journal_entries", "payment_details", "money_accounts"], ["G13", "G1"], params=LISTQ + DATEQ + ["direction", "money_account_id"], resp="Page<MoneyTransaction>", tag=T, fr=["FR-060"])
E("POST", "/cash-counts", "Record a cash count; a difference creates a draft adjustment awaiting approval", "banking.create",
  ["cash_counts", "cash_count_denominations", "journal_entries", "journal_lines", "approval_requests"], ["G2"], rules=["BR-058"],
  req="CashCountInput", resp="CashCount", idem=True, tag=T, fr=["FR-065"], codes=[201, 422],
  tx=TXP + ' A difference is posted only after approval (BR-058).')
E("POST", "/cash-counts/{id}/reverse", "Reverse a posted cash-count adjustment (mirror entry)", "banking.approve", ["cash_counts", "journal_entries", "journal_lines", "number_sequences", "audit_logs"], ["G2"], rules=["BR-003", "BR-004", "BR-058"], req="ReasonRequest", idem=True, resp="CashCount", tx=TXP, tag=T, fr=["FR-065"], codes=[200, 409, 422], added="R-03")
E("POST", "/bank-statement-imports", "Import a CSV/XLSX statement (multipart; column mapping)", "banking.reconcile",
  ["bank_statement_imports", "bank_statement_lines"], ["G10", "G5", "G3"], req="StatementImport", resp="StatementImportResult", idem=True, tag=T,
  fr=["FR-064"], codes=[201, 409, 415, 422], errors=['duplicate_statement'])
E("POST", "/reconciliations", "Start (or resume) a reconciliation for an account and statement date", "banking.reconcile", ["bank_reconciliations", "bank_statement_lines", "journal_lines"], ["G10"], req="ReconciliationStart", resp="Reconciliation", tag=T, fr=["FR-064"], codes=[200, 201])
E("GET", "/reconciliations/{id}", "Reconciliation with statement lines, suggestions and difference", "banking.reconcile", ["bank_reconciliations", "bank_statement_lines", "journal_lines", "journal_entries"], ["G10"], params=["filter"], resp="Reconciliation", tag=T, fr=["FR-064"])
E("POST", "/reconciliations/{id}/lines/{lineId}/match", "Match a statement line to a book line", "banking.reconcile", ["bank_statement_lines", "journal_lines"], ["G10"], req="MatchRequest", resp="Reconciliation", tag=T, fr=["FR-064"], codes=[200, 409, 422])
E("POST", "/reconciliations/{id}/lines/{lineId}/create-entry", "Create the missing receipt or bank-charge voucher and match it", "banking.reconcile",
  ["bank_statement_lines", "journal_entries", "journal_lines", "payment_details", "number_sequences"], ["G10"], rules=["BR-065", "BR-004", "BR-001"], req="CreateEntryFromLine", idem=True, resp="Reconciliation", tx=TXP, tag=T, fr=["FR-064"])
E("POST", "/reconciliations/{id}/lines/pair", "Mark two offsetting statement lines as a pair", "banking.reconcile", ["bank_statement_lines"], ["G10"], req="PairRequest", resp="Reconciliation", tag=T, fr=["FR-064"])
E("POST", "/reconciliations/{id}/lines/{lineId}/undo", "Undo a match, pair or ignore", "banking.reconcile", ["bank_statement_lines"], ["G10"], resp="Reconciliation", tag=T, fr=["FR-064"])
E("POST", "/reconciliations/{id}/accept-suggestions", "Accept every same-amount, same-date suggestion", "banking.reconcile", ["bank_statement_lines", "journal_lines"], ["G10"], resp="Reconciliation", tag=T, fr=["FR-064"])
E("POST", "/reconciliations/{id}/finish", "Finish (all lines handled and difference zero)", "banking.reconcile", ["bank_reconciliations", "bank_statement_imports", "money_accounts", "audit_logs"], ["G10"], rules=["BR-032"], resp="Reconciliation", tag=T, fr=["FR-064"], codes=[200, 422])
T = "Income and expenses"
E("GET", "/ie-categories", "Income or expense categories with ledger, budget and spent", "banking.view", ["ie_categories", "ledgers", "journal_lines"], ["I7", "I3", "I6", "I2", "I5"], params=["kind", "period"], resp="Page<IeCategory>", tag=T, fr=["FR-067"])
E("POST", "/ie-categories", "Create a category (new or existing ledger)", "banking.create", ["ie_categories", "ledgers"], ["I7"], req="IeCategoryInput", resp="IeCategory", tag=T, fr=["FR-067"], codes=[201, 422])
E("PATCH", "/ie-categories/{id}", "Edit name, budget, warning", "banking.edit", ["ie_categories"], ["I7"], req="IeCategoryInput", resp="IeCategory", tag=T, fr=["FR-067"])
E("GET", "/ie-entries", "Income or expense lines on category ledgers (any voucher)", "banking.view", ["journal_lines", "journal_entries", "ie_categories", "attachments", "payment_details"], ["I2", "I5", "I1", "I4"],
  params=LISTQ + DATEQ + ["kind", "category_id", "missing_receipt"], resp="Page<IeEntry>", tag=T, fr=["FR-068"])
E("POST", "/ie-entries", "Record an income or expense (creates INC/EXP voucher; optional recurrence)", "banking.create",
  ["journal_entries", "journal_lines", "payment_details", "ie_categories", "money_accounts", "recurring_templates", "number_sequences", "approval_requests"], ["I3", "I6"],
  rules=["BR-054", "BR-031", "BR-048", "BR-004", "BR-001", "BR-007"], req="IeEntryInput", idem=True, resp="Voucher", tx=TXP, tag=T, fr=["FR-066"], codes=[201, 202, 422])
E("GET", "/recurring-templates", "Recurring entries", "banking.view", ["recurring_templates"], ['I5', 'I2', 'I10'], resp="Page<RecurringTemplate>",
  tag=T, fr=["FR-066"])
E("PATCH", "/recurring-templates/{id}", "Pause, edit or end a recurring entry", "banking.edit", ["recurring_templates"], ['I5', 'I10'],
  req="RecurringTemplateInput", resp="RecurringTemplate", tag=T, fr=["FR-066"], ifmatch=True, rules=['BR-048', 'BR-049'])
E("GET", '/income-expenses/overview', "Income or expense overview for the FY", "banking.view", ["journal_lines", "ie_categories"], ["I1", "I4"],
  params=["kind", "period"], resp="ReportData", tag=T, fr=["FR-068"])

# ---------------------------------------------------------------- Accounting
T = "Accounting"
E("GET", "/accounting/overview", "Totals and books-health checks", "accounting.view", ["journal_lines", "journal_entries", "bank_statement_lines", "accounting_periods", "ledgers"], ["H1"], resp="ReportData", tag=T, fr=["FR-070"])
E("GET", "/account-groups", "Chart of accounts tree with balances", "accounting.view", ["account_groups", "ledgers", "journal_lines"], ["H2", "H3", "H4", "K16"], params=["as_of", "with_ledgers", "zero"], resp="AccountTree", tag=T, fr=["FR-071"])
E("POST", "/account-groups", "Create a group", "accounting.create", ["account_groups"], ["H2"], req="AccountGroupInput", resp="AccountGroup", tag=T, fr=["FR-071"], codes=[201, 422])
E("GET", "/account-groups/{id}", "Group details and children", "accounting.view", ["account_groups", "ledgers", "journal_lines"], ["H3"], resp="AccountGroup", tag=T, fr=["FR-071"])
E("PATCH", "/account-groups/{id}", "Edit a group (name, parent within nature, order)", "accounting.edit", ["account_groups"], ["H3"], rules=["BR-027"], req="AccountGroupInput", resp="AccountGroup", ifmatch=True, tag=T, fr=["FR-071"])
E("GET", "/ledgers", "Ledger list (balances only with accounting.view)", "auth.self", ["ledgers", "account_groups", "journal_lines"],
  ["H6", "H9", "H10", "H11", "H12", "K16", "D2", "C2", "K6", "I7"],
  params=LISTQ + ['nature', 'kind', 'cash_vouchers', 'active', 'purpose', 'account_group_id'], resp="Page<Ledger>", tag=T, fr=["FR-071"],
  desc='Picker for product, party, tax and category forms, and the ledger list. Without `accounting.view` the caller must send `purpose` (income_for_product, expense_for_product, purchase_for_supplier, tax, category) allowed by one of its create/edit permissions; only matching active posting ledgers are returned, without the balance fields (R-26). Sales staff therefore never browse the chart.',
  errors=['purpose_required'])
E("POST", "/ledgers", "Create a ledger (optional opening balance)", "accounting.create", ["ledgers", "journal_entries", "journal_lines", "account_groups"], ["H4"], rules=["BR-027", "BR-028"], req="LedgerInput", resp="Ledger", idem=True, tag=T, fr=["FR-071"], codes=[201, 422])
E("GET", "/ledgers/{id}", "Ledger details with FY summary", "accounting.view", ["ledgers", "journal_lines"], ["H5", "H7"], resp="Ledger", tag=T, fr=["FR-072"])
E("PATCH", "/ledgers/{id}", "Edit a ledger (code and opening locked once used)", "accounting.edit", ["ledgers", "journal_lines", "audit_logs"], ["H5"], rules=["BR-027", "BR-049"], req="LedgerInput", resp="Ledger", ifmatch=True, tag=T, fr=["FR-071"])
E("POST", "/ledgers/{id}/deactivate", "Make a ledger inactive (system-mapped ledgers refused)", "accounting.edit", ["ledgers", "account_mappings"], ["H5"], rules=["BR-026"], resp="Ledger", tag=T, fr=["FR-071"], codes=[200, 409])
E("GET", "/ledgers/{id}/entries", "Ledger entries with running balance and monthly closings", "accounting.view", ["journal_lines", "journal_entries"], ["H7"], params=DATEQ + ["page", "narration"], resp="LedgerStatement", tag=T, fr=["FR-072"])
E("GET", "/vouchers", "Voucher list / day book", "accounting.view", ["journal_entries", "journal_lines", "ledgers"], ["H8", "H1"], params=LISTQ + DATEQ + ["entry_type", "status", "ledger_id"], resp="Page<Voucher>", tag=T, fr=["FR-074", "FR-076"])
E("POST", "/vouchers", "Create a journal, payment, receipt or contra voucher (draft or post immediately)", 'auth.self',
  ["journal_entries", "journal_lines", "payment_details", "bill_allocations", "number_sequences", "money_accounts", "approval_requests", "roles"],
  ["H9", "H10", "H11", "H12", "G6", "G7", "G8", "G9"],
  rules=["BR-001", "BR-002", "BR-004", "BR-006", "BR-007", "BR-031", "BR-053", "BR-065", "BR-020"], req="VoucherInput", idem=True, resp="Voucher",
  tx=TXP, tag=T, fr=["FR-073", "FR-062", "FR-063"], codes=[201, 202, 409, 422],
  desc="`action`: draft | submit | post. Post above the limit is converted to submit (202). Banking pages call this with `banking.create`; journals need `accounting.create`.",
  perm_map={'entry_type = journal': 'accounting.create', 'entry_type in (payment, receipt, contra)': 'banking.create or accounting.create'})
E("GET", "/vouchers/{id}", "Voucher with lines, allocations, payment details, audit trail, linked documents", "accounting.view", ["journal_entries", "journal_lines", "payment_details", "bill_allocations", "attachments", "audit_logs"], ["H13", "H14"], resp="Voucher", tag=T, fr=["FR-074"])
E("PUT", "/vouchers/{id}", "Replace a draft or rejected voucher", 'auth.self', ["journal_entries", "journal_lines", "payment_details"],
  ["H9", "H10", "H11", "H12"], rules=["BR-025", "BR-001", "BR-049"], req="VoucherInput", resp="Voucher", ifmatch=True, tag=T, fr=["FR-073"],
  perm_map={'entry_type = journal': 'accounting.edit', 'entry_type in (payment, receipt, contra)': 'banking.edit or accounting.edit'})
E("DELETE", "/vouchers/{id}", "Delete a draft voucher (posted vouchers cannot be deleted)", "accounting.delete", ["journal_entries", "journal_lines", "audit_logs"], ["H13"], rules=["BR-025", "BR-003"], tag=T, fr=["FR-074"], codes=[204, 409])
E("POST", "/vouchers/{id}/submit", "Submit a draft for approval", 'auth.self', ["journal_entries", "approval_requests", "notifications"],
  ["H9", "H10"], rules=["BR-007"], resp="Voucher", tag=T, fr=["FR-073"],
  perm_map={'entry_type = journal': 'accounting.create', 'entry_type in (payment, receipt, contra)': 'banking.create or accounting.create'})
E("POST", "/vouchers/{id}/post", "Post a draft (or approved) voucher", 'auth.self',
  ["journal_entries", "journal_lines", "bill_allocations", "accounting_periods", "approval_requests"], ["H9", "H10", "H11", "H12"],
  rules=["BR-001", "BR-004", "BR-007", "BR-009", "BR-031"], req="PostRequest", idem=True, resp="Voucher", tx=TXP, tag=T, fr=["FR-073"],
  codes=[200, 202, 409, 422],
  perm_map={'entry_type = journal': 'accounting.create', 'entry_type in (payment, receipt, contra)': 'banking.create or accounting.create'})
E("POST", "/vouchers/{id}/reverse", "Create a linked reversing entry", "accounting.create", ["journal_entries", "journal_lines", "bill_allocations", "accounting_periods", "number_sequences", "audit_logs"], ["Acc-DlgReverse", "H13"],
  rules=["BR-003", "BR-004", "BR-009"], req="ReverseRequest", idem=True, resp="Voucher", tx=TXP + " The original row is locked; a second reversal hits UNIQUE(reverses_entry_id).", tag=T, fr=["FR-074"], codes=[201, 409, 422],
  desc="System entries (source_type set) are reversed through their document's cancel endpoint, not here (409).")
E("POST", "/vouchers/calculate", "Authoritative balance and double-entry preview for an unsaved voucher (no write)", "auth.self", ["ledgers", "money_accounts", "journal_lines", "company_settings", "roles"], ["H9", "H10", "H11", "H12", "G6", "G7", "G8", "G9"], rules=["BR-001", "BR-002", "BR-031", "BR-007", "BR-053"], req="VoucherInput", resp="VoucherPreview", tag=T, fr=["FR-073"], codes=[200, 422], perm_map={'entry_type = journal': 'accounting.create', 'entry_type in (payment, receipt, contra)': 'banking.create or accounting.create'}, added="R-27", desc="Returns lines, totals, difference, balanced flag, whether approval will be needed and warnings (insufficient funds, back-dated). The client-side sum is only a typing indicator (DEC-09).")
E("GET", "/vouchers/{id}/pdf", "Printable voucher (A4)", "accounting.view", ["journal_entries", "journal_lines", "companies"], ["H14"], resp="Binary", tag=T, fr=["FR-074"])
E("GET", "/voucher-templates", "Journal templates (depreciation, accruals, reclassify)", "accounting.view", ["ledgers"], ["H9"], resp="TemplateList", tag=T, fr=["FR-073"])

# ---------------------------------------------------------------- Reports
T = "Reports"
for path, name, pg, extra in [("trial-balance", "Trial balance", ["H15", "H1", "J1"], ["as_of", "level"]), ("profit-and-loss", "Profit and loss", ["H16", "J1"], DATEQ + ["compare", "detail", "branch_id"]),
                              ("balance-sheet", "Balance sheet", ["H17", "J1"], ["as_of", "compare", "detail"]), ("cash-flow", "Cash flow (indirect)", ["H18", "J1"], DATEQ),
                              ("general-ledger", "General ledger", ["H19", "J1"], DATEQ + ["ledger_ids", "group_by"]), ("receivables-aging", "Receivables aging", ["J6"], ["as_of", "q", "overdue_only"]),
                              ("payables-aging", "Payables aging", ["J7"], ["as_of", "q", "overdue_only"])]:
    E("GET", f"/reports/{path}", f"{name} (JSON; xlsx/pdf/csv via format)", "reports.view",
      ["journal_lines", "journal_entries", "ledgers", "account_groups"] + (["sales_invoices", "purchase_bills", "parties"] if "aging" in path else []), pg, rules=["BR-005", "BR-001"],
      params=extra + ["format"], resp="ReportData", tag=T, fr=["FR-076"], codes=[200, 202, 422], desc="Reads posted lines only. `format=xlsx|pdf|csv` requires reports.export and returns the file; when the export exceeds 5,000 rows the answer is 202 with an ExportJob to poll.")
for path, name, pg in [("sales", "Sales report", ["E11", "J1"]), ("sales-returns", "Sales return report", ["E12"]), ("purchases", "Purchase report", ["F9", "J1"]), ("purchase-returns", "Purchase return report", ["F10"]),
                       ("income", "Income report", ["I8", "J1"]), ("expenses", "Expense report", ["I9", "J1"])]:
    E("GET", f"/reports/{path}", name, "reports.view", ["sales_invoices", "sales_invoice_lines", "sales_returns", "purchase_bills", "purchase_bill_lines", "purchase_returns", "journal_lines", "ie_categories", "product_categories", "parties"][:10] if "sales" in path or "purch" in path else ["journal_lines", "ie_categories"],
      pg, params=DATEQ + ["group_by", "branch_id", "party_id", "reason", "format"], resp="ReportData", codes=[200, 202, 422], desc="`format=xlsx|pdf|csv` requires reports.export (202 + ExportJob above 5,000 rows).", tag=T, fr=["FR-047" if "sales" in path else "FR-055" if "purch" in path else "FR-068"])

# ---------------------------------------------------------------- LC
T = "LC"
E("GET", '/lc/overview', "LC KPIs, alerts, deadlines, by bank, pipeline, active, recent", "lc.view",
  ["letters_of_credit", "lc_payments", "lc_charges", "lc_documents", "lc_shipments", "lc_amendments", "audit_logs"], ["M1"], resp="ReportData",
  tag=T, fr=["FR-090"])
E("GET", "/letters-of-credit", "List LCs", "lc.view", ["letters_of_credit", "parties", "money_accounts", "lc_documents", "lc_payments", "lc_charges"], ["M2", "M5", "M7", "F3"], params=LISTQ + ["group", "bank_account_id", "party_id", "attention"], resp="Page<LetterOfCredit>", tag=T, fr=["FR-091"])
E("POST", "/letters-of-credit", "Create a draft LC (documents copied from template)", "lc.create", ["letters_of_credit", "lc_documents", "lc_document_template_items", "lc_shipments", "number_sequences"], ["M3", "F8"],
  rules=["BR-006"], req="LetterOfCreditInput", resp="LetterOfCredit", idem=True, tag=T, fr=["FR-092"], codes=[201, 422])
E("GET", "/letters-of-credit/{id}", "LC with milestones, terms, documents, shipments, charges, payments, amendments, money position", "lc.view",
  ["letters_of_credit", "lc_documents", "lc_shipments", "lc_charges", "lc_payments", "lc_amendments", "lc_amendment_changes"], ["M4"], resp="LetterOfCredit", tag=T, fr=["FR-093"])
E("PUT", "/letters-of-credit/{id}", "Update a draft or awaiting-bank LC (opened LCs change only through amendments)", "lc.edit", ["letters_of_credit", "lc_documents", "lc_shipments"], ["M3"], rules=["BR-040", "BR-041", "BR-049"], req="LetterOfCreditInput", resp="LetterOfCredit", ifmatch=True, tag=T, fr=["FR-092"])
E("DELETE", "/letters-of-credit/{id}", "Delete a draft LC", "lc.delete", ["letters_of_credit", "lc_documents", "lc_shipments"], ["M3"], rules=["BR-025"], tag=T, fr=["FR-092"], codes=[204, 409])
E("POST", "/letters-of-credit/{id}/transition", "Change status: submit, mark_opened, mark_refused, documents_presented, cancel, expire, reopen", "lc.edit", ["letters_of_credit", "audit_logs"], ["M3", "M4"],
  rules=["BR-039", "BR-040"], req="LcTransition", resp="LetterOfCredit", ifmatch=True, tag=T, fr=["FR-092", "FR-093"], codes=[200, 409, 422])
E("POST", "/letters-of-credit/{id}/close", "Close after checks (or with override reason); margin release posted", "lc.edit", ["letters_of_credit", "lc_payments", "lc_charges", "lc_documents", "journal_entries", "journal_lines"], ["LC-DlgClose", "M4"],
  rules=["BR-046", "BR-044", "BR-001"], req="LcCloseRequest", idem=True, resp="LetterOfCredit", tx=TXP, tag=T, fr=["FR-099"], codes=[200, 422])
E("GET", "/letters-of-credit/{id}/documents", "Documents of an LC", "lc.view", ["lc_documents", "attachments"], ["M4"], resp="Page<LcDocument>", tag=T, fr=["FR-095"])
E("POST", "/letters-of-credit/{id}/documents", "Add a required document", "lc.edit", ["lc_documents"], ["M4", "M3"], req="LcDocumentInput", resp="LcDocument", tag=T, fr=["FR-095"], codes=[201, 422])
E("PATCH", "/lc-documents/{id}", "Update document status, dates, discrepancy note", "lc.edit", ["lc_documents", "audit_logs"], ["M4", "M6"], req="LcDocumentInput", resp="LcDocument", ifmatch=True, tag=T, fr=["FR-095"])
E("GET", "/lc-documents/matrix", "Document status matrix across open LCs and follow-up list", "lc.view", ["lc_documents", "letters_of_credit"], ["M6"], resp="ReportData", tag=T, fr=["FR-095"])
E("GET", "/lc-document-templates", "Checklist templates with items", "lc.view", ["lc_document_templates", "lc_document_template_items"], ["M3", "M6"], resp="Page<LcDocumentTemplate>", tag=T, fr=["FR-095"])
E("PUT", "/lc-document-templates/{id}", "Replace a template's items", "lc.edit", ["lc_document_templates", "lc_document_template_items"], ["M6"], req="LcDocumentTemplateInput", resp="LcDocumentTemplate", tag=T, fr=["FR-095"])
E("POST", "/lc-document-templates", "Create a template", "lc.create", ["lc_document_templates", "lc_document_template_items"], ["M6"], req="LcDocumentTemplateInput", resp="LcDocumentTemplate", tag=T, fr=["FR-095"], codes=[201, 422])
E("GET", "/lc-amendments", "All amendments (filter pending/accepted)", "lc.view", ["lc_amendments", "lc_amendment_changes", "letters_of_credit"], ["M5", "M8"], params=LISTQ + ["status"], resp="Page<LcAmendment>", tag=T, fr=["FR-094"])
E("POST", "/letters-of-credit/{id}/amendments", "Record an amendment with field changes", "lc.edit", ["lc_amendments", "lc_amendment_changes", "letters_of_credit", "lc_charges"], ["M5", "M4"], rules=["BR-041"], req="LcAmendmentInput", resp="LcAmendment", idem=True, tag=T, fr=["FR-094"], codes=[201, 422])
E("PATCH", "/lc-amendments/{id}", "Advance amendment status; on accepted the LC is updated", "lc.edit", ["lc_amendments", "lc_amendment_changes", "letters_of_credit", "audit_logs"], ["M5", "M4"], rules=["BR-041"], req="LcAmendmentStatus", resp="LcAmendment", ifmatch=True, tx="Locks the LC row when applying accepted changes.", tag=T, fr=["FR-094"])
E("POST", "/letters-of-credit/{id}/shipments", "Add a shipment lot", "lc.edit", ["lc_shipments"], ["M4"], req="LcShipmentInput", resp="LcShipment", tag=T, fr=["FR-096"], codes=[201, 422])
E("PATCH", "/lc-shipments/{id}", "Update a lot (status, B/L, vessel, ETD/ETA, arrived)", "lc.edit", ["lc_shipments", "letters_of_credit", "audit_logs"], ["M4"], req="LcShipmentInput", resp="LcShipment", ifmatch=True, tag=T, fr=["FR-096"])
E("GET", "/lc-charges", "Charges across LCs (filter by LC and paid status)", "lc.view", ["lc_charges", "letters_of_credit"], ["M7"], params=LISTQ + ["letter_of_credit_id", "status"], resp="Page<LcCharge>", tag=T, fr=["FR-097"])
E("POST", "/lc-charges", "Add a charge or import expense (posts accrual or payment)", "lc.create", ["lc_charges", "journal_entries", "journal_lines", "payment_details", "money_accounts", "account_mappings", "number_sequences"], ["LC-DrawerCharge", "M4", "M7"],
  rules=["BR-044", "BR-045", "BR-047", "BR-001", "BR-004", "BR-031"], req="LcChargeInput", idem=True, resp="LcCharge", tx=TXP, tag=T, fr=["FR-097"], codes=[201, 422])
E("POST", "/lc-charges/{id}/reverse", "Reverse an LC charge (refused once included in a posted landed-cost allocation)", "lc.edit", ["lc_charges", "journal_entries", "journal_lines", "number_sequences", "audit_logs"], ["M4", "M7"], rules=["BR-003", "BR-004", "BR-009", "BR-045"], req="ReasonRequest", idem=True, resp="LcCharge", tx=TXP, tag=T, fr=["FR-097"], codes=[200, 409, 422], errors=["charge_allocated"], added="R-03")
E("POST", "/lc-charges/{id}/pay", "Pay an unpaid charge", "lc.create", ["lc_charges", "journal_entries", "journal_lines", "payment_details"], ["M4", "M7"], rules=["BR-031", "BR-001", "BR-004"], req="ChargePayment", idem=True, resp="LcCharge", tx=TXP, tag=T, fr=["FR-097"])
E("POST", "/letters-of-credit/{id}/payments", "Record a payment to the beneficiary at the bank's rate (applies margin, FX)", "lc.create",
  ["lc_payments", "letters_of_credit", "journal_entries", "journal_lines", "purchase_bills", "bill_allocations", "money_accounts", "account_mappings"], ["LC-DlgPay", "M4"],
  rules=["BR-042", "BR-043", "BR-044", "BR-001", "BR-004", "BR-031"], req="LcPaymentInput", idem=True, resp="LcPayment", tx=TXP, tag=T, fr=["FR-097"], codes=[201, 422])
E("GET", "/letters-of-credit/{id}/payments", "Payments of an LC with bank rate, margin applied and FX difference", "lc.view", ["lc_payments", "journal_entries"], ["M4"], resp="Page<LcPayment>", tag=T, fr=["FR-097"], added="R-03")
E("POST", "/lc-payments/{id}/reverse", "Reverse an LC payment (restores margin and supplier balance; mirror entry)", "lc.edit", ["lc_payments", "letters_of_credit", "journal_entries", "journal_lines", "bill_allocations", "purchase_bills", "number_sequences", "audit_logs"], ["M4"], rules=["BR-003", "BR-004", "BR-009", "BR-040"], req="ReasonRequest", idem=True, resp="LcPayment", tx=TXP, tag=T, fr=["FR-097"], codes=[200, 409, 422], added="R-03")
E("POST", "/letters-of-credit/{id}/landed-cost/preview", "Preview allocation of capitalised charges to an import bill's lines", "lc.view", ["lc_charges", "purchase_bill_lines", "stock_balances"], ["M4", "M7"], rules=["BR-045"], req="LandedCostRequest", resp="LandedCostAllocation", tag=T, fr=["FR-098"])
E("POST", "/letters-of-credit/{id}/landed-cost", "Post a landed-cost allocation (inventory and COGS split)", "lc.create",
  ["landed_cost_allocations", "landed_cost_allocation_lines", "lc_charges", "purchase_bill_lines", "stock_movements", "journal_entries", "journal_lines"], ["M4", "M7"],
  rules=["BR-045", "BR-017", "BR-001", "BR-004", "BR-009"], req="LandedCostRequest", idem=True, resp="LandedCostAllocation", tx=TXP, tag=T, fr=["FR-098"], codes=[201, 422])
E("POST", "/landed-cost-allocations/{id}/reverse", "Reverse a landed-cost allocation (inventory/COGS split and landed_cost movements)", "lc.edit", ["landed_cost_allocations", "landed_cost_allocation_lines", "lc_charges", "stock_movements", "stock_balances", "journal_entries", "journal_lines", "number_sequences", "audit_logs"], ["M4", "M7"], rules=["BR-003", "BR-004", "BR-009", "BR-045", "BR-017"], req="ReasonRequest", idem=True, resp="LandedCostAllocation", tx=TXP, tag=T, fr=["FR-098"], codes=[200, 409, 422], added="R-03")
E("GET", "/letters-of-credit/{id}/pdf", "Printable LC summary", "lc.view", ["letters_of_credit", "lc_documents", "lc_shipments", "lc_charges", "lc_payments", "parties", "companies"], ["M4"], resp="Binary", tag=T, fr=["FR-093"], added="C-01")
E("GET", "/letters-of-credit/{id}/cost-summary", "Landed cost breakdown and per-unit cost", "lc.view", ["lc_payments", "lc_charges", "landed_cost_allocations", "purchase_bills"], ["M7", "M4"], resp="ReportData", tag=T, fr=["FR-098"])
E("GET", "/reports/lc/{report}", "LC reports: register, status, outstanding, expiry, docs, amendments, charges, cost", "lc.view",
  ["letters_of_credit", "lc_payments", "lc_charges", "lc_documents", "lc_amendments", "landed_cost_allocations"], ["M8"], params=DATEQ + ["bank_account_id", "segment", "format"], resp="ReportData", tag="Reports", fr=["FR-099"])


# ---------------------------------------------------------------- contract-review corrections, second pass (18 Oct 2026)
# Found by sweeping the derived database operations of every endpoint that writes the books or stock.
# Kept as explicit, commented fixes so the reason stays next to the change.
def _fix(method, path, add_tables=(), ops=None):
    e = next(x for x in EP if x["method"] == method and x["path"] == path)
    for t in add_tables:
        if t not in e["tables"]:
            e["tables"].append(t)
    if ops:
        e["ops"].update(ops)


# R-06: entries without their own document number take the next system_journal (SJ) number.
for _m, _p in [("POST", "/stock-adjustments/{id}/reverse"), ("POST", "/stock-transfers/{id}/dispatch"), ("POST", "/stock-transfers/{id}/receive"),
               ("POST", "/stock-transfers/{id}/cancel"), ("POST", "/sales-invoices/{id}/cancel"), ("POST", "/purchase-bills/{id}/cancel"),
               ("POST", "/cash-counts"), ("POST", "/letters-of-credit/{id}/close"), ("POST", "/lc-charges/{id}/pay"),
               ("POST", "/letters-of-credit/{id}/payments"), ("POST", "/letters-of-credit/{id}/landed-cost"),
               ("POST", "/credit-notes/{id}/post"), ("POST", "/debit-notes/{id}/post")]:
    _fix(_m, _p, ["number_sequences"])
# Bulk post performs exactly the single-invoice posting per item.
_fix("POST", "/sales-invoices/bulk-post", ["account_mappings", "accounting_periods", "sales_invoice_lines", "number_sequences"])
# A draft voucher already has its lines; posting flips their status, it does not insert them.
_fix("POST", "/vouchers/{id}/post", ops={"journal_entries": "LU", "journal_lines": "U"})
# Editing a draft voucher replaces its lines.
_fix("PUT", "/vouchers/{id}", ops={"journal_entries": "U", "journal_lines": "DC", "payment_details": "U"})
# Landed cost raises the receiving warehouse's stock value (R-02: value lives in stock_balances).
_fix("POST", "/letters-of-credit/{id}/landed-cost", ["stock_balances"])
# An allocation links an existing settling line to a document; it creates no journal lines.
_fix("POST", "/allocations", ops={"bill_allocations": "C", "journal_lines": "L", "payment_details": "LU", "sales_invoices": "LU",
                                  "purchase_bills": "LU", "credit_notes": "R", "debit_notes": "R"})
# Converting a quotation or PO creates the draft invoice or bill (with lines) and reserves its number at first save.
_fix("POST", "/quotations/{id}/convert", ops={"sales_invoices": "C", "sales_invoice_lines": "C", "number_sequences": "LU"})
_fix("POST", "/purchase-orders/{id}/convert", ops={"purchase_bills": "C", "purchase_bill_lines": "C", "number_sequences": "LU"})
