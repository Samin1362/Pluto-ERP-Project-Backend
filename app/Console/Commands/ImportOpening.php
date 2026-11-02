<?php

namespace App\Console\Commands;

use App\Domain\Migration\OpeningImportService;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Auth;
use RuntimeException;

/**
 * Go-live data migration (backlog P17.1-BE-05; runbook docs/runbooks/opening-data-import.md):
 *   php artisan pluto:import:opening customers storage/app/import/customers.csv --dry-run
 *   php artisan pluto:import:opening customers storage/app/import/customers.csv --errors=storage/app/import/customers-errors.csv
 * Acts as the company's owner (audit rows name them). Exit code 1 when any row is refused; nothing is then saved.
 */
class ImportOpening extends Command
{
    protected $signature = 'pluto:import:opening {type : customers, suppliers, ledgers, banks or stock} {file : CSV laid out like docs/import-templates/<type>.csv}
        {--company= : company id (default: the only company)} {--user= : acting user e-mail (default: the owner)} {--dry-run : check every row, save nothing}
        {--errors= : write the error report to this CSV}';

    protected $description = 'Import opening balances or opening stock from a CSV (all rows or none; BR-028)';

    public function handle(OpeningImportService $import, CurrentCompany $current): int
    {
        $companies = Company::query()->pluck('id');
        $companyId = (int) ($this->option('company') ?: ($companies->count() === 1 ? $companies->first() : 0));
        $company = Company::find($companyId);
        if (! $company) {
            $this->error('Choose the company with --company=<id> ('.$companies->implode(', ').').');

            return self::FAILURE;
        }
        $current->set($company, null);
        $member = CompanyUser::query()->when($this->option('user'), fn ($q, $email) => $q->where('user_id', User::where('email', $email)->value('id')),
            fn ($q) => $q->whereHas('role', fn ($r) => $r->where('slug', 'owner')))->where('status', 'active')->first();
        if (! $member) {
            $this->error('No active acting member: pass --user=<e-mail> of an active member.');

            return self::FAILURE;
        }
        $current->set($company, $member);
        Auth::setUser(User::findOrFail($member->user_id));

        try {
            $r = $import->import((string) $this->argument('type'), (string) $this->argument('file'), (bool) $this->option('dry-run'));
        } catch (RuntimeException $e) {
            $this->error($e->getMessage());

            return self::FAILURE;
        } finally {
            $current->clear();
        }

        $this->line(sprintf('%s: %d rows, %d %s, %d errors.', $r['type'], $r['rows'], $r['would_import'],
            $r['dry_run'] ? 'would be imported (dry run, nothing saved)' : ($r['errors'] ? 'passed, but nothing saved because of the errors' : 'imported'), count($r['errors'])));
        foreach (array_slice($r['errors'], 0, 20) as $e) {
            $this->line("  row {$e['row']}".($e['column'] ? " [{$e['column']}]" : '').": {$e['message']}");
        }
        if (count($r['errors']) > 20) {
            $this->line('  … '.(count($r['errors']) - 20).' more'.($this->option('errors') ? ' in the error report' : '; use --errors=<file> for all'));
        }
        if ($this->option('errors') && $r['errors']) {
            $h = fopen((string) $this->option('errors'), 'w');
            fputcsv($h, ['row', 'column', 'message']);
            foreach ($r['errors'] as $e) {
                fputcsv($h, [$e['row'], $e['column'], $e['message']]);
            }
            fclose($h);
            $this->line('Error report: '.$this->option('errors'));
        }

        return $r['errors'] ? self::FAILURE : self::SUCCESS;
    }
}
