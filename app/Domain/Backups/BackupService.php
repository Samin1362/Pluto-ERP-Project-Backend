<?php

namespace App\Domain\Backups;

use App\Domain\Shared\AuditLogger;
use App\Models\Company;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use RuntimeException;
use Throwable;

/**
 * Per-company logical backups (K15; FR-087; BR-062; GAP-22; R-11; 11 §8.2; backlog P15.1-BE-09).
 *
 * A snapshot is every row of the company in every tenant table (those with company_id, plus the junction tables that
 * hang off them), as gzipped JSON on the private disk, with its SHA-256. Left out: users and their credentials (R-11),
 * audit logs (never deleted or rewound), idempotency keys, notifications, export jobs and the backup history itself.
 * Tables come from database/schema-contract.json, so a new table is covered without code changes.
 *
 * Restore (Owner, typed RESTORE) first takes a `pre_restore` snapshot, then — with the company answering 503
 * `company_maintenance` — replaces its rows with the snapshot's in one transaction: children before parents on delete,
 * parents first on insert. Posted journals may only be deleted for this company while @pluto_restore_company names it
 * (database trigger, 2026_11_04_000002). A membership whose user no longer exists is skipped.
 */
final class BackupService
{
    private const SKIP = ['audit_logs', 'idempotency_keys', 'notifications', 'export_jobs', 'company_backups'];

    public function __construct(private readonly CurrentCompany $current, private readonly AuditLogger $audit) {}

    /** @return list<array{table: string, level: int, scope: string, parent?: string, generated: list<string>}> tenant tables in insert order */
    public static function tables(): array
    {
        static $tables = null;
        if ($tables !== null) {
            return $tables;
        }
        $schema = json_decode((string) file_get_contents(database_path('schema-contract.json')), true)['tables'];
        $tenant = array_keys(array_filter($schema, fn ($t) => collect($t['columns'])->contains('name', 'company_id')));
        $tables = [];
        foreach ($schema as $name => $t) {
            if (in_array($name, self::SKIP, true) || $name === 'companies') {
                continue;
            }
            $generated = collect($t['columns'])->filter(fn ($c) => $c['generated'] !== null)->pluck('name')->all();
            if (in_array($name, $tenant, true)) {
                $tables[] = ['table' => $name, 'level' => $t['level'], 'scope' => 'company_id', 'generated' => $generated];

                continue;
            }
            // A junction or child without company_id: scoped through its foreign key to a tenant table.
            foreach ($t['foreign'] as $fk) {
                $ref = $fk['references_table'] ?? null;
                $col = $fk['column'] ?? null;
                if ($ref && $col && in_array($ref, $tenant, true) && ! in_array($ref, self::SKIP, true)) {
                    $tables[] = ['table' => $name, 'level' => $t['level'], 'scope' => $col, 'parent' => $ref, 'generated' => $generated];
                    break;
                }
            }
        }
        usort($tables, fn ($a, $b) => [$a['level'], $a['table']] <=> [$b['level'], $b['table']]);

        return $tables;
    }

    /** Backup (05 schema). */
    public static function resource(object $b): array
    {
        $by = $b->created_by ? DB::table('users')->where('id', $b->created_by)->first(['id', 'name']) : null;

        return ['id' => $b->id, 'kind' => $b->kind, 'status' => $b->status, 'size_bytes' => $b->size_bytes !== null ? (int) $b->size_bytes : null,
            'started_at' => CarbonImmutable::parse($b->started_at)->toIso8601String(), 'finished_at' => $b->finished_at ? CarbonImmutable::parse($b->finished_at)->toIso8601String() : null,
            'created_by' => $by ? ['id' => $by->id, 'name' => $by->name] : null];
    }

