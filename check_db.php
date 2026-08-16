<?php
$pdo = new PDO('sqlite:data/salon.sqlite');
$stmt = $pdo->query("PRAGMA table_info(customers)");
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
