<?php

namespace App\Domain\Access;

use App\Models\User;
use App\Support\Problem\ApiProblem;
use DateInterval;
use DateTimeImmutable;
use Illuminate\Support\Str;
use Lcobucci\JWT\Configuration;
use Lcobucci\JWT\Signer\Ecdsa\Sha256;
use Lcobucci\JWT\Signer\Key\InMemory;
use Lcobucci\JWT\Token\Plain;
use Lcobucci\JWT\Validation\Constraint\IssuedBy;
use Lcobucci\JWT\Validation\Constraint\LooseValidAt;
use Lcobucci\JWT\Validation\Constraint\PermittedFor;
use Lcobucci\JWT\Validation\Constraint\SignedWith;
use Lcobucci\JWT\Validation\RequiredConstraintsViolated;
use RuntimeException;
use Symfony\Component\Clock\NativeClock;
use Throwable;

/**
 * Access tokens (07 §3.1): ES256, 15 minutes, claims iss/aud/sub/sid/iat/nbf/exp/jti/amr/pwv.
 * The token carries identity only - never company, role or permissions.
 */
final class JwtService
{
    private ?Configuration $config = null;

    /** @param list<string> $amr how the sign-in was verified: ['pwd'] or ['pwd', 'otp'] */
    public function issue(User $user, int $sessionId, array $amr = ['pwd']): array
    {
        $now = new DateTimeImmutable('@'.time());
        $ttl = (int) config('ledgerflow.jwt.ttl_seconds');
        $c = $this->config();
        $token = $c->builder()
            ->issuedBy((string) config('ledgerflow.jwt.issuer'))
            ->permittedFor((string) config('ledgerflow.jwt.audience'))
            ->relatedTo((string) $user->id)
            ->identifiedBy((string) Str::uuid())
            ->issuedAt($now)
            ->canOnlyBeUsedAfter($now)
            ->expiresAt($now->modify(sprintf('%+d seconds', $ttl)))
            ->withHeader('kid', (string) config('ledgerflow.jwt.key_id'))
            ->withClaim('sid', $sessionId)
            ->withClaim('amr', array_values($amr))
            ->withClaim('pwv', $user->passwordVersion())
            ->getToken($c->signer(), $c->signingKey());

        return ['access_token' => $token->toString(), 'token_type' => 'Bearer', 'expires_in' => $ttl];
    }

    /**
     * Verifies signature, issuer, audience and time claims. Expiry is reported separately so the SPA knows
     * to refresh (401 token_expired) rather than sign in again (401 token_invalid).
     *
     * @return array{sub:int,sid:int,pwv:string,amr:list<string>}
     */
    public function verify(string $jwt): array
    {
        $c = $this->config();
        try {
            $token = $c->parser()->parse($jwt);
        } catch (Throwable) {
            throw new ApiProblem('token_invalid');
        }
        if (! $token instanceof Plain) {
            throw new ApiProblem('token_invalid');
        }
        $leeway = new DateInterval('PT'.(int) config('ledgerflow.jwt.leeway_seconds').'S');
        $clock = new NativeClock('UTC');
        try {
            $c->validator()->assert($token,
                new SignedWith($c->signer(), $c->verificationKey()),
                new IssuedBy((string) config('ledgerflow.jwt.issuer')),
                new PermittedFor((string) config('ledgerflow.jwt.audience')),
            );
        } catch (RequiredConstraintsViolated) {
            throw new ApiProblem('token_invalid');
        }
        try {
            $c->validator()->assert($token, new LooseValidAt($clock, $leeway));
        } catch (RequiredConstraintsViolated) {
            throw new ApiProblem($token->isExpired($clock->now()->sub($leeway)) ? 'token_expired' : 'token_invalid');
        }
        $claims = $token->claims();
        $sub = $claims->get('sub');
        $sid = $claims->get('sid');
        $pwv = $claims->get('pwv');
        $amr = $claims->get('amr');
        if (! ctype_digit((string) $sub) || ! is_int($sid) || ! is_string($pwv) || ! is_array($amr)) {
            throw new ApiProblem('token_invalid');
        }

        return ['sub' => (int) $sub, 'sid' => $sid, 'pwv' => $pwv, 'amr' => array_values(array_filter($amr, 'is_string'))];
    }

    private function config(): Configuration
    {
        if ($this->config) {
            return $this->config;
        }
        $priv = (string) config('ledgerflow.jwt.private_key_path');
        $pub = (string) config('ledgerflow.jwt.public_key_path');
        if (! is_readable($priv) || ! is_readable($pub)) {
            throw new RuntimeException('JWT keys are missing: run `php artisan ledgerflow:jwt-keys` (07 §3.1).');
        }

        return $this->config = Configuration::forAsymmetricSigner(new Sha256, InMemory::file($priv), InMemory::file($pub));
    }
}
