<?php
require_once 'config.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        // obtenerMesas / obtenerTodasLasMesas
        $cols_raw = $pdo->query("SHOW COLUMNS FROM MESAS")->fetchAll(PDO::FETCH_COLUMN);
        $cols = array_map('strtolower', $cols_raw);
        $select = 'idMesa, numero, capacidad, estado';
        if (in_array('clase', $cols)) $select .= ', clase';
        $stmt = $pdo->query('SELECT ' . $select . ' FROM MESAS ORDER BY FIELD(estado, "OCUPADA","LIBRE","INACTIVA"), numero+0, numero');
        $mesas = $stmt->fetchAll();
        foreach ($mesas as &$m) {
            $m['id'] = $m['idMesa'];
            $m['capacidad'] = (int)$m['capacidad'];
            $m['clase'] = isset($m['clase']) ? $m['clase'] : 'MESAS';
        }
        jsonOutput($mesas);
        break;

    case 'POST':
        $sesion = requireAdmin();
        $input = json_decode(file_get_contents('php://input'), true);
        $action = trim($input['action'] ?? '');

        // FIX: Add eliminar action (frontend sends POST not DELETE)
        if ($action === 'eliminar') {
            $id = trim($input['id'] ?? '');
            if (empty($id)) jsonOutput(['exito' => false, 'mensaje' => 'ID de mesa requerido.']);
            $stmt = $pdo->prepare('SELECT estado FROM MESAS WHERE idMesa = ?');
            $stmt->execute([$id]);
            $mesa = $stmt->fetch();
            if (!$mesa) jsonOutput(['exito' => false, 'mensaje' => 'Mesa no encontrada.']);
            if ($mesa['estado'] === 'OCUPADA') jsonOutput(['exito' => false, 'mensaje' => 'No puedes eliminar una mesa ocupada.']);
            $stmt = $pdo->prepare('DELETE FROM MESAS WHERE idMesa = ?');
            $stmt->execute([$id]);
            registrarMovimiento($pdo, $sesion['idUsuario'], 'ELIMINAR_MESA', $id, 'Elimino mesa del sistema');
            jsonOutput(['exito' => true, 'mensaje' => 'Mesa eliminada exitosamente.']);
        }

        $id = trim($input['id'] ?? '');
        $numero = trim($input['numero'] ?? '');
        $capacidad = (int)($input['capacidad'] ?? 4);
        $estado = strtoupper(trim($input['estado'] ?? 'LIBRE'));
        $clase = strtoupper(trim($input['clase'] ?? 'MESAS'));
        // Validate clase
        if (!in_array($clase, ['BARRA','VIP','MESAS'])) $clase = 'MESAS';

        // Check if clase column exists
        $cols_raw = $pdo->query("SHOW COLUMNS FROM MESAS")->fetchAll(PDO::FETCH_COLUMN);
        $cols = array_map('strtolower', $cols_raw);
        $hasClase = in_array('clase', $cols);

        if (empty($id)) {
            // Generate idMesa based on clase prefix
            $prefixMap = ['BARRA' => 'BARRA', 'VIP' => 'VIP', 'MESAS' => 'M'];
            $prefix = isset($prefixMap[$clase]) ? $prefixMap[$clase] : 'M';
            $nuevoId = $prefix . $numero;
            if ($hasClase) {
                $stmt = $pdo->prepare('INSERT INTO MESAS (idMesa, numero, capacidad, estado, clase) VALUES (?, ?, ?, ?, ?)');
                $stmt->execute([$nuevoId, $numero, $capacidad, $estado, $clase]);
            } else {
                $stmt = $pdo->prepare('INSERT INTO MESAS (idMesa, numero, capacidad, estado) VALUES (?, ?, ?, ?)');
                $stmt->execute([$nuevoId, $numero, $capacidad, $estado]);
            }
            registrarMovimiento($pdo, $sesion['idUsuario'], 'CREAR_MESA', $nuevoId, 'Creo mesa ' . $numero . ' (' . $clase . ')');
            jsonOutput(['exito' => true, 'mensaje' => 'Mesa creada exitosamente.']);
        } else {
            if ($hasClase) {
                $stmt = $pdo->prepare('UPDATE MESAS SET numero=?, capacidad=?, estado=?, clase=? WHERE idMesa=?');
                $stmt->execute([$numero, $capacidad, $estado, $clase, $id]);
            } else {
                $stmt = $pdo->prepare('UPDATE MESAS SET numero=?, capacidad=?, estado=? WHERE idMesa=?');
                $stmt->execute([$numero, $capacidad, $estado, $id]);
            }
            jsonOutput(['exito' => true, 'mensaje' => 'Mesa actualizada.']);
        }
        break;

    case 'DELETE':
        $sesion = requireAdmin();
        $id = $_GET['id'] ?? '';
        if (empty($id)) jsonOutput(['exito' => false, 'mensaje' => 'ID de mesa requerido.']);

        $stmt = $pdo->prepare('SELECT estado FROM MESAS WHERE idMesa = ?');
        $stmt->execute([$id]);
        $mesa = $stmt->fetch();
        if (!$mesa) jsonOutput(['exito' => false, 'mensaje' => 'Mesa no encontrada.']);
        if ($mesa['estado'] === 'OCUPADA') jsonOutput(['exito' => false, 'mensaje' => 'No puedes eliminar una mesa ocupada.']);

        $stmt = $pdo->prepare('DELETE FROM MESAS WHERE idMesa = ?');
        $stmt->execute([$id]);
        registrarMovimiento($pdo, $sesion['idUsuario'], 'ELIMINAR_MESA', $id, 'Eliminó la mesa del sistema');
        jsonOutput(['exito' => true, 'mensaje' => 'Mesa eliminada exitosamente.']);
        break;
}
