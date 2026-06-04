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

function is_inline_preview_attachment(?string $file_name, ?string $mime): bool {
  if (is_image_attachment_mime($mime)) {
    return true;
  }

  $mime = strtolower(trim((string)$mime));
  $ext = strtolower(pathinfo((string)$file_name, PATHINFO_EXTENSION));

  if ($mime === 'application/pdf' || $ext === 'pdf') {
    return true;
  }

  if (in_array($mime, ['text/plain', 'text/csv'], true) || in_array($ext, ['txt', 'csv'], true)) {
    return true;
  }

  return false;
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

function render_attachment_modal_assets(): string {
  static $rendered = false;
  if ($rendered) {
    return '';
  }
  $rendered = true;

  return <<<HTML
<style>
  .attachment-modal-overlay{position:fixed;inset:0;background:rgba(15,23,42,.72);display:none;align-items:center;justify-content:center;padding:20px;z-index:2000;}
  .attachment-modal-overlay.open{display:flex;}
  .attachment-modal{width:min(1100px,96vw);max-height:90vh;background:#fff;border-radius:12px;overflow:hidden;display:flex;flex-direction:column;box-shadow:0 22px 52px rgba(0,0,0,.35);}
  .attachment-modal-head{display:flex;align-items:center;gap:10px;padding:10px 14px;border-bottom:1px solid rgba(0,0,0,.1);}
  .attachment-modal-title{flex:1;min-width:0;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
  .attachment-modal-body{padding:12px;overflow:auto;display:flex;align-items:center;justify-content:center;background:#f8fafc;min-height:320px;}
  .attachment-modal-body img{max-width:100%;max-height:70vh;display:block;}
  .attachment-modal-frame{width:100%;height:70vh;border:1px solid rgba(0,0,0,.12);border-radius:8px;background:#fff;}
  .attachment-open-link{cursor:pointer;}
  .attachment-open-link:not(.btn){font:inherit;padding:0;margin:0;border:0;background:none;color:#2563eb;text-align:left;text-decoration:underline;}
  .attachment-modal-note{color:#475569;text-align:center;}
</style>
<div id="attachmentPreviewModal" class="attachment-modal-overlay" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="attachmentModalTitle">
  <div class="attachment-modal">
    <div class="attachment-modal-head">
      <div class="attachment-modal-title" id="attachmentModalTitle">Attachment</div>
      <a class="btn" id="attachmentModalDownload" href="#" target="_blank" rel="noopener noreferrer">Download</a>
      <button type="button" class="btn" id="attachmentModalClose">Close</button>
    </div>
    <div class="attachment-modal-body" id="attachmentModalBody"></div>
  </div>
</div>
<script>
(() => {
  if (window.__attachmentModalInit) return;
  window.__attachmentModalInit = true;

  const modal = document.getElementById('attachmentPreviewModal');
  const title = document.getElementById('attachmentModalTitle');
  const body = document.getElementById('attachmentModalBody');
  const closeBtn = document.getElementById('attachmentModalClose');
  const downloadBtn = document.getElementById('attachmentModalDownload');
  if (!modal || !title || !body || !closeBtn || !downloadBtn) return;

  const closeModal = () => {
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
    body.innerHTML = '';
  };

  closeBtn.addEventListener('click', closeModal);
  modal.addEventListener('click', (event) => {
    if (event.target === modal) closeModal();
  });
  document.addEventListener('keydown', (event) => {
    if (event.key === 'Escape' && modal.classList.contains('open')) closeModal();
  });

  document.addEventListener('click', (event) => {
    const trigger = event.target.closest('.attachment-open-link');
    if (!trigger) return;

    event.preventDefault();
    const name = trigger.getAttribute('data-attachment-name') || 'Attachment';
    const fileUrl = trigger.getAttribute('data-attachment-file') || '';
    const previewUrl = trigger.getAttribute('data-attachment-preview') || '';
    const canPreview = trigger.getAttribute('data-attachment-previewable') === '1' && previewUrl !== '';
    const isImage = trigger.getAttribute('data-attachment-image') === '1';

    title.textContent = name;
    downloadBtn.href = fileUrl || previewUrl;
    downloadBtn.style.display = (fileUrl || previewUrl) ? '' : 'none';
    body.innerHTML = '';

    if (canPreview) {
      if (isImage) {
        const img = document.createElement('img');
        img.src = previewUrl;
        img.alt = name;
        body.appendChild(img);
      } else {
        const frame = document.createElement('iframe');
        frame.className = 'attachment-modal-frame';
        frame.src = previewUrl;
        frame.setAttribute('title', name);
        body.appendChild(frame);
      }
    } else {
      const note = document.createElement('p');
      note.className = 'attachment-modal-note';
      note.textContent = 'Preview is unavailable for this file type. Use Download to open it externally.';
      body.appendChild(note);
    }

    modal.classList.add('open');
    modal.setAttribute('aria-hidden', 'false');
  });
})();
</script>
HTML;
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
  $can_preview_inline = $preview_src !== '' && is_inline_preview_attachment($display_name, $mime_type);

  $data_name = h($display_name);
  $data_file = h($file_url);
  $data_preview = h($can_preview_inline ? $preview_src : '');
  $data_previewable = $can_preview_inline ? '1' : '0';
  $data_image = $is_image ? '1' : '0';
  $trigger_attrs = ' class="attachment-open-link"'
   . ' data-attachment-name="' . $data_name . '"'
   . ' data-attachment-file="' . $data_file . '"'
   . ' data-attachment-preview="' . $data_preview . '"'
   . ' data-attachment-previewable="' . $data_previewable . '"'
   . ' data-attachment-image="' . $data_image . '"';

  $out = render_attachment_modal_assets();
  $out .= '<div style="display:flex; align-items:center; gap:8px;">';
  if ($is_image) {
   $out .= '<button type="button"' . $trigger_attrs . ' aria-label="' . h('Preview ' . $display_name) . '" style="padding:0; border:0; background:none;">'
     . '<img src="' . h($can_preview_inline ? $preview_src : $file_url) . '" alt="' . h($display_name) . '"'
     . ' style="width:44px; height:44px; object-fit:cover; border-radius:6px; border:1px solid rgba(0,0,0,.12); display:block;" />'
     . '</button>';
  } else {
   $out .= '<span aria-hidden="true" style="font-size:20px; line-height:1;">' . h($icon) . '</span>';
  }
  $out .= '<button type="button"' . $trigger_attrs
    . ' style="padding:0; border:0; background:none; font-size:12px; line-height:1.3; word-break:break-word;">' . h($display_name) . '</button>'
    . '<noscript><a href="' . h($file_url) . '" target="_blank" rel="noopener noreferrer" style="font-size:12px;">Open ' . h($display_name) . '</a></noscript>'
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
  $unread_messages_badge = '';
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

    $unread_stmt = $pdo->prepare("SELECT COUNT(*) FROM messages WHERE recipient_id = ? AND is_read = 0");
    $unread_stmt->execute([$user_id]);
    $unread_count = (int)$unread_stmt->fetchColumn();
    if ($unread_count > 0) {
      $unread_messages_badge = '<a href="messages.php" style="text-decoration:none;" aria-label="' . $unread_count . ' unread message' . ($unread_count === 1 ? '' : 's') . '">'
        . '<span style="display:inline-flex;align-items:center;justify-content:center;background:#dc2626;color:#fff;border-radius:999px;font-size:11px;font-weight:700;min-width:18px;height:18px;padding:0 5px;line-height:1;vertical-align:middle;" title="' . $unread_count . ' unread message' . ($unread_count === 1 ? '' : 's') . '">'
        . $unread_count
        . '</span></a>';
    }
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
	  <div class="topbar-brand">
		<img src='logo1.jpg' class="topbar-logo">
		<a class="brand" href="index.php">Project Manager</a>
	  </div>

	  <div class="topbar-right">
		<?php
		  $tz = new DateTimeZone('America/Los_Angeles');
		  $now = new DateTime('now', $tz);
		  $now_ms = (int)$now->format('U') * 1000;
		?>
		<div class="topbar-meta">
		  <?php if ($username): ?>
            <?= $clock_status_badge ?>
		    <span class="muted topbar-clock-label">LA: <strong id="clock"></strong></span>
		    <span class="muted">Signed in as <strong><?= h($username) ?></strong><?= $unread_messages_badge ?></span>
		    <a class="btn" href="logout.php">Logout</a>
		  <?php else: ?>
		    <a class="btn" href="login.php">Login</a>
		  <?php endif; ?>
		</div>

		<script>
		  (function () {
			let ms = <?= (int)$now_ms ?>;
			function tick() {
			  ms += 1000;
			  const parts = new Intl.DateTimeFormat('en-US', {
				timeZone: 'America/Los_Angeles',
				year: 'numeric',
				month: 'numeric',
				day: 'numeric',
				hour: 'numeric',
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
$is_logged_in = !empty($_SESSION['user_id']);
$is_regular_user = $is_logged_in && (($_SESSION['role'] ?? '') === 'user');
?>	

<nav class="menubar card">
  <div class="menubar-inner">
    <?php if ($is_regular_user): ?>
    <a class="menu-link <?= $current === 'user_page.php' ? 'active' : '' ?>" href="user_page.php">My Profile</a>
    <a class="menu-link <?= $current === 'machine_inquiry_form.php' ? 'active' : '' ?>" href="machine_inquiry_form.php">Machine Inquiry Form</a>
    <?php elseif ($show_mod_menu): ?>
    <a class="menu-link <?= $current === 'index.php' ? 'active' : '' ?>" href="index.php">Home</a>
    <a class="menu-link <?= $current === 'user_page.php' ? 'active' : '' ?>" href="user_page.php">My Profile</a>
    <a class="menu-link <?= $current === 'messages.php' ? 'active' : '' ?>" href="messages.php">Messages</a>
    <a class="menu-link <?= $current === 'time_clock.php' ? 'active' : '' ?>" href="time_clock.php">Time Clock</a>
    <?php render_menu_dropdown('Customer Inquiries', [
      ['href' => 'customer_inquiry_form.php?view=all', 'file' => 'customer_inquiry_form.php', 'label' => 'Customer Inquiries', 'visible' => true],
      ['href' => 'customer_inquiry_form.php', 'file' => 'customer_inquiry_form.php', 'label' => 'Quick Log Inquiry', 'visible' => true],
      ['href' => 'machine_inquiry_form.php', 'file' => 'machine_inquiry_form.php', 'label' => 'Machine Inquiry Form', 'visible' => true],
      ['href' => 'machine_inquiry_admin.php', 'file' => 'machine_inquiry_admin.php', 'label' => 'Inquiry Admin', 'visible' => true],
    ], $current); ?>
    <?php render_menu_dropdown('Projects', [
      ['href' => 'projects.php', 'file' => 'projects.php', 'label' => 'Projects', 'visible' => true],
      ['href' => 'documents.php', 'file' => 'documents.php', 'label' => 'Documents', 'visible' => true],
      ['href' => 'sops.php', 'file' => 'sops.php', 'label' => 'SOP', 'visible' => true],
      ['href' => 'playbooks.php', 'file' => 'playbooks.php', 'label' => 'Playbooks', 'visible' => true],
      ['href' => 'archives.php', 'file' => 'archives.php', 'label' => 'Archives', 'visible' => true],
    ], $current); ?>
    <?php render_menu_dropdown('Sourcing', [
      ['href' => 'vendors.php', 'file' => 'vendors.php', 'label' => 'Vendors', 'visible' => true],
      ['href' => 'freight_forwarders.php', 'file' => 'freight_forwarders.php', 'label' => 'Freight Forwarders', 'visible' => true],
      ['href' => 'sourcing_rfq_form.php', 'file' => 'sourcing_rfq_form.php', 'label' => 'Sourcing RFQ Form', 'visible' => true],
      ['href' => 'sourcing_rfq_tracker.php', 'file' => 'sourcing_rfq_tracker.php', 'label' => 'Sourcing RFQ Tracker', 'visible' => true],
      ['href' => 'alibaba_responses.php', 'file' => 'alibaba_responses.php', 'label' => 'Alibaba Responses', 'visible' => true],
      ['href' => 'shipping_rfq_form.php', 'file' => 'shipping_rfq_form.php', 'label' => 'Shipping RFQ Form', 'visible' => true],
      ['href' => 'shipping_rfq_tracker.php', 'file' => 'shipping_rfq_tracker.php', 'label' => 'Shipping RFQ Tracker', 'visible' => true],
      ['href' => 'order_tracker.php', 'file' => 'order_tracker.php', 'label' => 'Order Tracker', 'visible' => true],
    ], $current); ?>
    <a class="menu-link <?= in_array($current, ['inventory_list.php', 'inventory_form.php'], true) ? 'active' : '' ?>" href="inventory_list.php">Inventory</a>
    <?php if ($show_admin_menu): ?>
    <a class="menu-link <?= $current === 'admin_backend.php' ? 'active' : '' ?>" href="admin_backend.php">Admin Backend</a>
    <?php endif; ?>
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

/**
 * Renders the Alibaba procurement workflow banner.
 *
 * @param string $current_step  One of the workflow stage keys (e.g. 'create_rfq').
 *                              Defaults to the first step.
 */
function render_alibaba_workflow_banner(string $current_step = 'create_rfq'): void {
  static $css_rendered = false;

  $steps = [
    'create_rfq' => [
      'label'       => 'Create RFQ',
      'instruction' => 'Submit a request for quotation to Alibaba suppliers to begin the procurement process.',
      'url'         => 'sourcing_rfq_form.php',
    ],
    'copy_send_rfq' => [
      'label'       => 'Copy & Send RFQ',
      'instruction' => 'Copy the prepared RFQ content and send it to target suppliers on Alibaba.',
      'url'         => 'sourcing_rfq_submitted.php',
    ],
    'receive_quotes' => [
      'label'       => 'Receive Quotes',
      'instruction' => 'Wait for suppliers to respond. Review incoming quotes as they arrive in the RFQ Tracker.',
      'url'         => 'sourcing_rfq_tracker.php',
    ],
    'select_winning_quote' => [
      'label'       => 'Select Winning Quote',
      'instruction' => 'Compare submitted quotes and select the best supplier based on pricing, quality, and terms.',
      'url'         => 'sourcing_rfq_tracker.php',
    ],
    'create_purchase_order' => [
      'label'       => 'Create Purchase Order',
      'instruction' => 'Convert the winning quote into a formal purchase order.',
      'url'         => 'order_form.php',
    ],
    'send_purchase_order' => [
      'label'       => 'Send Purchase Order',
      'instruction' => 'Issue the formal Purchase Order. The supplier will confirm receipt and acceptance.',
      'url'         => 'sourcing_rfq_tracker.php',
    ],
    'vendor_accepts_po' => [
      'label'       => 'Vendor Accepts PO',
      'instruction' => 'Confirm the vendor has acknowledged and accepted the Purchase Order in writing.',
      'url'         => 'order_tracker.php?status=vendor_accepts_po',
    ],
    'in_production' => [
      'label'       => 'In Production',
      'instruction' => 'Production is underway. Monitor milestones and supplier updates until goods are ready to ship.',
      'url'         => 'order_tracker.php?status=vendor_produces_machine',
    ],
    'shipping' => [
      'label'       => 'Shipping',
      'instruction' => 'The order is in transit. Track shipment progress and required logistics documents.',
      'url'         => 'order_tracker.php?status=vendor_ships_machine',
    ],
    'received' => [
      'label'       => 'Received',
      'instruction' => 'Confirm delivery and completion of final receipt and acceptance activities.',
      'url'         => 'order_tracker.php?status=final_inspection_acceptance',
    ],
  ];

  $legacy_step_aliases = [
    'copy_rfq_text'               => 'copy_send_rfq',
    'evaluate_select_quote'       => 'select_winning_quote',
    'negotiate_terms'             => 'select_winning_quote',
    'create_order'                => 'create_purchase_order',
    'send_po'                     => 'send_purchase_order',
    'make_deposit_payment'        => 'in_production',
    'vendor_produces_machine'     => 'in_production',
    'make_final_payment'          => 'shipping',
    'vendor_ships_machine'        => 'shipping',
    'receive_tracking_documents'  => 'shipping',
    'arrives_clears_customs'      => 'shipping',
    'final_inspection_acceptance' => 'received',
  ];
  if (isset($legacy_step_aliases[$current_step])) {
    $current_step = $legacy_step_aliases[$current_step];
  }

  $step_keys     = array_keys($steps);
  $total         = count($step_keys);
  $current_index = array_search($current_step, $step_keys, true);
  if ($current_index === false) {
    $current_index = 0;
    $current_step  = $step_keys[0];
  }
  $current_label       = $steps[$current_step]['label'];
  $current_instruction = $steps[$current_step]['instruction'];

  if (!$css_rendered) {
    $css_rendered = true;
    ?>
<style>
.awb-wrap{border:1px solid #e5e7eb;border-radius:14px;background:#fff;box-shadow:0 1px 4px rgba(15,23,42,.07);margin:0 0 20px;overflow:hidden;}
.awb-head{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;padding:14px 18px 12px;border-bottom:1px solid #f1f5f9;}
.awb-head-left{display:flex;align-items:center;gap:10px;}
.awb-head-icon{font-size:20px;line-height:1;}
.awb-head-title{font-size:15px;font-weight:700;color:#111827;margin:0;}
.awb-head-badge{display:inline-flex;align-items:center;padding:3px 11px;border-radius:20px;font-size:11px;font-weight:700;letter-spacing:.06em;text-transform:uppercase;background:#dbeafe;color:#1e40af;border:1px solid #bfdbfe;}
.awb-track-outer{overflow-x:auto;padding:16px 18px 0;}
.awb-track{display:flex;align-items:flex-start;gap:0;min-width:max-content;padding-bottom:12px;}
.awb-step{display:flex;align-items:flex-start;gap:0;}
.awb-step-body{display:flex;flex-direction:column;align-items:center;width:88px;}
.awb-step-circle-wrap{position:relative;display:flex;align-items:center;justify-content:center;width:32px;height:32px;margin-bottom:7px;}
.awb-step-circle{width:32px;height:32px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:12px;font-weight:700;border:2px solid #d1d5db;background:#f9fafb;color:#9ca3af;transition:background .15s,border-color .15s,color .15s;cursor:pointer;text-decoration:none;line-height:1;}
.awb-step-circle:hover{border-color:#93c5fd;background:#eff6ff;color:#2563eb;}
.awb-step.awb-done .awb-step-circle{background:#2563eb;border-color:#2563eb;color:#fff;}
.awb-step.awb-current .awb-step-circle{background:#2563eb;border-color:#1d4ed8;color:#fff;box-shadow:0 0 0 4px rgba(59,130,246,.22);}
.awb-step-label{font-size:11px;line-height:1.3;text-align:center;color:#6b7280;word-break:break-word;max-width:88px;}
.awb-step.awb-done .awb-step-label{color:#374151;}
.awb-step.awb-current .awb-step-label{color:#1e40af;font-weight:600;}
.awb-connector{width:28px;flex-shrink:0;height:32px;display:flex;align-items:center;justify-content:center;}
.awb-connector-line{height:2px;width:100%;background:#e5e7eb;border-radius:1px;}
.awb-step.awb-done+.awb-connector .awb-connector-line{background:#2563eb;}
.awb-instruction{display:flex;align-items:flex-start;gap:10px;margin:0 18px 16px;padding:11px 14px;background:#eff6ff;border:1px solid #bfdbfe;border-radius:9px;}
.awb-instruction-icon{font-size:16px;line-height:1;flex-shrink:0;margin-top:1px;}
.awb-instruction-text{font-size:13px;line-height:1.5;color:#1e40af;}
.awb-instruction-step{font-weight:700;color:#1e40af;}
@media(max-width:640px){.awb-head{padding:12px 14px 10px;}.awb-track-outer{padding:14px 14px 0;}.awb-instruction{margin:0 14px 14px;}}
</style>
    <?php
  }
  ?>
<div class="awb-wrap">
  <div class="awb-head">
    <div class="awb-head-left">
      <span class="awb-head-icon" aria-hidden="true">🛒</span>
      <h2 class="awb-head-title">Alibaba Procurement Workflow</h2>
    </div>
    <span class="awb-head-badge">Step <?= ($current_index + 1) ?> of <?= $total ?>: <?= h($current_label) ?></span>
  </div>
  <div class="awb-track-outer" role="list" aria-label="Workflow steps">
    <div class="awb-track">
      <?php foreach ($step_keys as $i => $key):
        $is_done    = $i < $current_index;
        $is_current = $i === $current_index;
        $step_data  = $steps[$key];
        $css_class  = 'awb-step' . ($is_done ? ' awb-done' : '') . ($is_current ? ' awb-current' : '');
        $aria_label = 'Step ' . ($i + 1) . ': ' . $step_data['label'] . ($is_current ? ' (current)' : ($is_done ? ' (completed)' : ''));
      ?>
        <div class="<?= $css_class ?>" role="listitem">
          <div class="awb-step-body">
            <a class="awb-step-circle"
               href="<?= h($step_data['url']) ?>"
               aria-label="<?= h($aria_label) ?>"
               title="<?= h($step_data['label']) ?>"><?= ($i + 1) ?></a>
            <span class="awb-step-label"><?= h($step_data['label']) ?></span>
          </div>
        </div>
        <?php if ($i < $total - 1): ?>
          <div class="awb-connector" aria-hidden="true"><div class="awb-connector-line"></div></div>
        <?php endif; ?>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="awb-instruction" role="note">
    <span class="awb-instruction-icon" aria-hidden="true">💡</span>
    <p class="awb-instruction-text" style="margin:0;">
      <span class="awb-instruction-step">Step <?= ($current_index + 1) ?>: <?= h($current_label) ?> —</span>
      <?= h($current_instruction) ?>
    </p>
  </div>
</div>
  <?php
}

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
