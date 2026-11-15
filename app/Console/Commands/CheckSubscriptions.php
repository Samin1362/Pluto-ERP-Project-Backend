<?php

namespace App\Console\Commands;

use App\Domain\Platform\SubscriptionService;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;

/** Daily subscription check (PEP-503): reminders, suspension for payment, and a summary e-mail to the platform admins. */
class CheckSubscriptions extends Command
{
    protected $signature = 'pluto:subscriptions:check {--date= : As of this date (YYYY-MM-DD)} {--no-mail : Do not e-mail the summary}';

    protected $description = 'Remind Owners before their Pluto subscription is due, suspend companies unpaid past their grace days, e-mail the platform admins';

    public function handle(SubscriptionService $subscriptions): int
    {
        $r = $subscriptions->check($this->option('date') ? CarbonImmutable::parse($this->option('date')) : null);
        $this->info("Reminders: {$r['reminded']}; suspended: ".count($r['suspended']).'; overdue: '.count($r['overdue']).'; due soon: '.count($r['due_soon']));
        $to = (array) config('pluto.platform_admins', []);
        if ($to !== [] && ! $this->option('no-mail') && ($r['suspended'] || $r['overdue'] || $r['due_soon'])) {
            $part = fn (string $title, array $list) => $list ? "{$title}:\n- ".implode("\n- ", $list)."\n\n" : '';
            Mail::raw('Pluto subscriptions, '.CarbonImmutable::now('Asia/Dhaka')->format('j M Y')."\n\n"
                .$part('Suspended today for payment', $r['suspended']).$part('Overdue', $r['overdue']).$part('Due in the next 7 days', $r['due_soon'])
                .'Record payments in Platform console > Companies > Billing.', fn ($m) => $m->to($to)->subject('Pluto: subscriptions to follow up'));
        }

        return self::SUCCESS;
    }
}
