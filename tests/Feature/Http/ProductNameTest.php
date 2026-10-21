<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/** Backlog P1.7-BE-01: the product is Pluto (API root, refresh cookie, token audience, authenticator label). */
class ProductNameTest extends TestCase
{
    public function test_the_api_root_names_pluto(): void
    {
        $this->getJson('/')->assertOk()->assertExactJson(['name' => 'Pluto API', 'docs' => '/api/v1']);
    }

    public function test_sign_in_sets_the_pl_rt_refresh_cookie_and_a_pluto_token(): void
    {
        $this->makeUser('rafiq@meghna.example');
        $r = $this->postJson('/api/v1/auth/login', ['email' => 'rafiq@meghna.example', 'password' => self::PASSWORD])->assertOk();

        $this->assertSame('pl_rt', $this->refreshCookie($r)?->getName());
        $this->assertSame(['pluto-spa'], (array) $this->claims($r->json('data.access_token'))['aud']);
    }
}
