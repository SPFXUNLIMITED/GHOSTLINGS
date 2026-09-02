<?php
/**
 * project/customer_interaction_module.php
 *
 * Reusable "Customer Details" interaction module.
 *
 * Modeled after the prospect details modal in prospects.php, but adapted to
 * work against the `customers` table so it can be dropped into any admin
 * page that lists customers (technician/schedule.php, bookings.php,
 * recurring-services.php, etc.) without depending on anything prospect
 * specific.
 *
 * Usage from a host page:
 *
 *   require_once __DIR__ . '/project/customer_interaction_module.php';
 *   customerInteractionEnsureSchema($pdo);
 *   $customerInteractionCsrf = customerInteractionCsrfToken();
 *   ...
 *   // Anywhere a customer name is rendered:
 *   <button type="button" onclick="openCustomerDetailsModal(<?= (int) $customerId ?>)">...</button>
 *   ...
 *   // Once, near the end of the page body:
 *   <?php customerInteractionRenderModal(); ?>
 *
 * The modal loads customer details + interaction history via the shared
 * AJAX endpoint api/customer-interactions.php, so host pages do not need to
 * pre-render any customer/interaction JS data payloads.
 */

require_once __DIR__ . '/../functions.php';

/**
 * Check whether a column exists on a given table (information_schema based,
 * mirrors prospectsColumnExists() in prospects_schema.php).
 */
function customerInteractionColumnExists(PDO $pdo, string $table, string $column): bool
{
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table_name
          AND COLUMN_NAME = :column_name
    ");
    $stmt->execute([
        ':table_name' => $table,
        ':column_name' => $column,
    ]);

    return (int) $stmt->fetchColumn() > 0;
}

/**
 * Create/upgrade all tables required by the customer interaction module.
 * Safe to call on every request.
 */
function customerInteractionEnsureSchema(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS customer_interactions (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            customer_id INT UNSIGNED NOT NULL,
            interaction_type ENUM('call', 'email', 'note', 'status_change') NOT NULL,
            outcome VARCHAR(255) NULL,
            interaction_notes TEXT NULL,
            interacted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            admin_id INT UNSIGNED NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_customer_interactions_customer (customer_id),
            INDEX idx_customer_interactions_type (interaction_type),
            INDEX idx_customer_interactions_at (interacted_at),
            CONSTRAINT fk_customer_interactions_customer
                FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    if (!customerInteractionColumnExists($pdo, 'customers', 'notes')) {
        try {
            $pdo->exec("ALTER TABLE customers ADD COLUMN notes TEXT NULL");
        } catch (Throwable $e) {
            // Ignore if another concurrent request already added it.
        }
    }

    if (!customerInteractionColumnExists($pdo, 'customers', 'last_called_at')) {
        try {
            $pdo->exec("ALTER TABLE customers ADD COLUMN last_called_at DATETIME NULL");
        } catch (Throwable $e) {
            // Ignore.
        }
    }

    if (!customerInteractionColumnExists($pdo, 'customers', 'last_emailed_at')) {
        try {
            $pdo->exec("ALTER TABLE customers ADD COLUMN last_emailed_at DATETIME NULL");
        } catch (Throwable $e) {
            // Ignore.
        }
    }

    // customer_status backs the optional "status" the interaction log can set.
    ensure_customer_status_table($pdo);
}

/**
 * Statuses selectable from the "Status Change" interaction type. Backed by
 * the existing customer_status table (see customer-status.php).
 */
function customerInteractionStatusOptions(): array
{
    return ['VIP', 'Good', 'Caution', 'Banned'];
}

/** Ensure a per-session CSRF token exists for this module and return it. */
function customerInteractionCsrfToken(): string
{
    if (empty($_SESSION['customer_interaction_csrf'])) {
        $_SESSION['customer_interaction_csrf'] = bin2hex(random_bytes(32));
    }

    return (string) $_SESSION['customer_interaction_csrf'];
}

/** Validate a submitted CSRF token against the session token. */
function customerInteractionCsrfIsValid(string $submitted): bool
{
    $expected = customerInteractionCsrfToken();

    return $submitted !== '' && hash_equals($expected, $submitted);
}

function customerInteractionSanitizeField(string $value, int $maxLength = 255): string
{
    return mb_substr(trim($value), 0, $maxLength);
}

function customerInteractionNowLosAngeles(): string
{
    return (new DateTimeImmutable('now', new DateTimeZone('America/Los_Angeles')))->format('Y-m-d H:i:s');
}

function customerInteractionCleanDateTimeInput(string $value): ?string
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }
    foreach (['Y-m-d\TH:i', 'Y-m-d H:i:s', 'Y-m-d\TH:i:s'] as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $value);
        if ($dt !== false) {
            return $dt->format('Y-m-d H:i:s');
        }
    }

    return null;
}

