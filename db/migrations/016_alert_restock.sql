-- ============================================================================
--  Migración 016 — alertas de restock ("avisame cuando vuelva a haber").
--
--  alert_type distingue el tipo de alerta. target_price pasa a NULL-able porque
--  una alerta de restock no tiene precio objetivo.
--  Ejecutar una vez en phpMyAdmin.
-- ============================================================================

ALTER TABLE alerts
  ADD COLUMN alert_type ENUM('price','restock') NOT NULL DEFAULT 'price' AFTER product_id,
  MODIFY COLUMN target_price DECIMAL(12,2) NULL;
