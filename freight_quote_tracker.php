<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_rfq_access();

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
if (empty($_SESSION['srfq_tracker_csrf'])) {
  $_SESSION['srfq_tracker_csrf'] = bin2hex(random_bytes(24));
}

$request_statuses = [
  'pending'         => 'Pending',
  'sent'            => 'Sent',
  'quotes_received' => 'Quotes Received',
  'comparing'       => 'Comparing',
  'booked'          => 'Booked',
  'in_transit'      => 'In Transit',
  'delivered'       => 'Delivered',
  'closed'          => 'Closed',
];
$request_status_styles = [
  'pending'         => ['#f3f4f6', '#374151'],
  'sent'            => ['#dbeafe', '#1d4ed8'],
  'quotes_received' => ['#dcfce7', '#166534'],
  'comparing'       => ['#fef3c7', '#92400e'],
  'booked'          => ['#ede9fe', '#6d28d9'],
  'in_transit'      => ['#ffedd5', '#9a3412'],
  'delivered'       => ['#d1fae5', '#065f46'],
  'closed'          => ['#fee2e2', '#991b1b'],
];
$quote_statuses = [
  'received'     => 'Received',
  'under_review' => 'Under Review',
  'negotiating'  => 'Negotiating',
  'accepted'     => 'Accepted',
  'rejected'     => 'Rejected',
];
$quote_status_styles = [
  'received'     => ['#dcfce7', '#166534'],
  'under_review' => ['#dbeafe', '#1d4ed8'],
  'negotiating'  => ['#fef3c7', '#92400e'],
  'accepted'     => ['#ede9fe', '#6d28d9'],
  'rejected'     => ['#fee2e2', '#991b1b'],
];

$errors  = [];
$success = '';
$forwarder_options = [];
$ai_fill_source_text = '';
$ai_fill_show_panel = false;
$ai_prefill = [
  'forwarder_name' => '',
  'forwarder_name_other' => '',
  'quote_amount' => '',
  'transit_time_days' => '',
  'notes' => '',
];

try {
  $ff_stmt = $pdo->query("SELECT company_name FROM freight_forwarders WHERE company_name <> '' ORDER BY company_name ASC");
  if ($ff_stmt) {
    $forwarder_options = array_values(array_unique(array_filter(
      array_map('trim', $ff_stmt->fetchAll(PDO::FETCH_COLUMN)),
      fn($name) => $name !== ''
    )));
  }
} catch (Throwable $e) {
  $forwarder_options = [];
}

function srfq_status_select_style(array $styles, ?string $status): string {
  [$bg, $color] = $styles[(string)$status] ?? ['#f3f4f6', '#374151'];
  return "background:$bg; color:$color; border-color:$bg; font-weight:600;";
}

function srfq_env_value(string $key): string {
  $candidates = [
    getenv($key),
    $_ENV[$key] ?? null,
    $_SERVER[$key] ?? null,
  ];
  foreach ($candidates as $candidate) {
    $value = trim((string)$candidate);
    if ($value !== '') {
      return $value;
    }
  }
  return '';
}

function srfq_extract_quote_with_ai(string $source_text, ?string &$error_message = null): ?array {
  $api_key = srfq_env_value('OPENAI_API_KEY');
  if ($api_key === '') {
    $error_message = 'AI fill is not configured. Missing OPENAI_API_KEY.';
    return null;
  }

  $model = srfq_env_value('OPENAI_MODEL');
  if ($model === '') {
    $model = 'gpt-4.1-mini';
  }

  $payload = [
    'model' => $model,
    'temperature' => 0,
    'response_format' => ['type' => 'json_object'],
    'messages' => [
      [
        'role' => 'system',
        'content' => 'Extract freight quote details from user text. Return strict JSON only with keys: forwarder_name, quote_amount, transit_time_days, notes. Use empty string when missing. quote_amount must be number-like text without currency words. transit_time_days must be an integer in days.',
      ],
      [
        'role' => 'user',
        'content' => $source_text,
      ],
    ],
  ];

  $ch = curl_init('https://api.openai.com/v1/chat/completions');
  curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_HTTPHEADER => [
      'Authorization: Bearer ' . $api_key,
      'Content-Type: application/json',
    ],
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode($payload),
  ]);
  $body = curl_exec($ch);
  $curl_error = curl_error($ch);
  $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
  curl_close($ch);

  if ($body === false) {
    $error_message = 'AI request failed: ' . ($curl_error !== '' ? $curl_error : 'Unknown request error.');
    return null;
  }
  if ($status < 200 || $status >= 300) {
    $error_message = 'AI request failed with status ' . $status . '.';
    return null;
  }

  $response = json_decode($body, true);
  if (!is_array($response)) {
    $error_message = 'AI request failed: invalid response format.';
    return null;
  }
  $content = (string)($response['choices'][0]['message']['content'] ?? '');
  if ($content === '') {
    $error_message = 'AI request failed: empty AI response.';
    return null;
  }

  $fields = json_decode($content, true);
  if (!is_array($fields)) {
    $error_message = 'AI request failed: unable to parse extracted fields.';
    return null;
  }

  return $fields;
}

