<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_rfq_access();

const MAX_RFQ_QUANTITY = 1000;
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
$quote_statuses = [
  'received' => 'Received',
  'under_review' => 'Under Review',
  'negotiating' => 'Negotiating',
  'accepted' => 'Accepted',
  'rejected' => 'Rejected',
];

$errors = [];
$success = '';
$selected_rfq_id = 0;
$edit_quote_id = 0;
$edit_rfq_id = 0;

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

function build_rfq_email_text(array $rfq): string {
  $lines = [
    'RFQ #' . (int)$rfq['id'] . ': ' . trim((string)$rfq['request_title']),
    '',
    'Machine Size: ' . trim((string)$rfq['machine_size']),
    'Laser Watts: ' . trim((string)$rfq['laser_watts']),
    'Tube Type: ' . trim((string)$rfq['tube_type']),
    'Quantity: ' . (int)$rfq['quantity'],
    'Status: ' . trim((string)$rfq['request_status']),
    'Requested By: ' . trim((string)($rfq['requested_by_username'] ?? 'Unknown')),
    'Created: ' . trim((string)$rfq['created_at']),
    '',
    'Required Features:',
    trim((string)$rfq['required_features']),
  ];

  $additional_notes = trim((string)($rfq['additional_notes'] ?? ''));
  if ($additional_notes !== '') {
    $lines[] = '';
    $lines[] = 'Additional Notes:';
    $lines[] = $additional_notes;
  }

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
    } elseif ($action === 'add_quote') {
      $rfq_id = (int)($_POST['rfq_id'] ?? 0);
      $supplier_name = trim((string)($_POST['supplier_name'] ?? ''));
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
        } else {
          $today = new DateTime('today');
          if ($dt > $today) {
            $errors[] = 'Received date cannot be in the future.';
          }
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
              (rfq_request_id, supplier_name, quote_amount, currency, lead_time_days, shipping_cost,
               shipping_origin, shipping_method, quote_status, received_on, notes, created_by,
               quote_file_original_name, quote_file_stored_name, quote_file_mime_type, quote_file_size_bytes)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
          );
          $ins->execute([
            $rfq_id,
            $supplier_name,
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
    } elseif ($action === 'edit_quote') {
      $quote_id = (int)($_POST['quote_id'] ?? 0);
      $rfq_id = (int)($_POST['rfq_id'] ?? 0);
      $supplier_name = trim((string)($_POST['supplier_name'] ?? ''));
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
        } else {
          $today = new DateTime('today');
          if ($dt > $today) {
            $errors[] = 'Received date cannot be in the future.';
          }
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
                supplier_name = ?, quote_amount = ?, currency = ?, lead_time_days = ?,
                shipping_cost = ?, shipping_origin = ?, shipping_method = ?, quote_status = ?,
                received_on = ?, notes = ?,
                quote_file_original_name = ?, quote_file_stored_name = ?,
                quote_file_mime_type = ?, quote_file_size_bytes = ?
               WHERE id = ? AND rfq_request_id = ?"
            );
            $upd->execute([
              $supplier_name,
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

            $success = 'Quote updated successfully.';
            $selected_rfq_id = $rfq_id;
          } else {
            $selected_rfq_id = $rfq_id;
            $edit_quote_id = $quote_id;
          }
        }
      } else {
        $selected_rfq_id = $rfq_id;
        $edit_quote_id = $quote_id;
      }
    } elseif ($action === 'edit_rfq') {
      $rfq_id = (int)($_POST['rfq_id'] ?? 0);
      $request_title = trim((string)($_POST['request_title'] ?? ''));
      $machine_size = trim((string)($_POST['machine_size'] ?? ''));
      $laser_watts = trim((string)($_POST['laser_watts'] ?? ''));
      $tube_type = trim((string)($_POST['tube_type'] ?? ''));
      $quantity_raw = trim((string)($_POST['quantity'] ?? ''));
      $required_features = trim((string)($_POST['required_features'] ?? ''));
      $additional_notes = trim((string)($_POST['additional_notes'] ?? ''));

      if ($rfq_id <= 0) $errors[] = 'Invalid RFQ request.';
      if ($request_title === '') $errors[] = 'Request title is required.';
      if ($machine_size === '') $errors[] = 'Machine size is required.';
      if ($laser_watts === '') $errors[] = 'Laser watts is required.';
      if ($tube_type === '') $errors[] = 'Tube type is required.';
      if ($required_features === '') $errors[] = 'Required features are required.';
      if (!ctype_digit($quantity_raw) || (int)$quantity_raw < 1 || (int)$quantity_raw > MAX_RFQ_QUANTITY) {
        $errors[] = 'Quantity must be a whole number between 1 and ' . MAX_RFQ_QUANTITY . '.';
      }
      if (strlen($required_features) > 5000) {
        $errors[] = 'Required features must be 5000 characters or fewer.';
      }
      if (strlen($additional_notes) > 5000) {
        $errors[] = 'Additional notes must be 5000 characters or fewer.';
      }

      if (!$errors) {
        $upd = $pdo->prepare(
          "UPDATE rfq_requests SET
            request_title = ?, machine_size = ?, laser_watts = ?, tube_type = ?,
            quantity = ?, required_features = ?, additional_notes = ?
           WHERE id = ?"
        );
        $upd->execute([
          $request_title,
          $machine_size,
          $laser_watts,
          $tube_type,
          (int)$quantity_raw,
          $required_features,
          $additional_notes === '' ? null : $additional_notes,
          $rfq_id,
        ]);
        if ($upd->rowCount() > 0) {
          $success = 'RFQ request updated successfully.';
        } else {
          $errors[] = 'RFQ not found or no changes made.';
          $edit_rfq_id = $rfq_id;
        }
      } else {
        $edit_rfq_id = $rfq_id;
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
if ($edit_rfq_id <= 0) {
  $edit_rfq_id = max(0, (int)($_GET['edit_rfq_id'] ?? 0));
}
$rfq_text_id = max(0, (int)($_GET['rfq_text_id'] ?? 0));

$where_parts = [];
$params = [];
if ($search !== '') {
  $where_parts[] = "(r.request_title LIKE :q OR r.machine_size LIKE :q OR r.laser_watts LIKE :q OR r.tube_type LIKE :q OR r.required_features LIKE :q)";
  $params[':q'] = '%' . $search . '%';
}
if ($status_filter !== '' && isset($request_statuses[$status_filter])) {
  $where_parts[] = "r.request_status = :status";
  $params[':status'] = $status_filter;
}
$where_sql = $where_parts ? ('WHERE ' . implode(' AND ', $where_parts)) : '';

$sql = "
  SELECT
    r.id, r.request_title, r.machine_size, r.laser_watts, r.tube_type, r.quantity,
    r.required_features, r.additional_notes, r.request_status, r.created_at, r.updated_at,
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

$selected_rfq = null;
$quotes = [];
$editing_quote = null;
$editing_rfq = null;
$rfq_email_text = '';
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

    if ($edit_quote_id > 0) {
      foreach ($quotes as $q) {
        if ((int)$q['id'] === $edit_quote_id) {
          $editing_quote = $q;
          break;
        }
      }
    }
  }
}

