<?php

namespace App\Support;

use App\Models\Company;
use App\Models\CompanyUser;
use LogicException;

/**
 * The company and membership of the current request (07 §4 step 4). Bound as a singleton and reset at the start
 * of every API request, so tenant scopes can never use a previous request's company.
 */
final class CurrentCompany
{
    private ?Company $company = null;

    private ?CompanyUser $membership = null;

    public function set(Company $company, ?CompanyUser $membership): void
    {
        $this->company = $company;
        $this->membership = $membership;
    }

    public function clear(): void
    {
        $this->company = null;
        $this->membership = null;
    }

    public function has(): bool
    {
        return $this->company !== null;
    }

    public function id(): int
    {
        return $this->company?->id ?? throw new LogicException('No company context: tenant data needs X-Company-Id (BR-037).');
    }

    public function company(): Company
    {
        return $this->company ?? throw new LogicException('No company context.');
    }

    public function membership(): ?CompanyUser
    {
        return $this->membership;
    }
}
