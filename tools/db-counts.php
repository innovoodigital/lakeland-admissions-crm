<?php
require __DIR__ . '/../includes/db.php';

$db = get_db();

echo 'leads=' . $db->query('SELECT COUNT(*) FROM leads')->fetchColumn() . PHP_EOL;
echo 'followups=' . $db->query('SELECT COUNT(*) FROM follow_ups')->fetchColumn() . PHP_EOL;
