<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;

/** Unit of measure (pcs, kg, bag…); `decimals` is how many decimal places its quantities allow. */
class Unit extends Model
{
    use BelongsToCompany, TracksActor;

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['decimals' => 'integer'];
}
