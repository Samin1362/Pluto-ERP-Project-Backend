<?php

namespace Tests;

use App\Domain\Access\CompanyProvisioner;
use App\Models\Company;
use App\Models\CompanyUser;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentCompany;
use Database\Seeders\ReferenceDataSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Testing\TestResponse;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;

    /** Test-only password for fixture users (not a credential of any real system). */
    protected const PASSWORD = 'correct horse battery';

    protected function setUp(): void
    {
        parent::setUp();
        $dir = storage_path('framework/testing/keys');
        if (! is_file("$dir/jwt-private.pem")) {
            @mkdir($dir, 0700, true);
            $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
            openssl_pkey_export($key, $pem);
            file_put_contents("$dir/jwt-private.pem", $pem);
            file_put_contents("$dir/jwt-public.pem", openssl_pkey_get_details($key)['key']);
        }
        // A throw-away APP_KEY per test (encrypted casts: 2-step secrets); nothing is committed.
        if (! config('app.key')) {
            config(['app.key' => 'base64:'.base64_encode(random_bytes(32))]);
            $this->app->forgetInstance('encrypter');
        }
        config(['ledgerflow.jwt.private_key_path' => "$dir/jwt-private.pem", 'ledgerflow.jwt.public_key_path' => "$dir/jwt-public.pem"]);
        $this->seed(ReferenceDataSeeder::class);
        $this->withCredentials(); // send cookies on JSON requests, as the browser does for the refresh cookie
    }

    protected function makeUser(string $email, string $status = 'active'): User
    {
        $u = User::create(['name' => ucfirst(strtok($email, '@')), 'email' => $email, 'password_hash' => Hash::make(self::PASSWORD), 'status' => $status]);
        $u->forceFill(['password_changed_at' => now()])->save();

        return $u;
    }

    /** A fully provisioned company (04 §11.2) owned by $owner. */
    protected function makeCompany(string $name, User $owner): Company
    {
        return app(CompanyProvisioner::class)->provision($name, $owner);
    }

    protected function addMember(Company $company, User $user, string $roleSlug, string $status = 'active'): CompanyUser
    {
        $current = app(CurrentCompany::class);
        $current->set($company, null);
        try {
            $role = Role::where('slug', $roleSlug)->firstOrFail();

            return CompanyUser::create(['user_id' => $user->id, 'role_id' => $role->id, 'status' => $status, 'joined_at' => now()]);
        } finally {
            $current->clear();
        }
    }

    /** Signs in through the real endpoint and returns the access token. */
    protected function login(string $email, string $ip = '10.0.0.1'): string
    {
        $r = $this->withServerVariables(['REMOTE_ADDR' => $ip])->postJson('/api/v1/auth/login', ['email' => $email, 'password' => self::PASSWORD]);
        $r->assertOk()->assertJsonPath('data.status', 'authenticated');

        return $r->json('data.access_token');
    }

    /** Claims of an access token, read without verification (assertions only). */
    protected function claims(string $jwt): array
    {
        return json_decode(base64_decode(strtr(explode('.', $jwt)[1], '-_', '+/')), true);
    }

    /** Calls the API as a signed-in user in a company. */
    protected function api(string $method, string $uri, ?string $token, ?int $companyId = null, array $data = [], array $headers = []): TestResponse
    {
        $h = $headers;
        if ($token) {
            $h['Authorization'] = 'Bearer '.$token;
        }
        if ($companyId) {
            $h['X-Company-Id'] = (string) $companyId;
        }

        return $this->json($method, $uri, $data, $h);
    }

    protected function refreshCookie(TestResponse $r): ?\Symfony\Component\HttpFoundation\Cookie
    {
        foreach ($r->headers->getCookies() as $c) {
            if ($c->getName() === 'lf_rt') {
                return $c;
            }
        }

        return null;
    }

    /** Every error is application/problem+json with the catalogue code (05 §3). */
    protected function assertProblem(TestResponse $r, int $status, string $code): TestResponse
    {
        $r->assertStatus($status);
        $this->assertStringStartsWith('application/problem+json', (string) $r->headers->get('Content-Type'));
        $r->assertJsonPath('code', $code)->assertJsonPath('status', $status);
        $this->assertNotEmpty($r->json('request_id'));
        $this->assertArrayHasKey($code, \App\Support\Problem\ErrorCatalogue::CODES);

        return $r;
    }
}
