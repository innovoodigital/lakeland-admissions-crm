<?php
require_once __DIR__ . '/includes/auth.php';
do_logout();
header('Location: ' . BASE_PATH . '/login.php');
exit;
