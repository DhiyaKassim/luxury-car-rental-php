<?php
/**
 * CIS 344 - Database Design and Programming (Fall 2026)
 * File: db_connect.php
 * Purpose: Secure PHP Database Connection & Helper Utilities
 * Author: Dhiya Kassim
 *
 * Adapted from Pearson PHP & MySQL textbook examples, upgraded with:
 *   1. PDO (PHP Data Objects) with native prepared statements (SQL injection prevention)
 *   2. Strict exception handling and UTF-8 (utf8mb4) character encoding
 *   3. Automatic fallback/environment variable support for local (XAMPP/MAMP/Ubuntu LAMP) setups
 *   4. Session-based CSRF token generation and verification for form security
 */

declare(strict_types=1);

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ----------------------------------------------------------------------------
// 1. Database Connection Configuration
// Reads from environment variables if set, defaulting to standard local LAMP /
// XAMPP / MySQL Workbench parameters for the `luxury_car_rental` schema.
// ----------------------------------------------------------------------------
define('DB_HOST', getenv('DB_HOST') ?: '127.0.0.1');
define('DB_PORT', getenv('DB_PORT') ?: '3306');
define('DB_NAME', getenv('DB_NAME') ?: 'luxury_car_rental');
define('DB_USER', getenv('DB_USER') ?: 'root');
define('DB_PASS', getenv('DB_PASS') !== false ? getenv('DB_PASS') : 'Ss332211-');
define('DB_CHARSET', 'utf8mb4');

/**
 * Establishes and returns a singleton PDO connection to the MySQL database.
 *
 * Textbook Reference Note:
 * While basic textbook chapters introduce procedural `mysqli_connect($host, $user, $pass, $db)`,
 * using PDO with `PDO::ATTR_EMULATE_PREPARES => false` ensures true MySQL server-side
 * prepared statements and supports transactional rollbacks (`beginTransaction` / `commit` / `rollBack`)
 * required by Business Rules BR5, BR6, and BR12.
 *
 * @return PDO
 * @throws PDOException if the connection fails.
 */
function getDbConnection(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            DB_HOST,
            DB_PORT,
            DB_NAME,
            DB_CHARSET
        );

        $options = [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION, // Throw exceptions on SQL/CHECK constraint errors
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,       // Return associative arrays by default
            PDO::ATTR_EMULATE_PREPARES   => false,                  // Enforce true server-side prepared statements
        ];

        $pdo = new PDO($dsn, DB_USER, DB_PASS, $options);
    }

    return $pdo;
}

/**
 * Generates or retrieves the current session's CSRF token to protect POST forms.
 */
function getCsrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validates the submitted CSRF token from a POST form submission.
 */
function verifyCsrfToken(?string $submittedToken): bool
{
    if (empty($_SESSION['csrf_token']) || empty($submittedToken)) {
        return false;
    }
    return hash_equals($_SESSION['csrf_token'], $submittedToken);
}

/**
 * Safely escapes output strings for HTML rendering (prevents XSS).
 */
function e(mixed $value): string
{
    return htmlspecialchars((string)($value ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

/**
 * Calculates rental billing days per Business Rule BR9:
 * "Rental time is billed in full 24-hour blocks. Even a few extra minutes
 * counts as a whole extra day. Minimum charge is one day."
 */
function calculateBillingDays(string $pickupDatetime, string $returnDatetime): int
{
    $pickupTs = strtotime($pickupDatetime);
    $returnTs = strtotime($returnDatetime);

    if ($pickupTs === false || $returnTs === false || $returnTs <= $pickupTs) {
        return 1;
    }

    $diffSeconds = $returnTs - $pickupTs;
    $days = (int)ceil($diffSeconds / 86400);
    return max(1, $days);
}