<?php
/**
 * notify.php  –  EduVynta
 *
 * Dos responsabilidades:
 *   PUT  → El alumno registra/actualiza su FCM token al iniciar sesión.
 *   POST → El maestro (o el sistema) dispara una notificación a uno o varios alumnos.
 *
 * IMPORTANTE: Para que funcione, en Railway debes agregar la variable de entorno:
 *   FCM_SERVICE_ACCOUNT_JSON  →  contenido del JSON de cuenta de servicio de Firebase
 *   (descárgalo en Firebase Console → Configuración → Cuentas de servicio → Generar clave privada)
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

// ── PUT: el alumno guarda su FCM token ────────────────────────────────────────
if ($method === 'PUT') {
    $body  = getBody();
    $token = trim($body['fcm_token'] ?? '');

    if (!$token) jsonError('fcm_token es requerido');

    // Aseguramos que la columna exista (idempotente)
    $pdo->exec("ALTER TABLE usuarios ADD COLUMN IF NOT EXISTS fcm_token VARCHAR(512) NULL");

    $stmt = $pdo->prepare('UPDATE usuarios SET fcm_token = ? WHERE id = ?');
    $stmt->execute([$token, $payload['id']]);

    jsonResponse(['message' => 'Token FCM registrado']);
}

// ── POST: enviar notificación push a alumno(s) ────────────────────────────────
if ($method === 'POST') {
    if ($payload['rol'] !== 'maestro') {
        jsonError('Solo maestros pueden enviar notificaciones', 403);
    }

    $body      = getBody();
    $tipo      = $body['tipo']       ?? '';   // 'nueva_tarea' | 'tarea_modificada' | 'calificacion'
    $alumno_id = (int)($body['alumno_id'] ?? 0);
    $grupo_id  = (int)($body['grupo_id']  ?? 0);
    $titulo    = trim($body['titulo']     ?? '');
    $mensaje   = trim($body['mensaje']    ?? '');
    $data      = $body['data']            ?? [];

    if (!$tipo || !$titulo || !$mensaje) {
        jsonError('tipo, titulo y mensaje son requeridos');
    }

    // Obtener tokens FCM según el alcance
    if ($alumno_id > 0) {
        // Notificación a UN alumno específico (calificación)
        $stmt = $pdo->prepare(
            'SELECT fcm_token FROM usuarios WHERE id = ? AND fcm_token IS NOT NULL LIMIT 1'
        );
        $stmt->execute([$alumno_id]);
        $tokens = array_column($stmt->fetchAll(), 'fcm_token');
    } elseif ($grupo_id > 0) {
        // Notificación a TODOS los alumnos del grupo (nueva tarea / tarea modificada)
        // Verificar que el grupo pertenece al maestro
        $chk = $pdo->prepare('SELECT id FROM grupos WHERE id = ? AND maestro_id = ? LIMIT 1');
        $chk->execute([$grupo_id, $payload['id']]);
        if (!$chk->fetch()) jsonError('Grupo no autorizado', 403);

        $stmt = $pdo->prepare(
            'SELECT u.fcm_token
             FROM alumnos_grupos ag
             JOIN usuarios u ON u.id = ag.alumno_id
             WHERE ag.grupo_id = ? AND u.fcm_token IS NOT NULL'
        );
        $stmt->execute([$grupo_id]);
        $tokens = array_column($stmt->fetchAll(), 'fcm_token');
    } else {
        jsonError('Debes indicar alumno_id o grupo_id');
    }

    if (empty($tokens)) {
        jsonResponse(['message' => 'Sin dispositivos registrados', 'enviados' => 0]);
    }

    // Enviar via FCM v1 (HTTP)
    $enviados = 0;
    $errores  = [];
    foreach ($tokens as $fcmToken) {
        $result = sendFcmNotification($fcmToken, $titulo, $mensaje, $data);
        if ($result['ok']) {
            $enviados++;
        } else {
            $errores[] = $result['error'];
        }
    }

    jsonResponse([
        'message'  => "Notificaciones enviadas: $enviados",
        'enviados' => $enviados,
        'errores'  => $errores,
    ]);
}

jsonError('Método no permitido', 405);

// ─────────────────────────────────────────────────────────────────────────────
// Helpers FCM v1
// ─────────────────────────────────────────────────────────────────────────────

/**
 * Obtiene un Access Token OAuth2 usando la cuenta de servicio de Firebase.
 * El JSON de la cuenta de servicio debe estar en la variable de entorno FCM_SERVICE_ACCOUNT_JSON.
 */
function getFcmAccessToken(): string {
    $json = $_ENV['FCM_SERVICE_ACCOUNT_JSON'] ?? getenv('FCM_SERVICE_ACCOUNT_JSON') ?? '';
    if (!$json) throw new Exception('FCM_SERVICE_ACCOUNT_JSON no configurado en variables de entorno');

    $sa = json_decode($json, true);
    if (!$sa) throw new Exception('FCM_SERVICE_ACCOUNT_JSON tiene formato inválido');

    $now    = time();
    $header = base64UrlEncode(json_encode(['alg' => 'RS256', 'typ' => 'JWT']));
    $claim  = base64UrlEncode(json_encode([
        'iss'   => $sa['client_email'],
        'scope' => 'https://www.googleapis.com/auth/firebase.messaging',
        'aud'   => 'https://oauth2.googleapis.com/token',
        'iat'   => $now,
        'exp'   => $now + 3600,
    ]));

    $sigInput = "$header.$claim";
    openssl_sign($sigInput, $sig, $sa['private_key'], 'SHA256');
    $jwt = "$sigInput." . base64UrlEncode($sig);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
            'assertion'  => $jwt,
        ]),
    ]);
    $resp = curl_exec($ch);
    curl_close($ch);

    $data = json_decode($resp, true);
    if (empty($data['access_token'])) {
        throw new Exception('No se pudo obtener access_token de Google: ' . $resp);
    }
    return $data['access_token'];
}

function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

/**
 * Envía una notificación FCM v1 a un token específico.
 */
function sendFcmNotification(string $token, string $titulo, string $cuerpo, array $data = []): array {
    try {
        $accessToken = getFcmAccessToken();

        // Obtener project_id del JSON de cuenta de servicio
        $jsonStr   = $_ENV['FCM_SERVICE_ACCOUNT_JSON'] ?? getenv('FCM_SERVICE_ACCOUNT_JSON') ?? '';
        $sa        = json_decode($jsonStr, true);
        $projectId = $sa['project_id'] ?? 'aduvynta';

        $url     = "https://fcm.googleapis.com/v1/projects/$projectId/messages:send";
        $payload = [
            'message' => [
                'token' => $token,
                'notification' => [
                    'title' => $titulo,
                    'body'  => $cuerpo,
                ],
                'android' => [
                    'priority' => 'high',
                    'notification' => [
                        'channel_id' => 'eduvynta_channel',
                        'sound'      => 'default',
                    ],
                ],
                'data' => array_map('strval', $data), // FCM data solo acepta strings
            ],
        ];

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $accessToken,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS => json_encode($payload),
        ]);
        $resp    = curl_exec($ch);
        $code    = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($code >= 200 && $code < 300) {
            return ['ok' => true];
        }
        return ['ok' => false, 'error' => "FCM error $code: $resp"];
    } catch (Exception $e) {
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}
