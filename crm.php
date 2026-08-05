<?php
require __DIR__ . '/db.php';
require __DIR__ . '/layout.php';
require __DIR__ . '/auth.php';
require __DIR__ . '/bootstrap_env.php';
require __DIR__ . '/lib/PHPMailer/src/Exception.php';
require __DIR__ . '/lib/PHPMailer/src/PHPMailer.php';
require __DIR__ . '/lib/PHPMailer/src/SMTP.php';

use PHPMailer\PHPMailer\PHPMailer;

require_admin_or_moderator();

if (empty($_SESSION['crm_csrf'])) {
    $_SESSION['crm_csrf'] = bin2hex(random_bytes(24));
}

$userId = (int) ($_SESSION['user_id'] ?? 0);
$tz = new DateTimeZone(APP_TZ);

function crm_json_response(array $payload, int $status = 200): void
{
    http_response_code($status);
    header('Content-Type: application/json; charset=UTF-8');
    header('X-Content-Type-Options: nosniff');
    echo json_encode($payload);
    exit;
}

function crm_verify_csrf(): void
{
    $token = (string) ($_POST['csrf_token'] ?? '');
    if (!hash_equals((string) ($_SESSION['crm_csrf'] ?? ''), $token)) {
        crm_json_response(['ok' => false, 'error' => 'Security token mismatch.'], 403);
    }
}

function crm_contact_type_label(string $contactType): string
{
    if ($contactType === 'call') {
        return 'Phone Call';
    }
    if ($contactType === 'email') {
        return 'Email';
    }
    return 'Note';
}

function crm_customer_display_name(array $row): string
{
    $fullName = trim(((string) ($row['first_name'] ?? '')) . ' ' . ((string) ($row['last_name'] ?? '')));
    if ($fullName !== '') {
        return $fullName;
    }
    $company = trim((string) ($row['company'] ?? ''));
    return $company !== '' ? $company : '(no name)';
}

function crm_fetch_contact_history(PDO $pdo, int $customerId, DateTimeZone $tz): array
{
    $stmt = $pdo->prepare("\n        SELECT\n            cl.id,\n            cl.contact_type,\n            cl.notes,\n            cl.logged_at,\n            COALESCE(u.username, CONCAT_WS(' ', NULLIF(u.first_name, ''), NULLIF(u.last_name, ''))) AS logged_by_name\n        FROM contacts_log cl\n        LEFT JOIN users u ON u.id = cl.logged_by\n        WHERE cl.customer_id = ?\n        ORDER BY cl.logged_at DESC, cl.id DESC\n    ");
    $stmt->execute([$customerId]);

    $history = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $entry) {
        $loggedAt = (string) ($entry['logged_at'] ?? '');
        $dt = $loggedAt !== '' ? new DateTime($loggedAt, $tz) : null;
        $history[] = [
            'id' => (int) $entry['id'],
            'type' => (string) ($entry['contact_type'] ?? 'note'),
            'type_label' => crm_contact_type_label((string) ($entry['contact_type'] ?? 'note')),
            'date' => $dt ? $dt->format('m/d/Y') : '—',
            'time' => $dt ? $dt->format('g:i A') : '—',
            'notes' => (string) ($entry['notes'] ?? ''),
            'logged_by_name' => trim((string) ($entry['logged_by_name'] ?? '')),
        ];
    }

    return $history;
}

function crm_send_email(PDO $pdo, int $customerId, string $to, string $subject, string $messageHtml, string $messageText, ?string &$errorMessage): bool
{
    $smtpHost = trim((string) env_value('SMTP_HOST', ''));
    $smtpPort = (int) env_value('SMTP_PORT', '587');
    $smtpUsername = trim((string) env_value('SMTP_USERNAME', ''));
    $smtpPassword = (string) env_value('SMTP_PASSWORD', '');
    $smtpFromEmail = trim((string) env_value('SMTP_FROM_EMAIL', ''));
    $smtpFromName = trim(str_replace(["\r", "\n"], ' ', (string) env_value('SMTP_FROM_NAME', '')));

    $missing = [];
    if ($smtpHost === '') $missing[] = 'SMTP_HOST';
    if ($smtpPort <= 0) $missing[] = 'SMTP_PORT';
    if ($smtpUsername === '') $missing[] = 'SMTP_USERNAME';
    if ($smtpPassword === '') $missing[] = 'SMTP_PASSWORD';
    if ($smtpFromEmail === '' || !filter_var($smtpFromEmail, FILTER_VALIDATE_EMAIL)) $missing[] = 'SMTP_FROM_EMAIL';
    if ($missing) {
        $errorMessage = 'Missing or invalid SMTP configuration: ' . implode(', ', $missing);
        return false;
    }

    try {
        $mailer = new PHPMailer(true);
        $mailer->isSMTP();
        $mailer->SMTPOptions = ['ssl' => ['verify_peer' => false, 'verify_peer_name' => false, 'allow_self_signed' => true]];
        $mailer->Host = $smtpHost;
        $mailer->Port = $smtpPort;
        $mailer->SMTPAuth = true;
        $mailer->Username = $smtpUsername;
        $mailer->Password = $smtpPassword;
        if ($smtpPort === 465) {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
            $mailer->SMTPAutoTLS = false;
        } else {
            $mailer->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            $mailer->SMTPAutoTLS = true;
        }
        $mailer->CharSet = 'UTF-8';
        $mailer->setFrom($smtpFromEmail, $smtpFromName !== '' ? $smtpFromName : 'Ghostlings');
        $mailer->addAddress($to);
        $mailer->Subject = $subject;
        $mailer->isHTML(true);
        $mailer->Body = $messageHtml;
        $mailer->AltBody = $messageText;
        if (!$mailer->send()) {
            $errorMessage = trim((string) $mailer->ErrorInfo);
            return false;
        }
    } catch (Throwable $e) {
        $errorMessage = $e->getMessage();
        error_log('CRM email send failed for customer #' . $customerId . ': ' . $e->getMessage());
        return false;
    }

    $now = (new DateTime('now', new DateTimeZone(APP_TZ)))->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare("\n        INSERT INTO contacts_log (customer_id, contact_type, notes, logged_by, logged_at, created_at)\n        VALUES (?, 'email', ?, ?, ?, ?)\n    ");
    $stmt->execute([
        $customerId,
        $messageText !== '' ? $messageText : null,
        (int) ($_SESSION['user_id'] ?? 0) ?: null,
        $now,
        $now,
    ]);

    return true;
}