function build_shipping_rfq_email_text(array $rfq, array $crates): string {
  $sep  = str_repeat('=', 60);
  $sep2 = str_repeat('-', 60);
  $date = date('F j, Y', strtotime((string)$rfq['created_at']));

  $contact_name  = trim((string)($rfq['contact_name']  ?? ''));
  $company_name  = trim((string)($rfq['company_name']  ?? ''));
  $contact_email = trim((string)($rfq['contact_email'] ?? ''));
  $contact_phone = trim((string)($rfq['contact_phone'] ?? ''));
  $req_by        = trim((string)($rfq['requested_by_username'] ?? 'Unknown'));

  $dest_type = (string)($rfq['destination_type'] ?? 'port_la');
  $dest_label = $dest_type === 'door_delivery'
    ? 'Door Delivery – ' . trim((string)($rfq['destination_address'] ?? ''))
    : 'Port of Los Angeles (LAXLA)';

  $lines = [
    $sep,
    'SHIPPING REQUEST FOR QUOTATION',
    $sep,
    '',
    'RFQ #:          ' . (int)$rfq['id'],
    'Date:           ' . $date,
    'Status:         ' . ucfirst(str_replace('_', ' ', trim((string)$rfq['request_status']))),
    '',
    $sep2,
    'FROM:',
    $sep2,
  ];
  if ($company_name !== '') $lines[] = 'Company:        ' . $company_name;
  if ($contact_name !== '') {
    $lines[] = 'Contact:        ' . $contact_name;
  } else {
    $lines[] = 'Requested By:   ' . $req_by;
  }
  if ($contact_email !== '') $lines[] = 'Email:          ' . $contact_email;
  if ($contact_phone !== '') $lines[] = 'Phone:          ' . $contact_phone;

  $lines = array_merge($lines, [
    '',
    $sep2,
    'SHIPMENT DETAILS:',
    $sep2,
    'Title:          ' . trim((string)$rfq['request_title']),
  ]);

  $mw = $rfq['machine_weight_kg'] !== null ? trim((string)$rfq['machine_weight_kg']) . ' kg' : '—';
  $lines[] = 'Machine Weight: ' . $mw;
  $lines[] = 'Port of Loading:' . ' ' . trim((string)($rfq['port_of_loading'] ?? ''));
  $lines[] = 'Destination:    ' . $dest_label;
  $lines[] = 'Shipment Type:  ' . trim((string)($rfq['shipment_type'] ?? 'LCL'));

  if ($crates) {
    $lines[] = '';
    $lines[] = $sep2;
    $lines[] = 'CARGO CRATE DETAILS:';
    $lines[] = $sep2;
    foreach ($crates as $i => $c) {
      $lbl = trim((string)($c['crate_label'] ?? ''));
      $n   = $i + 1;
      $lines[] = 'Crate #' . $n . ($lbl !== '' ? ' – ' . $lbl : '') . ':';
      $qty = (int)($c['quantity'] ?? 1);
      if ($qty > 1) $lines[] = '  Quantity:       ' . $qty;
      $dim_parts = [];
      if ($c['length_cm'] !== null) $dim_parts[] = $c['length_cm'] . 'cm (L)';
      if ($c['width_cm']  !== null) $dim_parts[] = $c['width_cm']  . 'cm (W)';
      if ($c['height_cm'] !== null) $dim_parts[] = $c['height_cm'] . 'cm (H)';
      if ($dim_parts) $lines[] = '  Dimensions:     ' . implode(' × ', $dim_parts);
      if ($c['gross_weight_kg'] !== null) $lines[] = '  Gross Weight:   ' . $c['gross_weight_kg'] . ' kg';
    }
  }

  $notes = trim((string)($rfq['additional_notes'] ?? ''));
  if ($notes !== '') {
    $lines[] = '';
    $lines[] = $sep2;
    $lines[] = 'ADDITIONAL NOTES:';
    $lines[] = $sep2;
    $lines[] = $notes;
  }

  $lines[] = '';
  $lines[] = $sep;
  $lines[] = 'Please provide your best rate, transit time, and all applicable charges.';
  $lines[] = $sep;

  return implode("\n", $lines);
}

