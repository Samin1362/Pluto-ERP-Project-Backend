"""OpenAPI component schemas in a compact shorthand, compiled to OpenAPI 3.1 JSON Schema by build_api.py.

Shorthand per property:  "type[?][!]"  ? = nullable, ! = required
  types: id, str, str:N (maxLength), text, int, bool, date, datetime, money (decimal string, 2 dp), rate (6 dp),
         qty (3 dp), cost (4 dp), pct, email, enum:a|b|c, ref:Schema, [ref:Schema] (array), [str], obj, ccy, uuid
"""

S = {}


def s(_name, _desc, **props):
    S[_name] = (_desc, props)


LINE_IN = dict(product_id="id!", description="str:255?", quantity="qty!", discount_pct="pct", tax_rule_id="id?")
LINE_OUT = dict(id="id!", line_no="int!", product_id="id!", product_code="str!", product_name="str!", unit="str!", description="str?", quantity="qty!",
                discount_pct="pct!", discount_amount="money!", tax_rule_id="id?", tax_rate="pct!", tax_amount="money!", net_amount="money!", line_total="money!")
TOTALS = dict(subtotal="money!", discount_total="money!", tax_total="money!", rounding_adjustment="money!", total="money!", amount_in_words="str!")
AUDIT = dict(created_at="datetime!", created_by="ref:UserRef?", updated_at="datetime!", version="int!")

# ---------------------------------------------------------------- common
s("Error", "RFC 9457 problem details (application/problem+json), extended with a stable application error code (see the error catalogue), the request id and, for 422, field errors keyed by JSON path (e.g. lines.2.quantity).",
  type="str!", title="str!", status="int!", code="errcode!", detail="str?", instance="str?", request_id="uuid!", errors="errmap?", warnings="[ref:Warning]")
s("UserRef", "Minimal user reference.", id="id!", name="str!")
s("PartyRef", "Minimal party reference.", id="id!", code="str!", name="str!")
s("PageMeta", "Pagination metadata.", current_page="int!", per_page="int!", total="int!", last_page="int!")
s("Binary", "File response (application/pdf, xlsx, csv).")
s("Lookup", "Simple named record (category, stock group, unit).", id="id!", name="str!", parent_id="id?", code_prefix="str?", items_count="int?", stock_value="money?")
s("LookupInput", "Create or edit a product category or stock group.", name="str:80!", parent_id="id?", code_prefix="str:10?", default_unit_id="id?", default_stock_group_id="id?", sort_order="int?")
s("UnitInput", "Add a unit of measure (D2 inline).", name="str:30!", decimals="int")
s("ReasonRequest", "A required reason.", reason="str:255!")
s("StatusChange", "Set a document status.", status="enum:accepted|declined|expired|closed|cancelled!", reason="str:255?")
s("BulkIds", "Ids for a bulk action.", ids="[id]!")
s("BulkResult", "Per-item outcome of a bulk action.", succeeded="[id]!", failed="[ref:BulkFailure]!")
s("BulkFailure", "One failed item.", id="id!", code="str!", detail="str!")
s("PostRequest", "Post a document. confirm_backdated is required when the date is older than the warning window (BR-053). `receipt` (sales invoices only) records the cash received in the same transaction (R-28).", confirm_backdated="bool", acknowledge_warnings="[str]", receipt="ref:CashReceiptInput?")
s("CashReceiptInput", "Money received with a cash sale; amount must equal the invoice total.", money_account_id="id!", method="enum:cash|cheque|bank_transfer|beftn|rtgs|npsb|bkash|nagad|card!", amount="money!", transaction_reference="str:60?")
s("CancelRequest", "Cancel a posted document.", reason="enum:customer_cancelled|entered_by_mistake|wrong_party|duplicate|other!", note="str:255?", cancellation_date="date?")
s("ReverseRequest", "Reverse a posted voucher.", reversal_date="date!", reason="str:255!")
s("EmailRequest", "Send a document by e-mail.", to="[email]!", cc="[email]", message="text?")
s("FileUpload", "multipart/form-data with a `file` part.", file="str!")
s("SignedUrl", "Short-lived download link.", url="str!", expires_at="datetime!")
s("ReportData", "Generic report payload: header, columns, rows, totals and checks. Every amount is a decimal string.",
  title="str!", period="obj!", basis="str?", columns="[ref:ReportColumn]!", rows="[obj]!", totals="obj?", checks="[ref:ReportCheck]", meta="obj?")
s("ReportColumn", "Report column definition.", key="str!", label="str!", type="enum:text|amount|qty|pct|date|code!")
s("ReportCheck", "Agreement or health check on a report.", key="str!", ok="bool!", message="str!")

# ---------------------------------------------------------------- auth
s("LoginRequest", "E-mail and password.", email="email!", password="str:200!", device_label="str:120?")
s("LoginResponse", "Either tokens, or a two-factor challenge.", status="enum:authenticated|two_factor_required!", access_token="str?", token_type="str?", expires_in="int?", challenge_token="str?", user="ref:Me?")
s("TwoFactorRequest", "Second step.", challenge_token="str!", code="str:6?", recovery_code="str:20?")
s("TokenResponse", "New access token (refresh cookie rotated).", access_token="str!", token_type="str!", expires_in="int!")
s("ForgotPasswordRequest", "Request a reset link.", email="email!")
s("ResetPasswordRequest", "Set a new password.", token="str!", email="email!", password="str:200!", password_confirmation="str:200!")
s("ChangePasswordRequest", "Change own password.", current_password="str!", password="str:200!", password_confirmation="str:200!")
s("PasswordConfirm", "Re-enter password.", password="str!")
s("TwoFactorSetup", "TOTP enrolment.", otpauth_uri="str!", qr_svg="text!")
s("TwoFactorConfirm", "Confirm TOTP.", code="str:6!")
s("RecoveryCodes", "Shown once.", recovery_codes="[str]!")
s("InvitationPublic", "Invitation seen before accepting.", company_name="str!", role_name="str!", email="email!", expires_at="datetime!", existing_user="bool!")
s("AcceptInvitationRequest", "Accept an invitation.", name="str:120?", password="str:200?", password_confirmation="str:200?")
s("Me", "Current user.", id="id!", name="str!", email="email!", phone="str?", two_factor_enabled="bool!", companies="[ref:Membership]!", active_company_id="id?", permissions="[str]", approval_limits="obj?", branch_ids="[id]")
s("Membership", "Company membership.", company_id="id!", company_name="str!", role="str!", status="enum:active|invited|deactivated!")
s("Session", "Signed-in device.", id="id!", device_label="str?", user_agent="str?", ip_address="str?", last_seen_at="datetime?", current="bool!")
s("UserPreferences", "Appearance.", theme="enum:light|dark|system", density="enum:default|compact", text_size="enum:s|m|l", reduce_motion="bool", sidebar_collapsed="bool")
s("NotificationPreferences", "Per-event channels.", events="[ref:NotificationPreference]!", daily_summary_time="str?")
s("NotificationPreference", "Channels for one event.", event_key="str!", in_app="bool!", email="bool!", sms="bool!")