function crm_fetch_rows(PDO $pdo, string $search, DateTimeZone $tz): array
{
    $searchSql = '';
    $binds = [];
    if ($search !== '') {
        $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $search);
        $like = '%' . $escaped . '%';
        $searchSql = " AND (c.first_name LIKE :q ESCAPE '!' OR c.last_name LIKE :q ESCAPE '!' OR CONCAT(c.first_name, ' ', c.last_name) LIKE :q ESCAPE '!' OR c.company LIKE :q ESCAPE '!')";
        $binds[':q'] = $like;
    }

    $sql = "
        SELECT
            c.id,
            c.first_name,
            c.last_name,
            c.company,
            c.phone,
            c.email,
            c.followup_flagged,
            MAX(COALESCE(sr.completed_at, sr.updated_at)) AS last_service_date,
            (
                SELECT MAX(cl.logged_at)
                FROM contacts_log cl
                WHERE cl.customer_id = c.id
            ) AS last_contact_date,
            (
                SELECT cl2.contact_type
                FROM contacts_log cl2
                WHERE cl2.customer_id = c.id
                ORDER BY cl2.logged_at DESC, cl2.id DESC
                LIMIT 1
            ) AS last_contact_type
        FROM customers c
        LEFT JOIN service_requests sr
            ON sr.customer_id = c.id
           AND sr.request_status = 'completed'
        WHERE 1=1 {$searchSql}
        GROUP BY c.id, c.first_name, c.last_name, c.company, c.phone, c.email, c.followup_flagged
        HAVING c.followup_flagged = 1 OR last_service_date IS NOT NULL
        ORDER BY
            CASE WHEN last_contact_date IS NULL THEN 0 ELSE 1 END ASC,
            last_contact_date ASC,
            last_service_date DESC,
            c.last_name ASC,
            c.first_name ASC,
            c.id ASC
    ";

    $stmt = $pdo->prepare($sql);
    foreach ($binds as $key => $value) {
        $stmt->bindValue($key, $value, PDO::PARAM_STR);
    }
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $today = new DateTime('today', $tz);
    foreach ($rows as &$row) {
        if (!empty($row['last_contact_date'])) {
            $contactDate = new DateTime((string) $row['last_contact_date'], $tz);
            $contactDate->setTime(0, 0, 0);
            $row['days_since_contact'] = (int) $today->diff($contactDate)->days;
        } else {
            $row['days_since_contact'] = null;
        }
        $row['history'] = crm_fetch_contact_history($pdo, (int) $row['id'], $tz);
    }
    unset($row);

    return $rows;
}

