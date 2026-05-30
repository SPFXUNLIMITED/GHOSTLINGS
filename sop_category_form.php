<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require_login();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$errors = [];
$app_categories = [
  'project' => 'Project',
  'playbook' => 'Playbook',
  'document' => 'Document',
  'sop' => 'SOP',
];
$app_redirects = [
  'project' => 'projects.php',
  'playbook' => 'playbooks.php',
  'document' => 'documents.php',
  'sop' => 'sops.php',
];
$category = ['name' => '', 'description' => '', 'playbook' => 0, 'is_doc_category' => 0, 'is_sop_category' => 1, 'priority' => 'medium', 'owner_id' => current_user_id(), 'app_category' => 'sop'];

$all_users = is_admin() ? $pdo->query("SELECT id, username FROM users ORDER BY username")->fetchAll() : [];

if ($id) {
  $stmt = $pdo->prepare("SELECT id, name, description, playbook, is_doc_category, is_sop_category, priority, owner_id FROM projects WHERE id = ? AND is_sop_category = 1");
  $stmt->execute([$id]);
  $category = $stmt->fetch();
  if (!$category) { http_response_code(404); exit('SOP category not found'); }
  if (!empty($category['is_sop_category'])) {
    $category['app_category'] = 'sop';
  } elseif (!empty($category['is_doc_category'])) {
    $category['app_category'] = 'document';
  } elseif (!empty($category['playbook'])) {
    $category['app_category'] = 'playbook';
  } else {
    $category['app_category'] = 'project';
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name        = trim($_POST['name'] ?? '');
  $description = trim($_POST['description'] ?? '');
  $priority    = $_POST['priority'] ?? 'medium';
  $app_category = $_POST['app_category'] ?? ($category['app_category'] ?? 'sop');
  if (!in_array($priority, ['low','medium','high','critical'], true)) $priority = 'medium';
  if (!isset($app_categories[$app_category])) {
    $errors[] = "Invalid application category.";
    $app_category = 'sop';
  }

  if (is_admin()) {
    $owner_id = isset($_POST['owner_id']) && (int)$_POST['owner_id'] > 0 ? (int)$_POST['owner_id'] : null;
    if ($owner_id !== null) {
      $valid_ids = array_column($all_users, 'id');
      if (!in_array($owner_id, $valid_ids, false)) {
        $owner_id = null;
      }
    }
  } else {
    $owner_id = $id ? (int)$category['owner_id'] : current_user_id();
  }

  if ($name === '') $errors[] = "Name is required.";

  if (!$errors) {
    $playbook_flag = ($app_category === 'playbook') ? 1 : 0;
    $doc_flag = ($app_category === 'document') ? 1 : 0;
    $sop_flag = ($app_category === 'sop') ? 1 : 0;

    $category['priority'] = $priority;
    $category['owner_id'] = $owner_id;
    $category['app_category'] = $app_category;
    if ($id) {
      $stmt = $pdo->prepare("UPDATE projects SET name = ?, description = ?, playbook = ?, is_doc_category = ?, is_sop_category = ?, priority = ?, owner_id = ? WHERE id = ?");
      $stmt->execute([$name, $description ?: null, $playbook_flag, $doc_flag, $sop_flag, $priority, $owner_id, $id]);
    } else {
      $stmt = $pdo->prepare("INSERT INTO projects (name, description, playbook, is_doc_category, is_sop_category, priority, owner_id) VALUES (?, ?, ?, ?, ?, ?, ?)");
      $stmt->execute([$name, $description ?: null, $playbook_flag, $doc_flag, $sop_flag, $priority, $owner_id]);
    }
    header('Location: ' . $app_redirects[$app_category]);
    exit;
  }

  $category['name']        = $name;
  $category['description'] = $description;
  $category['priority']    = $priority;
  $category['owner_id']    = $owner_id;
  $category['app_category'] = $app_category;
}

render_header($id ? 'Edit SOP Category' : 'New SOP Category');
?>
<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h1 style="margin:0;"><?= $id ? 'Edit SOP Category' : 'New SOP Category' ?></h1>
    <a class="btn" href="sops.php">Back</a>
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
    <label>SOP category name</label>
    <input name="name" value="<?= h($category['name']) ?>" />

    <label>Description</label>
    <textarea name="description" rows="5"><?= h($category['description'] ?? '') ?></textarea>

    <label>Application</label>
    <select name="app_category">
      <?php foreach ($app_categories as $val => $label): ?>
        <option value="<?= h($val) ?>" <?= (($category['app_category'] ?? 'sop') === $val) ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>

    <label>Priority</label>
    <select name="priority">
      <?php foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'] as $val => $label): ?>
        <option value="<?= h($val) ?>" <?= ($category['priority'] === $val) ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>

    <?php if (is_admin()): ?>
    <label style="margin-top:10px;">Owner</label>
    <select name="owner_id">
      <option value="">— No owner —</option>
      <?php foreach ($all_users as $u): ?>
        <option value="<?= (int)$u['id'] ?>" <?= ((int)($category['owner_id'] ?? 0) === (int)$u['id']) ? 'selected' : '' ?>>
          <?= h($u['username']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <div class="row" style="margin-top:12px;">
      <button class="btn primary" type="submit"><?= $id ? 'Save Changes' : 'Create It' ?></button>
      <a class="btn" href="sops.php">Cancel</a>
    </div>
  </form>
</div>
<?php render_footer(); ?>
