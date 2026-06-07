<?php
require_once __DIR__ . '/jwt.php';

function requireAuth(): array {
    $headers = getallheaders();
    $auth    = $headers['Authorization'] ?? $headers['authorization'] ?? '';

    if (!str_starts_with($auth, 'Bearer ')) {
        http_response_code(401);
        echo json_encode(['error' => 'Token requerido']);
        exit;
    }

    $token   = substr($auth, 7);
    $payload = jwtDecode($token);

    if (!$payload) {
        http_response_code(401);
        echo json_encode(['error' => 'Token inválido o expirado']);
        exit;
    }

    return $payload;
}

function requireRol(string $rol): array {
    $payload = requireAuth();
    if ($payload['rol'] !== $rol) {
        http_response_code(403);
        echo json_encode(['error' => 'Acceso no autorizado para este rol']);
        exit;
    }
    return $payload;
}
