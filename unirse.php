<?php
ob_start();
ini_set('display_errors', '0');
error_reporting(0);
ob_start();
ini_set('display_errors', '0');
error_reporting(0);

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/response.php';

setCorsHeaders();
ob_end_clean();
ob_end_clean();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') jsonError('Método no permitido', 405);

$payload = requireAuth();
if ($payload['rol'] !== 'alumno') jsonError('Solo alumnos pueden unirse', 403);

$body   = getBody();
$codigo = strtoupper(trim($body['codigo'] ?? ''));

if (strlen($codigo) < 4) jsonError('Código inválido');

$pdo = getDB();

$grp = $pdo->prepare('SELECT id, nombre FROM grupos WHERE codigo_acceso = ? LIMIT 1');
$grp->execute([$codigo]);
$grupo = $grp->fetch();

if (!$grupo) jsonError('Código no encontrado. Verifica con tu maestro.', 404);

$ya = $pdo->prepare('SELECT alumno_id FROM alumnos_grupos WHERE alumno_id = ? AND grupo_id = ? LIMIT 1');
$ya->execute([$payload['id'], $grupo['id']]);
if ($ya->fetch()) jsonError('Ya estás inscrito en esta clase', 409);

$ins = $pdo->prepare('INSERT INTO alumnos_grupos (alumno_id, grupo_id) VALUES (?, ?)');
$ins->execute([$payload['id'], $grupo['id']]);

jsonResponse([
    'message'      => 'Te uniste correctamente',
    'grupo_id'     => (int)$grupo['id'],
    'grupo_nombre' => $grupo['nombre']
]);
