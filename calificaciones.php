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
                   COUNT(c.alumno_id)              AS materias_calificadas
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

    // Maestro o alumno: detalle de un alumno específico (para pre-llenar diálogo de edición)
    if ($alumno_id && $grupo_id) {
        if ($payload['rol'] === 'alumno' && $payload['id'] !== $alumno_id) {
            jsonError('Acceso denegado', 403);
        }
        $stmt = $pdo->prepare('
            SELECT c.primer_parcial, c.segundo_parcial, c.examen_final, c.promedio_final,
                   c.semestre, c.grupo_id,
                   g.nombre AS grupo_nombre
            FROM calificaciones c
            LEFT JOIN grupos g ON g.id = c.grupo_id
            WHERE c.alumno_id = ? AND c.grupo_id = ?
            LIMIT 1
        ');
        $stmt->execute([$alumno_id, $grupo_id]);
        $row = $stmt->fetch();
        jsonResponse($row ? [$row] : []);
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

    // Acepta null, número, o string vacío (vacío = null = sin calificación en ese parcial)
    $primer_parcial  = array_key_exists('primer_parcial',  $body) && $body['primer_parcial']  !== '' ? (float)$body['primer_parcial']  : null;
    $segundo_parcial = array_key_exists('segundo_parcial', $body) && $body['segundo_parcial'] !== '' ? (float)$body['segundo_parcial'] : null;
    $examen_final    = array_key_exists('examen_final',    $body) && $body['examen_final']    !== '' ? (float)$body['examen_final']    : null;

    if (!$alumno_id || !$grupo_id) jsonError('alumno_id y grupo_id son requeridos');

    // Validar rango 0-100
    foreach (['primer_parcial' => $primer_parcial, 'segundo_parcial' => $segundo_parcial, 'examen_final' => $examen_final] as $campo => $valor) {
        if ($valor !== null && ($valor < 0 || $valor > 100)) {
            jsonError("$campo debe estar entre 0 y 100");
        }
    }

    // Verificar que el grupo pertenece al maestro
    $chk = $pdo->prepare('SELECT id FROM grupos WHERE id = ? AND maestro_id = ? LIMIT 1');
    $chk->execute([$grupo_id, $payload['id']]);
    if (!$chk->fetch()) jsonError('Grupo no autorizado', 403);

    $chkA = $pdo->prepare('SELECT alumno_id FROM alumnos_grupos WHERE alumno_id = ? AND grupo_id = ? LIMIT 1');
    $chkA->execute([$alumno_id, $grupo_id]);
    if (!$chkA->fetch()) jsonError('El alumno no pertenece a este grupo', 403);

    // Verificar si ya existe una calificación para este alumno+grupo
    $existing = $pdo->prepare('
        SELECT primer_parcial, segundo_parcial, examen_final
        FROM calificaciones
        WHERE alumno_id = ? AND grupo_id = ?
        LIMIT 1
    ');
    $existing->execute([$alumno_id, $grupo_id]);
    $row = $existing->fetch();

    if ($row) {
        // Si el campo llegó en el request (incluso vacío), lo actualiza; si no llegó conserva el valor previo
        $p1 = array_key_exists('primer_parcial',  $body) ? $primer_parcial  : ($row['primer_parcial']  !== null ? (float)$row['primer_parcial']  : null);
        $p2 = array_key_exists('segundo_parcial', $body) ? $segundo_parcial : ($row['segundo_parcial'] !== null ? (float)$row['segundo_parcial'] : null);
        $p3 = array_key_exists('examen_final',    $body) ? $examen_final    : ($row['examen_final']    !== null ? (float)$row['examen_final']    : null);

        $partes   = array_values(array_filter([$p1, $p2, $p3], fn($v) => $v !== null));
        $promedio = count($partes) > 0 ? round(array_sum($partes) / count($partes), 2) : null;

        $upd = $pdo->prepare('
            UPDATE calificaciones
            SET primer_parcial=?, segundo_parcial=?, examen_final=?, promedio_final=?, semestre=?
            WHERE alumno_id=? AND grupo_id=?
        ');
        $upd->execute([$p1, $p2, $p3, $promedio, $semestre, $alumno_id, $grupo_id]);
    } else {
        $partes   = array_values(array_filter([$primer_parcial, $segundo_parcial, $examen_final], fn($v) => $v !== null));
        $promedio = count($partes) > 0 ? round(array_sum($partes) / count($partes), 2) : null;

        $ins = $pdo->prepare('
            INSERT INTO calificaciones
                (alumno_id, grupo_id, materia_id, semestre, primer_parcial, segundo_parcial, examen_final, promedio_final)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ');
        $ins->execute([$alumno_id, $grupo_id, $materia_id, $semestre, $primer_parcial, $segundo_parcial, $examen_final, $promedio]);
    }

    jsonResponse(['message' => 'Calificación guardada', 'promedio' => $promedio], 201);
}

jsonError('Método no permitido', 405);
