<?php

namespace App\Domain\Exports;

use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use ZipArchive;

/**
 * Export all my data (PEP-501; roadmap A6, owner decision 15 Nov 2026): everything one company owns, so a customer
 * can always leave with their books. A ZIP with one CSV per table holding only this company's rows (tables with
 * company_id, and their line tables reached through a parent row), the company profile, its members (name, e-mail,
 * role, status - never passwords or 2-step secrets) and its attachments. The tables are read from the database
 * itself, so a table added later is exported without a code change. Platform and security tables stay out.
 */
final class CompanyDataExporter
{
    /** Not the company's business data, or holding secrets, request replays or platform settings. */
    private const SKIP = ['idempotency_keys', 'user_sessions', 'password_reset_tokens', 'export_jobs', 'company_backups', 'company_users',
        'user_invitations', 'user_invitation_branches', 'support_access_grants', 'migrations', 'jobs', 'job_batches', 'failed_jobs', 'cache', 'cache_locks', 'sessions'];

    /** @return array{0: string, 1: string} file name, stored path */
    public function write(int $companyId, string $dir): array
    {
        $company = DB::table('companies')->where('id', $companyId)->first() ?? throw new RuntimeException('The company no longer exists.');
        $stamp = CarbonImmutable::now('Asia/Dhaka')->format('Y-m-d');
        $slug = trim((string) preg_replace('/[^a-z0-9]+/', '-', strtolower($company->trading_name ?: $company->legal_name)), '-') ?: 'company';
        $name = "pluto-{$slug}-all-data-{$stamp}.zip";
        Storage::disk('local')->makeDirectory($dir);
        $zipPath = Storage::disk('local')->path("{$dir}/{$name}");
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The export could not be written; try again.');
        }
        $tmp = [];
        $csv = function (string $file, iterable $rows, ?array $header = null) use ($zip, &$tmp, $dir): int {
            $path = Storage::disk('local')->path("{$dir}/".uniqid('part', true).'.csv');
            $h = fopen($path, 'w');
            fwrite($h, "\xEF\xBB\xBF"); // so Excel reads Bangla and the taka sign correctly
            $n = 0;
            foreach ($rows as $r) {
                $r = (array) $r;
                if ($n === 0) {
                    fputcsv($h, $header ?? array_keys($r));
                }
                fputcsv($h, array_map(fn ($v) => is_bool($v) ? (int) $v : $v, array_values($r)));
                $n++;
            }
            if ($n === 0 && $header) {
                fputcsv($h, $header);
            }
            fclose($h);
            $zip->addFile($path, $file);
            $tmp[] = $path;

            return $n;
        };

        $counts = [];
        $counts['company'] = $csv('company.csv', [$company]);
        $counts['members'] = $csv('members.csv', DB::table('company_users as m')->join('users as u', 'u.id', '=', 'm.user_id')
            ->leftJoin('roles as r', 'r.id', '=', 'm.role_id')->where('m.company_id', $companyId)->orderBy('m.id')
            ->get(['u.name', 'u.email', 'r.name as role', 'm.status', 'm.joined_via', 'm.joined_at']), ['name', 'email', 'role', 'status', 'joined_via', 'joined_at']);

        // Tables with company_id: this company's rows. Then tables without it that hang off an exported table (invoice
        // lines, role permissions, ...), as many levels as there are.
        $db = DB::getDatabaseName();
        $tenant = collect(DB::select("SELECT TABLE_NAME t FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = ? AND COLUMN_NAME = 'company_id'", [$db]))
            ->pluck('t')->reject(fn ($t) => in_array($t, self::SKIP, true) || $t === 'companies')->unique()->sort()->values();
        $parents = []; // table => query of its exported ids
        foreach ($tenant as $t) {
            $q = DB::table($t)->where('company_id', $companyId);
            if (self::hasId($t)) {
                $q->orderBy('id');
            }
            $counts[$t] = $csv("tables/{$t}.csv", $q->cursor());
            if (self::hasId($t)) {
                $parents[$t] = DB::table($t)->select('id')->where('company_id', $companyId);
            }
        }
        $fks = collect(DB::select('SELECT TABLE_NAME t, COLUMN_NAME c, REFERENCED_TABLE_NAME rt FROM information_schema.KEY_COLUMN_USAGE
            WHERE TABLE_SCHEMA = ? AND REFERENCED_TABLE_NAME IS NOT NULL', [$db]))->groupBy('t');
        for ($round = 0; $round < 4; $round++) {
            $added = false;
            foreach ($fks as $t => $links) {
                if (isset($counts[$t]) || in_array($t, self::SKIP, true) || $tenant->contains($t) || $t === 'companies') {
                    continue;
                }
                $via = $links->first(fn ($l) => isset($parents[$l->rt]));
                if (! $via) {
                    continue;
                }
                $ids = clone $parents[$via->rt];
                $counts[$t] = $csv("tables/{$t}.csv", DB::table($t)->whereIn($via->c, $ids)->cursor());
                if (self::hasId($t)) {
                    $parents[$t] = DB::table($t)->select('id')->whereIn($via->c, clone $parents[$via->rt]);
                }
                $added = true;
            }
            if (! $added) {
                break;
            }
        }

        // The company's attachments, under their own names.
        $files = 0;
        foreach (DB::table('attachments')->where('company_id', $companyId)->orderBy('id')->get() as $a) {
            $disk = Storage::disk($a->storage_disk ?: 'local');
            if ($disk->exists($a->storage_path)) {
                $zip->addFromString("attachments/{$a->id}-".preg_replace('/[^\w.\- ]+/u', '_', $a->original_name), (string) $disk->get($a->storage_path));
                $files++;
            }
        }

        ksort($counts);
        $lines = ["Pluto - all data of {$company->legal_name}", 'Exported '.CarbonImmutable::now('Asia/Dhaka')->format('j M Y, H:i').' (Dhaka time)', '',
            'company.csv     the company profile', 'members.csv     the people with access (no passwords)',
            'tables/*.csv    one file per kind of record, only this company\'s rows;', '                columns ending in _id point to the id column of another file',
            "attachments/    {$files} uploaded files, named <attachment id>-<file name>", '',
            'Money and quantities are plain numbers with a dot for decimals. Files are UTF-8 CSV.', '', 'Rows per file:'];
        foreach ($counts as $t => $n) {
            $lines[] = sprintf('  %-34s %d', $t, $n);
        }
        $zip->addFromString('README.txt', implode("\n", $lines)."\n");
        $zip->close();
        foreach ($tmp as $p) {
            @unlink($p);
        }

        return [$name, "{$dir}/{$name}"];
    }

    private static function hasId(string $table): bool
    {
        static $cache = [];

        return $cache[$table] ??= DB::getSchemaBuilder()->hasColumn($table, 'id');
    }
}
