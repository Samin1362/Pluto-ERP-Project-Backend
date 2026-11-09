<?php

namespace Tests\Feature\Platform;

use App\Domain\Exports\ExportSources;
use App\Models\ExportJob;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Symfony\Component\Yaml\Yaml;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;
use ZipArchive;

/**
 * POST /export-jobs, GET /export-jobs/{id} and the signed file (J1, K15, list pages; FR-076, FR-087; NFR-006; backlog
 * P14.1-BE-05). Tests run the queue synchronously, so a job is complete when POST answers.
 */
class ExportJobTest extends TestCase
{
    use BuildsMasterData;

    private string $token;

    private array $invoice;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $wh = $this->warehouse();
        $oil = $this->product('Soybean oil 5L jar', 'jar', '940.00');
        $today = CarbonImmutable::now('Asia/Dhaka')->toDateString();
        $this->postDoc('purchase-bills', ['party_id' => $this->supplier()->id, 'supplier_bill_no' => 'BEO-1', 'bill_date' => $today, 'warehouse_id' => $wh->id,
            'lines' => [['product_id' => $oil->id, 'quantity' => '100', 'unit_cost' => '800.00']]]);
        $this->invoice = $this->postDoc('sales-invoices', ['party_id' => $this->customer()->id, 'invoice_date' => $today, 'warehouse_id' => $wh->id,
            'lines' => [['product_id' => $oil->id, 'quantity' => '20']]]);
    }

    private function req(string $method, string $uri, array $data = [], ?string $token = null)
    {
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, $method === 'POST' ? ['Idempotency-Key' => (string) Str::uuid()] : []);
        $this->useBooks();

        return $r;
    }

    private function postDoc(string $resource, array $body): array
    {
        $d = $this->req('POST', "/api/v1/{$resource}", $body)->assertCreated()->json('data');

        return $this->req('POST', "/api/v1/{$resource}/{$d['id']}/post")->assertOk()->json('data');
    }

    private function download(string $url)
    {
        return $this->get($url);
    }

    public function test_a_report_export_runs_as_the_user_and_its_file_is_behind_a_signed_link(): void
    {
        $r = $this->req('POST', '/api/v1/export-jobs', ['kind' => 'report', 'format' => 'xlsx', 'source' => 'getReportsProfitAndLoss',
            'filters' => ['detail' => 'totals']])->assertStatus(202);
        $r->assertJsonPath('data.kind', 'report')->assertJsonPath('data.status', 'complete')->assertJsonPath('data.source', 'getReportsProfitAndLoss')
            ->assertJsonPath('data.format', 'xlsx')->assertJsonPath('data.error', null);
        $this->assertMatchesRegularExpression('/^profit-and-loss-\d{8}\.xlsx$/', $r->json('data.file_name'));
        $this->assertStringStartsWith('/api/v1/export-jobs/', $r->json('data.download_url'));
        $id = $r->json('data.id');
        $this->assertStringEndsWith("/api/v1/export-jobs/{$id}", $r->headers->get('Location'));
        $this->assertTrue(CarbonImmutable::parse($r->json('data.expires_at'))->greaterThan(now()->addHours(23)));

        $file = $this->download($r->json('data.download_url'))->assertOk();
        $this->assertStringContainsString('spreadsheetml', $file->headers->get('Content-Type'));
        $this->assertStringContainsString($r->json('data.file_name'), $file->headers->get('Content-Disposition'));
        $this->get("/api/v1/export-jobs/{$id}/file")->assertForbidden();

        $this->req('GET', "/api/v1/export-jobs/{$id}")->assertOk()->assertJsonPath('data.status', 'complete');
        $this->userWithRole('accountant');
        $this->assertProblem($this->req('GET', "/api/v1/export-jobs/{$id}", token: $this->login('accountant@meghna.example')), 404, 'not_found');

        // 24 hours on, the link is gone and the hourly prune removes the file.
        $this->travel(25)->hours();
        $this->req('GET', "/api/v1/export-jobs/{$id}")->assertJsonPath('data.download_url', null);
        $path = ExportJob::find($id)->file_path;
        $this->artisan('pluto:exports:prune')->expectsOutputToContain('Removed 1 export file')->assertSuccessful();
        Storage::disk('local')->assertMissing($path);
    }

    public function test_a_list_export_has_every_page_and_a_failed_source_says_why(): void
    {
        $r = $this->req('POST', '/api/v1/export-jobs', ['kind' => 'list', 'format' => 'csv', 'source' => 'getSalesInvoices', 'filters' => ['status' => 'posted']])->assertStatus(202);
        $r->assertJsonPath('data.status', 'complete');
        $csv = $this->download($r->json('data.download_url'))->assertOk()->streamedContent();
        $this->assertStringContainsString('Number', strtok($csv, "\n"));
        $this->assertStringContainsString($this->invoice['number'], $csv);
        $this->assertStringContainsString('Karim Distributors', $csv, 'the party flattened to its name');

        $bad = $this->req('POST', '/api/v1/export-jobs', ['kind' => 'list', 'format' => 'xlsx', 'source' => 'getSalesInvoices', 'filters' => ['status' => 'shipped']])->assertStatus(202);
        $bad->assertJsonPath('data.status', 'failed')->assertJsonPath('data.download_url', null);
        $this->assertStringStartsWith('The filters were not accepted', $bad->json('data.error'));
    }

    public function test_the_month_end_pack_zips_the_statements_in_both_formats(): void
    {
        $month = CarbonImmutable::now('Asia/Dhaka')->format('Y-m');
        $r = $this->req('POST', '/api/v1/export-jobs', ['kind' => 'export_pack', 'format' => 'both', 'period' => $month])->assertStatus(202);
        $r->assertJsonPath('data.status', 'complete')->assertJsonPath('data.kind', 'export_pack')->assertJsonPath('data.file_name', "month-end-pack-{$month}.zip");
        $zip = new ZipArchive;
        $this->assertTrue($zip->open(Storage::disk('local')->path(ExportJob::find($r->json('data.id'))->file_path)));
        $names = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $names[] = $zip->getNameIndex($i);
        }
        $this->assertCount(12, $names);
        $this->assertContains("trial-balance-{$month}.pdf", $names);
        $this->assertContains("profit-and-loss-{$month}.xlsx", $names);
    }

    public function test_the_pack_runs_in_a_queue_worker_with_no_company_context(): void
    {
        // A real worker has no request: the job must set its own company (the PDF letterhead needs it).
        $month = CarbonImmutable::now('Asia/Dhaka')->format('Y-m');
        $done = ExportJob::find($this->req('POST', '/api/v1/export-jobs', ['kind' => 'export_pack', 'format' => 'pdf', 'period' => $month])->assertStatus(202)->json('data.id'));
        $copy = $done->replicate();
        $copy->forceFill(['status' => 'queued', 'file_path' => null, 'finished_at' => null, 'error' => null])->save();
        $id = $copy->id;
        app(\App\Support\CurrentCompany::class)->clear();
        app(\App\Domain\Exports\ExportJobService::class)->run($id);
        $job = ExportJob::withoutGlobalScopes()->find($id);
        $this->assertSame('complete', $job->status, (string) $job->error);
        $this->assertFalse(app(\App\Support\CurrentCompany::class)->has());
    }

    public function test_validation_permissions_and_rate_limit(): void
    {
        $this->assertProblem($this->req('POST', '/api/v1/export-jobs', ['kind' => 'report', 'format' => 'xlsx', 'source' => 'getUsers']), 422, 'validation_failed')
            ->assertJsonStructure(['errors' => ['source']]);
        $this->assertProblem($this->req('POST', '/api/v1/export-jobs', ['kind' => 'list', 'format' => 'pdf', 'source' => 'getParties']), 422, 'validation_failed')
            ->assertJsonStructure(['errors' => ['format']]);
        $this->assertProblem($this->req('POST', '/api/v1/export-jobs', ['kind' => 'export_pack', 'format' => 'xlsx', 'period' => '2026-13-01']), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/export-jobs', ['kind' => 'report', 'format' => 'xlsx']), 422, 'validation_failed');
        $this->assertProblem($this->req('POST', '/api/v1/export-jobs', ['kind' => 'report', 'format' => 'xlsx', 'source' => 'getReportsSales', 'colour' => 'red']), 422, 'validation_failed');

        $this->userWithRole('sales');
        $this->assertProblem($this->req('POST', '/api/v1/export-jobs', ['kind' => 'report', 'format' => 'xlsx', 'source' => 'getReportsSales'], $this->login('sales@meghna.example')),
            403, 'permission_denied');
        $this->userWithRole('accountant');
        $acc = $this->login('accountant@meghna.example');
        $this->req('POST', '/api/v1/export-jobs', ['kind' => 'list', 'format' => 'xlsx', 'source' => 'getVouchers'], $acc)->assertStatus(202)->assertJsonPath('data.status', 'complete');

        for ($i = 0; $i < 30; $i++) {
            $this->req('POST', '/api/v1/export-jobs', ['kind' => 'report'], $acc);
        }
        $this->assertProblem($this->req('POST', '/api/v1/export-jobs', ['kind' => 'report'], $acc), 429, 'rate_limited');
    }

    public function test_every_export_source_is_a_get_endpoint_of_the_contract(): void
    {
        $paths = [];
        foreach (Yaml::parseFile(base_path('docs/openapi.yaml'))['paths'] as $path => $ops) {
            if (isset($ops['get']['operationId'])) {
                $paths[$ops['get']['operationId']] = $path;
            }
        }
        foreach ([...ExportSources::REPORTS, ...ExportSources::LISTS] as $op => [$path]) {
            $this->assertSame('/api/v1'.$path, $paths[$op] ?? null, $op);
        }
        $this->assertSame([], array_diff(ExportSources::PACK_DEFAULT, array_keys(ExportSources::REPORTS)));
        $this->assertSame([], array_diff(ExportSources::RANGE_REPORTS, array_keys(ExportSources::REPORTS)));
        $this->assertGreaterThan(0, DB::table('permissions')->where('key', 'reports.export')->count());
    }
}
