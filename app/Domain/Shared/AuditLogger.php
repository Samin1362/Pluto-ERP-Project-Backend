<?php

namespace App\Domain\Shared;

use App\Http\Middleware\RequestId;
use App\Models\AuditLog;
use App\Support\CurrentCompany;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Auth;

/** Writes the append-only audit trail (NFR-021) for business changes and security events (07 §11). */
final class AuditLogger
{
    public function __construct(private readonly CurrentCompany $current) {}

    public function record(string $action, ?Model $subject = null, ?array $old = null, ?array $new = null,
        ?int $userId = null, ?int $companyId = null, ?string $type = null): void
    {
        $request = request();
        AuditLog::create([
            'company_id' => $companyId ?? ($this->current->has() ? $this->current->id() : null),
            'user_id' => $userId ?? Auth::id(),
            'action' => $action,
            'auditable_type' => $type ?? ($subject ? $subject->getTable() : 'security'),
            'auditable_id' => $subject?->getKey(),
            'old_values' => $old,
            'new_values' => $new,
            'ip_address' => $request?->ip(),
            'user_agent' => substr((string) $request?->userAgent(), 0, 255) ?: null,
            'request_id' => $request ? RequestId::current($request) : null,
        ]);
    }
}
