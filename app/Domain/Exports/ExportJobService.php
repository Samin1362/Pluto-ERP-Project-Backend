<?php

namespace App\Domain\Exports;

use App\Domain\Access\JwtService;
use App\Domain\Access\PermissionResolver;
use App\Jobs\RunExportJob;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\ExportJob;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use App\Support\Reports\ReportExporter;
use Carbon\CarbonImmutable;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;
use RuntimeException;
use Throwable;
use ZipArchive;

/**
 * Export jobs (05 POST/GET /export-jobs; FR-076, FR-087; NFR-006; backlog P14.1-BE-05). A job is queued, then runs its
 * source as the user who asked — an internal request to the report or list endpoint with that user's session, so the
 * same permissions and masking apply (and a signed-out session stops the job). It writes:
 *  - report: one report as xlsx, csv or pdf;
 *  - list: every page of a list as xlsx or csv (one row per record, nested names flattened);
 *  - export_pack: the month's reports (financial statements by default) as xlsx, pdf or both, zipped.
 * The file sits on the private disk; its signed link and the file itself expire 24 hours after the job finishes.
 */
final class ExportJobService
{
    private const LIST_PAGES = 200; // 20,000 rows

    public function __construct(private readonly CurrentCompany $current, private readonly PermissionResolver $permissions, private readonly ReportExporter $exporter) {}

    public function create(array $in, CompanyUser $member, int $sessionId): ExportJob
    {
        $kind = $in['kind'];
        $format = $in['format'];
        $allowed = ['report' => ['xlsx', 'csv', 'pdf'], 'list' => ['xlsx', 'csv'], 'export_pack' => ['xlsx', 'pdf', 'both']][$kind];
        if (! in_array($format, $allowed, true)) {
            throw new ApiProblem('validation_failed', errors: ['format' => ['Choose '.implode(', ', $allowed).' for this export']]);
        }
        $sources = match ($kind) {
            'report' => [$this->source($in['source'] ?? null, ExportSources::REPORTS)],
            'list' => [$this->source($in['source'] ?? null, ExportSources::LISTS)],
            'export_pack' => array_map(fn ($s) => $this->source($s, ExportSources::REPORTS, 'reports'), $in['reports'] ?? ExportSources::PACK_DEFAULT),
        };
        foreach ($sources as $s) {
            $permission = $this->permissionOf(ExportSources::REPORTS[$s][0] ?? ExportSources::LISTS[$s][0]);
            if ($permission && ! $this->permissions->allows($member, $permission)) {
                throw new ApiProblem('permission_denied', "This export needs the {$permission} permission.");
            }
        }
        $job = ExportJob::create(['kind' => $kind, 'status' => 'queued', 'parameters' => [
            'format' => $format, 'source' => $kind === 'export_pack' ? null : $sources[0], 'reports' => $kind === 'export_pack' ? $sources : null,
            'period' => $in['period'] ?? null, 'filters' => array_map(fn ($v) => is_bool($v) ? ($v ? 'true' : 'false') : (string) $v, array_filter($in['filters'] ?? [], fn ($v) => $v !== null && $v !== '')),
            'session_id' => $sessionId,
        ]]);
        RunExportJob::dispatch($job->id);

        return $job->refresh();
    }

    /** @param  array<string, array{0: string, 1: string}>  $map */
    private function source(?string $id, array $map, string $field = 'source'): string
    {
        if ($id === null || ! isset($map[$id])) {
            throw new ApiProblem('validation_failed', errors: [$field => ['Choose one of: '.implode(', ', array_keys($map))]]);
        }

        return $id;
    }

    /** The `perm:` middleware of the endpoint, if it has one (others check inside; the internal request still enforces them). */
    private function permissionOf(string $path): ?string
    {
        $route = Route::getRoutes()->match(Request::create('/api/v1'.$path, 'GET'));
        foreach ($route->gatherMiddleware() as $m) {
            if (is_string($m) && str_starts_with($m, 'perm:')) {
                return substr($m, 5);
            }
        }

        return null;
    }

    /** ExportJob (05 schema) */
    public function resource(ExportJob $job): array
    {
        $p = $job->parameters;
        $expires = $job->finished_at ? CarbonImmutable::parse($job->finished_at)->addHours(ExportJob::TTL_HOURS) : null;
        $live = $job->status === 'complete' && $job->file_path && $expires?->isFuture();

        return ['id' => $job->id, 'kind' => $job->kind, 'status' => $job->status,
            'download_url' => $live ? URL::temporarySignedRoute('export-jobs.file', $expires, ['id' => $job->id], absolute: false) : null,
            'expires_at' => $expires?->toIso8601String(), 'finished_at' => $job->finished_at?->toIso8601String(), 'error' => $job->error,
            'source' => $p['source'] ?? ($job->kind === 'export_pack' ? 'month_end_pack' : ($p['report'] ?? null)), 'format' => $p['format'] ?? null,
            'file_name' => $p['file_name'] ?? null, 'created_at' => $job->created_at?->toIso8601String()];
    }