# ---------------------------------------------------------------- company & settings
s("CompanyCreate", "New company.", legal_name="str:190!", trading_name="str:190?", business_type="enum:private_limited|sole_proprietorship|partnership|public_limited!", fy_start_month="int", base_currency_code="ccy")
s("Company", "Company profile.", id="id!", legal_name="str!", trading_name="str?", business_type="str!", trade_licence_no="str?", tax_registration_no="str?", incorporated_on="date?",
  registered_address="str?", phone="str?", email="email?", website="str?", logo_url="str?", base_currency_code="ccy!", fy_start_month="int!", timezone="str!", branches="[ref:Branch]", seats_used="int", seat_limit="int", version="int!")
s("CompanyUpdate", "Editable company fields.", legal_name="str:190", trading_name="str:190?", business_type="enum:private_limited|sole_proprietorship|partnership|public_limited", trade_licence_no="str:60?",
  tax_registration_no="str:40?", incorporated_on="date?", registered_address="str:255?", phone="str:30?", email="email?", website="str:190?")
s("CompanySettings", "All company settings (see company_settings).", valuation_method="enum:weighted_average|fifo!", invoice_rounding="enum:none|nearest_1|nearest_5!", allow_negative_stock="bool!", require_approval="bool!",
  backdate_warning_days="int!", prices_include_tax="bool!", show_tax_no_on_invoice="bool!", default_warehouse_id="id?", default_payment_term_id="id?", symbol_position="enum:before|after!", table_decimals="int!",
  negative_format="enum:parentheses|minus!", number_grouping="enum:lakh|million!", date_format="str!", week_start="enum:saturday|sunday|monday!", print_language="enum:en|bn|both!",
  invoice="obj!", require_two_factor="bool!", idle_timeout_minutes="int?", backup_enabled="bool", backup_retention_days="int", version="int!")
s("CompanySettingsUpdate", "Partial update; same fields as CompanySettings except version.", valuation_method="enum:weighted_average|fifo", invoice_rounding="enum:none|nearest_1|nearest_5", allow_negative_stock="bool",
  require_approval="bool", backdate_warning_days="int", prices_include_tax="bool", show_tax_no_on_invoice="bool", default_warehouse_id="id?", default_payment_term_id="id?", symbol_position="enum:before|after",
  table_decimals="int", negative_format="enum:parentheses|minus", number_grouping="enum:lakh|million", date_format="str:20", week_start="enum:saturday|sunday|monday", print_language="enum:en|bn|both",
  invoice="obj", require_two_factor="bool", idle_timeout_minutes="int?", backup_enabled="bool", backup_retention_days="int")
s("Branch", "Location.", id="id!", code="str!", name="str!", kind="str?", address="str?", phone="str?", is_head_office="bool!", is_active="bool!", version="int!")
s("BranchInput", "Create or edit a location.", code="str:20!", name="str:120!", kind="str:120?", address="str:255?", phone="str:30?", is_head_office="bool", is_active="bool")
s("PaymentTerm", "Payment terms.", id="id!", name="str!", days="int!", is_default="bool!")
s("NumberSequence", "Numbering series.", id="id!", document_type="str!", prefix="str!", next_number="int!", padding="int!", reset_policy="enum:fiscal_year|never!", preview="str!", version="int!")
s("NumberSequenceUpdate", "Change a series.", prefix="str:20", next_number="int", padding="int")
s("TaxRule", "Tax rule.", id="id!", name="str!", rate="pct!", applies_to="enum:sales|purchases|both!", ledger_id="id!", ledger_name="str!", is_recoverable="bool!", is_active="bool!", version="int!")
s("TaxRuleInput", "Create or edit a tax rule.", name="str:80!", rate="pct!", applies_to="enum:sales|purchases|both!", ledger_id="id?", create_ledger="bool", is_recoverable="bool", is_active="bool")
s("AccountMappings", "Mapping key -> ledger or group.", mappings="[ref:AccountMapping]!")
s("AccountMapping", "One posting mapping.", mapping_key="str!", ledger_id="id?", account_group_id="id?", required_nature="str!", description="str?")
s("FiscalYear", "Financial year with periods.", id="id!", name="str!", start_date="date!", end_date="date!", status="enum:open|closing|closed!", periods="[ref:AccountingPeriod]!")
s("AccountingPeriod", "Monthly period.", id="id!", name="str!", start_date="date!", end_date="date!", status="enum:future|open|locked!", locked_at="datetime?", locked_by="ref:UserRef?", lock_note="str?")
s("PeriodLockRequest", "Lock a period.", note="str:255?")
s("Member", "Company member.", id="id!", user_id="id!", name="str!", email="email!", role_id="id!", role_name="str!", status="enum:active|invited|deactivated!", last_active_at="datetime?",
  two_factor_enabled="bool!", branch_ids="[id]", permissions="[str]", recent_activity="[ref:AuditLog]", version="int!")
s("MemberUpdate", "Change role or branches.", role_id="id", branch_ids="[id]")
s("Invitation", "Pending invitation.", id="id!", email="email!", name="str?", role_id="id!", expires_at="datetime!", status="enum:pending|accepted|revoked|expired!")
s("InvitationCreate", "Invite a user.", email="email!", name="str:120?", role_id="id!", branch_ids="[id]")
s("Role", "Role with permissions.", id="id!", name="str!", slug="str!", description="str?", is_system="bool!", members_count="int!", permissions="[str]!", payment_approval_limit="money?", journal_approval_limit="money?", version="int!")
s("RoleInput", "Create or replace a custom role. Both approval limits are required (0.00 = everything needs approval); only the system Owner role has no limit (R-10).", name="str:60!", description="str:255?", permissions="[str]!", payment_approval_limit="money!", journal_approval_limit="money!", copy_from_role_id="id?")
s("PermissionList", "Permission catalogue grouped by module.", modules="[obj]!")

