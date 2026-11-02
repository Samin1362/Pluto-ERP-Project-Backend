<?php

namespace App\Providers;

use App\Models\AccountGroup;
use App\Models\BankFacility;
use App\Models\BillAllocation;
use App\Models\CashCount;
use App\Models\CreditNote;
use App\Models\DebitNote;
use App\Models\FiscalYear;
use App\Models\FxRevaluation;
use App\Models\LandedCostAllocation;
use App\Models\LcCharge;
use App\Models\LcLoan;
use App\Models\LcLoanAccrual;
use App\Models\LcLoanRepayment;
use App\Models\LcPayment;
use App\Models\Ledger;
use App\Models\LetterOfCredit;
use App\Models\MoneyAccount;
use App\Models\Party;
use App\Models\Product;
use App\Models\PurchaseBill;
use App\Models\PurchaseReturn;
use App\Models\SalesInvoice;
use App\Models\SalesReturn;
use App\Models\StockAdjustment;
use App\Models\StockTransfer;
use App\Support\CurrentCompany;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CurrentCompany::class);
    }

    public function boot(): void
    {
        // Document morph aliases are their table names (journal_entries.source_type, stock_movements.source_type;
        // BooksIntegrity relies on it). Models not listed keep their class name.
        Relation::morphMap(['account_groups' => AccountGroup::class, 'ledgers' => Ledger::class, 'fiscal_years' => FiscalYear::class, 'purchase_bills' => PurchaseBill::class, 'sales_invoices' => SalesInvoice::class, 'sales_returns' => SalesReturn::class, 'credit_notes' => CreditNote::class, 'purchase_returns' => PurchaseReturn::class, 'debit_notes' => DebitNote::class, 'money_accounts' => MoneyAccount::class, 'parties' => Party::class,
            'stock_adjustments' => StockAdjustment::class, 'stock_transfers' => StockTransfer::class, 'products' => Product::class,
            'cash_counts' => CashCount::class, 'letters_of_credit' => LetterOfCredit::class, 'lc_charges' => LcCharge::class,
            'lc_payments' => LcPayment::class, 'bank_facilities' => BankFacility::class, 'lc_loans' => LcLoan::class, 'lc_loan_repayments' => LcLoanRepayment::class, 'lc_loan_accruals' => LcLoanAccrual::class, 'fx_revaluations' => FxRevaluation::class, 'landed_cost_allocations' => LandedCostAllocation::class,
            'bill_allocations' => BillAllocation::class]);

        // The `api` guard reads the user that AuthenticateJwt verified; it never accepts a session cookie.
        Auth::viaRequest('pluto-jwt', fn (Request $request) => $request->attributes->get('auth_user'));

        // 05 §1 rate limits. Credential endpoints (sign-in, 2-step, password reset, invitations): 5/min per IP + e-mail and
        // 20/min per IP. Refresh and sign-out carry the session's own cookie, so they count per session (60/min), not per
        // IP: an office of many users behind one public IP no longer shares one budget for page loads (15 §6, P17.1-BE-03).
        RateLimiter::for('auth-ip', function (Request $r) {
            if ($r->is('api/v1/auth/refresh', 'api/v1/auth/logout')) {
                $session = (string) $r->cookie('pl_rt', '');

                return Limit::perMinute((int) config('pluto.rate_limits.auth_per_session'))->by('auth-session:'.($session !== '' ? hash('sha256', $session) : $r->ip()));
            }
            $email = strtolower(trim((string) $r->input('email', '')));

            return array_filter([
                $email !== '' ? Limit::perMinute((int) config('pluto.rate_limits.auth_per_ip_email'))->by('auth-email:'.$r->ip().'|'.$email) : null,
                Limit::perMinute((int) config('pluto.rate_limits.auth_per_ip'))->by('auth:'.$r->ip()),
            ]);
        });
        RateLimiter::for('api-user', fn (Request $r) => Limit::perMinute((int) config('pluto.rate_limits.api_per_user'))->by('user:'.($r->attributes->get('auth_user')?->id ?? $r->ip())));
        // Export jobs: 30 a minute per user (P14.1-BE-05).
        RateLimiter::for('exports', fn (Request $r) => Limit::perMinute(30)->by('exports:'.($r->attributes->get('auth_user')?->id ?? $r->ip())));
    }
}
