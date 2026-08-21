<?php
require __DIR__ . '/../includes/db.php';

$db = get_db();
$rows = $db->query('SELECT status, COUNT(*) AS count FROM leads GROUP BY status ORDER BY status')->fetchAll();

foreach ($rows as $row) {
    echo $row['status'] . '=' . $row['count'] . PHP_EOL;
}
