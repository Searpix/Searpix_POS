<?php
/**
 * ============================================================
 * SEARPIX / FREAKERS
 * INSTALADOR PRINCIPAL
 * ============================================================
 *
 * Uso:
 *   http://localhost/setup.php
 *
 * Este archivo:
 *   1. Comprueba que PHP/MySQL estén disponibles.
 *   2. Crea un .env inicial si no existe.
 *   3. Genera automáticamente los secretos.
 *   4. Genera el token interno del instalador.
 *   5. Ejecuta api/setup.php.
 *   6. Protege el instalador después de completar.
 *
 * IMPORTANTE:
 * Este instalador está pensado para la primera instalación local
 * mediante XAMPP.
 * ============================================================
 */

declare(strict_types=1);

session_start();

header('Content-Type: text/html; charset=UTF-8');

$root = __DIR__;

function h(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function generarSecreto(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function escribirArchivo(string $path, string $content): bool
{
    return file_put_contents($path, $content, LOCK_EX) !== false;
}

function leerEnvSimple(string $path): array
{
    $resultado = [];

    if (!is_readable($path)) {
        return $resultado;
    }

    $lineas = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);

    foreach ($lineas as $linea) {
        $linea = trim($linea);

        if ($linea === '' || str_starts_with($linea, '#')) {
            continue;
        }

        if (!str_contains($linea, '=')) {
            continue;
        }

        [$clave, $valor] = explode('=', $linea, 2);

        $clave = trim($clave);
        $valor = trim($valor);

        if (
            strlen($valor) >= 2 &&
            (
                ($valor[0] === '"' && substr($valor, -1) === '"') ||
                ($valor[0] === "'" && substr($valor, -1) === "'")
            )
        ) {
            $valor = substr($valor, 1, -1);
        }

        $resultado[$clave] = $valor;
    }

    return $resultado;
}

$lockFile = $root . DIRECTORY_SEPARATOR . 'INSTALL.lock';
$envFile  = $root . DIRECTORY_SEPARATOR . '.env';

$errores = [];
$avisos = [];
$pasos = [];

$instalado = file_exists($lockFile);

/*
 * ============================================================
 * SI YA ESTÁ INSTALADO
 * ============================================================
 */

if ($instalado && !isset($_GET['reparar'])) {
    ?>
    <!DOCTYPE html>
    <html lang="es">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width,initial-scale=1">
        <title>SearPix — Instalación completada</title>

        <style>
            * {
                box-sizing: border-box;
            }

            body {
                margin: 0;
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 25px;
                background: #0b0f19;
                color: #fff;
                font-family: Arial, sans-serif;
            }

            .box {
                width: 100%;
                max-width: 720px;
                background: #121826;
                border: 1px solid #263044;
                border-radius: 18px;
                padding: 35px;
                box-shadow: 0 20px 70px rgba(0,0,0,.45);
            }

            h1 {
                margin-top: 0;
                color: #4ade80;
            }

            p {
                color: #b8c0d0;
                line-height: 1.6;
            }

            code {
                display: block;
                padding: 14px;
                margin: 15px 0;
                border-radius: 8px;
                background: #080b12;
                color: #7dd3fc;
            }

            a {
                display: inline-block;
                padding: 13px 20px;
                border-radius: 10px;
                background: #6c3ce1;
                color: #fff;
                text-decoration: none;
                font-weight: bold;
            }

            .warning {
                margin-top: 20px;
                padding: 15px;
                border-radius: 10px;
                background: rgba(234,179,8,.1);
                border: 1px solid rgba(234,179,8,.3);
                color: #fde68a;
            }
        </style>
    </head>

    <body>
        <div class="box">
            <h1>✓ Sistema instalado</h1>

            <p>
                La instalación inicial ya fue ejecutada.
                Por seguridad, <strong>setup.php</strong> está bloqueado.
            </p>

            <p>
                Utiliza el diagnóstico para comprobar el estado completo
                del servidor.
            </p>

            <a href="diagnostico.php">
                Abrir diagnóstico
            </a>

            <div class="warning">
                No elimines <strong>INSTALL.lock</strong> a menos que
                quieras realizar una reinstalación controlada.
            </div>
        </div>
    </body>
    </html>
    <?php
    exit;
}

/*
 * ============================================================
 * SOLO LOCALHOST
 * ============================================================
 */

$remote = $_SERVER['REMOTE_ADDR'] ?? '';

$esLocal = in_array(
    $remote,
    ['127.0.0.1', '::1', 'localhost', ''],
    true
);

if (!$esLocal) {
    http_response_code(403);

    echo '<h1>Acceso denegado</h1>';
    echo '<p>El instalador solamente puede ejecutarse desde localhost.</p>';

    exit;
}

/*
 * ============================================================
 * COMPROBACIONES BÁSICAS
 * ============================================================
 */

if (PHP_VERSION_ID < 80000) {
    $errores[] = 'Se requiere PHP 8.0 o superior. Versión actual: ' . PHP_VERSION;
}

if (!extension_loaded('pdo')) {
    $errores[] = 'La extensión PDO de PHP no está habilitada.';
}

if (!extension_loaded('pdo_mysql')) {
    $errores[] = 'La extensión PDO_MySQL de PHP no está habilitada.';
}

if (!function_exists('random_bytes')) {
    $errores[] = 'La función random_bytes() no está disponible.';
}

if (!is_dir($root . '/api')) {
    $errores[] = 'No existe la carpeta /api.';
}

if (!is_file($root . '/api/setup.php')) {
    $errores[] = 'No existe /api/setup.php.';
}

if (!is_dir($root . '/tenants')) {
    if (!@mkdir($root . '/tenants', 0755, true)) {
        $avisos[] = 'No se pudo crear automáticamente /tenants.';
    }
}

/*
 * ============================================================
 * EJECUCIÓN
 * ============================================================
 */

if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($errores)) {

    try {

        /*
         * ----------------------------------------------------
         * 1. GENERAR SECRETOS
         * ----------------------------------------------------
         */

        $secret = generarSecreto(32);
        $setupToken = generarSecreto(32);

        /*
         * ----------------------------------------------------
         * 2. CONFIGURACIÓN LOCAL XAMPP
         * ----------------------------------------------------
         */

        $envActual = leerEnvSimple($envFile);

        $dbHost = $envActual['LS_DB_HOST'] ?? '127.0.0.1';
        $dbName = $envActual['LS_DB_NAME'] ?? 'freakers_licenses';
        $dbUser = $envActual['LS_DB_USER'] ?? 'root';
        $dbPass = $envActual['LS_DB_PASS'] ?? '';

        $tenantPrefix =
            $envActual['LS_TENANT_DB_PREFIX']
            ?? 'searpox_pos_';

        $templateDb =
            $envActual['LS_TENANT_TEMPLATE_DB']
            ?? 'freakers_pos';

        $tenantStorage =
            $envActual['LS_TENANT_STORAGE']
            ?? ($root . DIRECTORY_SEPARATOR . 'tenants');

        $baseUrl =
            $envActual['LS_BASE_URL']
            ?? 'http://localhost';

        $tenantBaseUrl =
            $envActual['LS_TENANT_BASE_URL']
            ?? 'http://localhost/tenant';

        /*
         * ----------------------------------------------------
         * 3. CREAR .ENV
         * ----------------------------------------------------
         */

        $env = <<<ENV
# ============================================================
# SEARPIX / FREAKERS
# Archivo generado automáticamente por setup.php
# ============================================================

LS_DB_HOST={$dbHost}
LS_DB_NAME={$dbName}
LS_DB_USER={$dbUser}
LS_DB_PASS={$dbPass}

LS_SECRET={$secret}
LS_TOKEN_HOURS=24

LS_TENANT_DB_PREFIX={$tenantPrefix}
LS_TENANT_TEMPLATE_DB={$templateDb}

LS_TENANT_BASE_URL={$tenantBaseUrl}
LS_TENANT_STORAGE={$tenantStorage}

LS_TENANT_DB_HOST={$dbHost}
LS_TENANT_DB_ADMIN_USER={$dbUser}
LS_TENANT_DB_ADMIN_PASS={$dbPass}
LS_TENANT_DB_USER_PREFIX=tenant_

LS_BASE_URL={$baseUrl}

LS_MONEDA=COP
LS_WHATSAPP=

LS_MAIL_ENABLED=false
LS_MAIL_HOST=smtp.gmail.com
LS_MAIL_PORT=587
LS_MAIL_SECURE=tls
LS_MAIL_USER=
LS_MAIL_PASS=
LS_MAIL_FROM=
LS_MAIL_FROM_NAME=SearPix | No-Reply
LS_NOTIFY_EMAIL=

WOMPI_MODE=sandbox
WOMPI_SANDBOX_PUBLIC=
WOMPI_SANDBOX_PRIVATE=
WOMPI_SANDBOX_INTEGRITY=
WOMPI_SANDBOX_EVENTS=

WOMPI_PROD_PUBLIC=
WOMPI_PROD_PRIVATE=
WOMPI_PROD_INTEGRITY=
WOMPI_PROD_EVENTS=

PAYPAL_MODE=sandbox
PAYPAL_SANDBOX_CLIENT_ID=
PAYPAL_SANDBOX_SECRET=

PAYPAL_PROD_CLIENT_ID=
PAYPAL_PROD_SECRET=

PAYPAL_COP_TO_USD=4000

SETUP_DB_USER={$dbUser}
SETUP_DB_PASS={$dbPass}

SETUP_ADMIN_PASSWORD=
SEED_DEMO_CLIENT_PASSWORD=
SEED_DEMO_LICENSE_KEY=

SETUP_TOKEN={$setupToken}

ENV;

        if (!escribirArchivo($envFile, $env)) {
            throw new RuntimeException(
                'No se pudo crear el archivo .env.'
            );
        }

        $pasos[] = 'Archivo .env creado correctamente.';

        /*
         * ----------------------------------------------------
         * 4. CREAR setup_token.php
         * ----------------------------------------------------
         */

        $setupTokenFile = $root . '/api/setup_token.php';

        $tokenPhp = "<?php\n"
            . "defined('SETUP_TOKEN') || define('SETUP_TOKEN', "
            . var_export($setupToken, true)
            . ");\n";

        if (!escribirArchivo($setupTokenFile, $tokenPhp)) {
            throw new RuntimeException(
                'No se pudo crear api/setup_token.php.'
            );
        }

        $pasos[] = 'Token interno de instalación generado.';

        /*
         * ----------------------------------------------------
         * 5. CARGAR CONFIGURACIÓN
         * ----------------------------------------------------
         */

        require_once $root . '/config/config.php';

        /*
         * ----------------------------------------------------
         * 6. PROBAR MYSQL
         * ----------------------------------------------------
         */

        $pdo = new PDO(
            'mysql:host=' . LS_DB_HOST . ';charset=utf8mb4',
            LS_DB_USER,
            LS_DB_PASS,
            [
                PDO::ATTR_ERRMODE =>
                    PDO::ERRMODE_EXCEPTION,

                PDO::ATTR_EMULATE_PREPARES =>
                    false
            ]
        );

        $pasos[] = 'Conexión con MySQL exitosa.';

        /*
         * ----------------------------------------------------
         * 7. PREPARAR TOKEN PARA api/setup.php
         * ----------------------------------------------------
         */

        $_GET['token'] = $setupToken;

        /*
         * ----------------------------------------------------
         * 8. EJECUTAR INSTALADOR EXISTENTE
         * ----------------------------------------------------
         */

        ob_start();

        include $root . '/api/setup.php';

        $resultadoSetup = ob_get_clean();

        /*
         * Si api/setup.php terminó correctamente,
         * marcamos la instalación.
         */

        $lockContenido =
            "SEARPIX INSTALL LOCK\n"
            . "Fecha: " . date('c') . "\n"
            . "Servidor: localhost\n";

        if (!escribirArchivo($lockFile, $lockContenido)) {
            $avisos[] =
                'La instalación terminó, pero no se pudo crear INSTALL.lock.';
        } else {
            $pasos[] = 'INSTALL.lock creado. Instalador protegido.';
        }

        /*
         * ----------------------------------------------------
         * MOSTRAR RESULTADO
         * ----------------------------------------------------
         */

        ?>
        <!DOCTYPE html>
        <html lang="es">

        <head>
            <meta charset="UTF-8">
            <meta name="viewport"
                  content="width=device-width,initial-scale=1">

            <title>SearPix — Instalación</title>

            <style>
                * {
                    box-sizing: border-box;
                }

                body {
                    margin: 0;
                    padding: 25px;
                    background: #0b0f19;
                    color: #fff;
                    font-family: Arial, sans-serif;
                }

                .container {
                    max-width: 1000px;
                    margin: auto;
                }

                h1 {
                    color: #4ade80;
                }

                .card {
                    background: #121826;
                    border: 1px solid #263044;
                    border-radius: 15px;
                    padding: 25px;
                    margin-bottom: 20px;
                }

                .ok {
                    padding: 12px;
                    margin: 8px 0;
                    border-left: 4px solid #4ade80;
                    background: rgba(74,222,128,.08);
                    border-radius: 6px;
                }

                .warning {
                    padding: 12px;
                    margin: 8px 0;
                    border-left: 4px solid #facc15;
                    background: rgba(250,204,21,.08);
                    border-radius: 6px;
                }

                pre {
                    white-space: pre-wrap;
                    overflow-x: auto;
                    padding: 15px;
                    background: #070a10;
                    border-radius: 8px;
                    color: #cbd5e1;
                }

                a {
                    display: inline-block;
                    padding: 13px 18px;
                    background: #6c3ce1;
                    border-radius: 9px;
                    color: white;
                    text-decoration: none;
                    font-weight: bold;
                    margin-right: 8px;
                }
            </style>
        </head>

        <body>

        <div class="container">

            <h1>✓ Instalación ejecutada</h1>

            <div class="card">

                <h2>Pasos</h2>

                <?php foreach ($pasos as $paso): ?>
                    <div class="ok">
                        ✓ <?= h($paso) ?>
                    </div>
                <?php endforeach; ?>

                <?php foreach ($avisos as $aviso): ?>
                    <div class="warning">
                        ⚠ <?= h($aviso) ?>
                    </div>
                <?php endforeach; ?>

            </div>

            <div class="card">

                <h2>Resultado del instalador interno</h2>

                <pre><?= h($resultadoSetup) ?></pre>

            </div>

            <div class="card">

                <h2>Siguiente paso</h2>

                <p>
                    Ejecuta ahora el diagnóstico completo del servidor.
                </p>

                <a href="diagnostico.php">
                    Abrir diagnostico.php
                </a>

                <a href="index.html">
                    Ir al sistema
                </a>

            </div>

        </div>

        </body>
        </html>
        <?php

        exit;

    } catch (Throwable $e) {

        $errores[] = $e->getMessage();

        http_response_code(500);
    }
}

