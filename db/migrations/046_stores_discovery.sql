-- ============================================================================
--  Migración 046 — alta de 5 tiendas nuevas encontradas con el descubridor
--  (discover_stores.php sobre dominios .ni de Common Crawl). Todas WooCommerce,
--  NIO, precios con IVA. Verificado que publican precio real (no "consultar").
--
--    gcm ............... electrodomésticos / celulares (hcdn)
--    casadelaslamparas . iluminación / lámparas (nginx)
--    fogel ............. aires acondicionados, refrigeradoras (nginx)
--    telcmax ........... telefonía / tecnología (WAF → crawl server-side)
--    fetesa ............ ferretería / hogar (Cloudflare → crawl server-side)
--    cubitt ............ wearables / accesorios tech (Shopify)
--
--  gcm/casadelaslamparas/fogel (WooCommerce sin WAF) los crawlea GitHub Actions
--  (crawl_woocommerce.php all); cubitt vía crawl_shopify.php all.
--  fetesa y telcmax bloquean las IPs de Actions con 403, así que se crawlean
--  desde FatCow con cron/crawl_woo_server.php?store=fetesa  y  ?store=telcmax.
--  Ejecutar una vez en phpMyAdmin/Adminer.
-- ============================================================================

INSERT IGNORE INTO stores (slug, name, base_url, platform, currency, tax_included, tax_rate)
VALUES
  ('gcm',              'GCM',                'https://gcm.com.ni',              'woocommerce', 'NIO', 1, 0.1500),
  ('casadelaslamparas','Casa de las Lámparas','https://casadelaslamparas.com.ni','woocommerce', 'NIO', 1, 0.1500),
  ('fogel',            'Fogel',              'https://fogel.com.ni',            'woocommerce', 'NIO', 1, 0.1500),
  ('telcmax',          'TelcMax',            'https://telcmax.com',             'woocommerce', 'NIO', 1, 0.1500),
  ('fetesa',           'Fetesa',             'https://fetesa.com.ni',           'woocommerce', 'NIO', 1, 0.1500),
  ('cubitt',           'Cubitt',             'https://cubitt.com.ni',           'shopify',     'NIO', 1, 0.1500);
