<?php
session_start();

if (empty($_SESSION['admin_id'])) {
    header('Location: admin-login.php');
    exit;
}

require_once __DIR__ . '/project/db.php';

/**
 * Supported notification tags and their exact database sources.
 */
function getNotificationTagDefinitions(): array
{
    return [
        '{client_name}' => [
            'table' => 'customers',
            'columns' => ['first_name', 'last_name'],
            'description' => 'Customer first and last name combined.',
        ],
        '{client_address}' => [
            'table' => 'customers',
            'columns' => ['address', 'city', 'state', 'zip'],
            'description' => 'Customer service address combined into one line.',
        ],
        '{company_name}' => [
            'table' => 'company_settings',
            'columns' => ['company_name'],
            'description' => 'Your company name (editable in Custom Tags section).',
        ],
        '{company_phone}' => [
            'table' => 'company_settings',
            'columns' => ['company_phone'],
            'description' => 'Your company phone number (editable in Custom Tags section).',
        ],
        '{appointment_date}' => [
            'table' => 'service_requests',
            'columns' => ['promised_service_date'],
            'description' => 'Scheduled appointment date.',
        ],
        '{appointment_time}' => [
            'table' => 'service_route_stops',
            'columns' => ['arrival_window_start'],
            'description' => 'Arrival window start time.',
        ],
        '{appointment_end_time}' => [
            'table' => 'service_route_stops',
            'columns' => ['arrival_window_end'],
            'description' => 'Arrival window end time.',
        ],
        '{service_name}' => [
            'table' => 'service_requests',
            'columns' => ['services'],
            'description' => 'Comma-separated list of selected service names.',
        ],
        '{company_website}' => [
            'table' => 'company_settings',
            'columns' => ['company_website'],
            'description' => 'Your company website URL (editable in Custom Tags section).',
        ],
        '{customer_name}' => [
            'table' => 'customers',
            'columns' => ['first_name', 'last_name'],
            'description' => 'Customer full name (maintenance context).',
        ],
        '{last_service_date}' => [
            'table' => 'recurring_service_customers',
            'columns' => ['last_serviced_date'],
            'description' => 'Date the customer was last serviced.',
        ],
        '{next_service_date}' => [
            'table' => 'recurring_service_customers',
            'columns' => ['next_due_date'],
            'description' => 'Date the customer\'s next service is due.',
        ],
        '{admin_name}' => [
            'table' => 'session',
            'columns' => ['admin_username'],
            'description' => 'Name of the logged-in admin sending the notification.',
        ],
    ];
}

function getNotificationTemplateTags(string $template): array
{
    preg_match_all('/\{[a-z0-9_]+\}/i', $template, $matches);

    return array_values(array_unique($matches[0] ?? []));
}

function getUnsupportedNotificationTags(string $template): array
{
    return array_values(array_diff(
        getNotificationTemplateTags($template),
        array_keys(getNotificationTagDefinitions())
    ));
}

function ensureCompanySettingsTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS company_settings (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            company_name VARCHAR(255) NOT NULL DEFAULT '',
            company_phone VARCHAR(100) NOT NULL DEFAULT '',
            company_website VARCHAR(255) NOT NULL DEFAULT ''
        )
    ");

    $count = (int) $pdo->query("SELECT COUNT(*) FROM company_settings WHERE id = 1")->fetchColumn();
    if ($count === 0) {
        $pdo->exec("INSERT INTO company_settings (id, company_name, company_phone, company_website) VALUES (1, '', '', '')");
    }
}

