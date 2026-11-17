<?php
/**
 * CIS 344 - Database Design and Programming (Fall 2026)
 * File: index.php
 * Project: Luxury Car Rental PHP Web Platform
 * Author: Dhiya Kassim (Group: Meily Rodriguez, Ali Mustafaj, Dhiya Kassim)
 *
 * Description:
 * Custom PHP & MySQL web application adapted from the Pearson PHP & MySQL textbook
 * examples and tailored to the CIS 344 `luxury_car_rental` 5-table database schema.
 *
 * Key Capabilities:
 *   1. Secure PDO Database Connection (`db_connect.php`) with Prepared Statements
 *   2. Multi-Form Data Capture & Persistence:
 *      - Form A: Customer Registration (`CUSTOMER`)
 *      - Form B: Exotic Vehicle Reservation (`RESERVATION`) with atomic transaction
 *        locking (`SELECT ... FOR UPDATE`) to prevent double-bookings (BR5/BR10)
 *        and block `OutOfService` vehicles (BR6)
 *      - Form C: Rental Agreement Pickup & Verification (`RENTAL_AGREEMENT`) enforcing
 *        driver's license & insurance checks (BR13) and blocking canceled bookings (BR12),
 *        plus Vehicle Return & Odometer Check-In (BR14/BR15)
 *      - Form D: Reservation Payment Logging (`PAYMENT`) with positive amount validation (BR17)
 *   3. Interactive Date-Window Vehicle Availability Search
 *   4. Live Relational Joins & Overdue Rental Detection Dashboard
 */

declare(strict_types=1);
require_once __DIR__ . '/db_connect.php';

$csrfToken    = getCsrfToken();
$flashMessage = null;
$flashType    = 'success'; // 'success' or 'error'
$dbConnected  = false;
$dbError      = null;
$pdo          = null;

try {
    $pdo = getDbConnection();
    $dbConnected = true;
} catch (PDOException $e) {
    $dbConnected = false;
    $dbError = $e->getMessage();
}

