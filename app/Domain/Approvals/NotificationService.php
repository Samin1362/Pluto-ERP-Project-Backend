<?php

namespace App\Domain\Approvals;

use App\Domain\Access\PermissionResolver;
use App\Models\CompanyUser;
use Illuminate\Support\Facades\DB;

/** In-app notifications (L3 bell; 05 notifications; FR-013; backlog P7.1-BE-05). */
final class NotificationService
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    /** @param  list<int>  $userIds */
    public function notify(int $companyId, array $userIds, string $eventKey, string $title, ?string $body, string $category = 'info', ?string $linkType = null, ?int $linkId = null): void
    {
        $rows = array_map(fn ($u) => ['company_id' => $companyId, 'user_id' => $u, 'event_key' => $eventKey, 'title' => mb_substr($title, 0, 190),
            'body' => $body !== null ? mb_substr($body, 0, 500) : null, 'category' => $category, 'link_type' => $linkType, 'link_id' => $linkId,
            'created_at' => now(), 'updated_at' => now()], array_values(array_unique($userIds)));
        if ($rows !== []) {
            DB::table('notifications')->insert($rows);
        }
    }

    /** @return list<int> active members of the current company holding `$permission`, except `$exceptUserId` */
    public function holders(string $permission, ?int $exceptUserId = null): array
    {
        return CompanyUser::where('status', 'active')->get()
            ->filter(fn (CompanyUser $m) => $m->user_id !== $exceptUserId && $this->permissions->allows($m, $permission))
            ->map(fn (CompanyUser $m) => (int) $m->user_id)->values()->all();
    }
}
