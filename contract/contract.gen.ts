// GENERATED from docs/source/schemas.py by docs/source/build_ts_types.py - do not edit by hand.
// Field types follow docs/openapi.yaml (API contract 1.0.0-rc.1).

/** Decimal as string, e.g. "118450.00". Money never travels as a JSON number (05 §1). */
export type Decimal = string

export type ErrorCode =
  | 'allocation_exceeds_balance'
  | 'already_posted'
  | 'already_reversed'
  | 'approval_required'
  | 'backdated_confirmation_required'
  | 'base_currency_locked'
  | 'branch_restricted'
  | 'charge_allocated'
  | 'comment_required'
  | 'company_maintenance'
  | 'company_required'
  | 'created_from_return'
  | 'difference_not_zero'
  | 'duplicate'
  | 'duplicate_statement'
  | 'duplicate_supplier_bill'
  | 'exceeds_tolerance'
  | 'file_too_large'
  | 'file_type_not_allowed'
  | 'fiscal_year_not_closable'
  | 'foreign_document'
  | 'gone'
  | 'has_history'
  | 'idempotency_in_progress'
  | 'idempotency_key_reused'
  | 'if_match_required'
  | 'inactive_reference'
  | 'insufficient_funds'
  | 'insufficient_stock'
  | 'invalid_credentials'
  | 'invalid_mobile'
  | 'invalid_transition'
  | 'last_owner'
  | 'lc_not_closable'
  | 'lc_submit_incomplete'
  | 'ledger_locked'
  | 'lines_unhandled'
  | 'lock_out_of_order'
  | 'malformed_json'
  | 'mapping_missing'
  | 'margin_exceeds_deposit'
  | 'nature_mismatch'
  | 'not_a_member'
  | 'not_draft'
  | 'not_found'
  | 'note_applied'
  | 'period_locked'
  | 'permission_denied'
  | 'purpose_required'
  | 'rate_limited'
  | 'refresh_reuse_detected'
  | 'refresh_token_invalid'
  | 'restore_confirmation_required'
  | 'return_qty_exceeds'
  | 'role_in_use'
  | 'rounding_ledger_missing'
  | 'same_warehouse'
  | 'seat_limit_reached'
  | 'self_approval_forbidden'
  | 'server_error'
  | 'system_role'
  | 'token_expired'
  | 'token_invalid'
  | 'two_factor_failed'
  | 'two_factor_required_by_company'
  | 'unauthenticated'
  | 'unbalanced_entry'
  | 'use_document_cancel'
  | 'validation_failed'
  | 'version_conflict'

/** Accept an invitation. */
export interface AcceptInvitationRequest {
  name?: string | null
  password?: string | null
  password_confirmation?: string | null
}

/** Account group. */
export interface AccountGroup {
  id: number
  code: string
  name: string
  parent_id?: number | null
  nature: string
  normal_balance: string
  report_section: string
  cash_flow_category?: string | null
  is_system: boolean
  balance: Decimal
  children?: Array<Record<string, unknown>>
  version: number
}

/** Create or edit a group. */
export interface AccountGroupInput {
  code: string
  name: string
  parent_id: number
  cash_flow_category?: 'operating' | 'investing' | 'financing' | 'cash' | null
  sort_order?: number
  used_in_cash_vouchers?: boolean
}

/** One posting mapping. */
export interface AccountMapping {
  mapping_key: string
  ledger_id?: number | null
  account_group_id?: number | null
  required_nature: string
  description?: string | null
}

/** Mapping key -> ledger or group. */
export interface AccountMappings {
  mappings: Array<AccountMapping>
}

/** Group or ledger node. */
export interface AccountNode {
  kind: 'primary' | 'group' | 'ledger'
  id: number
  code: string
  name: string
  nature: string
  balance: Decimal
  balance_side: 'dr' | 'cr'
  note?: string | null
  children?: Array<AccountNode>
}

/** Chart of accounts tree. */
export interface AccountTree {
  as_of: string
  nodes: Array<AccountNode>
  total_debit: Decimal
  total_credit: Decimal
  balanced: boolean
}

/** Monthly period. */
export interface AccountingPeriod {
  id: number
  name: string
  start_date: string
  end_date: string
  status: 'future' | 'open' | 'locked'
  locked_at?: string | null
  locked_by?: UserRef | null
  lock_note?: string | null
}

/** Counted quantity. */
export interface AdjustmentLineInput {
  product_id: number
  counted_qty: Decimal
}

/** Aging bucket. */
export interface AgingBucket {
  key: 'not_due' | 'd1_30' | 'd31_60' | 'd61_90' | 'd90_plus' | 'total'
  amount: Decimal
  count: number
}

/** Settlement applied to a document. */
export interface Allocation {
  id: number
  voucher_id: number
  voucher_number: string
  date: string
  amount: Decimal
  kind: 'receipt' | 'payment' | 'credit_note' | 'debit_note' | 'advance'
  reversed: boolean
}

/** Apply an open settling line to a document of the same party (R-08). journal_line_id is the party-ledger line of a receipt, payment, advance or posted credit/debit note. */
export interface AllocationCreate {
  journal_line_id: number
  document_type: 'sales_invoice' | 'purchase_bill'
  document_id: number
  amount: Decimal
  allocated_on?: string | null
}

/** Amount to apply to one document. */
export interface AllocationInput {
  document_id: number
  amount: Decimal
}

/** Changed field. */
export interface AmendmentChange {
  field_key: 'expiry_date' | 'latest_shipment_date' | 'amount' | 'tolerance_pct' | 'other'
  new_value: string
}

/** Approve or reject. Comment required to reject. */
export interface ApprovalDecision {
  comment?: string | null
}

/** Approval request. */
export interface ApprovalRequest {
  id: number
  document_type: string
  document_id: number
  document_number: string
  amount: Decimal
  limit?: Decimal | null
  required_permission: string
  status: 'pending' | 'approved' | 'rejected' | 'cancelled'
  requested_by: UserRef
  requested_at: string
  decided_by?: UserRef | null
  decided_at?: string | null
  comment?: string | null
  facts?: Record<string, unknown> | null
}

/** Uploaded file. */
export interface Attachment {
  id: number
  original_name: string
  mime_type: string
  size_bytes: number
  uploaded_by: UserRef
  created_at: string
}

/** multipart: file, attachable_type, attachable_id. */
export interface AttachmentUpload {
  file: string
  attachable_type: 'sales_invoice' | 'purchase_bill' | 'voucher' | 'lc_document' | 'letter_of_credit' | 'stock_adjustment' | 'party'
  attachable_id: number
}

/** Audit entry. */
export interface AuditLog {
  id: number
  action: string
  user?: UserRef | null
  old_values?: Record<string, unknown> | null
  new_values?: Record<string, unknown> | null
  ip_address?: string | null
  created_at: string
}

/** Company backup. */
export interface Backup {
  id: number
  kind: 'automatic' | 'manual' | 'pre_restore' | 'pre_lock'
  status: 'running' | 'complete' | 'failed' | 'expired'
  size_bytes?: number | null
  started_at: string
  finished_at?: string | null
  created_by?: UserRef | null
}

/** Location. */
export interface Branch {
  id: number
  code: string
  name: string
  kind?: string | null
  address?: string | null
  phone?: string | null
  is_head_office: boolean
  is_active: boolean
  version: number
}

/** Create or edit a location. */
export interface BranchInput {
  code: string
  name: string
  kind?: string | null
  address?: string | null
  phone?: string | null
  is_head_office?: boolean
  is_active?: boolean
}

/** One failed item. */
export interface BulkFailure {
  id: number
  code: string
  detail: string
}

