<?php
/**
 * actividades.php - EduVynta
 * CRUD de actividades.
 * Al crear una actividad, dispara automáticamente la notificación FCM
 * a todos los alumnos del grupo.
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db.php';
require_once 'auth.php'; // setea $usuario con rol, id, etc.

$method = $_SERVER['REQUEST_METHOD'];

// ── GET: listar actividades de un grupo ───────────────────────
if ($method === 'GET') {
    $grupoId = (int)($_GET['grupo_id'] ?? 0);
    if (!$grupoId) {
        http_response_code(400);
        echo json_encode(['error' => 'grupo_id requerido']);
        exit();
    }

    $stmt = $pdo->prepare("
        SELECT a.*, 
               m.nombre AS materia_nombre,
               CONCAT(u.nombre, ' ', u.apellido) AS maestro_nombre
        FROM actividades a
        LEFT JOIN materias m ON m.id = a.materia_id
        LEFT JOIN usuarios u ON u.id = a.maestro_id
        WHERE a.grupo_id = :grupo_id
        ORDER BY a.fecha_limite ASC, a.created_at DESC
    ");
    $stmt->execute([':grupo_id' => $grupoId]);
    $actividades = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Si el usuario es alumno, adjuntar su calificación a cada actividad
    if (($usuario['rol'] ?? '') === 'alumno') {
        $alumnoId = $usuario['id'];
        foreach ($actividades as &$act) {
            $stmtCal = $pdo->prepare("
                SELECT calificacion, comentario, updated_at
                FROM calificaciones
                WHERE actividad_id = :actividad_id AND alumno_id = :alumno_id
            ");
            $stmtCal->execute([':actividad_id' => $act['id'], ':alumno_id' => $alumnoId]);
            $cal = $stmtCal->fetch(PDO::FETCH_ASSOC);
            $act['calificacion_alumno'] = $cal ?: null;
        }
    }

    echo json_encode($actividades);
    exit();
}

// ── POST: crear actividad ─────────────────────────────────────
if ($method === 'POST') {
    if (($usuario['rol'] ?? '') !== 'maestro') {
        http_response_code(403);
        echo json_encode(['error' => 'Solo maestros pueden crear actividades']);
        exit();
    }

    // Soporta multipart (con archivo) y JSON
    if (!empty($_POST)) {
        $grupoId     = (int)($_POST['grupo_id'] ?? 0);
        $descripcion = trim($_POST['descripcion'] ?? '');
        $fechaLimite = $_POST['fecha_limite'] ?? '';
        $materiaId   = $_POST['materia_id'] ? (int)$_POST['materia_id'] : null;
    } else {
        $raw         = file_get_contents('php://input');
        $body        = json_decode($raw, true) ?? [];
        $grupoId     = (int)($body['grupo_id'] ?? 0);
        $descripcion = trim($body['descripcion'] ?? '');
        $fechaLimite = $body['fecha_limite'] ?? '';
        $materiaId   = isset($body['materia_id']) ? (int)$body['materia_id'] : null;
    }

    if (!$grupoId || !$descripcion || !$fechaLimite) {
        http_response_code(400);
        echo json_encode(['error' => 'grupo_id, descripcion y fecha_limite son requeridos']);
        exit();
    }

    // Manejar archivo adjunto (opcional)
    $archivoUrl = null;
    if (!empty($_FILES['archivo']) && $_FILES['archivo']['error'] === UPLOAD_ERR_OK) {
        $uploadDir = 'uploads/actividades/';
        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

        $ext        = pathinfo($_FILES['archivo']['name'], PATHINFO_EXTENSION);
        $nombre     = 'actividad_' . $usuario['id'] . '_' . time() . '.' . $ext;
        $destino    = $uploadDir . $nombre;

        if (move_uploaded_file($_FILES['archivo']['tmp_name'], $destino)) {
            $archivoUrl = $destino;
        }
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO actividades (maestro_id, grupo_id, materia_id, descripcion, fecha_limite, archivo_url, created_at)
            VALUES (:maestro_id, :grupo_id, :materia_id, :descripcion, :fecha_limite, :archivo_url, NOW())
        ");
        $stmt->execute([
            ':maestro_id'   => $usuario['id'],
            ':grupo_id'     => $grupoId,
            ':materia_id'   => $materiaId,
            ':descripcion'  => $descripcion,
            ':fecha_limite' => $fechaLimite,
            ':archivo_url'  => $archivoUrl,
        ]);

        $actividadId = (int)$pdo->lastInsertId();

        // ── Notificar a todos los alumnos del grupo ────────────
        notificarNuevaTarea($actividadId, $grupoId, $descripcion, $fechaLimite);

        echo json_encode([
            'ok'          => true,
            'actividad_id' => $actividadId,
            'mensaje'     => 'Actividad creada y alumnos notificados',
        ]);

    } catch (Exception $e) {
        http_response_code(500);
        error_log('[actividades.php POST] ' . $e->getMessage());
        echo json_encode(['error' => $e->getMessage()]);
    }
    exit();
}

// ── DELETE: eliminar actividad ────────────────────────────────
if ($method === 'DELETE') {
    if (($usuario['rol'] ?? '') !== 'maestro') {
        http_response_code(403);
        echo json_encode(['error' => 'Solo maestros pueden eliminar actividades']);
        exit();
    }

    $actividadId = (int)($_GET['id'] ?? 0);
    if (!$actividadId) {
        http_response_code(400);
        echo json_encode(['error' => 'id requerido']);
        exit();
    }

    $stmt = $pdo->prepare("DELETE FROM actividades WHERE id = :id AND maestro_id = :maestro_id");
    $stmt->execute([':id' => $actividadId, ':maestro_id' => $usuario['id']]);

    echo json_encode(['ok' => true, 'eliminado' => $stmt->rowCount() > 0]);
    exit();
}

http_response_code(405);
echo json_encode(['error' => 'Método no permitido']);

// ── Helper: notificar nueva tarea ──────────────────────────────
function notificarNuevaTarea(int $actividadId, int $grupoId, string $descripcion, string $fechaLimite): void {
    $baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
             . '://' . $_SERVER['HTTP_HOST']
             . rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

    $payload = json_encode([
        'tipo'    => 'nueva_tarea',
        'titulo'  => 'Nueva tarea publicada',
        'mensaje' => "📚 $descripcion — Entrega: $fechaLimite",
        'data'    => [
            'tipo'         => 'nueva_tarea',
            'actividad_id' => (string)$actividadId,
            'grupo_id'     => (string)$grupoId,
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
        error_log("[actividades.php] notify.php respondió $code: $res");
    }
}
