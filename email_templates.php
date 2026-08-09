<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';

require_admin_or_moderator();

if (empty($_SESSION['et_csrf'])) {
    $_SESSION['et_csrf'] = bin2hex(random_bytes(24));
}

/**
 * Supported notification tags and their exact database sources.
 * Copied exactly from notifications.php.
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

function et_get_unsupported_tags(string $template): array
{
    preg_match_all('/\{[a-z0-9_]+\}/i', $template, $matches);
    $used = array_values(array_unique($matches[0] ?? []));
    return array_values(array_diff($used, array_keys(getNotificationTagDefinitions())));
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

/**
 * Load notification tag values for preview.
 * Copied exactly from notifications.php.
 */
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

function ensureEmailTemplatesTable(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS email_templates (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
            title VARCHAR(255) NOT NULL,
            subject VARCHAR(255) NOT NULL DEFAULT '',
            body TEXT NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )
    ");
}

function et_get_all(PDO $pdo): array
{
    return $pdo->query("SELECT id, title, subject, body FROM email_templates ORDER BY id ASC")->fetchAll(PDO::FETCH_ASSOC);
}

function et_verify_csrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['et_csrf'] ?? ''), $token)) {
        http_response_code(403);
        die('Security token mismatch.');
    }
}

ensureEmailTemplatesTable($pdo);
ensureCompanySettingsTable($pdo);

$successMessage = null;
$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    et_verify_csrf();
    $action = trim((string) ($_POST['action'] ?? ''));

    if ($action === 'add') {
        $title   = trim((string) ($_POST['title'] ?? ''));
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body    = trim((string) ($_POST['body'] ?? ''));
        $bad     = et_get_unsupported_tags($body);

        if ($title === '') {
            $errorMessage = 'Template name is required.';
        } elseif ($subject === '') {
            $errorMessage = 'Email subject is required.';
        } elseif ($body === '') {
            $errorMessage = 'Email body is required.';
        } elseif ($bad !== []) {
            $errorMessage = 'Unsupported tags: ' . implode(', ', $bad);
        } else {
            $pdo->prepare("INSERT INTO email_templates (title, subject, body) VALUES (:title, :subject, :body)")
                ->execute([':title' => $title, ':subject' => $subject, ':body' => $body]);
            $successMessage = 'Template added.';
        }
    } elseif ($action === 'edit') {
        $id      = (int) ($_POST['id'] ?? 0);
        $title   = trim((string) ($_POST['title'] ?? ''));
        $subject = trim((string) ($_POST['subject'] ?? ''));
        $body    = trim((string) ($_POST['body'] ?? ''));
        $bad     = et_get_unsupported_tags($body);

        if ($id <= 0) {
            $errorMessage = 'Invalid template ID.';
        } elseif ($title === '') {
            $errorMessage = 'Template name is required.';
        } elseif ($subject === '') {
            $errorMessage = 'Email subject is required.';
        } elseif ($body === '') {
            $errorMessage = 'Email body is required.';
        } elseif ($bad !== []) {
            $errorMessage = 'Unsupported tags: ' . implode(', ', $bad);
        } else {
            $pdo->prepare("UPDATE email_templates SET title = :title, subject = :subject, body = :body WHERE id = :id")
                ->execute([':title' => $title, ':subject' => $subject, ':body' => $body, ':id' => $id]);
            $successMessage = 'Template updated.';
        }
    } elseif ($action === 'delete') {
        $id = (int) ($_POST['id'] ?? 0);
        if ($id <= 0) {
            $errorMessage = 'Invalid template ID.';
        } else {
            $pdo->prepare("DELETE FROM email_templates WHERE id = :id")->execute([':id' => $id]);
            $successMessage = 'Template deleted.';
        }
    }

    if ($successMessage !== null) {
        $_SESSION['et_csrf'] = bin2hex(random_bytes(24));
        header('Location: email_templates.php?msg=' . urlencode($successMessage));
        exit;
    }
    // Regenerate token after failed POST
    $_SESSION['et_csrf'] = bin2hex(random_bytes(24));
}

if (isset($_GET['msg'])) {
    $successMessage = trim((string) $_GET['msg']);
}

$tagDefinitions  = getNotificationTagDefinitions();
$tagValues       = loadNotificationTagValues($pdo);
$templates       = et_get_all($pdo);

// Editing state
$editingTemplate = null;
$editId = (int) ($_GET['edit'] ?? 0);
if ($editId > 0) {
    foreach ($templates as $tpl) {
        if ((int) $tpl['id'] === $editId) {
            $editingTemplate = $tpl;
            break;
        }
    }
}

render_header('Email Templates');
?>

<div class="card page-header">
    <div class="page-header-body">
        <h1>CRM Email Templates</h1>
        <p class="muted">Manage reusable follow-up email templates for the CRM. Use the merge tags below to personalize messages automatically.</p>
    </div>
    <div>
        <a href="crm.php" class="btn">← Back to CRM</a>
    </div>
