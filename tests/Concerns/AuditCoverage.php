<?php

namespace Tests\Concerns;

use PHPUnit\Framework\Assert;

/**
 * TC-NFR-004 (backlog P17.1-BE-04; NFR-020, NFR-021): every successful /api/v1 request made by any test that wrote
 * business data must also write an audit_logs row carrying its X-Request-Id. Tests\TestCase sees the writes per
 * request; this class names the bookkeeping tables that do not count, the routes whose writes are not business data,
 * and fails the test that left a gap. Idempotent replays and no-op calls write nothing, so they are never flagged.
 * AUDIT_COVERAGE=report writes gaps to storage/logs/audit-coverage.log instead of failing (used to survey).
 */
final class AuditCoverage
{
    /** Method + route pattern (regex on the route URI) → why no audit row is expected. */
    private const EXEMPT = [
        '#^/api/v1/auth/#' => 'sign-in, refresh, sign-out and 2-step challenges: recorded in user_sessions / login history, not business data',
        '#/calculate$#' => 'calculates a document without saving it',
        '#/preview$#' => 'previews without saving',
        '#^/api/v1/notifications(/\{id\})?/read(-all)?$#' => "the user's own read marks",
    ];

    /** Tables whose rows are bookkeeping of the platform, not business data. */
    private const BOOKKEEPING = ['idempotency_keys', 'audit_logs', 'user_sessions', 'sessions', 'cache', 'cache_locks', 'jobs', 'job_batches', 'failed_jobs',
        'password_reset_tokens', 'login_attempts', 'notifications', 'notification_reads', 'user_preferences'];

    public static function bookkeeping(string $table): bool
    {
        return in_array(strtolower($table), self::BOOKKEEPING, true);
    }

    public static function exempt(string $method, string $uri): bool
    {
        foreach (array_keys(self::EXEMPT) as $pattern) {
            if (preg_match($pattern, $uri)) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<string>  $missing */
    public static function report(string $test, array $missing): void
    {
        if ($missing === []) {
            return;
        }
        if (getenv('AUDIT_COVERAGE') === 'report') {
            file_put_contents(__DIR__.'/../../storage/logs/audit-coverage.log', $test.' | '.implode(', ', $missing)."\n", FILE_APPEND);

            return;
        }
        Assert::fail('No audit_logs row with the request id for: '.implode(', ', $missing).' (TC-NFR-004; exempt it in Tests\\Concerns\\AuditCoverage only if it changes no business data)');
    }
}
