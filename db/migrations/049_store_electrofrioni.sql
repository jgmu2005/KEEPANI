-- ============================================================================
--  Migración 049 — alta de Electro Frío (electrofrioni.com).
--  Tienda de aires acondicionados / refrigeración (Managua). WooCommerce con
--  Store API abierta (sin Cloudflare). Se crawlea con crawl_woocommerce.php
--  dentro de 'all' (~232 productos). Precios en C$ con IVA incluido.
--  Ejecutar una vez en phpMyAdmin/Adminer.
-- ============================================================================

INSERT IGNORE INTO stores (slug, name, base_url, platform, currency, tax_included, tax_rate)
VALUES
  ('electrofrioni', 'Electro Frío', 'https://electrofrioni.com', 'woocommerce', 'NIO', 1, 0.1500);
