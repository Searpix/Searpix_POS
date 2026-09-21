<?php
/* ============================================
   LICENSE SERVER — Conexión a Base de Datos
   Las credenciales viven en /config/config.php
   ============================================ */

require_once __DIR__ . '/../config/config.php';

function lsConnection() {
    static $pdo = null;
    if ($pdo === null) {
        try {
            $pdo = new PDO(
                'mysql:host=' . LS_DB_HOST . ';dbname=' . LS_DB_NAME . ';charset=utf8mb4',
                LS_DB_USER,
                LS_DB_PASS,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );
            $pdo->exec("SET time_zone = '-05:00'");
        } catch (PDOException $e) {
            // No exponer detalles internos (host, nombre de BD, driver) al cliente.
            error_log('[Comandix][DB] ' . $e->getMessage());
            http_response_code(500);
            echo json_encode([
                'exito' => false,
                'mensaje' => 'No se pudo conectar con la base de datos. Intenta mas tarde.'
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
    return $pdo;
}
