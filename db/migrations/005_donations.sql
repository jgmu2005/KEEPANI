-- ============================================================================
--  Migración 005 — donaciones (webhook de Ko-fi)
--  Ejecutar una vez en phpMyAdmin (pestaña SQL).
-- ============================================================================

CREATE TABLE IF NOT EXISTS donations (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    source           VARCHAR(20)  NOT NULL DEFAULT 'kofi',
    external_id      VARCHAR(100) NOT NULL,          -- message_id de Ko-fi (idempotencia)
    email            VARCHAR(190) NULL,
    from_name        VARCHAR(190) NULL,
    amount           DECIMAL(10,2) NULL,
    currency         VARCHAR(10)  NULL,
    message          TEXT         NULL,
    type             VARCHAR(30)  NULL,              -- Donation | Subscription | Shop Order
    matched_user_id  BIGINT UNSIGNED NULL,           -- a quién se le acreditó (o NULL)
    created_at       DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_donation_ext (source, external_id),
    KEY idx_donation_user (matched_user_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
