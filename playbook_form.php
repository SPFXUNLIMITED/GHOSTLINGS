<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_login();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$errors = [];
$playbook = ['name' => '', 'description' => '', 'priority' => 'medium', 'owner_id' => current_user_id()];

$all_users = is_admin() ? $pdo->query("SELECT id, username FROM users ORDER BY username")->fetchAll() : [];

if ($id) {
  $stmt = $pdo->prepare("SELECT id, name, description, priority, owner_id FROM projects WHERE id = ? AND playbook = 1");
  $stmt->execute([$id]);
  $playbook = $stmt->fetch();
  if (!$playbook) { http_response_code(404); exit('Playbook not found'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name        = trim($_POST['name'] ?? '');
  $description = trim($_POST['description'] ?? '');
  $priority    = $_POST['priority'] ?? 'medium';
  if (!in_array($priority, ['low','medium','high','critical'], true)) $priority = 'medium';

  if (is_admin()) {
    $owner_id = isset($_POST['owner_id']) && (int)$_POST['owner_id'] > 0 ? (int)$_POST['owner_id'] : null;
    if ($owner_id !== null) {
      $valid_ids = array_column($all_users, 'id');
      if (!in_array($owner_id, $valid_ids, false)) {
        $owner_id = null;
      }
    }
  } else {
    $owner_id = $id ? (int)$playbook['owner_id'] : current_user_id();
  }

  if ($name === '') $errors[] = "Name is required.";

  if (!$errors) {
    $playbook['priority'] = $priority;
    $playbook['owner_id'] = $owner_id;
    if ($id) {
      $stmt = $pdo->prepare("UPDATE projects SET name = ?, description = ?, priority = ?, owner_id = ? WHERE id = ?");
      $stmt->execute([$name, $description ?: null, $priority, $owner_id, $id]);
    } else {
      $stmt = $pdo->prepare("INSERT INTO projects (name, description, playbook, is_doc_category, priority, owner_id) VALUES (?, ?, 1, 0, ?, ?)");
      $stmt->execute([$name, $description ?: null, $priority, $owner_id]);
    }
    header('Location: playbooks.php');
    exit;
  }

  $playbook['name']        = $name;
  $playbook['description'] = $description;
  $playbook['priority']    = $priority;
  $playbook['owner_id']    = $owner_id;
}

render_header($id ? 'Edit Playbook' : 'New Playbook');
?>
<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h1 style="margin:0;"><?= $id ? 'Edit Playbook' : 'New Playbook' ?></h1>
    <a class="btn" href="playbooks.php">Back</a>
  </div>

  <?php if ($errors): ?>
    <div class="alert error">
      <strong>Fix these:</strong>
      <ul>
        <?php foreach ($errors as $e): ?><li><?= h($e) ?></li><?php endforeach; ?>
      </ul>
    </div>
  <?php endif; ?>

  <form method="post">
    <label>Playbook name</label>
    <input name="name" value="<?= h($playbook['name']) ?>" />

    <label>Description</label>
    <textarea name="description" rows="5"><?= h($playbook['description'] ?? '') ?></textarea>

    <label>Priority</label>
    <select name="priority">
      <?php foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'] as $val => $label): ?>
        <option value="<?= h($val) ?>" <?= ($playbook['priority'] === $val) ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>

    <?php if (is_admin()): ?>
    <label style="margin-top:10px;">Owner</label>
    <select name="owner_id">
      <option value="">— No owner —</option>
      <?php foreach ($all_users as $u): ?>
        <option value="<?= (int)$u['id'] ?>" <?= ((int)($playbook['owner_id'] ?? 0) === (int)$u['id']) ? 'selected' : '' ?>>
          <?= h($u['username']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <div class="row" style="margin-top:12px;">
      <button class="btn primary" type="submit"><?= $id ? 'Save Changes' : 'Create It' ?></button>
      <a class="btn" href="playbooks.php">Cancel</a>
    </div>
  </form>
</div>
<?php render_footer(); ?>
