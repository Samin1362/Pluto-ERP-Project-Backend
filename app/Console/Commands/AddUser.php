<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\ReadsPassword;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/** Adds a user to a company with one of its roles (stand-in for the K10 invitation flow, not part of this slice). */
class AddUser extends Command
{
    use ReadsPassword;

    protected $signature = 'pluto:user:add {company_id} {email} {--name=} {--role=sales : owner|finance|accountant|sales|store|viewer}';

    protected $description = 'Add an active user to a company with a role';

    public function handle(CurrentCompany $current): int
    {
        $company = Company::findOrFail((int) $this->argument('company_id'));
        $current->set($company, null);
        $role = Role::where('slug', $this->option('role'))->first();
        if (! $role) {
            $this->error('Unknown role '.$this->option('role'));

            return self::INVALID;
        }
        $email = Str::lower($this->argument('email'));
        $user = User::where('email', $email)->first() ?? tap(User::create(['name' => $this->option('name') ?: $email, 'email' => $email,
            'password_hash' => Hash::make($this->readPassword('PLUTO_USER_PASSWORD')), 'status' => 'active']),
            fn ($u) => $u->forceFill(['password_changed_at' => now()])->save());
        CompanyUser::updateOrCreate(['user_id' => $user->id], ['role_id' => $role->id, 'status' => 'active', 'joined_at' => now()]);
        $this->info("{$email} is {$role->name} in \"{$company->legal_name}\"");

        return self::SUCCESS;
    }
}