</div>

<?php if ($successMessage !== null): ?>
    <div class="alert success"><?= h($successMessage) ?></div>
<?php endif; ?>
<?php if ($errorMessage !== null): ?>
    <div class="alert error"><?= h($errorMessage) ?></div>
<?php endif; ?>

<div class="card">
    <h2 style="margin:0 0 12px; font-size:1.1em;">Available Merge Tags</h2>
    <p class="muted" style="margin-bottom:12px;">Use any of these tags in your template subject or body. They will be replaced with real values when Patty sends an email.</p>
    <div style="overflow-x:auto;">
        <table>
            <thead>
                <tr>
                    <th>Tag</th>
                    <th>Source</th>
                    <th>Description</th>
                    <th>Sample Value</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($tagDefinitions as $tag => $def): ?>
                <tr>
                    <td><code style="background:#f3f4f6; padding:2px 6px; border-radius:4px; font-size:.9em;"><?= h($tag) ?></code></td>
                    <td class="muted"><?= h($def['table']) ?>.<?= h(implode('+', $def['columns'])) ?></td>
                    <td><?= h($def['description']) ?></td>
                    <td class="muted"><?= $tagValues[$tag] !== '' ? h($tagValues[$tag]) : '<em>no sample data</em>' ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<div class="card">
    <h2 style="margin:0 0 16px; font-size:1.1em;"><?= $editingTemplate ? 'Edit Template' : 'Add New Template' ?></h2>
    <form method="post" action="email_templates.php">
        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['et_csrf']) ?>" />
        <input type="hidden" name="action" value="<?= $editingTemplate ? 'edit' : 'add' ?>" />
        <?php if ($editingTemplate): ?>
            <input type="hidden" name="id" value="<?= (int) $editingTemplate['id'] ?>" />
        <?php endif; ?>

        <div style="margin-bottom:14px;">
            <label for="et-title" style="display:block; font-weight:600; margin-bottom:6px;">Template Name <span class="muted" style="font-weight:400;">(internal label)</span></label>
            <input id="et-title" type="text" name="title" style="max-width:480px; width:100%;" maxlength="255" required
                   value="<?= h($editingTemplate ? $editingTemplate['title'] : (string) ($_POST['title'] ?? '')) ?>"
                   placeholder="e.g. 6-Month Follow-Up" />
        </div>

        <div style="margin-bottom:14px;">
            <label for="et-subject" style="display:block; font-weight:600; margin-bottom:6px;">Email Subject</label>
            <input id="et-subject" type="text" name="subject" style="max-width:600px; width:100%;" maxlength="255" required
                   value="<?= h($editingTemplate ? $editingTemplate['subject'] : (string) ($_POST['subject'] ?? '')) ?>"
                   placeholder="e.g. Following up from {company_name}" />
        </div>

        <div style="margin-bottom:18px;">
            <label for="et-body" style="display:block; font-weight:600; margin-bottom:6px;">Email Body</label>
            <textarea id="et-body" name="body" rows="10" style="width:100%; resize:vertical;" required
                      placeholder="Hi {client_name},&#10;&#10;Just reaching out to follow up…&#10;&#10;— {admin_name}"><?= h($editingTemplate ? $editingTemplate['body'] : (string) ($_POST['body'] ?? '')) ?></textarea>
            <p class="muted" style="margin-top:4px; font-size:.88em;">You may use any merge tag from the table above.</p>
        </div>

        <div class="row" style="gap:10px;">
            <button type="submit" class="btn primary"><?= $editingTemplate ? 'Update Template' : 'Add Template' ?></button>
            <?php if ($editingTemplate): ?>
                <a href="email_templates.php" class="btn">Cancel</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<?php if ($templates): ?>
<div class="card" style="padding:0; overflow-x:auto;">
    <table>
        <thead>
            <tr>
                <th>Name</th>
                <th>Subject</th>
                <th>Body Preview</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($templates as $tpl): ?>
            <tr>
                <td><strong><?= h($tpl['title']) ?></strong></td>
                <td><?= h($tpl['subject']) ?></td>
                <td class="muted" style="max-width:320px; white-space:pre-wrap; word-break:break-word;"><?= h(mb_strimwidth((string) $tpl['body'], 0, 160, '…')) ?></td>
                <td>
                    <a href="email_templates.php?edit=<?= (int) $tpl['id'] ?>" class="btn" style="margin-right:6px;">Edit</a>
                    <form method="post" action="email_templates.php" style="display:inline;" onsubmit="return confirm('Delete this template?');">
                        <input type="hidden" name="csrf_token" value="<?= h($_SESSION['et_csrf']) ?>" />
                        <input type="hidden" name="action" value="delete" />
                        <input type="hidden" name="id" value="<?= (int) $tpl['id'] ?>" />
                        <button type="submit" class="btn">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php else: ?>
<div class="card">
    <p class="muted">No email templates yet. Add one above.</p>
</div>
<?php endif; ?>

<?php render_footer(); ?>
