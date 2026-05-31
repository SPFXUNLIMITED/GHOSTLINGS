<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_rfq_access();

const MAX_LEAD_TIME_DAYS = 3650;
const MAX_QUOTE_UPLOAD_BYTES = 26214400; // 25 MB

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
if (empty($_SESSION['rfq_tracker_csrf'])) {
  $_SESSION['rfq_tracker_csrf'] = bin2hex(random_bytes(24));
}

$request_statuses = [
  'draft' => 'Draft',
  'sourcing' => 'Sourcing',
  'quotes_received' => 'Quotes Received',
  'shortlisted' => 'Shortlisted',
  'ordered' => 'Ordered',
  'closed' => 'Closed',
];
$request_status_styles = [
  'draft' => ['#f3f4f6', '#374151'],
  'sourcing' => ['#dbeafe', '#1d4ed8'],
  'quotes_received' => ['#dcfce7', '#166534'],
  'shortlisted' => ['#fef3c7', '#92400e'],
  'ordered' => ['#ede9fe', '#6d28d9'],
  'closed' => ['#fee2e2', '#991b1b'],
];
$quote_statuses = [
  'received' => 'Received',
  'under_review' => 'Under Review',
  'negotiating' => 'Negotiating',
  'accepted' => 'Accepted',
  'rejected' => 'Rejected',
  'lost' => 'Lost',
];
$quote_status_styles = [
  'received' => ['#dcfce7', '#166534'],
  'under_review' => ['#dbeafe', '#1d4ed8'],
  'negotiating' => ['#fef3c7', '#92400e'],
  'accepted' => ['#ede9fe', '#6d28d9'],
  'rejected' => ['#fee2e2', '#991b1b'],
  'lost' => ['#f3f4f6', '#374151'],
];
$urgency_badges = [
  'low' => ['Low', '#ecfeff', '#155e75'],
  'normal' => ['Normal', '#e2e8f0', '#334155'],
  'high' => ['High', '#ffedd5', '#9a3412'],
  'critical' => ['Critical', '#fee2e2', '#991b1b'],
];

$errors = [];
$success = '';
$selected_rfq_id = 0;
$edit_quote_id = 0;
$add_quote_post = null;
$edit_quote_post = null;

function format_shipping_details(?string $origin, ?string $method): string {
  $origin = trim((string)$origin);
  $method = trim((string)$method);
  if ($origin === '' && $method === '') {
    return '—';
  }
  if ($origin !== '' && $method !== '') {
    return $origin . ' • ' . $method;
  }
  return $origin !== '' ? $origin : $method;
}

function get_status_select_style(array $status_styles, ?string $status): string {
  [$background, $color] = $status_styles[(string)$status] ?? ['#f3f4f6', '#374151'];
  return 'background:' . $background . '; color:' . $color . '; border-color:' . $background . '; font-weight:600;';
}

function build_rfq_email_text(array $rfq): string {
  $sep  = str_repeat('=', 60);
  $sep2 = str_repeat('-', 60);
  $date = date('F j, Y', strtotime((string)$rfq['created_at']));

  $contact_name  = trim((string)($rfq['contact_name']  ?? ''));
  $company_name  = trim((string)($rfq['company_name']  ?? ''));
  $contact_email = trim((string)($rfq['contact_email'] ?? ''));
  $contact_phone = trim((string)($rfq['contact_phone'] ?? ''));
  $requested_by  = trim((string)($rfq['requested_by_username'] ?? 'Unknown'));

  $lines = [
    $sep,
    'REQUEST FOR QUOTATION (RFQ)',
    $sep,
    '',
    'RFQ #:        ' . (int)$rfq['id'],
    'Date:         ' . $date,
    'Status:       ' . ucfirst(str_replace('_', ' ', trim((string)$rfq['request_status']))),
    '',
    $sep2,
    'FROM:',
    $sep2,
  ];

  if ($company_name !== '') {
    $lines[] = 'Company:      ' . $company_name;
  }
  if ($contact_name !== '') {
    $lines[] = 'Contact:      ' . $contact_name;
  } else {
    $lines[] = 'Requested By: ' . $requested_by;
  }
  if ($contact_email !== '') {
    $lines[] = 'Email:        ' . $contact_email;
  }
  if ($contact_phone !== '') {
    $lines[] = 'Phone:        ' . $contact_phone;
  }

  $request_category = trim((string)($rfq['request_category'] ?? 'machine'));
  $is_parts_request = $request_category === 'parts';
  $lines = array_merge($lines, [
    '',
    $sep2,
    $is_parts_request ? 'PARTS REQUEST DETAILS:' : 'MACHINE SPECIFICATIONS:',
    $sep2,
    'Title:        ' . trim((string)$rfq['request_title']),
    'Quantity:     ' . (int)$rfq['quantity'],
  ]);

  if ($is_parts_request) {
    $lines[] = 'Part Category: ' . trim((string)($rfq['part_category'] ?? ''));
    $lines[] = '';
    $lines[] = $sep2;
    $lines[] = 'PART SPECS:';
    $lines[] = $sep2;
    $lines[] = trim((string)($rfq['part_specs'] ?? ''));
  } else {
    $lines[] = 'Machine Size: ' . trim((string)$rfq['machine_size']);
    $lines[] = 'Laser Watts:  ' . trim((string)$rfq['laser_watts']);
    $lines[] = 'Tube Type:    ' . trim((string)$rfq['tube_type']);
    $lines[] = '';
    $lines[] = $sep2;
    $lines[] = 'REQUIRED FEATURES:';
    $lines[] = $sep2;
    $lines[] = trim((string)$rfq['required_features']);
  }

  $additional_notes = trim((string)($rfq['additional_notes'] ?? ''));
  if ($additional_notes !== '') {
    $lines[] = '';
    $lines[] = $sep2;
    $lines[] = 'ADDITIONAL NOTES:';
    $lines[] = $sep2;
    $lines[] = $additional_notes;
  }

  $lines[] = '';
  $lines[] = $sep;
  $lines[] = 'Please reply with your best quotation at your earliest convenience.';
  $lines[] = $sep;

  return implode("\n", $lines);
}

function is_safe_stored_upload_name(string $name): bool {
  return (bool)preg_match('/^[a-zA-Z0-9._-]+$/', $name);
}

function sanitize_upload_original_name(string $name): string {
  $name = trim($name);
  $name = preg_replace('/[\x00-\x1F\x7F]+/u', '', $name) ?? '';
  $name = str_replace(['\\', '/'], '_', $name);
  $name = preg_replace('/\s+/', ' ', $name) ?? '';
  if ($name === '') {
    return 'quote-file';
  }
  if (mb_strlen($name) > 255) {
    return mb_substr($name, 0, 255);
  }
  return $name;
}

function allowed_quote_upload_extensions(): array {
  return ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'csv', 'txt', 'png', 'jpg', 'jpeg', 'gif', 'webp', 'zip'];
}

