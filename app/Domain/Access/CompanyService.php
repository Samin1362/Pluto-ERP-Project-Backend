<?php

namespace App\Domain\Access;

use App\Domain\Accounting\LedgerService;
use App\Domain\Shared\AuditLogger;
use App\Models\AccountGroup;
use App\Models\Branch;
use App\Models\Company;
use App\Models\CompanySetting;
use App\Models\CompanyUser;
use App\Models\Ledger;
use App\Models\NumberSequence;
use App\Models\TaxRule;
use App\Models\User;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

/**
 * Company settings (K1–K7, S0; FR-005, FR-080–FR-085; BR-035, BR-036, BR-006; backlog P15.1-BE-01..03): create a
 * company, its profile and logo, locations, settings, number series and tax rules. Every change is audited and
 * version-checked (BR-049).
 */
final class CompanyService
{
    /** The settings only the Owner may change (05 PATCH /company/settings). */
    public const OWNER_SETTINGS = ['require_two_factor', 'idle_timeout_minutes', 'backup_enabled', 'backup_retention_days'];

    private const INVOICE_COLUMNS = ['template' => 'invoice_template', 'accent_color' => 'invoice_accent_color', 'show_logo' => 'invoice_show_logo',
        'show_words' => 'invoice_show_words', 'show_bank' => 'invoice_show_bank', 'show_signature' => 'invoice_show_signature', 'footer_note' => 'invoice_footer_note'];

    public function __construct(private readonly CurrentCompany $current, private readonly CompanyProvisioner $provisioner, private readonly AuditLogger $audit,
        private readonly LedgerService $ledgers) {}

    /** POST /companies: the creator becomes its Owner; chart, year, series and defaults are seeded in one transaction. */
    public function create(array $in, User $owner): Company
    {
        $company = $this->provisioner->provision($in['legal_name'], $owner, null, array_filter([
            'trading_name' => $in['trading_name'] ?? null, 'business_type' => $in['business_type'], 'fy_start_month' => $in['fy_start_month'] ?? null,
            'base_currency_code' => $in['base_currency_code'] ?? null,
        ], fn ($v) => $v !== null));
        $this->current->set($company, null); // the request has no X-Company-Id; the answer describes the new company
        $this->audit->record('created', $company, new: ['legal_name' => $company->legal_name], userId: $owner->id, companyId: $company->id, type: 'companies');

        return $company;
    }

    /** Company (05 schema): profile, locations, seats and a 1-day signed logo link. */
    public function resource(Company $c): array
    {
        return ['id' => $c->id, 'legal_name' => $c->legal_name, 'trading_name' => $c->trading_name, 'business_type' => $c->business_type,
            'trade_licence_no' => $c->trade_licence_no, 'tax_registration_no' => $c->tax_registration_no, 'incorporated_on' => $c->incorporated_on ? substr((string) $c->incorporated_on, 0, 10) : null,
            'registered_address' => $c->registered_address, 'phone' => $c->phone, 'email' => $c->email, 'website' => $c->website,
            'logo_url' => $c->logo_path ? URL::temporarySignedRoute('company.logo', now()->addDay(), ['id' => $c->id, 'v' => $c->lock_version], absolute: false) : null,
            'base_currency_code' => $c->base_currency_code, 'fy_start_month' => (int) $c->fy_start_month, 'timezone' => $c->timezone,
            'branches' => Branch::where('company_id', $c->id)->orderByDesc('is_head_office')->orderBy('name')->get()->map(fn (Branch $b) => self::branch($b))->all(),
            'seats_used' => self::seatsUsed($c->id), 'seat_limit' => (int) $c->seat_limit, 'version' => (int) $c->lock_version];
    }

    public static function branch(Branch $b): array
    {
        return ['id' => $b->id, 'code' => $b->code, 'name' => $b->name, 'kind' => $b->kind, 'address' => $b->address, 'phone' => $b->phone,
            'is_head_office' => (bool) $b->is_head_office, 'is_active' => (bool) $b->is_active, 'version' => (int) $b->lock_version];
    }

    /** Seats taken (BR-059): active and invited members plus invitations still open. */
    public static function seatsUsed(int $companyId): int
    {
        // PEP-496: Pluto support joined from the platform console is not the customer's seat.
        return DB::table('company_users')->where('company_id', $companyId)->whereIn('status', ['active', 'invited'])->where('joined_via', 'member')->count()
            + DB::table('user_invitations')->where('company_id', $companyId)->whereNull('accepted_at')->whereNull('revoked_at')->where('expires_at', '>', now())->count();
    }

