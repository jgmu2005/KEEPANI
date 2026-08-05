-- ============================================================================
--  Ojo al Precio — Esquema MySQL (MVP)
--  Charset utf8mb4 en todo. Pensado para MySQL 5.7+/8 o MariaDB 10.2+.
--  Ejecutar una vez sobre la base de datos ya creada en FatCow (cPanel).
-- ============================================================================

SET NAMES utf8mb4;
SET time_zone = '+00:00';   -- guardamos todo en UTC; se formatea al mostrar.

-- ----------------------------------------------------------------------------
--  TIENDAS
--  Catálogo de tiendas soportadas. `tax_included`/`tax_rate` resuelven el caso
--  PriceSmart (precios sin IVA -> +15%). `platform` documenta el adaptador.
-- ----------------------------------------------------------------------------
CREATE TABLE stores (
    id            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug          VARCHAR(40)  NOT NULL,               -- 'sinsa', 'siman', 'copasa'
    name          VARCHAR(120) NOT NULL,
    base_url      VARCHAR(255) NOT NULL,
    platform      VARCHAR(30)  NOT NULL DEFAULT 'custom', -- 'vtex'|'og_meta'|'magento'|...
    currency      CHAR(3)      NOT NULL DEFAULT 'NIO',
    tax_included  TINYINT(1)   NOT NULL DEFAULT 1,
    tax_rate      DECIMAL(5,4) NOT NULL DEFAULT 0.1500,
    is_active     TINYINT(1)   NOT NULL DEFAULT 1,
    created_at    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_stores_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  PRODUCTOS
--  Un producto por tienda, identificado por su SKU nativo. Los metadatos
--  (título/marca/imagen) se "enriquecen" al registrar y se refrescan de vez
--  en cuando; NO en cada captura diaria.
-- ----------------------------------------------------------------------------
CREATE TABLE products (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    store_id       INT UNSIGNED    NOT NULL,
    external_sku   VARCHAR(120)    NOT NULL,           -- SKU/productId en la tienda
    url            VARCHAR(500)    NOT NULL,
    title          VARCHAR(300)    NULL,
    brand          VARCHAR(120)    NULL,
    image_url      VARCHAR(500)    NULL,
    is_active      TINYINT(1)      NOT NULL DEFAULT 1, -- se sigue trackeando
    first_seen_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen_at   DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_products_store_sku (store_id, external_sku),
    KEY idx_products_store (store_id),
    CONSTRAINT fk_products_store FOREIGN KEY (store_id)
        REFERENCES stores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  HISTÓRICO DE PRECIOS  (append-only, el corazón del sistema)
--  - price_native : precio EXACTO como lo publica la tienda (fuente de verdad).
--  - price_final  : comparable CON IVA (native + IVA si la tienda no lo incluye).
--  - price_usd    : normalizado a USD con el tipo de cambio de la fecha; nullable
--                   hasta que exista el rate (se rellena en la conversión).
--  Índice único (product_id, captured_date) evita duplicar si el cron corre 2x.
-- ----------------------------------------------------------------------------
CREATE TABLE price_history (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id     BIGINT UNSIGNED NOT NULL,
    captured_date  DATE            NOT NULL,           -- día de la captura (UTC)
    captured_at    DATETIME        NOT NULL,           -- timestamp exacto
    price_native   DECIMAL(12,2)   NULL,
    price_final    DECIMAL(12,2)   NULL,
    price_usd      DECIMAL(12,2)   NULL,
    list_price     DECIMAL(12,2)   NULL,
    discount_pct   DECIMAL(5,2)    NULL,
    currency       CHAR(3)         NOT NULL DEFAULT 'NIO',
    in_stock       TINYINT(1)      NOT NULL DEFAULT 0,
    PRIMARY KEY (id),
    UNIQUE KEY uq_price_product_day (product_id, captured_date),
    KEY idx_price_product_time (product_id, captured_at),
    CONSTRAINT fk_price_product FOREIGN KEY (product_id)
        REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  TIPO DE CAMBIO  (una fila por día; fuente BCN)
--  Se usa para convertir price_native/price_final a USD con la tasa de la
--  fecha de captura (no la de hoy), porque el córdoba tiene crawling peg.
-- ----------------------------------------------------------------------------
CREATE TABLE exchange_rates (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    rate_date   DATE         NOT NULL,
    usd_to_nio  DECIMAL(10,4) NOT NULL,                -- 1 USD = X NIO
    source      VARCHAR(40)  NOT NULL DEFAULT 'BCN',
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_rate_date (rate_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  USUARIOS + ALERTAS + NOTIFICACIONES
--  Ver gráficos = público. Crear alertas = requiere registro.
-- ----------------------------------------------------------------------------
CREATE TABLE users (
    id             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    email          VARCHAR(190)    NOT NULL,
    password_hash  VARCHAR(255)    NOT NULL,
    is_verified    TINYINT(1)      NOT NULL DEFAULT 0,
    created_at     DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE alerts (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    user_id          BIGINT UNSIGNED NOT NULL,
    product_id       BIGINT UNSIGNED NOT NULL,
    target_price     DECIMAL(12,2)   NOT NULL,         -- disparar cuando price_final <= esto
    target_currency  CHAR(3)         NOT NULL DEFAULT 'NIO',
    is_active        TINYINT(1)      NOT NULL DEFAULT 1,
    last_triggered_at DATETIME       NULL,
    created_at       DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_alerts_user (user_id),
    KEY idx_alerts_product (product_id),
    CONSTRAINT fk_alerts_user FOREIGN KEY (user_id)
        REFERENCES users (id) ON DELETE CASCADE,
    CONSTRAINT fk_alerts_product FOREIGN KEY (product_id)
        REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notifications (
    id               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    alert_id         BIGINT UNSIGNED NOT NULL,
    price_at_trigger DECIMAL(12,2)   NOT NULL,
    channel          VARCHAR(20)     NOT NULL DEFAULT 'email',
    sent_at          DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    KEY idx_notif_alert (alert_id),
    CONSTRAINT fk_notif_alert FOREIGN KEY (alert_id)
        REFERENCES alerts (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  COMPARADOR CROSS-STORE  (nice-to-have; se llena en fase posterior)
-- ----------------------------------------------------------------------------
CREATE TABLE product_matches (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_a_id  BIGINT UNSIGNED NOT NULL,
    product_b_id  BIGINT UNSIGNED NOT NULL,
    confidence    DECIMAL(4,3)    NOT NULL DEFAULT 0.000,  -- 0..1
    match_method  VARCHAR(30)     NOT NULL DEFAULT 'manual',
    created_at    DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_match_pair (product_a_id, product_b_id),
    CONSTRAINT fk_match_a FOREIGN KEY (product_a_id)
        REFERENCES products (id) ON DELETE CASCADE,
    CONSTRAINT fk_match_b FOREIGN KEY (product_b_id)
        REFERENCES products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  CURSOR DEL SEED MASIVO (crawler VTEX) — avance "poco a poco" por tienda
-- ----------------------------------------------------------------------------
CREATE TABLE crawl_cursors (
    store_slug  VARCHAR(40)  NOT NULL,
    next_from   INT UNSIGNED NOT NULL DEFAULT 0,
    total_seen  INT UNSIGNED NOT NULL DEFAULT 0,
    done        TINYINT(1)   NOT NULL DEFAULT 0,
    updated_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (store_slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ----------------------------------------------------------------------------
--  SEED de las 3 tiendas de la Fase 1
-- ----------------------------------------------------------------------------
INSERT INTO stores (slug, name, base_url, platform, currency, tax_included, tax_rate) VALUES
    ('sinsa',  'Sinsa',           'https://www.sinsa.com.ni', 'vtex',    'NIO', 1, 0.1500),
    ('siman',  'Siman Nicaragua', 'https://ni.siman.com',     'vtex',    'NIO', 1, 0.1500),
    ('copasa', 'Copasa',          'https://www.copasa.com.ni','og_meta', 'NIO', 1, 0.1500);
