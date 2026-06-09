<?php
ob_start();
ini_set('display_errors', 0);
error_reporting(0);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/response.php';

setCorsHeaders();
ob_end_clean();

$payload = requireAuth();
$pdo     = getDB();
$method  = $_SERVER['REQUEST_METHOD'];

// ── GET: lista de grupos ──────────────────────────────────────────────────
if ($method === 'GET') {
    if ($payload['rol'] === 'maestro') {
        $stmt = $pdo->prepare('
            SELECT g.id, g.nombre, g.semestre, g.codigo_acceso, g.maestro_id,
                   COUNT(ag.alumno_id) AS total_alumnos
            FROM grupos g
            LEFT JOIN alumnos_grupos ag ON ag.grupo_id = g.id
            WHERE g.maestro_id = ?
            GROUP BY g.id
            ORDER BY g.nombre ASC
        ');
        $stmt->execute([$payload['id']]);
    } else {
        $stmt = $pdo->prepare('
            SELECT g.id, g.nombre, g.semestre, g.codigo_acceso,
                   u.nombre AS maestro_nombre
            FROM alumnos_grupos ag
            JOIN grupos   g ON g.id = ag.grupo_id
            JOIN usuarios u ON u.id = g.maestro_id
            WHERE ag.alumno_id = ?
            ORDER BY g.nombre ASC
        ');
        $stmt->execute([$payload['id']]);
    }
    jsonResponse($stmt->fetchAll());
}

// ── POST: crear grupo (solo maestro) ─────────────────────────────────────
if ($method === 'POST') {
    if ($payload['rol'] !== 'maestro') jsonError('Solo maestros pueden crear grupos', 403);

    $body     = getBody();
    $nombre   = trim($body['nombre']   ?? '');
    $semestre = (int)($body['semestre'] ?? 1);

    if (!$nombre) jsonError('nombre es requerido');

    do {
        $codigo = strtoupper(substr(md5(uniqid(rand(), true)), 0, 6));
        $chk = $pdo->prepare('SELECT id FROM grupos WHERE codigo_acceso = ? LIMIT 1');
        $chk->execute([$codigo]);
    } while ($chk->fetch());

    $ins = $pdo->prepare('INSERT INTO grupos (nombre, semestre, maestro_id, codigo_acceso) VALUES (?, ?, ?, ?)');
    $ins->execute([$nombre, $semestre, $payload['id'], $codigo]);

    jsonResponse([
        'message'       => 'Grupo creado',
        'id'            => (int)$pdo->lastInsertId(),
        'codigo_acceso' => $codigo
    ], 201);
}

// ── PUT: alumno se une a un grupo con código ──────────────────────────────
if ($method === 'PUT') {
    if ($payload['rol'] !== 'alumno') jsonError('Solo alumnos pueden unirse a grupos', 403);

    $body   = getBody();
    $codigo = strtoupper(trim($body['codigo'] ?? ''));

    if (strlen($codigo) < 4) jsonError('El código debe tener al menos 4 caracteres');

    $grp = $pdo->prepare('SELECT id, nombre FROM grupos WHERE codigo_acceso = ? LIMIT 1');
    $grp->execute([$codigo]);
    $grupo = $grp->fetch();

    if (!$grupo) jsonError('Código inválido o clase no encontrada', 404);

    $ya = $pdo->prepare('SELECT id FROM alumnos_grupos WHERE alumno_id = ? AND grupo_id = ? LIMIT 1');
    $ya->execute([$payload['id'], $grupo['id']]);
    if ($ya->fetch()) jsonError('Ya estás inscrito en esta clase', 409);

    $ins = $pdo->prepare('INSERT INTO alumnos_grupos (alumno_id, grupo_id) VALUES (?, ?)');
    $ins->execute([$payload['id'], $grupo['id']]);

    jsonResponse([
        'message'      => 'Te uniste a ' . $grupo['nombre'],
        'grupo_id'     => (int)$grupo['id'],
        'grupo_nombre' => $grupo['nombre']
    ], 200);
}

jsonError('Método no permitido', 405);
