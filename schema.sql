-- ============================================================================
-- CIS 344 - Database Design and Programming (Fall 2026)
-- Project: Luxury Car Rental Database & PHP Web Platform
-- Author: Dhiya Kassim 
-- Target DBMS: MySQL Server 8.0.46+
-- Schema Name: luxury_car_rental
-- ============================================================================
-- Implements the 5 core tables (CUSTOMER, VEHICLE, RESERVATION,
-- RENTAL_AGREEMENT, PAYMENT), relational foreign keys, UNIQUE constraints,
-- and 11 enforced CHECK constraints defined in Business Rules BR1-BR19.
-- ============================================================================

DROP DATABASE IF EXISTS luxury_car_rental;
CREATE DATABASE luxury_car_rental
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE luxury_car_rental;

-- ----------------------------------------------------------------------------
-- 1. CUSTOMER Table (BR1, BR2)
-- Stores main renter identity, contact info, and driver's license details.
-- ----------------------------------------------------------------------------
CREATE TABLE CUSTOMER (
    customer_id INT AUTO_INCREMENT PRIMARY KEY,
    first_name VARCHAR(60) NOT NULL,
    last_name VARCHAR(60) NOT NULL,
    email VARCHAR(120) NOT NULL UNIQUE,
    phone VARCHAR(25) NOT NULL,
    license_number VARCHAR(40) NOT NULL UNIQUE,
    license_state VARCHAR(40) NOT NULL,
    license_expiry DATE NOT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_customer_email_format CHECK (email LIKE '%_@_%._%'),
    CONSTRAINT chk_customer_license_nonempty CHECK (CHAR_LENGTH(TRIM(license_number)) >= 5)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- 2. VEHICLE Table (BR3, BR4, BR6)
-- Tracks exotic fleet inventory, unique 17-character VIN, daily rental rate,
-- standard security deposit requirement, and service status.
-- ----------------------------------------------------------------------------
CREATE TABLE VEHICLE (
    vehicle_id INT AUTO_INCREMENT PRIMARY KEY,
    vin CHAR(17) NOT NULL UNIQUE,
    make VARCHAR(50) NOT NULL,
    model VARCHAR(60) NOT NULL,
    model_year INT NOT NULL,
    category VARCHAR(40) NOT NULL DEFAULT 'Exotic Coupe',
    daily_rate DECIMAL(10, 2) NOT NULL,
    required_deposit DECIMAL(10, 2) NOT NULL DEFAULT 2500.00,
    current_odometer INT NOT NULL DEFAULT 0,
    service_status ENUM('InService', 'OutOfService') NOT NULL DEFAULT 'InService',
    CONSTRAINT chk_vehicle_vin_length CHECK (CHAR_LENGTH(vin) = 17),
    CONSTRAINT chk_vehicle_year CHECK (model_year >= 2015 AND model_year <= 2030),
    CONSTRAINT chk_vehicle_daily_rate CHECK (daily_rate > 0.00),
    CONSTRAINT chk_vehicle_deposit CHECK (required_deposit >= 0.00)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- 3. RESERVATION Table (BR2, BR4, BR5, BR7, BR8, BR9, BR10, BR18, BR19)
-- Links one CUSTOMER and one VEHICLE. Locks in agreed_daily_rate and
-- deposit_amount at booking time so later vehicle rate changes do not alter
-- historical or confirmed bookings.
-- ----------------------------------------------------------------------------
CREATE TABLE RESERVATION (
    reservation_id INT AUTO_INCREMENT PRIMARY KEY,
    customer_id INT NOT NULL,
    vehicle_id INT NOT NULL,
    scheduled_pickup DATETIME NOT NULL,
    scheduled_return DATETIME NOT NULL,
    agreed_daily_rate DECIMAL(10, 2) NOT NULL,
    deposit_amount DECIMAL(10, 2) NOT NULL,
    reservation_status ENUM('Confirmed', 'Ongoing', 'Completed', 'Canceled') NOT NULL DEFAULT 'Confirmed',
    notes VARCHAR(255) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_reservation_customer
        FOREIGN KEY (customer_id) REFERENCES CUSTOMER(customer_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT fk_reservation_vehicle
        FOREIGN KEY (vehicle_id) REFERENCES VEHICLE(vehicle_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_reservation_dates CHECK (scheduled_return > scheduled_pickup),
    CONSTRAINT chk_reservation_rate CHECK (agreed_daily_rate > 0.00),
    CONSTRAINT chk_reservation_deposit CHECK (deposit_amount >= 0.00)
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- 4. RENTAL_AGREEMENT Table (BR11, BR12, BR13, BR14, BR15)
-- 1-to-0..1 relationship with RESERVATION (enforced via UNIQUE on reservation_id).
-- Created at vehicle pickup after driver's license and insurance verification.
-- actual_return and return_odometer remain NULL while the rental is active.
-- ----------------------------------------------------------------------------
CREATE TABLE RENTAL_AGREEMENT (
    rental_id INT AUTO_INCREMENT PRIMARY KEY,
    reservation_id INT NOT NULL UNIQUE,
    license_verified TINYINT(1) NOT NULL,
    insurance_verified TINYINT(1) NOT NULL,
    actual_pickup DATETIME NOT NULL,
    actual_return DATETIME DEFAULT NULL,
    pickup_odometer INT NOT NULL,
    return_odometer INT DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT fk_rental_reservation
        FOREIGN KEY (reservation_id) REFERENCES RESERVATION(reservation_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_rental_verification CHECK (license_verified = 1 AND insurance_verified = 1),
    CONSTRAINT chk_rental_dates_and_odometer CHECK (
        pickup_odometer >= 0
        AND (actual_return IS NULL OR actual_return > actual_pickup)
        AND (return_odometer IS NULL OR return_odometer >= pickup_odometer)
    )
) ENGINE=InnoDB;

-- ----------------------------------------------------------------------------
-- 5. PAYMENT Table (BR16, BR17, BR18)
-- 1-to-many relationship with RESERVATION. Tracks positive incoming payments
-- separately from security deposit holds.
-- ----------------------------------------------------------------------------
CREATE TABLE PAYMENT (
    payment_id INT AUTO_INCREMENT PRIMARY KEY,
    reservation_id INT NOT NULL,
    payment_date DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    amount DECIMAL(10, 2) NOT NULL,
    payment_method ENUM('Credit Card', 'Debit Card', 'Wire Transfer', 'Cash') NOT NULL,
    reference_note VARCHAR(120) DEFAULT NULL,
    CONSTRAINT fk_payment_reservation
        FOREIGN KEY (reservation_id) REFERENCES RESERVATION(reservation_id)
        ON UPDATE CASCADE ON DELETE RESTRICT,
    CONSTRAINT chk_payment_amount_positive CHECK (amount > 0.00)
) ENGINE=InnoDB;

-- ============================================================================
-- SAMPLE SEED DATA FOR TESTING & DEMONSTRATION
-- ============================================================================

INSERT INTO CUSTOMER (first_name, last_name, email, phone, license_number, license_state, license_expiry) VALUES
('Dhiya', 'Kassim', 'dhiyakassim22@gmail.com', '347-230-3834', 'NY-587693-DK', 'NY', '2029-08-15'),
('Meily', 'Rodriguez', 'meily.rodriguez@lehman.cuny.edu', '718-555-0142', 'NY-884120-MR', 'NY', '2028-11-30'),
('Ali', 'Mustafaj', 'ali.mustafaj@lehman.cuny.edu', '917-555-0198', 'NJ-442910-AM', 'NJ', '2027-05-20'),
('Marcus', 'Vance', 'mvance@exoticfleet.example.com', '212-555-0177', 'CT-901245-MV', 'CT', '2028-03-10');

INSERT INTO VEHICLE (vin, make, model, model_year, category, daily_rate, required_deposit, current_odometer, service_status) VALUES
('ZHWUC1ZD9KLA09121', 'Lamborghini', 'Huracan EVO Spyder', 2024, 'Exotic Roadster', 1295.00, 5000.00, 4120, 'InService'),
('ZFF92LLA1N0268412', 'Ferrari', 'F8 Tributo', 2023, 'Supercar Coupe', 1450.00, 5000.00, 3280, 'InService'),
('WP0AB2A90PS224109', 'Porsche', '911 GT3 RS', 2025, 'Track Coupe', 995.00, 3500.00, 1890, 'InService'),
('W1K6G7GB5PA190433', 'Mercedes-AMG', 'G 63 SUV', 2024, 'Luxury SUV', 695.00, 2500.00, 8450, 'InService'),
('SCA666D59PU214880', 'Rolls-Royce', 'Cullinan Black Badge', 2024, 'Ultra-Luxury SUV', 1650.00, 7500.00, 5600, 'InService'),
('SBM14DCA8PW007319', 'McLaren', '720S Performance', 2023, 'Supercar Coupe', 1350.00, 5000.00, 6110, 'OutOfService');

-- Sample Reservations (including Completed, Ongoing, Confirmed, and Canceled)
INSERT INTO RESERVATION (customer_id, vehicle_id, scheduled_pickup, scheduled_return, agreed_daily_rate, deposit_amount, reservation_status, notes) VALUES
(1, 3, '2026-11-10 10:00:00', '2026-11-12 10:00:00', 995.00, 3500.00, 'Completed', 'Weekend track package - returned clean'),
(2, 1, '2026-11-15 12:00:00', '2026-11-18 12:00:00', 1295.00, 5000.00, 'Ongoing', 'VIP Manhattan event rental'),
(3, 4, '2026-11-20 09:00:00', '2026-11-23 09:00:00', 695.00, 2500.00, 'Confirmed', 'Thanksgiving early booking'),
(4, 2, '2026-11-13 14:00:00', '2026-11-15 14:00:00', 1450.00, 5000.00, 'Canceled', 'Customer rescheduled trip');

-- Sample Rental Agreements (Reservation #1 completed, Reservation #2 active)
INSERT INTO RENTAL_AGREEMENT (reservation_id, license_verified, insurance_verified, actual_pickup, actual_return, pickup_odometer, return_odometer) VALUES
(1, 1, 1, '2026-11-10 10:05:00', '2026-11-12 09:45:00', 1650, 1890),
(2, 1, 1, '2026-11-15 12:15:00', NULL, 4120, NULL);

-- Sample Payments
INSERT INTO PAYMENT (reservation_id, payment_date, amount, payment_method, reference_note) VALUES
(1, '2026-11-09 16:30:00', 995.00, 'Credit Card', 'Advance 50% booking payment'),
(1, '2026-11-10 10:05:00', 995.00, 'Credit Card', 'Balance paid at pickup'),
(2, '2026-11-15 12:10:00', 2590.00, 'Wire Transfer', 'Partial advance payment (2 days)'),
(3, '2026-11-16 09:00:00', 695.00, 'Debit Card', 'Initial reservation deposit payment');