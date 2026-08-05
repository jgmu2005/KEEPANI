-- ============================================================================
--  Migración 006 — eventos para rate limiting (anti-abuso)
--  Ejecutar una vez en phpMyAdmin (pestaña SQL).
-- ============================================================================

CREATE TABLE IF NOT EXISTS rate_events (
    id         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    ip         VARCHAR(45)  NOT NULL,          -- IPv4/IPv6
    action     VARCHAR(30)  NOT NULL,          -- 'register' | 'login' | 'recover' | 'track'
    created_at DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_rate (ip, action, created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
