<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_rfq_access();

const MAX_RFQ_QUANTITY = 1000;
// Small tolerance to avoid false mismatches from decimal rounding in money math.
const PRICE_COMPARISON_TOLERANCE = 0.01;
const REQUEST_TYPES = ['RFQ', 'Sourcing', 'PO'];
const REQUEST_CATEGORIES = ['machine', 'parts'];
const PO_SHIPPING_METHODS = ['Sea Freight', 'Air Freight', 'Express', 'Pickup'];

if (session_status() !== PHP_SESSION_ACTIVE) {
  session_start();
}
if (empty($_SESSION['rfq_form_csrf'])) {
  $_SESSION['rfq_form_csrf'] = bin2hex(random_bytes(24));
}

$errors = [];
$success = '';
$edit_rfq_id = max(0, (int)(($_SERVER['REQUEST_METHOD'] === 'POST') ? ($_POST['edit_rfq_id'] ?? 0) : ($_GET['edit_rfq_id'] ?? 0)));
$is_edit_mode = $edit_rfq_id > 0;
$forced_request_category = null;
if (isset($rfq_form_mode) && is_string($rfq_form_mode)) {
  $mode = strtolower(trim($rfq_form_mode));
  if (in_array($mode, REQUEST_CATEGORIES, true)) {
    $forced_request_category = $mode;
  }
}
$query_category = strtolower(trim((string)($_GET['request_category'] ?? '')));
if ($forced_request_category === null && !$is_edit_mode && in_array($query_category, REQUEST_CATEGORIES, true)) {
  $forced_request_category = $query_category;
}
$is_parts_entrypoint = $forced_request_category === 'parts';

function validate_po_money(string $value, string $label, array &$errors): ?float {
  if ($value === '') {
    $errors[] = $label . ' is required for purchase orders.';
    return null;
  }
  if (!is_numeric($value)) {
    $errors[] = $label . ' must be a valid number.';
    return null;
  }
  $amount = round((float)$value, 2);
  if ($amount < 0) {
    $errors[] = $label . ' cannot be negative.';
    return null;
  }
  return $amount;
}

function split_request_title_with_type(string $stored_title): array {
  $stored_title = trim($stored_title);
  if (preg_match('/^Purchase\s+Order\s*:\s*(.+)$/i', $stored_title, $m)) {
    return ['PO', trim($m[1])];
  }
  foreach (REQUEST_TYPES as $request_type) {
    $request_type_pattern = preg_quote($request_type, '/');
    if (preg_match('/^' . $request_type_pattern . '\s*:\s*(.+)$/i', $stored_title, $m)) {
      return [$request_type, trim($m[1])];
    }
  }
  return ['RFQ', $stored_title];
}
$fields = [
  'request_category'=> $forced_request_category ?? 'machine',
  'request_type'    => 'RFQ',
  'acquisition_purpose' => 'customer',
  'urgency'         => 'normal',
  'contact_name'    => '',
  'company_name'    => '',
  'contact_email'   => '',
  'contact_phone'   => '',
  'buyer_name'      => '',
  'buyer_company'   => '',
  'buyer_email'     => '',
  'buyer_phone'     => '',
  'request_title'   => '',
  'machine_size'    => '',
  'laser_watts'     => '',
  'tube_type'       => '',
  'part_category'   => '',
  'part_specs'      => '',
  'quantity'        => '1',
  'required_features' => '',
  'additional_notes'  => '',
  'po_supplier_info' => '',
  'po_unit_price' => '',
  'po_line_total' => '',
  'po_expected_delivery_date' => '',
  'po_delivery_address' => '',
  'po_payment_terms' => '',
  'po_shipping_method' => '',
  'po_shipping_cost' => '',
  'po_total_amount' => '',
  'image_path'  => '',
  'image_thumb' => '',
];
$profile_contact_fields = [
  'contact_name'  => '',
  'company_name'  => '',
  'contact_email' => '',
  'contact_phone' => '',
];
$profile_delivery_address = '';

// Load canned responses for quick-fill buttons
$canned_responses = $pdo->query(
  "SELECT slot, label, body FROM rfq_canned_responses WHERE slot IN (1,2,3,4) AND label != '' AND body != '' ORDER BY slot"
)->fetchAll();

if (current_user_id() !== null) {
  $profile_stmt = $pdo->prepare(
    "SELECT username, email, contact_name, company_name, contact_phone, delivery_address
     FROM users
     WHERE id = ?
     LIMIT 1"
  );
  $profile_stmt->execute([(int)current_user_id()]);
  $profile = $profile_stmt->fetch();
  if ($profile) {
    $profile_contact_fields['contact_name'] = trim((string)($profile['contact_name'] ?? ''));
    if ($profile_contact_fields['contact_name'] === '') {
      $profile_contact_fields['contact_name'] = trim((string)($profile['username'] ?? ''));
    }
    $profile_contact_fields['company_name'] = trim((string)($profile['company_name'] ?? ''));
    $profile_contact_fields['contact_email'] = trim((string)($profile['email'] ?? ''));
    $profile_contact_fields['contact_phone'] = trim((string)($profile['contact_phone'] ?? ''));
    $profile_delivery_address = trim((string)($profile['delivery_address'] ?? ''));
  }
}
$fields = array_merge($fields, $profile_contact_fields);
$fields['po_delivery_address'] = $profile_delivery_address;

