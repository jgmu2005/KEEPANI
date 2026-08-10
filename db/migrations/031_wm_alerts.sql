-- Alertas de liquidaciones Walmart por email — SOLO suscriptores mensuales (perk Premium).
-- users.wm_alerts: preferencia (por defecto ON; el suscriptor puede darse de baja).
-- wm_drops.notified_at: marca para no re-enviar la misma baja.
ALTER TABLE users    ADD COLUMN wm_alerts TINYINT(1) NOT NULL DEFAULT 1;
ALTER TABLE wm_drops ADD COLUMN notified_at DATETIME NULL;
CREATE INDEX idx_wm_notified ON wm_drops (notified_at);