    public function update(array $in, int $version): Company
    {
        return DB::transaction(function () use ($in, $version) {
            $c = Company::whereKey($this->current->id())->lockForUpdate()->firstOrFail();
            $this->assertVersion($c->lock_version, $version);
            $old = $c->only(array_keys($in));
            $c->fill($in)->save();
            $this->audit->record('updated', $c, $old, $c->only(array_keys($in)), type: 'companies');

            return $c;
        });
    }

    /** PUT /company/logo: PNG or SVG up to 1 MB, content-sniffed (BR-051); replaces the old file. */
    public function setLogo(UploadedFile $file, int $version): Company
    {
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($file->getRealPath()) ?: '';
        $ext = match ($mime) {
            'image/png' => 'png', 'image/svg+xml', 'image/svg' => 'svg', default => null
        };
        if ($ext === 'svg' && preg_match('/<script|on[a-z]+\s*=|javascript:/i', (string) file_get_contents($file->getRealPath()))) {
            $ext = null; // an SVG that carries script is refused, not cleaned
        }
        if ($ext === null) {
            throw new ApiProblem('file_type_not_allowed', 'The logo must be a PNG or SVG image.');
        }
        if ($file->getSize() > 1024 * 1024) {
            throw new ApiProblem('file_too_large', 'The logo can be up to 1 MB.');
        }

        return DB::transaction(function () use ($file, $ext, $version) {
            $c = Company::whereKey($this->current->id())->lockForUpdate()->firstOrFail();
            $this->assertVersion($c->lock_version, $version);
            $path = "company/{$c->id}/logo/".Str::uuid().".{$ext}";
            Storage::disk('local')->put($path, (string) file_get_contents($file->getRealPath()));
            $old = $c->logo_path;
            $c->forceFill(['logo_path' => $path])->save();
            if ($old) {
                DB::afterCommit(fn () => Storage::disk('local')->delete($old));
            }
            $this->audit->record('logo_changed', $c, type: 'companies');

            return $c;
        });
    }

    public function removeLogo(): void
    {
        $c = Company::findOrFail($this->current->id());
        if ($c->logo_path) {
            Storage::disk('local')->delete($c->logo_path);
            $c->forceFill(['logo_path' => null])->save();
            $this->audit->record('logo_removed', $c, type: 'companies');
        }
    }

    public function saveBranch(array $in, ?int $id = null, ?int $version = null): Branch
    {
        return DB::transaction(function () use ($in, $id, $version) {
            $b = $id ? Branch::whereKey($id)->lockForUpdate()->firstOrFail() : new Branch;
            if ($id) {
                $this->assertVersion($b->lock_version, (int) $version);
            }
            if (($in['is_head_office'] ?? false) === true) {
                Branch::where('is_head_office', true)->when($id, fn ($q) => $q->whereKeyNot($id))->update(['is_head_office' => false]);
            } elseif ($id && $b->is_head_office && array_key_exists('is_head_office', $in)) {
                throw new ApiProblem('validation_failed', errors: ['is_head_office' => ['Choose another head office first']]);
            }
            if ($id && $b->is_head_office && ($in['is_active'] ?? true) === false) {
                throw new ApiProblem('validation_failed', errors: ['is_active' => ['The head office cannot be closed']]);
            }
            $old = $id ? $b->only(array_keys($in)) : null;
            $b->fill($in)->save();
            $this->audit->record($id ? 'updated' : 'created', $b, $old, $b->only(array_keys($in)), type: 'branches');

            return $b->refresh();
        });
    }

    /** PATCH /company/settings: partial; the Owner-only fields need the Owner role; requiring 2-step needs it on the Owner's own account first. */
    public function updateSettings(array $in, int $version, CompanyUser $member, User $user): CompanySetting
    {
        $ownerFields = array_intersect(array_keys($in), self::OWNER_SETTINGS);
        if ($ownerFields !== [] && $member->role?->slug !== 'owner') {
            throw new ApiProblem('permission_denied', 'Only an Owner can change '.implode(', ', $ownerFields).'.');
        }
        if (($in['require_two_factor'] ?? false) === true && $user->two_factor_confirmed_at === null) {
            throw new ApiProblem('validation_failed', errors: ['require_two_factor' => ['Turn on 2-step verification for your own account first, so you are not locked out']]);
        }

        return DB::transaction(function () use ($in, $version) {
            $s = CompanySetting::lockForUpdate()->firstOrFail();
            $this->assertVersion($s->lock_version, $version);
            $row = array_diff_key($in, ['invoice' => true]);
            foreach ($in['invoice'] ?? [] as $k => $v) {
                $row[self::INVOICE_COLUMNS[$k]] = $v;
            }
            $old = $s->only(array_keys($row));
            $s->fill($row)->save();
            $this->audit->record('updated', $s, $old, $s->only(array_keys($row)), type: 'company_settings');

            return $s->refresh();
        });
    }

