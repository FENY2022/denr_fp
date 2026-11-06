-- phpMyAdmin SQL Dump
-- version 5.2.1
-- https://www.phpmyadmin.net/
--
-- Host: 127.0.0.1
-- Generation Time: Nov 06, 2026 at 02:44 PM
-- Server version: 10.4.32-MariaDB
-- PHP Version: 8.2.12

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";


/*!40101 SET @OLD_CHARACTER_SET_CLIENT=@@CHARACTER_SET_CLIENT */;
/*!40101 SET @OLD_CHARACTER_SET_RESULTS=@@CHARACTER_SET_RESULTS */;
/*!40101 SET @OLD_COLLATION_CONNECTION=@@COLLATION_CONNECTION */;
/*!40101 SET NAMES utf8mb4 */;

--
-- Database: `denr_fp_oms`
--

-- --------------------------------------------------------

--
-- Table structure for table `account_login_logs`
--

CREATE TABLE `account_login_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `username_snapshot` varchar(190) DEFAULT NULL,
  `event_type` varchar(40) NOT NULL,
  `ip_address` varchar(45) NOT NULL DEFAULT 'unknown',
  `user_agent` varchar(500) NOT NULL DEFAULT 'Unknown',
  `actor_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `reason` varchar(500) DEFAULT NULL,
  `created_at` timestamp NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `account_login_logs`
--

