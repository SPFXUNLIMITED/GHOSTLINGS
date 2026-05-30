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

function is_image_attachment_mime(?string $mime): bool {
  return is_string($mime) && preg_match('#^image/(png|jpe?g|gif|webp)$#i', $mime);
}

function attachment_icon_emoji(?string $file_name, ?string $mime): string {
  $mime = strtolower(trim((string)$mime));
  $file_name = strtolower(trim((string)$file_name));
  $ext = pathinfo($file_name, PATHINFO_EXTENSION);

  if (strpos($mime, 'pdf') !== false || $ext === 'pdf') return '📕';
  if (strpos($mime, 'word') !== false || in_array($ext, ['doc', 'docx'], true)) return '📘';
  if (strpos($mime, 'excel') !== false || in_array($ext, ['xls', 'xlsx', 'csv'], true)) return '📗';
  if (strpos($mime, 'zip') !== false || in_array($ext, ['zip', 'rar', '7z', 'gz', 'tar'], true)) return '🗜️';
  if (strpos($mime, 'audio/') === 0 || in_array($ext, ['mp3', 'wav', 'ogg'], true)) return '🎵';
  if (strpos($mime, 'video/') === 0 || in_array($ext, ['mp4', 'mov', 'avi', 'webm'], true)) return '🎞️';
  if (is_image_attachment_mime($mime) || in_array($ext, ['png', 'jpg', 'jpeg', 'gif', 'webp'], true)) return '🖼️';
  if (strpos($mime, 'text/') === 0 || in_array($ext, ['txt', 'md', 'log'], true)) return '📝';
  return '📄';
}

