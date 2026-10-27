
CREATE DATABASE IF NOT EXISTS showcase_event_db
CHARACTER SET utf8mb4
COLLATE utf8mb4_unicode_ci;

USE showcase_event_db;

-- Disable foreign key checks while recreating tables
SET FOREIGN_KEY_CHECKS = 0;

DROP TABLE IF EXISTS bookings;
DROP TABLE IF EXISTS clients;
DROP TABLE IF EXISTS events;

SET FOREIGN_KEY_CHECKS = 1;


-- ==========================================
-- 1. EVENTS TABLE
-- ==========================================

CREATE TABLE events (
    event_id INT AUTO_INCREMENT PRIMARY KEY,
    event_name VARCHAR(150) NOT NULL,
    event_date DATE NOT NULL,
    venue VARCHAR(150) NOT NULL,
    capacity INT NOT NULL DEFAULT 100,
    description TEXT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;


-- ==========================================
-- 2. CLIENTS TABLE
-- ==========================================

CREATE TABLE clients (
    client_id INT AUTO_INCREMENT PRIMARY KEY,
    client_name VARCHAR(100) NOT NULL,
    email VARCHAR(150) NOT NULL UNIQUE,
    phone VARCHAR(30) DEFAULT NULL,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB;


-- ==========================================
-- 3. BOOKINGS TABLE
-- ==========================================

CREATE TABLE bookings (
    booking_id INT AUTO_INCREMENT PRIMARY KEY,
    client_id INT NOT NULL,
    event_id INT NOT NULL,
    guests INT NOT NULL DEFAULT 1,
    booking_date TIMESTAMP DEFAULT CURRENT_TIMESTAMP,

    CONSTRAINT fk_booking_client
        FOREIGN KEY (client_id)
        REFERENCES clients(client_id)
        ON DELETE CASCADE,

    CONSTRAINT fk_booking_event
        FOREIGN KEY (event_id)
        REFERENCES events(event_id)
        ON DELETE CASCADE,

    CONSTRAINT unique_client_event
        UNIQUE (client_id, event_id)
) ENGINE=InnoDB;


-- ==========================================
-- 4. SAMPLE EVENTS
-- All dates are generated relative to today
-- ==========================================

INSERT INTO events
(event_name, event_date, venue, capacity, description)
VALUES
(
    'Creative Tech Expo',
    DATE_ADD(CURDATE(), INTERVAL 7 DAY),
    'Crystal Convention Hall',
    200,
    'A fictional showcase of creative technology and digital innovation.'
),
(
    'Future Leaders Summit',
    DATE_ADD(CURDATE(), INTERVAL 14 DAY),
    'Aurora Conference Centre',
    150,
    'A demonstration event focused on leadership and collaboration.'
),
(
    'Digital Art Festival',
    DATE_ADD(CURDATE(), INTERVAL 21 DAY),
    'Blue Horizon Art Space',
    120,
    'A fictional exhibition celebrating digital art and design.'
),
(
    'Startup Connect',
    DATE_ADD(CURDATE(), INTERVAL 30 DAY),
    'Innovation Pavilion',
    250,
    'A mock networking event for fictional startup founders.'
),
(
    'Community Music Night',
    DATE_SUB(CURDATE(), INTERVAL 5 DAY),
    'Sunset Garden Arena',
    180,
    'A sample past event for demonstrating the management dashboard.'
),
(
    'Photography Showcase',
    DATE_ADD(CURDATE(), INTERVAL 40 DAY),
    'Silverline Exhibition Centre',
    100,
    'A fictional photography and visual arts showcase.'
);


-- ==========================================
-- 5. SAMPLE CLIENTS
-- Fictional demonstration identities only
-- ==========================================

INSERT INTO clients
(client_name, email, phone)
VALUES
(
    'Alex Morgan',
    'alex.morgan@example.test',
    '000-100-2001'
),
(
    'Jamie Taylor',
    'jamie.taylor@example.test',
    '000-100-2002'
),
(
    'Jordan Lee',
    'jordan.lee@example.test',
    '000-100-2003'
),
(
    'Casey Parker',
    'casey.parker@example.test',
    '000-100-2004'
),
(
    'Riley Bennett',
    'riley.bennett@example.test',
    '000-100-2005'
);


-- ==========================================
-- 6. SAMPLE BOOKINGS
-- ==========================================

INSERT INTO bookings
(client_id, event_id, guests)
VALUES
(1, 1, 3),
(2, 1, 5),
(3, 2, 2),
(4, 3, 4),
(1, 4, 2),
(5, 6, 3);


-- ==========================================
-- 7. VERIFY THE IMPORT
-- ==========================================

SELECT * FROM events;
SELECT * FROM clients;
SELECT * FROM bookings;

SELECT
    e.event_name,
    e.event_date,
    e.capacity,
    COALESCE(SUM(b.guests), 0) AS booked_guests
FROM events e
LEFT JOIN bookings b ON e.event_id = b.event_id
GROUP BY
    e.event_id,
    e.event_name,
    e.event_date,
    e.capacity
ORDER BY e.event_date;
