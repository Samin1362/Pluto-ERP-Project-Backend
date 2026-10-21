<?php

use App\Models\User;

/* The API is stateless: the `api` guard is backed by the verified JWT (07 §4). No session guard is used. */
return [
    'defaults' => ['guard' => 'api', 'passwords' => 'users'],
    'guards' => [
        'api' => ['driver' => 'pluto-jwt', 'provider' => 'users'],
    ],
    'providers' => [
        'users' => ['driver' => 'eloquent', 'model' => User::class],
    ],
    'passwords' => [],
    'password_timeout' => 10800,
];
