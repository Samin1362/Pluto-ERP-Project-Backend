<?php

namespace App\Console\Commands;

use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;

/**
 * Daily operations check (backlog P17.1-BE-06; 11 §7–§8): the newest nightly database backup exists, is less than
 * 26 hours old and not empty (folder PLUTO_BACKUP_DIR, the host's ./backups mounted read-only into the live
 * scheduler), and no queued job failed in the last 24 hours. A problem is logged as critical (and so reaches Sentry
 * when it is on) and e-mailed to PLUTO_ALERT_EMAIL. Exit 1 when anything is wrong.
 */
class OpsCheck extends Command
{
    protected $signature = 'pluto:ops:check {--no-mail : report only}';

    protected $description = 'Check the nightly backup and failed jobs; alert by e-mail when something is wrong';

    public function handle(): int
    {
        $problems = [];
        $dir = (string) config('pluto.ops.backup_dir');
        if ($dir !== '') {
            $files = is_dir($dir) ? glob(rtrim($dir, '/').'/*.sql.gz') : false;
            $newest = $files ? collect($files)->sortByDesc(fn ($f) => filemtime($f))->first() : null;
            if (! $newest) {
                $problems[] = "No database backup found in {$dir}.";
            } else {
                $age = (int) round((time() - filemtime($newest)) / 3600);
                if ($age >= 26) {
                    $problems[] = 'The newest database backup ('.basename($newest).") is {$age} hours old: the nightly backup did not run.";
                }
                if (filesize($newest) < 1024) {
                    $problems[] = 'The newest database backup ('.basename($newest).') is almost empty ('.filesize($newest).' bytes).';
                }
            }
        }
        $failed = DB::table('failed_jobs')->where('failed_at', '>=', CarbonImmutable::now()->subDay())->count();
        if ($failed > 0) {
            $problems[] = "{$failed} queued job(s) failed in the last 24 hours (php artisan queue:failed).";
        }

        if ($problems === []) {
            $this->info('Operations check passed'.($dir === '' ? ' (backup folder not configured: PLUTO_BACKUP_DIR)' : '').'.');

            return self::SUCCESS;
        }
        foreach ($problems as $p) {
            $this->error($p);
        }
        Log::critical('Operations check failed', ['problems' => $problems]);
        $to = (string) config('pluto.ops.alert_email');
        if ($to !== '' && ! $this->option('no-mail')) {
            Mail::raw('Pluto operations check, '.CarbonImmutable::now('Asia/Dhaka')->format('j M Y H:i')." (Dhaka):\n\n- ".implode("\n- ", $problems)
                ."\n\nRunbook: docs/runbooks/monitoring.md", fn ($m) => $m->to($to)->subject('Pluto: operations check failed'));
        }

        return self::FAILURE;
    }
}
