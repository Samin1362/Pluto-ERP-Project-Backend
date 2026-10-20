<?php

namespace App\Console\Commands\Concerns;

use RuntimeException;

/** Passwords come from an environment variable (automation) or a hidden prompt - never from arguments or code. */
trait ReadsPassword
{
    protected function readPassword(string $envVar): string
    {
        $password = (string) (getenv($envVar) ?: '');
        if ($password === '') {
            $password = (string) $this->secret("Password (or set {$envVar})");
        }
        if (mb_strlen($password) < 10 || mb_strlen($password) > 200) {
            throw new RuntimeException('Passwords need 10 to 200 characters (07 §5).');
        }

        return $password;
    }
}