function render_attachment_preview(?string $file_url, ?string $display_name, ?string $mime_type = null, ?string $preview_url = null): string {
  $file_url = trim((string)$file_url);
  if ($file_url === '') {
    return '<span class="muted">—</span>';
  }

  $display_name = trim((string)$display_name);
  if ($display_name === '') $display_name = 'Attachment';

  $mime_type = trim((string)$mime_type);
  $is_image = is_image_attachment_mime($mime_type);
  $icon = attachment_icon_emoji($display_name, $mime_type);
  $preview_src = trim((string)($preview_url ?? $file_url));

  $out = '<div style="display:flex; align-items:center; gap:8px;">';
  if ($is_image) {
    $out .= '<a href="' . h($file_url) . '" target="_blank" rel="noopener noreferrer">'
      . '<img src="' . h($preview_src) . '" alt="' . h($display_name) . '"'
      . ' style="width:44px; height:44px; object-fit:cover; border-radius:6px; border:1px solid rgba(0,0,0,.12); display:block;" />'
      . '</a>';
  } else {
    $out .= '<span aria-hidden="true" style="font-size:20px; line-height:1;">' . h($icon) . '</span>';
  }
  $out .= '<a href="' . h($file_url) . '" target="_blank" rel="noopener noreferrer"'
    . ' style="font-size:12px; line-height:1.3; word-break:break-word;">' . h($display_name) . '</a>'
    . '</div>';

  return $out;
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

function render_doc_details(string $details, array $placeholder_values = []): string {
  if ($details === '') return '';
  $field_index = 0;
  $inline_field_labels = [
    'contact_name' => 'Contact Name',
    'company_name' => 'Company Name',
    'email' => 'Email',
    'contact_phone' => 'Contact Phone',
    'username' => 'Username',
  ];
  $placeholder_token_pattern = '[a-zA-Z][a-zA-Z0-9_-]*';
  $inline_field_pattern = implode('|', array_map(static fn(string $field): string => preg_quote($field, '/'), array_keys($inline_field_labels)));
  $normalized_placeholders = [];
  foreach ($placeholder_values as $key => $value) {
    $normalized_placeholders[strtolower((string)$key)] = trim((string)$value);
  }

  $resolve_placeholder_text = static function (string $field_name, string $fallback = '') use ($normalized_placeholders): string {
    $field_name = strtolower($field_name);
    $placeholder = $normalized_placeholders[$field_name] ?? '';
    if ($placeholder === '' && $field_name === 'contact_name') {
      $placeholder = $normalized_placeholders['username'] ?? '';
    }
    if ($placeholder === '') {
      $placeholder = $fallback;
    }
    return $placeholder;
  };

  $rendered = preg_replace_callback(
    '/(^|[\r\n]+)([^\r\n<>()\[\]]+?)\s+text\s+input\s*(?:\[(' . $placeholder_token_pattern . ')\]|\((' . $placeholder_token_pattern . ')\))(?=$|[\r\n]+)/i',
    static function (array $matches) use (&$field_index, $resolve_placeholder_text): string {
      $prefix = $matches[1] ?? '';
      $label = trim((string)($matches[2] ?? ''));
      $field_name = (string)($matches[3] ?? $matches[4] ?? '');
      if ($label === '' || $field_name === '' || !preg_match('/^[a-zA-Z][a-zA-Z0-9_-]*$/', $field_name)) {
        return $matches[0];
      }

      $field_index++;
      $field_id = 'doc_field_' . $field_index . '_' . strtolower(str_replace('-', '_', $field_name));
      $placeholder = $resolve_placeholder_text($field_name, $label);

      return $prefix
        . '<div style="margin:12px 0;">'
        . '<label for="' . h($field_id) . '" style="display:block; font-weight:600; margin-bottom:6px;">' . h($label) . '</label>'
        . '<input type="text" id="' . h($field_id) . '" name="' . h($field_name) . '" placeholder="' . h($placeholder) . '" style="width:100%; max-width:480px;" />'
        . '</div>';
    },
    $details
  );

  return preg_replace_callback(
    '/(?:\[(' . $inline_field_pattern . ')\]|\((' . $inline_field_pattern . ')\))/i',
    static function (array $matches) use ($resolve_placeholder_text, $inline_field_labels): string {
      $field_name = strtolower((string)($matches[1] ?? $matches[2] ?? ''));
      $label = $inline_field_labels[$field_name] ?? $field_name;
      $placeholder = $resolve_placeholder_text($field_name, $label);
      return '<span>' . h($placeholder) . '</span>';
    },
    $rendered
  );
}

function render_menu_dropdown(string $label, array $items, string $current): void {
  $visible_items = array_values(array_filter($items, static fn(array $item): bool => !empty($item['visible'])));
  if (!$visible_items) {
    return;
  }

  if (count($visible_items) === 1) {
    $item = $visible_items[0];
    ?>
    <a class="menu-link <?= $current === $item['file'] ? 'active' : '' ?>" href="<?= h($item['href']) ?>"><?= h($item['label']) ?></a>
    <?php
    return;
  }

  $is_active = false;
  foreach ($visible_items as $item) {
    if ($current === $item['file']) {
      $is_active = true;
      break;
    }
  }
  ?>
  <details class="menu-dropdown">
    <summary class="menu-link menu-dropdown-toggle <?= $is_active ? 'active' : '' ?>"><?= h($label) ?></summary>
    <div class="menu-dropdown-menu">
      <?php foreach ($visible_items as $item): ?>
      <a class="menu-dropdown-item <?= $current === $item['file'] ? 'active' : '' ?>" href="<?= h($item['href']) ?>"><?= h($item['label']) ?></a>
      <?php endforeach; ?>
    </div>
  </details>
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
    <a class="menu-link <?= $current === 'user_page.php' ? 'active' : '' ?>" href="user_page.php">My Profile</a>
    <?php render_menu_dropdown('App Requests', [
      ['href' => 'app_request_form.php', 'file' => 'app_request_form.php', 'label' => 'App Request Form', 'visible' => true],
      ['href' => 'app_request_tracker.php', 'file' => 'app_request_tracker.php', 'label' => 'Request Tracker', 'visible' => $show_mod_menu],
    ], $current); ?>
    <?php if (($_SESSION['role'] ?? '') !== 'user'): ?>
    <?php render_menu_dropdown('Projects', [
      ['href' => 'projects.php', 'file' => 'projects.php', 'label' => 'Projects', 'visible' => true],
      ['href' => 'documents.php', 'file' => 'documents.php', 'label' => 'Documents', 'visible' => true],
      ['href' => 'playbooks.php', 'file' => 'playbooks.php', 'label' => 'Playbooks', 'visible' => true],
      ['href' => 'archives.php', 'file' => 'archives.php', 'label' => 'Archives', 'visible' => true],
    ], $current); ?>
    <a class="menu-link <?= $current === 'time_clock.php' ? 'active' : '' ?>" href="time_clock.php">Time Clock</a>
    <?php endif; ?>
    <?php endif; ?>
    <?php render_menu_dropdown('Service Requests', [
      ['href' => 'form.php', 'file' => 'form.php', 'label' => 'Service Request Form', 'visible' => true],
      ['href' => 'form_admin.php', 'file' => 'form_admin.php', 'label' => 'Form Entries', 'visible' => $show_mod_menu],
    ], $current); ?>
    <?php if ($show_mod_menu): ?>
    <span class="menu-spacer" aria-hidden="true"></span>
    <?php render_menu_dropdown('RFQ & Sourcing', [
      ['href' => 'vendors.php', 'file' => 'vendors.php', 'label' => 'Vendors', 'visible' => true],
      ['href' => 'rfq_form.php', 'file' => 'rfq_form.php', 'label' => 'RFQ Form', 'visible' => $show_rfq_menu],
      ['href' => 'rfq_tracker.php', 'file' => 'rfq_tracker.php', 'label' => 'RFQ Tracker', 'visible' => $show_rfq_menu],
    ], $current); ?>
    <?php endif; ?>
    <?php if ($show_admin_menu): ?>
    <a class="menu-link <?= $current === 'admin_backend.php' ? 'active' : '' ?>" href="admin_backend.php">Admin Backend</a>
    <?php endif; ?>
  </div>
</nav>

<script>
  (function () {
    const dropdowns = Array.from(document.querySelectorAll('details.menu-dropdown'));
    if (!dropdowns.length) return;

    function closeDropdown(dropdown) {
      dropdown.removeAttribute('open');
    }

    function closeAll(except) {
      dropdowns.forEach((dropdown) => {
        if (dropdown !== except) {
          closeDropdown(dropdown);
        }
      });
    }

    dropdowns.forEach((dropdown) => {
      dropdown.addEventListener('toggle', function () {
        if (dropdown.hasAttribute('open')) {
          closeAll(dropdown);
        }
      });

      dropdown.addEventListener('focusout', function (event) {
        const next = event.relatedTarget;
        if (!next || !dropdown.contains(next)) {
          closeDropdown(dropdown);
        }
      });
    });

    document.addEventListener('click', function (event) {
      if (!event.target.closest('details.menu-dropdown')) {
        closeAll();
      }
    });
  })();
</script>



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
