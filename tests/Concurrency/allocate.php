<?php

/**
 * Child process for the allocation concurrency test (09 §9, TC-CON-002; backlog P6.1-BE-02). Boots the app against
 * the test database and applies one settling line to a document through AllocationService, after waiting for the
 * parent's start barrier file. Prints one JSON line: {"ok":true} or {"ok":false,"code":"…"}.
 *   php tests/Concurrency/allocate.php <company_id> <user_id> <journal_line_id> <document_type> <document_id> <amount> <barrier_file>
 */

use App\Domain\Settlements\AllocationService;
use App\Domain\Shared\Money;
use App\Models\Company;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $companyId, $userId, $lineId, $type, $docId, $amount, $barrier] = $argv;
app(CurrentCompany::class)->set(Company::findOrFail((int) $companyId), null);
Auth::setUser(User::findOrFail((int) $userId));

for ($i = 0; $i < 600 && ! file_exists($barrier); $i++) {
    usleep(10_000);
}

try {
    app(AllocationService::class)->allocate((int) $lineId, $type, (int) $docId, Money::of($amount));
    echo json_encode(['ok' => true])."\n";
} catch (ApiProblem $e) {
    echo json_encode(['ok' => false, 'code' => $e->errorCode])."\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'code' => 'exception', 'message' => $e->getMessage()])."\n";
}
