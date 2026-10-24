<?php

namespace App\Console\Commands;

use App\Domain\Access\CompanyProvisioner;
use App\Models\Company;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;

/**
 * Adds defaults that later phases introduced to companies created before them; safe to run again.
 * Today: the default units of measure (P3.1-BE-01). New companies get them from CompanyProvisioner.
 */
class SeedCompanyDefaults extends Command
{
    protected $signature = 'pluto:company:defaults {company_id? : Only this company (default: all)}';

    protected $description = 'Add missing defaults (units of measure) to existing companies';

    public function handle(CompanyProvisioner $provisioner, CurrentCompany $current): int
    {
        $companies = Company::query()->when($this->argument('company_id'), fn ($q, $id) => $q->whereKey($id))->get();
        if ($companies->isEmpty()) {
            $this->error('No such company');

            return self::FAILURE;
        }
        foreach ($companies as $company) {
            $current->set($company, null);
            try {
                $added = $provisioner->seedDefaultUnits();
            } finally {
                $current->clear();
            }
            $this->info("Company #{$company->id} \"{$company->legal_name}\": {$added} unit(s) added");
        }

        return self::SUCCESS;
    }
}
