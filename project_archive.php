<?php
require __DIR__ . '/db.php';
require __DIR__ . '/auth.php';
require_login();

$id     = isset($_GET['id'])     ? (int)$_GET['id']     : 0;
$action = isset($_GET['action']) ? $_GET['action']       : 'archive';

if (!$id || !in_array($action, ['archive', 'unarchive'], true)) {
  header('Location: index.php');
  exit;
}

$archived = ($action === 'archive') ? 1 : 0;

$stmt = $pdo->prepare("UPDATE projects SET archived = ? WHERE id = ?");
$stmt->execute([$archived, $id]);

if ($action === 'unarchive') {
  $redirect = 'archives.php';
} else {
  // Redirect back to the appropriate list based on project type
  $row = $pdo->prepare("SELECT playbook FROM projects WHERE id = ?");
  $row->execute([$id]);
  $proj = $row->fetch();
  $redirect = ($proj && $proj['playbook']) ? 'playbooks.php' : 'index.php';
}

header('Location: ' . $redirect);
exit;
