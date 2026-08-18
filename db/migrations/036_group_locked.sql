-- Candado para asignaciones MANUALES de comparativo. Cuando un admin agrega o
-- quita un producto de un grupo desde producto.php, se marca group_locked = 1 y
-- los crons de agrupación automática (build_groups, build_phone_groups) lo IGNORAN,
-- para que la decisión manual no se sobreescriba en la próxima corrida.
ALTER TABLE products
    ADD COLUMN group_locked TINYINT NOT NULL DEFAULT 0 AFTER group_id;

-- Índice chico para filtrar rápido los bloqueados en los matchers.
ALTER TABLE products ADD KEY idx_group_locked (group_locked);
