<?php

function completionCertificateSummaryLine(): string
{
    return 'This certifies that the approved work has been satisfactorily completed and accepted by the customer.';
}

function completionCertificateClauses(): array
{
    return [
        'The customer confirms the listed work has been completed to their satisfaction.',
        'The customer approves release of final payment for the completed service.',
    ];
}

function completionCertificateFetchLatestByJobIds(PDO $pdo, array $serviceRequestIds): array
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
            WHERE agreement_type = 'completion_certificate'
              AND service_request_id IN ($placeholders)
            GROUP BY service_request_id
         ) latest ON latest.latest_id = sa.id
         WHERE sa.agreement_type = 'completion_certificate'"
    );
    $stmt->execute($serviceRequestIds);

    $rows = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $rows[(int) $row['service_request_id']] = $row;
    }

    return $rows;
}

function completionCertificateFetchLatestByServiceRequestId(PDO $pdo, int $serviceRequestId): ?array
{
    if ($serviceRequestId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT sa.*
         FROM service_authorizations sa
         WHERE sa.service_request_id = :service_request_id
           AND sa.agreement_type = 'completion_certificate'
         ORDER BY sa.id DESC
         LIMIT 1"
    );
    $stmt->execute([
        ':service_request_id' => $serviceRequestId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function completionCertificateFetchLatestServiceAuthorization(PDO $pdo, int $serviceRequestId): ?array
{
    if ($serviceRequestId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT id, signed_at
         FROM service_authorizations
         WHERE service_request_id = :service_request_id
           AND agreement_type = 'service_authorization'
         ORDER BY id DESC
         LIMIT 1"
    );
    $stmt->execute([
        ':service_request_id' => $serviceRequestId,
    ]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function completionCertificateIsCurrentForServiceRequest(PDO $pdo, int $serviceRequestId): bool
{
    $latestAuthorization = completionCertificateFetchLatestServiceAuthorization($pdo, $serviceRequestId);
    $latestCertificate = completionCertificateFetchLatestByServiceRequestId($pdo, $serviceRequestId);
    if (!$latestAuthorization || !$latestCertificate) {
        return false;
    }

    $authorizationTs = strtotime((string) ($latestAuthorization['signed_at'] ?? ''));
    $certificateTs = strtotime((string) ($latestCertificate['signed_at'] ?? ''));
    if ($authorizationTs === false || $certificateTs === false) {
        return false;
    }

    return $certificateTs >= $authorizationTs;
}

function completionCertificateCanCreateForServiceRequest(PDO $pdo, int $serviceRequestId): bool
{
    return completionCertificateFetchLatestServiceAuthorization($pdo, $serviceRequestId) !== null;
}

function completionCertificateHasPrerequisiteAuthorization(PDO $pdo, int $serviceRequestId): bool
{
    return completionCertificateFetchLatestServiceAuthorization($pdo, $serviceRequestId) !== null;
}

function completionCertificatePrepareSignaturePaths(int $serviceRequestId): array
{
    serviceAuthorizationEnsureDirectory(serviceAuthorizationSignatureRoot());

    $fileName = sprintf(
        'completion-cert-%d-%s-%s.png',
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

function completionCertificateSave(PDO $pdo, int $serviceRequestId, string $signatureDataUrl, ?float $latitude, ?float $longitude, ?string $signedAtInput, ?string $scopeOverride = null): array
{
    $job = serviceAuthorizationFetchJob($pdo, $serviceRequestId);
    if (!$job) {
        throw new RuntimeException('Service request not found.');
    }

    $signatureBinary = serviceAuthorizationDecodeSignaturePng($signatureDataUrl);
    $signaturePaths  = completionCertificatePrepareSignaturePaths($serviceRequestId);
    serviceAuthorizationWriteTempSignature($signaturePaths['temp'], $signatureBinary);
    $signedAt        = serviceAuthorizationParseSignedAt($signedAtInput)->setTimezone(new DateTimeZone('UTC'))->format(DATE_ATOM);
    $completionBody  = completionCertificateRemoveLegacyTechnicianNotesBlocks(
        serviceAuthorizationBuildScopeOfWork($pdo, $job),
        (string) ($job['technician_notes'] ?? '')
    );
    if ($scopeOverride !== null) {
        $normalizedScope = serviceAuthorizationNormalizeTextarea($scopeOverride);
        if ($normalizedScope !== '') {
            $completionBody = $normalizedScope;
        }
    }
    $summaryLine     = completionCertificateSummaryLine();
    $signatureSha256 = hash('sha256', $signatureBinary);
    $startedTransaction = false;
    $authorizationId = 0;

    try {
        if (!$pdo->inTransaction()) {
            $pdo->beginTransaction();
            $startedTransaction = true;
        }

        $stmt = $pdo->prepare(
            "INSERT INTO service_authorizations
                (service_request_id, agreement_type, agreement_summary, scope_of_work, signature_path, signature_sha256, signed_at, signed_latitude, signed_longitude)
             VALUES
                (:service_request_id, 'completion_certificate', :agreement_summary, :scope_of_work, :signature_path, :signature_sha256, :signed_at, :signed_latitude, :signed_longitude)"
        );
        $stmt->execute([
            ':service_request_id' => $serviceRequestId,
            ':agreement_summary'  => $summaryLine,
            ':scope_of_work'      => $completionBody,
            ':signature_path'     => $signaturePaths['relative'],
            ':signature_sha256'   => $signatureSha256,
            ':signed_at'          => $signedAt,
            ':signed_latitude'    => $latitude,
            ':signed_longitude'   => $longitude,
        ]);
        $authorizationId = (int) $pdo->lastInsertId();

        if (!rename($signaturePaths['temp'], $signaturePaths['absolute'])) {
            throw new RuntimeException('Unable to finalize signature image.');
        }

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

function completionCertificateRenderPages(array $certificate): array
{
    $pageWidth          = 1275;
    $pageHeight         = 1650;
    $marginX            = 90;
    $topMargin          = 110;
    $bottomMargin       = 90;
    $signatureBlockSize = 400;
    $bodyLimit          = $pageHeight - $bottomMargin - $signatureBlockSize;
    $bodyWidth          = $pageWidth - ($marginX * 2);
    $titleFont          = serviceAuthorizationFontPath(true);
    $bodyFont           = serviceAuthorizationFontPath(false);
    $pages              = [];

    $newPage = static function () use ($pageWidth, $pageHeight, $topMargin): array {
        $image = imagecreatetruecolor($pageWidth, $pageHeight);
        imageantialias($image, true);
        $white = imagecolorallocate($image, 255, 255, 255);
        imagefilledrectangle($image, 0, 0, $pageWidth, $pageHeight, $white);

        return [
            'image' => $image,
            'y' => $topMargin,
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

    $drawWrappedBlock($page, 'Completion Certificate', 26, 42, $black, true, 10);
    $drawWrappedBlock($page, completionCertificateSummaryLine(), 16, 28, $black, false, 16);
    $drawWrappedBlock($page, 'Customer: ' . ($certificate['customer_name'] ?? 'Customer'), 14, 24, $muted, false, 0);
    $drawWrappedBlock($page, 'Service Request #: ' . (string) ($certificate['service_request_number'] ?? $certificate['service_request_id'] ?? ''), 14, 24, $muted, false, 0);
    $drawWrappedBlock($page, 'Completed: ' . serviceAuthorizationFormatSignedAtDisplay($certificate['signed_at'] ?? ''), 14, 24, $muted, false, 24);
    $drawWrappedBlock($page, 'Completed Work', 18, 30, $black, true, 4);
    $drawWrappedBlock($page, completionCertificateBuildCompletedWorkText($certificate), 15, 28, $black, false, 20);
    $drawWrappedBlock($page, 'Customer Acknowledgment', 18, 30, $black, true, 4);

    foreach (completionCertificateClauses() as $index => $clause) {
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

    $signaturePath = serviceAuthorizationResolveSignaturePath((string) ($certificate['signature_path'] ?? ''));
    $signatureImage = @imagecreatefrompng($signaturePath);
    if ($signatureImage === false) {
        throw new RuntimeException('Stored signature image is unavailable.');
    }

    $customerBoxX = $marginX;
    $customerBoxY = $signatureTop + 42;
    $customerBoxW = 530;
    $customerBoxH = 120;
    imagerectangle($page['image'], $customerBoxX, $customerBoxY, $customerBoxX + $customerBoxW, $customerBoxY + $customerBoxH, $lineColor);

    $srcW = imagesx($signatureImage);
    $srcH = imagesy($signatureImage);
    $destW = $customerBoxW - 24;
    $destH = max(1, (int) round(($srcH / max(1, $srcW)) * $destW));
    if ($destH > ($customerBoxH - 24)) {
        $destH = $customerBoxH - 24;
        $destW = max(1, (int) round(($srcW / max(1, $srcH)) * $destH));
    }
    $destX = $customerBoxX + (int) floor(($customerBoxW - $destW) / 2);
    $destY = $customerBoxY + (int) floor(($customerBoxH - $destH) / 2);
    imagealphablending($page['image'], true);
    imagesavealpha($page['image'], true);
    imagecopyresampled($page['image'], $signatureImage, $destX, $destY, 0, 0, $destW, $destH, $srcW, $srcH);
    imagedestroy($signatureImage);

    serviceAuthorizationRenderTextLine(
        $page['image'],
        $bodyFont,
        14,
        $customerBoxX,
        $customerBoxY + $customerBoxH + 28,
        $muted,
        'Date: ' . serviceAuthorizationFormatSignedAtDisplay($certificate['signed_at'] ?? ''),
        3
    );

    $techLabelY = $customerBoxY + $customerBoxH + 80;
    serviceAuthorizationRenderTextLine($page['image'], $titleFont, 18, $marginX, $techLabelY, $black, 'Technician Signature', 5);

    $techLineY = $techLabelY + 36;
    imageline($page['image'], $marginX, $techLineY, $marginX + 530, $techLineY, $lineColor);
    serviceAuthorizationRenderTextLine($page['image'], $bodyFont, 14, $marginX, $techLineY + 28, $muted, 'Date: ________________________', 3);

    $stampX = $marginX + 580;
    serviceAuthorizationRenderTextLine($page['image'], $bodyFont, 16, $stampX, $customerBoxY + 18, $black, 'Captured Details', 4);
    serviceAuthorizationRenderTextLine($page['image'], $bodyFont, 14, $stampX, $customerBoxY + 52, $muted, 'Timestamp: ' . serviceAuthorizationFormatSignedAtDisplay($certificate['signed_at'] ?? ''), 3);

    $lat = $certificate['signed_latitude'] ?? null;
    $lng = $certificate['signed_longitude'] ?? null;
    $gpsText = ($lat !== null && $lng !== null)
        ? sprintf('GPS: %.6f, %.6f', (float) $lat, (float) $lng)
        : 'GPS: Not captured';
    serviceAuthorizationRenderTextLine($page['image'], $bodyFont, 14, $stampX, $customerBoxY + 86, $muted, $gpsText, 3);
    serviceAuthorizationRenderTextLine($page['image'], $bodyFont, 14, $stampX, $customerBoxY + 120, $muted, 'Document: Completion Certificate', 3);

    $pages[] = $page['image'];

    $jpegPages = [];
    foreach ($pages as $image) {
        ob_start();
        imagejpeg($image, null, 92);
        $jpegPages[] = (string) ob_get_clean();
        imagedestroy($image);
    }

    return array_merge($jpegPages, completionCertificateRenderPhotoJpegs($certificate));
}

function completionCertificateCollectRenderablePhotos(array $certificate): array
{
    $photos = [];
    foreach (serviceAuthorizationDecodeJobPhotos($certificate['job_photos'] ?? null) as $path) {
        try {
            $resolved = serviceAuthorizationResolveStoragePath($path);
        } catch (Throwable $e) {
            continue;
        }

        $binary = @file_get_contents($resolved);
        if (!is_string($binary) || $binary === '') {
            continue;
        }

        $imageInfo = @getimagesizefromstring($binary);
        if ($imageInfo === false || strpos((string) ($imageInfo['mime'] ?? ''), 'image/') !== 0) {
            continue;
        }

        $photos[] = [
            'path' => $path,
            'label' => basename($path),
            'binary' => $binary,
        ];
    }

    return $photos;
}

function completionCertificateRenderPhotoJpegs(array $certificate, ?string $titleFont = null, ?string $bodyFont = null): array
{
    $photos = completionCertificateCollectRenderablePhotos($certificate);
    if ($photos === []) {
        return [];
    }

    $pageWidth = 1275;
    $pageHeight = 1650;
    $marginX = 90;
    $topMargin = 110;
    $bottomMargin = 90;
    $titleFont = $titleFont === '' ? null : ($titleFont ?? serviceAuthorizationFontPath(true));
    $bodyFont = $bodyFont === '' ? null : ($bodyFont ?? serviceAuthorizationFontPath(false));
    $jpegPages = [];
    $totalPhotos = count($photos);

    foreach ($photos as $index => $photo) {
        $sourceImage = @imagecreatefromstring($photo['binary']);
        if ($sourceImage === false) {
            continue;
        }

        $page = imagecreatetruecolor($pageWidth, $pageHeight);
        imageantialias($page, true);
        $white = imagecolorallocate($page, 255, 255, 255);
        $black = imagecolorallocate($page, 17, 24, 39);
        $muted = imagecolorallocate($page, 75, 85, 99);
        $lineColor = imagecolorallocate($page, 209, 213, 219);
        imagefilledrectangle($page, 0, 0, $pageWidth, $pageHeight, $white);

        serviceAuthorizationRenderTextLine($page, $titleFont, 24, $marginX, $topMargin, $black, 'Job Photo Appendix', 5);
        serviceAuthorizationRenderTextLine($page, $bodyFont, 15, $marginX, $topMargin + 40, $muted, 'Technician-captured reference photos for this completion certificate.', 4);
        serviceAuthorizationRenderTextLine(
            $page,
            $bodyFont,
            14,
            $marginX,
            $topMargin + 76,
            $muted,
            sprintf('Photo %d of %d', $index + 1, $totalPhotos),
            3
        );

        $boxX = $marginX;
        $boxY = $topMargin + 120;
        $boxW = $pageWidth - ($marginX * 2);
        $boxH = $pageHeight - $boxY - $bottomMargin - 90;
        imagerectangle($page, $boxX, $boxY, $boxX + $boxW, $boxY + $boxH, $lineColor);

        $srcW = imagesx($sourceImage);
        $srcH = imagesy($sourceImage);
        $destW = $boxW - 32;
        $destH = max(1, (int) round(($srcH / max(1, $srcW)) * $destW));
        if ($destH > ($boxH - 32)) {
            $destH = $boxH - 32;
            $destW = max(1, (int) round(($srcW / max(1, $srcH)) * $destH));
        }
        $destX = $boxX + (int) floor(($boxW - $destW) / 2);
        $destY = $boxY + (int) floor(($boxH - $destH) / 2);
        imagealphablending($page, true);
        imagesavealpha($page, true);
        imagecopyresampled($page, $sourceImage, $destX, $destY, 0, 0, $destW, $destH, $srcW, $srcH);
        imagedestroy($sourceImage);

        serviceAuthorizationRenderTextLine($page, $bodyFont, 13, $marginX, $pageHeight - $bottomMargin, $muted, $photo['label'], 3);

        ob_start();
        imagejpeg($page, null, 92);
        $jpegPages[] = (string) ob_get_clean();
        imagedestroy($page);
    }

    return $jpegPages;
}

function completionCertificateGeneratePdf(PDO $pdo, int $authorizationId): array
{
    $certificate = serviceAuthorizationFetchById($pdo, $authorizationId);
    if (!$certificate || ($certificate['agreement_type'] ?? '') !== 'completion_certificate') {
        throw new RuntimeException('Completion certificate record not found.');
    }

    $jpegPages = completionCertificateRenderPages($certificate);
    $pdfBinary = serviceAuthorizationRenderPdfFromJpegs($jpegPages);

    return [
        'filename' => sprintf('completion-certificate-%d-%d.pdf', (int) $certificate['service_request_id'], (int) $certificate['id']),
        'content' => $pdfBinary,
        'certificate' => $certificate,
    ];
}

function completionCertificateRemoveLegacyTechnicianNotesBlocks(string $scopeText, ?string $technicianNotes = null): string
{
    $scopeText = trim(str_replace(["\r\n", "\r"], "\n", $scopeText));
    if ($scopeText === '') {
        return '';
    }

    $normalizedNotes = serviceAuthorizationNormalizeTextarea((string) $technicianNotes);
    $blocks = preg_split("/\n{2,}/", $scopeText) ?: [];
    $blocks = array_values(array_filter(
        $blocks,
        static function ($block): bool {
            return trim((string) $block) !== '';
        }
    ));
    $blocks = array_values(array_filter(
        $blocks,
        static function ($block) use ($normalizedNotes): bool {
            $block = trim((string) $block);
            $newlinePos = strpos($block, "\n");
            if ($newlinePos === false) {
                return !completionCertificateIsTechnicianNotesHeadingLine($block);
            }

            $firstLine = trim(substr($block, 0, $newlinePos));
            if (!completionCertificateIsTechnicianNotesHeadingLine($firstLine)) {
                return true;
            }

            $body = serviceAuthorizationNormalizeTextarea(substr($block, $newlinePos + 1));
            if ($body === '') {
                return false;
            }

            return $normalizedNotes === ''
                ? true
                : $body !== $normalizedNotes;
        }
    ));

    return implode("\n\n", $blocks);
}

function completionCertificateBuildCompletedWorkText(array $certificate): string
{
    $scopeText = trim(str_replace(["\r\n", "\r"], "\n", (string) ($certificate['scope_of_work'] ?? '')));
    $notesValue = serviceAuthorizationNormalizeWhitespace(str_replace("\n", ' ', str_replace("\r", "\n", (string) ($certificate['technician_notes'] ?? ''))));
    $technicianNotes = $notesValue === '' ? '' : 'Technician notes: ' . $notesValue;
    $normalizedTechnicianNotes = completionCertificateNormalizeInlineTechnicianNotesBlock($technicianNotes);
    if ($scopeText === '') {
        return $technicianNotes;
    }

    if ($technicianNotes === '') {
        return $scopeText;
    }

    $blocks = preg_split("/\n{2,}/", $scopeText) ?: [];
    $blocks = array_values(array_filter($blocks, static fn ($block): bool => trim((string) $block) !== ''));
    $blocks = array_values(array_filter(
        $blocks,
        static fn ($block): bool => completionCertificateNormalizeInlineTechnicianNotesBlock((string) $block) !== $normalizedTechnicianNotes
    ));
    foreach ($blocks as $index => $block) {
        if (completionCertificateIsStructuredIssueSummaryBlock((string) $block)) {
            array_splice($blocks, $index + 1, 0, [$technicianNotes]);
            return implode("\n\n", $blocks);
        }
    }

    $blocks[] = $technicianNotes;
    return implode("\n\n", array_values(array_filter($blocks, static fn ($block): bool => trim((string) $block) !== '')));
}

function completionCertificateIsStructuredIssueSummaryBlock(string $block): bool
{
    $block = trim($block);
    return $block !== ''
        && !str_contains($block, "\n")
        && stripos($block, 'Issue summary:') === 0;
}

function completionCertificateNormalizeInlineTechnicianNotesBlock(string $block): ?string
{
    $block = trim(str_replace(["\r\n", "\r"], "\n", $block));
    if ($block === '' || str_contains($block, "\n")) {
        return null;
    }

    if (!preg_match('/^Technician notes(?:\s*:\s*|\s+)(.*)$/i', $block, $matches)) {
        return null;
    }

    $value = serviceAuthorizationNormalizeWhitespace((string) ($matches[1] ?? ''));
    if ($value === '') {
        return 'Technician notes:';
    }

    return 'Technician notes: ' . $value;
}

function completionCertificateIsTechnicianNotesHeadingLine(string $line): bool
{
    return preg_match('/^Technician notes(?:\s*:)?$/i', trim($line)) === 1;
}

function completionCertificatePdfRoot(): string
{
    return serviceAuthorizationStorageRoot() . '/completion-certificates';
}

function completionCertificateExpectedPdfPath(int $serviceRequestId, int $certificateId): string
{
    return sprintf(
        'uploads/service-authorizations/completion-certificates/completion-certificate-%d-%d.pdf',
        $serviceRequestId,
        $certificateId
    );
}

function completionCertificateWritePdfFile(int $serviceRequestId, int $certificateId, string $pdfBinary): string
{
    serviceAuthorizationEnsureDirectory(completionCertificatePdfRoot());

    $relativePath = completionCertificateExpectedPdfPath($serviceRequestId, $certificateId);
    $absolutePath = dirname(__DIR__) . '/' . $relativePath;

    if (file_put_contents($absolutePath, $pdfBinary, LOCK_EX) === false) {
        throw new RuntimeException('Unable to write completion certificate PDF.');
    }

    return $relativePath;
}

function completionCertificatePersistFilePath(PDO $pdo, int $serviceRequestId, string $relativePath): void
{
    $stmt = $pdo->prepare(
        "UPDATE service_requests
         SET completion_certificate = :completion_certificate
         WHERE id = :id
         LIMIT 1"
    );
    $stmt->execute([
        ':completion_certificate' => $relativePath,
        ':id' => $serviceRequestId,
    ]);
}

function completionCertificateResolvePdfPath(string $relativePath): string
{
    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $baseRoot     = realpath(dirname(__DIR__));
    $uploadsRoot  = realpath(completionCertificatePdfRoot());
    $absolutePath = $baseRoot . '/' . $relativePath;
    $resolved     = realpath($absolutePath);

    if (
        $resolved === false ||
        $uploadsRoot === false ||
        strpos($resolved, $uploadsRoot . DIRECTORY_SEPARATOR) !== 0
    ) {
        throw new RuntimeException('Stored completion certificate PDF is unavailable.');
    }

    return $resolved;
}

function completionCertificateFetchStoredFilePath(PDO $pdo, int $serviceRequestId): ?string
{
    if ($serviceRequestId <= 0) {
        return null;
    }

    $stmt = $pdo->prepare(
        "SELECT completion_certificate
         FROM service_requests
         WHERE id = :id
         LIMIT 1"
    );
    $stmt->execute([':id' => $serviceRequestId]);
    $value = $stmt->fetchColumn();
    if ($value === false) {
        return null;
    }

    $path = trim((string) $value);
    return $path !== '' ? $path : null;
}

function completionCertificateGenerateAndStoreByServiceRequest(PDO $pdo, int $serviceRequestId): array
{
    $certificate = completionCertificateFetchLatestByServiceRequestId($pdo, $serviceRequestId);
    if (!$certificate) {
        throw new RuntimeException('Completion certificate record not found.');
    }

    return completionCertificateGenerateAndStoreById($pdo, (int) $certificate['id']);
}

function completionCertificateGenerateAndStoreById(PDO $pdo, int $authorizationId): array
{
    if ($authorizationId <= 0) {
        throw new RuntimeException('Completion certificate record not found.');
    }

    $certificate = serviceAuthorizationFetchById($pdo, $authorizationId);
    if (!$certificate || ($certificate['agreement_type'] ?? '') !== 'completion_certificate') {
        throw new RuntimeException('Completion certificate record not found.');
    }

    $serviceRequestId = (int) ($certificate['service_request_id'] ?? 0);
    if ($serviceRequestId <= 0) {
        throw new RuntimeException('Completion certificate record not found.');
    }

    $certificateId = (int) $certificate['id'];
    $pdf = completionCertificateGeneratePdf($pdo, $certificateId);
    $relativePath = completionCertificateWritePdfFile($serviceRequestId, $certificateId, $pdf['content']);
    try {
        completionCertificatePersistFilePath($pdo, $serviceRequestId, $relativePath);
    } catch (Throwable $e) {
        try {
            $resolvedNewPath = completionCertificateResolvePdfPath($relativePath);
            if (is_file($resolvedNewPath)) {
                @unlink($resolvedNewPath);
            }
        } catch (Throwable $cleanupError) {
        }
        throw $e;
    }

    return [
        'filename' => $pdf['filename'],
        'content' => $pdf['content'],
        'certificate' => $pdf['certificate'],
        'path' => $relativePath,
    ];
}

function completionCertificateLoadOrGenerateByServiceRequest(PDO $pdo, int $serviceRequestId): array
{
    $latestCertificate = completionCertificateFetchLatestByServiceRequestId($pdo, $serviceRequestId);
    if (!$latestCertificate) {
        throw new RuntimeException('Completion certificate record not found.');
    }

    $latestCertificateId = (int) ($latestCertificate['id'] ?? 0);
    $expectedPath = completionCertificateExpectedPdfPath($serviceRequestId, $latestCertificateId);
    $storedPath = completionCertificateFetchStoredFilePath($pdo, $serviceRequestId);
    if ($storedPath !== null && $storedPath === $expectedPath) {
        try {
            $resolved = completionCertificateResolvePdfPath($storedPath);
            $content = @file_get_contents($resolved);
            if (is_string($content) && $content !== '') {
                return [
                    'filename' => basename($resolved),
                    'content' => $content,
                    'path' => $storedPath,
                ];
            }
        } catch (Throwable $e) {
        }
    }

    return completionCertificateGenerateAndStoreById($pdo, $latestCertificateId);
}
