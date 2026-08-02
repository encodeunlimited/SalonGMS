<?php

return [
    'displayErrorDetails' => $_ENV['APP_ENV'] === 'development',
    'db' => [
        'connection' => $_ENV['DB_CONNECTION'] ?? 'sqlite',
        'database' => $_ENV['DB_DATABASE'] ?? 'data/salon.sqlite',
        'host' => $_ENV['DB_HOST'] ?? '127.0.0.1',
        'port' => $_ENV['DB_PORT'] ?? '3306',
        'username' => $_ENV['DB_USERNAME'] ?? 'root',
        'password' => $_ENV['DB_PASSWORD'] ?? '',
        'ssl_ca' => $_ENV['DB_SSL_CA'] ?? null,
    ],
    'jwt' => [
        'secret' => $_ENV['JWT_SECRET'] ?? 'secret',
        'expiration' => $_ENV['JWT_EXPIRATION_HOURS'] ?? 24,
    ]
];
