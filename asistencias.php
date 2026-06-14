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
    $grupo_id  = (int)($_GET['grupo_id']  ?? 0);
    $alumno_id = (int)($_GET['alumno_id'] ?? 0);
    $fecha     = trim($_GET['fecha']      ?? '');
    $historial = isset($_GET['historial']) && $_GET['historial'] === '1';

    // Maestro: historial por grupo (todas las fechas con resumen)
    if ($payload['rol'] === 'maestro' && $grupo_id && $historial) {
        $chk = $pdo->prepare('SELECT id FROM grupos WHERE id = ? AND maestro_id = ? LIMIT 1');
        $chk->execute([$grupo_id, $payload['id']]);
        if (!$chk->fetch()) jsonError('Grupo no autorizado', 403);

        $stmt = $pdo->prepare('
            SELECT a.fecha,
                   COUNT(a.alumno_id) AS total,
                   SUM(a.presente)    AS presentes
            FROM asistencias a
            WHERE a.grupo_id = ?
            GROUP BY a.fecha
            ORDER BY a.fecha DESC
        ');
        $stmt->execute([$grupo_id]);
        jsonResponse($stmt->fetchAll());
    }

    // Maestro: detalle de un día específico para un grupo
    if ($payload['rol'] === 'maestro' && $grupo_id && $fecha) {
        $chk = $pdo->prepare('SELECT id FROM grupos WHERE id = ? AND maestro_id = ? LIMIT 1');
        $chk->execute([$grupo_id, $payload['id']]);
        if (!$chk->fetch()) jsonError('Grupo no autorizado', 403);

        $stmt = $pdo->prepare('
            SELECT u.nombre, a.presente
            FROM asistencias a
            JOIN usuarios u ON u.id = a.alumno_id
            WHERE a.grupo_id = ? AND a.fecha = ?
            ORDER BY u.nombre ASC
        ');
        $stmt->execute([$grupo_id, $fecha]);
        jsonResponse($stmt->fetchAll());
    }

    // Alumno: sus asistencias
    if ($payload['rol'] === 'alumno') {
        $params = [$payload['id']];
        $sql = 'SELECT a.fecha, a.presente, g.nombre AS grupo_nombre
                FROM asistencias a
                JOIN grupos g ON g.id = a.grupo_id
                WHERE a.alumno_id = ?';
        if ($grupo_id) { $sql .= ' AND a.grupo_id = ?'; $params[] = $grupo_id; }
        $sql .= ' ORDER BY a.fecha DESC';
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        jsonResponse($stmt->fetchAll());
    }

    jsonError('Parámetros inválidos');
}

// ── POST: registrar asistencia ────────────────────────────────────────────────
if ($method === 'POST') {
    if ($payload['rol'] !== 'maestro') jsonError('Solo maestros pueden registrar asistencia', 403);

    $body      = getBody();
    $alumno_id = (int)($body['alumno_id'] ?? 0);
    $grupo_id  = (int)($body['grupo_id']  ?? 0);
    $fecha     = trim($body['fecha']      ?? '');
    $presente  = isset($body['presente']) ? (int)$body['presente'] : 0;

    if (!$alumno_id || !$grupo_id || !$fecha) jsonError('alumno_id, grupo_id y fecha son requeridos');

    // Verificar que el grupo es del maestro
    $chk = $pdo->prepare('SELECT id FROM grupos WHERE id = ? AND maestro_id = ? LIMIT 1');
    $chk->execute([$grupo_id, $payload['id']]);
    if (!$chk->fetch()) jsonError('Grupo no autorizado', 403);

    // UPSERT: INSERT o actualiza si ya existe registro del día
    $stmt = $pdo->prepare('
        INSERT INTO asistencias (alumno_id, grupo_id, fecha, presente)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE presente = VALUES(presente)
    ');
    $stmt->execute([$alumno_id, $grupo_id, $fecha, $presente]);

    jsonResponse(['message' => 'Asistencia registrada'], 201);
}

jsonError('Método no permitido', 405);