function crm_render_rows(array $rows): void
{
    foreach ($rows as $row) {
        $days = $row['days_since_contact'];
        if ($days === null) {
            $rowStyle = 'background:#fef2f2;';
            $statusLabel = 'Never Contacted';
            $statusColor = '#dc2626';
        } elseif ($days > 365) {
            $rowStyle = 'background:#fef2f2;';
            $statusLabel = 'Overdue';
            $statusColor = '#dc2626';
        } elseif ($days >= 180) {
            $rowStyle = 'background:#fefce8;';
            $statusLabel = 'Due Soon';
            $statusColor = '#b45309';
        } else {
            $rowStyle = 'background:#f0fdf4;';
            $statusLabel = 'OK';
            $statusColor = '#166534';
        }

        $displayName = crm_customer_display_name($row);
        $lastService = !empty($row['last_service_date']) ? fmt_date_mdY(substr((string) $row['last_service_date'], 0, 10)) : '—';
        $lastContact = !empty($row['last_contact_date']) ? fmt_date_mdY(substr((string) $row['last_contact_date'], 0, 10)) : '—';
        $email = trim((string) ($row['email'] ?? ''));
        $emailSubject = 'Follow-up from Ghostlings';
        $emailBody = "Hello {$displayName},\n\nJust following up regarding your recent service order.\n\nBest,\nGhostlings";
        ?>
        <tr style="<?= h($rowStyle) ?>">
            <td>
                <a href="customer_details.php?id=<?= (int) $row['id'] ?>"><?= h($displayName) ?></a>
                <?php if (!empty($row['followup_flagged'])): ?>
                    <span title="Manually added to follow-up" style="color:#b45309; font-size:.8em;">★ flagged</span>
                <?php endif; ?>
            </td>
            <td><?= h((string) ($row['company'] ?? '')) ?></td>
            <td><?= h((string) ($row['phone'] ?? '')) ?></td>
            <td>
                <?php if ($email !== ''): ?>
                    <button
                        type="button"
                        class="btn send-email-btn"
                        data-customer-id="<?= (int) $row['id'] ?>"
                        data-customer-name="<?= h($displayName) ?>"
                        data-customer-email="<?= h($email) ?>"
                        data-email-subject="<?= h($emailSubject) ?>"
                        data-email-body="<?= h($emailBody) ?>"
                    >Send Email</button>
                <?php else: ?>
                    <span class="muted">—</span>
                <?php endif; ?>
            </td>
            <td><?= h($lastService) ?></td>
            <td><?= h($lastContact) ?></td>
            <td><?= h($days !== null ? (string) $days : '—') ?></td>
            <td><strong style="color:<?= h($statusColor) ?>;"><?= h($statusLabel) ?></strong></td>
            <td>
                <button type="button" class="btn log-contact-btn" data-customer-id="<?= (int) $row['id'] ?>" data-customer-name="<?= h($displayName) ?>">Log Contact</button>
                <button
                    type="button"
                    class="btn view-log-btn"
                    data-customer-id="<?= (int) $row['id'] ?>"
                    data-customer-name="<?= h($displayName) ?>"
                    data-history="<?= h(json_encode($row['history'], JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_AMP | JSON_HEX_QUOT) ?: '[]') ?>"
                    style="margin-top:4px;"
                >View Log</button>
                <?php if (!empty($row['followup_flagged'])): ?>
                    <button type="button" class="btn remove-flag-btn" data-customer-id="<?= (int) $row['id'] ?>" style="margin-top:4px;">Remove Flag</button>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'log_contact') {
    crm_verify_csrf();
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $contactType = (string) ($_POST['contact_type'] ?? 'note');
    $notes = trim((string) ($_POST['notes'] ?? ''));
    if ($customerId <= 0) {
        crm_json_response(['ok' => false, 'error' => 'Invalid customer.'], 400);
    }
    if (!in_array($contactType, ['call', 'email', 'note'], true)) {
        $contactType = 'note';
    }
    $now = (new DateTime('now', $tz))->format('Y-m-d H:i:s');
    $stmt = $pdo->prepare("INSERT INTO contacts_log (customer_id, contact_type, notes, logged_by, logged_at, created_at) VALUES (?, ?, ?, ?, ?, ?)");
    $stmt->execute([$customerId, $contactType, $notes !== '' ? $notes : null, $userId > 0 ? $userId : null, $now, $now]);
    $_SESSION['crm_csrf'] = bin2hex(random_bytes(24));
    crm_json_response(['ok' => true, 'new_csrf' => $_SESSION['crm_csrf'], 'history' => crm_fetch_contact_history($pdo, $customerId, $tz)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'toggle_flag') {
    crm_verify_csrf();
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $flagValue = (int) ($_POST['flag_value'] ?? 0) ? 1 : 0;
    if ($customerId <= 0) {
        crm_json_response(['ok' => false, 'error' => 'Invalid customer.'], 400);
    }
    $stmt = $pdo->prepare('UPDATE customers SET followup_flagged = ? WHERE id = ?');
    $stmt->execute([$flagValue, $customerId]);
    $_SESSION['crm_csrf'] = bin2hex(random_bytes(24));
    crm_json_response(['ok' => true, 'new_csrf' => $_SESSION['crm_csrf']]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string) ($_POST['action'] ?? '') === 'send_email') {
    crm_verify_csrf();
    $customerId = (int) ($_POST['customer_id'] ?? 0);
    $subject = trim((string) ($_POST['subject'] ?? ''));
    $body = trim((string) ($_POST['body'] ?? ''));
    $to = trim((string) ($_POST['to_email'] ?? ''));
    if ($customerId <= 0 || $subject === '' || $body === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
        crm_json_response(['ok' => false, 'error' => 'Please provide a valid recipient, subject, and message.'], 400);
    }
    $html = nl2br(h($body));
    $errorMessage = null;
    if (!crm_send_email($pdo, $customerId, $to, $subject, $html, $body, $errorMessage)) {
        crm_json_response(['ok' => false, 'error' => $errorMessage ?: 'Unable to send email.'], 500);
    }
    $_SESSION['crm_csrf'] = bin2hex(random_bytes(24));
    crm_json_response(['ok' => true, 'new_csrf' => $_SESSION['crm_csrf'], 'history' => crm_fetch_contact_history($pdo, $customerId, $tz)]);
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && (string) ($_GET['customer_search'] ?? '') === '1') {
    header('Content-Type: application/json; charset=UTF-8');
    $query = trim((string) ($_GET['q'] ?? ''));
    if ($query === '') {
        echo json_encode(['html' => '']);
        exit;
    }
    $escaped = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], $query);
    $like = '%' . $escaped . '%';
    $stmt = $pdo->prepare("\n        SELECT c.id, c.first_name, c.last_name, c.company, c.phone, c.email, c.followup_flagged,\n               EXISTS(SELECT 1 FROM service_requests sr WHERE sr.customer_id = c.id AND sr.request_status = 'completed') AS has_completed\n        FROM customers c\n        WHERE c.first_name LIKE :q ESCAPE '!'\n           OR c.last_name LIKE :q ESCAPE '!'\n           OR CONCAT(c.first_name, ' ', c.last_name) LIKE :q ESCAPE '!'\n           OR c.company LIKE :q ESCAPE '!'\n        ORDER BY c.last_name, c.first_name, c.company\n        LIMIT 50\n    ");
    $stmt->bindValue(':q', $like, PDO::PARAM_STR);
    $stmt->execute();
    ob_start();
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $displayName = crm_customer_display_name($row);
        $inCrm = !empty($row['followup_flagged']) || !empty($row['has_completed']);
        ?>
        <tr>
            <td><a href="customer_details.php?id=<?= (int) $row['id'] ?>"><?= h($displayName) ?></a></td>
            <td><?= h((string) ($row['company'] ?? '')) ?></td>
            <td><?= h((string) ($row['phone'] ?? '')) ?></td>
            <td><?= h((string) ($row['email'] ?? '')) ?></td>
            <td>
                <?php if ($inCrm): ?>
                    <span style="color:#166534; font-weight:600;">✓ In CRM</span>
                <?php else: ?>
                    <button type="button" class="btn add-to-crm-btn" data-customer-id="<?= (int) $row['id'] ?>">+ Add to CRM</button>
                <?php endif; ?>
            </td>
        </tr>
        <?php
    }
    echo json_encode(['html' => ob_get_clean()]);
    exit;
}

