<?php
require 'vendor/autoload.php';

$pdo = new PDO('sqlite:data/database.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

echo "--- SQLite PRAGMA Status ---" . PHP_EOL;
$pragmas = ['journal_mode', 'synchronous', 'cache_size', 'temp_store', 'mmap_size', 'busy_timeout'];
foreach ($pragmas as $p) {
    $val = $pdo->query("PRAGMA $p")->fetchColumn();
    echo "$p = $val" . PHP_EOL;
}

echo PHP_EOL . "--- Database Tables & Indexes ---" . PHP_EOL;
$tables = $pdo->query("SELECT name FROM sqlite_master WHERE type='table' AND name NOT LIKE 'sqlite_%' ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);
foreach ($tables as $t) {
    $count = $pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
    $indexes = $pdo->query("SELECT name FROM sqlite_master WHERE type='index' AND tbl_name='$t'")->fetchAll(PDO::FETCH_COLUMN);
    echo "$t ($count rows): " . count($indexes) . " indexes -> " . implode(', ', $indexes) . PHP_EOL;
}
