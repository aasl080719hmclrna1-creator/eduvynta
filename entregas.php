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

    if ($payload['rol'] === 'maestro') {
        // Maestro: ve todas las entregas de una actividad
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

    } else {
        // Alumno: ve su propia entrega para una actividad
        $actividad_id = (int)($_GET['actividad_id'] ?? 0);
        if (!$actividad_id) jsonError('actividad_id requerido');

        $stmt = $pdo->prepare('
            SELECT e.*
            FROM entregas e
            WHERE e.actividad_id = ? AND e.alumno_id = ?
            LIMIT 1
        ');
        $stmt->execute([$actividad_id, $payload['id']]);
        $entrega = $stmt->fetch();

        // Si no existe entrega devolvemos objeto vacío (no error)
        jsonResponse($entrega ?: (object)[]);
    }
}

// ── POST: alumno sube entrega ─────────────────────────────────────────────────
if ($method === 'POST') {
    if ($payload['rol'] !== 'alumno') {
        jsonError('Solo alumnos pueden entregar tareas', 403);
    }

    $contentType = $_SERVER['CONTENT_TYPE'] ?? '';
    $isMultipart = str_contains($contentType, 'multipart/form-data');

    if ($isMultipart) {
        $actividad_id = (int)($_POST['actividad_id'] ?? 0);
        $comentario   = trim($_POST['comentario'] ?? '');
    } else {
        $body         = getBody();
        $actividad_id = (int)($body['actividad_id'] ?? 0);
        $comentario   = trim($body['comentario'] ?? '');
    }

    if (!$actividad_id) jsonError('actividad_id requerido');

    // Verificar que el alumno pertenece al grupo de la actividad
    $chk = $pdo->prepare('
        SELECT a.id FROM actividades a
        JOIN alumnos_grupos ag ON ag.grupo_id = a.grupo_id
        WHERE a.id = ? AND ag.alumno_id = ? LIMIT 1
    ');
    $chk->execute([$actividad_id, $payload['id']]);
    if (!$chk->fetch()) jsonError('Actividad no encontrada o no autorizado', 403);

    // Archivo adjunto
    $archivo_url = null;
    if ($isMultipart && isset($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = __DIR__ . '/uploads/entregas/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $ext      = pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION);
        $safeName = 'entrega_' . $payload['id'] . '_' . $actividad_id . '_' . time() . '.' . $ext;

        if ($_FILES['archivo']['size'] > 20 * 1024 * 1024) jsonError('El archivo supera los 20 MB');

        if (move_uploaded_file($_FILES['archivo']['tmp_name'], $uploadDir . $safeName)) {
            $archivo_url = 'uploads/entregas/' . $safeName;
        } else {
            jsonError('No se pudo guardar el archivo');
        }
    }

    $ins = $pdo->prepare('
        INSERT INTO entregas (actividad_id, alumno_id, comentario, archivo_url)
        VALUES (?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
            comentario    = VALUES(comentario),
            archivo_url   = COALESCE(VALUES(archivo_url), archivo_url),
            fecha_entrega = CURRENT_TIMESTAMP
    ');
    $ins->execute([$actividad_id, $payload['id'], $comentario, $archivo_url]);

    jsonResponse(['message' => 'Entrega registrada', 'archivo_url' => $archivo_url], 201);
}

// ── PUT: maestro califica ─────────────────────────────────────────────────────
if ($method === 'PUT') {
    if ($payload['rol'] !== 'maestro') jsonError('Solo maestros pueden calificar', 403);

    $body              = getBody();
    $entrega_id        = (int)($body['entrega_id']  ?? 0);
    $calificacion      = isset($body['calificacion']) ? (float)$body['calificacion'] : null;
    $retroalimentacion = trim($body['retroalimentacion'] ?? '');

    if (!$entrega_id || $calificacion === null) jsonError('entrega_id y calificacion requeridos');
    if ($calificacion < 0 || $calificacion > 100) jsonError('Calificación debe estar entre 0 y 100');

    $pdo->prepare('UPDATE entregas SET calificacion = ?, retroalimentacion = ? WHERE id = ?')
        ->execute([$calificacion, $retroalimentacion, $entrega_id]);

    jsonResponse(['message' => 'Calificación registrada']);
}

jsonError('Método no permitido', 405);
