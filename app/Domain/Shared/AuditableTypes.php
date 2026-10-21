<?php

namespace App\Domain\Shared;

/**
 * API resource names accepted by GET /audit-logs (backlog P1.1-BE-02), mapped in one place to the table stored in
 * audit_logs.auditable_type (AuditLogger uses the model's table) and to the permission that may see the record's
 * activity (07 §6.2: view permission of the owning module). Add a line when a module starts writing audit entries.
 */
final class AuditableTypes
{
    /** @var array<string, array{table: string, permission: string}> */
    public const MAP = [
        'party' => ['table' => 'parties', 'permission' => 'parties.view'],
        'product' => ['table' => 'products', 'permission' => 'inventory.view'],
        'warehouse' => ['table' => 'warehouses', 'permission' => 'inventory.view'],
        'stock_adjustment' => ['table' => 'stock_adjustments', 'permission' => 'inventory.view'],
        'stock_transfer' => ['table' => 'stock_transfers', 'permission' => 'inventory.view'],
        'purchase_order' => ['table' => 'purchase_orders', 'permission' => 'purchases.view'],
        'purchase_bill' => ['table' => 'purchase_bills', 'permission' => 'purchases.view'],
        'purchase_return' => ['table' => 'purchase_returns', 'permission' => 'purchases.view'],
        'debit_note' => ['table' => 'debit_notes', 'permission' => 'purchases.view'],
        'quotation' => ['table' => 'quotations', 'permission' => 'sales.view'],
        'sales_invoice' => ['table' => 'sales_invoices', 'permission' => 'sales.view'],
        'sales_return' => ['table' => 'sales_returns', 'permission' => 'sales.view'],
        'credit_note' => ['table' => 'credit_notes', 'permission' => 'sales.view'],
        'journal_entry' => ['table' => 'journal_entries', 'permission' => 'accounting.view'],
        'ledger' => ['table' => 'ledgers', 'permission' => 'accounting.view'],
        'money_account' => ['table' => 'money_accounts', 'permission' => 'banking.view'],
        'letter_of_credit' => ['table' => 'letters_of_credit', 'permission' => 'lc.view'],
        'branch' => ['table' => 'branches', 'permission' => 'settings.view'],
    ];

    public static function names(): array
    {
        return array_keys(self::MAP);
    }

    /** @return array{table: string, permission: string}|null */
    public static function get(string $type): ?array
    {
        return self::MAP[$type] ?? null;
    }
}
