<?php

use App\Http\Controllers\Api\V1\Accounting\LedgerController;
use App\Http\Controllers\Api\V1\Auth\AuthController;
use App\Http\Controllers\Api\V1\Me\TwoFactorController;
use App\Http\Controllers\Api\V1\MeController;
use App\Http\Controllers\Api\V1\Parties\LookupController;
use App\Http\Controllers\Api\V1\Parties\PartyController;
use App\Http\Controllers\Api\V1\Platform\PlatformLookupController;
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

        Route::get('payment-terms', [LookupController::class, 'paymentTerms']);
        Route::get('party-groups', [LookupController::class, 'partyGroups'])->middleware('perm:parties.view');
        Route::post('party-groups', [PartyController::class, 'storeGroup'])->middleware(['perm:parties.create', 'idempotent']);

        Route::get('parties', [PartyController::class, 'index'])->middleware('perm:parties.view');
        Route::post('parties', [PartyController::class, 'store'])->middleware(['perm:parties.create', 'idempotent']);
        Route::post('parties/bulk-deactivate', [PartyController::class, 'bulkDeactivate'])->middleware('perm:parties.edit');
        Route::get('parties/{id}', [PartyController::class, 'show'])->whereNumber('id')->middleware('perm:parties.view');
        Route::patch('parties/{id}', [PartyController::class, 'update'])->whereNumber('id')->middleware('perm:parties.edit');
        Route::post('parties/{id}/deactivate', [PartyController::class, 'deactivate'])->whereNumber('id')->middleware('perm:parties.edit');
        Route::post('parties/{id}/reactivate', [PartyController::class, 'reactivate'])->whereNumber('id')->middleware('perm:parties.edit');
        Route::delete('parties/{id}', [PartyController::class, 'destroy'])->whereNumber('id')->middleware('perm:parties.delete');
    });
});
