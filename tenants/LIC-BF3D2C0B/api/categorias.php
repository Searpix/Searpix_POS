<?php
require_once 'config.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $stmt = $pdo->query('SELECT * FROM CATEGORIAS ORDER BY estado, nombre');
        $cats = $stmt->fetchAll();
        foreach ($cats as &$c) {
            $c['id'] = $c['idCategoria'];
            $c['nombre'] = $c['nombre'];
            $c['imagen'] = $c['imagen'];
            $c['estado'] = $c['estado'];
        }
        jsonOutput($cats);
        break;

    case 'POST':
        $sesion = requireAdmin();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = trim($input['id'] ?? '');
        $nombre = trim($input['nombre'] ?? '');
        $imagen = trim($input['imagen'] ?? '');
        $estado = strtoupper(trim($input['estado'] ?? 'ACTIVO'));

        if (empty($id)) {
            $nuevoId = generarId('CAT');
            $stmt = $pdo->prepare('INSERT INTO CATEGORIAS (idCategoria, nombre, imagen, estado) VALUES (?, ?, ?, ?)');
            $stmt->execute([$nuevoId, $nombre, $imagen, $estado]);
            registrarMovimiento($pdo, $sesion['idUsuario'], 'CREAR_CATEGORIA', $nuevoId, 'Creó categoría: ' . $nombre);
            jsonOutput(['exito' => true, 'mensaje' => 'Categoría creada exitosamente.']);
        } else {
            $stmt = $pdo->prepare('UPDATE CATEGORIAS SET nombre=?, imagen=?, estado=? WHERE idCategoria=?');
            $stmt->execute([$nombre, $imagen, $estado, $id]);
            registrarMovimiento($pdo, $sesion['idUsuario'], 'EDITAR_CATEGORIA', $id, 'Editó categoría: ' . $nombre);
            jsonOutput(['exito' => true, 'mensaje' => 'Categoría actualizada.']);
        }
        break;
}
