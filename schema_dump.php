<?php
$db = new PDO('sqlite:data/database.sqlite');
$stmt = $db->query("SELECT name, sql FROM sqlite_master WHERE type='table'");
while($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    echo $row['name'] . "\n" . $row['sql'] . "\n\n";
}
