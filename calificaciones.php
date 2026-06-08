<?php
/**
 * calificaciones.php
 * GET  ?alumno_id=X&grupo_id=Y  → notas del alumno en ese grupo (maestro o el propio alumno)
 * GET  ?grupo_id=Y              → todos los alumnos con promedio del grupo (solo maestro)
 * POST                          → crear/actualizar calificación de un alumno en una materia
 */
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/response.php';

setCorsHeaders();
$payload = requireAuth();
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];

if ($method === 'GET') {
    $alumno_id = (int)($_GET['alumno_id'] ?? 0);
    $grupo_id  = (int)($_GET['grupo_id']  ?? 0);

    // Maestro: ver todos los alumnos con promedios de un grupo
    if ($payload['rol'] === 'maestro' && $grupo_id && !$alumno_id) {
        // Verificar que el grupo le pertenece
        $chk = $pdo->prepare('SELECT id FROM grupos WHERE id = ? AND maestro_id = ? LIMIT 1');
        $chk->execute([$grupo_id, $payload['id']]);
        if (!$chk->fetch()) jsonError('Grupo no autorizado', 403);

        $stmt = $pdo->prepare('
            SELECT u.id AS alumno_id, u.nombre,
                   ROUND(AVG(c.calificacion), 2) AS promedio,
                   COUNT(c.id) AS materias_calificadas
            FROM alumnos_grupos ag
            JOIN usuarios u ON u.id = ag.alumno_id
            LEFT JOIN calificaciones c ON c.alumno_id = u.id AND c.grupo_id = ?
            WHERE ag.grupo_id = ?
            GROUP BY u.id, u.nombre
            ORDER BY u.nombre ASC
        ');
        $stmt->execute([$grupo_id, $grupo_id]);
        jsonResponse($stmt->fetchAll());
    }

    // Maestro o alumno: ver calificaciones detalladas de un alumno específico
    if ($alumno_id) {
        // Alumno solo puede ver sus propias calificaciones
        if ($payload['rol'] === 'alumno' && $payload['id'] !== $alumno_id) {
            jsonError('Acceso denegado', 403);
        }

        $stmt = $pdo->prepare('
            SELECT c.*, m.nombre AS materia_nombre
            FROM calificaciones c
            LEFT JOIN materias m ON m.id = c.materia_id
            WHERE c.alumno_id = ?
            ORDER BY m.nombre ASC
        ');
        $stmt->execute([$alumno_id]);
        jsonResponse($stmt->fetchAll());
    }

    // Alumno: sus propias calificaciones
    if ($payload['rol'] === 'alumno') {
        $stmt = $pdo->prepare('
            SELECT c.*, m.nombre AS materia_nombre, g.nombre AS grupo_nombre
            FROM calificaciones c
            LEFT JOIN materias m ON m.id = c.materia_id
            LEFT JOIN grupos g   ON g.id = c.grupo_id
            WHERE c.alumno_id = ?
            ORDER BY g.nombre ASC, m.nombre ASC
        ');
        $stmt->execute([$payload['id']]);
        jsonResponse($stmt->fetchAll());
    }

    jsonError('Parámetros inválidos');
}

if ($method === 'POST') {
    if ($payload['rol'] !== 'maestro') jsonError('Solo maestros pueden calificar', 403);

    $body        = getBody();
    $alumno_id   = (int)($body['alumno_id']   ?? 0);
    $grupo_id    = (int)($body['grupo_id']    ?? 0);
    $materia_id  = (int)($body['materia_id']  ?? 0);
    $calificacion = isset($body['calificacion']) ? (float)$body['calificacion'] : null;
    $periodo     = trim($body['periodo'] ?? date('Y') . '-1');

    if (!$alumno_id || !$grupo_id || $calificacion === null) {
        jsonError('alumno_id, grupo_id y calificacion son requeridos');
    }
    if ($calificacion < 0 || $calificacion > 100) {
        jsonError('La calificación debe estar entre 0 y 100');
    }

    // Verificar que el grupo le pertenece al maestro
    $chk = $pdo->prepare('SELECT id FROM grupos WHERE id = ? AND maestro_id = ? LIMIT 1');
    $chk->execute([$grupo_id, $payload['id']]);
    if (!$chk->fetch()) jsonError('Grupo no autorizado', 403);

    // Verificar que el alumno pertenece al grupo
    $chkA = $pdo->prepare('SELECT id FROM alumnos_grupos WHERE alumno_id = ? AND grupo_id = ? LIMIT 1');
    $chkA->execute([$alumno_id, $grupo_id]);
    if (!$chkA->fetch()) jsonError('El alumno no pertenece a este grupo', 403);

    // INSERT o UPDATE de calificación
    $stmt = $pdo->prepare('
        INSERT INTO calificaciones (alumno_id, grupo_id, materia_id, calificacion, periodo, promedio_final)
        VALUES (?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            calificacion   = VALUES(calificacion),
            promedio_final = VALUES(promedio_final),
            updated_at     = CURRENT_TIMESTAMP
    ');
    $stmt->execute([
        $alumno_id,
        $grupo_id,
        $materia_id ?: null,
        $calificacion,
        $periodo,
        $calificacion,   // promedio_final igual a calificacion por ahora
    ]);

    jsonResponse(['message' => 'Calificación guardada correctamente'], 201);
}

jsonError('Método no permitido', 405);
