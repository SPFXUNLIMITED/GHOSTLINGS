<?php
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

require_once __DIR__ . '/project/db.php';
require_once __DIR__ . '/prospects_schema.php';
require_once __DIR__ . '/project/prospect_tools.php';

prospectsEnsureSchema($pdo);

if (empty($_SESSION['prospects_csrf'])) {
    $_SESSION['prospects_csrf'] = bin2hex(random_bytes(32));
}
$csrf = (string) $_SESSION['prospects_csrf'];
$adminId = (int) ($_SESSION['admin_id'] ?? 0);

function prospectAllowedStatuses(): array
{
    return array_keys(prospectStatuses());
}

function prospectCleanDateTimeInput(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d\TH:i', $value);
    if ($dt !== false) {
        return $dt->format('Y-m-d H:i:s');
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value);
    if ($dt !== false) {
        return $dt->format('Y-m-d H:i:s');
    }
    return null;
}

function prospectSplitContactName(string $contactName): array
{
    $contactName = trim($contactName);
    if ($contactName === '') {
        return ['', ''];
    }
    $parts = preg_split('/\s+/', $contactName, 2) ?: [];
    return [
        $parts[0] ?? '',
        $parts[1] ?? '',
    ];
}

function prospectFindDuplicate(PDO $pdo, int $prospectId, string $email, string $phone, string $company): ?array
{
    $conditions = [];
    $params = [':id' => $prospectId];

    if ($email !== '') {
        $conditions[] = 'LOWER(email) = LOWER(:email)';
        $params[':email'] = $email;
    }
    if ($phone !== '') {
        $conditions[] = 'phone = :phone';
        $params[':phone'] = $phone;
    }
    if ($company !== '') {
        $conditions[] = 'LOWER(company) = LOWER(:company)';
        $params[':company'] = $company;
    }

    if ($conditions === []) {
        return null;
    }

    $sql = "
        SELECT id, company, contact_name, email, phone
        FROM prospects
        WHERE is_archived = 0
          AND id != :id
          AND (" . implode(' OR ', $conditions) . ")
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function prospectFindCustomerDuplicate(PDO $pdo, string $email, string $phone, string $company): ?array
{
    $conditions = [];
    $params = [];

    if ($email !== '') {
        $conditions[] = 'LOWER(email) = LOWER(:email)';
        $params[':email'] = $email;
    }
    if ($phone !== '') {
        $conditions[] = 'phone = :phone';
        $params[':phone'] = $phone;
    }
    if ($company !== '') {
        $conditions[] = 'LOWER(company) = LOWER(:company)';
        $params[':company'] = $company;
    }

    if ($conditions === []) {
        return null;
    }

    $sql = "
        SELECT id, company, first_name, last_name, email, phone
        FROM customers
        WHERE " . implode(' OR ', $conditions) . "
        ORDER BY id ASC
        LIMIT 1
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

function prospectGetCustomerColumns(PDO $pdo): array
{
    try {
        $columns = $pdo->query("SHOW COLUMNS FROM customers")->fetchAll(PDO::FETCH_COLUMN);
        return is_array($columns) ? $columns : [];
    } catch (Throwable $e) {
        return [];
    }
}

$flashSuccess = '';
$flashError = '';
if (!empty($_SESSION['prospects_flash_success'])) {
    $flashSuccess = (string) $_SESSION['prospects_flash_success'];
    unset($_SESSION['prospects_flash_success']);
}
if (!empty($_SESSION['prospects_flash_error'])) {
    $flashError = (string) $_SESSION['prospects_flash_error'];
    unset($_SESSION['prospects_flash_error']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $postCsrf = trim((string) ($_POST['csrf'] ?? ''));
    $action = trim((string) ($_POST['action'] ?? ''));
    $queryStatus = trim((string) ($_POST['status_filter'] ?? 'all'));
    $querySearch = trim((string) ($_POST['q'] ?? ''));
    $redirectQs = http_build_query(array_filter([
        'status' => $queryStatus,
        'q' => $querySearch,
    ], static fn($value) => $value !== ''));

    if ($postCsrf === '' || !hash_equals($csrf, $postCsrf)) {
        $_SESSION['prospects_flash_error'] = 'Invalid security token.';
        header('Location: prospects.php' . ($redirectQs !== '' ? '?' . $redirectQs : ''));
        exit;
    }

    try {
        if ($action === 'save_prospect') {
            $prospectId = (int) ($_POST['prospect_id'] ?? 0);
            $company = prospectSanitizeField((string) ($_POST['company'] ?? ''));
            $contactName = prospectSanitizeField((string) ($_POST['contact_name'] ?? ''));
            $phone = prospectSanitizeField((string) ($_POST['phone'] ?? ''), 100);
            $email = strtolower(prospectSanitizeField((string) ($_POST['email'] ?? '')));
            $website = prospectSanitizeField((string) ($_POST['website'] ?? ''));
            $status = trim((string) ($_POST['status'] ?? 'new'));
            $notes = prospectSanitizeField((string) ($_POST['notes'] ?? ''), 10000);
            $rawTextDump = prospectSanitizeField((string) ($_POST['raw_text_dump'] ?? ''), 65000);
            $parseProvider = prospectSanitizeField((string) ($_POST['parse_provider'] ?? ''), 100);
            $parseConfidence = is_numeric($_POST['parse_confidence'] ?? null) ? (float) $_POST['parse_confidence'] : null;
            $parseErrors = prospectSanitizeField((string) ($_POST['parse_errors'] ?? ''), 3000);

            if (!in_array($status, prospectAllowedStatuses(), true)) {
                $status = 'new';
            }
            if ($company === '' && $contactName === '') {
                throw new RuntimeException('Company or contact name is required.');
            }
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                throw new RuntimeException('Invalid email format.');
            }

            $duplicate = prospectFindDuplicate($pdo, $prospectId, $email, $phone, $company);
            if ($duplicate !== null) {
                throw new RuntimeException('Duplicate prospect found (email, phone, or company).');
            }

            $previewPayload = json_encode([
                'company' => $company,
                'contact_name' => $contactName,
                'phone' => $phone,
                'email' => $email,
                'website' => $website,
                'status' => $status,
                'notes' => $notes,
            ], JSON_UNESCAPED_UNICODE);

            if ($prospectId > 0) {
                $stmt = $pdo->prepare("
                    UPDATE prospects
                    SET company = :company,
                        contact_name = :contact_name,
                        phone = :phone,
                        email = :email,
                        website = :website,
                        status = :status,
                        notes = :notes,
                        raw_text_dump = :raw_text_dump,
                        parse_preview_json = :parse_preview_json,
                        parse_confidence = :parse_confidence,
                        parse_provider = :parse_provider,
                        parse_errors = :parse_errors,
                        updated_by = :updated_by
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':company' => $company,
                    ':contact_name' => $contactName,
                    ':phone' => $phone !== '' ? $phone : null,
                    ':email' => $email !== '' ? $email : null,
                    ':website' => $website !== '' ? $website : null,
                    ':status' => $status,
                    ':notes' => $notes !== '' ? $notes : null,
                    ':raw_text_dump' => $rawTextDump !== '' ? $rawTextDump : null,
                    ':parse_preview_json' => $previewPayload,
                    ':parse_confidence' => $parseConfidence,
                    ':parse_provider' => $parseProvider !== '' ? $parseProvider : null,
                    ':parse_errors' => $parseErrors !== '' ? $parseErrors : null,
                    ':updated_by' => $adminId > 0 ? $adminId : null,
                    ':id' => $prospectId,
                ]);
                $_SESSION['prospects_flash_success'] = 'Prospect updated.';
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO prospects (
                        company, contact_name, phone, email, website, status, notes,
                        raw_text_dump, parse_preview_json, parse_confidence, parse_provider, parse_errors,
                        created_by, updated_by
                    ) VALUES (
                        :company, :contact_name, :phone, :email, :website, :status, :notes,
                        :raw_text_dump, :parse_preview_json, :parse_confidence, :parse_provider, :parse_errors,
                        :created_by, :updated_by
                    )
                ");
                $stmt->execute([
                    ':company' => $company,
                    ':contact_name' => $contactName,
                    ':phone' => $phone !== '' ? $phone : null,
                    ':email' => $email !== '' ? $email : null,
                    ':website' => $website !== '' ? $website : null,
                    ':status' => $status,
                    ':notes' => $notes !== '' ? $notes : null,
                    ':raw_text_dump' => $rawTextDump !== '' ? $rawTextDump : null,
                    ':parse_preview_json' => $previewPayload,
                    ':parse_confidence' => $parseConfidence,
                    ':parse_provider' => $parseProvider !== '' ? $parseProvider : null,
                    ':parse_errors' => $parseErrors !== '' ? $parseErrors : null,
                    ':created_by' => $adminId > 0 ? $adminId : null,
                    ':updated_by' => $adminId > 0 ? $adminId : null,
                ]);
                $_SESSION['prospects_flash_success'] = 'Prospect created.';
            }
        } elseif ($action === 'archive_prospect') {
            $prospectId = (int) ($_POST['prospect_id'] ?? 0);
            if ($prospectId <= 0) {
                throw new RuntimeException('Invalid prospect ID.');
            }
            $stmt = $pdo->prepare("
                UPDATE prospects
                SET is_archived = 1,
                    status = 'archived',
                    updated_by = :admin_id
                WHERE id = :id
            ");
            $stmt->execute([
                ':id' => $prospectId,
                ':admin_id' => $adminId > 0 ? $adminId : null,
            ]);
            $_SESSION['prospects_flash_success'] = 'Prospect archived.';
        } elseif ($action === 'log_interaction') {
            $prospectId = (int) ($_POST['prospect_id'] ?? 0);
            $type = trim((string) ($_POST['interaction_type'] ?? 'note'));
            $outcome = prospectSanitizeField((string) ($_POST['outcome'] ?? ''));
            $interactionNotes = prospectSanitizeField((string) ($_POST['interaction_notes'] ?? ''), 3000);
            $interactedAt = prospectCleanDateTimeInput((string) ($_POST['interacted_at'] ?? '')) ?? date('Y-m-d H:i:s');
            $newStatus = trim((string) ($_POST['new_status'] ?? ''));

            if ($prospectId <= 0) {
                throw new RuntimeException('Invalid prospect ID.');
            }
            if (!in_array($type, ['call', 'email', 'note', 'status_change'], true)) {
                throw new RuntimeException('Invalid interaction type.');
            }

            $pdo->beginTransaction();
            $insert = $pdo->prepare("
                INSERT INTO prospect_interactions (
                    prospect_id, interaction_type, outcome, interaction_notes, interacted_at, admin_id
                ) VALUES (
                    :prospect_id, :interaction_type, :outcome, :interaction_notes, :interacted_at, :admin_id
                )
            ");
            $insert->execute([
                ':prospect_id' => $prospectId,
                ':interaction_type' => $type,
                ':outcome' => $outcome !== '' ? $outcome : null,
                ':interaction_notes' => $interactionNotes !== '' ? $interactionNotes : null,
                ':interacted_at' => $interactedAt,
                ':admin_id' => $adminId > 0 ? $adminId : null,
            ]);

            if ($type === 'call') {
                $update = $pdo->prepare("UPDATE prospects SET last_called_at = :ts, updated_by = :admin_id WHERE id = :id");
                $update->execute([
                    ':ts' => $interactedAt,
                    ':id' => $prospectId,
                    ':admin_id' => $adminId > 0 ? $adminId : null,
                ]);
            } elseif ($type === 'email') {
                $update = $pdo->prepare("UPDATE prospects SET last_emailed_at = :ts, updated_by = :admin_id WHERE id = :id");
                $update->execute([
                    ':ts' => $interactedAt,
                    ':id' => $prospectId,
                    ':admin_id' => $adminId > 0 ? $adminId : null,
                ]);
            }

            if ($type === 'status_change' && in_array($newStatus, prospectAllowedStatuses(), true)) {
                $update = $pdo->prepare("UPDATE prospects SET status = :status, updated_by = :admin_id WHERE id = :id");
                $update->execute([
                    ':status' => $newStatus,
                    ':id' => $prospectId,
                    ':admin_id' => $adminId > 0 ? $adminId : null,
                ]);
            }

            $pdo->commit();
            $_SESSION['prospects_flash_success'] = 'Interaction logged.';
        } elseif ($action === 'convert_to_customer') {
            $prospectId = (int) ($_POST['prospect_id'] ?? 0);
            if ($prospectId <= 0) {
                throw new RuntimeException('Invalid prospect ID.');
            }

            $pdo->beginTransaction();

            $prospectStmt = $pdo->prepare("SELECT * FROM prospects WHERE id = :id LIMIT 1 FOR UPDATE");
            $prospectStmt->execute([':id' => $prospectId]);
            $prospect = $prospectStmt->fetch(PDO::FETCH_ASSOC);
            if (!$prospect) {
                throw new RuntimeException('Prospect not found.');
            }

            $existingMapStmt = $pdo->prepare("SELECT customer_id FROM prospect_conversion_map WHERE prospect_id = :prospect_id LIMIT 1 FOR UPDATE");
            $existingMapStmt->execute([':prospect_id' => $prospectId]);
            $existingCustomerId = (int) ($existingMapStmt->fetchColumn() ?: 0);
            if ($existingCustomerId > 0 || !empty($prospect['converted_at'])) {
                throw new RuntimeException('This prospect has already been converted.');
            }

            $email = trim((string) ($prospect['email'] ?? ''));
            $phone = trim((string) ($prospect['phone'] ?? ''));
            $company = trim((string) ($prospect['company'] ?? ''));
            $duplicateCustomer = prospectFindCustomerDuplicate($pdo, $email, $phone, $company);
            if ($duplicateCustomer !== null) {
                throw new RuntimeException('Conversion blocked: matching customer already exists (#' . (int) $duplicateCustomer['id'] . ').');
            }

            [$firstName, $lastName] = prospectSplitContactName((string) ($prospect['contact_name'] ?? ''));
            if ($firstName === '' && $company !== '') {
                $firstName = $company;
            }

            $availableCustomerCols = prospectGetCustomerColumns($pdo);
            if ($availableCustomerCols === []) {
                throw new RuntimeException('Unable to read customers table schema.');
            }
            $availableSet = array_fill_keys($availableCustomerCols, true);
            $customerValues = [
                'hubspot_contact_id' => 'prospect_' . bin2hex(random_bytes(10)),
                'first_name' => $firstName,
                'last_name' => $lastName,
                'company' => $company,
                'phone' => $phone,
                'email' => $email,
                'address' => '',
                'city' => '',
                'state' => '',
                'zip' => '',
                'country' => 'USA',
                'password_hash' => password_hash(bin2hex(random_bytes(16)), PASSWORD_DEFAULT),
                'last_updated' => null,
            ];

            $insertCols = [];
            $insertParams = [];
            foreach ($customerValues as $column => $value) {
                if (!isset($availableSet[$column])) {
                    continue;
                }
                $insertCols[] = $column;
                $insertParams[":" . $column] = $value;
            }
            if ($insertCols === []) {
                throw new RuntimeException('No compatible columns found for customer conversion.');
            }

            $placeholders = implode(', ', array_map(static fn($c) => ':' . $c, $insertCols));
            $insertCustomer = $pdo->prepare(
                'INSERT INTO customers (' . implode(', ', $insertCols) . ') VALUES (' . $placeholders . ')'
            );
            $insertCustomer->execute($insertParams);
            $customerId = (int) $pdo->lastInsertId();

            $updateProspect = $pdo->prepare("
                UPDATE prospects
                SET status = 'converted',
                    converted_customer_id = :customer_id,
                    converted_at = NOW(),
                    updated_by = :admin_id
                WHERE id = :id
            ");
            $updateProspect->execute([
                ':customer_id' => $customerId,
                ':admin_id' => $adminId > 0 ? $adminId : null,
                ':id' => $prospectId,
            ]);

            $insertMap = $pdo->prepare("
                INSERT INTO prospect_conversion_map (prospect_id, customer_id, converted_by)
                VALUES (:prospect_id, :customer_id, :converted_by)
            ");
            $insertMap->execute([
                ':prospect_id' => $prospectId,
                ':customer_id' => $customerId,
                ':converted_by' => $adminId > 0 ? $adminId : null,
            ]);

            $insertInteraction = $pdo->prepare("
                INSERT INTO prospect_interactions (
                    prospect_id, interaction_type, outcome, interaction_notes, interacted_at, admin_id
                ) VALUES (
                    :prospect_id, 'conversion', :outcome, :notes, NOW(), :admin_id
                )
            ");
            $insertInteraction->execute([
                ':prospect_id' => $prospectId,
                ':outcome' => 'Converted to customer',
                ':notes' => 'Created customer ID #' . $customerId,
                ':admin_id' => $adminId > 0 ? $adminId : null,
            ]);

            $pdo->commit();
            $_SESSION['prospects_flash_success'] = 'Prospect converted to customer #' . $customerId . '.';
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        $_SESSION['prospects_flash_error'] = $e->getMessage();
    }

    header('Location: prospects.php' . ($redirectQs !== '' ? '?' . $redirectQs : ''));
    exit;
}

$statusFilter = trim((string) ($_GET['status'] ?? 'all'));
$search = trim((string) ($_GET['q'] ?? ''));

$where = ['is_archived = 0'];
$params = [];
if ($statusFilter !== '' && $statusFilter !== 'all' && in_array($statusFilter, prospectAllowedStatuses(), true)) {
    $where[] = 'status = :status';
    $params[':status'] = $statusFilter;
} else {
    $statusFilter = 'all';
}
if ($search !== '') {
    $where[] = '(company LIKE :q OR contact_name LIKE :q OR email LIKE :q OR phone LIKE :q)';
    $params[':q'] = '%' . $search . '%';
}

$sql = 'SELECT * FROM prospects WHERE ' . implode(' AND ', $where) . ' ORDER BY created_at DESC';
$prospectStmt = $pdo->prepare($sql);
$prospectStmt->execute($params);
$prospects = $prospectStmt->fetchAll(PDO::FETCH_ASSOC);

$prospectIds = array_map(static fn(array $p): int => (int) ($p['id'] ?? 0), $prospects);
$interactionsByProspect = [];
if ($prospectIds !== []) {
    $placeholders = implode(', ', array_fill(0, count($prospectIds), '?'));
    $interactionStmt = $pdo->prepare("
        SELECT id, prospect_id, interaction_type, outcome, interaction_notes, interacted_at
        FROM prospect_interactions
        WHERE prospect_id IN ({$placeholders})
        ORDER BY interacted_at DESC, id DESC
    ");
    $interactionStmt->execute($prospectIds);
    foreach ($interactionStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $pid = (int) ($row['prospect_id'] ?? 0);
        if (!isset($interactionsByProspect[$pid])) {
            $interactionsByProspect[$pid] = [];
        }
        if (count($interactionsByProspect[$pid]) < 5) {
            $interactionsByProspect[$pid][] = $row;
        }
    }
}

$statusMap = prospectStatuses();
$pageTitle = 'Prospects | Ghost Laser';
$pageDescription = 'Cold calling prospect pipeline management.';
$headerRight = '<div class="flex items-center gap-3"><a href="prospect_notifications.php" class="text-sm text-zinc-400 hover:text-white transition-colors">Prospect Templates</a><a href="dashboard.php" class="text-sm text-zinc-400 hover:text-white transition-colors">&larr; Dashboard</a></div>';
$extraHead = <<<'HTML'
<style>
    .btn-glow { box-shadow: 0 0 20px rgba(6,182,212,0.35); }
    .btn-glow:hover { box-shadow: 0 0 30px rgba(6,182,212,0.55); }
    .modal-overlay { position: fixed; inset: 0; background: rgba(0,0,0,.65); backdrop-filter: blur(2px); display: none; align-items: center; justify-content: center; z-index: 60; padding: 1rem; }
    .modal-overlay.open { display: flex; }
    .modal-box { width: min(920px, 96vw); max-height: 92vh; overflow: auto; border: 1px solid rgb(63,63,70); background: rgba(24,24,27,.98); border-radius: 1rem; }
    .field { width: 100%; border: 1px solid rgb(63,63,70); background: rgb(9,9,11); color: #fff; border-radius: .5rem; padding: .55rem .75rem; font-size: .875rem; }
    .label { font-size: .72rem; letter-spacing: .06em; text-transform: uppercase; color: rgb(161,161,170); margin-bottom: .35rem; display: block; font-weight: 600; }
</style>
HTML;
require_once __DIR__ . '/templates/header.php';
?>
<main class="min-h-screen hero-grid pt-24 pb-16 px-4">
    <div class="max-w-7xl mx-auto space-y-7">
        <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6">
            <div class="flex flex-col gap-4 lg:flex-row lg:items-center lg:justify-between">
                <div>
                    <h1 class="text-3xl font-bold tracking-tight">Prospects</h1>
                    <p class="mt-2 text-zinc-400">Separate lead pipeline for cold calling sign companies.</p>
                </div>
                <div class="flex items-center gap-3">
                    <a href="prospect_notifications.php" class="rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm text-zinc-200 hover:border-cyan-500/50 hover:text-cyan-300">Prospect Templates</a>
                    <button type="button" onclick="openCreateModal()" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 btn-glow">Add Prospect</button>
                </div>
            </div>
        </section>

        <?php if ($flashSuccess !== ''): ?>
            <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-400"><?= htmlspecialchars($flashSuccess, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($flashError !== ''): ?>
            <div class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400"><?= htmlspecialchars($flashError, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-5">
            <form method="GET" action="prospects.php" class="grid gap-3 md:grid-cols-[220px_1fr_auto]">
                <select name="status" class="field">
                    <option value="all"<?= $statusFilter === 'all' ? ' selected' : '' ?>>All statuses</option>
                    <?php foreach ($statusMap as $statusKey => $statusLabel): ?>
                        <option value="<?= htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8') ?>"<?= $statusFilter === $statusKey ? ' selected' : '' ?>><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
                <input type="text" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>" class="field" placeholder="Search company, contact, phone, or email">
                <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950">Filter</button>
            </form>
        </section>

        <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-5">
            <?php if ($prospects === []): ?>
                <p class="text-sm text-zinc-500">No prospects found.</p>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-800">
                                <th class="pb-3 text-left text-zinc-500">Company</th>
                                <th class="pb-3 text-left text-zinc-500">Contact</th>
                                <th class="pb-3 text-left text-zinc-500">Status</th>
                                <th class="pb-3 text-left text-zinc-500">Last Called</th>
                                <th class="pb-3 text-left text-zinc-500">Last Emailed</th>
                                <th class="pb-3 text-right text-zinc-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-800/60">
                        <?php foreach ($prospects as $prospect): ?>
                            <?php
                                $pid = (int) ($prospect['id'] ?? 0);
                                $statusKey = (string) ($prospect['status'] ?? 'new');
                                $statusLabel = $statusMap[$statusKey] ?? ucfirst(str_replace('_', ' ', $statusKey));
                                $rowInteractions = $interactionsByProspect[$pid] ?? [];
                            ?>
                            <tr class="align-top">
                                <td class="py-3 pr-3">
                                    <div class="font-semibold text-white"><?= htmlspecialchars((string) ($prospect['company'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="text-xs text-zinc-500 mt-1"><?= htmlspecialchars((string) ($prospect['website'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td class="py-3 pr-3 text-zinc-300">
                                    <div><?= htmlspecialchars((string) ($prospect['contact_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="text-xs text-zinc-500 mt-1"><?= htmlspecialchars((string) ($prospect['phone'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                    <div class="text-xs text-zinc-500"><?= htmlspecialchars((string) ($prospect['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                </td>
                                <td class="py-3 pr-3 text-zinc-300"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="py-3 pr-3 text-zinc-400"><?= htmlspecialchars((string) ($prospect['last_called_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="py-3 pr-3 text-zinc-400"><?= htmlspecialchars((string) ($prospect['last_emailed_at'] ?? '—'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="py-3 text-right">
                                    <div class="inline-flex flex-wrap justify-end gap-2">
                                        <button
                                            type="button"
                                            class="rounded-md border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-xs text-zinc-300 hover:text-cyan-300"
                                            onclick='openEditModal(<?= htmlspecialchars(json_encode([
                                                'id' => $pid,
                                                'company' => (string) ($prospect['company'] ?? ''),
                                                'contact_name' => (string) ($prospect['contact_name'] ?? ''),
                                                'phone' => (string) ($prospect['phone'] ?? ''),
                                                'email' => (string) ($prospect['email'] ?? ''),
                                                'website' => (string) ($prospect['website'] ?? ''),
                                                'status' => (string) ($prospect['status'] ?? 'new'),
                                                'notes' => (string) ($prospect['notes'] ?? ''),
                                                'raw_text_dump' => (string) ($prospect['raw_text_dump'] ?? ''),
                                                'parse_provider' => (string) ($prospect['parse_provider'] ?? ''),
                                                'parse_confidence' => (string) ($prospect['parse_confidence'] ?? ''),
                                                'parse_errors' => (string) ($prospect['parse_errors'] ?? ''),
                                            ]), ENT_QUOTES, 'UTF-8') ?>)'
                                        >Edit</button>

                                        <?php if ((string) ($prospect['status'] ?? '') !== 'converted'): ?>
                                            <form method="POST" action="prospects.php" onsubmit="return confirm('Convert this prospect to a customer?');">
                                                <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="action" value="convert_to_customer">
                                                <input type="hidden" name="prospect_id" value="<?= $pid ?>">
                                                <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                                <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                                                <button type="submit" class="rounded-md border border-emerald-700/60 bg-emerald-950/30 px-3 py-1.5 text-xs text-emerald-300 hover:border-emerald-500/60">Convert to Customer</button>
                                            </form>
                                        <?php endif; ?>

                                        <form method="POST" action="prospects.php" onsubmit="return confirm('Archive this prospect?');">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="archive_prospect">
                                            <input type="hidden" name="prospect_id" value="<?= $pid ?>">
                                            <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                                            <button type="submit" class="rounded-md border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-xs text-zinc-400 hover:text-red-400">Archive</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <tr>
                                <td colspan="6" class="pb-4">
                                    <div class="rounded-xl border border-zinc-800 bg-zinc-950/60 p-4 mt-1">
                                        <div class="grid gap-4 lg:grid-cols-[1.1fr_1fr]">
                                            <div>
                                                <p class="text-xs uppercase tracking-wider text-zinc-500 mb-2">Notes</p>
                                                <p class="text-sm text-zinc-300 whitespace-pre-line"><?= htmlspecialchars((string) ($prospect['notes'] ?? 'No notes yet.'), ENT_QUOTES, 'UTF-8') ?></p>
                                            </div>
                                            <div>
                                                <p class="text-xs uppercase tracking-wider text-zinc-500 mb-2">Recent Interactions</p>
                                                <?php if ($rowInteractions === []): ?>
                                                    <p class="text-xs text-zinc-500">No interactions logged.</p>
                                                <?php else: ?>
                                                    <div class="space-y-2">
                                                        <?php foreach ($rowInteractions as $interaction): ?>
                                                            <div class="text-xs text-zinc-300 border border-zinc-800 rounded-lg px-2 py-1.5">
                                                                <div><span class="text-cyan-300 uppercase"><?= htmlspecialchars((string) $interaction['interaction_type'], ENT_QUOTES, 'UTF-8') ?></span> · <?= htmlspecialchars((string) $interaction['interacted_at'], ENT_QUOTES, 'UTF-8') ?></div>
                                                                <div class="text-zinc-400"><?= htmlspecialchars((string) ($interaction['outcome'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                                                <div class="text-zinc-500"><?= htmlspecialchars((string) ($interaction['interaction_notes'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                                            </div>
                                                        <?php endforeach; ?>
                                                    </div>
                                                <?php endif; ?>
                                            </div>
                                        </div>

                                        <form method="POST" action="prospects.php" class="mt-4 grid gap-2 md:grid-cols-5">
                                            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="action" value="log_interaction">
                                            <input type="hidden" name="prospect_id" value="<?= $pid ?>">
                                            <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
                                            <select name="interaction_type" class="field">
                                                <option value="call">Call</option>
                                                <option value="email">Email</option>
                                                <option value="note">Note</option>
                                                <option value="status_change">Status Change</option>
                                            </select>
                                            <input type="text" name="outcome" maxlength="255" placeholder="Outcome" class="field">
                                            <select name="new_status" class="field">
                                                <option value="">Status (optional)</option>
                                                <?php foreach ($statusMap as $statusKeyOption => $statusLabelOption): ?>
                                                    <option value="<?= htmlspecialchars($statusKeyOption, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabelOption, ENT_QUOTES, 'UTF-8') ?></option>
                                                <?php endforeach; ?>
                                            </select>
                                            <input type="datetime-local" name="interacted_at" class="field">
                                            <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-xs font-semibold text-zinc-950">Log</button>
                                            <div class="md:col-span-5">
                                                <textarea name="interaction_notes" rows="2" maxlength="3000" class="field" placeholder="Interaction notes"></textarea>
                                            </div>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>

<div id="prospectModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="prospectModalTitle">
    <div class="modal-box">
        <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
            <h2 id="prospectModalTitle" class="text-lg font-semibold text-white">Prospect</h2>
            <button type="button" class="rounded-md border border-zinc-700 px-3 py-1 text-xs text-zinc-300" onclick="closeProspectModal()">Close</button>
        </div>
        <form method="POST" action="prospects.php" id="prospectForm" class="p-5 space-y-4">
            <input type="hidden" name="csrf" value="<?= htmlspecialchars($csrf, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="action" value="save_prospect">
            <input type="hidden" name="prospect_id" id="form_prospect_id" value="0">
            <input type="hidden" name="status_filter" value="<?= htmlspecialchars($statusFilter, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="q" value="<?= htmlspecialchars($search, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="parse_provider" id="form_parse_provider" value="">
            <input type="hidden" name="parse_confidence" id="form_parse_confidence" value="">
            <input type="hidden" name="parse_errors" id="form_parse_errors" value="">

            <div class="grid gap-4 md:grid-cols-2">
                <div><label class="label">Company</label><input class="field" type="text" name="company" id="form_company" maxlength="255"></div>
                <div><label class="label">Contact</label><input class="field" type="text" name="contact_name" id="form_contact_name" maxlength="255"></div>
                <div><label class="label">Phone</label><input class="field" type="text" name="phone" id="form_phone" maxlength="100"></div>
                <div><label class="label">Email</label><input class="field" type="email" name="email" id="form_email" maxlength="255"></div>
                <div><label class="label">Website</label><input class="field" type="text" name="website" id="form_website" maxlength="255"></div>
                <div>
                    <label class="label">Status</label>
                    <select class="field" name="status" id="form_status">
                        <?php foreach ($statusMap as $statusKey => $statusLabel): ?>
                            <option value="<?= htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div>
                <label class="label">Notes</label>
                <textarea class="field" rows="4" name="notes" id="form_notes" maxlength="10000"></textarea>
            </div>
            <div>
                <label class="label">Smart Text Dump (AI Parse Preview)</label>
                <textarea class="field" rows="6" name="raw_text_dump" id="form_raw_text_dump" maxlength="65000" placeholder="Paste website/company text dump here."></textarea>
                <div class="mt-2 flex items-center gap-2">
                    <button type="button" id="parseBtn" onclick="parseTextDump()" class="rounded-md border border-cyan-500/40 bg-cyan-500/10 px-3 py-1.5 text-xs text-cyan-300 hover:bg-cyan-500/20">AI Parse &amp; Preview</button>
                    <span id="parseMeta" class="text-xs text-zinc-500"></span>
                </div>
            </div>

            <div class="pt-2 flex justify-end gap-3">
                <button type="button" onclick="closeProspectModal()" class="rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm text-zinc-300">Cancel</button>
                <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950">Save Prospect</button>
            </div>
        </form>
    </div>
</div>

<div id="parsePreviewModal" class="modal-overlay" role="dialog" aria-modal="true" aria-labelledby="parsePreviewTitle">
    <div class="modal-box">
        <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
            <h2 id="parsePreviewTitle" class="text-lg font-semibold text-white">AI Parse Preview</h2>
            <button type="button" class="rounded-md border border-zinc-700 px-3 py-1 text-xs text-zinc-300" onclick="closeParsePreview()">Close</button>
        </div>
        <div class="p-5 space-y-4">
            <p id="parsePreviewMeta" class="text-xs text-zinc-400"></p>
            <div class="grid gap-4 md:grid-cols-2">
                <div><label class="label">Company</label><input class="field" type="text" id="preview_company"></div>
                <div><label class="label">Contact</label><input class="field" type="text" id="preview_contact_name"></div>
                <div><label class="label">Phone</label><input class="field" type="text" id="preview_phone"></div>
                <div><label class="label">Email</label><input class="field" type="text" id="preview_email"></div>
                <div><label class="label">Website</label><input class="field" type="text" id="preview_website"></div>
                <div>
                    <label class="label">Status</label>
                    <select class="field" id="preview_status">
                        <?php foreach ($statusMap as $statusKey => $statusLabel): ?>
                            <option value="<?= htmlspecialchars($statusKey, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusLabel, ENT_QUOTES, 'UTF-8') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div>
                <label class="label">Notes (editable before apply)</label>
                <textarea id="preview_notes" class="field" rows="5"></textarea>
            </div>
            <p id="parsePreviewErrors" class="text-xs text-amber-300"></p>
            <div class="flex justify-end gap-3">
                <button type="button" onclick="closeParsePreview()" class="rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm text-zinc-300">Cancel</button>
                <button type="button" onclick="applyPreviewToForm()" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950">Apply to Form</button>
            </div>
        </div>
    </div>
</div>

<script>
const prospectsCsrf = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>;
let latestParseResult = null;

function openCreateModal() {
    document.getElementById('prospectModalTitle').textContent = 'Add Prospect';
    document.getElementById('prospectForm').reset();
    document.getElementById('form_prospect_id').value = '0';
    document.getElementById('form_status').value = 'new';
    document.getElementById('form_parse_provider').value = '';
    document.getElementById('form_parse_confidence').value = '';
    document.getElementById('form_parse_errors').value = '';
    document.getElementById('parseMeta').textContent = '';
    document.getElementById('prospectModal').classList.add('open');
}

function openEditModal(prospect) {
    document.getElementById('prospectModalTitle').textContent = 'Edit Prospect';
    document.getElementById('form_prospect_id').value = String(prospect.id || 0);
    document.getElementById('form_company').value = prospect.company || '';
    document.getElementById('form_contact_name').value = prospect.contact_name || '';
    document.getElementById('form_phone').value = prospect.phone || '';
    document.getElementById('form_email').value = prospect.email || '';
    document.getElementById('form_website').value = prospect.website || '';
    document.getElementById('form_status').value = prospect.status || 'new';
    document.getElementById('form_notes').value = prospect.notes || '';
    document.getElementById('form_raw_text_dump').value = prospect.raw_text_dump || '';
    document.getElementById('form_parse_provider').value = prospect.parse_provider || '';
    document.getElementById('form_parse_confidence').value = prospect.parse_confidence || '';
    document.getElementById('form_parse_errors').value = prospect.parse_errors || '';
    document.getElementById('parseMeta').textContent = prospect.parse_provider ? `Last parse: ${prospect.parse_provider} (${prospect.parse_confidence || ''}%)` : '';
    document.getElementById('prospectModal').classList.add('open');
}

function closeProspectModal() {
    document.getElementById('prospectModal').classList.remove('open');
}

async function parseTextDump() {
    const raw = document.getElementById('form_raw_text_dump').value.trim();
    const parseBtn = document.getElementById('parseBtn');
    if (!raw) {
        alert('Paste text to parse first.');
        return;
    }
    parseBtn.disabled = true;
    parseBtn.textContent = 'Parsing...';
    try {
        const res = await fetch('api/prospect-parse.php', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'X-CSRF-Token': prospectsCsrf
            },
            body: JSON.stringify({ raw_text: raw })
        });
        const data = await res.json();
        if (!res.ok) {
            throw new Error(data.error || 'Parse failed.');
        }
        latestParseResult = data;
        document.getElementById('preview_company').value = data.parsed_fields.company || '';
        document.getElementById('preview_contact_name').value = data.parsed_fields.contact_name || '';
        document.getElementById('preview_phone').value = data.parsed_fields.phone || '';
        document.getElementById('preview_email').value = data.parsed_fields.email || '';
        document.getElementById('preview_website').value = data.parsed_fields.website || '';
        document.getElementById('preview_status').value = data.parsed_fields.status || 'new';
        document.getElementById('preview_notes').value = data.parsed_fields.notes || '';
        document.getElementById('parsePreviewMeta').textContent = `Provider: ${data.provider || 'ai'} · Confidence: ${data.confidence || 0}%`;
        document.getElementById('parsePreviewErrors').textContent = Array.isArray(data.errors) && data.errors.length > 0 ? data.errors.join(' ') : '';
        document.getElementById('parsePreviewModal').classList.add('open');
    } catch (err) {
        alert(err.message || 'Parse failed.');
    } finally {
        parseBtn.disabled = false;
        parseBtn.textContent = 'AI Parse & Preview';
    }
}

function closeParsePreview() {
    document.getElementById('parsePreviewModal').classList.remove('open');
}

function applyPreviewToForm() {
    document.getElementById('form_company').value = document.getElementById('preview_company').value;
    document.getElementById('form_contact_name').value = document.getElementById('preview_contact_name').value;
    document.getElementById('form_phone').value = document.getElementById('preview_phone').value;
    document.getElementById('form_email').value = document.getElementById('preview_email').value;
    document.getElementById('form_website').value = document.getElementById('preview_website').value;
    document.getElementById('form_status').value = document.getElementById('preview_status').value;
    document.getElementById('form_notes').value = document.getElementById('preview_notes').value;

    if (latestParseResult) {
        document.getElementById('form_parse_provider').value = latestParseResult.provider || '';
        document.getElementById('form_parse_confidence').value = latestParseResult.confidence || '';
        document.getElementById('form_parse_errors').value = Array.isArray(latestParseResult.errors) ? latestParseResult.errors.join(' ') : '';
        document.getElementById('parseMeta').textContent = `Last parse: ${latestParseResult.provider || 'ai'} (${latestParseResult.confidence || 0}%)`;
    }
    closeParsePreview();
}

document.getElementById('prospectModal').addEventListener('click', (e) => {
    if (e.target.id === 'prospectModal') closeProspectModal();
});
document.getElementById('parsePreviewModal').addEventListener('click', (e) => {
    if (e.target.id === 'parsePreviewModal') closeParsePreview();
});
document.addEventListener('keydown', (e) => {
    if (e.key === 'Escape') {
        closeProspectModal();
        closeParsePreview();
    }
});
</script>
<?php require_once __DIR__ . '/templates/footer.php'; ?>
