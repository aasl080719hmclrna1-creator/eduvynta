<?php
/**
 * GET /api/usuarios/search.php?q=texto&rol=alumno
 * Busca usuarios por nombre o email (para que maestros inscriban alumnos)
 */

require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../middleware/auth.php';
require_once __DIR__ . '/../../middleware/response.php';

setCorsHeaders();
requireRol('maestro');
$pdo = getDB();

$q   = trim($_GET['q']   ?? '');
$rol = trim($_GET['rol'] ?? 'alumno');

if (strlen($q) < 2) jsonError('Ingresa al menos 2 caracteres para buscar');

$like = "%$q%";
$stmt = $pdo->prepare('
    SELECT id, nombre, email, usuario
    FROM usuarios
    WHERE (nombre LIKE ? OR email LIKE ? OR usuario LIKE ?)
      AND rol = ?
      AND activo = 1
    ORDER BY nombre ASC
    LIMIT 20
');
$stmt->execute([$like, $like, $like, $rol]);
jsonResponse($stmt->fetchAll());
