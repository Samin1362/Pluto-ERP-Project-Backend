<?php

namespace App\Domain\Exports;

/**
 * What an export job can run (05 ExportJobCreate; backlog P14.1-BE-05): the report and list endpoints by their
 * contract operationId, with the file name they get. The job calls the endpoint as the requesting user, so the
 * endpoint's own permission and masking rules apply. A test checks every entry against the contract.
 */
final class ExportSources
{
    /** operationId => [path under /api/v1, file name] — endpoints that answer ReportData */
    public const REPORTS = [
        'getReportsTrialBalance' => ['/reports/trial-balance', 'trial-balance'],
        'getReportsProfitAndLoss' => ['/reports/profit-and-loss', 'profit-and-loss'],
        'getReportsBalanceSheet' => ['/reports/balance-sheet', 'balance-sheet'],
        'getReportsCashFlow' => ['/reports/cash-flow', 'cash-flow'],
        'getReportsGeneralLedger' => ['/reports/general-ledger', 'general-ledger'],
        'getReportsReceivablesAging' => ['/reports/receivables-aging', 'receivables-aging'],
        'getReportsPayablesAging' => ['/reports/payables-aging', 'payables-aging'],
        'getReportsSales' => ['/reports/sales', 'sales-report'],
        'getReportsSalesReturns' => ['/reports/sales-returns', 'sales-returns-report'],
        'getReportsPurchases' => ['/reports/purchases', 'purchases-report'],
        'getReportsPurchaseReturns' => ['/reports/purchase-returns', 'purchase-returns-report'],
        'getReportsIncome' => ['/reports/income', 'income-report'],
        'getReportsExpenses' => ['/reports/expenses', 'expense-report'],
        'getReportsInventoryLowStock' => ['/reports/inventory/low-stock', 'low-stock'],
        'getReportsInventoryValuation' => ['/reports/inventory/valuation', 'stock-valuation'],
    ];

    /** operationId => [path under /api/v1, file name] — paginated lists ({data, meta}); every page is exported */
    public const LISTS = [
        'getParties' => ['/parties', 'parties'],
        'getProducts' => ['/products', 'products'],
        'getStockMovements' => ['/stock-movements', 'stock-movements'],
        'getStockAdjustments' => ['/stock-adjustments', 'stock-adjustments'],
        'getStockTransfers' => ['/stock-transfers', 'stock-transfers'],
        'getSalesInvoices' => ['/sales-invoices', 'sales-invoices'],
        'getCustomerReceipts' => ['/customer-receipts', 'customer-receipts'],
        'getQuotations' => ['/quotations', 'quotations'],
        'getSalesReturns' => ['/sales-returns', 'sales-returns'],
        'getCreditNotes' => ['/credit-notes', 'credit-notes'],
        'getPurchaseBills' => ['/purchase-bills', 'purchase-bills'],
        'getSupplierPayments' => ['/supplier-payments', 'supplier-payments'],
        'getPurchaseOrders' => ['/purchase-orders', 'purchase-orders'],
        'getPurchaseReturns' => ['/purchase-returns', 'purchase-returns'],
        'getDebitNotes' => ['/debit-notes', 'debit-notes'],
        'getMoneyAccounts' => ['/money-accounts', 'money-accounts'],
        'getMoneyTransactions' => ['/money-transactions', 'money-transactions'],
        'getIeEntries' => ['/ie-entries', 'income-expense-entries'],
        'getLedgers' => ['/ledgers', 'ledgers'],
        'getVouchers' => ['/vouchers', 'vouchers'],
    ];

    /** The month-end pack's reports when none are named (the financial statements and both agings). */
    public const PACK_DEFAULT = ['getReportsTrialBalance', 'getReportsProfitAndLoss', 'getReportsBalanceSheet', 'getReportsCashFlow',
        'getReportsReceivablesAging', 'getReportsPayablesAging'];

    /** Report parameters that take the pack month: a range (from the 1st) or a date (the month's last day). */
    public const RANGE_REPORTS = ['getReportsProfitAndLoss', 'getReportsCashFlow', 'getReportsGeneralLedger', 'getReportsSales', 'getReportsSalesReturns',
        'getReportsPurchases', 'getReportsPurchaseReturns', 'getReportsIncome', 'getReportsExpenses'];
}
