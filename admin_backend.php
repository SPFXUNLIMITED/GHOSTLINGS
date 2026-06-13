<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_admin();

const CR_LABEL_MAX = 100;
const CR_BODY_MAX  = 2000;
const CR_SLOT_COUNT = 4;
const HUBSPOT_TOKEN_MAX = 512;
const STRIPE_SECRET_KEY_MAX = 512;
const RECENT_ACTIVITY_PER_PAGE = 20;

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
if (empty($_SESSION['admin_backend_csrf'])) {
  $_SESSION['admin_backend_csrf'] = bin2hex(random_bytes(24));
}
if (empty($_SESSION['app_request_tracker_csrf'])) {
  $_SESSION['app_request_tracker_csrf'] = bin2hex(random_bytes(24));
}
if (empty($_SESSION['payroll_export_csrf'])) {
  $_SESSION['payroll_export_csrf'] = bin2hex(random_bytes(24));
}

$allowed_sections = [
  'dashboard',
  'users',
  'time_reports',
  'payroll_export',
  'canned_responses',
  'integrations',
  'system_settings',
];

$section = (string)($_GET['section'] ?? 'dashboard');
if ($section === 'overview') {
  $section = 'dashboard';
}
if (!in_array($section, $allowed_sections, true)) {
  $section = 'dashboard';
}

$cr_success = '';
$cr_errors  = [];
$integrations_success = '';
$integrations_errors = [];
$users_errors = [];
$users_success = '';
$hubspot_token_is_set = false;
$hubspot_token_updated_at = '';
$stripe_secret_key_is_set = false;
$stripe_secret_key_updated_at = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $section === 'canned_responses') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['admin_backend_csrf'], $csrf)) {
    $cr_errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    for ($i = 1; $i <= CR_SLOT_COUNT; $i++) {
      $lbl  = trim((string)($_POST["cr_label_{$i}"] ?? ''));
      $body = trim((string)($_POST["cr_body_{$i}"] ?? ''));
      if (strlen($lbl) > CR_LABEL_MAX) {
        $cr_errors[] = "Response {$i} label must be " . CR_LABEL_MAX . ' characters or fewer.';
      }
      if (strlen($body) > CR_BODY_MAX) {
        $cr_errors[] = "Response {$i} body must be " . CR_BODY_MAX . ' characters or fewer.';
      }
    }

    if (!$cr_errors) {
      for ($i = 1; $i <= CR_SLOT_COUNT; $i++) {
        $lbl  = trim((string)($_POST["cr_label_{$i}"] ?? ''));
        $body = trim((string)($_POST["cr_body_{$i}"] ?? ''));
        $pdo->prepare(
          "INSERT INTO rfq_canned_responses (slot, label, body) VALUES (?, ?, ?)
           ON DUPLICATE KEY UPDATE label = ?, body = ?"
        )->execute([$i, $lbl, $body, $lbl, $body]);
      }
      $_SESSION['admin_backend_csrf'] = bin2hex(random_bytes(24));
      $cr_success = 'Canned responses saved.';
    }
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $section === 'integrations') {
  $csrf = (string)($_POST['csrf_token'] ?? '');
  if (!hash_equals((string)$_SESSION['admin_backend_csrf'], $csrf)) {
    $integrations_errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    $submitted_hubspot_token = trim((string)($_POST['hubspot_private_app_token'] ?? ''));
    $clear_hubspot_token = !empty($_POST['clear_hubspot_token']);
    $submitted_stripe_secret_key = trim((string)($_POST['stripe_secret_key'] ?? ''));
    $clear_stripe_secret_key = !empty($_POST['clear_stripe_secret_key']);

    if (strlen($submitted_hubspot_token) > HUBSPOT_TOKEN_MAX) {
      $integrations_errors[] = 'HubSpot token must be ' . HUBSPOT_TOKEN_MAX . ' characters or fewer.';
    }
    if (strlen($submitted_stripe_secret_key) > STRIPE_SECRET_KEY_MAX) {
      $integrations_errors[] = 'Stripe secret key must be ' . STRIPE_SECRET_KEY_MAX . ' characters or fewer.';
    }

    if (!$integrations_errors) {
      try {
        app_ensure_integration_settings_table($pdo);
        app_settings_crypto_key();

        $existing_stmt = $pdo->prepare("SELECT setting_val FROM integration_settings WHERE setting_key = 'hubspot_private_app_token' LIMIT 1");
        $existing_stmt->execute();
        $existing_value = (string)($existing_stmt->fetchColumn() ?? '');

        $value_to_store = $existing_value;
        $is_encrypted = 1;

        if ($submitted_hubspot_token !== '') {
          $value_to_store = app_encrypt_setting_value($submitted_hubspot_token);
        } elseif ($clear_hubspot_token) {
          $value_to_store = '';
          $is_encrypted = 0;
        }

        $db_value_to_store = $value_to_store === '' ? null : $value_to_store;
        $pdo->prepare(
          "INSERT INTO integration_settings (setting_key, setting_val, is_encrypted)
           VALUES ('hubspot_private_app_token', ?, ?)
           ON DUPLICATE KEY UPDATE setting_val = ?, is_encrypted = ?"
        )->execute([
          $db_value_to_store,
          $is_encrypted,
          $db_value_to_store,
          $is_encrypted,
        ]);

        $existing_stmt = $pdo->prepare("SELECT setting_val FROM integration_settings WHERE setting_key = 'stripe_secret_key' LIMIT 1");
        $existing_stmt->execute();
        $existing_value = (string)($existing_stmt->fetchColumn() ?? '');

        $value_to_store = $existing_value;
        $is_encrypted = 1;

        if ($submitted_stripe_secret_key !== '') {
          $value_to_store = app_encrypt_setting_value($submitted_stripe_secret_key);
        } elseif ($clear_stripe_secret_key) {
          $value_to_store = '';
          $is_encrypted = 0;
        }

        $db_value_to_store = $value_to_store === '' ? null : $value_to_store;
        $pdo->prepare(
          "INSERT INTO integration_settings (setting_key, setting_val, is_encrypted)
           VALUES ('stripe_secret_key', ?, ?)
           ON DUPLICATE KEY UPDATE setting_val = ?, is_encrypted = ?"
        )->execute([
          $db_value_to_store,
          $is_encrypted,
          $db_value_to_store,
          $is_encrypted,
        ]);

        $_SESSION['admin_backend_csrf'] = bin2hex(random_bytes(24));
        $messages = [];
        if ($submitted_hubspot_token !== '') {
          $messages[] = 'HubSpot token saved securely.';
        } elseif ($clear_hubspot_token) {
          $messages[] = 'HubSpot token removed.';
        }
        if ($submitted_stripe_secret_key !== '') {
          $messages[] = 'Stripe secret key saved securely.';
        } elseif ($clear_stripe_secret_key) {
          $messages[] = 'Stripe secret key removed.';
        }
        $integrations_success = $messages ? implode(' ', $messages) : 'Integration settings saved.';
      } catch (Throwable $e) {
        error_log('Integrations token save failed: ' . $e->getMessage());
        $integrations_errors[] = 'Unable to save token right now. Please try again. If this continues, check server error logs.';
      }
    }
  }
}