# ---------------------------------------------------------------- platform
s("SearchResults", "Grouped search hits.", query="str!", groups="[ref:SearchGroup]!")
s("SearchGroup", "Hits of one type.", type="enum:party|sales_invoice|purchase_bill|voucher|ledger|product|letter_of_credit!", count="int!", items="[ref:SearchHit]!")
s("SearchHit", "One hit.", id="id!", title="str!", subtitle="str?", badge="str?", url="str!")
s("Notification", "In-app notification.", id="id!", event_key="str!", title="str!", body="str?", category="enum:approvals|alerts|info!", link_type="str?", link_id="id?", read_at="datetime?", created_at="datetime!")
s("ApprovalRequest", "Approval request.", id="id!", document_type="str!", document_id="id!", document_number="str!", amount="money!", limit="money?", required_permission="str!",
  status="enum:pending|approved|rejected|cancelled!", requested_by="ref:UserRef!", requested_at="datetime!", decided_by="ref:UserRef?", decided_at="datetime?", comment="str?", facts="obj?")
s("ApprovalDecision", "Approve or reject. Comment required to reject.", comment="str:500?")
s("Attachment", "Uploaded file.", id="id!", original_name="str!", mime_type="str!", size_bytes="int!", uploaded_by="ref:UserRef!", created_at="datetime!")
s("AttachmentUpload", "multipart: file, attachable_type, attachable_id.", file="str!", attachable_type="enum:sales_invoice|purchase_bill|voucher|lc_document|letter_of_credit|stock_adjustment|party!", attachable_id="id!")
s("AuditLog", "Audit entry.", id="id!", action="str!", user="ref:UserRef?", old_values="obj?", new_values="obj?", ip_address="str?", created_at="datetime!")
s("DashboardSummary", "A1 payload.", period="obj!", kpis="[ref:Kpi]!", revenue_vs_expenses="[obj]!", cash_position="[obj]!", money_owed="obj!", upcoming_payments="[obj]!", recent_transactions="[obj]!", inventory_alerts="[obj]!", banners="[obj]")
s("Kpi", "Stat tile.", key="str!", label="str!", value="money!", delta_pct="pct?", trend="[money]", foot="str?")
s("LensData", "A2-A8 payload.", lens="enum:financial|sales|expenses|receivables|payables|cash|inventory!", kpis="[ref:Kpi]!", series="[obj]", table="ref:ReportData")
s("Backup", "Company backup.", id="id!", kind="enum:automatic|manual|pre_restore|pre_lock!", status="enum:running|complete|failed|expired!", size_bytes="int?", started_at="datetime!", finished_at="datetime?", created_by="ref:UserRef?")
s("RestoreRequest", "Restore confirmation.", confirmation="enum:RESTORE!")
s("ExportJob", "Async export.", id="id!", kind="enum:export_pack|report|list!", status="enum:queued|running|complete|failed!", download_url="str?", expires_at="datetime?", finished_at="datetime?", error="str?")
s("ExportJobCreate", "Start an export. export_pack: month-end pack (`period`, `reports`). list: any list screen, `source` = the list operationId (e.g. getParties) and `filters` = its query parameters; the caller needs that list's view permission as well.", kind="enum:export_pack|list!", format="enum:xlsx|pdf|both|csv!", period="str:20?", reports="[str]", source="str:60?", filters="obj?")

# ---------------------------------------------------------------- parties
s("PartyInput", "Create or edit a customer or supplier. `mobile` is required when country_code = BD (BR-063, R-33).", party_type="enum:customer|supplier!", name="str:190!", category="enum:business|individual|walk_in|manufacturer|distributor|service_provider?",
  party_group_id="id?", contact_person="str:120?", mobile="str:20?", email="email?", alt_phone="str:20?", address="str:255?", district="str:60?", area="str:80?", country_code="str:2",
  default_currency_code="ccy", payment_term_id="id?", credit_limit="money?", price_tier="enum:standard|wholesale", default_purchase_ledger_id="id?", tax_registration_no="str:40?",
  port_of_loading="str:120?", notes="text?", opening_balance="money", opening_side="enum:dr|cr", bank_accounts="[ref:PartyBankAccount]")
s("PartyBankAccount", "Supplier bank details.", id="id?", bank_name="str:120!", branch="str:120?", account_name="str:150?", account_number="str:40!", routing_number="str:20?", swift_code="str:11?", is_default="bool")
s("Party", "Customer or supplier.", id="id!", party_type="enum:customer|supplier!", code="str!", name="str!", category="str?", group="ref:PartyGroup?", contact_person="str?", mobile="str?", email="email?",
  alt_phone="str?", address="str?", district="str?", area="str?", country_code="str!", default_currency_code="ccy!", payment_term="ref:PaymentTerm?", credit_limit="money?", price_tier="str!",
  ledger_id="id!", ledger_code="str!", tax_registration_no="str?", port_of_loading="str?", default_purchase_ledger_id="id?", status="enum:active|inactive|prospect!", balance="money!", balance_side="enum:dr|cr!", overdue="money!",
  opening_balance="money!", opening_locked="bool!", bank_accounts="[ref:PartyBankAccount]", kpis="[ref:Kpi]", notes="text?", version="int!")
s("PartyGroup", "Party group.", id="id!", party_type="str!", name="str!")
s("PartyGroupInput", "Create a group.", party_type="enum:customer|supplier!", name="str:80!")
s("OpenDocuments", "Open invoices or bills of a party, oldest due first, and the open credits that can be applied to them.", party="ref:PartyRef!", documents="[ref:OpenDocument]!", credits="[ref:OpenCredit]!", total_open="money!", unapplied_credit="money!")
s("OpenCredit", "An unapplied receipt/payment, advance or posted note that can be allocated (R-08).", journal_line_id="id!", kind="enum:receipt|payment|advance|credit_note|debit_note!", number="str!", date="date!", amount="money!", unapplied="money!")
s("OpenDocument", "One open document.", id="id!", type="enum:sales_invoice|purchase_bill!", number="str!", date="date!", due_date="date!", total="money!", settled="money!", balance="money!", days_overdue="int!")
s("PartyTransaction", "History row.", id="id!", type="str!", number="str!", date="date!", reference="str?", due_date="date?", amount="money!", status="str!", url="str!")
s("LedgerStatement", "Ledger/book lines with running balance.", ledger="obj!", period="obj!", opening="money!", opening_side="enum:dr|cr!", lines="[ref:LedgerLine]!", total_debit="money!", total_credit="money!",
  closing="money!", closing_side="enum:dr|cr!", monthly="[obj]", meta="ref:PageMeta?")
