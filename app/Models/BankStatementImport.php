<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** An imported bank statement file (G10; CF-19): stored under its SHA-256, so the same file is refused twice (TC-BNK-003). */
class BankStatementImport extends Model
{
    use BelongsToCompany, TracksActor;

    protected $guarded = ['id', 'company_id'];

    protected $casts = ['period_from' => 'date', 'period_to' => 'date'];

    public function lines(): HasMany
    {
        return $this->hasMany(BankStatementLine::class)->orderBy('line_no');
    }
}