/*
 * ============================================================
 * PANTALLA DEL INSTALADOR
 * ============================================================
 */
?>
<!DOCTYPE html>
<html lang="es">

<head>

    <meta charset="UTF-8">

    <meta name="viewport"
          content="width=device-width,initial-scale=1">

    <title>SearPix — Instalador</title>

    <style>

        * {
            box-sizing: border-box;
        }

        body {
            margin: 0;
            min-height: 100vh;
            padding: 30px;
            background: #0b0f19;
            color: #fff;
            font-family: Arial, sans-serif;
        }

        .container {
            max-width: 850px;
            margin: auto;
        }

        .logo {
            font-size: 30px;
            font-weight: 900;
            color: #a78bfa;
        }

        .subtitle {
            color: #94a3b8;
            margin-bottom: 30px;
        }

        .card {
            background: #121826;
            border: 1px solid #263044;
            border-radius: 18px;
            padding: 28px;
            margin-bottom: 20px;
        }

        .check {
            padding: 12px;
            margin: 8px 0;
            border-radius: 8px;
            background: rgba(74,222,128,.08);
            border-left: 4px solid #4ade80;
        }

        .error {
            padding: 12px;
            margin: 8px 0;
            border-radius: 8px;
            background: rgba(239,68,68,.1);
            border-left: 4px solid #ef4444;
            color: #fecaca;
        }

        .warning {
            padding: 12px;
            margin: 8px 0;
            border-radius: 8px;
            background: rgba(250,204,21,.1);
            border-left: 4px solid #facc15;
            color: #fde68a;
        }

        button {
            width: 100%;
            border: 0;
            padding: 16px;
            border-radius: 10px;
            background: #6c3ce1;
            color: white;
            font-size: 17px;
            font-weight: 800;
            cursor: pointer;
        }

        button:hover {
            opacity: .9;
        }

        code {
            color: #7dd3fc;
        }

    </style>

