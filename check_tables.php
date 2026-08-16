<?php
$pdo = new PDO('sqlite:data/database.sqlite');
$stmt = $pdo->query("SELECT name FROM sqlite_master WHERE type='table'");
print_r($stmt->fetchAll(PDO::FETCH_COLUMN));
