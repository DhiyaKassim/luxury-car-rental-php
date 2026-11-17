# Luxury Car Rental PHP & MySQL Platform (`luxury_car_rental`)

**Course:** CIS 344 — Database Design and Programming (Fall 2026, Lehman College CUNY)

**Author:** Dhiya Kassim 

**Database Engine:** MySQL Server 8.0.46+



## 1. Project Overview & Textbook Adaptation

This platform adapts the classic PHP and MySQL database-driven web patterns from the Pearson *PHP & MySQL* curriculum (`Pearson-PHP-MYSQL-complete` on uCertify) into a full-featured **Luxury Car Rental Management Platform** connected to the `luxury_car_rental` relational schema.

The domain rules are modeled on [Enterprise's Exotic Car Rental Requirements](https://www.enterprise.com/en/car-rental-faqs/us-exotic-car-rental/requirements-for-renting-an-exotic-car.html) (driver's license verification, exotic insurance verification, and separate security deposit tracking) and enforce all 19 Business Rules (`BR1`–`BR19`) and 11 `CHECK` constraints across five normalized tables:

1. `CUSTOMER` — Stores main renter profiles, contact details, unique driver's license numbers, issuing states, and expiration dates (`BR1`, `BR2`).

2. `VEHICLE` — Tracks exotic fleet vehicles, unique 17-character VINs, model years, daily rental rates, required security deposits, odometer readings, and service status (`BR3`, `BR4`, `BR6`).

3. `RESERVATION` — Links one customer and one vehicle for a scheduled pickup and return window while locking in the `agreed_daily_rate` and `deposit_amount` at booking time (`BR5`, `BR7`, `BR8`, `BR9`, `BR10`).

4. `RENTAL_AGREEMENT` — 1-to-0..1 operational record created when a customer picks up the vehicle after license and insurance verification, tracking actual pickup/return timestamps and odometer readings (`BR11`–`BR15`).

5. `PAYMENT` — 1-to-many ledger tracking positive incoming rental payments tied to a reservation (`BR16`–`BR18`).

## 2. Repository File Structure

| File | Role in the Platform | 
| ----- | ----- | 
| `schema.sql` | Complete MySQL 8.0 DDL script creating the `luxury_car_rental` schema, all 5 tables, primary/foreign keys, `UNIQUE` constraints, 11 enforced `CHECK` constraints, and realistic exotic fleet seed data. | 
| `db_connect.php` | Secure PDO connection singleton (`PDO::ATTR_EMULATE_PREPARES => false`, `PDO::ERRMODE_EXCEPTION`), CSRF token generator/validator, XSS output escaper (`e()`), and 24-hour block billing calculator (`calculateBillingDays()`). | 
| `index.php` | Main PHP platform providing 4 interactive database-persisted forms, a live date-window vehicle availability search, and a 5-table relational dashboard with automatic overdue rental detection. | 
| `README.md` | Complete setup, operation, form-to-database workflow, and GitHub deployment documentation. | 

## 3. How the Forms Interact with the Database

Every form in `index.php` submits via HTTP `POST` with a cryptographic session CSRF token (`csrf_token`), validates inputs server-side in PHP, and executes parameterized SQL queries via `PDO::prepare()` and `PDOStatement::execute()`:

| Platform Form (`index.php`) | Target Table(s) | Database Interaction & Enforced Rules | 
| ----- | ----- | ----- | 
| **1. Register Customer** (`form_action = register_customer`) | `CUSTOMER` | Validates email syntax, non-empty license number (`>= 5` chars), and non-expired `license_expiry` date before executing a prepared `INSERT INTO CUSTOMER`. | 
| **2. Book Exotic Reservation** (`form_action = create_reservation`) | `VEHICLE`, `RESERVATION` | Opens a MySQL transaction (`$pdo->beginTransaction()`), locks the target vehicle row with `SELECT ... FOR UPDATE`, verifies `service_status = 'InService'` (`BR6`), queries `RESERVATION` to ensure zero overlapping `Confirmed` or `Ongoing` bookings (`scheduled_pickup < :new_return AND scheduled_return > :new_pickup`, `BR5`/`BR10`), snapshots `daily_rate` and `required_deposit` into `RESERVATION` (`BR8`), and commits. | 
| **3A. Dispatch / Create Rental Agreement** (`form_action = create_rental_agreement`) | `RESERVATION`, `RENTAL_AGREEMENT` | Verifies inside a transaction that the reservation is not `Canceled` (`BR12`), enforces both `license_verified = 1` and `insurance_verified = 1` (`BR13`), inserts the `RENTAL_AGREEMENT` with `actual_return = NULL`, and updates `RESERVATION.reservation_status` to `'Ongoing'`. | 
| **3B. Complete Vehicle Return** (`form_action = complete_return`) | `RENTAL_AGREEMENT`, `RESERVATION`, `VEHICLE` | Verifies `actual_return > actual_pickup` and `return_odometer >= pickup_odometer` (`BR15`), updates `RENTAL_AGREEMENT`, marks `RESERVATION` as `'Completed'`, and updates `VEHICLE.current_odometer`. | 
| **4. Record Payment** (`form_action = record_payment`) | `PAYMENT` | Validates `amount > 0.00` (`BR17`) and executes a prepared `INSERT INTO PAYMENT` linked to the selected `reservation_id`. | 