/** Ids for a bulk action. */
export interface BulkIds {
  ids: Array<number>
}

/** Per-item outcome of a bulk action. */
export interface BulkResult {
  succeeded: Array<number>
  failed: Array<BulkFailure>
}

/** Calculated line. */
export interface CalculatedLine {
  line_no: number
  gross: Decimal
  discount_amount: Decimal
  tax_amount: Decimal
  line_total: Decimal
  available_qty?: Decimal | null
}

/** Cancel a posted document. */
export interface CancelRequest {
  reason: 'customer_cancelled' | 'entered_by_mistake' | 'wrong_party' | 'duplicate' | 'other'
  note?: string | null
  cancellation_date?: string | null
}

/** Recorded cash count. */
export interface CashCount {
  id: number
  money_account_id: number
  counted_at: string
  counted_by: UserRef
  book_balance: Decimal
  counted_total: Decimal
  difference: Decimal
  note?: string | null
  status: 'recorded' | 'adjusted' | 'reversed'
  adjustment_entry_id?: number | null
}

/** Cash count. */
export interface CashCountInput {
  money_account_id: number
  counted_at: string
  denominations: Array<Denomination>
  note?: string | null
}

/** Money received with a cash sale; amount must equal the invoice total. */
export interface CashReceiptInput {
  money_account_id: number
  method: 'cash' | 'cheque' | 'bank_transfer' | 'beftn' | 'rtgs' | 'npsb' | 'bkash' | 'nagad' | 'card'
  amount: Decimal
  transaction_reference?: string | null
}

/** Change own password. */
export interface ChangePasswordRequest {
  current_password: string
  password: string
  password_confirmation: string
}

/** Pay a charge. */
export interface ChargePayment {
  paid_from_account_id: number
  date: string
  reference?: string | null
}

/** Company profile. */
export interface Company {
  id: number
  legal_name: string
  trading_name?: string | null
  business_type: string
  trade_licence_no?: string | null
  tax_registration_no?: string | null
  incorporated_on?: string | null
  registered_address?: string | null
  phone?: string | null
  email?: string | null
  website?: string | null
  logo_url?: string | null
  base_currency_code: string
  fy_start_month: number
  timezone: string
  branches?: Array<Branch>
  seats_used?: number
  seat_limit?: number
  version: number
}

/** New company. */
export interface CompanyCreate {
  legal_name: string
  trading_name?: string | null
  business_type: 'private_limited' | 'sole_proprietorship' | 'partnership' | 'public_limited'
  fy_start_month?: number
  base_currency_code?: string
}

/** All company settings (see company_settings). */
export interface CompanySettings {
  valuation_method: 'weighted_average' | 'fifo'
  invoice_rounding: 'none' | 'nearest_1' | 'nearest_5'
  allow_negative_stock: boolean
  require_approval: boolean
  backdate_warning_days: number
  prices_include_tax: boolean
  show_tax_no_on_invoice: boolean
  default_warehouse_id?: number | null
  default_payment_term_id?: number | null
  symbol_position: 'before' | 'after'
  table_decimals: number
  negative_format: 'parentheses' | 'minus'
  number_grouping: 'lakh' | 'million'
  date_format: string
  week_start: 'saturday' | 'sunday' | 'monday'
  print_language: 'en' | 'bn' | 'both'
  invoice: Record<string, unknown>
  require_two_factor: boolean
  idle_timeout_minutes?: number | null
  backup_enabled?: boolean
  backup_retention_days?: number
  version: number
}

/** Partial update; same fields as CompanySettings except version. */
export interface CompanySettingsUpdate {
  valuation_method?: 'weighted_average' | 'fifo'
  invoice_rounding?: 'none' | 'nearest_1' | 'nearest_5'
  allow_negative_stock?: boolean
  require_approval?: boolean
  backdate_warning_days?: number
  prices_include_tax?: boolean
  show_tax_no_on_invoice?: boolean
  default_warehouse_id?: number | null
  default_payment_term_id?: number | null
  symbol_position?: 'before' | 'after'
  table_decimals?: number
  negative_format?: 'parentheses' | 'minus'
  number_grouping?: 'lakh' | 'million'
  date_format?: string
  week_start?: 'saturday' | 'sunday' | 'monday'
  print_language?: 'en' | 'bn' | 'both'
  invoice?: Record<string, unknown>
  require_two_factor?: boolean
  idle_timeout_minutes?: number | null
  backup_enabled?: boolean
  backup_retention_days?: number
}

/** Editable company fields. */
export interface CompanyUpdate {
  legal_name?: string
  trading_name?: string | null
  business_type?: 'private_limited' | 'sole_proprietorship' | 'partnership' | 'public_limited'
  trade_licence_no?: string | null
  tax_registration_no?: string | null
  incorporated_on?: string | null
  registered_address?: string | null
  phone?: string | null
  email?: string | null
  website?: string | null
}

/** Parsed count sheet. */
export interface CountSheetPreview {
  lines: Array<Record<string, unknown>>
  unknown_codes?: Array<string>
}

/** Create the missing voucher. */
export interface CreateEntryFromLine {
  kind: 'receipt' | 'bank_charge' | 'payment'
  ledger_id: number
  party_id?: number | null
  narration?: string | null
}

/** A1 payload. */
export interface DashboardSummary {
  period: Record<string, unknown>
  kpis: Array<Kpi>
  revenue_vs_expenses: Array<Record<string, unknown>>
  cash_position: Array<Record<string, unknown>>
  money_owed: Record<string, unknown>
  upcoming_payments: Array<Record<string, unknown>>
  recent_transactions: Array<Record<string, unknown>>
  inventory_alerts: Array<Record<string, unknown>>
  banners?: Array<Record<string, unknown>>
}

/** Delivery details. */
export interface DeliveryUpdate {
  challan_no?: string | null
  vehicle_no?: string | null
  delivered_at?: string | null
}

/** Note or coin count. */
export interface Denomination {
  denomination: Decimal
  count: number
}

/** Server-side totals for an unsaved document. */
export interface DocumentCalculation {
  lines: Array<CalculatedLine>
  warnings: Array<Warning>
  party_balance_before?: Decimal | null
  party_balance_after?: Decimal | null
  credit_limit?: Decimal | null
  effects?: Array<string>
  subtotal: Decimal
  discount_total: Decimal
  tax_total: Decimal
  rounding_adjustment: Decimal
  total: Decimal
  amount_in_words: string
}

/** Send a document by e-mail. */
export interface EmailRequest {
  to: Array<string>
  cc?: Array<string>
  message?: string | null
}

/** RFC 9457 problem details (application/problem+json), extended with a stable application error code (see the error catalogue), the request id and, for 422, field errors keyed by JSON path (e.g. lines.2.quantity). */
export interface Error {
  type: string
  title: string
  status: number
  code: ErrorCode
  detail?: string | null
  instance?: string | null
  request_id: string
  errors?: Record<string, string[]> | null
  warnings?: Array<Warning>
}

/** Async export. */
export interface ExportJob {
  id: number
  kind: 'export_pack' | 'report' | 'list'
  status: 'queued' | 'running' | 'complete' | 'failed'
  download_url?: string | null
  expires_at?: string | null
  finished_at?: string | null
  error?: string | null
}

/** Start an export. export_pack: month-end pack (`period`, `reports`). list: any list screen, `source` = the list operationId (e.g. getParties) and `filters` = its query parameters; the caller needs that list's view permission as well. */
export interface ExportJobCreate {
  kind: 'export_pack' | 'list'
  format: 'xlsx' | 'pdf' | 'both' | 'csv'
  period?: string | null
  reports?: Array<string>
  source?: string | null
  filters?: Record<string, unknown> | null
}

