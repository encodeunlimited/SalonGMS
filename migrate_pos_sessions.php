<?php

require __DIR__ . '/vendor/autoload.php';

use Dotenv\Dotenv;

$dotenv = Dotenv::createImmutable(__DIR__);
$dotenv->safeLoad();

$dbPath = __DIR__ . '/data/database.sqlite';
$pdo = new PDO('sqlite:' . $dbPath);
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "Creating pos_sessions table...\n";

$pdo->exec("
CREATE TABLE IF NOT EXISTS pos_sessions (
    id INTEGER PRIMARY KEY AUTOINCREMENT,
    tenant_id INTEGER NOT NULL,
    opened_by INTEGER NOT NULL,
    opened_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    opening_balance REAL NOT NULL,
    closed_by INTEGER NULL,
    closed_at DATETIME NULL,
    closing_balance REAL NULL,
    expected_balance REAL NULL,
    status TEXT NOT NULL DEFAULT 'open',
    FOREIGN KEY (tenant_id) REFERENCES tenants(id),
    FOREIGN KEY (opened_by) REFERENCES users(id),
    FOREIGN KEY (closed_by) REFERENCES users(id)
)
");

echo "Done.\n";
