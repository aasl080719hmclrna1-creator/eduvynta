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
$method  = $_SERVER['REQUEST_METHOD'];

// ── GET: solo maestro puede ver todas las entregas de una actividad ───────
if ($method === 'GET') {
    if ($payload['rol'] !== 'maestro') {
        jsonError('Acceso denegado', 403);
    }
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

// ── POST: solo alumnos pueden entregar tareas ─────────────────────────────
if ($method === 'POST') {
    if ($payload['rol'] !== 'alumno') {
        jsonError('Solo alumnos pueden entregar tareas', 403);
    }

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
    if (!$chk->fetch()) {
        jsonError('Actividad no encontrada o no autorizado', 403);
    }

    $ins = $pdo->prepare('
        INSERT INTO entregas (actividad_id, alumno_id, comentario)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE comentario = VALUES(comentario), fecha_entrega = CURRENT_TIMESTAMP
    ');
    $ins->execute([$actividad_id, $payload['id'], $comentario]);

    jsonResponse(['message' => 'Entrega registrada'], 201);
}

// ── PUT: solo maestros pueden calificar ───────────────────────────────────
if ($method === 'PUT') {
    if ($payload['rol'] !== 'maestro') {
        jsonError('Solo maestros pueden calificar', 403);
    }

    $body         = getBody();
    $entrega_id   = (int)($body['entrega_id']  ?? 0);
    $calificacion = isset($body['calificacion']) ? (float)$body['calificacion'] : null;

    if (!$entrega_id || $calificacion === null) {
        jsonError('entrega_id y calificacion requeridos');
    }
    if ($calificacion < 0 || $calificacion > 100) {
        jsonError('Calificación debe estar entre 0 y 100');
    }

    $upd = $pdo->prepare('UPDATE entregas SET calificacion = ? WHERE id = ?');
    $upd->execute([$calificacion, $entrega_id]);

    jsonResponse(['message' => 'Calificación registrada']);
}

jsonError('Método no permitido', 405);
