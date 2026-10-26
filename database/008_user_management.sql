-- DENR FP-OMS — RICTU User Management and 34 designated staff records (source form dated Oct 21, 2026)
-- IMPORTANT: Run ONCE on an existing database after 006_user_profile_fields.sql.
-- Designations are PENDING SETUP CONTACT RECORDS ONLY. This script creates NO user login or password.
-- Office contact email may be shared by colleagues; login emails MUST be assigned individually.
-- Run: mysql -u root -p denr_fp_oms < database/008_user_management.sql

ALTER TABLE users
    ADD COLUMN contact_number VARCHAR(40) NULL AFTER office_level,
    ADD COLUMN office_station VARCHAR(160) NULL AFTER contact_number;

CREATE TABLE IF NOT EXISTS user_designations (
    id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    source_key VARCHAR(64) NOT NULL UNIQUE,
    full_name VARCHAR(180) NOT NULL,
    reference_email VARCHAR(190) NULL,
    contact_number VARCHAR(40) NULL,
    office_station VARCHAR(160) NOT NULL,
    suggested_role VARCHAR(60) NOT NULL,
    notes VARCHAR(255) NULL,
    linked_user_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    INDEX idx_designation_role (suggested_role),
    INDEX idx_designation_office (office_station),
    INDEX idx_designation_user (linked_user_id),
    CONSTRAINT fk_designation_linked_user FOREIGN KEY (linked_user_id) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- GIS Focal is a designation listed in the submitted FP-OMS User Role Assignment Form.
INSERT INTO roles (code, label, description)
VALUES ('gis_focal', 'GIS Focal', 'Prepare GIS maps and geographic information for FP processing')
ON DUPLICATE KEY UPDATE label=VALUES(label), description=VALUES(description);

-- Import the names exactly as designated. Shared emails below are reference contacts, not login credentials.
INSERT IGNORE INTO user_designations
    (source_key, full_name, reference_email, contact_number, office_station, suggested_role, notes)
VALUES
    ('a01-1', 'ANTHONIE FENY V. CATALAN', 'Venzonanthonie@gmail.com', '09478984921', 'DENR 13/ PMD', 'super_admin', 'Check for an existing administrator account before provisioning.'),
    ('c01-1', 'Nelson Caranzo', 'cenronasipit@denr.gov.ph', '0956-0845-321', 'Nasipit', 'cenro_twg', 'Shared office contact email; collect individual login email.'),
    ('c01-2', 'Micheal Moreno', 'cenronasipit@denr.gov.ph', '0956-0845-321', 'Nasipit', 'cenro_twg', 'Shared office contact email; collect individual login email.'),
    ('c02-1', 'Ruel Ladaga', 'cenrotubay@denr.gov.ph', '0948-829-6736', 'Tubay', 'cenro_twg', 'Shared office contact email; collect individual login email.'),
    ('c02-2', 'Hantel Nicolas', 'cenrotubay@denr.gov.ph', '0948-829-6736', 'Tubay', 'cenro_twg', 'Shared office contact email; collect individual login email.'),
    ('c03-1', 'Sara Parejo', 'cenrobayugan@denr.gov.ph', '085-830-5035', 'Bayugan', 'cenro_twg', 'Shared office contact email; collect individual login email.'),
    ('c03-2', 'Alilaliegh Putane', 'cenrobayugan@denr.gov.ph', '085-830-5035', 'Bayugan', 'cenro_twg', 'Shared office contact email; collect individual login email.'),
    ('c04-1', 'Roel Rosales', 'cenrobunawan@denr.gov.ph', '0951-6846-768', 'Bunawan', 'cenro_twg', 'Shared office contact email; collect individual login email.'),
    ('c04-2', 'Jemelito Morala', 'cenrobunawan@denr.gov.ph', '0951-6846-768', 'Bunawan', 'cenro_twg', 'Shared office contact email; collect individual login email.'),
    ('c05-1', 'Racelyn Argosino', 'cenroloreto@denr.gov.ph', '0930-2405-190', 'Loreto', 'cenro_twg', 'Shared office contact email; collect individual login email.'),
    ('c05-2', 'Juvy B. Espenor', 'cenroloreto@denr.gov.ph', '0930-2405-190', 'Loreto', 'cenro_twg', 'Shared office contact email; collect individual login email.'),
    ('c06-1', 'Vince Estopito', 'cenrotalacogon@denr.gov.ph', '0948-7398-129', 'Talacogon', 'cenro_twg', 'Shared office contact email; collect individual login email.'),
    ('c06-2', 'Jolito Pabellan', 'cenrotalacogon@denr.gov.ph', '0948-7398-129', 'Talacogon', 'cenro_twg', 'Shared office contact email; collect individual login email.'),
    ('c07-1', 'Romel Arbolonio', 'cenrobislig@denr.gov.ph', '0968-331-4001', 'Bislig', 'cenro_twg', 'Shared office contact email; collect individual login email.'),
    ('c07-2', 'Jay Villegas', 'cenrobislig@denr.gov.ph', '0968-331-4001', 'Bislig', 'cenro_twg', 'Shared office contact email; collect individual login email.'),
    ('c08-1', 'Joseph Langanlangan', 'cenrolianga@denr.gov.ph', '0907-169-7840', 'Lianga', 'cenro_twg', 'Shared office contact email; collect individual login email.'),
    ('c08-2', 'Maritess Orcullo', 'cenrolianga@denr.gov.ph', '0907-169-7840', 'Lianga', 'cenro_twg', 'Shared office contact email; collect individual login email.'),
    ('c09-1', 'Leonila Rosales', 'cenrocantilan@denr.gov.ph', '0912-463-4909', 'Cantilan', 'cenro_twg', 'Shared office contact email; collect individual login email.'),
    ('c09-2', 'Ma. Theresa', 'cenrocantilan@denr.gov.ph', '0912-463-4909', 'Cantilan', 'cenro_twg', 'Name appears incomplete in source form; verify identity before account creation.'),
    ('c10-1', 'Larry Curias', 'penrosdnrps@gmail.com', '0946-2822-765', 'Surigao del Norte (IPENRO)', 'penro_user', 'Shared office contact email; collect individual login email.'),
    ('c10-2', 'Omar Tupal', 'penrosdnrps@gmail.com', '0946-2822-765', 'Surigao del Norte (IPENRO)', 'penro_user', 'Shared office contact email; collect individual login email.'),
    ('c11-1', 'Nathaniel Racho', 'penrodinagat@denr.gov.ph', '0950-9579-453', 'Dinagat Islands (IPENRO)', 'penro_user', 'Shared office contact email; collect individual login email.'),
    ('c11-2', 'Crisley J. Sapid', 'penrodinagat@denr.gov.ph', '0950-9579-453', 'Dinagat Islands (IPENRO)', 'penro_user', 'Shared office contact email; collect individual login email.'),
    ('c12-1', 'Ruel Efren', 'cenrotubod@denr.gov.ph', '0910-0919-474', 'Tubod', 'cenro_twg', 'Shared office contact email; collect individual login email.'),
    ('c12-2', 'Jerwin Resola', 'cenrotubod@denr.gov.ph', '0910-0919-474', 'Tubod', 'cenro_twg', 'Shared office contact email; collect individual login email.'),
    ('d01-1', 'Rosemarie P. Salvador', 'Lasangutilizationlpdd@gmail.com', '09079416644', 'LPDD/FUS', 'lpdd_fus', 'Shared office contact email; collect individual login email.'),
    ('d02-1', 'Archim C. Ocariz', 'Lasangutilizationlpdd@gmail.com', NULL, 'LPDD/FUS', 'lpdd_fus', 'Shared office contact email; collect individual login email.'),
    ('d03-1', 'Alexander Paler', 'Lasangutilizationlpdd@gmail.com', NULL, 'LPDD/FUS', 'lpdd_fus', 'Shared office contact email; collect individual login email.'),
    ('d04-1', 'Mila J. Garcia', 'lpddr13@denr.gov.ph', NULL, 'LPDD/RO', 'lpdd_chief', NULL),
    ('d05-1', 'Julie Montalban', 'records.r13@denr.gov.ph', NULL, 'Records Section/Admin RO', 'ro_receiving', NULL),
    ('ard01-1', 'Atty. Redemtor A. Oja', 'Ordts2021@gmail.com', NULL, 'Office of the ARD TS', 'ard_ts', NULL),
    ('rrdc01-1', 'Leonito Ramos', 'cbfmcaragaofficial@gmail.com', NULL, 'CDD/RO', 'rrdc_member', NULL),
    ('rrdc02-1', 'Kristoffer B. Uy', 'kristofferbuy23@gmail.com', NULL, 'GIS Focal', 'gis_focal', NULL),
    ('red01-1', 'Ma. Theresa J. Allen', 'r13@denr.gov.ph', NULL, 'ORED', 'red', NULL);