    /** Takes a snapshot of the current company now. @return int backup id */
    public function snapshot(string $kind, ?int $userId = null): int
    {
        $c = $this->current->id();
        $retention = (int) (DB::table('company_settings')->where('company_id', $c)->value('backup_retention_days') ?: 30);
        $id = DB::table('company_backups')->insertGetId(['company_id' => $c, 'kind' => $kind, 'status' => 'running', 'started_at' => now(),
            'expires_at' => now()->addDays($retention), 'created_by' => $userId, 'updated_by' => $userId, 'created_at' => now(), 'updated_at' => now()]);
        $this->audit->record('backup_started', null, null, ['backup_id' => $id, 'kind' => $kind], userId: $userId, type: 'company_backups');
        $path = "backups/company/{$c}/".now()->format('Ymd-His')."-{$kind}-".Str::lower(Str::random(6)).'.json.gz';
        try {
            $data = ['format' => 1, 'company_id' => $c, 'taken_at' => now()->toIso8601String(), 'company' => (array) DB::table('companies')->find($c), 'tables' => []];
            foreach (self::tables() as $t) {
                $data['tables'][$t['table']] = $this->rows($t, $c)->map(fn ($r) => array_diff_key((array) $r, array_flip($t['generated'])))->all();
            }
            $gz = gzencode((string) json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION), 6);
            Storage::disk('local')->put($path, $gz);
            DB::table('company_backups')->where('id', $id)->update(['status' => 'complete', 'storage_path' => $path, 'size_bytes' => strlen($gz), 'sha256' => hash('sha256', $gz),
                'finished_at' => now(), 'updated_at' => now()]);
        } catch (Throwable $e) {
            report($e);
            DB::table('company_backups')->where('id', $id)->update(['status' => 'failed', 'error' => mb_substr($e->getMessage(), 0, 500), 'finished_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }

    private function rows(array $t, int $companyId)
    {
        $q = DB::table($t['table']);
        if ($t['scope'] === 'company_id') {
            return $q->where('company_id', $companyId)->get();
        }

        return $q->whereIn($t['scope'], DB::table($t['parent'])->where('company_id', $companyId)->select('id'))->get();
    }

    /** SignedUrl: 5 minutes, relative (it works through the proxy). Downloading is audited (R-11). */
    public function downloadUrl(int $id): array
    {
        $b = DB::table('company_backups')->where('company_id', $this->current->id())->where('id', $id)->first() ?? throw new ApiProblem('not_found');
        if ($b->status !== 'complete' || ! $b->storage_path) {
            throw new ApiProblem('invalid_transition', 'Only a complete backup can be downloaded.');
        }
        $expires = now()->addMinutes(5);
        $this->audit->record('backup_downloaded', null, null, ['backup_id' => $id], type: 'company_backups');

        return ['url' => URL::temporarySignedRoute('backups.file', $expires, ['id' => $id], absolute: false), 'expires_at' => $expires->toIso8601String()];
    }

    /** Replaces the current company's data with backup `$id` (after a pre_restore snapshot). @return int the pre_restore backup id */
    public function restore(int $id, int $userId): int
    {
        $c = $this->current->id();
        $b = DB::table('company_backups')->where('company_id', $c)->where('id', $id)->first() ?? throw new ApiProblem('not_found');
        if ($b->status !== 'complete' || ! $b->storage_path || ! Storage::disk('local')->exists($b->storage_path)) {
            throw new ApiProblem('invalid_transition', 'This backup cannot be restored: it is not complete or its file has expired.');
        }
        $gz = Storage::disk('local')->get($b->storage_path);
        if (hash('sha256', $gz) !== $b->sha256) {
            throw new ApiProblem('invalid_transition', 'The backup file does not match its checksum; it was not restored.');
        }
        $data = json_decode((string) gzdecode($gz), true);
        if (($data['format'] ?? null) !== 1 || (int) $data['company_id'] !== $c) {
            throw new ApiProblem('invalid_transition', 'This file is not a backup of this company.');
        }
        $pre = $this->snapshot('pre_restore', $userId);
        if (DB::table('company_backups')->where('id', $pre)->value('status') !== 'complete') {
            throw new RuntimeException('The safety snapshot before the restore failed; nothing was changed.');
        }
        Cache::put("company:{$c}:restoring", true, now()->addMinutes(30));
        try {
            DB::transaction(function () use ($c, $data) {
                DB::statement('SET @pluto_restore_company = ?', [$c]);
                DB::statement('SET FOREIGN_KEY_CHECKS = 0');
                try {
                    foreach (array_reverse(self::tables()) as $t) {
                        $t['scope'] === 'company_id' ? DB::table($t['table'])->where('company_id', $c)->delete()
                            : DB::table($t['table'])->whereIn($t['scope'], DB::table($t['parent'])->where('company_id', $c)->select('id'))->delete();
                    }
                    $users = DB::table('users')->pluck('id')->flip();
                    foreach (self::tables() as $t) {
                        $rows = $data['tables'][$t['table']] ?? [];
                        if ($t['table'] === 'company_users') {
                            $rows = array_values(array_filter($rows, fn ($r) => isset($users[$r['user_id']])));
                        }
                        foreach (array_chunk($rows, 500) as $chunk) {
                            DB::table($t['table'])->insert(array_map(fn ($r) => array_diff_key($r, array_flip($t['generated'])), $chunk));
                        }
                    }
                    $profile = array_diff_key($data['company'], array_flip(['id', 'status', 'seat_limit', 'created_at']));
                    DB::table('companies')->where('id', $c)->update([...$profile, 'lock_version' => DB::raw('lock_version + 1'), 'updated_at' => now()]);
                } finally {
                    DB::statement('SET FOREIGN_KEY_CHECKS = 1');
                    DB::statement('SET @pluto_restore_company = NULL');
                }
            });
        } finally {
            Cache::forget("company:{$c}:restoring");
        }
        Cache::forget('perm:all');
        $this->audit->record('restored', null, null, ['backup_id' => $id, 'taken_at' => $data['taken_at'], 'pre_restore_backup_id' => $pre], userId: $userId, type: 'company_backups');

        return $pre;
    }

    /** Removes expired backup files (retention per company) and marks them expired. @return int removed */
    public static function prune(): int
    {
        $n = 0;
        foreach (DB::table('company_backups')->where('status', 'complete')->whereNotNull('expires_at')->where('expires_at', '<', now())->get() as $b) {
            if ($b->storage_path) {
                Storage::disk('local')->delete($b->storage_path);
            }
            DB::table('company_backups')->where('id', $b->id)->update(['status' => 'expired', 'storage_path' => null, 'updated_at' => now()]);
            $n++;
        }

        return $n;
    }

    public static function company(int $id): Company
    {
        return Company::findOrFail($id);
    }
}
