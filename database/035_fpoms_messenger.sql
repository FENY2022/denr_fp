-- DENR CARAGA FP-OMS 035: secure floating Messenger.
-- Apply once after migration 034. Back up the database and private storage first.
-- Chat principals are polymorphic because DENR personnel live in users while applicants
-- live in user_client; principal_kind + principal_id prevents overlapping numeric IDs
-- from being mistaken for the same account.

CREATE TABLE fp_chat_threads (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    thread_type ENUM('direct','group') NOT NULL,
    direct_key VARCHAR(100) NULL,
    name VARCHAR(160) NULL,
    created_by_kind ENUM('staff','applicant') NOT NULL,
    created_by_id BIGINT UNSIGNED NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fp_chat_direct_key (direct_key),
    KEY idx_fp_chat_threads_updated (updated_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fp_chat_thread_members (
    thread_id BIGINT UNSIGNED NOT NULL,
    principal_kind ENUM('staff','applicant') NOT NULL,
    principal_id BIGINT UNSIGNED NOT NULL,
    member_role ENUM('admin','member') NOT NULL DEFAULT 'member',
    last_read_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    hidden_before_message_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
    archived_at DATETIME NULL,
    joined_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (thread_id,principal_kind,principal_id),
    KEY idx_fp_chat_member_principal (principal_kind,principal_id,archived_at),
    CONSTRAINT fk_fp_chat_member_thread
        FOREIGN KEY (thread_id) REFERENCES fp_chat_threads(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fp_chat_files (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    uploaded_by_kind ENUM('staff','applicant') NOT NULL,
    uploaded_by_id BIGINT UNSIGNED NOT NULL,
    original_name VARCHAR(255) NOT NULL,
    stored_path VARCHAR(500) NOT NULL,
    file_size BIGINT UNSIGNED NOT NULL,
    mime_type VARCHAR(100) NOT NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_fp_chat_file_path (stored_path),
    KEY idx_fp_chat_file_uploader (uploaded_by_kind,uploaded_by_id,created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE fp_chat_messages (
    id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    thread_id BIGINT UNSIGNED NOT NULL,
    sender_kind ENUM('staff','applicant') NOT NULL,
    sender_id BIGINT UNSIGNED NOT NULL,
    message TEXT NULL,
    file_id BIGINT UNSIGNED NULL,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_fp_chat_messages_thread (thread_id,id),
    KEY idx_fp_chat_messages_sender (sender_kind,sender_id,created_at),
    CONSTRAINT fk_fp_chat_message_thread
        FOREIGN KEY (thread_id) REFERENCES fp_chat_threads(id) ON DELETE CASCADE,
    CONSTRAINT fk_fp_chat_message_file
        FOREIGN KEY (file_id) REFERENCES fp_chat_files(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SELECT
    (SELECT COUNT(*) FROM fp_chat_threads) AS chat_threads,
    (SELECT COUNT(*) FROM fp_chat_thread_members) AS chat_members,
    (SELECT COUNT(*) FROM fp_chat_messages) AS chat_messages;
