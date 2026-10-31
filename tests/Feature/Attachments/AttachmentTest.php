<?php

namespace Tests\Feature\Attachments;

use App\Models\Attachment;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/** Attachments: /attachments (FR-066; BR-051, NFR-016; TC-BNK-009; backlog P13.1-BE-01). */
class AttachmentTest extends TestCase
{
    use BuildsMasterData;

    /** A 1×1 PNG. */
    private const PNG = 'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mP8z8BQDwAEhQGAhKmMIQAAAABJRU5ErkJggg==';

    private string $token;

    private array $bank;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
        $this->bank = $this->req('POST', '/api/v1/money-accounts', ['account_type' => 'bank', 'bank_name' => 'Sonali Bank', 'account_name' => 'Meghna Traders Ltd.',
            'account_number' => '0012345678', 'opening_balance' => '50000.00'])->assertCreated()->json('data');
    }

    private function req(string $method, string $uri, array $data = [], ?string $token = null)
    {
        $r = $this->api($method, $uri, $token ?? $this->token, $this->books->id, $data, $method === 'POST' ? ['Idempotency-Key' => (string) Str::uuid()] : []);
        $this->useBooks();

        return $r;
    }

    private function upload(string $name, string $content, string $type, ?int $id, ?string $token = null)
    {
        $data = ['attachable_type' => $type] + ($id ? ['attachable_id' => (string) $id] : []);
        $r = $this->call('POST', '/api/v1/attachments', $data, [], ['file' => UploadedFile::fake()->createWithContent($name, $content)],
            $this->transformHeadersToServerVars(['Authorization' => 'Bearer '.($token ?? $this->token), 'X-Company-Id' => (string) $this->books->id,
                'Accept' => 'application/json', 'Idempotency-Key' => (string) Str::uuid()]));
        $this->useBooks();

        return $r;
    }

    /** A posted payment voucher from the bank (a cash voucher: banking.* decides). */
    private function voucher(): int
    {
        $rent = DB::table('ledgers')->where('company_id', $this->books->id)->where('code', '6101')->value('id');

        return $this->req('POST', '/api/v1/vouchers', ['entry_type' => 'payment', 'action' => 'post', 'date' => now('Asia/Dhaka')->toDateString(),
            'payment' => ['money_account_id' => $this->bank['id'], 'method' => 'bank_transfer'], 'lines' => [['ledger_id' => $rent, 'amount' => '1500.00']]])
            ->assertCreated()->json('data.id');
    }

    public function test_t_c_bn_k_009_a_renamed_exe_and_an_11_mb_pdf_are_refused(): void
    {
        $v = $this->voucher();
        $this->assertProblem($this->upload('invoice.pdf', "MZ\x90\x00\x03\x00\x00\x00\x04\x00\x00\x00\xFF\xFF".str_repeat("\x00", 200).'This program cannot be run in DOS mode', 'voucher', $v), 415, 'file_type_not_allowed');
        $this->assertProblem($this->upload('scan.pdf', "%PDF-1.4\n".str_repeat('A', 11 * 1024 * 1024), 'voucher', $v), 413, 'file_too_large');
        $this->assertSame(0, Attachment::withoutGlobalScopes()->count());
        Storage::disk('local')->assertDirectoryEmpty('/');
    }

    public function test_a_png_is_stored_privately_listed_and_downloaded_through_a_signed_link(): void
    {
        $v = $this->voucher();
        $r = $this->upload('receipt.png', base64_decode(self::PNG), 'voucher', $v)->assertCreated();
        $r->assertJsonPath('data.mime_type', 'image/png')->assertJsonPath('data.original_name', 'receipt.png')->assertJsonPath('data.uploaded_by.name', 'Owner');
        $a = Attachment::findOrFail($r->json('data.id'));
        $this->assertMatchesRegularExpression('#^company/'.$this->books->id.'/\d{4}/\d{2}/[0-9a-f-]{36}\.png$#', $a->storage_path);
        $this->assertSame(hash('sha256', base64_decode(self::PNG)), $a->sha256);
        Storage::disk('local')->assertExists($a->storage_path);

        $this->req('GET', "/api/v1/attachments?attachable_type=voucher&attachable_id={$v}")->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $a->id);
        $url = $this->req('GET', "/api/v1/attachments/{$a->id}/url")->assertOk()->json('data.url');
        $this->assertStringStartsWith("/api/v1/attachments/{$a->id}/file?expires=", $url);
        $file = $this->get($url)->assertOk();
        $this->assertSame('image/png', $file->headers->get('Content-Type'));
        $this->assertSame(base64_decode(self::PNG), $file->streamedContent());
        $this->get(str_replace('signature=', 'signature=0', $url))->assertForbidden(); // a tampered link is refused
        $this->get("/api/v1/attachments/{$a->id}/file")->assertForbidden();

        $this->req('DELETE', "/api/v1/attachments/{$a->id}")->assertNoContent();
        Storage::disk('local')->assertMissing($a->storage_path);
        $this->assertDatabaseHas('audit_logs', ['action' => 'detached', 'auditable_type' => 'attachments', 'auditable_id' => $a->id]);
    }

    public function test_permission_follows_the_record_and_other_companies_are_not_found(): void
    {
        $v = $this->voucher();
        $this->userWithRole('viewer', 'viewer@meghna.example');
        $viewer = $this->login('viewer@meghna.example');
        $this->assertProblem($this->upload('receipt.png', base64_decode(self::PNG), 'voucher', $v, $viewer), 403, 'permission_denied');
        $id = $this->upload('receipt.png', base64_decode(self::PNG), 'voucher', $v)->assertCreated()->json('data.id');
        $this->req('GET', "/api/v1/attachments/{$id}/url", [], $viewer)->assertOk(); // banking.view reads it
        $this->assertProblem($this->req('DELETE', "/api/v1/attachments/{$id}", [], $viewer), 403, 'permission_denied');
        $this->assertProblem($this->upload('receipt.png', base64_decode(self::PNG), 'purchase_bill', 999999), 404, 'not_found');

        $other = $this->provisionedCompany('Padma Foods Ltd.', 'owner@padma.example');
        $t2 = $this->login('owner@padma.example');
        $this->api('GET', "/api/v1/attachments/{$id}/url", $t2, $other->id)->assertNotFound();
        $this->api('GET', "/api/v1/attachments?attachable_type=voucher&attachable_id={$v}", $t2, $other->id)->assertNotFound();
    }

    public function test_a_pending_upload_belongs_to_its_uploader_until_an_entry_links_it(): void
    {
        $r = $this->upload('bill.pdf', "%PDF-1.4\n1 0 obj << >> endobj\ntrailer << >>\n%%EOF", 'voucher', null)->assertCreated();
        $a = Attachment::findOrFail($r->json('data.id'));
        $this->assertTrue($a->isPending());
        $this->assertProblem($this->upload('bill.pdf', '%PDF-1.4', 'purchase_bill', null), 422, 'validation_failed');

        $this->userWithRole('accountant', 'accounts@meghna.example');
        $this->api('DELETE', "/api/v1/attachments/{$a->id}", $this->login('accounts@meghna.example'), $this->books->id)->assertNotFound();
        $this->useBooks();
        $this->req('DELETE', "/api/v1/attachments/{$a->id}")->assertNoContent(); // I6 · Remove attachment before saving

        $stale = $this->upload('old.pdf', '%PDF-1.4', 'voucher', null)->assertCreated()->json('data.id');
        Attachment::whereKey($stale)->update(['created_at' => now()->subDays(2)]);
        $this->artisan('pluto:recurring:run')->assertSuccessful();
        $this->assertNull(Attachment::withoutGlobalScopes()->find($stale));
    }
}
