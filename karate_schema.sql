-- ============================================================
-- Shotokan Karate Portal — Complete Database Schema
-- Run this ONCE on a fresh database in phpMyAdmin > SQL tab
-- ============================================================

SET FOREIGN_KEY_CHECKS = 0;
SET time_zone = '-06:00';  -- Mountain Time (America/Denver)

-- ------------------------------------------------------------
-- USERS  (login accounts)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS users (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(50)  NOT NULL UNIQUE,
    password_hash VARCHAR(255) NOT NULL,
    role          ENUM('student','instructor','admin','parent') NOT NULL DEFAULT 'student',
    email         VARCHAR(100) NOT NULL,
    first_name    VARCHAR(50)  DEFAULT NULL,
    last_name     VARCHAR(50)  DEFAULT NULL,
    active        TINYINT(1)   NOT NULL DEFAULT 1,
    last_login    DATETIME,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- STUDENTS  (profile / contact info — one row per user)
-- student_type: 'guest' until registration fee paid, then 'student'
-- active_override: NULL = follow 3-month attendance rule
--                  1    = force active (admin override)
--                  0    = force inactive (admin override)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS students (
    id                      INT AUTO_INCREMENT PRIMARY KEY,
    user_id                 INT DEFAULT NULL,
    first_name              VARCHAR(50)  NOT NULL,
    last_name               VARCHAR(50)  NOT NULL,
    date_of_birth           DATE,
    phone                   VARCHAR(20),
    email                   VARCHAR(100) NOT NULL,
    emergency_contact_name  VARCHAR(100),
    emergency_contact_phone VARCHAR(20),
    street_address          VARCHAR(300) DEFAULT NULL,
    city_state_zip          VARCHAR(200) DEFAULT NULL,
    registration_date       DATE NOT NULL,
    student_type            ENUM('guest','student','parent','instructor','admin') NOT NULL DEFAULT 'guest',
    waiver_signed           TINYINT(1) NOT NULL DEFAULT 0,
    waiver_date             DATE,
    injury_waiver           TINYINT(1) NOT NULL DEFAULT 0,
    injury_waiver_date      DATE,
    notes                   TEXT,
    medical_note            TEXT,
    active                  TINYINT(1) NOT NULL DEFAULT 1,
    active_override         TINYINT(1) DEFAULT NULL,
    FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- INJURY WAIVER SUBMISSIONS  (digital waiver records)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS injury_waiver_submissions (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    student_id             INT          NOT NULL,
    print_name             VARCHAR(200) NOT NULL,
    signature              VARCHAR(200) NOT NULL,
    signed_date            DATE         NOT NULL,
    guardian_signature     VARCHAR(200) DEFAULT NULL,
    guardian_signed_date   DATE         DEFAULT NULL,
    date_of_birth          DATE,
    cell_phone             VARCHAR(50),
    home_phone             VARCHAR(50),
    email                  VARCHAR(200),
    street_address         VARCHAR(300),
    city_state_zip         VARCHAR(200),
    mailing_address        VARCHAR(300),
    mailing_city_state_zip VARCHAR(200),
    ip_address             VARCHAR(45),
    submitted_at           DATETIME     DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- RANKS  (belt levels — pre-populated)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS ranks (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    name        VARCHAR(60)  NOT NULL,
    kyu_dan     VARCHAR(20)  NOT NULL,
    rank_order  INT          NOT NULL,
    test_fee    DECIMAL(8,2) NOT NULL DEFAULT 10.00
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

INSERT INTO ranks (name, kyu_dan, rank_order, test_fee) VALUES
    ('White Belt with Black Stripe', '10th Kyu', 1,  10.00),
    ('Yellow Belt with White Stripe','9th Kyu',  2,  10.00),
    ('Yellow Belt',                  '8th Kyu',  3,  10.00),
    ('Orange Belt',                  '7th Kyu',  4,  10.00),
    ('Purple Belt',                  '6th Kyu',  5,  10.00),
    ('Blue Belt',                    '5th Kyu',  6,  10.00),
    ('Green Belt',                   '4th Kyu',  7,  10.00),
    ('Brown Belt',                   '3rd Kyu',  8,  10.00),
    ('Brown Belt',                   '2nd Kyu',  9,  10.00),
    ('Brown Belt',                   '1st Kyu',  10, 10.00),
    ('Black Belt (Shodan)',          '1st Dan',  11, 10.00),
    ('Black Belt (Nidan)',           '2nd Dan',  12, 10.00),
    ('Black Belt (Sandan)',          '3rd Dan',  13, 10.00);

-- ------------------------------------------------------------
-- STUDENT RANKS  (rank history — current = highest rank_order)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS student_ranks (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    student_id    INT  NOT NULL,
    rank_id       INT  NOT NULL,
    achieved_date DATE NOT NULL,
    notes         TEXT,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (rank_id)    REFERENCES ranks(id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- CLASS SESSIONS  (one row per class date)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS class_sessions (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    session_date  DATE NOT NULL UNIQUE,
    class_type    ENUM('class','seminar','private') NOT NULL DEFAULT 'class',
    instructor_id INT,
    location      VARCHAR(100) DEFAULT 'Dojo Location',
    notes         TEXT,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (instructor_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- ATTENDANCE
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS attendance (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    student_id  INT NOT NULL,
    session_id  INT NOT NULL,
    present     TINYINT(1)   NOT NULL DEFAULT 1,
    notes       VARCHAR(255),
    recorded_by INT,
    UNIQUE KEY uq_attendance (student_id, session_id),
    FOREIGN KEY (student_id)  REFERENCES students(id)       ON DELETE CASCADE,
    FOREIGN KEY (session_id)  REFERENCES class_sessions(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id)          ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- BELT TESTS  (one row per student per test)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS belt_tests (
    id               INT AUTO_INCREMENT PRIMARY KEY,
    student_id       INT NOT NULL,
    test_date        DATE NOT NULL,
    rank_testing_for INT NOT NULL,
    result           ENUM('pending','pass','fail') NOT NULL DEFAULT 'pending',
    score            TINYINT UNSIGNED NULL,
    fee_paid         TINYINT(1) NOT NULL DEFAULT 0,
    belt_awarded     TINYINT(1) NOT NULL DEFAULT 0,
    notes            TEXT,
    created_by       INT,
    created_at       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id)       REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (rank_testing_for) REFERENCES ranks(id),
    FOREIGN KEY (created_by)       REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- PAYMENTS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payments (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    student_id     INT NOT NULL,
    amount         DECIMAL(8,2) NOT NULL,
    payment_type   ENUM('monthly_tuition','registration','belt_test','slc_training','seminar','other') NOT NULL,
    payment_method ENUM('paypal','venmo','cash','check','mail') NOT NULL,
    transaction_id VARCHAR(100),
    payment_date   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    month_covered  DATE,
    notes          VARCHAR(255),
    payer_name     VARCHAR(100),
    payer_note     VARCHAR(255),
    recorded_by    INT,
    FOREIGN KEY (student_id)  REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (recorded_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- PAYMENT WAIVERS  (admin can waive fees for a student)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS payment_waivers (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    student_id    INT NOT NULL,
    waiver_type   ENUM('monthly_tuition','registration','belt_test','slc_training','seminar','all') NOT NULL,
    reason        TEXT,
    granted_by    INT,
    granted_date  DATE NOT NULL,
    expires_date  DATE,
    active        TINYINT(1) NOT NULL DEFAULT 1,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (granted_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- STUDENT NOTES
-- Instructors can add (enforced at app level), admins can read/write
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS student_notes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    student_id  INT NOT NULL,
    content     TEXT NOT NULL,
    created_by  INT,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE,
    FOREIGN KEY (created_by) REFERENCES users(id)    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- GENERAL CLASS NOTES  (date-stamped log entries, admin only)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS general_notes (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    content     TEXT NOT NULL,
    created_by  INT,
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- EXPENSES  (rent, equipment, utilities, etc.)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS expenses (
    id            INT AUTO_INCREMENT PRIMARY KEY,
    expense_type  ENUM('rent','equipment','utilities','supplies','other') NOT NULL,
    amount        DECIMAL(8,2) NOT NULL,
    expense_date  DATE NOT NULL,
    description   VARCHAR(255),
    paid          TINYINT(1) NOT NULL DEFAULT 0,
    recorded_by   INT,
    created_at    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- LOGIN ATTEMPTS  (rate limiting — blocks after 5 failures in 15 min)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS login_attempts (
    id           INT AUTO_INCREMENT PRIMARY KEY,
    identifier   VARCHAR(100) NOT NULL,
    attempted_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_ident_time (identifier, attempted_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- AUDIT LOG
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS audit_log (
    id          INT AUTO_INCREMENT PRIMARY KEY,
    user_id     INT,
    username    VARCHAR(50),
    action      VARCHAR(80)  NOT NULL,
    target_type VARCHAR(50),
    target_id   INT,
    detail      TEXT,
    ip_address  VARCHAR(45),
    created_at  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_created (created_at),
    INDEX idx_user    (user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- PARENT STUDENTS  (links a parent user to their children's student records)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS parent_students (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    parent_user_id INT NOT NULL,
    student_id     INT NOT NULL,
    UNIQUE KEY uq_parent_student (parent_user_id, student_id),
    FOREIGN KEY (parent_user_id) REFERENCES users(id)    ON DELETE CASCADE,
    FOREIGN KEY (student_id)     REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- DONATIONS
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS donations (
    id             INT AUTO_INCREMENT PRIMARY KEY,
    amount         DECIMAL(8,2) NOT NULL,
    payment_method ENUM('paypal','cash','check','mail') NOT NULL,
    donor_name     VARCHAR(100),
    notes          VARCHAR(255),
    payment_date   DATE NOT NULL,
    recorded_by    INT,
    created_at     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    FOREIGN KEY (recorded_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- SUBSCRIPTIONS  (PayPal auto-pay)
-- ------------------------------------------------------------
CREATE TABLE IF NOT EXISTS subscriptions (
    id                     INT AUTO_INCREMENT PRIMARY KEY,
    student_id             INT NOT NULL,
    paypal_subscription_id VARCHAR(100) NOT NULL,
    status                 ENUM('pending','active','cancelled','suspended','expired') NOT NULL DEFAULT 'pending',
    created_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    FOREIGN KEY (student_id) REFERENCES students(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

-- ------------------------------------------------------------
-- SEED: default admin account
-- Username: admin   Password: ChangeMe123!
-- CHANGE THIS PASSWORD immediately after first login
-- ------------------------------------------------------------
INSERT INTO users (username, password_hash, role, email) VALUES (
    'admin',
    '$2y$12$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2.uheWG/igi',
    'admin',
    'admin@example.com'
);

SET FOREIGN_KEY_CHECKS = 1;
