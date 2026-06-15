<?php
/**
 * notify.php - EduVynta
 * Envía notificaciones FCM a alumnos/maestros usando Service Account (OAuth2)
 * REQUIERE: columna fcm_token en tabla usuarios
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once 'db.php';

$raw  = file_get_contents('php://input');
$body = json_decode($raw, true);

if (!$body || !isset($body['tipo'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Body inválido o falta "tipo"']);
    exit();
}

$tipo = $body['tipo'];
$data = $body['data'] ?? [];

// ── OAuth2 token desde Service Account ────────────────────────
function getAccessToken(): string {
    $json = $_ENV['FCM_SERVICE_ACCOUNT_JSON'] ?? getenv('FCM_SERVICE_ACCOUNT_JSON');
    if (!$json) throw new Exception('FCM_SERVICE_ACCOUNT_JSON no configurado');

    $sa  = json_decode($json, true);
    if (!$sa) throw new Exception('FCM_SERVICE_ACCOUNT_JSON no es JSON válido');

    $now    = time();
    $header  = base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $payload = base64UrlEncode(json_encode([
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));

    $toSign = "$header.$payload";
    $key    = openssl_pkey_get_private($sa['private_key']);
    if (!$key) throw new Exception('No se pudo cargar la private_key del Service Account');

    openssl_sign($toSign, $sig, $key, OPENSSL_ALGO_SHA256);
    $jwt = "$toSign." . base64UrlEncode($sig);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) throw new Exception("Error OAuth2 ($code): $res");

    $tok = json_decode($res, true);
    return $tok['access_token'] ?? throw new Exception('No se recibió access_token');
}

function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

// ── Enviar FCM a un token ──────────────────────────────────────
function sendFCM(string $fcmToken, string $title, string $bodyText, array $dataPayload, string $accessToken): bool {
    $sa        = json_decode(getenv('FCM_SERVICE_ACCOUNT_JSON'), true);
    $projectId = $sa['project_id'];
    $url       = "https://fcm.googleapis.com/v1/projects/{$projectId}/messages:send";

    $message = [
        'message' => [
            'token'        => $fcmToken,
            'notification' => ['title' => $title, 'body' => $bodyText],
            'data'         => array_map('strval', $dataPayload),
            'android'      => ['priority' => 'high', 'notification' => ['sound' => 'default']],
            'apns'         => ['payload' => ['aps' => ['sound' => 'default']]],
        ],
    ];

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            "Authorization: Bearer $accessToken",
        ],
        CURLOPT_POSTFIELDS => json_encode($message),
    ]);
    $res  = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($code !== 200) {
        error_log("FCM error ($code): $res  token: $fcmToken");
        return false;
    }
    return true;
}

// ── Tokens de alumnos de un grupo ─────────────────────────────
function getTokensAlumnosGrupo(PDO $pdo, int $grupoId): array {
    $stmt = $pdo->prepare("
        SELECT u.fcm_token
        FROM usuarios u
        INNER JOIN usuarios_grupos ug ON ug.usuario_id = u.id
        WHERE ug.grupo_id = :grupo_id
          AND u.rol = 'alumno'
          AND u.fcm_token IS NOT NULL
          AND u.fcm_token != ''
    ");
    $stmt->execute([':grupo_id' => $grupoId]);
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// ── Token de un alumno específico ─────────────────────────────
function getTokenAlumno(PDO $pdo, int $alumnoId): ?string {
    $stmt = $pdo->prepare("
        SELECT fcm_token FROM usuarios
        WHERE id = :id AND fcm_token IS NOT NULL AND fcm_token != ''
    ");
    $stmt->execute([':id' => $alumnoId]);
    return $stmt->fetchColumn() ?: null;
}

// ── Info de actividad ──────────────────────────────────────────
function getActividad(PDO $pdo, int $actividadId): ?array {
    $stmt = $pdo->prepare("
        SELECT a.*, m.nombre AS materia_nombre
        FROM actividades a
        LEFT JOIN materias m ON m.id = a.materia_id
        WHERE a.id = :id
    ");
    $stmt->execute([':id' => $actividadId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Calificación de un alumno en una actividad ─────────────────
function getCalificacion(PDO $pdo, int $actividadId, int $alumnoId): ?array {
    $stmt = $pdo->prepare("
        SELECT * FROM calificaciones
        WHERE actividad_id = :a AND alumno_id = :u
    ");
    $stmt->execute([':a' => $actividadId, ':u' => $alumnoId]);
    return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
}

// ── Lógica principal ───────────────────────────────────────────
try {
    $accessToken = getAccessToken();

    switch ($tipo) {

        // 1. Nueva tarea → todos los alumnos del grupo
        case 'nueva_tarea': {
            $grupoId     = (int)($data['grupo_id'] ?? $body['grupo_id'] ?? 0);
            $actividadId = (int)($data['actividad_id'] ?? 0);

            if (!$grupoId) throw new Exception('grupo_id requerido');

            $actividad = $actividadId ? getActividad($pdo, $actividadId) : null;
            $titulo    = $body['titulo'] ?? 'Nueva tarea publicada';
            $mensaje   = $actividad
                ? "📚 {$actividad['descripcion']} — Entrega: {$actividad['fecha_limite']}"
                : ($body['mensaje'] ?? 'Tienes una nueva tarea pendiente');

            $tokens = getTokensAlumnosGrupo($pdo, $grupoId);

            if (empty($tokens)) {
                echo json_encode(['ok' => true, 'enviados' => 0, 'msg' => 'Sin tokens registrados aún']);
                exit();
            }

            $ok = 0;
            foreach ($tokens as $token) {
                $sent = sendFCM($token, $titulo, $mensaje, [
                    'tipo'         => 'nueva_tarea',
                    'actividad_id' => (string)$actividadId,
                    'grupo_id'     => (string)$grupoId,
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                ], $accessToken);
                if ($sent) $ok++;
            }

            echo json_encode(['ok' => true, 'enviados' => $ok, 'total' => count($tokens)]);
            break;
        }

        // 2. Tarea calificada → al alumno con su nota
        case 'tarea_calificada': {
            $alumnoId    = (int)($data['alumno_id'] ?? 0);
            $actividadId = (int)($data['actividad_id'] ?? 0);

            if (!$alumnoId || !$actividadId) {
                throw new Exception('alumno_id y actividad_id requeridos');
            }

            $actividad    = getActividad($pdo, $actividadId);
            $calificacion = getCalificacion($pdo, $actividadId, $alumnoId);
            $calText      = $calificacion ? $calificacion['calificacion'] : ($data['calificacion'] ?? '—');
            $desc         = $actividad['descripcion'] ?? 'tu actividad';

            $titulo  = '✅ ¡Tu tarea fue calificada!';
            $mensaje = "Obtuviste $calText en: $desc";

            $token = getTokenAlumno($pdo, $alumnoId);
            if (!$token) {
                echo json_encode(['ok' => true, 'enviados' => 0, 'msg' => 'Alumno sin token FCM']);
                exit();
            }

            $sent = sendFCM($token, $titulo, $mensaje, [
                'tipo'         => 'tarea_calificada',
                'actividad_id' => (string)$actividadId,
                'alumno_id'    => (string)$alumnoId,
                'calificacion' => (string)$calText,
                'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
            ], $accessToken);

            echo json_encode(['ok' => $sent, 'enviados' => $sent ? 1 : 0]);
            break;
        }

        // 3. Recordatorio → alumnos que NO han entregado
        case 'recordatorio_tarea': {
            $grupoId     = (int)($data['grupo_id'] ?? 0);
            $actividadId = (int)($data['actividad_id'] ?? 0);

            if (!$grupoId || !$actividadId) {
                throw new Exception('grupo_id y actividad_id requeridos');
            }

            $actividad = getActividad($pdo, $actividadId);
            if (!$actividad) throw new Exception('Actividad no encontrada');

            $stmt = $pdo->prepare("
                SELECT u.fcm_token
                FROM usuarios u
                INNER JOIN usuarios_grupos ug ON ug.usuario_id = u.id
                WHERE ug.grupo_id = :grupo_id
                  AND u.rol = 'alumno'
                  AND u.fcm_token IS NOT NULL AND u.fcm_token != ''
                  AND u.id NOT IN (
                      SELECT alumno_id FROM entregas WHERE actividad_id = :actividad_id
                  )
            ");
            $stmt->execute([':grupo_id' => $grupoId, ':actividad_id' => $actividadId]);
            $tokens = $stmt->fetchAll(PDO::FETCH_COLUMN);

            $titulo  = '⏰ Recuerda entregar tu tarea';
            $mensaje = "Pendiente: {$actividad['descripcion']} — Vence: {$actividad['fecha_limite']}";

            $ok = 0;
            foreach ($tokens as $token) {
                if (sendFCM($token, $titulo, $mensaje, [
                    'tipo'         => 'recordatorio_tarea',
                    'actividad_id' => (string)$actividadId,
                    'grupo_id'     => (string)$grupoId,
                    'click_action' => 'FLUTTER_NOTIFICATION_CLICK',
                ], $accessToken)) $ok++;
            }

            echo json_encode(['ok' => true, 'enviados' => $ok, 'total' => count($tokens)]);
            break;
        }

        default:
            http_response_code(400);
            echo json_encode(['error' => "Tipo desconocido: $tipo"]);
    }

} catch (Exception $e) {
    http_response_code(500);
    error_log('[notify.php] ' . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