/** multipart/form-data with a `file` part. */
export interface FileUpload {
  file: string
}

/** Financial year with periods. */
export interface FiscalYear {
  id: number
  name: string
  start_date: string
  end_date: string
  status: 'open' | 'closing' | 'closed'
  periods: Array<AccountingPeriod>
}

/** Request a reset link. */
export interface ForgotPasswordRequest {
  email: string
}

/** Income or expense category. */
export interface IeCategory {
  id: number
  kind: 'income' | 'expense'
  name: string
  description?: string | null
  ledger_id: number
  ledger_code: string
  monthly_budget?: Decimal | null
  warn_at_percent: Decimal
  spent: Decimal
  used_pct?: Decimal | null
  status: 'on_track' | 'near_limit' | 'over_budget' | 'not_used' | 'no_budget'
}

/** Create or edit a category. */
export interface IeCategoryInput {
  kind: 'income' | 'expense'
  name: string
  description?: string | null
  ledger_id?: number | null
  create_ledger?: boolean
  monthly_budget?: Decimal | null
  warn_at_percent?: Decimal
}

/** Line on a category ledger. */
export interface IeEntry {
  journal_line_id: number
  voucher_id: number
  voucher_number: string
  date: string
  description: string
  category: string
  account: string
  method?: string | null
  amount: Decimal
  has_receipt: boolean
}

/** Quick income or expense. */
export interface IeEntryInput {
  kind: 'income' | 'expense'
  amount: Decimal
  date: string
  category_id: number
  money_account_id: number
  method: 'cash' | 'cheque' | 'bank_transfer' | 'beftn' | 'rtgs' | 'npsb' | 'bkash' | 'nagad' | 'card'
  payee_text?: string | null
  reference?: string | null
  tax_rule_id?: number | null
  notes?: string | null
  attachment_ids?: Array<number>
  recurrence?: RecurrenceInput | null
  confirm_backdated?: boolean
}

/** Outcome of an import: per-row errors on dry run, created ids on commit. */
export interface ImportResult {
  dry_run: boolean
  rows: number
  valid_rows: number
  created_ids?: Array<number>
  errors: Array<ImportRowError>
}

/** One rejected import row. */
export interface ImportRowError {
  row: number
  column?: string | null
  code: ErrorCode
  message: string
}

/** Pending invitation. */
export interface Invitation {
  id: number
  email: string
  name?: string | null
  role_id: number
  expires_at: string
  status: 'pending' | 'accepted' | 'revoked' | 'expired'
}

/** Invite a user. */
export interface InvitationCreate {
  email: string
  name?: string | null
  role_id: number
  branch_ids?: Array<number>
}

/** Invitation seen before accepting. */
export interface InvitationPublic {
  company_name: string
  role_name: string
  email: string
  expires_at: string
  existing_user: boolean
}

/** Stat tile. */
export interface Kpi {
  key: string
  label: string
  value: Decimal
  delta_pct?: Decimal | null
  trend?: Array<Decimal>
  foot?: string | null
}

/** Allocation preview or result. */
export interface LandedCostAllocation {
  id?: number | null
  status: 'preview' | 'draft' | 'posted' | 'reversed'
  total_amount: Decimal
  lines: Array<Record<string, unknown>>
  journal?: Array<PostingLine>
  warnings?: Array<Warning>
}

/** Allocate capitalised charges to a bill. */
export interface LandedCostRequest {
  purchase_bill_id: number
  allocation_date: string
  method?: 'value' | 'quantity'
  charge_ids?: Array<number>
}

/** Amendment. */
export interface LcAmendment {
  id: number
  letter_of_credit_id: number
  amendment_no: number
  status: string
  requested_on: string
  issued_on?: string | null
  accepted_on?: string | null
  changes: Array<Record<string, unknown>>
  bank_fee: Decimal
  reason?: string | null
  version: number
}

/** Record an amendment. */
export interface LcAmendmentInput {
  requested_on: string
  status: 'requested' | 'issued' | 'accepted'
  changes: Array<AmendmentChange>
  other_change?: string | null
  reason?: string | null
  bank_fee?: Decimal
}

/** Advance status. */
export interface LcAmendmentStatus {
  status: 'issued' | 'accepted' | 'refused'
  date: string
}

/** LC charge. */
export interface LcCharge {
  id: number
  letter_of_credit_id: number
  lc_number: string
  charge_type: string
  charge_date: string
  amount: Decimal
  status: 'unpaid' | 'paid'
  paid_to?: string | null
  include_in_landed_cost: boolean
  is_refundable: boolean
  allocated: boolean
  journal_entry_id?: number | null
  reference?: string | null
  version: number
}

/** Charge or import expense. */
export interface LcChargeInput {
  letter_of_credit_id: number
  charge_type: 'margin_deposit' | 'opening_commission' | 'amendment_fee' | 'swift_postage' | 'stamp_documentation' | 'acceptance_commission' | 'discrepancy_fee' | 'marine_insurance' | 'cf_agent_fee' | 'port_handling' | 'customs_duty_taxes' | 'inland_transport' | 'other'
  charge_date: string
  amount: Decimal
  paid_to?: string | null
  payee_party_id?: number | null
  status: 'unpaid' | 'paid'
  paid_from_account_id?: number | null
  include_in_landed_cost?: boolean
  reference?: string | null
  lc_shipment_id?: number | null
  lc_amendment_id?: number | null
}

/** Close an LC. */
export interface LcCloseRequest {
  override_reason?: string | null
  release_margin_to_account_id?: number | null
}

/** LC document. */
export interface LcDocument {
  id: number
  name: string
  provided_by: string
  copies: number
  note?: string | null
  is_required: boolean
  status: string
  due_date?: string | null
  received_date?: string | null
  attachments?: Array<Attachment>
  version: number
}

/** Required document. */
export interface LcDocumentInput {
  name: string
  provided_by: string
  copies?: number
  note?: string | null
  is_required?: boolean
  status?: 'not_yet_due' | 'pending' | 'received' | 'missing' | 'discrepant' | 'not_required'
  due_date?: string | null
  received_date?: string | null
  discrepancy_note?: string | null
  lc_shipment_id?: number | null
}

/** Checklist template. */
export interface LcDocumentTemplate {
  id: number
  name: string
  is_default: boolean
  items: Array<Record<string, unknown>>
}

/** Template items. */
export interface LcDocumentTemplateInput {
  name: string
  is_default?: boolean
  items: Array<Record<string, unknown>>
}

/** LC payment. */
export interface LcPayment {
  id: number
  payment_kind: 'sight_payment' | 'usance_acceptance' | 'usance_settlement'
  payment_date: string
  amount_foreign: Decimal
  bank_rate: Decimal
  amount_bdt: Decimal
  margin_applied: Decimal
  fx_difference: Decimal
  journal_entry_id?: number | null
  status: string
}

/** Payment at the bank's rate. */
export interface LcPaymentInput {
  payment_kind?: 'sight_payment' | 'usance_acceptance' | 'usance_settlement'
  payment_date: string
  amount_foreign: Decimal
  bank_rate: Decimal
  money_account_id: number
  margin_applied?: Decimal
  lc_shipment_id?: number | null
  purchase_bill_id?: number | null
  bank_reference?: string | null
  description?: string | null
}

/** Shipment lot. */
export interface LcShipment {
  id: number
  lot_no: number
  status: string
  bl_number?: string | null
  vessel?: string | null
  etd?: string | null
  eta?: string | null
  arrived_on?: string | null
  quantity?: Decimal | null
  value_foreign?: Decimal | null
  purchase_bill_id?: number | null
  version: number
}

