<?php

namespace Tests\Feature\Settings;

use App\Domain\Backups\BackupService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BooksAreConsistent;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * /backups (K15; FR-087; BR-062; GAP-22; R-11; TC-AUTH-023, TC-NFR-006; backlog P15.1-BE-09): a snapshot, then the
 * restore drill — posted vouchers made after the snapshot are gone, the books balance, audit logs stay.
 */
class BackupTest extends TestCase
{
    use BooksAreConsistent, BuildsMasterData;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
    }

    private function req(string $method, string $uri, array $data = [], ?string $token = null)
    {
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, $method === 'POST' ? ['Idempotency-Key' => (string) Str::uuid()] : []);
        $this->useBooks();

        return $r;
    }

    private function bank(): string
    {
        return (string) DB::table('journal_lines as l')->join('money_accounts as a', 'a.ledger_id', '=', 'l.ledger_id')->where('a.company_id', $this->books->id)
            ->where('l.is_posted', 1)->selectRaw('COALESCE(SUM(l.debit) - SUM(l.credit), 0) b')->value('b');
    }

    public function test_t_c_nfr_006_restore_drill_brings_the_books_back_to_the_snapshot(): void
    {
        $bank = $this->req('POST', '/api/v1/money-accounts', ['account_type' => 'bank', 'bank_name' => 'Sonali Bank', 'account_name' => 'Meghna Traders Ltd.',
            'account_number' => '0012345678', 'opening_balance' => '100000.00'])->assertCreated()->json('data');
        $b = $this->req('POST', '/api/v1/backups')->assertStatus(202)->assertJsonPath('data.kind', 'manual')->assertJsonPath('data.status', 'complete');
        $this->assertGreaterThan(0, $b->json('data.size_bytes'));
        $this->assertSame('owner@meghna.example', DB::table('users')->where('id', $b->json('data.created_by.id'))->value('email'));
        $snapshot = json_decode(gzdecode(Storage::disk('local')->get(DB::table('company_backups')->where('id', $b->json('data.id'))->value('storage_path'))), true);
        $this->assertArrayNotHasKey('users', $snapshot['tables'], 'no credentials (R-11)');
        $this->assertArrayNotHasKey('audit_logs', $snapshot['tables']);
        $this->assertNotEmpty($snapshot['tables']['journal_lines']);
        $this->assertNotEmpty($snapshot['tables']['role_permissions'], 'junction tables travel with their parents');

        // After the snapshot: a posted expense and a new customer.
        $rent = $this->req('POST', '/api/v1/ie-categories', ['kind' => 'expense', 'name' => 'Rent', 'ledger_id' => DB::table('ledgers')->where('company_id', $this->books->id)->where('code', '6101')->value('id')])->json('data');
        $this->req('POST', '/api/v1/ie-entries', ['kind' => 'expense', 'amount' => '30000.00', 'date' => now('Asia/Dhaka')->toDateString(), 'category_id' => $rent['id'],
            'money_account_id' => $bank['id'], 'method' => 'bank_transfer'])->assertCreated();
        $this->customer();
        $this->assertSame('70000.00', $this->bank());
        $auditsBefore = DB::table('audit_logs')->where('company_id', $this->books->id)->count();

        $this->userWithRole('finance');
        $this->assertProblem($this->req('POST', '/api/v1/backups/'.$b->json('data.id').'/restore', ['confirmation' => 'RESTORE'], $this->login('finance@meghna.example')), 403, 'permission_denied');
        $this->assertProblem($this->req('POST', '/api/v1/backups/'.$b->json('data.id').'/restore', ['confirmation' => 'restore']), 422, 'validation_failed')
            ->assertJsonPath('errors.confirmation.0', 'Type RESTORE to confirm');
        $this->req('POST', '/api/v1/backups/'.$b->json('data.id').'/restore', ['confirmation' => 'RESTORE'])->assertStatus(202);

        $this->assertSame('100000.00', $this->bank(), 'the expense made after the snapshot is gone');
        $this->assertSame(0, DB::table('parties')->where('company_id', $this->books->id)->count());
        $this->assertSame(0, DB::table('ie_categories')->where('company_id', $this->books->id)->count());
        $this->assertGreaterThan($auditsBefore, DB::table('audit_logs')->where('company_id', $this->books->id)->count(), 'audit logs are never rewound (R-11)');
        $kinds = array_column($this->req('GET', '/api/v1/backups')->assertOk()->json('data'), 'kind');
        $this->assertSame(['pre_restore', 'manual'], $kinds);
        $this->assertBooksAreConsistent();
        $this->req('GET', '/api/v1/company/settings')->assertOk(); // the company answers again
        $this->artisan('pluto:integrity')->assertSuccessful();

        // The safety snapshot can bring the expense back.
        $pre = $this->req('GET', '/api/v1/backups')->json('data.0.id');
        $this->req('POST', "/api/v1/backups/{$pre}/restore", ['confirmation' => 'RESTORE'])->assertStatus(202);
        $this->assertSame('70000.00', $this->bank());
    }

    public function test_download_link_retention_and_who_may_see_backups(): void
    {
        $id = $this->req('POST', '/api/v1/backups')->json('data.id');
        $url = $this->req('GET', "/api/v1/backups/{$id}/download")->assertOk()->json('data.url');
        $this->assertStringStartsWith("/api/v1/backups/{$id}/file", $url);
        $this->get($url)->assertOk()->assertHeader('Content-Type', 'application/gzip');
        $this->get("/api/v1/backups/{$id}/file")->assertForbidden();
        $this->assertTrue(DB::table('audit_logs')->where('action', 'backup_downloaded')->exists());

        $this->userWithRole('finance');
        $fin = $this->login('finance@meghna.example');
        $this->req('GET', '/api/v1/backups', token: $fin)->assertOk();
        $this->assertProblem($this->req('GET', "/api/v1/backups/{$id}/download", token: $fin), 403, 'permission_denied', 'snapshots hold the whole company: Owner only');
        $this->userWithRole('accountant');
        $this->assertProblem($this->req('GET', '/api/v1/backups', token: $this->login('accountant@meghna.example')), 403, 'permission_denied');

        DB::table('company_settings')->where('company_id', $this->books->id)->update(['backup_enabled' => true, 'backup_retention_days' => 7]);
        DB::table('company_backups')->where('company_id', $this->books->id)->update(['expires_at' => now()->addDays(7)]);
        $this->artisan('pluto:backups:run', ['--company' => $this->books->id])->expectsOutputToContain('complete')->assertSuccessful();
        $this->travel(8)->days();
        $this->assertSame(2, BackupService::prune());
        $this->assertSame(['expired', 'expired'], DB::table('company_backups')->where('company_id', $this->books->id)->orderBy('id')->pluck('status')->all());
        $this->assertProblem($this->req('GET', "/api/v1/backups/{$id}/download"), 409, 'invalid_transition');
    }
}
