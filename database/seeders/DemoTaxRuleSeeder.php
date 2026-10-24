<?php

namespace Database\Seeders;

use App\Models\Company;
use App\Models\Ledger;
use App\Models\TaxRule;
use App\Support\CurrentCompany;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Test tax rules for every company until Settings can create them (K6, P15; backlog P3.1-BE-01).
 * Local and staging only: `php artisan db:seed --class=DemoTaxRuleSeeder`. Rates are examples, never advice (BR-036).
 */
class DemoTaxRuleSeeder extends Seeder
{
    /** name => [rate, applies_to, ledger code, is_recoverable] */
    private const RULES = [
        'VAT 15%' => ['15.0000', 'sales', '2205', true],
        'VAT 7.5%' => ['7.5000', 'sales', '2205', true],
        'Input VAT 15%' => ['15.0000', 'purchases', '1402', true],
        'AIT 5%' => ['5.0000', 'purchases', '1402', false],
    ];

    public function run(): void
    {
        if (app()->environment('production')) {
            throw new RuntimeException('DemoTaxRuleSeeder never runs in production.');
        }
        $current = app(CurrentCompany::class);
        foreach (Company::all() as $company) {
            $current->set($company, null);
            try {
                foreach (self::RULES as $name => [$rate, $appliesTo, $ledgerCode, $recoverable]) {
                    $ledger = Ledger::where('code', $ledgerCode)->first();
                    if ($ledger && ! TaxRule::where('name', $name)->exists()) {
                        TaxRule::create(['name' => $name, 'rate' => $rate, 'applies_to' => $appliesTo, 'ledger_id' => $ledger->id,
                            'is_recoverable' => $recoverable, 'is_active' => true]);
                    }
                }
            } finally {
                $current->clear();
            }
        }
    }
}