if ($edit_rfq_id > 0) {
  $er = $pdo->prepare(
    "SELECT id, request_title, machine_size, laser_watts, tube_type, quantity, required_features, additional_notes
     FROM rfq_requests WHERE id = ? LIMIT 1"
  );
  $er->execute([$edit_rfq_id]);
  $editing_rfq = $er->fetch() ?: null;
}

if ($rfq_text_id > 0) {
  $txt = $pdo->prepare(
    "SELECT r.id, r.request_title, r.machine_size, r.laser_watts, r.tube_type, r.quantity,
            r.required_features, r.additional_notes, r.request_status, r.created_at,
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

<div class="card">
  <h1 style="margin-top:0; margin-bottom:4px;">RFQ Quote Tracking</h1>
  <p class="muted" style="margin:0;">
    Track supplier quotes, lead times, and shipping costs for CO2 laser cutter purchases.
  </p>
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

<?php if ($editing_rfq): ?>
<div class="card">
  <h2 style="margin-top:0; margin-bottom:12px;">Edit RFQ #<?= (int)$editing_rfq['id'] ?></h2>
  <form method="post" class="form-grid" novalidate>
    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['rfq_tracker_csrf']) ?>" />
    <input type="hidden" name="action" value="edit_rfq" />
    <input type="hidden" name="rfq_id" value="<?= (int)$editing_rfq['id'] ?>" />

    <div class="full">
      <label>RFQ Title <span style="color:var(--d)">*</span></label>
      <input type="text" name="request_title" maxlength="255" required
             value="<?= h($editing_rfq['request_title']) ?>" />
    </div>
    <div>
      <label>Machine Size <span style="color:var(--d)">*</span></label>
      <input type="text" name="machine_size" maxlength="100" required
             value="<?= h($editing_rfq['machine_size']) ?>" />
    </div>
    <div>
      <label>Laser Watts <span style="color:var(--d)">*</span></label>
      <input type="text" name="laser_watts" maxlength="50" required
             value="<?= h($editing_rfq['laser_watts']) ?>" />
    </div>
    <div>
      <label>Tube Type <span style="color:var(--d)">*</span></label>
      <input type="text" name="tube_type" maxlength="100" required
             value="<?= h($editing_rfq['tube_type']) ?>" />
    </div>
    <div>
      <label>Quantity <span style="color:var(--d)">*</span></label>
      <input type="number" name="quantity" min="1" max="<?= MAX_RFQ_QUANTITY ?>" required
             value="<?= h((string)$editing_rfq['quantity']) ?>" />
    </div>
    <div class="full">
      <label>Required Features <span style="color:var(--d)">*</span></label>
      <textarea name="required_features" rows="5" maxlength="5000" required><?= h($editing_rfq['required_features']) ?></textarea>
    </div>
    <div class="full">
      <label>Additional Notes</label>
      <textarea name="additional_notes" rows="4" maxlength="5000"><?= h((string)($editing_rfq['additional_notes'] ?? '')) ?></textarea>
    </div>
    <div class="full row" style="margin-top:8px;">
      <button type="submit" class="btn primary">Save Changes</button>
      <a class="btn" href="rfq_tracker.php">Cancel</a>
    </div>
  </form>
</div>
<?php endif; ?>

<div class="card">
  <form method="get" class="row" style="align-items:flex-end;">
    <div style="flex:1 1 300px;">
      <label>Search RFQs</label>
      <input type="text" name="q" value="<?= h($search) ?>"
             placeholder="Search title, size, watts, tube type, features..." />
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
      <a class="btn" href="rfq_form.php">New RFQ</a>
    </div>
  </form>
</div>

<?php if ($rfq_email_text !== ''): ?>
  <div class="card">
    <h2 style="margin-top:0;">RFQ Email Text</h2>
    <p class="muted" style="margin-top:0;">
      Copy this text and paste it into your email.
    </p>
    <textarea id="rfq_email_text" rows="16" readonly><?= h($rfq_email_text) ?></textarea>
    <div class="row" style="margin-top:8px;">
      <button type="button" class="btn" onclick="navigator.clipboard.writeText(document.getElementById('rfq_email_text').value)">Copy Text</button>
    </div>
  </div>
<?php endif; ?>

<div class="card">
  <div class="table-wrap" style="overflow-x:auto;">
    <table class="table-auto" style="min-width:1100px;">
      <thead>
        <tr>
          <th>#</th>
          <th>RFQ</th>
          <th>Specs</th>
          <th>Features</th>
          <th>Quotes</th>
          <th>Status</th>
          <th>Requested By</th>
          <th>Created</th>
          <th class="col-actions">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (!$rfqs): ?>
          <tr><td colspan="9" class="muted">No RFQ requests found.</td></tr>
        <?php endif; ?>
        <?php foreach ($rfqs as $r): ?>
          <tr>
            <td class="muted"><?= (int)$r['id'] ?></td>
            <td>
              <strong><?= h($r['request_title']) ?></strong><br>
              <span class="muted">Qty: <?= (int)$r['quantity'] ?></span>
            </td>
            <td>
              Size: <?= h($r['machine_size']) ?><br>
              Watts: <?= h($r['laser_watts']) ?><br>
              Tube: <?= h($r['tube_type']) ?>
            </td>
            <td style="max-width:260px; white-space:normal;">
              <?= nl2br(h(mb_strimwidth((string)$r['required_features'], 0, 180, '…'))) ?>
            </td>
            <td>
              <span class="badge"><?= (int)$r['quote_count'] ?> quote(s)</span><br>
              <span class="muted">
                Best quote:
                <?php if ($r['lowest_quote_amount'] !== null): ?>
                  <?= h(number_format((float)$r['lowest_quote_amount'], 2)) ?>
                <?php else: ?>
                  —
                <?php endif; ?>
                <br>
                Best lead: <?= $r['best_lead_time_days'] !== null ? h((string)$r['best_lead_time_days']) . ' days' : '—' ?><br>
                Lowest ship:
                <?php if ($r['lowest_shipping_cost'] !== null): ?>
                  <?= h(number_format((float)$r['lowest_shipping_cost'], 2)) ?>
                <?php else: ?>
                  —
                <?php endif; ?>
                <br>
                Currencies in quotes: <?= h((string)($r['quote_currencies'] ?: '—')) ?>
              </span>
            </td>
            <td>
              <form method="post" class="row" style="gap:6px; align-items:center;">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['rfq_tracker_csrf']) ?>" />
                <input type="hidden" name="action" value="update_request_status" />
                <input type="hidden" name="rfq_id" value="<?= (int)$r['id'] ?>" />
                <select name="request_status" style="min-width:150px;">
                  <?php foreach ($request_statuses as $k => $label): ?>
                    <option value="<?= h($k) ?>" <?= $r['request_status'] === $k ? 'selected' : '' ?>><?= h($label) ?></option>
                  <?php endforeach; ?>
                </select>
                <button type="submit" class="btn">Save</button>
              </form>
            </td>
            <td><?= h($r['requested_by_username'] ?? 'Unknown') ?></td>
            <td class="muted" style="white-space:nowrap;"><?= h($r['created_at']) ?></td>
            <td class="col-actions">
              <a class="btn" href="rfq_tracker.php?rfq_id=<?= (int)$r['id'] ?>">Quotes</a>
              <a class="btn" href="rfq_tracker.php?rfq_text_id=<?= (int)$r['id'] ?>">Email Text</a>
              <a class="btn" href="rfq_tracker.php?edit_rfq_id=<?= (int)$r['id'] ?>">Edit</a>
              <form method="post" style="display:inline;"
                    onsubmit="return confirm('Delete this RFQ and all its quotes? This cannot be undone.');">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['rfq_tracker_csrf']) ?>" />
                <input type="hidden" name="action" value="delete_rfq" />
                <input type="hidden" name="rfq_id" value="<?= (int)$r['id'] ?>" />
                <button type="submit" class="btn" style="color:#b91c1c;">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($selected_rfq): ?>
  <div class="card">
    <h2 style="margin-top:0;">Quotes for RFQ #<?= (int)$selected_rfq['id'] ?> — <?= h($selected_rfq['request_title']) ?></h2>

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
          <select name="quote_status">
            <?php foreach ($quote_statuses as $k => $label): ?>
              <option value="<?= h($k) ?>" <?= $editing_quote['quote_status'] === $k ? 'selected' : '' ?>><?= h($label) ?></option>
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
    <?php else: ?>
      <h3 style="margin-top:0; margin-bottom:12px;">Add Quote</h3>
      <form method="post" class="form-grid" enctype="multipart/form-data" novalidate>
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['rfq_tracker_csrf']) ?>" />
        <input type="hidden" name="action" value="add_quote" />
        <input type="hidden" name="rfq_id" value="<?= (int)$selected_rfq['id'] ?>" />

        <div>
          <label>Supplier Name <span style="color:var(--d)">*</span></label>
          <input type="text" name="supplier_name" maxlength="255" required placeholder="e.g. ABC Laser Systems" />
        </div>
        <div>
          <label>Quote Amount <span style="color:var(--d)">*</span></label>
          <input type="number" name="quote_amount" min="0" step="0.01" required placeholder="e.g. 10800.00" />
        </div>
        <div>
          <label>Currency <span style="color:var(--d)">*</span></label>
          <input type="text" name="currency" maxlength="3" required value="USD" />
        </div>
        <div>
          <label>Lead Time (days)</label>
          <input type="number" name="lead_time_days" min="0" max="<?= MAX_LEAD_TIME_DAYS ?>" placeholder="e.g. 35" />
        </div>
        <div>
          <label>Shipping Cost</label>
          <input type="number" name="shipping_cost" min="0" step="0.01" placeholder="e.g. 1800.00" />
        </div>
        <div>
          <label>Shipping Method</label>
          <input type="text" name="shipping_method" maxlength="100" placeholder="e.g. Sea freight / Air cargo" />
        </div>
        <div>
          <label>Shipping Origin</label>
          <input type="text" name="shipping_origin" maxlength="255" placeholder="e.g. Qingdao, China" />
        </div>
        <div>
          <label>Quote Status</label>
          <select name="quote_status">
            <?php foreach ($quote_statuses as $k => $label): ?>
              <option value="<?= h($k) ?>"><?= h($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div>
          <label>Quote Received On</label>
          <input type="date" name="received_on" />
        </div>
        <div class="full">
          <label>Notes</label>
          <textarea name="notes" rows="4" maxlength="5000"
                    placeholder="Include quote terms, included accessories, warranty, or negotiation details."></textarea>
        </div>
        <div>
          <label>Quote File</label>
          <input type="file" name="quote_file" />
          <div class="muted" style="font-size:12px; margin-top:4px;">Optional, up to 25 MB.</div>
        </div>
        <div class="full row" style="margin-top:8px;">
          <button type="submit" class="btn primary">Add Quote</button>
        </div>
      </form>
    <?php endif; ?>

    <div class="table-wrap" style="overflow-x:auto; margin-top:14px;">
      <table class="table-auto" style="min-width:1020px;">
        <thead>
          <tr>
            <th>Supplier</th>
            <th>Quote</th>
            <th>Lead Time</th>
            <th>Shipping</th>
            <th>Status</th>
            <th>Received</th>
            <th>Notes</th>
            <th>Attachment</th>
            <th>Added By</th>
            <th class="col-actions">Actions</th>
          </tr>
        </thead>
        <tbody>
          <?php if (!$quotes): ?>
            <tr><td colspan="10" class="muted">No quotes added yet for this RFQ.</td></tr>
          <?php endif; ?>
          <?php foreach ($quotes as $q): ?>
            <tr>
              <td><?= h($q['supplier_name']) ?></td>
              <td>
                <?= h($q['currency']) ?> <?= h(number_format((float)$q['quote_amount'], 2)) ?>
              </td>
              <td><?= $q['lead_time_days'] !== null ? h((string)$q['lead_time_days']) . ' days' : '—' ?></td>
              <td>
                <?= $q['shipping_cost'] !== null ? h(number_format((float)$q['shipping_cost'], 2)) : '—' ?><br>
                <span class="muted"><?= h(format_shipping_details($q['shipping_origin'] ?? null, $q['shipping_method'] ?? null)) ?></span>
              </td>
              <td><?= h($quote_statuses[$q['quote_status']] ?? $q['quote_status']) ?></td>
              <td><?= h($q['received_on'] ?? '') ?></td>
              <td style="max-width:240px; white-space:normal;"><?= nl2br(h(mb_strimwidth((string)($q['notes'] ?? ''), 0, 180, '…'))) ?></td>
              <td>
                <?php
                  $file_name = (string)($q['quote_file_stored_name'] ?? '');
                  $file_url = '';
                  if ($file_name !== '' && is_safe_stored_upload_name($file_name)) {
                    $file_url = 'rfq_quote_file.php?quote_id=' . (int)$q['id'];
                  }
                ?>
                <?php if ($file_url !== ''): ?>
                  <a class="btn" href="<?= h($file_url) ?>" target="_blank" rel="noopener noreferrer">Open</a><br>
                  <span class="muted" style="font-size:12px;">
                    <?= h((string)($q['quote_file_original_name'] ?? 'Attachment')) ?>
                  </span>
                <?php else: ?>
                  —
                <?php endif; ?>
              </td>
              <td class="muted"><?= h($q['created_by_username'] ?? 'Unknown') ?></td>
              <td class="col-actions">
                <a class="btn" href="rfq_tracker.php?rfq_id=<?= (int)$selected_rfq['id'] ?>&edit_quote_id=<?= (int)$q['id'] ?>">Edit</a>
                <form method="post" style="display:inline;"
                      onsubmit="return confirm('Delete this quote? This cannot be undone.');">
                  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['rfq_tracker_csrf']) ?>" />
                  <input type="hidden" name="action" value="delete_quote" />
                  <input type="hidden" name="rfq_id" value="<?= (int)$selected_rfq['id'] ?>" />
                  <input type="hidden" name="quote_id" value="<?= (int)$q['id'] ?>" />
                  <button type="submit" class="btn" style="color:#b91c1c;">Delete</button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<?php render_footer(); ?>
