<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Refresh-token session per device (07 §3.2). */
class UserSession extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['last_seen_at' => 'datetime', 'expires_at' => 'datetime', 'revoked_at' => 'datetime'];
}