    /** The queued work. Runs outside any request; marks the job failed with a readable reason on any error. */
    public function run(int $id): void
    {
        $job = ExportJob::withoutGlobalScopes()->find($id);
        if (! $job || $job->status !== 'queued') {
            return;
        }
        $job->forceFill(['status' => 'running'])->save();
        $dir = "exports/company/{$job->company_id}/".Str::uuid();
        $previous = $this->current->has() ? [$this->current->company(), $this->current->membership()] : null;
        try {
            $user = User::findOrFail($job->created_by);
            // The worker has no request, so set the job's company for the whole run: the internal calls restore it
            // after each request, and the PDF writer needs it for the letterhead.
            $this->current->set(Company::findOrFail($job->company_id),
                CompanyUser::withoutGlobalScopes()->where('company_id', $job->company_id)->where('user_id', $user->id)->first());
            $p = $job->parameters;
            $call = fn (string $path, array $query, bool $page = false) => $this->call($user, (int) $p['session_id'], (int) $job->company_id, $path, $query, $page);
            [$name, $files] = match ($job->kind) {
                'report' => $this->report($call, $p, $dir),
                'list' => $this->list($call, $p, $dir),
                'export_pack' => $this->pack($call, $p, $dir),
            };
            $p['file_name'] = $name;
            $job->forceFill(['status' => 'complete', 'file_path' => $files, 'finished_at' => now(), 'parameters' => $p, 'error' => null])->save();
        } catch (Throwable $e) {
            Storage::disk('local')->deleteDirectory($dir);
            if (! $e instanceof ApiProblem && ! $e instanceof RuntimeException) {
                report($e);
            }
            $job->forceFill(['status' => 'failed', 'finished_at' => now(), 'error' => mb_substr($e instanceof RuntimeException || $e instanceof ApiProblem ? $e->getMessage() : 'The export could not be prepared; try again.', 0, 500)])->save();
        } finally {
            $previous ? $this->current->set(...$previous) : $this->current->clear();
        }
    }

    private function report(callable $call, array $p, string $dir): array
    {
        [$path, $file] = ExportSources::REPORTS[$p['source']];
        $report = $call($path, $p['filters'] ?? []);
        $name = $file.'-'.self::stamp($report).'.'.$p['format'];
        $this->writeReport($report, $p['format'], "{$dir}/{$name}");

        return [$name, "{$dir}/{$name}"];
    }

    private function list(callable $call, array $p, string $dir): array
    {
        [$path, $file] = ExportSources::LISTS[$p['source']];
        $rows = [];
        for ($page = 1; $page <= self::LIST_PAGES; $page++) {
            $res = $call($path, [...($p['filters'] ?? []), 'page' => (string) $page, 'per_page' => '100'], true);
            array_push($rows, ...array_map(fn ($r) => self::flatten($r), $res['data']));
            if ($page >= (int) ($res['meta']['last_page'] ?? 1)) {
                break;
            }
        }
        $name = $file.'-'.CarbonImmutable::now('Asia/Dhaka')->format('Ymd-Hi').'.'.$p['format'];
        $abs = $this->absolute("{$dir}/{$name}");
        $writer = $p['format'] === 'xlsx' ? new XlsxWriter : new CsvWriter;
        $writer->openToFile($abs);
        $keys = array_keys(array_merge(...($rows ?: [[]])));
        $writer->addRow(Row::fromValues(array_map(fn ($k) => Str::headline($k), $keys), (new Style)->setFontBold()));
        foreach ($rows as $r) {
            $writer->addRow(Row::fromValues(array_map(fn ($k) => $r[$k] ?? null, $keys)));
        }
        $writer->close();

        return [$name, "{$dir}/{$name}"];
    }

