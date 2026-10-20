<?php

/* The API is stateless: the `api` guard is backed by the verified JWT (07 §4). No session guard is used. */
return [
    'defaults' => ['guard' => 'api', 'passwords' => 'users'],
    'guards' => [
        'api' => ['driver' => 'ledgerflow-jwt', 'provider' => 'users'],
    ],
    'providers' => [
        'users' => ['driver' => 'eloquent', 'model' => App\Models\User::class],
    ],
    'passwords' => [],
    'password_timeout' => 10800,
];
