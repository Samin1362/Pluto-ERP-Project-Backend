<?php

namespace App\Domain\Platform;

use App\Domain\Access\CompanyProvisioner;
use App\Domain\Access\MemberService;
use App\Domain\Shared\AuditLogger;
use App\Models\Company;
use App\Models\Role;
use App\Support\CurrentCompany;
use App\Support\Problem\ApiProblem;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/**
 * Platform console (PEP-483): the owner of this Pluto installation sells it to many companies. A platform admin lists
 * every company, creates one for a new customer without joining it (the customer's Owner gets an e-mail invitation),
 * and suspends or reactivates a company (ResolveCompany already refuses a company that is not active). Reads go
 * across companies on purpose, so they use the query builder with no company scope; every change is written to that
 * company's own audit log.
 */
final class PlatformCompanyService
{
    public function __construct(private readonly CompanyProvisioner $provisioner, private readonly MemberService $members,
        private readonly CurrentCompany $current, private readonly AuditLogger $audit) {}

    /** @param array{page?: int, per_page?: int, q?: string, status?: string} $f */
    public function page(array $f): LengthAwarePaginator
    {
        $q = DB::table('companies as c')->select('c.id')->orderByDesc('c.id');
        if (! empty($f['status'])) {
            $q->where('c.status', $f['status']);
        }
        if (! empty($f['q'])) {
            $term = '%'.addcslashes($f['q'], '%_\\').'%';
            $q->where(fn ($w) => $w->where('c.legal_name', 'like', $term)->orWhere('c.trading_name', 'like', $term)
                ->orWhereExists(fn ($e) => $e->from('company_users as m')->join('users as u', 'u.id', '=', 'm.user_id')->whereColumn('m.company_id', 'c.id')->where('u.email', 'like', $term)));
        }
        $page = $q->paginate((int) ($f['per_page'] ?? 25), ['*'], 'page', (int) ($f['page'] ?? 1));
        $rows = $this->rows($page->pluck('id')->all());

        return $page->setCollection(collect($page->pluck('id'))->map(fn ($id) => $rows[$id]));
    }

    /** @return array<string, mixed> PlatformCompany */
    public function one(int $id): array
    {
        return $this->rows([$id])[$id] ?? throw new ApiProblem('not_found');
    }

    /** @param array<string, mixed> $in PlatformCompanyCreate */
    public function create(array $in): array
    {
        $email = strtolower(trim($in['owner_email']));
        $company = DB::transaction(function () use ($in, $email) {
            $company = $this->provisioner->provision($in['legal_name'], null, null, array_filter([
                'trading_name' => $in['trading_name'] ?? null, 'business_type' => $in['business_type'], 'fy_start_month' => $in['fy_start_month'] ?? null,
                'base_currency_code' => $in['base_currency_code'] ?? null,
            ], fn ($v) => $v !== null));
            $previous = $this->current->has() ? [$this->current->company(), $this->current->membership()] : null;
            $this->current->set($company, null);
            try {
                $owner = Role::where('slug', 'owner')->value('id');
                $this->members->invite(['email' => $email, 'name' => $in['owner_name'] ?? null, 'role_id' => $owner]);
                $this->audit->record('created', $company, new: ['legal_name' => $company->legal_name, 'owner_email' => $email, 'by' => 'platform'], type: 'companies');
            } finally {
                $previous ? $this->current->set(...$previous) : $this->current->clear();
            }

            return $company;
        });

        return $this->one($company->id);
    }

    /** Suspend (active → suspended) or reactivate (suspended → active), with the reason in the company's audit log. */
    public function setStatus(int $id, string $to, string $reason): array
    {
        $from = $to === 'suspended' ? 'active' : 'suspended';
        DB::transaction(function () use ($id, $to, $from, $reason) {
            $c = Company::query()->whereKey($id)->lockForUpdate()->first() ?? throw new ApiProblem('not_found');
            if ($c->status !== $from) {
                throw new ApiProblem('invalid_transition', "{$c->legal_name} is {$c->status}; only a company that is {$from} can be ".($to === 'suspended' ? 'suspended' : 'reactivated').'.');
            }
            $c->update(['status' => $to]);
            $this->audit->record($to === 'suspended' ? 'suspended' : 'reactivated', $c, ['status' => $from], ['status' => $to, 'reason' => $reason, 'by' => 'platform'],
                companyId: $c->id, type: 'companies');
        });

        return $this->one($id);
    }

    /** @param list<int> $ids @return array<int, array<string, mixed>> */
    private function rows(array $ids): array
    {
        if ($ids === []) {
            return [];
        }
        $owners = DB::table('company_users as m')->join('roles as r', 'r.id', '=', 'm.role_id')->join('users as u', 'u.id', '=', 'm.user_id')
            ->whereIn('m.company_id', $ids)->where('r.slug', 'owner')->where('m.status', 'active')->orderBy('m.id')
            ->get(['m.company_id', 'u.name', 'u.email'])->groupBy('company_id')->map->first();
        $invited = DB::table('user_invitations as i')->join('roles as r', 'r.id', '=', 'i.role_id')->whereIn('i.company_id', $ids)->where('r.slug', 'owner')
            ->whereNull('i.accepted_at')->whereNull('i.revoked_at')->orderBy('i.id')->get(['i.company_id', 'i.name', 'i.email'])->groupBy('company_id')->map->first();
        $members = DB::table('company_users')->whereIn('company_id', $ids)->where('status', 'active')->groupBy('company_id')->selectRaw('company_id, COUNT(*) n')->pluck('n', 'company_id');
        $activity = DB::table('audit_logs')->whereIn('company_id', $ids)->groupBy('company_id')->selectRaw('company_id, MAX(created_at) at')->pluck('at', 'company_id');

        $out = [];
        foreach (DB::table('companies')->whereIn('id', $ids)->get(['id', 'legal_name', 'trading_name', 'status', 'created_at']) as $c) {
            $o = $owners[$c->id] ?? null;
            $i = $o ? null : ($invited[$c->id] ?? null);
            $out[$c->id] = ['id' => $c->id, 'legal_name' => $c->legal_name, 'trading_name' => $c->trading_name, 'status' => $c->status,
                'created_at' => self::ts($c->created_at), 'owner_name' => $o->name ?? $i->name ?? null, 'owner_email' => $o->email ?? $i->email ?? null,
                'owner_pending' => $o === null && $i !== null, 'members' => (int) ($members[$c->id] ?? 0), 'last_activity_at' => self::ts($activity[$c->id] ?? null)];
        }

        return $out;
    }

    private static function ts(?string $at): ?string
    {
        return $at ? CarbonImmutable::parse($at, 'UTC')->toIso8601String() : null;
    }
}