function getCompanySettings(PDO $pdo): array
{
    $defaults = ['company_name' => '', 'company_phone' => '', 'company_website' => ''];
    try {
        $row = $pdo->query("SELECT company_name, company_phone, company_website FROM company_settings WHERE id = 1 LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $row ?: $defaults;
    } catch (Throwable $e) {
        return $defaults;
    }
}

function saveCompanySettings(PDO $pdo, string $name, string $phone, string $website): void
{
    $stmt = $pdo->prepare("
        UPDATE company_settings
        SET company_name = :name, company_phone = :phone, company_website = :website
        WHERE id = 1
    ");
    $stmt->execute([':name' => $name, ':phone' => $phone, ':website' => $website]);
}

function renderNotificationTemplate(string $template, array $tagValues): string
{
    return strtr($template, $tagValues);
}

function loadNotificationTagValues(PDO $pdo): array
{
    $tagValues = array_fill_keys(array_keys(getNotificationTagDefinitions()), '');

    $companySettings = getCompanySettings($pdo);
    $tagValues['{company_name}']    = $companySettings['company_name'];
    $tagValues['{company_phone}']   = $companySettings['company_phone'];
    $tagValues['{company_website}'] = $companySettings['company_website'];

    $customerAndRequest = [];
    try {
        $customerAndRequestStmt = $pdo->query("
            SELECT
                c.first_name,
                c.last_name,
                c.address,
                c.city,
                c.state,
                c.zip,
                c.company,
                c.phone,
                sr.promised_service_date,
                sr.services AS services_json
            FROM service_requests sr
            JOIN customers c ON c.id = sr.customer_id
            ORDER BY
                CASE WHEN sr.promised_service_date IS NULL OR sr.promised_service_date = '' THEN 1 ELSE 0 END,
                sr.promised_service_date DESC,
                sr.id DESC
            LIMIT 1
        ");
        $customerAndRequest = $customerAndRequestStmt->fetch(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) {
        // Preview data is optional on environments without seeded scheduling data.
    }

    $fullName = trim(implode(' ', array_filter([
        trim((string) ($customerAndRequest['first_name'] ?? '')),
        trim((string) ($customerAndRequest['last_name'] ?? '')),
    ])));
    $fullAddress = implode(', ', array_filter([
        trim((string) ($customerAndRequest['address'] ?? '')),
        trim((string) ($customerAndRequest['city'] ?? '')),
        trim((string) ($customerAndRequest['state'] ?? '')),
        trim((string) ($customerAndRequest['zip'] ?? '')),
    ]));

    $tagValues['{client_name}'] = $fullName;
    $tagValues['{client_address}'] = $fullAddress;
    $tagValues['{appointment_date}'] = trim((string) ($customerAndRequest['promised_service_date'] ?? ''));

    // Resolve {service_name} from the JSON array of service IDs stored in service_requests.services
    $tagValues['{service_name}'] = '';
    $servicesJson = trim((string) ($customerAndRequest['services_json'] ?? ''));
    if ($servicesJson !== '') {
        $serviceIds = json_decode($servicesJson, true);
        if (is_array($serviceIds) && $serviceIds !== []) {
            try {
                $placeholders = implode(',', array_fill(0, count($serviceIds), '?'));
                $svcStmt = $pdo->prepare(
                    "SELECT service_name FROM services WHERE id IN ({$placeholders}) ORDER BY service_name ASC"
                );
                $svcStmt->execute($serviceIds);
                $svcNames = $svcStmt->fetchAll(PDO::FETCH_COLUMN);
                $tagValues['{service_name}'] = implode(', ', $svcNames);
            } catch (Throwable $e) {
                // services table may not exist in every environment.
            }
        }
    }

    try {
        $routeStopStmt = $pdo->query("
            SELECT arrival_window_start, arrival_window_end
            FROM service_route_stops
            ORDER BY id DESC
            LIMIT 1
        ");
        $routeStop = $routeStopStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $tagValues['{appointment_time}'] = trim((string) ($routeStop['arrival_window_start'] ?? ''));
        $tagValues['{appointment_end_time}'] = trim((string) ($routeStop['arrival_window_end'] ?? ''));
    } catch (Throwable $e) {
        // service_route_stops may not exist in every environment yet.
    }

    // {customer_name} – alias of {client_name} for maintenance context
    $tagValues['{customer_name}'] = $tagValues['{client_name}'];

    // {last_service_date} and {next_service_date} from recurring_service_customers
    try {
        $recurringStmt = $pdo->query("
            SELECT last_serviced_date, next_due_date
            FROM recurring_service_customers
            ORDER BY id DESC
            LIMIT 1
        ");
        $recurringRow = $recurringStmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $tagValues['{last_service_date}'] = trim((string) ($recurringRow['last_serviced_date'] ?? ''));
        $tagValues['{next_service_date}'] = trim((string) ($recurringRow['next_due_date'] ?? ''));
    } catch (Throwable $e) {
        $tagValues['{last_service_date}'] = '';
        $tagValues['{next_service_date}'] = '';
    }

    // {admin_name} from session
    $tagValues['{admin_name}'] = trim((string) ($_SESSION['admin_username'] ?? ''));

    return $tagValues;
}

function ensureNotificationsTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS notifications (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            body TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
}

function getNotifications(PDO $pdo): array
{
    return $pdo->query("SELECT id, title, body FROM notifications ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
}

ensureNotificationsTable($pdo);
ensureCompanySettingsTable($pdo);

$successMessage = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'save_custom_tags') {
        $companyName    = trim((string) ($_POST['custom_company_name'] ?? ''));
        $companyPhone   = trim((string) ($_POST['custom_company_phone'] ?? ''));
        $companyWebsite = trim((string) ($_POST['custom_company_website'] ?? ''));
        saveCompanySettings($pdo, $companyName, $companyPhone, $companyWebsite);
        $successMessage = 'Custom tags saved successfully.';
    } elseif ($action === 'add') {
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        $unsupportedTags = getUnsupportedNotificationTags($body);

        if ($title === '') {
            $errorMessage = 'Notification title is required.';
        } elseif ($body === '') {
            $errorMessage = 'Notification body is required.';
        } elseif ($unsupportedTags !== []) {
            $errorMessage = 'Unsupported notification tags: ' . implode(', ', $unsupportedTags);
        } else {
            $stmt = $pdo->prepare("INSERT INTO notifications (title, body) VALUES (:title, :body)");
            $stmt->execute([
                ':title' => $title,
                ':body' => $body,
            ]);
            $successMessage = 'Notification added successfully.';
        }
    } elseif ($action === 'edit') {
        $id = (int) ($_POST['id'] ?? 0);
        $title = trim((string) ($_POST['title'] ?? ''));
        $body = trim((string) ($_POST['body'] ?? ''));
        $unsupportedTags = getUnsupportedNotificationTags($body);

        if ($id <= 0) {
            $errorMessage = 'Invalid notification ID.';
        } elseif ($title === '') {
            $errorMessage = 'Notification title is required.';
        } elseif ($body === '') {
            $errorMessage = 'Notification body is required.';
        } elseif ($unsupportedTags !== []) {
            $errorMessage = 'Unsupported notification tags: ' . implode(', ', $unsupportedTags);
        } else {
            $stmt = $pdo->prepare("UPDATE notifications SET title = :title, body = :body WHERE id = :id");
            $stmt->execute([
                ':title' => $title,
                ':body' => $body,
                ':id' => $id,
            ]);
            $successMessage = 'Notification updated successfully.';
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);

        if ($id <= 0) {
            $errorMessage = 'Invalid notification ID.';
        } else {
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = :id");
            $stmt->execute([':id' => $id]);
            $successMessage = 'Notification deleted.';
        }
    }

    if ($successMessage !== null) {
        header('Location: notifications.php?msg=' . urlencode($successMessage));
        exit;
    }
}

if (isset($_GET['msg'])) {
    $successMessage = htmlspecialchars(trim((string) $_GET['msg']), ENT_QUOTES, 'UTF-8');
}

$notificationTagDefinitions = getNotificationTagDefinitions();
$notificationTagValues = loadNotificationTagValues($pdo);
$notifications = getNotifications($pdo);
$companySettings = getCompanySettings($pdo);

foreach ($notifications as &$notification) {
    $notification['unsupported_tags'] = getUnsupportedNotificationTags((string) $notification['body']);
    $notification['rendered_body'] = renderNotificationTemplate((string) $notification['body'], $notificationTagValues);
}
unset($notification);
?>
<?php
$pageTitle = 'Notifications | Ghost Laser';
$pageDescription = 'Ghost Laser notification template management.';
$extraHead = <<<'HTML'
    <style>
        .card-glow { box-shadow: 0 0 0 1px rgba(6,182,212,0.15), 0 0 60px rgba(6,182,212,0.06); }
        .modal-overlay { background: rgba(0,0,0,0.65); backdrop-filter: blur(4px); }
    </style>
HTML;
$headerRight = <<<'HTML'
                <div class="flex items-center gap-3">
                    <a href="dashboard.php" class="text-sm text-zinc-400 hover:text-white transition-colors">&larr; Back to Dashboard</a>
                    <a href="settings.php" class="text-sm text-zinc-400 hover:text-white transition-colors">Schedule Settings</a>
                </div>
HTML;
require_once __DIR__ . '/templates/header.php';
?>

    <main class="min-h-screen hero-grid pt-24 pb-16 px-4">
        <div class="max-w-5xl mx-auto space-y-8">
            <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow">
                <div class="inline-flex items-center gap-2 rounded-full border border-cyan-500/20 bg-cyan-500/10 px-3 py-1 text-xs font-medium uppercase tracking-[0.2em] text-cyan-400">
                    Admin Settings
                </div>
                <h1 class="mt-4 text-3xl font-bold tracking-tight">Notifications</h1>
                <p class="mt-2 text-zinc-400">
                    Manage reusable notification templates. Click any title below to expand the full message body.
                </p>
            </section>

            <?php if ($successMessage !== null): ?>
            <div class="rounded-xl border border-emerald-500/30 bg-emerald-500/10 px-4 py-3 text-sm text-emerald-400">
                <?= $successMessage ?>
            </div>
            <?php endif; ?>

            <?php if ($errorMessage !== null): ?>
            <div class="rounded-xl border border-red-500/30 bg-red-500/10 px-4 py-3 text-sm text-red-400">
                <?= htmlspecialchars($errorMessage, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow space-y-6">
                <div>
                    <h2 class="text-xl font-semibold text-white">Supported Notification Tags</h2>
                    <p class="mt-2 text-sm text-zinc-400">
                        Use only these tags in notification bodies. Each tag below is wired to the listed database table and column(s) when notifications are rendered.
                    </p>
                </div>

                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-800">
                                <th class="pb-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">Tag</th>
                                <th class="pb-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">Table</th>
                                <th class="pb-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">Column(s)</th>
                                <th class="pb-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">Current Preview Value</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-800/60">
                            <?php foreach ($notificationTagDefinitions as $tag => $definition): ?>
                            <tr>
                                <td class="py-3 pr-4 font-semibold text-cyan-300"><?= htmlspecialchars($tag, ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="py-3 pr-4 text-zinc-200"><?= htmlspecialchars($definition['table'], ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="py-3 pr-4 text-zinc-200"><?= htmlspecialchars(implode(' + ', $definition['columns']), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="py-3 text-zinc-400">
                                    <?= htmlspecialchars($notificationTagValues[$tag] !== '' ? $notificationTagValues[$tag] : 'No sample data available', ENT_QUOTES, 'UTF-8') ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </section>

            <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow space-y-6">
                <div>
                    <h2 class="text-xl font-semibold text-white">Custom Tags</h2>
                    <p class="mt-2 text-sm text-zinc-400">
                        Set your company's details here. These values are used when rendering the
                        <span class="text-cyan-300">{company_name}</span>,
                        <span class="text-cyan-300">{company_phone}</span>, and
                        <span class="text-cyan-300">{company_website}</span> tags in any notification template.
                    </p>
                </div>

                <form method="POST" action="notifications.php" class="space-y-4">
                    <input type="hidden" name="action" value="save_custom_tags">
                    <div class="grid gap-4 md:grid-cols-3">
                        <div>
                            <label for="custom-company-name" class="mb-1.5 block text-xs font-medium text-zinc-400">Company Name</label>
                            <input
                                type="text"
                                id="custom-company-name"
                                name="custom_company_name"
                                maxlength="255"
                                value="<?= htmlspecialchars($companySettings['company_name'], ENT_QUOTES, 'UTF-8') ?>"
                                class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/50"
                                placeholder="e.g. Ghost Laser"
                            >
                        </div>
                        <div>
                            <label for="custom-company-phone" class="mb-1.5 block text-xs font-medium text-zinc-400">Company Phone</label>
                            <input
                                type="text"
                                id="custom-company-phone"
                                name="custom_company_phone"
                                maxlength="100"
                                value="<?= htmlspecialchars($companySettings['company_phone'], ENT_QUOTES, 'UTF-8') ?>"
                                class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/50"
                                placeholder="e.g. (555) 123-4567"
                            >
                        </div>
                        <div>
                            <label for="custom-company-website" class="mb-1.5 block text-xs font-medium text-zinc-400">Company Website</label>
                            <input
                                type="text"
                                id="custom-company-website"
                                name="custom_company_website"
                                maxlength="255"
                                value="<?= htmlspecialchars($companySettings['company_website'], ENT_QUOTES, 'UTF-8') ?>"
                                class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/50"
                                placeholder="e.g. https://LaserCutterRepair.com"
                            >
                        </div>
                    </div>
                    <div class="flex justify-end">
                        <button
                            type="submit"
                            class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 transition-colors hover:bg-cyan-400 focus:outline-none focus:ring-2 focus:ring-cyan-500/50"
                        >
                            Save Custom Tags
                        </button>
                    </div>
                </form>
            </section>

            <section class="bg-zinc-900/80 border border-zinc-800 rounded-2xl p-6 md:p-8 card-glow space-y-6">
                <div class="flex flex-col gap-4 md:flex-row md:items-start md:justify-between">
                    <div>
                        <h2 class="text-xl font-semibold text-white">Notification Templates</h2>
                        <p class="mt-2 text-sm text-zinc-400">
                            Supported tags:
                            <?php foreach (array_keys($notificationTagDefinitions) as $index => $tag): ?>
                                <span class="text-cyan-300"><?= htmlspecialchars($tag, ENT_QUOTES, 'UTF-8') ?></span><?= $index < count($notificationTagDefinitions) - 1 ? ',' : '' ?>
                            <?php endforeach; ?>
                        </p>
                    </div>
                    <button
                        type="button"
                        onclick="openAddModal()"
                        class="inline-flex items-center justify-center gap-2 rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 transition-colors hover:bg-cyan-400 focus:outline-none focus:ring-2 focus:ring-cyan-500/50"
                    >
                        <svg class="w-4 h-4" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4v16m8-8H4"/>
                        </svg>
                        Add New
                    </button>
                </div>

                <?php if (empty($notifications)): ?>
                <p class="text-sm text-zinc-500">No notifications found. Add one to start building personalized templates.</p>
                <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="w-full text-sm">
                        <thead>
                            <tr class="border-b border-zinc-800">
                                <th class="pb-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">ID</th>
                                <th class="pb-3 text-left text-xs font-semibold uppercase tracking-wider text-zinc-500">Title</th>
                                <th class="pb-3 text-right text-xs font-semibold uppercase tracking-wider text-zinc-500">Actions</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-zinc-800/60">
                            <?php foreach ($notifications as $notification): ?>
                            <tr class="group">
                                <td class="py-3.5 pr-4 align-top font-mono text-xs text-zinc-400">
                                    <?= (int) $notification['id'] ?>
                                </td>
                                <td class="py-3.5 pr-4">
                                    <button
                                        type="button"
                                        class="notification-toggle flex w-full items-center gap-3 text-left font-medium text-white transition-colors hover:text-cyan-300"
                                        data-target="notification-body-<?= (int) $notification['id'] ?>"
                                        aria-expanded="false"
                                    >
                                        <svg class="h-4 w-4 flex-shrink-0 text-cyan-400 transition-transform" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                                        </svg>
                                        <span><?= htmlspecialchars($notification['title'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </button>
                                </td>
                                <td class="py-3.5 text-right">
                                    <div class="inline-flex items-center gap-2">
                                        <button
                                            type="button"
                                            onclick="openEditModal(<?= (int) $notification['id'] ?>, <?= htmlspecialchars(json_encode($notification['title']), ENT_QUOTES, 'UTF-8') ?>, <?= htmlspecialchars(json_encode($notification['body']), ENT_QUOTES, 'UTF-8') ?>)"
                                            class="rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-xs font-medium text-zinc-300 transition-colors hover:border-cyan-500/50 hover:text-cyan-400 focus:outline-none focus:ring-2 focus:ring-cyan-500/40"
                                        >
                                            Edit
                                        </button>
                                        <form method="POST" action="notifications.php" onsubmit="return confirm('Delete this notification?')">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="id" value="<?= (int) $notification['id'] ?>">
                                            <button
                                                type="submit"
                                                class="rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-1.5 text-xs font-medium text-zinc-400 transition-colors hover:border-red-500/50 hover:text-red-400 focus:outline-none focus:ring-2 focus:ring-red-500/40"
                                            >
                                                Delete
                                            </button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                            <tr id="notification-body-<?= (int) $notification['id'] ?>" class="hidden bg-zinc-950/40">
                                <td colspan="3" class="px-4 pb-4 pt-1">
                                    <div class="space-y-4">
                                        <?php if ($notification['unsupported_tags'] !== []): ?>
                                        <div class="rounded-xl border border-amber-500/30 bg-amber-500/10 p-4 text-sm text-amber-200">
                                            Unsupported tags found: <?= htmlspecialchars(implode(', ', $notification['unsupported_tags']), ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                        <?php endif; ?>

                                        <div class="grid gap-4 lg:grid-cols-2">
                                            <div>
                                                <div class="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-500">Template Body</div>
                                                <div class="rounded-xl border border-zinc-800 bg-zinc-950/60 p-4 text-sm leading-7 text-zinc-300 whitespace-pre-line">
                                                    <?= htmlspecialchars($notification['body'], ENT_QUOTES, 'UTF-8') ?>
                                                </div>
                                            </div>
                                            <div>
                                                <div class="mb-2 text-xs font-semibold uppercase tracking-wider text-zinc-500">Rendered Preview</div>
                                                <div class="rounded-xl border border-cyan-500/20 bg-cyan-500/5 p-4 text-sm leading-7 text-zinc-200 whitespace-pre-line">
                                                    <?= htmlspecialchars($notification['rendered_body'], ENT_QUOTES, 'UTF-8') ?>
                                                </div>
                                            </div>
                                        </div>
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

    <div id="add-modal" class="modal-overlay fixed inset-0 z-50 hidden flex items-center justify-center p-4">
        <div class="w-full max-w-2xl rounded-2xl border border-zinc-800 bg-zinc-900 p-6 shadow-2xl">
            <h3 class="mb-5 text-lg font-semibold text-white">Add New Notification</h3>
            <form method="POST" action="notifications.php">
                <input type="hidden" name="action" value="add">
                <div class="space-y-4">
                    <div>
                        <label for="add-title" class="mb-1.5 block text-xs font-medium text-zinc-400">Title</label>
                        <input
                            type="text"
                            id="add-title"
                            name="title"
                            required
                            maxlength="255"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/50"
                            placeholder="e.g. Appointment Reminder"
                        >
                    </div>
                    <div>
                        <label for="add-body" class="mb-1.5 block text-xs font-medium text-zinc-400">Message Body</label>
                        <textarea
                            id="add-body"
                            name="body"
                            rows="8"
                            required
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/50"
                            placeholder="Hello {client_name}, your appointment at {client_address} is scheduled for {appointment_date} between {appointment_time} and {appointment_end_time}."
                        ></textarea>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button
                        type="button"
                        onclick="closeAddModal()"
                        class="rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm font-medium text-zinc-300 transition-colors hover:border-zinc-600 hover:text-white focus:outline-none"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 transition-colors hover:bg-cyan-400 focus:outline-none focus:ring-2 focus:ring-cyan-500/50"
                    >
                        Add New
                    </button>
                </div>
            </form>
        </div>
    </div>

    <div id="edit-modal" class="modal-overlay fixed inset-0 z-50 hidden flex items-center justify-center p-4">
        <div class="w-full max-w-2xl rounded-2xl border border-zinc-800 bg-zinc-900 p-6 shadow-2xl">
            <h3 class="mb-5 text-lg font-semibold text-white">Edit Notification</h3>
            <form method="POST" action="notifications.php">
                <input type="hidden" name="action" value="edit">
                <input type="hidden" name="id" id="edit-id">
                <div class="space-y-4">
                    <div>
                        <label for="edit-title" class="mb-1.5 block text-xs font-medium text-zinc-400">Title</label>
                        <input
                            type="text"
                            id="edit-title"
                            name="title"
                            required
                            maxlength="255"
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/50"
                        >
                    </div>
                    <div>
                        <label for="edit-body" class="mb-1.5 block text-xs font-medium text-zinc-400">Message Body</label>
                        <textarea
                            id="edit-body"
                            name="body"
                            rows="8"
                            required
                            class="w-full rounded-lg border border-zinc-700 bg-zinc-800 px-3 py-2 text-sm text-white placeholder-zinc-500 focus:border-cyan-500 focus:outline-none focus:ring-1 focus:ring-cyan-500/50"
                        ></textarea>
                    </div>
                </div>
                <div class="mt-6 flex justify-end gap-3">
                    <button
                        type="button"
                        onclick="closeEditModal()"
                        class="rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm font-medium text-zinc-300 transition-colors hover:border-zinc-600 hover:text-white focus:outline-none"
                    >
                        Cancel
                    </button>
                    <button
                        type="submit"
                        class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 transition-colors hover:bg-cyan-400 focus:outline-none focus:ring-2 focus:ring-cyan-500/50"
                    >
                        Save Changes
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script>
        const addModal = document.getElementById('add-modal');
        const editModal = document.getElementById('edit-modal');

        function openAddModal() {
            addModal.classList.remove('hidden');
            document.getElementById('add-title').focus();
        }

        function closeAddModal() {
            addModal.classList.add('hidden');
        }

        function openEditModal(id, title, body) {
            document.getElementById('edit-id').value = id;
            document.getElementById('edit-title').value = title;
            document.getElementById('edit-body').value = body;
            editModal.classList.remove('hidden');
            document.getElementById('edit-title').focus();
        }

        function closeEditModal() {
            editModal.classList.add('hidden');
        }

        addModal.addEventListener('click', (event) => {
            if (event.target === addModal) {
                closeAddModal();
            }
        });

        editModal.addEventListener('click', (event) => {
            if (event.target === editModal) {
                closeEditModal();
            }
        });

        document.addEventListener('keydown', (event) => {
            if (event.key === 'Escape') {
                closeAddModal();
                closeEditModal();
            }
        });

        document.querySelectorAll('.notification-toggle').forEach((button) => {
            button.addEventListener('click', () => {
                const bodyRow = document.getElementById(button.dataset.target);
                const icon = button.querySelector('svg');
                const isExpanded = button.getAttribute('aria-expanded') === 'true';

                button.setAttribute('aria-expanded', isExpanded ? 'false' : 'true');
                bodyRow.classList.toggle('hidden', isExpanded);
                icon.classList.toggle('rotate-90', !isExpanded);
            });
        });
    </script>

<?php require_once __DIR__ . '/templates/footer.php'; ?>