s("LedgerLine", "One ledger line.", date="date!", voucher_id="id!", voucher_number="str!", entry_type="str!", particulars="str!", narration="str?", debit="money!", credit="money!", balance="money!", balance_side="enum:dr|cr!")
s("OutstandingReport", "Aging across parties.", as_of="date!", buckets="[ref:AgingBucket]!", rows="[obj]!", totals="obj!")
s("AgingBucket", "Aging bucket.", key="enum:not_due|d1_30|d31_60|d61_90|d90_plus|total!", amount="money!", count="int!")
s("PartyStatement", "Statement of account.", statement_no="str!", party="ref:PartyRef!", period="obj!", summary="obj!", lines="[ref:LedgerLine]!", aging="[ref:AgingBucket]?", footer="str?")
s("StatementSendRequest", "Send statements.", party_ids="[id]!", date_from="date!", date_to="date!", include_aging="bool")

# ---------------------------------------------------------------- inventory
s("ProductInput", "Create or edit a product or service.", item_type="enum:product|service!", name="str:190!", code="str:30?", category_id="id?", unit_id="id!", barcode="str:40?",
  last_purchase_cost="cost?", selling_price="money!", wholesale_price="money?", tax_rule_id="id?", income_ledger_id="id!", expense_ledger_id="id?", stock_group_id="id?",
  preferred_supplier_id="id?", is_active="bool", warehouses="[ref:ProductWarehouseInput]")
s("ProductWarehouseInput", "Per-warehouse settings; opening_qty only on create.", warehouse_id="id!", reorder_level="qty", reorder_qty="qty", opening_qty="qty", opening_unit_cost="cost")
s("Product", "Product or service. Cost fields omitted without inventory.view_costs (BR-052).", id="id!", item_type="enum:product|service!", code="str!", name="str!", category="ref:Lookup?", unit="str!",
  barcode="str?", selling_price="money!", wholesale_price="money?", tax_rule_id="id?", income_ledger_id="id!", track_stock="bool!", stock_group="ref:Lookup?", preferred_supplier="ref:PartyRef?",
  on_hand="qty?", stock_status="enum:in_stock|low_stock|out_of_stock|not_tracked!", avg_cost="cost?", stock_value="money?", last_purchase_cost="cost?",
  warehouses="[ref:StockLevel]", is_active="bool!", version="int!")
s("StockLevel", "Stock of a product in a warehouse.", product_id="id!", product_code="str!", product_name="str!", warehouse_id="id!", warehouse_name="str!", qty_on_hand="qty!", reorder_level="qty!",
  reorder_qty="qty!", value="money?", status="enum:in_stock|low_stock|out_of_stock|not_stocked!")
s("StockMovement", "Stock ledger row. unit_cost and value are omitted without inventory.view_costs (BR-052).", id="id!", moved_on="date!", movement_type="str!", product="ref:Lookup!", warehouse="ref:Lookup!", quantity_in="qty!", quantity_out="qty!",
  qty_balance_after="qty!", unit_cost="cost?", value="money?", source_type="str!", source_id="id!", source_number="str!")
s("Warehouse", "Warehouse.", id="id!", code="str!", name="str!", branch_id="id!", address="str?", manager="ref:UserRef?", phone="str?", inventory_ledger_id="id!", is_default_for_sales="bool!",
  status="enum:active|planned|inactive!", opens_on="date?", stock_value="money?", products_count="int!", below_reorder_count="int!", capacity_used_pct="pct?", version="int!")
s("WarehouseInput", "Create or edit a warehouse.", code="str:20!", name="str:120!", branch_id="id!", address="str:255?", manager_user_id="id?", phone="str:30?", inventory_ledger_id="id?",
  is_default_for_sales="bool", status="enum:active|planned|inactive", opens_on="date?")
s("StockAdjustmentInput", "Draft adjustment.", adjustment_date="date!", warehouse_id="id!", reason="enum:count_difference|damaged|expired|theft_or_loss|free_samples!", notes="text?", lines="[ref:AdjustmentLineInput]!")
s("AdjustmentLineInput", "Counted quantity.", product_id="id!", counted_qty="qty!")
s("StockAdjustment", "Stock adjustment. net_value and posting_preview are omitted without inventory.view_costs (BR-052).", id="id!", number="str!", adjustment_date="date!", warehouse_id="id!", reason="str!", status="enum:draft|posted|reversed!", lines="[obj]!", net_value="money?",
  journal_entry_id="id?", posting_preview="[ref:PostingLine]", version="int!")
s("CountSheetPreview", "Parsed count sheet.", lines="[obj]!", unknown_codes="[str]")
s("ProductImport", "multipart/form-data: `file` (CSV or XLSX, max 10 MB, columns per the import template) and `dry_run` query flag.", file="str!")
s("ImportResult", "Outcome of an import: per-row errors on dry run, created ids on commit.", dry_run="bool!", rows="int!", valid_rows="int!", created_ids="[id]", errors="[ref:ImportRowError]!")
s("ImportRowError", "One rejected import row.", row="int!", column="str?", code="errcode!", message="str!")
s("StockTransferInput", "Draft transfer.", from_warehouse_id="id!", to_warehouse_id="id!", dispatch_date="date?", vehicle="str:60?", driver="str:120?", notes="text?", lines="[ref:TransferLineInput]!")
s("TransferLineInput", "Quantity to move.", product_id="id!", quantity="qty!")
s("TransferReceive", "Received quantities (default = dispatched).", received_date="date!", lines="[ref:TransferLineInput]")
s("StockTransfer", "Stock transfer. value_at_cost is omitted without inventory.view_costs (BR-052).", id="id!", number="str!", from_warehouse_id="id!", to_warehouse_id="id!", status="enum:draft|dispatched|received|cancelled!", dispatch_date="date?",
  received_date="date?", lines="[obj]!", total_units="qty!", value_at_cost="money?", version="int!")