function allowed_quote_upload_mime_types(): array {
  return [
    'application/pdf',
    'application/msword',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
    'application/vnd.ms-excel',
    'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
    'text/csv',
    'text/plain',
    'image/png',
    'image/jpeg',
    'image/gif',
    'image/webp',
    'application/zip',
    'application/x-zip-compressed',
  ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submitted_csrf = (string)($_POST['csrf_token'] ?? '');
  if (empty($_SESSION['rfq_tracker_csrf']) || !hash_equals((string)$_SESSION['rfq_tracker_csrf'], $submitted_csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  } else {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'update_request_status') {
      $rfq_id = (int)($_POST['rfq_id'] ?? 0);
      $new_status = (string)($_POST['request_status'] ?? '');
      if ($rfq_id <= 0) {
        $errors[] = 'Invalid RFQ request.';
      } elseif (!isset($request_statuses[$new_status])) {
        $errors[] = 'Invalid RFQ status selected.';
      } else {
        $stmt = $pdo->prepare("UPDATE rfq_requests SET request_status = ? WHERE id = ?");
        $stmt->execute([$new_status, $rfq_id]);
        if ($stmt->rowCount() > 0) {
          $success = 'RFQ status updated.';
        } else {
          $errors[] = 'RFQ not found.';
        }
      }
    } elseif ($action === 'update_quote_status') {
      $rfq_id = (int)($_POST['rfq_id'] ?? 0);
      $quote_id = (int)($_POST['quote_id'] ?? 0);
      $new_status = (string)($_POST['quote_status'] ?? '');
      if ($rfq_id <= 0 || $quote_id <= 0) {
        $errors[] = 'Invalid quote.';
      } elseif (!isset($quote_statuses[$new_status])) {
        $errors[] = 'Invalid quote status selected.';
      } else {
        $stmt = $pdo->prepare("UPDATE rfq_quotes SET quote_status = ? WHERE id = ? AND rfq_request_id = ?");
        $stmt->execute([$new_status, $quote_id, $rfq_id]);
        if ($stmt->rowCount() > 0) {
          $success = 'Quote status updated.';
        } else {
          $errors[] = 'Quote not found.';
        }
        $selected_rfq_id = $rfq_id;
      }
    } elseif ($action === 'add_quote') {
      $rfq_id = (int)($_POST['rfq_id'] ?? 0);
      $supplier_name = trim((string)($_POST['supplier_name'] ?? ''));
      $model_name = trim((string)($_POST['model_name'] ?? ''));
      $sku = trim((string)($_POST['sku'] ?? ''));
      $msrp_raw = trim((string)($_POST['msrp'] ?? ''));
      $map_price_raw = trim((string)($_POST['map_price'] ?? ''));
      $moq_20_price_raw = trim((string)($_POST['moq_20_price'] ?? ''));
      $moq_10_price_raw = trim((string)($_POST['moq_10_price'] ?? ''));
      $drop_ship_price_raw = trim((string)($_POST['drop_ship_price'] ?? ''));
      $quote_amount_raw = trim((string)($_POST['quote_amount'] ?? ''));
      $currency = strtoupper(trim((string)($_POST['currency'] ?? 'USD')));
      $lead_time_days_raw = trim((string)($_POST['lead_time_days'] ?? ''));
      $shipping_cost_raw = trim((string)($_POST['shipping_cost'] ?? ''));
      $shipping_origin = trim((string)($_POST['shipping_origin'] ?? ''));
      $shipping_method = trim((string)($_POST['shipping_method'] ?? ''));
      $quote_status = (string)($_POST['quote_status'] ?? 'received');
      $received_on = trim((string)($_POST['received_on'] ?? ''));
      $notes = trim((string)($_POST['notes'] ?? ''));
      $quote_file = $_FILES['quote_file'] ?? null;
      $has_quote_file = is_array($quote_file) && (($quote_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);

      if ($rfq_id <= 0) $errors[] = 'Invalid RFQ request selected.';
      if ($supplier_name === '') $errors[] = 'Supplier name is required.';
      if (strlen($model_name) > 255) $errors[] = 'Model name must be 255 characters or fewer.';
      if (strlen($sku) > 100) $errors[] = 'SKU must be 100 characters or fewer.';
      if ($msrp_raw !== '' && (!is_numeric($msrp_raw) || (float)$msrp_raw < 0)) {
        $errors[] = 'MSRP must be a non-negative number.';
      }
      if ($map_price_raw !== '' && (!is_numeric($map_price_raw) || (float)$map_price_raw < 0)) {
        $errors[] = 'MAP must be a non-negative number.';
      }
      if ($moq_20_price_raw !== '' && (!is_numeric($moq_20_price_raw) || (float)$moq_20_price_raw < 0)) {
        $errors[] = 'MOQ 20 must be a non-negative number.';
      }
      if ($moq_10_price_raw !== '' && (!is_numeric($moq_10_price_raw) || (float)$moq_10_price_raw < 0)) {
        $errors[] = 'MOQ 10 must be a non-negative number.';
      }
      if ($drop_ship_price_raw !== '' && (!is_numeric($drop_ship_price_raw) || (float)$drop_ship_price_raw < 0)) {
        $errors[] = 'Drop Ship must be a non-negative number.';
      }
      if ($quote_amount_raw === '' || !is_numeric($quote_amount_raw) || (float)$quote_amount_raw < 0) {
        $errors[] = 'Quote amount must be a non-negative number.';
      }
      if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        $errors[] = 'Currency must be a 3-letter code (e.g. USD, CNY).';
      }
      if ($lead_time_days_raw !== '' && (!ctype_digit($lead_time_days_raw) || (int)$lead_time_days_raw > MAX_LEAD_TIME_DAYS)) {
        $errors[] = 'Lead time must be a whole number of days up to ' . MAX_LEAD_TIME_DAYS . '.';
      }
      if ($shipping_cost_raw !== '' && (!is_numeric($shipping_cost_raw) || (float)$shipping_cost_raw < 0)) {
        $errors[] = 'Shipping cost must be a non-negative number.';
      }
      if (!isset($quote_statuses[$quote_status])) {
        $errors[] = 'Invalid quote status selected.';
      }
      if ($received_on !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', $received_on);
        if (!$dt || $dt->format('Y-m-d') !== $received_on) {
          $errors[] = 'Received date must be in YYYY-MM-DD format.';
        } elseif ($dt->format('Y-m-d') > date('Y-m-d')) {
          $errors[] = 'Received date cannot be in the future.';
        }
      }
      if (strlen($notes) > 5000) {
        $errors[] = 'Notes must be 5000 characters or fewer.';
      }
      if ($has_quote_file && ($quote_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'Quote file upload failed (code ' . (int)($quote_file['error'] ?? 0) . ').';
      }
      if ($has_quote_file && ((int)($quote_file['size'] ?? 0) > MAX_QUOTE_UPLOAD_BYTES)) {
        $errors[] = 'Quote file must be 25 MB or smaller.';
      }

      if (!$errors) {
        $exists = $pdo->prepare("SELECT id FROM rfq_requests WHERE id = ? LIMIT 1");
        $exists->execute([$rfq_id]);
        if (!$exists->fetch()) {
          $errors[] = 'RFQ request not found.';
        } else {
          $quote_file_original_name = null;
          $quote_file_stored_name = null;
          $quote_file_mime_type = null;
          $quote_file_size_bytes = null;

          if ($has_quote_file) {
            $uploads_dir = __DIR__ . '/uploads';
            if (!is_dir($uploads_dir) && !mkdir($uploads_dir, 0775, true) && !is_dir($uploads_dir)) {
              $errors[] = 'Failed to create uploads directory.';
            }
            if (!is_dir($uploads_dir) || !is_writable($uploads_dir)) {
              $errors[] = 'Uploads directory is missing or not writable.';
            } else {
              $quote_file_original_name = sanitize_upload_original_name((string)($quote_file['name'] ?? 'quote-file'));
              $tmp_path = (string)($quote_file['tmp_name'] ?? '');
              $quote_file_size_bytes = (int)($quote_file['size'] ?? 0);

              $ext_raw = '';
              $dot = strrpos($quote_file_original_name, '.');
              if ($dot !== false) {
                $ext_raw = strtolower(substr($quote_file_original_name, $dot + 1));
                $ext_raw = preg_replace('/[^a-z0-9]+/i', '', $ext_raw) ?? '';
              }
              if ($ext_raw === '' || !in_array($ext_raw, allowed_quote_upload_extensions(), true)) {
                $errors[] = 'Unsupported quote file type.';
              }

              if (!function_exists('finfo_open')) {
                $errors[] = 'Server configuration error: MIME detection is unavailable.';
              } elseif (is_file($tmp_path)) {
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                if ($fi) {
                  $quote_file_mime_type = finfo_file($fi, $tmp_path) ?: null;
                  finfo_close($fi);
                }
              }
              if ($quote_file_mime_type === null || !in_array($quote_file_mime_type, allowed_quote_upload_mime_types(), true)) {
                $errors[] = 'Unsupported quote file MIME type.';
              }

              $ext = '';
              if ($ext_raw !== '') {
                $ext = '.' . $ext_raw;
              }
              if ($errors) {
                $quote_file_original_name = null;
                $quote_file_stored_name = null;
                $quote_file_mime_type = null;
                $quote_file_size_bytes = null;
              } else {
                $quote_file_stored_name = 'rfq' . $rfq_id . '_' . bin2hex(random_bytes(16)) . $ext;
                $dest_path = $uploads_dir . '/' . $quote_file_stored_name;

                if (!move_uploaded_file($tmp_path, $dest_path)) {
                  $errors[] = 'Failed to save uploaded quote file.';
                  $quote_file_original_name = null;
                  $quote_file_stored_name = null;
                  $quote_file_mime_type = null;
                  $quote_file_size_bytes = null;
                }
              }
            }
          }

          if ($errors) {
            // keep request selected so user can retry quickly
            $selected_rfq_id = $rfq_id;
          } else {
          $ins = $pdo->prepare(
            "INSERT INTO rfq_quotes
            (rfq_request_id, supplier_name, model_name, sku, msrp, map_price, moq_20_price, moq_20_margin_msrp,
             moq_20_margin_map, moq_10_price, moq_10_margin_msrp, moq_10_margin_map, drop_ship_price,
             drop_ship_margin_msrp, drop_ship_margin_map, quote_amount, currency, lead_time_days, shipping_cost,
               shipping_origin, shipping_method, quote_status, received_on, notes, created_by,
               quote_file_original_name, quote_file_stored_name, quote_file_mime_type, quote_file_size_bytes)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
          );
          $ins->execute([
            $rfq_id,
            $supplier_name,
            $model_name === '' ? null : $model_name,
            $sku === '' ? null : $sku,
            $msrp_raw === '' ? null : (float)$msrp_raw,
            $map_price_raw === '' ? null : (float)$map_price_raw,
            $moq_20_price_raw === '' ? null : (float)$moq_20_price_raw,
            null,
            null,
            $moq_10_price_raw === '' ? null : (float)$moq_10_price_raw,
            null,
            null,
            $drop_ship_price_raw === '' ? null : (float)$drop_ship_price_raw,
            null,
            null,
            (float)$quote_amount_raw,
            $currency,
            $lead_time_days_raw === '' ? null : (int)$lead_time_days_raw,
            $shipping_cost_raw === '' ? null : (float)$shipping_cost_raw,
            $shipping_origin === '' ? null : $shipping_origin,
            $shipping_method === '' ? null : $shipping_method,
            $quote_status,
            $received_on === '' ? null : $received_on,
            $notes === '' ? null : $notes,
            (int)current_user_id(),
            $quote_file_original_name,
            $quote_file_stored_name,
            $quote_file_mime_type,
            $quote_file_size_bytes,
          ]);
          $success = 'Quote added to RFQ tracker.';
          $selected_rfq_id = $rfq_id;
          }
        }
      }
      if ($errors) {
        if ($rfq_id > 0) {
          $selected_rfq_id = $rfq_id;
        }
        $add_quote_post = [
          'supplier_name'   => $supplier_name,
          'model_name'      => $model_name,
          'sku'             => $sku,
          'msrp'            => $msrp_raw,
          'map_price'       => $map_price_raw,
          'moq_20_price'    => $moq_20_price_raw,
          'moq_10_price'    => $moq_10_price_raw,
          'drop_ship_price' => $drop_ship_price_raw,
          'quote_amount'    => $quote_amount_raw,
          'currency'        => $currency,
          'lead_time_days'  => $lead_time_days_raw,
          'shipping_cost'   => $shipping_cost_raw,
          'shipping_origin' => $shipping_origin,
          'shipping_method' => $shipping_method,
          'quote_status'    => $quote_status,
          'received_on'     => $received_on,
          'notes'           => $notes,
        ];
      }
    } elseif ($action === 'edit_quote') {
      $quote_id = (int)($_POST['quote_id'] ?? 0);
      $rfq_id = (int)($_POST['rfq_id'] ?? 0);
      $supplier_name = trim((string)($_POST['supplier_name'] ?? ''));
      $model_name = trim((string)($_POST['model_name'] ?? ''));
      $sku = trim((string)($_POST['sku'] ?? ''));
      $msrp_raw = trim((string)($_POST['msrp'] ?? ''));
      $map_price_raw = trim((string)($_POST['map_price'] ?? ''));
      $moq_20_price_raw = trim((string)($_POST['moq_20_price'] ?? ''));
      $moq_10_price_raw = trim((string)($_POST['moq_10_price'] ?? ''));
      $drop_ship_price_raw = trim((string)($_POST['drop_ship_price'] ?? ''));
      $quote_amount_raw = trim((string)($_POST['quote_amount'] ?? ''));
      $currency = strtoupper(trim((string)($_POST['currency'] ?? 'USD')));
      $lead_time_days_raw = trim((string)($_POST['lead_time_days'] ?? ''));
      $shipping_cost_raw = trim((string)($_POST['shipping_cost'] ?? ''));
      $shipping_origin = trim((string)($_POST['shipping_origin'] ?? ''));
      $shipping_method = trim((string)($_POST['shipping_method'] ?? ''));
      $quote_status = (string)($_POST['quote_status'] ?? 'received');
      $received_on = trim((string)($_POST['received_on'] ?? ''));
      $notes = trim((string)($_POST['notes'] ?? ''));
      $remove_file = !empty($_POST['remove_file']);
      $quote_file = $_FILES['quote_file'] ?? null;
      $has_quote_file = is_array($quote_file) && (($quote_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE);

      if ($quote_id <= 0) $errors[] = 'Invalid quote.';
      if ($rfq_id <= 0) $errors[] = 'Invalid RFQ request.';
      if ($supplier_name === '') $errors[] = 'Supplier name is required.';
      if (strlen($model_name) > 255) $errors[] = 'Model name must be 255 characters or fewer.';
      if (strlen($sku) > 100) $errors[] = 'SKU must be 100 characters or fewer.';
      if ($msrp_raw !== '' && (!is_numeric($msrp_raw) || (float)$msrp_raw < 0)) {
        $errors[] = 'MSRP must be a non-negative number.';
      }
      if ($map_price_raw !== '' && (!is_numeric($map_price_raw) || (float)$map_price_raw < 0)) {
        $errors[] = 'MAP must be a non-negative number.';
      }
      if ($moq_20_price_raw !== '' && (!is_numeric($moq_20_price_raw) || (float)$moq_20_price_raw < 0)) {
        $errors[] = 'MOQ 20 must be a non-negative number.';
      }
      if ($moq_10_price_raw !== '' && (!is_numeric($moq_10_price_raw) || (float)$moq_10_price_raw < 0)) {
        $errors[] = 'MOQ 10 must be a non-negative number.';
      }
      if ($drop_ship_price_raw !== '' && (!is_numeric($drop_ship_price_raw) || (float)$drop_ship_price_raw < 0)) {
        $errors[] = 'Drop Ship must be a non-negative number.';
      }
      if ($quote_amount_raw === '' || !is_numeric($quote_amount_raw) || (float)$quote_amount_raw < 0) {
        $errors[] = 'Quote amount must be a non-negative number.';
      }
      if (!preg_match('/^[A-Z]{3}$/', $currency)) {
        $errors[] = 'Currency must be a 3-letter code (e.g. USD, CNY).';
      }
      if ($lead_time_days_raw !== '' && (!ctype_digit($lead_time_days_raw) || (int)$lead_time_days_raw > MAX_LEAD_TIME_DAYS)) {
        $errors[] = 'Lead time must be a whole number of days up to ' . MAX_LEAD_TIME_DAYS . '.';
      }
      if ($shipping_cost_raw !== '' && (!is_numeric($shipping_cost_raw) || (float)$shipping_cost_raw < 0)) {
        $errors[] = 'Shipping cost must be a non-negative number.';
      }
      if (!isset($quote_statuses[$quote_status])) {
        $errors[] = 'Invalid quote status selected.';
      }
      if ($received_on !== '') {
        $dt = DateTime::createFromFormat('Y-m-d', $received_on);
        if (!$dt || $dt->format('Y-m-d') !== $received_on) {
          $errors[] = 'Received date must be in YYYY-MM-DD format.';
        } elseif ($dt->format('Y-m-d') > date('Y-m-d')) {
          $errors[] = 'Received date cannot be in the future.';
        }
      }
      if (strlen($notes) > 5000) {
        $errors[] = 'Notes must be 5000 characters or fewer.';
      }
      if ($has_quote_file && ($quote_file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errors[] = 'Quote file upload failed (code ' . (int)($quote_file['error'] ?? 0) . ').';
      }
      if ($has_quote_file && ((int)($quote_file['size'] ?? 0) > MAX_QUOTE_UPLOAD_BYTES)) {
        $errors[] = 'Quote file must be 25 MB or smaller.';
      }

      if (!$errors) {
        $existing = $pdo->prepare("SELECT * FROM rfq_quotes WHERE id = ? AND rfq_request_id = ? LIMIT 1");
        $existing->execute([$quote_id, $rfq_id]);
        $existing_quote = $existing->fetch();
        if (!$existing_quote) {
          $errors[] = 'Quote not found.';
        } else {
          $new_file_original_name = $existing_quote['quote_file_original_name'];
          $new_file_stored_name = $existing_quote['quote_file_stored_name'];
          $new_file_mime_type = $existing_quote['quote_file_mime_type'];
          $new_file_size_bytes = $existing_quote['quote_file_size_bytes'];
          $old_stored_name = (string)($existing_quote['quote_file_stored_name'] ?? '');

          if ($has_quote_file) {
            $uploads_dir = __DIR__ . '/uploads';
            if (!is_dir($uploads_dir) && !mkdir($uploads_dir, 0775, true) && !is_dir($uploads_dir)) {
              $errors[] = 'Failed to create uploads directory.';
            }
            if (!is_dir($uploads_dir) || !is_writable($uploads_dir)) {
              $errors[] = 'Uploads directory is missing or not writable.';
            } else {
              $new_file_original_name = sanitize_upload_original_name((string)($quote_file['name'] ?? 'quote-file'));
              $tmp_path = (string)($quote_file['tmp_name'] ?? '');
              $new_file_size_bytes = (int)($quote_file['size'] ?? 0);

              $ext_raw = '';
              $dot = strrpos($new_file_original_name, '.');
              if ($dot !== false) {
                $ext_raw = strtolower(substr($new_file_original_name, $dot + 1));
                $ext_raw = preg_replace('/[^a-z0-9]+/i', '', $ext_raw) ?? '';
              }
              if ($ext_raw === '' || !in_array($ext_raw, allowed_quote_upload_extensions(), true)) {
                $errors[] = 'Unsupported quote file type.';
              }

              if (!function_exists('finfo_open')) {
                $errors[] = 'Server configuration error: MIME detection is unavailable.';
              } elseif (is_file($tmp_path)) {
                $fi = finfo_open(FILEINFO_MIME_TYPE);
                if ($fi) {
                  $new_file_mime_type = finfo_file($fi, $tmp_path) ?: null;
                  finfo_close($fi);
                }
              }
              if ($new_file_mime_type === null || !in_array($new_file_mime_type, allowed_quote_upload_mime_types(), true)) {
                $errors[] = 'Unsupported quote file MIME type.';
              }

              $ext = $ext_raw !== '' ? '.' . $ext_raw : '';
              if ($errors) {
                $new_file_original_name = $existing_quote['quote_file_original_name'];
                $new_file_stored_name = $existing_quote['quote_file_stored_name'];
                $new_file_mime_type = $existing_quote['quote_file_mime_type'];
                $new_file_size_bytes = $existing_quote['quote_file_size_bytes'];
              } else {
                $new_file_stored_name = 'rfq' . $rfq_id . '_' . bin2hex(random_bytes(16)) . $ext;
                $dest_path = __DIR__ . '/uploads/' . $new_file_stored_name;
                if (!move_uploaded_file($tmp_path, $dest_path)) {
                  $errors[] = 'Failed to save uploaded quote file.';
                  $new_file_original_name = $existing_quote['quote_file_original_name'];
                  $new_file_stored_name = $existing_quote['quote_file_stored_name'];
                  $new_file_mime_type = $existing_quote['quote_file_mime_type'];
                  $new_file_size_bytes = $existing_quote['quote_file_size_bytes'];
                }
              }
            }
          } elseif ($remove_file) {
            $new_file_original_name = null;
            $new_file_stored_name = null;
            $new_file_mime_type = null;
            $new_file_size_bytes = null;
          }

          if (!$errors) {
            $upd = $pdo->prepare(
              "UPDATE rfq_quotes SET
                supplier_name = ?, model_name = ?, sku = ?, msrp = ?, map_price = ?, moq_20_price = ?,
                moq_20_margin_msrp = ?, moq_20_margin_map = ?, moq_10_price = ?, moq_10_margin_msrp = ?,
                moq_10_margin_map = ?, drop_ship_price = ?, drop_ship_margin_msrp = ?, drop_ship_margin_map = ?,
                quote_amount = ?, currency = ?, lead_time_days = ?,
                shipping_cost = ?, shipping_origin = ?, shipping_method = ?, quote_status = ?,
                received_on = ?, notes = ?,
                quote_file_original_name = ?, quote_file_stored_name = ?,
                quote_file_mime_type = ?, quote_file_size_bytes = ?
               WHERE id = ? AND rfq_request_id = ?"
            );
            $upd->execute([
              $supplier_name,
              $model_name === '' ? null : $model_name,
              $sku === '' ? null : $sku,
              $msrp_raw === '' ? null : (float)$msrp_raw,
              $map_price_raw === '' ? null : (float)$map_price_raw,
              $moq_20_price_raw === '' ? null : (float)$moq_20_price_raw,
              null,
              null,
              $moq_10_price_raw === '' ? null : (float)$moq_10_price_raw,
              null,
              null,
              $drop_ship_price_raw === '' ? null : (float)$drop_ship_price_raw,
              null,
              null,
              (float)$quote_amount_raw,
              $currency,
              $lead_time_days_raw === '' ? null : (int)$lead_time_days_raw,
              $shipping_cost_raw === '' ? null : (float)$shipping_cost_raw,
              $shipping_origin === '' ? null : $shipping_origin,
              $shipping_method === '' ? null : $shipping_method,
              $quote_status,
              $received_on === '' ? null : $received_on,
              $notes === '' ? null : $notes,
              $new_file_original_name,
              $new_file_stored_name,
              $new_file_mime_type,
              $new_file_size_bytes,
              $quote_id,
              $rfq_id,
            ]);

            // Delete old file from disk if it was replaced or removed
            if (($has_quote_file || $remove_file) && $old_stored_name !== '' && is_safe_stored_upload_name($old_stored_name)) {
              $old_path = __DIR__ . '/uploads/' . $old_stored_name;
              if (is_file($old_path)) {
                @unlink($old_path);
              }
            }

            $redirect_query = http_build_query([
              'rfq_id' => (int)$rfq_id,
              'quote_id' => (int)$quote_id,
            ]);
            header('Location: rfq_quote_details.php?' . $redirect_query);
            exit;
          } else {
            $selected_rfq_id = $rfq_id;
            $edit_quote_id = $quote_id;
          }
        }
      } else {
        $selected_rfq_id = $rfq_id;
        $edit_quote_id = $quote_id;
      }
      if ($errors) {
        $edit_quote_post = [
          'supplier_name'   => $supplier_name,
          'model_name'      => $model_name,
          'sku'             => $sku,
          'msrp'            => $msrp_raw,
          'map_price'       => $map_price_raw,
          'moq_20_price'    => $moq_20_price_raw,
          'moq_10_price'    => $moq_10_price_raw,
          'drop_ship_price' => $drop_ship_price_raw,
          'quote_amount'    => $quote_amount_raw,
          'currency'        => $currency,
          'lead_time_days'  => $lead_time_days_raw !== '' ? $lead_time_days_raw : null,
          'shipping_cost'   => $shipping_cost_raw !== '' ? $shipping_cost_raw : null,
          'shipping_origin' => $shipping_origin !== '' ? $shipping_origin : null,
          'shipping_method' => $shipping_method !== '' ? $shipping_method : null,
          'quote_status'    => $quote_status,
          'received_on'     => $received_on !== '' ? $received_on : null,
          'notes'           => $notes !== '' ? $notes : null,
        ];
      }
    } elseif ($action === 'delete_rfq') {
      $rfq_id = (int)($_POST['rfq_id'] ?? 0);
      if ($rfq_id <= 0) {
        $errors[] = 'Invalid RFQ request.';
      } else {
        // Delete uploaded files for all quotes in this RFQ
        $file_rows = $pdo->prepare("SELECT quote_file_stored_name FROM rfq_quotes WHERE rfq_request_id = ?");
        $file_rows->execute([$rfq_id]);
        foreach ($file_rows->fetchAll() as $fr) {
          $stored = (string)($fr['quote_file_stored_name'] ?? '');
          if ($stored !== '' && is_safe_stored_upload_name($stored)) {
            $old_path = __DIR__ . '/uploads/' . $stored;
            if (is_file($old_path)) {
              @unlink($old_path);
            }
          }
        }
        $pdo->prepare("DELETE FROM rfq_quotes WHERE rfq_request_id = ?")->execute([$rfq_id]);
        $del = $pdo->prepare("DELETE FROM rfq_requests WHERE id = ?");
        $del->execute([$rfq_id]);
        if ($del->rowCount() > 0) {
          $success = 'RFQ request and all associated quotes deleted.';
        } else {
          $errors[] = 'RFQ not found.';
        }
      }
    } elseif ($action === 'delete_quote') {
      $quote_id = (int)($_POST['quote_id'] ?? 0);
      $rfq_id = (int)($_POST['rfq_id'] ?? 0);
      if ($quote_id <= 0 || $rfq_id <= 0) {
        $errors[] = 'Invalid quote or RFQ.';
      } else {
        $row = $pdo->prepare("SELECT quote_file_stored_name FROM rfq_quotes WHERE id = ? AND rfq_request_id = ? LIMIT 1");
        $row->execute([$quote_id, $rfq_id]);
        $del_quote = $row->fetch();
        if (!$del_quote) {
          $errors[] = 'Quote not found.';
        } else {
          $stored = (string)($del_quote['quote_file_stored_name'] ?? '');
          if ($stored !== '' && is_safe_stored_upload_name($stored)) {
            $old_path = __DIR__ . '/uploads/' . $stored;
            if (is_file($old_path)) {
              @unlink($old_path);
            }
          }
          $pdo->prepare("DELETE FROM rfq_quotes WHERE id = ? AND rfq_request_id = ?")->execute([$quote_id, $rfq_id]);
          $success = 'Quote deleted successfully.';
          $selected_rfq_id = $rfq_id;
        }
      }
    }
  }
}

$search = trim((string)($_GET['q'] ?? ''));
$status_filter = trim((string)($_GET['status'] ?? ''));
if ($selected_rfq_id <= 0) {
  $selected_rfq_id = max(0, (int)($_GET['rfq_id'] ?? 0));
}
if ($edit_quote_id <= 0) {
  $edit_quote_id = max(0, (int)($_GET['edit_quote_id'] ?? 0));
}
$rfq_text_id = max(0, (int)($_GET['rfq_text_id'] ?? 0));
$add_quote_id = max(0, (int)($_GET['add_quote_id'] ?? 0));

$where_parts = [];
$params = [];
if ($search !== '') {
  $where_parts[] = "(r.request_title LIKE :q OR r.machine_size LIKE :q OR r.laser_watts LIKE :q OR r.tube_type LIKE :q OR r.part_category LIKE :q OR r.part_specs LIKE :q OR r.required_features LIKE :q)";
  $params[':q'] = '%' . $search . '%';
}
if ($status_filter !== '' && isset($request_statuses[$status_filter])) {
  $where_parts[] = "r.request_status = :status";
  $params[':status'] = $status_filter;
}
$where_sql = $where_parts ? ('WHERE ' . implode(' AND ', $where_parts)) : '';

$sql = "
  SELECT
    r.id, r.request_category, r.request_title, r.machine_size, r.laser_watts, r.tube_type, r.part_category, r.part_specs, r.quantity,
    r.required_features, r.additional_notes, r.request_status, r.urgency, r.created_at, r.updated_at,
    u.username AS requested_by_username,
    COUNT(q.id) AS quote_count,
    MIN(q.quote_amount) AS lowest_quote_amount,
    MIN(q.lead_time_days) AS best_lead_time_days,
    MIN(q.shipping_cost) AS lowest_shipping_cost,
    GROUP_CONCAT(DISTINCT q.currency ORDER BY q.currency SEPARATOR ', ') AS quote_currencies
  FROM rfq_requests r
  LEFT JOIN users u ON u.id = r.requested_by
  LEFT JOIN rfq_quotes q ON q.rfq_request_id = r.id
  $where_sql
  GROUP BY r.id
  ORDER BY r.created_at DESC, r.id DESC
";
$stmt = $pdo->prepare($sql);
foreach ($params as $k => $v) {
  $stmt->bindValue($k, $v);
}
$stmt->execute();
$rfqs = $stmt->fetchAll();

$hero_total_rfqs   = count($rfqs);
$hero_active_rfqs  = 0;
$hero_sourcing_rfqs = 0;
$hero_quotes_total = 0;
foreach ($rfqs as $rfq_row) {
  $hero_status = (string)($rfq_row['request_status'] ?? 'draft');
  if ($hero_status !== 'closed') {
    $hero_active_rfqs++;
  }
  if ($hero_status === 'sourcing') {
    $hero_sourcing_rfqs++;
  }
  $hero_quotes_total += (int)($rfq_row['quote_count'] ?? 0);
}

$selected_rfq = null;
$quotes = [];
$editing_quote = null;
$rfq_email_text = '';
$show_add_quote_form = false;
$orders_by_quote_id = [];
if ($selected_rfq_id > 0) {
  $sel = $pdo->prepare("SELECT id, request_title FROM rfq_requests WHERE id = ? LIMIT 1");
  $sel->execute([$selected_rfq_id]);
  $selected_rfq = $sel->fetch();
  if ($selected_rfq) {
    $qs = $pdo->prepare(
      "SELECT q.*, u.username AS created_by_username
       FROM rfq_quotes q
       LEFT JOIN users u ON u.id = q.created_by
       WHERE q.rfq_request_id = ?
       ORDER BY COALESCE(q.received_on, DATE(q.created_at)) DESC, q.id DESC"
    );
    $qs->execute([$selected_rfq_id]);
    $quotes = $qs->fetchAll();

    $order_stmt = $pdo->prepare("SELECT id, rfq_quote_id, po_number FROM rfq_orders WHERE rfq_request_id = ?");
    $order_stmt->execute([$selected_rfq_id]);
    foreach ($order_stmt->fetchAll() as $order_row) {
      $orders_by_quote_id[(int)$order_row['rfq_quote_id']] = $order_row;
    }

    if ($edit_quote_id > 0) {
      foreach ($quotes as $q) {
        if ((int)$q['id'] === $edit_quote_id) {
          $editing_quote = $q;
          break;
        }
      }
      if ($editing_quote !== null && $edit_quote_post !== null) {
        $editing_quote = array_merge($editing_quote, $edit_quote_post);
      }
    }

    $show_add_quote_form = $add_quote_post !== null
      || ($add_quote_id > 0 && $add_quote_id === $selected_rfq_id);
  }
}

if ($rfq_text_id > 0) {
  $txt = $pdo->prepare(
    "SELECT r.id, r.request_category, r.request_title, r.machine_size, r.laser_watts, r.tube_type, r.part_category, r.part_specs, r.quantity,
            r.required_features, r.additional_notes, r.request_status, r.created_at,
            r.contact_name, r.company_name, r.contact_email, r.contact_phone,
            u.username AS requested_by_username
     FROM rfq_requests r
     LEFT JOIN users u ON u.id = r.requested_by
     WHERE r.id = ? LIMIT 1"
  );
  $txt->execute([$rfq_text_id]);
  $rfq_for_email = $txt->fetch();
  if ($rfq_for_email) {
    $rfq_email_text = build_rfq_email_text($rfq_for_email);
  } else {
    $errors[] = 'RFQ not found for email text export.';
  }
}

render_header('RFQ Tracker');
?>

<style>
  .status-select option {
    font-weight: 600;
  }
</style>

<div class="card laser-rfq-hero page-header">
  <div class="laser-rfq-hero-beams" aria-hidden="true">
    <span class="laser-rfq-beam laser-rfq-beam-1"></span>
    <span class="laser-rfq-beam laser-rfq-beam-2"></span>
    <span class="laser-rfq-beam laser-rfq-beam-3"></span>
  </div>
  <div class="laser-rfq-hero-glow" aria-hidden="true"></div>
  <div class="page-header-body laser-rfq-hero-body">
    <span class="laser-rfq-hero-tag">🔴 Global Laser Sourcing Command Center</span>
    <h1>RFQ Quote Tracking <span class="laser-rfq-hero-count">(<?= (int)$hero_total_rfqs ?>)</span></h1>
    <p class="muted">Source precision laser cutting machinery and parts from global factories — compare supplier bids, track lead times, and drive the best deals to the table.</p>
    <ul class="laser-rfq-hero-pills" aria-label="Sourcing highlights">
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">🏭</span> Factory-direct sourcing</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">⚡</span> Live quote pipeline</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">🌏</span> Global supplier network</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">🔧</span> Machines &amp; precision parts</li>
    </ul>
    <div class="laser-rfq-hero-stats" aria-label="RFQ sourcing summary">
      <div class="laser-rfq-hero-stat">
        <strong><?= (int)$hero_total_rfqs ?></strong>
        <span>Total RFQs</span>
      </div>
      <div class="laser-rfq-hero-stat">
        <strong><?= (int)$hero_active_rfqs ?></strong>
        <span>Active Pipeline</span>
      </div>
      <div class="laser-rfq-hero-stat">
        <strong><?= (int)$hero_sourcing_rfqs ?></strong>
        <span>In Sourcing</span>
      </div>
      <div class="laser-rfq-hero-stat">
        <strong><?= (int)$hero_quotes_total ?></strong>
        <span>Quotes Logged</span>
      </div>
    </div>
  </div>
  <div class="laser-rfq-hero-actions">
    <a class="btn primary" href="rfq_form.php">+ New Machine RFQ</a>
    <a class="btn" href="rfq_form.php?request_category=parts">+ New Parts RFQ</a>
  </div>
</div>

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

<div class="card">
  <form method="get" class="row" style="align-items:flex-end;">
    <div style="flex:1 1 300px;">
      <label>Search RFQs</label>
      <input type="text" name="q" value="<?= h($search) ?>"
             placeholder="Search title, machine specs, part specs, or features..." />
    </div>
    <div style="width:220px;">
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
      <a class="btn" href="rfq_tracker.php">Clear</a>
    </div>
  </form>
</div>

<?php if ($rfq_email_text !== ''): ?>
  <div class="card">
    <h2 style="margin-top:0;">RFQ Email Text</h2>
    <p class="muted" style="margin-top:0;">
      Copy this text and paste it into your email.
    </p>
    <label id="rfq_email_text_label" for="rfq_email_text">Email content</label>
    <textarea id="rfq_email_text" rows="16" readonly aria-labelledby="rfq_email_text_label"><?= h($rfq_email_text) ?></textarea>
    <div class="row" style="margin-top:8px;">
      <button type="button" class="btn" onclick="copyRfqEmailText()">Copy Text</button>
      <span id="rfq_copy_status" class="muted" role="status" aria-live="polite"></span>
    </div>
  </div>
  <script>
    function copyRfqEmailText() {
      const text = document.getElementById('rfq_email_text').value;
      const status = document.getElementById('rfq_copy_status');
      const copyFailedMessage = 'Failed to copy to clipboard. Your browser may not support this feature. Please select the text and copy manually.';
      const copySuccessDurationMs = 3000;
      const copyErrorDurationMs = 5000;
      const canUseClipboard = navigator.clipboard && typeof navigator.clipboard.writeText === 'function';
      if (!canUseClipboard) {
        status.textContent = copyFailedMessage;
        setTimeout(function() {
          status.textContent = '';
        }, copyErrorDurationMs);
        return;
      }
      navigator.clipboard.writeText(text).then(function() {
        status.textContent = 'Copied to clipboard.';
        setTimeout(function() {
          status.textContent = '';
        }, copySuccessDurationMs);
      }, function() {
        status.textContent = copyFailedMessage;
        setTimeout(function() {
          status.textContent = '';
        }, copyErrorDurationMs);
      });
    }
  </script>
<?php endif; ?>

<?php if (!$selected_rfq): ?>
  <div class="card">
    <div class="table-wrap" style="overflow-x:auto;">
      <table class="table-auto" style="min-width:720px;">
        <thead>
          <tr>
            <th>#</th>
            <th>RFQ</th>
            <th>Status</th>
            <th class="col-actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$rfqs): ?>
            <tr><td colspan="4" class="muted">No RFQ requests found.</td></tr>
          <?php endif; ?>
          <?php foreach ($rfqs as $r): ?>
            <tr>
              <td class="muted"><?= (int)$r['id'] ?></td>
              <td>
                <strong><?= h($r['request_title']) ?></strong><br>
                <span class="muted">
                  <?= ($r['request_category'] ?? 'machine') === 'parts' ? 'Parts' : 'Machine' ?> · Qty: <?= (int)$r['quantity'] ?> · Quotes: <?= (int)$r['quote_count'] ?> · Urgency:
                  <?php
                    $urgency_val = (string)($r['urgency'] ?? 'normal');
                    $ub = $urgency_badges[$urgency_val] ?? [ucfirst($urgency_val), '#e2e8f0', '#334155'];
                  ?>
                  <span style="display:inline-block; padding:2px 8px; border-radius:12px; font-size:0.72em; font-weight:600; letter-spacing:0.04em; background:<?= h($ub[1]) ?>; color:<?= h($ub[2]) ?>;"><?= h($ub[0]) ?></span>
                </span>
              </td>
              <td>
                <form method="post" class="row" style="gap:6px; align-items:center;">
                  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['rfq_tracker_csrf']) ?>" />
                  <input type="hidden" name="action" value="update_request_status" />
                  <input type="hidden" name="rfq_id" value="<?= (int)$r['id'] ?>" />
                  <select name="request_status" class="status-select" style="min-width:150px; <?= h(get_status_select_style($request_status_styles, (string)$r['request_status'])) ?>">
                    <?php foreach ($request_statuses as $k => $label): ?>
                      <?php [$option_background, $option_color] = $request_status_styles[$k] ?? ['#f3f4f6', '#374151']; ?>
                      <option value="<?= h($k) ?>" data-bg="<?= h($option_background) ?>" data-color="<?= h($option_color) ?>" style="background:<?= h($option_background) ?>; color:<?= h($option_color) ?>;" <?= $r['request_status'] === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn">Save</button>
                </form>
              </td>
              <td class="col-actions">
                <a class="btn" href="rfq_details.php?id=<?= (int)$r['id'] ?>">View</a>
                <a class="btn" href="rfq_tracker.php?rfq_id=<?= (int)$r['id'] ?>">Quotes</a>
                <a class="btn" href="rfq_tracker.php?rfq_text_id=<?= (int)$r['id'] ?>">Email Text</a>
                <a class="btn" href="rfq_form.php?edit_rfq_id=<?= (int)$r['id'] ?>">Edit</a>
                <form method="post" style="display:inline;"
                      onsubmit="return confirm('Delete this RFQ and all its quotes? This cannot be undone.');">
                  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['rfq_tracker_csrf']) ?>" />
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

<?php if ($selected_rfq): ?>
  <div class="card">
    <div class="page-header" style="margin-bottom:14px;">
      <div class="page-header-body">
        <h2>Quotes — <span class="muted" style="font-weight:400;">RFQ #<?= (int)$selected_rfq['id'] ?></span></h2>
        <p class="muted"><?= h($selected_rfq['request_title']) ?></p>
      </div>
      <div class="row" style="flex-shrink:0;">
        <a class="btn" href="rfq_tracker.php<?= $search !== '' || $status_filter !== '' ? '?' . http_build_query(array_filter(['q' => $search, 'status' => $status_filter])) : '' ?>">← All RFQs</a>
        <a class="btn" href="rfq_tracker.php?rfq_text_id=<?= (int)$selected_rfq['id'] ?>">Email Text</a>
        <a class="btn" href="rfq_form.php?edit_rfq_id=<?= (int)$selected_rfq['id'] ?>">Edit RFQ</a>
        <a class="btn" href="rfq_details.php?id=<?= (int)$selected_rfq['id'] ?>">View Details</a>
        <a class="btn" href="order_tracker.php?rfq_id=<?= (int)$selected_rfq['id'] ?>">Order Tracker</a>
      </div>
    </div>

    <?php if ($editing_quote): ?>
      <h3 style="margin-top:0; margin-bottom:12px;">Edit Quote</h3>
      <form method="post" class="form-grid" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['rfq_tracker_csrf']) ?>" />
        <input type="hidden" name="action" value="edit_quote" />
        <input type="hidden" name="rfq_id" value="<?= (int)$selected_rfq['id'] ?>" />
        <input type="hidden" name="quote_id" value="<?= (int)$editing_quote['id'] ?>" />

        <div>
          <label>Supplier Name <span style="color:var(--d)">*</span></label>
          <input type="text" name="supplier_name" maxlength="255" required
                 value="<?= h($editing_quote['supplier_name']) ?>" />
        </div>
        <div>
          <label>Model Name</label>
          <input type="text" name="model_name" maxlength="255"
                 value="<?= h((string)($editing_quote['model_name'] ?? '')) ?>" />
        </div>
        <div>
          <label>SKU</label>
          <input type="text" name="sku" maxlength="100"
                 value="<?= h((string)($editing_quote['sku'] ?? '')) ?>" />
        </div>
        <div>
          <label>MSRP</label>
          <input type="number" name="msrp" min="0" step="0.01"
                 value="<?= $editing_quote['msrp'] !== null ? h((string)$editing_quote['msrp']) : '' ?>" />
        </div>
        <div>
          <label>MAP (Minimum Advertised Price)</label>
          <input type="number" name="map_price" min="0" step="0.01"
                 value="<?= $editing_quote['map_price'] !== null ? h((string)$editing_quote['map_price']) : '' ?>" />
        </div>
        <div>
          <label>MOQ 20</label>
          <input type="number" name="moq_20_price" min="0" step="0.01"
                 value="<?= $editing_quote['moq_20_price'] !== null ? h((string)$editing_quote['moq_20_price']) : '' ?>" />
        </div>
        <div>
          <label>MOQ 10</label>
          <input type="number" name="moq_10_price" min="0" step="0.01"
                 value="<?= $editing_quote['moq_10_price'] !== null ? h((string)$editing_quote['moq_10_price']) : '' ?>" />
        </div>
        <div>
          <label>Drop Ship</label>
          <input type="number" name="drop_ship_price" min="0" step="0.01"
                 value="<?= $editing_quote['drop_ship_price'] !== null ? h((string)$editing_quote['drop_ship_price']) : '' ?>" />
        </div>
        <div>
          <label>Quote Amount <span style="color:var(--d)">*</span></label>
          <input type="number" name="quote_amount" min="0" step="0.01" required
                 value="<?= h((string)$editing_quote['quote_amount']) ?>" />
        </div>
        <div>
          <label>Currency <span style="color:var(--d)">*</span></label>
          <input type="text" name="currency" maxlength="3" required
                 value="<?= h($editing_quote['currency']) ?>" />
        </div>
        <div>
          <label>Lead Time (days)</label>
          <input type="number" name="lead_time_days" min="0" max="<?= MAX_LEAD_TIME_DAYS ?>"
                 value="<?= $editing_quote['lead_time_days'] !== null ? h((string)$editing_quote['lead_time_days']) : '' ?>" />
        </div>
        <div>
          <label>Shipping Cost</label>
          <input type="number" name="shipping_cost" min="0" step="0.01"
                 value="<?= $editing_quote['shipping_cost'] !== null ? h((string)$editing_quote['shipping_cost']) : '' ?>" />
        </div>
        <div>
          <label>Shipping Method</label>
          <input type="text" name="shipping_method" maxlength="100"
                 value="<?= h((string)($editing_quote['shipping_method'] ?? '')) ?>" />
        </div>
        <div>
          <label>Shipping Origin</label>
          <input type="text" name="shipping_origin" maxlength="255"
                 value="<?= h((string)($editing_quote['shipping_origin'] ?? '')) ?>" />
        </div>
        <div>
          <label>Quote Status</label>
          <select name="quote_status" class="status-select" style="<?= h(get_status_select_style($quote_status_styles, (string)$editing_quote['quote_status'])) ?>">
            <?php foreach ($quote_statuses as $k => $label): ?>
              <?php [$option_background, $option_color] = $quote_status_styles[$k] ?? ['#f3f4f6', '#374151']; ?>
              <option value="<?= h($k) ?>" data-bg="<?= h($option_background) ?>" data-color="<?= h($option_color) ?>" style="background:<?= h($option_background) ?>; color:<?= h($option_color) ?>;" <?= $editing_quote['quote_status'] === $k ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Quote Received On</label>
          <input type="date" name="received_on"
                 value="<?= h((string)($editing_quote['received_on'] ?? '')) ?>" />
        </div>
        <div class="full">
          <label>Notes</label>
          <textarea name="notes" rows="4" maxlength="5000"><?= h((string)($editing_quote['notes'] ?? '')) ?></textarea>
        </div>
        <div>
          <label>Replace Quote File</label>
          <input type="file" name="quote_file" />
          <div class="muted" style="font-size:12px; margin-top:4px;">
            <?php if (!empty($editing_quote['quote_file_original_name'])): ?>
              Current: <?= h($editing_quote['quote_file_original_name']) ?>.
              Upload a new file to replace it, or check below to remove it.
            <?php else: ?>
              Optional, up to 25 MB.
            <?php endif; ?>
          </div>
          <?php if (!empty($editing_quote['quote_file_original_name'])): ?>
            <label style="display:flex; align-items:center; gap:6px; margin-top:6px; font-weight:normal;">
              <input type="checkbox" name="remove_file" value="1" />
              Remove current attachment
            </label>
          <?php endif; ?>
        </div>
        <div class="full row" style="margin-top:8px;">
          <button type="submit" class="btn primary">Save Changes</button>
          <a class="btn" href="rfq_tracker.php?rfq_id=<?= (int)$selected_rfq['id'] ?>">Cancel</a>
        </div>
      </form>
    <?php elseif ($show_add_quote_form): ?>
      <h3 style="margin-top:0; margin-bottom:12px;">Add Quote</h3>
      <form method="post" class="form-grid" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['rfq_tracker_csrf']) ?>" />
        <input type="hidden" name="action" value="add_quote" />
        <input type="hidden" name="rfq_id" value="<?= (int)$selected_rfq['id'] ?>" />

        <div>
          <label>Supplier Name <span style="color:var(--d)">*</span></label>
          <input type="text" name="supplier_name" maxlength="255" required placeholder="e.g. ABC Laser Systems"
                 value="<?= h($add_quote_post['supplier_name'] ?? '') ?>" />
        </div>
        <div>
          <label>Model Name</label>
          <input type="text" name="model_name" maxlength="255" placeholder="e.g. GL-1325 Pro"
                 value="<?= h($add_quote_post['model_name'] ?? '') ?>" />
        </div>
        <div>
          <label>SKU</label>
          <input type="text" name="sku" maxlength="100" placeholder="e.g. GL-1325-PRO"
                 value="<?= h($add_quote_post['sku'] ?? '') ?>" />
        </div>
        <div>
          <label>MSRP</label>
          <input type="number" name="msrp" min="0" step="0.01" placeholder="e.g. 12999.00"
                 value="<?= h($add_quote_post['msrp'] ?? '') ?>" />
        </div>
        <div>
          <label>MAP (Minimum Advertised Price)</label>
          <input type="number" name="map_price" min="0" step="0.01" placeholder="e.g. 11999.00"
                 value="<?= h($add_quote_post['map_price'] ?? '') ?>" />
        </div>
        <div>
          <label>MOQ 20</label>
          <input type="number" name="moq_20_price" min="0" step="0.01" placeholder="e.g. 9500.00"
                 value="<?= h($add_quote_post['moq_20_price'] ?? '') ?>" />
        </div>
        <div>
          <label>MOQ 10</label>
          <input type="number" name="moq_10_price" min="0" step="0.01" placeholder="e.g. 9800.00"
                 value="<?= h($add_quote_post['moq_10_price'] ?? '') ?>" />
        </div>
        <div>
          <label>Drop Ship</label>
          <input type="number" name="drop_ship_price" min="0" step="0.01" placeholder="e.g. 10200.00"
                 value="<?= h($add_quote_post['drop_ship_price'] ?? '') ?>" />
        </div>
        <div>
          <label>Quote Amount <span style="color:var(--d)">*</span></label>
          <input type="number" name="quote_amount" min="0" step="0.01" required placeholder="e.g. 10800.00"
                 value="<?= h($add_quote_post['quote_amount'] ?? '') ?>" />
        </div>
        <div>
          <label>Currency <span style="color:var(--d)">*</span></label>
          <input type="text" name="currency" maxlength="3" required
                 value="<?= h($add_quote_post['currency'] ?? 'USD') ?>" />
        </div>
        <div>
          <label>Lead Time (days)</label>
          <input type="number" name="lead_time_days" min="0" max="<?= MAX_LEAD_TIME_DAYS ?>" placeholder="e.g. 35"
                 value="<?= h($add_quote_post['lead_time_days'] ?? '') ?>" />
        </div>
        <div>
          <label>Shipping Cost</label>
          <input type="number" name="shipping_cost" min="0" step="0.01" placeholder="e.g. 1800.00"
                 value="<?= h($add_quote_post['shipping_cost'] ?? '') ?>" />
        </div>
        <div>
          <label>Shipping Method</label>
          <input type="text" name="shipping_method" maxlength="100" placeholder="e.g. DDP / FOB / EXW"
                 value="<?= h($add_quote_post['shipping_method'] ?? '') ?>" />
        </div>
        <div>
          <label>Shipping Origin</label>
          <input type="text" name="shipping_origin" maxlength="255" placeholder="e.g. Qingdao, China"
                 value="<?= h($add_quote_post['shipping_origin'] ?? '') ?>" />
        </div>
        <div>
          <label>Quote Status</label>
          <select name="quote_status" class="status-select" style="<?= h(get_status_select_style($quote_status_styles, (string)($add_quote_post['quote_status'] ?? 'received'))) ?>">
            <?php foreach ($quote_statuses as $k => $label): ?>
              <?php [$option_background, $option_color] = $quote_status_styles[$k] ?? ['#f3f4f6', '#374151']; ?>
              <option value="<?= h($k) ?>" data-bg="<?= h($option_background) ?>" data-color="<?= h($option_color) ?>" style="background:<?= h($option_background) ?>; color:<?= h($option_color) ?>;" <?= ($add_quote_post['quote_status'] ?? 'received') === $k ? 'selected' : '' ?>><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Quote Received On</label>
          <input type="date" name="received_on"
                 value="<?= h($add_quote_post['received_on'] ?? '') ?>" />
        </div>
        <div class="full">
          <label>Notes</label>
          <textarea name="notes" rows="4" maxlength="5000"
                    placeholder="Include quote terms, included accessories, warranty, or negotiation details."><?= h($add_quote_post['notes'] ?? '') ?></textarea>
        </div>
        <div>
          <label>Quote File</label>
          <input type="file" name="quote_file" />
          <div class="muted" style="font-size:12px; margin-top:4px;">Optional, up to 25 MB.</div>
        </div>
        <div class="full row" style="margin-top:8px;">
          <button type="submit" class="btn primary">Add Quote</button>
          <a class="btn" href="rfq_tracker.php?rfq_id=<?= (int)$selected_rfq['id'] ?>">Cancel</a>
        </div>
      </form>
    <?php else: ?>
      <div class="row" style="margin-bottom:14px;">
        <a class="btn primary" href="rfq_tracker.php?rfq_id=<?= (int)$selected_rfq['id'] ?>&add_quote_id=<?= (int)$selected_rfq['id'] ?>">Add Quote</a>
      </div>
    <?php endif; ?>

    <div class="table-wrap" style="overflow-x:auto; margin-top:14px;">
      <table class="table-auto" style="min-width:760px;">
        <thead>
          <tr>
            <th>Supplier</th>
            <th>Quote</th>
            <th>Status</th>
            <th>Attachment</th>
            <th>Added By</th>
            <th class="col-actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$quotes): ?>
            <tr><td colspan="6" class="muted">No quotes added yet for this RFQ.</td></tr>
          <?php endif; ?>
          <?php foreach ($quotes as $q): ?>
            <tr>
              <td>
                <div><?= h($q['supplier_name']) ?></div>
                <?php if (!empty($q['model_name'])): ?>
                  <div class="muted" style="font-size:12px;">Model: <?= h((string)$q['model_name']) ?></div>
                <?php endif; ?>
                <?php if (!empty($q['sku'])): ?>
                  <div class="muted" style="font-size:12px;">SKU: <?= h((string)$q['sku']) ?></div>
                <?php endif; ?>
                <?php if ($q['msrp'] !== null): ?>
                  <div class="muted" style="font-size:12px;">MSRP: <?= h((string)$q['currency']) ?> <?= h(number_format((float)$q['msrp'], 2)) ?></div>
                <?php endif; ?>
              </td>
              <td>
                <?= h($q['currency']) ?> <?= h(number_format((float)$q['quote_amount'], 2)) ?>
              </td>
              <td>
                <form method="post" class="row" style="gap:4px; align-items:center; margin-top:4px;">
                  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['rfq_tracker_csrf']) ?>" />
                  <input type="hidden" name="action" value="update_quote_status" />
                  <input type="hidden" name="rfq_id" value="<?= (int)$selected_rfq['id'] ?>" />
                  <input type="hidden" name="quote_id" value="<?= (int)$q['id'] ?>" />
                  <select name="quote_status" class="status-select" style="min-width:120px; <?= h(get_status_select_style($quote_status_styles, (string)$q['quote_status'])) ?>">
                    <?php foreach ($quote_statuses as $k => $ql): ?>
                      <?php [$option_background, $option_color] = $quote_status_styles[$k] ?? ['#f3f4f6', '#374151']; ?>
                      <option value="<?= h($k) ?>" data-bg="<?= h($option_background) ?>" data-color="<?= h($option_color) ?>" style="background:<?= h($option_background) ?>; color:<?= h($option_color) ?>;" <?= $q['quote_status'] === $k ? 'selected' : '' ?>><?= h($ql) ?></option>
                    <?php endforeach; ?>
                  </select>
                  <button type="submit" class="btn">Save</button>
                </form>
              </td>
              <td>
                <?php
                  $file_name = (string)($q['quote_file_stored_name'] ?? '');
                  $file_url = '';
                  $preview_url = '';
                  if ($file_name !== '' && is_safe_stored_upload_name($file_name)) {
                    $file_url = 'rfq_quote_file.php?quote_id=' . (int)$q['id'];
                    $preview_url = $file_url . '&inline=1';
                  }
                ?>
                <?php if ($file_url !== ''): ?>
                  <?= render_attachment_preview(
                    $file_url,
                    (string)($q['quote_file_original_name'] ?? 'Attachment'),
                    (string)($q['quote_file_mime_type'] ?? ''),
                    $preview_url
                  ) ?>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <td class="muted"><?= h($q['created_by_username'] ?? 'Unknown') ?></td>
              <td class="col-actions">
                <a class="btn" href="rfq_quote_details.php?rfq_id=<?= (int)$selected_rfq['id'] ?>&quote_id=<?= (int)$q['id'] ?>">View</a>
                <a class="btn" href="rfq_tracker.php?rfq_id=<?= (int)$selected_rfq['id'] ?>&edit_quote_id=<?= (int)$q['id'] ?>">Edit</a>
                <?php if ((string)$q['quote_status'] === 'accepted'): ?>
                  <a class="btn primary" href="order_form.php?rfq_id=<?= (int)$selected_rfq['id'] ?>&quote_id=<?= (int)$q['id'] ?>">Convert to Order</a>
                <?php endif; ?>
                <?php if (isset($orders_by_quote_id[(int)$q['id']])): ?>
                  <a class="btn" href="order_form.php?order_id=<?= (int)$orders_by_quote_id[(int)$q['id']]['id'] ?>">
                    <?= h((string)($orders_by_quote_id[(int)$q['id']]['po_number'] ?: 'PO #' . (int)$orders_by_quote_id[(int)$q['id']]['id'])) ?>
                  </a>
                <?php endif; ?>
                <form method="post" style="display:inline;"
                      onsubmit="return confirm('Delete this quote? This cannot be undone.');">
                  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['rfq_tracker_csrf']) ?>" />
                  <input type="hidden" name="action" value="delete_quote" />
                  <input type="hidden" name="rfq_id" value="<?= (int)$selected_rfq['id'] ?>" />
                  <input type="hidden" name="quote_id" value="<?= (int)$q['id'] ?>" />
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

<script>
  (function() {
    function applyStatusSelectColors(select) {
      if (!select || !select.options || select.selectedIndex < 0) return;
      const option = select.options[select.selectedIndex];
      select.style.background = option.dataset.bg || '#f3f4f6';
      select.style.color = option.dataset.color || '#374151';
      select.style.borderColor = option.dataset.bg || '#f3f4f6';
      select.style.fontWeight = '600';
    }

    document.querySelectorAll('.status-select').forEach(function(select) {
      applyStatusSelectColors(select);
      select.addEventListener('change', function() {
        applyStatusSelectColors(select);
      });
    });
  })();
</script>

<?php render_footer(); ?>
