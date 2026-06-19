<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_login();

$incoming_shipments = [
  [
    'order_date' => '2026-06-16',
    'expected_arrival' => '2026-06-21',
    'carrier' => 'Amazon Logistics',
    'tracking_number' => 'TBA781239104512',
    'item_description' => 'CO₂ laser tube replacement kit',
    'status' => 'In Transit',
  ],
  [
    'order_date' => '2026-06-14',
    'expected_arrival' => '2026-06-24',
    'carrier' => 'Alibaba Express',
    'tracking_number' => 'ALI-620499321-US',
    'item_description' => 'Linear rails and mounting brackets',
    'status' => 'Ordered',
  ],
  [
    'order_date' => '2026-06-10',
    'expected_arrival' => '2026-06-19',
    'carrier' => 'UPS',
    'tracking_number' => '1Z84Y2F90314566712',
    'item_description' => 'Servo motor driver boards (x4)',
    'status' => 'Delayed',
  ],
  [
    'order_date' => '2026-06-08',
    'expected_arrival' => '2026-06-15',
    'carrier' => 'DHL',
    'tracking_number' => 'JD0146000058743210',
    'item_description' => 'Fiber laser safety lenses and shields',
    'status' => 'Received',
  ],
  [
    'order_date' => '2026-06-17',
    'expected_arrival' => '2026-06-23',
    'carrier' => 'FedEx',
    'tracking_number' => '794813240159',
    'item_description' => 'Cooling pump assembly',
    'status' => 'In Transit',
  ],
];

$status_colors = [
  'Ordered' => ['#e0f2fe', '#075985'],
  'In Transit' => ['#fef3c7', '#92400e'],
  'Delayed' => ['#fee2e2', '#991b1b'],
  'Received' => ['#dcfce7', '#166534'],
];

render_header('Incoming Shipments');
?>

<div class="card page-header">
  <div class="page-header-body">
    <h1 style="margin:0;">Incoming Shipments</h1>
    <p class="muted" style="margin:6px 0 0;">Track incoming packages and parts from carriers and suppliers.</p>
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
      </tr>
    </thead>
    <tbody>
      <?php foreach ($incoming_shipments as $shipment): ?>
        <?php
          $status = (string)($shipment['status'] ?? 'Ordered');
          [$bg, $fg] = $status_colors[$status] ?? ['#e5e7eb', '#374151'];
        ?>
        <tr>
          <td><?= h($shipment['order_date']) ?></td>
          <td><?= h($shipment['expected_arrival']) ?></td>
          <td><?= h($shipment['carrier']) ?></td>
          <td><code><?= h($shipment['tracking_number']) ?></code></td>
          <td style="max-width:340px; white-space:normal;"><?= h($shipment['item_description']) ?></td>
          <td>
            <span style="display:inline-block; padding:4px 10px; border-radius:999px; font-size:12px; font-weight:600; background:<?= h($bg) ?>; color:<?= h($fg) ?>;">
              <?= h($status) ?>
            </span>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</div>

<?php render_footer(); ?>