/** Shipment lot. */
export interface LcShipmentInput {
  lot_no?: number
  status?: 'not_shipped' | 'booking_confirmed' | 'at_sea' | 'arrived' | 'arrived_cleared' | 'not_shipped_expired'
  booking_ref?: string | null
  bl_number?: string | null
  vessel?: string | null
  port_of_loading?: string | null
  port_of_discharge?: string | null
  mode?: 'sea' | 'air' | 'road'
  etd?: string | null
  eta?: string | null
  shipped_on?: string | null
  arrived_on?: string | null
  quantity?: Decimal | null
  value_foreign?: Decimal | null
}

/** Status change. */
export interface LcTransition {
  action: 'submit' | 'mark_opened' | 'mark_refused' | 'documents_presented' | 'payment_due' | 'cancel' | 'expire' | 'reopen'
  bank_reference?: string | null
  date?: string | null
  reason?: string | null
}

/** Ledger. */
export interface Ledger {
  id: number
  code: string
  name: string
  account_group_id: number
  group_path: Array<string>
  nature: string
  normal_balance: string
  ledger_kind: string
  description?: string | null
  track_bills: boolean
  show_in_cash_vouchers: boolean
  is_system: boolean
  is_active: boolean
  has_entries: boolean
  opening_balance: Decimal
  opening_side: 'dr' | 'cr'
  balance: Decimal
  balance_side: 'dr' | 'cr'
  fy_summary?: Record<string, unknown> | null
  version: number
}

/** Create or edit a ledger. */
export interface LedgerInput {
  name: string
  account_group_id: number
  code?: string | null
  description?: string | null
  track_bills?: boolean
  show_in_cash_vouchers?: boolean
  opening_balance?: Decimal
  opening_side?: 'dr' | 'cr'
}

/** One ledger line. */
export interface LedgerLine {
  date: string
  voucher_id: number
  voucher_number: string
  entry_type: string
  particulars: string
  narration?: string | null
  debit: Decimal
  credit: Decimal
  balance: Decimal
  balance_side: 'dr' | 'cr'
}

/** Ledger/book lines with running balance. */
export interface LedgerStatement {
  ledger: Record<string, unknown>
  period: Record<string, unknown>
  opening: Decimal
  opening_side: 'dr' | 'cr'
  lines: Array<LedgerLine>
  total_debit: Decimal
  total_credit: Decimal
  closing: Decimal
  closing_side: 'dr' | 'cr'
  monthly?: Array<Record<string, unknown>>
  meta?: PageMeta | null
}

/** A2-A8 payload. */
export interface LensData {
  lens: 'financial' | 'sales' | 'expenses' | 'receivables' | 'payables' | 'cash' | 'inventory'
  kpis: Array<Kpi>
  series?: Array<Record<string, unknown>>
  table?: ReportData
}

/** Import LC. */
export interface LetterOfCredit {
  id: number
  lc_number: string
  status: 'draft' | 'awaiting_bank' | 'opened' | 'partly_shipped' | 'documents_presented' | 'payment_due' | 'closed' | 'cancelled' | 'expired'
  party: PartyRef
  goods_description: string
  currency_code: string
  amount: Decimal
  tolerance_pct: Decimal
  planning_rate: Decimal
  amount_bdt_planned: Decimal
  paid_foreign: Decimal
  outstanding_foreign: Decimal
  tenor: string
  usance_days?: number | null
  incoterm: string
  issuing_bank?: MoneyAccount | null
  bank_reference?: string | null
  opening_status: string
  opened_on?: string | null
  latest_shipment_date?: string | null
  expiry_date?: string | null
  days_to_expiry?: number | null
  maturity_date?: string | null
  margin_pct: Decimal
  margin_amount: Decimal
  flags?: Array<Warning>
  milestones: Array<Record<string, unknown>>
  documents?: Array<LcDocument>
  shipments?: Array<LcShipment>
  charges?: Array<LcCharge>
  payments?: Array<LcPayment>
  amendments?: Array<LcAmendment>
  money_position?: Record<string, unknown> | null
  created_at: string
  created_by?: UserRef | null
  updated_at: string
  version: number
}

/** Create or update a draft/awaiting LC. Only supplier, amount, issuing bank and expiry are needed to submit. `document_template_id` copies that checklist into the LC's documents on create (or while draft) and is not stored (R-40). */
export interface LetterOfCreditInput {
  party_id: number
  purchase_order_id?: number | null
  intended_import?: string | null
  goods_description: string
  quantity?: Decimal | null
  quantity_unit?: string | null
  import_authorization_ref?: string | null
  lc_type?: 'irrevocable' | 'irrevocable_transferable' | 'standby'
  tenor: 'sight' | 'usance'
  usance_days?: number | null
  incoterm: string
  currency_code: string
  amount: Decimal
  tolerance_pct?: Decimal
  planning_rate: Decimal
  partial_shipments?: boolean
  transhipment?: boolean
  confirmation_required?: boolean
  issuing_bank_account_id?: number | null
  advising_bank?: string | null
  margin_pct?: Decimal
  margin_account_id?: number | null
  application_date?: string | null
  latest_shipment_date?: string | null
  expiry_date?: string | null
  presentation_days?: number
  document_template_id?: number | null
  documents?: Array<LcDocumentInput>
  record_shipments?: boolean
  shipment?: LcShipmentInput | null
}

/** E-mail and password. */
export interface LoginRequest {
  email: string
  password: string
  device_label?: string | null
}

/** Either tokens, or a two-factor challenge. */
export interface LoginResponse {
  status: 'authenticated' | 'two_factor_required'
  access_token?: string | null
  token_type?: string | null
  expires_in?: number | null
  challenge_token?: string | null
  user?: Me | null
}

/** Simple named record (category, stock group, unit). */
export interface Lookup {
  id: number
  name: string
  parent_id?: number | null
  code_prefix?: string | null
  items_count?: number | null
  stock_value?: Decimal | null
}

/** Create or edit a product category or stock group. */
export interface LookupInput {
  name: string
  parent_id?: number | null
  code_prefix?: string | null
  default_unit_id?: number | null
  default_stock_group_id?: number | null
  sort_order?: number | null
}

/** Rows to order. */
export interface LowStockOrderRequest {
  rows: Array<Record<string, unknown>>
  order_date: string
}

/** Match to a book line. */
export interface MatchRequest {
  journal_line_id: number
}

/** Current user. */
export interface Me {
  id: number
  name: string
  email: string
  phone?: string | null
  two_factor_enabled: boolean
  companies: Array<Membership>
  active_company_id?: number | null
  permissions?: Array<string>
  approval_limits?: Record<string, unknown> | null
  branch_ids?: Array<number>
}

/** Company member. */
export interface Member {
  id: number
  user_id: number
  name: string
  email: string
  role_id: number
  role_name: string
  status: 'active' | 'invited' | 'deactivated'
  last_active_at?: string | null
  two_factor_enabled: boolean
  branch_ids?: Array<number>
  permissions?: Array<string>
  recent_activity?: Array<AuditLog>
  version: number
}

/** Change role or branches. */
export interface MemberUpdate {
  role_id?: number
  branch_ids?: Array<number>
}

/** Company membership. */
export interface Membership {
  company_id: number
  company_name: string
  role: string
  status: 'active' | 'invited' | 'deactivated'
}

