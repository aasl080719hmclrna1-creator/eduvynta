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

if ($payload['rol'] !== 'maestro') {
    jsonError('Solo maestros pueden ver esta información', 403);
}

$maestroId = $payload['id'];

// Obtener grupos activos del maestro
$stmtGrupos = $pdo->prepare('
    SELECT id, nombre
    FROM grupos
    WHERE maestro_id = ? AND activo = 1
    ORDER BY nombre ASC
');
$stmtGrupos->execute([$maestroId]);
$grupos = $stmtGrupos->fetchAll();

$resultado = [];

foreach ($grupos as $grupo) {
    $grupoId     = $grupo['id'];
    $grupoNombre = $grupo['nombre'];

    // Actividades VENCIDAS (fecha_limite < hoy) de este grupo
    $stmtActs = $pdo->prepare('
        SELECT id, descripcion, fecha_limite
        FROM actividades
        WHERE maestro_id = ? AND grupo_id = ? AND fecha_limite < CURDATE()
        ORDER BY fecha_limite DESC
    ');
    $stmtActs->execute([$maestroId, $grupoId]);
    $actividades = $stmtActs->fetchAll();

    if (empty($actividades)) continue;

    // Alumnos del grupo
    $stmtAlumnos = $pdo->prepare('
        SELECT u.id, u.nombre
        FROM alumnos_grupos ag
        JOIN usuarios u ON u.id = ag.alumno_id
        WHERE ag.grupo_id = ?
        ORDER BY u.nombre ASC
    ');
    $stmtAlumnos->execute([$grupoId]);
    $alumnos = $stmtAlumnos->fetchAll();

    if (empty($alumnos)) continue;

    // Para cada actividad vencida, ver qué alumnos NO entregaron
    $actividadesConPendientes = [];

    foreach ($actividades as $act) {
        $actId = $act['id'];

        // IDs de alumnos que SÍ entregaron
        $stmtEntregaron = $pdo->prepare('
            SELECT alumno_id FROM entregas WHERE actividad_id = ?
        ');
        $stmtEntregaron->execute([$actId]);
        $entregaron = array_column($stmtEntregaron->fetchAll(), 'alumno_id');

        // Alumnos que NO entregaron
        $noEntregaron = array_filter($alumnos, function($alumno) use ($entregaron) {
            return !in_array($alumno['id'], $entregaron);
        });

        if (!empty($noEntregaron)) {
            $actividadesConPendientes[] = [
                'actividad_id'   => (int)$actId,
                'descripcion'    => $act['descripcion'],
                'fecha_limite'   => $act['fecha_limite'],
                'alumnos_pendientes' => array_values(array_map(function($a) {
                    return ['id' => (int)$a['id'], 'nombre' => $a['nombre']];
                }, $noEntregaron)),
            ];
        }
    }

    if (!empty($actividadesConPendientes)) {
        $resultado[] = [
            'grupo_id'     => (int)$grupoId,
            'grupo_nombre' => $grupoNombre,
            'actividades'  => $actividadesConPendientes,
        ];
    }
}

jsonResponse($resultado);
