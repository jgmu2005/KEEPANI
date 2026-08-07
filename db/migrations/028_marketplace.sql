-- ============================================================================
--  Migración 028 — MARKETPLACE (tiendas Treinta), 100% AISLADO del tracker.
--  Tablas propias mk_*; NO se mezclan con products/price_history/stores.
--  La página /marketplace y el crawler usan sólo estas tablas.
-- ============================================================================

CREATE TABLE IF NOT EXISTS mk_stores (
    id          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    slug        VARCHAR(80)  NOT NULL,          -- slug Treinta (última parte de la URL)
    name        VARCHAR(160) NOT NULL,          -- se actualiza desde el sitio al crawlear
    url         VARCHAR(255) NOT NULL,          -- URL completa del catálogo (catalogo/ o tienda/)
    whatsapp    VARCHAR(40)  NULL,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1,
    created_at  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_crawl  DATETIME     NULL,
    PRIMARY KEY (id),
    UNIQUE KEY uq_slug (slug)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mk_products (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    store_id    INT UNSIGNED NOT NULL,
    ext_id      VARCHAR(64)  NOT NULL,          -- UUID Treinta (catalogo) o hash del nombre (tienda)
    name        VARCHAR(255) NOT NULL,
    image_url   TEXT         NULL,
    price       DECIMAL(12,2) NULL,             -- precio actual (mínimo si tiene variantes)
    currency    VARCHAR(8)   NOT NULL DEFAULT 'NIO',
    in_stock    TINYINT(1)   NOT NULL DEFAULT 1,
    is_active   TINYINT(1)   NOT NULL DEFAULT 1, -- 0 = ya no aparece en el catálogo
    first_seen  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    last_seen   DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    UNIQUE KEY uq_store_ext (store_id, ext_id),
    KEY idx_store (store_id),
    CONSTRAINT fk_mkp_store FOREIGN KEY (store_id) REFERENCES mk_stores (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS mk_price_history (
    id            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    product_id    BIGINT UNSIGNED NOT NULL,
    captured_date DATE          NOT NULL,
    price         DECIMAL(12,2) NULL,
    in_stock      TINYINT(1)    NOT NULL DEFAULT 1,
    PRIMARY KEY (id),
    UNIQUE KEY uq_prod_day (product_id, captured_date),
    CONSTRAINT fk_mkph_prod FOREIGN KEY (product_id) REFERENCES mk_products (id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Seed inicial (las 10 tiendas dadas). El nombre se refina al primer crawl.
INSERT IGNORE INTO mk_stores (slug, name, url) VALUES
 ('relojesnicaragua',            'Relojes Nicaragua',              'https://catalogo.treinta.co/relojesnicaragua'),
 ('multitiendaonlinemanagua',    'Multi Tienda Online Managua',    'https://tienda.treinta.co/multitiendaonlinemanagua'),
 ('principitosnicaragua',        'Principitos Nicaragua',          'https://catalogo.treinta.co/principitosnicaragua'),
 ('vitaminasysuplementostorres', 'Vitaminas y Suplementos Torres', 'https://catalogo.treinta.co/vitaminasysuplementostorres'),
 ('edsa',                        'EDSA',                           'https://catalogo.treinta.co/edsa'),
 ('comercionica',               'Comercio Nica',                  'https://catalogo.treinta.co/comercionica'),
 ('modahogarnicaragua1',        'Moda Hogar Nicaragua',           'https://catalogo.treinta.co/modahogarnicaragua1'),
 ('tecnoshopnicaragua',         'TecnoShop Nicaragua',            'https://catalogo.treinta.co/tecnoshopnicaragua'),
 ('variedadesmeta',             'Variedades Meta',                'https://catalogo.treinta.co/variedadesmeta'),
 ('rogamaonline',               'Rogama Online',                  'https://catalogo.treinta.co/rogamaonline');