/** Cash, bank, wallet, card or cash-credit account. */
export interface MoneyAccount {
  id: number
  account_type: 'cash' | 'bank' | 'wallet' | 'card' | 'cash_credit'
  overdraft_limit?: Decimal | null
  name: string
  bank_name?: string | null
  branch_name?: string | null
  account_name?: string | null
  account_number_masked?: string | null
  routing_number?: string | null
  currency_code: string
  ledger_id: number
  ledger_code: string
  book_balance: Decimal
  custodian?: UserRef | null
  use_for_receipts: boolean
  use_for_payments: boolean
  show_on_invoices: boolean
  last_reconciled_on?: string | null
  unmatched_lines: number
  is_active: boolean
  version: number
}

/** Add or edit an account. */
export interface MoneyAccountInput {
  account_type: 'cash' | 'bank' | 'wallet' | 'card' | 'cash_credit'
  overdraft_limit?: Decimal | null
  name?: string | null
  bank_name: string
  branch_name?: string | null
  account_name: string
  account_number: string
  routing_number?: string | null
  opening_balance?: Decimal
  opening_date?: string | null
  custodian_user_id?: number | null
  use_for_receipts?: boolean
  use_for_payments?: boolean
  show_on_invoices?: boolean
}

/** Cash/bank movement row. */
export interface MoneyTransaction {
  date: string
  voucher_id: number
  voucher_number: string
  account: string
  particulars: string
  direction: 'in' | 'out' | 'transfer'
  amount_in: Decimal
  amount_out: Decimal
}

/** Credit or debit note. */
export interface Note {
  id: number
  number: string
  kind: 'credit_note' | 'debit_note'
  status: 'draft' | 'posted' | 'cancelled'
  party: PartyRef
  note_date: string
  reason: string
  amount: Decimal
  usage: string
  effects?: Array<Record<string, unknown>>
  journal?: Array<PostingLine>
  version: number
}

/** Credit or debit note. `apply_to_document_id` (required when usage = apply) is the invoice or bill of the same party that the note is allocated to on posting; it is stored only as the resulting bill_allocations row (R-20). */
export interface NoteInput {
  party_id: number
  source_document_id?: number | null
  return_id?: number | null
  note_date: string
  reason: string
  amount: Decimal
  usage: 'apply' | 'credit' | 'refund'
  apply_to_document_id?: number | null
  refund?: RefundInput | null
}

/** In-app notification. */
export interface Notification {
  id: number
  event_key: string
  title: string
  body?: string | null
  category: 'approvals' | 'alerts' | 'info'
  link_type?: string | null
  link_id?: number | null
  read_at?: string | null
  created_at: string
}

/** Channels for one event. */
export interface NotificationPreference {
  event_key: string
  in_app: boolean
  email: boolean
  sms: boolean
}

/** Per-event channels. */
export interface NotificationPreferences {
  events: Array<NotificationPreference>
  daily_summary_time?: string | null
}

/** Numbering series. */
export interface NumberSequence {
  id: number
  document_type: string
  prefix: string
  next_number: number
  padding: number
  reset_policy: 'fiscal_year' | 'never'
  preview: string
  version: number
}

/** Change a series. */
export interface NumberSequenceUpdate {
  prefix?: string
  next_number?: number
  padding?: number
}

/** An unapplied receipt/payment, advance or posted note that can be allocated (R-08). */
export interface OpenCredit {
  journal_line_id: number
  kind: 'receipt' | 'payment' | 'advance' | 'credit_note' | 'debit_note'
  number: string
  date: string
  amount: Decimal
  unapplied: Decimal
}

/** One open document. */
export interface OpenDocument {
  id: number
  type: 'sales_invoice' | 'purchase_bill'
  number: string
  date: string
  due_date: string
  total: Decimal
  settled: Decimal
  balance: Decimal
  days_overdue: number
}

/** Open invoices or bills of a party, oldest due first, and the open credits that can be applied to them. */
export interface OpenDocuments {
  party: PartyRef
  documents: Array<OpenDocument>
  credits: Array<OpenCredit>
  total_open: Decimal
  unapplied_credit: Decimal
}

/** Aging across parties. */
export interface OutstandingReport {
  as_of: string
  buckets: Array<AgingBucket>
  rows: Array<Record<string, unknown>>
  totals: Record<string, unknown>
}

/** Pagination metadata. */
export interface PageMeta {
  current_page: number
  per_page: number
  total: number
  last_page: number
}

/** Offsetting statement lines. */
export interface PairRequest {
  line_ids: Array<number>
}

/** Customer or supplier. */
export interface Party {
  id: number
  party_type: 'customer' | 'supplier'
  code: string
  name: string
  category?: string | null
  group?: PartyGroup | null
  contact_person?: string | null
  mobile?: string | null
  email?: string | null
  alt_phone?: string | null
  address?: string | null
  district?: string | null
  area?: string | null
  country_code: string
  default_currency_code: string
  payment_term?: PaymentTerm | null
  credit_limit?: Decimal | null
  price_tier: string
  ledger_id: number
  ledger_code: string
  tax_registration_no?: string | null
  port_of_loading?: string | null
  default_purchase_ledger_id?: number | null
  status: 'active' | 'inactive' | 'prospect'
  balance: Decimal
  balance_side: 'dr' | 'cr'
  overdue: Decimal
  opening_balance: Decimal
  opening_locked: boolean
  bank_accounts?: Array<PartyBankAccount>
  kpis?: Array<Kpi>
  notes?: string | null
  version: number
}

/** Supplier bank details. */
export interface PartyBankAccount {
  id?: number | null
  bank_name: string
  branch?: string | null
  account_name?: string | null
  account_number: string
  routing_number?: string | null
  swift_code?: string | null
  is_default?: boolean
}

/** Party group. */
export interface PartyGroup {
  id: number
  party_type: string
  name: string
}

/** Create a group. */
export interface PartyGroupInput {
  party_type: 'customer' | 'supplier'
  name: string
}

/** Create or edit a customer or supplier. `mobile` is required when country_code = BD (BR-063, R-33). */
export interface PartyInput {
  party_type: 'customer' | 'supplier'
  name: string
  category?: 'business' | 'individual' | 'walk_in' | 'manufacturer' | 'distributor' | 'service_provider' | null
  party_group_id?: number | null
  contact_person?: string | null
  mobile?: string | null
  email?: string | null
  alt_phone?: string | null
  address?: string | null
  district?: string | null
  area?: string | null
  country_code?: string
  default_currency_code?: string
  payment_term_id?: number | null
  credit_limit?: Decimal | null
  price_tier?: 'standard' | 'wholesale'
  default_purchase_ledger_id?: number | null
  tax_registration_no?: string | null
  port_of_loading?: string | null
  notes?: string | null
  opening_balance?: Decimal
  opening_side?: 'dr' | 'cr'
  bank_accounts?: Array<PartyBankAccount>
}

/** Customer receipt (E7) or supplier payment (F5). */
export interface PartyPaymentInput {
  party_id: number
  date: string
  amount: Decimal
  money_account_id: number
  method: 'cash' | 'cheque' | 'bank_transfer' | 'beftn' | 'rtgs' | 'npsb' | 'bkash' | 'nagad' | 'card'
  cheque_number?: string | null
  cheque_date?: string | null
  drawn_on?: string | null
  transaction_reference?: string | null
  bank_charge?: Decimal
  exchange_rate?: Decimal | null
  narration?: string | null
  allocations?: Array<AllocationInput>
  auto_allocate?: boolean
  confirm_backdated?: boolean
}

/** Minimal party reference. */
export interface PartyRef {
  id: number
  code: string
  name: string
}

/** Statement of account. */
export interface PartyStatement {
  statement_no: string
  party: PartyRef
  period: Record<string, unknown>
  summary: Record<string, unknown>
  lines: Array<LedgerLine>
  aging?: Array<AgingBucket> | null
  footer?: string | null
}