## 4. Unique Features & Innovations Implemented

In Project 1 (`LuxuryCarRental_Report`), three specific database-only limitations were identified for future improvement. This PHP platform resolves all three directly:

1. **Atomic Transaction Locking to Prevent Double-Bookings (`BR5` & `BR10`)**: Instead of running a passive availability check that could suffer from race conditions, `index.php` wraps new reservation creation inside an InnoDB transaction (`$pdo->beginTransaction()`), locks the vehicle row using `SELECT ... FOR UPDATE`, checks for any conflicting `Confirmed` or `Ongoing` reservation where `scheduled_pickup < :new_return AND scheduled_return > :new_pickup`, and rolls back immediately if a conflict exists.

2. **Application + Database Enforcement of `OutOfService` Vehicles (`BR6`)**: Vehicles marked `OutOfService` (such as the sample 2023 McLaren 720S in `schema.sql`) are automatically excluded from availability searches and blocked during reservation submission.

3. **Preventing Canceled Reservations from Generating Rental Agreements (`BR12`)**: The rental dispatch handler inspects `RESERVATION.reservation_status` inside a transaction and rejects any attempt to create a `RENTAL_AGREEMENT` for a `Canceled` booking.

4. **Full 24-Hour Block Billing Engine (`BR9`) & Historical Rate Locking (`BR8`)**: `calculateBillingDays()` computes `ceil((return_ts - pickup_ts) / 86400)` with a 1-day minimum and multiplies by `RESERVATION.agreed_daily_rate` so future fleet price changes never alter existing bookings.

5. **Real-Time Overdue Rental Flagging**: The 5-table relational join in `index.php` compares `NOW() > r.scheduled_return` when `ra.actual_return IS NULL` and highlights overdue vehicles in the dashboard.

## 5. Setup & Operation Instructions

### Step 1: Import the MySQL 8.0 Database Schema

Open **MySQL Workbench 8.0**  and run `schema.sql`:

```
mysql -u root -p < schema.sql

```

This creates the `luxury_car_rental` database, sets up all 5 tables and 11 `CHECK` constraints, and loads sample customers, exotic vehicles, reservations, rental agreements, and payments.

### Step 2: Configure Database Credentials

By default, `db_connect.php` connects to `127.0.0.1:3306`, database `luxury_car_rental`, user `root`, and an empty password (standard for XAMPP/local setups). If your MySQL root user has a password, either set environment variables when launching PHP or update lines 24–28 of `db_connect.php`:

```
export DB_HOST="127.0.0.1"
export DB_PORT="3306"
export DB_NAME="luxury_car_rental"
export DB_USER="root"
export DB_PASS="your_mysql_password"

```

### Step 3: Start the PHP Platform

You can run the platform using PHP's built-in web server (or place the folder inside XAMPP `htdocs/` or `/var/www/html/` on Ubuntu):

```
php -S localhost:8000

```

Open **`http://localhost:8000/index.php`** in your browser to register customers, search available exotic vehicles, book reservations, dispatch/return rental agreements, and record payments.

## 6. Publishing to Your GitHub Repository for Submission

To publish these files to a new GitHub repository and get your submission link, place `schema.sql`, `db_connect.php`, `index.php`, and `README.md` into a local folder and run:

```
mkdir luxury-car-rental-php
cd luxury-car-rental-php
# Copy schema.sql, db_connect.php, index.php, and README.md into this directory

git init
git add schema.sql db_connect.php index.php README.md
git commit -m "Initial commit: CIS 344 Luxury Car Rental PHP & MySQL Platform"
git branch -M main

# Replace <YOUR_GITHUB_USERNAME> with your GitHub username:
git remote add origin https://github.com/<YOUR_GITHUB_USERNAME>/luxury-car-rental-php.git
git push -u origin main

```
