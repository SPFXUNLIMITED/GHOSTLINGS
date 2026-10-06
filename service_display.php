<?php
/**
 * Shared, display-only helpers for turning the numeric service IDs stored in
 * service_requests.services (a JSON array) back into human-readable service
 * names for technician-facing pages (schedule cards, job details modals, and
 * the technician dashboard).
 *
 * Nothing here modifies storage: IDs stay numeric in the database — this is
 * purely a presentation-layer lookup. Results are cached per request so a
 * page rendering many jobs never queries the services table repeatedly, and
 * any ID without a matching row renders as "Unknown service #N" rather than
 * a blank or a fatal error.
 */

/**
 * Resolve a set of numeric service IDs to an id => service_name map.
 *
 * IDs already resolved earlier in the request come from a static cache; the
 * remainder is fetched in a single batched query, so the services table is
 * hit at most once per distinct ID per request. An ID with no matching row
 * maps to "Unknown service #N". If the services table itself is unavailable
 * the lookup degrades gracefully to the same fallback labels instead of
 * raising an exception.
 *
 * @param PDO                 $pdo
 * @param array<int|mixed>    $ids  Values that are (or cast to) service IDs.
 *
 * @return array<int,string>  id => service_name (or "Unknown service #N").
 */
function serviceNamesForIds(PDO $pdo, array $ids): array
{
    static $cache = null; // id => service_name, memoized for this request.
    if ($cache === null) {
        $cache = [];
        try {
            // Warm the cache with the whole catalog in one query — it is
            // small, and it guarantees a page full of jobs never re-queries.
            foreach ($pdo->query('SELECT id, service_name FROM services')->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $cache[(int) $row['id']] = (string) $row['service_name'];
            }
        } catch (Throwable $e) {
            // Display-only lookup: degrade to "Unknown service #N" labels.
        }
    }

    $names = [];
    foreach ($ids as $id) {
        $id        = (int) $id;
        $names[$id] = $cache[$id] ?? sprintf('Unknown service #%d', $id);
    }

    return $names;
}

/**
 * Format a job's raw `services` JSON value as a comma-separated list of
 * service names for display.
 *
 * Entries are normally numeric services.id values (post-repair data) and are
 * resolved via serviceNamesForIds(); legacy rows whose services column still
 * contains service_name strings are passed through unchanged so pre-repair
 * rows render exactly as before.
 */
function formatServicesListForDisplay(PDO $pdo, $servicesJson): string
{
    $raw = trim((string) ($servicesJson ?? ''));
    if ($raw === '') {
        return '';
    }

    $entries = json_decode($raw, true);
    if (!is_array($entries) || $entries === []) {
        return '';
    }

    $numericIds = [];
    foreach ($entries as $entry) {
        if (is_numeric($entry)) {
            $numericIds[] = (int) $entry;
        }
    }
    $nameById = $numericIds !== [] ? serviceNamesForIds($pdo, $numericIds) : [];

    $names = [];
    foreach ($entries as $entry) {
        if (is_numeric($entry)) {
            $names[] = $nameById[(int) $entry] ?? sprintf('Unknown service #%d', (int) $entry);
            continue;
        }
        $names[] = (string) $entry; // Legacy service_name string.
    }

    return implode(', ', $names);
}