// ── POST handler ─────────────────────────────────────────────────────────────
$selected_rfq_id = max(0, (int)($_GET['rfq_id'] ?? 0));
$rfq_text_id     = max(0, (int)($_GET['rfq_text_id'] ?? 0));
$edit_quote_id   = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submitted_csrf = (string)($_POST['csrf_token'] ?? '');
  if (empty($_SESSION['srfq_tracker_csrf']) || !hash_equals((string)$_SESSION['srfq_tracker_csrf'], $submitted_csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  }

  $action = (string)($_POST['action'] ?? '');

  if (!$errors && $action === 'update_request_status') {
    $rfq_id   = max(0, (int)($_POST['rfq_id'] ?? 0));
    $new_status = (string)($_POST['request_status'] ?? '');
    if ($rfq_id > 0 && array_key_exists($new_status, $request_statuses)) {
      $pdo->prepare("UPDATE shipping_rfq_requests SET request_status = ? WHERE id = ?")
          ->execute([$new_status, $rfq_id]);
      $success = 'Status updated.';
    }
    $selected_rfq_id = $rfq_id;
  }

  if (!$errors && $action === 'delete_rfq') {
    $rfq_id = max(0, (int)($_POST['rfq_id'] ?? 0));
    if ($rfq_id > 0) {
      $pdo->prepare("DELETE FROM shipping_rfq_crates WHERE shipping_rfq_id = ?")->execute([$rfq_id]);
      $pdo->prepare("DELETE FROM shipping_rfq_quotes WHERE shipping_rfq_id = ?")->execute([$rfq_id]);
      $pdo->prepare("DELETE FROM shipping_rfq_requests WHERE id = ?")->execute([$rfq_id]);
      $success = 'Freight Quote deleted.';
      $selected_rfq_id = 0;
    }
  }

  if (!$errors && $action === 'add_quote') {
    $rfq_id = max(0, (int)($_POST['rfq_id'] ?? 0));
    if ($rfq_id > 0) {
      $qf = [
        'forwarder_name'   => trim((string)($_POST['forwarder_name']   ?? '')),
        'forwarder_other'  => trim((string)($_POST['forwarder_name_other'] ?? '')),
        'quote_amount'     => trim((string)($_POST['quote_amount']      ?? '')),
        'currency'         => strtoupper(trim((string)($_POST['currency'] ?? 'USD'))),
        'transit_time_days'=> trim((string)($_POST['transit_time_days'] ?? '')),
        'shipment_type'    => trim((string)($_POST['quote_shipment_type'] ?? 'LCL')),
        'container_size'   => trim((string)($_POST['container_size']    ?? '')),
        'port_of_loading'  => trim((string)($_POST['quote_port_of_loading'] ?? '')),
        'destination'      => trim((string)($_POST['quote_destination'] ?? '')),
        'quote_status'     => trim((string)($_POST['quote_status']      ?? 'received')),
        'received_on'      => trim((string)($_POST['received_on']       ?? '')),
        'notes'            => trim((string)($_POST['notes']             ?? '')),
      ];
      if ($qf['forwarder_name'] === '__other__' && $qf['forwarder_other'] === '') {
        $errors[] = 'Please enter a forwarder name when selecting Other.';
      }
      if ($qf['forwarder_name'] === '__other__') {
        $qf['forwarder_name'] = $qf['forwarder_other'];
      }
      if ($qf['forwarder_name'] === '') $errors[] = 'Forwarder name is required.';
      if (!is_numeric($qf['quote_amount']) || (float)$qf['quote_amount'] < 0) $errors[] = 'Quote amount must be a non-negative number.';
      if ($qf['transit_time_days'] !== '' && (!ctype_digit($qf['transit_time_days']) || (int)$qf['transit_time_days'] < 1)) $errors[] = 'Transit time must be a positive whole number of days (at least 1).';
      if (!array_key_exists($qf['quote_status'], $quote_statuses)) $errors[] = 'Invalid quote status.';
      if (!in_array($qf['shipment_type'], ['FCL', 'LCL'], true)) $errors[] = 'Invalid shipment type.';
      if (!$errors) {
        $pdo->prepare(
          "INSERT INTO shipping_rfq_quotes
            (shipping_rfq_id, forwarder_name, quote_amount, currency, transit_time_days,
             shipment_type, container_size, port_of_loading, destination,
             quote_status, received_on, notes, created_by)
           VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        )->execute([
          $rfq_id,
          $qf['forwarder_name'],
          (float)$qf['quote_amount'],
          $qf['currency'] !== '' ? $qf['currency'] : 'USD',
          $qf['transit_time_days'] !== '' ? (int)$qf['transit_time_days'] : null,
          $qf['shipment_type'],
          $qf['container_size'] !== '' ? $qf['container_size'] : null,
          $qf['port_of_loading'] !== '' ? $qf['port_of_loading'] : null,
          $qf['destination'] !== '' ? $qf['destination'] : null,
          $qf['quote_status'],
          $qf['received_on'] !== '' ? $qf['received_on'] : null,
          $qf['notes'] !== '' ? $qf['notes'] : null,
          (int)current_user_id(),
        ]);
        $success = 'Quote added.';
      }
      $selected_rfq_id = $rfq_id;
    }
  }

  if (!$errors && $action === 'edit_quote') {
    $rfq_id  = max(0, (int)($_POST['rfq_id']    ?? 0));
    $qid     = max(0, (int)($_POST['quote_id']   ?? 0));
    if ($rfq_id > 0 && $qid > 0) {
      $qf = [
        'forwarder_name'   => trim((string)($_POST['forwarder_name']    ?? '')),
        'forwarder_other'  => trim((string)($_POST['forwarder_name_other'] ?? '')),
        'quote_amount'     => trim((string)($_POST['quote_amount']       ?? '')),
        'currency'         => strtoupper(trim((string)($_POST['currency'] ?? 'USD'))),
        'transit_time_days'=> trim((string)($_POST['transit_time_days']  ?? '')),
        'shipment_type'    => trim((string)($_POST['quote_shipment_type'] ?? 'LCL')),
        'container_size'   => trim((string)($_POST['container_size']     ?? '')),
        'port_of_loading'  => trim((string)($_POST['quote_port_of_loading'] ?? '')),
        'destination'      => trim((string)($_POST['quote_destination']  ?? '')),
        'quote_status'     => trim((string)($_POST['quote_status']       ?? 'received')),
        'received_on'      => trim((string)($_POST['received_on']        ?? '')),
        'notes'            => trim((string)($_POST['notes']              ?? '')),
      ];
      if ($qf['forwarder_name'] === '__other__' && $qf['forwarder_other'] === '') {
        $errors[] = 'Please enter a forwarder name when selecting Other.';
      }
      if ($qf['forwarder_name'] === '__other__') {
        $qf['forwarder_name'] = $qf['forwarder_other'];
      }
      if ($qf['forwarder_name'] === '') $errors[] = 'Forwarder name is required.';
      if (!is_numeric($qf['quote_amount']) || (float)$qf['quote_amount'] < 0) $errors[] = 'Quote amount must be a non-negative number.';
      if ($qf['transit_time_days'] !== '' && (!ctype_digit($qf['transit_time_days']) || (int)$qf['transit_time_days'] < 1)) $errors[] = 'Transit time must be a positive whole number of days (at least 1).';
      if (!array_key_exists($qf['quote_status'], $quote_statuses)) $errors[] = 'Invalid quote status.';
      if (!in_array($qf['shipment_type'], ['FCL', 'LCL'], true)) $errors[] = 'Invalid shipment type.';
      if (!$errors) {
        $pdo->prepare(
          "UPDATE shipping_rfq_quotes SET
            forwarder_name = ?, quote_amount = ?, currency = ?, transit_time_days = ?,
            shipment_type = ?, container_size = ?, port_of_loading = ?, destination = ?,
            quote_status = ?, received_on = ?, notes = ?
           WHERE id = ? AND shipping_rfq_id = ?"
        )->execute([
          $qf['forwarder_name'],
          (float)$qf['quote_amount'],
          $qf['currency'] !== '' ? $qf['currency'] : 'USD',
          $qf['transit_time_days'] !== '' ? (int)$qf['transit_time_days'] : null,
          $qf['shipment_type'],
          $qf['container_size'] !== '' ? $qf['container_size'] : null,
          $qf['port_of_loading'] !== '' ? $qf['port_of_loading'] : null,
          $qf['destination'] !== '' ? $qf['destination'] : null,
          $qf['quote_status'],
          $qf['received_on'] !== '' ? $qf['received_on'] : null,
          $qf['notes'] !== '' ? $qf['notes'] : null,
          $qid,
          $rfq_id,
        ]);
        $success = 'Quote updated.';
      }
      $selected_rfq_id = $rfq_id;
    }
  }

  if (!$errors && $action === 'delete_quote') {
    $rfq_id = max(0, (int)($_POST['rfq_id']  ?? 0));
    $qid    = max(0, (int)($_POST['quote_id'] ?? 0));
    if ($rfq_id > 0 && $qid > 0) {
      $pdo->prepare("DELETE FROM shipping_rfq_quotes WHERE id = ? AND shipping_rfq_id = ?")
          ->execute([$qid, $rfq_id]);
      $success = 'Quote deleted.';
    }
    $selected_rfq_id = $rfq_id;
  }

  if (!$errors && $action === 'ai_fill_quote') {
    $rfq_id = max(0, (int)($_POST['rfq_id'] ?? 0));
    $selected_rfq_id = $rfq_id;
    $ai_fill_show_panel = true;
    $ai_fill_source_text = trim((string)($_POST['ai_quote_text'] ?? ''));
    if ($ai_fill_source_text === '') {
      $errors[] = 'Please paste forwarder quote text before using AI Fill Quote.';
    } else {
      $ai_error = null;
      $ai_fields = srfq_extract_quote_with_ai($ai_fill_source_text, $ai_error);
      if (!is_array($ai_fields)) {
        $errors[] = $ai_error ?: 'AI fill failed. Please review and try again.';
      } else {
        $ai_forwarder = trim((string)($ai_fields['forwarder_name'] ?? ''));
        $ai_quote_amount_raw = trim((string)($ai_fields['quote_amount'] ?? ''));
        $ai_transit_raw = trim((string)($ai_fields['transit_time_days'] ?? ''));
        $ai_notes = trim((string)($ai_fields['notes'] ?? ''));

        if ($ai_forwarder !== '') {
          if (in_array($ai_forwarder, $forwarder_options, true)) {
            $ai_prefill['forwarder_name'] = $ai_forwarder;
            $ai_prefill['forwarder_name_other'] = '';
          } else {
            $ai_prefill['forwarder_name'] = '__other__';
            $ai_prefill['forwarder_name_other'] = mb_substr($ai_forwarder, 0, 255);
          }
        }

        if ($ai_quote_amount_raw !== '') {
          $normalized_amount = preg_replace('/[^0-9.\-]/', '', $ai_quote_amount_raw);
          if ($normalized_amount !== '' && is_numeric($normalized_amount) && (float)$normalized_amount >= 0) {
            $ai_prefill['quote_amount'] = number_format((float)$normalized_amount, 2, '.', '');
          }
        }

        if ($ai_transit_raw !== '') {
          $normalized_days = preg_replace('/\D+/', '', $ai_transit_raw);
          if ($normalized_days !== '' && (int)$normalized_days > 0) {
            $ai_prefill['transit_time_days'] = (string)(int)$normalized_days;
          }
        }

        if ($ai_notes !== '') {
          $ai_prefill['notes'] = mb_substr($ai_notes, 0, 5000);
        }

        if (
          $ai_prefill['forwarder_name'] === '' &&
          $ai_prefill['forwarder_name_other'] === '' &&
          $ai_prefill['quote_amount'] === '' &&
          $ai_prefill['transit_time_days'] === '' &&
          $ai_prefill['notes'] === ''
        ) {
          $errors[] = 'AI could not find quote details. Please adjust the pasted text and try again.';
        } else {
          $success = 'AI Fill complete. Review the fields, make any edits, then click Add Quote.';
        }
      }
    }
  }
}

