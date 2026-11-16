<?php

namespace App\Console\Commands;

use App\Domain\Parties\PaymentReminderService;
use App\Models\Company;
use App\Models\Party;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Automatic payment reminders (PEP-516). For every active company that switched them on (Settings, reminders_auto),
 * e-mails each customer with an e-mail address whose invoices are at least `reminder_days_after_due` days past due,
 * unless that customer had a reminder in the last 7 days. Runs daily at 10:00 Dhaka.
 */
class SendPaymentReminders extends Command
{
    protected $signature = 'pluto:reminders:send {--company= : Only this company id} {--dry-run : List who would get one, send nothing}';

    protected $description = 'E-mail payment reminders to customers with overdue invoices (companies with automatic reminders on)';

    public function handle(PaymentReminderService $reminders, CurrentCompany $current): int
    {
        $failed = false;
        $companies = Company::query()->where('status', 'active')
            ->whereIn('id', DB::table('company_settings')->where('reminders_auto', true)->select('company_id'))
            ->when($this->option('company'), fn ($q, $id) => $q->whereKey($id))->orderBy('id')->get();
        foreach ($companies as $company) {
            $current->set($company, null);
            $days = (int) (DB::table('company_settings')->where('company_id', $company->id)->value('reminder_days_after_due') ?? 7);
            $late = CarbonImmutable::now('Asia/Dhaka')->startOfDay()->subDays($days)->toDateString();
            $sent = 0;
            $customers = Party::query()->where('party_type', 'customer')->where('status', 'active')->whereNotNull('email')->where('email', '!=', '')
                ->whereIn('id', DB::table('sales_invoices')->where('company_id', $company->id)->where('status', 'posted')->where('balance_due', '>', 0)
                    ->where('due_date', '<', $late)->select('party_id'))->orderBy('id')->get();
            foreach ($customers as $party) {
                if ($reminders->sentRecently($party)) {
                    continue;
                }
                if ($this->option('dry-run')) {
                    $this->line("  would remind {$party->code} {$party->name}");
                    $sent++;

                    continue;
                }
                try {
                    $reminders->send($party, 'auto'); // every overdue invoice, once one is $days late
                    $sent++;
                } catch (ApiProblem) {
                    // Nothing to send after all (paid meanwhile): skip quietly.
                } catch (Throwable $e) {
                    report($e);
                    $failed = true;
                    $this->warn("#{$company->id} {$party->code}: {$e->getMessage()}");
                }
            }
            $this->line("#{$company->id} {$company->legal_name}: {$sent} reminder(s)".($this->option('dry-run') ? ' (dry run)' : ''));
        }
        $current->clear();

        return $failed ? self::FAILURE : self::SUCCESS;
    }
}
