-- ── Run this in phpMyAdmin or MySQL CLI ──────────────────────
-- Creates the database, users table, and students table for the SMS project

CREATE DATABASE IF NOT EXISTS sms_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE sms_db;

-- ── Users table (admin + student accounts) ───────────────────
CREATE TABLE IF NOT EXISTS users (
    id            INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    username      VARCHAR(60)   NOT NULL UNIQUE,
    email         VARCHAR(150)  NOT NULL UNIQUE,
    first_name    VARCHAR(100)  NOT NULL,
    last_name     VARCHAR(100)  NOT NULL,
    password_hash VARCHAR(255)  NOT NULL,
    role          ENUM('admin','student') NOT NULL DEFAULT 'student',
    created_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at    TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- No known administrator password is seeded by this schema.
-- For local template setup, use the guarded shared/setup.php helper with
-- explicit development environment flags and an uncommitted password.

-- ── Students table ────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS students (
    id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    first_name   VARCHAR(100)  NOT NULL,
    last_name    VARCHAR(100)  NOT NULL,
    birthday     DATE          NOT NULL,
    course       VARCHAR(150)  NOT NULL,
    year_level   VARCHAR(50)   NOT NULL,
    section      VARCHAR(50)   NOT NULL,
    phone        VARCHAR(20)   NOT NULL,
    status       ENUM('Active','Inactive') NOT NULL DEFAULT 'Active',
    created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Sample data
INSERT INTO students (first_name, last_name, birthday, course, year_level, section, phone, status) VALUES
('Juswa',    'Pudaders',   '2004-06-20', 'Bachelor of Science in Information Technology', '4th Year', '41018', '09999999999', 'Active'),
('Maria',    'Santos',     '2003-03-15', 'Bachelor of Science in Computer Science',       '3rd Year', '31011', '09111111111', 'Inactive'),
('Jose',     'Reyes',      '2002-09-10', 'Bachelor of Science in Information Technology', '4th Year', '41019', '09222222222', 'Active'),
('Ana',      'Cruz',       '2005-01-25', 'Bachelor of Science in Information Systems',    '2nd Year', '21005', '09333333333', 'Active'),
('Carlos',   'Garcia',     '2001-11-30', 'Bachelor of Science in Computer Science',       '4th Year', '41020', '09444444444', 'Inactive'),
('Liza',     'Dela Cruz',  '2003-07-14', 'Bachelor of Science in Information Technology', '3rd Year', '31012', '09555555501', 'Active'),
('Ramon',    'Villanueva', '2002-04-22', 'Bachelor of Science in Computer Science',       '4th Year', '41021', '09555555502', 'Active'),
('Patricia', 'Aquino',     '2004-11-05', 'Bachelor of Science in Information Systems',    '2nd Year', '21006', '09555555503', 'Inactive'),
('Mark',     'Bautista',   '2003-08-30', 'Bachelor of Science in Information Technology', '3rd Year', '31013', '09555555504', 'Active'),
('Jenny',    'Navarro',    '2005-02-18', 'Bachelor of Science in Computer Science',       '1st Year', '11001', '09555555505', 'Active'),
('Rico',     'Fernandez',  '2001-12-09', 'Bachelor of Science in Information Technology', '4th Year', '41022', '09555555506', 'Inactive'),
('Sheila',   'Ramos',      '2004-05-03', 'Bachelor of Science in Information Systems',    '2nd Year', '21007', '09555555507', 'Active'),
('Angelo',   'Torres',     '2002-10-27', 'Bachelor of Science in Computer Science',       '4th Year', '41023', '09555555508', 'Active'),
('Claire',   'Mendoza',    '2003-01-16', 'Bachelor of Science in Information Technology', '3rd Year', '31014', '09555555509', 'Inactive'),
('Danilo',   'Pascual',    '2000-09-08', 'Bachelor of Science in Computer Science',       '4th Year', '41024', '09555555510', 'Active'),
('Rowena',   'Espinosa',   '2005-06-21', 'Bachelor of Science in Information Systems',    '1st Year', '11002', '09555555511', 'Active'),
('Freddie',  'Castillo',   '2002-03-13', 'Bachelor of Science in Information Technology', '4th Year', '41025', '09555555512', 'Inactive'),
('Aileen',   'Morales',    '2004-09-29', 'Bachelor of Science in Computer Science',       '2nd Year', '21008', '09555555513', 'Active'),
('Ronnie',   'Aguilar',    '2001-07-04', 'Bachelor of Science in Information Technology', '4th Year', '41026', '09555555514', 'Active'),
('Mylene',   'Domingo',    '2003-12-11', 'Bachelor of Science in Information Systems',    '3rd Year', '31015', '09555555515', 'Inactive'),
('Bryan',    'Lacson',     '2004-04-07', 'Bachelor of Science in Computer Science',       '2nd Year', '21009', '09555555516', 'Active'),
('Rosalie',  'Ilagan',     '2002-08-19', 'Bachelor of Science in Information Technology', '4th Year', '41027', '09555555517', 'Active'),
('Eduardo',  'Pineda',     '2005-03-25', 'Bachelor of Science in Computer Science',       '1st Year', '11003', '09555555518', 'Inactive'),
('Vanessa',  'Ocampo',     '2003-10-02', 'Bachelor of Science in Information Systems',    '3rd Year', '31016', '09555555519', 'Active'),
('Kenneth',  'Bondoc',     '2001-05-17', 'Bachelor of Science in Information Technology', '4th Year', '41028', '09555555520', 'Active');