// ── Determine which quote to edit (GET) ─────────────────────────────────────
$edit_quote_id = max(0, (int)($_GET['edit_quote_id'] ?? 0));

// ── Load list data ───────────────────────────────────────────────────────────
$search        = trim((string)($_GET['q']      ?? ''));
$status_filter = trim((string)($_GET['status'] ?? ''));

$where_parts = [];
$where_args  = [];
if ($search !== '') {
  $where_parts[] = "(r.request_title LIKE ? OR r.machine_model LIKE ? OR r.port_of_loading LIKE ?)";
  $like = '%' . $search . '%';
  $where_args = array_merge($where_args, [$like, $like, $like]);
}
if ($status_filter !== '' && array_key_exists($status_filter, $request_statuses)) {
  $where_parts[] = "r.request_status = ?";
  $where_args[] = $status_filter;
}
$where_sql = $where_parts ? 'WHERE ' . implode(' AND ', $where_parts) : '';

$rfqs_stmt = $pdo->prepare(
  "SELECT r.*, u.username AS requested_by_username,
          (SELECT COUNT(*) FROM shipping_rfq_quotes q WHERE q.shipping_rfq_id = r.id) AS quote_count
   FROM shipping_rfq_requests r
   LEFT JOIN users u ON u.id = r.requested_by
   $where_sql
   ORDER BY r.created_at DESC"
);
$rfqs_stmt->execute($where_args);
$rfqs = $rfqs_stmt->fetchAll();

$hero_total_rfqs   = count($rfqs);
$hero_open_rfqs    = 0;
$hero_booked_rfqs  = 0;
$hero_quotes_total = 0;
foreach ($rfqs as $rfq_row) {
  $hero_status = (string)($rfq_row['request_status'] ?? 'draft');
  if ($hero_status !== 'closed') {
    $hero_open_rfqs++;
  }
  if ($hero_status === 'booked') {
    $hero_booked_rfqs++;
  }
  $hero_quotes_total += (int)($rfq_row['quote_count'] ?? 0);
}

// Selected RFQ (for quotes view)
$selected_rfq    = null;
$rfq_crates      = [];
$rfq_quotes      = [];
$editing_quote   = null;
$rfq_email_text  = '';

if ($selected_rfq_id > 0) {
  $sr = $pdo->prepare(
    "SELECT r.*, u.username AS requested_by_username
     FROM shipping_rfq_requests r
     LEFT JOIN users u ON u.id = r.requested_by
     WHERE r.id = ? LIMIT 1"
  );
  $sr->execute([$selected_rfq_id]);
  $selected_rfq = $sr->fetch();
  if ($selected_rfq) {
    $cs = $pdo->prepare("SELECT * FROM shipping_rfq_crates WHERE shipping_rfq_id = ? ORDER BY sort_order, id");
    $cs->execute([$selected_rfq_id]);
    $rfq_crates = $cs->fetchAll();

    $qs = $pdo->prepare("SELECT * FROM shipping_rfq_quotes WHERE shipping_rfq_id = ? ORDER BY created_at ASC");
    $qs->execute([$selected_rfq_id]);
    $rfq_quotes = $qs->fetchAll();

    if ($edit_quote_id > 0) {
      foreach ($rfq_quotes as $q) {
        if ((int)$q['id'] === $edit_quote_id) {
          $editing_quote = $q;
          break;
        }
      }
    }
  } else {
    $selected_rfq_id = 0;
  }
}

// Email text mode
if ($rfq_text_id > 0) {
  $tr = $pdo->prepare(
    "SELECT r.*, u.username AS requested_by_username
     FROM shipping_rfq_requests r
     LEFT JOIN users u ON u.id = r.requested_by
     WHERE r.id = ? LIMIT 1"
  );
  $tr->execute([$rfq_text_id]);
  $text_rfq = $tr->fetch();
  if ($text_rfq) {
    $tc = $pdo->prepare("SELECT * FROM shipping_rfq_crates WHERE shipping_rfq_id = ? ORDER BY sort_order, id");
    $tc->execute([$rfq_text_id]);
    $text_crates = $tc->fetchAll();
    $rfq_email_text = build_shipping_rfq_email_text($text_rfq, $text_crates);
  }
}

render_header('Freight Quote Tracker');
?>

