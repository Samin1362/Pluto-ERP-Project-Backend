<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Stored response of an idempotent request (BR-050). Scoped by company and user explicitly, not by global scope. */
class IdempotencyKey extends Model
{
    protected $guarded = ['id'];

    protected $casts = ['response_body' => 'array', 'locked_until' => 'datetime', 'expires_at' => 'datetime'];
}
