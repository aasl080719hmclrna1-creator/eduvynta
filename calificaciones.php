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

    // Maestro: lista de alumnos del grupo con su promedio_final
    if ($payload['rol'] === 'maestro' && $grupo_id && !$alumno_id) {
        // Verificar que el grupo pertenece al maestro
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
    $alumno_id      = (int)($body['alumno_id']  ?? 0);
    $grupo_id       = (int)($body['grupo_id']   ?? 0);
    $materia_id     = (int)($body['materia_id'] ?? 1);
    $semestre       = (int)($body['semestre']   ?? 1);
    $primer_parcial  = isset($body['primer_parcial'])  ? (float)$body['primer_parcial']  : null;
    $segundo_parcial = isset($body['segundo_parcial']) ? (float)$body['segundo_parcial'] : null;
    $examen_final    = isset($body['examen_final'])    ? (float)$body['examen_final']    : null;

    if (!$alumno_id || !$grupo_id) jsonError('alumno_id y grupo_id son requeridos');

    // Validar rango 0-100
    foreach (['primer_parcial' => $primer_parcial, 'segundo_parcial' => $segundo_parcial, 'examen_final' => $examen_final] as $campo => $valor) {
        if ($valor !== null && ($valor < 0 || $valor > 100)) {
            jsonError("$campo debe estar entre 0 y 100");
        }
    }

    // Calcular promedio con los parciales que lleguen
    $partes   = array_filter([$primer_parcial, $segundo_parcial, $examen_final], fn($v) => $v !== null);
    $promedio = count($partes) > 0 ? array_sum($partes) / count($partes) : null;

    // Verificar que el grupo pertenece al maestro
    $chk = $pdo->prepare('SELECT id FROM grupos WHERE id = ? AND maestro_id = ? LIMIT 1');
    $chk->execute([$grupo_id, $payload['id']]);
    if (!$chk->fetch()) jsonError('Grupo no autorizado', 403);

    // Verificar que el alumno pertenece al grupo
    $chkA = $pdo->prepare('SELECT alumno_id FROM alumnos_grupos WHERE alumno_id = ? AND grupo_id = ? LIMIT 1');
    $chkA->execute([$alumno_id, $grupo_id]);
    if (!$chkA->fetch()) jsonError('El alumno no pertenece a este grupo', 403);

    // FIX: Buscar calificación existente y hacer UPDATE, si no existe INSERT
    // Esto evita duplicados si la tabla no tiene índice UNIQUE configurado
    $existing = $pdo->prepare('SELECT id, primer_parcial, segundo_parcial, examen_final FROM calificaciones WHERE alumno_id = ? AND grupo_id = ? LIMIT 1');
    $existing->execute([$alumno_id, $grupo_id]);
    $row = $existing->fetch();

    if ($row) {
        // Mantener valores anteriores si el nuevo es null (no se envió ese parcial)
        $p1_final = $primer_parcial  !== null ? $primer_parcial  : (float)$row['primer_parcial'];
        $p2_final = $segundo_parcial !== null ? $segundo_parcial : (float)$row['segundo_parcial'];
        $p3_final = $examen_final    !== null ? $examen_final    : (float)$row['examen_final'];

        // Recalcular promedio con los valores definitivos
        $partes_final = array_filter([$p1_final, $p2_final, $p3_final], fn($v) => $v !== null && $v > 0);
        $promedio_final = count($partes_final) > 0 ? round(array_sum($partes_final) / count($partes_final), 2) : null;

        $upd = $pdo->prepare('UPDATE calificaciones SET primer_parcial=?, segundo_parcial=?, examen_final=?, promedio_final=?, semestre=? WHERE id=?');
        $upd->execute([$p1_final, $p2_final, $p3_final, $promedio_final, $semestre, $row['id']]);
        $promedio = $promedio_final;
    } else {
        $ins = $pdo->prepare('INSERT INTO calificaciones (alumno_id, grupo_id, materia_id, semestre, primer_parcial, segundo_parcial, examen_final, promedio_final) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
        $ins->execute([$alumno_id, $grupo_id, $materia_id, $semestre, $primer_parcial, $segundo_parcial, $examen_final, $promedio !== null ? round($promedio, 2) : null]);
        $promedio = $promedio !== null ? round($promedio, 2) : null;
    }

    jsonResponse(['message' => 'Calificación guardada', 'promedio' => $promedio], 201);
}

jsonError('Método no permitido', 405);