<div class="card shipping-rfq-hero page-header">
  <div class="shipping-rfq-hero-glow" aria-hidden="true"></div>
  <div class="page-header-body shipping-rfq-hero-body">
    <span class="shipping-rfq-hero-tag">Ocean + Air Freight Command Center</span>
    <h1>Freight Quote Tracker <span class="shipping-rfq-hero-count">(<?= (int)$hero_total_rfqs ?>)</span></h1>
    <p class="muted">Orchestrate every shipment request, compare carrier bids faster, and keep cargo moving with confidence.</p>
    <ul class="shipping-rfq-hero-pills" aria-label="Freight Quote Tracker highlights">
      <li class="shipping-rfq-hero-pill"><span aria-hidden="true">📦</span> Live quote pipeline</li>
      <li class="shipping-rfq-hero-pill"><span aria-hidden="true">🌍</span> Global route visibility</li>
      <li class="shipping-rfq-hero-pill"><span aria-hidden="true">⚡</span> Faster booking decisions</li>
    </ul>
    <div class="shipping-rfq-hero-stats" aria-label="Freight Quote Tracker summary">
      <div class="shipping-rfq-hero-stat">
        <strong><?= (int)$hero_total_rfqs ?></strong>
        <span>Total RFQs</span>
      </div>
      <div class="shipping-rfq-hero-stat">
        <strong><?= (int)$hero_open_rfqs ?></strong>
        <span>Open Pipeline</span>
      </div>
      <div class="shipping-rfq-hero-stat">
        <strong><?= (int)$hero_booked_rfqs ?></strong>
        <span>Booked</span>
      </div>
      <div class="shipping-rfq-hero-stat">
        <strong><?= (int)$hero_quotes_total ?></strong>
        <span>Quotes Logged</span>
      </div>
    </div>
  </div>
  <div class="shipping-rfq-hero-actions">
    <a class="btn primary" href="freight_quote_form.php">+ New Freight Quote</a>
    <button type="button" class="btn" id="focus-srfq-filters">Explore Pipeline</button>
  </div>
</div>

<?php render_alibaba_workflow_banner('quotes_received'); ?>

<?php if ($errors): ?>
  <div class="alert error">
    <ul style="margin:0; padding-left:18px;">
      <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>
<?php if ($success): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;">
    <?= h($success) ?>
  </div>
<?php endif; ?>

<!-- Filter bar -->
<div class="card">
  <form method="get" class="row" style="gap:12px; align-items:flex-end; flex-wrap:wrap;">
    <?php if ($selected_rfq_id > 0): ?>
      <input type="hidden" name="rfq_id" value="<?= (int)$selected_rfq_id ?>" />
    <?php endif; ?>
    <div style="flex:1; min-width:180px;">
      <label>Search</label>
      <input type="text" id="shipping-rfq-search-input" name="q" value="<?= h($search) ?>"
             placeholder="Search title, model, or port…" />
    </div>
    <div style="width:200px;">
      <label>Status</label>
      <select name="status">
        <option value="">All statuses</option>
        <?php foreach ($request_statuses as $k => $label): ?>
          <option value="<?= h($k) ?>" <?= $status_filter === $k ? 'selected' : '' ?>><?= h($label) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="row">
      <button type="submit" class="btn primary">Filter</button>
      <a class="btn" href="freight_quote_tracker.php">Clear</a>
      <a class="btn" href="freight_quote_form.php">New RFQ</a>
    </div>
  </form>
</div>

<!-- Email text panel -->
<?php if ($rfq_email_text !== ''): ?>
  <div class="card">
    <h2 style="margin-top:0;">Freight Quote Email Text</h2>
    <p class="muted" style="margin-top:0;">Copy this text and paste it into your email to forwarders.</p>
    <label id="srfq_email_lbl" for="srfq_email_text">Email content</label>
    <textarea id="srfq_email_text" rows="20" readonly aria-labelledby="srfq_email_lbl"><?= h($rfq_email_text) ?></textarea>
    <div class="row" style="margin-top:8px;">
      <button type="button" class="btn" onclick="copySrfqEmailText()">Copy Text</button>
      <span id="srfq_copy_status" class="muted" role="status" aria-live="polite"></span>
    </div>
  </div>
  <script>
    function copySrfqEmailText() {
      var text   = document.getElementById('srfq_email_text').value;
      var status = document.getElementById('srfq_copy_status');
      var failMsg = 'Failed to copy. Please select the text and copy manually.';
      if (!navigator.clipboard || typeof navigator.clipboard.writeText !== 'function') {
        status.textContent = failMsg;
        setTimeout(function () { status.textContent = ''; }, 5000);
        return;
      }
      navigator.clipboard.writeText(text).then(function () {
        status.textContent = 'Copied to clipboard.';
        setTimeout(function () { status.textContent = ''; }, 3000);
      }, function () {
        status.textContent = failMsg;
        setTimeout(function () { status.textContent = ''; }, 5000);
      });
    }
  </script>
<?php endif; ?>

<!-- RFQ list -->
<?php if (!$selected_rfq): ?>
  <div class="card">
    <div class="table-wrap" style="overflow-x:auto;">
      <table class="table-auto" style="min-width:980px;">
        <thead>
          <tr>
            <th>#</th>
            <th>Freight Quote</th>
            <th>Machine</th>
            <th>Route</th>
            <th>Status</th>
            <th>Quotes</th>
            <th class="col-actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rfqs): ?>
            <tr><td colspan="7" class="muted">No freight quote requests found.</td></tr>
          <?php endif; ?>
          <?php foreach ($rfqs as $r): ?>
            <?php
              $dest_type  = (string)($r['destination_type'] ?? 'port_la');
              $dest_label = $dest_type === 'door_delivery' ? 'Door Delivery' : 'Port of LA';
            ?>
            <tr>
              <td class="muted"><?= (int)$r['id'] ?></td>
              <td>
                <strong><?= h($r['request_title']) ?></strong><br>
                <span class="muted">Model: <?= h((string)($r['machine_model'] ?? '—')) ?></span>
              </td>
              <td>
                <strong><?= h((string)($r['machine_model'] ?? '—')) ?></strong>
                <?php if ($r['machine_weight_kg'] !== null): ?>
                  <br><span class="muted"><?= h(rtrim(rtrim((string)$r['machine_weight_kg'], '0'), '.')) ?> kg</span>
                <?php endif; ?>
              </td>
              <td class="muted">
                <?= h((string)($r['port_of_loading'] ?? '—')) ?> → <?= h($dest_label) ?><br>
                <span style="font-weight:600;"><?= h((string)($r['shipment_type'] ?? 'LCL')) ?></span>
              </td>
              <td>
                <form method="post" class="row" style="gap:6px; align-items:center;">
                  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['srfq_tracker_csrf']) ?>" />
                  <input type="hidden" name="action" value="update_request_status" />
                  <input type="hidden" name="rfq_id" value="<?= (int)$r['id'] ?>" />
                  <select name="request_status" class="status-select"
                          style="min-width:150px; <?= h(srfq_status_select_style($request_status_styles, (string)$r['request_status'])) ?>">
                    <?php foreach ($request_statuses as $k => $label): ?>
                      <?php [$obg, $oc] = $request_status_styles[$k] ?? ['#f3f4f6', '#374151']; ?>
                      <option value="<?= h($k) ?>"
                              data-bg="<?= h($obg) ?>" data-color="<?= h($oc) ?>"
                              style="background:<?= h($obg) ?>; color:<?= h($oc) ?>;"
                              <?= $r['request_status'] === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn">Save</button>
                </form>
              </td>
              <td class="muted"><?= (int)$r['quote_count'] ?></td>
              <td class="col-actions">
                <a class="btn" href="freight_quote_tracker.php?rfq_id=<?= (int)$r['id'] ?>">Quotes</a>
                <a class="btn" href="freight_quote_tracker.php?rfq_text_id=<?= (int)$r['id'] ?>">Email Text</a>
                <a class="btn" href="freight_quote_form.php?edit_id=<?= (int)$r['id'] ?>">Edit</a>
                <form method="post" style="display:inline;"
                      onsubmit="return confirm('Delete this Freight Quote and all its quotes? This cannot be undone.');">
                  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['srfq_tracker_csrf']) ?>" />
                  <input type="hidden" name="action" value="delete_rfq" />
                  <input type="hidden" name="rfq_id" value="<?= (int)$r['id'] ?>" />
                  <button type="submit" class="btn danger">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<!-- Quotes panel for a selected RFQ -->
