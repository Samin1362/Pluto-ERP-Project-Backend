<?php

namespace Tests\Feature\Http;

use App\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Backlog P0.3-BE-02/03: client IP, host and cookies behind the Vercel proxy and the Cloudflare tunnel. */
class ProxyTrustTest extends TestCase
{
    private const SECRET = 'test-only-proxy-secret-0123456789abcdef';

    private const TUNNEL_IP = '172.18.0.9';

    protected function setUp(): void
    {
        parent::setUp();
        config(['pluto.proxy.shared_secret' => self::SECRET]);
        Route::get('api/_probe', fn (Request $r) => response()->json([
            'ip' => $r->ip(), 'host' => $r->getHost(), 'secure' => $r->secure(),
            'secret' => $r->header('X-Pluto-Proxy-Secret'), 'xff' => $r->header('X-Forwarded-For'),
        ]));
    }

    /** Headers the Vercel proxy sends; $secret null = a direct caller pretending to be a proxy. */
    private function viaProxy(string $clientIp, ?string $secret = self::SECRET): static
    {
        return $this->withServerVariables(['REMOTE_ADDR' => self::TUNNEL_IP])->withHeaders(array_filter([
            'X-Forwarded-For' => $clientIp.', 76.76.21.21',
            'X-Forwarded-Host' => 'pluto-erp-frontend.vercel.app',
            'X-Forwarded-Proto' => 'https',
            'X-Pluto-Proxy-Secret' => $secret,
        ]));
    }

    public function test_the_proxy_secret_makes_the_first_forwarded_address_the_client_ip(): void
    {
        $this->viaProxy('203.0.113.7')->getJson('/api/_probe')->assertOk()
            ->assertJson(['ip' => '203.0.113.7', 'host' => 'pluto-erp-frontend.vercel.app', 'secure' => true, 'secret' => null, 'xff' => null]);
    }

    public function test_the_old_secret_header_still_works_for_one_release_and_is_removed_too(): void
    {
        // P1.7-BE-01: Vercel may still run the old proxy during the live switch (P1.7-BE-04).
        $this->withServerVariables(['REMOTE_ADDR' => self::TUNNEL_IP])->withHeaders([
            'X-Forwarded-For' => '203.0.113.8', 'X-Forwarded-Host' => 'pluto-erp-frontend.vercel.app', 'X-Forwarded-Proto' => 'https',
            'X-LF-Proxy-Secret' => self::SECRET,
        ])->getJson('/api/_probe')->assertOk()->assertJson(['ip' => '203.0.113.8', 'secret' => null]);
        Route::get('api/_probe_legacy', fn (Request $r) => response()->json(['legacy' => $r->header('X-LF-Proxy-Secret')]));
        $this->withHeaders(['X-LF-Proxy-Secret' => self::SECRET])->getJson('/api/_probe_legacy')->assertJson(['legacy' => null]);
    }

    public function test_forwarded_headers_are_ignored_without_the_right_secret(): void
    {
        foreach (['wrong-secret', null] as $secret) {
            $this->viaProxy('203.0.113.7', $secret)->getJson('/api/_probe')->assertOk()
                ->assertJson(['ip' => self::TUNNEL_IP, 'host' => 'localhost', 'secure' => false, 'secret' => null, 'xff' => null]);
        }
    }

    public function test_an_empty_configured_secret_trusts_nobody(): void
    {
        config(['pluto.proxy.shared_secret' => '']);
        $this->viaProxy('203.0.113.7', '')->getJson('/api/_probe')->assertJson(['ip' => self::TUNNEL_IP]);
    }

    public function test_login_throttling_and_audit_use_the_resolved_ip(): void
    {
        $this->makeUser('rafiq@meghna.example');
        for ($i = 0; $i < 5; $i++) {
            $this->viaProxy('203.0.113.7')->postJson('/api/v1/auth/login', ['email' => 'rafiq@meghna.example', 'password' => 'wrong password!'])
                ->assertStatus(401);
        }
        $this->assertSame(['203.0.113.7'], AuditLog::where('action', 'login_failed')->distinct()->pluck('ip_address')->all());
        $this->assertProblem($this->viaProxy('203.0.113.7')->postJson('/api/v1/auth/login', ['email' => 'rafiq@meghna.example', 'password' => self::PASSWORD]), 429, 'rate_limited');

        // Another person behind the same proxy and tunnel is not affected.
        $this->viaProxy('198.51.100.20')->postJson('/api/v1/auth/login', ['email' => 'rafiq@meghna.example', 'password' => self::PASSWORD])->assertOk();
        $this->assertSame('198.51.100.20', AuditLog::where('action', 'login')->latest('id')->value('ip_address'));
    }

    public function test_refresh_cookie_is_host_only_strict_secure_http_only_on_the_auth_path(): void
    {
        $this->makeUser('rafiq@meghna.example');
        $r = $this->viaProxy('203.0.113.7')->postJson('/api/v1/auth/login', ['email' => 'rafiq@meghna.example', 'password' => self::PASSWORD]);
        $header = collect($r->headers->all('set-cookie'))->first(fn ($c) => str_starts_with($c, 'pl_rt='));

        $this->assertNotNull($header);
        $attrs = array_map(fn ($a) => strtolower(trim($a)), array_slice(explode(';', $header), 1));
        $this->assertContains('path=/api/v1/auth', $attrs);
        $this->assertContains('secure', $attrs);
        $this->assertContains('httponly', $attrs);
        $this->assertContains('samesite=strict', $attrs);
        $this->assertEmpty(array_filter($attrs, fn ($a) => str_starts_with($a, 'domain=')), 'the cookie must be host-only');
    }

    public function test_location_uses_the_forwarded_host_only_for_the_trusted_proxy(): void
    {
        $company = $this->makeCompany('Meghna Traders Ltd.', $this->makeUser('owner@meghna.example'));
        $token = $this->login('owner@meghna.example');
        $body = ['party_type' => 'customer', 'name' => 'Rahman Stores', 'mobile' => '01711458230'];

        $r = $this->viaProxy('203.0.113.7')->api('POST', '/api/v1/parties', $token, $company->id, $body)->assertCreated();
        $this->assertStringStartsWith('https://pluto-erp-frontend.vercel.app/api/v1/parties/', $r->headers->get('Location'));

        $body['name'] = 'Karim Traders';
        // Absolute URL: the test client would otherwise build it from the previous (proxied) request.
        $r = $this->viaProxy('203.0.113.7', 'wrong-secret')->api('POST', 'http://localhost/api/v1/parties', $token, $company->id, $body)->assertCreated();
        $this->assertStringStartsWith('http://localhost/api/v1/parties/', $r->headers->get('Location'));
    }
}
