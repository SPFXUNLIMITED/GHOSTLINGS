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
  $role = (string)($_SESSION['role'] ?? '');
  $show_global_search = !empty($_SESSION['user_id']) && $role !== 'user';
  $header_search_query = basename((string)($_SERVER['PHP_SELF'] ?? '')) === 'search.php'
    ? trim((string)($_GET['q'] ?? ''))
    : '';
  $clock_status_badge = '';
  $user_id = (int)($_SESSION['user_id'] ?? 0);
  global $pdo;
  if ($user_id > 0 && isset($pdo) && $pdo instanceof PDO) {
    $open_stmt = $pdo->prepare("
      SELECT id
      FROM time_entries
      WHERE user_id = ? AND clock_out IS NULL AND hours_override IS NULL
      ORDER BY clock_in DESC
      LIMIT 1
    ");
    $open_stmt->execute([$user_id]);
    $clock_status_badge = $open_stmt->fetch()
      ? '<span class="badge clocked-in">● Clocked In</span>'
      : '<span class="badge clocked-out">○ Clocked Out</span>';
  }
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
              <?= $clock_status_badge ?>
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

      <?php if ($show_global_search): ?>
        <form method="get" action="search.php" class="topbar-search" role="search">
          <input
            type="text"
            name="q"
            value="<?= h($header_search_query) ?>"
            placeholder="Search projects, playbooks, documents, tasks, files..."
            aria-label="Search projects, playbooks, documents, tasks, and files"
          />
          <button type="submit" class="btn">Search</button>
        </form>
      <?php endif; ?>
	  </div>
	</div>


<?php
$current = basename($_SERVER['PHP_SELF']);
$show_mod_menu = !empty($_SESSION['is_admin']) || !empty($_SESSION['is_moderator']);
$show_admin_menu = !empty($_SESSION['is_admin']);
$show_rfq_menu = $show_mod_menu;
?>	

<nav class="menubar card">
  <div class="menubar-inner">
    <?php if (!empty($_SESSION['user_id'])): ?>
    <a class="menu-link <?= $current === 'index.php' ? 'active' : '' ?>" href="index.php">Home</a>
    <?php if (($_SESSION['role'] ?? '') === 'user'): ?>
    <a class="menu-link <?= $current === 'user_page.php' ? 'active' : '' ?>" href="user_page.php">My Request</a>
    <?php else: ?>
    <a class="menu-link <?= $current === 'projects.php' ? 'active' : '' ?>" href="projects.php">Projects</a>
    <a class="menu-link <?= $current === 'documents.php' ? 'active' : '' ?>" href="documents.php">Documents</a>
    <a class="menu-link <?= $current === 'playbooks.php' ? 'active' : '' ?>" href="playbooks.php">Playbooks</a>
    <a class="menu-link <?= $current === 'archives.php' ? 'active' : '' ?>" href="archives.php">Archives</a>
    <a class="menu-link <?= $current === 'time_clock.php' ? 'active' : '' ?>" href="time_clock.php">Time Clock</a>
    <?php endif; ?>
    <?php endif; ?>
	<a class="menu-link <?= $current === 'form.php' ? 'active' : '' ?>" href="form.php">Form</a>
  </div>
</nav>

<?php if ($show_mod_menu || $show_admin_menu): ?>
<nav class="menubar card">
  <div class="menubar-inner">
    <?php if ($show_mod_menu): ?>
    <a class="menu-link <?= $current === 'form_admin.php' ? 'active' : '' ?>" href="form_admin.php">Form Entries</a>
    <?php endif; ?>
    <?php if ($show_rfq_menu): ?>
    <a class="menu-link <?= $current === 'rfq_form.php' ? 'active' : '' ?>" href="rfq_form.php">RFQ Form</a>
    <a class="menu-link <?= $current === 'rfq_tracker.php' ? 'active' : '' ?>" href="rfq_tracker.php">RFQ Tracker</a>
    <?php endif; ?>
    <?php if ($show_admin_menu): ?>
    <a class="menu-link <?= $current === 'admin_backend.php' ? 'active' : '' ?>" href="admin_backend.php">Admin Backend</a>
    <?php endif; ?>
  </div>
</nav>
<?php endif; ?>



<?php }

