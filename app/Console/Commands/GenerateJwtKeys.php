<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;

/** Creates the ES256 (P-256) key pair for access tokens (07 §3.1). Keys stay outside version control. */
class GenerateJwtKeys extends Command
{
    protected $signature = 'ledgerflow:jwt-keys {--force : Overwrite existing keys (signs everyone out)}';

    protected $description = 'Generate the ES256 key pair used to sign access tokens';

    public function handle(): int
    {
        $priv = config('ledgerflow.jwt.private_key_path');
        $pub = config('ledgerflow.jwt.public_key_path');
        if (is_file($priv) && ! $this->option('force')) {
            $this->info("Keys already exist at {$priv}; use --force to replace them.");

            return self::SUCCESS;
        }
        $key = openssl_pkey_new(['private_key_type' => OPENSSL_KEYTYPE_EC, 'curve_name' => 'prime256v1']);
        openssl_pkey_export($key, $pem);
        @mkdir(dirname($priv), 0700, true);
        @mkdir(dirname($pub), 0700, true);
        file_put_contents($priv, $pem);
        chmod($priv, 0600);
        file_put_contents($pub, openssl_pkey_get_details($key)['key']);
        $this->info("ES256 keys written to {$priv} and {$pub}.");

        return self::SUCCESS;
    }
}
