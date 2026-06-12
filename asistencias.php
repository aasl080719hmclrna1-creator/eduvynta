<?php
/**
 * asistencias.php
 *
 * POST  { alumno_id, grupo_id, fecha (yyyy-MM-dd), presente (0|1) }
 *       → registra o actualiza la asistencia de un alumno (solo maestro)
 *
 * GET   ?grupo_id=X[&fecha=yyyy-MM-dd]
 *       → lista asistencias del grupo en esa fecha (hoy si no se manda fecha)
 *
 * GET   ?alumno_id=X[&grupo_id=Y]
 *       → historial de asistencias del alumno
 */
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
    $fecha     = trim($_GET['fecha'] ?? date('Y-m-d'));

    // Maestro ve asistencias de un grupo en una fecha
    if ($payload['rol'] === 'maestro' && $grupo_id && !$alumno_id) {
        $chk = $pdo->prepare('SELECT id FROM grupos WHERE id = ? AND maestro_id = ? LIMIT 1');
        $chk->execute([$grupo_id, $payload['id']]);
        if (!$chk->fetch()) jsonError('Grupo no autorizado', 403);

        $stmt = $pdo->prepare('
            SELECT a.*, u.nombre AS alumno_nombre
            FROM asistencias a
            JOIN usuarios u ON u.id = a.alumno_id
            WHERE a.grupo_id = ? AND a.fecha = ?
            ORDER BY u.nombre ASC
        ');
        $stmt->execute([$grupo_id, $fecha]);
        jsonResponse($stmt->fetchAll());
    }

    // Ver historial de un alumno concreto
    if ($alumno_id) {
        if ($payload['rol'] === 'alumno' && $payload['id'] !== $alumno_id) {
            jsonError('Acceso denegado', 403);
        }

        $params = [$alumno_id];
        $extra  = '';
        if ($grupo_id) {
            $extra    = 'AND a.grupo_id = ?';
            $params[] = $grupo_id;
        }

        $stmt = $pdo->prepare("
            SELECT a.*, g.nombre AS grupo_nombre
            FROM asistencias a
            LEFT JOIN grupos g ON g.id = a.grupo_id
            WHERE a.alumno_id = ? $extra
            ORDER BY a.fecha DESC
            LIMIT 90
        ");
        $stmt->execute($params);
        jsonResponse($stmt->fetchAll());
    }

    // Alumno: sus asistencias de hoy en todos sus grupos
    if ($payload['rol'] === 'alumno') {
        $stmt = $pdo->prepare('
            SELECT a.*, g.nombre AS grupo_nombre
            FROM asistencias a
            LEFT JOIN grupos g ON g.id = a.grupo_id
            WHERE a.alumno_id = ? AND a.fecha = ?
            ORDER BY g.nombre ASC
        ');
        $stmt->execute([$payload['id'], $fecha]);
        jsonResponse($stmt->fetchAll());
    }

    jsonError('Parámetros inválidos');
}

// ── POST: maestro registra/actualiza asistencia ───────────────────────────────
if ($method === 'POST') {
    if ($payload['rol'] !== 'maestro') {
        jsonError('Solo maestros pueden registrar asistencias', 403);
    }

    $body      = getBody();
    $alumno_id = (int)($body['alumno_id'] ?? 0);
    $grupo_id  = (int)($body['grupo_id']  ?? 0);
    $fecha     = trim($body['fecha']      ?? date('Y-m-d'));
    $presente  = isset($body['presente']) ? (int)$body['presente'] : 0;

    if (!$alumno_id || !$grupo_id) {
        jsonError('alumno_id y grupo_id son requeridos');
    }

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $fecha)) {
        jsonError('Formato de fecha inválido (use yyyy-MM-dd)');
    }

    // Verificar que el grupo pertenece al maestro
    $chk = $pdo->prepare('SELECT id FROM grupos WHERE id = ? AND maestro_id = ? LIMIT 1');
    $chk->execute([$grupo_id, $payload['id']]);
    if (!$chk->fetch()) jsonError('Grupo no autorizado', 403);

    // FIX: usar alumno_id en vez de id — la tabla alumnos_grupos es pivot y puede no tener columna 'id'
    $chkA = $pdo->prepare('SELECT alumno_id FROM alumnos_grupos WHERE alumno_id = ? AND grupo_id = ? LIMIT 1');
    $chkA->execute([$alumno_id, $grupo_id]);
    if (!$chkA->fetch()) jsonError('El alumno no pertenece a este grupo', 403);

    // FIX: SELECT + UPDATE/INSERT manual en vez de ON DUPLICATE KEY (no requiere índice UNIQUE en la tabla)
    $existing = $pdo->prepare('SELECT id FROM asistencias WHERE alumno_id = ? AND grupo_id = ? AND fecha = ? LIMIT 1');
    $existing->execute([$alumno_id, $grupo_id, $fecha]);
    $row = $existing->fetch();

    if ($row) {
        $upd = $pdo->prepare('UPDATE asistencias SET presente = ?, updated_at = CURRENT_TIMESTAMP WHERE id = ?');
        $upd->execute([$presente ? 1 : 0, $row['id']]);
    } else {
        $ins = $pdo->prepare('INSERT INTO asistencias (alumno_id, grupo_id, fecha, presente) VALUES (?, ?, ?, ?)');
        $ins->execute([$alumno_id, $grupo_id, $fecha, $presente ? 1 : 0]);
    }

    jsonResponse(['message' => 'Asistencia registrada'], 201);
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    if ($payload['rol'] !== 'maestro') {
        jsonError('Solo maestros pueden eliminar asistencias', 403);
    }

    $body      = getBody();
    $alumno_id = (int)($body['alumno_id'] ?? 0);
    $grupo_id  = (int)($body['grupo_id']  ?? 0);
    $fecha     = trim($body['fecha']      ?? '');

    if (!$alumno_id || !$grupo_id || !$fecha) {
        jsonError('alumno_id, grupo_id y fecha son requeridos');
    }

    $chk = $pdo->prepare('SELECT id FROM grupos WHERE id = ? AND maestro_id = ? LIMIT 1');
    $chk->execute([$grupo_id, $payload['id']]);
    if (!$chk->fetch()) jsonError('Grupo no autorizado', 403);

    $del = $pdo->prepare('DELETE FROM asistencias WHERE alumno_id = ? AND grupo_id = ? AND fecha = ?');
    $del->execute([$alumno_id, $grupo_id, $fecha]);

    jsonResponse(['message' => 'Registro de asistencia eliminado']);
}

jsonError('Método no permitido', 405);
