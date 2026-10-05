<?php
// Muestra el documento de propiedad escaneado de un terreno (solo administradores).
session_start();
require 'db.php';
require 'campos_terreno.php';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit;
}
if (!puedeVerDatosPrivados($_SESSION['usuario_rol'])) {
    http_response_code(403);
    die("Error 403: Solo los administradores pueden ver documentos de propiedad.");
}

$stmt = $pdo->prepare("SELECT doc_archivo, codigo_catastral FROM terrenos WHERE id = ?");
$stmt->execute([intval($_GET['id'] ?? 0)]);
$t = $stmt->fetch();

$ruta = $t && $t['doc_archivo'] ? DOCUMENTOS_DIR . basename($t['doc_archivo']) : null;
if (!$ruta || !is_file($ruta)) {
    http_response_code(404);
    die("El terreno no tiene documento de propiedad cargado.");
}

$ext = strtolower(pathinfo($ruta, PATHINFO_EXTENSION));
$tipos = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];

header('Content-Type: ' . ($tipos[$ext] ?? 'application/octet-stream'));
header('Content-Length: ' . filesize($ruta));
header('Content-Disposition: inline; filename="documento_' . preg_replace('/[^A-Za-z0-9\-]/', '', $t['codigo_catastral']) . '.' . $ext . '"');
header('X-Content-Type-Options: nosniff');
readfile($ruta);
