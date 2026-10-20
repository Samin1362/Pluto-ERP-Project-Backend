<?php

namespace App\Models\Concerns;

/** Optimistic concurrency (BR-049): lock_version increases on every update and is exposed as `version` / ETag. */
trait HasLockVersion
{
    protected static function bootHasLockVersion(): void
    {
        static::updating(function ($model) {
            $model->lock_version = (int) $model->getOriginal('lock_version') + 1;
        });
    }

    public function etag(): string
    {
        return 'W/"'.$this->lock_version.'"';
    }
}