# ---------------------------------------------------------------- sales & purchases documents
s("SalesInvoiceInput", "Create or replace a draft invoice. Prices are optional; defaults come from the price tier.", party_id="id!", invoice_date="date!", due_date="date?", payment_term_id="id?",
  warehouse_id="id!", customer_po_ref="str:60?", quotation_id="id?", branch_id="id?", notes="text?", internal_note="text?", lines="[ref:SalesLineInput]!")
s("SalesLineInput", "Invoice line.", unit_price="money?", **LINE_IN)
s("SalesInvoice", "Sales invoice.", id="id!", number="str!", status="enum:draft|posted|cancelled!", payment_status="enum:unpaid|part_paid|paid!", overdue="bool!", party="ref:PartyRef!",
  invoice_date="date!", due_date="date!", warehouse_id="id!", customer_po_ref="str?", lines="[ref:SalesLine]!", amount_settled="money!", balance_due="money!",
  allocations="[ref:Allocation]", journal="[ref:PostingLine]", delivery="obj?", posted_at="datetime?", cancel_reason="str?", **TOTALS, **AUDIT)
s("SalesLine", "Invoice line out. Cost fields need inventory.view_costs.", unit_price="money!", unit_cost="cost?", cost_amount="money?", returned_quantity="qty!", **LINE_OUT)
s("DeliveryUpdate", "Delivery details.", challan_no="str:30?", vehicle_no="str:40?", delivered_at="datetime?")
s("DocumentCalculation", "Server-side totals for an unsaved document.", lines="[ref:CalculatedLine]!", warnings="[ref:Warning]!", party_balance_before="money?", party_balance_after="money?",
  credit_limit="money?", effects="[str]", **TOTALS)
s("CalculatedLine", "Calculated line.", line_no="int!", gross="money!", discount_amount="money!", tax_amount="money!", line_total="money!", available_qty="qty?")
s("Warning", "Non-blocking warning.", code="enum:CREDIT_LIMIT_EXCEEDED|PARTY_OVERDUE|LOW_STOCK|BACKDATED|BUDGET_NEAR_LIMIT|DUPLICATE_PARTY|INSUFFICIENT_FUNDS|APPROVAL_REQUIRED!", message="str!", line_no="int?")
s("QuotationInput", "Create or replace a quotation.", party_id="id!", quotation_date="date!", valid_until="date!", warehouse_id="id?", payment_term_id="id?", notes="text?", internal_note="text?", lines="[ref:SalesLineInput]!")
s("Quotation", "Quotation.", id="id!", number="str!", status="enum:draft|sent|accepted|declined|expired|converted!", party="ref:PartyRef!", quotation_date="date!", valid_until="date!",
  lines="[ref:SalesLine]!", converted_invoice_id="id?", **TOTALS, **AUDIT)
s("PurchaseBillInput", "Create or replace a draft bill. Foreign currency requires exchange_rate.", party_id="id!", supplier_bill_no="str:60!", bill_date="date!", due_date="date?", payment_term_id="id?",
  warehouse_id="id!", purchase_order_id="id?", letter_of_credit_id="id?", lc_shipment_id="id?", currency_code="ccy", exchange_rate="rate", branch_id="id?", notes="text?", internal_note="text?",
  receipt_condition_note="str:255?", lines="[ref:PurchaseLineInput]!")
s("PurchaseLineInput", "Bill line.", unit_cost="cost!", purchase_order_line_id="id?", **LINE_IN)
s("PurchaseBill", "Purchase bill.", id="id!", number="str!", supplier_bill_no="str!", status="enum:draft|posted|cancelled!", payment_status="enum:unpaid|part_paid|paid!", overdue="bool!",
  party="ref:PartyRef!", bill_date="date!", due_date="date!", warehouse_id="id!", currency_code="ccy!", exchange_rate="rate!", total_foreign="money?", purchase_order_id="id?",
  letter_of_credit_id="id?", lines="[obj]!", amount_settled="money!", balance_due="money!", allocations="[ref:Allocation]", journal="[ref:PostingLine]", posted_at="datetime?", **TOTALS, **AUDIT)
s("PurchaseOrderInput", "Create or replace a PO.", party_id="id!", order_date="date!", expected_date="date?", warehouse_id="id!", payment_term_id="id?", currency_code="ccy", exchange_rate="rate",
  source_note="str:120?", notes="text?", internal_note="text?", lines="[ref:PurchaseLineInput]!")
s("PurchaseOrder", "Purchase order.", id="id!", number="str!", status="enum:draft|sent|partly_received|received|closed|cancelled!", party="ref:PartyRef!", order_date="date!", expected_date="date?",
  currency_code="ccy!", lines="[obj]!", total_foreign="money?", **TOTALS, **AUDIT)
s("LowStockOrderRequest", "Rows to order.", rows="[obj]!", order_date="date!")
s("ReturnInput", "Sales or purchase return. stock_action: restock|write_off (sales), send_back|already_disposed (purchase). `usage` and `apply_to_document_id` decide how the note created on posting is used (R-04).", source_document_id="id!", return_date="date!", warehouse_id="id!",
  stock_action="enum:restock|write_off|send_back|already_disposed!", usage="enum:apply|credit|refund", apply_to_document_id="id?", notes="text?", lines="[ref:ReturnLineInput]!")
s("ReturnLineInput", "Returned quantity per original line.", source_line_id="id!", quantity="qty!", reason="str:30!")
s("Return", "Sales or purchase return.", id="id!", number="str!", status="enum:draft|posted|cancelled!", party="ref:PartyRef!", source_document_id="id!", source_number="str!", return_date="date!",
  stock_action="str!", lines="[obj]!", total="money!", note_id="id?", note_number="str?", version="int!")
s("NoteInput", "Credit or debit note. `apply_to_document_id` (required when usage = apply) is the invoice or bill of the same party that the note is allocated to on posting; it is stored only as the resulting bill_allocations row (R-20).", party_id="id!", source_document_id="id?", return_id="id?", note_date="date!", reason="str:30!", amount="money!", usage="enum:apply|credit|refund!",
  apply_to_document_id="id?", refund="ref:RefundInput?")