/** History row. */
export interface PartyTransaction {
  id: number
  type: string
  number: string
  date: string
  reference?: string | null
  due_date?: string | null
  amount: Decimal
  status: string
  url: string
}

/** Re-enter password. */
export interface PasswordConfirm {
  password: string
}

/** Cash/bank side of payment, receipt or contra. */
export interface PaymentDetailInput {
  money_account_id: number
  counter_money_account_id?: number | null
  method: 'cash' | 'cheque' | 'bank_transfer' | 'beftn' | 'rtgs' | 'npsb' | 'bkash' | 'nagad' | 'card'
  party_id?: number | null
  payee_text?: string | null
  cheque_number?: string | null
  cheque_date?: string | null
  drawn_on?: string | null
  transaction_reference?: string | null
  bank_charge?: Decimal
  handed_to?: string | null
}

/** Payment terms. */
export interface PaymentTerm {
  id: number
  name: string
  days: number
  is_default: boolean
}

/** Lock a period. */
export interface PeriodLockRequest {
  note?: string | null
}

/** Permission catalogue grouped by module. */
export interface PermissionList {
  modules: Array<Record<string, unknown>>
}

/** Post a document. confirm_backdated is required when the date is older than the warning window (BR-053). `receipt` (sales invoices only) records the cash received in the same transaction (R-28). */
export interface PostRequest {
  confirm_backdated?: boolean
  acknowledge_warnings?: Array<string>
  receipt?: CashReceiptInput | null
}

/** Journal line preview or actual. */
export interface PostingLine {
  ledger_id: number
  ledger_code: string
  ledger_name: string
  debit: Decimal
  credit: Decimal
}

/** Product or service. Cost fields omitted without inventory.view_costs (BR-052). */
export interface Product {
  id: number
  item_type: 'product' | 'service'
  code: string
  name: string
  category?: Lookup | null
  unit: string
  barcode?: string | null
  selling_price: Decimal
  wholesale_price?: Decimal | null
  tax_rule_id?: number | null
  income_ledger_id: number
  track_stock: boolean
  stock_group?: Lookup | null
  preferred_supplier?: PartyRef | null
  on_hand?: Decimal | null
  stock_status: 'in_stock' | 'low_stock' | 'out_of_stock' | 'not_tracked'
  avg_cost?: Decimal | null
  stock_value?: Decimal | null
  last_purchase_cost?: Decimal | null
  warehouses?: Array<StockLevel>
  is_active: boolean
  version: number
}

/** multipart/form-data: `file` (CSV or XLSX, max 10 MB, columns per the import template) and `dry_run` query flag. */
export interface ProductImport {
  file: string
}

/** Create or edit a product or service. */
export interface ProductInput {
  item_type: 'product' | 'service'
  name: string
  code?: string | null
  category_id?: number | null
  unit_id: number
  barcode?: string | null
  last_purchase_cost?: Decimal | null
  selling_price: Decimal
  wholesale_price?: Decimal | null
  tax_rule_id?: number | null
  income_ledger_id: number
  expense_ledger_id?: number | null
  stock_group_id?: number | null
  preferred_supplier_id?: number | null
  is_active?: boolean
  warehouses?: Array<ProductWarehouseInput>
}

/** Per-warehouse settings; opening_qty only on create. */
export interface ProductWarehouseInput {
  warehouse_id: number
  reorder_level?: Decimal
  reorder_qty?: Decimal
  opening_qty?: Decimal
  opening_unit_cost?: Decimal
}

/** Purchase bill. */
export interface PurchaseBill {
  id: number
  number: string
  supplier_bill_no: string
  status: 'draft' | 'posted' | 'cancelled'
  payment_status: 'unpaid' | 'part_paid' | 'paid'
  overdue: boolean
  party: PartyRef
  bill_date: string
  due_date: string
  warehouse_id: number
  currency_code: string
  exchange_rate: Decimal
  total_foreign?: Decimal | null
  purchase_order_id?: number | null
  letter_of_credit_id?: number | null
  lines: Array<Record<string, unknown>>
  amount_settled: Decimal
  balance_due: Decimal
  allocations?: Array<Allocation>
  journal?: Array<PostingLine>
  posted_at?: string | null
  subtotal: Decimal
  discount_total: Decimal
  tax_total: Decimal
  rounding_adjustment: Decimal
  total: Decimal
  amount_in_words: string
  created_at: string
  created_by?: UserRef | null
  updated_at: string
  version: number
}

/** Create or replace a draft bill. Foreign currency requires exchange_rate. */
export interface PurchaseBillInput {
  party_id: number
  supplier_bill_no: string
  bill_date: string
  due_date?: string | null
  payment_term_id?: number | null
  warehouse_id: number
  purchase_order_id?: number | null
  letter_of_credit_id?: number | null
  lc_shipment_id?: number | null
  currency_code?: string
  exchange_rate?: Decimal
  branch_id?: number | null
  notes?: string | null
  internal_note?: string | null
  receipt_condition_note?: string | null
  lines: Array<PurchaseLineInput>
}

/** Bill line. */
export interface PurchaseLineInput {
  unit_cost: Decimal
  purchase_order_line_id?: number | null
  product_id: number
  description?: string | null
  quantity: Decimal
  discount_pct?: Decimal
  tax_rule_id?: number | null
}

/** Purchase order. */
export interface PurchaseOrder {
  id: number
  number: string
  status: 'draft' | 'sent' | 'partly_received' | 'received' | 'closed' | 'cancelled'
  party: PartyRef
  order_date: string
  expected_date?: string | null
  currency_code: string
  lines: Array<Record<string, unknown>>
  total_foreign?: Decimal | null
  subtotal: Decimal
  discount_total: Decimal
  tax_total: Decimal
  rounding_adjustment: Decimal
  total: Decimal
  amount_in_words: string
  created_at: string
  created_by?: UserRef | null
  updated_at: string
  version: number
}

/** Create or replace a PO. */
export interface PurchaseOrderInput {
  party_id: number
  order_date: string
  expected_date?: string | null
  warehouse_id: number
  payment_term_id?: number | null
  currency_code?: string
  exchange_rate?: Decimal
  source_note?: string | null
  notes?: string | null
  internal_note?: string | null
  lines: Array<PurchaseLineInput>
}

/** Quotation. */
export interface Quotation {
  id: number
  number: string
  status: 'draft' | 'sent' | 'accepted' | 'declined' | 'expired' | 'converted'
  party: PartyRef
  quotation_date: string
  valid_until: string
  lines: Array<SalesLine>
  converted_invoice_id?: number | null
  subtotal: Decimal
  discount_total: Decimal
  tax_total: Decimal
  rounding_adjustment: Decimal
  total: Decimal
  amount_in_words: string
  created_at: string
  created_by?: UserRef | null
  updated_at: string
  version: number
}

/** Create or replace a quotation. */
export interface QuotationInput {
  party_id: number
  quotation_date: string
  valid_until: string
  warehouse_id?: number | null
  payment_term_id?: number | null
  notes?: string | null
  internal_note?: string | null
  lines: Array<SalesLineInput>
}

/** A required reason. */
export interface ReasonRequest {
  reason: string
}

/** Reconciliation workspace. */
export interface Reconciliation {
  id: number
  money_account_id: number
  statement_date: string
  statement_balance: Decimal
  book_balance: Decimal
  difference: Decimal
  matched_count: number
  line_count: number
  status: 'in_progress' | 'finished'
  lines: Array<StatementLine>
  version: number
}

/** Start a reconciliation. */
export interface ReconciliationStart {
  money_account_id: number
  statement_date: string
  statement_balance: Decimal
}

