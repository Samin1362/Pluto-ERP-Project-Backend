<?php

/**
 * Child process for the concurrency tests (09 §9; backlog P4.3-BE-04). Boots the app against the test database and
 * posts one purchase bill through PurchaseBillPostingService, after waiting for the parent's start barrier file so
 * sibling processes start together. Prints one JSON line: {"ok":true} or {"ok":false,"code":"…"}.
 *   php tests/Concurrency/post-bill.php <company_id> <user_id> <bill_id> <barrier_file> [hold]
 * With `hold`, the post runs inside an outer transaction that is never committed: it prints {"posted":"uncommitted"}
 * and sleeps, so the parent can kill the process mid-post (TC-CON-004).
 */

use App\Domain\Purchases\PurchaseBillPostingService;
use App\Models\Company;
use App\Models\PurchaseBill;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

[, $companyId, $userId, $billId, $barrier] = $argv;
$hold = ($argv[5] ?? '') === 'hold';
app(CurrentCompany::class)->set(Company::findOrFail((int) $companyId), null);
Auth::setUser(User::findOrFail((int) $userId));

for ($i = 0; $i < 600 && ! file_exists($barrier); $i++) {
    usleep(10_000);
}

try {
    if ($hold) {
        DB::beginTransaction();
        app(PurchaseBillPostingService::class)->post(PurchaseBill::findOrFail((int) $billId));
        fwrite(STDOUT, json_encode(['posted' => 'uncommitted'])."\n");
        fflush(STDOUT);
        sleep(60); // killed by the parent before this ends; the connection drops and MySQL rolls back

        exit(1);
    }
    app(PurchaseBillPostingService::class)->post(PurchaseBill::findOrFail((int) $billId));
    echo json_encode(['ok' => true])."\n";
} catch (ApiProblem $e) {
    echo json_encode(['ok' => false, 'code' => $e->errorCode])."\n";
} catch (Throwable $e) {
    echo json_encode(['ok' => false, 'code' => 'exception', 'message' => $e->getMessage()])."\n";
}
