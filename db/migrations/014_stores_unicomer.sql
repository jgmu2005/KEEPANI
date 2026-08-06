-- ============================================================================
--  Migración 014 — alta de tiendas Unicomer (Magento, adaptador OG por URL)
--  La Curacao, RadioShack, Almacenes Tropigas — Nicaragua.
--  Ejecutar una vez en phpMyAdmin.
-- ============================================================================

INSERT IGNORE INTO stores (slug, name, base_url, platform, currency, tax_included, tax_rate)
VALUES
  ('lacuracao',  'La Curacao',         'https://www.lacuracaonline.com',    'og_meta', 'NIO', 1, 0.1500),
  ('radioshack', 'RadioShack',         'https://www.radioshackla.com',      'og_meta', 'NIO', 1, 0.1500),
  ('tropigas',   'Almacenes Tropigas', 'https://www.almacenestropigas.com', 'og_meta', 'NIO', 1, 0.1500);
