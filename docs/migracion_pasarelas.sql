-- ============================================================
-- Migración: soporte de pasarelas (Wompi / PayPal)
-- Ejecutar UNA sola vez sobre una base de datos YA existente.
-- NO borra datos. En phpMyAdmin: selecciona freakers_licenses > SQL > pega esto.
-- ============================================================
USE freakers_licenses;

-- Añadir 'PAYPAL' a los métodos de pago permitidos
ALTER TABLE PAGOS
  MODIFY metodo ENUM('TRANSFERENCIA','NEQUI','DAVIPLATA','TARJETA','EFECTIVO','WOMPI','PAYU','PAYPAL')
  NOT NULL DEFAULT 'TRANSFERENCIA';

-- Guardar la referencia/ID de transacción de la pasarela
-- (si ya existe la columna, ignora el error)
ALTER TABLE PAGOS
  ADD COLUMN gateway_ref VARCHAR(255) DEFAULT NULL AFTER referencia;
