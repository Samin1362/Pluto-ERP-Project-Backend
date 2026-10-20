<?php

namespace App\Models\Concerns;

use App\Models\Company;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Row-level tenancy (07 §7, BR-037): every query is filtered by the current company and every insert gets it.
 * A query without a company context throws, so tenant data is never read unscoped by accident.
 * Cross-company access in system code must say so explicitly with withoutGlobalScope('company').
 */
trait BelongsToCompany
{
    protected static function bootBelongsToCompany(): void
    {
        static::addGlobalScope('company', function (Builder $query) {
            $query->where($query->getModel()->qualifyColumn('company_id'), app(CurrentCompany::class)->id());
        });
        static::creating(function ($model) {
            if (! $model->company_id) {
                $model->company_id = app(CurrentCompany::class)->id();
            }
        });
    }

    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }
}
