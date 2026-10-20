<?php

namespace App\Support\Problem;

/**
 * GENERATED from docs/source/errors.py by docs/source/build_php_contract.py. Do not edit by hand.
 * code => [HTTP status, title]. Codes are stable API (05 §3).
 */
final class ErrorCatalogue
{
    public const CODES = [
        'allocation_exceeds_balance' => [422, 'The allocation is more than the document\'s open balance or the payment'],
        'already_posted' => [409, 'This document has already been posted'],
        'already_reversed' => [409, 'This entry has already been reversed or cancelled'],
        'approval_required' => [202, 'Above your approval limit: submitted for approval'],
        'backdated_confirmation_required' => [422, 'The date is older than the back-date window; confirm to continue'],
        'base_currency_locked' => [422, 'The base currency cannot change once entries exist'],
        'branch_restricted' => [403, 'This record belongs to a branch you cannot access'],
        'charge_allocated' => [409, 'The charge is part of a posted landed-cost allocation'],
        'comment_required' => [422, 'A comment is required to reject'],
        'company_maintenance' => [503, 'This company is being restored from a backup; try again shortly (Retry-After)'],
        'company_required' => [400, 'The X-Company-Id header is missing'],
        'created_from_return' => [409, 'This debit note belongs to a purchase return; cancel the return'],
        'difference_not_zero' => [422, 'The reconciliation difference must be zero'],
        'duplicate' => [409, 'A record with the same unique value already exists'],
        'duplicate_statement' => [409, 'This statement file was already imported'],
        'duplicate_supplier_bill' => [409, 'This supplier\'s bill number is already entered'],
        'exceeds_tolerance' => [422, 'Payments would exceed the LC amount plus tolerance'],
        'file_too_large' => [413, 'Files may be up to 10 MB'],
        'file_type_not_allowed' => [415, 'Only JPG, PNG or PDF up to 10 MB'],
        'fiscal_year_not_closable' => [422, 'The year has not ended or a period is still open'],
        'foreign_document' => [422, 'The document belongs to another party'],
        'gone' => [410, 'This link has expired'],
        'has_history' => [409, 'This record is used elsewhere; make it inactive instead'],
        'idempotency_in_progress' => [409, 'The same request is still being processed'],
        'idempotency_key_reused' => [422, 'This Idempotency-Key was used with a different request'],
        'if_match_required' => [428, 'If-Match header with the current version is required'],
        'inactive_reference' => [422, 'An inactive party, product, ledger, warehouse or account was used'],
        'insufficient_funds' => [422, 'The account would go below zero (or below its overdraft limit)'],
        'insufficient_stock' => [422, 'Not enough stock in the warehouse'],
        'invalid_credentials' => [401, 'E-mail or password is incorrect'],
        'invalid_mobile' => [422, 'Mobile numbers have 11 digits starting 01'],
        'invalid_transition' => [409, 'This status change is not allowed from the current status'],
        'last_owner' => [409, 'A company must keep at least one active Owner'],
        'lc_not_closable' => [422, 'Unpaid supplier, unpaid charges or missing documents; give an override reason'],
        'lc_submit_incomplete' => [422, 'Supplier, amount, issuing bank and expiry are needed'],
        'ledger_locked' => [422, 'Code or opening balance cannot change once the ledger has entries'],
        'lines_unhandled' => [422, 'Every statement line must be matched, created, paired or ignored'],
        'lock_out_of_order' => [422, 'Periods lock and unlock in date order'],
        'malformed_json' => [400, 'The request body is not valid JSON or has the wrong type'],
        'mapping_missing' => [422, 'A posting ledger is not mapped in Accounting defaults'],
        'margin_exceeds_deposit' => [422, 'More margin applied than was deposited'],
        'nature_mismatch' => [422, 'The group must have the same nature (asset, liability, …)'],
        'not_a_member' => [403, 'You are not an active member of this company'],
        'not_draft' => [409, 'Only a draft can be changed, posted or deleted'],
        'not_found' => [404, 'Not found (or it belongs to another company)'],
        'note_applied' => [409, 'Remove the note\'s allocations before cancelling'],
        'period_locked' => [422, 'The date is in a locked period or outside an open financial year'],
        'permission_denied' => [403, 'Your role does not allow this action'],
        'purpose_required' => [422, 'Choose what the ledger is for'],
        'rate_limited' => [429, 'Too many requests; try again later'],
        'refresh_reuse_detected' => [401, 'A refresh token was reused; every session of this sign-in was revoked'],
        'refresh_token_invalid' => [401, 'The session has ended; sign in again'],
        'restore_confirmation_required' => [422, 'Type RESTORE to confirm'],
        'return_qty_exceeds' => [422, 'More than the quantity still returnable'],
        'role_in_use' => [409, 'Move the members to another role first'],
        'rounding_ledger_missing' => [422, 'Map the rounding ledger in Accounting defaults first'],
        'same_warehouse' => [422, 'Source and destination warehouses must differ'],
        'seat_limit_reached' => [409, 'All seats are in use'],
        'self_approval_forbidden' => [403, 'You cannot approve a voucher you submitted'],
        'server_error' => [500, 'Something went wrong; quote the request id'],
        'system_role' => [403, 'The Owner role cannot be changed or deleted'],
        'token_expired' => [401, 'The access token has expired; refresh and retry'],
        'token_invalid' => [401, 'The access token is not valid'],
        'two_factor_failed' => [401, 'The authentication code is not valid'],
        'two_factor_required_by_company' => [403, 'Your company requires 2-step verification'],
        'unauthenticated' => [401, 'Sign-in is required'],
        'unbalanced_entry' => [422, 'Total debit must equal total credit'],
        'use_document_cancel' => [409, 'System entries are corrected through their document'],
        'validation_failed' => [422, 'The request has invalid fields'],
        'version_conflict' => [409, 'Someone else changed this record; reload to see their changes'],
    ];

    public static function status(string $code): int
    {
        return self::CODES[$code][0] ?? 500;
    }

    public static function title(string $code): string
    {
        return self::CODES[$code][1] ?? 'Something went wrong';
    }
}
