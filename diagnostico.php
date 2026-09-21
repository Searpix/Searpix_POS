<?php
/**
 * ============================================================
 * SEARPIX / FREAKERS
 * DIAGNÓSTICO DEL SERVIDOR
 * ============================================================
 *
 * Uso:
 *   http://localhost/diagnostico.php
 *
 * Este archivo NO modifica la base de datos.
 *
 * Comprueba:
 *   - PHP
 *   - extensiones
 *   - archivos principales
 *   - .env
 *   - conexión MySQL
 *   - base central
 *   - tablas principales
 *   - carpeta tenants
 *   - configuración multi-tenant
 *   - permisos básicos
 * ============================================================
 */

declare(strict_types=1);

header('Content-Type: text/html; charset=UTF-8');

$root = __DIR__;

$ok = [];
$warning = [];
$error = [];

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function checkOk(
    string $nombre,
    string $detalle,
    array &$ok
): void {
    $ok[] = [
        'nombre' => $nombre,
        'detalle' => $detalle
    ];
}

function checkWarning(
    string $nombre,
    string $detalle,
    array &$warning
): void {
    $warning[] = [
        'nombre' => $nombre,
        'detalle' => $detalle
    ];
}

function checkError(
    string $nombre,
    string $detalle,
    array &$error
): void {
    $error[] = [
        'nombre' => $nombre,
        'detalle' => $detalle
    ];
}

/*
 * ============================================================
 * 1. PHP
 * ============================================================
 */

if (PHP_VERSION_ID >= 80000) {
    checkOk(
        'PHP',
        'Versión ' . PHP_VERSION,
        $ok
    );
} else {
    checkError(
        'PHP',
        'Se requiere PHP 8.0 o superior. Actual: ' . PHP_VERSION,
        $error
    );
}

/*
 * ============================================================
 * 2. EXTENSIONES
 * ============================================================
 */

$extensiones = [
    'pdo' => 'PDO',
    'pdo_mysql' => 'PDO MySQL',
    'mbstring' => 'mbstring',
    'openssl' => 'OpenSSL',
    'json' => 'JSON',
    'fileinfo' => 'Fileinfo'
];

foreach ($extensiones as $extension => $nombre) {

    if (extension_loaded($extension)) {

        checkOk(
            'Extensión ' . $nombre,
            'Disponible',
            $ok
        );

    } else {

        checkError(
            'Extensión ' . $nombre,
            'No está habilitada',
            $error
        );
    }
}

/*
 * ============================================================
 * 3. ARCHIVOS PRINCIPALES
 * ============================================================
 */

$archivos = [
    '.env',
    '.htaccess',
    'index.html',
    'config/config.php',
    'api/db_config.php',
    'api/auth.php',
    'api/licenses.php',
    'api/provisioner.php',
    'api/tickets.php',
    'api/setup.php',
    'systems/index.html',
    'systems/login.html',
    'systems/api/auth.php',
    'tenants/.htaccess'
];

foreach ($archivos as $archivo) {

    $path = $root . DIRECTORY_SEPARATOR .
        str_replace('/', DIRECTORY_SEPARATOR, $archivo);

    if (is_file($path)) {

        checkOk(
            'Archivo ' . $archivo,
            'Encontrado',
            $ok
        );

    } else {

        checkError(
            'Archivo ' . $archivo,
            'No encontrado',
            $error
        );
    }
}

/*
 * ============================================================
 * 4. .ENV
 * ============================================================
 */

$envFile = $root . '/.env';

$env = [];

if (is_file($envFile)) {

    $lineas = file(
        $envFile,
        FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES
    );

    foreach ($lineas as $linea) {

        $linea = trim($linea);

        if (
            $linea === '' ||
            str_starts_with($linea, '#') ||
            !str_contains($linea, '=')
        ) {
            continue;
        }

        [$clave, $valor] = explode('=', $linea, 2);

        $env[trim($clave)] = trim($valor);
    }

    checkOk(
        '.env',
        'Archivo encontrado',
        $ok
    );

} else {

    checkError(
        '.env',
        'No existe. Ejecuta setup.php.',
        $error
    );
}

