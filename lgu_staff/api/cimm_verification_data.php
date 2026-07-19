<?php
/**
 * CIMM Verification Data Access Layer
 *
 * Provides PDO connectivity and helper functions for the
 * cimm_verification_reports table used by the admin verification
 * monitoring page.
 */

/**
 * Create (or reuse) a PDO connection pointing at the same database
 * the mysqli $conn in config.php already uses.
 */
function rgmap_verification_pdo(): PDO {
    static $pdo = null;
    if ($pdo !== null) {
        return $pdo;
    }
    $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
    $pdo = new PDO($dsn, DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,
    ]);
    $pdo->exec("SET time_zone = '+08:00'");
    return $pdo;
}

/**
 * Fetch CIMM verification reports.
 *
 * @param PDO    $pdo     Database connection
 * @param array  $opts    Optional: ['limit' => int]
 * @return array
 */
function rgmap_fetch_cimm_verification_reports(PDO $pdo, array $opts = []): array {
    $limit = (int) ($opts['limit'] ?? 500);
    if ($limit < 1) {
        $limit = 500;
    }

    // Ensure the table exists so the page doesn't blow up
    // even if no sync has run yet.
    $pdo->exec("CREATE TABLE IF NOT EXISTS cimm_verification_reports (
        id              INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        cimm_req_id     VARCHAR(50)  DEFAULT NULL,
        reference_code  VARCHAR(100) DEFAULT NULL,
        infrastructure  VARCHAR(255) DEFAULT NULL,
        location        VARCHAR(255) DEFAULT NULL,
        issue           TEXT         DEFAULT NULL,
        cprf_facility_name VARCHAR(255) DEFAULT NULL,
        reporter_name   VARCHAR(255) DEFAULT NULL,
        starting_date   DATE         DEFAULT NULL,
        estimated_end_date DATE     DEFAULT NULL,
        priority        VARCHAR(20)  DEFAULT 'medium',
        budget          DECIMAL(15,2) DEFAULT NULL,
        verification_status VARCHAR(30) DEFAULT 'Pending Review',
        approval_status VARCHAR(30) DEFAULT NULL,
        created_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP,
        updated_at      TIMESTAMP    DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_verification_status (verification_status),
        INDEX idx_cimm_req_id (cimm_req_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $stmt = $pdo->prepare(
        "SELECT * FROM cimm_verification_reports ORDER BY created_at DESC LIMIT ?"
    );
    $stmt->execute([$limit]);
    return $stmt->fetchAll();
}
