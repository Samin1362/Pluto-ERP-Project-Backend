<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

/** `php artisan db:seed` loads reference data only; companies are created with `php artisan pluto:company:create`. */
class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call(ReferenceDataSeeder::class);
    }
}
