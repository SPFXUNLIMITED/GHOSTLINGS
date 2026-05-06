<?php
function h($v) { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }

/**
 * Convert "YYYY-MM-DD" -> "MM-DD-YYYY" for display.
 * Returns '' if empty/invalid.
 */
function fmt_date_mdY(?string $ymd): string {
  $ymd = trim((string)$ymd);
  if ($ymd === '') return '';
  $dt = DateTime::createFromFormat('Y-m-d', $ymd);
  if (!$dt) return '';
  return $dt->format('m-d-Y');
}

/**
 * Convert "MM-DD-YYYY" -> "YYYY-MM-DD" for storage.
 * Returns '' if empty/invalid.
 */
function parse_date_mdY(?string $mdy): string {
  $mdy = trim((string)$mdy);
  if ($mdy === '') return '';
  $dt = DateTime::createFromFormat('m-d-Y', $mdy);
  if (!$dt) return '';
  return $dt->format('Y-m-d');
}

function render_pagination(int $current_page, int $total, int $per_page, string $page_param): void {
  $total_pages = max(1, (int)ceil($total / $per_page));
  if ($total_pages <= 1) return;
  // Preserve only the known pagination params to prevent parameter pollution.
  $allowed = ['proj_page', 'task_page'];
  $params = [];
  foreach ($allowed as $key) {
    if (isset($_GET[$key])) {
      $params[$key] = (int)$_GET[$key];
    }
  }
  ?>
  <div class="pagination">
    <?php if ($current_page > 1): ?>
      <?php $params[$page_param] = $current_page - 1; ?>
      <a class="btn" href="?<?= http_build_query($params) ?>">← Prev</a>
    <?php endif; ?>
    <span class="muted">Page <?= $current_page ?> of <?= $total_pages ?></span>
    <?php if ($current_page < $total_pages): ?>
      <?php $params[$page_param] = $current_page + 1; ?>
      <a class="btn" href="?<?= http_build_query($params) ?>">Next →</a>
    <?php endif; ?>
  </div>
  <?php
}

function render_header(string $title): void {
  if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
  }
  $username = $_SESSION['username'] ?? null;
?>
<!doctype html>
<html lang="en">
<head>
  <meta charset="utf-8" />
  <meta name="viewport" content="width=device-width, initial-scale=1" />
  <title><?= h($title) ?></title>
  
<link rel="icon" href="favicon.ico">
<link rel="icon" type="image/png" sizes="32x32" href="favicon-32x32.png">

  <link rel="stylesheet" href="styles.css?v=<?= urlencode((string)filemtime(__DIR__ . '/styles.css')) ?>" />
</head>
<body>
  <div class="container">
	<div class="topbar">
	  <div>
		<img src='logo1.jpg'><br>
		<a class="brand" href="index.php">Project Manager</a>
	  </div>

		<div class="actions">
		  <div class="row" style="justify-content:flex-end; align-items:center;">
			<?php if ($username): ?>
			  <span class="muted">Signed in as <strong><?= h($username) ?></strong></span>
			  <a class="btn" href="logout.php">Logout</a>
			<?php else: ?>
			  <a class="btn" href="login.php">Login</a>
			<?php endif; ?>
		  </div>




		<!-- START TIME (under Login/Logout) -->
		<?php
		  $tz = new DateTimeZone('America/Los_Angeles');
		  $now = new DateTime('now', $tz);
		  $now_ms = (int)$now->format('U') * 1000;
		?>
		<div class="card actions-clock-card">
			<div class="muted actions-clock">
			  Current time (Los Angeles): <strong id="clock"></strong>
			</div>
		</div>

		<script>
		  (function () {
			let ms = <?= (int)$now_ms ?>;

			function tick() {
			  ms += 1000;

			  const parts = new Intl.DateTimeFormat('en-US', {
				timeZone: 'America/Los_Angeles',
				year: 'numeric',
				month: 'numeric',   // no leading zero
				day: 'numeric',     // no leading zero
				hour: 'numeric',    // no leading zero
				minute: '2-digit',
				second: '2-digit',
				hour12: true
			  }).formatToParts(new Date(ms));

			  const get = (type) => parts.find(p => p.type === type)?.value || '';

			  document.getElementById('clock').textContent =
				`${get('month')}-${get('day')}-${get('year')} ${get('hour')}:${get('minute')}:${get('second')} ${get('dayPeriod')}`;
			}

			tick();
			setInterval(tick, 1000);
		  })();
		</script>
		<!-- END TIME -->
	  </div>
	</div>


<?php $current = basename($_SERVER['PHP_SELF']); ?>	

<nav class="menubar card">
  <div class="menubar-inner">
    <a class="menu-link <?= $current === 'index.php' ? 'active' : '' ?>" href="index.php">Projects</a>
	<a class="menu-link <?= $current === 'documents.php' ? 'active' : '' ?>" href="documents.php">Documents</a>
	<a class="menu-link <?= $current === 'playbooks.php' ? 'active' : '' ?>" href="playbooks.php">Playbooks</a>
	<a class="menu-link <?= $current === 'archives.php' ? 'active' : '' ?>" href="archives.php">Archives</a>
	<?php if (!empty($_SESSION['user_id'])): ?>
	<a class="menu-link <?= $current === 'time_clock.php' ? 'active' : '' ?>" href="time_clock.php">Time Clock</a>
	<?php endif; ?>
	<?php if (!empty($_SESSION['is_admin'])): ?>
	<a class="menu-link <?= $current === 'time_report.php' ? 'active' : '' ?>" href="time_report.php">Time Reports</a>
	<a class="menu-link <?= $current === 'users.php' ? 'active' : '' ?>" href="users.php">Users</a>
	<?php endif; ?>
  </div>
</nav>



<?php }

function render_footer(): void { ?>
  </div>
  <script src="sort.js?v=<?= urlencode((string)filemtime(__DIR__ . '/sort.js')) ?>"></script>
</body>
</html>
<?php }