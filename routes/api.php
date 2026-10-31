<?php

use App\Http\Controllers\Api\V1\Accounting\AccountGroupController;
use App\Http\Controllers\Api\V1\Accounting\AccountingOverviewController;
use App\Http\Controllers\Api\V1\Accounting\AccountMappingController;
use App\Http\Controllers\Api\V1\Accounting\FiscalYearController;
use App\Http\Controllers\Api\V1\Accounting\LedgerController;
use App\Http\Controllers\Api\V1\Accounting\VoucherController;
use App\Http\Controllers\Api\V1\Approvals\ApprovalController;
use App\Http\Controllers\Api\V1\Approvals\NotificationController;
use App\Http\Controllers\Api\V1\Attachments\AttachmentController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Banking\BankingController;
use App\Http\Controllers\Api\V1\Banking\CashCountController;
use App\Http\Controllers\Api\V1\Banking\IncomeExpenseController;
use App\Http\Controllers\Api\V1\Banking\MoneyAccountController;
use App\Http\Controllers\Api\V1\Banking\ReconciliationController;
use App\Http\Controllers\Api\V1\Inventory\ProductCategoryController;
use App\Http\Controllers\Api\V1\Inventory\ProductController;
use App\Http\Controllers\Api\V1\Inventory\StockAdjustmentController;
use App\Http\Controllers\Api\V1\Inventory\StockGroupController;
use App\Http\Controllers\Api\V1\Inventory\StockMovementController;
use App\Http\Controllers\Api\V1\Inventory\StockTransferController;
use App\Http\Controllers\Api\V1\Inventory\UnitController;
use App\Http\Controllers\Api\V1\Inventory\WarehouseController;
use App\Http\Controllers\Api\V1\Me\TwoFactorController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\Parties\LookupController;
use App\Http\Controllers\Api\V1\Parties\PartyAccountController;
use App\Http\Controllers\Api\V1\Parties\PartyController;
use App\Http\Controllers\Api\V1\Platform\PlatformLookupController;
use App\Http\Controllers\Api\V1\Purchases\DebitNoteController;
use App\Http\Controllers\Api\V1\Purchases\PurchaseBillController;
use App\Http\Controllers\Api\V1\Purchases\PurchaseOrderController;
use App\Http\Controllers\Api\V1\Purchases\PurchaseReturnController;
use App\Http\Controllers\Api\V1\Reports\FinancialReportController;
use App\Http\Controllers\Api\V1\Reports\InventoryReportController;
use App\Http\Controllers\Api\V1\Reports\TradeReportController;
use App\Http\Controllers\Api\V1\Sales\CreditNoteController;
use App\Http\Controllers\Api\V1\Sales\QuotationController;
use App\Http\Controllers\Api\V1\Sales\SalesInvoiceController;
use App\Http\Controllers\Api\V1\Sales\SalesReturnController;
use App\Http\Controllers\Api\V1\Settlements\AllocationController;
use App\Http\Controllers\Api\V1\Settlements\CustomerReceiptController;
use App\Http\Controllers\Api\V1\Settlements\SupplierPaymentController;
use Illuminate\Support\Facades\Route;

/*
 * /api/v1 - first vertical slice: authentication (with 2-step), company context, RBAC and the customer workflow.
 * Middleware order per 07 §4: jwt -> company -> perm:<permission> -> idempotent.
 */
Route::prefix('auth')->middleware('throttle:auth-ip')->group(function () {
    Route::post('login', [AuthController::class, 'login']);
    Route::post('two-factor', [AuthController::class, 'twoFactor']);
    Route::post('refresh', [AuthController::class, 'refresh']);
    Route::post('logout', [AuthController::class, 'logout']);
});