<?php if ($selected_rfq): ?>
  <?php
    $sel_dest_type  = (string)($selected_rfq['destination_type'] ?? 'port_la');
    $sel_dest_label = $sel_dest_type === 'door_delivery'
      ? 'Door Delivery — ' . h((string)($selected_rfq['destination_address'] ?? ''))
      : 'Port of Los Angeles';
  ?>
  <div class="card">
    <div class="page-header" style="margin-bottom:14px;">
      <div class="page-header-body">
        <h2>Quotes — <span class="muted" style="font-weight:400;">Freight Quote #<?= (int)$selected_rfq['id'] ?></span></h2>
        <p class="muted"><?= h($selected_rfq['request_title']) ?></p>
      </div>
      <div class="row" style="flex-shrink:0;">
        <a class="btn" href="freight_quote_tracker.php<?= $search !== '' || $status_filter !== '' ? '?' . http_build_query(array_filter(['q' => $search, 'status' => $status_filter])) : '' ?>">← All RFQs</a>
        <a class="btn" href="freight_quote_tracker.php?rfq_text_id=<?= (int)$selected_rfq['id'] ?>">Email Text</a>
        <a class="btn" href="freight_quote_form.php?edit_id=<?= (int)$selected_rfq['id'] ?>">Edit RFQ</a>
      </div>
    </div>

    <!-- RFQ Summary -->
    <div style="display:grid; grid-template-columns:repeat(auto-fit, minmax(160px,1fr)); gap:10px; margin-bottom:18px; padding:12px; background:var(--surface-alt,#f8f9fa); border-radius:8px;">
      <?php if ($selected_rfq['machine_weight_kg'] !== null): ?>
      <div><span class="muted" style="font-size:.8em;">Machine Weight</span><br><strong><?= h(rtrim(rtrim((string)$selected_rfq['machine_weight_kg'], '0'), '.')) ?> kg</strong></div>
      <?php endif; ?>
      <div><span class="muted" style="font-size:.8em;">Port of Loading</span><br><strong><?= h($selected_rfq['port_of_loading']) ?></strong></div>
      <div><span class="muted" style="font-size:.8em;">Destination</span><br><strong><?= $sel_dest_label ?></strong></div>
      <div><span class="muted" style="font-size:.8em;">Shipment Type</span><br><strong><?= h((string)($selected_rfq['shipment_type'] ?? 'LCL')) ?></strong></div>
    </div>

    <?php if ($rfq_crates): ?>
    <h3 style="margin-top:0; margin-bottom:8px; font-size:.95em;">Cargo Crates</h3>
    <div class="table-wrap" style="overflow-x:auto; margin-bottom:18px;">
      <table class="table-auto" style="min-width:560px; font-size:.88em;">
        <thead>
          <tr>
            <th>#</th>
            <th>Label</th>
            <th>Qty</th>
            <th>L × W × H (cm)</th>
            <th>Gross Wt (kg)</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($rfq_crates as $ci => $cr): ?>
          <tr>
            <td class="muted"><?= $ci + 1 ?></td>
            <td><?= $cr['crate_label'] !== '' ? h($cr['crate_label']) : '<span class="muted">—</span>' ?></td>
            <td><?= (int)($cr['quantity'] ?? 1) ?></td>
            <td>
              <?php
                $dims = array_filter([
                  $cr['length_cm'] !== null ? rtrim(rtrim((string)$cr['length_cm'], '0'), '.') : null,
                  $cr['width_cm']  !== null ? rtrim(rtrim((string)$cr['width_cm'],  '0'), '.') : null,
                  $cr['height_cm'] !== null ? rtrim(rtrim((string)$cr['height_cm'], '0'), '.') : null,
                ], fn($v) => $v !== null);
                echo $dims ? h(implode(' × ', $dims)) : '<span class="muted">—</span>';
              ?>
            </td>
            <td><?= $cr['gross_weight_kg'] !== null ? h(rtrim(rtrim((string)$cr['gross_weight_kg'], '0'), '.')) : '<span class="muted">—</span>' ?></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <?php endif; ?>

    <!-- Edit quote form -->
    <?php if ($editing_quote): ?>
    <?php
      $edit_forwarder_name = trim((string)($editing_quote['forwarder_name'] ?? ''));
      $edit_forwarder_is_other = $edit_forwarder_name !== '' && !in_array($edit_forwarder_name, $forwarder_options, true);
    ?>
    <h3 style="margin-top:0; margin-bottom:12px;">Edit Quote</h3>
    <form method="post" class="form-grid" novalidate>
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['srfq_tracker_csrf']) ?>" />
        <input type="hidden" name="action"   value="edit_quote" />
        <input type="hidden" name="rfq_id"   value="<?= (int)$selected_rfq['id'] ?>" />
        <input type="hidden" name="quote_id" value="<?= (int)$editing_quote['id'] ?>" />
        <div>
          <label>Freight Forwarder / Carrier <span style="color:var(--d)">*</span></label>
          <select name="forwarder_name" id="edit_forwarder_name" required data-forwarder-select data-other-target="edit_forwarder_name_other">
            <option value="">Select forwarder</option>
            <?php foreach ($forwarder_options as $forwarder_name): ?>
              <option value="<?= h($forwarder_name) ?>" <?= !$edit_forwarder_is_other && $edit_forwarder_name === $forwarder_name ? 'selected' : '' ?>>
                <?= h($forwarder_name) ?>
              </option>
            <?php endforeach; ?>
            <option value="__other__" <?= $edit_forwarder_is_other ? 'selected' : '' ?>>Other</option>
          </select>
          <input type="text" name="forwarder_name_other" id="edit_forwarder_name_other" maxlength="255"
                 placeholder="Enter freight forwarder / carrier name"
                 value="<?= h($edit_forwarder_is_other ? $edit_forwarder_name : '') ?>"
                 style="<?= $edit_forwarder_is_other ? '' : 'display:none;' ?>" />
        </div>
        <div>
          <label>Quote Amount <span style="color:var(--d)">*</span></label>
          <input type="number" name="quote_amount" min="0" step="0.01" required
                 value="<?= h((string)($editing_quote['quote_amount'] ?? '0')) ?>" />
        </div>
        <div>
          <label>Currency</label>
          <input type="text" name="currency" maxlength="3"
                 value="<?= h((string)($editing_quote['currency'] ?? 'USD')) ?>" />
        </div>
        <div>
          <label>Transit Time (days)</label>
          <input type="number" name="transit_time_days" min="1"
                 value="<?= h((string)($editing_quote['transit_time_days'] ?? '')) ?>" />
        </div>
        <div>
          <label>Shipment Type</label>
          <select name="quote_shipment_type">
            <option value="LCL" <?= ($editing_quote['shipment_type'] ?? '') === 'LCL' ? 'selected' : '' ?>>LCL</option>
            <option value="FCL" <?= ($editing_quote['shipment_type'] ?? '') === 'FCL' ? 'selected' : '' ?>>FCL</option>
          </select>
        </div>
        <div>
          <label>Container Size</label>
          <input type="text" name="container_size" maxlength="50"
                 value="<?= h((string)($editing_quote['container_size'] ?? '')) ?>" />
        </div>
        <div>
          <label>Port of Loading</label>
          <input type="text" name="quote_port_of_loading" maxlength="255"
                 value="<?= h((string)($editing_quote['port_of_loading'] ?? '')) ?>" />
        </div>
        <div>
          <label>Destination</label>
          <input type="text" name="quote_destination" maxlength="255"
                 value="<?= h((string)($editing_quote['destination'] ?? '')) ?>" />
        </div>
        <div>
          <label>Quote Status</label>
          <select name="quote_status">
            <?php foreach ($quote_statuses as $k => $label): ?>
              <option value="<?= h($k) ?>"
                      <?= ($editing_quote['quote_status'] ?? '') === $k ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Received On</label>
          <input type="date" name="received_on"
                 value="<?= h((string)($editing_quote['received_on'] ?? '')) ?>" />
        </div>
        <div class="full">
          <label>Notes</label>
          <textarea name="notes" rows="3" maxlength="5000"><?= h((string)($editing_quote['notes'] ?? '')) ?></textarea>
        </div>
        <div class="full row" style="margin-top:4px;">
          <button type="submit" class="btn primary">Save Changes</button>
          <a class="btn" href="freight_quote_tracker.php?rfq_id=<?= (int)$selected_rfq['id'] ?>">Cancel</a>
        </div>
      </form>
      <hr style="margin:18px 0;" />
    <?php else: ?>
      <!-- Add quote form -->
      <div style="margin-bottom:12px;">
        <button type="button" class="btn" id="toggle-ai-fill-quote">AI Fill Quote</button>
      </div>
      <div id="ai-fill-quote-panel" style="display:none; margin-bottom:14px; padding:12px; border:1px solid var(--line,#e5e7eb); border-radius:8px;"
           data-open="<?= $ai_fill_show_panel ? '1' : '0' ?>">
        <form method="post" novalidate>
          <input type="hidden" name="csrf_token" value="<?= h($_SESSION['srfq_tracker_csrf']) ?>" />
          <input type="hidden" name="action"  value="ai_fill_quote" />
          <input type="hidden" name="rfq_id"  value="<?= (int)$selected_rfq['id'] ?>" />
          <label for="ai_quote_text"><strong>Paste forwarder text</strong></label>
          <textarea id="ai_quote_text" name="ai_quote_text" rows="7" maxlength="20000"
                    placeholder="Paste forwarder email or quote text here..."><?= h($ai_fill_source_text) ?></textarea>
          <div class="row" style="margin-top:8px;">
            <button type="submit" class="btn primary">Fill Form</button>
          </div>
        </form>
      </div>
      <?php
        $add_forwarder_name_value = trim((string)($ai_prefill['forwarder_name'] ?? ''));
        $add_forwarder_other_value = trim((string)($ai_prefill['forwarder_name_other'] ?? ''));
        $add_forwarder_is_other = $add_forwarder_name_value === '__other__';
      ?>
      <h3 style="margin-top:0; margin-bottom:12px;">Add Quote</h3>
      <form method="post" class="form-grid" novalidate>
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['srfq_tracker_csrf']) ?>" />
        <input type="hidden" name="action"  value="add_quote" />
        <input type="hidden" name="rfq_id"  value="<?= (int)$selected_rfq['id'] ?>" />
        <div>
          <label>Freight Forwarder / Carrier <span style="color:var(--d)">*</span></label>
          <select name="forwarder_name" id="add_forwarder_name" required data-forwarder-select data-other-target="add_forwarder_name_other">
            <option value="">Select forwarder</option>
            <?php foreach ($forwarder_options as $forwarder_name): ?>
              <option value="<?= h($forwarder_name) ?>" <?= $add_forwarder_name_value === $forwarder_name ? 'selected' : '' ?>><?= h($forwarder_name) ?></option>
            <?php endforeach; ?>
            <option value="__other__" <?= $add_forwarder_is_other ? 'selected' : '' ?>>Other</option>
          </select>
          <input type="text" name="forwarder_name_other" id="add_forwarder_name_other" maxlength="255"
                 placeholder="Enter freight forwarder / carrier name" value="<?= h($add_forwarder_other_value) ?>"
                 style="<?= $add_forwarder_is_other ? '' : 'display:none;' ?>" />
        </div>
        <div>
          <label>Quote Amount <span style="color:var(--d)">*</span></label>
          <input type="number" name="quote_amount" min="0" step="0.01" required placeholder="0.00"
                 value="<?= h((string)($ai_prefill['quote_amount'] ?? '')) ?>" />
        </div>
        <div>
          <label>Currency</label>
          <input type="text" name="currency" maxlength="3" value="USD" placeholder="USD" />
        </div>
        <div>
          <label>Transit Time (days)</label>
          <input type="number" name="transit_time_days" min="1" placeholder="e.g. 30"
                 value="<?= h((string)($ai_prefill['transit_time_days'] ?? '')) ?>" />
        </div>
        <div>
          <label>Shipment Type</label>
          <select name="quote_shipment_type">
            <option value="LCL" selected>LCL</option>
            <option value="FCL">FCL</option>
          </select>
        </div>
        <div>
          <label>Container Size</label>
          <input type="text" name="container_size" maxlength="50" placeholder="e.g. 20ft, 40ft HQ" />
        </div>
        <div>
          <label>Port of Loading</label>
          <input type="text" name="quote_port_of_loading" maxlength="255"
                 value="<?= h($selected_rfq['port_of_loading']) ?>" placeholder="e.g. Shanghai, China" />
        </div>
        <div>
          <label>Destination</label>
          <input type="text" name="quote_destination" maxlength="255"
                 value="<?= h($sel_dest_label) ?>" placeholder="e.g. Port of Los Angeles" />
        </div>
        <div>
          <label>Quote Status</label>
          <select name="quote_status">
            <?php foreach ($quote_statuses as $k => $label): ?>
              <option value="<?= h($k) ?>" <?= $k === 'received' ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Received On</label>
          <input type="date" name="received_on" value="<?= h(date('Y-m-d')) ?>" />
        </div>
        <div class="full">
          <label>Notes</label>
          <textarea name="notes" rows="3" maxlength="5000"
                    placeholder="Include charges breakdown, incoterm, insurance, etc."><?= h((string)($ai_prefill['notes'] ?? '')) ?></textarea>
        </div>
        <div class="full row" style="margin-top:4px;">
          <button type="submit" class="btn primary">Add Quote</button>
        </div>
      </form>
      <hr style="margin:18px 0;" />
    <?php endif; ?>

    <!-- Quotes table -->
    <h3 style="margin-bottom:10px;">Received Quotes (<?= count($rfq_quotes) ?>)</h3>
    <?php if (!$rfq_quotes): ?>
      <p class="muted">No quotes yet. Add the first quote above.</p>
    <?php else: ?>
      <div class="table-wrap" style="overflow-x:auto;">
        <table class="table-auto" style="min-width:700px;">
          <thead>
            <tr>
              <th>Forwarder</th>
              <th>Amount</th>
              <th>Transit</th>
              <th>Type</th>
              <th>Status</th>
              <th class="col-actions">Actions</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rfq_quotes as $q): ?>
              <?php
                [$qbg, $qc] = $quote_status_styles[(string)($q['quote_status'] ?? 'received')] ?? ['#f3f4f6', '#374151'];
              ?>
              <tr>
                <td>
                  <strong><?= h($q['forwarder_name']) ?></strong><br>
                  <span class="muted" style="font-size:.82em;">
                    <?php if ($q['port_of_loading']): ?><?= h($q['port_of_loading']) ?> → <?php endif; ?>
                    <?php if ($q['destination']): ?><?= h($q['destination']) ?><?php endif; ?>
                    <?php if ($q['container_size']): ?> · <?= h($q['container_size']) ?><?php endif; ?>
                  </span>
                </td>
                <td>
                  <?= h(number_format((float)$q['quote_amount'], 2)) ?>
                  <span class="muted"><?= h((string)($q['currency'] ?? 'USD')) ?></span>
                </td>
                <td class="muted">
                  <?= $q['transit_time_days'] !== null ? (int)$q['transit_time_days'] . ' days' : '—' ?>
                </td>
                <td><span style="font-weight:600;"><?= h((string)($q['shipment_type'] ?? 'LCL')) ?></span></td>
                <td>
                  <span style="display:inline-block; padding:2px 10px; border-radius:12px; font-size:.78em; font-weight:600;
                               background:<?= h($qbg) ?>; color:<?= h($qc) ?>;">
                    <?= h($quote_statuses[(string)($q['quote_status'] ?? 'received')] ?? ucfirst((string)$q['quote_status'])) ?>
                  </span>
                </td>
                <td class="col-actions">
                  <a class="btn" href="freight_quote_tracker.php?rfq_id=<?= (int)$selected_rfq['id'] ?>&edit_quote_id=<?= (int)$q['id'] ?>">Edit</a>
                  <form method="post" style="display:inline;"
                        onsubmit="return confirm('Delete this quote? This cannot be undone.');">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['srfq_tracker_csrf']) ?>" />
                    <input type="hidden" name="action"   value="delete_quote" />
                    <input type="hidden" name="rfq_id"   value="<?= (int)$selected_rfq['id'] ?>" />
                    <input type="hidden" name="quote_id" value="<?= (int)$q['id'] ?>" />
                    <button type="submit" class="btn danger">Delete</button>
                  </form>
                </td>
              </tr>
              <?php if (trim((string)($q['notes'] ?? '')) !== ''): ?>
              <tr>
                <td colspan="6" style="padding-top:0; padding-bottom:6px;">
                  <span class="muted" style="font-size:.83em; white-space:pre-wrap;"><?= h($q['notes']) ?></span>
                </td>
              </tr>
              <?php endif; ?>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  </div>
