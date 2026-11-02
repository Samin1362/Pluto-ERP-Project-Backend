<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;

/** Period-end revaluation of open foreign-currency items (06 §12.13). */
class FxRevaluation extends Model
{
    use BelongsToCompany, TracksActor;

    protected $table = 'fx_revaluations';

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['total_difference' => AsMoney::class, 'rates' => 'array', 'revaluation_date' => 'date', 'reversal_date' => 'date'];
}
