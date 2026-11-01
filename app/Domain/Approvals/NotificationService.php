<?php

namespace App\Domain\Approvals;

use App\Domain\Access\PermissionResolver;
use App\Mail\NotificationMail;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Notifications (L3 bell; 05 notifications; FR-013; backlog P7.1-BE-05, P14.1-BE-04). Each user's switches for the
 * event (notification_preferences, K12) decide the channels: in-app unless switched off, e-mail (queued) only when
 * switched on. SMS is not sent in v1.
 */
final class NotificationService
{
    public function __construct(private readonly PermissionResolver $permissions) {}

    /** @param  list<int>  $userIds */
    public function notify(int $companyId, array $userIds, string $eventKey, string $title, ?string $body, string $category = 'info', ?string $linkType = null, ?int $linkId = null, ?string $url = null): void
    {
        $userIds = array_values(array_unique(array_map('intval', $userIds)));
        if ($userIds === []) {
            return;
        }
        $prefs = DB::table('notification_preferences')->where('company_id', $companyId)->where('event_key', $eventKey)->whereIn('user_id', $userIds)->get()->keyBy('user_id');
        $inApp = array_values(array_filter($userIds, fn ($u) => ! isset($prefs[$u]) || $prefs[$u]->in_app));
        $rows = array_map(fn ($u) => ['company_id' => $companyId, 'user_id' => $u, 'event_key' => $eventKey, 'title' => mb_substr($title, 0, 190),
            'body' => $body !== null ? mb_substr($body, 0, 500) : null, 'category' => $category, 'link_type' => $linkType, 'link_id' => $linkId,
            'data' => $url ? json_encode(['url' => $url]) : null, 'created_at' => now(), 'updated_at' => now()], $inApp);
        if ($rows !== []) {
            DB::table('notifications')->insert($rows);
        }
        $email = array_values(array_filter($userIds, fn ($u) => isset($prefs[$u]) && $prefs[$u]->email));
        if ($email !== []) {
            $company = Company::find($companyId);
            foreach (User::whereIn('id', $email)->where('status', 'active')->get() as $user) {
                Mail::to($user->email)->queue(new NotificationMail($title, $user->name, array_values(array_filter([$body])),
                    $url ? config('pluto.frontend_url').$url : null, (string) ($company?->trading_name ?: $company?->legal_name)));
            }
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