function render_footer(): void {
  // Pass login state to JS so idle tracking only runs for authenticated users.
  $is_logged_in = !empty($_SESSION['user_id']);
?>
  </div>
  <script src="sort.js?v=<?= urlencode((string)filemtime(__DIR__ . '/sort.js')) ?>"></script>

<?php if ($is_logged_in): ?>
  <!-- Idle-timeout notifications (hidden by default) -->
  <div id="idle-warning" style="
    display:none; position:fixed; bottom:24px; left:50%; transform:translateX(-50%);
    background:#fefce8; border:1px solid #ca8a04; color:#713f12;
    padding:14px 20px; border-radius:8px; z-index:9999;
    box-shadow:0 4px 12px rgba(0,0,0,.15); max-width:420px; width:90%; text-align:center;
    font-size:14px; line-height:1.5;">
    <strong>Idle warning:</strong> You will be automatically clocked out in
    <strong id="idle-countdown">5:00</strong> due to inactivity.<br>
    <span style="font-size:12px; color:#92400e;">Move your mouse or press a key to stay clocked in.</span>
  </div>

  <div id="idle-clocked-out" style="
    display:none; position:fixed; bottom:24px; left:50%; transform:translateX(-50%);
    background:#fef2f2; border:1px solid #dc2626; color:#7f1d1d;
    padding:14px 20px; border-radius:8px; z-index:9999;
    box-shadow:0 4px 12px rgba(0,0,0,.15); max-width:420px; width:90%; text-align:center;
    font-size:14px; line-height:1.5;">
    <strong>Auto clocked out.</strong> You were clocked out after 30 minutes of inactivity.<br>
    <a href="time_clock.php" style="color:#dc2626; font-weight:600;">Go to Time Clock →</a>
  </div>

  <script>
  (function () {
    var IDLE_MS  = 30 * 60 * 1000; // 30 minutes → auto clock-out
    var WARN_MS  = 25 * 60 * 1000; // 25 minutes → show warning
    var WARN_DUR = (IDLE_MS - WARN_MS) / 1000; // countdown length in seconds

    var idleTimer    = null;
    var warnTimer    = null;
    var countdownInt = null;
    var warned       = false;

    function resetTimer() {
      clearTimeout(idleTimer);
      clearTimeout(warnTimer);
      if (warned) {
        hideWarning();
        warned = false;
      }
      warnTimer = setTimeout(showWarning, WARN_MS);
      idleTimer = setTimeout(autoClockOut, IDLE_MS);
    }

    function playWarningSound() {
      try {
        var ctx = new (window.AudioContext || window.webkitAudioContext)();
        var resumePromise = ctx.state === 'suspended' ? ctx.resume() : Promise.resolve();
        resumePromise.then(function () {
          var osc = ctx.createOscillator();
          var gain = ctx.createGain();
          osc.connect(gain);
          gain.connect(ctx.destination);
          osc.type = 'sine';
          osc.frequency.setValueAtTime(880, ctx.currentTime);
          gain.gain.setValueAtTime(0.4, ctx.currentTime);
          gain.gain.exponentialRampToValueAtTime(0.001, ctx.currentTime + 3);
          osc.start(ctx.currentTime);
          osc.stop(ctx.currentTime + 3);
        }).catch(function () { /* audio not supported */ });
      } catch (e) { /* audio not supported */ }
    }

    function showWarning() {
      warned = true;
      var el = document.getElementById('idle-warning');
      if (el) el.style.display = 'block';
      playWarningSound();
      startCountdown();
    }

    function hideWarning() {
      clearInterval(countdownInt);
      var el = document.getElementById('idle-warning');
      if (el) el.style.display = 'none';
    }

    function startCountdown() {
      var remaining = WARN_DUR;
      updateCountdown(remaining);
      countdownInt = setInterval(function () {
        remaining -= 1;
        if (remaining <= 0) {
          clearInterval(countdownInt);
          return;
        }
        updateCountdown(remaining);
      }, 1000);
    }

    function updateCountdown(secs) {
      var el = document.getElementById('idle-countdown');
      if (!el) return;
      var m = Math.floor(secs / 60);
      var s = secs % 60;
      el.textContent = m + ':' + (s < 10 ? '0' : '') + s;
    }

    function autoClockOut() {
      clearInterval(countdownInt);
      fetch('idle_clockout.php', {
        method: 'POST',
        headers: { 'X-Requested-With': 'XMLHttpRequest' }
      })
      .then(function (r) { return r.json(); })
      .then(function (data) {
        hideWarning();
        if (data.clocked_out) {
          var el = document.getElementById('idle-clocked-out');
          if (el) el.style.display = 'block';
        }
      })
      .catch(function () {
        hideWarning();
        var el = document.getElementById('idle-clocked-out');
        if (el) {
          el.innerHTML = '<strong>Auto clock-out failed.</strong> Please <a href="time_clock.php" style="color:#dc2626; font-weight:600;">visit Time Clock</a> to clock out manually.';
          el.style.display = 'block';
        }
      });
    }

    ['mousemove', 'keydown', 'mousedown', 'scroll', 'touchstart'].forEach(function (ev) {
      document.addEventListener(ev, resetTimer, true);
    });

    resetTimer();

    // Session keep-alive: ping the server every 10 minutes so the PHP session
    // does not expire while the browser remains open, including hidden tabs.
    setInterval(function () {
      fetch('ping.php', { method: 'POST', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) {
          // Session has expired — redirect to login so the user can re-authenticate.
          if (r.status === 401 || r.status === 403) {
            window.location.href = 'login.php';
          }
        })
        .catch(function () { /* ignore transient network errors */ });
    }, 10 * 60 * 1000);
  })();
  </script>
<?php endif; ?>

</body>
</html>
<?php }