Route::middleware(['jwt', 'throttle:api-user'])->group(function () {
    Route::get('me', MeController::class)->middleware('company:optional');
    Route::post('me/two-factor', [TwoFactorController::class, 'start']);
    Route::post('me/two-factor/confirm', [TwoFactorController::class, 'confirm']);
    Route::delete('me/two-factor', [TwoFactorController::class, 'destroy']);

    Route::middleware('company')->group(function () {
        // Platform lookups for every member (P1.1-BE-01).
        Route::get('fiscal-years', [PlatformLookupController::class, 'fiscalYears']);
        Route::get('branches', [PlatformLookupController::class, 'branches']);
        Route::get('company/settings', [PlatformLookupController::class, 'companySettings']);
        Route::get('audit-logs', [PlatformLookupController::class, 'auditLogs']); // P1.1-BE-02: permission is contextual
        Route::get('ledgers', [LedgerController::class, 'index']); // P1.1-BE-03: chart for accounting.view, picker by purpose otherwise
        Route::post('ledgers', [LedgerController::class, 'store'])->middleware(['perm:accounting.create', 'idempotent']); // P7.1-BE-02
        Route::get('ledgers/{id}', [LedgerController::class, 'show'])->whereNumber('id')->middleware('perm:accounting.view');
        Route::patch('ledgers/{id}', [LedgerController::class, 'update'])->whereNumber('id')->middleware('perm:accounting.edit');
        Route::post('ledgers/{id}/deactivate', [LedgerController::class, 'deactivate'])->whereNumber('id')->middleware('perm:accounting.edit');
        Route::get('ledgers/{id}/entries', [LedgerController::class, 'entries'])->whereNumber('id')->middleware('perm:accounting.view'); // P7.1-BE-03
        Route::get('accounting/overview', AccountingOverviewController::class)->middleware('perm:accounting.view');
        // Vouchers (P7.1-BE-04). Create/replace/submit/post/calculate check the permission by voucher type in the controller (R-09).
        Route::get('vouchers', [VoucherController::class, 'index'])->middleware('perm:accounting.view');
        Route::post('vouchers', [VoucherController::class, 'store'])->middleware('idempotent');
        Route::post('vouchers/calculate', [VoucherController::class, 'calculate']);
        Route::get('voucher-templates', [VoucherController::class, 'templates'])->middleware('perm:accounting.view');
        Route::get('vouchers/{id}', [VoucherController::class, 'show'])->whereNumber('id')->middleware('perm:accounting.view');
        Route::put('vouchers/{id}', [VoucherController::class, 'update'])->whereNumber('id');
        Route::delete('vouchers/{id}', [VoucherController::class, 'destroy'])->whereNumber('id')->middleware('perm:accounting.delete');
        Route::post('vouchers/{id}/submit', [VoucherController::class, 'submit'])->whereNumber('id');
        Route::post('vouchers/{id}/post', [VoucherController::class, 'post'])->whereNumber('id')->middleware('idempotent');
        Route::post('vouchers/{id}/reverse', [VoucherController::class, 'reverse'])->whereNumber('id')->middleware(['perm:accounting.create', 'idempotent']); // P7.1-BE-06
        Route::get('vouchers/{id}/pdf', [VoucherController::class, 'pdf'])->whereNumber('id')->middleware('perm:accounting.view');
        // Period lock/unlock and year close (P7.1-BE-07).
        Route::post('accounting-periods/{id}/lock', [FiscalYearController::class, 'lock'])->whereNumber('id')->middleware('perm:accounting.lock_period');
        Route::post('accounting-periods/{id}/unlock', [FiscalYearController::class, 'unlock'])->whereNumber('id')->middleware('perm:accounting.lock_period');
        Route::post('fiscal-years/{id}/close', [FiscalYearController::class, 'close'])->whereNumber('id')->middleware(['perm:accounting.close_year', 'idempotent']);
        // Accounting defaults (P7.1-BE-08).
        Route::get('account-mappings', [AccountMappingController::class, 'index'])->middleware('perm:accounting.view');
        Route::put('account-mappings', [AccountMappingController::class, 'update'])->middleware('perm:settings.edit');
        // Approvals and notifications (P7.1-BE-05). Deciding needs the request's required_permission, checked by the service (BR-008).
        Route::get('approvals', [ApprovalController::class, 'index']);
        Route::get('approvals/{id}', [ApprovalController::class, 'show'])->whereNumber('id');
        Route::post('approvals/{id}/approve', [ApprovalController::class, 'approve'])->whereNumber('id')->middleware('idempotent');
        Route::post('approvals/{id}/reject', [ApprovalController::class, 'reject'])->whereNumber('id');
        Route::get('notifications', [NotificationController::class, 'index']);
        Route::post('notifications/read-all', [NotificationController::class, 'readAll']);
        Route::post('notifications/{id}/read', [NotificationController::class, 'read'])->whereNumber('id');
        // Chart of accounts (P7.1-BE-01).
        Route::get('account-groups', [AccountGroupController::class, 'index'])->middleware('perm:accounting.view');
        Route::post('account-groups', [AccountGroupController::class, 'store'])->middleware('perm:accounting.create');
        Route::get('account-groups/{id}', [AccountGroupController::class, 'show'])->whereNumber('id')->middleware('perm:accounting.view');
        Route::patch('account-groups/{id}', [AccountGroupController::class, 'update'])->whereNumber('id')->middleware('perm:accounting.edit');
        Route::get('tax-rules', [PlatformLookupController::class, 'taxRules']); // P3.1-BE-01: any member (product and document forms)

        Route::get('payment-terms', [LookupController::class, 'paymentTerms']);
        Route::get('party-groups', [LookupController::class, 'partyGroups'])->middleware('perm:parties.view');
        Route::post('party-groups', [PartyController::class, 'storeGroup'])->middleware(['perm:parties.create', 'idempotent']);

        Route::get('parties', [PartyController::class, 'index'])->middleware('perm:parties.view');
        Route::post('parties', [PartyController::class, 'store'])->middleware(['perm:parties.create', 'idempotent']);
        Route::post('parties/bulk-deactivate', [PartyController::class, 'bulkDeactivate'])->middleware('perm:parties.edit');
        // Party accounts (P6.1-BE-05). parties/outstanding sits before parties/{id}.
        Route::get('parties/outstanding', [PartyAccountController::class, 'outstanding'])->name('parties.outstanding')->middleware('perm:parties.view');
        Route::post('parties/statements/send', [PartyAccountController::class, 'send'])->middleware(['perm:parties.view', 'idempotent']);
        Route::get('parties/{id}/ledger', [PartyAccountController::class, 'ledger'])->whereNumber('id')->name('parties.ledger')->middleware('perm:parties.view');
        Route::get('parties/{id}/transactions', [PartyAccountController::class, 'transactions'])->whereNumber('id')->name('parties.transactions')->middleware('perm:parties.view');
        Route::get('parties/{id}/statement', [PartyAccountController::class, 'statement'])->whereNumber('id')->name('parties.statement')->middleware('perm:parties.view');
        Route::get('parties/{id}/statement/pdf', [PartyAccountController::class, 'statementPdf'])->whereNumber('id')->name('parties.statement.pdf')->middleware('perm:parties.view');
        Route::get('parties/{id}', [PartyController::class, 'show'])->whereNumber('id')->middleware('perm:parties.view');
        Route::patch('parties/{id}', [PartyController::class, 'update'])->whereNumber('id')->middleware('perm:parties.edit');
        Route::post('parties/{id}/deactivate', [PartyController::class, 'deactivate'])->whereNumber('id')->middleware('perm:parties.edit');
        Route::post('parties/{id}/reactivate', [PartyController::class, 'reactivate'])->whereNumber('id')->middleware('perm:parties.edit');
        Route::delete('parties/{id}', [PartyController::class, 'destroy'])->whereNumber('id')->middleware('perm:parties.delete');

        // Inventory masters (P3.1).
        Route::get('units', [UnitController::class, 'index'])->middleware('perm:inventory.view');
        Route::post('units', [UnitController::class, 'store'])->middleware('perm:inventory.create');
        foreach (['product-categories' => ProductCategoryController::class, 'stock-groups' => StockGroupController::class] as $path => $controller) {
            Route::get($path, [$controller, 'index'])->middleware('perm:inventory.view');
            Route::post($path, [$controller, 'store'])->middleware('perm:inventory.create');
            Route::patch("{$path}/{id}", [$controller, 'update'])->whereNumber('id')->middleware('perm:inventory.edit');
            Route::delete("{$path}/{id}", [$controller, 'destroy'])->whereNumber('id')->middleware('perm:inventory.delete');
        }
        Route::get('warehouses', [WarehouseController::class, 'index'])->middleware('perm:inventory.view');
        Route::post('warehouses', [WarehouseController::class, 'store'])->middleware(['perm:inventory.create', 'idempotent']);
        Route::get('warehouses/{id}', [WarehouseController::class, 'show'])->whereNumber('id')->middleware('perm:inventory.view');
        Route::patch('warehouses/{id}', [WarehouseController::class, 'update'])->whereNumber('id')->middleware('perm:inventory.edit');
        Route::get('warehouses/{id}/stock', [WarehouseController::class, 'stock'])->whereNumber('id')->middleware('perm:inventory.view');
        Route::get('stock-adjustments', [StockAdjustmentController::class, 'index'])->middleware('perm:inventory.view');
        Route::post('stock-adjustments', [StockAdjustmentController::class, 'store'])->middleware(['perm:inventory.create', 'idempotent']);
        Route::post('stock-adjustments/count-sheet', [StockAdjustmentController::class, 'countSheet'])->middleware('perm:inventory.create');
        Route::get('stock-adjustments/{id}', [StockAdjustmentController::class, 'show'])->whereNumber('id')->middleware('perm:inventory.view');
        Route::patch('stock-adjustments/{id}', [StockAdjustmentController::class, 'update'])->whereNumber('id')->middleware('perm:inventory.edit');
        Route::delete('stock-adjustments/{id}', [StockAdjustmentController::class, 'destroy'])->whereNumber('id')->middleware('perm:inventory.delete');
        Route::post('stock-adjustments/{id}/post', [StockAdjustmentController::class, 'post'])->whereNumber('id')->middleware(['perm:inventory.create', 'idempotent']);
        Route::post('stock-adjustments/{id}/reverse', [StockAdjustmentController::class, 'reverse'])->whereNumber('id')->middleware(['perm:inventory.approve', 'idempotent']);
        Route::get('stock-transfers', [StockTransferController::class, 'index'])->middleware('perm:inventory.view');
        Route::post('stock-transfers', [StockTransferController::class, 'store'])->middleware(['perm:inventory.create', 'idempotent']);
        Route::get('stock-transfers/{id}', [StockTransferController::class, 'show'])->whereNumber('id')->middleware('perm:inventory.view');
        Route::patch('stock-transfers/{id}', [StockTransferController::class, 'update'])->whereNumber('id')->middleware('perm:inventory.edit');
        Route::delete('stock-transfers/{id}', [StockTransferController::class, 'destroy'])->whereNumber('id')->middleware('perm:inventory.delete');
        Route::post('stock-transfers/{id}/dispatch', [StockTransferController::class, 'send'])->whereNumber('id')->middleware(['perm:inventory.create', 'idempotent']);
        Route::post('stock-transfers/{id}/receive', [StockTransferController::class, 'receive'])->whereNumber('id')->middleware(['perm:inventory.create', 'idempotent']);
        Route::post('stock-transfers/{id}/cancel', [StockTransferController::class, 'cancel'])->whereNumber('id')->middleware(['perm:inventory.edit', 'idempotent']);
        Route::get('products', [ProductController::class, 'index'])->middleware('perm:inventory.view');
        Route::post('products', [ProductController::class, 'store'])->middleware(['perm:inventory.create', 'idempotent']);
        Route::post('products/import', [ProductController::class, 'import'])->middleware(['perm:inventory.create', 'idempotent']);
        Route::delete('products/{id}', [ProductController::class, 'destroy'])->whereNumber('id')->middleware('perm:inventory.delete');
        Route::get('products/{id}', [ProductController::class, 'show'])->whereNumber('id')->middleware('perm:inventory.view');
        Route::patch('products/{id}', [ProductController::class, 'update'])->whereNumber('id')->middleware('perm:inventory.edit');

        Route::get('stock-movements', [StockMovementController::class, 'index'])->middleware('perm:inventory.view');

        // Purchase bills (P4.2).
        Route::post('purchase-bills/calculate', [PurchaseBillController::class, 'calculate'])->middleware('perm:purchases.create');
        Route::get('purchase-bills', [PurchaseBillController::class, 'index'])->middleware('perm:purchases.view');
        Route::post('purchase-bills', [PurchaseBillController::class, 'store'])->middleware(['perm:purchases.create', 'idempotent']);
        Route::get('purchase-bills/{id}', [PurchaseBillController::class, 'show'])->whereNumber('id')->middleware('perm:purchases.view');
        Route::put('purchase-bills/{id}', [PurchaseBillController::class, 'update'])->whereNumber('id')->middleware('perm:purchases.edit');
        Route::post('purchase-bills/{id}/post', [PurchaseBillController::class, 'post'])->whereNumber('id')->middleware(['perm:purchases.create', 'idempotent']);
        Route::post('purchase-bills/{id}/cancel', [PurchaseBillController::class, 'cancel'])->whereNumber('id')->middleware(['perm:purchases.edit', 'idempotent']);
        Route::delete('purchase-bills/{id}', [PurchaseBillController::class, 'destroy'])->whereNumber('id')->middleware('perm:purchases.delete');

        // Purchase orders (F8, F11, D12; backlog P10.2-BE-01; BR-056: never post)
        Route::get('purchase-orders', [PurchaseOrderController::class, 'index'])->middleware('perm:purchases.view');
        Route::post('purchase-orders', [PurchaseOrderController::class, 'store'])->middleware(['perm:purchases.create', 'idempotent']);
        Route::post('purchase-orders/from-low-stock', [PurchaseOrderController::class, 'fromLowStock'])->middleware(['perm:purchases.create', 'idempotent']);
        Route::get('purchase-orders/{id}', [PurchaseOrderController::class, 'show'])->whereNumber('id')->middleware('perm:purchases.view');
        Route::put('purchase-orders/{id}', [PurchaseOrderController::class, 'update'])->whereNumber('id')->middleware('perm:purchases.edit');
        Route::delete('purchase-orders/{id}', [PurchaseOrderController::class, 'destroy'])->whereNumber('id')->middleware('perm:purchases.delete');
        Route::post('purchase-orders/{id}/send', [PurchaseOrderController::class, 'send'])->whereNumber('id')->middleware('perm:purchases.edit');
        Route::post('purchase-orders/{id}/status', [PurchaseOrderController::class, 'status'])->whereNumber('id')->middleware('perm:purchases.edit');
        Route::post('purchase-orders/{id}/convert', [PurchaseOrderController::class, 'convert'])->whereNumber('id')->middleware(['perm:purchases.create', 'idempotent']);
        Route::get('purchase-orders/{id}/pdf', [PurchaseOrderController::class, 'pdf'])->whereNumber('id')->middleware('perm:purchases.view');

        // Purchase returns and debit notes (F6, F7, F13; backlog P10.4; 06 §10; R-04)
        Route::get('purchase-returns', [PurchaseReturnController::class, 'index'])->middleware('perm:purchases.view');
        Route::post('purchase-returns', [PurchaseReturnController::class, 'store'])->middleware(['perm:purchases.create', 'idempotent']);
        Route::get('purchase-returns/{id}', [PurchaseReturnController::class, 'show'])->whereNumber('id')->middleware('perm:purchases.view');
        Route::put('purchase-returns/{id}', [PurchaseReturnController::class, 'update'])->whereNumber('id')->middleware('perm:purchases.edit');
        Route::delete('purchase-returns/{id}', [PurchaseReturnController::class, 'destroy'])->whereNumber('id')->middleware('perm:purchases.delete');
        Route::post('purchase-returns/{id}/post', [PurchaseReturnController::class, 'post'])->whereNumber('id')->middleware(['perm:purchases.create', 'idempotent']);
        Route::post('purchase-returns/{id}/cancel', [PurchaseReturnController::class, 'cancel'])->whereNumber('id')->middleware(['perm:purchases.edit', 'idempotent']);
        Route::get('debit-notes', [DebitNoteController::class, 'index'])->middleware('perm:purchases.view');
        Route::post('debit-notes', [DebitNoteController::class, 'store'])->middleware(['perm:purchases.create', 'idempotent']);
        Route::get('debit-notes/{id}', [DebitNoteController::class, 'show'])->whereNumber('id')->middleware('perm:purchases.view');
        Route::put('debit-notes/{id}', [DebitNoteController::class, 'update'])->whereNumber('id')->middleware('perm:purchases.edit');
        Route::delete('debit-notes/{id}', [DebitNoteController::class, 'destroy'])->whereNumber('id')->middleware('perm:purchases.delete');
        Route::post('debit-notes/{id}/post', [DebitNoteController::class, 'post'])->whereNumber('id')->middleware(['perm:purchases.create', 'idempotent']);
        Route::post('debit-notes/{id}/cancel', [DebitNoteController::class, 'cancel'])->whereNumber('id')->middleware(['perm:purchases.edit', 'idempotent']);
        Route::get('debit-notes/{id}/pdf', [DebitNoteController::class, 'pdf'])->whereNumber('id')->middleware('perm:purchases.view');

        // Money accounts (P6.1). The list is open to every member (pickers; masked without banking.view).
        Route::get('money-accounts', [MoneyAccountController::class, 'index']);
        Route::post('money-accounts', [MoneyAccountController::class, 'store'])->middleware(['perm:banking.create', 'idempotent']);
        Route::get('money-accounts/{id}', [MoneyAccountController::class, 'show'])->whereNumber('id')->middleware('perm:banking.view');
        Route::patch('money-accounts/{id}', [MoneyAccountController::class, 'update'])->whereNumber('id')->middleware('perm:banking.edit');
        Route::get('money-accounts/{id}/book', [BankingController::class, 'book'])->whereNumber('id')->middleware('perm:banking.view');
        Route::get('banking/overview', [BankingController::class, 'overview'])->middleware('perm:banking.view');
        Route::get('money-transactions', [BankingController::class, 'transactions'])->middleware('perm:banking.view');
        Route::post('cash-counts', [CashCountController::class, 'store'])->middleware(['perm:banking.create', 'idempotent']);
        Route::post('cash-counts/{id}/reverse', [CashCountController::class, 'reverse'])->whereNumber('id')->middleware(['perm:banking.approve', 'idempotent']);
        Route::post('bank-statement-imports', [ReconciliationController::class, 'import'])->middleware(['perm:banking.reconcile', 'idempotent']);
        Route::post('reconciliations', [ReconciliationController::class, 'start'])->middleware('perm:banking.reconcile');
        Route::get('reconciliations/{id}', [ReconciliationController::class, 'show'])->whereNumber('id')->middleware('perm:banking.reconcile');
        Route::post('reconciliations/{id}/lines/pair', [ReconciliationController::class, 'pair'])->whereNumber('id')->middleware('perm:banking.reconcile');
        Route::post('reconciliations/{id}/lines/{lineId}/match', [ReconciliationController::class, 'match'])->whereNumber(['id', 'lineId'])->middleware('perm:banking.reconcile');
        Route::post('reconciliations/{id}/lines/{lineId}/create-entry', [ReconciliationController::class, 'createEntry'])->whereNumber(['id', 'lineId'])->middleware(['perm:banking.reconcile', 'idempotent']);
        Route::post('reconciliations/{id}/lines/{lineId}/undo', [ReconciliationController::class, 'undo'])->whereNumber(['id', 'lineId'])->middleware('perm:banking.reconcile');
        Route::post('reconciliations/{id}/accept-suggestions', [ReconciliationController::class, 'acceptSuggestions'])->whereNumber('id')->middleware('perm:banking.reconcile');
        Route::post('reconciliations/{id}/finish', [ReconciliationController::class, 'finish'])->whereNumber('id')->middleware('perm:banking.reconcile');

        // Allocations (P6.1, R-08): permission follows the party (sales.* or purchases.*), checked in the controller.
        Route::post('allocations', [AllocationController::class, 'store'])->middleware('idempotent');
        Route::delete('allocations/{id}', [AllocationController::class, 'destroy'])->whereNumber('id');
        Route::get('parties/{id}/open-documents', [AllocationController::class, 'openDocuments'])->whereNumber('id')->middleware('perm:parties.view');

        // Customer receipts (P6.1).
        Route::get('customer-receipts', [CustomerReceiptController::class, 'index'])->middleware('perm:sales.view');
        Route::post('customer-receipts', [CustomerReceiptController::class, 'store'])->middleware(['perm:sales.create', 'idempotent']);
        Route::get('customer-receipts/{id}', [CustomerReceiptController::class, 'show'])->whereNumber('id')->middleware('perm:sales.view');
        Route::get('customer-receipts/{id}/pdf', [CustomerReceiptController::class, 'pdf'])->whereNumber('id')->middleware('perm:sales.view');
        // Supplier payments (P8.1-BE-01).
        Route::get('supplier-payments', [SupplierPaymentController::class, 'index'])->middleware('perm:purchases.view');
        Route::post('supplier-payments', [SupplierPaymentController::class, 'store'])->middleware(['perm:purchases.create', 'idempotent']);
        Route::get('supplier-payments/{id}', [SupplierPaymentController::class, 'show'])->whereNumber('id')->middleware('perm:purchases.view');
        Route::get('supplier-payments/{id}/pdf', [SupplierPaymentController::class, 'pdf'])->whereNumber('id')->middleware('perm:purchases.view');

        // Sales invoices (P5.1).
        Route::post('sales-invoices/calculate', [SalesInvoiceController::class, 'calculate'])->middleware('perm:sales.create');
        Route::get('sales-invoices', [SalesInvoiceController::class, 'index'])->middleware('perm:sales.view');
        Route::post('sales-invoices', [SalesInvoiceController::class, 'store'])->middleware(['perm:sales.create', 'idempotent']);
        Route::get('sales-invoices/{id}', [SalesInvoiceController::class, 'show'])->whereNumber('id')->middleware('perm:sales.view');
        Route::put('sales-invoices/{id}', [SalesInvoiceController::class, 'update'])->whereNumber('id')->middleware('perm:sales.edit');
        Route::post('sales-invoices/{id}/post', [SalesInvoiceController::class, 'post'])->whereNumber('id')->middleware(['perm:sales.create', 'idempotent']);
        Route::post('sales-invoices/bulk-post', [SalesInvoiceController::class, 'bulkPost'])->middleware(['perm:sales.create', 'idempotent']);
        Route::post('sales-invoices/{id}/cancel', [SalesInvoiceController::class, 'cancel'])->whereNumber('id')->middleware(['perm:sales.edit', 'idempotent']);
        Route::get('sales-invoices/{id}/pdf', [SalesInvoiceController::class, 'pdf'])->whereNumber('id')->middleware('perm:sales.view');
        Route::post('sales-invoices/{id}/send', [SalesInvoiceController::class, 'send'])->whereNumber('id')->middleware(['perm:sales.edit', 'idempotent']);
        Route::patch('sales-invoices/{id}/delivery', [SalesInvoiceController::class, 'delivery'])->whereNumber('id')->middleware('perm:sales.edit');
        Route::delete('sales-invoices/{id}', [SalesInvoiceController::class, 'destroy'])->whereNumber('id')->middleware('perm:sales.delete');

        // Quotations (E10, E13; backlog P10.1-BE-01; BR-056: never post)
        Route::get('quotations', [QuotationController::class, 'index'])->middleware('perm:sales.view');
        Route::post('quotations', [QuotationController::class, 'store'])->middleware(['perm:sales.create', 'idempotent']);
        Route::get('quotations/{id}', [QuotationController::class, 'show'])->whereNumber('id')->middleware('perm:sales.view');
        Route::put('quotations/{id}', [QuotationController::class, 'update'])->whereNumber('id')->middleware('perm:sales.edit');
        Route::delete('quotations/{id}', [QuotationController::class, 'destroy'])->whereNumber('id')->middleware('perm:sales.delete');
        Route::post('quotations/{id}/send', [QuotationController::class, 'send'])->whereNumber('id')->middleware('perm:sales.edit');
        Route::post('quotations/{id}/status', [QuotationController::class, 'status'])->whereNumber('id')->middleware('perm:sales.edit');
        Route::post('quotations/{id}/convert', [QuotationController::class, 'convert'])->whereNumber('id')->middleware(['perm:sales.create', 'idempotent']);
        Route::get('quotations/{id}/pdf', [QuotationController::class, 'pdf'])->whereNumber('id')->middleware('perm:sales.view');

        // Sales returns and credit notes (E8, E9, E15; backlog P10.3; 06 §7)
        Route::get('sales-returns', [SalesReturnController::class, 'index'])->middleware('perm:sales.view');
        Route::post('sales-returns', [SalesReturnController::class, 'store'])->middleware(['perm:sales.create', 'idempotent']);
        Route::get('sales-returns/{id}', [SalesReturnController::class, 'show'])->whereNumber('id')->middleware('perm:sales.view');
        Route::put('sales-returns/{id}', [SalesReturnController::class, 'update'])->whereNumber('id')->middleware('perm:sales.edit');
        Route::delete('sales-returns/{id}', [SalesReturnController::class, 'destroy'])->whereNumber('id')->middleware('perm:sales.delete');
        Route::post('sales-returns/{id}/post', [SalesReturnController::class, 'post'])->whereNumber('id')->middleware(['perm:sales.create', 'idempotent']);
        Route::post('sales-returns/{id}/cancel', [SalesReturnController::class, 'cancel'])->whereNumber('id')->middleware(['perm:sales.edit', 'idempotent']);
        Route::get('credit-notes', [CreditNoteController::class, 'index'])->middleware('perm:sales.view');
        Route::post('credit-notes', [CreditNoteController::class, 'store'])->middleware(['perm:sales.create', 'idempotent']);
        Route::get('credit-notes/{id}', [CreditNoteController::class, 'show'])->whereNumber('id')->middleware('perm:sales.view');
        Route::put('credit-notes/{id}', [CreditNoteController::class, 'update'])->whereNumber('id')->middleware('perm:sales.edit');
        Route::delete('credit-notes/{id}', [CreditNoteController::class, 'destroy'])->whereNumber('id')->middleware('perm:sales.delete');
        Route::post('credit-notes/{id}/post', [CreditNoteController::class, 'post'])->whereNumber('id')->middleware(['perm:sales.create', 'idempotent']);
        Route::post('credit-notes/{id}/cancel', [CreditNoteController::class, 'cancel'])->whereNumber('id')->middleware(['perm:sales.edit', 'idempotent']);
        Route::get('credit-notes/{id}/pdf', [CreditNoteController::class, 'pdf'])->whereNumber('id')->middleware('perm:sales.view');
        // Trade reports and overviews (E1, E11, E12, F1, F9, F10; backlog P10.5-BE-01). Margin and balances need reports.financial (DEC-13).
        Route::get('sales/overview', [TradeReportController::class, 'salesOverview'])->name('sales.overview')->middleware('perm:sales.view');
        Route::get('purchases/overview', [TradeReportController::class, 'purchasesOverview'])->name('purchases.overview')->middleware('perm:purchases.view');
        Route::get('reports/sales', [TradeReportController::class, 'sales'])->name('reports.sales')->middleware('perm:reports.view');
        Route::get('reports/sales-returns', [TradeReportController::class, 'salesReturns'])->name('reports.sales-returns')->middleware('perm:reports.view');
        Route::get('reports/purchases', [TradeReportController::class, 'purchases'])->name('reports.purchases')->middleware('perm:reports.view');
        Route::get('reports/purchase-returns', [TradeReportController::class, 'purchaseReturns'])->name('reports.purchase-returns')->middleware('perm:reports.view');
        Route::get('reports/inventory/summary', [InventoryReportController::class, 'summary'])->name('reports.inventory.summary')->middleware('perm:inventory.view');
        Route::get('reports/inventory/low-stock', [InventoryReportController::class, 'lowStock'])->name('reports.inventory.low-stock')->middleware('perm:inventory.view');
        Route::get('reports/inventory/valuation', [InventoryReportController::class, 'valuation'])->name('reports.inventory.valuation')->middleware('perm:inventory.view_costs');
        // Financial statements (P9.1): reports.financial (DEC-13); format=xlsx|pdf|csv also needs reports.export.
        foreach (['trial-balance' => 'trialBalance', 'profit-and-loss' => 'profitAndLoss', 'balance-sheet' => 'balanceSheet', 'cash-flow' => 'cashFlow',
            'general-ledger' => 'generalLedger', 'receivables-aging' => 'receivablesAging', 'payables-aging' => 'payablesAging'] as $path => $method) {
            Route::get("reports/{$path}", [FinancialReportController::class, $method])->name("reports.{$path}")->middleware('perm:reports.financial');
        }

        // Attachments (P13.1-BE-01): permission follows the record the file belongs to, checked in AttachmentService.
        Route::get('attachments', [AttachmentController::class, 'index']);
        Route::post('attachments', [AttachmentController::class, 'store'])->middleware('idempotent');
        Route::get('attachments/{id}/url', [AttachmentController::class, 'url'])->whereNumber('id');
        Route::delete('attachments/{id}', [AttachmentController::class, 'destroy'])->whereNumber('id');
        // Income and expenses (I1–I10; P13.1-BE-02..05). The reports are profit-and-loss figures: reports.financial (DEC-13).
        Route::get('ie-categories', [IncomeExpenseController::class, 'categories'])->middleware('perm:banking.view');
        Route::post('ie-categories', [IncomeExpenseController::class, 'storeCategory'])->middleware(['perm:banking.create', 'idempotent']);
        Route::patch('ie-categories/{id}', [IncomeExpenseController::class, 'updateCategory'])->whereNumber('id')->middleware('perm:banking.edit');
        Route::get('ie-entries', [IncomeExpenseController::class, 'entries'])->middleware('perm:banking.view');
        Route::post('ie-entries', [IncomeExpenseController::class, 'storeEntry'])->middleware(['perm:banking.create', 'idempotent']);
        Route::get('recurring-templates', [IncomeExpenseController::class, 'templates'])->middleware('perm:banking.view');
        Route::patch('recurring-templates/{id}', [IncomeExpenseController::class, 'updateTemplate'])->whereNumber('id')->middleware('perm:banking.edit');
        Route::get('income-expenses/overview', [IncomeExpenseController::class, 'overview'])->middleware('perm:banking.view');
        Route::get('reports/income', [IncomeExpenseController::class, 'incomeReport'])->name('reports.income')->middleware('perm:reports.financial');
        Route::get('reports/expenses', [IncomeExpenseController::class, 'expenseReport'])->name('reports.expenses')->middleware('perm:reports.financial');
    });
});

// A file behind a 5-minute signed link from GET /attachments/{id}/url (relative signature, so it works through the proxy).
Route::get('attachments/{id}/file', [AttachmentController::class, 'file'])->whereNumber('id')->name('attachments.file')->middleware('signed:relative');
