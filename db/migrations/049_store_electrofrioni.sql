-- ============================================================================
--  Migración 049 — alta de Electro Frío (electrofrioni.com).
--  Tienda de aires acondicionados / refrigeración (Managua). WooCommerce con
--  Store API abierta (sin Cloudflare). Se crawlea con crawl_woocommerce.php
--  dentro de 'all' (~232 productos). OJO: su WooCommerce está en USD (la Store API
--  reporta currency_code USD) → producto.php lo convierte a C$ con el usd_rate.
--  Ejecutar una vez en phpMyAdmin/Adminer.
-- ============================================================================

INSERT IGNORE INTO stores (slug, name, base_url, platform, currency, tax_included, tax_rate)
VALUES
  ('electrofrioni', 'Electro Frío', 'https://electrofrioni.com', 'woocommerce', 'USD', 1, 0.1500);
-- Si la fila ya existía (se insertó antes como NIO), forzamos USD:
UPDATE stores SET currency = 'USD' WHERE slug = 'electrofrioni';
