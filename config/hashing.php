<?php

/* Argon2id with the 07 §2 parameters (memory 64 MiB, time 4, 1 thread). Tests lower the cost via phpunit.xml. */
return [
    'driver' => env('HASH_DRIVER', 'argon2id'),
    'bcrypt' => ['rounds' => env('BCRYPT_ROUNDS', 12), 'verify' => env('HASH_VERIFY', true), 'limit' => env('BCRYPT_LIMIT', null)],
    'argon' => [
        'memory' => (int) env('ARGON_MEMORY', 65536),
        'threads' => (int) env('ARGON_THREADS', 1),
        'time' => (int) env('ARGON_TIME', 4),
        'verify' => env('HASH_VERIFY', true),
    ],
    'rehash_on_login' => false,
];