function customerInteractionFormatDisplayDateTime(?string $value): string
{
    if ($value === null || trim($value) === '') {
        return '';
    }
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $value) ?: DateTimeImmutable::createFromFormat('Y-m-d\TH:i:s', $value);
    if ($dt === false) {
        return $value;
    }

    return $dt->format('M j, Y g:i A');
}

/** Return the editable customer field names supported by the module. */
function customerInteractionEditableFields(): array
{
    return ['first_name', 'last_name', 'company', 'phone', 'email', 'address', 'city', 'state', 'zip', 'notes'];
}

/**
 * Fetch a single customer record, its status, and its interaction history,
 * shaped for direct JSON output to the modal's JS.
 */
function customerInteractionFetchRecord(PDO $pdo, int $customerId): ?array
{
    if ($customerId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare("
        SELECT c.*, cs.status AS customer_status
        FROM customers c
        LEFT JOIN customer_status cs ON cs.customer_id = c.id
        WHERE c.id = :id
        LIMIT 1
    ");
    $stmt->execute([':id' => $customerId]);
    $customer = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$customer) {
        return null;
    }

    $interactionsStmt = $pdo->prepare("
        SELECT id, customer_id, interaction_type, outcome, interaction_notes, interacted_at
        FROM customer_interactions
        WHERE customer_id = :customer_id
        ORDER BY interacted_at DESC, id DESC
    ");
    $interactionsStmt->execute([':customer_id' => $customerId]);
    $interactions = $interactionsStmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($interactions as &$interaction) {
        $interaction['interacted_at_display'] = customerInteractionFormatDisplayDateTime((string) ($interaction['interacted_at'] ?? ''));
    }
    unset($interaction);

    return [
        'customer' => [
            'id' => (int) $customer['id'],
            'first_name' => (string) ($customer['first_name'] ?? ''),
            'last_name' => (string) ($customer['last_name'] ?? ''),
            'company' => (string) ($customer['company'] ?? ''),
            'phone' => (string) ($customer['phone'] ?? ''),
            'email' => (string) ($customer['email'] ?? ''),
            'website' => (string) ($customer['website'] ?? ''),
            'address' => (string) ($customer['address'] ?? ''),
            'city' => (string) ($customer['city'] ?? ''),
            'state' => (string) ($customer['state'] ?? ''),
            'zip' => (string) ($customer['zip'] ?? ''),
            'notes' => (string) ($customer['notes'] ?? ''),
            'status' => (string) ($customer['customer_status'] ?? ''),
            'last_called_at' => (string) ($customer['last_called_at'] ?? ''),
            'last_called_at_display' => customerInteractionFormatDisplayDateTime((string) ($customer['last_called_at'] ?? '')),
            'last_emailed_at' => (string) ($customer['last_emailed_at'] ?? ''),
            'last_emailed_at_display' => customerInteractionFormatDisplayDateTime((string) ($customer['last_emailed_at'] ?? '')),
        ],
        'interactions' => $interactions,
    ];
}

/**
 * Insert a new interaction row and update last_called_at / last_emailed_at /
 * status as appropriate. Mirrors the log_interaction action in prospects.php.
 *
 * @throws RuntimeException on invalid input.
 */
function customerInteractionLog(PDO $pdo, int $adminId, array $input): void
{
    $customerId = (int) ($input['customer_id'] ?? 0);
    $type = trim((string) ($input['interaction_type'] ?? 'note'));
    $outcome = customerInteractionSanitizeField((string) ($input['outcome'] ?? ''));
    $interactionNotes = customerInteractionSanitizeField((string) ($input['interaction_notes'] ?? ''), 3000);
    $interactedAt = customerInteractionCleanDateTimeInput((string) ($input['interacted_at'] ?? '')) ?? customerInteractionNowLosAngeles();
    $newStatus = trim((string) ($input['new_status'] ?? ''));

    if ($customerId <= 0) {
        throw new RuntimeException('Invalid customer ID.');
    }
    if (!in_array($type, ['call', 'email', 'note', 'status_change'], true)) {
        throw new RuntimeException('Invalid interaction type.');
    }

    $customerExistsStmt = $pdo->prepare('SELECT id FROM customers WHERE id = :id LIMIT 1');
    $customerExistsStmt->execute([':id' => $customerId]);
    if (!$customerExistsStmt->fetchColumn()) {
        throw new RuntimeException('Customer not found.');
    }

    $pdo->beginTransaction();
    try {
        $insert = $pdo->prepare("
            INSERT INTO customer_interactions (
                customer_id, interaction_type, outcome, interaction_notes, interacted_at, admin_id
            ) VALUES (
                :customer_id, :interaction_type, :outcome, :interaction_notes, :interacted_at, :admin_id
            )
        ");
        $insert->execute([
            ':customer_id' => $customerId,
            ':interaction_type' => $type,
            ':outcome' => $outcome !== '' ? $outcome : null,
            ':interaction_notes' => $interactionNotes !== '' ? $interactionNotes : null,
            ':interacted_at' => $interactedAt,
            ':admin_id' => $adminId > 0 ? $adminId : null,
        ]);

        if ($type === 'call') {
            $update = $pdo->prepare("UPDATE customers SET last_called_at = :ts WHERE id = :id");
            $update->execute([':ts' => $interactedAt, ':id' => $customerId]);
        } elseif ($type === 'email') {
            $update = $pdo->prepare("UPDATE customers SET last_emailed_at = :ts WHERE id = :id");
            $update->execute([':ts' => $interactedAt, ':id' => $customerId]);
        }

        if ($type === 'status_change' && in_array($newStatus, customerInteractionStatusOptions(), true)) {
            $adminIdentity = $adminId > 0 ? ('Admin #' . $adminId) : null;
            $upsertStatus = $pdo->prepare("
                INSERT INTO customer_status (customer_id, status, updated_by)
                VALUES (:customer_id, :status, :updated_by)
                ON DUPLICATE KEY UPDATE
                    status = VALUES(status),
                    updated_by = VALUES(updated_by),
                    updated_at = CURRENT_TIMESTAMP
            ");
            $upsertStatus->execute([
                ':customer_id' => $customerId,
                ':status' => $newStatus,
                ':updated_by' => $adminIdentity,
            ]);
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

/**
 * Save (update) editable customer fields. Only columns that actually exist
 * on the customers table are written.
 *
 * @throws RuntimeException on invalid input.
 */
function customerInteractionSaveCustomer(PDO $pdo, array $input): void
{
    $customerId = (int) ($input['customer_id'] ?? 0);
    if ($customerId <= 0) {
        throw new RuntimeException('Invalid customer ID.');
    }

    $customerExistsStmt = $pdo->prepare('SELECT id FROM customers WHERE id = :id LIMIT 1');
    $customerExistsStmt->execute([':id' => $customerId]);
    if (!$customerExistsStmt->fetchColumn()) {
        throw new RuntimeException('Customer not found.');
    }

    $availableColumns = $pdo->query('SHOW COLUMNS FROM customers')->fetchAll(PDO::FETCH_COLUMN);
    $availableSet = array_fill_keys(is_array($availableColumns) ? $availableColumns : [], true);

    $updates = [];
    $params = [':id' => $customerId];
    foreach (customerInteractionEditableFields() as $field) {
        if (!isset($availableSet[$field])) {
            continue;
        }
        $maxLength = $field === 'notes' ? 5000 : 255;
        $value = customerInteractionSanitizeField((string) ($input[$field] ?? ''), $maxLength);
        $updates[] = "{$field} = :{$field}";
        $params[":{$field}"] = $value !== '' ? $value : null;
    }

    if ($updates === []) {
        throw new RuntimeException('No editable columns found on the customers table.');
    }

    $sql = 'UPDATE customers SET ' . implode(', ', $updates) . ' WHERE id = :id';
    $pdo->prepare($sql)->execute($params);
}

/**
 * Echo the "Customer Details" modal (view + edit + log-interaction form).
 * Include this once per page, anywhere after <body>.
 *
 * @param array $options {
 *     @type string $ajax_endpoint Path to the AJAX backend. Defaults to a
 *                                 path relative to the site root.
 * }
 */
function customerInteractionRenderModal(array $options = []): void
{
    $csrf = customerInteractionCsrfToken();
    $ajaxEndpoint = (string) ($options['ajax_endpoint'] ?? '/api/customer-interactions.php');
    $statusOptions = $options['status_options'] ?? customerInteractionStatusOptions();
    ?>
    <div id="customerDetailsModal" class="fixed inset-0 z-[9999] hidden items-center justify-center bg-zinc-950/80 px-4" role="dialog" aria-modal="true" aria-labelledby="customerDetailsTitle">
        <div class="absolute inset-0 customer-interaction-overlay"></div>
        <div class="relative z-10 w-full max-w-2xl max-h-[92vh] overflow-y-auto rounded-3xl border border-zinc-700 bg-zinc-900 shadow-2xl shadow-black/40">
            <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
                <h2 id="customerDetailsTitle" class="text-lg font-semibold text-white">Customer Details</h2>
                <div class="flex items-center gap-2">
                    <button type="button" id="customerDetailsEditBtn" class="rounded-md border border-cyan-700/60 bg-cyan-950/20 px-3 py-1.5 text-xs text-cyan-300 hover:border-cyan-500/60" onclick="openCustomerEditModal()">Edit</button>
                    <button type="button" class="rounded-md border border-zinc-700 px-3 py-1.5 text-xs text-zinc-300" onclick="closeCustomerDetailsModal()">Close</button>
                </div>
            </div>
            <div class="p-5 space-y-4">
                <div id="customerDetailsError" class="hidden rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2 text-xs text-red-400"></div>
                <div id="customerDetailsLoading" class="text-xs text-zinc-500">Loading customer…</div>
                <div id="customerDetailsBody" class="hidden space-y-4">
                    <div class="grid gap-3 md:grid-cols-2 text-sm">
                        <div><span class="text-zinc-500">Name:</span> <span id="ci_details_name" class="text-zinc-200"></span></div>
                        <div><span class="text-zinc-500">Company:</span> <span id="ci_details_company" class="text-zinc-200"></span></div>
                        <div><span class="text-zinc-500">Phone:</span> <span id="ci_details_phone" class="text-zinc-200"></span></div>
                        <div><span class="text-zinc-500">Email:</span> <span id="ci_details_email" class="text-zinc-200"></span></div>
                        <div><span class="text-zinc-500">Address:</span> <span id="ci_details_address" class="text-zinc-200"></span></div>
                        <div><span class="text-zinc-500">Status:</span> <span id="ci_details_status" class="text-zinc-200"></span></div>
                        <div><span class="text-zinc-500">Last Called:</span> <span id="ci_details_last_called_at" class="text-zinc-200"></span></div>
                        <div><span class="text-zinc-500">Last Emailed:</span> <span id="ci_details_last_emailed_at" class="text-zinc-200"></span></div>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wider text-zinc-500 mb-2">Notes</p>
                        <div id="ci_details_notes" class="text-sm text-zinc-300 whitespace-pre-line rounded-lg border border-zinc-800 bg-zinc-950/60 p-3"></div>
                    </div>
                    <div>
                        <p class="text-xs uppercase tracking-wider text-zinc-500 mb-2">Interaction History</p>
                        <div id="ci_details_interactions" class="space-y-2"></div>
                    </div>
                    <form id="customerInteractionLogForm" class="pt-2 space-y-2" onsubmit="return submitCustomerInteractionLog(event)">
                        <input type="hidden" id="ci_log_customer_id" value="0">
                        <div class="flex flex-wrap gap-2 items-center">
                            <select id="ci_log_type" class="field" style="width:auto;">
                                <option value="call">Call</option>
                                <option value="email">Email</option>
                                <option value="note">Note</option>
                                <option value="status_change">Status Change</option>
                            </select>
                            <select id="ci_log_new_status" class="field" style="width:auto;">
                                <option value="">Status (optional)</option>
                                <?php foreach ($statusOptions as $statusOption): ?>
                                    <option value="<?= htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($statusOption, ENT_QUOTES, 'UTF-8') ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="datetime-local" id="ci_log_interacted_at" class="field" style="width:auto;">
                            <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-xs font-semibold text-zinc-950 whitespace-nowrap">Log</button>
                        </div>
                        <textarea id="ci_log_notes" rows="2" maxlength="3000" class="field" placeholder="Interaction notes"></textarea>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <div id="customerEditModal" class="fixed inset-0 z-[10000] hidden items-center justify-center bg-zinc-950/80 px-4" role="dialog" aria-modal="true" aria-labelledby="customerEditTitle">
        <div class="absolute inset-0 customer-interaction-overlay"></div>
        <div class="relative z-10 w-full max-w-2xl max-h-[92vh] overflow-y-auto rounded-3xl border border-zinc-700 bg-zinc-900 shadow-2xl shadow-black/40">
            <div class="p-5 border-b border-zinc-800 flex items-center justify-between">
                <h2 id="customerEditTitle" class="text-lg font-semibold text-white">Edit Customer</h2>
                <button type="button" class="rounded-md border border-zinc-700 px-3 py-1.5 text-xs text-zinc-300" onclick="closeCustomerEditModal()">Close</button>
            </div>
            <form id="customerEditForm" class="p-5 space-y-4" onsubmit="return submitCustomerEditForm(event)">
                <input type="hidden" id="ci_edit_customer_id" value="0">
                <div id="customerEditError" class="hidden rounded-lg border border-red-500/30 bg-red-500/10 px-3 py-2 text-xs text-red-400"></div>
                <div class="grid gap-4 md:grid-cols-2">
                    <div><label class="label">First Name</label><input class="field" type="text" id="ci_edit_first_name" maxlength="255"></div>
                    <div><label class="label">Last Name</label><input class="field" type="text" id="ci_edit_last_name" maxlength="255"></div>
                    <div><label class="label">Company</label><input class="field" type="text" id="ci_edit_company" maxlength="255"></div>
                    <div><label class="label">Phone</label><input class="field" type="text" id="ci_edit_phone" maxlength="100"></div>
                    <div class="md:col-span-2"><label class="label">Email</label><input class="field" type="email" id="ci_edit_email" maxlength="255"></div>
                    <div class="md:col-span-2"><label class="label">Address</label><input class="field" type="text" id="ci_edit_address" maxlength="255"></div>
                    <div><label class="label">City</label><input class="field" type="text" id="ci_edit_city" maxlength="100"></div>
                    <div><label class="label">State</label><input class="field" type="text" id="ci_edit_state" maxlength="50"></div>
                    <div><label class="label">Zip</label><input class="field" type="text" id="ci_edit_zip" maxlength="20"></div>
                    <div class="md:col-span-2"><label class="label">Notes</label><textarea class="field" id="ci_edit_notes" rows="4" maxlength="5000"></textarea></div>
                </div>
                <div class="flex justify-end gap-3 pt-2">
                    <button type="button" class="rounded-lg border border-zinc-700 bg-zinc-800 px-4 py-2 text-sm text-zinc-300" onclick="closeCustomerEditModal()">Cancel</button>
                    <button type="submit" class="rounded-lg bg-cyan-500 px-4 py-2 text-sm font-semibold text-zinc-950 hover:bg-cyan-400">Save Customer</button>
                </div>
            </form>
        </div>
    </div>

    <style>
        body.modal-open { overflow: hidden; }
        #customerDetailsModal .field,
        #customerEditModal .field {
            width: 100%;
            border: 1px solid rgb(63,63,70);
            background: rgb(9,9,11);
            color: #fff;
            border-radius: .5rem;
            padding: .55rem .75rem;
            font-size: .875rem;
        }
        #customerDetailsModal .label,
        #customerEditModal .label {
            font-size: .72rem;
            letter-spacing: .06em;
            text-transform: uppercase;
            color: rgb(161,161,170);
            margin-bottom: .35rem;
            display: block;
            font-weight: 600;
        }
    </style>

    <script>
    (function () {
        if (window.__customerInteractionModuleLoaded) {
            return;
        }
        window.__customerInteractionModuleLoaded = true;

        const CI_CSRF = <?= json_encode($csrf, JSON_UNESCAPED_UNICODE) ?>;
        const CI_ENDPOINT = <?= json_encode($ajaxEndpoint, JSON_UNESCAPED_UNICODE) ?>;
        let ciCurrentCustomerId = 0;
        let ciCurrentRecord = null;

        function ciEscapeHtml(value) {
            return String(value || '')
                .replaceAll('&', '&amp;')
                .replaceAll('<', '&lt;')
                .replaceAll('>', '&gt;')
                .replaceAll('"', '&quot;')
                .replaceAll("'", '&#039;');
        }

        function ciGetLaNowDateTimeLocal() {
            return new Intl.DateTimeFormat('sv-SE', {
                timeZone: 'America/Los_Angeles',
                year: 'numeric', month: '2-digit', day: '2-digit',
                hour: '2-digit', minute: '2-digit', hour12: false
            }).format(new Date()).replace(' ', 'T');
        }

        function ciShowError(elId, message) {
            const el = document.getElementById(elId);
            if (!el) return;
            if (message) {
                el.textContent = message;
                el.classList.remove('hidden');
            } else {
                el.textContent = '';
                el.classList.add('hidden');
            }
        }

        function ciApiCall(action, payload) {
            return fetch(CI_ENDPOINT, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    'X-CSRF-Token': CI_CSRF
                },
                body: JSON.stringify(Object.assign({ action: action }, payload))
            }).then(function (res) {
                return res.json().then(function (data) {
                    if (!res.ok || !data.success) {
                        throw new Error(data.error || 'Request failed.');
                    }
                    return data;
                });
            });
        }

        window.renderCustomerInteractions = function (interactions) {
            const history = document.getElementById('ci_details_interactions');
            if (!history) return;
            if (!Array.isArray(interactions) || interactions.length === 0) {
                history.innerHTML = '<p class="text-xs text-zinc-500">No interactions logged.</p>';
                return;
            }
            history.innerHTML = interactions.map(function (interaction) {
                return '<div class="text-xs text-zinc-300 border border-zinc-800 rounded-lg px-2 py-1.5">'
                    + '<div><span class="text-cyan-300 uppercase">' + ciEscapeHtml(interaction.interaction_type || '') + '</span> · ' + ciEscapeHtml(interaction.interacted_at_display || interaction.interacted_at || '') + '</div>'
                    + '<div class="text-zinc-400">' + ciEscapeHtml(interaction.outcome || '') + '</div>'
                    + '<div class="text-zinc-500">' + ciEscapeHtml(interaction.interaction_notes || '') + '</div>'
                    + '</div>';
            }).join('');
        };

        function ciPopulateDetails(record) {
            const c = record.customer;
            document.getElementById('ci_details_name').textContent = ((c.first_name || '') + ' ' + (c.last_name || '')).trim() || '—';
            document.getElementById('ci_details_company').textContent = c.company || '—';
            document.getElementById('ci_details_phone').textContent = c.phone || '—';
            document.getElementById('ci_details_email').textContent = c.email || '—';
            const addressParts = [c.address, c.city, c.state, c.zip].filter(Boolean);
            document.getElementById('ci_details_address').textContent = addressParts.length ? addressParts.join(', ') : '—';
            document.getElementById('ci_details_status').textContent = c.status || '—';
            document.getElementById('ci_details_last_called_at').textContent = c.last_called_at_display || '—';
            document.getElementById('ci_details_last_emailed_at').textContent = c.last_emailed_at_display || '—';
            document.getElementById('ci_details_notes').textContent = c.notes || 'No notes yet.';
            document.getElementById('ci_log_customer_id').value = String(c.id);
            document.getElementById('ci_log_interacted_at').value = ciGetLaNowDateTimeLocal();
            window.renderCustomerInteractions(record.interactions);
        }

        window.openCustomerDetailsModal = function (customerId) {
            customerId = parseInt(customerId, 10);
            if (!customerId) return;
            ciCurrentCustomerId = customerId;

            const modal = document.getElementById('customerDetailsModal');
            const loading = document.getElementById('customerDetailsLoading');
            const body = document.getElementById('customerDetailsBody');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
            document.body.classList.add('modal-open');
            loading.classList.remove('hidden');
            body.classList.add('hidden');
            ciShowError('customerDetailsError', '');

            ciApiCall('get_customer', { customer_id: customerId }).then(function (data) {
                ciCurrentRecord = data.record;
                ciPopulateDetails(data.record);
                loading.classList.add('hidden');
                body.classList.remove('hidden');
            }).catch(function (err) {
                loading.classList.add('hidden');
                ciShowError('customerDetailsError', err.message || 'Unable to load customer.');
            });
        };

        window.closeCustomerDetailsModal = function () {
            const modal = document.getElementById('customerDetailsModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
            document.body.classList.remove('modal-open');
        };

        window.openCustomerEditModal = function () {
            if (!ciCurrentRecord) return;
            const c = ciCurrentRecord.customer;
            document.getElementById('ci_edit_customer_id').value = String(c.id);
            document.getElementById('ci_edit_first_name').value = c.first_name || '';
            document.getElementById('ci_edit_last_name').value = c.last_name || '';
            document.getElementById('ci_edit_company').value = c.company || '';
            document.getElementById('ci_edit_phone').value = c.phone || '';
            document.getElementById('ci_edit_email').value = c.email || '';
            document.getElementById('ci_edit_address').value = c.address || '';
            document.getElementById('ci_edit_city').value = c.city || '';
            document.getElementById('ci_edit_state').value = c.state || '';
            document.getElementById('ci_edit_zip').value = c.zip || '';
            document.getElementById('ci_edit_notes').value = c.notes || '';
            ciShowError('customerEditError', '');
            const modal = document.getElementById('customerEditModal');
            modal.classList.remove('hidden');
            modal.classList.add('flex');
        };

        window.closeCustomerEditModal = function () {
            const modal = document.getElementById('customerEditModal');
            modal.classList.add('hidden');
            modal.classList.remove('flex');
        };

        window.submitCustomerInteractionLog = function (event) {
            event.preventDefault();
            const payload = {
                customer_id: parseInt(document.getElementById('ci_log_customer_id').value, 10) || ciCurrentCustomerId,
                interaction_type: document.getElementById('ci_log_type').value,
                new_status: document.getElementById('ci_log_new_status').value,
                interacted_at: document.getElementById('ci_log_interacted_at').value,
                interaction_notes: document.getElementById('ci_log_notes').value
            };
            ciApiCall('log_interaction', payload).then(function () {
                document.getElementById('ci_log_notes').value = '';
                document.getElementById('ci_log_new_status').value = '';
                return ciApiCall('get_customer', { customer_id: payload.customer_id });
            }).then(function (data) {
                ciCurrentRecord = data.record;
                ciPopulateDetails(data.record);
            }).catch(function (err) {
                ciShowError('customerDetailsError', err.message || 'Unable to log interaction.');
            });
            return false;
        };

        window.submitCustomerEditForm = function (event) {
            event.preventDefault();
            const payload = {
                customer_id: parseInt(document.getElementById('ci_edit_customer_id').value, 10) || ciCurrentCustomerId,
                first_name: document.getElementById('ci_edit_first_name').value,
                last_name: document.getElementById('ci_edit_last_name').value,
                company: document.getElementById('ci_edit_company').value,
                phone: document.getElementById('ci_edit_phone').value,
                email: document.getElementById('ci_edit_email').value,
                address: document.getElementById('ci_edit_address').value,
                city: document.getElementById('ci_edit_city').value,
                state: document.getElementById('ci_edit_state').value,
                zip: document.getElementById('ci_edit_zip').value,
                notes: document.getElementById('ci_edit_notes').value
            };
            ciApiCall('save_customer', payload).then(function () {
                window.closeCustomerEditModal();
                return ciApiCall('get_customer', { customer_id: payload.customer_id });
            }).then(function (data) {
                ciCurrentRecord = data.record;
                ciPopulateDetails(data.record);
            }).catch(function (err) {
                ciShowError('customerEditError', err.message || 'Unable to save customer.');
            });
            return false;
        };

        document.addEventListener('DOMContentLoaded', function () {
            const detailsModal = document.getElementById('customerDetailsModal');
            const editModal = document.getElementById('customerEditModal');
            if (detailsModal) {
                detailsModal.querySelector('.customer-interaction-overlay').addEventListener('click', window.closeCustomerDetailsModal);
            }
            if (editModal) {
                editModal.querySelector('.customer-interaction-overlay').addEventListener('click', window.closeCustomerEditModal);
            }
            document.addEventListener('keydown', function (event) {
                if (event.key !== 'Escape') return;
                if (editModal && !editModal.classList.contains('hidden')) {
                    window.closeCustomerEditModal();
                } else if (detailsModal && !detailsModal.classList.contains('hidden')) {
                    window.closeCustomerDetailsModal();
                }
            });
        });
    })();
    </script>
    <?php
}