    /** NumberSequence (05 schema). */
    public static function sequence(NumberSequence $s): array
    {
        return ['id' => $s->id, 'document_type' => $s->document_type, 'prefix' => $s->prefix, 'next_number' => (int) $s->next_number, 'padding' => (int) $s->padding,
            'reset_policy' => $s->reset_policy, 'preview' => $s->prefix.str_pad((string) $s->next_number, (int) $s->padding, '0', STR_PAD_LEFT), 'version' => (int) $s->lock_version];
    }

    /** Every series in use now: the current financial year's and the never-reset ones (GAP-08). */
    public function sequences(): array
    {
        $today = now('Asia/Dhaka')->toDateString();
        $fy = DB::table('fiscal_years')->where('company_id', $this->current->id())->where('start_date', '<=', $today)->where('end_date', '>=', $today)->value('id');

        return NumberSequence::where(fn ($q) => $q->whereNull('fiscal_year_id')->when($fy, fn ($q) => $q->orWhere('fiscal_year_id', $fy)))
            ->orderBy('document_type')->get()->map(fn (NumberSequence $s) => self::sequence($s))->all();
    }

    /** PATCH /number-sequences/{id}: never below the next unused number (BR-006: numbers are never reused). */
    public function updateSequence(int $id, array $in, int $version): NumberSequence
    {
        return DB::transaction(function () use ($id, $in, $version) {
            $s = NumberSequence::whereKey($id)->lockForUpdate()->firstOrFail();
            $this->assertVersion($s->lock_version, $version);
            if (isset($in['next_number']) && $in['next_number'] < $s->next_number) {
                throw new ApiProblem('validation_failed', errors: ['next_number' => ['Numbers up to '.($s->next_number - 1).' are already used; the next can only go up']]);
            }
            $old = $s->only(array_keys($in));
            $s->fill($in)->save();
            $this->audit->record('updated', $s, $old, $s->only(array_keys($in)), type: 'number_sequences');

            return $s->refresh();
        });
    }

    /** POST/PATCH tax rules (BR-036: the rate is the user's; CON-5: none seeded). A new ledger goes under Tax payable/receivable's group. */
    public function saveTaxRule(array $in, ?int $id = null, ?int $version = null): TaxRule
    {
        return DB::transaction(function () use ($in, $id, $version) {
            $r = $id ? TaxRule::whereKey($id)->lockForUpdate()->firstOrFail() : new TaxRule;
            if ($id) {
                $this->assertVersion($r->lock_version, (int) $version);
            }
            if (! $id && ($in['create_ledger'] ?? false)) {
                $sales = ($in['applies_to'] ?? 'both') !== 'purchases';
                $group = AccountGroup::where('code', $sales ? '21' : '11')->firstOrFail();
                $in['ledger_id'] = $this->ledgers->create(['account_group_id' => $group->id, 'name' => Str::limit($in['name'].($sales ? ' payable' : ' receivable'), 120, '')])->id;
            }
            unset($in['create_ledger']);
            if (! $id && empty($in['ledger_id'])) {
                throw new ApiProblem('validation_failed', errors: ['ledger_id' => ['Choose the ledger the tax collects in, or create one']]);
            }
            if (isset($in['ledger_id'])) {
                $nature = DB::table('account_groups')->where('id', Ledger::findOrFail($in['ledger_id'])->account_group_id)->value('nature');
                if (! in_array($nature, ['liability', 'asset'], true)) {
                    throw new ApiProblem('validation_failed', errors: ['ledger_id' => ['Tax collects in a liability (payable) or asset (receivable) ledger']]);
                }
            }
            $old = $id ? $r->only(array_keys($in)) : null;
            $r->fill($in)->save();
            $this->audit->record($id ? 'updated' : 'created', $r, $old, $r->only(array_keys($in)), type: 'tax_rules');

            return $r->refresh()->load('ledger');
        });
    }

    private function assertVersion(int|string|null $current, int $expected): void
    {
        if ((int) $current !== $expected) {
            throw new ApiProblem('version_conflict', extra: ['current_version' => (int) $current]);
        }
    }
}
