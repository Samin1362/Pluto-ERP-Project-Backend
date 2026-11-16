<?php

namespace App\Domain\Platform;

use App\Domain\Shared\AuditLogger;
use App\Models\Announcement;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;

/**
 * Announcements (PEP-513; owner request 15 Nov 2026): the software owner posts a message ("Update tonight 11 pm",
 * "New: LC items") that every person in every company sees as a banner until they close it. Times are stored in UTC.
 */
final class AnnouncementService
{
    public function __construct(private readonly AuditLogger $audit) {}

    /** @return list<array<string, mixed>> every announcement, newest first (platform console) */
    public function all(): array
    {
        return Announcement::orderByDesc('id')->get()->map(fn (Announcement $a) => self::resource($a))->all();
    }

    /** @return list<array<string, mixed>> what this person should see now */
    public function current(int $userId): array
    {
        $now = now();

        return Announcement::where('is_active', true)
            ->where(fn ($q) => $q->whereNull('starts_at')->orWhere('starts_at', '<=', $now))
            ->where(fn ($q) => $q->whereNull('ends_at')->orWhere('ends_at', '>', $now))
            ->whereNotExists(fn ($q) => $q->from('announcement_dismissals as d')->whereColumn('d.announcement_id', 'announcements.id')->where('d.user_id', $userId))
            ->orderByDesc('id')->get()->map(fn (Announcement $a) => self::resource($a))->all();
    }

    public function create(array $in): array
    {
        $a = DB::transaction(function () use ($in) {
            $a = Announcement::create(self::fields($in) + ['is_active' => $in['is_active'] ?? true]);
            $this->audit->record('created', $a, null, ['title' => $a->title, 'by' => 'platform'], type: 'announcements');

            return $a;
        });

        return self::resource($a->refresh());
    }

    public function update(int $id, array $in, int $version): array
    {
        $a = DB::transaction(function () use ($id, $in, $version) {
            $a = Announcement::query()->whereKey($id)->lockForUpdate()->first() ?? throw new ApiProblem('not_found');
            if ((int) $a->lock_version !== $version) {
                throw new ApiProblem('version_conflict');
            }
            $old = ['title' => $a->title, 'is_active' => (bool) $a->is_active];
            $a->update(self::fields($in) + (array_key_exists('is_active', $in) ? ['is_active' => (bool) $in['is_active']] : []));
            $this->audit->record('updated', $a, $old, ['title' => $a->title, 'is_active' => (bool) $a->is_active, 'by' => 'platform'], type: 'announcements');

            return $a;
        });

        return self::resource($a->refresh());
    }

    public function delete(int $id): void
    {
        DB::transaction(function () use ($id) {
            $a = Announcement::query()->whereKey($id)->lockForUpdate()->first() ?? throw new ApiProblem('not_found');
            $this->audit->record('deleted', $a, ['title' => $a->title], null, type: 'announcements');
            $a->delete();
        });
    }

    /** Closes it for this person; closing twice, or an announcement that is gone, is not an error. */
    public function dismiss(int $id, int $userId): void
    {
        if (Announcement::query()->whereKey($id)->exists()) {
            DB::table('announcement_dismissals')->insertOrIgnore(['announcement_id' => $id, 'user_id' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /** @return array<string, mixed> */
    private static function fields(array $in): array
    {
        $ts = fn (?string $v) => $v === null || $v === '' ? null : CarbonImmutable::parse($v)->utc();

        return ['title' => $in['title'], 'body' => $in['body'] ?? null, 'tone' => $in['tone'], 'starts_at' => $ts($in['starts_at'] ?? null), 'ends_at' => $ts($in['ends_at'] ?? null)];
    }

    /** @return array<string, mixed> Announcement */
    public static function resource(Announcement $a): array
    {
        return ['id' => $a->id, 'title' => $a->title, 'body' => $a->body, 'tone' => $a->tone, 'starts_at' => $a->starts_at?->toIso8601String(),
            'ends_at' => $a->ends_at?->toIso8601String(), 'is_active' => (bool) $a->is_active, 'created_at' => $a->created_at?->toIso8601String(), 'version' => (int) $a->lock_version];
    }
}
