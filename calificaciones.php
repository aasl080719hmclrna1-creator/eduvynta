<?php
/**
 * calificaciones.php - EduVynta
 * El maestro sube/actualiza la calificación de un alumno en una actividad.
 * Al guardar, dispara notificación FCM al alumno automáticamente.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db.php';
require_once 'auth.php'; // tu middleware JWT → $usuario

// Solo maestros pueden calificar
if (($usuario['rol'] ?? '') !== 'maestro') {
    http_response_code(403);
    echo json_encode(['error' => 'Solo maestros pueden calificar']);
    exit();
}

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: listar calificaciones de una actividad ────────────────
if ($method === 'GET') {
    $actividadId = (int)($_GET['actividad_id'] ?? 0);
    if (!$actividadId) {
        http_response_code(400);
        echo json_encode(['error' => 'actividad_id requerido']);
        exit();
    }

    $stmt = $pdo->prepare("
        SELECT c.*, 
               CONCAT(u.nombre, ' ', u.apellido) AS alumno_nombre,
               u.email AS alumno_email
        FROM calificaciones c
        INNER JOIN usuarios u ON u.id = c.alumno_id
        WHERE c.actividad_id = :actividad_id
        ORDER BY u.nombre
    ");
    $stmt->execute([':actividad_id' => $actividadId]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    exit();
}

// ── POST: guardar/actualizar calificación ──────────────────────
if ($method === 'POST') {
    $raw  = file_get_contents('php://input');
    $body = json_decode($raw, true);

    $actividadId  = (int)($body['actividad_id'] ?? 0);
    $alumnoId     = (int)($body['alumno_id'] ?? 0);
    $calificacion = $body['calificacion'] ?? null;
    $comentario   = trim($body['comentario'] ?? '');

    if (!$actividadId || !$alumnoId || $calificacion === null) {
        http_response_code(400);
        echo json_encode(['error' => 'actividad_id, alumno_id y calificacion son requeridos']);
        exit();
    }

    // Validar rango 0-100
    $calNum = floatval($calificacion);
    if ($calNum < 0 || $calNum > 100) {
        http_response_code(400);
        echo json_encode(['error' => 'La calificación debe estar entre 0 y 100']);
        exit();
    }

    try {
        // Upsert: actualizar si ya existe, insertar si no
        $stmt = $pdo->prepare("
            INSERT INTO calificaciones (actividad_id, alumno_id, calificacion, comentario, maestro_id, created_at, updated_at)
            VALUES (:actividad_id, :alumno_id, :calificacion, :comentario, :maestro_id, NOW(), NOW())
            ON DUPLICATE KEY UPDATE
                calificacion = VALUES(calificacion),
                comentario   = VALUES(comentario),
                updated_at   = NOW()
        ");
        $stmt->execute([
            ':actividad_id' => $actividadId,
            ':alumno_id'    => $alumnoId,
            ':calificacion' => $calNum,
            ':comentario'   => $comentario,
            ':maestro_id'   => $usuario['id'],
        ]);

        // ── Disparar notificación FCM al alumno ────────────────
        notificarCalificacion($actividadId, $alumnoId, $calNum);

        echo json_encode([
            'ok'           => true,
            'mensaje'      => 'Calificación guardada',
            'calificacion' => $calNum,
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        error_log('[calificaciones.php] ' . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);

// ── Helper: llama notify.php internamente ──────────────────────
function notificarCalificacion(int $actividadId, int $alumnoId, float $cal): void {
    // Llamada interna al mismo servidor (no sale a internet)
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
             . '://' . $_SERVER['HTTP_HOST']
             . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

    $payload = json_encode([
        'tipo'    => 'tarea_calificada',
        'titulo'  => '¡Tu tarea fue calificada!',
        'mensaje' => "Obtuviste $cal",
        'data'    => [
            'tipo'         => 'tarea_calificada',
            'actividad_id' => (string)$actividadId,
            'alumno_id'    => (string)$alumnoId,
            'calificacion' => (string)$cal,
        ],
    ]);

    $ch = curl_init("$baseUrl/notify.php");
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/json'],
        CURLOPT_POSTFIELDS     => $payload,
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        error_log("[calificaciones.php] notify.php respondió $code: $res");
    }
}
