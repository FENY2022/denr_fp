-- FP-OMS ↔ PNPKI_2 secure Template No. 2 handoff.
-- Apply to denr_fp_oms after backup. No existing notices/accounts are deleted.
CREATE TABLE IF NOT EXISTS fpoms_pnpki_links (
  application_id BIGINT UNSIGNED NOT NULL,
  revision INT UNSIGNED NOT NULL,
  notice_id BIGINT UNSIGNED NOT NULL,
  source_key CHAR(64) NOT NULL,
  remote_doc_id INT NOT NULL,
  requested_by_user_id BIGINT UNSIGNED NOT NULL,
  stage ENUM('awaiting_red','awaiting_records','verification_required','released_verified')
       NOT NULL DEFAULT 'awaiting_red',
  created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (application_id,revision),
  UNIQUE KEY uq_fpoms_pnpki_source (source_key),
  UNIQUE KEY uq_fpoms_pnpki_remote (remote_doc_id),
  CONSTRAINT fk_fpoms_pnpki_app FOREIGN KEY (application_id)
      REFERENCES permit_step2_applications(id) ON DELETE CASCADE,
  CONSTRAINT fk_fpoms_pnpki_notice FOREIGN KEY (notice_id)
      REFERENCES fpoms_payment_notices(id) ON DELETE CASCADE,
  CONSTRAINT fk_fpoms_pnpki_actor FOREIGN KEY (requested_by_user_id)
      REFERENCES users(id) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fpoms_pnpki_nonces (
  nonce CHAR(32) NOT NULL PRIMARY KEY,
  received_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
