<?php
require_once 'config.php';

$pdo = getConnection();
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'GET':
        $sesion = requireAdmin();
        $stmt = $pdo->query('SELECT idUsuario, usuario, nombre, apellido, rol, estado, clave FROM USUARIOS ORDER BY estado, rol, usuario');
        $usuarios = $stmt->fetchAll();
        foreach ($usuarios as &$u) {
            $u['id'] = $u['idUsuario'];
            $u['contrasena'] = $u['clave'];
        }
        jsonOutput($usuarios);
        break;

    case 'POST':
        $sesion = requireAdmin();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = trim($input['id'] ?? '');
        $usuario = trim($input['usuario'] ?? '');
        $nombre = trim($input['nombre'] ?? '');
        $apellido = trim($input['apellido'] ?? '');
        $rol = strtoupper(trim($input['rol'] ?? 'MESERO'));
        $estado = strtoupper(trim($input['estado'] ?? 'ACTIVO'));
        $contrasena = trim($input['contrasena'] ?? '');

        if (empty($id)) {
            if (empty($usuario) || empty($nombre) || empty($contrasena))
                jsonOutput(['exito' => false, 'mensaje' => 'Llena los campos obligatorios (Usuario, Nombre, Contraseña).']);

            // Check duplicate
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM USUARIOS WHERE LOWER(usuario) = LOWER(?)');
            $stmt->execute([$usuario]);
            if ($stmt->fetchColumn() > 0)
                jsonOutput(['exito' => false, 'mensaje' => "Error: El usuario '$usuario' ya existe."]);

            $nuevoId = generarId('USR');
            $hash = password_hash($contrasena, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare('INSERT INTO USUARIOS (idUsuario, usuario, clave, nombre, apellido, rol, estado) VALUES (?, ?, ?, ?, ?, ?, ?)');
            $stmt->execute([$nuevoId, $usuario, $hash, $nombre, $apellido, $rol, $estado]);
            registrarMovimiento($pdo, $sesion['idUsuario'], 'CREAR_USUARIO', $nuevoId, 'Creó usuario: ' . $usuario);
            jsonOutput(['exito' => true, 'mensaje' => 'Usuario creado exitosamente.']);
        } else {
            if (empty($usuario) || empty($nombre))
                jsonOutput(['exito' => false, 'mensaje' => 'Campos obligatorios vacíos.']);

            // Check duplicate excluding self
            $stmt = $pdo->prepare('SELECT COUNT(*) FROM USUARIOS WHERE LOWER(usuario) = LOWER(?) AND idUsuario != ?');
            $stmt->execute([$usuario, $id]);
            if ($stmt->fetchColumn() > 0)
                jsonOutput(['exito' => false, 'mensaje' => "Error: El usuario '$usuario' ya existe."]);

            // Build update query
            $fields = ['usuario = ?', 'nombre = ?', 'apellido = ?', 'rol = ?', 'estado = ?'];
            $params = [$usuario, $nombre, $apellido, $rol, $estado];
            if (!empty($contrasena)) {
                $fields[] = 'clave = ?';
                $params[] = password_hash($contrasena, PASSWORD_BCRYPT);
            }
            $params[] = $id;
            $stmt = $pdo->prepare('UPDATE USUARIOS SET ' . implode(', ', $fields) . ' WHERE idUsuario = ?');
            $stmt->execute($params);
            registrarMovimiento($pdo, $sesion['idUsuario'], 'EDITAR_USUARIO', $id, 'Editó usuario: ' . $usuario);
            jsonOutput(['exito' => true, 'mensaje' => 'Usuario actualizado.']);
        }
        break;
}
