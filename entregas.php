<?php
/**
 * GET  entregas.php?actividad_id=X  → lista entregas (maestro)
 * POST entregas.php                  → alumno entrega tarea
 * PUT  entregas.php                  → maestro califica { entrega_id, calificacion }
 *
 * CORREGIDO:
 *  - require_once usa __DIR__ hacia el mismo directorio (estructura plana)
 *  - Validación de calificación: cast a float antes de comparar (evita bug con "0")
 */

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/response.php';

setCorsHeaders();
$payload = requireAuth();
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];

// ── GET: maestro ve entregas de una actividad ─────────────────────────────────
if ($method === 'GET') {
    if ($payload['rol'] !== 'maestro') jsonError('Acceso denegado', 403);
    $actividad_id = (int)($_GET['actividad_id'] ?? 0);
    if (!$actividad_id) jsonError('actividad_id requerido');

    $stmt = $pdo->prepare('
        SELECT e.*, u.nombre AS alumno_nombre
        FROM entregas e
        JOIN usuarios u ON u.id = e.alumno_id
        WHERE e.actividad_id = ?
        ORDER BY e.fecha_entrega DESC
    ');
    $stmt->execute([$actividad_id]);
    jsonResponse($stmt->fetchAll());
}

// ── POST: alumno sube entrega ─────────────────────────────────────────────────
if ($method === 'POST') {
    if ($payload['rol'] !== 'alumno') jsonError('Solo alumnos pueden entregar tareas', 403);

    $body         = getBody();
    $actividad_id = (int)($body['actividad_id'] ?? 0);
    $comentario   = trim($body['comentario'] ?? '');

    if (!$actividad_id) jsonError('actividad_id requerido');

    // Verificar que el alumno pertenece al grupo de la actividad
    $chk = $pdo->prepare('
        SELECT a.id FROM actividades a
        JOIN alumnos_grupos ag ON ag.grupo_id = a.grupo_id
        WHERE a.id = ? AND ag.alumno_id = ? LIMIT 1
    ');
    $chk->execute([$actividad_id, $payload['id']]);
    if (!$chk->fetch()) jsonError('Actividad no encontrada o no autorizado', 403);

    $ins = $pdo->prepare('
        INSERT INTO entregas (actividad_id, alumno_id, comentario)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE comentario = VALUES(comentario), fecha_entrega = CURRENT_TIMESTAMP
    ');
    $ins->execute([$actividad_id, $payload['id'], $comentario]);

    jsonResponse(['message' => 'Entrega registrada'], 201);
}

// ── PUT: maestro califica ─────────────────────────────────────────────────────
if ($method === 'PUT') {
    if ($payload['rol'] !== 'maestro') jsonError('Solo maestros pueden calificar', 403);

    $body         = getBody();
    $entrega_id   = (int)($body['entrega_id']  ?? 0);
    // CORREGIDO: cast explícito a float para que la validación no falle con valor 0
    $calificacion = isset($body['calificacion']) ? (float)$body['calificacion'] : null;

    if (!$entrega_id || $calificacion === null) jsonError('entrega_id y calificacion requeridos');
    if ($calificacion < 0 || $calificacion > 100) jsonError('Calificación debe estar entre 0 y 100');

    $upd = $pdo->prepare('UPDATE entregas SET calificacion = ? WHERE id = ?');
    $upd->execute([$calificacion, $entrega_id]);

    jsonResponse(['message' => 'Calificación registrada']);
}

jsonError('Método no permitido', 405);
