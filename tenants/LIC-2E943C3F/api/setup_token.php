<?php
/* ============================================
   TOKEN DE INSTALACION / SETUP
   ------------------------------------------------------------
   Cambia el valor de abajo por una cadena larga y aleatoria ANTES
   de ejecutar setup.php / factory_reset.php / fix_fk_constraint.php.
   Mientras siga siendo 'CAMBIA_ESTE_TOKEN', esos scripts quedaran
   BLOQUEADOS por seguridad.

   Ejemplo de uso una vez configurado:
     http://localhost/searpox/api/setup.php?token=TU_TOKEN_SECRETO
   ============================================ */
if (!defined('SETUP_TOKEN')) {
    define('SETUP_TOKEN', getenv('SETUP_TOKEN') ?: '');
}
