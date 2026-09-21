<?php
require_once 'config.php';

$sesion = requireAuth();
$pdo = getConnection();

// Get date params
$fInicio = isset($_GET['fInicio']) ? trim($_GET['fInicio']) : null;
$fFin = isset($_GET['fFin']) ? trim($_GET['fFin']) : null;

$dt = new DateTime('now', new DateTimeZone('America/Bogota'));
$hoy = $dt->format('Y-m-d');
$ayer = date('Y-m-d', strtotime('-1 day', strtotime($hoy)));

// If date range provided, use those as context
if ($fInicio && $fFin) {
    $fechaBase = $fFin;
} else {
    $fechaBase = $hoy;
}

$inicioMes = date('Y-m-01', strtotime($fechaBase));
$inicioSemana = date('Y-m-d', strtotime('monday this week', strtotime($fechaBase)));
$finSemana = date('Y-m-d', strtotime('sunday this week', strtotime($fechaBase)));

// ============ KPIs matching dashboard.html expectations ============

// Total ordenes del dia
$stmt = $pdo->prepare("SELECT COUNT(*) as total FROM ORDENES WHERE DATE(fecha) = ? AND estado NOT IN ('ELIMINADA','CANCELADO')");
$stmt->execute([$hoy]);
$totalOrdenes = (int)$stmt->fetch()['total'];

// Mesas disponibles
$stmt = $pdo->query("SELECT COUNT(*) as total FROM MESAS WHERE estado = 'LIBRE'");
$mesasDisponibles = (int)$stmt->fetch()['total'];

// Mesas ocupadas
$stmt = $pdo->query("SELECT COUNT(*) as total FROM MESAS WHERE estado = 'OCUPADA'");
$mesasOcupadas = (int)$stmt->fetch()['total'];

// Total caja (ventas del dia)
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) as total FROM ORDENES WHERE DATE(fecha) = ? AND estado NOT IN ('ELIMINADA','CANCELADO')");
$stmt->execute([$hoy]);
$totalCaja = (float)$stmt->fetch()['total'];

// Total egresos
$stmt = $pdo->prepare("SELECT COALESCE(SUM(egreso),0) as total FROM ORDENES WHERE DATE(fecha) = ? AND egreso > 0 AND estado NOT IN ('ELIMINADA','CANCELADO')");
$stmt->execute([$hoy]);
$totalEgresos = (float)$stmt->fetch()['total'];

// Total en caja (ventas - egresos)
$totalEnCaja = $totalCaja - $totalEgresos;

// Ventas hoy
$ventasHoy = $totalCaja;

// Ventas ayer
$stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) as total FROM ORDENES WHERE DATE(fecha) = ? AND estado NOT IN ('ELIMINADA','CANCELADO')");
$stmt->execute([$ayer]);
$ventasAyer = (float)$stmt->fetch()['total'];

// ============ Chart Mensual (last 6 months) ============
$chartMensual = [];
for ($i = 5; $i >= 0; $i--) {
    $mes = date('Y-m', strtotime("-$i months", strtotime($fechaBase)));
    $mesNombre = date('M', strtotime("-$i months", strtotime($fechaBase)));
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(total),0) as total FROM ORDENES WHERE DATE_FORMAT(fecha, '%Y-%m') = ? AND estado NOT IN ('ELIMINADA','CANCELADO')");
    $stmt->execute([$mes]);
    $total = (float)$stmt->fetch()['total'];
    $chartMensual[] = ['mes' => $mesNombre, 'total' => $total];
}

// ============ Chart Semanal (daily for this week) ============
$chartSemanal = [];
$diasNombre = [1=>'Lun', 2=>'Mar', 3=>'Mie', 4=>'Jue', 5=>'Vie', 6=>'Sab', 7=>'Dom'];
$stmt = $pdo->prepare("SELECT DATE(fecha) as dia, SUM(total) as total FROM ORDENES WHERE DATE(fecha) BETWEEN ? AND ? AND estado NOT IN ('ELIMINADA','CANCELADO') GROUP BY DATE(fecha) ORDER BY dia");
$stmt->execute([$inicioSemana, $finSemana]);
$semanaRows = $stmt->fetchAll(PDO::FETCH_KEY_PAIR);
for ($d = 1; $d <= 7; $d++) {
    $fechaDia = date('Y-m-d', strtotime($inicioSemana . ' +' . ($d - 1) . ' days'));
    $diaNombre = $diasNombre[$d];
    $total = isset($semanaRows[$fechaDia]) ? (float)$semanaRows[$fechaDia] : 0;
    $chartSemanal[] = ['dia' => $diaNombre, 'total' => $total];
}

// ============ Ultimas 5 ordenes ============
$stmt = $pdo->prepare("SELECT o.idOrden, o.idMesa, o.fecha, o.estado, o.total, o.canal, u.usuario as mesero FROM ORDENES o LEFT JOIN USUARIOS u ON o.idUsuario = u.idUsuario WHERE o.estado NOT IN ('ELIMINADA','CANCELADO') ORDER BY o.fecha DESC LIMIT 5");
$stmt->execute();
$ultimasOrdenes = $stmt->fetchAll();

// ============ Ultimas 5 conexiones (from MOVIMIENTOS) ============
$stmt = $pdo->prepare("SELECT idUsuario, accion, fecha FROM MOVIMIENTOS ORDER BY fecha DESC LIMIT 5");
$stmt->execute();
$ultimasConexiones = $stmt->fetchAll();

// ============ Output matching dashboard.html expectations ============
jsonOutput([
    'totalOrdenes' => $totalOrdenes,
    'mesasDisponibles' => $mesasDisponibles,
    'mesasOcupadas' => $mesasOcupadas,
    'totalCaja' => $totalCaja,
    'totalEgresos' => $totalEgresos,
    'totalEnCaja' => $totalEnCaja,
    'ventasHoy' => $ventasHoy,
    'ventasAyer' => $ventasAyer,
    'chartMensual' => $chartMensual,
    'chartSemanal' => $chartSemanal,
    'ultimasOrdenes' => $ultimasOrdenes,
    'ultimasConexiones' => $ultimasConexiones
]);