s("RefundInput", "Refund details for usage=refund.", money_account_id="id!", method="enum:cash|cheque|bank_transfer|beftn|rtgs|npsb|bkash|nagad|card!", reference="str:60?")
s("Note", "Credit or debit note.", id="id!", number="str!", kind="enum:credit_note|debit_note!", status="enum:draft|posted|cancelled!", party="ref:PartyRef!", note_date="date!", reason="str!",
  amount="money!", usage="str!", effects="[obj]", journal="[ref:PostingLine]", version="int!")
s("AllocationCreate", "Apply an open settling line to a document of the same party (R-08). journal_line_id is the party-ledger line of a receipt, payment, advance or posted credit/debit note.", journal_line_id="id!", document_type="enum:sales_invoice|purchase_bill!", document_id="id!", amount="money!", allocated_on="date?")
s("Allocation", "Settlement applied to a document.", id="id!", voucher_id="id!", voucher_number="str!", date="date!", amount="money!", kind="enum:receipt|payment|credit_note|debit_note|advance!", reversed="bool!")
s("PostingLine", "Journal line preview or actual.", ledger_id="id!", ledger_code="str!", ledger_name="str!", debit="money!", credit="money!")

# ---------------------------------------------------------------- vouchers & money
s("PartyPaymentInput", "Customer receipt (E7) or supplier payment (F5).", party_id="id!", date="date!", amount="money!", money_account_id="id!",
  method="enum:cash|cheque|bank_transfer|beftn|rtgs|npsb|bkash|nagad|card!", cheque_number="str:20?", cheque_date="date?", drawn_on="str:120?", transaction_reference="str:60?",
  bank_charge="money", exchange_rate="rate?", narration="str:1000?", allocations="[ref:AllocationInput]", auto_allocate="bool", confirm_backdated="bool")
s("AllocationInput", "Amount to apply to one document.", document_id="id!", amount="money!")
s("VoucherInput", "Journal, payment, receipt or contra voucher.", entry_type="enum:journal|payment|receipt|contra!", action="enum:draft|submit|post!", date="date!", reference="str:60?",
  narration="str:1000?", template_key="str:40?", payment="ref:PaymentDetailInput?", lines="[ref:VoucherLineInput]!", confirm_backdated="bool")
s("PaymentDetailInput", "Cash/bank side of payment, receipt or contra.", money_account_id="id!", counter_money_account_id="id?", method="enum:cash|cheque|bank_transfer|beftn|rtgs|npsb|bkash|nagad|card!",
  party_id="id?", payee_text="str:150?", cheque_number="str:20?", cheque_date="date?", drawn_on="str:120?", transaction_reference="str:60?", bank_charge="money", handed_to="str:120?")
s("VoucherLineInput", "Voucher line. Journal: debit or credit. Payment/receipt: amount.", ledger_id="id!", debit="money", credit="money", amount="money", line_narration="str:500?",
  against_reference="str:60?", allocations="[ref:AllocationInput]")
s("Voucher", "Journal entry / voucher.", id="id!", voucher_number="str!", entry_type="str!", status="enum:draft|submitted|approved|rejected|posted|reversed!", entry_date="date!", reference="str?",
  narration="str?", total_debit="money!", total_credit="money!", lines="[ref:VoucherLine]!", payment="obj?", source="obj?", reverses_entry_id="id?", reversed_by_entry_id="id?",
  approval="ref:ApprovalRequest?", audit_trail="[ref:AuditLog]", amount_in_words="str!", **AUDIT)
s("VoucherLine", "Voucher line.", line_no="int!", ledger_id="id!", ledger_code="str!", ledger_name="str!", debit="money!", credit="money!", line_narration="str?", against_reference="str?", foreign_amount="money?", exchange_rate="rate?")
s("MoneyAccount", "Cash, bank, wallet, card or cash-credit account.", id="id!", account_type="enum:cash|bank|wallet|card|cash_credit!", overdraft_limit="money?", name="str!", bank_name="str?", branch_name="str?", account_name="str?", account_number_masked="str?",
  routing_number="str?", currency_code="ccy!", ledger_id="id!", ledger_code="str!", book_balance="money!", custodian="ref:UserRef?", use_for_receipts="bool!", use_for_payments="bool!",
  show_on_invoices="bool!", last_reconciled_on="date?", unmatched_lines="int!", is_active="bool!", version="int!")
s("MoneyAccountInput", "Add or edit an account.", account_type="enum:cash|bank|wallet|card|cash_credit!", overdraft_limit="money?", name="str:120?", bank_name="str:120!", branch_name="str:120?", account_name="str:150!", account_number="str:40!",
  routing_number="str:9?", opening_balance="money", opening_date="date?", custodian_user_id="id?", use_for_receipts="bool", use_for_payments="bool", show_on_invoices="bool")
s("MoneyTransaction", "Cash/bank movement row.", date="date!", voucher_id="id!", voucher_number="str!", account="str!", particulars="str!", direction="enum:in|out|transfer!", amount_in="money!", amount_out="money!")
s("CashCountInput", "Cash count.", money_account_id="id!", counted_at="datetime!", denominations="[ref:Denomination]!", note="str:255?")
s("Denomination", "Note or coin count.", denomination="money!", count="int!")
s("CashCount", "Recorded cash count.", id="id!", money_account_id="id!", counted_at="datetime!", counted_by="ref:UserRef!", book_balance="money!", counted_total="money!", difference="money!",
  note="str?", status="enum:recorded|adjusted|reversed!", adjustment_entry_id="id?")
s("StatementImport", "multipart: file, money_account_id, column mapping.", file="str!", money_account_id="id!", mapping="obj?")
s("StatementImportResult", "Imported statement.", id="id!", line_count="int!", period_from="date!", period_to="date!", duplicates_skipped="int!")
s("ReconciliationStart", "Start a reconciliation.", money_account_id="id!", statement_date="date!", statement_balance="money!")
s("Reconciliation", "Reconciliation workspace.", id="id!", money_account_id="id!", statement_date="date!", statement_balance="money!", book_balance="money!", difference="money!",
  matched_count="int!", line_count="int!", status="enum:in_progress|finished!", lines="[ref:StatementLine]!", version="int!")
s("StatementLine", "Statement line with match state.", id="id!", txn_date="date!", description="str!", bank_reference="str?", amount="money!",
  status="enum:unmatched|matched|created|paired|ignored!", suggestion="obj?", matched="obj?")