// ── Payroll Export: ADP CSV download (must happen before any output) ──────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $section === 'payroll_export'
    && isset($_POST['export_adp'])) {
  $csrf = (string)($_POST['payroll_csrf'] ?? '');
  if (hash_equals((string)$_SESSION['payroll_export_csrf'], $csrf)) {
    $pe_from = trim($_POST['pe_from'] ?? '');
    $pe_to   = trim($_POST['pe_to']   ?? '');
    $pe_tz   = new DateTimeZone(APP_TZ);

    $pe_stmt = $pdo->prepare("
      SELECT u.username,
        CASE
          WHEN te.hours_override IS NOT NULL THEN te.hours_override
          WHEN te.clock_out IS NOT NULL
            THEN ROUND((
              TIMESTAMPDIFF(SECOND, te.clock_in, te.clock_out) -
              CASE
                WHEN te.lunch_start IS NOT NULL
                  THEN GREATEST(TIMESTAMPDIFF(SECOND, te.lunch_start, COALESCE(te.lunch_end, te.clock_out)), 0)
                ELSE 0
              END
            ) / 3600, 4)
          ELSE NULL
        END AS hours
      FROM time_entries te
      JOIN users u ON u.id = te.user_id
      WHERE DATE(te.clock_in) >= ? AND DATE(te.clock_in) <= ?
      ORDER BY u.username ASC, te.clock_in ASC
    ");
    $pe_stmt->execute([$pe_from, $pe_to]);
    $pe_rows = $pe_stmt->fetchAll();

    $pe_by_emp = [];
    foreach ($pe_rows as $r) {
      $emp = $r['username'];
      if (!isset($pe_by_emp[$emp])) {
        $pe_by_emp[$emp] = 0.0;
      }
      if ($r['hours'] !== null) {
        $pe_by_emp[$emp] += (float)$r['hours'];
      }
    }

    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="payroll_adp_' . date('Ymd') . '.csv"');
    $fh = fopen('php://output', 'w');
    fputcsv($fh, ['Employee', 'Regular Hours', 'Pay Period Start', 'Pay Period End']);
    foreach ($pe_by_emp as $emp => $hrs) {
      fputcsv($fh, [$emp, number_format($hrs, 2), $pe_from, $pe_to]);
    }
    fclose($fh);
    exit;
  }
}

// ── Payroll Export: save edited entry ────────────────────────────────────────
$payroll_save_errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $section === 'payroll_export'
    && isset($_POST['pe_save_entry'])) {
  $csrf = (string)($_POST['payroll_csrf'] ?? '');
  if (!hash_equals((string)$_SESSION['payroll_export_csrf'], $csrf)) {
    $payroll_save_errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    $pe_tz       = new DateTimeZone(APP_TZ);
    $pe_entry_id = (int)($_POST['entry_id'] ?? 0);
    $pe_uid_save = (int)($_POST['user_id']  ?? 0);
    $pe_ci_raw   = trim($_POST['clock_in']       ?? '');
    $pe_co_raw   = trim($_POST['clock_out']      ?? '');
    $pe_ho_raw   = trim($_POST['hours_override'] ?? '');
    $pe_desc     = trim($_POST['description']    ?? '');

    $pe_ci = $pe_ci_raw !== '' ? DateTime::createFromFormat('Y-m-d\TH:i', $pe_ci_raw, $pe_tz) : false;
    $pe_co = $pe_co_raw !== '' ? DateTime::createFromFormat('Y-m-d\TH:i', $pe_co_raw, $pe_tz) : null;
    $pe_ho = $pe_ho_raw !== '' ? (float)$pe_ho_raw : null;

    if ($pe_entry_id <= 0)  $payroll_save_errors[] = 'Invalid entry.';
    if (!$pe_ci)            $payroll_save_errors[] = 'Clock-in date/time is required and must be valid.';
    if ($pe_uid_save <= 0)  $payroll_save_errors[] = 'Employee is required.';

    if (!$payroll_save_errors) {
      $pdo->prepare("UPDATE time_entries
                     SET user_id=?, clock_in=?, clock_out=?, hours_override=?, description=?
                     WHERE id=?")
          ->execute([
            $pe_uid_save,
            $pe_ci->format('Y-m-d H:i:s'),
            $pe_co ? $pe_co->format('Y-m-d H:i:s') : null,
            $pe_ho,
            $pe_desc ?: null,
            $pe_entry_id,
          ]);
      $_SESSION['payroll_export_csrf'] = bin2hex(random_bytes(24));
      header('Location: admin_backend.php?section=payroll_export');
      exit;
    }
  }
}

// ── Payroll Export: delete entry ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $section === 'payroll_export'
    && isset($_POST['pe_delete_entry'])) {
  $csrf = (string)($_POST['payroll_csrf'] ?? '');
  if (hash_equals((string)$_SESSION['payroll_export_csrf'], $csrf)) {
    $pe_del_id = (int)($_POST['entry_id'] ?? 0);
    if ($pe_del_id > 0) {
      $pdo->prepare("DELETE FROM time_entries WHERE id = ?")->execute([$pe_del_id]);
    }
    $_SESSION['payroll_export_csrf'] = bin2hex(random_bytes(24));
    header('Location: admin_backend.php?section=payroll_export');
    exit;
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $section === 'users') {
  $users_action = (string)($_POST['action'] ?? '');

  // ADD USER
  if ($users_action === 'add') {
    $new_username  = trim($_POST['new_username'] ?? '');
    $new_password  = (string)($_POST['new_password'] ?? '');
    $new_password2 = (string)($_POST['new_password2'] ?? '');
    $new_role      = (string)($_POST['new_role'] ?? 'user');
    if (!in_array($new_role, ['admin','moderator','user'], true)) $new_role = 'user';
    $new_is_admin  = ($new_role === 'admin') ? 1 : 0;

    if ($new_username === '') {
      $users_errors[] = 'Username is required.';
    } elseif (strlen($new_username) > 64) {
      $users_errors[] = 'Username must be 64 characters or fewer.';
    } elseif (!preg_match('/^[A-Za-z0-9][A-Za-z0-9_.\-]*[A-Za-z0-9]$|^[A-Za-z0-9]$/', $new_username)
              || str_contains($new_username, '..')) {
      $users_errors[] = 'Username may only contain letters, numbers, underscores, hyphens, and dots; it must start and end with a letter or number and must not contain consecutive dots.';
    } elseif (strlen($new_password) < 6) {
      $users_errors[] = 'Password must be at least 6 characters.';
    } elseif ($new_password !== $new_password2) {
      $users_errors[] = 'Passwords do not match.';
    } else {
      $ck = $pdo->prepare("SELECT id FROM users WHERE username = ? LIMIT 1");
      $ck->execute([$new_username]);
      if ($ck->fetch()) {
        $users_errors[] = 'That username is already taken.';
      } else {
        $hash = password_hash($new_password, PASSWORD_DEFAULT);
        $ins  = $pdo->prepare("INSERT INTO users (username, password_hash, is_admin, role, email_verified) VALUES (?, ?, ?, ?, 1)");
        $ins->execute([$new_username, $hash, $new_is_admin, $new_role]);
        $users_success = 'User "' . htmlspecialchars($new_username, ENT_QUOTES, 'UTF-8') . '" created successfully.';
      }
    }
  }

  // CHANGE PASSWORD
  elseif ($users_action === 'change_password') {
    $uid = (int)($_POST['uid'] ?? 0);
    $pw1 = (string)($_POST['password1'] ?? '');
    $pw2 = (string)($_POST['password2'] ?? '');

    if ($uid <= 0) {
      $users_errors[] = 'Invalid user.';
    } elseif (strlen($pw1) < 6) {
      $users_errors[] = 'Password must be at least 6 characters.';
    } elseif ($pw1 !== $pw2) {
      $users_errors[] = 'Passwords do not match.';
    } else {
      $hash = password_hash($pw1, PASSWORD_DEFAULT);
      $upd  = $pdo->prepare("UPDATE users SET password_hash = ? WHERE id = ?");
      $upd->execute([$hash, $uid]);
      $users_success = 'Password updated.';
    }
  }

  // TOGGLE ADMIN
  elseif ($users_action === 'toggle_admin') {
    $uid = (int)($_POST['uid'] ?? 0);
    if ($uid <= 0) {
      $users_errors[] = 'Invalid user.';
    } elseif ($uid === current_user_id()) {
      $users_errors[] = 'You cannot change your own admin status.';
    } else {
      $row = $pdo->prepare("SELECT is_admin, role FROM users WHERE id = ? LIMIT 1");
      $row->execute([$uid]);
      $target = $row->fetch();
      if (!$target) {
        $users_errors[] = 'User not found.';
      } else {
        if ($target['is_admin']) {
          $new_admin = 0;
          $new_role  = ($target['role'] === 'moderator') ? 'moderator' : 'user';
        } else {
          $new_admin = 1;
          $new_role  = 'admin';
        }
        $pdo->prepare("UPDATE users SET is_admin = ?, role = ? WHERE id = ?")->execute([$new_admin, $new_role, $uid]);
        $users_success = 'Admin status updated.';
      }
    }
  }

  // SET ROLE
  elseif ($users_action === 'set_role') {
    $uid      = (int)($_POST['uid'] ?? 0);
    $new_role = (string)($_POST['new_role'] ?? '');
    if ($uid <= 0) {
      $users_errors[] = 'Invalid user.';
    } elseif ($uid === current_user_id()) {
      $users_errors[] = 'You cannot change your own role.';
    } elseif (!in_array($new_role, ['admin','moderator','user'], true)) {
      $users_errors[] = 'Invalid role.';
    } else {
      $new_is_admin = ($new_role === 'admin') ? 1 : 0;
      $pdo->prepare("UPDATE users SET role = ?, is_admin = ? WHERE id = ?")->execute([$new_role, $new_is_admin, $uid]);
      $users_success = 'Role updated to ' . $new_role . '.';
    }
  }

  // DELETE USER
  elseif ($users_action === 'delete') {
    $uid = (int)($_POST['uid'] ?? 0);
    if ($uid <= 0) {
      $users_errors[] = 'Invalid user.';
    } elseif ($uid === current_user_id()) {
      $users_errors[] = 'You cannot delete your own account.';
    } else {
      $pdo->prepare("UPDATE tasks SET assigned_to = NULL WHERE assigned_to = ?")->execute([$uid]);
      $pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$uid]);
      $users_success = 'User deleted.';
    }
  }
}

