<?php

/**
 * Child process for the sales concurrency test (09 §9, TC-CON-001; backlog P5.1-BE-03). Boots the app against the test
 * database and posts one sales invoice through SalesInvoicePostingService, after waiting for the parent's start
 * barrier file so sibling processes start together. Prints one JSON line: {"ok":true} or {"ok":false,"code":"…"}.
 *   php tests/Concurrency/post-invoice.php <company_id> <user_id> <invoice_id> <barrier_file>
 */

use App\Domain\Sales\SalesInvoicePostingService;
use App\Models\Company;
use App\Models\SalesInvoice;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $companyId, $userId, $invoiceId, $barrier] = $argv;
app(CurrentCompany::class)->set(Company::findOrFail((int) $companyId), null);
Auth::setUser(User::findOrFail((int) $userId));

for ($i = 0; $i < 600 && ! file_exists($barrier); $i++) {
    usleep(10_000);
}

try {
    app(SalesInvoicePostingService::class)->post(SalesInvoice::findOrFail((int) $invoiceId));
    echo json_encode(['ok' => true])."\n";
} catch (ApiProblem $e) {
    echo json_encode(['ok' => false, 'code' => $e->errorCode])."\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'code' => 'exception', 'message' => $e->getMessage()])."\n";
}
