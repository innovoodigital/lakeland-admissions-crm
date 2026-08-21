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
<script>
  (function () {
    var theme = localStorage.getItem('lakeland-theme') || 'light';
    document.documentElement.setAttribute('data-theme', theme);
  })();
</script>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Plus+Jakarta+Sans:wght@400;500;600;700;800&family=Inter:wght@400;500;600&family=IBM+Plex+Mono:wght@500;600;700&display=swap" rel="stylesheet">
<link rel="stylesheet" href="<?= BASE_PATH ?>/assets/css/style.css">
</head>
<body>
<div class="app-shell">
  <aside class="sidebar">
    <div class="brand"><?= e(SITE_TITLE) ?><small><?= e(SCHOOL_NAME) ?></small></div>
    <nav>
      <a href="<?= BASE_PATH ?>/index.php" class="<?= $active==='dashboard'?'active':'' ?>">Dashboard</a>
      <a href="<?= BASE_PATH ?>/leads.php" class="<?= $active==='leads'?'active':'' ?>">Leads</a>
      <div class="sidebar-tabs sidebar-subtabs" aria-label="Lead visit pipeline">
        <?php
          $visitView = $_GET['visit_view'] ?? '';
          $visitTabs = [
              'pending_visits' => ['Pending Visits', 'Need a date'],
              'planned_visits' => ['Planned Visits', 'Booked'],
              'visited' => ['Visited', 'Completed'],
              'missed_appointments' => ['Missed Appointments', 'No-show'],
          ];
        ?>
        <?php foreach ($visitTabs as $key => [$label, $hint]): ?>
          <?php
            $visitQuery = $_GET;
            $visitQuery['visit_view'] = $key;
            $visitQuery['page'] = 1;
            unset($visitQuery['status']);
          ?>
          <a href="<?= BASE_PATH ?>/leads.php?<?= e(http_build_query($visitQuery)) ?>" class="<?= $active === 'leads' && $visitView === $key ? 'active' : '' ?>">
            <span aria-hidden="true"></span>
            <strong><?= e($label) ?></strong>
            <small><?= e($hint) ?></small>
          </a>
        <?php endforeach; ?>
      </div>
      <?php if (is_admin()): ?>
      <a href="<?= BASE_PATH ?>/lead_form.php" class="<?= $active==='add'?'active':'' ?>">Add Lead</a>
      <a href="<?= BASE_PATH ?>/import_csv.php" class="<?= $active==='import'?'active':'' ?>">Import CSV</a>
      <?php endif; ?>
    </nav>
    <?php if ($user): ?>
    <div class="user-box">
      <div class="user-meta">
        <span>Signed in as</span>
        <strong><?= e($user['display_name']) ?></strong>
        <small><?= e($user['role']) ?></small>
      </div>
      <button type="button" class="theme-toggle" data-theme-toggle aria-label="Switch to dark mode" aria-pressed="false">
        <span class="theme-toggle-track">
          <span class="theme-toggle-thumb"></span>
        </span>
        <span class="theme-toggle-text">Dark mode</span>
      </button>
      <a class="logout-link" href="<?= BASE_PATH ?>/logout.php">Log out</a>
    </div>
    <?php endif; ?>
  </aside>
  <main class="main">
    <?php $f = flash_get(); if ($f): ?>
      <div class="toast-stack" aria-live="polite" aria-atomic="true">
        <div class="app-toast <?= e($f['type']) ?>" role="status" data-toast>
          <span class="app-toast-icon" aria-hidden="true"></span>
          <span class="app-toast-message"><?= e($f['message']) ?></span>
          <button type="button" class="app-toast-close" aria-label="Dismiss notification" data-toast-close>×</button>
          <span class="app-toast-progress" aria-hidden="true"></span>
        </div>
      </div>
    <?php endif; ?>