</head>

<body>

<div class="container">

    <div class="logo">
        SEARPIX
    </div>

    <div class="subtitle">
        Instalador inicial del sistema
    </div>

    <div class="card">

        <h1>Configuración del servidor</h1>

        <p>
            Este instalador prepara automáticamente el entorno
            para XAMPP y ejecuta el instalador de la base central.
        </p>

        <div class="check">
            ✓ PHP <?= h(PHP_VERSION) ?>
        </div>

        <?php if (extension_loaded('pdo')): ?>
            <div class="check">
                ✓ PDO disponible
            </div>
        <?php else: ?>
            <div class="error">
                ✗ PDO no disponible
            </div>
        <?php endif; ?>

        <?php if (extension_loaded('pdo_mysql')): ?>
            <div class="check">
                ✓ PDO MySQL disponible
            </div>
        <?php else: ?>
            <div class="error">
                ✗ PDO MySQL no disponible
            </div>
        <?php endif; ?>

        <?php if (is_dir($root . '/api')): ?>
            <div class="check">
                ✓ Carpeta API encontrada
            </div>
        <?php else: ?>
            <div class="error">
                ✗ No existe la carpeta API
            </div>
        <?php endif; ?>

        <?php foreach ($errores as $error): ?>
            <div class="error">
                ✗ <?= h($error) ?>
            </div>
        <?php endforeach; ?>

    </div>

    <div class="card">

        <h2>Antes de comenzar</h2>

        <p>
            Asegúrate de que <strong>Apache</strong> y
            <strong>MySQL</strong> estén iniciados en XAMPP.
        </p>

        <p>
            El instalador utilizará la configuración local de
            MySQL de XAMPP.
        </p>

        <div class="warning">
            Si ya tienes datos importantes en la base configurada,
            no ejecutes una reinstalación destructiva.
        </div>

    </div>

    <?php if (empty($errores)): ?>

        <div class="card">

            <form method="post">

                <button type="submit">
                    🚀 INSTALAR SISTEMA
                </button>

            </form>

        </div>

    <?php endif; ?>

</div>

</body>
</html>