$search = trim((string) ($_GET['q'] ?? ''));
$rows = crm_fetch_rows($pdo, $search, $tz);

if ((string) ($_GET['live_search'] ?? '') === '1') {
    ob_start();
    crm_render_rows($rows);
    crm_json_response(['tableRowsHtml' => ob_get_clean()]);
}

render_header('CRM');
?>
<div class="card page-header">
    <div class="page-header-body">
        <h1>Customer Relationship Management</h1>
        <p class="muted">Customers with completed service orders or manual follow-up flags, sorted by days since last contact.</p>
    </div>
</div>

<div class="card">
    <form id="crm-search-form" method="get" action="crm.php" class="row" style="margin-bottom:4px;" role="search">
        <input id="crm-search-input" type="text" name="q" value="<?= h($search) ?>" placeholder="Filter CRM list by name or company…" aria-label="Filter CRM list by name or company" style="max-width:360px;" />
        <button type="submit" class="btn">Search</button>
        <a id="crm-search-clear" class="btn" href="crm.php" <?= $search === '' ? 'style="display:none;"' : '' ?>>Clear</a>
    </form>
</div>

<div class="card">
    <h2 style="margin:0 0 12px; font-size:1.1em;">Add Customer to CRM</h2>
    <div class="row" style="margin-bottom:8px;">
        <input id="add-crm-search-input" type="text" placeholder="Search customers by name or company…" aria-label="Search customers to add to CRM" style="max-width:360px;" />
        <span id="add-crm-searching" style="display:none; color:#6b7280; font-size:.9em;">Searching…</span>
    </div>
    <div id="add-crm-results" style="display:none; overflow-x:auto;">
        <table>
            <thead><tr><th>Customer</th><th>Company</th><th>Phone</th><th>Email</th><th>Action</th></tr></thead>
            <tbody id="add-crm-results-body"></tbody>
        </table>
    </div>
    <p id="add-crm-empty" style="display:none;" class="muted">No customers found.</p>
</div>

<div id="crm-table-wrap" class="card" style="padding:0; overflow-x:auto;">
    <?php if (!$rows): ?>
        <p id="crm-no-results" class="muted" style="padding:16px;">No CRM customers found.</p>
    <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th>Customer</th>
                    <th>Company</th>
                    <th>Phone</th>
                    <th>Email</th>
                    <th>Last Service Date</th>
                    <th>Last Contact Date</th>
                    <th>Days Since Contact</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody id="crm-table-body"><?php crm_render_rows($rows); ?></tbody>
        </table>
    <?php endif; ?>
</div>

