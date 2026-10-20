<?php

namespace Tests\Feature\Http;

use Tests\TestCase;

/** X-If-Match is accepted as If-Match (Vercel's edge turns a successful If-Match edit into 412). BR-049. */
class XIfMatchTest extends TestCase
{
    public function test_x_if_match_works_like_if_match(): void
    {
        $company = $this->makeCompany('Meghna Traders Ltd.', $this->makeUser('owner@meghna.example'));
        $token = $this->login('owner@meghna.example');
        $id = $this->api('POST', '/api/v1/parties', $token, $company->id, ['party_type' => 'customer', 'name' => 'Rahman Stores', 'mobile' => '01711458230'])
            ->assertCreated()->json('data.id');

        $this->api('PATCH', "/api/v1/parties/{$id}", $token, $company->id, ['name' => 'Rahman Stores Ltd'], ['X-If-Match' => 'W/"1"'])
            ->assertOk()->assertHeader('ETag', 'W/"2"')->assertJsonPath('data.name', 'Rahman Stores Ltd');
        $this->assertProblem($this->api('PATCH', "/api/v1/parties/{$id}", $token, $company->id, ['name' => 'Stale'], ['X-If-Match' => 'W/"1"']), 409, 'version_conflict');
        // A real If-Match wins over the alias.
        $this->assertProblem($this->api('PATCH', "/api/v1/parties/{$id}", $token, $company->id, ['name' => 'Stale'], ['If-Match' => 'W/"1"', 'X-If-Match' => 'W/"2"']), 409, 'version_conflict');
        $this->assertProblem($this->api('PATCH', "/api/v1/parties/{$id}", $token, $company->id, ['name' => 'None']), 428, 'if_match_required');
    }
}