/*
 * ============================================================
 * 5. VARIABLES IMPORTANTES
 * ============================================================
 */

$variablesRequeridas = [
    'LS_DB_HOST',
    'LS_DB_NAME',
    'LS_DB_USER',
    'LS_TENANT_DB_PREFIX',
    'LS_TENANT_TEMPLATE_DB',
    'LS_TENANT_STORAGE',
    'LS_TENANT_DB_HOST',
    'LS_TENANT_DB_ADMIN_USER',
    'LS_TENANT_DB_USER_PREFIX',
    'LS_BASE_URL',
    'LS_TENANT_BASE_URL'
];

foreach ($variablesRequeridas as $variable) {

    if (
        isset($env[$variable]) &&
        $env[$variable] !== ''
    ) {

        checkOk(
            'Configuración ' . $variable,
            'Configurada',
            $ok
        );

    } else {

        checkError(
            'Configuración ' . $variable,
            'No configurada',
            $error
        );
    }
}

/*
 * ============================================================
 * 6. SECRET
 * ============================================================
 */

if (
    isset($env['LS_SECRET']) &&
    strlen($env['LS_SECRET']) >= 32
) {

    checkOk(
        'LS_SECRET',
        'Configurado correctamente',
        $ok
    );

} else {

    checkError(
        'LS_SECRET',
        'No existe o es demasiado corto',
        $error
    );
}

/*
 * ============================================================
 * 7. MYSQL
 * ============================================================
 */

$pdo = null;

if (
    extension_loaded('pdo_mysql') &&
    !empty($env['LS_DB_HOST']) &&
    isset($env['LS_DB_USER'])
) {

    try {

        $dsn =
            'mysql:host=' .
            $env['LS_DB_HOST'] .
            ';charset=utf8mb4';

        $pdo = new PDO(
            $dsn,
            $env['LS_DB_USER'],
            $env['LS_DB_PASS'] ?? '',
            [
                PDO::ATTR_ERRMODE =>
                    PDO::ERRMODE_EXCEPTION,

                PDO::ATTR_DEFAULT_FETCH_MODE =>
                    PDO::FETCH_ASSOC,

                PDO::ATTR_EMULATE_PREPARES =>
                    false
            ]
        );

        checkOk(
            'MySQL',
            'Conexión exitosa',
            $ok
        );

    } catch (Throwable $e) {

        checkError(
            'MySQL',
            'No se pudo establecer conexión',
            $error
        );
    }
}

/*
 * ============================================================
 * 8. BASE CENTRAL
 * ============================================================
 */

if ($pdo && !empty($env['LS_DB_NAME'])) {

    try {

        $dbName = $env['LS_DB_NAME'];

        if (
            !preg_match(
                '/^[A-Za-z0-9_]+$/',
                $dbName
            )
        ) {

            throw new RuntimeException(
                'Nombre de BD inválido.'
            );
        }

        $stmt = $pdo->query(
            "SELECT SCHEMA_NAME
             FROM INFORMATION_SCHEMA.SCHEMATA
             WHERE SCHEMA_NAME = " .
            $pdo->quote($dbName)
        );

        $databaseExists = (bool)$stmt->fetchColumn();

        if ($databaseExists) {

            checkOk(
                'Base central',
                $dbName . ' existe',
                $ok
            );

            $pdo->exec(
                'USE `' .
                str_replace('`', '``', $dbName) .
                '`'
            );

        } else {

            checkError(
                'Base central',
                $dbName . ' no existe. Ejecuta setup.php.',
                $error
            );
        }

    } catch (Throwable $e) {

        checkError(
            'Base central',
            'No se pudo comprobar',
            $error
        );
    }
}

