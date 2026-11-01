<?php

namespace App\Models;

use App\Casts\AsMoney;
use App\Casts\AsQuantity;
use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;

/** Shipment lot of an LC (M4 Shipment tab). */
class LcShipment extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $guarded = ['id', 'company_id', 'lock_version'];

    protected $casts = ['quantity' => AsQuantity::class, 'value_foreign' => AsMoney::class, 'etd' => 'date', 'eta' => 'date', 'shipped_on' => 'date', 'arrived_on' => 'date'];
}
