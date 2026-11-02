<?php

namespace Tests\Feature\Ops;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/** pluto:ops:check (backlog P17.1-BE-06): a missing, stale or empty nightly backup and failed jobs raise an alert e-mail. */
class OpsCheckTest extends TestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->dir = sys_get_temp_dir().'/pluto-backups-'.uniqid();
        mkdir($this->dir);
        config(['pluto.ops.backup_dir' => $this->dir, 'pluto.ops.alert_email' => 'owner@meghna.example']);
        Mail::fake();
    }

    private function backup(string $name, int $bytes, int $hoursAgo): void
    {
        file_put_contents("{$this->dir}/{$name}", str_repeat('x', $bytes));
        touch("{$this->dir}/{$name}", time() - $hoursAgo * 3600);
    }

    public function test_a_fresh_backup_and_no_failed_jobs_pass_quietly(): void
    {
        $this->backup('pluto-20261031-020000.sql.gz', 4096, 30);
        $this->backup('pluto-20261101-020000.sql.gz', 4096, 6);
        $this->artisan('pluto:ops:check')->expectsOutputToContain('Operations check passed')->assertSuccessful();
        Mail::assertNothingOutgoing();
    }

    public function test_missing_stale_or_empty_backups_and_failed_jobs_alert_the_owner(): void
    {
        $this->artisan('pluto:ops:check')->expectsOutputToContain('No database backup found')->assertFailed();

        $this->backup('pluto-20261030-020000.sql.gz', 10, 40);
        DB::table('failed_jobs')->insert(['uuid' => 'a7c6e3f0-0000-4000-8000-000000000001', 'connection' => 'redis', 'queue' => 'default', 'payload' => '{}',
            'exception' => 'RuntimeException: SMTP down', 'failed_at' => now()]);
        $this->artisan('pluto:ops:check')->expectsOutputToContain('40 hours old')->expectsOutputToContain('almost empty')
            ->expectsOutputToContain('1 queued job(s) failed')->assertFailed();
        $this->artisan('pluto:ops:check', ['--no-mail' => true])->assertFailed();
    }
}
