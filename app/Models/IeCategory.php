<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** An income or expense category (I7; FR-067): its own ledger, an optional monthly budget and the warning level. */
class IeCategory extends Model
{
    use BelongsToCompany, TracksActor;

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['is_active' => 'boolean'];

    public function ledger(): BelongsTo
    {
        return $this->belongsTo(Ledger::class);
    }
}
