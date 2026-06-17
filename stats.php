<?php
ob_start();
ini_set('display_errors', '0');
error_reporting(0);
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/response.php';

setCorsHeaders();
ob_end_clean();
$payload = requireAuth();
$pdo     = getDB();
$id      = $payload['id'];

if ($payload['rol'] === 'maestro') {
    $stmtAlumnos = $pdo->prepare('
        SELECT COUNT(DISTINCT ag.alumno_id) AS total
        FROM grupos g
        JOIN alumnos_grupos ag ON ag.grupo_id = g.id
        WHERE g.maestro_id = ? AND g.activo = 1
    ');
    $stmtAlumnos->execute([$id]);
    $totalAlumnos = (int)$stmtAlumnos->fetchColumn();

    $stmtActs = $pdo->prepare('SELECT COUNT(*) FROM actividades WHERE maestro_id = ?');
    $stmtActs->execute([$id]);
    $totalActs = (int)$stmtActs->fetchColumn();

    $stmtAsist = $pdo->prepare('
        SELECT COUNT(*) AS total, SUM(presente) AS presentes
        FROM asistencias a
        JOIN grupos g ON g.id = a.grupo_id
        WHERE g.maestro_id = ? AND a.fecha = CURDATE()
    ');
    $stmtAsist->execute([$id]);
    $asist     = $stmtAsist->fetch();
    $porcAsist = ($asist['total'] > 0)
        ? round($asist['presentes'] / $asist['total'] * 100, 1)
        : null;

    // Alumnos que NO han entregado alguna actividad activa del maestro
    $stmtNoEntr = $pdo->prepare('
        SELECT COUNT(*) AS total
        FROM actividades a
        JOIN grupos g ON g.id = a.grupo_id
        JOIN alumnos_grupos ag ON ag.grupo_id = g.id
        LEFT JOIN entregas e ON e.actividad_id = a.id AND e.alumno_id = ag.alumno_id
        WHERE g.maestro_id = ? AND e.id IS NULL
    ');
    $stmtNoEntr->execute([$id]);
    $noEntregadas = (int)$stmtNoEntr->fetchColumn();

    jsonResponse([
        'total_alumnos'          => $totalAlumnos,
        'total_actividades'      => $totalActs,
        'porcentaje_asistencias' => $porcAsist,
        'no_entregadas'          => $noEntregadas,
    ]);

} else {
    $stmtPend = $pdo->prepare('
        SELECT COUNT(*) FROM actividades a
        JOIN alumnos_grupos ag ON ag.grupo_id = a.grupo_id AND ag.alumno_id = ?
        LEFT JOIN entregas e ON e.actividad_id = a.id AND e.alumno_id = ?
        WHERE e.id IS NULL AND a.fecha_limite >= CURDATE()
    ');
    $stmtPend->execute([$id, $id]);
    $pendientes = (int)$stmtPend->fetchColumn();

    $stmtEntr = $pdo->prepare('SELECT COUNT(*) FROM entregas WHERE alumno_id = ?');
    $stmtEntr->execute([$id]);
    $entregadas = (int)$stmtEntr->fetchColumn();

    $stmtProm = $pdo->prepare('SELECT AVG(promedio_final) FROM calificaciones WHERE alumno_id = ? AND promedio_final IS NOT NULL');
    $stmtProm->execute([$id]);
    $prom = $stmtProm->fetchColumn();

    $stmtGrupos = $pdo->prepare('SELECT COUNT(*) FROM alumnos_grupos WHERE alumno_id = ?');
    $stmtGrupos->execute([$id]);
    $grupos = (int)$stmtGrupos->fetchColumn();

    jsonResponse([
        'tareas_pendientes' => $pendientes,
        'tareas_entregadas' => $entregadas,
        'promedio_general'  => $prom !== false ? round((float)$prom, 2) : null,
        'grupos'            => $grupos,
    ]);
}