    private function pack(callable $call, array $p, string $dir): array
    {
        $month = CarbonImmutable::parse(($p['period'] ?? CarbonImmutable::now('Asia/Dhaka')->subMonth()->format('Y-m')).'-01');
        $range = ['date_from' => $month->toDateString(), 'date_to' => $month->endOfMonth()->toDateString()];
        $name = 'month-end-pack-'.$month->format('Y-m').'.zip';
        $zipPath = $this->absolute("{$dir}/{$name}");
        $zip = new ZipArchive;
        if ($zip->open($zipPath, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
            throw new RuntimeException('The pack could not be written; try again.');
        }
        $parts = [];
        foreach ($p['reports'] as $source) {
            [$path, $file] = ExportSources::REPORTS[$source];
            $report = $call($path, in_array($source, ExportSources::RANGE_REPORTS, true) ? $range : ['as_of' => $range['date_to']]);
            foreach ($p['format'] === 'both' ? ['xlsx', 'pdf'] : [$p['format']] as $f) {
                $part = "{$dir}/parts/{$file}-{$month->format('Y-m')}.{$f}";
                $this->writeReport($report, $f, $part);
                $zip->addFile($this->absolute($part), basename($part));
                $parts[] = $part;
            }
        }
        $zip->close();
        Storage::disk('local')->deleteDirectory("{$dir}/parts");

        return [$name, "{$dir}/{$name}"];
    }

    private function writeReport(array $report, string $format, string $relative): void
    {
        $this->exporter->save($report, $format, $this->absolute($relative));
    }

    private function absolute(string $relative): string
    {
        Storage::disk('local')->makeDirectory(dirname($relative));

        return Storage::disk('local')->path($relative);
    }

    /**
     * GET the endpoint as the requesting user (their session, the job's company) through the HTTP kernel, so route
     * middleware — permission, company, validation — applies exactly as for the SPA.
     */
    private function call(User $user, int $sessionId, int $companyId, string $path, array $query, bool $page = false): array
    {
        $token = app(JwtService::class)->issue($user, $sessionId)['access_token'];
        $request = Request::create('/api/v1'.$path, 'GET', $query, [], [], ['HTTP_AUTHORIZATION' => "Bearer {$token}", 'HTTP_X_COMPANY_ID' => (string) $companyId,
            'HTTP_ACCEPT' => 'application/json', 'REMOTE_ADDR' => '127.0.0.1']);
        $app = app();
        $previous = [$app->bound('request') ? $app->make('request') : null, Auth::guard('api')->user(), $this->current->has() ? [$this->current->company(), $this->current->membership()] : null];
        try {
            $response = $app->make(Kernel::class)->handle($request);
        } finally {
            if ($previous[0]) {
                $app->instance('request', $previous[0]);
            }
            $previous[1] ? Auth::guard('api')->setUser($previous[1]) : Auth::guard('api')->forgetUser();
            $previous[2] ? $this->current->set(...$previous[2]) : $this->current->clear();
        }
        $body = json_decode((string) $response->getContent(), true);
        if ($response->getStatusCode() !== 200 || ! is_array($body)) {
            throw new RuntimeException(match ($response->getStatusCode()) {
                401 => 'Your session ended before the export ran; sign in and export again.',
                403 => 'You do not have permission to export this.',
                422 => 'The filters were not accepted: '.implode(' ', array_map(fn ($m) => implode(' ', (array) $m), $body['errors'] ?? [])),
                default => $body['title'] ?? 'The export could not be prepared; try again.',
            });
        }

        return $page ? $body : $body['data'];
    }

    /** One list record as a flat row: nested objects by their name (or number, code), lists by their count. */
    private static function flatten(array $r): array
    {
        $out = [];
        foreach ($r as $k => $v) {
            if (is_array($v)) {
                if (array_is_list($v)) {
                    $out[$k] = count($v);
                } else {
                    $out[$k] = $v['name'] ?? $v['number'] ?? $v['code'] ?? $v['label'] ?? null;
                }
            } else {
                $out[$k] = is_bool($v) ? ($v ? 'Yes' : 'No') : $v;
            }
        }

        return $out;
    }

    private static function stamp(array $report): string
    {
        $p = $report['period'] ?? [];

        return str_replace('-', '', (string) ($p['as_of'] ?? $p['to'] ?? $p['date_to'] ?? CarbonImmutable::now('Asia/Dhaka')->toDateString()));
    }

    /** Removes the files of jobs finished more than 24 hours ago. @return int files removed */
    public static function prune(): int
    {
        $n = 0;
        ExportJob::withoutGlobalScopes()->whereNotNull('file_path')->where('finished_at', '<', now()->subHours(ExportJob::TTL_HOURS))->orderBy('id')
            ->each(function (ExportJob $j) use (&$n) {
                Storage::disk('local')->deleteDirectory(dirname($j->file_path));
                $j->forceFill(['file_path' => null])->save();
                $n++;
            });

        return $n;
    }
}
