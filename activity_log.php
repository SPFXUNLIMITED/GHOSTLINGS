<?php
// === TEMP DEBUG - REMOVE LATER ===
if (isset($pdo) && ($pdo instanceof PDO)) {
  echo '<pre>';
  echo "=== RAW created_at VALUES ===\n\n";

  $allowed_tables = ['app_requests', 'rfq_requests', 'customer_phone_inquiries'];
  $tables = ['app_requests', 'rfq_requests', 'customer_phone_inquiries'];

  foreach ($tables as $table) {
    if (!in_array($table, $allowed_tables, true)) {
      continue;
    }

    echo "Table: <b>" . htmlspecialchars($table, ENT_QUOTES, 'UTF-8') . "</b>\n";
    try {
      $stmt = $pdo->query("SELECT id, created_at FROM `$table` ORDER BY created_at DESC LIMIT 5");
      if ($stmt === false) {
        echo "  Query failed.\n\n";
        continue;
      }
      while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        echo "  ID " . htmlspecialchars((string)$row['id'], ENT_QUOTES, 'UTF-8') . " → " . htmlspecialchars((string)$row['created_at'], ENT_QUOTES, 'UTF-8') . "\n";
      }
    } catch (Throwable $e) {
      echo "  Query error: " . htmlspecialchars($e->getMessage(), ENT_QUOTES, 'UTF-8') . "\n";
    }
    echo "\n";
  }
  echo '</pre>';
}
// === END DEBUG ===

$activity_rows = [];
$activity_error = '';
$activity_limit = isset($_GET['activity_limit']) ? (int)$_GET['activity_limit'] : 200;
$activity_limit = max(50, min(500, $activity_limit));

if (!isset($pdo) || !($pdo instanceof PDO)) {
  $activity_error = 'Activity log is unavailable.';
} else {
  try {
    $activity_rows = $pdo->query("
  SELECT *
  FROM (
    SELECT
      ar.created_at AS event_time,
      COALESCE(NULLIF(u.contact_name, ''), u.username, CONCAT('User #', ar.requested_by)) AS user_name,
      'Submitted App Request' AS action_name,
      CONCAT(
        UPPER(REPLACE(ar.request_type, '_', ' ')),
        ': ',
        ar.request_title
      ) AS action_details
    FROM app_requests ar
    LEFT JOIN users u ON u.id = ar.requested_by

    UNION ALL

    SELECT
      rr.created_at AS event_time,
      COALESCE(NULLIF(u.contact_name, ''), u.username, CONCAT('User #', rr.requested_by)) AS user_name,
      'Submitted RFQ' AS action_name,
      CONCAT(
        rr.request_title,
        ' (',
        rr.request_status,
        ')'
      ) AS action_details
    FROM rfq_requests rr
    LEFT JOIN users u ON u.id = rr.requested_by

    UNION ALL

    SELECT
      cpi.created_at AS event_time,
      COALESCE(NULLIF(u.contact_name, ''), u.username, 'Unknown') AS user_name,
      'Logged Customer Inquiry' AS action_name,
      CONCAT(
        cpi.customer_name,
        CASE
          WHEN cpi.company_name IS NOT NULL AND cpi.company_name <> ''
          THEN CONCAT(' (', cpi.company_name, ')')
          ELSE ''
        END
      ) AS action_details
    FROM customer_phone_inquiries cpi
    LEFT JOIN users u ON u.id = cpi.created_by
  ) AS activity_feed
  ORDER BY event_time DESC
  LIMIT {$activity_limit}
")->fetchAll();
  } catch (Throwable $e) {
    $activity_error = 'Unable to load activity log right now.';
  }
}
?>

<div class="card">
  <h2 style="margin-top:0;">Activity Log</h2>
  <p class="muted" style="margin-top:0;">
    Most recent user activity across key workflows (showing up to <?= (int)$activity_limit ?> records).
  </p>

  <?php if ($activity_error !== ''): ?>
    <div class="alert error" style="margin-bottom:14px;"><?= h($activity_error) ?></div>
  <?php endif; ?>

  <div style="overflow-x:auto;">
    <table style="min-width:900px;">
      <thead>
        <tr>
          <th>Date &amp; Time</th>
          <th>User</th>
          <th>Action</th>
          <th>Details</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($activity_rows)): ?>
          <tr>
            <td colspan="4" class="muted">No activity logged yet.</td>
          </tr>
        <?php endif; ?>
        <?php foreach ($activity_rows as $row): ?>
          <tr>
            <td style="white-space:nowrap;"><?= h($row['event_time']) ?></td>
            <td style="white-space:nowrap;"><?= h($row['user_name']) ?></td>
            <td style="white-space:nowrap;"><?= h($row['action_name']) ?></td>
            <td style="min-width:300px;"><?= h($row['action_details']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