/** Shown once. */
export interface RecoveryCodes {
  recovery_codes: Array<string>
}

/** Repeat settings. */
export interface RecurrenceInput {
  frequency: 'weekly' | 'monthly' | 'quarterly' | 'yearly'
  next_run_on: string
  ends_type: 'never' | 'after_count' | 'on_date'
  ends_after_count?: number | null
  ends_on?: string | null
}

/** Recurring entry. */
export interface RecurringTemplate {
  id: number
  kind: string
  frequency: string
  next_run_on: string
  ends_type: string
  occurrences_done: number
  is_active: boolean
  payload: Record<string, unknown>
  version: number
}

/** Edit a recurring entry. */
export interface RecurringTemplateInput {
  frequency?: 'weekly' | 'monthly' | 'quarterly' | 'yearly'
  next_run_on?: string
  ends_type?: 'never' | 'after_count' | 'on_date'
  ends_after_count?: number | null
  ends_on?: string | null
  is_active?: boolean
}

/** Refund details for usage=refund. */
export interface RefundInput {
  money_account_id: number
  method: 'cash' | 'cheque' | 'bank_transfer' | 'beftn' | 'rtgs' | 'npsb' | 'bkash' | 'nagad' | 'card'
  reference?: string | null
}

/** Agreement or health check on a report. */
export interface ReportCheck {
  key: string
  ok: boolean
  message: string
}

/** Report column definition. */
export interface ReportColumn {
  key: string
  label: string
  type: 'text' | 'amount' | 'qty' | 'pct' | 'date' | 'code'
}

/** Generic report payload: header, columns, rows, totals and checks. Every amount is a decimal string. */
export interface ReportData {
  title: string
  period: Record<string, unknown>
  basis?: string | null
  columns: Array<ReportColumn>
  rows: Array<Record<string, unknown>>
  totals?: Record<string, unknown> | null
  checks?: Array<ReportCheck>
  meta?: Record<string, unknown> | null
}

/** Set a new password. */
export interface ResetPasswordRequest {
  token: string
  email: string
  password: string
  password_confirmation: string
}

/** Restore confirmation. */
export interface RestoreRequest {
  confirmation: 'RESTORE'
}

/** Sales or purchase return. */
export interface Return {
  id: number
  number: string
  status: 'draft' | 'posted' | 'cancelled'
  party: PartyRef
  source_document_id: number
  source_number: string
  return_date: string
  stock_action: string
  lines: Array<Record<string, unknown>>
  total: Decimal
  note_id?: number | null
  note_number?: string | null
  version: number
}

/** Sales or purchase return. stock_action: restock|write_off (sales), send_back|already_disposed (purchase). `usage` and `apply_to_document_id` decide how the note created on posting is used (R-04). */
export interface ReturnInput {
  source_document_id: number
  return_date: string
  warehouse_id: number
  stock_action: 'restock' | 'write_off' | 'send_back' | 'already_disposed'
  usage?: 'apply' | 'credit' | 'refund'
  apply_to_document_id?: number | null
  notes?: string | null
  lines: Array<ReturnLineInput>
}

/** Returned quantity per original line. */
export interface ReturnLineInput {
  source_line_id: number
  quantity: Decimal
  reason: string
}

/** Reverse a posted voucher. */
export interface ReverseRequest {
  reversal_date: string
  reason: string
}

/** Role with permissions. */
export interface Role {
  id: number
  name: string
  slug: string
  description?: string | null
  is_system: boolean
  members_count: number
  permissions: Array<string>
  payment_approval_limit?: Decimal | null
  journal_approval_limit?: Decimal | null
  version: number
}

/** Create or replace a custom role. Both approval limits are required (0.00 = everything needs approval); only the system Owner role has no limit (R-10). */
export interface RoleInput {
  name: string
  description?: string | null
  permissions: Array<string>
  payment_approval_limit: Decimal
  journal_approval_limit: Decimal
  copy_from_role_id?: number | null
}

/** Sales invoice. */
export interface SalesInvoice {
  id: number
  number: string
  status: 'draft' | 'posted' | 'cancelled'
  payment_status: 'unpaid' | 'part_paid' | 'paid'
  overdue: boolean
  party: PartyRef
  invoice_date: string
  due_date: string
  warehouse_id: number
  customer_po_ref?: string | null
  lines: Array<SalesLine>
  amount_settled: Decimal
  balance_due: Decimal
  allocations?: Array<Allocation>
  journal?: Array<PostingLine>
  delivery?: Record<string, unknown> | null
  posted_at?: string | null
  cancel_reason?: string | null
  subtotal: Decimal
  discount_total: Decimal
  tax_total: Decimal
  rounding_adjustment: Decimal
  total: Decimal
  amount_in_words: string
  created_at: string
  created_by?: UserRef | null
  updated_at: string
  version: number
}

/** Create or replace a draft invoice. Prices are optional; defaults come from the price tier. */
export interface SalesInvoiceInput {
  party_id: number
  invoice_date: string
  due_date?: string | null
  payment_term_id?: number | null
  warehouse_id: number
  customer_po_ref?: string | null
  quotation_id?: number | null
  branch_id?: number | null
  notes?: string | null
  internal_note?: string | null
  lines: Array<SalesLineInput>
}

/** Invoice line out. Cost fields need inventory.view_costs. */
export interface SalesLine {
  unit_price: Decimal
  unit_cost?: Decimal | null
  cost_amount?: Decimal | null
  returned_quantity: Decimal
  id: number
  line_no: number
  product_id: number
  product_code: string
  product_name: string
  unit: string
  description?: string | null
  quantity: Decimal
  discount_pct: Decimal
  discount_amount: Decimal
  tax_rule_id?: number | null
  tax_rate: Decimal
  tax_amount: Decimal
  net_amount: Decimal
  line_total: Decimal
}

/** Invoice line. */
export interface SalesLineInput {
  unit_price?: Decimal | null
  product_id: number
  description?: string | null
  quantity: Decimal
  discount_pct?: Decimal
  tax_rule_id?: number | null
}

/** Hits of one type. */
export interface SearchGroup {
  type: 'party' | 'sales_invoice' | 'purchase_bill' | 'voucher' | 'ledger' | 'product' | 'letter_of_credit'
  count: number
  items: Array<SearchHit>
}

/** One hit. */
export interface SearchHit {
  id: number
  title: string
  subtitle?: string | null
  badge?: string | null
  url: string
}

/** Grouped search hits. */
export interface SearchResults {
  query: string
  groups: Array<SearchGroup>
}

/** Signed-in device. */
export interface Session {
  id: number
  device_label?: string | null
  user_agent?: string | null
  ip_address?: string | null
  last_seen_at?: string | null
  current: boolean
}

/** Short-lived download link. */
export interface SignedUrl {
  url: string
  expires_at: string
}

/** multipart: file, money_account_id, column mapping. */
export interface StatementImport {
  file: string
  money_account_id: number
  mapping?: Record<string, unknown> | null
}

/** Imported statement. */
export interface StatementImportResult {
  id: number
  line_count: number
  period_from: string
  period_to: string
  duplicates_skipped: number
}

/** Statement line with match state. */
export interface StatementLine {
  id: number
  txn_date: string
  description: string
  bank_reference?: string | null
  amount: Decimal
  status: 'unmatched' | 'matched' | 'created' | 'paired' | 'ignored'
  suggestion?: Record<string, unknown> | null
  matched?: Record<string, unknown> | null
}

/** Send statements. */
export interface StatementSendRequest {
  party_ids: Array<number>
  date_from: string
  date_to: string
  include_aging?: boolean
}