<div id="log-contact-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:1000; align-items:center; justify-content:center; padding:16px;">
    <div style="background:#fff; border-radius:8px; padding:28px 32px; width:100%; max-width:480px; box-shadow:0 8px 32px rgba(0,0,0,.2); position:relative;">
        <h2 id="log-contact-title" style="margin:0 0 18px;">Log Contact</h2>
        <form id="log-contact-form">
            <input type="hidden" id="log-customer-id" name="customer_id" value="" />
            <input type="hidden" name="action" value="log_contact" />
            <input type="hidden" id="crm-csrf" name="csrf_token" value="<?= h($_SESSION['crm_csrf']) ?>" />
            <div style="margin-bottom:14px;">
                <label for="log-contact-type" style="display:block; font-weight:600; margin-bottom:6px;">Contact Type</label>
                <select id="log-contact-type" name="contact_type" style="width:100%;">
                    <option value="call">📞 Phone Call</option>
                    <option value="email">✉️ Email</option>
                    <option value="note">📝 Note</option>
                </select>
            </div>
            <div style="margin-bottom:18px;">
                <label for="log-notes" style="display:block; font-weight:600; margin-bottom:6px;">Notes <span class="muted" style="font-weight:400;">(optional)</span></label>
                <textarea id="log-notes" name="notes" rows="4" style="width:100%; resize:vertical;" placeholder="Summary of the conversation…"></textarea>
            </div>
            <div id="log-contact-error" class="alert error" style="display:none; margin-bottom:14px;"></div>
            <div class="row" style="gap:10px;">
                <button type="submit" class="btn primary" id="log-submit-btn">Save Log Entry</button>
                <button type="button" class="btn" id="log-cancel-btn">Cancel</button>
            </div>
        </form>
    </div>
</div>

<div id="view-log-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:1100; align-items:center; justify-content:center; padding:16px;">
    <div class="crm-modal-shell" role="dialog" aria-modal="true" aria-labelledby="view-log-title">
        <div class="crm-modal-header">
            <h2 id="view-log-title" class="crm-modal-title">Conversation Log</h2>
            <button type="button" class="crm-modal-close" id="view-log-close" aria-label="Close">&times;</button>
        </div>
        <div class="crm-modal-scroll"><div id="view-log-list" class="crm-history-list"></div></div>
        <form id="view-log-form" class="crm-compose-form">
            <input type="hidden" id="view-log-customer-id" name="customer_id" value="" />
            <input type="hidden" name="action" value="log_contact" />
            <input type="hidden" id="view-log-csrf" name="csrf_token" value="<?= h($_SESSION['crm_csrf']) ?>" />
            <input type="hidden" name="contact_type" value="note" />
            <label for="view-log-notes" style="display:block; font-weight:600; margin-bottom:6px;">Add Note</label>
            <textarea id="view-log-notes" name="notes" rows="4" placeholder="Add a new note…"></textarea>
            <div id="view-log-error" class="alert error" style="display:none; margin-top:12px;"></div>
            <div class="crm-compose-actions">
                <span class="muted" style="font-size:.9em;">New notes stay visible while the history scrolls above.</span>
                <button type="submit" class="btn primary" id="view-log-submit">Add Note</button>
            </div>
        </form>
    </div>
</div>

<div id="send-email-overlay" style="display:none; position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:1200; align-items:center; justify-content:center; padding:16px;">
    <div style="background:#fff; border-radius:8px; padding:28px 32px; width:100%; max-width:620px; box-shadow:0 8px 32px rgba(0,0,0,.2); position:relative;">
        <h2 id="send-email-title" style="margin:0 0 18px;">Send Email</h2>
        <form id="send-email-form">
            <input type="hidden" id="send-email-customer-id" name="customer_id" value="" />
            <input type="hidden" name="action" value="send_email" />
            <input type="hidden" id="send-email-csrf" name="csrf_token" value="<?= h($_SESSION['crm_csrf']) ?>" />
            <div style="margin-bottom:14px;">
                <label for="send-email-to" style="display:block; font-weight:600; margin-bottom:6px;">To</label>
                <input id="send-email-to" type="email" name="to_email" style="width:100%;" required />
            </div>
            <div style="margin-bottom:14px;">
                <label for="send-email-subject" style="display:block; font-weight:600; margin-bottom:6px;">Subject</label>
                <input id="send-email-subject" type="text" name="subject" style="width:100%;" required />
            </div>
            <div style="margin-bottom:18px;">
                <label for="send-email-body" style="display:block; font-weight:600; margin-bottom:6px;">Message</label>
                <textarea id="send-email-body" name="body" rows="8" style="width:100%; resize:vertical;" required></textarea>
            </div>
            <div id="send-email-error" class="alert error" style="display:none; margin-bottom:14px;"></div>
            <div class="row" style="gap:10px;">
                <button type="submit" class="btn primary" id="send-email-submit">Send Email</button>
                <button type="button" class="btn" id="send-email-cancel">Cancel</button>
            </div>
        </form>
    </div>
</div>

