<?php

namespace App\Models;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A person who can sign in (global, not tenant-scoped). Membership of companies is in company_users. */
class User extends Model implements Authenticatable
{
    use \Illuminate\Auth\Authenticatable;

    protected $fillable = ['name', 'email', 'phone', 'password_hash', 'status'];

    protected $hidden = ['password_hash', 'two_factor_secret', 'two_factor_recovery_codes'];

    protected $casts = [
        'email_verified_at' => 'datetime', 'two_factor_confirmed_at' => 'datetime', 'password_changed_at' => 'datetime',
        'last_login_at' => 'datetime', 'locked_until' => 'datetime', 'failed_login_count' => 'integer',
        // 07 §5: TOTP secret and recovery-code digests are encrypted with APP_KEY
        'two_factor_secret' => 'encrypted', 'two_factor_recovery_codes' => 'encrypted:array',
    ];

    public function getAuthPassword(): string
    {
        return (string) $this->password_hash;
    }

    public function getRememberTokenName(): ?string
    {
        return null; // stateless API: no remember-me column
    }

    public function memberships(): HasMany
    {
        return $this->hasMany(CompanyUser::class)->withoutGlobalScope('company');
    }

    public function sessions(): HasMany
    {
        return $this->hasMany(UserSession::class);
    }

    /** Password version claim (`pwv`, 07 §3.1): changes whenever the password hash changes (BR-061). */
    public function passwordVersion(): string
    {
        return substr(hash('sha256', (string) $this->password_hash), 0, 16);
    }
}
