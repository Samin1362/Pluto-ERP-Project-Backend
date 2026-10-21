<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** A financial year (Bangladesh default July–June) with its monthly accounting periods (04 §6.1). */
class FiscalYear extends Model
{
    use BelongsToCompany, TracksActor;

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['start_date' => 'date', 'end_date' => 'date', 'closed_at' => 'datetime'];

    public function periods(): HasMany
    {
        return $this->hasMany(AccountingPeriod::class)->orderBy('start_date');
    }
}