s("MatchRequest", "Match to a book line.", journal_line_id="id!")
s("CreateEntryFromLine", "Create the missing voucher.", kind="enum:receipt|bank_charge|payment!", ledger_id="id!", party_id="id?", narration="str:500?")
s("PairRequest", "Offsetting statement lines.", line_ids="[id]!")
s("IeCategory", "Income or expense category.", id="id!", kind="enum:income|expense!", name="str!", description="str?", ledger_id="id!", ledger_code="str!", monthly_budget="money?",
  warn_at_percent="pct!", spent="money!", used_pct="pct?", status="enum:on_track|near_limit|over_budget|not_used|no_budget!")
s("IeCategoryInput", "Create or edit a category.", kind="enum:income|expense!", name="str:80!", description="str:255?", ledger_id="id?", create_ledger="bool", monthly_budget="money?", warn_at_percent="pct")
s("IeEntryInput", "Quick income or expense.", kind="enum:income|expense!", amount="money!", date="date!", category_id="id!", money_account_id="id!",
  method="enum:cash|cheque|bank_transfer|beftn|rtgs|npsb|bkash|nagad|card!", payee_text="str:150?", reference="str:60?", tax_rule_id="id?", notes="str:500?",
  attachment_ids="[id]", recurrence="ref:RecurrenceInput?", confirm_backdated="bool")
s("RecurrenceInput", "Repeat settings.", frequency="enum:weekly|monthly|quarterly|yearly!", next_run_on="date!", ends_type="enum:never|after_count|on_date!", ends_after_count="int?", ends_on="date?")
s("IeEntry", "Line on a category ledger.", journal_line_id="id!", voucher_id="id!", voucher_number="str!", date="date!", description="str!", category="str!", account="str!", method="str?",
  amount="money!", has_receipt="bool!")
s("RecurringTemplate", "Recurring entry.", id="id!", kind="str!", frequency="str!", next_run_on="date!", ends_type="str!", occurrences_done="int!", is_active="bool!", payload="obj!", version="int!")
s("RecurringTemplateInput", "Edit a recurring entry.", frequency="enum:weekly|monthly|quarterly|yearly", next_run_on="date", ends_type="enum:never|after_count|on_date", ends_after_count="int?", ends_on="date?", is_active="bool")

# ---------------------------------------------------------------- accounting
s("AccountTree", "Chart of accounts tree.", as_of="date!", nodes="[ref:AccountNode]!", total_debit="money!", total_credit="money!", balanced="bool!")
s("AccountNode", "Group or ledger node.", kind="enum:primary|group|ledger!", id="id!", code="str!", name="str!", nature="str!", balance="money!", balance_side="enum:dr|cr!", note="str?", children="[ref:AccountNode]")
s("AccountGroup", "Account group.", id="id!", code="str!", name="str!", parent_id="id?", nature="str!", normal_balance="str!", report_section="str!", cash_flow_category="str?", is_system="bool!",
  balance="money!", children="[obj]", version="int!")
s("AccountGroupInput", "Create or edit a group.", code="str:20!", name="str:120!", parent_id="id!", cash_flow_category="enum:operating|investing|financing|cash?", sort_order="int", used_in_cash_vouchers="bool")
s("Ledger", "Ledger.", id="id!", code="str!", name="str!", account_group_id="id!", group_path="[str]!", nature="str!", normal_balance="str!", ledger_kind="str!", description="str?",
  track_bills="bool!", show_in_cash_vouchers="bool!", is_system="bool!", is_active="bool!", has_entries="bool!", opening_balance="money!", opening_side="enum:dr|cr!", balance="money!",
  balance_side="enum:dr|cr!", fy_summary="obj?", version="int!")
s("LedgerInput", "Create or edit a ledger.", name="str:150!", account_group_id="id!", code="str:20?", description="str:255?", track_bills="bool", show_in_cash_vouchers="bool",
  opening_balance="money", opening_side="enum:dr|cr")
s("TemplateList", "Journal templates.", templates="[obj]!")
s("VoucherPreview", "Server-calculated voucher (R-27): the lines as they would post, totals, balance status, approval need and warnings.", lines="[ref:PostingLine]!", total_debit="money!", total_credit="money!", difference="money!", balanced="bool!", needs_approval="bool!", approval_limit="money?", warnings="[ref:Warning]!", amount_in_words="str!")

# ---------------------------------------------------------------- LC
s("LetterOfCreditInput", "Create or update a draft/awaiting LC. Only supplier, amount, issuing bank and expiry are needed to submit. `document_template_id` copies that checklist into the LC's documents on create (or while draft) and is not stored (R-40).", party_id="id!", purchase_order_id="id?", intended_import="str:255?",
  goods_description="str:500!", quantity="qty?", quantity_unit="str:20?", import_authorization_ref="str:60?", lc_type="enum:irrevocable|irrevocable_transferable|standby", tenor="enum:sight|usance!",
  usance_days="int?", incoterm="str:40!", currency_code="ccy!", amount="money!", tolerance_pct="pct", planning_rate="rate!", partial_shipments="bool", transhipment="bool",
  confirmation_required="bool", issuing_bank_account_id="id?", advising_bank="str:190?", margin_pct="pct", margin_account_id="id?", application_date="date?", latest_shipment_date="date?",
  expiry_date="date?", presentation_days="int", document_template_id="id?", documents="[ref:LcDocumentInput]", record_shipments="bool", shipment="ref:LcShipmentInput?")
s("LetterOfCredit", "Import LC.", id="id!", lc_number="str!", status="enum:draft|awaiting_bank|opened|partly_shipped|documents_presented|payment_due|closed|cancelled|expired!", party="ref:PartyRef!",
  goods_description="str!", currency_code="ccy!", amount="money!", tolerance_pct="pct!", planning_rate="rate!", amount_bdt_planned="money!", paid_foreign="money!", outstanding_foreign="money!",
  tenor="str!", usance_days="int?", incoterm="str!", issuing_bank="ref:MoneyAccount?", bank_reference="str?", opening_status="str!", opened_on="date?", latest_shipment_date="date?",
  expiry_date="date?", days_to_expiry="int?", maturity_date="date?", margin_pct="pct!", margin_amount="money!", flags="[ref:Warning]", milestones="[obj]!",
  documents="[ref:LcDocument]", shipments="[ref:LcShipment]", charges="[ref:LcCharge]", payments="[ref:LcPayment]", amendments="[ref:LcAmendment]", money_position="obj?", **AUDIT)