/*
 * ============================================================
 * 9. TABLAS CENTRALES
 * ============================================================
 */

$tablasRequeridas = [
    'ADMIN_USUARIOS',
    'CLIENTES',
    'PLANES_LICENCIA',
    'LICENCIAS',
    'LICENCIA_ACTIVACIONES',
    'LICENCIA_LOG',
    'PAGOS',
    'TENANTS',
    'TICKETS',
    'TICKET_MENSAJES',
    'SESIONES'
];

if ($pdo) {

    foreach ($tablasRequeridas as $tabla) {

        try {

            $stmt = $pdo->query(
                "SHOW TABLES LIKE " .
                $pdo->quote($tabla)
            );

            if ($stmt->fetchColumn()) {

                checkOk(
                    'Tabla ' . $tabla,
                    'Existe',
                    $ok
                );

            } else {

                checkError(
                    'Tabla ' . $tabla,
                    'No existe',
                    $error
                );
            }

        } catch (Throwable $e) {

            checkError(
                'Tabla ' . $tabla,
                'No se pudo comprobar',
                $error
            );
        }
    }
}

/*
 * ============================================================
 * 10. TENANTS
 * ============================================================
 */

$tenantStorage =
    $env['LS_TENANT_STORAGE']
    ?? ($root . '/tenants');

if (is_dir($tenantStorage)) {

    if (is_readable($tenantStorage)) {

        checkOk(
            'Almacenamiento de tenants',
            $tenantStorage,
            $ok
        );

    } else {

        checkError(
            'Almacenamiento de tenants',
            'La carpeta no es legible',
            $error
        );
    }

} else {

    checkWarning(
        'Almacenamiento de tenants',
        'La carpeta todavía no existe',
        $warning
    );
}

/*
 * ============================================================
 * 11. TENANTS .HTACCESS
 * ============================================================
 */

$tenantHtaccess =
    $root . '/tenants/.htaccess';

if (is_file($tenantHtaccess)) {

    checkOk(
        'Protección tenants',
        '.htaccess encontrado',
        $ok
    );

} else {

    checkWarning(
        'Protección tenants',
        'No existe tenants/.htaccess',
        $warning
    );
}

/*
 * ============================================================
 * 12. LOCK
 * ============================================================
 */

$lockFile =
    $root . '/INSTALL.lock';

if (is_file($lockFile)) {

    checkOk(
        'Instalador',
        'INSTALL.lock existe; instalación protegida',
        $ok
    );

} else {

    checkWarning(
        'Instalador',
        'INSTALL.lock no existe; setup.php todavía está disponible',
        $warning
    );
}

/*
 * ============================================================
 * 13. CONFIG.PHP
 * ============================================================
 */

try {

    if (is_file($root . '/config/config.php')) {

        require_once $root . '/config/config.php';

        if (defined('LS_DB_NAME')) {

            checkOk(
                'config/config.php',
                'Carga correctamente',
                $ok
            );

        } else {

            checkError(
                'config/config.php',
                'No define LS_DB_NAME',
                $error
            );
        }
    }

} catch (Throwable $e) {

    checkError(
        'config/config.php',
        'Error al cargar configuración',
        $error
    );
}

/*
 * ============================================================
 * RESUMEN
 * ============================================================
 */

$totalOk = count($ok);
$totalWarning = count($warning);
$totalError = count($error);

$estado = 'CORRECTO';

if ($totalError > 0) {
    $estado = 'CON ERRORES';
} elseif ($totalWarning > 0) {
    $estado = 'CORRECTO CON AVISOS';
}

?>
<!DOCTYPE html>
<html lang="es">

<head>

<meta charset="UTF-8">

<meta name="viewport"
      content="width=device-width,initial-scale=1">

<title>SearPix — Diagnóstico</title>

<style>

* {
    box-sizing: border-box;
}

body {
    margin: 0;
    padding: 25px;
    background: #0b0f19;
    color: #e5e7eb;
    font-family: Arial, sans-serif;
}

