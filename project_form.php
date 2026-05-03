<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$errors = [];
$project = ['name' => '', 'description' => '', 'playbook' => 0, 'priority' => 'medium'];

if ($id) {
  $stmt = $pdo->prepare("SELECT id, name, description, playbook, priority FROM projects WHERE id = ?");
  $stmt->execute([$id]);
  $project = $stmt->fetch();
  if (!$project) { http_response_code(404); exit('Project not found'); }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
  $name = trim($_POST['name'] ?? '');
  $description = trim($_POST['description'] ?? '');
  $priority = $_POST['priority'] ?? 'medium';
  if (!in_array($priority, ['low','medium','high','critical'], true)) $priority = 'medium';
  
  // ADD THIS LINE HERE
  $playbook = isset($_POST['playbook']) ? 1 : 0;
  $project['playbook'] = $playbook;

  if ($name === '') $errors[] = "Name is required.";

  if (!$errors) {
    $project['priority'] = $priority;
    if ($id) {
      $stmt = $pdo->prepare("UPDATE projects SET name = ?, description = ?, playbook = ?, priority = ? WHERE id = ?");
      $stmt->execute([$name, $description ?: null, $playbook, $priority, $id]);
    } else {
      $stmt = $pdo->prepare("INSERT INTO projects (name, description, playbook, priority) VALUES (?, ?, ?, ?)");
      $stmt->execute([$name, $description ?: null, $playbook, $priority]);
    }
    header('Location: index.php');
    exit;
  }

  $project['name'] = $name;
  $project['description'] = $description;
  $project['priority'] = $priority;
}

render_header($id ? 'Edit Project' : 'New Project');
?>
<div class="card">
  <div class="row" style="justify-content:space-between; align-items:center;">
    <h1 style="margin:0;"><?= $id ? 'Edit Project' : 'New Project / New Playbook' ?></h1>
    <a class="btn" href="index.php">Back</a>
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

    <label>Priority</label>
    <select name="priority">
      <?php foreach (['low' => 'Low', 'medium' => 'Medium', 'high' => 'High', 'critical' => 'Critical'] as $val => $label): ?>
        <option value="<?= h($val) ?>" <?= ($project['priority'] === $val) ? 'selected' : '' ?>><?= h($label) ?></option>
      <?php endforeach; ?>
    </select>
	
	<label style="display:flex; gap:10px; align-items:center; margin-top:10px;">
	  <span>Playbook project:</span>
	  <input type="checkbox" name="playbook" value="1" <?= !empty($project['playbook']) ? 'checked' : '' ?>>
	</label>

    <div class="row" style="margin-top:12px;">
      <button class="btn primary" type="submit"><?= $id ? 'Save Changes' : 'Create It' ?></button>
      <a class="btn" href="index.php">Cancel</a>
    </div>
  </form>
</div>
<?php render_footer(); ?>