s("LcTransition", "Status change.", action="enum:submit|mark_opened|mark_refused|documents_presented|payment_due|cancel|expire|reopen!", bank_reference="str:60?", date="date?", reason="str:255?")
s("LcCloseRequest", "Close an LC.", override_reason="str:255?", release_margin_to_account_id="id?")
s("LcDocumentInput", "Required document.", name="str:120!", provided_by="str:80!", copies="int", note="str:255?", is_required="bool",
  status="enum:not_yet_due|pending|received|missing|discrepant|not_required", due_date="date?", received_date="date?", discrepancy_note="str:500?", lc_shipment_id="id?")
s("LcDocument", "LC document.", id="id!", name="str!", provided_by="str!", copies="int!", note="str?", is_required="bool!", status="str!", due_date="date?", received_date="date?", attachments="[ref:Attachment]", version="int!")
s("LcDocumentTemplate", "Checklist template.", id="id!", name="str!", is_default="bool!", items="[obj]!")
s("LcDocumentTemplateInput", "Template items.", name="str:80!", is_default="bool", items="[obj]!")
s("LcAmendmentInput", "Record an amendment.", requested_on="date!", status="enum:requested|issued|accepted!", changes="[ref:AmendmentChange]!", other_change="str:255?", reason="str:500?", bank_fee="money")
s("AmendmentChange", "Changed field.", field_key="enum:expiry_date|latest_shipment_date|amount|tolerance_pct|other!", new_value="str:120!")
s("LcAmendmentStatus", "Advance status.", status="enum:issued|accepted|refused!", date="date!")
s("LcAmendment", "Amendment.", id="id!", letter_of_credit_id="id!", amendment_no="int!", status="str!", requested_on="date!", issued_on="date?", accepted_on="date?", changes="[obj]!", bank_fee="money!", reason="str?", version="int!")
s("LcShipmentInput", "Shipment lot.", lot_no="int", status="enum:not_shipped|booking_confirmed|at_sea|arrived|arrived_cleared|not_shipped_expired", booking_ref="str:60?", bl_number="str:60?",
  vessel="str:120?", port_of_loading="str:120?", port_of_discharge="str:120?", mode="enum:sea|air|road", etd="date?", eta="date?", shipped_on="date?", arrived_on="date?", quantity="qty?", value_foreign="money?")
s("LcShipment", "Shipment lot.", id="id!", lot_no="int!", status="str!", bl_number="str?", vessel="str?", etd="date?", eta="date?", arrived_on="date?", quantity="qty?", value_foreign="money?", purchase_bill_id="id?", version="int!")
s("LcChargeInput", "Charge or import expense.", letter_of_credit_id="id!", charge_type="enum:margin_deposit|opening_commission|amendment_fee|swift_postage|stamp_documentation|acceptance_commission|discrepancy_fee|marine_insurance|cf_agent_fee|port_handling|customs_duty_taxes|inland_transport|other!",
  charge_date="date!", amount="money!", paid_to="str:150?", payee_party_id="id?", status="enum:unpaid|paid!", paid_from_account_id="id?", include_in_landed_cost="bool", reference="str:60?",
  lc_shipment_id="id?", lc_amendment_id="id?")
s("ChargePayment", "Pay a charge.", paid_from_account_id="id!", date="date!", reference="str:60?")
s("LcCharge", "LC charge.", id="id!", letter_of_credit_id="id!", lc_number="str!", charge_type="str!", charge_date="date!", amount="money!", status="enum:unpaid|paid!", paid_to="str?",
  include_in_landed_cost="bool!", is_refundable="bool!", allocated="bool!", journal_entry_id="id?", reference="str?", version="int!")
s("LcPaymentInput", "Payment at the bank's rate.", payment_kind="enum:sight_payment|usance_acceptance|usance_settlement", payment_date="date!", amount_foreign="money!", bank_rate="rate!", money_account_id="id!", margin_applied="money", lc_shipment_id="id?",
  purchase_bill_id="id?", bank_reference="str:60?", description="str:150?")
s("LcPayment", "LC payment.", id="id!", payment_kind="enum:sight_payment|usance_acceptance|usance_settlement!", payment_date="date!", amount_foreign="money!", bank_rate="rate!", amount_bdt="money!", margin_applied="money!", fx_difference="money!", journal_entry_id="id?", status="str!")
s("LandedCostRequest", "Allocate capitalised charges to a bill.", purchase_bill_id="id!", allocation_date="date!", method="enum:value|quantity", charge_ids="[id]")
s("LandedCostAllocation", "Allocation preview or result.", id="id?", status="enum:preview|draft|posted|reversed!", total_amount="money!", lines="[obj]!", journal="[ref:PostingLine]", warnings="[ref:Warning]")


# Optional request fields that the server fills when omitted. Compiled into each property's description
# (and checked by validate_api.py A14 against NOT NULL columns without a database default).
SERVER_DEFAULTS = {
    ("PartyInput", "default_currency_code"): "the company's base currency (BDT)",
    ("WarehouseInput", "inventory_ledger_id"): "a new ledger 'Inventory, <warehouse>' under 1301 is created",
    ("TaxRuleInput", "ledger_id"): "with create_ledger=true a ledger is created under the matching tax group; one of the two is required",
    ("IeCategoryInput", "ledger_id"): "with create_ledger=true a ledger is created under the matching income or expense group; one of the two is required",
    ("SalesInvoiceInput", "due_date"): "invoice_date + the payment term's days (BR-055)",
    ("SalesLineInput", "unit_price"): "the product's selling or wholesale price by the customer's price tier",
    ("PurchaseBillInput", "due_date"): "bill_date + the payment term's days (BR-055)",
    ("PurchaseBillInput", "currency_code"): "the supplier's default currency",
    ("PurchaseOrderInput", "currency_code"): "the supplier's default currency",
    ("CompanyCreate", "base_currency_code"): "BDT",
    ("LcShipmentInput", "lot_no"): "the next lot number of the LC",
    ("MoneyAccountInput", "name"): "'<bank> ••<last 4 digits>'",
}