<?php endif; ?>

<script>
(function () {
  // Color-code status selects on change
  document.querySelectorAll('select.status-select').forEach(function (sel) {
    sel.addEventListener('change', function () {
      var opt = sel.options[sel.selectedIndex];
      var bg    = opt.getAttribute('data-bg')    || '#f3f4f6';
      var color = opt.getAttribute('data-color') || '#374151';
      sel.style.background  = bg;
      sel.style.color       = color;
      sel.style.borderColor = bg;
    });
  });

  document.querySelectorAll('[data-forwarder-select]').forEach(function (sel) {
    var targetId = sel.getAttribute('data-other-target');
    var otherInput = targetId ? document.getElementById(targetId) : null;
    if (!otherInput) return;
    var sync = function () {
      var showOther = sel.value === '__other__';
      otherInput.style.display = showOther ? '' : 'none';
      otherInput.required = showOther;
    };
    sel.addEventListener('change', sync);
    sync();
  });

  var heroJumpButton = document.getElementById('focus-srfq-filters');
  var searchInput = document.getElementById('shipping-rfq-search-input');
  if (heroJumpButton && searchInput) {
    heroJumpButton.addEventListener('click', function () {
      searchInput.scrollIntoView({ behavior: 'smooth', block: 'center' });
      requestAnimationFrame(function () {
        searchInput.focus();
      });
    });
  }

  var aiToggleButton = document.getElementById('toggle-ai-fill-quote');
  var aiFillPanel = document.getElementById('ai-fill-quote-panel');
  if (aiToggleButton && aiFillPanel) {
    var isOpenByDefault = aiFillPanel.getAttribute('data-open') === '1';
    var syncAiPanel = function (show) {
      aiFillPanel.style.display = show ? '' : 'none';
    };
    syncAiPanel(isOpenByDefault);
    aiToggleButton.addEventListener('click', function () {
      syncAiPanel(aiFillPanel.style.display === 'none');
    });
  }
})();
</script>

<?php render_footer(); ?>
