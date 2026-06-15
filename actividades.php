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
    $id = (int)($_GET['id'] ?? 0);

    if ($id > 0) {
        $stmt = $pdo->prepare('
            SELECT a.*, m.nombre AS materia_nombre
            FROM actividades a
            LEFT JOIN materias m ON m.id = a.materia_id
            WHERE a.id = ? LIMIT 1
        ');
        $stmt->execute([$id]);
        $actividad = $stmt->fetch();
        if (!$actividad) jsonError('Actividad no encontrada', 404);
        jsonResponse($actividad);
    }

    $grupo_id = (int)($_GET['grupo_id'] ?? 0);

    if ($payload['rol'] === 'maestro') {
        $sql    = 'SELECT a.*, m.nombre AS materia_nombre FROM actividades a LEFT JOIN materias m ON m.id = a.materia_id WHERE a.maestro_id = ?';
        $params = [$payload['id']];
        if ($grupo_id > 0) { $sql .= ' AND a.grupo_id = ?'; $params[] = $grupo_id; }
        $sql .= ' ORDER BY a.fecha_limite DESC';
    } else {
        $sql    = 'SELECT a.*, m.nombre AS materia_nombre FROM actividades a JOIN alumnos_grupos ag ON ag.grupo_id = a.grupo_id LEFT JOIN materias m ON m.id = a.materia_id WHERE ag.alumno_id = ?';
        $params = [$payload['id']];
        if ($grupo_id > 0) { $sql .= ' AND a.grupo_id = ?'; $params[] = $grupo_id; }
        $sql .= ' ORDER BY a.fecha_limite ASC';
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    jsonResponse($stmt->fetchAll());
}

// ── POST: crear actividad (soporta JSON y multipart/form-data con archivo) ────
if ($method === 'POST') {
    if ($payload['rol'] !== 'maestro') jsonError('Solo maestros pueden crear actividades', 403);

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isMultipart = str_contains($contentType, 'multipart/form-data');

    if ($isMultipart) {
        $descripcion  = trim($_POST['descripcion']  ?? '');
        $fecha_limite = trim($_POST['fecha_limite'] ?? '');
        $grupo_id     = (int)($_POST['grupo_id']    ?? 0);
        $materia_id   = (int)($_POST['materia_id']  ?? 0);
    } else {
        $body         = getBody();
        $descripcion  = trim($body['descripcion']  ?? '');
        $fecha_limite = trim($body['fecha_limite'] ?? '');
        $grupo_id     = (int)($body['grupo_id']    ?? 0);
        $materia_id   = (int)($body['materia_id']  ?? 0);
    }

    if (!$descripcion || !$fecha_limite) jsonError('descripcion y fecha_limite son requeridos');

    // ── Subida de archivo opcional ────────────────────────────────────────────
    $archivo_url = null;
    if ($isMultipart && isset($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/uploads/actividades/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        if ($_FILES['archivo']['size'] > 30 * 1024 * 1024) {
            jsonError('El archivo supera los 30 MB');
        }

        $ext      = strtolower(pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION));
        $allowed  = ['pdf', 'jpg', 'jpeg', 'png', 'gif', 'webp', 'doc', 'docx', 'ppt', 'pptx', 'xls', 'xlsx', 'txt'];
        if (!in_array($ext, $allowed)) {
            jsonError('Tipo de archivo no permitido');
        }

        $safeName = 'actividad_' . $payload['id'] . '_' . time() . '.' . $ext;
        if (!move_uploaded_file($_FILES['archivo']['tmp_name'], $uploadDir . $safeName)) {
            jsonError('No se pudo guardar el archivo');
        }
        $archivo_url = 'uploads/actividades/' . $safeName;
    }

    // Aseguramos que la columna archivo_url exista (idempotente)
    $pdo->exec("ALTER TABLE actividades ADD COLUMN IF NOT EXISTS archivo_url VARCHAR(512) NULL");

    $ins = $pdo->prepare('
        INSERT INTO actividades (maestro_id, grupo_id, materia_id, descripcion, fecha_limite, archivo_url)
        VALUES (?, ?, ?, ?, ?, ?)
    ');
    $ins->execute([
        $payload['id'],
        $grupo_id   ?: null,
        $materia_id ?: null,
        $descripcion,
        $fecha_limite,
        $archivo_url,
    ]);

    jsonResponse(['message' => 'Actividad creada', 'id' => (int)$pdo->lastInsertId(), 'archivo_url' => $archivo_url], 201);
}

// ── PUT: editar actividad ─────────────────────────────────────────────────────
if ($method === 'PUT') {
    if ($payload['rol'] !== 'maestro') jsonError('Solo maestros pueden editar actividades', 403);

    $body         = getBody();
    $id           = (int)($body['id']          ?? 0);
    $descripcion  = trim($body['descripcion']  ?? '');
    $fecha_limite = trim($body['fecha_limite'] ?? '');

    if (!$id) jsonError('id es requerido');

    $chk = $pdo->prepare('SELECT id, grupo_id FROM actividades WHERE id = ? AND maestro_id = ? LIMIT 1');
    $chk->execute([$id, $payload['id']]);
    $row = $chk->fetch();
    if (!$row) jsonError('Actividad no encontrada o no autorizado', 403);

    $updates = []; $params = [];
    if ($descripcion)  { $updates[] = 'descripcion = ?';  $params[] = $descripcion; }
    if ($fecha_limite) { $updates[] = 'fecha_limite = ?'; $params[] = $fecha_limite; }
    if (empty($updates)) jsonError('Nada que actualizar');

    $params[] = $id;
    $pdo->prepare('UPDATE actividades SET ' . implode(', ', $updates) . ' WHERE id = ?')->execute($params);

    jsonResponse(['message' => 'Actividad actualizada', 'grupo_id' => (int)$row['grupo_id']]);
}

// ── DELETE ────────────────────────────────────────────────────────────────────
if ($method === 'DELETE') {
    if ($payload['rol'] !== 'maestro') jsonError('Solo maestros pueden eliminar actividades', 403);

    $body = getBody();
    $id   = (int)($body['id'] ?? 0);
    if (!$id) jsonError('id es requerido');

    $chk = $pdo->prepare('SELECT id FROM actividades WHERE id = ? AND maestro_id = ? LIMIT 1');
    $chk->execute([$id, $payload['id']]);
    if (!$chk->fetch()) jsonError('Actividad no encontrada o no autorizado', 403);

    $pdo->prepare('DELETE FROM actividades WHERE id = ?')->execute([$id]);
    jsonResponse(['message' => 'Actividad eliminada']);
}

jsonError('Método no permitido', 405);
