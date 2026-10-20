<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Global catalogue of permission keys (module.action). */
class Permission extends Model
{
    protected $guarded = ['id'];
}
