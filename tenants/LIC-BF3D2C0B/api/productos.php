<?php
require_once 'config.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $action = $_GET['action'] ?? 'all';
        if ($action === 'menu') {
            $stmt = $pdo->query('SELECT idCategoria, nombre, imagen FROM CATEGORIAS WHERE estado="ACTIVO" ORDER BY nombre');
            $categorias = $stmt->fetchAll();
            $stmt = $pdo->query('SELECT idProducto, idCategoria, nombre, precio, imagen FROM PRODUCTOS WHERE estado="ACTIVO" ORDER BY nombre');
            $productos = $stmt->fetchAll();
            foreach ($productos as &$p) { $p['precio'] = (float)$p['precio']; }
            jsonOutput(['categorias' => $categorias, 'productos' => $productos]);
        } else {
            // FIX: Support filter params buscar, idCategoria, estado
            $where = '1=1';
            $params = [];
            $buscar = trim($_GET['buscar'] ?? '');
            $idCategoria = trim($_GET['idCategoria'] ?? '');
            $estado = trim($_GET['estado'] ?? '');
            if ($buscar) { $where .= ' AND p.nombre LIKE ?'; $params[] = '%' . $buscar . '%'; }
            if ($idCategoria) { $where .= ' AND p.idCategoria = ?'; $params[] = $idCategoria; }
            if ($estado) { $where .= ' AND p.estado = ?'; $params[] = $estado; }
            $sql = 'SELECT p.*, c.nombre as categoriaNombre FROM PRODUCTOS p LEFT JOIN CATEGORIAS c ON p.idCategoria = c.idCategoria WHERE ' . $where . ' ORDER BY p.estado, p.nombre';
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $productos = $stmt->fetchAll();
            foreach ($productos as &$p) {
                $p['id'] = $p['idProducto'];
                $p['categoria'] = $p['idCategoria'];
                $p['precio'] = (float)$p['precio'];
            }
            jsonOutput($productos);
        }
        break;

    case 'POST':
        $sesion = requireAdmin();
        $input = json_decode(file_get_contents('php://input'), true);
        $action = trim($input['action'] ?? '');

        // FIX: Add eliminar action (frontend sends POST not DELETE)
        if ($action === 'eliminar') {
            $idProducto = trim($input['idProducto'] ?? '');
            if (empty($idProducto)) jsonOutput(['exito' => false, 'mensaje' => 'ID de producto requerido.']);
            $stmt = $pdo->prepare('DELETE FROM PRODUCTOS WHERE idProducto = ?');
            $stmt->execute([$idProducto]);
            registrarMovimiento($pdo, $sesion['idUsuario'], 'ELIMINAR_PRODUCTO', $idProducto, 'Elimino producto: ' . $idProducto);
            jsonOutput(['exito' => true, 'mensaje' => 'Producto eliminado.']);
        }

        // FIX: Accept idProducto OR id for save/update
        $id = trim($input['id'] ?? '') ?: trim($input['idProducto'] ?? '');
        $idCategoria = trim($input['idCategoria'] ?? '');
        $nombre = trim($input['nombre'] ?? '');
        $precio = (float)($input['precio'] ?? 0);
        $estado = strtoupper(trim($input['estado'] ?? 'ACTIVO'));
        $imagen = trim($input['imagen'] ?? '');

        if (empty($nombre)) {
            jsonOutput(['exito' => false, 'mensaje' => 'El nombre del producto es obligatorio.']);
        }
        if (empty($idCategoria)) {
            jsonOutput(['exito' => false, 'mensaje' => 'Debes seleccionar una categoria. Si no ves opciones, crea una primero en Categorias.']);
        }
        $stmt = $pdo->prepare('SELECT idCategoria FROM CATEGORIAS WHERE idCategoria = ?');
        $stmt->execute([$idCategoria]);
        if (!$stmt->fetch()) {
            jsonOutput(['exito' => false, 'mensaje' => 'La categoria seleccionada no existe. Verifica en Categorias.']);
        }
        if ($precio <= 0) {
            jsonOutput(['exito' => false, 'mensaje' => 'El precio debe ser mayor a $0.']);
        }

        if (empty($imagen)) {
            $imagen = '';
        }

        if (empty($id)) {
            $nuevoId = generarId('PROD');
            $stmt = $pdo->prepare('INSERT INTO PRODUCTOS (idProducto, idCategoria, nombre, precio, imagen, estado) VALUES (?, ?, ?, ?, ?, ?)');
            $ok = $stmt->execute([$nuevoId, $idCategoria, $nombre, $precio, $imagen, $estado]);
            if (!$ok) {
                $errInfo = $stmt->errorInfo();
                jsonOutput(['exito' => false, 'mensaje' => 'Error al crear producto: ' . $errInfo[2]]);
            }
            registrarMovimiento($pdo, $sesion['idUsuario'], 'CREAR_PRODUCTO', $nuevoId, 'Creo producto: ' . $nombre);
            jsonOutput(['exito' => true, 'mensaje' => 'Producto creado exitosamente.']);
        } else {
            $stmt = $pdo->prepare('UPDATE PRODUCTOS SET idCategoria=?, nombre=?, precio=?, imagen=?, estado=? WHERE idProducto=?');
            $ok = $stmt->execute([$idCategoria, $nombre, $precio, $imagen, $estado, $id]);
            if (!$ok) {
                $errInfo = $stmt->errorInfo();
                jsonOutput(['exito' => false, 'mensaje' => 'Error al actualizar producto: ' . $errInfo[2]]);
            }
            registrarMovimiento($pdo, $sesion['idUsuario'], 'EDITAR_PRODUCTO', $id, 'Edito producto: ' . $nombre);
            jsonOutput(['exito' => true, 'mensaje' => 'Producto actualizado.']);
        }
        break;
}