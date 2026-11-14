-- FP-OMS 044: Template No. 3 RED PNPKI_2 signing + Records official release.
-- Run once on denr_fp_oms AFTER 041/042/043 and a full backup.
-- Existing Template No. 2 links, notices and uploaded CENRO memos stay unchanged.
CREATE TABLE IF NOT EXISTS fpoms_cenro_memo_signings (
  application_id BIGINT UNSIGNED NOT NULL,
  revision INT UNSIGNED NOT NULL,
  prepared_by BIGINT UNSIGNED NOT NULL,
  draft_path VARCHAR(400) NOT NULL,
  draft_sha256 CHAR(64) NOT NULL,
  source_key CHAR(64) NOT NULL,
  signed_path VARCHAR(400) NULL,
  signed_sha256 CHAR(64) NULL,
  signed_size BIGINT UNSIGNED NULL,
  signed_by BIGINT UNSIGNED NULL,
  remote_doc_id INT NULL,
  stage ENUM('pending_red','awaiting_records','verification_required','released_verified') NOT NULL DEFAULT 'pending_red',
  released_artifact_id BIGINT UNSIGNED NULL,
  released_at DATETIME NULL,
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (application_id, revision),
  UNIQUE KEY uq_cenro_memo_source (source_key),
  UNIQUE KEY uq_cenro_memo_remote (remote_doc_id),
  CONSTRAINT fk_cenro_memo_app FOREIGN KEY (application_id) REFERENCES permit_step2_applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_cenro_memo_preparer FOREIGN KEY (prepared_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cenro_memo_signer FOREIGN KEY (signed_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_cenro_memo_artifact FOREIGN KEY (released_artifact_id) REFERENCES permit_flow_artifacts(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
