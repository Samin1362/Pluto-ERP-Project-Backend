<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsPassword;
use App\Domain\Access\CompanyProvisioner;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Creates a company with the full 04 §11.2 bootstrap and its Owner (existing or new user). */
class CreateCompany extends Command
{
    use ReadsPassword;

    protected $signature = 'pluto:company:create {legal_name} {--owner-email=} {--owner-name=}';

    protected $description = 'Create a company (roles, chart of accounts, FY, number series) and its Owner';

    public function handle(CompanyProvisioner $provisioner): int
    {
        $email = Str::lower((string) $this->option('owner-email'));
        if (! filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $this->error('--owner-email must be a valid e-mail address');

            return self::INVALID;
        }
        $user = User::where('email', $email)->first();
        if (! $user) {
            $user = User::create(['name' => $this->option('owner-name') ?: $email, 'email' => $email,
                'password_hash' => Hash::make($this->readPassword('PLUTO_OWNER_PASSWORD')), 'status' => 'active']);
            $user->forceFill(['password_changed_at' => now()])->save();
        }
        $company = $provisioner->provision($this->argument('legal_name'), $user);
        $this->info("Company #{$company->id} \"{$company->legal_name}\" created; Owner: {$user->email}");

        return self::SUCCESS;
    }
}
