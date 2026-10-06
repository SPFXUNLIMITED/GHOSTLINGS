<?php

function prospectStatuses(): array
{
    return [
        'no_answer' => 'No Answer',
        'left_voicemail' => 'Left Voicemail',
        'disconnected' => 'Disconnected / Bad Number',
        'not_interested' => 'Not Interested',
        'has_provider' => 'Already Has Service Provider',
        'farms_out' => 'Farms Out Laser Work',
        'interested_service' => 'Interested in Service',
        'interested_machine' => 'Interested in Machine',
        'needs_follow_up' => 'Needs Follow Up',
    ];
}

function prospectNormalizePhone(string $value): string
{
    return preg_replace('/\D+/', '', $value) ?: '';
}

function prospectSanitizeField(string $value, int $maxLength = 255): string
{
    return mb_substr(trim($value), 0, $maxLength);
}

function prospectParseRawText(string $rawText): array
{
    $rawText = trim($rawText);
    $lines = preg_split('/\R+/', $rawText) ?: [];
    $cleanLines = array_values(array_filter(array_map(static fn($line) => trim((string) $line), $lines), static fn($line) => $line !== ''));

    $email = '';
    if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $rawText, $m)) {
        $email = strtolower($m[0]);
    }

    $website = '';
    if (preg_match('~\b((?:https?://)?(?:www\.)?[a-z0-9\-]+\.[a-z]{2,}(?:/[^\s]*)?)~i', $rawText, $m)) {
        $website = $m[1];
        if (!preg_match('~^https?://~i', $website)) {
            $website = 'https://' . ltrim($website, '/');
        }
    }

    $phone = '';
    if (preg_match('/(?:\+?1[\s\-.]?)?(?:\(?\d{3}\)?[\s\-.]?)\d{3}[\s\-.]?\d{4}/', $rawText, $m)) {
        $phone = $m[0];
    }

    $company = '';
    $contact = '';
    foreach ($cleanLines as $line) {
        if ($company === '' && preg_match('/^(?:company|business|organization)\s*:\s*(.+)$/i', $line, $m)) {
            $company = prospectSanitizeField($m[1]);
        }
        if ($contact === '' && preg_match('/^(?:contact|owner|manager|name)\s*:\s*(.+)$/i', $line, $m)) {
            $contact = prospectSanitizeField($m[1]);
        }
    }

    if ($company === '' && isset($cleanLines[0])) {
        $company = prospectSanitizeField($cleanLines[0]);
    }

    if ($contact === '') {
        foreach ($cleanLines as $line) {
            if (preg_match('/^[A-Z][a-z]+(?:\s+[A-Z][a-z]+){1,2}$/', $line)) {
                $contact = prospectSanitizeField($line);
                break;
            }
        }
    }

    $address = '';
    $city    = '';
    $state   = '';
    $zip     = '';

    // Locate the raw address line via label (Pass 1) or street pattern (Pass 2).
    $rawAddressLine = '';

    // Pass 1: explicit "Location" or "Address" label on its own line or with inline value.
    foreach ($cleanLines as $idx => $line) {
        if (preg_match('/^(?:location|address)\s*:?\s*(.*)$/i', $line, $m)) {
            $inline = trim($m[1]);
            if ($inline !== '') {
                $rawAddressLine = $inline;
            } elseif (isset($cleanLines[$idx + 1])) {
                $rawAddressLine = $cleanLines[$idx + 1];
            }
            break;
        }
    }

    // Pass 2: scan for a line that starts with a street number.
    if ($rawAddressLine === '') {
        $streetPattern = '/^\d+\s+\S.*(?:st\.?|ave\.?|blvd\.?|rd\.?|dr\.?|ln\.?|way|ct\.?|pl\.?|pkwy\.?|hwy\.?|washington|valley|mountain|hills?|park|circle|court|boulevard|avenue|street|road|drive|lane)/i';
        foreach ($cleanLines as $line) {
            if (preg_match($streetPattern, $line)) {
                $rawAddressLine = $line;
                break;
            }
        }
    }

    // Split the raw address line into street / city / state / zip.
    // Expected formats (all common US address forms):
    //   "18109 Mount Washington St. Fountain Valley, CA. 92708"
    //   "18109 Mount Washington St., Fountain Valley, CA 92708"
    //   "18109 Mount Washington St., Fountain Valley, CA, 92708"
    if ($rawAddressLine !== '') {
        // Pattern: everything up to the last street suffix, then city, state, zip.
        // We try a structured regex first that captures the four parts.
        $splitPattern = '/^(.+?(?:st\.?|ave\.?|blvd\.?|rd\.?|dr\.?|ln\.?|way|ct\.?|pl\.?|pkwy\.?|hwy\.?|suite\s+\S+|ste\.?\s+\S+|#\S+)(?:\s+(?:unit|apt\.?|apartment|bldg\.?|building|fl\.?|floor|room|rm\.?|dept\.?|department|lot|trlr\.?|trailer|sp\.?|space|box|pmb)\s+\S+)?)\s*[,\s]+\s*(.+?)\s*[,\s]+\s*([A-Z]{2})\.?\s*[,\s]*\s*(\d{5}(?:-\d{4})?)$/i';
        if (preg_match($splitPattern, $rawAddressLine, $m)) {
            $address = prospectSanitizeField(rtrim($m[1], ' ,'), 255);
            $city    = prospectSanitizeField($m[2], 100);
            $state   = prospectSanitizeField(rtrim($m[3], '.'), 50);
            $zip     = prospectSanitizeField($m[4], 20);
        } else {
            // Fallback: pull zip (5-digit) and two-letter state from the end, keep rest as street.
            $remaining = $rawAddressLine;
            if (preg_match('/\b(\d{5}(?:-\d{4})?)\b/', $remaining, $zm)) {
                $zip       = $zm[1];
                $remaining = trim(str_replace($zm[0], '', $remaining), " \t\n\r,.");
            }
            if (preg_match('/\b([A-Z]{2})\.?\b/', $remaining, $sm)) {
                $state     = rtrim($sm[1], '.');
                $remaining = trim(str_replace($sm[0], '', $remaining), " \t\n\r,.");
            }
            // Try to split remaining into street + city at the last comma.
            $commaPos = strrpos($remaining, ',');
            if ($commaPos !== false) {
                $address = prospectSanitizeField(substr($remaining, 0, $commaPos), 255);
                $city    = prospectSanitizeField(substr($remaining, $commaPos + 1), 100);
            } else {
                $address = prospectSanitizeField($remaining, 255);
            }
        }
    }

    $status = 'no_answer';
    $lower = strtolower($rawText);
    if (str_contains($lower, 'not interested')) {
        $status = 'not_interested';
    } elseif (str_contains($lower, 'farms out') || str_contains($lower, 'farms laser')) {
        $status = 'farms_out';
    } elseif (str_contains($lower, 'already has') || str_contains($lower, 'has provider') || str_contains($lower, 'current provider')) {
        $status = 'has_provider';
    } elseif (str_contains($lower, 'interested in machine') || str_contains($lower, 'buy a laser') || str_contains($lower, 'purchase machine')) {
        $status = 'interested_machine';
    } elseif (str_contains($lower, 'interested in service') || str_contains($lower, 'quote for service') || str_contains($lower, 'interested in getting')) {
        $status = 'interested_service';
    } elseif (str_contains($lower, 'follow up') || str_contains($lower, 'follow-up')) {
        $status = 'needs_follow_up';
    } elseif (str_contains($lower, 'left voicemail') || str_contains($lower, 'voicemail')) {
        $status = 'left_voicemail';
    } elseif (str_contains($lower, 'disconnected') || str_contains($lower, 'bad number') || str_contains($lower, 'wrong number')) {
        $status = 'disconnected';
    } elseif (str_contains($lower, 'called') || str_contains($lower, 'emailed') || str_contains($lower, 'contacted')) {
        $status = 'contacted';
    }

    $confidence = 0.35;
    foreach ([$company, $contact, $phone, $email, $website, $address, $city, $state, $zip] as $field) {
        if (trim((string) $field) !== '') {
            $confidence += 0.12;
        }
    }
    $confidence = max(0.0, min(0.95, $confidence));

    $errors = [];
    if ($company === '') {
        $errors[] = 'Company could not be confidently detected.';
    }
    if ($contact === '') {
        $errors[] = 'Contact name could not be confidently detected.';
    }

    $notes = '';
    foreach ($cleanLines as $line) {
        if (preg_match('/^(?:notes?|summary)\s*:\s*(.+)$/i', $line, $m)) {
            $notes = prospectSanitizeField($m[1], 10000);
            break;
        }
    }

    return [
        'fields' => [
            'company' => $company,
            'contact_name' => $contact,
            'phone' => prospectSanitizeField($phone, 100),
            'email' => prospectSanitizeField($email),
            'website' => prospectSanitizeField($website),
            'address' => $address,
            'city'    => $city,
            'state'   => $state,
            'zip'     => $zip,
            'status' => $status,
            'notes' => $notes,
        ],
        'confidence' => round($confidence * 100, 2),
        'provider' => 'heuristic-ai',
        'errors' => $errors,
    ];
}

/**
 * Look for an existing non-archived prospect whose company name closely
 * matches $company (case-insensitive exact, then partial containment).
 * Returns id, company, phone, last_called_at, or null if none found.
 */
function prospectFindSimilarByCompany(PDO $pdo, string $company): ?array
{
    $company = trim($company);
    if ($company === '') {
        return null;
    }

    // 1. Exact case-insensitive match.
    $stmt = $pdo->prepare("
        SELECT id, company, phone, last_called_at
        FROM prospects
        WHERE is_archived = 0
          AND LOWER(company) = LOWER(:company)
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmt->execute([':company' => $company]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return $row;
    }

    // 2. Partial containment: stored name contains parsed name, or vice-versa.
    $stmt = $pdo->prepare("
        SELECT id, company, phone, last_called_at
        FROM prospects
        WHERE is_archived = 0
          AND (
              LOWER(company) LIKE LOWER(:like_parsed)
              OR LOWER(:company) LIKE CONCAT('%', LOWER(company), '%')
          )
        ORDER BY id ASC
        LIMIT 1
    ");
    $stmt->execute([
        ':like_parsed' => '%' . $company . '%',
        ':company'     => $company,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}