/** Set a document status. */
export interface StatusChange {
  status: 'accepted' | 'declined' | 'expired' | 'closed' | 'cancelled'
  reason?: string | null
}

/** Stock adjustment. net_value and posting_preview are omitted without inventory.view_costs (BR-052). */
export interface StockAdjustment {
  id: number
  number: string
  adjustment_date: string
  warehouse_id: number
  reason: string
  status: 'draft' | 'posted' | 'reversed'
  lines: Array<Record<string, unknown>>
  net_value?: Decimal | null
  journal_entry_id?: number | null
  posting_preview?: Array<PostingLine>
  version: number
}

/** Draft adjustment. */
export interface StockAdjustmentInput {
  adjustment_date: string
  warehouse_id: number
  reason: 'count_difference' | 'damaged' | 'expired' | 'theft_or_loss' | 'free_samples'
  notes?: string | null
  lines: Array<AdjustmentLineInput>
}

/** Stock of a product in a warehouse. */
export interface StockLevel {
  product_id: number
  product_code: string
  product_name: string
  warehouse_id: number
  warehouse_name: string
  qty_on_hand: Decimal
  reorder_level: Decimal
  reorder_qty: Decimal
  value?: Decimal | null
  status: 'in_stock' | 'low_stock' | 'out_of_stock' | 'not_stocked'
}

/** Stock ledger row. unit_cost and value are omitted without inventory.view_costs (BR-052). */
export interface StockMovement {
  id: number
  moved_on: string
  movement_type: string
  product: Lookup
  warehouse: Lookup
  quantity_in: Decimal
  quantity_out: Decimal
  qty_balance_after: Decimal
  unit_cost?: Decimal | null
  value?: Decimal | null
  source_type: string
  source_id: number
  source_number: string
}

/** Stock transfer. value_at_cost is omitted without inventory.view_costs (BR-052). */
export interface StockTransfer {
  id: number
  number: string
  from_warehouse_id: number
  to_warehouse_id: number
  status: 'draft' | 'dispatched' | 'received' | 'cancelled'
  dispatch_date?: string | null
  received_date?: string | null
  lines: Array<Record<string, unknown>>
  total_units: Decimal
  value_at_cost?: Decimal | null
  version: number
}

/** Draft transfer. */
export interface StockTransferInput {
  from_warehouse_id: number
  to_warehouse_id: number
  dispatch_date?: string | null
  vehicle?: string | null
  driver?: string | null
  notes?: string | null
  lines: Array<TransferLineInput>
}

/** Tax rule. */
export interface TaxRule {
  id: number
  name: string
  rate: Decimal
  applies_to: 'sales' | 'purchases' | 'both'
  ledger_id: number
  ledger_name: string
  is_recoverable: boolean
  is_active: boolean
  version: number
}

/** Create or edit a tax rule. */
export interface TaxRuleInput {
  name: string
  rate: Decimal
  applies_to: 'sales' | 'purchases' | 'both'
  ledger_id?: number | null
  create_ledger?: boolean
  is_recoverable?: boolean
  is_active?: boolean
}

/** Journal templates. */
export interface TemplateList {
  templates: Array<Record<string, unknown>>
}

/** New access token (refresh cookie rotated). */
export interface TokenResponse {
  access_token: string
  token_type: string
  expires_in: number
}

/** Quantity to move. */
export interface TransferLineInput {
  product_id: number
  quantity: Decimal
}

/** Received quantities (default = dispatched). */
export interface TransferReceive {
  received_date: string
  lines?: Array<TransferLineInput>
}

/** Confirm TOTP. */
export interface TwoFactorConfirm {
  code: string
}

/** Second step. */
export interface TwoFactorRequest {
  challenge_token: string
  code?: string | null
  recovery_code?: string | null
}

/** TOTP enrolment. */
export interface TwoFactorSetup {
  otpauth_uri: string
  qr_svg: string
}

/** Add a unit of measure (D2 inline). */
export interface UnitInput {
  name: string
  decimals?: number
}

/** Appearance. */
export interface UserPreferences {
  theme?: 'light' | 'dark' | 'system'
  density?: 'default' | 'compact'
  text_size?: 's' | 'm' | 'l'
  reduce_motion?: boolean
  sidebar_collapsed?: boolean
}

/** Minimal user reference. */
export interface UserRef {
  id: number
  name: string
}

/** Journal entry / voucher. */
export interface Voucher {
  id: number
  voucher_number: string
  entry_type: string
  status: 'draft' | 'submitted' | 'approved' | 'rejected' | 'posted' | 'reversed'
  entry_date: string
  reference?: string | null
  narration?: string | null
  total_debit: Decimal
  total_credit: Decimal
  lines: Array<VoucherLine>
  payment?: Record<string, unknown> | null
  source?: Record<string, unknown> | null
  reverses_entry_id?: number | null
  reversed_by_entry_id?: number | null
  approval?: ApprovalRequest | null
  audit_trail?: Array<AuditLog>
  amount_in_words: string
  created_at: string
  created_by?: UserRef | null
  updated_at: string
  version: number
}

/** Journal, payment, receipt or contra voucher. */
export interface VoucherInput {
  entry_type: 'journal' | 'payment' | 'receipt' | 'contra'
  action: 'draft' | 'submit' | 'post'
  date: string
  reference?: string | null
  narration?: string | null
  template_key?: string | null
  payment?: PaymentDetailInput | null
  lines: Array<VoucherLineInput>
  confirm_backdated?: boolean
}

/** Voucher line. */
export interface VoucherLine {
  line_no: number
  ledger_id: number
  ledger_code: string
  ledger_name: string
  debit: Decimal
  credit: Decimal
  line_narration?: string | null
  against_reference?: string | null
  foreign_amount?: Decimal | null
  exchange_rate?: Decimal | null
}

/** Voucher line. Journal: debit or credit. Payment/receipt: amount. */
export interface VoucherLineInput {
  ledger_id: number
  debit?: Decimal
  credit?: Decimal
  amount?: Decimal
  line_narration?: string | null
  against_reference?: string | null
  allocations?: Array<AllocationInput>
}

/** Server-calculated voucher (R-27): the lines as they would post, totals, balance status, approval need and warnings. */
export interface VoucherPreview {
  lines: Array<PostingLine>
  total_debit: Decimal
  total_credit: Decimal
  difference: Decimal
  balanced: boolean
  needs_approval: boolean
  approval_limit?: Decimal | null
  warnings: Array<Warning>
  amount_in_words: string
}

/** Warehouse. */
export interface Warehouse {
  id: number
  code: string
  name: string
  branch_id: number
  address?: string | null
  manager?: UserRef | null
  phone?: string | null
  inventory_ledger_id: number
  is_default_for_sales: boolean
  status: 'active' | 'planned' | 'inactive'
  opens_on?: string | null
  stock_value?: Decimal | null
  products_count: number
  below_reorder_count: number
  capacity_used_pct?: Decimal | null
  version: number
}

/** Create or edit a warehouse. */
export interface WarehouseInput {
  code: string
  name: string
  branch_id: number
  address?: string | null
  manager_user_id?: number | null
  phone?: string | null
  inventory_ledger_id?: number | null
  is_default_for_sales?: boolean
  status?: 'active' | 'planned' | 'inactive'
  opens_on?: string | null
}

/** Non-blocking warning. */
export interface Warning {
  code: 'CREDIT_LIMIT_EXCEEDED' | 'PARTY_OVERDUE' | 'LOW_STOCK' | 'BACKDATED' | 'BUDGET_NEAR_LIMIT' | 'DUPLICATE_PARTY' | 'INSUFFICIENT_FUNDS' | 'APPROVAL_REQUIRED'
  message: string
  line_no?: number | null
}
