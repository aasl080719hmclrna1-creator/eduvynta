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

// ── GET ───────────────────────────────────────────────────────────────────────
if ($method === 'GET') {
    $alumno_id = (int)($_GET['alumno_id'] ?? 0);
    $grupo_id  = (int)($_GET['grupo_id']  ?? 0);

    // Maestro: todos los alumnos del grupo con su promedio
    if ($payload['rol'] === 'maestro' && $grupo_id && !$alumno_id) {
        $chk = $pdo->prepare('SELECT id FROM grupos WHERE id = ? AND maestro_id = ? LIMIT 1');
        $chk->execute([$grupo_id, $payload['id']]);
        if (!$chk->fetch()) jsonError('Grupo no autorizado', 403);

        // Traer alumnos del grupo con promedio si ya tienen calificación
        $stmt = $pdo->prepare('
            SELECT u.id AS alumno_id,
                   u.nombre,
                   ROUND(AVG(c.calificacion), 2) AS promedio,
                   COUNT(c.id)                   AS materias_calificadas
            FROM alumnos_grupos ag
            JOIN usuarios u ON u.id = ag.alumno_id
            LEFT JOIN calificaciones c
                   ON c.alumno_id = u.id AND c.grupo_id = ?
            WHERE ag.grupo_id = ?
            GROUP BY u.id, u.nombre
            ORDER BY u.nombre ASC
        ');
        $stmt->execute([$grupo_id, $grupo_id]);
        jsonResponse($stmt->fetchAll());
    }

    // Alumno: sus propias calificaciones
    if ($payload['rol'] === 'alumno') {
        $stmt = $pdo->prepare('
            SELECT c.calificacion,
                   c.grupo_id,
                   g.nombre AS grupo_nombre
            FROM calificaciones c
            LEFT JOIN grupos g ON g.id = c.grupo_id
            WHERE c.alumno_id = ?
            ORDER BY g.nombre ASC
        ');
        $stmt->execute([$payload['id']]);
        jsonResponse($stmt->fetchAll());
    }

    jsonError('Parámetros inválidos');
}

// ── POST: guardar calificación ────────────────────────────────────────────────
if ($method === 'POST') {
    if ($payload['rol'] !== 'maestro') jsonError('Solo maestros pueden calificar', 403);

    $body         = getBody();
    $alumno_id    = (int)($body['alumno_id']   ?? 0);
    $grupo_id     = (int)($body['grupo_id']    ?? 0);
    $calificacion = isset($body['calificacion']) ? (float)$body['calificacion'] : null;

    if (!$alumno_id || !$grupo_id || $calificacion === null)
        jsonError('alumno_id, grupo_id y calificacion son requeridos');

    if ($calificacion < 0 || $calificacion > 100)
        jsonError('La calificación debe estar entre 0 y 100');

    // Verificar que el grupo le pertenece al maestro
    $chk = $pdo->prepare('SELECT id FROM grupos WHERE id = ? AND maestro_id = ? LIMIT 1');
    $chk->execute([$grupo_id, $payload['id']]);
    if (!$chk->fetch()) jsonError('Grupo no autorizado', 403);

    // Verificar que el alumno pertenece al grupo
    $chkA = $pdo->prepare('SELECT alumno_id FROM alumnos_grupos WHERE alumno_id = ? AND grupo_id = ? LIMIT 1');
    $chkA->execute([$alumno_id, $grupo_id]);
    if (!$chkA->fetch()) jsonError('El alumno no pertenece a este grupo', 403);

    // INSERT o UPDATE — sin materia_id ni promedio_final para no depender de columnas opcionales
    $stmt = $pdo->prepare('
        INSERT INTO calificaciones (alumno_id, grupo_id, calificacion)
        VALUES (?, ?, ?)
        ON DUPLICATE KEY UPDATE
            calificacion = VALUES(calificacion)
    ');
    $stmt->execute([$alumno_id, $grupo_id, $calificacion]);

    jsonResponse(['message' => 'Calificación guardada'], 201);
}

jsonError('Método no permitido', 405);
