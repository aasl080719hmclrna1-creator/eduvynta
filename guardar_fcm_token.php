<?php
/**
 * guardar_fcm_token.php - EduVynta
 * El app Flutter llama esto al iniciar sesión para registrar/actualizar
 * el FCM token del dispositivo del usuario.
 * 
 * POST body: { "fcm_token": "eXXXXX..." }
 * Header: Authorization: Bearer <jwt>
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
require_once 'auth.php'; // setea $usuario

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Método no permitido']);
    exit();
}

$raw      = file_get_contents('php://input');
$body     = json_decode($raw, true);
$fcmToken = trim($body['fcm_token'] ?? '');

if (!$fcmToken) {
    http_response_code(400);
    echo json_encode(['error' => 'fcm_token requerido']);
    exit();
}

try {
    $stmt = $pdo->prepare("
        UPDATE usuarios SET fcm_token = :fcm_token WHERE id = :id
    ");
    $stmt->execute([':fcm_token' => $fcmToken, ':id' => $usuario['id']]);

    echo json_encode(['ok' => true, 'mensaje' => 'Token FCM actualizado']);
} catch (Exception $e) {
    http_response_code(500);
    error_log('[guardar_fcm_token.php] ' . $e->getMessage());
    echo json_encode(['error' => $e->getMessage()]);
}