// ============================================================================
// FORM SUBMISSION HANDLERS (POST)
// ============================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $dbConnected && $pdo !== null) {
    $submittedToken = $_POST['csrf_token'] ?? '';
    $action         = $_POST['form_action'] ?? '';

    if (!verifyCsrfToken($submittedToken)) {
        $flashType    = 'error';
        $flashMessage = 'Security validation failed (invalid CSRF token). Please refresh and try again.';
    } else {
        try {
            // ----------------------------------------------------------------
            // FORM 1: Register a New Customer (BR1, BR2)
            // ----------------------------------------------------------------
            if ($action === 'register_customer') {
                $firstName     = trim($_POST['first_name'] ?? '');
                $lastName      = trim($_POST['last_name'] ?? '');
                $email         = trim($_POST['email'] ?? '');
                $phone         = trim($_POST['phone'] ?? '');
                $licenseNumber = strtoupper(trim($_POST['license_number'] ?? ''));
                $licenseState  = strtoupper(trim($_POST['license_state'] ?? ''));
                $licenseExpiry = trim($_POST['license_expiry'] ?? '');

                if ($firstName === '' || $lastName === '' || $email === '' || $phone === '' || $licenseNumber === '' || $licenseState === '' || $licenseExpiry === '') {
                    throw new RuntimeException('All customer registration fields are required.');
                }
                if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                    throw new RuntimeException('Please enter a valid email address.');
                }
                if (strtotime($licenseExpiry) < strtotime(date('Y-m-d'))) {
                    throw new RuntimeException('Driver\'s license expiration date cannot be in the past.');
                }

                $stmt = $pdo->prepare('
                    INSERT INTO CUSTOMER (
                        first_name, last_name, email, phone,
                        license_number, license_state, license_expiry
                    ) VALUES (
                        :first_name, :last_name, :email, :phone,
                        :license_number, :license_state, :license_expiry
                    )
                ');
                $stmt->execute([
                    ':first_name'     => $firstName,
                    ':last_name'      => $lastName,
                    ':email'          => $email,
                    ':phone'          => $phone,
                    ':license_number' => $licenseNumber,
                    ':license_state'  => $licenseState,
                    ':license_expiry' => $licenseExpiry,
                ]);

                $newId = (int)$pdo->lastInsertId();
                $flashType    = 'success';
                $flashMessage = sprintf(
                    'Customer "%s %s" (ID #%d, License: %s) saved to CUSTOMER table.',
                    $firstName,
                    $lastName,
                    $newId,
                    $licenseNumber
                );
            }

            // ----------------------------------------------------------------
            // FORM 2: Create a Vehicle Reservation with Atomic Transaction
            // Enforces BR5 (no overlapping bookings), BR6 (InService check),
            // BR7 (return > pickup), BR8 (locked-in rate/deposit), BR9, BR10.
            // ----------------------------------------------------------------
            elseif ($action === 'create_reservation') {
                $customerId      = (int)($_POST['customer_id'] ?? 0);
                $vehicleId       = (int)($_POST['vehicle_id'] ?? 0);
                $scheduledPickup = str_replace('T', ' ', trim($_POST['scheduled_pickup'] ?? ''));
                $scheduledReturn = str_replace('T', ' ', trim($_POST['scheduled_return'] ?? ''));
                $notes           = trim($_POST['notes'] ?? '');

                if (strlen($scheduledPickup) === 16) {
                    $scheduledPickup .= ':00';
                }
                if (strlen($scheduledReturn) === 16) {
                    $scheduledReturn .= ':00';
                }

                if ($customerId <= 0 || $vehicleId <= 0 || $scheduledPickup === '' || $scheduledReturn === '') {
                    throw new RuntimeException('Please select a customer, vehicle, and both pickup/return timestamps.');
                }
                if (strtotime($scheduledReturn) <= strtotime($scheduledPickup)) {
                    throw new RuntimeException('Business Rule BR7 Violation: Scheduled return time must be after scheduled pickup time.');
                }

                // Begin atomic transaction to prevent race-condition double bookings
                $pdo->beginTransaction();

                // Lock the target vehicle row and verify service_status (BR6)
                $vehStmt = $pdo->prepare('
                    SELECT vehicle_id, make, model, daily_rate, required_deposit, service_status
                    FROM VEHICLE
                    WHERE vehicle_id = :vehicle_id
                    FOR UPDATE
                ');
                $vehStmt->execute([':vehicle_id' => $vehicleId]);
                $vehicle = $vehStmt->fetch();

                if (!$vehicle) {
                    throw new RuntimeException('Selected vehicle does not exist.');
                }
                if ($vehicle['service_status'] !== 'InService') {
                    throw new RuntimeException(sprintf(
                        'Business Rule BR6 Violation: %s %s is currently marked OutOfService and cannot be booked.',
                        $vehicle['make'],
                        $vehicle['model']
                    ));
                }

                // Check for overlapping Confirmed or Ongoing reservations (BR5 & BR10)
                // Note per BR10: A booking runs from pickup up to (but not including) return,
                // so a new booking starting at the exact moment another ends is permitted.
                $overlapStmt = $pdo->prepare('
                    SELECT COUNT(*) AS conflict_count
                    FROM RESERVATION
                    WHERE vehicle_id = :vehicle_id
                      AND reservation_status IN (\'Confirmed\', \'Ongoing\')
                      AND scheduled_pickup < :new_return
                      AND scheduled_return > :new_pickup
                ');
                $overlapStmt->execute([
                    ':vehicle_id' => $vehicleId,
                    ':new_return' => $scheduledReturn,
                    ':new_pickup' => $scheduledPickup,
                ]);
                $conflictCount = (int)$overlapStmt->fetchColumn();

                if ($conflictCount > 0) {
                    throw new RuntimeException(sprintf(
                        'Business Rule BR5 Violation: %s %s already has an overlapping Confirmed or Ongoing reservation during that time window.',
                        $vehicle['make'],
                        $vehicle['model']
                    ));
                }

                // Lock in the vehicle's current daily rate and deposit amount (BR8)
                $insertRes = $pdo->prepare('
                    INSERT INTO RESERVATION (
                        customer_id, vehicle_id, scheduled_pickup, scheduled_return,
                        agreed_daily_rate, deposit_amount, reservation_status, notes
                    ) VALUES (
                        :customer_id, :vehicle_id, :scheduled_pickup, :scheduled_return,
                        :agreed_daily_rate, :deposit_amount, \'Confirmed\', :notes
                    )
                ');
                $insertRes->execute([
                    ':customer_id'       => $customerId,
                    ':vehicle_id'        => $vehicleId,
                    ':scheduled_pickup'  => $scheduledPickup,
                    ':scheduled_return'  => $scheduledReturn,
                    ':agreed_daily_rate' => $vehicle['daily_rate'],
                    ':deposit_amount'    => $vehicle['required_deposit'],
                    ':notes'             => $notes !== '' ? $notes : null,
                ]);

                $reservationId = (int)$pdo->lastInsertId();
                $pdo->commit();

                $billingDays = calculateBillingDays($scheduledPickup, $scheduledReturn);
                $estimatedTotal = $billingDays * (float)$vehicle['daily_rate'];

                $flashType    = 'success';
                $flashMessage = sprintf(
                    'Reservation #%d confirmed for %s %s! Locked rate: $%s/day (%d billing day[s] = $%s + $%s security deposit).',
                    $reservationId,
                    $vehicle['make'],
                    $vehicle['model'],
                    number_format((float)$vehicle['daily_rate'], 2),
                    $billingDays,
                    number_format($estimatedTotal, 2),
                    number_format((float)$vehicle['required_deposit'], 2)
                );
            }

            // ----------------------------------------------------------------
            // FORM 3A: Dispatch Vehicle / Create Rental Agreement (BR11-BR14)
            // ----------------------------------------------------------------
            elseif ($action === 'create_rental_agreement') {
                $reservationId     = (int)($_POST['reservation_id'] ?? 0);
                $licenseVerified   = isset($_POST['license_verified']) ? 1 : 0;
                $insuranceVerified = isset($_POST['insurance_verified']) ? 1 : 0;
                $actualPickup      = str_replace('T', ' ', trim($_POST['actual_pickup'] ?? ''));
                $pickupOdometer    = (int)($_POST['pickup_odometer'] ?? -1);

                if (strlen($actualPickup) === 16) {
                    $actualPickup .= ':00';
                }

                if ($reservationId <= 0 || $actualPickup === '' || $pickupOdometer < 0) {
                    throw new RuntimeException('Please provide a valid reservation, pickup timestamp, and starting odometer reading.');
                }
                if ($licenseVerified !== 1 || $insuranceVerified !== 1) {
                    throw new RuntimeException('Business Rule BR13 Violation: Both Driver\'s License and Insurance must be verified before vehicle dispatch.');
                }

                $pdo->beginTransaction();

                $resStmt = $pdo->prepare('
                    SELECT reservation_id, reservation_status
                    FROM RESERVATION
                    WHERE reservation_id = :reservation_id
                    FOR UPDATE
                ');
                $resStmt->execute([':reservation_id' => $reservationId]);
                $res = $resStmt->fetch();

                if (!$res) {
                    throw new RuntimeException('Reservation not found.');
                }
                if ($res['reservation_status'] === 'Canceled') {
                    throw new RuntimeException('Business Rule BR12 Violation: A canceled reservation cannot be converted into a rental agreement.');
                }

                $insAgreement = $pdo->prepare('
                    INSERT INTO RENTAL_AGREEMENT (
                        reservation_id, license_verified, insurance_verified,
                        actual_pickup, actual_return, pickup_odometer, return_odometer
                    ) VALUES (
                        :reservation_id, :license_verified, :insurance_verified,
                        :actual_pickup, NULL, :pickup_odometer, NULL
                    )
                ');
                $insAgreement->execute([
                    ':reservation_id'     => $reservationId,
                    ':license_verified'   => $licenseVerified,
                    ':insurance_verified' => $insuranceVerified,
                    ':actual_pickup'      => $actualPickup,
                    ':pickup_odometer'    => $pickupOdometer,
                ]);

                $updRes = $pdo->prepare('
                    UPDATE RESERVATION
                    SET reservation_status = \'Ongoing\'
                    WHERE reservation_id = :reservation_id
                ');
                $updRes->execute([':reservation_id' => $reservationId]);

                $pdo->commit();

                $flashType    = 'success';
                $flashMessage = sprintf(
                    'Rental Agreement created for Reservation #%d. Status updated to Ongoing (Pickup Odometer: %s mi).',
                    $reservationId,
                    number_format($pickupOdometer)
                );
            }

            // ----------------------------------------------------------------
            // FORM 3B: Complete Vehicle Return & Odometer Check-In (BR14, BR15)
            // ----------------------------------------------------------------
            elseif ($action === 'complete_return') {
                $rentalId       = (int)($_POST['rental_id'] ?? 0);
                $actualReturn   = str_replace('T', ' ', trim($_POST['actual_return'] ?? ''));
                $returnOdometer = (int)($_POST['return_odometer'] ?? -1);

                if (strlen($actualReturn) === 16) {
                    $actualReturn .= ':00';
                }

                if ($rentalId <= 0 || $actualReturn === '' || $returnOdometer < 0) {
                    throw new RuntimeException('Please select an active rental agreement and enter return timestamp and odometer.');
                }

                $pdo->beginTransaction();

                $raStmt = $pdo->prepare('
                    SELECT ra.rental_id, ra.reservation_id, ra.actual_pickup, ra.pickup_odometer, r.vehicle_id
                    FROM RENTAL_AGREEMENT ra
                    INNER JOIN RESERVATION r ON r.reservation_id = ra.reservation_id
                    WHERE ra.rental_id = :rental_id
                    FOR UPDATE
                ');
                $raStmt->execute([':rental_id' => $rentalId]);
                $ra = $raStmt->fetch();

                if (!$ra) {
                    throw new RuntimeException('Active rental agreement not found.');
                }
                if (strtotime($actualReturn) <= strtotime($ra['actual_pickup'])) {
                    throw new RuntimeException('Business Rule BR15 Violation: Actual return time must be after actual pickup time.');
                }
                if ($returnOdometer < (int)$ra['pickup_odometer']) {
                    throw new RuntimeException(sprintf(
                        'Business Rule BR15 Violation: Return odometer (%d mi) cannot be less than pickup odometer (%d mi).',
                        $returnOdometer,
                        (int)$ra['pickup_odometer']
                    ));
                }

                $updRa = $pdo->prepare('
                    UPDATE RENTAL_AGREEMENT
                    SET actual_return = :actual_return,
                        return_odometer = :return_odometer
                    WHERE rental_id = :rental_id
                ');
                $updRa->execute([
                    ':actual_return'   => $actualReturn,
                    ':return_odometer' => $returnOdometer,
                    ':rental_id'       => $rentalId,
                ]);

                $updRes = $pdo->prepare('
                    UPDATE RESERVATION
                    SET reservation_status = \'Completed\'
                    WHERE reservation_id = :reservation_id
                ');
                $updRes->execute([':reservation_id' => $ra['reservation_id']]);

                $updVeh = $pdo->prepare('
                    UPDATE VEHICLE
                    SET current_odometer = :return_odometer
                    WHERE vehicle_id = :vehicle_id
                ');
                $updVeh->execute([
                    ':return_odometer' => $returnOdometer,
                    ':vehicle_id'      => $ra['vehicle_id'],
                ]);

                $pdo->commit();

                $milesDriven = $returnOdometer - (int)$ra['pickup_odometer'];
                $flashType    = 'success';
                $flashMessage = sprintf(
                    'Rental #%d checked in and completed! Total distance driven: %s miles.',
                    $rentalId,
                    number_format($milesDriven)
                );
            }

            // ----------------------------------------------------------------
            // FORM 4: Record a Payment Toward a Reservation (BR16, BR17, BR18)
            // ----------------------------------------------------------------
            elseif ($action === 'record_payment') {
                $reservationId = (int)($_POST['reservation_id'] ?? 0);
                $amount        = (float)($_POST['amount'] ?? 0);
                $paymentMethod = trim($_POST['payment_method'] ?? 'Credit Card');
                $referenceNote = trim($_POST['reference_note'] ?? '');

                if ($reservationId <= 0 || $amount <= 0) {
                    throw new RuntimeException('Business Rule BR17 Violation: Payment must reference a valid reservation and have a positive amount (> $0.00).');
                }

                $payStmt = $pdo->prepare('
                    INSERT INTO PAYMENT (
                        reservation_id, payment_date, amount, payment_method, reference_note
                    ) VALUES (
                        :reservation_id, NOW(), :amount, :payment_method, :reference_note
                    )
                ');
                $payStmt->execute([
                    ':reservation_id' => $reservationId,
                    ':amount'         => $amount,
                    ':payment_method' => $paymentMethod,
                    ':reference_note' => $referenceNote !== '' ? $referenceNote : null,
                ]);

                $paymentId    = (int)$pdo->lastInsertId();
                $flashType    = 'success';
                $flashMessage = sprintf(
                    'Payment #%d of $%s (%s) recorded for Reservation #%d.',
                    $paymentId,
                    number_format($amount, 2),
                    $paymentMethod,
                    $reservationId
                );
            }
        } catch (Throwable $ex) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            $flashType    = 'error';
            $flashMessage = $ex->getMessage();
        }
    }
}

// ============================================================================
// DATA QUERIES FOR DASHBOARD & SELECT DROPDOWNS
// ============================================================================
$customers          = [];
$vehicles           = [];
$availableVehicles  = [];
$reservations       = [];
$activeAgreements   = [];
$payments           = [];

$searchPickup = trim($_GET['search_pickup'] ?? '2026-11-20T10:00');
$searchReturn = trim($_GET['search_return'] ?? '2026-11-22T10:00');

if ($dbConnected && $pdo !== null) {
    // 1. Fetch Customers
    $customers = $pdo->query('
        SELECT customer_id, first_name, last_name, email, phone,
               license_number, license_state, license_expiry
        FROM CUSTOMER
        ORDER BY customer_id DESC
    ')->fetchAll();

    // 2. Fetch All Vehicles
    $vehicles = $pdo->query('
        SELECT vehicle_id, vin, make, model, model_year, category,
               daily_rate, required_deposit, current_odometer, service_status
        FROM VEHICLE
        ORDER BY daily_rate DESC
    ')->fetchAll();

    // 3. Date-Window Availability Search Query (BR5, BR6, BR10)
    $sPickupSql = str_replace('T', ' ', $searchPickup) . (strlen($searchPickup) === 16 ? ':00' : '');
    $sReturnSql = str_replace('T', ' ', $searchReturn) . (strlen($searchReturn) === 16 ? ':00' : '');

    if (strtotime($sReturnSql) > strtotime($sPickupSql)) {
        $availStmt = $pdo->prepare('
            SELECT v.vehicle_id, v.vin, v.make, v.model, v.model_year,
                   v.category, v.daily_rate, v.required_deposit
            FROM VEHICLE v
            WHERE v.service_status = \'InService\'
              AND NOT EXISTS (
                  SELECT 1
                  FROM RESERVATION r
                  WHERE r.vehicle_id = v.vehicle_id
                    AND r.reservation_status IN (\'Confirmed\', \'Ongoing\')
                    AND r.scheduled_pickup < :search_return
                    AND r.scheduled_return > :search_pickup
              )
            ORDER BY v.daily_rate ASC
        ');
        $availStmt->execute([
            ':search_return' => $sReturnSql,
            ':search_pickup' => $sPickupSql,
        ]);
        $availableVehicles = $availStmt->fetchAll();
    }

    // 4. Fetch Reservations with Customer, Vehicle, Rental Agreement, and Payment Totals
    $reservations = $pdo->query('
        SELECT
            r.reservation_id,
            r.scheduled_pickup,
            r.scheduled_return,
            r.agreed_daily_rate,
            r.deposit_amount,
            r.reservation_status,
            r.notes,
            c.first_name,
            c.last_name,
            c.license_number,
            v.make,
            v.model,
            v.model_year,
            ra.rental_id,
            ra.actual_pickup,
            ra.actual_return,
            ra.pickup_odometer,
            ra.return_odometer,
            COALESCE(SUM(p.amount), 0.00) AS total_paid,
            CASE
                WHEN r.reservation_status = \'Ongoing\'
                     AND ra.actual_return IS NULL
                     AND NOW() > r.scheduled_return
                THEN 1
                ELSE 0
            END AS is_overdue
        FROM RESERVATION r
        INNER JOIN CUSTOMER c ON c.customer_id = r.customer_id
        INNER JOIN VEHICLE v ON v.vehicle_id = r.vehicle_id
        LEFT JOIN RENTAL_AGREEMENT ra ON ra.reservation_id = r.reservation_id
        LEFT JOIN PAYMENT p ON p.reservation_id = r.reservation_id
        GROUP BY
            r.reservation_id, r.scheduled_pickup, r.scheduled_return,
            r.agreed_daily_rate, r.deposit_amount, r.reservation_status, r.notes,
            c.first_name, c.last_name, c.license_number,
            v.make, v.model, v.model_year,
            ra.rental_id, ra.actual_pickup, ra.actual_return,
            ra.pickup_odometer, ra.return_odometer
        ORDER BY r.reservation_id DESC
    ')->fetchAll();

    // 5. Fetch Active Rental Agreements for Return Check-In Dropdown
    $activeAgreements = $pdo->query('
        SELECT ra.rental_id, ra.reservation_id, ra.actual_pickup, ra.pickup_odometer,
               c.first_name, c.last_name, v.make, v.model
        FROM RENTAL_AGREEMENT ra
        INNER JOIN RESERVATION r ON r.reservation_id = ra.reservation_id
        INNER JOIN CUSTOMER c ON c.customer_id = r.customer_id
        INNER JOIN VEHICLE v ON v.vehicle_id = r.vehicle_id
        WHERE ra.actual_return IS NULL
        ORDER BY ra.rental_id DESC
    ')->fetchAll();

    // 6. Fetch Recent Payments
    $payments = $pdo->query('
        SELECT p.payment_id, p.reservation_id, p.payment_date, p.amount,
               p.payment_method, p.reference_note,
               c.first_name, c.last_name, v.make, v.model
        FROM PAYMENT p
        INNER JOIN RESERVATION r ON r.reservation_id = p.reservation_id
        INNER JOIN CUSTOMER c ON c.customer_id = r.customer_id
        INNER JOIN VEHICLE v ON v.vehicle_id = r.vehicle_id
        ORDER BY p.payment_date DESC, p.payment_id DESC
    ')->fetchAll();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Luxury Car Rental Platform | CIS 344 PHP &amp; MySQL</title>
    <style>
        :root {
            --bg-dark: #0f172a;
            --bg-card: #ffffff;
            --bg-page: #f1f5f9;
            --text-main: #0f172a;
            --text-muted: #475569;
            --accent-gold: #b45309;
            --accent-blue: #1d4ed8;
            --border-color: #cbd5e1;
            --success-bg: #dcfce7;
            --success-text: #166534;
            --error-bg: #fee2e2;
            --error-text: #991b1b;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
            background-color: var(--bg-page);
            color: var(--text-main);
            line-height: 1.5;
        }
        header {
            background: linear-gradient(135deg, #0f172a 0%, #1e293b 100%);
            color: #ffffff;
            padding: 1.5rem 2rem;
            border-bottom: 4px solid #d97706;
        }
        header h1 {
            margin: 0 0 0.25rem 0;
            font-size: 1.65rem;
            letter-spacing: -0.02em;
        }
        header p {
            margin: 0;
            color: #cbd5e1;
            font-size: 0.92rem;
        }
        .container {
            max-width: 1320px;
            margin: 1.5rem auto;
            padding: 0 1.25rem;
        }
        .alert {
            padding: 1rem 1.25rem;
            border-radius: 8px;
            margin-bottom: 1.5rem;
            font-weight: 500;
            border-left: 5px solid;
        }
        .alert-success {
            background: var(--success-bg);
            color: var(--success-text);
            border-left-color: #15803d;
        }
        .alert-error {
            background: var(--error-bg);
            color: var(--error-text);
            border-left-color: #b91c1c;
        }
        .grid-forms {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 1.25rem;
            margin-bottom: 2rem;
        }
        .card {
            background: var(--bg-card);
            border: 1px solid var(--border-color);
            border-radius: 10px;
            padding: 1.25rem;
            box-shadow: 0 1px 3px rgba(15, 23, 42, 0.06);
        }
        .card h2 {
            margin-top: 0;
            font-size: 1.12rem;
            border-bottom: 2px solid #e2e8f0;
            padding-bottom: 0.5rem;
            color: #0f172a;
        }
        .card h2 span.badge-rule {
            float: right;
            font-size: 0.72rem;
            background: #e0e7ff;
            color: #1e40af;
            padding: 0.15rem 0.5rem;
            border-radius: 999px;
            font-weight: 600;
        }
        .form-group {
            margin-bottom: 0.85rem;
        }
        label {
            display: block;
            font-size: 0.84rem;
            font-weight: 600;
            margin-bottom: 0.25rem;
            color: #334155;
        }
        input[type="text"],
        input[type="email"],
        input[type="date"],
        input[type="datetime-local"],
        input[type="number"],
        select {
            width: 100%;
            padding: 0.52rem 0.65rem;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            font-size: 0.9rem;
            background: #ffffff;
        }
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 0.5rem;
            margin-bottom: 0.5rem;
            font-size: 0.86rem;
        }
        button.btn {
            width: 100%;
            padding: 0.62rem 1rem;
            border: none;
            border-radius: 6px;
            background: var(--accent-blue);
            color: #ffffff;
            font-weight: 600;
            font-size: 0.92rem;
            cursor: pointer;
        }
        button.btn:hover { background: #1e40af; }
        button.btn-gold { background: #b45309; }
        button.btn-gold:hover { background: #92400e; }
        .table-wrap {
            overflow-x: auto;
            margin-bottom: 1.75rem;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 0.86rem;
            background: #ffffff;
        }
        th, td {
            padding: 0.65rem 0.75rem;
            border-bottom: 1px solid #e2e8f0;
            text-align: left;
        }
        th {
            background: #f8fafc;
            font-weight: 700;
            color: #334155;
            white-space: nowrap;
        }
        .num { text-align: right; font-variant-numeric: tabular-nums; }
        .status-pill {
            display: inline-block;
            padding: 0.15rem 0.55rem;
            border-radius: 999px;
            font-size: 0.75rem;
            font-weight: 700;
        }
        .status-Confirmed { background: #dbeafe; color: #1e40af; }
        .status-Ongoing { background: #fef3c7; color: #92400e; }
        .status-Completed { background: #dcfce7; color: #166534; }
        .status-Canceled { background: #f1f5f9; color: #64748b; }
        .status-Overdue { background: #fee2e2; color: #991b1b; }
        .status-InService { background: #dcfce7; color: #166534; }
        .status-OutOfService { background: #fee2e2; color: #991b1b; }
    </style>
</head>
<body>

<header>
    <h1>Luxury Car Rental Management Platform</h1>
    <p>CIS 344 Database Design &amp; Programming (Fall 2026) &bull; PHP &amp; MySQL 8.0 Transactional Platform &bull; Schema: <code>luxury_car_rental</code></p>
</header>

<div class="container">

    <?php if (!$dbConnected): ?>
        <div class="alert alert-error">
            <strong>Database Connection Notice:</strong> Could not connect to MySQL schema <code><?= e(DB_NAME) ?></code> on <code><?= e(DB_HOST) ?>:<?= e(DB_PORT) ?></code> (<?= e($dbError) ?>).<br>
            To initialize the database, import <code>schema.sql</code> into MySQL Server 8.0+ (<code>mysql -u root -p &lt; schema.sql</code>) and verify your credentials in <code>db_connect.php</code>.
        </div>
    <?php endif; ?>

    <?php if ($flashMessage !== null): ?>
        <div class="alert <?= $flashType === 'error' ? 'alert-error' : 'alert-success' ?>">
            <?= e($flashMessage) ?>
        </div>
    <?php endif; ?>

    <!-- ================================================================== -->
    <!-- SECTION 1: DATA ENTRY FORMS (SAVING TO MYSQL DATABASE)             -->
    <!-- ================================================================== -->
    <div class="grid-forms">

        <!-- FORM 1: CUSTOMER REGISTRATION -->
        <div class="card">
            <h2>1. Register Customer <span class="badge-rule">BR1 &bull; BR2</span></h2>
            <form method="post" action="index.php">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="form_action" value="register_customer">

                <div class="form-group">
                    <label for="first_name">First Name</label>
                    <input type="text" id="first_name" name="first_name" required placeholder="e.g., Dhiya">
                </div>
                <div class="form-group">
                    <label for="last_name">Last Name</label>
                    <input type="text" id="last_name" name="last_name" required placeholder="e.g., Kassim">
                </div>
                <div class="form-group">
                    <label for="email">Email Address</label>
                    <input type="email" id="email" name="email" required placeholder="renter@example.com">
                </div>
                <div class="form-group">
                    <label for="phone">Phone Number</label>
                    <input type="text" id="phone" name="phone" required placeholder="347-230-3834">
                </div>
                <div class="form-group">
                    <label for="license_number">Driver's License Number (Unique)</label>
                    <input type="text" id="license_number" name="license_number" required placeholder="NY-587693-DK">
                </div>
                <div class="form-group">
                    <label for="license_state">Issuing State / Region</label>
                    <input type="text" id="license_state" name="license_state" required placeholder="NY" maxlength="40">
                </div>
                <div class="form-group">
                    <label for="license_expiry">License Expiration Date</label>
                    <input type="date" id="license_expiry" name="license_expiry" required>
                </div>
                <button type="submit" class="btn">Save Customer to Database</button>
            </form>
        </div>

        <!-- FORM 2: NEW RESERVATION (TRANSACTIONAL DOUBLE-BOOKING GUARD) -->
        <div class="card">
            <h2>2. Book Exotic Reservation <span class="badge-rule">BR5 &bull; BR6 &bull; BR8</span></h2>
            <form method="post" action="index.php">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="form_action" value="create_reservation">

                <div class="form-group">
                    <label for="res_customer_id">Main Renter (CUSTOMER)</label>
                    <select id="res_customer_id" name="customer_id" required>
                        <option value="">-- Select Registered Renter --</option>
                        <?php foreach ($customers as $c): ?>
                            <option value="<?= (int)$c['customer_id'] ?>">
                                #<?= (int)$c['customer_id'] ?> - <?= e($c['first_name'] . ' ' . $c['last_name']) ?> (<?= e($c['license_number']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="res_vehicle_id">Exotic Vehicle (VEHICLE)</label>
                    <select id="res_vehicle_id" name="vehicle_id" required>
                        <option value="">-- Select Exotic Vehicle --</option>
                        <?php foreach ($vehicles as $v): ?>
                            <option value="<?= (int)$v['vehicle_id'] ?>">
                                <?= e($v['model_year'] . ' ' . $v['make'] . ' ' . $v['model']) ?>
                                ($<?= e(number_format((float)$v['daily_rate'], 2)) ?>/day | <?= e($v['service_status']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="scheduled_pickup">Scheduled Pickup Date &amp; Time</label>
                    <input type="datetime-local" id="scheduled_pickup" name="scheduled_pickup" required>
                </div>
                <div class="form-group">
                    <label for="scheduled_return">Scheduled Return Date &amp; Time</label>
                    <input type="datetime-local" id="scheduled_return" name="scheduled_return" required>
                </div>
                <div class="form-group">
                    <label for="notes">Booking Notes (Optional)</label>
                    <input type="text" id="notes" name="notes" placeholder="VIP delivery / event details">
                </div>
                <button type="submit" class="btn btn-gold">Lock Rate &amp; Confirm Booking</button>
            </form>
        </div>

        <!-- FORM 3: RENTAL AGREEMENT DISPATCH & RETURN -->
        <div class="card">
            <h2>3. Dispatch &amp; Return <span class="badge-rule">BR11–BR15</span></h2>
            <form method="post" action="index.php" style="margin-bottom: 1.1rem; padding-bottom: 1rem; border-bottom: 1px dashed #cbd5e1;">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="form_action" value="create_rental_agreement">

                <div class="form-group">
                    <label for="ra_reservation_id">Confirmed Reservation to Dispatch</label>
                    <select id="ra_reservation_id" name="reservation_id" required>
                        <option value="">-- Select Reservation --</option>
                        <?php foreach ($reservations as $r): ?>
                            <?php if ($r['rental_id'] === null && $r['reservation_status'] === 'Confirmed'): ?>
                                <option value="<?= (int)$r['reservation_id'] ?>">
                                    Res #<?= (int)$r['reservation_id'] ?>: <?= e($r['first_name'] . ' ' . $r['last_name']) ?> &mdash; <?= e($r['make'] . ' ' . $r['model']) ?>
                                </option>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="actual_pickup">Actual Pickup Timestamp</label>
                    <input type="datetime-local" id="actual_pickup" name="actual_pickup" required>
                </div>
                <div class="form-group">
                    <label for="pickup_odometer">Pickup Odometer Reading (Miles)</label>
                    <input type="number" id="pickup_odometer" name="pickup_odometer" min="0" required placeholder="e.g., 4120">
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" id="license_verified" name="license_verified" value="1" required>
                    <label for="license_verified" style="margin:0;">Driver's License Verified (BR13)</label>
                </div>
                <div class="checkbox-group">
                    <input type="checkbox" id="insurance_verified" name="insurance_verified" value="1" required>
                    <label for="insurance_verified" style="margin:0;">Exotic Insurance Policy Verified (BR13)</label>
                </div>
                <button type="submit" class="btn">Create Rental Agreement (Dispatch)</button>
            </form>

            <!-- Vehicle Return Sub-Form -->
            <form method="post" action="index.php">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="form_action" value="complete_return">

                <div class="form-group">
                    <label for="rental_id">Active Rental to Check In</label>
                    <select id="rental_id" name="rental_id" required>
                        <option value="">-- Select Ongoing Rental --</option>
                        <?php foreach ($activeAgreements as $ag): ?>
                            <option value="<?= (int)$ag['rental_id'] ?>">
                                Rental #<?= (int)$ag['rental_id'] ?> (Res #<?= (int)$ag['reservation_id'] ?>) - <?= e($ag['make'] . ' ' . $ag['model']) ?> [Start: <?= (int)$ag['pickup_odometer'] ?> mi]
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="actual_return">Actual Return Timestamp</label>
                    <input type="datetime-local" id="actual_return" name="actual_return" required>
                </div>
                <div class="form-group">
                    <label for="return_odometer">Return Odometer Reading (&ge; Pickup)</label>
                    <input type="number" id="return_odometer" name="return_odometer" min="0" required placeholder="e.g., 4350">
                </div>
                <button type="submit" class="btn">Complete Return Check-In</button>
            </form>
        </div>

        <!-- FORM 4: RECORD PAYMENT -->
        <div class="card">
            <h2>4. Record Payment <span class="badge-rule">BR16–BR18</span></h2>
            <form method="post" action="index.php">
                <input type="hidden" name="csrf_token" value="<?= e($csrfToken) ?>">
                <input type="hidden" name="form_action" value="record_payment">

                <div class="form-group">
                    <label for="pay_reservation_id">Target Reservation</label>
                    <select id="pay_reservation_id" name="reservation_id" required>
                        <option value="">-- Select Reservation --</option>
                        <?php foreach ($reservations as $r): ?>
                            <option value="<?= (int)$r['reservation_id'] ?>">
                                Res #<?= (int)$r['reservation_id'] ?> (<?= e($r['first_name'] . ' ' . $r['last_name']) ?> - <?= e($r['make'] . ' ' . $r['model']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label for="amount">Payment Amount ($ USD, &gt; 0)</label>
                    <input type="number" id="amount" name="amount" step="0.01" min="0.01" required placeholder="1295.00">
                </div>
                <div class="form-group">
                    <label for="payment_method">Payment Method</label>
                    <select id="payment_method" name="payment_method" required>
                        <option value="Credit Card">Credit Card</option>
                        <option value="Debit Card">Debit Card</option>
                        <option value="Wire Transfer">Wire Transfer</option>
                        <option value="Cash">Cash</option>
                    </select>
                </div>
                <div class="form-group">
                    <label for="reference_note">Payment Reference / Receipt Note</label>
                    <input type="text" id="reference_note" name="reference_note" placeholder="e.g., Advance rental payment">
                </div>
                <button type="submit" class="btn">Record Payment in Database</button>
            </form>
        </div>

    </div>

    <!-- ================================================================== -->
    <!-- SECTION 2: AVAILABILITY SEARCH FILTER                              -->
    <!-- ================================================================== -->
    <div class="card" style="margin-bottom: 1.75rem;">
        <h2>Search Available Exotic Fleet by Time Window <span class="badge-rule">BR5 &bull; BR6 &bull; BR10</span></h2>
        <form method="get" action="index.php" style="display:flex; flex-wrap:wrap; gap:1rem; align-items:flex-end; margin-bottom:1rem;">
            <div style="flex:1; min-width:220px;">
                <label for="search_pickup">Desired Pickup Date/Time</label>
                <input type="datetime-local" id="search_pickup" name="search_pickup" value="<?= e($searchPickup) ?>" required>
            </div>
            <div style="flex:1; min-width:220px;">
                <label for="search_return">Desired Return Date/Time</label>
                <input type="datetime-local" id="search_return" name="search_return" value="<?= e($searchReturn) ?>" required>
            </div>
            <div>
                <button type="submit" class="btn" style="width:auto; padding:0.58rem 1.4rem;">Check Available Cars</button>
            </div>
        </form>

        <div class="table-wrap" style="margin-bottom:0;">
            <table>
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>VIN (17-Char)</th>
                        <th>Vehicle</th>
                        <th>Category</th>
                        <th class="num">Daily Rate</th>
                        <th class="num">Security Deposit</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($availableVehicles)): ?>
                        <tr><td colspan="6">No available <code>InService</code> vehicles found for the selected window (or database is offline).</td></tr>
                    <?php else: ?>
                        <?php foreach ($availableVehicles as $av): ?>
                            <tr>
                                <td>#<?= (int)$av['vehicle_id'] ?></td>
                                <td><code><?= e($av['vin']) ?></code></td>
                                <td><strong><?= e($av['model_year'] . ' ' . $av['make'] . ' ' . $av['model']) ?></strong></td>
                                <td><?= e($av['category']) ?></td>
                                <td class="num">$<?= e(number_format((float)$av['daily_rate'], 2)) ?></td>
                                <td class="num">$<?= e(number_format((float)$av['required_deposit'], 2)) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>

    <!-- ================================================================== -->
    <!-- SECTION 3: LIVE RELATIONAL RESERVATIONS, RENTALS & PAYMENTS LEDGER -->
    <!-- ================================================================== -->
    <div class="card table-wrap">
        <h2>Reservations, Active Rental Agreements &amp; Payment Balances (5-Table Relational Join)</h2>
        <table>
            <thead>
                <tr>
                    <th>Res #</th>
                    <th>Customer (License)</th>
                    <th>Vehicle</th>
                    <th>Scheduled Window</th>
                    <th class="num">Days (BR9)</th>
                    <th class="num">Locked Rate</th>
                    <th class="num">Rental Total</th>
                    <th class="num">Deposit (BR18)</th>
                    <th class="num">Paid So Far</th>
                    <th>Odometer (Pick/Ret)</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($reservations)): ?>
                    <tr><td colspan="11">No reservation records loaded yet.</td></tr>
                <?php else: ?>
                    <?php foreach ($reservations as $r): ?>
                        <?php
                            $days = calculateBillingDays($r['scheduled_pickup'], $r['scheduled_return']);
                            $rentalCharge = $days * (float)$r['agreed_daily_rate'];
                            $statusLabel = $r['reservation_status'];
                        ?>
                        <tr>
                            <td><strong>#<?= (int)$r['reservation_id'] ?></strong></td>
                            <td>
                                <?= e($r['first_name'] . ' ' . $r['last_name']) ?><br>
                                <small style="color:#64748b;"><?= e($r['license_number']) ?></small>
                            </td>
                            <td><?= e($r['model_year'] . ' ' . $r['make'] . ' ' . $r['model']) ?></td>
                            <td>
                                <small><strong>Pick:</strong> <?= e($r['scheduled_pickup']) ?><br>
                                <strong>Ret:</strong> <?= e($r['scheduled_return']) ?></small>
                            </td>
                            <td class="num"><?= $days ?></td>
                            <td class="num">$<?= e(number_format((float)$r['agreed_daily_rate'], 2)) ?></td>
                            <td class="num"><strong>$<?= e(number_format($rentalCharge, 2)) ?></strong></td>
                            <td class="num">$<?= e(number_format((float)$r['deposit_amount'], 2)) ?></td>
                            <td class="num">$<?= e(number_format((float)$r['total_paid'], 2)) ?></td>
                            <td>
                                <?php if ($r['rental_id'] !== null): ?>
                                    <small>Out: <?= e(number_format((int)$r['pickup_odometer'])) ?> mi<br>
                                    In: <?= $r['return_odometer'] !== null ? e(number_format((int)$r['return_odometer'])) . ' mi' : '<em>Still Out</em>' ?></small>
                                <?php else: ?>
                                    <small style="color:#64748b;">Not Dispatched</small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="status-pill status-<?= e($statusLabel) ?>"><?= e($statusLabel) ?></span>
                                <?php if ((int)$r['is_overdue'] === 1): ?>
                                    <br><span class="status-pill status-Overdue" style="margin-top:4px;">OVERDUE</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

</div>

</body>
</html>