<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Append-only audit trail (NFR-021): never updated or deleted by the application. */
class AuditLog extends Model
{
    public const UPDATED_AT = null;

    protected $guarded = ['id'];

    protected $casts = ['old_values' => 'array', 'new_values' => 'array'];

    protected static function booted(): void
    {
        static::updating(fn () => throw new \LogicException('audit_logs is append-only'));
        static::deleting(fn () => throw new \LogicException('audit_logs is append-only'));
    }
}
