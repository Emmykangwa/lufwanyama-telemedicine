-- =====================================================================
-- database.sql  --  Lufwanyama District Telemedicine System
-- MySQL schema (MySQL 8.0 or later; 5.7 also works but ignores CHECK)
--
-- Design: the doctor works INSIDE the health centre. There is no separate
-- referral hospital. An appointment names the health centre that will see
-- the patient (facility_id) and, optionally, a specific doctor at that
-- centre (doctor_id); NULL means "any doctor at the centre".
--
-- Import with phpMyAdmin (Import tab) or:
--   mysql -u root -p < database.sql
-- =====================================================================

CREATE DATABASE IF NOT EXISTS telemedicine
  CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
USE telemedicine;

-- ---------------------------------------------------------------------
-- 1. FACILITIES: the health centres (every facility is a health centre)
-- ---------------------------------------------------------------------
CREATE TABLE facilities (
  facility_id INT AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(120) NOT NULL,
  location    VARCHAR(120) NULL,
  is_active   TINYINT(1)   NOT NULL DEFAULT 1,
  created_at  TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY facilities_name_uniq (name)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 2. USERS: everyone who can log in. A facility employs many users
--    (health workers and doctors). Patients and admins have facility_id NULL.
--    Passwords are never stored, only an Argon2id/bcrypt hash.
-- ---------------------------------------------------------------------
CREATE TABLE users (
  user_id       INT AUTO_INCREMENT PRIMARY KEY,
  facility_id   INT NULL,
  full_name     VARCHAR(120) NOT NULL,
  phone         VARCHAR(20)  NOT NULL,
  email         VARCHAR(120) NULL,
  password_hash VARCHAR(255) NOT NULL,
  role          ENUM('patient','health_worker','doctor','admin') NOT NULL,
  is_active     TINYINT(1)   NOT NULL DEFAULT 1,
  last_login_at TIMESTAMP    NULL,
  created_at    TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY users_phone_uniq (phone),
  UNIQUE KEY users_email_uniq (email),
  KEY users_role_idx (role),
  KEY users_facility_idx (facility_id),
  CONSTRAINT users_facility_fk FOREIGN KEY (facility_id) REFERENCES facilities(facility_id),
  -- email, when given, must be stored lower-case. The COLLATE is required:
  -- the column's utf8mb4_unicode_ci collation compares case-insensitively, so a
  -- plain `email = LOWER(email)` is always true and would enforce nothing.
  CONSTRAINT users_email_lower CHECK (email IS NULL OR email COLLATE utf8mb4_bin = LOWER(email)),
  -- clinical staff (health workers AND doctors) must belong to a health centre
  CONSTRAINT users_staff_facility CHECK (
    role NOT IN ('health_worker','doctor') OR facility_id IS NOT NULL
  )
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 3. PATIENTS: a patient may or may not have a login (user_id can be NULL,
--    because a health worker can register and book for a resident who has
--    no phone of their own).
-- ---------------------------------------------------------------------
CREATE TABLE patients (
  patient_id     INT AUTO_INCREMENT PRIMARY KEY,
  user_id        INT NULL,
  patient_number VARCHAR(20)  NOT NULL,
  full_name      VARCHAR(120) NOT NULL,
  dob            DATE NULL,
  sex            ENUM('F','M') NULL,
  phone          VARCHAR(20)  NULL,
  village        VARCHAR(100) NULL,
  facility_id    INT NULL,
  created_at     TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY patients_number_uniq (patient_number),
  UNIQUE KEY patients_user_uniq (user_id),
  KEY patients_facility_idx (facility_id),
  KEY patients_name_idx (full_name),
  CONSTRAINT patients_user_fk     FOREIGN KEY (user_id)     REFERENCES users(user_id)          ON DELETE SET NULL,
  CONSTRAINT patients_facility_fk FOREIGN KEY (facility_id) REFERENCES facilities(facility_id) ON DELETE SET NULL
  -- (date-of-birth-not-in-future is enforced in PHP: MySQL does not allow
  --  non-deterministic functions such as CURRENT_DATE in a CHECK constraint.)
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 4. APPOINTMENTS: one patient has many appointments.
--
--    facility_id = the health centre that will see the patient.
--    doctor_id   = the chosen doctor at that centre (NULL = any doctor).
--    booked_by   = the user who created it (the health worker, or the
--                  patient when booking for themselves).
-- ---------------------------------------------------------------------
CREATE TABLE appointments (
  appointment_id   INT AUTO_INCREMENT PRIMARY KEY,
  patient_id       INT NOT NULL,
  booked_by        INT NOT NULL,
  facility_id      INT NOT NULL,
  doctor_id        INT NULL,
  department       VARCHAR(60)  NOT NULL,
  appointment_date DATE         NOT NULL,
  reason           VARCHAR(255) NOT NULL,
  status ENUM('pending','confirmed','rescheduled','declined','completed')
         NOT NULL DEFAULT 'pending',
  created_at       TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY appointments_status_idx (status),
  KEY appointments_date_idx (appointment_date),
  KEY appointments_patient_idx (patient_id),
  KEY appointments_facility_idx (facility_id),
  KEY appointments_doctor_idx (doctor_id),
  CONSTRAINT appointments_patient_fk  FOREIGN KEY (patient_id)  REFERENCES patients(patient_id)    ON DELETE CASCADE,
  CONSTRAINT appointments_bookedby_fk FOREIGN KEY (booked_by)   REFERENCES users(user_id),
  CONSTRAINT appointments_facility_fk FOREIGN KEY (facility_id) REFERENCES facilities(facility_id),
  CONSTRAINT appointments_doctor_fk   FOREIGN KEY (doctor_id)   REFERENCES users(user_id)          ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 5. CONSULTATIONS: an appointment gives rise to AT MOST ONE consultation.
--    It carries the preliminary clinical notes sent to the doctor (FR3) and
--    the doctor's reply (FR4). Every appointment in this system is created
--    together with its consultation row, so the notes and the reply always
--    have a home whether a health worker or the patient made the request.
-- ---------------------------------------------------------------------
CREATE TABLE consultations (
  consultation_id INT AUTO_INCREMENT PRIMARY KEY,
  appointment_id  INT NOT NULL,
  sent_by         INT NOT NULL,
  answered_by     INT NULL,
  clinical_notes  TEXT NULL,
  doctor_response VARCHAR(255) NULL,
  responded_at    DATETIME NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY consultations_appointment_uniq (appointment_id),
  KEY consultations_answeredby_idx (answered_by),
  CONSTRAINT consultations_appointment_fk FOREIGN KEY (appointment_id) REFERENCES appointments(appointment_id) ON DELETE CASCADE,
  CONSTRAINT consultations_sentby_fk      FOREIGN KEY (sent_by)        REFERENCES users(user_id),
  CONSTRAINT consultations_answeredby_fk  FOREIGN KEY (answered_by)    REFERENCES users(user_id)               ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- 6. AUDIT LOGS: every sensitive action writes exactly one row.
--    user_id is nullable so that failed logins (no known user yet) and
--    removed users still leave a readable trail.
-- ---------------------------------------------------------------------
CREATE TABLE audit_logs (
  log_id     BIGINT AUTO_INCREMENT PRIMARY KEY,
  user_id    INT NULL,
  action     VARCHAR(40) NOT NULL,
  entity     VARCHAR(40) NOT NULL,
  entity_id  INT NULL,
  details    VARCHAR(255) NULL,
  ip_address VARCHAR(45)  NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY audit_logs_created_idx (created_at),
  KEY audit_logs_user_idx (user_id),
  KEY audit_logs_action_idx (action),
  CONSTRAINT audit_logs_user_fk FOREIGN KEY (user_id) REFERENCES users(user_id) ON DELETE SET NULL
) ENGINE=InnoDB;

-- ---------------------------------------------------------------------
-- Starter health centres. Rename them to the real centres in your district
-- from Admin > Users > Facilities before going live.
-- ---------------------------------------------------------------------
INSERT INTO facilities (name, location) VALUES
  ('Rural Health Centre A', 'Lufwanyama District'),
  ('Rural Health Centre B', 'Lufwanyama District');
