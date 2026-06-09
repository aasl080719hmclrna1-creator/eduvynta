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

    // Maestro: lista de alumnos del grupo con promedio_final
    if ($payload['rol'] === 'maestro' && $grupo_id && !$alumno_id) {
        $chk = $pdo->prepare('SELECT id FROM grupos WHERE id = ? AND maestro_id = ? LIMIT 1');
        $chk->execute([$grupo_id, $payload['id']]);
        if (!$chk->fetch()) jsonError('Grupo no autorizado', 403);

        $stmt = $pdo->prepare('
            SELECT u.id AS alumno_id,
                   u.nombre,
                   ROUND(AVG(c.promedio_final), 2) AS promedio,
                   COUNT(c.id)                     AS materias_calificadas
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

    // Alumno: sus calificaciones por grupo
    if ($payload['rol'] === 'alumno') {
        $stmt = $pdo->prepare('
            SELECT c.primer_parcial, c.segundo_parcial, c.examen_final, c.promedio_final,
                   c.semestre, c.grupo_id,
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

// ── POST: guardar/actualizar calificación ─────────────────────────────────────
if ($method === 'POST') {
    if ($payload['rol'] !== 'maestro') jsonError('Solo maestros pueden calificar', 403);

    $body           = getBody();
    $alumno_id      = (int)($body['alumno_id']      ?? 0);
    $grupo_id       = (int)($body['grupo_id']       ?? 0);
    $materia_id     = (int)($body['materia_id']     ?? 1); // default 1 si no se manda
    $semestre       = (int)($body['semestre']       ?? 1);
    $primer_parcial  = isset($body['primer_parcial'])  ? (float)$body['primer_parcial']  : null;
    $segundo_parcial = isset($body['segundo_parcial']) ? (float)$body['segundo_parcial'] : null;
    $examen_final    = isset($body['examen_final'])    ? (float)$body['examen_final']    : null;

    if (!$alumno_id || !$grupo_id) jsonError('alumno_id y grupo_id son requeridos');

    // Calcular promedio_final con los parciales que lleguen
    $partes  = array_filter([$primer_parcial, $segundo_parcial, $examen_final], fn($v) => $v !== null);
    $promedio = count($partes) > 0 ? array_sum($partes) / count($partes) : null;

    // Verificar que el grupo pertenece al maestro
    $chk = $pdo->prepare('SELECT id FROM grupos WHERE id = ? AND maestro_id = ? LIMIT 1');
    $chk->execute([$grupo_id, $payload['id']]);
    if (!$chk->fetch()) jsonError('Grupo no autorizado', 403);

    // Verificar que el alumno pertenece al grupo
    $chkA = $pdo->prepare('SELECT alumno_id FROM alumnos_grupos WHERE alumno_id = ? AND grupo_id = ? LIMIT 1');
    $chkA->execute([$alumno_id, $grupo_id]);
    if (!$chkA->fetch()) jsonError('El alumno no pertenece a este grupo', 403);

    $stmt = $pdo->prepare('
        INSERT INTO calificaciones
            (alumno_id, grupo_id, materia_id, semestre, primer_parcial, segundo_parcial, examen_final, promedio_final)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            primer_parcial  = COALESCE(VALUES(primer_parcial),  primer_parcial),
            segundo_parcial = COALESCE(VALUES(segundo_parcial), segundo_parcial),
            examen_final    = COALESCE(VALUES(examen_final),    examen_final),
            promedio_final  = VALUES(promedio_final)
    ');
    $stmt->execute([
        $alumno_id, $grupo_id, $materia_id, $semestre,
        $primer_parcial, $segundo_parcial, $examen_final, $promedio
    ]);

    jsonResponse(['message' => 'Calificación guardada', 'promedio' => $promedio], 201);
}

jsonError('Método no permitido', 405);
