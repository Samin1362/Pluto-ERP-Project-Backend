<?php

namespace Tests\Feature\Settings;

use App\Domain\Company\BrandPalette;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\BuildsMasterData;
use Tests\TestCase;

/**
 * Company brand colour (PEP-497): PATCH /company brand_color; Company.brand and Me.brand carry the colours the server
 * derives for the light and the dark theme; each company has its own; null goes back to the Pluto default.
 */
class BrandColourTest extends TestCase
{
    use BuildsMasterData;

    private string $token;

    protected function setUp(): void
    {
        parent::setUp();
        $this->provisionedCompany();
        $this->token = $this->login('owner@meghna.example');
    }

    private function req(string $method, string $uri, array $data = [], ?int $version = null, ?string $token = null, ?int $company = null)
    {
        $r = $this->api($method, $uri, $token ?? $this->token, $company ?? $this->books->id, $data, $version ? ['If-Match' => "W/\"{$version}\""] : []);
        $this->useBooks();

        return $r;
    }

    public function test_a_company_sets_its_colour_and_the_app_gets_the_palette(): void
    {
        $this->req('GET', '/api/v1/company')->assertOk()->assertJsonPath('data.brand', null);
        $this->req('GET', '/api/v1/me')->assertOk()->assertJsonPath('data.brand', null);

        $r = $this->req('PATCH', '/api/v1/company', ['brand_color' => '#0F766E'], 1)->assertOk()->assertHeader('ETag', 'W/"2"');
        $expected = BrandPalette::for('#0f766e');
        $r->assertJsonPath('data.brand', $expected)->assertJsonPath('data.brand.color', '#0f766e')->assertJsonPath('data.brand.light.brand', '#0f766e')
            ->assertJsonPath('data.brand.light.on_brand', '#ffffff')->assertJsonPath('data.version', 2);
        $this->assertSame(['brand', 'brand_hover', 'on_brand', 'brand_ink', 'brand_soft', 'focus'], array_keys($r->json('data.brand.dark')));
        $this->assertSame('#0f766e', DB::table('companies')->where('id', $this->books->id)->value('brand_color'), 'kept in lower case');

        $this->req('GET', '/api/v1/company')->assertJsonPath('data.brand', $expected);
        $this->req('GET', '/api/v1/me')->assertOk()->assertJsonPath('data.brand', $expected);
        $this->assertArrayNotHasKey('brand', $this->api('GET', '/api/v1/me', $this->token)->assertOk()->json('data'), 'no company chosen: no colour');

        $audit = DB::table('audit_logs')->where('company_id', $this->books->id)->where('auditable_type', 'companies')->where('action', 'updated')->latest('id')->first();
        $this->assertSame(['brand_color' => null], json_decode($audit->old_values, true));
        $this->assertSame(['brand_color' => '#0f766e'], json_decode($audit->new_values, true));
    }

    public function test_null_goes_back_to_the_pluto_default(): void
    {
        $this->req('PATCH', '/api/v1/company', ['brand_color' => '#7f1d1d'], 1)->assertOk()->assertJsonPath('data.brand.color', '#7f1d1d');
        // other fields leave the colour alone
        $this->req('PATCH', '/api/v1/company', ['phone' => '01711000000'], 2)->assertOk()->assertJsonPath('data.brand.color', '#7f1d1d');
        $this->req('PATCH', '/api/v1/company', ['brand_color' => null], 3)->assertOk()->assertJsonPath('data.brand', null);
        $this->assertNull(DB::table('companies')->where('id', $this->books->id)->value('brand_color'));
        $this->req('GET', '/api/v1/me')->assertOk()->assertJsonPath('data.brand', null);
    }

    public function test_a_malformed_or_unreadable_colour_is_refused_on_the_field(): void
    {
        foreach (['teal', '#0f7', '#0f766', '#0f766ez', '0f766e', 'rgb(1,2,3)', 15] as $bad) {
            $this->assertProblem($this->req('PATCH', '/api/v1/company', ['brand_color' => $bad], 1), 422, 'validation_failed')
                ->assertJsonPath('errors.brand_color.0', 'Enter a colour like #1f3a5f');
        }
        // mid grey: neither white nor dark button text reaches 4.5:1 on it
        $r = $this->assertProblem($this->req('PATCH', '/api/v1/company', ['brand_color' => '#797979'], 1), 422, 'validation_failed');
        $this->assertStringContainsString('hard to read', $r->json('errors.brand_color.0'));
        $this->assertNull(DB::table('companies')->where('id', $this->books->id)->value('brand_color'), 'nothing saved');
        // light and dark colours that the palette can make readable are fine
        $this->req('PATCH', '/api/v1/company', ['brand_color' => '#ffd400'], 1)->assertOk()->assertJsonPath('data.brand.light.on_brand', '#0b1320');
    }

    public function test_only_people_who_may_edit_settings_change_it(): void
    {
        $this->userWithRole('viewer');
        $viewer = $this->login('viewer@meghna.example');
        $this->assertProblem($this->req('PATCH', '/api/v1/company', ['brand_color' => '#0f766e'], 1, $viewer), 403, 'permission_denied');
        $this->assertNull(DB::table('companies')->where('id', $this->books->id)->value('brand_color'));
        // …but everyone in the company sees the colour
        $this->req('PATCH', '/api/v1/company', ['brand_color' => '#0f766e'], 1)->assertOk();
        $this->req('GET', '/api/v1/me', token: $viewer)->assertOk()->assertJsonPath('data.brand.color', '#0f766e');
        $this->assertProblem($this->req('PATCH', '/api/v1/company', ['brand_color' => '#7f1d1d']), 428, 'if_match_required');
    }

    public function test_each_company_has_its_own_colour(): void
    {
        $owner = User::where('email', 'owner@meghna.example')->firstOrFail();
        $other = $this->makeCompany('Padma Agro Ltd.', $this->makeUser('owner@padma.example'));
        $this->addMember($other, $owner, 'owner');
        $this->useBooks();
        $this->req('PATCH', '/api/v1/company', ['brand_color' => '#0f766e'], 1)->assertOk();

        // the same person, switching company: the colour follows the company
        $this->req('GET', '/api/v1/me')->assertJsonPath('data.brand.color', '#0f766e');
        $this->req('GET', '/api/v1/me', company: $other->id)->assertOk()->assertJsonPath('data.active_company_id', $other->id)->assertJsonPath('data.brand', null);
        $this->req('GET', '/api/v1/company', company: $other->id)->assertOk()->assertJsonPath('data.brand', null);
        $padma = $this->login('owner@padma.example');
        $this->req('GET', '/api/v1/me', token: $padma, company: $other->id)->assertOk()->assertJsonPath('data.brand', null);

        $this->req('PATCH', '/api/v1/company', ['brand_color' => '#7f1d1d'], 1, $padma, $other->id)->assertOk()->assertJsonPath('data.brand.color', '#7f1d1d');
        $this->req('GET', '/api/v1/me')->assertJsonPath('data.brand.color', '#0f766e');
        $this->req('GET', '/api/v1/me', company: $other->id)->assertJsonPath('data.brand.color', '#7f1d1d');
        // not a member: no way to read or set another company's colour
        $this->assertNull($this->req('GET', '/api/v1/me', token: $padma)->json('data.brand'));
        $this->assertContains($this->req('PATCH', '/api/v1/company', ['brand_color' => '#000000'], 2, $padma)->status(), [403, 404]);
        $this->assertSame('#0f766e', DB::table('companies')->where('id', $this->books->id)->value('brand_color'));
    }
}
