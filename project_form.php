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
  'document' => 'Document Category',
  'sop' => 'SOP Category',
];
$app_redirects = [
  'project' => 'projects.php',
  'playbook' => 'playbooks.php',
  'document' => 'documents.php',
  'sop' => 'sops.php',
];
$project = ['name' => '', 'description' => '', 'playbook' => 0, 'is_doc_category' => 0, 'is_sop_category' => 0, 'priority' => 'medium', 'owner_id' => current_user_id(), 'app_category' => 'project'];

$all_users = is_admin() ? $pdo->query("SELECT id, username FROM users ORDER BY username")->fetchAll() : [];

if ($id) {
  $stmt = $pdo->prepare("SELECT id, name, description, playbook, is_doc_category, is_sop_category, priority, owner_id FROM projects WHERE id = ?");
  $stmt->execute([$id]);
  $project = $stmt->fetch();
  if (!$project) { http_response_code(404); exit('Project not found'); }
  if (!empty($project['is_doc_category'])) {
    $project['app_category'] = 'document';
  } elseif (!empty($project['is_sop_category'])) {
    $project['app_category'] = 'sop';
  } elseif (!empty($project['playbook'])) {
    $project['app_category'] = 'playbook';
  } else {
    $project['app_category'] = 'project';
  }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim($_POST['name'] ?? '');
  $description = trim($_POST['description'] ?? '');
  $priority = $_POST['priority'] ?? 'medium';
  $app_category = $_POST['app_category'] ?? ($project['app_category'] ?? 'project');
  if (!in_array($priority, ['low','medium','high','critical'], true)) $priority = 'medium';
  if (!isset($app_categories[$app_category])) {
    $errors[] = "Invalid application category.";
    $app_category = 'project';
  }

  // Owner: admins may pick any user; others keep their own id (or existing owner)
  if (is_admin()) {
    $owner_id = isset($_POST['owner_id']) && (int)$_POST['owner_id'] > 0 ? (int)$_POST['owner_id'] : null;
    // Validate the selected owner exists
    if ($owner_id !== null) {
      $valid_ids = array_column($all_users, 'id');
      if (!in_array($owner_id, $valid_ids, false)) {
        $owner_id = null;
      }
    }
  } else {
    $owner_id = $id ? (int)$project['owner_id'] : current_user_id();
  }

  if ($name === '') $errors[] = "Name is required.";

  if (!$errors) {
    $playbook_flag = ($app_category === 'playbook') ? 1 : 0;
    $doc_flag = ($app_category === 'document') ? 1 : 0;
    $sop_flag = ($app_category === 'sop') ? 1 : 0;

    $project['priority'] = $priority;
    $project['owner_id'] = $owner_id;
    $project['app_category'] = $app_category;
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

  $project['name'] = $name;
  $project['description'] = $description;
  $project['priority'] = $priority;
  $project['owner_id'] = $owner_id;
  $project['app_category'] = $app_category;
}

render_header($id ? 'Edit Project' : 'New Project');
?>
<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h1 style="margin:0;"><?= $id ? 'Edit Project' : 'New Project' ?></h1>
    <a class="btn" href="projects.php">Back</a>
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
    <label>Project name</label>
    <input name="name" value="<?= h($project['name']) ?>" />

    <label>Description</label>
    <textarea name="description" rows="5"><?= h($project['description'] ?? '') ?></textarea>

    <label>Application category</label>
    <select name="app_category">
      <?php foreach ($app_categories as $val => $label): ?>
        <option value="<?= h($val) ?>" <?= (($project['app_category'] ?? 'project') === $val) ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>

    <label>Priority</label>
    <select name="priority">
      <?php foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'] as $val => $label): ?>
        <option value="<?= h($val) ?>" <?= ($project['priority'] === $val) ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>

    <?php if (is_admin()): ?>
    <label style="margin-top:10px;">Owner</label>
    <select name="owner_id">
      <option value="">— No owner —</option>
      <?php foreach ($all_users as $u): ?>
        <option value="<?= (int)$u['id'] ?>" <?= ((int)($project['owner_id'] ?? 0) === (int)$u['id']) ? 'selected' : '' ?>>
          <?= h($u['username']) ?>
        </option>
      <?php endforeach; ?>
    </select>
    <?php endif; ?>

    <div class="row" style="margin-top:12px;">
      <button class="btn primary" type="submit"><?= $id ? 'Save Changes' : 'Create It' ?></button>
      <a class="btn" href="projects.php">Cancel</a>
    </div>
  </form>
</div>
<?php render_footer(); ?>