INSERT INTO `account_login_logs` (`id`, `user_id`, `username_snapshot`, `event_type`, `ip_address`, `user_agent`, `actor_user_id`, `reason`, `created_at`) VALUES
(1, 29, 'fpuser000029', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36 Edg/156.0.0.0', NULL, NULL, '2026-11-06 06:18:01'),
(2, 31, 'juliemontalban31', 'login_success', '::1', 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/156.0.0.0 Safari/537.36 Edg/156.0.0.0', NULL, NULL, '2026-11-06 06:30:14');

-- --------------------------------------------------------

--
-- Table structure for table `applicant_registrations`
--

CREATE TABLE `applicant_registrations` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `client_id` bigint(20) UNSIGNED NOT NULL,
  `company_name` varchar(220) NOT NULL,
  `organization_type` varchar(32) NOT NULL DEFAULT 'individual',
  `company_address` text NOT NULL,
  `representative_name` varchar(180) NOT NULL,
  `representative_position` varchar(150) DEFAULT NULL,
  `contact_number` varchar(40) NOT NULL,
  `revision` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_reviewed_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `applicant_registrations`
--

INSERT INTO `applicant_registrations` (`id`, `client_id`, `company_name`, `organization_type`, `company_address`, `representative_name`, `representative_position`, `contact_number`, `revision`, `submitted_at`, `last_reviewed_at`) VALUES
(29, 29, 'LNM LUMBER', 'individual', 'Testing', 'ANTHONIE FENY V. CATALAN', 'Forest Ranger', '09478984921', 1, '2026-10-29 23:30:33', '2026-10-30 06:03:09');

-- --------------------------------------------------------

--
-- Table structure for table `application_documents`
--

CREATE TABLE `application_documents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `document_code` varchar(70) NOT NULL,
  `original_name` varchar(220) NOT NULL,
  `stored_path` varchar(300) NOT NULL,
  `mime_type` varchar(80) NOT NULL,
  `sha256` char(64) NOT NULL,
  `uploaded_by` bigint(20) UNSIGNED DEFAULT NULL,
  `uploaded_by_client_id` bigint(20) UNSIGNED DEFAULT NULL,
  `version` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `audit_logs`
--

CREATE TABLE `audit_logs` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `actor_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `actor_client_id` bigint(20) UNSIGNED DEFAULT NULL,
  `action` varchar(100) NOT NULL,
  `target_type` varchar(70) NOT NULL,
  `target_id` bigint(20) UNSIGNED DEFAULT NULL,
  `metadata_json` longtext CHARACTER SET utf8mb4 COLLATE utf8mb4_bin DEFAULT NULL CHECK (json_valid(`metadata_json`)),
  `ip_address` varchar(45) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `audit_logs`
--

INSERT INTO `audit_logs` (`id`, `actor_user_id`, `actor_client_id`, `action`, `target_type`, `target_id`, `metadata_json`, `ip_address`, `created_at`) VALUES
(1, NULL, 29, 'auth.login', 'client', 29, NULL, '::1', '2026-11-06 13:56:58'),
(2, NULL, 29, 'auth.logout', 'client', 29, NULL, '::1', '2026-11-06 14:17:52'),
(3, NULL, 29, 'auth.login', 'client', 29, NULL, '::1', '2026-11-06 14:17:55'),
(4, NULL, 29, 'auth.logout', 'client', 29, NULL, '::1', '2026-11-06 14:17:56'),
(5, 29, NULL, 'auth.login', 'user', 29, NULL, '::1', '2026-11-06 14:18:00'),
(6, 29, NULL, 'messenger.delete', 'chat_thread', 1, NULL, '::1', '2026-11-06 14:22:25'),
(7, 31, NULL, 'auth.login', 'user', 31, NULL, '::1', '2026-11-06 14:30:14'),
(8, 31, NULL, 'messenger.delete', 'chat_thread', 1, NULL, '::1', '2026-11-06 14:30:22'),
(9, 29, NULL, 'messenger.delete', 'chat_thread', 1, NULL, '::1', '2026-11-06 14:30:58'),
(10, 31, NULL, 'messenger.delete', 'chat_thread', 1, NULL, '::1', '2026-11-06 14:31:07'),
(11, 31, NULL, 'messenger.delete', 'chat_thread', 2, NULL, '::1', '2026-11-06 14:31:12');

-- --------------------------------------------------------

--
-- Table structure for table `fpoms_deficiency_notices`
--

CREATE TABLE `fpoms_deficiency_notices` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `revision` int(10) UNSIGNED NOT NULL,
  `reviewer_user_id` bigint(20) UNSIGNED NOT NULL,
  `payload_json` longtext NOT NULL,
  `draft_path` varchar(300) NOT NULL,
  `draft_sha256` char(64) NOT NULL,
  `status` enum('pending_red','signed') NOT NULL DEFAULT 'pending_red',
  `artifact_id` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `approved_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `email_status` enum('not_sent','sent','failed') NOT NULL DEFAULT 'not_sent',
  `email_last_attempt_at` datetime DEFAULT NULL,
  `email_sent_at` datetime DEFAULT NULL
) ;

-- --------------------------------------------------------

--
-- Table structure for table `fpoms_notice_config`
--

CREATE TABLE `fpoms_notice_config` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `signatory_name` varchar(180) NOT NULL DEFAULT 'MA. THERESA J. ALLEN',
  `signatory_title` varchar(180) NOT NULL DEFAULT 'OIC, Regional Executive Director',
  `contact_emails` varchar(400) NOT NULL DEFAULT 'r13@denr.gov.ph; lasangutilizationlpdd@gmail.com',
  `footer_text` varchar(300) NOT NULL DEFAULT 'DENR-XIII, Ambago, Butuan City, Philippines | Hotline No. 0981-017-3551 | E-Mail: r13@denr.gov.ph',
  `template_name` varchar(240) DEFAULT NULL,
  `template_path` varchar(300) DEFAULT NULL,
  `signature_path` varchar(300) DEFAULT NULL,
  `signature_sha256` char(64) DEFAULT NULL,
  `updated_by` bigint(20) UNSIGNED DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `fpoms_notice_config`
--

INSERT INTO `fpoms_notice_config` (`id`, `signatory_name`, `signatory_title`, `contact_emails`, `footer_text`, `template_name`, `template_path`, `signature_path`, `signature_sha256`, `updated_by`, `updated_at`) VALUES
(1, 'MA. THERESA J. ALLEN', 'OIC, Regional Executive Director', 'r13@denr.gov.ph; lasangutilizationlpdd@gmail.com', 'DENR-XIII, Ambago, Butuan City, Philippines | Hotline No. 0981-017-3551 | E-Mail: r13@denr.gov.ph', NULL, NULL, 'fp_notices/config/e289043f24b9d5006f71953b02c7019453f44891.jpg', '3c59a506ab03dbfaee132640a466d6c9653f89e08f421ff57679d6042b5eabcc', 1, '2026-11-05 12:56:55');

-- --------------------------------------------------------

--
-- Table structure for table `fpoms_realtime_settings`
--

CREATE TABLE `fpoms_realtime_settings` (
  `id` tinyint(3) UNSIGNED NOT NULL,
  `enabled` tinyint(1) NOT NULL DEFAULT 0,
  `app_id` varchar(32) NOT NULL DEFAULT '',
  `app_key` varchar(128) NOT NULL DEFAULT '',
  `secret_ciphertext` text DEFAULT NULL,
  `cluster` varchar(32) NOT NULL DEFAULT 'ap1',
  `last_tested_at` datetime DEFAULT NULL,
  `last_test_ok` tinyint(1) DEFAULT NULL,
  `last_test_note` varchar(180) DEFAULT NULL,
  `updated_by` bigint(20) UNSIGNED DEFAULT NULL,
  `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `fpoms_realtime_settings`
--

INSERT INTO `fpoms_realtime_settings` (`id`, `enabled`, `app_id`, `app_key`, `secret_ciphertext`, `cluster`, `last_tested_at`, `last_test_ok`, `last_test_note`, `updated_by`, `updated_at`) VALUES
(1, 1, '2208663', '56a132864eec4a5608c1', 'RlBPTVNQMU21hYlsUDjrAqMGbO7oMb6p07mf9KWQhux/AY2/vF0PM0hy36+tvx6QpqaraL/RbQ==', 'ap1', '2026-11-03 09:00:53', 1, 'Pusher API accepted the test event. Confirm browser subscription separately.', 1, '2026-11-03 01:00:53');

-- --------------------------------------------------------

--
-- Table structure for table `fp_chat_files`
--

CREATE TABLE `fp_chat_files` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `uploaded_by_kind` enum('staff','applicant') NOT NULL,
  `uploaded_by_id` bigint(20) UNSIGNED NOT NULL,
  `original_name` varchar(255) NOT NULL,
  `stored_path` varchar(500) NOT NULL,
  `file_size` bigint(20) UNSIGNED NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `fp_chat_messages`
--

CREATE TABLE `fp_chat_messages` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `thread_id` bigint(20) UNSIGNED NOT NULL,
  `sender_kind` enum('staff','applicant') NOT NULL,
  `sender_id` bigint(20) UNSIGNED NOT NULL,
  `message` text DEFAULT NULL,
  `file_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `fp_chat_messages`
--

INSERT INTO `fp_chat_messages` (`id`, `thread_id`, `sender_kind`, `sender_id`, `message`, `file_id`, `created_at`) VALUES
(1, 1, 'staff', 31, 'hou', NULL, '2026-11-06 14:30:33'),
(2, 1, 'staff', 31, 'h', NULL, '2026-11-06 14:30:48');

-- --------------------------------------------------------

--
-- Table structure for table `fp_chat_threads`
--

CREATE TABLE `fp_chat_threads` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `thread_type` enum('direct','group') NOT NULL,
  `direct_key` varchar(100) DEFAULT NULL,
  `name` varchar(160) DEFAULT NULL,
  `created_by_kind` enum('staff','applicant') NOT NULL,
  `created_by_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `fp_chat_threads`
--

INSERT INTO `fp_chat_threads` (`id`, `thread_type`, `direct_key`, `name`, `created_by_kind`, `created_by_id`, `created_at`, `updated_at`) VALUES
(1, 'direct', 'staff:29|staff:31', NULL, 'staff', 29, '2026-11-06 14:21:23', '2026-11-06 14:30:48'),
(2, 'direct', 'staff:1|staff:31', NULL, 'staff', 31, '2026-11-06 14:30:25', '2026-11-06 14:30:25');

-- --------------------------------------------------------

--
-- Table structure for table `fp_chat_thread_members`
--

CREATE TABLE `fp_chat_thread_members` (
  `thread_id` bigint(20) UNSIGNED NOT NULL,
  `principal_kind` enum('staff','applicant') NOT NULL,
  `principal_id` bigint(20) UNSIGNED NOT NULL,
  `member_role` enum('admin','member') NOT NULL DEFAULT 'member',
  `last_read_message_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `hidden_before_message_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `archived_at` datetime DEFAULT NULL,
  `joined_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `fp_chat_thread_members`
--

INSERT INTO `fp_chat_thread_members` (`thread_id`, `principal_kind`, `principal_id`, `member_role`, `last_read_message_id`, `hidden_before_message_id`, `archived_at`, `joined_at`) VALUES
(1, 'staff', 29, 'member', 2, 2, '2026-11-06 14:30:58', '2026-11-06 14:21:23'),
(1, 'staff', 31, 'member', 2, 2, '2026-11-06 14:31:07', '2026-11-06 14:21:23'),
(2, 'staff', 1, 'member', 0, 0, NULL, '2026-11-06 14:30:25'),
(2, 'staff', 31, 'member', 0, 0, '2026-11-06 14:31:12', '2026-11-06 14:30:25');

-- --------------------------------------------------------

--
-- Table structure for table `login_attempts`
--

CREATE TABLE `login_attempts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `email_hash` char(64) NOT NULL,
  `ip_address` varchar(45) NOT NULL,
  `was_successful` tinyint(1) NOT NULL DEFAULT 0,
  `attempted_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `login_attempts`
--

INSERT INTO `login_attempts` (`id`, `email_hash`, `ip_address`, `was_successful`, `attempted_at`) VALUES
(1, '66dcb4526ab8c5efc9a16eb92213a54bf13741d9c58421fc5b134dba1812d9fd', '::1', 1, '2026-11-06 13:56:58'),
(2, '66dcb4526ab8c5efc9a16eb92213a54bf13741d9c58421fc5b134dba1812d9fd', '::1', 1, '2026-11-06 14:17:55'),
(3, '38b7e8067f3177b4662190f67da46903337e973f88bb55789781fa59da03c639', '::1', 1, '2026-11-06 14:18:00'),
(4, '059b3a2051805816b22b76b731a51da0a3dde814e47b7a3fb563796157cbf1a5', '::1', 1, '2026-11-06 14:30:14');

-- --------------------------------------------------------

--
-- Table structure for table `notifications`
--

CREATE TABLE `notifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `client_id` bigint(20) UNSIGNED DEFAULT NULL,
  `title` varchar(180) NOT NULL,
  `message` varchar(1000) NOT NULL,
  `icon` varchar(50) NOT NULL DEFAULT 'bell',
  `level` varchar(20) NOT NULL DEFAULT 'info',
  `route_page` varchar(80) DEFAULT NULL,
  `route_query_json` text DEFAULT NULL,
  `read_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `offices`
--

CREATE TABLE `offices` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `name` varchar(180) NOT NULL,
  `office_type` enum('REGIONAL','PENRO','CENRO') NOT NULL,
  `parent_office_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `password_reset_tokens`
--

CREATE TABLE `password_reset_tokens` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `client_id` bigint(20) UNSIGNED DEFAULT NULL,
  `token_hash` char(64) NOT NULL,
  `requested_ip` varchar(45) DEFAULT NULL,
  `expires_at` datetime NOT NULL,
  `used_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permit_applications`
--

CREATE TABLE `permit_applications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `client_id` bigint(20) UNSIGNED NOT NULL,
  `reference_number` varchar(35) NOT NULL,
  `project_name` varchar(220) NOT NULL,
  `project_location` text NOT NULL,
  `proposed_area_hectares` decimal(16,4) DEFAULT NULL,
  `current_step` tinyint(3) UNSIGNED NOT NULL DEFAULT 2,
  `status` enum('draft','submitted','under_review','for_correction','approved','released','denied','cancelled') NOT NULL DEFAULT 'draft',
  `submitted_at` datetime DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ;

-- --------------------------------------------------------

--
-- Table structure for table `permit_flow_artifacts`
--

CREATE TABLE `permit_flow_artifacts` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `step_number` tinyint(3) UNSIGNED NOT NULL,
  `application_revision` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `category` varchar(60) NOT NULL,
  `original_name` varchar(220) NOT NULL,
  `stored_path` varchar(400) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size` bigint(20) UNSIGNED NOT NULL,
  `sha256` char(64) NOT NULL,
  `uploaded_by` bigint(20) UNSIGNED NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permit_flow_cases`
--

CREATE TABLE `permit_flow_cases` (
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `current_step` tinyint(3) UNSIGNED NOT NULL DEFAULT 4,
  `field_validation_schedule` datetime DEFAULT NULL,
  `rrdc_schedule` datetime DEFAULT NULL,
  `notice_text` text DEFAULT NULL,
  `cenro_instructions` text DEFAULT NULL,
  `completed_at` datetime DEFAULT NULL,
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permit_flow_events`
--

CREATE TABLE `permit_flow_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `step_number` tinyint(3) UNSIGNED NOT NULL,
  `action` varchar(70) NOT NULL,
  `remarks` text DEFAULT NULL,
  `actor_id` bigint(20) UNSIGNED NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permit_payment_verifications`
--

CREATE TABLE `permit_payment_verifications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `status` enum('awaiting_payment','verified') NOT NULL DEFAULT 'awaiting_payment',
  `notified_at` datetime DEFAULT NULL,
  `receipt_number` varchar(90) DEFAULT NULL,
  `amount_paid` decimal(13,2) DEFAULT NULL,
  `payment_date` date DEFAULT NULL,
  `original_name` varchar(220) DEFAULT NULL,
  `stored_path` varchar(500) DEFAULT NULL,
  `mime_type` varchar(100) DEFAULT NULL,
  `file_size` bigint(20) UNSIGNED DEFAULT NULL,
  `sha256` char(64) DEFAULT NULL,
  `verified_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `verified_at` datetime DEFAULT NULL,
  `notes` varchar(2000) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permit_records`
--

CREATE TABLE `permit_records` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `permit_number` varchar(65) NOT NULL,
  `issued_at` date DEFAULT NULL,
  `expires_at` date DEFAULT NULL,
  `approved_by` bigint(20) UNSIGNED DEFAULT NULL,
  `released_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permit_records_archives`
--

CREATE TABLE `permit_records_archives` (
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `archived_at` datetime NOT NULL,
  `archived_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `archive_remarks` varchar(2000) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permit_step2_applications`
--

CREATE TABLE `permit_step2_applications` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `client_id` bigint(20) UNSIGNED NOT NULL,
  `project_title` varchar(180) NOT NULL,
  `project_type` varchar(20) NOT NULL DEFAULT 'unspecified',
  `project_type_other` varchar(160) DEFAULT NULL,
  `project_location` varchar(300) NOT NULL,
  `project_area_hectares` decimal(14,4) DEFAULT NULL,
  `project_latitude` decimal(10,7) DEFAULT NULL,
  `project_longitude` decimal(11,7) DEFAULT NULL,
  `purpose` text DEFAULT NULL,
  `status` enum('submitted','corrections_required','forwarded_to_ro','reverted','ro_received','forwarded_to_lpdd','step4_under_evaluation','step4_corrections_required','step4_resubmitted','step4_complete') NOT NULL DEFAULT 'submitted',
  `revision` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `flow_version` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `last_submitted_at` datetime NOT NULL DEFAULT current_timestamp(),
  `reviewed_at` datetime DEFAULT NULL,
  `received_at` datetime DEFAULT NULL,
  `received_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `forwarded_at` datetime DEFAULT NULL,
  `reviewer_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permit_step2_documents`
--

CREATE TABLE `permit_step2_documents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `client_id` bigint(20) UNSIGNED NOT NULL,
  `revision` int(10) UNSIGNED NOT NULL,
  `requirement_type` varchar(48) NOT NULL DEFAULT 'other',
  `original_name` varchar(220) NOT NULL,
  `stored_path` varchar(500) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size` bigint(20) UNSIGNED NOT NULL,
  `sha256` char(64) NOT NULL,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permit_step2_draft_documents`
--

CREATE TABLE `permit_step2_draft_documents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `client_id` bigint(20) UNSIGNED NOT NULL,
  `draft_hash` char(64) NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `requirement_type` varchar(48) NOT NULL DEFAULT 'other',
  `original_name` varchar(220) NOT NULL,
  `stored_path` varchar(300) NOT NULL,
  `mime_type` varchar(100) NOT NULL,
  `file_size` bigint(20) UNSIGNED NOT NULL,
  `sha256` char(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permit_step2_events`
--

CREATE TABLE `permit_step2_events` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `actor_client_id` bigint(20) UNSIGNED DEFAULT NULL,
  `actor_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `action` enum('submitted','returned_for_correction','resubmitted','forwarded_to_ro','documents_received','reverted','ro_documents_received','forwarded_to_lpdd','step4_review_started','step4_returned_for_compliance','step4_resubmitted','step4_complete','ro_archived','payment_verified_lpdd_fus') NOT NULL,
  `revision` int(10) UNSIGNED NOT NULL,
  `remarks` text DEFAULT NULL,
  `occurred_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permit_step2_form_drafts`
--

CREATE TABLE `permit_step2_form_drafts` (
  `client_id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL DEFAULT 0,
  `project_title` varchar(180) NOT NULL DEFAULT '',
  `project_type` varchar(20) NOT NULL DEFAULT '',
  `project_type_other` varchar(160) NOT NULL DEFAULT '',
  `project_location` varchar(300) NOT NULL DEFAULT '',
  `project_area_hectares` varchar(24) NOT NULL DEFAULT '',
  `project_latitude` varchar(32) NOT NULL DEFAULT '',
  `project_longitude` varchar(32) NOT NULL DEFAULT '',
  `purpose` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permit_step3_movements`
--

CREATE TABLE `permit_step3_movements` (
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `revision` int(10) UNSIGNED NOT NULL,
  `received_at` datetime NOT NULL,
  `received_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `receipt_remarks` text DEFAULT NULL,
  `forwarded_at` datetime DEFAULT NULL,
  `forwarded_by_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `forwarding_remarks` text DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permit_step4_requirement_reviews`
--

CREATE TABLE `permit_step4_requirement_reviews` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `review_id` bigint(20) UNSIGNED NOT NULL,
  `requirement_type` varchar(48) NOT NULL,
  `assessment` enum('complete','incomplete','insufficient','not_applicable') NOT NULL,
  `remarks` varchar(1500) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `permit_step4_reviews`
--

CREATE TABLE `permit_step4_reviews` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `revision` int(10) UNSIGNED NOT NULL,
  `reviewer_user_id` bigint(20) UNSIGNED DEFAULT NULL,
  `review_status` enum('under_evaluation','returned','complete') NOT NULL DEFAULT 'under_evaluation',
  `overall_remarks` text DEFAULT NULL,
  `applicant_instructions` text DEFAULT NULL,
  `field_validation_schedule` datetime DEFAULT NULL,
  `started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `decided_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `registration_documents`
--

CREATE TABLE `registration_documents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `registration_id` bigint(20) UNSIGNED NOT NULL,
  `document_type` enum('valid_id','supporting_document') NOT NULL,
  `id_type` varchar(80) DEFAULT NULL,
  `requirement_type` varchar(40) NOT NULL DEFAULT 'other',
  `original_name` varchar(220) NOT NULL,
  `stored_path` varchar(300) NOT NULL,
  `mime_type` varchar(80) NOT NULL,
  `file_size` bigint(20) UNSIGNED NOT NULL,
  `sha256` char(64) NOT NULL,
  `revision` int(10) UNSIGNED NOT NULL DEFAULT 1,
  `uploaded_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `registration_documents`
--

INSERT INTO `registration_documents` (`id`, `registration_id`, `document_type`, `id_type`, `requirement_type`, `original_name`, `stored_path`, `mime_type`, `file_size`, `sha256`, `revision`, `uploaded_at`) VALUES
(61, 29, 'supporting_document', NULL, 'other', 'Checklist-of-reuirements FS.docx.pdf', 'registrations/29/065b7e21da98c32f5d9f8dd12a09bc2467f9.pdf', 'application/pdf', 81916, '319ec2d217d9d1806928d4e3fd0d0d4fe36004c3dfe8e2b71aeed19e8ae7ea9d', 1, '2026-10-29 23:30:33'),
(62, 29, 'valid_id', 'drivers_license', 'other', 'Checklist-of-reuirements FS.docx.pdf', 'registrations/29/897981841290308c88eb41a1c48a60ec5dfb.pdf', 'application/pdf', 81916, '319ec2d217d9d1806928d4e3fd0d0d4fe36004c3dfe8e2b71aeed19e8ae7ea9d', 1, '2026-10-29 23:30:33');

-- --------------------------------------------------------

--
-- Table structure for table `registration_draft_documents`
--

CREATE TABLE `registration_draft_documents` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `draft_hash` char(64) NOT NULL,
  `document_type` enum('valid_id','supporting_document') NOT NULL,
  `id_type` varchar(80) DEFAULT NULL,
  `requirement_type` varchar(40) NOT NULL DEFAULT 'other',
  `original_name` varchar(220) NOT NULL,
  `stored_path` varchar(300) NOT NULL,
  `mime_type` varchar(80) NOT NULL,
  `file_size` bigint(20) UNSIGNED NOT NULL,
  `sha256` char(64) NOT NULL,
  `ip_hash` char(64) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `registration_draft_documents`
--

INSERT INTO `registration_draft_documents` (`id`, `draft_hash`, `document_type`, `id_type`, `requirement_type`, `original_name`, `stored_path`, `mime_type`, `file_size`, `sha256`, `ip_hash`, `created_at`, `expires_at`) VALUES
(47, 'd0b7d5d29b15ee10e603bf71711b901d9e1ef4fdfcbe5f1862eefbb89115cf0e', 'supporting_document', NULL, 'other', '870096125_1428069158868329_4024791655077340978_n.jpg', 'registration_drafts/d0b7d5d29b15/e3ac705893c9cdbbea685d6151d233088d4ef1bfa5e9.jpg', 'image/jpeg', 235741, '8680d6e9f79292400d5e6d0e8396ad27debd1dd3b949a72fa9aba519ffeba4d8', 'eff8e7ca506627fe15dda5e0e512fcaad70b6d520f37cc76597fdb4f2d83a1a3', '2026-10-29 10:14:46', '2026-10-30 10:14:46'),
(79, '103e65de80c8c6ced83b6c8b27ead3ea7b281382f44fca35c169766533e34c3d', 'supporting_document', NULL, 'other', 'Screenshot 2026-04-24 164708.png', 'registration_drafts/103e65de80c8/bd8acc64cb37c612618e50816cef5af0a1a1be1c3880.png', 'image/png', 29516, '415ac7ad7916e220f4c152fc9a889406fb7518e35bd76ee964d41c434f2c1409', 'eff8e7ca506627fe15dda5e0e512fcaad70b6d520f37cc76597fdb4f2d83a1a3', '2026-10-30 10:36:47', '2026-10-31 10:36:47'),
(80, '103e65de80c8c6ced83b6c8b27ead3ea7b281382f44fca35c169766533e34c3d', 'supporting_document', NULL, 'other', 'Screenshot 2026-04-25 124439.png', 'registration_drafts/103e65de80c8/ef24f0b262ef15e905310becd41eab944277b2247304.png', 'image/png', 32641, '03519f3ccee903755f9114d1fbae82ce2206ef126a786c2b5279d9be547c8574', 'eff8e7ca506627fe15dda5e0e512fcaad70b6d520f37cc76597fdb4f2d83a1a3', '2026-10-30 10:36:51', '2026-10-31 10:36:51'),
(81, '103e65de80c8c6ced83b6c8b27ead3ea7b281382f44fca35c169766533e34c3d', 'valid_id', 'drivers_license', 'other', 'Screenshot 2026-04-23 145432.png', 'registration_drafts/103e65de80c8/5c2cf3e5ac6a058182e9e376d0783bde6e5cd8ffffe3.png', 'image/png', 73281, '8929b599587ac6d2a50ccdc9a768ea9ce5278497bc9e82f4ea2291a09f0934b7', 'eff8e7ca506627fe15dda5e0e512fcaad70b6d520f37cc76597fdb4f2d83a1a3', '2026-10-30 10:37:24', '2026-10-31 10:37:24');

-- --------------------------------------------------------

--
-- Table structure for table `registration_email_otps`
--

CREATE TABLE `registration_email_otps` (
  `client_id` bigint(20) UNSIGNED NOT NULL,
  `otp_hash` varchar(255) NOT NULL,
  `issued_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `failed_attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `resend_window_started_at` datetime NOT NULL DEFAULT current_timestamp(),
  `sends_in_window` tinyint(3) UNSIGNED NOT NULL DEFAULT 1,
  `last_send_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `registration_reviews`
--

CREATE TABLE `registration_reviews` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `registration_id` bigint(20) UNSIGNED NOT NULL,
  `reviewer_user_id` bigint(20) UNSIGNED NOT NULL,
  `revision` int(10) UNSIGNED NOT NULL,
  `decision` enum('approved','needs_correction') NOT NULL,
  `remarks` text DEFAULT NULL,
  `reviewed_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `registration_reviews`
--

INSERT INTO `registration_reviews` (`id`, `registration_id`, `reviewer_user_id`, `revision`, `decision`, `remarks`, `reviewed_at`) VALUES
(5, 29, 29, 1, 'approved', NULL, '2026-10-30 06:03:09');

-- --------------------------------------------------------

--
-- Table structure for table `roles`
--

CREATE TABLE `roles` (
  `id` smallint(5) UNSIGNED NOT NULL,
  `code` varchar(60) NOT NULL,
  `label` varchar(130) NOT NULL,
  `description` varchar(255) DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `roles`
--

INSERT INTO `roles` (`id`, `code`, `label`, `description`) VALUES
(1, 'super_admin', 'Super Administrator (RICTU)', 'System configuration and DENR internal-user provisioning'),
(2, 'applicant', 'Feasibility Permit Applicant', 'Registration, account corrections, and (later) FP applications'),
(3, 'ro_receiving', 'RO Receiving/Releasing (Admin)', 'Receive applications, forward and release approvals'),
(4, 'lpdd_fus', 'LPDD-FUS', 'Registration review, documentary evaluation, Cashier payment verification, Official Receipt upload and FP drafting'),
(5, 'lpdd_chief', 'LPDD Chief', 'Initial the memo, permit and TOR'),
(6, 'ard_ts', 'ARD-TS', 'Endorse and initial documents for RED'),
(7, 'red', 'Regional Executive Director', 'Final approval of FP/TOR and related documents'),
(8, 'cenro_twg', 'CENRO Technical Working Group', 'Field validation, CSW, GIS/shapefiles and certifications'),
(9, 'penro_user', 'PENRO User', 'Coordination with CENRO field offices'),
(10, 'rrdc_member', 'RRDC Member', 'Review and deliberation committee'),
(11, 'rrdc_secretariat', 'RRDC Secretariat', 'Meeting minutes, agreements and signed documents'),
(14, 'gis_focal', 'GIS Focal', 'Prepare GIS maps and geographic information for FP processing'),
(17, 'smd', 'SMD / GIS Mapping', 'RRDC Day 1 final mapping and scheduling support'),
(18, 'cdd', 'CDD', 'RRDC scheduling, meeting minutes and agreements');

-- --------------------------------------------------------

--
-- Table structure for table `shared_office_recovery_otps`
--

CREATE TABLE `shared_office_recovery_otps` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `email_hash` char(64) NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `requested_ip` varchar(45) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `failed_attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `used_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `staff_password_reset_otps`
--

CREATE TABLE `staff_password_reset_otps` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `code_hash` varchar(255) NOT NULL,
  `requested_ip` varchar(45) NOT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `expires_at` datetime NOT NULL,
  `failed_attempts` tinyint(3) UNSIGNED NOT NULL DEFAULT 0,
  `used_at` datetime DEFAULT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------

--
-- Table structure for table `users`
--

CREATE TABLE `users` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `username` varchar(80) DEFAULT NULL,
  `name` varchar(180) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `profile_photo` varchar(255) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `user_kind` enum('applicant','internal') NOT NULL,
  `office_level` varchar(150) DEFAULT NULL,
  `contact_number` varchar(40) DEFAULT NULL,
  `office_station` varchar(160) DEFAULT NULL,
  `status` enum('pending_review','needs_correction','active','suspended','pending_setup') NOT NULL DEFAULT 'pending_review',
  `office_id` bigint(20) UNSIGNED DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `activated_at` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `source_key` varchar(64) DEFAULT NULL,
  `reference_email` varchar(190) DEFAULT NULL,
  `designation_notes` varchar(255) DEFAULT NULL,
  `session_version` int(10) UNSIGNED NOT NULL DEFAULT 1
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `users`
--

INSERT INTO `users` (`id`, `username`, `name`, `email`, `profile_photo`, `password_hash`, `user_kind`, `office_level`, `contact_number`, `office_station`, `status`, `office_id`, `must_change_password`, `activated_at`, `last_login_at`, `created_at`, `updated_at`, `source_key`, `reference_email`, `designation_notes`, `session_version`) VALUES
(1, 'fpuser000001', 'ANTHONIE FENY V. CATALAN', 'venzonanthonie@gmail.com', NULL, '$2y$10$3ILP/0Px6vgKViNBXUlVEeQYBTH/.PCTczcHZsfscGJoowO8coFg2', 'internal', 'Regional Office', '09478984921', 'DENR 13/ PMD', 'active', NULL, 0, '2026-10-21 11:56:20', '2026-11-06 13:42:59', '2026-10-21 11:56:20', '2026-11-06 13:42:59', 'a01-1', 'Venzonanthonie@gmail.com', 'Check for an existing administrator account before provisioning.', 1),
(3, 'nelsoncaranzo3', 'Nelson Caranzo', NULL, NULL, NULL, 'internal', 'CENRO', '0956-0845-321', 'Nasipit', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'c01-1', 'cenronasipit@denr.gov.ph', 'Shared office contact email; collect individual login email.', 1),
(4, 'michealmoreno4', 'Micheal Moreno', NULL, NULL, NULL, 'internal', 'CENRO', '0956-0845-321', 'Nasipit', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'c01-2', 'cenronasipit@denr.gov.ph', 'Shared office contact email; collect individual login email.', 1),
(5, 'ruelladaga5', 'Ruel Ladaga', NULL, NULL, NULL, 'internal', 'CENRO', '0948-829-6736', 'Tubay', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'c02-1', 'cenrotubay@denr.gov.ph', 'Shared office contact email; collect individual login email.', 1),
(6, 'hantelnicolas6', 'Hantel Nicolas', NULL, NULL, NULL, 'internal', 'CENRO', '0948-829-6736', 'Tubay', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'c02-2', 'cenrotubay@denr.gov.ph', 'Shared office contact email; collect individual login email.', 1),
(7, 'saraparejo7', 'Sara Parejo', 'cenrobayugan@denr.gov.ph', NULL, '$2y$10$OG/LJRPfPtzRLysROCXICOX80A1iQn99V6JV.Jf1tF.tE0Xqf6YTW', 'internal', 'CENRO', '085-830-5035', 'Bayugan', 'active', NULL, 1, '2026-11-01 21:15:16', NULL, '2026-10-26 15:15:07', '2026-11-01 21:15:16', 'c03-1', 'cenrobayugan@denr.gov.ph', 'Shared office contact email; collect individual login email.', 1),
(8, 'alilalieghputane8', 'Alilaliegh Putane', NULL, NULL, NULL, 'internal', 'CENRO', '085-830-5035', 'Bayugan', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'c03-2', 'cenrobayugan@denr.gov.ph', 'Shared office contact email; collect individual login email.', 1),
(9, 'roelrosales9', 'Roel Rosales', NULL, NULL, NULL, 'internal', 'CENRO', '0951-6846-768', 'Bunawan', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'c04-1', 'cenrobunawan@denr.gov.ph', 'Shared office contact email; collect individual login email.', 1),
(10, 'jemelitomorala10', 'Jemelito Morala', NULL, NULL, NULL, 'internal', 'CENRO', '0951-6846-768', 'Bunawan', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'c04-2', 'cenrobunawan@denr.gov.ph', 'Shared office contact email; collect individual login email.', 1),
(11, 'racelynargosino11', 'Racelyn Argosino', NULL, NULL, NULL, 'internal', 'CENRO', '0930-2405-190', 'Loreto', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'c05-1', 'cenroloreto@denr.gov.ph', 'Shared office contact email; collect individual login email.', 1),
(12, 'juvybespenor12', 'Juvy B. Espenor', NULL, NULL, NULL, 'internal', 'CENRO', '0930-2405-190', 'Loreto', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'c05-2', 'cenroloreto@denr.gov.ph', 'Shared office contact email; collect individual login email.', 1),
(13, 'vinceestopito13', 'Vince Estopito', 'cenrotalacogon@denr.gov.ph', NULL, '$2y$10$rus8U.jfWyK/IcyY/g4COeGoYiikfAeKj0/uSRzySHHGGTOtDZhwC', 'internal', 'CENRO', '0948-7398-129', 'Talacogon', 'active', NULL, 0, '2026-11-01 21:14:51', '2026-11-02 12:25:21', '2026-10-26 15:15:07', '2026-11-02 12:25:21', 'c06-1', 'cenrotalacogon@denr.gov.ph', 'Shared office contact email; collect individual login email.', 1),
(14, 'jolitopabellan14', 'Jolito Pabellan', NULL, NULL, NULL, 'internal', 'CENRO', '0948-7398-129', 'Talacogon', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'c06-2', 'cenrotalacogon@denr.gov.ph', 'Shared office contact email; collect individual login email.', 1),
(15, 'romelarbolonio15', 'Romel Arbolonio', NULL, NULL, NULL, 'internal', 'CENRO', '0968-331-4001', 'Bislig', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'c07-1', 'cenrobislig@denr.gov.ph', 'Shared office contact email; collect individual login email.', 1),
(16, 'jayvillegas16', 'Jay Villegas', NULL, NULL, NULL, 'internal', 'CENRO', '0968-331-4001', 'Bislig', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'c07-2', 'cenrobislig@denr.gov.ph', 'Shared office contact email; collect individual login email.', 1),
(17, 'josephlanganlangan17', 'Joseph Langanlangan', NULL, NULL, NULL, 'internal', 'CENRO', '0907-169-7840', 'Lianga', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'c08-1', 'cenrolianga@denr.gov.ph', 'Shared office contact email; collect individual login email.', 1),
(18, 'maritessorcullo18', 'Maritess Orcullo', NULL, NULL, NULL, 'internal', 'CENRO', '0907-169-7840', 'Lianga', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'c08-2', 'cenrolianga@denr.gov.ph', 'Shared office contact email; collect individual login email.', 1),
(19, 'leonilarosales19', 'Leonila Rosales', NULL, NULL, NULL, 'internal', 'CENRO', '0912-463-4909', 'Cantilan', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'c09-1', 'cenrocantilan@denr.gov.ph', 'Shared office contact email; collect individual login email.', 1),
(20, 'matheresa20', 'Ma. Theresa', 'cenrocantilan@denr.gov.ph', NULL, '$2y$10$MASNTRW28oPhOTbZ6R67fOJou.o1NbLAgK5vT9vrbV.zXCMWKUrmC', 'internal', 'Regional Office', '0912-463-4909', 'Cantilan', 'active', NULL, 0, '2026-11-05 21:04:29', '2026-11-06 12:43:36', '2026-10-26 15:15:07', '2026-11-06 12:43:36', 'c09-2', 'cenrocantilan@denr.gov.ph', 'Name appears incomplete in source form; verify identity before account creation.', 1),
(21, 'larrycurias21', 'Larry Curias', NULL, NULL, NULL, 'internal', 'PENRO', '0946-2822-765', 'Surigao del Norte (IPENRO)', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'c10-1', 'penrosdnrps@gmail.com', 'Shared office contact email; collect individual login email.', 1),
(22, 'omartupal22', 'Omar Tupal', NULL, NULL, NULL, 'internal', 'PENRO', '0946-2822-765', 'Surigao del Norte (IPENRO)', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'c10-2', 'penrosdnrps@gmail.com', 'Shared office contact email; collect individual login email.', 1),
(23, 'nathanielracho23', 'Nathaniel Racho', NULL, NULL, NULL, 'internal', 'PENRO', '0950-9579-453', 'Dinagat Islands (IPENRO)', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'c11-1', 'penrodinagat@denr.gov.ph', 'Shared office contact email; collect individual login email.', 1),
(24, 'crisleyjsapid24', 'Crisley J. Sapid', NULL, NULL, NULL, 'internal', 'PENRO', '0950-9579-453', 'Dinagat Islands (IPENRO)', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'c11-2', 'penrodinagat@denr.gov.ph', 'Shared office contact email; collect individual login email.', 1),
(25, 'ruelefren25', 'Ruel Efren', NULL, NULL, NULL, 'internal', 'CENRO', '0910-0919-474', 'Tubod', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'c12-1', 'cenrotubod@denr.gov.ph', 'Shared office contact email; collect individual login email.', 1),
(26, 'jerwinresola26', 'Jerwin Resola', NULL, NULL, NULL, 'internal', 'CENRO', '0910-0919-474', 'Tubod', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'c12-2', 'cenrotubod@denr.gov.ph', 'Shared office contact email; collect individual login email.', 1),
(27, 'rosemariepsalvador27', 'Rosemarie P. Salvador', NULL, NULL, NULL, 'internal', 'Regional Office', '09079416644', 'LPDD/FUS', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'd01-1', 'Lasangutilizationlpdd@gmail.com', 'Shared office contact email; collect individual login email.', 1),
(28, 'archimcocariz28', 'Archim C. Ocariz', NULL, NULL, NULL, 'internal', 'Regional Office', NULL, 'LPDD/FUS', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'd02-1', 'Lasangutilizationlpdd@gmail.com', 'Shared office contact email; collect individual login email.', 1),
(29, 'fpuser000029', 'Alexander Paler', 'alexanderpaler751@gmail.com', NULL, '$2y$10$ERr6l6Xgpy2CoG7XExlAZe0PsWQA1NycltijqqU7NhisHj1Ri5nBS', 'internal', 'Regional Office', NULL, 'LPDD/FUS', 'active', NULL, 0, '2026-10-28 09:35:27', '2026-11-06 14:18:00', '2026-10-26 15:15:07', '2026-11-06 14:18:00', 'd03-1', 'Lasangutilizationlpdd@gmail.com', 'Shared office contact email; collect individual login email.', 1),
(30, 'fpuser000030', 'Mila J. Garcia', 'lpddr13@denr.gov.ph', NULL, '$2y$10$h.Z.vWEYW8G3RP6QjVxLQ.hwhDi5G81uFZqd8TbkFfVao54kbsS22', 'internal', 'Regional Office', NULL, 'LPDD/RO', 'active', NULL, 1, '2026-10-28 09:35:56', NULL, '2026-10-26 15:15:07', '2026-10-28 09:35:56', 'd04-1', 'lpddr13@denr.gov.ph', NULL, 1),
(31, 'juliemontalban31', 'Julie Montalban', 'records.r13@denr.gov.ph', NULL, '$2y$10$bUSHg7WDNtTeXHpo2nOKx.3ZaaiQcB68wPR9wtERVC1MsjNdF/1Rq', 'internal', 'Regional Office', NULL, 'Records Section/Admin RO', 'active', NULL, 0, '2026-11-01 21:17:22', '2026-11-06 14:30:14', '2026-10-26 15:15:07', '2026-11-06 14:30:14', 'd05-1', 'records.r13@denr.gov.ph', NULL, 1),
(32, 'attyredemtoraoja32', 'Atty. Redemtor A. Oja', NULL, NULL, NULL, 'internal', 'Regional Office', NULL, 'Office of the ARD TS', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'ard01-1', 'Ordts2021@gmail.com', NULL, 1),
(33, 'leonitoramos33', 'Leonito Ramos', NULL, NULL, NULL, 'internal', 'Regional Office', NULL, 'CDD/RO', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'rrdc01-1', 'cbfmcaragaofficial@gmail.com', NULL, 1),
(34, 'kristofferbuy34', 'Kristoffer B. Uy', NULL, NULL, NULL, 'internal', 'Regional Office', NULL, 'GIS Focal', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'rrdc02-1', 'kristofferbuy23@gmail.com', NULL, 1),
(35, 'matheresajallen35', 'Ma. Theresa J. Allen', NULL, NULL, NULL, 'internal', 'Regional Office', NULL, 'ORED', 'pending_setup', NULL, 1, NULL, NULL, '2026-10-26 15:15:07', '2026-11-01 20:46:27', 'red01-1', 'r13@denr.gov.ph', NULL, 1);

-- --------------------------------------------------------

--
-- Table structure for table `user_client`
--

CREATE TABLE `user_client` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `username` varchar(80) DEFAULT NULL,
  `name` varchar(180) NOT NULL,
  `email` varchar(190) DEFAULT NULL,
  `profile_photo` varchar(255) DEFAULT NULL,
  `password_hash` varchar(255) DEFAULT NULL,
  `user_kind` enum('applicant','internal') NOT NULL,
  `office_level` varchar(150) DEFAULT NULL,
  `contact_number` varchar(40) DEFAULT NULL,
  `office_station` varchar(160) DEFAULT NULL,
  `status` enum('pending_review','needs_correction','active','suspended','pending_setup','pending_email_verification') NOT NULL DEFAULT 'pending_review',
  `office_id` bigint(20) UNSIGNED DEFAULT NULL,
  `must_change_password` tinyint(1) NOT NULL DEFAULT 0,
  `activated_at` datetime DEFAULT NULL,
  `email_verified_at` datetime DEFAULT NULL,
  `last_login_at` datetime DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp(),
  `updated_at` datetime NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  `source_key` varchar(64) DEFAULT NULL,
  `reference_email` varchar(190) DEFAULT NULL,
  `designation_notes` varchar(255) DEFAULT NULL
) ;

--
-- Dumping data for table `user_client`
--

INSERT INTO `user_client` (`id`, `username`, `name`, `email`, `profile_photo`, `password_hash`, `user_kind`, `office_level`, `contact_number`, `office_station`, `status`, `office_id`, `must_change_password`, `activated_at`, `email_verified_at`, `last_login_at`, `created_at`, `updated_at`, `source_key`, `reference_email`, `designation_notes`) VALUES
(29, 'fpclient000029', 'ANTHONIE FENY V. CATALAN', 'amsos@denr.gov.ph', NULL, '$2y$10$oajoNedgC8nFtNZT5vUKMeseya8NdHW9tcPeabo2CxnUFfyOY0dKK', 'applicant', 'External Applicant', NULL, NULL, 'active', NULL, 0, '2026-10-30 06:03:09', '2026-10-29 23:30:57', '2026-11-06 14:17:55', '2026-10-29 23:30:33', '2026-11-06 14:17:55', NULL, NULL, NULL);

-- --------------------------------------------------------

--
-- Table structure for table `user_roles`
--

CREATE TABLE `user_roles` (
  `user_id` bigint(20) UNSIGNED NOT NULL,
  `role_id` smallint(5) UNSIGNED NOT NULL,
  `assigned_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Dumping data for table `user_roles`
--

INSERT INTO `user_roles` (`user_id`, `role_id`, `assigned_at`) VALUES
(1, 1, '2026-10-28 09:25:59'),
(3, 8, '2026-10-26 15:40:00'),
(4, 8, '2026-10-26 15:40:00'),
(5, 8, '2026-10-26 15:40:00'),
(6, 8, '2026-10-26 15:40:00'),
(7, 8, '2026-11-01 21:15:16'),
(8, 8, '2026-10-26 15:40:00'),
(9, 8, '2026-10-26 15:40:00'),
(10, 8, '2026-10-26 15:40:00'),
(11, 8, '2026-10-26 15:40:00'),
(12, 8, '2026-10-26 15:40:00'),
(13, 8, '2026-11-01 21:14:51'),
(14, 8, '2026-10-26 15:40:00'),
(15, 8, '2026-10-26 15:40:00'),
(16, 8, '2026-10-26 15:40:00'),
(17, 8, '2026-10-26 15:40:00'),
(18, 8, '2026-10-26 15:40:00'),
(19, 8, '2026-10-26 15:40:00'),
(20, 7, '2026-11-05 21:06:20'),
(21, 9, '2026-10-26 15:40:00'),
(22, 9, '2026-10-26 15:40:00'),
(23, 9, '2026-10-26 15:40:00'),
(24, 9, '2026-10-26 15:40:00'),
(25, 8, '2026-10-26 15:40:00'),
(26, 8, '2026-10-26 15:40:00'),
(27, 4, '2026-10-26 15:40:00'),
(28, 4, '2026-10-26 15:40:00'),
(29, 4, '2026-11-01 15:30:40'),
(30, 5, '2026-10-28 09:35:56'),
(31, 3, '2026-11-01 21:17:22'),
(32, 6, '2026-10-26 15:40:00'),
(33, 10, '2026-10-26 15:40:00'),
(34, 14, '2026-10-26 15:40:00'),
(35, 7, '2026-10-26 15:40:00');

-- --------------------------------------------------------

--
-- Table structure for table `workflow_actions`
--

CREATE TABLE `workflow_actions` (
  `id` bigint(20) UNSIGNED NOT NULL,
  `application_id` bigint(20) UNSIGNED NOT NULL,
  `step_number` tinyint(3) UNSIGNED NOT NULL,
  `performed_by` bigint(20) UNSIGNED DEFAULT NULL,
  `performed_by_client_id` bigint(20) UNSIGNED DEFAULT NULL,
  `action_code` varchar(70) NOT NULL,
  `from_status` varchar(40) DEFAULT NULL,
  `to_status` varchar(40) DEFAULT NULL,
  `remarks` text DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT current_timestamp()
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

--
-- Indexes for dumped tables
--

--
-- Indexes for table `account_login_logs`
--
ALTER TABLE `account_login_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_account_login_user_created` (`user_id`,`created_at`),
  ADD KEY `idx_account_login_event_created` (`event_type`,`created_at`),
  ADD KEY `idx_account_login_actor_created` (`actor_user_id`,`created_at`);

--
-- Indexes for table `applicant_registrations`
--
ALTER TABLE `applicant_registrations`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `user_id` (`client_id`);

--
-- Indexes for table `application_documents`
--
ALTER TABLE `application_documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `stored_path` (`stored_path`),
  ADD KEY `idx_app_docs` (`application_id`,`document_code`,`version`),
  ADD KEY `idx_application_document_client` (`uploaded_by_client_id`),
  ADD KEY `fk_ad_user` (`uploaded_by`);

--
-- Indexes for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_audit_target` (`target_type`,`target_id`,`created_at`),
  ADD KEY `idx_audit_actor` (`actor_user_id`,`created_at`),
  ADD KEY `idx_audit_client` (`actor_client_id`,`created_at`);

--
-- Indexes for table `fpoms_deficiency_notices`
--
ALTER TABLE `fpoms_deficiency_notices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fp_notice_application_revision` (`application_id`,`revision`),
  ADD KEY `idx_fp_notice_pending` (`status`,`created_at`),
  ADD KEY `fk_fp_notice_reviewer` (`reviewer_user_id`),
  ADD KEY `fk_fp_notice_approver` (`approved_by`),
  ADD KEY `fk_fp_notice_artifact` (`artifact_id`),
  ADD KEY `idx_fp_notice_email_status` (`email_status`);

--
-- Indexes for table `fpoms_notice_config`
--
ALTER TABLE `fpoms_notice_config`
  ADD PRIMARY KEY (`id`),
  ADD KEY `fk_fp_notice_config_editor` (`updated_by`);

--
-- Indexes for table `fpoms_realtime_settings`
--
ALTER TABLE `fpoms_realtime_settings`
  ADD PRIMARY KEY (`id`);

--
-- Indexes for table `fp_chat_files`
--
ALTER TABLE `fp_chat_files`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fp_chat_file_path` (`stored_path`),
  ADD KEY `idx_fp_chat_file_uploader` (`uploaded_by_kind`,`uploaded_by_id`,`created_at`);

--
-- Indexes for table `fp_chat_messages`
--
ALTER TABLE `fp_chat_messages`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_fp_chat_messages_thread` (`thread_id`,`id`),
  ADD KEY `idx_fp_chat_messages_sender` (`sender_kind`,`sender_id`,`created_at`),
  ADD KEY `fk_fp_chat_message_file` (`file_id`);

--
-- Indexes for table `fp_chat_threads`
--
ALTER TABLE `fp_chat_threads`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_fp_chat_direct_key` (`direct_key`),
  ADD KEY `idx_fp_chat_threads_updated` (`updated_at`);

--
-- Indexes for table `fp_chat_thread_members`
--
ALTER TABLE `fp_chat_thread_members`
  ADD PRIMARY KEY (`thread_id`,`principal_kind`,`principal_id`),
  ADD KEY `idx_fp_chat_member_principal` (`principal_kind`,`principal_id`,`archived_at`);

--
-- Indexes for table `login_attempts`
--
ALTER TABLE `login_attempts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_login_email` (`email_hash`,`attempted_at`),
  ADD KEY `idx_login_ip` (`ip_address`,`attempted_at`);

--
-- Indexes for table `notifications`
--
ALTER TABLE `notifications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_notifications_user_unread_created` (`user_id`,`read_at`,`created_at`),
  ADD KEY `idx_notifications_client` (`client_id`,`read_at`,`created_at`);

--
-- Indexes for table `offices`
--
ALTER TABLE `offices`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_office_type_name` (`office_type`,`name`),
  ADD KEY `fk_office_parent` (`parent_office_id`);

--
-- Indexes for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `token_hash` (`token_hash`),
  ADD KEY `idx_password_reset_user_created` (`user_id`,`created_at`),
  ADD KEY `idx_password_reset_expiry` (`expires_at`,`used_at`),
  ADD KEY `idx_reset_client` (`client_id`,`created_at`);

--
-- Indexes for table `permit_applications`
--
ALTER TABLE `permit_applications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `reference_number` (`reference_number`),
  ADD KEY `idx_app_owner` (`client_id`,`status`),
  ADD KEY `idx_app_workflow` (`current_step`,`status`);

--
-- Indexes for table `permit_flow_artifacts`
--
ALTER TABLE `permit_flow_artifacts`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_flow_artifact_category` (`application_id`,`step_number`,`category`),
  ADD KEY `fk_fp_flow_artifact_staff` (`uploaded_by`);

--
-- Indexes for table `permit_flow_cases`
--
ALTER TABLE `permit_flow_cases`
  ADD PRIMARY KEY (`application_id`);

--
-- Indexes for table `permit_flow_events`
--
ALTER TABLE `permit_flow_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_flow_events` (`application_id`,`id`),
  ADD KEY `fk_fp_flow_event_staff` (`actor_id`);

--
-- Indexes for table `permit_payment_verifications`
--
ALTER TABLE `permit_payment_verifications`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_payment_application` (`application_id`),
  ADD KEY `idx_payment_status` (`status`,`verified_at`),
  ADD KEY `idx_payment_verifier` (`verified_by_user_id`,`verified_at`);

--
-- Indexes for table `permit_records`
--
ALTER TABLE `permit_records`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `application_id` (`application_id`),
  ADD UNIQUE KEY `permit_number` (`permit_number`),
  ADD KEY `fk_pr_user` (`approved_by`);

--
-- Indexes for table `permit_records_archives`
--
ALTER TABLE `permit_records_archives`
  ADD PRIMARY KEY (`application_id`),
  ADD KEY `idx_records_archives_date` (`archived_at`),
  ADD KEY `idx_records_archives_user` (`archived_by_user_id`);

--
-- Indexes for table `permit_step2_applications`
--
ALTER TABLE `permit_step2_applications`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_step2_client` (`client_id`,`created_at`),
  ADD KEY `idx_step2_queue` (`status`,`last_submitted_at`),
  ADD KEY `fk_step2_reviewer` (`reviewer_user_id`),
  ADD KEY `idx_step2_received_by` (`received_by_user_id`);

--
-- Indexes for table `permit_step2_documents`
--
ALTER TABLE `permit_step2_documents`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_step2_documents_app` (`application_id`,`revision`,`id`),
  ADD KEY `fk_step2_document_client` (`client_id`);

--
-- Indexes for table `permit_step2_draft_documents`
--
ALTER TABLE `permit_step2_draft_documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_step2_draft_path` (`stored_path`),
  ADD KEY `idx_step2_draft_owner` (`client_id`,`draft_hash`,`application_id`,`expires_at`),
  ADD KEY `idx_step2_draft_expire` (`expires_at`);

--
-- Indexes for table `permit_step2_events`
--
ALTER TABLE `permit_step2_events`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_step2_events_app` (`application_id`,`occurred_at`,`id`),
  ADD KEY `fk_step2_event_client` (`actor_client_id`),
  ADD KEY `fk_step2_event_staff` (`actor_user_id`);

--
-- Indexes for table `permit_step2_form_drafts`
--
ALTER TABLE `permit_step2_form_drafts`
  ADD PRIMARY KEY (`client_id`,`application_id`);

--
-- Indexes for table `permit_step3_movements`
--
ALTER TABLE `permit_step3_movements`
  ADD PRIMARY KEY (`application_id`),
  ADD KEY `idx_step3_received` (`received_at`),
  ADD KEY `idx_step3_forwarded` (`forwarded_at`),
  ADD KEY `fk_step3_received_by` (`received_by_user_id`),
  ADD KEY `fk_step3_forwarded_by` (`forwarded_by_user_id`);

--
-- Indexes for table `permit_step4_requirement_reviews`
--
ALTER TABLE `permit_step4_requirement_reviews`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_step4_review_requirement` (`review_id`,`requirement_type`),
  ADD KEY `idx_step4_requirement_assessment` (`assessment`);

--
-- Indexes for table `permit_step4_reviews`
--
ALTER TABLE `permit_step4_reviews`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `uq_step4_app_revision` (`application_id`,`revision`),
  ADD KEY `idx_step4_status` (`review_status`,`started_at`),
  ADD KEY `idx_step4_reviewer` (`reviewer_user_id`,`started_at`);

--
-- Indexes for table `registration_documents`
--
ALTER TABLE `registration_documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `stored_path` (`stored_path`),
  ADD KEY `idx_reg_docs_latest` (`registration_id`,`document_type`,`id`);

--
-- Indexes for table `registration_draft_documents`
--
ALTER TABLE `registration_draft_documents`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `stored_path` (`stored_path`),
  ADD KEY `idx_draft_owner` (`draft_hash`,`expires_at`),
  ADD KEY `idx_draft_expiration` (`expires_at`),
  ADD KEY `idx_draft_ip` (`ip_hash`,`created_at`);

--
-- Indexes for table `registration_email_otps`
--
ALTER TABLE `registration_email_otps`
  ADD PRIMARY KEY (`client_id`),
  ADD KEY `idx_registration_otp_expiration` (`expires_at`);

--
-- Indexes for table `registration_reviews`
--
ALTER TABLE `registration_reviews`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_reg_review` (`registration_id`,`reviewed_at`),
  ADD KEY `fk_rev_user` (`reviewer_user_id`);

--
-- Indexes for table `roles`
--
ALTER TABLE `roles`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `code` (`code`);

--
-- Indexes for table `shared_office_recovery_otps`
--
ALTER TABLE `shared_office_recovery_otps`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_office_otp_hash_time` (`email_hash`,`created_at`),
  ADD KEY `idx_office_otp_ip_time` (`requested_ip`,`created_at`);

--
-- Indexes for table `staff_password_reset_otps`
--
ALTER TABLE `staff_password_reset_otps`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_staff_otp_user` (`user_id`,`created_at`),
  ADD KEY `idx_staff_otp_ip` (`requested_ip`,`created_at`);

--
-- Indexes for table `users`
--
ALTER TABLE `users`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `uq_users_username` (`username`),
  ADD UNIQUE KEY `uq_users_source_key` (`source_key`),
  ADD UNIQUE KEY `uq_staff_username_login` (`username`),
  ADD KEY `idx_user_status_kind` (`status`,`user_kind`),
  ADD KEY `fk_user_office` (`office_id`);

--
-- Indexes for table `user_client`
--
ALTER TABLE `user_client`
  ADD PRIMARY KEY (`id`),
  ADD UNIQUE KEY `email` (`email`),
  ADD UNIQUE KEY `uq_users_username` (`username`),
  ADD UNIQUE KEY `uq_users_source_key` (`source_key`),
  ADD KEY `idx_user_status_kind` (`status`,`user_kind`),
  ADD KEY `fk_user_office` (`office_id`);

--
-- Indexes for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD PRIMARY KEY (`user_id`,`role_id`),
  ADD KEY `fk_ur_role` (`role_id`);

--
-- Indexes for table `workflow_actions`
--
ALTER TABLE `workflow_actions`
  ADD PRIMARY KEY (`id`),
  ADD KEY `idx_workflow_app` (`application_id`,`created_at`),
  ADD KEY `idx_workflow_action_client` (`performed_by_client_id`),
  ADD KEY `fk_wa_user` (`performed_by`);

--
-- AUTO_INCREMENT for dumped tables
--

--
-- AUTO_INCREMENT for table `account_login_logs`
--
ALTER TABLE `account_login_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `applicant_registrations`
--
ALTER TABLE `applicant_registrations`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=30;

--
-- AUTO_INCREMENT for table `application_documents`
--
ALTER TABLE `application_documents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `audit_logs`
--
ALTER TABLE `audit_logs`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=12;

--
-- AUTO_INCREMENT for table `fpoms_deficiency_notices`
--
ALTER TABLE `fpoms_deficiency_notices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fp_chat_files`
--
ALTER TABLE `fp_chat_files`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `fp_chat_messages`
--
ALTER TABLE `fp_chat_messages`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `fp_chat_threads`
--
ALTER TABLE `fp_chat_threads`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=3;

--
-- AUTO_INCREMENT for table `login_attempts`
--
ALTER TABLE `login_attempts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=5;

--
-- AUTO_INCREMENT for table `notifications`
--
ALTER TABLE `notifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `offices`
--
ALTER TABLE `offices`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permit_applications`
--
ALTER TABLE `permit_applications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permit_flow_artifacts`
--
ALTER TABLE `permit_flow_artifacts`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permit_flow_events`
--
ALTER TABLE `permit_flow_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permit_payment_verifications`
--
ALTER TABLE `permit_payment_verifications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permit_records`
--
ALTER TABLE `permit_records`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permit_step2_applications`
--
ALTER TABLE `permit_step2_applications`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permit_step2_documents`
--
ALTER TABLE `permit_step2_documents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permit_step2_draft_documents`
--
ALTER TABLE `permit_step2_draft_documents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permit_step2_events`
--
ALTER TABLE `permit_step2_events`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permit_step4_requirement_reviews`
--
ALTER TABLE `permit_step4_requirement_reviews`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `permit_step4_reviews`
--
ALTER TABLE `permit_step4_reviews`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `registration_documents`
--
ALTER TABLE `registration_documents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=63;

--
-- AUTO_INCREMENT for table `registration_draft_documents`
--
ALTER TABLE `registration_draft_documents`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=83;

--
-- AUTO_INCREMENT for table `registration_reviews`
--
ALTER TABLE `registration_reviews`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=6;

--
-- AUTO_INCREMENT for table `roles`
--
ALTER TABLE `roles`
  MODIFY `id` smallint(5) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=19;

--
-- AUTO_INCREMENT for table `shared_office_recovery_otps`
--
ALTER TABLE `shared_office_recovery_otps`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `staff_password_reset_otps`
--
ALTER TABLE `staff_password_reset_otps`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `users`
--
ALTER TABLE `users`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT, AUTO_INCREMENT=37;

--
-- AUTO_INCREMENT for table `user_client`
--
ALTER TABLE `user_client`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- AUTO_INCREMENT for table `workflow_actions`
--
ALTER TABLE `workflow_actions`
  MODIFY `id` bigint(20) UNSIGNED NOT NULL AUTO_INCREMENT;

--
-- Constraints for dumped tables
--

--
-- Constraints for table `applicant_registrations`
--
ALTER TABLE `applicant_registrations`
  ADD CONSTRAINT `fk_registration_client` FOREIGN KEY (`client_id`) REFERENCES `user_client` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `application_documents`
--
ALTER TABLE `application_documents`
  ADD CONSTRAINT `fk_ad_application` FOREIGN KEY (`application_id`) REFERENCES `permit_applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_ad_client` FOREIGN KEY (`uploaded_by_client_id`) REFERENCES `user_client` (`id`),
  ADD CONSTRAINT `fk_ad_user` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `audit_logs`
--
ALTER TABLE `audit_logs`
  ADD CONSTRAINT `fk_audit_client` FOREIGN KEY (`actor_client_id`) REFERENCES `user_client` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_audit_user` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `fpoms_deficiency_notices`
--
ALTER TABLE `fpoms_deficiency_notices`
  ADD CONSTRAINT `fk_fp_notice_app` FOREIGN KEY (`application_id`) REFERENCES `permit_step2_applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_fp_notice_approver` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_fp_notice_artifact` FOREIGN KEY (`artifact_id`) REFERENCES `permit_flow_artifacts` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_fp_notice_reviewer` FOREIGN KEY (`reviewer_user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `fpoms_notice_config`
--
ALTER TABLE `fpoms_notice_config`
  ADD CONSTRAINT `fk_fp_notice_config_editor` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `fp_chat_messages`
--
ALTER TABLE `fp_chat_messages`
  ADD CONSTRAINT `fk_fp_chat_message_file` FOREIGN KEY (`file_id`) REFERENCES `fp_chat_files` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_fp_chat_message_thread` FOREIGN KEY (`thread_id`) REFERENCES `fp_chat_threads` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `fp_chat_thread_members`
--
ALTER TABLE `fp_chat_thread_members`
  ADD CONSTRAINT `fk_fp_chat_member_thread` FOREIGN KEY (`thread_id`) REFERENCES `fp_chat_threads` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `notifications`
--
ALTER TABLE `notifications`
  ADD CONSTRAINT `fk_notifications_client` FOREIGN KEY (`client_id`) REFERENCES `user_client` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_notifications_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `offices`
--
ALTER TABLE `offices`
  ADD CONSTRAINT `fk_office_parent` FOREIGN KEY (`parent_office_id`) REFERENCES `offices` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `password_reset_tokens`
--
ALTER TABLE `password_reset_tokens`
  ADD CONSTRAINT `fk_password_reset_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_reset_client` FOREIGN KEY (`client_id`) REFERENCES `user_client` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `permit_applications`
--
ALTER TABLE `permit_applications`
  ADD CONSTRAINT `fk_permit_client` FOREIGN KEY (`client_id`) REFERENCES `user_client` (`id`);

--
-- Constraints for table `permit_flow_artifacts`
--
ALTER TABLE `permit_flow_artifacts`
  ADD CONSTRAINT `fk_fp_flow_artifact_app` FOREIGN KEY (`application_id`) REFERENCES `permit_step2_applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_fp_flow_artifact_staff` FOREIGN KEY (`uploaded_by`) REFERENCES `users` (`id`);

--
-- Constraints for table `permit_flow_cases`
--
ALTER TABLE `permit_flow_cases`
  ADD CONSTRAINT `fk_fp_flow_case_app` FOREIGN KEY (`application_id`) REFERENCES `permit_step2_applications` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `permit_flow_events`
--
ALTER TABLE `permit_flow_events`
  ADD CONSTRAINT `fk_fp_flow_event_app` FOREIGN KEY (`application_id`) REFERENCES `permit_step2_applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_fp_flow_event_staff` FOREIGN KEY (`actor_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `permit_payment_verifications`
--
ALTER TABLE `permit_payment_verifications`
  ADD CONSTRAINT `fk_fp_payment_app` FOREIGN KEY (`application_id`) REFERENCES `permit_step2_applications` (`id`),
  ADD CONSTRAINT `fk_fp_payment_verifier` FOREIGN KEY (`verified_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `permit_records`
--
ALTER TABLE `permit_records`
  ADD CONSTRAINT `fk_pr_application` FOREIGN KEY (`application_id`) REFERENCES `permit_applications` (`id`),
  ADD CONSTRAINT `fk_pr_user` FOREIGN KEY (`approved_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `permit_records_archives`
--
ALTER TABLE `permit_records_archives`
  ADD CONSTRAINT `fk_records_archive_app` FOREIGN KEY (`application_id`) REFERENCES `permit_step2_applications` (`id`),
  ADD CONSTRAINT `fk_records_archive_staff` FOREIGN KEY (`archived_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `permit_step2_applications`
--
ALTER TABLE `permit_step2_applications`
  ADD CONSTRAINT `fk_step2_client` FOREIGN KEY (`client_id`) REFERENCES `user_client` (`id`),
  ADD CONSTRAINT `fk_step2_received_by` FOREIGN KEY (`received_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_step2_reviewer` FOREIGN KEY (`reviewer_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `permit_step2_documents`
--
ALTER TABLE `permit_step2_documents`
  ADD CONSTRAINT `fk_step2_document_app` FOREIGN KEY (`application_id`) REFERENCES `permit_step2_applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_step2_document_client` FOREIGN KEY (`client_id`) REFERENCES `user_client` (`id`);

--
-- Constraints for table `permit_step2_draft_documents`
--
ALTER TABLE `permit_step2_draft_documents`
  ADD CONSTRAINT `fk_step2_draft_client` FOREIGN KEY (`client_id`) REFERENCES `user_client` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `permit_step2_events`
--
ALTER TABLE `permit_step2_events`
  ADD CONSTRAINT `fk_step2_event_app` FOREIGN KEY (`application_id`) REFERENCES `permit_step2_applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_step2_event_client` FOREIGN KEY (`actor_client_id`) REFERENCES `user_client` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_step2_event_staff` FOREIGN KEY (`actor_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `permit_step2_form_drafts`
--
ALTER TABLE `permit_step2_form_drafts`
  ADD CONSTRAINT `fk_step2_form_draft_client` FOREIGN KEY (`client_id`) REFERENCES `user_client` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `permit_step3_movements`
--
ALTER TABLE `permit_step3_movements`
  ADD CONSTRAINT `fk_step3_app` FOREIGN KEY (`application_id`) REFERENCES `permit_step2_applications` (`id`),
  ADD CONSTRAINT `fk_step3_forwarded_by` FOREIGN KEY (`forwarded_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL,
  ADD CONSTRAINT `fk_step3_received_by` FOREIGN KEY (`received_by_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `permit_step4_requirement_reviews`
--
ALTER TABLE `permit_step4_requirement_reviews`
  ADD CONSTRAINT `fk_step4_requirement_review` FOREIGN KEY (`review_id`) REFERENCES `permit_step4_reviews` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `permit_step4_reviews`
--
ALTER TABLE `permit_step4_reviews`
  ADD CONSTRAINT `fk_step4_app` FOREIGN KEY (`application_id`) REFERENCES `permit_step2_applications` (`id`),
  ADD CONSTRAINT `fk_step4_reviewer` FOREIGN KEY (`reviewer_user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `registration_documents`
--
ALTER TABLE `registration_documents`
  ADD CONSTRAINT `fk_rd_registration` FOREIGN KEY (`registration_id`) REFERENCES `applicant_registrations` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `registration_email_otps`
--
ALTER TABLE `registration_email_otps`
  ADD CONSTRAINT `fk_registration_email_otp_client` FOREIGN KEY (`client_id`) REFERENCES `user_client` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `registration_reviews`
--
ALTER TABLE `registration_reviews`
  ADD CONSTRAINT `fk_rev_registration` FOREIGN KEY (`registration_id`) REFERENCES `applicant_registrations` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_rev_user` FOREIGN KEY (`reviewer_user_id`) REFERENCES `users` (`id`);

--
-- Constraints for table `staff_password_reset_otps`
--
ALTER TABLE `staff_password_reset_otps`
  ADD CONSTRAINT `fk_staff_otp_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `users`
--
ALTER TABLE `users`
  ADD CONSTRAINT `fk_user_office` FOREIGN KEY (`office_id`) REFERENCES `offices` (`id`) ON DELETE SET NULL;

--
-- Constraints for table `user_roles`
--
ALTER TABLE `user_roles`
  ADD CONSTRAINT `fk_ur_role` FOREIGN KEY (`role_id`) REFERENCES `roles` (`id`),
  ADD CONSTRAINT `fk_ur_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

--
-- Constraints for table `workflow_actions`
--
ALTER TABLE `workflow_actions`
  ADD CONSTRAINT `fk_wa_application` FOREIGN KEY (`application_id`) REFERENCES `permit_applications` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_wa_client` FOREIGN KEY (`performed_by_client_id`) REFERENCES `user_client` (`id`),
  ADD CONSTRAINT `fk_wa_user` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`);
COMMIT;

/*!40101 SET CHARACTER_SET_CLIENT=@OLD_CHARACTER_SET_CLIENT */;
/*!40101 SET CHARACTER_SET_RESULTS=@OLD_CHARACTER_SET_RESULTS */;
/*!40101 SET COLLATION_CONNECTION=@OLD_COLLATION_CONNECTION */;