if ($is_edit_mode && $_SERVER['REQUEST_METHOD'] !== 'POST') {
  $edit_stmt = $pdo->prepare(
    "SELECT id, request_category, acquisition_purpose, urgency, buyer_name, buyer_company, buyer_email, buyer_phone,
            request_title, machine_size, laser_watts, tube_type, part_category, part_specs, quantity, required_features, additional_notes,
            po_supplier_info, po_unit_price, po_line_total, po_expected_delivery_date, po_delivery_address, po_payment_terms,
            po_shipping_method, po_shipping_cost, po_total_amount, image_path, image_thumb
     FROM rfq_requests
     WHERE id = ?
     LIMIT 1"
  );
  $edit_stmt->execute([$edit_rfq_id]);
  $editing_rfq = $edit_stmt->fetch();
  if (!$editing_rfq) {
    $errors[] = 'RFQ not found.';
    $is_edit_mode = false;
    $edit_rfq_id = 0;
  } else {
    [$parsed_request_type, $parsed_request_title] = split_request_title_with_type((string)($editing_rfq['request_title'] ?? ''));
    $editing_request_category = strtolower(trim((string)($editing_rfq['request_category'] ?? 'machine')));
    $fields['request_type'] = in_array($parsed_request_type, REQUEST_TYPES, true) ? $parsed_request_type : 'RFQ';
    if ($fields['request_type'] !== 'PO' && $editing_request_category === 'po') {
      $fields['request_type'] = 'PO';
    }
    $form_request_category = $editing_request_category;
    if (!in_array($form_request_category, REQUEST_CATEGORIES, true)) {
      $form_request_category = trim((string)($editing_rfq['part_category'] ?? '')) !== '' ? 'parts' : 'machine';
    }
    $fields['request_category'] = $form_request_category;
    $fields['acquisition_purpose'] = (string)($editing_rfq['acquisition_purpose'] ?? 'customer');
    $fields['urgency'] = (string)($editing_rfq['urgency'] ?? 'normal');
    $fields['buyer_name'] = (string)($editing_rfq['buyer_name'] ?? '');
    $fields['buyer_company'] = (string)($editing_rfq['buyer_company'] ?? '');
    $fields['buyer_email'] = (string)($editing_rfq['buyer_email'] ?? '');
    $fields['buyer_phone'] = (string)($editing_rfq['buyer_phone'] ?? '');
    $fields['request_title'] = $parsed_request_title;
    $fields['machine_size'] = (string)($editing_rfq['machine_size'] ?? '');
    $fields['laser_watts'] = (string)($editing_rfq['laser_watts'] ?? '');
    $fields['tube_type'] = (string)($editing_rfq['tube_type'] ?? '');
    $fields['part_category'] = (string)($editing_rfq['part_category'] ?? '');
    $fields['part_specs'] = (string)($editing_rfq['part_specs'] ?? '');
    $fields['quantity'] = (string)($editing_rfq['quantity'] ?? '1');
    $fields['required_features'] = (string)($editing_rfq['required_features'] ?? '');
    $fields['additional_notes'] = (string)($editing_rfq['additional_notes'] ?? '');
    $fields['po_supplier_info'] = (string)($editing_rfq['po_supplier_info'] ?? '');
    $fields['po_unit_price'] = $editing_rfq['po_unit_price'] !== null ? (string)$editing_rfq['po_unit_price'] : '';
    $fields['po_line_total'] = $editing_rfq['po_line_total'] !== null ? (string)$editing_rfq['po_line_total'] : '';
    $fields['po_expected_delivery_date'] = (string)($editing_rfq['po_expected_delivery_date'] ?? '');
    $fields['po_delivery_address'] = (string)($editing_rfq['po_delivery_address'] ?? '');
    if ($fields['po_delivery_address'] === '') {
      $fields['po_delivery_address'] = $profile_delivery_address;
    }
    $fields['po_payment_terms'] = (string)($editing_rfq['po_payment_terms'] ?? '');
    $fields['po_shipping_method'] = (string)($editing_rfq['po_shipping_method'] ?? '');
    $fields['po_shipping_cost'] = $editing_rfq['po_shipping_cost'] !== null ? (string)$editing_rfq['po_shipping_cost'] : '';
    $fields['po_total_amount'] = $editing_rfq['po_total_amount'] !== null ? (string)$editing_rfq['po_total_amount'] : '';
    $fields['image_path']  = (string)($editing_rfq['image_path']  ?? '');
    $fields['image_thumb'] = (string)($editing_rfq['image_thumb'] ?? '');
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $submitted_csrf = (string)($_POST['csrf_token'] ?? '');
  if (empty($_SESSION['rfq_form_csrf']) || !hash_equals((string)$_SESSION['rfq_form_csrf'], $submitted_csrf)) {
    $errors[] = 'Security token mismatch. Please refresh and try again.';
  }

  foreach ($fields as $k => $_) {
    if (array_key_exists($k, $profile_contact_fields)) {
      continue;
    }
    $fields[$k] = trim((string)($_POST[$k] ?? ''));
  }
  $fields = array_merge($fields, $profile_contact_fields);
  if ($forced_request_category !== null) {
    $fields['request_category'] = $forced_request_category;
  }

  if (!in_array($fields['request_type'], REQUEST_TYPES, true)) {
    $errors[] = 'Request type must be one of: ' . implode(', ', REQUEST_TYPES) . '.';
  }
  if (!in_array($fields['request_category'], REQUEST_CATEGORIES, true)) {
    $errors[] = 'Request category must be Machine or Parts.';
  }
  if (!in_array($fields['acquisition_purpose'], ['customer', 'internal'], true)) {
    $errors[] = 'Acquisition purpose must be Customer Request or Internal Use.';
  }
  if (!in_array($fields['urgency'], ['low', 'normal', 'high', 'critical'], true)) {
    $errors[] = 'Urgency must be Low, Normal, High, or Critical.';
  }
  if ($fields['request_title'] === '') $errors[] = 'Request title is required.';
  if ($fields['request_category'] === 'machine') {
    if ($fields['machine_size'] === '') $errors[] = 'Machine size is required for machine requests.';
    if ($fields['laser_watts'] === '') $errors[] = 'Laser watts is required for machine requests.';
    if ($fields['tube_type'] === '') $errors[] = 'Tube type is required for machine requests.';
    if ($fields['required_features'] === '') $errors[] = 'Required features are required for machine requests.';
  } else {
    if ($fields['part_category'] === '') $errors[] = 'Part category is required for parts requests.';
    if ($fields['part_specs'] === '') $errors[] = 'Part specs are required for parts requests.';
  }
  if ($fields['contact_email'] !== '' && !filter_var($fields['contact_email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Contact email must be a valid email address.';
  }
  if ($fields['buyer_email'] !== '' && !filter_var($fields['buyer_email'], FILTER_VALIDATE_EMAIL)) {
    $errors[] = 'Customer email must be a valid email address.';
  }

  if (!ctype_digit($fields['quantity']) || (int)$fields['quantity'] < 1 || (int)$fields['quantity'] > MAX_RFQ_QUANTITY) {
    $errors[] = 'Quantity must be a whole number between 1 and ' . MAX_RFQ_QUANTITY . '.';
  }
  if ($is_edit_mode) {
    $exists_stmt = $pdo->prepare("SELECT id FROM rfq_requests WHERE id = ? LIMIT 1");
    $exists_stmt->execute([$edit_rfq_id]);
    if (!$exists_stmt->fetch()) {
      $errors[] = 'RFQ not found.';
    }
  }

  if (strlen($fields['required_features']) > 5000) {
    $errors[] = 'Required features must be 5000 characters or fewer.';
  }
  if (strlen($fields['part_specs']) > 5000) {
    $errors[] = 'Part specs must be 5000 characters or fewer.';
  }
  if (strlen($fields['part_category']) > 100) {
    $errors[] = 'Part category must be 100 characters or fewer.';
  }
  if (strlen($fields['additional_notes']) > 5000) {
    $errors[] = 'Additional notes must be 5000 characters or fewer.';
  }

  $po_unit_price_amount = null;
  $po_line_total_amount = null;
  $po_shipping_cost_amount = null;
  $po_total_amount_amount = null;
  if ($fields['request_type'] === 'PO') {
    if ($fields['po_supplier_info'] === '') {
      $errors[] = 'Supplier information is required for purchase orders.';
    } elseif (strlen($fields['po_supplier_info']) > 500) {
      $errors[] = 'Supplier information must be 500 characters or fewer.';
    }
    $po_unit_price_amount = validate_po_money($fields['po_unit_price'], 'Unit price', $errors);
    $po_line_total_amount = $po_unit_price_amount !== null ? round($po_unit_price_amount * (int)$fields['quantity'], 2) : null;
    if ($fields['po_expected_delivery_date'] === '') {
      $errors[] = 'Expected delivery date is required for purchase orders.';
    } else {
      $expected_date = DateTime::createFromFormat('Y-m-d', $fields['po_expected_delivery_date']);
      $date_ok = $expected_date && $expected_date->format('Y-m-d') === $fields['po_expected_delivery_date'];
      if (!$date_ok) {
        $errors[] = 'Expected delivery date must be a valid date.';
      } elseif ($fields['po_expected_delivery_date'] < date('Y-m-d')) {
        $errors[] = 'Expected delivery date cannot be in the past.';
      }
    }
    if ($fields['po_delivery_address'] === '') {
      $errors[] = 'Delivery address is required for purchase orders.';
    } elseif (strlen($fields['po_delivery_address']) > 500) {
      $errors[] = 'Delivery address must be 500 characters or fewer.';
    }
    if ($fields['po_payment_terms'] === '') {
      $errors[] = 'Payment terms are required for purchase orders.';
    } elseif (strlen($fields['po_payment_terms']) > 2000) {
      $errors[] = 'Payment terms must be 2000 characters or fewer.';
    }
    if (!in_array($fields['po_shipping_method'], PO_SHIPPING_METHODS, true)) {
      $errors[] = 'Shipping method must be one of: ' . implode(', ', PO_SHIPPING_METHODS) . '.';
    }
    $po_shipping_cost_amount = validate_po_money($fields['po_shipping_cost'], 'Shipping cost', $errors);
    $po_total_amount_amount = validate_po_money($fields['po_total_amount'], 'Total amount', $errors);
  }

  if (!$errors) {
    // Handle image upload
    $new_image_path  = null;
    $new_image_thumb = null;
    $image_upload_error = '';
    if (isset($_FILES['rfq_image']) && $_FILES['rfq_image']['error'] !== UPLOAD_ERR_NO_FILE) {
      $fup = $_FILES['rfq_image'];
      if ($fup['error'] !== UPLOAD_ERR_OK) {
        $errors[] = 'Image upload failed (code ' . (int)$fup['error'] . ').';
      } else {
        $allowed_mimes = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/gif' => 'gif'];
        $tmp_path = (string)($fup['tmp_name'] ?? '');
        $detected_mime = '';
        if (is_file($tmp_path) && function_exists('finfo_open')) {
          $fi = finfo_open(FILEINFO_MIME_TYPE);
          if ($fi) {
            $detected_mime = (string)(finfo_file($fi, $tmp_path) ?: '');
            finfo_close($fi);
          }
        }
        if (!isset($allowed_mimes[$detected_mime])) {
          $errors[] = 'Image must be a JPG, PNG, or GIF file.';
        } else {
          $uploadsDir = __DIR__ . '/uploads';
          if (!is_dir($uploadsDir)) @mkdir($uploadsDir, 0775, true);
          if (!is_dir($uploadsDir) || !is_writable($uploadsDir)) {
            $errors[] = 'Uploads directory is not writable.';
          } else {
            $ext = $allowed_mimes[$detected_mime];
            $base_name = 'rfq_img_' . bin2hex(random_bytes(12));
            $stored_full  = $base_name . '.' . $ext;
            $stored_thumb = $base_name . '_thumb.' . $ext;
            if (!move_uploaded_file($tmp_path, $uploadsDir . '/' . $stored_full)) {
              $errors[] = 'Failed to save uploaded image.';
            } else {
              // Create thumbnail (max 200×200)
              $thumb_ok = false;
              $src_path = $uploadsDir . '/' . $stored_full;
              if ($detected_mime === 'image/jpeg') {
                $src_img = @imagecreatefromjpeg($src_path);
              } elseif ($detected_mime === 'image/png') {
                $src_img = @imagecreatefrompng($src_path);
              } else {
                $src_img = @imagecreatefromgif($src_path);
              }
              if ($src_img !== false) {
                $src_w = imagesx($src_img);
                $src_h = imagesy($src_img);
                $max_side = 200;
                if ($src_w > $max_side || $src_h > $max_side) {
                  $ratio = min($max_side / $src_w, $max_side / $src_h);
                  $dst_w = (int)round($src_w * $ratio);
                  $dst_h = (int)round($src_h * $ratio);
                } else {
                  $dst_w = $src_w;
                  $dst_h = $src_h;
                }
                $thumb_img = imagecreatetruecolor($dst_w, $dst_h);
                if ($thumb_img !== false) {
                  if ($detected_mime === 'image/png') {
                    imagealphablending($thumb_img, false);
                    imagesavealpha($thumb_img, true);
                    $transparent = imagecolorallocatealpha($thumb_img, 255, 255, 255, 127);
                    imagefill($thumb_img, 0, 0, $transparent);
                  }
                  imagecopyresampled($thumb_img, $src_img, 0, 0, 0, 0, $dst_w, $dst_h, $src_w, $src_h);
                  $thumb_path = $uploadsDir . '/' . $stored_thumb;
                  if ($detected_mime === 'image/jpeg') {
                    $thumb_ok = imagejpeg($thumb_img, $thumb_path, 85);
                  } elseif ($detected_mime === 'image/png') {
                    $thumb_ok = imagepng($thumb_img, $thumb_path);
                  } else {
                    $thumb_ok = imagegif($thumb_img, $thumb_path);
                  }
                  imagedestroy($thumb_img);
                }
                imagedestroy($src_img);
              }
              // Fall back to full image as thumb if GD failed
              if (!$thumb_ok) {
                $stored_thumb = $stored_full;
              }
              $new_image_path  = $stored_full;
              $new_image_thumb = $stored_thumb;
            }
          }
        }
      }
    }
  }

  if (!$errors) {
    $stored_request_category = $fields['request_type'] === 'PO' ? 'po' : $fields['request_category'];
    $full_request_title = $fields['request_type'] . ': ' . $fields['request_title'];
    if ($is_edit_mode) {
      $stmt = $pdo->prepare(
        "UPDATE rfq_requests SET
          request_category = ?, acquisition_purpose = ?, urgency = ?, buyer_name = ?, buyer_company = ?, buyer_email = ?, buyer_phone = ?,
          request_title = ?, machine_size = ?, laser_watts = ?, tube_type = ?, part_category = ?, part_specs = ?,
          quantity = ?, required_features = ?, additional_notes = ?, po_supplier_info = ?, po_unit_price = ?, po_line_total = ?,
          po_expected_delivery_date = ?, po_delivery_address = ?, po_payment_terms = ?, po_shipping_method = ?, po_shipping_cost = ?, po_total_amount = ?,
          image_path = COALESCE(?, image_path), image_thumb = COALESCE(?, image_thumb)
         WHERE id = ?"
      );
      $stmt->execute([
        $stored_request_category,
        $fields['acquisition_purpose'],
        $fields['urgency'],
        $fields['buyer_name']    === '' ? null : $fields['buyer_name'],
        $fields['buyer_company'] === '' ? null : $fields['buyer_company'],
        $fields['buyer_email']   === '' ? null : $fields['buyer_email'],
        $fields['buyer_phone']   === '' ? null : $fields['buyer_phone'],
        $full_request_title,
        $fields['request_category'] === 'machine' ? $fields['machine_size'] : null,
        $fields['request_category'] === 'machine' ? $fields['laser_watts'] : null,
        $fields['request_category'] === 'machine' ? $fields['tube_type'] : null,
        $fields['request_category'] === 'parts' ? $fields['part_category'] : null,
        $fields['request_category'] === 'parts' ? $fields['part_specs'] : null,
        (int)$fields['quantity'],
        $fields['request_category'] === 'machine' ? $fields['required_features'] : null,
        $fields['additional_notes'] === '' ? null : $fields['additional_notes'],
        $fields['request_type'] === 'PO' ? $fields['po_supplier_info'] : null,
        $fields['request_type'] === 'PO' ? $po_unit_price_amount : null,
        $fields['request_type'] === 'PO' ? $po_line_total_amount : null,
        $fields['request_type'] === 'PO' ? $fields['po_expected_delivery_date'] : null,
        $fields['request_type'] === 'PO' ? $fields['po_delivery_address'] : null,
        $fields['request_type'] === 'PO' ? $fields['po_payment_terms'] : null,
        $fields['request_type'] === 'PO' ? $fields['po_shipping_method'] : null,
        $fields['request_type'] === 'PO' ? $po_shipping_cost_amount : null,
        $fields['request_type'] === 'PO' ? $po_total_amount_amount : null,
        $new_image_path,
        $new_image_thumb,
        $edit_rfq_id,
      ]);
      header('Location: sourcing_rfq_tracker.php');
      exit;
    } else {
      $stmt = $pdo->prepare(
        "INSERT INTO rfq_requests
          (
            requested_by, request_category, acquisition_purpose, urgency, contact_name, company_name, contact_email, contact_phone,
            buyer_name, buyer_company, buyer_email, buyer_phone,
            request_title, machine_size, laser_watts, tube_type, part_category, part_specs,
            quantity, required_features, additional_notes, po_supplier_info, po_unit_price, po_line_total, po_expected_delivery_date,
            po_delivery_address, po_payment_terms, po_shipping_method, po_shipping_cost, po_total_amount,
            image_path, image_thumb
          )
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
      );
      $stmt->execute([
        (int)current_user_id(),
        $stored_request_category,
        $fields['acquisition_purpose'],
        $fields['urgency'],
        $fields['contact_name']  === '' ? null : $fields['contact_name'],
        $fields['company_name']  === '' ? null : $fields['company_name'],
        $fields['contact_email'] === '' ? null : $fields['contact_email'],
        $fields['contact_phone'] === '' ? null : $fields['contact_phone'],
        $fields['buyer_name']    === '' ? null : $fields['buyer_name'],
        $fields['buyer_company'] === '' ? null : $fields['buyer_company'],
        $fields['buyer_email']   === '' ? null : $fields['buyer_email'],
        $fields['buyer_phone']   === '' ? null : $fields['buyer_phone'],
        $full_request_title,
        $fields['request_category'] === 'machine' ? $fields['machine_size'] : null,
        $fields['request_category'] === 'machine' ? $fields['laser_watts'] : null,
        $fields['request_category'] === 'machine' ? $fields['tube_type'] : null,
        $fields['request_category'] === 'parts' ? $fields['part_category'] : null,
        $fields['request_category'] === 'parts' ? $fields['part_specs'] : null,
        (int)$fields['quantity'],
        $fields['request_category'] === 'machine' ? $fields['required_features'] : null,
        $fields['additional_notes'] === '' ? null : $fields['additional_notes'],
        $fields['request_type'] === 'PO' ? $fields['po_supplier_info'] : null,
        $fields['request_type'] === 'PO' ? $po_unit_price_amount : null,
        $fields['request_type'] === 'PO' ? $po_line_total_amount : null,
        $fields['request_type'] === 'PO' ? $fields['po_expected_delivery_date'] : null,
        $fields['request_type'] === 'PO' ? $fields['po_delivery_address'] : null,
        $fields['request_type'] === 'PO' ? $fields['po_payment_terms'] : null,
        $fields['request_type'] === 'PO' ? $fields['po_shipping_method'] : null,
        $fields['request_type'] === 'PO' ? $po_shipping_cost_amount : null,
        $fields['request_type'] === 'PO' ? $po_total_amount_amount : null,
        $new_image_path,
        $new_image_thumb,
      ]);

      $new_request_id = (int)$pdo->lastInsertId();
      $_SESSION['rfq_form_csrf'] = bin2hex(random_bytes(24));
      header('Location: sourcing_rfq_submitted.php?rfq_id=' . $new_request_id);
      exit;
    }
  }
}

render_header($is_edit_mode ? ('Edit Sourcing RFQ #' . $edit_rfq_id) : ($is_parts_entrypoint ? 'Parts RFQ / Sourcing Request Form' : 'Sourcing RFQ Form'));
?>

<div class="card page-header">
  <div class="page-header-body">
    <h1 id="page-heading"
      <?php if (!$is_edit_mode && !$is_parts_entrypoint): ?>
        data-default-heading="Sourcing RFQ Form"
        data-po-heading="Purchase Order Form"
      <?php endif; ?>>
      <?= $is_edit_mode ? ('Edit Sourcing RFQ Request #' . (int)$edit_rfq_id) : ($is_parts_entrypoint ? 'CO2 Laser Parts RFQ / Sourcing Requests' : 'Sourcing RFQ Form') ?>
    </h1>
    <p class="muted">
      <?= $is_edit_mode
        ? 'Update this RFQ request using the same form used for new RFQs.'
        : 'Submit either machine RFQs or parts sourcing requests (chillers, blowers, laser tubes, and more) in one workflow.' ?>
    </p>
  </div>
  <a class="btn" href="sourcing_rfq_tracker.php">Sourcing RFQ Tracker →</a>
</div>

<?php
render_alibaba_workflow_banner('create_rfq');
?>

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

<form method="post" enctype="multipart/form-data" class="card" novalidate>
  <input type="hidden" name="csrf_token" value="<?= h($_SESSION['rfq_form_csrf']) ?>" />
  <?php if ($is_edit_mode): ?>
    <input type="hidden" name="edit_rfq_id" value="<?= (int)$edit_rfq_id ?>" />
  <?php endif; ?>

  <div class="info-banner">
    ℹ️ Company and contact details are pre-filled from your <a href="user_page.php">profile</a>. Update your profile to change these defaults.
  </div>
  <?php if ($canned_responses): ?>
  <div style="margin:12px 0 16px;">
    <button type="button" class="btn primary" data-canned-all="1" style="font-weight:700;">
      Add All Notices
    </button>
  </div>
  <?php endif; ?>
  <h2 class="form-section-heading">Request Details</h2>

  <div class="form-grid">
    <div>
      <?php if ($forced_request_category !== null): ?>
        <label>Request Category</label>
        <input type="hidden" id="request_category" name="request_category" value="<?= h($fields['request_category']) ?>" />
        <input type="text" value="<?= $fields['request_category'] === 'parts' ? 'Parts' : 'Machine' ?>" disabled />
      <?php else: ?>
        <label>Request Category <span style="color:var(--d)">*</span></label>
        <select name="request_category" id="request_category" required>
          <option value="machine" <?= $fields['request_category'] === 'machine' ? 'selected' : '' ?>>Machine</option>
          <option value="parts" <?= $fields['request_category'] === 'parts' ? 'selected' : '' ?>>Parts</option>
        </select>
      <?php endif; ?>
    </div>
    <div>
      <label>Request Type <span style="color:var(--d)">*</span></label>
      <select name="request_type" id="request_type" required>
        <?php foreach (REQUEST_TYPES as $request_type): ?>
          <option value="<?= h($request_type) ?>" <?= $fields['request_type'] === $request_type ? 'selected' : '' ?>><?= h($request_type) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div>
      <label>Acquisition Purpose <span style="color:var(--d)">*</span></label>
      <select name="acquisition_purpose" id="acquisition_purpose" required>
        <option value="customer" <?= $fields['acquisition_purpose'] === 'customer' ? 'selected' : '' ?>>Customer Request</option>
        <option value="internal" <?= $fields['acquisition_purpose'] === 'internal' ? 'selected' : '' ?>>Internal Use (Inventory / Repairs)</option>
      </select>
    </div>
    <div>
      <label>Urgency <span style="color:var(--d)">*</span></label>
      <select name="urgency" required>
        <option value="low"      <?= $fields['urgency'] === 'low'      ? 'selected' : '' ?>>Low</option>
        <option value="normal"   <?= $fields['urgency'] === 'normal'   ? 'selected' : '' ?>>Normal</option>
        <option value="high"     <?= $fields['urgency'] === 'high'     ? 'selected' : '' ?>>High</option>
        <option value="critical" <?= $fields['urgency'] === 'critical' ? 'selected' : '' ?>>Critical</option>
      </select>
    </div>
  </div>

  <div id="customer_information_section" style="margin-top:12px; display:<?= $fields['acquisition_purpose'] === 'customer' ? 'block' : 'none' ?>;">
    <h2 class="form-section-heading">Customer Information</h2>
    <div class="form-grid" style="margin-bottom:12px;">
      <div>
        <label>Customer Name</label>
        <input type="text" name="buyer_name" maxlength="255"
               value="<?= h($fields['buyer_name']) ?>" <?= $fields['acquisition_purpose'] === 'customer' ? '' : 'disabled' ?> />
      </div>
      <div>
        <label>Customer Company</label>
        <input type="text" name="buyer_company" maxlength="255"
               value="<?= h($fields['buyer_company']) ?>" <?= $fields['acquisition_purpose'] === 'customer' ? '' : 'disabled' ?> />
      </div>
      <div>
        <label>Customer Email</label>
        <input type="email" name="buyer_email" maxlength="255"
               value="<?= h($fields['buyer_email']) ?>" <?= $fields['acquisition_purpose'] === 'customer' ? '' : 'disabled' ?> />
      </div>
      <div>
        <label>Customer Phone</label>
        <input type="text" name="buyer_phone" maxlength="100"
               value="<?= h($fields['buyer_phone']) ?>" <?= $fields['acquisition_purpose'] === 'customer' ? '' : 'disabled' ?> />
      </div>
    </div>
  </div>

  <div class="form-grid">
    <div class="full">
      <label>Request Title <span style="color:var(--d)">*</span></label>
      <input type="text" name="request_title" maxlength="255" required
             value="<?= h($fields['request_title']) ?>"
             placeholder="e.g. 130W CO2 Laser Cutter for Acrylic Production" />
    </div>
    <div class="machine-only">
      <label>Machine Size <span style="color:var(--d)">*</span></label>
      <input type="text" name="machine_size" maxlength="100" data-required-on="machine"
             value="<?= h($fields['machine_size']) ?>"
             placeholder="e.g. 1300x900mm bed" />
    </div>
    <div class="machine-only">
      <label>Laser Watts <span style="color:var(--d)">*</span></label>
      <input type="text" name="laser_watts" maxlength="50" data-required-on="machine"
             value="<?= h($fields['laser_watts']) ?>"
             placeholder="e.g. 100W / 130W / 150W" />
    </div>
    <div class="machine-only">
      <label>Tube Type <span style="color:var(--d)">*</span></label>
      <input type="text" name="tube_type" maxlength="100" data-required-on="machine"
             value="<?= h($fields['tube_type']) ?>"
             placeholder="e.g. RECI W6, Yongli A8" />
    </div>
    <div class="parts-only">
      <label>Part Category <span style="color:var(--d)">*</span></label>
      <input type="text" name="part_category" maxlength="100" data-required-on="parts"
             value="<?= h($fields['part_category']) ?>"
             placeholder="e.g. Chiller, Blower, Laser Tube, Lens Set" />
    </div>
    <div>
      <label>Quantity <span style="color:var(--d)">*</span></label>
      <input type="number" name="quantity" min="1" max="<?= MAX_RFQ_QUANTITY ?>" required
             value="<?= h($fields['quantity']) ?>" />
    </div>
    <div class="full po-only">
      <h2 class="form-section-heading">Purchase Order Details</h2>
    </div>
    <div class="full po-only">
      <label>Supplier Information <span style="color:var(--d)">*</span></label>
      <textarea name="po_supplier_info" rows="2" maxlength="500" data-required-on-type="PO"
                placeholder="Who you are buying from"><?= h($fields['po_supplier_info']) ?></textarea>
    </div>
    <div class="po-only">
      <label>Unit Price <span style="color:var(--d)">*</span></label>
      <input type="number" name="po_unit_price" min="0" step="0.01" data-required-on-type="PO"
             value="<?= h($fields['po_unit_price']) ?>" />
    </div>
    <div class="po-only">
      <label>Line Total</label>
      <input type="number" name="po_line_total" min="0" step="0.01" readonly
             value="<?= h($fields['po_line_total']) ?>" />
    </div>
    <div class="po-only">
      <label>Expected Delivery Date <span style="color:var(--d)">*</span></label>
      <input type="date" name="po_expected_delivery_date" data-required-on-type="PO"
             value="<?= h($fields['po_expected_delivery_date']) ?>" />
    </div>
    <div class="po-only">
      <label>Shipping Method <span style="color:var(--d)">*</span></label>
      <select name="po_shipping_method" data-required-on-type="PO">
        <option value="">Select shipping method</option>
        <?php foreach (PO_SHIPPING_METHODS as $shipping_method): ?>
          <option value="<?= h($shipping_method) ?>" <?= $fields['po_shipping_method'] === $shipping_method ? 'selected' : '' ?>><?= h($shipping_method) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="po-only">
      <label>Shipping Cost <span style="color:var(--d)">*</span></label>
      <input type="number" name="po_shipping_cost" min="0" step="0.01" data-required-on-type="PO"
             value="<?= h($fields['po_shipping_cost']) ?>" />
    </div>
    <div class="po-only">
      <label>Total Amount <span style="color:var(--d)">*</span></label>
      <input type="number" name="po_total_amount" min="0" step="0.01" data-required-on-type="PO"
             value="<?= h($fields['po_total_amount']) ?>" />
    </div>
    <div class="full po-only">
      <label>Delivery Address <span style="color:var(--d)">*</span></label>
      <textarea name="po_delivery_address" rows="3" maxlength="500" data-required-on-type="PO"
                placeholder="Where to ship this purchase order"><?= h($fields['po_delivery_address']) ?></textarea>
    </div>
    <div class="full po-only">
      <label>Payment Terms <span style="color:var(--d)">*</span></label>
      <textarea name="po_payment_terms" rows="3" maxlength="2000" data-required-on-type="PO"
                placeholder="Deposit, balance terms, and payment schedule"><?= h($fields['po_payment_terms']) ?></textarea>
    </div>
    <div class="full machine-only">
      <label>Required Features <span style="color:var(--d)">*</span></label>
      <textarea name="required_features" rows="5" maxlength="5000" data-required-on="machine"
               placeholder="List required machine features: chiller type, autofocus, rotary, software support, etc."><?= h($fields['required_features']) ?></textarea>
    </div>
    <div class="full parts-only">
      <label>Part Specs <span style="color:var(--d)">*</span></label>
      <textarea name="part_specs" rows="5" maxlength="5000" data-required-on="parts"
               placeholder="List brand/model compatibility, voltage, dimensions, connector type, and any other required part specs."><?= h($fields['part_specs']) ?></textarea>
    </div>
    <div class="full">
      <label>Additional Notes</label>
      <?php if ($canned_responses): ?>
      <div style="margin-bottom:6px; display:flex; gap:8px; flex-wrap:wrap;">
        <?php foreach ($canned_responses as $cr): ?>
        <button type="button" class="btn"
                data-canned-body="<?= h($cr['body']) ?>"
        ><?= h($cr['label']) ?></button>
        <?php endforeach; ?>
      </div>
      <?php endif; ?>
      <textarea name="additional_notes" rows="4" maxlength="5000"
                placeholder="Any extra details about use case, preferred lead time, certification needs, etc."><?= h($fields['additional_notes']) ?></textarea>
      <div class="muted" style="margin-top:6px;">
        Available placeholders: <code>[contact_name]</code> <code>[company_name]</code> <code>[email]</code> <code>[contact_phone]</code> <code>[username]</code>.
      </div>
      <?php if ($canned_responses): ?>
      <script>
        (function () {
          var notes = document.querySelector('[name=additional_notes]');
        if (notes === null) {
          return;
        }
        var cannedButtons = document.querySelectorAll('[data-canned-body]');
        var cannedBodies = Array.prototype.map.call(cannedButtons, function (btn) {
          return btn.getAttribute('data-canned-body') || '';
          }).filter(function (body) {
            return body !== '';
          });
          cannedButtons.forEach(function (btn) {
            btn.addEventListener('click', function () {
              notes.value = btn.getAttribute('data-canned-body');
              notes.focus();
            });
          });
          var addAllButton = document.querySelector('[data-canned-all]');
          if (addAllButton) {
            addAllButton.addEventListener('click', function () {
              notes.value = cannedBodies.join('\n\n');
              notes.focus();
            });
          }
        })();
      </script>
      <?php endif; ?>
    </div>
  </div>

  <h2 class="form-section-heading">Image Attachment</h2>
  <div class="form-grid">
    <div class="full">
      <label>Upload Image (JPG, PNG, GIF)</label>
      <input type="file" name="rfq_image" id="rfq_image" accept="image/jpeg,image/png,image/gif" />
      <div class="muted" style="margin-top:4px;">Optional. Attach a reference image for this request.</div>
    </div>
    <div class="full" id="rfq_image_preview_wrap" style="<?= $fields['image_thumb'] !== '' ? '' : 'display:none;' ?>">
      <label>Image Preview</label>
      <?php echo render_attachment_modal_assets(); ?>
      <?php if ($fields['image_thumb'] !== '' && $is_edit_mode): ?>
        <?php
          $thumb_url = 'sourcing_rfq_image.php?rfq_id=' . (int)$edit_rfq_id . '&type=thumb';
          $full_url  = 'sourcing_rfq_image.php?rfq_id=' . (int)$edit_rfq_id . '&type=full';
        ?>
        <button type="button"
          class="attachment-open-link"
          data-attachment-name="<?= h('RFQ #' . $edit_rfq_id . ' Image') ?>"
          data-attachment-file="<?= h($full_url . '&download=1') ?>"
          data-attachment-preview="<?= h($full_url) ?>"
          data-attachment-previewable="1"
          data-attachment-image="1"
          style="padding:0; border:0; background:none; cursor:pointer;"
          title="Click to view full-size image"
        ><img src="<?= h($thumb_url) ?>"
              alt="RFQ image thumbnail"
              style="max-width:200px; max-height:200px; border-radius:6px; border:1px solid rgba(0,0,0,.12); display:block;" /></button>
        <div class="muted" style="margin-top:4px;">Click the thumbnail to view the full image. Upload a new file above to replace it.</div>
      <?php endif; ?>
      <div id="rfq_image_js_preview"></div>
    </div>
  </div>

  <div class="row" style="margin-top:18px;">
    <button type="submit" class="btn primary"><?= $is_edit_mode ? 'Save Changes' : 'Submit Request' ?></button>
    <?php if (!$is_edit_mode): ?>
    <a class="btn" href="<?= $fields['request_category'] === 'parts' ? 'sourcing_rfq_form.php?request_category=machine' : 'sourcing_rfq_form.php?request_category=parts' ?>">
      <?= $fields['request_category'] === 'parts' ? 'Switch to Machine RFQ Form' : 'Switch to Parts RFQ Form' ?>
    </a>
    <?php endif; ?>
    <a class="btn" href="sourcing_rfq_tracker.php">Go to Sourcing RFQ Tracker</a>
  </div>
</form>

<script>
  (function () {
    var categoryField = document.getElementById('request_category');
    var requestTypeField = document.getElementById('request_type');
    var acquisitionField = document.getElementById('acquisition_purpose');
    var customerInfoSection = document.getElementById('customer_information_section');
    var customerInfoInputs = customerInfoSection ? customerInfoSection.querySelectorAll('input') : [];
    var workflowStepConfig = {
      create_rfq: {
        label: 'Create RFQ',
        instruction: 'Submit a request for quotation to Alibaba suppliers to begin the procurement process.'
      },
      create_purchase_order: {
        label: 'Create Purchase Order',
        instruction: 'Convert the winning quote into a formal purchase order.'
      }
    };

    function findWorkflowStepIndex(steps, targetLabel) {
      for (var i = 0; i < steps.length; i++) {
        var labelElement = steps[i].querySelector('.awb-step-label');
        if (labelElement && labelElement.textContent.trim() === targetLabel) {
          return i;
        }
      }
      return -1;
    }

    function updateWorkflowBanner() {
      var workflowBanner = document.querySelector('.awb-wrap');
      if (!workflowBanner) return;
      var workflowSteps = workflowBanner.querySelectorAll('.awb-step');
      if (!workflowSteps.length) return;

      var workflowBadge = workflowBanner.querySelector('.awb-head-badge');
      var workflowInstructionText = workflowBanner.querySelector('.awb-instruction-text');
      var workflowInstructionStep = workflowInstructionText ? workflowInstructionText.querySelector('.awb-instruction-step') : null;
      var workflowKey = requestTypeField && requestTypeField.value === 'PO' ? 'create_purchase_order' : 'create_rfq';
      var workflowState = workflowStepConfig[workflowKey];
      if (!workflowState) return;
      var workflowIndex = findWorkflowStepIndex(workflowSteps, workflowState.label);
      if (workflowIndex < 0) workflowIndex = 0;

      workflowSteps.forEach(function (step, index) {
        step.classList.toggle('awb-done', index < workflowIndex);
        step.classList.toggle('awb-current', index === workflowIndex);
      });

      if (workflowBadge) {
        workflowBadge.textContent = 'Step ' + (workflowIndex + 1) + ' of ' + workflowSteps.length + ': ' + workflowState.label;
      }

      if (workflowInstructionStep) {
        workflowInstructionStep.textContent = 'Step ' + (workflowIndex + 1) + ': ' + workflowState.label + ' —';
      }

      if (workflowInstructionText) {
        var instructionSuffix = ' ' + workflowState.instruction;
        if (workflowInstructionStep) {
          var nextNode = workflowInstructionStep.nextSibling;
          if (nextNode && nextNode.nodeType === Node.TEXT_NODE) {
            nextNode.nodeValue = instructionSuffix;
          } else {
            workflowInstructionStep.insertAdjacentText('afterend', instructionSuffix);
          }
        } else {
          workflowInstructionText.textContent = workflowState.instruction;
        }
      }
    }

    if (acquisitionField && customerInfoSection) {
      function toggleCustomerInfo() {
        var showCustomerInfo = acquisitionField.value === 'customer';
        customerInfoSection.style.display = showCustomerInfo ? 'block' : 'none';
        customerInfoInputs.forEach(function (input) {
          input.disabled = !showCustomerInfo;
        });
      }
      acquisitionField.addEventListener('change', toggleCustomerInfo);
      toggleCustomerInfo();
    }
    if (!categoryField) return;
    var machineFields = document.querySelectorAll('.machine-only');
    var partsFields = document.querySelectorAll('.parts-only');
    var poFields = document.querySelectorAll('.po-only');
    var workflowStepLabels = document.querySelectorAll('.awb-wrap .awb-step-label');
    var workflowStepCircles = document.querySelectorAll('.awb-wrap .awb-step-circle');
    function updateWorkflowStepTwoLabel() {
      var isPO = requestTypeField && requestTypeField.value === 'PO';
      var stepTwoLabel = isPO ? 'Copy & Send PO' : 'Copy & Send RFQ';
      if (workflowStepLabels.length > 1) {
        workflowStepLabels[1].textContent = stepTwoLabel;
      }
      if (workflowStepCircles.length > 1) {
        workflowStepCircles[1].title = stepTwoLabel;
        workflowStepCircles[1].setAttribute('aria-label', 'Step 2: ' + stepTwoLabel);
      }
    }
    function toggleSections() {
      var isParts = categoryField.value === 'parts';
      var isPO = requestTypeField && requestTypeField.value === 'PO';
      machineFields.forEach(function (el) { el.style.display = isParts ? 'none' : ''; });
      partsFields.forEach(function (el) { el.style.display = isParts ? '' : 'none'; });
      poFields.forEach(function (el) { el.style.display = isPO ? '' : 'none'; });
      document.querySelectorAll('[data-required-on]').forEach(function (input) {
        input.required = input.getAttribute('data-required-on') === categoryField.value;
      });
      document.querySelectorAll('[data-required-on-type]').forEach(function (input) {
        var isRequired = input.getAttribute('data-required-on-type') === (requestTypeField ? requestTypeField.value : '');
        input.required = isRequired;
      });
      updateWorkflowStepTwoLabel();
      var pageHeading = document.getElementById('page-heading');
      if (pageHeading && pageHeading.dataset.defaultHeading) {
        pageHeading.textContent = isPO ? pageHeading.dataset.poHeading : pageHeading.dataset.defaultHeading;
      }
    }
    categoryField.addEventListener('change', toggleSections);
    if (requestTypeField) {
      requestTypeField.addEventListener('change', toggleSections);
    }
    toggleSections();

    // Auto-calculate Line Total = Unit Price × Quantity
    var unitPriceField = document.querySelector('input[name="po_unit_price"]');
    var lineTotalField = document.querySelector('input[name="po_line_total"]');
    var quantityField  = document.querySelector('input[name="quantity"]');
    function recalcLineTotal() {
      if (!unitPriceField || !lineTotalField || !quantityField) return;
      var price = parseFloat(unitPriceField.value);
      var qty   = parseInt(quantityField.value, 10);
      if (!isNaN(price) && !isNaN(qty) && qty > 0) {
        lineTotalField.value = (price * qty).toFixed(2);
      } else {
        lineTotalField.value = '';
      }
    }
    if (unitPriceField) unitPriceField.addEventListener('input', recalcLineTotal);
    if (quantityField)  quantityField.addEventListener('input', recalcLineTotal);
    recalcLineTotal();

    // Client-side image preview on file select
    var rfqImageInput   = document.getElementById('rfq_image');
    var rfqPreviewWrap  = document.getElementById('rfq_image_preview_wrap');
    var rfqJsPreview    = document.getElementById('rfq_image_js_preview');
    if (rfqImageInput && rfqPreviewWrap && rfqJsPreview) {
      rfqImageInput.addEventListener('change', function () {
        var file = rfqImageInput.files && rfqImageInput.files[0];
        rfqJsPreview.innerHTML = '';
        if (!file) return;
        var reader = new FileReader();
        reader.onload = function (e) {
          rfqPreviewWrap.style.display = '';
          var img = document.createElement('img');
          img.src = e.target.result;
          img.alt = 'Selected image preview';
          img.style.cssText = 'max-width:200px; max-height:200px; border-radius:6px; border:1px solid rgba(0,0,0,.12); display:block; margin-top:6px;';
          rfqJsPreview.appendChild(img);
          // Make the JS preview clickable using the attachment modal if available
          img.style.cursor = 'pointer';
          img.title = 'Click to view full size';
          img.addEventListener('click', function () {
            var modal = document.getElementById('attachmentPreviewModal');
            var modalTitle = document.getElementById('attachmentModalTitle');
            var modalBody  = document.getElementById('attachmentModalBody');
            var modalDownload = document.getElementById('attachmentModalDownload');
            if (!modal || !modalTitle || !modalBody) {
              window.open(e.target.result, '_blank');
              return;
            }
            modalTitle.textContent = file.name || 'Image Preview';
            modalBody.innerHTML = '';
            var fullImg = document.createElement('img');
            fullImg.src = e.target.result;
            fullImg.alt = file.name || 'Image';
            modalBody.appendChild(fullImg);
            if (modalDownload) modalDownload.style.display = 'none';
            modal.classList.add('open');
            modal.setAttribute('aria-hidden', 'false');
          });
        };
        reader.readAsDataURL(file);
      });
    }
  })();
</script>

<?php render_footer(); ?>