.container {
    width: 100%;
    max-width: 1100px;
    margin: auto;
}

h1 {
    margin-bottom: 5px;
}

.subtitle {
    color: #94a3b8;
    margin-bottom: 25px;
}

.summary {
    display: grid;
    grid-template-columns:
        repeat(4, minmax(0, 1fr));

    gap: 15px;
    margin-bottom: 25px;
}

.card {
    background: #121826;
    border: 1px solid #263044;
    border-radius: 15px;
    padding: 20px;
}

.number {
    font-size: 32px;
    font-weight: 900;
}

.green {
    color: #4ade80;
}

.yellow {
    color: #facc15;
}

.red {
    color: #f87171;
}

.status {
    font-size: 22px;
    font-weight: 900;
}

.item {
    padding: 13px;
    margin: 7px 0;
    border-radius: 8px;
}

.item-ok {
    background: rgba(74,222,128,.07);
    border-left: 4px solid #4ade80;
}

.item-warning {
    background: rgba(250,204,21,.07);
    border-left: 4px solid #facc15;
}

.item-error {
    background: rgba(239,68,68,.08);
    border-left: 4px solid #ef4444;
}

.detail {
    display: block;
    color: #94a3b8;
    font-size: 13px;
    margin-top: 4px;
}

.actions {
    margin-top: 25px;
}

a {
    display: inline-block;
    padding: 13px 18px;
    border-radius: 9px;
    background: #6c3ce1;
    color: white;
    text-decoration: none;
    font-weight: bold;
    margin-right: 8px;
}

@media (max-width: 700px) {

    .summary {
        grid-template-columns: 1fr 1fr;
    }

}

</style>

</head>

<body>

<div class="container">

<h1>🔐 Diagnóstico SearPix</h1>

<div class="subtitle">
Estado técnico del servidor y arquitectura multi-tenant
</div>

<div class="summary">

<div class="card">
    <div class="number green">
        <?= $totalOk ?>
    </div>
    <div>Correctos</div>
</div>

<div class="card">
    <div class="number yellow">
        <?= $totalWarning ?>
    </div>
    <div>Avisos</div>
</div>

<div class="card">
    <div class="number red">
        <?= $totalError ?>
    </div>
    <div>Errores</div>
</div>

<div class="card">

    <div class="status
        <?= $totalError > 0
            ? 'red'
            : ($totalWarning > 0
                ? 'yellow'
                : 'green') ?>">

        <?= h($estado) ?>

    </div>

    <div>Estado general</div>

</div>

</div>

<?php if (!empty($error)): ?>

<div class="card">

<h2 class="red">
❌ Errores
</h2>

<?php foreach ($error as $item): ?>

<div class="item item-error">

<strong>
✗ <?= h($item['nombre']) ?>
</strong>

<span class="detail">
<?= h($item['detalle']) ?>
</span>

</div>

<?php endforeach; ?>

</div>

<?php endif; ?>


<?php if (!empty($warning)): ?>

<div class="card">

<h2 class="yellow">
⚠ Avisos
</h2>

<?php foreach ($warning as $item): ?>

<div class="item item-warning">

<strong>
⚠ <?= h($item['nombre']) ?>
</strong>

<span class="detail">
<?= h($item['detalle']) ?>
</span>

</div>

<?php endforeach; ?>

</div>

<?php endif; ?>


<div class="card">

<h2 class="green">
✓ Comprobaciones correctas
</h2>

<?php foreach ($ok as $item): ?>

<div class="item item-ok">

<strong>
✓ <?= h($item['nombre']) ?>
</strong>

<span class="detail">
<?= h($item['detalle']) ?>
</span>

</div>

<?php endforeach; ?>

</div>


<div class="actions">

<a href="diagnostico.php">
↻ Ejecutar nuevamente
</a>

<a href="index.html">
Ir al sistema
</a>

</div>

</div>

</body>

</html>