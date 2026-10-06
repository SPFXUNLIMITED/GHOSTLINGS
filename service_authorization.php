<?php

require_once __DIR__ . '/service_display.php';

function ensureServiceAuthorizationSchema(PDO $pdo): void
{
    $pdo->exec(
        "CREATE TABLE IF NOT EXISTS service_authorizations (
            id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            service_request_id INT UNSIGNED NOT NULL,
            agreement_type VARCHAR(50) NOT NULL DEFAULT 'service_authorization',
            agreement_summary VARCHAR(255) NOT NULL,
            scope_of_work MEDIUMTEXT NOT NULL,
            signature_path VARCHAR(255) NOT NULL,
            signature_sha256 CHAR(64) NOT NULL,
            signed_at VARCHAR(40) NOT NULL,
            signed_latitude DECIMAL(10,7) NULL,
            signed_longitude DECIMAL(10,7) NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_service_authorizations_request (service_request_id),
            INDEX idx_service_authorizations_type (agreement_type)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4"
    );

    $uniqueIndexExistsStmt = $pdo->query("
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'service_authorizations'
          AND INDEX_NAME = 'uniq_service_authorization_request_type'
    ");
    if ((int) $uniqueIndexExistsStmt->fetchColumn() > 0) {
        $pdo->exec("ALTER TABLE service_authorizations DROP INDEX uniq_service_authorization_request_type");
    }

    $requestIndexExistsStmt = $pdo->query("
        SELECT COUNT(*)
        FROM information_schema.STATISTICS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = 'service_authorizations'
          AND INDEX_NAME = 'idx_service_authorizations_request'
    ");
    if ((int) $requestIndexExistsStmt->fetchColumn() === 0) {
        $pdo->exec("ALTER TABLE service_authorizations ADD INDEX idx_service_authorizations_request (service_request_id)");
    }
}

function serviceAuthorizationSummaryLine(): string
{
    return 'The customer authorizes the technician to perform the listed work described below.';
}

function serviceAuthorizationClauses(): array
{
    return [
        'The customer authorizes Ghost Laser to inspect, diagnose, and perform the approved service described in the Scope of Work.',
        'The customer agrees to pay for all parts, labor, travel, and related service charges required to complete the authorized work.',
        'The customer acknowledges that the equipment may have pre-existing wear, cosmetic issues, or damage that is unrelated to the authorized service.',
        'The customer waives claims arising solely from normal wear, hidden defects, or conditions discovered during service that are not caused by Ghost Laser negligence.',
    ];
}

function serviceAuthorizationStorageRoot(): string
{
    return dirname(__DIR__) . '/uploads/service-authorizations';
}

function serviceAuthorizationSignatureRoot(): string
{
    return serviceAuthorizationStorageRoot() . '/signatures';
}

function serviceAuthorizationPhotoRoot(): string
{
    return serviceAuthorizationStorageRoot() . '/job-photos';
}

function serviceAuthorizationPhotoTempRoot(): string
{
    return serviceAuthorizationStorageRoot() . '/tmp';
}

function serviceAuthorizationEnsureDirectory(string $path): void
{
    if (is_dir($path)) {
        return;
    }

    if (!mkdir($path, 0775, true) && !is_dir($path)) {
        throw new RuntimeException('Unable to create authorization storage directory.');
    }
}

function serviceAuthorizationNormalizeWhitespace(string $value): string
{
    $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
    $value = preg_replace("/[ \t]+/", ' ', $value) ?? $value;
    $value = preg_replace("/\n{3,}/", "\n\n", $value) ?? $value;
    return trim($value);
}

function serviceAuthorizationNormalizeTextarea(string $value): string
{
    $value = trim(str_replace(["\r\n", "\r"], "\n", $value));
    return preg_replace("/\n{3,}/", "\n\n", $value) ?? $value;
}

function serviceAuthorizationNormalizeStoredPhotoPath(string $path, bool $allowPending = false): string
{
    $path = ltrim(str_replace('\\', '/', trim($path)), '/');
    if (strpos($path, 'uploads/service-authorizations/job-photos/') === 0) {
        return $path;
    }
    if ($allowPending && strpos($path, 'uploads/service-authorizations/tmp/') === 0) {
        return $path;
    }

    return '';
}

function serviceAuthorizationDecodeJobPhotos($value, bool $allowPending = false): array
{
    $items = [];
    if (is_array($value)) {
        $items = $value;
    } elseif (is_string($value)) {
        $trimmed = trim($value);
        if ($trimmed !== '') {
            $decoded = json_decode($trimmed, true);
            if (is_array($decoded)) {
                $items = $decoded;
            }
        }
    }

    $paths = [];
    foreach ($items as $item) {
        if (!is_string($item)) {
            continue;
        }
        $path = serviceAuthorizationNormalizeStoredPhotoPath($item, $allowPending);
        if ($path === '' || in_array($path, $paths, true)) {
            continue;
        }
        $paths[] = $path;
    }

    return $paths;
}

function serviceAuthorizationEncodeJobPhotos(array $paths, bool $allowPending = false): ?string
{
    $paths = serviceAuthorizationDecodeJobPhotos($paths, $allowPending);
    return $paths === [] ? null : json_encode($paths, JSON_UNESCAPED_SLASHES);
}

function serviceAuthorizationJobPhotoPublicUrl(string $relativePath): string
{
    return '/' . ltrim(str_replace('\\', '/', $relativePath), '/');
}

function serviceAuthorizationBuildJobPhotoPayloads(array $paths): array
{
    $payloads = [];
    foreach (serviceAuthorizationDecodeJobPhotos($paths) as $path) {
        $payloads[] = [
            'path' => $path,
            'url' => serviceAuthorizationJobPhotoPublicUrl($path),
        ];
    }

    return $payloads;
}

function serviceAuthorizationEnsureSentence(string $label, string $value): string
{
    $value = serviceAuthorizationNormalizeWhitespace($value);
    if ($value === '') {
        return '';
    }

    $sentence = $label . ': ' . $value;
    if (!preg_match('/[.!?]$/', $sentence)) {
        $sentence .= '.';
    }

    return $sentence;
}

function serviceAuthorizationBuildHeadingBlock(string $heading, string $value): string
{
    $value = serviceAuthorizationNormalizeTextarea($value);
    if ($value === '') {
        return '';
    }

    return $heading . "\n" . $value;
}

function serviceAuthorizationPrimaryProblemText(array $job): string
{
    $problem = (string) ($job['problem'] ?? '');
    if (trim($problem) !== '') {
        return $problem;
    }

    return (string) ($job['problem_details'] ?? '');
}

function serviceAuthorizationFormatServices(PDO $pdo, $services): string
{
    if ($services === null) {
        return '';
    }

    $raw = trim((string) $services);
    if ($raw === '') {
        return '';
    }

    $decoded = json_decode($raw, true);
    if (is_array($decoded)) {
        return formatServicesListForDisplay($pdo, $raw);
    }

    return $raw;
}

function serviceAuthorizationFormatServiceAddress(array $job): string
{
    $parts = array_filter([
        trim((string) ($job['address'] ?? $job['destination_street'] ?? '')),
        trim((string) ($job['city'] ?? $job['destination_city'] ?? '')),
        trim((string) ($job['state'] ?? $job['destination_state'] ?? '')),
        trim((string) ($job['zip'] ?? $job['destination_zip'] ?? '')),
    ], static fn (string $part): bool => $part !== '');

    return implode(', ', $parts);
}

function serviceAuthorizationBuildScopeOfWork(PDO $pdo, array $job): string
{
    $parts = [];

    $services = serviceAuthorizationFormatServices($pdo, $job['services'] ?? null);
    if ($services !== '') {
        $parts[] = serviceAuthorizationEnsureSentence('Requested services', $services);
    }

    $problemSummary = trim((string) ($job['problem_summary'] ?? ''));
    if ($problemSummary !== '') {
        $parts[] = serviceAuthorizationEnsureSentence('Issue summary', $problemSummary);
    }

    $problemDetails = trim(serviceAuthorizationPrimaryProblemText($job));
    if ($problemDetails !== '' && strcasecmp($problemDetails, $problemSummary) !== 0) {
        $parts[] = serviceAuthorizationEnsureSentence('Job description', $problemDetails);
    }

    $technicianNotes = (string) ($job['technician_notes'] ?? '');
    $technicianNotesBlock = serviceAuthorizationBuildHeadingBlock('Technician notes', $technicianNotes);
    if ($technicianNotesBlock !== '') {
        $parts[] = $technicianNotesBlock;
    }

    $equipment = implode(' ', array_filter([
        trim((string) ($job['laser_brand'] ?? '')),
        trim((string) ($job['laser_model'] ?? '')),
        trim((string) ($job['laser_watts'] ?? '')),
    ], static fn (string $part): bool => $part !== ''));
    if ($equipment !== '') {
        $parts[] = serviceAuthorizationEnsureSentence('Equipment', $equipment);
    }

    $address = serviceAuthorizationFormatServiceAddress($job);
    if ($address !== '') {
        $parts[] = serviceAuthorizationEnsureSentence('Service location', $address);
    }

    if ($parts === []) {
        $parts[] = 'Perform the service request currently listed for this visit.';
    }

    return implode("\n\n", $parts);
}

function serviceAuthorizationFetchJob(PDO $pdo, int $serviceRequestId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            sr.id,
            sr.problem_summary,
            sr.problem,
            sr.problem_details,
            sr.technician_notes,
            sr.job_photos,
            sr.services,
            sr.laser_brand,
            sr.laser_model,
            sr.laser_watts,
            sr.destination_street,
            sr.destination_city,
            sr.destination_state,
            sr.destination_zip,
            COALESCE(c.first_name, '') AS first_name,
            COALESCE(c.last_name, '') AS last_name,
            COALESCE(c.address, sr.destination_street) AS address,
            COALESCE(c.city, sr.destination_city) AS city,
            COALESCE(c.state, sr.destination_state) AS state,
            COALESCE(c.zip, sr.destination_zip) AS zip
         FROM service_requests sr
         LEFT JOIN customers c ON c.id = sr.customer_id
         WHERE sr.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $serviceRequestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function serviceAuthorizationFetchLatestByJobIds(PDO $pdo, array $serviceRequestIds): array
{
    $serviceRequestIds = array_values(array_unique(array_filter(array_map('intval', $serviceRequestIds), static fn (int $id): bool => $id > 0)));
    if ($serviceRequestIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($serviceRequestIds), '?'));
    $stmt = $pdo->prepare(
        "SELECT sa.*
         FROM service_authorizations sa
         INNER JOIN (
            SELECT MAX(id) AS latest_id
            FROM service_authorizations
            WHERE agreement_type = 'service_authorization'
              AND service_request_id IN ($placeholders)
            GROUP BY service_request_id
         ) latest ON latest.latest_id = sa.id"
    );
    $stmt->execute($serviceRequestIds);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[(int) $row['service_request_id']] = $row;
    }

    return $rows;
}

function serviceAuthorizationParseSignedAt(?string $signedAt): DateTimeImmutable
{
    if (is_string($signedAt) && trim($signedAt) !== '') {
        try {
            return new DateTimeImmutable($signedAt);
        } catch (Throwable $e) {
        }
    }

    return new DateTimeImmutable('now', new DateTimeZone('UTC'));
}

function serviceAuthorizationFormatSignedAtDisplay(?string $signedAt): string
{
    try {
        return serviceAuthorizationParseSignedAt($signedAt)
            ->setTimezone(new DateTimeZone('America/Los_Angeles'))
            ->format('M j, Y g:i A T');
    } catch (Throwable $e) {
        return trim((string) $signedAt);
    }
}

function serviceAuthorizationDecodeSignaturePng(string $signatureDataUrl): string
{
    $signatureDataUrl = trim($signatureDataUrl);
    if (!preg_match('/^data:image\/png;base64,([a-zA-Z0-9+\/=]+)$/', $signatureDataUrl, $matches)) {
        throw new InvalidArgumentException('Signature must be a PNG image.');
    }

    $binary = base64_decode($matches[1], true);
    if ($binary === false || $binary === '') {
        throw new InvalidArgumentException('Signature image could not be decoded.');
    }

    $imageInfo = @getimagesizefromstring($binary);
    if ($imageInfo === false || ($imageInfo['mime'] ?? '') !== 'image/png') {
        throw new InvalidArgumentException('Signature image must be a valid PNG.');
    }

    if (strlen($binary) > 5 * 1024 * 1024) {
        throw new InvalidArgumentException('Signature image is too large.');
    }

    return $binary;
}

function serviceAuthorizationPrepareSignaturePaths(int $serviceRequestId): array
{
    serviceAuthorizationEnsureDirectory(serviceAuthorizationSignatureRoot());

    $fileName = sprintf(
        'service-auth-%d-%s-%s.png',
        $serviceRequestId,
        gmdate('YmdHis'),
        bin2hex(random_bytes(6))
    );
    $relativePath = 'uploads/service-authorizations/signatures/' . $fileName;
    $absolutePath = dirname(__DIR__) . '/' . $relativePath;

    return [
        'relative' => $relativePath,
        'absolute' => $absolutePath,
        'temp' => $absolutePath . '.tmp',
    ];
}

function serviceAuthorizationWriteTempSignature(string $tempPath, string $binary): void
{
    if (file_put_contents($tempPath, $binary, LOCK_EX) === false) {
        throw new RuntimeException('Unable to save signature image.');
    }
}

function serviceAuthorizationSaveTechnicianNotes(PDO $pdo, int $serviceRequestId, string $technicianNotes, array $job): array
{
    if ((int) ($job['id'] ?? 0) !== $serviceRequestId) {
        throw new RuntimeException('Service request not found.');
    }

    $normalizedNotes = serviceAuthorizationNormalizeTextarea($technicianNotes);
    $stmt = $pdo->prepare(
        "UPDATE service_requests
         SET technician_notes = :technician_notes
         WHERE id = :id
         LIMIT 1"
    );
    if ($normalizedNotes === '') {
        $stmt->bindValue(':technician_notes', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':technician_notes', $normalizedNotes, PDO::PARAM_STR);
    }
    $stmt->bindValue(':id', $serviceRequestId, PDO::PARAM_INT);
    $stmt->execute();

    $job['technician_notes'] = $normalizedNotes;

    return [
        'service_request_id' => $serviceRequestId,
        'technician_notes' => $normalizedNotes,
        'scope_of_work' => serviceAuthorizationBuildScopeOfWork($pdo, $job),
    ];
}

function serviceAuthorizationNormalizeUploadedFilesArray(?array $filesSpec): array
{
    if (!is_array($filesSpec) || !isset($filesSpec['name'])) {
        return [];
    }

    $names = is_array($filesSpec['name']) ? $filesSpec['name'] : [$filesSpec['name']];
    $types = is_array($filesSpec['type'] ?? null) ? $filesSpec['type'] : [$filesSpec['type'] ?? ''];
    $tmpNames = is_array($filesSpec['tmp_name'] ?? null) ? $filesSpec['tmp_name'] : [$filesSpec['tmp_name'] ?? ''];
    $errors = is_array($filesSpec['error'] ?? null) ? $filesSpec['error'] : [$filesSpec['error'] ?? UPLOAD_ERR_NO_FILE];
    $sizes = is_array($filesSpec['size'] ?? null) ? $filesSpec['size'] : [$filesSpec['size'] ?? 0];

    $files = [];
    foreach ($names as $index => $name) {
        $files[] = [
            'name' => (string) $name,
            'type' => (string) ($types[$index] ?? ''),
            'tmp_name' => (string) ($tmpNames[$index] ?? ''),
            'error' => (int) ($errors[$index] ?? UPLOAD_ERR_NO_FILE),
            'size' => (int) ($sizes[$index] ?? 0),
        ];
    }

    return $files;
}

function serviceAuthorizationPhotoExtensionForMime(string $mime): ?string
{
    $map = [
        'image/jpeg' => 'jpg',
        'image/png' => 'png',
        'image/webp' => 'webp',
    ];

    return $map[strtolower($mime)] ?? null;
}

function serviceAuthorizationDecodeUploadedPhoto(array $file): array
{
    $error = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($error === UPLOAD_ERR_NO_FILE) {
        throw new InvalidArgumentException('No photos were selected.');
    }
    if ($error !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException('One of the selected photos could not be uploaded.');
    }

    $size = (int) ($file['size'] ?? 0);
    if ($size <= 0) {
        throw new InvalidArgumentException('One of the selected photos is empty.');
    }
    if ($size > 10 * 1024 * 1024) {
        throw new InvalidArgumentException('Each photo must be 10MB or smaller.');
    }

    $tmpName = (string) ($file['tmp_name'] ?? '');
    if ($tmpName === '' || !is_file($tmpName)) {
        throw new InvalidArgumentException('A selected photo is unavailable.');
    }

    $binary = @file_get_contents($tmpName);
    if (!is_string($binary) || $binary === '') {
        throw new InvalidArgumentException('A selected photo could not be read.');
    }

    $imageInfo = @getimagesizefromstring($binary);
    $mime = strtolower((string) ($imageInfo['mime'] ?? ''));
    $extension = serviceAuthorizationPhotoExtensionForMime($mime);
    if ($extension === null) {
        throw new InvalidArgumentException('Photos must be JPG, PNG, or WebP images.');
    }

    $image = @imagecreatefromstring($binary);
    if ($image === false) {
        throw new InvalidArgumentException('One of the selected photos is not a valid image.');
    }
    imagedestroy($image);

    return [
        'binary' => $binary,
        'extension' => $extension,
    ];
}

function serviceAuthorizationPreparePhotoPaths(int $serviceRequestId, string $extension): array
{
    serviceAuthorizationEnsureDirectory(serviceAuthorizationPhotoRoot());
    serviceAuthorizationEnsureDirectory(serviceAuthorizationPhotoTempRoot());

    $fileName = sprintf(
        'job-photo-%d-%s-%s.%s',
        $serviceRequestId,
        gmdate('YmdHis'),
        bin2hex(random_bytes(6)),
        $extension
    );
    $relativePath = 'uploads/service-authorizations/job-photos/' . $fileName;
    $absolutePath = dirname(__DIR__) . '/' . $relativePath;
    $tempPath = tempnam(serviceAuthorizationPhotoTempRoot(), 'job-photo-');
    if ($tempPath === false) {
        throw new RuntimeException('Unable to prepare temporary photo storage.');
    }
    $tempRelativePath = 'uploads/service-authorizations/tmp/' . basename($tempPath);

    return [
        'relative' => $relativePath,
        'absolute' => $absolutePath,
        'temp' => $tempPath,
        'temp_relative' => $tempRelativePath,
    ];
}

function serviceAuthorizationPersistRawJobPhotos(PDO $pdo, int $serviceRequestId, array $paths): void
{
    $encoded = serviceAuthorizationEncodeJobPhotos($paths, true);
    $stmt = $pdo->prepare(
        "UPDATE service_requests
         SET job_photos = :job_photos,
             completion_certificate = NULL
         WHERE id = :id
         LIMIT 1"
    );
    if ($encoded === null) {
        $stmt->bindValue(':job_photos', null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(':job_photos', $encoded, PDO::PARAM_STR);
    }
    $stmt->bindValue(':id', $serviceRequestId, PDO::PARAM_INT);
    $stmt->execute();
}

function serviceAuthorizationPersistJobPhotos(PDO $pdo, int $serviceRequestId, array $paths): void
{
    serviceAuthorizationPersistRawJobPhotos($pdo, $serviceRequestId, $paths);
}

function serviceAuthorizationFetchJobPhotoState(PDO $pdo, int $serviceRequestId, bool $forUpdate = false): ?array
{
    $sql = "SELECT job_photos, completion_certificate
            FROM service_requests
            WHERE id = :id
            LIMIT 1";
    if ($forUpdate && (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
        $sql .= ' FOR UPDATE';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute([':id' => $serviceRequestId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function serviceAuthorizationDeleteStoredPathIfPresent(?string $relativePath, array $allowedPrefixes): void
{
    $relativePath = trim((string) $relativePath);
    if ($relativePath === '') {
        return;
    }

    $normalizedPath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $isAllowed = false;
    foreach ($allowedPrefixes as $prefix) {
        if (strpos($normalizedPath, $prefix) === 0) {
            $isAllowed = true;
            break;
        }
    }
    if (!$isAllowed) {
        error_log('serviceAuthorizationDeleteStoredPathIfPresent skipped unexpected path: ' . $normalizedPath);
        return;
    }

    try {
        $absolutePath = serviceAuthorizationResolveStoragePath($normalizedPath);
        if (is_file($absolutePath)) {
            @unlink($absolutePath);
        }
    } catch (Throwable $e) {
        error_log('serviceAuthorizationDeleteStoredPathIfPresent cleanup error: ' . $e->getMessage());
    }
}

function serviceAuthorizationDeleteStoredJobPhotoIfPresent(?string $relativePath): void
{
    serviceAuthorizationDeleteStoredPathIfPresent($relativePath, [
        'uploads/service-authorizations/job-photos/',
        'uploads/service-authorizations/tmp/',
    ]);
}

function serviceAuthorizationDeleteStoredCompletionCertificateIfPresent(?string $relativePath): void
{
    serviceAuthorizationDeleteStoredPathIfPresent($relativePath, [
        'uploads/service-authorizations/completion-certificates/',
    ]);
}

function serviceAuthorizationPublishStoredJobPhotos(array $storedPaths, array $createdPaths): array
{
    $replacements = [];
    foreach ($createdPaths as $pathSet) {
        $replacements[(string) $pathSet['temp_relative']] = (string) $pathSet['relative'];
    }

    $publishedPaths = [];
    foreach ($storedPaths as $path) {
        $candidate = $replacements[$path] ?? $path;
        if (!in_array($candidate, $publishedPaths, true)) {
            $publishedPaths[] = $candidate;
        }
    }

    foreach ($createdPaths as $pathSet) {
        if (!in_array($pathSet['relative'], $publishedPaths, true)) {
            $publishedPaths[] = $pathSet['relative'];
        }
    }

    return $publishedPaths;
}

function serviceAuthorizationFilterActiveStoredJobPhotos(array $paths): array
{
    $filtered = [];
    foreach (serviceAuthorizationDecodeJobPhotos($paths, true) as $path) {
        if (strpos($path, 'uploads/service-authorizations/tmp/') === 0) {
            $absolutePath = dirname(__DIR__) . '/' . $path;
            if (!is_file($absolutePath)) {
                continue;
            }
        }

        if (!in_array($path, $filtered, true)) {
            $filtered[] = $path;
        }
    }

    return $filtered;
}

function serviceAuthorizationFinalizeStoredPhoto(string $tempPath, string $absolutePath): void
{
    if (@rename($tempPath, $absolutePath)) {
        @chmod($absolutePath, 0664);
        return;
    }

    if (!@copy($tempPath, $absolutePath)) {
        throw new RuntimeException('Unable to finalize one of the photos.');
    }
    @chmod($absolutePath, 0664);
    if (!@unlink($tempPath)) {
        error_log('serviceAuthorizationFinalizeStoredPhoto temp cleanup warning: unable to remove ' . $tempPath);
    }
}

function serviceAuthorizationSaveJobPhotos(PDO $pdo, int $serviceRequestId, array $uploadedFiles, array $job): array
{
    if ((int) ($job['id'] ?? 0) !== $serviceRequestId) {
        throw new RuntimeException('Service request not found.');
    }

    $uploadedFiles = array_values(array_filter(
        $uploadedFiles,
        static fn (array $file): bool => (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE
    ));
    if ($uploadedFiles === []) {
        throw new InvalidArgumentException('No photos were selected.');
    }
    if (count($uploadedFiles) > 10) {
        throw new InvalidArgumentException('Upload up to 10 photos at a time.');
    }

    $createdPaths = [];
    $startedTransaction = false;
    $pendingCommitted = false;
    $previousCertificatePath = null;
    $publishedPaths = [];
    try {
        foreach ($uploadedFiles as $file) {
            $decoded = serviceAuthorizationDecodeUploadedPhoto($file);
            $paths = serviceAuthorizationPreparePhotoPaths($serviceRequestId, $decoded['extension']);
            if (file_put_contents($paths['temp'], $decoded['binary'], LOCK_EX) === false) {
                throw new RuntimeException('Unable to save one of the photos.');
            }
            $createdPaths[] = $paths;
        }

        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        $state = serviceAuthorizationFetchJobPhotoState($pdo, $serviceRequestId, true);
        if (!$state) {
            throw new RuntimeException('Service request not found.');
        }

        $activeStoredPaths = serviceAuthorizationFilterActiveStoredJobPhotos(
            serviceAuthorizationDecodeJobPhotos($state['job_photos'] ?? null, true)
        );
        $publishedStoredPaths = serviceAuthorizationDecodeJobPhotos($activeStoredPaths);
        if ((count($publishedStoredPaths) + count($createdPaths)) > 20) {
            throw new InvalidArgumentException('Each job can have up to 20 photos.');
        }

        $pendingPaths = array_values(array_unique(array_merge(
            $activeStoredPaths,
            array_column($createdPaths, 'temp_relative')
        )));
        $previousCertificatePath = $state['completion_certificate'] ?? null;
        serviceAuthorizationPersistRawJobPhotos($pdo, $serviceRequestId, $pendingPaths);

        if ($startedTransaction) {
            $pdo->commit();
        }
        $pendingCommitted = true;

        foreach ($createdPaths as $pathSet) {
            serviceAuthorizationFinalizeStoredPhoto($pathSet['temp'], $pathSet['absolute']);
        }

        $startedTransaction = false;
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }
        $state = serviceAuthorizationFetchJobPhotoState($pdo, $serviceRequestId, true);
        if (!$state) {
            throw new RuntimeException('Service request not found.');
        }
        $storedPaths = serviceAuthorizationDecodeJobPhotos($state['job_photos'] ?? null, true);
        $publishedPaths = serviceAuthorizationPublishStoredJobPhotos($storedPaths, $createdPaths);
        if (count(serviceAuthorizationDecodeJobPhotos($publishedPaths)) > 20) {
            throw new InvalidArgumentException('Each job can have up to 20 photos.');
        }
        serviceAuthorizationPersistRawJobPhotos($pdo, $serviceRequestId, $publishedPaths);

        if ($startedTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if ($pendingCommitted) {
            try {
                $cleanupStarted = false;
                if (!$pdo->inTransaction()) {
                    $pdo->beginTransaction();
                    $cleanupStarted = true;
                }
                $state = serviceAuthorizationFetchJobPhotoState($pdo, $serviceRequestId, true);
                if ($state) {
                    $cleanupTargets = array_merge(
                        array_column($createdPaths, 'temp_relative'),
                        array_column($createdPaths, 'relative')
                    );
                    $currentPaths = serviceAuthorizationDecodeJobPhotos($state['job_photos'] ?? null, true);
                    $currentPaths = array_values(array_filter(
                        $currentPaths,
                        static fn (string $path): bool => !in_array($path, $cleanupTargets, true)
                    ));
                    serviceAuthorizationPersistRawJobPhotos($pdo, $serviceRequestId, $currentPaths);
                }
                if ($cleanupStarted) {
                    $pdo->commit();
                }
            } catch (Throwable $cleanupError) {
                if ($cleanupStarted && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            }
        }
        foreach ($createdPaths as $path) {
            if (is_file($path['temp'] ?? '')) {
                @unlink($path['temp']);
            }
            if (is_file($path['absolute'] ?? '')) {
                @unlink($path['absolute']);
            }
        }
        throw $e;
    }

    serviceAuthorizationDeleteStoredCompletionCertificateIfPresent($previousCertificatePath);

    return [
        'service_request_id' => $serviceRequestId,
        'photos' => serviceAuthorizationBuildJobPhotoPayloads($publishedPaths),
    ];
}

function serviceAuthorizationRemoveJobPhoto(PDO $pdo, int $serviceRequestId, string $photoPath, array $job): array
{
    if ((int) ($job['id'] ?? 0) !== $serviceRequestId) {
        throw new RuntimeException('Service request not found.');
    }

    $photoPath = serviceAuthorizationNormalizeStoredPhotoPath($photoPath);
    if ($photoPath === '') {
        throw new InvalidArgumentException('Invalid photo path.');
    }

    $remainingPaths = [];
    $previousCertificatePath = null;
    $startedTransaction = false;

    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        $state = serviceAuthorizationFetchJobPhotoState($pdo, $serviceRequestId, true);
        if (!$state) {
            throw new RuntimeException('Service request not found.');
        }

        $existingPaths = serviceAuthorizationDecodeJobPhotos($state['job_photos'] ?? null, true);
        if (!in_array($photoPath, $existingPaths, true)) {
            throw new InvalidArgumentException('Photo not found.');
        }

        $remainingPaths = array_values(array_filter(
            $existingPaths,
            static fn (string $path): bool => $path !== $photoPath
        ));
        $previousCertificatePath = $state['completion_certificate'] ?? null;
        serviceAuthorizationPersistJobPhotos($pdo, $serviceRequestId, $remainingPaths);

        if ($startedTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }

    serviceAuthorizationDeleteStoredJobPhotoIfPresent($photoPath);
    serviceAuthorizationDeleteStoredCompletionCertificateIfPresent($previousCertificatePath);

    return [
        'service_request_id' => $serviceRequestId,
        'photos' => serviceAuthorizationBuildJobPhotoPayloads($remainingPaths),
    ];
}

function serviceAuthorizationHandleJobPhotoApiRequest(
    PDO $pdo,
    bool $hasDashboardAccess,
    bool $canAccessServiceRequest,
    int $serviceRequestId,
    string $action,
    string $csrfToken,
    string $sessionCsrf,
    ?array $job,
    array $uploadedFiles = [],
    ?string $photoPath = null
): array {
    if (!$hasDashboardAccess) {
        return [
            'status' => 401,
            'body' => ['success' => false, 'error' => 'Unauthorized'],
        ];
    }

    if ($serviceRequestId <= 0) {
        return [
            'status' => 400,
            'body' => ['success' => false, 'error' => 'Missing service_request_id'],
        ];
    }

    if ($sessionCsrf === '' || $csrfToken === '' || !hash_equals($sessionCsrf, $csrfToken)) {
        return [
            'status' => 403,
            'body' => ['success' => false, 'error' => 'Invalid security token. Reload the dashboard and try again.'],
        ];
    }

    if (!$canAccessServiceRequest) {
        return [
            'status' => 403,
            'body' => ['success' => false, 'error' => 'You do not have access to update this job.'],
        ];
    }

    if (!$job) {
        return [
            'status' => 404,
            'body' => ['success' => false, 'error' => 'Service request not found.'],
        ];
    }

    if ($action !== 'upload' && $action !== 'remove') {
        return [
            'status' => 400,
            'body' => ['success' => false, 'error' => 'Unsupported action.'],
        ];
    }

    try {
        $result = $action === 'remove'
            ? serviceAuthorizationRemoveJobPhoto($pdo, $serviceRequestId, (string) $photoPath, $job)
            : serviceAuthorizationSaveJobPhotos($pdo, $serviceRequestId, $uploadedFiles, $job);

        return [
            'status' => 200,
            'body' => [
                'success' => true,
                'service_request_id' => (int) $result['service_request_id'],
                'photos' => $result['photos'],
            ],
        ];
    } catch (InvalidArgumentException $e) {
        return [
            'status' => 400,
            'body' => ['success' => false, 'error' => $e->getMessage()],
        ];
    } catch (Throwable $e) {
        error_log('technician-job-photos-api error: ' . $e->getMessage());
        return [
            'status' => 500,
            'body' => ['success' => false, 'error' => 'Unable to update job photos right now.'],
        ];
    }
}

function serviceAuthorizationJobPhotoMethodNotAllowedResponse(): array
{
    return [
        'status' => 405,
        'headers' => ['Allow' => 'POST'],
        'body' => ['success' => false, 'error' => 'Method not allowed'],
    ];
}

function serviceAuthorizationSave(PDO $pdo, int $serviceRequestId, string $signatureDataUrl, ?float $latitude, ?float $longitude, ?string $signedAtInput): array
{
    $job = serviceAuthorizationFetchJob($pdo, $serviceRequestId);
    if (!$job) {
        throw new RuntimeException('Service request not found.');
    }

    $signatureBinary = serviceAuthorizationDecodeSignaturePng($signatureDataUrl);
    $signaturePaths  = serviceAuthorizationPrepareSignaturePaths($serviceRequestId);
    serviceAuthorizationWriteTempSignature($signaturePaths['temp'], $signatureBinary);
    $signedAt        = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DATE_ATOM);
    $scopeOfWork     = serviceAuthorizationBuildScopeOfWork($pdo, $job);
    $summaryLine     = serviceAuthorizationSummaryLine();
    $signatureSha256 = hash('sha256', $signatureBinary);
    $startedTransaction = false;
    $authorizationId = 0;
    $pendingSignaturePath = $signaturePaths['relative'] . '.tmp';

    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO service_authorizations
                (service_request_id, agreement_type, agreement_summary, scope_of_work, signature_path, signature_sha256, signed_at, signed_latitude, signed_longitude)
             VALUES
                (:service_request_id, 'service_authorization', :agreement_summary, :scope_of_work, :signature_path, :signature_sha256, :signed_at, :signed_latitude, :signed_longitude)"
        );
        $stmt->execute([
            ':service_request_id' => $serviceRequestId,
            ':agreement_summary'  => $summaryLine,
            ':scope_of_work'      => $scopeOfWork,
            ':signature_path'     => $pendingSignaturePath,
            ':signature_sha256'   => $signatureSha256,
            ':signed_at'          => $signedAt,
            ':signed_latitude'    => $latitude,
            ':signed_longitude'   => $longitude,
        ]);
        $authorizationId = (int) $pdo->lastInsertId();

        if (!rename($signaturePaths['temp'], $signaturePaths['absolute'])) {
            throw new RuntimeException('Unable to finalize signature image.');
        }

        $update = $pdo->prepare(
            "UPDATE service_authorizations
             SET signature_path = :signature_path
             WHERE id = :id
             LIMIT 1"
        );
        $update->execute([
            ':signature_path' => $signaturePaths['relative'],
            ':id' => $authorizationId,
        ]);

        if ($startedTransaction) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
        if ($startedTransaction && $pdo->inTransaction()) {
            $pdo->rollBack();
        }
        if (is_file($signaturePaths['temp'])) {
            @unlink($signaturePaths['temp']);
        }
        if (is_file($signaturePaths['absolute'])) {
            @unlink($signaturePaths['absolute']);
        }
        throw $e;
    }

    return serviceAuthorizationFetchById($pdo, $authorizationId) ?? [];
}

function serviceAuthorizationFetchById(PDO $pdo, int $authorizationId): ?array
{
    $stmt = $pdo->prepare(
        "SELECT
            sa.*,
            sr.id AS service_request_number,
            sr.technician_notes,
            sr.job_photos,
            COALESCE(NULLIF(TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))), ''), 'Customer') AS customer_name
         FROM service_authorizations sa
         JOIN service_requests sr ON sr.id = sa.service_request_id
         LEFT JOIN customers c ON c.id = sr.customer_id
         WHERE sa.id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $authorizationId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function serviceAuthorizationResolveStoragePath(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $baseRoot     = realpath(dirname(__DIR__));
    $uploadsRoot  = realpath(serviceAuthorizationStorageRoot());
    $absolutePath = $baseRoot . '/' . $relativePath;
    $resolved     = realpath($absolutePath);

    if (
        $resolved === false ||
        $uploadsRoot === false ||
        ($resolved !== $uploadsRoot && strpos($resolved, $uploadsRoot . DIRECTORY_SEPARATOR) !== 0)
    ) {
        throw new RuntimeException('Stored file is unavailable.');
    }

    return $resolved;
}

function serviceAuthorizationResolveSignaturePath(string $relativePath): string
{
    try {
        return serviceAuthorizationResolveStoragePath($relativePath);
    } catch (Throwable $e) {
        throw new RuntimeException('Signature file is unavailable.');
    }
}

function serviceAuthorizationFontPath(bool $bold = false): ?string
{
    $candidates = $bold
        ? [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans-Bold.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans-Bold.ttf',
        ]
        : [
            '/usr/share/fonts/truetype/dejavu/DejaVuSans.ttf',
            '/usr/share/fonts/dejavu/DejaVuSans.ttf',
        ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function serviceAuthorizationWrapText(?string $fontPath, int $fontSize, int $maxWidth, string $text): array
{
    $text = trim($text);
    if ($text === '') {
        return [''];
    }

    if ($fontPath === null || !function_exists('imagettfbbox')) {
        return preg_split("/\n/", wordwrap($text, 70), -1, PREG_SPLIT_NO_EMPTY) ?: [$text];
    }

    $lines = [];
    foreach (preg_split("/\n/", $text) ?: [] as $paragraph) {
        $paragraph = trim($paragraph);
        if ($paragraph === '') {
            $lines[] = '';
            continue;
        }

        $words = preg_split('/\s+/', $paragraph) ?: [];
        $line  = '';
        foreach ($words as $word) {
            $candidate = $line === '' ? $word : $line . ' ' . $word;
            $bbox = imagettfbbox($fontSize, 0, $fontPath, $candidate);
            $width = $bbox ? abs($bbox[2] - $bbox[0]) : 0;
            if ($line !== '' && $width > $maxWidth) {
                $lines[] = $line;
                $line = $word;
            } else {
                $line = $candidate;
            }
        }
        $lines[] = $line;
    }

    return $lines === [] ? [$text] : $lines;
}

function serviceAuthorizationRenderTextLine($image, ?string $fontPath, int $fontSize, int $x, int $y, int $color, string $text, int $builtInFont = 3): void
{
    if ($fontPath !== null && function_exists('imagettftext')) {
        imagettftext($image, $fontSize, 0, $x, $y, $color, $fontPath, $text);
        return;
    }

    imagestring($image, $builtInFont, $x, max(0, $y - 14), $text, $color);
}

function serviceAuthorizationRenderPages(array $authorization): array
{
    $pageWidth          = 1275;
    $pageHeight         = 1650;
    $marginX            = 90;
    $topMargin          = 110;
    $bottomMargin       = 90;
    $signatureBlockSize = 290;
    $bodyLimit          = $pageHeight - $bottomMargin - $signatureBlockSize;
    $bodyWidth          = $pageWidth - ($marginX * 2);
    $titleFont          = serviceAuthorizationFontPath(true);
    $bodyFont           = serviceAuthorizationFontPath(false);
    $pages              = [];

    $newPage = static function () use ($pageWidth, $pageHeight): array {
        $image = imagecreatetruecolor($pageWidth, $pageHeight);
        imageantialias($image, true);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, $pageWidth, $pageHeight, $white);

        return [
            'image' => $image,
            'y' => 110,
        ];
    };

    $page = $newPage();
    $black = imagecolorallocate($page['image'], 17, 24, 39);
    $muted = imagecolorallocate($page['image'], 75, 85, 99);
    $lineColor = imagecolorallocate($page['image'], 209, 213, 219);

    $ensureRoom = static function (array &$pageState, int $neededHeight) use (&$pages, $newPage, $bodyLimit): void {
        if (($pageState['y'] + $neededHeight) <= $bodyLimit) {
            return;
        }

        $pages[] = $pageState['image'];
        $pageState = $newPage();
    };

    $drawWrappedBlock = static function (array &$pageState, string $text, int $fontSize, int $lineHeight, int $color, bool $bold = false, int $after = 18) use ($bodyWidth, $marginX, $ensureRoom, $titleFont, $bodyFont): void {
        $fontPath = $bold ? $titleFont : $bodyFont;
        $lines = serviceAuthorizationWrapText($fontPath, $fontSize, $bodyWidth, $text);
        foreach ($lines as $line) {
            $ensureRoom($pageState, $lineHeight);
            serviceAuthorizationRenderTextLine($pageState['image'], $fontPath, $fontSize, $marginX, $pageState['y'], $color, $line, $bold ? 5 : 4);
            $pageState['y'] += $lineHeight;
        }
        $pageState['y'] += $after;
    };

    $drawWrappedBlock($page, 'Service Authorization Agreement', 26, 42, $black, true, 10);
    $drawWrappedBlock($page, serviceAuthorizationSummaryLine(), 16, 28, $black, false, 16);
    $drawWrappedBlock($page, 'Customer: ' . ($authorization['customer_name'] ?? 'Customer'), 14, 24, $muted, false, 0);
    $drawWrappedBlock($page, 'Service Request #: ' . (string) ($authorization['service_request_number'] ?? $authorization['service_request_id'] ?? ''), 14, 24, $muted, false, 0);
    $drawWrappedBlock($page, 'Signed: ' . serviceAuthorizationFormatSignedAtDisplay($authorization['signed_at'] ?? ''), 14, 24, $muted, false, 24);
    $drawWrappedBlock($page, 'Scope of Work', 18, 30, $black, true, 4);
    $drawWrappedBlock($page, (string) ($authorization['scope_of_work'] ?? ''), 15, 28, $black, false, 20);
    $drawWrappedBlock($page, 'Terms', 18, 30, $black, true, 4);

    foreach (serviceAuthorizationClauses() as $index => $clause) {
        $drawWrappedBlock($page, ($index + 1) . '. ' . $clause, 15, 28, $black, false, 10);
    }

    $signatureTop = max($page['y'] + 10, $pageHeight - $bottomMargin - $signatureBlockSize + 20);
    if ($signatureTop > ($pageHeight - $bottomMargin - $signatureBlockSize + 20)) {
        $pages[] = $page['image'];
        $page = $newPage();
        $black = imagecolorallocate($page['image'], 17, 24, 39);
        $muted = imagecolorallocate($page['image'], 75, 85, 99);
        $lineColor = imagecolorallocate($page['image'], 209, 213, 219);
        $signatureTop = $pageHeight - $bottomMargin - $signatureBlockSize + 20;
    }

    imageline($page['image'], $marginX, $signatureTop - 16, $pageWidth - $marginX, $signatureTop - 16, $lineColor);
    serviceAuthorizationRenderTextLine($page['image'], $titleFont, 18, $marginX, $signatureTop + 18, $black, 'Customer Signature', 5);

    $signaturePath = serviceAuthorizationResolveSignaturePath((string) ($authorization['signature_path'] ?? ''));
    $signatureImage = @imagecreatefrompng($signaturePath);
    if ($signatureImage === false) {
        throw new RuntimeException('Stored signature image is unavailable.');
    }

    $boxX = $marginX;
    $boxY = $signatureTop + 42;
    $boxW = 530;
    $boxH = 140;
    imagerectangle($page['image'], $boxX, $boxY, $boxX + $boxW, $boxY + $boxH, $lineColor);

    $srcW = imagesx($signatureImage);
    $srcH = imagesy($signatureImage);
    $destW = $boxW - 24;
    $destH = max(1, (int) round(($srcH / max(1, $srcW)) * $destW));
    if ($destH > ($boxH - 24)) {
        $destH = $boxH - 24;
        $destW = max(1, (int) round(($srcW / max(1, $srcH)) * $destH));
    }
    $destX = $boxX + (int) floor(($boxW - $destW) / 2);
    $destY = $boxY + (int) floor(($boxH - $destH) / 2);
    imagealphablending($page['image'], true);
    imagesavealpha($page['image'], true);
    imagecopyresampled($page['image'], $signatureImage, $destX, $destY, 0, 0, $destW, $destH, $srcW, $srcH);
    imagedestroy($signatureImage);

    $stampX = $boxX + $boxW + 40;
    serviceAuthorizationRenderTextLine($page['image'], $bodyFont, 16, $stampX, $boxY + 22, $black, 'Captured Details', 4);
    serviceAuthorizationRenderTextLine($page['image'], $bodyFont, 14, $stampX, $boxY + 58, $muted, 'Timestamp: ' . serviceAuthorizationFormatSignedAtDisplay($authorization['signed_at'] ?? ''), 3);

    $lat = $authorization['signed_latitude'] ?? null;
    $lng = $authorization['signed_longitude'] ?? null;
    $gpsText = ($lat !== null && $lng !== null)
        ? sprintf('GPS: %.6f, %.6f', (float) $lat, (float) $lng)
        : 'GPS: Not captured';
    serviceAuthorizationRenderTextLine($page['image'], $bodyFont, 14, $stampX, $boxY + 92, $muted, $gpsText, 3);
    serviceAuthorizationRenderTextLine($page['image'], $bodyFont, 14, $stampX, $boxY + 126, $muted, 'Agreement: Service Authorization', 3);

    $pages[] = $page['image'];

    $jpegPages = [];
    foreach ($pages as $image) {
        ob_start();
        imagejpeg($image, null, 92);
        $jpegPages[] = (string) ob_get_clean();
        imagedestroy($image);
    }

    return $jpegPages;
}

function serviceAuthorizationRenderPdfFromJpegs(array $jpegPages): string
{
    if ($jpegPages === []) {
        throw new RuntimeException('No pages available for PDF generation.');
    }

    if (class_exists('Imagick')) {
        $imagick = new Imagick();
        foreach ($jpegPages as $jpeg) {
            $page = new Imagick();
            $page->setResolution(150, 150);
            $page->readImageBlob($jpeg);
            $page->setImageFormat('jpeg');
            $imagick->addImage($page);
            $page->clear();
            $page->destroy();
        }
        $imagick->setImageFormat('pdf');
        $blob = $imagick->getImagesBlob();
        $imagick->clear();
        $imagick->destroy();
        return $blob;
    }

    $objects = [];
    $offsets = [];
    $pageRefs = [];
    $nextId = 3;

    foreach ($jpegPages as $index => $jpeg) {
        $imageInfo = @getimagesizefromstring($jpeg);
        if ($imageInfo === false) {
            throw new RuntimeException('Unable to read rendered PDF page.');
        }

        $pageObjId = $nextId++;
        $contentObjId = $nextId++;
        $imageObjId = $nextId++;
        $pageRefs[] = $pageObjId . ' 0 R';
        $imageName = 'Im' . ($index + 1);

        $content = "q\n612 0 0 792 0 0 cm\n/{$imageName} Do\nQ\n";
        $objects[$imageObjId] =
            "<< /Type /XObject /Subtype /Image /Width {$imageInfo[0]} /Height {$imageInfo[1]} /ColorSpace /DeviceRGB /BitsPerComponent 8 /Filter /DCTDecode /Length " . strlen($jpeg) . " >>\n" .
            "stream\n{$jpeg}\nendstream";
        $objects[$contentObjId] =
            "<< /Length " . strlen($content) . " >>\nstream\n{$content}endstream";
        $objects[$pageObjId] =
            "<< /Type /Page /Parent 2 0 R /MediaBox [0 0 612 792] /Resources << /XObject << /{$imageName} {$imageObjId} 0 R >> >> /Contents {$contentObjId} 0 R >>";
    }

    $objects[1] = "<< /Type /Catalog /Pages 2 0 R >>";
    $objects[2] = "<< /Type /Pages /Count " . count($pageRefs) . " /Kids [" . implode(' ', $pageRefs) . "] >>";
    ksort($objects);

    $pdf = "%PDF-1.4\n";
    foreach ($objects as $id => $body) {
        $offsets[$id] = strlen($pdf);
        $pdf .= $id . " 0 obj\n" . $body . "\nendobj\n";
    }

    $xrefOffset = strlen($pdf);
    $maxId = max(array_keys($objects));
    $pdf .= "xref\n0 " . ($maxId + 1) . "\n";
    $pdf .= "0000000000 65535 f \n";
    for ($i = 1; $i <= $maxId; $i++) {
        $offset = $offsets[$i] ?? 0;
        $pdf .= sprintf("%010d 00000 n \n", $offset);
    }

    $pdf .= "trailer\n<< /Size " . ($maxId + 1) . " /Root 1 0 R >>\nstartxref\n{$xrefOffset}\n%%EOF";
    return $pdf;
}

function serviceAuthorizationGeneratePdf(PDO $pdo, int $authorizationId): array
{
    $authorization = serviceAuthorizationFetchById($pdo, $authorizationId);
    if (!$authorization) {
        throw new RuntimeException('Authorization record not found.');
    }

    $jpegPages = serviceAuthorizationRenderPages($authorization);
    $pdfBinary = serviceAuthorizationRenderPdfFromJpegs($jpegPages);

    return [
        'filename' => sprintf('service-authorization-%d.pdf', (int) $authorization['service_request_id']),
        'content' => $pdfBinary,
        'authorization' => $authorization,
    ];
}
