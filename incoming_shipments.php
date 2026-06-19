<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_login();

$status_options = ['Ordered', 'In Transit', 'Delayed', 'Received'];
$status_colors = [
  'Ordered' => ['#e0f2fe', '#075985'],
  'In Transit' => ['#fef3c7', '#92400e'],
  'Delayed' => ['#fee2e2', '#991b1b'],
  'Received' => ['#dcfce7', '#166534'],
];

$incoming_errors = [];
$incoming_success = '';

$pdo->exec("
  CREATE TABLE IF NOT EXISTS incoming_shipments (
    id               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    order_date       DATE NOT NULL,
    expected_arrival DATE NOT NULL,
    carrier          VARCHAR(120) NOT NULL,
    tracking_number  VARCHAR(160) NOT NULL,
    item_description TEXT NOT NULL,
    status           VARCHAR(30) NOT NULL DEFAULT 'Ordered',
    created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_incoming_shipments_expected_arrival (expected_arrival),
    KEY idx_incoming_shipments_status (status)
  ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
");

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $action = trim((string)($_POST['action'] ?? ''));
  $order_date = trim((string)($_POST['order_date'] ?? ''));
  $expected_arrival = trim((string)($_POST['expected_arrival'] ?? ''));
  $carrier = trim((string)($_POST['carrier'] ?? ''));
  $tracking_number = trim((string)($_POST['tracking_number'] ?? ''));
  $item_description = trim((string)($_POST['item_description'] ?? ''));
  $status = trim((string)($_POST['status'] ?? ''));

  if ($order_date === '') {
    $incoming_errors[] = 'Order Date is required.';
  }
  if ($expected_arrival === '') {
    $incoming_errors[] = 'Expected Arrival is required.';
  }
  if ($carrier === '') {
    $incoming_errors[] = 'Carrier is required.';
  }
  if ($tracking_number === '') {
    $incoming_errors[] = 'Tracking Number is required.';
  }
  if ($item_description === '') {
    $incoming_errors[] = 'Item Description is required.';
  }
  if (!in_array($status, $status_options, true)) {
    $incoming_errors[] = 'Select a valid shipment status.';
  }

  if ($order_date !== '' && strtotime($order_date) === false) {
    $incoming_errors[] = 'Order Date must be a valid date.';
  }
  if ($expected_arrival !== '' && strtotime($expected_arrival) === false) {
    $incoming_errors[] = 'Expected Arrival must be a valid date.';
  }

  if (strlen($carrier) > 120) {
    $incoming_errors[] = 'Carrier is too long.';
  }
  if (strlen($tracking_number) > 160) {
    $incoming_errors[] = 'Tracking Number is too long.';
  }
  if (strlen($item_description) > 65535) {
    $incoming_errors[] = 'Item Description is too long.';
  }

  if (empty($incoming_errors)) {
    try {
      if ($action === 'edit_shipment') {
        $edit_id_raw = trim((string)($_POST['edit_id'] ?? ''));
        if ($edit_id_raw === '' || !ctype_digit($edit_id_raw)) {
          $incoming_errors[] = 'Invalid shipment selected for edit.';
        } else {
          $edit_id = (int)$edit_id_raw;
          if ($edit_id <= 0) {
            $incoming_errors[] = 'Invalid shipment selected for edit.';
          } else {
            $stmt = $pdo->prepare(
              "UPDATE incoming_shipments
               SET order_date = :order_date,
                   expected_arrival = :expected_arrival,
                   carrier = :carrier,
                   tracking_number = :tracking_number,
                   item_description = :item_description,
                   status = :status
               WHERE id = :id"
            );
            $stmt->execute([
              ':order_date' => $order_date,
              ':expected_arrival' => $expected_arrival,
              ':carrier' => $carrier,
              ':tracking_number' => $tracking_number,
              ':item_description' => $item_description,
              ':status' => $status,
              ':id' => $edit_id,
            ]);

            if ($stmt->rowCount() === 0) {
              $exists_stmt = $pdo->prepare("SELECT id FROM incoming_shipments WHERE id = ? LIMIT 1");
              $exists_stmt->execute([$edit_id]);
              if ($exists_stmt->fetch() === false) {
                $incoming_errors[] = 'Could not find shipment to edit.';
              } else {
                $incoming_success = 'Incoming shipment updated.';
              }
            } else {
              $incoming_success = 'Incoming shipment updated.';
            }
          }
        }
      } else {
        $stmt = $pdo->prepare(
          "INSERT INTO incoming_shipments
             (order_date, expected_arrival, carrier, tracking_number, item_description, status)
           VALUES
             (:order_date, :expected_arrival, :carrier, :tracking_number, :item_description, :status)"
        );
        $stmt->execute([
          ':order_date' => $order_date,
          ':expected_arrival' => $expected_arrival,
          ':carrier' => $carrier,
          ':tracking_number' => $tracking_number,
          ':item_description' => $item_description,
          ':status' => $status,
        ]);
        $incoming_success = 'Incoming shipment added.';
      }
    } catch (Throwable $e) {
      $incoming_errors[] = 'Unable to save shipment right now. Please try again.';
    }
  }
}

$incoming_shipments_stmt = $pdo->query(
  "SELECT id, order_date, expected_arrival, carrier, tracking_number, item_description, status
   FROM incoming_shipments
   ORDER BY order_date DESC, id DESC"
);
$incoming_shipments = $incoming_shipments_stmt->fetchAll();

$hero_total_incoming = count($incoming_shipments);
$hero_in_transit = 0;
$hero_delayed = 0;
$hero_received = 0;

foreach ($incoming_shipments as $shipment) {
  $shipment_status = (string)($shipment['status'] ?? 'Ordered');
  if ($shipment_status === 'In Transit') {
    $hero_in_transit++;
  } elseif ($shipment_status === 'Delayed') {
    $hero_delayed++;
  } elseif ($shipment_status === 'Received') {
    $hero_received++;
  }
}

render_header('Incoming Shipments');
?>

<?php foreach ($incoming_errors as $incoming_error): ?>
  <div class="alert" style="border-color:#fecaca; background:#fef2f2; color:#991b1b;"><?= h($incoming_error) ?></div>
<?php endforeach; ?>

<?php if ($incoming_success !== ''): ?>
  <div class="alert" style="border-color:#bbf7d0; background:#f0fdf4; color:#166534;"><?= h($incoming_success) ?></div>
<?php endif; ?>

<div class="card laser-rfq-hero page-header">
  <div class="laser-rfq-hero-beams" aria-hidden="true">
    <span class="laser-rfq-beam laser-rfq-beam-1"></span>
    <span class="laser-rfq-beam laser-rfq-beam-2"></span>
    <span class="laser-rfq-beam laser-rfq-beam-3"></span>
  </div>
  <div class="laser-rfq-hero-glow" aria-hidden="true"></div>
  <div class="page-header-body laser-rfq-hero-body">
    <span class="laser-rfq-hero-tag">🚚 Logistics Control Center</span>
    <h1>
      Incoming Shipments
      <span class="laser-rfq-hero-count" aria-label="Total incoming shipments: <?= (int)$hero_total_incoming ?>">(<?= (int)$hero_total_incoming ?>)</span>
    </h1>
    <p class="muted">Track incoming packages, monitor delivery risks, and keep critical parts flowing into operations.</p>
    <ul class="laser-rfq-hero-pills" aria-label="Incoming shipment highlights">
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">📦</span> Live inbound visibility</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">🛰️</span> Carrier tracking snapshots</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">⚠️</span> Delay escalation at a glance</li>
      <li class="laser-rfq-hero-pill"><span aria-hidden="true">✅</span> Received status confirmation</li>
    </ul>
    <div class="laser-rfq-hero-stats" aria-label="Incoming shipment summary">
      <div class="laser-rfq-hero-stat">
        <strong><?= (int)$hero_total_incoming ?></strong>
        <span>Total Incoming</span>
      </div>
      <div class="laser-rfq-hero-stat">
        <strong><?= (int)$hero_in_transit ?></strong>
        <span>In Transit</span>
      </div>
      <div class="laser-rfq-hero-stat">
        <strong><?= (int)$hero_delayed ?></strong>
        <span>Delayed</span>
      </div>
      <div class="laser-rfq-hero-stat">
        <strong><?= (int)$hero_received ?></strong>
        <span>Received</span>
      </div>
    </div>
  </div>
  <div class="laser-rfq-hero-actions">
    <button type="button" class="btn primary" id="incoming-new-btn">+ New Shipment</button>
  </div>
</div>

<div class="card" style="padding:0; overflow-x:auto;">
  <table>
    <thead>
      <tr>
        <th>Order Date</th>
        <th>Expected Arrival</th>
        <th>Carrier</th>
        <th>Tracking Number</th>
        <th>Item Description</th>
        <th>Status</th>
        <th>Actions</th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($incoming_shipments as $shipment): ?>
        <?php
          $status = (string)($shipment['status'] ?? 'Ordered');
          [$bg, $fg] = $status_colors[$status] ?? ['#e5e7eb', '#374151'];
          $shipment_json = json_encode([
            'id' => (int)($shipment['id'] ?? 0),
            'order_date' => (string)($shipment['order_date'] ?? ''),
            'expected_arrival' => (string)($shipment['expected_arrival'] ?? ''),
            'carrier' => (string)($shipment['carrier'] ?? ''),
            'tracking_number' => (string)($shipment['tracking_number'] ?? ''),
            'item_description' => (string)($shipment['item_description'] ?? ''),
            'status' => $status,
          ], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
        ?>
        <tr>
          <td><?= h((string)$shipment['order_date']) ?></td>
          <td><?= h((string)$shipment['expected_arrival']) ?></td>
          <td><?= h((string)$shipment['carrier']) ?></td>
          <td><code><?= h((string)$shipment['tracking_number']) ?></code></td>
          <td style="max-width:340px; white-space:normal;"><?= h((string)$shipment['item_description']) ?></td>
          <td>
            <span style="display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; background:<?= h($bg) ?>; color:<?= h($fg) ?>;">
              <?= h($status) ?>
            </span>
          </td>
          <td class="incoming-actions">
            <button type="button" class="btn incoming-edit-btn" data-shipment="<?= (string)$shipment_json ?>">Edit</button>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<div id="incoming-modal" role="dialog" aria-modal="true" aria-labelledby="incoming-modal-title" aria-hidden="true">
  <div class="incoming-modal-backdrop" id="incoming-modal-backdrop"></div>
  <div class="incoming-modal-shell">
    <div class="incoming-modal-header">
      <span aria-hidden="true">📦</span>
      <h2 id="incoming-modal-title" class="incoming-modal-title">Add Incoming Shipment</h2>
      <button type="button" class="incoming-modal-close" id="incoming-modal-close" aria-label="Close">&times;</button>
    </div>
    <form method="post" action="incoming_shipments.php" id="incoming-form">
      <div class="incoming-modal-body">
        <input type="hidden" name="action" id="incoming-action" value="add_shipment" />
        <input type="hidden" name="edit_id" id="incoming-edit-id" value="" />

        <div class="form-grid">
          <div>
            <label for="incoming-order-date">Order Date</label>
            <input id="incoming-order-date" type="date" name="order_date" required />
          </div>
          <div>
            <label for="incoming-expected-arrival">Expected Arrival</label>
            <input id="incoming-expected-arrival" type="date" name="expected_arrival" required />
          </div>
          <div>
            <label for="incoming-carrier">Carrier</label>
            <input id="incoming-carrier" type="text" name="carrier" maxlength="120" required />
          </div>
          <div>
            <label for="incoming-tracking-number">Tracking Number</label>
            <input id="incoming-tracking-number" type="text" name="tracking_number" maxlength="160" required />
          </div>
          <div style="grid-column:1/-1;">
            <label for="incoming-item-description">Item Description</label>
            <textarea id="incoming-item-description" name="item_description" rows="3" maxlength="1000" required></textarea>
          </div>
          <div>
            <label for="incoming-status">Status</label>
            <select id="incoming-status" name="status" required>
              <?php foreach ($status_options as $status_option): ?>
                <option value="<?= h($status_option) ?>"><?= h($status_option) ?></option>
              <?php endforeach; ?>
            </select>
          </div>
        </div>
      </div>
      <div class="incoming-modal-footer">
        <button type="button" class="btn" id="incoming-modal-cancel">Cancel</button>
        <button type="submit" class="btn primary" id="incoming-modal-submit">Save Shipment</button>
      </div>
    </form>
  </div>
</div>

<style>
.incoming-actions { white-space:nowrap; }
.incoming-actions .btn { font-size:0.8em; padding:3px 9px; }

#incoming-modal {
  position:fixed; inset:0; z-index:9000; display:none;
}
#incoming-modal.open { display:block; }

.incoming-modal-backdrop {
  position:absolute; inset:0;
  background:rgba(15, 23, 42, 0.72);
  backdrop-filter:blur(4px);
  -webkit-backdrop-filter:blur(4px);
}

.incoming-modal-shell {
  position:absolute;
  top:50%; left:50%;
  transform:translate(-50%, -50%);
  width:min(620px, calc(100vw - 32px));
  max-height:calc(100vh - 48px);
  display:flex; flex-direction:column;
  background:#fff;
  border-radius:16px;
  box-shadow:0 32px 80px rgba(0, 0, 0, 0.4), 0 0 0 1px rgba(0, 0, 0, 0.08);
  overflow:hidden;
}

.incoming-modal-header {
  padding:20px 24px 14px;
  border-bottom:1px solid #e2e8f0;
  display:flex; align-items:center; gap:12px;
}

.incoming-modal-title {
  margin:0;
  font-size:1.15em;
  font-weight:700;
  color:#0f172a;
}

.incoming-modal-close {
  margin-left:auto;
  width:30px; height:30px;
  border:none;
  border-radius:50%;
  background:#f1f5f9;
  color:#64748b;
  font-size:18px;
  line-height:1;
  cursor:pointer;
}
.incoming-modal-close:hover { background:#e2e8f0; }

.incoming-modal-body {
  padding:20px 24px;
  overflow-y:auto;
  flex:1;
}

.incoming-modal-footer {
  padding:14px 24px;
  border-top:1px solid #e2e8f0;
  display:flex;
  justify-content:flex-end;
  gap:8px;
}
</style>

<script>
(function () {
  'use strict';

  var modal = document.getElementById('incoming-modal');
  var backdrop = document.getElementById('incoming-modal-backdrop');
  var addBtn = document.getElementById('incoming-new-btn');
  var closeBtn = document.getElementById('incoming-modal-close');
  var cancelBtn = document.getElementById('incoming-modal-cancel');
  var form = document.getElementById('incoming-form');
  var title = document.getElementById('incoming-modal-title');
  var submitBtn = document.getElementById('incoming-modal-submit');
  var actionInput = document.getElementById('incoming-action');
  var editIdInput = document.getElementById('incoming-edit-id');

  var orderDateInput = document.getElementById('incoming-order-date');
  var expectedArrivalInput = document.getElementById('incoming-expected-arrival');
  var carrierInput = document.getElementById('incoming-carrier');
  var trackingInput = document.getElementById('incoming-tracking-number');
  var itemDescriptionInput = document.getElementById('incoming-item-description');
  var statusInput = document.getElementById('incoming-status');

  function openModal() {
    modal.classList.add('open');
    modal.removeAttribute('aria-hidden');
    orderDateInput.focus();
  }

  function closeModal() {
    modal.classList.remove('open');
    modal.setAttribute('aria-hidden', 'true');
  }

  function resetFormForNew() {
    form.reset();
    title.textContent = 'Add Incoming Shipment';
    submitBtn.textContent = 'Save Shipment';
    actionInput.value = 'add_shipment';
    editIdInput.value = '';
    statusInput.value = 'Ordered';
  }

  function setFormForEdit(shipment) {
    title.textContent = 'Edit Incoming Shipment';
    submitBtn.textContent = 'Update Shipment';
    actionInput.value = 'edit_shipment';
    editIdInput.value = shipment.id || '';
    orderDateInput.value = shipment.order_date || '';
    expectedArrivalInput.value = shipment.expected_arrival || '';
    carrierInput.value = shipment.carrier || '';
    trackingInput.value = shipment.tracking_number || '';
    itemDescriptionInput.value = shipment.item_description || '';
    statusInput.value = shipment.status || 'Ordered';
  }

  addBtn.addEventListener('click', function () {
    resetFormForNew();
    openModal();
  });

  closeBtn.addEventListener('click', closeModal);
  cancelBtn.addEventListener('click', closeModal);
  backdrop.addEventListener('click', closeModal);

  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && modal.classList.contains('open')) {
      closeModal();
    }
  });

  document.addEventListener('click', function (event) {
    var editBtn = event.target.closest('.incoming-edit-btn');
    if (!editBtn) {
      return;
    }

    try {
      var shipment = JSON.parse(editBtn.dataset.shipment || '{}');
      setFormForEdit(shipment);
      openModal();
    } catch (error) {
      console.error('Incoming shipment parse error:', error);
      alert('The shipment data is invalid. Please refresh the page and try again.');
    }
  });
})();
</script>

<?php render_footer(); ?>
