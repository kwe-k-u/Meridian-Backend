drop database meridian_os;
CREATE DATABASE IF NOT EXISTS meridian_os;
USE meridian_os;

-- =============================================
-- ID GENERATION FUNCTIONS
-- =============================================

DELIMITER //

DROP FUNCTION IF EXISTS generate_id //

CREATE FUNCTION generate_id(prefix VARCHAR(4))
RETURNS VARCHAR(20)
NOT DETERMINISTIC
NO SQL
BEGIN
    RETURN CONCAT(
        prefix, '_',
        UPPER(SUBSTRING(REPLACE(UUID(), '-', ''), 1, 12))
    );
END //

DELIMITER ;

-- =============================================
-- COMPANIES TABLE
-- =============================================

CREATE TABLE IF NOT EXISTS companies (
    company_id VARCHAR(20) PRIMARY KEY,
    company_name VARCHAR(100) NOT NULL,
    city_of_operation VARCHAR(50),
    status ENUM('active', 'disabled') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- =============================================
-- USERS TABLE
-- Firebase UID stored for authentication via Google Sign-In / email link
-- =============================================

CREATE TABLE IF NOT EXISTS users (
    user_id VARCHAR(20) PRIMARY KEY,
    firebase_uid VARCHAR(128) UNIQUE,
    email VARCHAR(255) NOT NULL UNIQUE,
    display_name VARCHAR(100),
    phone VARCHAR(20),
    avatar_url VARCHAR(500),
    status ENUM('pending', 'active', 'disabled') DEFAULT 'pending',
    last_login DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- =============================================
-- USER-COMPANY LINK TABLE
-- =============================================

CREATE TABLE IF NOT EXISTS user_company (
    user_id VARCHAR(20),
    company_id VARCHAR(20),
    role ENUM('owner', 'admin', 'member') DEFAULT 'member',
    is_default BOOLEAN DEFAULT FALSE,
    is_enabled BOOLEAN DEFAULT TRUE,
    joined_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (user_id, company_id),
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE,
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE
);

-- =============================================
-- INVITATIONS TABLE
-- Tracks email invitations sent to prospective users
-- =============================================

CREATE TABLE IF NOT EXISTS invitations (
    token VARCHAR(255) PRIMARY KEY,
    company_id VARCHAR(20) NOT NULL,
    invited_by VARCHAR(20) NOT NULL,
    email VARCHAR(255) NOT NULL,
    role ENUM('admin', 'member') DEFAULT 'member',
    status ENUM('pending', 'accepted', 'expired', 'cancelled') DEFAULT 'pending',
    expires_at DATETIME NOT NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (invited_by) REFERENCES users(user_id) ON DELETE CASCADE
);

-- =============================================
-- ADMINS TABLE
-- System-level administrators who manage the platform
-- =============================================

CREATE TABLE IF NOT EXISTS admins (
    admin_id VARCHAR(20) PRIMARY KEY,
    user_id VARCHAR(20) NOT NULL UNIQUE,
    role ENUM('super_admin', 'support', 'finance') DEFAULT 'support',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE CASCADE
);

-- =============================================
-- SUBSCRIPTION TIERS TABLE
-- Available subscription plans
-- =============================================

CREATE TABLE IF NOT EXISTS subscription_tiers (
    tier_id VARCHAR(20) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    description TEXT,
    price_quarterly INT NOT NULL,
    price_yearly INT,
    currency VARCHAR(3) DEFAULT 'GHS',
    max_employees INT,
    max_trips INT,
    is_active BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- =============================================
-- COMPANY SUBSCRIPTIONS TABLE
-- Tracks which tier each company is on
-- =============================================

CREATE TABLE IF NOT EXISTS company_subscriptions (
    subscription_id VARCHAR(20) PRIMARY KEY,
    company_id VARCHAR(20) NOT NULL,
    tier_id VARCHAR(20) NOT NULL,
    status ENUM('active', 'cancelled', 'expired') DEFAULT 'active',
    billing_interval ENUM('quarterly', 'yearly') DEFAULT 'quarterly',
    start_date DATE NOT NULL,
    end_date DATE,
    auto_renew BOOLEAN DEFAULT TRUE,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (tier_id) REFERENCES subscription_tiers(tier_id) ON DELETE RESTRICT
);

-- =============================================
-- TRANSACTIONS TABLE
-- General ledger for all money movement (inbound and outbound)
-- =============================================

CREATE TABLE IF NOT EXISTS transactions (
    transaction_id VARCHAR(20) PRIMARY KEY,
    amount INT NOT NULL,
    currency VARCHAR(3) DEFAULT 'GHS',
    status ENUM('pending', 'completed', 'failed', 'refunded') DEFAULT 'pending',
    payment_method VARCHAR(50),
    transaction_reference VARCHAR(255),
    paid_at DATETIME,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);

-- =============================================
-- SUBSCRIPTION PAYMENTS TABLE
-- Links a transaction to a company subscription payment
-- =============================================

CREATE TABLE IF NOT EXISTS subscription_payments (
    transaction_id VARCHAR(20) PRIMARY KEY,
    subscription_id VARCHAR(20) NOT NULL,
    company_id VARCHAR(20) NOT NULL,
    initiated_by VARCHAR(20),
    FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id) ON DELETE CASCADE,
    FOREIGN KEY (subscription_id) REFERENCES company_subscriptions(subscription_id) ON DELETE RESTRICT,
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (initiated_by) REFERENCES users(user_id) ON DELETE SET NULL
);


-- =============================================
-- CUSTOMERS TABLE
-- Travellers managed by each company
-- =============================================

CREATE TABLE IF NOT EXISTS customers (
    customer_id VARCHAR(20) PRIMARY KEY,
    company_id VARCHAR(20) NOT NULL,
    first_name VARCHAR(50) NOT NULL,
    last_name VARCHAR(50) NOT NULL,
    email VARCHAR(255),
    phone VARCHAR(20),
    nationality VARCHAR(50),
    date_of_birth DATE,
    passport_number VARCHAR(50),
    notes TEXT,
    status ENUM('active', 'inactive', 'archived') DEFAULT 'active',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE
);

-- =============================================
-- TRIPS TABLE
-- A trip represents a traveller request that can have multiple itinerary plans
-- =============================================

CREATE TABLE IF NOT EXISTS trips (
    trip_id VARCHAR(20) PRIMARY KEY,
    company_id VARCHAR(20) NOT NULL,
    created_by VARCHAR(20),
    trip_name VARCHAR(200) NOT NULL,
    description TEXT,
    start_date DATE,
    end_date DATE,
    budget VARCHAR(50),
    status ENUM('inquiry', 'planning', 'booked', 'in_progress', 'completed', 'cancelled') DEFAULT 'inquiry',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (company_id) REFERENCES companies(company_id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
);


-- =============================================
-- TRIP PAYMENTS TABLE
-- Tracks payments made by customers against a trip
-- =============================================

CREATE TABLE IF NOT EXISTS trip_payments (
    transaction_id VARCHAR(20) PRIMARY KEY,
    trip_id VARCHAR(20) NOT NULL,
    notes TEXT,
    FOREIGN KEY (transaction_id) REFERENCES transactions(transaction_id) ON DELETE CASCADE,
    FOREIGN KEY (trip_id) REFERENCES trips(trip_id) ON DELETE RESTRICT
);

-- =============================================
-- DESTINATIONS TABLE
-- Reusable destination entries
-- =============================================

CREATE TABLE IF NOT EXISTS destinations (
    destination_id VARCHAR(20) PRIMARY KEY,
    name VARCHAR(100) NOT NULL,
    country VARCHAR(100),
    url VARCHAR(500),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
);

-- =============================================
-- ITINERARY TABLE
-- Itinerary plans created under a trip
-- =============================================

CREATE TABLE IF NOT EXISTS itinerary (
    itinerary_id VARCHAR(20) PRIMARY KEY,
    trip_id VARCHAR(20) NOT NULL,
    created_by VARCHAR(20),
    itinerary_name VARCHAR(200) NOT NULL,
    description TEXT,
    start_date DATE,
    end_date DATE,
    status ENUM('draft', 'planning', 'confirmed', 'in_progress', 'completed', 'cancelled') DEFAULT 'draft',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (trip_id) REFERENCES trips(trip_id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- =============================================
-- ITINERARY DAYS TABLE
-- Daily itinerary for each itinerary
-- =============================================

CREATE TABLE IF NOT EXISTS itinerary_days (
    itinerary_day_id VARCHAR(20) PRIMARY KEY,
    itinerary_id VARCHAR(20) NOT NULL,
    day_number INT NOT NULL,
    date DATE,
    title VARCHAR(200),
    description TEXT,
    location VARCHAR(200),
    FOREIGN KEY (itinerary_id) REFERENCES itinerary(itinerary_id) ON DELETE CASCADE
);

-- =============================================
-- ITINERARY DAY DESTINATIONS TABLE
-- Links destinations to specific itinerary days with cost and activities
-- =============================================

CREATE TABLE IF NOT EXISTS itinerary_day_destinations (
    itinerary_day_id VARCHAR(20) NOT NULL,
    destination_id VARCHAR(20) NOT NULL,
    cost VARCHAR(50),
    currency VARCHAR(3) DEFAULT 'GHS',
    activities TEXT,
    booking_url VARCHAR(500),
    PRIMARY KEY (itinerary_day_id, destination_id),
    FOREIGN KEY (itinerary_day_id) REFERENCES itinerary_days(itinerary_day_id) ON DELETE CASCADE,
    FOREIGN KEY (destination_id) REFERENCES destinations(destination_id) ON DELETE RESTRICT
);

-- =============================================
-- ITINERARY FLIGHTS TABLE
-- Flights booked for an itinerary
-- =============================================

CREATE TABLE IF NOT EXISTS itinerary_flights (
    flight_id VARCHAR(20) PRIMARY KEY,
    itinerary_id VARCHAR(20) NOT NULL,
    airline VARCHAR(100),
    flight_number VARCHAR(20),
    departure_airport VARCHAR(100),
    arrival_airport VARCHAR(100),
    departure_datetime DATETIME,
    arrival_datetime DATETIME,
    cost INT,
    currency VARCHAR(3) DEFAULT 'GHS',
    booking_reference VARCHAR(50),
    booking_url VARCHAR(500),
    status ENUM('pending', 'booked', 'confirmed', 'cancelled') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (itinerary_id) REFERENCES itinerary(itinerary_id) ON DELETE CASCADE
);

-- =============================================
-- ITINERARY ACCOMMODATION TABLE
-- Accommodation booked for an itinerary
-- =============================================

CREATE TABLE IF NOT EXISTS itinerary_accommodation (
    accommodation_id VARCHAR(20) PRIMARY KEY,
    itinerary_id VARCHAR(20) NOT NULL,
    accommodation_name VARCHAR(200) NOT NULL,
    address VARCHAR(500),
    check_in_date DATE,
    check_out_date DATE,
    room_type VARCHAR(100),
    cost INT,
    currency VARCHAR(3) DEFAULT 'GHS',
    booking_reference VARCHAR(50),
    booking_url VARCHAR(500),
    status ENUM('pending', 'booked', 'confirmed', 'cancelled') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (itinerary_id) REFERENCES itinerary(itinerary_id) ON DELETE CASCADE
);

-- =============================================
-- TRIP-CUSTOMER LINK TABLE
-- Links customers to the trip (traveller request)
-- =============================================

CREATE TABLE IF NOT EXISTS trip_customers (
    trip_id VARCHAR(20) NOT NULL,
    customer_id VARCHAR(20) NOT NULL,
    role ENUM('primary', 'companion') DEFAULT 'primary',
    added_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (trip_id, customer_id),
    FOREIGN KEY (trip_id) REFERENCES trips(trip_id) ON DELETE CASCADE,
    FOREIGN KEY (customer_id) REFERENCES customers(customer_id) ON DELETE CASCADE
);

-- =============================================
-- CALLS TABLE
-- Online meetings held for a trip
-- =============================================

CREATE TABLE IF NOT EXISTS calls (
    call_id VARCHAR(20) PRIMARY KEY,
    trip_id VARCHAR(20) NOT NULL,
    organized_by VARCHAR(20),
    title VARCHAR(200),
    started_at DATETIME,
    ended_at DATETIME,
    meeting_link VARCHAR(500),
    notes TEXT,
    transcript LONGTEXT,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (trip_id) REFERENCES trips(trip_id) ON DELETE CASCADE,
    FOREIGN KEY (organized_by) REFERENCES users(user_id) ON DELETE SET NULL
);

-- =============================================
-- CALL ACTION ITEMS TABLE
-- Action items and tasks created during a call
-- =============================================

CREATE TABLE IF NOT EXISTS call_action_items (
    action_item_id VARCHAR(20) PRIMARY KEY,
    call_id VARCHAR(20) NOT NULL,
    description TEXT NOT NULL,
    status ENUM('pending', 'checked', 'archived') DEFAULT 'pending',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (call_id) REFERENCES calls(call_id) ON DELETE CASCADE
);

-- =============================================
-- INDEXES
-- =============================================

CREATE INDEX idx_users_email ON users(email);
CREATE INDEX idx_users_firebase_uid ON users(firebase_uid);
CREATE INDEX idx_user_company_role ON user_company(role);
CREATE INDEX idx_invitations_email ON invitations(email);
CREATE INDEX idx_invitations_status ON invitations(status);
CREATE INDEX idx_customers_company ON customers(company_id);
CREATE INDEX idx_customers_email ON customers(email);
CREATE INDEX idx_itinerary_status ON itinerary(status);

CREATE INDEX idx_itinerary_days_itinerary ON itinerary_days(itinerary_id);
CREATE INDEX idx_itinerary_flights_itinerary ON itinerary_flights(itinerary_id);
CREATE INDEX idx_itinerary_accommodation_itinerary ON itinerary_accommodation(itinerary_id);
CREATE INDEX idx_itinerary_day_destinations_day ON itinerary_day_destinations(itinerary_day_id);
CREATE INDEX idx_itinerary_day_destinations_dest ON itinerary_day_destinations(destination_id);
CREATE INDEX idx_trips_company ON trips(company_id);
CREATE INDEX idx_trips_status ON trips(status);
CREATE INDEX idx_trip_customers_trip ON trip_customers(trip_id);
CREATE INDEX idx_trip_customers_customer ON trip_customers(customer_id);
CREATE INDEX idx_calls_trip ON calls(trip_id);
CREATE INDEX idx_call_action_items_call ON call_action_items(call_id);
CREATE INDEX idx_admins_user ON admins(user_id);
CREATE INDEX idx_company_subscriptions_company ON company_subscriptions(company_id);
CREATE INDEX idx_company_subscriptions_status ON company_subscriptions(status);
CREATE INDEX idx_transactions_status ON transactions(status);
CREATE INDEX idx_transactions_reference ON transactions(transaction_reference(191));
CREATE INDEX idx_subscription_payments_sub ON subscription_payments(subscription_id);
CREATE INDEX idx_subscription_payments_company ON subscription_payments(company_id);
CREATE INDEX idx_trip_payments_trip ON trip_payments(trip_id);

-- =============================================
-- STORED PROCEDURES
-- =============================================

DELIMITER //

-- Create a new company with the founding owner
DROP PROCEDURE IF EXISTS create_company //

CREATE PROCEDURE create_company(
    IN p_company_name VARCHAR(100),
    IN p_city_of_operation VARCHAR(50),
    IN p_owner_firebase_uid VARCHAR(128),
    IN p_owner_email VARCHAR(255),
    IN p_owner_display_name VARCHAR(100)
)
BEGIN
    DECLARE v_company_id VARCHAR(20);
    DECLARE v_user_id VARCHAR(20);

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    SET v_company_id = generate_id('CMP');
    SET v_user_id = generate_id('USR');

    INSERT INTO companies (company_id, company_name, city_of_operation)
    VALUES (v_company_id, p_company_name, p_city_of_operation);

    INSERT INTO users (user_id, firebase_uid, email, display_name, status)
    VALUES (v_user_id, p_owner_firebase_uid, p_owner_email, p_owner_display_name, 'active');

    INSERT INTO user_company (user_id, company_id, role, is_default)
    VALUES (v_user_id, v_company_id, 'owner', TRUE);

    SELECT v_company_id AS company_id, v_user_id AS user_id;

    COMMIT;
END //

-- Invite a user to join a company via email
DROP PROCEDURE IF EXISTS invite_user //

CREATE PROCEDURE invite_user(
    IN p_company_id VARCHAR(20),
    IN p_invited_by VARCHAR(20),
    IN p_email VARCHAR(255),
    IN p_role ENUM('admin', 'member')
)
BEGIN
    DECLARE v_token VARCHAR(255);

    SET v_token = UUID();

    INSERT INTO invitations (token, company_id, invited_by, email, role, expires_at)
    VALUES (v_token, p_company_id, p_invited_by, p_email, p_role, DATE_ADD(NOW(), INTERVAL 7 DAY));

    SELECT v_token AS token;
END //

-- Accept an invitation, create or link user account
DROP PROCEDURE IF EXISTS accept_invitation //

CREATE PROCEDURE accept_invitation(
    IN p_token VARCHAR(255),
    IN p_firebase_uid VARCHAR(128),
    IN p_display_name VARCHAR(100),
    IN p_phone VARCHAR(20)
)
BEGIN
    DECLARE v_company_id VARCHAR(20);
    DECLARE v_email VARCHAR(255);
    DECLARE v_role ENUM('admin', 'member');
    DECLARE v_user_id VARCHAR(20);
    DECLARE v_expires_at DATETIME;
    DECLARE v_existing_user_id VARCHAR(20);
    DECLARE v_found INT DEFAULT 0;

    DECLARE EXIT HANDLER FOR SQLEXCEPTION
    BEGIN
        ROLLBACK;
        RESIGNAL;
    END;

    START TRANSACTION;

    SELECT company_id, email, role, expires_at, 1
    INTO v_company_id, v_email, v_role, v_expires_at, v_found
    FROM invitations
    WHERE token = p_token AND status = 'pending'
    FOR UPDATE;

    IF v_found = 0 THEN
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invalid or already used invitation token';
    END IF;

    IF NOW() > v_expires_at THEN
        UPDATE invitations SET status = 'expired' WHERE token = p_token;
        SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'Invitation has expired';
    END IF;

    SELECT user_id INTO v_existing_user_id
    FROM users
    WHERE email = v_email
    LIMIT 1;

    IF v_existing_user_id IS NOT NULL THEN
        SET v_user_id = v_existing_user_id;

        UPDATE users
        SET firebase_uid = COALESCE(firebase_uid, p_firebase_uid),
            status = 'active',
            display_name = COALESCE(display_name, p_display_name),
            phone = COALESCE(phone, p_phone)
        WHERE user_id = v_user_id;
    ELSE
        SET v_user_id = generate_id('USR');

        INSERT INTO users (user_id, firebase_uid, email, display_name, phone, status)
        VALUES (v_user_id, p_firebase_uid, v_email, p_display_name, p_phone, 'active');
    END IF;

    INSERT INTO user_company (user_id, company_id, role)
    VALUES (v_user_id, v_company_id, v_role)
    ON DUPLICATE KEY UPDATE role = v_role;

    UPDATE invitations SET status = 'accepted' WHERE token = p_token;

    SELECT v_user_id AS user_id, v_company_id AS company_id;

    COMMIT;
END //

-- Get all companies a user belongs to
DROP PROCEDURE IF EXISTS get_user_companies //

CREATE PROCEDURE get_user_companies(IN p_user_id VARCHAR(20))
BEGIN
    SELECT
        c.company_id,
        c.company_name,
        c.city_of_operation,
        uc.role,
        uc.is_default
    FROM companies c
    JOIN user_company uc ON c.company_id = uc.company_id
    WHERE uc.user_id = p_user_id AND c.status = 'active'
    ORDER BY uc.is_default DESC, c.created_at DESC;
END //

-- Get all users and pending invitations for a company
DROP PROCEDURE IF EXISTS get_company_members //

CREATE PROCEDURE get_company_members(IN p_company_id VARCHAR(20))
BEGIN
    SELECT
        u.user_id,
        u.display_name,
        u.email,
        u.status,
        u.last_login,
        u.avatar_url,
        uc.role,
        uc.joined_at
    FROM users u
    JOIN user_company uc ON u.user_id = uc.user_id
    WHERE uc.company_id = p_company_id

    UNION ALL

    SELECT
        NULL AS user_id,
        NULL AS display_name,
        i.email,
        'invited' AS status,
        NULL AS last_login,
        NULL AS avatar_url,
        i.role,
        i.created_at AS joined_at
    FROM invitations i
    WHERE i.company_id = p_company_id AND i.status = 'pending'

    ORDER BY joined_at DESC;
END //

-- =============================================
-- CRM PROCEDURES
-- =============================================

-- Create a new destination
DROP PROCEDURE IF EXISTS create_destination //

CREATE PROCEDURE create_destination(
    IN p_name VARCHAR(100),
    IN p_country VARCHAR(100),
    IN p_url VARCHAR(500)
)
BEGIN
    DECLARE v_destination_id VARCHAR(20);
    SET v_destination_id = generate_id('DST');

    INSERT INTO destinations (destination_id, name, country, url)
    VALUES (v_destination_id, p_name, p_country, p_url);

    SELECT v_destination_id AS destination_id;
END //

-- Get all destinations
DROP PROCEDURE IF EXISTS get_destinations //

CREATE PROCEDURE get_destinations()
BEGIN
    SELECT * FROM destinations ORDER BY name;
END //

-- Create a new customer for a company
DROP PROCEDURE IF EXISTS create_customer //

CREATE PROCEDURE create_customer(
    IN p_company_id VARCHAR(20),
    IN p_first_name VARCHAR(50),
    IN p_last_name VARCHAR(50),
    IN p_email VARCHAR(255),
    IN p_phone VARCHAR(20),
    IN p_nationality VARCHAR(50),
    IN p_date_of_birth DATE,
    IN p_passport_number VARCHAR(50),
    IN p_notes TEXT
)
BEGIN
    DECLARE v_customer_id VARCHAR(20);
    SET v_customer_id = generate_id('CST');

    INSERT INTO customers (
        customer_id, company_id, first_name, last_name, email, phone,
        nationality, date_of_birth, passport_number, notes
    ) VALUES (
        v_customer_id, p_company_id, p_first_name, p_last_name, p_email, p_phone,
        p_nationality, p_date_of_birth, p_passport_number, p_notes
    );

    SELECT v_customer_id AS customer_id;
END //

-- Get all customers for a company
DROP PROCEDURE IF EXISTS get_company_customers //

CREATE PROCEDURE get_company_customers(IN p_company_id VARCHAR(20))
BEGIN
    SELECT *
    FROM customers
    WHERE company_id = p_company_id
    ORDER BY created_at DESC;
END //

-- =============================================
-- TRIP PROCEDURES
-- =============================================

-- Create a new trip (traveller request)
DROP PROCEDURE IF EXISTS create_trip //

CREATE PROCEDURE create_trip(
    IN p_company_id VARCHAR(20),
    IN p_created_by VARCHAR(20),
    IN p_trip_name VARCHAR(200),
    IN p_description TEXT,
    IN p_start_date DATE,
    IN p_end_date DATE,
    IN p_budget VARCHAR(50)
)
BEGIN
    DECLARE v_trip_id VARCHAR(20);
    SET v_trip_id = generate_id('TRP');

    INSERT INTO trips (trip_id, company_id, created_by, trip_name, description, start_date, end_date, budget)
    VALUES (v_trip_id, p_company_id, p_created_by, p_trip_name, p_description, p_start_date, p_end_date, p_budget);

    SELECT v_trip_id AS trip_id;
END //

-- Get all trips for a company
DROP PROCEDURE IF EXISTS get_company_trips //

CREATE PROCEDURE get_company_trips(IN p_company_id VARCHAR(20))
BEGIN
    SELECT *
    FROM trips
    WHERE company_id = p_company_id
    ORDER BY created_at DESC;
END //

-- Get full trip details with itineraries and customers
DROP PROCEDURE IF EXISTS get_trip_details //

CREATE PROCEDURE get_trip_details(IN p_trip_id VARCHAR(20))
BEGIN
    -- Trip header
    SELECT * FROM trips WHERE trip_id = p_trip_id;

    -- Itineraries under this trip
    SELECT * FROM itinerary WHERE trip_id = p_trip_id ORDER BY start_date;

    -- Customers linked to this trip
    SELECT c.*, tc.role, tc.added_at
    FROM customers c
    JOIN trip_customers tc ON c.customer_id = tc.customer_id
    WHERE tc.trip_id = p_trip_id;

    -- Calls for this trip
    SELECT * FROM calls WHERE trip_id = p_trip_id ORDER BY started_at;
END //

-- =============================================
-- ITINERARY PROCEDURES
-- =============================================

-- Create a new itinerary under a trip
DROP PROCEDURE IF EXISTS create_itinerary //

CREATE PROCEDURE create_itinerary(
    IN p_trip_id VARCHAR(20),
    IN p_created_by VARCHAR(20),
    IN p_itinerary_name VARCHAR(200),
    IN p_description TEXT,
    IN p_start_date DATE,
    IN p_end_date DATE
)
BEGIN
    DECLARE v_itinerary_id VARCHAR(20);
    SET v_itinerary_id = generate_id('ITN');

    INSERT INTO itinerary (
        itinerary_id, trip_id, created_by, itinerary_name, description,
        start_date, end_date
    ) VALUES (
        v_itinerary_id, p_trip_id, p_created_by, p_itinerary_name, p_description,
        p_start_date, p_end_date
    );

    SELECT v_itinerary_id AS itinerary_id;
END //

-- Add a day to an itinerary
DROP PROCEDURE IF EXISTS add_itinerary_day //

CREATE PROCEDURE add_itinerary_day(
    IN p_itinerary_id VARCHAR(20),
    IN p_day_number INT,
    IN p_date DATE,
    IN p_title VARCHAR(200),
    IN p_description TEXT,
    IN p_location VARCHAR(200)
)
BEGIN
    DECLARE v_itinerary_day_id VARCHAR(20);
    SET v_itinerary_day_id = generate_id('ITD');

    INSERT INTO itinerary_days (itinerary_day_id, itinerary_id, day_number, `date`, title, description, location)
    VALUES (v_itinerary_day_id, p_itinerary_id, p_day_number, p_date, p_title, p_description, p_location);

    SELECT v_itinerary_day_id AS itinerary_day_id;
END //

-- Add a flight to an itinerary
DROP PROCEDURE IF EXISTS add_itinerary_flight //

CREATE PROCEDURE add_itinerary_flight(
    IN p_itinerary_id VARCHAR(20),
    IN p_airline VARCHAR(100),
    IN p_flight_number VARCHAR(20),
    IN p_departure_airport VARCHAR(100),
    IN p_arrival_airport VARCHAR(100),
    IN p_departure_datetime DATETIME,
    IN p_arrival_datetime DATETIME,
    IN p_cost INT,
    IN p_currency VARCHAR(3),
    IN p_booking_reference VARCHAR(50),
    IN p_booking_url VARCHAR(500)
)
BEGIN
    DECLARE v_flight_id VARCHAR(20);
    SET v_flight_id = generate_id('FLT');

    INSERT INTO itinerary_flights (
        flight_id, itinerary_id, airline, flight_number, departure_airport,
        arrival_airport, departure_datetime, arrival_datetime, cost, currency, booking_reference, booking_url
    ) VALUES (
        v_flight_id, p_itinerary_id, p_airline, p_flight_number, p_departure_airport,
        p_arrival_airport, p_departure_datetime, p_arrival_datetime, p_cost, COALESCE(p_currency, 'GHS'), p_booking_reference, p_booking_url
    );

    SELECT v_flight_id AS flight_id;
END //

-- Add accommodation to an itinerary
DROP PROCEDURE IF EXISTS add_itinerary_accommodation //

CREATE PROCEDURE add_itinerary_accommodation(
    IN p_itinerary_id VARCHAR(20),
    IN p_accommodation_name VARCHAR(200),
    IN p_address VARCHAR(500),
    IN p_check_in_date DATE,
    IN p_check_out_date DATE,
    IN p_room_type VARCHAR(100),
    IN p_cost INT,
    IN p_currency VARCHAR(3),
    IN p_booking_reference VARCHAR(50)
)
BEGIN
    DECLARE v_accommodation_id VARCHAR(20);
    SET v_accommodation_id = generate_id('ACC');

    INSERT INTO itinerary_accommodation (
        accommodation_id, itinerary_id, accommodation_name, address,
        check_in_date, check_out_date, room_type, cost, currency, booking_reference
    ) VALUES (
        v_accommodation_id, p_itinerary_id, p_accommodation_name, p_address,
        p_check_in_date, p_check_out_date, p_room_type, p_cost, COALESCE(p_currency, 'GHS'), p_booking_reference
    );

    SELECT v_accommodation_id AS accommodation_id;
END //

-- Link a destination to an itinerary day
DROP PROCEDURE IF EXISTS add_destination_to_itinerary_day //

CREATE PROCEDURE add_destination_to_itinerary_day(
    IN p_itinerary_day_id VARCHAR(20),
    IN p_destination_id VARCHAR(20),
    IN p_cost VARCHAR(50),
    IN p_currency VARCHAR(3),
    IN p_activities TEXT,
    IN p_booking_url VARCHAR(500)
)
BEGIN
    INSERT INTO itinerary_day_destinations (itinerary_day_id, destination_id, cost, currency, activities, booking_url)
    VALUES (p_itinerary_day_id, p_destination_id, p_cost, COALESCE(p_currency, 'GHS'), p_activities, p_booking_url)
    ON DUPLICATE KEY UPDATE cost = p_cost, currency = COALESCE(p_currency, 'GHS'), activities = p_activities, booking_url = p_booking_url;
END //

-- Get full itinerary details with days, flights, accommodation, and customers
DROP PROCEDURE IF EXISTS get_itinerary_details //

CREATE PROCEDURE get_itinerary_details(IN p_itinerary_id VARCHAR(20))
BEGIN
    -- Itinerary header with trip info
    SELECT i.*, t.trip_name, t.trip_id
    FROM itinerary i
    JOIN trips t ON i.trip_id = t.trip_id
    WHERE i.itinerary_id = p_itinerary_id;

    -- Itinerary days
    SELECT * FROM itinerary_days WHERE itinerary_id = p_itinerary_id ORDER BY day_number;

    -- Flights
    SELECT * FROM itinerary_flights WHERE itinerary_id = p_itinerary_id ORDER BY departure_datetime;

    -- Accommodation
    SELECT * FROM itinerary_accommodation WHERE itinerary_id = p_itinerary_id ORDER BY check_in_date;

    -- Customers on this itinerary (through trip)
    SELECT c.*, tc.role, tc.added_at
    FROM customers c
    JOIN trip_customers tc ON c.customer_id = tc.customer_id
    JOIN trips t ON tc.trip_id = t.trip_id
    JOIN itinerary i ON i.trip_id = t.trip_id
    WHERE i.itinerary_id = p_itinerary_id;
END //

-- Link a customer to a trip
DROP PROCEDURE IF EXISTS add_customer_to_trip //

CREATE PROCEDURE add_customer_to_trip(
    IN p_trip_id VARCHAR(20),
    IN p_customer_id VARCHAR(20),
    IN p_role ENUM('primary', 'companion')
)
BEGIN
    INSERT INTO trip_customers (trip_id, customer_id, role)
    VALUES (p_trip_id, p_customer_id, p_role)
    ON DUPLICATE KEY UPDATE role = p_role;
END //

-- =============================================
-- CALL PROCEDURES
-- =============================================

-- Schedule or log a call for a trip
DROP PROCEDURE IF EXISTS schedule_call //

CREATE PROCEDURE schedule_call(
    IN p_trip_id VARCHAR(20),
    IN p_organized_by VARCHAR(20),
    IN p_title VARCHAR(200),
    IN p_started_at DATETIME,
    IN p_meeting_link VARCHAR(500)
)
BEGIN
    DECLARE v_call_id VARCHAR(20);
    SET v_call_id = generate_id('CAL');

    INSERT INTO calls (call_id, trip_id, organized_by, title, started_at, meeting_link)
    VALUES (v_call_id, p_trip_id, p_organized_by, p_title, p_started_at, p_meeting_link);

    SELECT v_call_id AS call_id;
END //

-- End a call and record duration
DROP PROCEDURE IF EXISTS end_call //

CREATE PROCEDURE end_call(IN p_call_id VARCHAR(20))
BEGIN
    UPDATE calls
    SET ended_at = NOW()
    WHERE call_id = p_call_id AND ended_at IS NULL;
END //

-- Save notes for a call
DROP PROCEDURE IF EXISTS save_call_notes //

CREATE PROCEDURE save_call_notes(
    IN p_call_id VARCHAR(20),
    IN p_notes TEXT,
    IN p_transcript LONGTEXT
)
BEGIN
    UPDATE calls
    SET notes = COALESCE(p_notes, notes),
        transcript = COALESCE(p_transcript, transcript)
    WHERE call_id = p_call_id;
END //

-- Add an action item from a call
DROP PROCEDURE IF EXISTS add_action_item //

CREATE PROCEDURE add_action_item(
    IN p_call_id VARCHAR(20),
    IN p_description TEXT
)
BEGIN
    DECLARE v_action_item_id VARCHAR(20);
    SET v_action_item_id = generate_id('ACI');

    INSERT INTO call_action_items (action_item_id, call_id, description)
    VALUES (v_action_item_id, p_call_id, p_description);

    SELECT v_action_item_id AS action_item_id;
END //

-- Update action item status
DROP PROCEDURE IF EXISTS update_action_item_status //

CREATE PROCEDURE update_action_item_status(
    IN p_action_item_id VARCHAR(20),
    IN p_status ENUM('pending', 'checked', 'archived')
)
BEGIN
    UPDATE call_action_items SET status = p_status
    WHERE action_item_id = p_action_item_id;
END //

-- Get all action items for a call
DROP PROCEDURE IF EXISTS get_call_action_items //

CREATE PROCEDURE get_call_action_items(IN p_call_id VARCHAR(20))
BEGIN
    SELECT * FROM call_action_items
    WHERE call_id = p_call_id
    ORDER BY FIELD(status, 'pending', 'checked', 'archived'), created_at;
END //

-- Get all calls for a trip
DROP PROCEDURE IF EXISTS get_trip_calls //

CREATE PROCEDURE get_trip_calls(IN p_trip_id VARCHAR(20))
BEGIN
    SELECT c.*, u.display_name AS organized_by_name
    FROM calls c
    LEFT JOIN users u ON c.organized_by = u.user_id
    WHERE c.trip_id = p_trip_id
    ORDER BY c.started_at DESC;
END //

-- =============================================
-- ADMIN & SUBSCRIPTION PROCEDURES
-- =============================================

-- Promote a user to system admin
DROP PROCEDURE IF EXISTS create_admin //

CREATE PROCEDURE create_admin(
    IN p_user_id VARCHAR(20),
    IN p_role ENUM('super_admin', 'support', 'finance')
)
BEGIN
    DECLARE v_admin_id VARCHAR(20);
    SET v_admin_id = generate_id('ADM');

    INSERT INTO admins (admin_id, user_id, role)
    VALUES (v_admin_id, p_user_id, p_role)
    ON DUPLICATE KEY UPDATE role = p_role;

    SELECT v_admin_id AS admin_id;
END //

-- Admin: view all companies with details
DROP PROCEDURE IF EXISTS admin_get_companies //

CREATE PROCEDURE admin_get_companies()
BEGIN
    SELECT
        c.*,
        st.name AS subscription_tier,
        cs.status AS subscription_status,
        cs.end_date AS subscription_end,
        (SELECT COUNT(*) FROM user_company uc WHERE uc.company_id = c.company_id) AS employee_count,
        (SELECT COUNT(*) FROM trips t WHERE t.company_id = c.company_id) AS trip_count
    FROM companies c
    LEFT JOIN company_subscriptions cs ON c.company_id = cs.company_id AND cs.status = 'active'
    LEFT JOIN subscription_tiers st ON cs.tier_id = st.tier_id
    ORDER BY c.created_at DESC;
END //

-- Admin: view all transactions grouped by type
DROP PROCEDURE IF EXISTS admin_get_transactions //

CREATE PROCEDURE admin_get_transactions(
    IN p_status ENUM('pending', 'completed', 'failed', 'refunded')
)
BEGIN
    SELECT
        t.*,
        c.company_name,
        'subscription' AS type,
        st.name AS tier_name,
        u.display_name AS initiated_by_name
    FROM transactions t
    JOIN subscription_payments sp ON t.transaction_id = sp.transaction_id
    JOIN companies c ON sp.company_id = c.company_id
    LEFT JOIN company_subscriptions cs ON sp.subscription_id = cs.subscription_id
    LEFT JOIN subscription_tiers st ON cs.tier_id = st.tier_id
    LEFT JOIN users u ON sp.initiated_by = u.user_id
    WHERE (p_status IS NULL OR t.status = p_status)

    UNION ALL

    SELECT
        t.*,
        c.company_name,
        'trip' AS type,
        tr.trip_name AS tier_name,
        NULL AS initiated_by_name
    FROM transactions t
    JOIN trip_payments tp ON t.transaction_id = tp.transaction_id
    JOIN trips tr ON tp.trip_id = tr.trip_id
    JOIN companies c ON tr.company_id = c.company_id
    WHERE (p_status IS NULL OR t.status = p_status)

    ORDER BY created_at DESC;
END //

-- Assign a subscription tier to a company
DROP PROCEDURE IF EXISTS assign_subscription //

CREATE PROCEDURE assign_subscription(
    IN p_company_id VARCHAR(20),
    IN p_tier_id VARCHAR(20),
    IN p_status ENUM('active'),
    IN p_billing_interval ENUM('quarterly', 'yearly'),
    IN p_start_date DATE,
    IN p_end_date DATE,
    IN p_auto_renew BOOLEAN
)
BEGIN
    DECLARE v_subscription_id VARCHAR(20);
    SET v_subscription_id = generate_id('SUB');

    INSERT INTO company_subscriptions (subscription_id, company_id, tier_id, status, billing_interval, start_date, end_date, auto_renew)
    VALUES (v_subscription_id, p_company_id, p_tier_id, p_status, p_billing_interval, p_start_date, p_end_date, COALESCE(p_auto_renew, TRUE));

    SELECT v_subscription_id AS subscription_id;
END //

-- Record a subscription payment
DROP PROCEDURE IF EXISTS record_subscription_payment //

CREATE PROCEDURE record_subscription_payment(
    IN p_company_id VARCHAR(20),
    IN p_subscription_id VARCHAR(20),
    IN p_initiated_by VARCHAR(20),
    IN p_amount INT,
    IN p_currency VARCHAR(3),
    IN p_payment_method VARCHAR(50),
    IN p_transaction_reference VARCHAR(255),
    IN p_status ENUM('pending', 'completed', 'failed', 'refunded')
)
BEGIN
    DECLARE v_transaction_id VARCHAR(20);
    SET v_transaction_id = generate_id('TXN');

    INSERT INTO transactions (transaction_id, amount, currency, payment_method, transaction_reference, status, paid_at)
    VALUES (v_transaction_id, p_amount, COALESCE(p_currency, 'GHS'), p_payment_method, p_transaction_reference, p_status, IF(p_status = 'completed', NOW(), NULL));

    INSERT INTO subscription_payments (transaction_id, subscription_id, company_id, initiated_by)
    VALUES (v_transaction_id, p_subscription_id, p_company_id, p_initiated_by);

    SELECT v_transaction_id AS transaction_id;
END //

-- Record a trip payment from a customer
DROP PROCEDURE IF EXISTS record_trip_payment //

CREATE PROCEDURE record_trip_payment(
    IN p_trip_id VARCHAR(20),
    IN p_amount INT,
    IN p_currency VARCHAR(3),
    IN p_payment_method VARCHAR(50),
    IN p_transaction_reference VARCHAR(255),
    IN p_notes TEXT,
    IN p_status ENUM('pending', 'completed', 'failed', 'refunded')
)
BEGIN
    DECLARE v_transaction_id VARCHAR(20);
    SET v_transaction_id = generate_id('TXN');

    INSERT INTO transactions (transaction_id, amount, currency, payment_method, transaction_reference, status, paid_at)
    VALUES (v_transaction_id, p_amount, COALESCE(p_currency, 'GHS'), p_payment_method, p_transaction_reference, p_status, IF(p_status = 'completed', NOW(), NULL));

    INSERT INTO trip_payments (transaction_id, trip_id, notes)
    VALUES (v_transaction_id, p_trip_id, p_notes);

    SELECT v_transaction_id AS transaction_id;
END //

-- Get subscription history for a company
DROP PROCEDURE IF EXISTS get_company_subscriptions //

CREATE PROCEDURE get_company_subscriptions(IN p_company_id VARCHAR(20))
BEGIN
    SELECT
        cs.*,
        st.name AS tier_name,
        st.price_quarterly,
        st.features
    FROM company_subscriptions cs
    JOIN subscription_tiers st ON cs.tier_id = st.tier_id
    WHERE cs.company_id = p_company_id
    ORDER BY cs.created_at DESC;
END //

-- Get available subscription tiers
DROP PROCEDURE IF EXISTS get_subscription_tiers //

CREATE PROCEDURE get_subscription_tiers()
BEGIN
    SELECT * FROM subscription_tiers WHERE is_active = TRUE ORDER BY price_quarterly;
END //

DELIMITER ;
