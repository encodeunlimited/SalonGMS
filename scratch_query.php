<?php
$db = new PDO('sqlite:f:\PROJECT\SalonMS\data\database.sqlite');
$stmt = $db->query('SELECT * FROM customer_package_services');
print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
