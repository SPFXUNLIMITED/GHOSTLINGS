<?php

function technicianDashboardHasAccess(): bool
{
    return !empty($_SESSION['admin_id']);
}

function technicianDashboardAdminId(): int
{
    return (int) ($_SESSION['admin_id'] ?? 0);
}

function technicianDashboardCanAccessServiceRequest(PDO $pdo, int $serviceRequestId): bool
{
    $adminId = technicianDashboardAdminId();
    if ($adminId <= 0 || $serviceRequestId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT 1
         FROM scheduled_cluster_jobs scj
         JOIN scheduled_clusters sc ON sc.id = scj.scheduled_cluster_id
         WHERE scj.service_request_id = :service_request_id
         LIMIT 1"
    );
    $stmt->execute([
        ':service_request_id' => $serviceRequestId,
    ]);

    return (bool) $stmt->fetchColumn();
}

function technicianDashboardCanAccessAuthorization(PDO $pdo, int $authorizationId): bool
{
    $adminId = technicianDashboardAdminId();
    if ($adminId <= 0 || $authorizationId <= 0) {
        return false;
    }

    $stmt = $pdo->prepare(
        "SELECT EXISTS(
            SELECT 1
            FROM service_authorizations sa
            WHERE sa.id = :authorization_id
              AND EXISTS (
                SELECT 1
                FROM scheduled_cluster_jobs scj
                JOIN scheduled_clusters sc ON sc.id = scj.scheduled_cluster_id
                WHERE scj.service_request_id = sa.service_request_id
                LIMIT 1
              )
         )"
    );
    $stmt->execute([
        ':authorization_id' => $authorizationId,
    ]);

    return (bool) $stmt->fetchColumn();
}