$users_list = [];
if ($section === 'users') {
  $users_list = $pdo->query("SELECT id, username, email, is_admin, role FROM users ORDER BY id ASC")->fetchAll();
}

// ── Payroll Export data ───────────────────────────────────────────────────────
$pe_tz_obj      = new DateTimeZone(APP_TZ);
$pe_from        = '';
$pe_to          = '';
$pe_entries     = [];
$pe_by_employee = [];
$pe_all_users   = [];
$pe_editing     = null;

// Additional payroll variables for the two-card layout
$pe_last_from        = '';
$pe_last_to          = '';
$pe_curr_from        = '';
$pe_curr_to          = '';
$pe_last_entries     = [];
$pe_last_by_employee = [];

if ($section === 'payroll_export') {
  $pe_now = new DateTime('now', $pe_tz_obj);
  $pe_dow = (int)$pe_now->format('N'); // 1=Mon .. 7=Sun

  // Last completed pay period: most recently finished Mon–Fri work week.
  // Days back to reach the last completed Friday (today's Friday counts as "in progress").
  $pe_days_to_last_fri = ($pe_dow <= 5) ? ($pe_dow + 2) : ($pe_dow - 5);
  $pe_last_fri_dt = (clone $pe_now)->modify("-{$pe_days_to_last_fri} days");
  $pe_last_mon_dt = (clone $pe_last_fri_dt)->modify('-4 days');
  $pe_last_from   = $pe_last_mon_dt->format('Y-m-d');
  $pe_last_to     = $pe_last_fri_dt->format('Y-m-d');

  // Current pay period: Monday of the current week to today (capped at Friday on weekends).
  $pe_days_from_mon = $pe_dow - 1;
  $pe_curr_mon_dt   = (clone $pe_now)->modify("-{$pe_days_from_mon} days");
  $pe_curr_end_dt   = ($pe_dow >= 6) ? (clone $pe_curr_mon_dt)->modify('+4 days') : $pe_now;
  $pe_curr_from     = $pe_curr_mon_dt->format('Y-m-d');
  $pe_curr_to       = $pe_curr_end_dt->format('Y-m-d');

  $pe_all_users = $pdo->query("SELECT id, username FROM users ORDER BY username ASC")->fetchAll();

  // Last completed pay period: full detailed entries (for the top card + ADP export)
  $pe_last_stmt = $pdo->prepare("
    SELECT
      te.id,
      te.user_id,
      te.clock_in,
      te.clock_out,
      te.lunch_start,
      te.lunch_end,
      te.hours_override,
      te.description,
      u.username,
      CASE
        WHEN te.hours_override IS NOT NULL THEN te.hours_override
        WHEN te.clock_out IS NOT NULL
          THEN ROUND((
            TIMESTAMPDIFF(SECOND, te.clock_in, te.clock_out) -
            CASE
              WHEN te.lunch_start IS NOT NULL
                THEN GREATEST(TIMESTAMPDIFF(SECOND, te.lunch_start, COALESCE(te.lunch_end, te.clock_out)), 0)
              ELSE 0
            END
          ) / 3600, 2)
        ELSE NULL
      END AS hours
    FROM time_entries te
    JOIN users u ON u.id = te.user_id
    WHERE DATE(te.clock_in) >= ? AND DATE(te.clock_in) <= ?
    ORDER BY u.username ASC, te.clock_in DESC
  ");
  $pe_last_stmt->execute([$pe_last_from, $pe_last_to]);
  $pe_last_entries = $pe_last_stmt->fetchAll();
  foreach ($pe_last_entries as $r) {
    $emp = $r['username'];
    if (!isset($pe_last_by_employee[$emp])) {
      $pe_last_by_employee[$emp] = ['username' => $emp, 'total_hours' => 0.0, 'entries' => 0];
    }
    $pe_last_by_employee[$emp]['entries']++;
    if ($r['hours'] !== null) {
      $pe_last_by_employee[$emp]['total_hours'] += (float)$r['hours'];
    }
  }

  // Current pay period: detailed entries (for the bottom card)
  $pe_stmt = $pdo->prepare("
    SELECT
      te.id,
      te.user_id,
      te.clock_in,
      te.clock_out,
      te.lunch_start,
      te.lunch_end,
      te.hours_override,
      te.description,
      u.username,
      CASE
        WHEN te.hours_override IS NOT NULL THEN te.hours_override
        WHEN te.clock_out IS NOT NULL
          THEN ROUND((
            TIMESTAMPDIFF(SECOND, te.clock_in, te.clock_out) -
            CASE
              WHEN te.lunch_start IS NOT NULL
                THEN GREATEST(TIMESTAMPDIFF(SECOND, te.lunch_start, COALESCE(te.lunch_end, te.clock_out)), 0)
              ELSE 0
            END
          ) / 3600, 2)
        ELSE NULL
      END AS hours
    FROM time_entries te
    JOIN users u ON u.id = te.user_id
    WHERE DATE(te.clock_in) >= ? AND DATE(te.clock_in) <= ?
    ORDER BY u.username ASC, te.clock_in DESC
  ");
  $pe_stmt->execute([$pe_curr_from, $pe_curr_to]);
  $pe_entries = $pe_stmt->fetchAll();

  foreach ($pe_entries as $r) {
    $emp = $r['username'];
    if (!isset($pe_by_employee[$emp])) {
      $pe_by_employee[$emp] = ['username' => $emp, 'total_hours' => 0.0, 'entries' => 0];
    }
    $pe_by_employee[$emp]['entries']++;
    if ($r['hours'] !== null) {
      $pe_by_employee[$emp]['total_hours'] += (float)$r['hours'];
    }
  }

  // Load entry being edited
  if (($_GET['pe_action'] ?? '') === 'edit' && isset($_GET['pe_id'])) {
    $pe_edit_id = (int)$_GET['pe_id'];
    $pe_stmt2   = $pdo->prepare("SELECT * FROM time_entries WHERE id = ?");
    $pe_stmt2->execute([$pe_edit_id]);
    $pe_editing = $pe_stmt2->fetch() ?: null;
  }
}

if (!function_exists('excerpt_text')) {
  function excerpt_text(string $text, int $limit): string {
    $text = trim($text);
    if ($limit <= 0 || $text === '') return '';
    if (function_exists('mb_strlen') && function_exists('mb_substr')) {
      if (mb_strlen($text, 'UTF-8') <= $limit) return $text;
      return rtrim(mb_substr($text, 0, $limit, 'UTF-8')) . '…';
    }
    if (strlen($text) <= $limit) return $text;
    return rtrim(substr($text, 0, $limit)) . '…';
  }
}

$canned = [];
if ($section === 'canned_responses') {
  $rows = $pdo->query(
    'SELECT slot, label, body FROM rfq_canned_responses WHERE slot IN (1,2,3,4) ORDER BY slot'
  )->fetchAll();
  foreach ($rows as $r) {
    $canned[(int)$r['slot']] = $r;
  }
  for ($i = 1; $i <= CR_SLOT_COUNT; $i++) {
    if (!isset($canned[$i])) {
      $canned[$i] = ['slot' => $i, 'label' => '', 'body' => ''];
    }
  }
}

if ($section === 'integrations') {
  try {
    app_ensure_integration_settings_table($pdo);
    app_settings_crypto_key();
  } catch (Throwable $e) {
    error_log('Integrations encryption initialization failed: ' . $e->getMessage());
    $integrations_errors[] = 'Unable to initialize secure encryption for integration settings.';
  }

  $row = $pdo->prepare(
    "SELECT setting_val, updated_at
     FROM integration_settings
     WHERE setting_key = 'hubspot_private_app_token'
     LIMIT 1"
  );
  $row->execute();
  $integration = $row->fetch() ?: [];
  $hubspot_token_is_set = trim((string)($integration['setting_val'] ?? '')) !== '';
  $hubspot_token_updated_at = trim((string)($integration['updated_at'] ?? ''));

  $row = $pdo->prepare(
    "SELECT setting_val, updated_at
     FROM integration_settings
     WHERE setting_key = 'stripe_secret_key'
     LIMIT 1"
  );
  $row->execute();
  $integration = $row->fetch() ?: [];
  $stripe_secret_key_is_set = trim((string)($integration['setting_val'] ?? '')) !== '';
  $stripe_secret_key_updated_at = trim((string)($integration['updated_at'] ?? ''));
}

$total_users = 0;
$count_phone_inquiries = 0;
$count_machine_inquiries = 0;
$count_rfq_requests = 0;
$count_shipping_rfq = 0;
$count_app_requests = 0;
$recent_activity = [];
$recent_activity_total = 0;
$recent_activity_page = 1;
$activity_type_options = ['Attendance', 'Time Entry', 'Quick Order', 'App Request', 'Page View'];
$activity_type_filter = (string)($_GET['activity_type'] ?? 'all');
if ($activity_type_filter !== 'all' && !in_array($activity_type_filter, $activity_type_options, true)) {
  $activity_type_filter = 'all';
}
$activity_sort = (string)($_GET['activity_sort'] ?? 'when');
$activity_sort_options = ['type', 'actor', 'when'];
if (!in_array($activity_sort, $activity_sort_options, true)) {
  $activity_sort = 'when';
}
$activity_dir = strtolower((string)($_GET['activity_dir'] ?? 'desc'));
if (!in_array($activity_dir, ['asc', 'desc'], true)) {
  $activity_dir = 'desc';
}
$activity_query_url = static function (array $overrides = []): string {
  $params = [];
  foreach ($_GET as $key => $value) {
    if (is_scalar($value)) {
      $params[$key] = (string)$value;
    }
  }
  $params['section'] = 'dashboard';
  foreach ($overrides as $key => $value) {
    if ($value === null || $value === '') {
      unset($params[$key]);
    } else {
      $params[$key] = (string)$value;
    }
  }
  return 'admin_backend.php?' . http_build_query($params);
};
$activity_sort_url = static function (string $column) use ($activity_query_url, $activity_sort, $activity_dir): string {
  $next_dir = ($activity_sort === $column && $activity_dir === 'asc') ? 'desc' : 'asc';
  return $activity_query_url([
    'activity_sort' => $column,
    'activity_dir' => $next_dir,
    'activity_page' => null,
  ]);
};
$activity_sort_indicator = static function (string $column) use ($activity_sort, $activity_dir): string {
  if ($activity_sort !== $column) {
    return '';
  }
  return $activity_dir === 'asc' ? ' ↑' : ' ↓';
};
$render_activity_filter = static function () use ($activity_sort, $activity_dir, $activity_type_options, $activity_type_filter): void {
  ?>
  <form method="get" action="admin_backend.php" style="display:flex; gap:10px; align-items:end; flex-wrap:wrap;">
    <input type="hidden" name="section" value="dashboard" />
    <input type="hidden" name="activity_sort" value="<?= h($activity_sort) ?>" />
    <input type="hidden" name="activity_dir" value="<?= h($activity_dir) ?>" />
    <div>
      <label for="activity_type" style="display:block; margin-bottom:4px;">Type</label>
      <select id="activity_type" name="activity_type">
        <option value="all">All</option>
        <?php foreach ($activity_type_options as $option): ?>
          <option value="<?= h($option) ?>" <?= $activity_type_filter === $option ? 'selected' : '' ?>><?= h($option) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <button type="submit" class="btn">Filter</button>
  </form>
  <?php
};

if ($section === 'dashboard') {
  try {
    $total_users = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
  } catch (Throwable $e) {
    $total_users = 0;
  }

  try {
    $count_phone_inquiries = (int)$pdo->query('SELECT COUNT(*) FROM customer_phone_inquiries')->fetchColumn();
  } catch (Throwable $e) {
    $count_phone_inquiries = 0;
  }
  try {
    $count_machine_inquiries = (int)$pdo->query('SELECT COUNT(*) FROM machine_inquiries')->fetchColumn();
  } catch (Throwable $e) {
    $count_machine_inquiries = 0;
  }
  try {
    $count_rfq_requests = (int)$pdo->query('SELECT COUNT(*) FROM rfq_requests')->fetchColumn();
  } catch (Throwable $e) {
    $count_rfq_requests = 0;
  }
  try {
    $count_shipping_rfq = (int)$pdo->query('SELECT COUNT(*) FROM shipping_rfq_requests')->fetchColumn();
  } catch (Throwable $e) {
    $count_shipping_rfq = 0;
  }
  try {
    $count_app_requests = (int)$pdo->query('SELECT COUNT(*) FROM app_requests')->fetchColumn();
  } catch (Throwable $e) {
    $count_app_requests = 0;
  }

  try {
    $recent_activity_page = max(1, (int)($_GET['activity_page'] ?? 1));
    $activity_sql = "
      SELECT
        'Time Entry' AS kind,
        COALESCE(u.username, CONCAT('User #', te.user_id)) AS actor,
        COALESCE(NULLIF(TRIM(te.description), ''), 'Clock activity recorded') AS details,
        te.created_at AS occurred_at
      FROM time_entries te
      LEFT JOIN users u ON u.id = te.user_id

      UNION ALL

      SELECT
        'Quick Order' AS kind,
        COALESCE(
          NULLIF(TRIM(u.contact_name), ''),
          NULLIF(TRIM(u.username), ''),
          CASE
            WHEN cpi.created_by IS NOT NULL THEN CONCAT('User #', cpi.created_by)
            ELSE NULL
          END,
          'Unknown user'
        ) AS actor,
        CONCAT(
          'Created Quick Order for ',
          COALESCE(
            NULLIF(TRIM(cpi.company_name), ''),
            NULLIF(TRIM(cpi.customer_name), ''),
            'Unknown customer'
          ),
          CASE
            WHEN NULLIF(TRIM(cpi.company_name), '') IS NOT NULL
              AND NULLIF(TRIM(cpi.customer_name), '') IS NOT NULL
            THEN CONCAT(' (Contact: ', TRIM(cpi.customer_name), ')')
            ELSE ''
          END
        ) AS details,
        cpi.created_at AS occurred_at
      FROM customer_phone_inquiries cpi
      LEFT JOIN users u ON u.id = cpi.created_by

      UNION ALL

      SELECT
        'App Request' AS kind,
        COALESCE(u.username, CONCAT('User #', ar.requested_by)) AS actor,
        COALESCE(NULLIF(TRIM(ar.request_title), ''), 'Request submitted') AS details,
        ar.created_at AS occurred_at
      FROM app_requests ar
      LEFT JOIN users u ON u.id = ar.requested_by

      UNION ALL

      SELECT
        'Attendance' AS kind,
        COALESCE(NULLIF(TRIM(u.username), ''), NULLIF(TRIM(aal.user_label), ''), CONCAT('User #', aal.user_id)) AS actor,
        COALESCE(NULLIF(TRIM(aal.details), ''), 'Attendance activity recorded') AS details,
        aal.created_at AS occurred_at
      FROM admin_activity_log aal
      LEFT JOIN users u ON u.id = aal.user_id
      WHERE aal.action_name = 'Attendance'

      UNION ALL

      SELECT
        'Page View' AS kind,
        COALESCE(u.username, CONCAT('User #', pv.user_id)) AS actor,
        CONCAT('Viewed ', pv.page) AS details,
        pv.viewed_at AS occurred_at
      FROM page_views pv
      LEFT JOIN users u ON u.id = pv.user_id
      WHERE pv.viewed_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
    ";
    $activity_params = [];
    $activity_where_sql = '';
    if ($activity_type_filter !== 'all') {
      $activity_where_sql = ' WHERE kind = :activity_type';
      $activity_params[':activity_type'] = $activity_type_filter;
    }
    $count_stmt = $pdo->prepare("SELECT COUNT(*) FROM ({$activity_sql}) activity{$activity_where_sql}");
    $count_stmt->execute($activity_params);
    $recent_activity_total = (int)$count_stmt->fetchColumn();
    $activity_total_pages = max(1, (int)ceil($recent_activity_total / RECENT_ACTIVITY_PER_PAGE));
    if ($recent_activity_page > $activity_total_pages) {
      $recent_activity_page = $activity_total_pages;
    }
    $activity_offset = ($recent_activity_page - 1) * RECENT_ACTIVITY_PER_PAGE;
    if ($activity_sort === 'type' && $activity_dir === 'asc') {
      $activity_query_sql = "SELECT kind, actor, details, occurred_at
       FROM ({$activity_sql}) activity{$activity_where_sql}
       ORDER BY kind ASC
       LIMIT :activity_limit
       OFFSET :activity_offset";
    } elseif ($activity_sort === 'type') {
      $activity_query_sql = "SELECT kind, actor, details, occurred_at
       FROM ({$activity_sql}) activity{$activity_where_sql}
       ORDER BY kind DESC
       LIMIT :activity_limit
       OFFSET :activity_offset";
    } elseif ($activity_sort === 'actor' && $activity_dir === 'asc') {
      $activity_query_sql = "SELECT kind, actor, details, occurred_at
       FROM ({$activity_sql}) activity{$activity_where_sql}
       ORDER BY actor ASC
       LIMIT :activity_limit
       OFFSET :activity_offset";
    } elseif ($activity_sort === 'actor') {
      $activity_query_sql = "SELECT kind, actor, details, occurred_at
       FROM ({$activity_sql}) activity{$activity_where_sql}
       ORDER BY actor DESC
       LIMIT :activity_limit
       OFFSET :activity_offset";
    } elseif ($activity_dir === 'asc') {
      $activity_query_sql = "SELECT kind, actor, details, occurred_at
       FROM ({$activity_sql}) activity{$activity_where_sql}
       ORDER BY occurred_at ASC
       LIMIT :activity_limit
       OFFSET :activity_offset";
    } else {
      $activity_query_sql = "SELECT kind, actor, details, occurred_at
       FROM ({$activity_sql}) activity{$activity_where_sql}
       ORDER BY occurred_at DESC
       LIMIT :activity_limit
       OFFSET :activity_offset";
    }
    $activity_stmt = $pdo->prepare($activity_query_sql);
    foreach ($activity_params as $name => $value) {
      $activity_stmt->bindValue($name, $value, PDO::PARAM_STR);
    }
    $activity_stmt->bindValue(':activity_limit', RECENT_ACTIVITY_PER_PAGE, PDO::PARAM_INT);
    $activity_stmt->bindValue(':activity_offset', $activity_offset, PDO::PARAM_INT);
    $activity_stmt->execute();
    $recent_activity = $activity_stmt->fetchAll();
  } catch (Throwable $e) {
    $recent_activity = [];
    $recent_activity_total = 0;
    $recent_activity_page = 1;
  }
}

$menu = [
  'dashboard' => ['label' => 'Dashboard', 'subtitle' => 'Overview'],
  'users' => ['label' => 'Users', 'subtitle' => 'Accounts & permissions'],
  'time_reports' => ['label' => 'Time Reports', 'subtitle' => 'Payroll and hour tracking'],
  'payroll_export' => ['label' => 'Payroll Export', 'subtitle' => 'Bi-weekly ADP export'],
  'canned_responses' => ['label' => 'Canned Responses', 'subtitle' => 'RFQ quick responses'],
  'integrations' => ['label' => 'Integrations', 'subtitle' => 'API tokens and external services'],
  'system_settings' => ['label' => 'System Settings', 'subtitle' => 'Configuration and controls'],
];

$format_activity_datetime = static function ($value): string {
  if (empty($value)) return '—';
  
  $dt = DateTime::createFromFormat('Y-m-d H:i:s', (string)$value, new DateTimeZone(APP_TZ));
  if ($dt === false) return '—';
  
  return $dt->format('M j, Y g:i A') . ' PT';
};

render_header('Admin Backend');
?>

<style>
  .admin-shell {
    display: grid;
    grid-template-columns: 260px minmax(0, 1fr);
    gap: 18px;
    align-items: start;
  }

  .admin-sidebar {
    position: sticky;
    top: 12px;
    padding: 12px;
  }

  .admin-brand {
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: .08em;
    color: var(--m);
    margin: 4px 0 12px;
  }

  .admin-nav-link {
    display: block;
    text-decoration: none;
    border: 1px solid transparent;
    border-radius: 10px;
    padding: 10px 12px;
    margin-bottom: 8px;
    color: #334155;
    background: #f8fafc;
    transition: border-color .15s ease, background .15s ease;
  }

  .admin-nav-link:last-child {
    margin-bottom: 0;
  }

  .admin-nav-link:hover {
    background: #f1f5f9;
    border-color: #cbd5e1;
  }

  .admin-nav-link.active {
    background: #eff6ff;
    border-color: #93c5fd;
    color: #1d4ed8;
  }

  .admin-nav-label {
    display: block;
    font-weight: 700;
    line-height: 1.2;
  }

  .admin-nav-subtitle {
    display: block;
    font-size: 12px;
    color: var(--m);
    margin-top: 2px;
  }

  .admin-main {
    min-width: 0;
  }

  .admin-page-title {
    margin: 0;
    font-size: 1.45rem;
  }

  .admin-placeholder {
    text-align: center;
    padding: 30px 16px;
  }

  .admin-recent-table {
    width: 100%;
    border-collapse: collapse;
  }

  .admin-recent-table th,
  .admin-recent-table td {
    border-bottom: 1px solid var(--b);
    padding: 10px 8px;
    text-align: left;
    font-size: 13px;
    vertical-align: top;
  }

  .admin-recent-table th {
    color: var(--m);
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: .04em;
    font-weight: 700;
  }

  .admin-recent-toolbar {
    display: flex;
    justify-content: space-between;
    gap: 12px;
    align-items: end;
    flex-wrap: wrap;
    margin-bottom: 14px;
  }

  .admin-recent-sort {
    color: inherit;
    text-decoration: none;
  }

  .admin-recent-sort:hover {
    text-decoration: underline;
  }

  .admin-grid-two {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: 12px;
  }

  .canned-item + .canned-item {
    margin-top: 22px;
  }

  @media (max-width: 980px) {
    .admin-shell {
      grid-template-columns: 1fr;
    }

    .admin-sidebar {
      position: static;
    }
  }

  @media (max-width: 700px) {
    .admin-grid-two {
      grid-template-columns: 1fr;
    }
  }
</style>

<div class="card page-head">
  <div class="page-head-main">
    <h1 class="admin-page-title">Admin Area</h1>
    <p class="muted">Centralized management for operations, users, reporting, and system controls.</p>
  </div>
</div>

<div class="admin-shell">
  <aside class="card admin-sidebar">
    <div class="admin-brand">Administration</div>
    <?php foreach ($menu as $key => $item): ?>
      <a class="admin-nav-link <?= $section === $key ? 'active' : '' ?>" href="admin_backend.php?section=<?= h($key) ?>">
        <span class="admin-nav-label"><?= h($item['label']) ?></span>
        <span class="admin-nav-subtitle"><?= h($item['subtitle']) ?></span>
      </a>
    <?php endforeach; ?>
  </aside>

  <main class="admin-main">
    <?php if ($section === 'dashboard'): ?>
      <div class="card">
        <h2 style="margin-top:0;">Dashboard Overview</h2>
        <p class="muted">High-level visibility into team activity and incoming requests.</p>

        <div class="stat-grid" style="margin-top:14px;">
          <div class="stat-card">
            <div class="stat-value"><?= number_format($total_users) ?></div>
            <div class="stat-label">Total Users</div>
          </div>
          <div class="stat-card">
            <div class="stat-value"><?= number_format($count_phone_inquiries) ?></div>
            <div class="stat-label">Customer Phone Inquiries</div>
          </div>
          <div class="stat-card">
            <div class="stat-value"><?= number_format($count_machine_inquiries) ?></div>
            <div class="stat-label">Machine Inquiries</div>
          </div>
          <div class="stat-card">
            <div class="stat-value"><?= number_format($count_rfq_requests) ?></div>
            <div class="stat-label">RFQ Requests</div>
          </div>
          <div class="stat-card">
            <div class="stat-value"><?= number_format($count_shipping_rfq) ?></div>
            <div class="stat-label">Shipping RFQ Requests</div>
          </div>
          <div class="stat-card">
            <div class="stat-value"><?= number_format($count_app_requests) ?></div>
            <div class="stat-label">App Requests</div>
          </div>
          <div class="stat-card">
            <div class="stat-value"><?= number_format($recent_activity_total) ?></div>
            <div class="stat-label">Recent Activity Items</div>
          </div>
        </div>
      </div>

      <div class="card">
        <h3 style="margin-top:0;">Recent Activity</h3>
        <?php if (!$recent_activity): ?>
          <div class="admin-recent-toolbar">
            <?php $render_activity_filter(); ?>
          </div>
          <p class="muted" style="margin:0;"><?= $activity_type_filter === 'all' ? 'No activity has been recorded yet.' : 'No activity matches the selected type.' ?></p>
        <?php else: ?>
          <div class="admin-recent-toolbar">
            <?php $render_activity_filter(); ?>
            <div class="muted" style="font-size:12px;">
              Showing <?= number_format(count($recent_activity)) ?> of <?= number_format($recent_activity_total) ?> items
            </div>
          </div>
          <table class="admin-recent-table">
            <thead>
              <tr>
                <th><a class="admin-recent-sort" href="<?= h($activity_sort_url('type')) ?>">Type<?= h($activity_sort_indicator('type')) ?></a></th>
                <th><a class="admin-recent-sort" href="<?= h($activity_sort_url('actor')) ?>">Actor<?= h($activity_sort_indicator('actor')) ?></a></th>
                <th>Details</th>
                <th><a class="admin-recent-sort" href="<?= h($activity_sort_url('when')) ?>">When<?= h($activity_sort_indicator('when')) ?></a></th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($recent_activity as $row): ?>
                <tr>
                  <td><?= h((string)$row['kind']) ?></td>
                  <td><?= h((string)$row['actor']) ?></td>
                  <td><?= h((string)$row['details']) ?></td>
                  <td><?= h($format_activity_datetime($row['occurred_at'])) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
          <?php render_pagination($recent_activity_page, $recent_activity_total, RECENT_ACTIVITY_PER_PAGE, 'activity_page'); ?>
        <?php endif; ?>
      </div>

    <?php elseif ($section === 'canned_responses'): ?>

      <div class="card">
        <h2 style="margin-top:0;">Canned Responses</h2>
        <p class="muted" style="margin-bottom:16px;">
          Configure quick-fill response buttons for the RFQ additional notes field.
        </p>

        <?php if ($cr_errors): ?>
          <div class="alert error" style="margin-bottom:14px;">
            <ul style="margin:0; padding-left:18px;">
              <?php foreach ($cr_errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if ($cr_success): ?>
          <div class="alert" style="margin-bottom:14px; border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
            <?= h($cr_success) ?>
          </div>
        <?php endif; ?>

        <form method="post" action="admin_backend.php?section=canned_responses" novalidate>
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['admin_backend_csrf']) ?>" />

          <?php for ($i = 1; $i <= CR_SLOT_COUNT; $i++): ?>
            <div class="canned-item">
              <h3 class="form-section-heading" style="margin-top:0;">Response <?= $i ?></h3>
              <div class="admin-grid-two">
                <div>
                  <label>Button Label</label>
                  <input type="text" name="cr_label_<?= $i ?>" maxlength="100"
                         value="<?= h($canned[$i]['label']) ?>"
                         placeholder="e.g. Standard Request" />
                </div>
                <div>
                  <label>Response Text</label>
                  <textarea name="cr_body_<?= $i ?>" rows="4" maxlength="2000"
                            placeholder="Text to insert into Additional Notes..."><?= h($canned[$i]['body']) ?></textarea>
                </div>
              </div>
            </div>
          <?php endfor; ?>

          <div style="margin-top:18px;">
            <button type="submit" class="btn primary">Save Canned Responses</button>
          </div>
        </form>
      </div>

    <?php elseif ($section === 'integrations'): ?>

      <div class="card">
        <h2 style="margin-top:0;">Integrations</h2>
        <p class="muted" style="margin-bottom:16px;">Manage third-party API credentials used by the system.</p>

        <?php if ($integrations_errors): ?>
          <div class="alert error" style="margin-bottom:14px;">
            <ul style="margin:0; padding-left:18px;">
              <?php foreach ($integrations_errors as $error): ?><li><?= h($error) ?></li><?php endforeach; ?>
            </ul>
          </div>
        <?php endif; ?>

        <?php if ($integrations_success): ?>
          <div class="alert" style="margin-bottom:14px; border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
            <?= h($integrations_success) ?>
          </div>
        <?php endif; ?>

        <form method="post" action="admin_backend.php?section=integrations" novalidate>
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['admin_backend_csrf']) ?>" />

          <div style="max-width:560px;">
            <label for="hubspot_private_app_token">HubSpot Private App Token</label>
            <input id="hubspot_private_app_token" type="password" name="hubspot_private_app_token" maxlength="<?= HUBSPOT_TOKEN_MAX ?>" autocomplete="new-password" aria-describedby="hubspot_private_app_token_help" placeholder="<?= $hubspot_token_is_set ? 'Saved token is hidden. Enter a new token to replace it.' : 'Enter HubSpot token' ?>" />
            <p id="hubspot_private_app_token_help" class="muted" style="margin:6px 0 0; font-size:12px;">
              <?= $hubspot_token_is_set ? 'A token is currently saved and encrypted in the database.' : 'No token saved yet.' ?>
              <?php if ($hubspot_token_updated_at !== ''): ?>
                Last updated: <?= h($format_activity_datetime($hubspot_token_updated_at)) ?>.
              <?php endif; ?>
            </p>
          </div>

          <label style="display:inline-flex; gap:8px; align-items:center; margin-top:12px;">
            <input type="checkbox" name="clear_hubspot_token" value="1" />
            <span>Clear saved token</span>
          </label>

          <div style="max-width:560px; margin-top:18px;">
            <label for="stripe_secret_key">Stripe Secret Key</label>
            <input id="stripe_secret_key" type="password" name="stripe_secret_key" maxlength="<?= STRIPE_SECRET_KEY_MAX ?>" autocomplete="new-password" aria-describedby="stripe_secret_key_help" placeholder="<?= $stripe_secret_key_is_set ? 'Saved key is hidden. Enter a new key to replace it.' : 'Enter Stripe secret key' ?>" />
            <p id="stripe_secret_key_help" class="muted" style="margin:6px 0 0; font-size:12px;">
              <?= $stripe_secret_key_is_set ? 'A Stripe secret key is currently saved and encrypted in the database.' : 'No Stripe secret key saved yet.' ?>
              <?php if ($stripe_secret_key_updated_at !== ''): ?>
                Last updated: <?= h($format_activity_datetime($stripe_secret_key_updated_at)) ?>.
              <?php endif; ?>
            </p>
          </div>

          <label style="display:inline-flex; gap:8px; align-items:center; margin-top:12px;">
            <input type="checkbox" name="clear_stripe_secret_key" value="1" />
            <span>Clear saved Stripe key</span>
          </label>

          <div style="margin-top:18px;">
            <button type="submit" class="btn primary">Save Integration Settings</button>
          </div>
        </form>
      </div>

    <?php elseif ($section === 'users'): ?>

      <?php if ($users_errors): ?>
        <div class="alert error">
          <ul style="margin:0; padding-left:18px;">
            <?php foreach ($users_errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
          </ul>
        </div>
      <?php endif; ?>

      <?php if ($users_success): ?>
        <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
          <?= h($users_success) ?>
        </div>
      <?php endif; ?>

      <div class="card">
        <h2 style="margin-top:0;">All Users</h2>
        <div class="table-wrap">
          <table class="table-auto">
            <thead>
              <tr>
                <th>ID</th>
                <th>Username</th>
                <th>Role</th>
                <th class="col-actions">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$users_list): ?>
                <tr><td colspan="4" class="muted">No users found.</td></tr>
              <?php endif; ?>
              <?php foreach ($users_list as $u): ?>
                <tr>
                  <td class="muted"><?= (int)$u['id'] ?></td>
                  <td>
                    <strong><?= h($u['username']) ?></strong>
                    <?php if ((int)$u['id'] === current_user_id()): ?>
                      <span class="badge" style="margin-left:6px;">You</span>
                    <?php endif; ?>
                    <?php if (!empty($u['email'])): ?>
                      <br><span class="muted" style="font-size:12px;"><?= h($u['email']) ?></span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?php $role = $u['role'] ?? ($u['is_admin'] ? 'admin' : 'user'); ?>
                    <?php if ($role === 'admin'): ?>
                      <span class="badge priority-high">Admin</span>
                    <?php elseif ($role === 'moderator'): ?>
                      <span class="badge priority-medium">Moderator</span>
                    <?php else: ?>
                      <span class="badge">User</span>
                    <?php endif; ?>
                  </td>
                  <td class="col-actions">
                    <div class="actions">
                      <button type="button" class="btn"
                        onclick="togglePasswordForm(<?= (int)$u['id'] ?>)">
                        Change Password
                      </button>

                      <?php if ((int)$u['id'] !== current_user_id()): ?>
                        <form method="post" style="display:inline;" action="admin_backend.php?section=users">
                          <input type="hidden" name="action" value="set_role">
                          <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
                          <select name="new_role" style="width:auto; padding:4px 8px; display:inline-block;">
                            <option value="user"      <?= ($role === 'user')      ? 'selected' : '' ?>>User</option>
                            <option value="moderator" <?= ($role === 'moderator') ? 'selected' : '' ?>>Moderator</option>
                            <option value="admin"     <?= ($role === 'admin')     ? 'selected' : '' ?>>Admin</option>
                          </select>
                          <button type="submit" class="btn">Set Role</button>
                        </form>

                        <form method="post" style="display:inline;" action="admin_backend.php?section=users"
                          onsubmit="return confirm('Delete user <?= h($u['username']) ?>? This cannot be undone.');">
                          <input type="hidden" name="action" value="delete">
                          <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
                          <button type="submit" class="btn danger">Delete</button>
                        </form>
                      <?php endif; ?>
                    </div>

                    <div id="pwform-<?= (int)$u['id'] ?>" style="display:none; margin-top:10px;">
                      <form method="post" action="admin_backend.php?section=users">
                        <input type="hidden" name="action" value="change_password">
                        <input type="hidden" name="uid" value="<?= (int)$u['id'] ?>">
                        <div class="form-grid" style="max-width:400px;">
                          <div>
                            <label>New Password</label>
                            <input type="password" name="password1" autocomplete="new-password" required minlength="6" />
                          </div>
                          <div>
                            <label>Confirm Password</label>
                            <input type="password" name="password2" autocomplete="new-password" required minlength="6" />
                          </div>
                          <div class="full">
                            <div class="row" style="margin-top:6px;">
                              <button type="submit" class="btn primary">Save Password</button>
                              <button type="button" class="btn"
                                onclick="togglePasswordForm(<?= (int)$u['id'] ?>)">Cancel</button>
                            </div>
                          </div>
                        </div>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <div class="card">
        <h2 style="margin-top:0;">Add New User</h2>
        <form method="post" action="admin_backend.php?section=users" style="max-width:480px;">
          <input type="hidden" name="action" value="add">

          <label>Username</label>
          <input type="text" name="new_username" value="<?= h($_POST['new_username'] ?? '') ?>"
                 autocomplete="off" required maxlength="64" />

          <label>Password</label>
          <input type="password" name="new_password" autocomplete="new-password" required minlength="6" />

          <label>Confirm Password</label>
          <input type="password" name="new_password2" autocomplete="new-password" required minlength="6" />

          <label>Role</label>
          <select name="new_role" style="width:auto; max-width:200px;">
            <option value="user">User</option>
            <option value="moderator">Moderator</option>
            <option value="admin">Admin</option>
          </select>

          <div class="row" style="margin-top:14px;">
            <button type="submit" class="btn primary">Create User</button>
          </div>
        </form>
      </div>

      <script>
      function togglePasswordForm(uid) {
        var el = document.getElementById('pwform-' + uid);
        if (el) {
          el.style.display = el.style.display === 'none' ? 'block' : 'none';
        }
      }
      </script>

    <?php elseif ($section === 'time_reports'): ?>

      <div class="card admin-placeholder">
        <h2 style="margin-top:0;">Time Reports</h2>
        <p class="muted">Time reporting placeholder. This section will host reporting filters, export options, and summaries.</p>
        <a class="btn" href="time_report.php">Open Current Time Reports</a>
      </div>

    <?php elseif ($section === 'payroll_export'): ?>

      <?php
        $pe_cancel_url = 'admin_backend.php?section=payroll_export';
      ?>

      <!-- Last Completed Pay Period -->
      <div class="card">
        <div class="row" style="justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:14px;">
          <div>
            <h2 style="margin:0;">Last Completed Pay Period</h2>
            <p class="muted" style="margin:4px 0 0;">
              <?= h($pe_last_from) ?> &mdash; <?= h($pe_last_to) ?>
              &nbsp;&middot;&nbsp; <?= count($pe_last_by_employee) ?> employee(s)
            </p>
          </div>
          <!-- Export for ADP button -->
          <form method="post" action="admin_backend.php?section=payroll_export">
            <input type="hidden" name="payroll_csrf" value="<?= h($_SESSION['payroll_export_csrf']) ?>" />
            <input type="hidden" name="export_adp"   value="1" />
            <input type="hidden" name="pe_from"      value="<?= h($pe_last_from) ?>" />
            <input type="hidden" name="pe_to"        value="<?= h($pe_last_to) ?>" />
            <button type="submit" class="btn primary">⬇ Export for ADP</button>
          </form>
        </div>

        <!-- Summary by employee -->
        <div class="table-wrap" style="margin-bottom:20px;">
          <table class="table-auto">
            <thead>
              <tr>
                <th>Employee</th>
                <th>Total Hours</th>
                <th>Entries</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$pe_last_by_employee): ?>
                <tr><td colspan="3" class="muted">No time entries for the last completed pay period.</td></tr>
              <?php endif; ?>
              <?php foreach ($pe_last_by_employee as $emp): ?>
                <tr>
                  <td><strong><?= h($emp['username']) ?></strong></td>
                  <td><?= number_format($emp['total_hours'], 2) ?>h</td>
                  <td><?= (int)$emp['entries'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Detailed entries -->
        <div class="table-wrap">
          <table class="table-auto">
            <thead>
              <tr>
                <th>Employee</th>
                <th>Date</th>
                <th>Clock In</th>
                <th>Lunch</th>
                <th>Clock Out</th>
                <th>Hours</th>
                <th>Note</th>
                <th class="col-actions">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$pe_last_entries): ?>
                <tr><td colspan="8" class="muted">No entries for the last completed pay period.</td></tr>
              <?php endif; ?>
              <?php foreach ($pe_last_entries as $pe_r): ?>
                <?php
                  $pe_ci_dt  = new DateTime($pe_r['clock_in'], $pe_tz_obj);
                  $pe_co_dt  = !empty($pe_r['clock_out']) ? new DateTime($pe_r['clock_out'], $pe_tz_obj) : null;
                  $pe_lunch  = '<span class="muted">—</span>';
                  if (!empty($pe_r['lunch_start'])) {
                    $pe_ls = new DateTime($pe_r['lunch_start'], $pe_tz_obj);
                    if (!empty($pe_r['lunch_end'])) {
                      $pe_le = new DateTime($pe_r['lunch_end'], $pe_tz_obj);
                      $pe_lunch = h($pe_ls->format('g:i A')) . ' – ' . h($pe_le->format('g:i A'));
                    } else {
                      $pe_lunch = 'Started ' . h($pe_ls->format('g:i A'));
                    }
                  }
                  $pe_edit_href = 'admin_backend.php?' . http_build_query([
                    'section'    => 'payroll_export',
                    'pe_action'  => 'edit',
                    'pe_id'      => $pe_r['id'],
                  ]);
                ?>
                <tr>
                  <td><strong><?= h($pe_r['username']) ?></strong></td>
                  <td><?= h($pe_ci_dt->format('m-d-Y')) ?></td>
                  <td><?= h($pe_ci_dt->format('g:i A')) ?></td>
                  <td><?= $pe_lunch ?></td>
                  <td>
                    <?php if ($pe_co_dt): ?>
                      <?= h($pe_co_dt->format('g:i A')) ?>
                    <?php else: ?>
                      <span class="badge clocked-in">Open</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?= $pe_r['hours'] !== null
                      ? number_format((float)$pe_r['hours'], 2) . 'h'
                      : '<span class="muted">—</span>' ?>
                  </td>
                  <td><?= $pe_r['description'] ? h($pe_r['description']) : '<span class="muted">—</span>' ?></td>
                  <td class="col-actions">
                    <div class="actions">
                      <a class="btn" href="<?= h($pe_edit_href) ?>">Edit</a>
                      <form method="post" style="margin:0;"
                            action="admin_backend.php?section=payroll_export"
                            onsubmit="return confirm('Delete this time entry? This cannot be undone.');">
                        <input type="hidden" name="payroll_csrf"    value="<?= h($_SESSION['payroll_export_csrf']) ?>" />
                        <input type="hidden" name="pe_delete_entry" value="1" />
                        <input type="hidden" name="entry_id"        value="<?= (int)$pe_r['id'] ?>" />
                        <button class="btn danger" type="submit">Delete</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

      <!-- Edit entry form (shown when pe_action=edit) -->
      <?php if ($pe_editing && !$payroll_save_errors): ?>
        <?php
          $pe_ci_fmt = str_replace(' ', 'T', substr($pe_editing['clock_in'], 0, 16));
          $pe_co_fmt = $pe_editing['clock_out']
            ? str_replace(' ', 'T', substr($pe_editing['clock_out'], 0, 16))
            : '';
        ?>
        <div class="card" style="border-color:#93c5fd;">
          <div class="row" style="justify-content:space-between; align-items:center; margin-bottom:12px;">
            <h2 style="margin:0;">Edit Time Entry #<?= (int)$pe_editing['id'] ?></h2>
            <a class="btn" href="<?= h($pe_cancel_url) ?>">Cancel</a>
          </div>
          <form method="post" action="admin_backend.php?section=payroll_export">
            <input type="hidden" name="payroll_csrf" value="<?= h($_SESSION['payroll_export_csrf']) ?>" />
            <input type="hidden" name="pe_save_entry" value="1" />
            <input type="hidden" name="entry_id"      value="<?= (int)$pe_editing['id'] ?>" />
            <div class="form-grid">
              <div>
                <label>Employee</label>
                <select name="user_id">
                  <?php foreach ($pe_all_users as $pu): ?>
                    <option value="<?= (int)$pu['id'] ?>"
                      <?= (int)$pe_editing['user_id'] === (int)$pu['id'] ? 'selected' : '' ?>>
                      <?= h($pu['username']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label>Clock In</label>
                <input type="datetime-local" name="clock_in" value="<?= h($pe_ci_fmt) ?>" required />
              </div>
              <div>
                <label>Clock Out <span class="muted">(leave blank if still open)</span></label>
                <input type="datetime-local" name="clock_out" value="<?= h($pe_co_fmt) ?>" />
              </div>
              <div>
                <label>Hours Override <span class="muted">(leave blank to use clock times)</span></label>
                <input type="number" step="0.01" min="0" name="hours_override"
                       value="<?= $pe_editing['hours_override'] !== null ? h((string)$pe_editing['hours_override']) : '' ?>" />
              </div>
              <div class="full">
                <label>Note</label>
                <textarea name="description" rows="2"><?= h($pe_editing['description'] ?? '') ?></textarea>
              </div>
            </div>
            <div class="row" style="margin-top:12px; gap:8px;">
              <button type="submit" class="btn primary">Save Changes</button>
              <a class="btn" href="<?= h($pe_cancel_url) ?>">Cancel</a>
            </div>
          </form>
        </div>
      <?php elseif ($pe_editing && $payroll_save_errors): ?>
        <?php
          $pe_ci_fmt = str_replace(' ', 'T', substr((string)($_POST['clock_in'] ?? $pe_editing['clock_in']), 0, 16));
          $pe_co_raw_val = $_POST['clock_out'] ?? ($pe_editing['clock_out'] ? substr($pe_editing['clock_out'], 0, 16) : '');
          $pe_co_fmt = str_replace(' ', 'T', trim((string)$pe_co_raw_val));
        ?>
        <div class="card" style="border-color:#93c5fd;">
          <div class="row" style="justify-content:space-between; align-items:center; margin-bottom:12px;">
            <h2 style="margin:0;">Edit Time Entry #<?= (int)$pe_editing['id'] ?></h2>
            <a class="btn" href="<?= h($pe_cancel_url) ?>">Cancel</a>
          </div>
          <div class="alert error" style="margin-bottom:10px;">
            <ul style="margin:0; padding-left:18px;">
              <?php foreach ($payroll_save_errors as $pse): ?><li><?= h($pse) ?></li><?php endforeach; ?>
            </ul>
          </div>
          <form method="post" action="admin_backend.php?section=payroll_export">
            <input type="hidden" name="payroll_csrf" value="<?= h($_SESSION['payroll_export_csrf']) ?>" />
            <input type="hidden" name="pe_save_entry" value="1" />
            <input type="hidden" name="entry_id"      value="<?= (int)$pe_editing['id'] ?>" />
            <div class="form-grid">
              <div>
                <label>Employee</label>
                <select name="user_id">
                  <?php foreach ($pe_all_users as $pu): ?>
                    <option value="<?= (int)$pu['id'] ?>"
                      <?= (int)($_POST['user_id'] ?? $pe_editing['user_id']) === (int)$pu['id'] ? 'selected' : '' ?>>
                      <?= h($pu['username']) ?>
                    </option>
                  <?php endforeach; ?>
                </select>
              </div>
              <div>
                <label>Clock In</label>
                <input type="datetime-local" name="clock_in" value="<?= h($pe_ci_fmt) ?>" required />
              </div>
              <div>
                <label>Clock Out <span class="muted">(leave blank if still open)</span></label>
                <input type="datetime-local" name="clock_out" value="<?= h($pe_co_fmt) ?>" />
              </div>
              <div>
                <label>Hours Override <span class="muted">(leave blank to use clock times)</span></label>
                <input type="number" step="0.01" min="0" name="hours_override"
                       value="<?= h($_POST['hours_override'] ?? ($pe_editing['hours_override'] !== null ? (string)$pe_editing['hours_override'] : '')) ?>" />
              </div>
              <div class="full">
                <label>Note</label>
                <textarea name="description" rows="2"><?= h($_POST['description'] ?? ($pe_editing['description'] ?? '')) ?></textarea>
              </div>
            </div>
            <div class="row" style="margin-top:12px; gap:8px;">
              <button type="submit" class="btn primary">Save Changes</button>
              <a class="btn" href="<?= h($pe_cancel_url) ?>">Cancel</a>
            </div>
          </form>
        </div>
      <?php endif; ?>

      <!-- Current Pay Period: summary + detailed entries -->
      <div class="card">
        <div style="margin-bottom:14px;">
          <h2 style="margin:0;">Current Pay Period
            <span class="muted" style="font-size:14px; font-weight:400; margin-left:8px;"><?= count($pe_entries) ?> entr<?= count($pe_entries) === 1 ? 'y' : 'ies' ?></span>
          </h2>
          <p class="muted" style="margin:4px 0 0;">
            <?= h($pe_curr_from) ?> &mdash; <?= h($pe_curr_to) ?>
            &nbsp;&middot;&nbsp; <?= count($pe_by_employee) ?> employee(s)
          </p>
        </div>

        <!-- Summary by employee -->
        <div class="table-wrap" style="margin-bottom:20px;">
          <table class="table-auto">
            <thead>
              <tr>
                <th>Employee</th>
                <th>Total Hours</th>
                <th>Entries</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$pe_by_employee): ?>
                <tr><td colspan="3" class="muted">No time entries for the current pay period.</td></tr>
              <?php endif; ?>
              <?php foreach ($pe_by_employee as $emp): ?>
                <tr>
                  <td><strong><?= h($emp['username']) ?></strong></td>
                  <td><?= number_format($emp['total_hours'], 2) ?>h</td>
                  <td><?= (int)$emp['entries'] ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>

        <!-- Detailed entries -->
        <div class="table-wrap">
          <table class="table-auto">
            <thead>
              <tr>
                <th>Employee</th>
                <th>Date</th>
                <th>Clock In</th>
                <th>Lunch</th>
                <th>Clock Out</th>
                <th>Hours</th>
                <th>Note</th>
                <th class="col-actions">Actions</th>
              </tr>
            </thead>
            <tbody>
              <?php if (!$pe_entries): ?>
                <tr><td colspan="8" class="muted">No entries for the current pay period.</td></tr>
              <?php endif; ?>
              <?php foreach ($pe_entries as $pe_r): ?>
                <?php
                  $pe_ci_dt  = new DateTime($pe_r['clock_in'], $pe_tz_obj);
                  $pe_co_dt  = !empty($pe_r['clock_out']) ? new DateTime($pe_r['clock_out'], $pe_tz_obj) : null;
                  $pe_lunch  = '<span class="muted">—</span>';
                  if (!empty($pe_r['lunch_start'])) {
                    $pe_ls = new DateTime($pe_r['lunch_start'], $pe_tz_obj);
                    if (!empty($pe_r['lunch_end'])) {
                      $pe_le = new DateTime($pe_r['lunch_end'], $pe_tz_obj);
                      $pe_lunch = h($pe_ls->format('g:i A')) . ' – ' . h($pe_le->format('g:i A'));
                    } else {
                      $pe_lunch = 'Started ' . h($pe_ls->format('g:i A'));
                    }
                  }
                  $pe_edit_href = 'admin_backend.php?' . http_build_query([
                    'section'    => 'payroll_export',
                    'pe_action'  => 'edit',
                    'pe_id'      => $pe_r['id'],
                  ]);
                ?>
                <tr>
                  <td><strong><?= h($pe_r['username']) ?></strong></td>
                  <td><?= h($pe_ci_dt->format('m-d-Y')) ?></td>
                  <td><?= h($pe_ci_dt->format('g:i A')) ?></td>
                  <td><?= $pe_lunch ?></td>
                  <td>
                    <?php if ($pe_co_dt): ?>
                      <?= h($pe_co_dt->format('g:i A')) ?>
                    <?php else: ?>
                      <span class="badge clocked-in">Open</span>
                    <?php endif; ?>
                  </td>
                  <td>
                    <?= $pe_r['hours'] !== null
                      ? number_format((float)$pe_r['hours'], 2) . 'h'
                      : '<span class="muted">—</span>' ?>
                  </td>
                  <td><?= $pe_r['description'] ? h($pe_r['description']) : '<span class="muted">—</span>' ?></td>
                  <td class="col-actions">
                    <div class="actions">
                      <a class="btn" href="<?= h($pe_edit_href) ?>">Edit</a>
                      <form method="post" style="margin:0;"
                            action="admin_backend.php?section=payroll_export"
                            onsubmit="return confirm('Delete this time entry? This cannot be undone.');">
                        <input type="hidden" name="payroll_csrf"    value="<?= h($_SESSION['payroll_export_csrf']) ?>" />
                        <input type="hidden" name="pe_delete_entry" value="1" />
                        <input type="hidden" name="entry_id"        value="<?= (int)$pe_r['id'] ?>" />
                        <button class="btn danger" type="submit">Delete</button>
                      </form>
                    </div>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      </div>

    <?php elseif ($section === 'system_settings'): ?>

      <div class="card admin-placeholder">
        <h2 style="margin-top:0;">System Settings</h2>
        <p class="muted">System settings placeholder. This section will include global configuration and maintenance controls.</p>
      </div>

    <?php endif; ?>
  </main>
</div>

<?php render_footer(); ?>
