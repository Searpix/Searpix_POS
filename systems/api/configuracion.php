<?php
/* ============================================
   FREAKERS POS v6 - Configuration API
   Actions: obtener (GET), actualizar (POST)
   ============================================ */

require_once 'config.php';

try {
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET') {
        $sesion = requireAuth();
        $pdo = getConnection();
        
        $stmt = $pdo->query('SELECT parametro, valor FROM CONFIGURACION ORDER BY parametro');
        $rows = $stmt->fetchAll();
        
        $config = [];
        foreach ($rows as $row) {
            $config[$row['parametro']] = $row['valor'];
        }
        
        jsonOutput(['exito' => true, 'config' => $config]);
    }

    if ($method === 'POST') {
        $sesion = requireAdmin();
        $pdo = getConnection();
        
        $input = json_decode(file_get_contents('php://input'), true);
        $action = trim($input['action'] ?? '');
        
        if ($action === 'actualizar') {
            $params = $input['parametros'] ?? [];
            $updated = 0;
            
            foreach ($params as $key => $value) {
                if (setConfig($pdo, $key, $value)) $updated++;
            }
            
            registrarMovimiento($pdo, $sesion['idUsuario'], 'ACTUALIZAR_CONFIG', null, "Configuracion actualizada: $updated parametros");
            jsonOutput(['exito' => true, 'mensaje' => "Configuracion actualizada ($updated parametros)", 'updated' => $updated]);
        }
        
        jsonOutput(['exito' => false, 'mensaje' => 'Accion no valida.']);
    }

    jsonOutput(['exito' => false, 'mensaje' => 'Metodo no permitido.']);

} catch (Exception $e) {
    error_log('[Comandix][pos-config] ' . $e->getMessage());
    jsonOutput(['exito' => false, 'mensaje' => 'Error del servidor.']);
}
