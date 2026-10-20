<?php

namespace App\Models\Concerns;

use Illuminate\Support\Facades\Auth;

/** Fills created_by / updated_by from the authenticated user (04 §2 standard columns). */
trait TracksActor
{
    protected static function bootTracksActor(): void
    {
        static::creating(function ($model) {
            $id = Auth::id();
            if ($id) {
                $model->created_by ??= $id;
                $model->updated_by ??= $id;
            }
        });
        static::updating(function ($model) {
            if ($id = Auth::id()) {
                $model->updated_by = $id;
            }
        });
    }
}
