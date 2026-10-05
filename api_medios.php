<?php
// API de la galería: subir, listar, eliminar y cambiar portada. Responde JSON.
session_start();
require 'db.php';
require 'medios.php';
require 'permisos.php';

header('Content-Type: application/json; charset=utf-8');

function responder($datos, $codigo = 200)
{
    http_response_code($codigo);
    echo json_encode($datos, JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isset($_SESSION['usuario_id'])) {
    responder(['ok' => false, 'error' => 'La sesión expiró. Vuelva a iniciar sesión.'], 401);
}

$user_id      = (int)$_SESSION['usuario_id'];
$user_name    = $_SESSION['usuario_nombre'];
$es_superadmin = puede('editar_terrenos');

// Si el archivo supera post_max_size, PHP descarta todo el envío y $_POST llega vacío
if ($_SERVER['REQUEST_METHOD'] === 'POST' && empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
    responder(['ok' => false, 'error' => 'El archivo supera el tamaño máximo permitido (' . formatoMB(limiteArchivoBytes()) . ').'], 413);
}

$accion = $_POST['accion'] ?? $_GET['accion'] ?? '';

function terrenoExiste(PDO $pdo, int $id)
{
    $stmt = $pdo->prepare("SELECT id, nombre, imagen FROM terrenos WHERE id = ?");
    $stmt->execute([$id]);
    return $stmt->fetch();
}

switch ($accion) {
    case 'listar':
        $terreno_id = (int)($_GET['terreno_id'] ?? 0);
        $t = terrenoExiste($pdo, $terreno_id);
        if (!$t) responder(['ok' => false, 'error' => 'El terreno no existe.'], 404);
        responder(['ok' => true, 'portada' => $t['imagen'], 'medios' => mediosPorTerreno($pdo, [$terreno_id])[$terreno_id] ?? []]);

    case 'subir':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(['ok' => false, 'error' => 'Método no permitido.'], 405);
        $terreno_id = (int)($_POST['terreno_id'] ?? 0);
        $t = terrenoExiste($pdo, $terreno_id);
        if (!$t) responder(['ok' => false, 'error' => 'El terreno no existe.'], 404);
        if (empty($_FILES['archivo'])) responder(['ok' => false, 'error' => 'No se recibió ningún archivo.'], 400);

        $r = guardarMedio($pdo, $terreno_id, $_FILES['archivo'], $user_id, $user_name, $_POST['descripcion'] ?? '');
        if (!$r['ok']) responder($r, 422);

        $tipo = $r['medio']['tipo'] === 'foto' ? 'una foto' : 'un video';
        registrarAuditoriaMedio($pdo, $terreno_id, $user_id, $user_name, "$user_name subió $tipo a la galería.");
        $r['portada'] = terrenoExiste($pdo, $terreno_id)['imagen'];
        responder($r);

    case 'eliminar':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(['ok' => false, 'error' => 'Método no permitido.'], 405);
        if (!$es_superadmin) responder(['ok' => false, 'error' => 'Únicamente el Super Admin puede eliminar fotos o videos.'], 403);
        $m = eliminarMedio($pdo, (int)($_POST['medio_id'] ?? 0));
        if (!$m) responder(['ok' => false, 'error' => 'El archivo ya no existe.'], 404);

        $tipo = $m['tipo'] === 'foto' ? 'una foto' : 'un video';
        registrarAuditoriaMedio($pdo, (int)$m['terreno_id'], $user_id, $user_name, "$user_name eliminó $tipo de la galería.");
        responder(['ok' => true, 'portada' => terrenoExiste($pdo, (int)$m['terreno_id'])['imagen']]);

    case 'portada':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') responder(['ok' => false, 'error' => 'Método no permitido.'], 405);
        if (!$es_superadmin) responder(['ok' => false, 'error' => 'Únicamente el Super Admin puede cambiar la portada.'], 403);
        $stmt = $pdo->prepare("SELECT terreno_id, archivo FROM terreno_medios WHERE id = ? AND tipo = 'foto'");
        $stmt->execute([(int)($_POST['medio_id'] ?? 0)]);
        $m = $stmt->fetch();
        if (!$m) responder(['ok' => false, 'error' => 'Solo una foto puede ser portada.'], 404);

        $pdo->prepare("UPDATE terrenos SET imagen = ? WHERE id = ?")->execute([$m['archivo'], $m['terreno_id']]);
        registrarAuditoriaMedio($pdo, (int)$m['terreno_id'], $user_id, $user_name, "$user_name cambió la foto de portada.");
        responder(['ok' => true, 'portada' => $m['archivo']]);

    default:
        responder(['ok' => false, 'error' => 'Acción no válida.'], 400);
}
