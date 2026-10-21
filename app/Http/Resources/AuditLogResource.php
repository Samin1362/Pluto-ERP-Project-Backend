<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/** AuditLog entry (05 schemas). Secret-looking keys are removed from the old/new values before they leave the API. */
class AuditLogResource extends JsonResource
{
    private const SECRET = '/(password|secret|token|recovery|otp|api_key)/i';

    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'user' => $this->user ? ['id' => $this->user->id, 'name' => $this->user->name] : null,
            'old_values' => self::clean($this->old_values),
            'new_values' => self::clean($this->new_values),
            'ip_address' => $this->ip_address,
            'created_at' => $this->created_at->toIso8601String(),
        ];
    }

    private static function clean(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }
        $out = [];
        foreach ($values as $k => $v) {
            if (preg_match(self::SECRET, (string) $k)) {
                continue;
            }
            $out[$k] = is_array($v) ? self::clean($v) : $v;
        }

        return $out;
    }
}
