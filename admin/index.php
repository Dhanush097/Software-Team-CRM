<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no, viewport-fit=cover" />
  <meta name="theme-color" content="#2563EB" />
  <meta name="apple-mobile-web-app-capable" content="yes" />
  <meta name="apple-mobile-web-app-status-bar-style" content="default" />
  <meta name="mobile-web-app-capable" content="yes" />
  <title>Software CRM — Mobile App</title>
  
  <!-- Inter Font -->
  <link rel="preconnect" href="https://fonts.googleapis.com" />
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin />
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet" />

  <!-- Modular CSS System (Small Packets) -->
  <link rel="stylesheet" href="assets/css/tokens.css" />
  <link rel="stylesheet" href="assets/css/layout.css" />
  <link rel="stylesheet" href="assets/css/components.css" />
  <link rel="stylesheet" href="assets/css/ghost-nav.css" />
  <link rel="stylesheet" href="assets/css/auth-otp.css" />

  <!-- Alpine.js (Defer) -->
  <script defer src="assets/js/alpine.min.js"></script>

  <!-- Modular JavaScript Architecture (Small Packets) -->
  <script src="assets/js/api.js"></script>
  <script src="assets/js/navigation.js"></script>
  <script src="assets/js/app.js"></script>
</head>
<body x-data="crmApp" x-cloak>

  <!-- ═════════════════════════════════════════════
       MAIN APP VIEWPORT (BORDERLESS FULL-HEIGHT)
  ═════════════════════════════════════════════ -->
  <main class="app-viewport-wrapper" id="phoneWrapper">
    <div class="phone-simulator" id="phoneSimulator">

      <!-- Screen Stacking Container -->
      <div class="screen-scroller" id="screenScroller">
        <?php include __DIR__ . '/views/screens/screen_login.php'; ?>
        <?php include __DIR__ . '/views/screens/screen_dashboard.php'; ?>
        <?php include __DIR__ . '/views/screens/screen_contacts.php'; ?>
        <?php include __DIR__ . '/views/screens/screen_detail.php'; ?>
        <?php include __DIR__ . '/views/screens/screen_projects.php'; ?>
        <?php include __DIR__ . '/views/screens/screen_tasks.php'; ?>
        <?php include __DIR__ . '/views/screens/screen_calendar.php'; ?>
        <?php include __DIR__ . '/views/screens/screen_invoices.php'; ?>
        <?php include __DIR__ . '/views/screens/screen_compact.php'; ?>
        <?php include __DIR__ . '/views/screens/screen_documents.php'; ?>
        <?php include __DIR__ . '/views/screens/screen_team.php'; ?>
        <?php include __DIR__ . '/views/screens/screen_reports.php'; ?>
        <?php include __DIR__ . '/views/screens/screen_settings.php'; ?>
      </div><!-- /screen-scroller -->


      <!-- Global Persistent Bottom Navigation Bar -->
      <?php include __DIR__ . '/views/partials/bottom_nav.php'; ?>

      <!-- Screen 10: Slide-out Navigation Drawer -->
      <?php include __DIR__ . '/views/partials/drawer.php'; ?>

    </div><!-- /phone-simulator -->
  </main>

  <!-- Modals (Add Contact / Add Task) -->
  <?php include __DIR__ . '/views/partials/modals.php'; ?>

  <!-- Reactive Toast Notification -->
  <?php include __DIR__ . '/views/partials/toast.php'; ?>

</body>
</html>
