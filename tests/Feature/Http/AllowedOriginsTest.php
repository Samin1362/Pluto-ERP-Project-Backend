<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/** Backlog P0.3-BE-01: refresh/logout trust exact origins and https wildcard patterns (07 §3.2). */
class AllowedOriginsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        config(['pluto.allowed_origins' => ['https://pluto-erp-frontend.vercel.app', 'http://localhost:5173'],
            'pluto.allowed_origin_patterns' => ['https://pluto-erp-frontend-*-samin1362s-projects.vercel.app', 'http://*.example.org']]);
        $this->makeUser('rafiq@meghna.example');
    }

    private function refreshFrom(?string $origin)
    {
        $login = $this->postJson('/api/v1/auth/login', ['email' => 'rafiq@meghna.example', 'password' => self::PASSWORD]);
        $headers = ['X-Requested-With' => 'XMLHttpRequest'] + ($origin === null ? [] : ['Origin' => $origin]);

        return $this->withUnencryptedCookie('pl_rt', $this->refreshCookie($login)->getValue())->withHeaders($headers)
            ->postJson('/api/v1/auth/refresh');
    }

    public function test_exact_origins_are_trusted(): void
    {
        $this->refreshFrom('https://pluto-erp-frontend.vercel.app')->assertOk();
        $this->refreshFrom('http://localhost:5173/')->assertOk();
    }

    public function test_a_preview_url_matching_a_pattern_is_trusted(): void
    {
        $this->refreshFrom('https://pluto-erp-frontend-git-feat-pep-42-samin1362s-projects.vercel.app')->assertOk();
    }

    public function test_other_origins_are_rejected(): void
    {
        foreach (['https://evil.vercel.app', 'https://pluto-erp-frontend-x-samin1362s-projects.vercel.app.evil.com',
            'https://pluto-erp-frontend-a.b-samin1362s-projects.vercel.app'] as $origin) {
            $this->assertProblem($this->refreshFrom($origin), 401, 'refresh_token_invalid');
        }
    }

    public function test_plain_http_never_matches_an_https_pattern_and_http_patterns_for_public_hosts_are_ignored(): void
    {
        $this->assertProblem($this->refreshFrom('http://pluto-erp-frontend-git-x-samin1362s-projects.vercel.app'), 401, 'refresh_token_invalid');
        $this->assertProblem($this->refreshFrom('http://shop.example.org'), 401, 'refresh_token_invalid');
    }

    public function test_a_missing_origin_is_still_allowed_with_the_csrf_header(): void
    {
        $this->refreshFrom(null)->assertOk();
    }
}
