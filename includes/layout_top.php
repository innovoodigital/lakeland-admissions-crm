<?php
// Expects $page_title and optional $active to be set before including this file.
$user = current_user();
$active = $active ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?= e($page_title ?? SITE_TITLE) ?> · <?= e(SITE_TITLE) ?></title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Fraunces:wght@500;600;700&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@500;600&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css">
</head>
<body>
<div class="app-shell">
  <aside class="sidebar">
    <div class="brand"><?= e(SITE_TITLE) ?><small><?= e(SCHOOL_NAME) ?></small></div>
    <nav>
      <a href="<?= BASE_PATH ?>/index.php" class="<?= $active==='dashboard'?'active':'' ?>">Dashboard</a>
      <a href="<?= BASE_PATH ?>/leads.php" class="<?= $active==='leads'?'active':'' ?>">Leads</a>
      <?php if (is_admin()): ?>
      <a href="<?= BASE_PATH ?>/lead_form.php" class="<?= $active==='add'?'active':'' ?>">Add Lead</a>
      <a href="<?= BASE_PATH ?>/import_csv.php" class="<?= $active==='import'?'active':'' ?>">Import CSV</a>
      <?php endif; ?>
    </nav>
    <?php if ($user): ?>
    <div class="user-box">
      Signed in as <strong><?= e($user['display_name']) ?></strong> (<?= e($user['role']) ?>)<br>
      <a href="<?= BASE_PATH ?>/logout.php">Log out</a>
    </div>
    <?php endif; ?>
  </aside>
  <main class="main">
    <?php $f = flash_get(); if ($f): ?>
      <div class="flash <?= e($f['type']) ?>"><?= e($f['message']) ?></div>
    <?php endif; ?>
