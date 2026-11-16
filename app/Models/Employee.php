<?php

namespace App\Models;

use App\Models\Concerns\BelongsToCompany;
use App\Models\Concerns\HasLockVersion;
use App\Models\Concerns\TracksActor;
use Illuminate\Database\Eloquent\Model;

/** A person the company pays a salary (PEP-520). Gross = basic + house rent + medical + conveyance (EmployeeService). */
class Employee extends Model
{
    use BelongsToCompany, HasLockVersion, TracksActor;

    protected $guarded = ['id', 'company_id', 'code'];

    protected $casts = ['joining_date' => 'date', 'left_on' => 'date', 'lock_version' => 'integer'];
}
