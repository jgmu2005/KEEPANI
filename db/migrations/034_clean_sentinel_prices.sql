-- Limpia el precio-centinela (Siman/Sinsa ponen 10.000.000 a lo que aún no tiene
-- precio real). Deja en null esos valores para que no contaminen mínimo/máximo
-- histórico ni generen falsos descuentos "-100%".
UPDATE price_history
   SET price_final = NULL, price_native = NULL
 WHERE price_final >= 1000000;

UPDATE price_history
   SET list_price = NULL, discount_pct = NULL
 WHERE list_price >= 1000000;

-- Precio de lista que quedó por debajo (o igual) del precio final = no es descuento.
UPDATE price_history
   SET list_price = NULL, discount_pct = NULL
 WHERE list_price IS NOT NULL AND price_final IS NOT NULL AND list_price <= price_final;
