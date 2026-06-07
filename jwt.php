<?php
// JWT_SECRET debe configurarse como variable de entorno en Railway
// Ejemplo en Railway: JWT_SECRET=tu_secreto_muy_largo_y_seguro_aqui
define('JWT_SECRET', $_ENV['JWT_SECRET'] ?? getenv('JWT_SECRET') ?? 'CAMBIA_ESTE_SECRET_EN_PRODUCCION_32CHARS!!');
define('JWT_EXPIRY', 86400); // 24 horas en segundos

function jwtEncode(array $payload): string {
    $header         = base64UrlEncode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
    $payload['exp'] = time() + JWT_EXPIRY;
    $payload['iat'] = time(); // issued at
    $encodedPayload = base64UrlEncode(json_encode($payload));
    $sig            = base64UrlEncode(hash_hmac('sha256', "$header.$encodedPayload", JWT_SECRET, true));
    return "$header.$encodedPayload.$sig";
}

function jwtDecode(string $token): ?array {
    $parts = explode('.', $token);
    if (count($parts) !== 3) return null;

    [$header, $payload, $sig] = $parts;

    // Verificar firma
    $expected = base64UrlEncode(hash_hmac('sha256', "$header.$payload", JWT_SECRET, true));
    if (!hash_equals($expected, $sig)) return null;

    // Decodificar payload
    $data = json_decode(base64UrlDecode($payload), true);
    if (!$data) return null;

    // Verificar expiración
    if (isset($data['exp']) && $data['exp'] < time()) return null;

    return $data;
}

function base64UrlEncode(string $data): string {
    return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
}

function base64UrlDecode(string $data): string {
    return base64_decode(strtr($data, '-_', '+/') . str_repeat('=', 4 - strlen($data) % 4));
}
