<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/** Membership: one user in one company with exactly one role (07 §6.1). */
class CompanyUser extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $guarded = ['id', 'lock_version'];

    protected $casts = ['joined_at' => 'datetime', 'deactivated_at' => 'datetime'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class)->withoutGlobalScope('company');
    }

    public function branches(): BelongsToMany
    {
        return $this->belongsToMany(Branch::class, 'company_user_branches')->withoutGlobalScope('company');
    }
}
