<?php

namespace Database\Seeders;

use App\Domain\Access\RoleTemplates;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/** Global reference data for every environment (04 §11.1): currencies and the permission catalogue. Idempotent. */
class ReferenceDataSeeder extends Seeder
{
    public function run(): void
    {
        $currencies = [['BDT', 'Bangladeshi taka', '৳'], ['USD', 'US dollar', '$'], ['EUR', 'Euro', '€'], ['CNY', 'Chinese yuan', '¥'],
            ['INR', 'Indian rupee', '₹'], ['GBP', 'Pound sterling', '£'], ['SGD', 'Singapore dollar', 'S$'], ['JPY', 'Japanese yen', '¥']];
        foreach ($currencies as [$code, $name, $symbol]) {
            DB::table('currencies')->updateOrInsert(['code' => $code], ['name' => $name, 'symbol' => $symbol, 'minor_units' => $code === 'JPY' ? 0 : 2]);
        }
        foreach (RoleTemplates::PERMISSIONS as $key) {
            [$module, $action] = explode('.', $key, 2);
            DB::table('permissions')->updateOrInsert(['key' => $key], [
                'module' => $module, 'action' => $action,
                'description' => ucfirst(str_replace('_', ' ', $action)).' '.str_replace('_', ' ', $module),
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
    }
}
