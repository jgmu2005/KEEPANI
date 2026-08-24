-- DENORMALIZACIÓN del precio/stock ACTUAL en products (Fase A).
-- Hoy cada listado (comparador, ofertas, admin) calcula el "último precio" buscando
-- en price_history la fila más reciente de cada producto — lento a escala. Guardamos
-- una copia lista en products, actualizada al ingestar (como el saldo de una cuenta).
-- Fase A solo agrega/pobla las columnas; las queries se cambian en la Fase B.
ALTER TABLE products
    ADD COLUMN last_price    DECIMAL(12,2) NULL,
    ADD COLUMN last_list     DECIMAL(12,2) NULL,
    ADD COLUMN last_in_stock TINYINT       NULL,
    ADD COLUMN last_currency VARCHAR(8)    NULL,
    ADD COLUMN last_date     DATE          NULL;

-- Índice para los listados: activos, en stock y con precio.
ALTER TABLE products ADD KEY idx_last_stock (is_active, last_in_stock, last_price);