<style>
.crm-modal-shell{background:#fff;border-radius:12px;width:min(760px,96vw);max-height:88vh;box-shadow:0 16px 40px rgba(0,0,0,.24);display:flex;flex-direction:column;overflow:hidden;}
.crm-modal-header{padding:20px 24px 16px;border-bottom:1px solid #e5e7eb;display:flex;align-items:center;gap:12px;}
.crm-modal-title{margin:0;font-size:1.2em;flex:1;}
.crm-modal-close{border:none;background:#f3f4f6;border-radius:999px;width:34px;height:34px;font-size:22px;cursor:pointer;color:#374151;}
.crm-modal-scroll{padding:20px 24px;overflow-y:auto;flex:1;background:#f8fafc;}
.crm-history-list{display:flex;flex-direction:column;gap:14px;}
.crm-history-card{background:#fff;border:1px solid #e5e7eb;border-radius:10px;padding:14px 16px;box-shadow:0 1px 2px rgba(15,23,42,.06);}
.crm-history-meta{display:flex;flex-wrap:wrap;gap:10px 16px;margin-bottom:8px;color:#475569;font-size:.92em;}
.crm-history-type{font-weight:700;color:#0f172a;}
.crm-history-content{white-space:pre-wrap;color:#111827;line-height:1.45;}
.crm-history-empty{padding:28px 20px;text-align:center;color:#64748b;background:#fff;border:1px dashed #cbd5e1;border-radius:10px;}
.crm-compose-form{border-top:1px solid #e5e7eb;padding:16px 24px 20px;background:#fff;}
.crm-compose-form textarea{width:100%;resize:vertical;min-height:92px;}
.crm-compose-actions{display:flex;gap:10px;align-items:center;justify-content:space-between;margin-top:12px;flex-wrap:wrap;}
@media (max-width: 640px){.crm-modal-header,.crm-modal-scroll,.crm-compose-form{padding-left:16px;padding-right:16px;}}
</style>

<script>
(function () {
  var csrfInput = document.getElementById('crm-csrf');
  var viewLogCsrf = document.getElementById('view-log-csrf');
  var sendEmailCsrf = document.getElementById('send-email-csrf');

  function updateCsrf(token) {
    if (!token) return;
    if (csrfInput) csrfInput.value = token;
    if (viewLogCsrf) viewLogCsrf.value = token;
    if (sendEmailCsrf) sendEmailCsrf.value = token;
  }

  function escapeHtml(value) {
    return String(value)
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#039;');
  }

  function renderHistory(entries) {
    var historyList = document.getElementById('view-log-list');
    if (!historyList) return;
    if (!entries || !entries.length) {
      historyList.innerHTML = '<div class="crm-history-empty">No past notes yet.</div>';
      return;
    }
    historyList.innerHTML = entries.map(function (entry) {
      var icon = entry.type === 'call' ? '📞' : (entry.type === 'email' ? '✉️' : '📝');
      var notes = entry.notes && entry.notes.trim() !== '' ? escapeHtml(entry.notes).replace(/\n/g, '<br>') : '<span class="muted">No details provided.</span>';
      var by = entry.logged_by_name && entry.logged_by_name.trim() !== '' ? '<span>By: ' + escapeHtml(entry.logged_by_name) + '</span>' : '';
      return '<article class="crm-history-card"><div class="crm-history-meta"><span class="crm-history-type">' + icon + ' ' + escapeHtml(entry.type_label || 'Note') + '</span><span>Date: ' + escapeHtml(entry.date || '—') + '</span><span>Time: ' + escapeHtml(entry.time || '—') + '</span>' + by + '</div><div class="crm-history-content">' + notes + '</div></article>';
    }).join('');
  }

  function bindLogContactButtons() {
    document.querySelectorAll('.log-contact-btn').forEach(function (btn) {
      if (btn.dataset.boundLog) return;
      btn.dataset.boundLog = '1';
      btn.addEventListener('click', function () {
        document.getElementById('log-customer-id').value = btn.dataset.customerId || '';
        document.getElementById('log-contact-title').textContent = 'Log Contact — ' + (btn.dataset.customerName || 'Customer');
        document.getElementById('log-contact-type').value = 'call';
        document.getElementById('log-notes').value = '';
        document.getElementById('log-contact-error').style.display = 'none';
        document.getElementById('log-contact-overlay').style.display = 'flex';
      });
    });
  }

  function bindViewLogButtons() {
    document.querySelectorAll('.view-log-btn').forEach(function (btn) {
      if (btn.dataset.boundView) return;
      btn.dataset.boundView = '1';
      btn.addEventListener('click', function () {
        var history = [];
        try { history = JSON.parse(btn.dataset.history || '[]'); } catch (e) { history = []; }
        document.getElementById('view-log-customer-id').value = btn.dataset.customerId || '';
        document.getElementById('view-log-title').textContent = 'Conversation Log — ' + (btn.dataset.customerName || 'Customer');
        document.getElementById('view-log-notes').value = '';
        document.getElementById('view-log-error').style.display = 'none';
        renderHistory(history);
        document.getElementById('view-log-overlay').style.display = 'flex';
      });
    });
  }

  function bindSendEmailButtons() {
    document.querySelectorAll('.send-email-btn').forEach(function (btn) {
      if (btn.dataset.boundEmail) return;
      btn.dataset.boundEmail = '1';
      btn.addEventListener('click', function () {
        document.getElementById('send-email-customer-id').value = btn.dataset.customerId || '';
        document.getElementById('send-email-title').textContent = 'Send Email — ' + (btn.dataset.customerName || 'Customer');
        document.getElementById('send-email-to').value = btn.dataset.customerEmail || '';
        document.getElementById('send-email-subject').value = btn.dataset.emailSubject || '';
        document.getElementById('send-email-body').value = btn.dataset.emailBody || '';
        document.getElementById('send-email-error').style.display = 'none';
        document.getElementById('send-email-overlay').style.display = 'flex';
      });
    });
  }

  function toggleFlag(customerId, flagValue, btn) {
    var formData = new FormData();
    formData.append('action', 'toggle_flag');
    formData.append('customer_id', customerId);
    formData.append('flag_value', flagValue ? '1' : '0');
    formData.append('csrf_token', csrfInput ? csrfInput.value : '');
    if (btn) {
      btn.disabled = true;
      btn.textContent = flagValue ? 'Adding…' : 'Removing…';
    }
    fetch('crm.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: formData })
      .then(function (resp) { return resp.json(); })
      .then(function (json) {
        if (json && json.ok) {
          updateCsrf(json.new_csrf || '');
          window.location.reload();
          return;
        }
        throw new Error(json && json.error ? json.error : 'An error occurred.');
      })
      .catch(function (err) {
        if (btn) {
          btn.disabled = false;
          btn.textContent = flagValue ? '+ Add to CRM' : 'Remove Flag';
        }
        alert(err.message || 'Network error. Please try again.');
      });
  }

  function bindRemoveFlagButtons() {
    document.querySelectorAll('.remove-flag-btn').forEach(function (btn) {
      if (btn.dataset.boundRemove) return;
      btn.dataset.boundRemove = '1';
      btn.addEventListener('click', function () {
        toggleFlag(btn.dataset.customerId || '', false, btn);
      });
    });
  }

  document.getElementById('log-cancel-btn').addEventListener('click', function () {
    document.getElementById('log-contact-overlay').style.display = 'none';
  });
  document.getElementById('view-log-close').addEventListener('click', function () {
    document.getElementById('view-log-overlay').style.display = 'none';
  });
  document.getElementById('send-email-cancel').addEventListener('click', function () {
    document.getElementById('send-email-overlay').style.display = 'none';
  });

  ['log-contact-overlay', 'view-log-overlay', 'send-email-overlay'].forEach(function (id) {
    var overlay = document.getElementById(id);
    if (!overlay) return;
    overlay.addEventListener('click', function (event) {
      if (event.target === overlay) overlay.style.display = 'none';
    });
  });

  document.addEventListener('keydown', function (event) {
    if (event.key !== 'Escape') return;
    ['log-contact-overlay', 'view-log-overlay', 'send-email-overlay'].forEach(function (id) {
      var overlay = document.getElementById(id);
      if (overlay && overlay.style.display === 'flex') overlay.style.display = 'none';
    });
  });

  document.getElementById('log-contact-form').addEventListener('submit', function (event) {
    event.preventDefault();
    var errorBox = document.getElementById('log-contact-error');
    var submitBtn = document.getElementById('log-submit-btn');
    errorBox.style.display = 'none';
    submitBtn.disabled = true;
    submitBtn.textContent = 'Saving…';
    fetch('crm.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: new FormData(event.target) })
      .then(function (resp) { return resp.json(); })
      .then(function (json) {
        if (!json || !json.ok) throw new Error(json && json.error ? json.error : 'An error occurred.');
        updateCsrf(json.new_csrf || '');
        window.location.reload();
      })
      .catch(function (err) {
        errorBox.textContent = err.message || 'Network error. Please try again.';
        errorBox.style.display = '';
        submitBtn.disabled = false;
        submitBtn.textContent = 'Save Log Entry';
      });
  });

  document.getElementById('view-log-form').addEventListener('submit', function (event) {
    event.preventDefault();
    var errorBox = document.getElementById('view-log-error');
    var submitBtn = document.getElementById('view-log-submit');
    errorBox.style.display = 'none';
    submitBtn.disabled = true;
    submitBtn.textContent = 'Saving…';
    fetch('crm.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: new FormData(event.target) })
      .then(function (resp) { return resp.json(); })
      .then(function (json) {
        if (!json || !json.ok) throw new Error(json && json.error ? json.error : 'An error occurred.');
        updateCsrf(json.new_csrf || '');
        renderHistory(json.history || []);
        document.getElementById('view-log-notes').value = '';
        submitBtn.disabled = false;
        submitBtn.textContent = 'Add Note';
      })
      .catch(function (err) {
        errorBox.textContent = err.message || 'Network error. Please try again.';
        errorBox.style.display = '';
        submitBtn.disabled = false;
        submitBtn.textContent = 'Add Note';
      });
  });

  document.getElementById('send-email-form').addEventListener('submit', function (event) {
    event.preventDefault();
    var errorBox = document.getElementById('send-email-error');
    var submitBtn = document.getElementById('send-email-submit');
    errorBox.style.display = 'none';
    submitBtn.disabled = true;
    submitBtn.textContent = 'Sending…';
    fetch('crm.php', { method: 'POST', credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' }, body: new FormData(event.target) })
      .then(function (resp) { return resp.json(); })
      .then(function (json) {
        if (!json || !json.ok) throw new Error(json && json.error ? json.error : 'An error occurred.');
        updateCsrf(json.new_csrf || '');
        document.getElementById('send-email-overlay').style.display = 'none';
        window.location.reload();
      })
      .catch(function (err) {
        errorBox.textContent = err.message || 'Network error. Please try again.';
        errorBox.style.display = '';
        submitBtn.disabled = false;
        submitBtn.textContent = 'Send Email';
      });
  });

  (function () {
    var crmForm = document.getElementById('crm-search-form');
    var crmInput = document.getElementById('crm-search-input');
    var crmClear = document.getElementById('crm-search-clear');
    var crmTableBody = document.getElementById('crm-table-body');
    if (!crmForm || !crmInput || !crmTableBody) return;
    var timer = null;
    var controller = null;
    var lastQuery = crmInput.value.trim();
    function updateClear() {
      crmClear.style.display = crmInput.value.trim() === '' ? 'none' : '';
    }
    function runSearch() {
      var query = crmInput.value.trim();
      updateClear();
      if (query === lastQuery) return;
      lastQuery = query;
      if (controller) controller.abort();
      controller = new AbortController();
      var url = new URL('crm.php', window.location.href);
      if (query !== '') url.searchParams.set('q', query); else url.searchParams.delete('q');
      url.searchParams.set('live_search', '1');
      fetch(url.toString(), { method: 'GET', credentials: 'same-origin', signal: controller.signal, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (resp) { return resp.json(); })
        .then(function (json) {
          if (json && typeof json.tableRowsHtml === 'string') {
            crmTableBody.innerHTML = json.tableRowsHtml;
            bindLogContactButtons();
            bindViewLogButtons();
            bindSendEmailButtons();
            bindRemoveFlagButtons();
          }
          window.history.replaceState(null, '', url.pathname + (query !== '' ? '?q=' + encodeURIComponent(query) : ''));
        })
        .catch(function (err) { if (!err || err.name !== 'AbortError') {} });
    }
    crmInput.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(runSearch, 250);
    });
    crmForm.addEventListener('submit', function (event) {
      event.preventDefault();
      clearTimeout(timer);
      runSearch();
    });
    crmClear.addEventListener('click', function (event) {
      event.preventDefault();
      crmInput.value = '';
      clearTimeout(timer);
      runSearch();
      crmInput.focus();
    });
  })();

  (function () {
    var input = document.getElementById('add-crm-search-input');
    var resultsDiv = document.getElementById('add-crm-results');
    var resultsBody = document.getElementById('add-crm-results-body');
    var emptyMsg = document.getElementById('add-crm-empty');
    var searching = document.getElementById('add-crm-searching');
    if (!input || !resultsDiv || !resultsBody) return;
    var timer = null;
    var controller = null;
    var lastQuery = '';
    function runSearch() {
      var query = input.value.trim();
      if (query === lastQuery) return;
      lastQuery = query;
      if (query === '') {
        resultsDiv.style.display = 'none';
        emptyMsg.style.display = 'none';
        searching.style.display = 'none';
        resultsBody.innerHTML = '';
        return;
      }
      if (controller) controller.abort();
      controller = new AbortController();
      searching.style.display = '';
      var url = new URL('crm.php', window.location.href);
      url.searchParams.set('customer_search', '1');
      url.searchParams.set('q', query);
      fetch(url.toString(), { method: 'GET', credentials: 'same-origin', signal: controller.signal, headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (resp) { return resp.json(); })
        .then(function (json) {
          searching.style.display = 'none';
          if (!json || typeof json.html !== 'string') return;
          resultsBody.innerHTML = json.html;
          var hasRows = resultsBody.children.length > 0;
          resultsDiv.style.display = hasRows ? '' : 'none';
          emptyMsg.style.display = hasRows ? 'none' : '';
          resultsBody.querySelectorAll('.add-to-crm-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
              toggleFlag(btn.dataset.customerId || '', true, btn);
            });
          });
        })
        .catch(function (err) {
          if (err && err.name === 'AbortError') return;
          searching.style.display = 'none';
        });
    }
    input.addEventListener('input', function () {
      clearTimeout(timer);
      timer = setTimeout(runSearch, 250);
    });
  })();

  bindLogContactButtons();
  bindViewLogButtons();
  bindSendEmailButtons();
  bindRemoveFlagButtons();
})();
</script>
<?php render_footer(); ?>
