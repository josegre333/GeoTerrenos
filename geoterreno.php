<?php
session_start();
require 'db.php';
require 'medios.php';
require 'campos_terreno.php';

// Muestra un error del formulario con un botón para volver sin perder lo escrito
function errorFormulario($mensaje)
{
    http_response_code(422);
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Revise el formulario</title></head>'
        . '<body style="font-family:Segoe UI,sans-serif; background:#f1f5f9; display:grid; place-items:center; min-height:100vh; margin:0;">'
        . '<div style="background:#fff; border:1px solid #fca5a5; border-radius:10px; padding:24px; max-width:460px;">'
        . '<h2 style="color:#b91c1c; margin:0 0 8px; font-size:18px;">⚠ Revise el formulario</h2>'
        . '<p style="color:#334155; font-size:14px;">' . htmlspecialchars($mensaje) . '</p>'
        . '<button onclick="history.back()" style="margin-top:10px; padding:9px 16px; background:#2563eb; color:#fff; border:none; border-radius:6px; font-weight:bold; cursor:pointer;">← Volver y corregir</button>'
        . '</div></body></html>';
    exit;
}

// Control de Acceso
if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit;
}

$user_id   = $_SESSION['usuario_id'];
$user_name = $_SESSION['usuario_nombre'];
$user_rol  = $_SESSION['usuario_rol'];

// Permisos según la jerarquía de roles (permisos.php)
$puede_editar   = puede('editar_terrenos');
$puede_eliminar = puede('eliminar_terrenos');

// ---------------------------------------------------------------------
// PROCESAR POST (GUARDAR / EDITAR / ELIMINAR)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Si lo enviado supera post_max_size, PHP descarta el formulario completo
    if (empty($_POST) && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $_SESSION['flash_errores'] = ["No se guardó nada: los archivos juntos pesan más de lo permitido (" . formatoMB(iniABytes(ini_get('post_max_size'))) . "). Guarde el terreno con menos archivos y suba el resto desde su galería."];
        header("Location: geoterreno.php");
        exit;
    }

    // ELIMINAR TERRENO
    if (isset($_POST['eliminar_terreno'])) {
        $id = intval($_POST['terreno_id']);

        if (!$puede_eliminar) {
            die("Error 403: Únicamente el Super Admin puede eliminar terrenos.");
        }

        $stmtTerreno = $pdo->prepare("SELECT nombre, imagen, doc_archivo FROM terrenos WHERE id = ?");
        $stmtTerreno->execute([$id]);
        $terreno = $stmtTerreno->fetch();

        if ($terreno) {
            eliminarDocumentoPropiedad($terreno['doc_archivo']);
            // Borra del disco todas sus fotos y videos (las filas se borran en cascada)
            eliminarArchivosDeTerreno($pdo, $id);
            if (!empty($terreno['imagen']) && file_exists("uploads/" . basename($terreno['imagen']))) {
                unlink("uploads/" . basename($terreno['imagen']));
            }

            $stmtDel = $pdo->prepare("DELETE FROM terrenos WHERE id = ?");
            $stmtDel->execute([$id]);

            $stmtAudit = $pdo->prepare("INSERT INTO auditoria_terrenos (terreno_id, usuario_id, usuario_nombre, accion, detalles) VALUES (NULL, ?, ?, 'ELIMINAR', ?)");
            $stmtAudit->execute([$user_id, $user_name, "El Super Admin $user_name eliminó el terreno '{$terreno['nombre']}'."]);
        }

        header("Location: geoterreno.php");
        exit;
    }

    // GUARDAR O EDITAR TERRENO
    if (isset($_POST['guardar_terreno'])) {
        $id = !empty($_POST['terreno_id']) ? intval($_POST['terreno_id']) : null;

        if ($id && !$puede_editar) {
            die("Error 403: Únicamente el Super Admin puede editar terrenos.");
        }

        $nombre            = trim($_POST['nombre'] ?? '');
        $tipo              = $_POST['tipo'] ?? '';
        $estado            = $_POST['estado'] ?? '';
        $personas          = max(0, intval($_POST['personas'] ?? 0));
        $latitud           = floatval($_POST['latitud'] ?? 0);
        $longitud          = floatval($_POST['longitud'] ?? 0);
        $zona_especifica   = trim($_POST['zona_especifica'] ?? '');
        $estado_region     = trim($_POST['estado_region'] ?? '');
        $municipio         = trim($_POST['municipio'] ?? '');
        $parroquia         = trim($_POST['parroquia'] ?? '');
        $puntos_referencia = trim($_POST['puntos_referencia'] ?? '');
        $tiene_lago        = isset($_POST['tiene_lago']) ? 1 : 0;
        $tiene_piscina     = isset($_POST['tiene_piscina']) ? 1 : 0;
        $area_m2           = floatval($_POST['area_m2'] ?? 0);
        $codigo_catastral  = strtoupper(trim($_POST['codigo_catastral'] ?? ''));
        $clase_predio      = $_POST['clase_predio'] ?? 'urbano';

        if (!in_array($clase_predio, ['urbano', 'rural'], true)) {
            errorFormulario("Clase de predio no válida.");
        }
        if ($codigo_catastral !== '') {
            if (!preg_match('/^[A-Z0-9\-\.\/]{3,40}$/', $codigo_catastral)) {
                errorFormulario("El código catastral solo puede tener letras, números, guiones, puntos o barras (3 a 40 caracteres).");
            }
            $stmtCod = $pdo->prepare("SELECT id FROM terrenos WHERE codigo_catastral = ? AND id <> ?");
            $stmtCod->execute([$codigo_catastral, $id ?? 0]);
            if ($stmtCod->fetch()) {
                errorFormulario("El código catastral '$codigo_catastral' ya pertenece a otro terreno.");
            }
        }

        // Validaciones básicas
        $tipos_validos   = ['vivienda', 'montana', 'hogar', 'galpon', 'comercio'];
        $estados_validos = ['habitable', 'no_habitable', 'en_riesgo'];

        if ($nombre === '' || $zona_especifica === '') {
            errorFormulario("El nombre y la zona específica son obligatorios.");
        }
        if (!in_array($tipo, $tipos_validos, true) || !in_array($estado, $estados_validos, true)) {
            errorFormulario("Tipo o estado no válido.");
        }
        if (($_POST['latitud'] ?? '') === '' || ($_POST['longitud'] ?? '') === '' || abs($latitud) > 90 || abs($longitud) > 180) {
            errorFormulario("Debe seleccionar una coordenada válida en el mapa.");
        }

        $orientacion  = ($latitud >= 0) ? 'Norte' : 'Sur';
        $orientacion .= ' / ';
        $orientacion .= ($longitud >= 0) ? 'Este' : 'Oeste';

        // Datos completos: propiedad, linderos, servicios, riesgo, valor, población, inspección
        try {
            $extendidos = leerCamposExtendidos($_POST);
            $nuevo_documento = guardarDocumentoPropiedad($_FILES['doc_archivo'] ?? null);
        } catch (InvalidArgumentException $e) {
            errorFormulario($e->getMessage());
        }

        $datos = array_merge([
            'codigo_catastral'  => $codigo_catastral !== '' ? $codigo_catastral : ($id ? 'GT-' . str_pad($id, 6, '0', STR_PAD_LEFT) : null),
            'clase_predio'      => $clase_predio,
            'nombre'            => $nombre,
            'tipo'              => $tipo,
            'estado'            => $estado,
            'personas'          => $personas,
            'latitud'           => $latitud,
            'longitud'          => $longitud,
            'orientacion'       => $orientacion,
            'zona_especifica'   => $zona_especifica,
            'estado_region'     => $estado_region,
            'municipio'         => $municipio,
            'parroquia'         => $parroquia,
            'puntos_referencia' => $puntos_referencia,
            'tiene_lago'        => $tiene_lago,
            'tiene_piscina'     => $tiene_piscina,
            'area_m2'           => $area_m2,
        ], $extendidos);

        if ($id) {
            // Quien no puede ver los datos personales tampoco los sobrescribe
            if (!puedeVerDatosPrivados($user_rol)) {
                unset($datos['propietario_documento'], $datos['propietario_telefono']);
            }

            // Documento de propiedad: se reemplaza si se sube uno nuevo, o se quita si se pidió
            $stmtDoc = $pdo->prepare("SELECT doc_archivo FROM terrenos WHERE id = ?");
            $stmtDoc->execute([$id]);
            $doc_anterior = $stmtDoc->fetchColumn() ?: null;
            if ($nuevo_documento) {
                $datos['doc_archivo'] = $nuevo_documento;
                eliminarDocumentoPropiedad($doc_anterior);
            } elseif (!empty($_POST['quitar_documento'])) {
                $datos['doc_archivo'] = null;
                eliminarDocumentoPropiedad($doc_anterior);
            }

            $sets = implode(', ', array_map(fn($c) => "$c = ?", array_keys($datos)));
            $pdo->prepare("UPDATE terrenos SET $sets WHERE id = ?")->execute([...array_values($datos), $id]);

            $stmtAudit = $pdo->prepare("INSERT INTO auditoria_terrenos (terreno_id, usuario_id, usuario_nombre, accion, detalles) VALUES (?, ?, ?, 'EDITAR', ?)");
            $stmtAudit->execute([$id, $user_id, $user_name, "Modificado por $user_name."]);
        } else {
            $datos['doc_archivo']   = $nuevo_documento;
            $datos['creado_por_id'] = $user_id;

            $columnas = implode(', ', array_keys($datos));
            $marcas   = implode(', ', array_fill(0, count($datos), '?'));
            $pdo->prepare("INSERT INTO terrenos ($columnas) VALUES ($marcas)")->execute(array_values($datos));
            $nuevo_id = $pdo->lastInsertId();

            // Si no se indicó código catastral, se genera uno automático
            if ($codigo_catastral === '') {
                $pdo->prepare("UPDATE terrenos SET codigo_catastral = ? WHERE id = ?")
                    ->execute(['GT-' . str_pad($nuevo_id, 6, '0', STR_PAD_LEFT), $nuevo_id]);
            }

            $stmtAudit = $pdo->prepare("INSERT INTO auditoria_terrenos (terreno_id, usuario_id, usuario_nombre, accion, detalles) VALUES (?, ?, ?, 'CREAR', ?)");
            $stmtAudit->execute([$nuevo_id, $user_id, $user_name, "Registro inicial por $user_name."]);
        }

        // Fotos y videos adjuntados en el formulario
        $terreno_guardado = $id ?: (int)$nuevo_id;
        $subidos = 0;
        $errores = [];
        foreach (normalizarArchivos($_FILES['medios'] ?? null) as $archivo) {
            if ($archivo['error'] === UPLOAD_ERR_NO_FILE) continue;
            $r = guardarMedio($pdo, $terreno_guardado, $archivo, (int)$user_id, $user_name);
            if ($r['ok']) {
                $subidos++;
            } else {
                $errores[] = $r['error'];
            }
        }
        if ($subidos > 0) {
            registrarAuditoriaMedio($pdo, $terreno_guardado, (int)$user_id, $user_name, "$user_name subió $subidos archivo(s) a la galería.");
        }
        if ($errores) {
            $_SESSION['flash_errores'] = $errores;
        }
        $_SESSION['flash_ok'] = ($id ? "Terreno actualizado." : "Terreno registrado.") . ($subidos ? " Se subieron $subidos foto(s)/video(s)." : "");

        // Vuelve al mapa con el terreno abierto y su galería visible
        header("Location: geoterreno.php?terreno=$terreno_guardado");
        exit;
    }
}

// Utilidades para el historial
function tiempoRelativo($fecha)
{
    $seg = time() - strtotime($fecha);
    if ($seg < 60) return 'hace un momento';
    if ($seg < 3600) return 'hace ' . floor($seg / 60) . ' min';
    if ($seg < 86400) return 'hace ' . floor($seg / 3600) . ' h';
    $dias = floor($seg / 86400);
    if ($dias == 1) return 'ayer';
    if ($dias < 30) return "hace $dias días";
    return date('d/m/Y', strtotime($fecha));
}

function iniciales($nombre)
{
    $partes = preg_split('/\s+/', trim($nombre));
    $ini = mb_substr($partes[0] ?? '?', 0, 1) . (isset($partes[1]) ? mb_substr($partes[1], 0, 1) : '');
    return mb_strtoupper($ini);
}

function colorAvatar($nombre)
{
    $colores = ['#2563eb', '#7c3aed', '#0f766e', '#c2410c', '#be185d', '#4d7c0f', '#0369a1', '#a16207'];
    return $colores[abs(crc32($nombre)) % count($colores)];
}

// Mensajes tras guardar
$flash_ok      = $_SESSION['flash_ok'] ?? null;
$flash_errores = $_SESSION['flash_errores'] ?? [];
unset($_SESSION['flash_ok'], $_SESSION['flash_errores']);

// Cargar Terrenos (con su galería) y Auditorías
$terrenos = $pdo->query("SELECT t.*, u.nombre as creador FROM terrenos t JOIN usuarios u ON t.creado_por_id = u.id ORDER BY t.id DESC")->fetchAll();
$medios = mediosPorTerreno($pdo);
foreach ($terrenos as &$t) {
    $t['medios'] = $medios[$t['id']] ?? [];
    $t = ocultarDatosPrivados($t, $user_rol);
}
unset($t);
$ver_privado = puedeVerDatosPrivados($user_rol);
$auditorias = $pdo->query("SELECT a.*, COALESCE(t.nombre, 'Terreno Eliminado') as terreno_nombre FROM auditoria_terrenos a LEFT JOIN terrenos t ON a.terreno_id = t.id ORDER BY a.fecha_hora DESC LIMIT 50")->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>GeoTerrenos - Gestión Avanzada</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.css" />
    <link rel="stylesheet" href="assets/galeria.css" />
    <style>
        * {
            box-sizing: border-box;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 0;
        }

        body {
            display: flex;
            height: 100vh;
            background: #121824;
            color: #333;
            overflow: hidden;
        }

        #sidebar {
            width: 430px;
            background: #ffffff;
            padding: 16px;
            overflow-y: auto;
            max-height: 100vh;
            border-right: 1px solid #cbd5e1;
            display: flex;
            flex-direction: column;
            gap: 12px;
            z-index: 10;
        }

        #map-container {
            flex: 1;
            position: relative;
            height: 100vh;
        }

        #map {
            width: 100%;
            height: 100%;
        }

        /* Panel Flotante para Galería de Fotos del Terreno Seleccionado */
        #fotos-panel {
            position: absolute;
            top: 12px;
            right: 12px;
            width: 330px;
            max-width: calc(100% - 24px);
            max-height: calc(100vh - 60px);
            background: rgba(255, 255, 255, 0.95);
            backdrop-filter: blur(4px);
            border-radius: 8px;
            box-shadow: 0 4px 15px rgba(0, 0, 0, 0.3);
            padding: 12px;
            z-index: 1000;
            display: none;
            overflow-y: auto;
            border: 1px solid #cbd5e1;
        }

        #fotos-panel h4 {
            font-size: 13px;
            color: #002b66;
            margin-bottom: 8px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        #fotos-panel .close-btn {
            cursor: pointer;
            color: #ef4444;
            font-weight: bold;
            font-size: 14px;
        }

        #fotos-panel .sub {
            font-size: 11px;
            color: #475569;
            margin: -4px 0 8px;
        }

        #fotos-panel .sub a {
            color: #23607a;
            font-weight: bold;
        }

        .flash {
            border-radius: 6px;
            padding: 8px 10px;
            font-size: 12px;
            position: relative;
        }

        .flash-ok {
            background: #dcfce7;
            border: 1px solid #86efac;
            color: #166534;
        }

        .flash-error {
            background: #fef2f2;
            border: 1px solid #fca5a5;
            color: #991b1b;
        }

        .flash ul {
            margin: 4px 0 0 16px;
        }

        .flash .cerrar {
            position: absolute;
            top: 4px;
            right: 8px;
            cursor: pointer;
            font-weight: bold;
        }

        .ayuda-campo {
            display: block;
            font-size: 10px;
            color: #64748b;
            margin-top: 3px;
        }

        .search-box {
            position: absolute;
            top: 12px;
            left: 55px;
            z-index: 1000;
            background: white;
            padding: 6px 10px;
            border-radius: 8px;
            box-shadow: 0 4px 12px rgba(0, 0, 0, 0.25);
            display: flex;
            gap: 6px;
            width: 320px;
        }

        .search-box input {
            flex: 1;
            padding: 6px 8px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-size: 12px;
            outline: none;
        }

        .search-box button {
            padding: 6px 12px;
            background: #2563eb;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 12px;
            font-weight: bold;
            cursor: pointer;
        }

        #coords-box {
            position: absolute;
            bottom: 12px;
            right: 12px;
            z-index: 1000;
            background: rgba(18, 24, 36, 0.85);
            color: #ffffff;
            padding: 6px 12px;
            border-radius: 6px;
            font-size: 11px;
            font-family: monospace;
            pointer-events: none;
        }

        .header-box {
            background: #002b66;
            color: white;
            padding: 10px 14px;
            border-radius: 6px;
            font-size: 12px;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header-box a {
            color: #f87171;
            text-decoration: none;
            font-weight: bold;
        }

        .leyenda {
            background: #f8fafc;
            padding: 10px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            font-size: 12px;
        }

        .leyenda h4 {
            margin-bottom: 2px;
            color: #1e293b;
            font-size: 12px;
        }

        .leyenda .ayuda {
            display: block;
            font-size: 11px;
            color: #64748b;
            margin-bottom: 8px;
        }

        .leyenda-item {
            display: flex;
            align-items: center;
            gap: 2px;
            margin-bottom: 4px;
            padding: 6px 8px;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            background: #fff;
            cursor: pointer;
            user-select: none;
            transition: background 0.15s, opacity 0.15s;
        }

        .leyenda-item:hover {
            background: #e0f2fe;
            border-color: #93c5fd;
        }

        .leyenda-item input {
            cursor: pointer;
            width: 15px;
            height: 15px;
        }

        .leyenda-item .texto {
            flex: 1;
        }

        .leyenda-item .contador {
            min-width: 24px;
            padding: 1px 7px;
            border-radius: 10px;
            background: #e2e8f0;
            color: #1e293b;
            font-weight: bold;
            font-size: 11px;
            text-align: center;
        }

        /* Capa oculta: se ve apagada y tachada */
        .leyenda-item.apagado {
            opacity: 0.5;
            background: #f1f5f9;
        }

        .leyenda-item.apagado .texto {
            text-decoration: line-through;
        }

        .leyenda-pie {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-top: 6px;
            font-size: 11px;
            color: #475569;
        }

        .leyenda-pie button {
            background: none;
            border: none;
            color: #2563eb;
            font-size: 11px;
            font-weight: bold;
            cursor: pointer;
            padding: 2px 4px;
        }

        .leyenda-pie button:hover {
            text-decoration: underline;
        }

        .color-box {
            display: inline-block;
            width: 12px;
            height: 12px;
            margin: 0 6px 0 4px;
            border-radius: 50%;
        }

        .form-group {
            margin-bottom: 8px;
        }

        .form-group label {
            display: block;
            font-size: 11px;
            font-weight: bold;
            color: #475569;
            margin-bottom: 3px;
        }

        .form-group input,
        .form-group select,
        .form-group textarea {
            width: 100%;
            padding: 6px 8px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-size: 12px;
            outline: none;
        }

        /* ---------- Secciones del formulario ---------- */
        .seccion-form {
            margin: 12px 0 10px;
        }

        .seccion-titulo {
            display: flex;
            align-items: baseline;
            gap: 6px;
            font-size: 12px;
            font-weight: bold;
            color: #002b66;
            margin-bottom: 6px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e2e8f0;
        }

        .seccion-titulo small {
            font-weight: normal;
            color: #64748b;
            font-size: 11px;
            margin-left: auto;
        }

        /* Zona para elegir fotos y videos */
        .zona-archivos {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 12px;
            border: 2px dashed #93c5fd;
            border-radius: 8px;
            background: #eff6ff;
            cursor: pointer;
            transition: background 0.15s, border-color 0.15s;
        }

        .zona-archivos:hover {
            background: #dbeafe;
            border-color: #2563eb;
        }

        .zona-archivos .ico {
            font-size: 26px;
        }

        .zona-archivos b {
            display: block;
            font-size: 12px;
            color: #1e3a8a;
        }

        .zona-archivos small {
            font-size: 11px;
            color: #475569;
        }

        /* Tarjetas de características (checkbox visual) */
        .caracteristicas {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 8px;
        }

        .carac {
            position: relative;
            cursor: pointer;
        }

        .carac input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .carac-card {
            display: flex;
            flex-direction: column;
            align-items: center;
            text-align: center;
            gap: 4px;
            padding: 12px 8px 10px;
            border: 2px solid #e2e8f0;
            border-radius: 10px;
            background: #fff;
            transition: all 0.15s;
            height: 100%;
        }

        .carac:hover .carac-card {
            border-color: #93c5fd;
            background: #f8fafc;
            transform: translateY(-1px);
        }

        .carac-ico {
            font-size: 26px;
            line-height: 1;
            filter: grayscale(0.6);
            transition: filter 0.15s, transform 0.15s;
        }

        .carac-card b {
            font-size: 12px;
            color: #1e293b;
        }

        .carac-card small {
            font-size: 10px;
            color: #64748b;
        }

        .carac-check {
            position: absolute;
            top: 6px;
            right: 6px;
            width: 18px;
            height: 18px;
            border-radius: 50%;
            border: 2px solid #cbd5e1;
            background: #fff;
            color: transparent;
            font-size: 11px;
            font-weight: bold;
            display: grid;
            place-items: center;
            transition: all 0.15s;
        }

        .carac input:checked+.carac-card {
            border-color: #2563eb;
            background: #eff6ff;
            box-shadow: 0 0 0 3px rgba(37, 99, 235, 0.15);
        }

        .carac input:checked+.carac-card .carac-ico {
            filter: none;
            transform: scale(1.12);
        }

        .carac input:checked+.carac-card .carac-check {
            background: #2563eb;
            border-color: #2563eb;
            color: #fff;
        }

        .carac input:focus-visible+.carac-card {
            outline: 2px solid #2563eb;
            outline-offset: 2px;
        }

        .contador-texto {
            display: block;
            text-align: right;
            font-size: 10px;
            color: #94a3b8;
            margin-top: 2px;
        }

        /* Botones del formulario: siempre visibles al bajar */
        .acciones-form {
            position: sticky;
            bottom: -16px;
            z-index: 5;
            display: flex;
            gap: 8px;
            margin: 12px -16px 0;
            padding: 10px 16px 16px;
            background: linear-gradient(to bottom, rgba(255, 255, 255, 0.85), #fff 30%);
            border-top: 1px solid #e2e8f0;
        }

        .btn-guardar {
            flex: 1;
            padding: 11px;
            background: linear-gradient(135deg, #16a34a, #15803d);
            color: #fff;
            border: none;
            border-radius: 8px;
            font-weight: bold;
            font-size: 13px;
            cursor: pointer;
            box-shadow: 0 3px 8px rgba(22, 163, 74, 0.35);
            transition: transform 0.1s, box-shadow 0.15s;
        }

        .btn-guardar::before {
            content: '💾 ';
        }

        .btn-guardar:hover {
            box-shadow: 0 5px 12px rgba(22, 163, 74, 0.45);
            transform: translateY(-1px);
        }

        .btn-guardar.editando {
            background: linear-gradient(135deg, #2563eb, #1d4ed8);
            box-shadow: 0 3px 8px rgba(37, 99, 235, 0.35);
        }

        .btn-guardar.editando::before {
            content: '✏️ ';
        }

        .btn-limpiar {
            padding: 11px 14px;
            background: #fff;
            color: #475569;
            border: 1px solid #cbd5e1;
            border-radius: 8px;
            font-weight: bold;
            font-size: 12px;
            cursor: pointer;
        }

        .btn-limpiar:hover {
            background: #fef2f2;
            color: #b91c1c;
            border-color: #fca5a5;
        }

        /* ---------- Formulario en pestañas ---------- */
        .form-cabecera {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 8px;
        }

        .form-cabecera h3 {
            font-size: 14px;
            color: #002b66;
        }

        .modo-form {
            font-size: 10px;
            font-weight: bold;
            padding: 3px 8px;
            border-radius: 10px;
            background: #dcfce7;
            color: #166534;
            max-width: 60%;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .modo-form.editando {
            background: #dbeafe;
            color: #1e40af;
        }

        .tabs-form {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 4px;
            margin-bottom: 10px;
        }

        .tab {
            position: relative;
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 5px;
            padding: 7px 4px;
            border: 1px solid #e2e8f0;
            border-radius: 7px;
            background: #f8fafc;
            color: #475569;
            font-size: 11px;
            font-weight: bold;
            cursor: pointer;
            transition: all 0.15s;
        }

        .tab:hover {
            background: #eff6ff;
            border-color: #93c5fd;
        }

        .tab.activo {
            background: #002b66;
            border-color: #002b66;
            color: #fff;
        }

        /* Punto rojo: la pestaña tiene un campo obligatorio vacío */
        .tab.con-error::after {
            content: '';
            position: absolute;
            top: 3px;
            right: 4px;
            width: 7px;
            height: 7px;
            border-radius: 50%;
            background: #ef4444;
        }

        /* Punto verde: la pestaña tiene datos cargados */
        .tab.con-datos:not(.activo)::before {
            content: '';
            position: absolute;
            bottom: 3px;
            left: 50%;
            transform: translateX(-50%);
            width: 14px;
            height: 3px;
            border-radius: 2px;
            background: #22c55e;
        }

        .panel-tab {
            display: none;
            animation: aparecer 0.15s ease-out;
        }

        .panel-tab.activo {
            display: block;
        }

        @keyframes aparecer {
            from {
                opacity: 0;
                transform: translateY(3px);
            }

            to {
                opacity: 1;
                transform: none;
            }
        }

        .pasos-ayuda {
            background: #fffbeb;
            border: 1px solid #fde68a;
            border-radius: 7px;
            padding: 7px 9px;
            font-size: 11px;
            color: #78350f;
            margin-bottom: 10px;
            line-height: 1.45;
        }

        .fila-2,
        .fila-3 {
            display: flex;
            gap: 6px;
        }

        .fila-2>.form-group,
        .fila-3>.form-group {
            flex: 1;
            min-width: 0;
        }

        .form-group textarea {
            resize: vertical;
        }

        .form-group input:focus,
        .form-group select:focus,
        .form-group textarea:focus {
            border-color: #2563eb;
            box-shadow: 0 0 0 2px rgba(37, 99, 235, 0.15);
        }

        .form-group input.invalido,
        .form-group select.invalido {
            border-color: #ef4444;
            background: #fef2f2;
        }

        .subtitulo-form {
            display: flex;
            align-items: baseline;
            gap: 6px;
            font-size: 12px;
            font-weight: bold;
            color: #002b66;
            margin: 12px 0 6px;
            padding-bottom: 4px;
            border-bottom: 1px solid #e2e8f0;
        }

        .subtitulo-form small {
            margin-left: auto;
            font-weight: normal;
            font-size: 10px;
            color: #64748b;
            text-align: right;
        }

        .enlace {
            background: none;
            border: none;
            color: #2563eb;
            font-size: 11px;
            font-weight: bold;
            cursor: pointer;
            padding: 0;
        }

        .enlace:hover {
            text-decoration: underline;
        }

        .caracteristicas.tres {
            grid-template-columns: repeat(3, 1fr);
            gap: 6px;
        }

        .carac.mini .carac-card {
            padding: 9px 4px 7px;
            gap: 2px;
        }

        .carac.mini .carac-ico {
            font-size: 20px;
        }

        .carac.mini .carac-card b {
            font-size: 10.5px;
            line-height: 1.2;
        }

        .carac.mini .carac-check {
            width: 14px;
            height: 14px;
            font-size: 9px;
            top: 4px;
            right: 4px;
        }

        .estado-poligono {
            display: flex;
            align-items: center;
            gap: 10px;
            padding: 10px;
            border-radius: 8px;
            border: 2px dashed #cbd5e1;
            background: #f8fafc;
            font-size: 11px;
            color: #475569;
            margin-bottom: 10px;
        }

        .estado-poligono .ico {
            font-size: 24px;
            color: #94a3b8;
        }

        .estado-poligono.listo {
            border-style: solid;
            border-color: #86efac;
            background: #f0fdf4;
            color: #166534;
        }

        .estado-poligono.listo .ico {
            color: #16a34a;
        }

        .linderos {
            display: flex;
            flex-direction: column;
            gap: 6px;
        }

        .lindero {
            display: flex;
            gap: 6px;
            align-items: center;
        }

        .lindero .lado {
            flex: none;
            width: 26px;
            height: 26px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            font-size: 11px;
            font-weight: bold;
            color: #fff;
            background: #002b66;
        }

        .lindero input {
            padding: 6px 8px;
            border: 1px solid #cbd5e1;
            border-radius: 4px;
            font-size: 12px;
            outline: none;
            min-width: 0;
        }

        .lindero input[type="text"] {
            flex: 1;
        }

        .lindero input[type="number"] {
            width: 74px;
            flex: none;
        }

        .chips-riesgo {
            display: flex;
            flex-wrap: wrap;
            gap: 5px;
        }

        .chip-riesgo input {
            position: absolute;
            opacity: 0;
            pointer-events: none;
        }

        .chip-riesgo span {
            display: inline-block;
            padding: 5px 10px;
            border: 1px solid #cbd5e1;
            border-radius: 16px;
            font-size: 11px;
            background: #fff;
            color: #334155;
            cursor: pointer;
            transition: all 0.15s;
            user-select: none;
        }

        .chip-riesgo span:hover {
            border-color: #f87171;
        }

        .chip-riesgo input:checked+span {
            background: #fee2e2;
            border-color: #ef4444;
            color: #991b1b;
            font-weight: bold;
        }

        .chip-riesgo input:focus-visible+span {
            outline: 2px solid #ef4444;
            outline-offset: 1px;
        }

        .doc-actual {
            margin-top: 5px;
            font-size: 11px;
            background: #f0fdf4;
            border: 1px solid #bbf7d0;
            border-radius: 5px;
            padding: 6px 8px;
            color: #166534;
        }

        .doc-actual a {
            color: #166534;
            font-weight: bold;
        }

        /* ---------- Historial (línea de tiempo) ---------- */
        .historial {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 12px;
            margin-top: 8px;
        }

        .historial-cabecera {
            display: flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 8px;
        }

        .historial-cabecera h3 {
            font-size: 13px;
            color: #002b66;
            flex: 1;
        }

        .historial-cabecera .badge {
            background: #002b66;
            color: #fff;
            font-size: 10px;
            font-weight: bold;
            padding: 2px 8px;
            border-radius: 10px;
        }

        .historial-filtros {
            display: flex;
            flex-wrap: wrap;
            gap: 4px;
            margin-bottom: 6px;
        }

        .chip {
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #475569;
            border-radius: 14px;
            padding: 3px 9px;
            font-size: 11px;
            font-weight: bold;
            cursor: pointer;
        }

        .chip:hover {
            border-color: #94a3b8;
        }

        .chip.activo {
            background: #002b66;
            border-color: #002b66;
            color: #fff;
        }

        .historial-buscar {
            width: 100%;
            padding: 6px 8px 6px 26px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 12px;
            margin-bottom: 8px;
            background: #fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='14' viewBox='0 0 24 24' fill='none' stroke='%2394a3b8' stroke-width='2.5'%3E%3Ccircle cx='11' cy='11' r='7'/%3E%3Cpath d='m20 20-3.5-3.5'/%3E%3C/svg%3E") no-repeat 8px center;
            outline: none;
        }

        .historial-buscar:focus {
            border-color: #2563eb;
        }

        .historial-lista {
            max-height: 300px;
            overflow-y: auto;
            padding-right: 2px;
        }

        .hist-item {
            position: relative;
            display: flex;
            gap: 10px;
            padding: 8px 6px 8px 4px;
            border-radius: 8px;
            cursor: pointer;
            transition: background 0.15s;
        }

        /* Línea vertical que une los eventos */
        .hist-item::before {
            content: '';
            position: absolute;
            left: 19px;
            top: 38px;
            bottom: -8px;
            width: 2px;
            background: #e2e8f0;
        }

        .hist-item:last-child::before {
            display: none;
        }

        .hist-item:hover {
            background: #f1f5f9;
        }

        .hist-item.activo {
            background: #eff6ff;
            box-shadow: inset 3px 0 0 #2563eb;
        }

        .hist-avatar {
            position: relative;
            z-index: 1;
            flex: none;
            width: 30px;
            height: 30px;
            border-radius: 50%;
            color: #fff;
            font-size: 11px;
            font-weight: bold;
            display: grid;
            place-items: center;
            border: 2px solid #fff;
            box-shadow: 0 0 0 1px #e2e8f0;
        }

        .hist-cuerpo {
            flex: 1;
            min-width: 0;
        }

        .hist-linea1 {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .hist-terreno {
            flex: 1;
            font-size: 12px;
            color: #1e293b;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .hist-item.borrado .hist-terreno {
            color: #94a3b8;
            text-decoration: line-through;
        }

        .hist-tag {
            flex: none;
            font-size: 9px;
            font-weight: bold;
            text-transform: uppercase;
            letter-spacing: 0.3px;
            padding: 2px 6px;
            border-radius: 8px;
        }

        .tag-CREAR {
            background: #dcfce7;
            color: #166534;
        }

        .tag-EDITAR {
            background: #dbeafe;
            color: #1e40af;
        }

        .tag-ELIMINAR {
            background: #fee2e2;
            color: #991b1b;
        }

        .hist-linea2 {
            font-size: 11px;
            color: #64748b;
            margin-top: 1px;
        }

        .hist-detalle {
            font-size: 11px;
            color: #475569;
            margin-top: 2px;
            white-space: nowrap;
            overflow: hidden;
            text-overflow: ellipsis;
        }

        .historial-vacio {
            padding: 16px;
            text-align: center;
            font-size: 12px;
            color: #64748b;
        }

        .historial-ayuda {
            display: block;
            font-size: 10px;
            color: #94a3b8;
            margin-top: 6px;
            text-align: center;
        }

        #detalleHistorialBox {
            display: none;
            position: relative;
            background: linear-gradient(135deg, #eff6ff, #f8fafc);
            border: 1px solid #bfdbfe;
            border-radius: 10px;
            padding: 12px;
            font-size: 12px;
            color: #1e3a8a;
            margin: 8px 0 20px;
        }

        #detalleHistorialBox .cerrar {
            position: absolute;
            top: 8px;
            right: 10px;
            cursor: pointer;
            color: #64748b;
            font-weight: bold;
        }

        .share-btn {
            display: inline-block;
            padding: 4px 8px;
            font-size: 11px;
            border-radius: 3px;
            text-decoration: none;
            color: white;
            font-weight: bold;
            margin-right: 4px;
        }

        .share-ws {
            background: #25d366;
        }

        .share-mail {
            background: #ea4335;
        }

        .btn-reporte {
            width: 100%;
            padding: 9px;
            background: #0f766e;
            color: white;
            border: none;
            border-radius: 5px;
            font-weight: bold;
            cursor: pointer;
        }

        .btn-reporte:hover {
            background: #115e59;
        }

        .reporte-box {
            background: #f0fdfa;
            border: 1px solid #99f6e4;
            border-radius: 6px;
            padding: 10px;
            font-size: 12px;
        }

        .reporte-box h4 {
            font-size: 12px;
            color: #0f766e;
            margin-bottom: 6px;
        }

        .reporte-box small {
            display: block;
            color: #475569;
            margin-bottom: 8px;
        }
    </style>
</head>

<body>
    <div id="sidebar">
        <div class="header-box">
            <div>
                <strong><?= htmlspecialchars($user_name) ?></strong><br>
                <small>Rol: <?= htmlspecialchars(nombreRol($user_rol)) ?> · Nivel <?= nivelRol($user_rol) ?></small>
                <?php if (puede('gestionar_usuarios')): ?>
                    <br><a href="usuarios.php" style="font-size:12px; color:#93c5fd;">👥 Usuarios y permisos</a>
                <?php endif; ?>
            </div>
            <a href="logout.php">Cerrar Sesión</a>
        </div>

        <?php if ($flash_ok): ?>
            <div class="flash flash-ok"><span class="cerrar" onclick="this.parentNode.remove()">✕</span>✔ <?= htmlspecialchars($flash_ok) ?></div>
        <?php endif; ?>
        <?php if ($flash_errores): ?>
            <div class="flash flash-error"><span class="cerrar" onclick="this.parentNode.remove()">✕</span>
                <b>Algunos archivos no se pudieron subir:</b>
                <ul>
                    <?php foreach ($flash_errores as $err): ?><li><?= htmlspecialchars($err) ?></li><?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <!-- Leyenda -->
        <div class="leyenda">
            <h4>Filtrar Capas / Estado</h4>
            <small class="ayuda">Haz clic en una fila para mostrar u ocultar esos terrenos en el mapa.</small>
            <label class="leyenda-item" title="Terrenos habitables registrados en los últimos 7 días">
                <input type="checkbox" id="chk_nueva" checked onchange="filtrarMapa()">
                <span class="color-box" style="background: #2ecc71;"></span>
                <span class="texto">Verde: Zona Nueva (&lt; 7 días)</span>
                <span class="contador" id="cnt_nueva">0</span>
            </label>
            <label class="leyenda-item" title="Terrenos habitables con más de 7 días registrados">
                <input type="checkbox" id="chk_antigua" checked onchange="filtrarMapa()">
                <span class="color-box" style="background: #3498db;"></span>
                <span class="texto">Azul: Zona Antigua (Habitable)</span>
                <span class="contador" id="cnt_antigua">0</span>
            </label>
            <label class="leyenda-item" title="Terrenos marcados en riesgo">
                <input type="checkbox" id="chk_riesgo" checked onchange="filtrarMapa()">
                <span class="color-box" style="background: #e74c3c;"></span>
                <span class="texto">Rojo: Zona En Riesgo</span>
                <span class="contador" id="cnt_riesgo">0</span>
            </label>
            <label class="leyenda-item" title="Terrenos no habitables">
                <input type="checkbox" id="chk_nohabitable" checked onchange="filtrarMapa()">
                <span class="color-box" style="background: #7f8c8d;"></span>
                <span class="texto">Gris: No Habitable</span>
                <span class="contador" id="cnt_nohabitable">0</span>
            </label>
            <div class="leyenda-pie">
                <span id="leyendaResumen">Mostrando 0 de 0 terrenos</span>
                <span>
                    <button type="button" onclick="marcarTodasCapas(true)">Todos</button>
                    <button type="button" onclick="marcarTodasCapas(false)">Ninguno</button>
                </span>
            </div>
        </div>

        <!-- Reportes -->
        <div class="reporte-box">
            <h4>Reportes</h4>
            <?php if (puede('reporte_general')): ?>
                <small>Imprime (o guarda en PDF) los terrenos visibles según los filtros de arriba.</small>
                <button type="button" class="btn-reporte" onclick="imprimirReporteGeneral()">🖨 Generar Reporte General</button>
            <?php else: ?>
                <small>🔒 Los reportes generales y el análisis requieren rol Administrador o superior.</small>
            <?php endif; ?>
            <?php if (puede('ver_analisis')): ?>
                <button type="button" class="btn-reporte" style="margin-top:6px; background:#002b66;" onclick="location.href='analisis.php'">📊 Ver Análisis y Diagnóstico</button>
            <?php endif; ?>
            <button type="button" class="btn-reporte" style="margin-top:6px; background:#23607a;" onclick="location.href='consulta_catastral.php'">🔎 Consulta Catastral</button>
        </div>

        <!-- Formulario principal (en pestañas) -->
        <form method="POST" id="formTerreno" action="geoterreno.php" enctype="multipart/form-data" novalidate>
            <div class="form-cabecera">
                <h3>Gestión de Terrenos</h3>
                <span class="modo-form" id="modoForm">Nuevo</span>
            </div>
            <input type="hidden" name="terreno_id" id="terreno_id">
            <input type="hidden" name="poligono_geojson" id="poligono_geojson">

            <div class="tabs-form" role="tablist">
                <button type="button" class="tab activo" data-tab="general" role="tab">📍<span>General</span></button>
                <button type="button" class="tab" data-tab="propiedad" role="tab">🏛️<span>Propiedad</span></button>
                <button type="button" class="tab" data-tab="linderos" role="tab">📐<span>Linderos</span></button>
                <button type="button" class="tab" data-tab="servicios" role="tab">🔧<span>Servicios</span></button>
                <button type="button" class="tab" data-tab="riesgo" role="tab">⚠️<span>Riesgo</span></button>
                <button type="button" class="tab" data-tab="fotos" role="tab">📷<span>Fotos</span></button>
            </div>

            <!-- ================= 1. GENERAL ================= -->
            <div class="panel-tab activo" data-panel="general">
                <div class="pasos-ayuda">
                    <b>¿Cómo registrar?</b> 1) Haz clic en el mapa para ubicarlo. 2) Dibuja su contorno con <b>⬟</b> para calcular área, perímetro y linderos. 3) Completa las pestañas.
                </div>

                <div class="fila-2">
                    <div class="form-group" style="flex:3;">
                        <label>Código Catastral (Nº predial)</label>
                        <input type="text" name="codigo_catastral" id="codigo_catastral" maxlength="40" placeholder="Vacío = se genera solo">
                    </div>
                    <div class="form-group" style="flex:2;">
                        <label>Clase de Predio</label>
                        <select name="clase_predio" id="clase_predio">
                            <option value="urbano">Urbano</option>
                            <option value="rural">Rural</option>
                        </select>
                    </div>
                </div>

                <div class="form-group">
                    <label>Nombre del Terreno / Propiedad *</label>
                    <input type="text" name="nombre" id="nombre" required maxlength="150" placeholder="Ej. Parcela San José">
                </div>

                <div class="fila-2">
                    <div class="form-group">
                        <label>Tipo</label>
                        <select name="tipo" id="tipo">
                            <option value="vivienda">Vivienda</option>
                            <option value="montana">Montaña</option>
                            <option value="hogar">Hogar / Casa</option>
                            <option value="galpon">Galpón</option>
                            <option value="comercio">Comercio</option>
                        </select>
                    </div>
                    <div class="form-group">
                        <label>Estado Habitacional</label>
                        <select name="estado" id="estado">
                            <option value="habitable">Habitable</option>
                            <option value="no_habitable">No Habitable</option>
                            <option value="en_riesgo">En Riesgo</option>
                        </select>
                    </div>
                </div>

                <div class="fila-2">
                    <div class="form-group">
                        <label>Uso Actual</label>
                        <select name="uso_actual" id="uso_actual"><?= htmlOpciones('uso_actual') ?></select>
                    </div>
                    <div class="form-group">
                        <label>Zonificación</label>
                        <input type="text" name="zonificacion" id="zonificacion" maxlength="100" placeholder="Ej: R4, AR-2, Agrícola">
                    </div>
                </div>

                <div class="subtitulo-form">Ubicación</div>
                <div class="fila-2">
                    <div class="form-group">
                        <label>Estado / Región</label>
                        <input type="text" name="estado_region" id="estado_region" placeholder="Ej: Miranda">
                    </div>
                    <div class="form-group">
                        <label>Municipio</label>
                        <input type="text" name="municipio" id="municipio" placeholder="Ej: Sucre">
                    </div>
                </div>
                <div class="fila-2">
                    <div class="form-group">
                        <label>Parroquia</label>
                        <input type="text" name="parroquia" id="parroquia" placeholder="Ej: Petare">
                    </div>
                    <div class="form-group">
                        <label>Zona Específica *</label>
                        <input type="text" name="zona_especifica" id="zona_especifica" required placeholder="Ej: Sector Norte">
                    </div>
                </div>
                <div class="fila-2">
                    <div class="form-group">
                        <label>Latitud *</label>
                        <input type="text" name="latitud" id="latitud" readonly required placeholder="Clic en mapa">
                    </div>
                    <div class="form-group">
                        <label>Longitud *</label>
                        <input type="text" name="longitud" id="longitud" readonly required placeholder="Clic en mapa">
                    </div>
                </div>

                <div class="form-group">
                    <label>Puntos de Referencia</label>
                    <textarea name="puntos_referencia" id="puntos_referencia" rows="2" maxlength="500" placeholder="Ej: A 200 m de la torre de luz, frente a la escuela..." oninput="contarReferencia()"></textarea>
                    <small class="contador-texto" id="refContador">0 / 500</small>
                </div>
            </div>

            <!-- ================= 2. PROPIEDAD ================= -->
            <div class="panel-tab" data-panel="propiedad">
                <div class="subtitulo-form">Naturaleza del terreno</div>
                <div class="caracteristicas">
                    <label class="carac">
                        <input type="radio" name="naturaleza" id="naturaleza_privado" value="privado" checked onchange="alternarPublico()">
                        <span class="carac-card">
                            <span class="carac-check">✓</span>
                            <span class="carac-ico">🏠</span>
                            <b>Privado</b>
                            <small>De una persona o empresa</small>
                        </span>
                    </label>
                    <label class="carac">
                        <input type="radio" name="naturaleza" id="naturaleza_publico" value="publico" onchange="alternarPublico()">
                        <span class="carac-card">
                            <span class="carac-check">✓</span>
                            <span class="carac-ico">🏛️</span>
                            <b>Público</b>
                            <small>Del Estado o municipio</small>
                        </span>
                    </label>
                </div>

                <div id="bloquePublico" style="display:none;">
                    <div class="fila-2" style="margin-top:8px;">
                        <div class="form-group">
                            <label>Nivel</label>
                            <select name="nivel_publico" id="nivel_publico"><?= htmlOpciones('nivel_publico') ?></select>
                        </div>
                        <div class="form-group">
                            <label>Organismo responsable</label>
                            <input type="text" name="organismo_responsable" id="organismo_responsable" maxlength="150" placeholder="Ej: Alcaldía de Sucre">
                        </div>
                    </div>
                </div>

                <div class="form-group" style="margin-top:8px;">
                    <label>Régimen de Tenencia</label>
                    <select name="regimen_tenencia" id="regimen_tenencia"><?= htmlOpciones('regimen_tenencia') ?></select>
                </div>

                <div class="subtitulo-form">Propietario u ocupante <small>🔒 Cédula y teléfono solo los ven administradores</small></div>
                <div class="form-group">
                    <label>Nombre o Razón Social</label>
                    <input type="text" name="propietario_nombre" id="propietario_nombre" maxlength="150" placeholder="Ej: María Pérez / Inversiones XYZ C.A.">
                </div>
                <div class="fila-2">
                    <div class="form-group">
                        <label>Cédula o RIF</label>
                        <input type="text" name="propietario_documento" id="propietario_documento" maxlength="30" placeholder="V-12345678 / J-12345678-9">
                    </div>
                    <div class="form-group">
                        <label>Teléfono</label>
                        <input type="tel" name="propietario_telefono" id="propietario_telefono" maxlength="30" placeholder="0412-1234567">
                    </div>
                </div>

                <div class="subtitulo-form">Documento de propiedad</div>
                <div class="fila-2">
                    <div class="form-group">
                        <label>Nº de Documento</label>
                        <input type="text" name="doc_numero" id="doc_numero" maxlength="40">
                    </div>
                    <div class="form-group">
                        <label>Fecha de Registro</label>
                        <input type="date" name="doc_fecha" id="doc_fecha" max="<?= date('Y-m-d') ?>">
                    </div>
                </div>
                <div class="fila-3">
                    <div class="form-group">
                        <label>Tomo</label>
                        <input type="text" name="doc_tomo" id="doc_tomo" maxlength="20">
                    </div>
                    <div class="form-group">
                        <label>Folio</label>
                        <input type="text" name="doc_folio" id="doc_folio" maxlength="20">
                    </div>
                    <div class="form-group">
                        <label>Protocolo</label>
                        <input type="text" name="doc_protocolo" id="doc_protocolo" maxlength="40">
                    </div>
                </div>
                <div class="form-group">
                    <label>Oficina de Registro</label>
                    <input type="text" name="doc_oficina" id="doc_oficina" maxlength="150" placeholder="Ej: Registro Público del Municipio Sucre">
                </div>
                <div class="form-group">
                    <label>Documento escaneado (PDF, JPG o PNG)</label>
                    <input type="file" name="doc_archivo" id="doc_archivo" accept="application/pdf,image/jpeg,image/png">
                    <div id="docActual" class="doc-actual" style="display:none;"></div>
                </div>

                <div class="subtitulo-form">Situación legal y valor</div>
                <div class="fila-2">
                    <div class="form-group">
                        <label>Situación Legal</label>
                        <select name="situacion_legal" id="situacion_legal"><?= htmlOpciones('situacion_legal') ?></select>
                    </div>
                    <div class="form-group">
                        <label>Impuesto Inmobiliario</label>
                        <select name="solvencia_inmobiliaria" id="solvencia_inmobiliaria"><?= htmlOpciones('solvencia_inmobiliaria') ?></select>
                    </div>
                </div>
                <div class="fila-3">
                    <div class="form-group" style="flex:2;">
                        <label>Valor Catastral</label>
                        <input type="number" name="valor_catastral" id="valor_catastral" min="0" step="0.01" placeholder="0,00">
                    </div>
                    <div class="form-group">
                        <label>Moneda</label>
                        <select name="valor_moneda" id="valor_moneda"><?= htmlOpciones('valor_moneda', null) ?></select>
                    </div>
                    <div class="form-group" style="flex:2;">
                        <label>Fecha Avalúo</label>
                        <input type="date" name="valor_fecha" id="valor_fecha" max="<?= date('Y-m-d') ?>">
                    </div>
                </div>
            </div>

            <!-- ================= 3. LINDEROS ================= -->
            <div class="panel-tab" data-panel="linderos">
                <div class="estado-poligono" id="estadoPoligono">
                    <span class="ico">⬟</span>
                    <span id="estadoPoligonoTexto"><b>Sin contorno dibujado.</b><br>Usa el botón ⬟ (polígono) o ◼ (rectángulo) a la izquierda del mapa.</span>
                </div>

                <div class="fila-2">
                    <div class="form-group">
                        <label>Área (m²)</label>
                        <input type="number" step="0.01" name="area_m2" id="area_m2" readonly placeholder="Se calcula al dibujar">
                    </div>
                    <div class="form-group">
                        <label>Perímetro (m)</label>
                        <input type="number" step="0.01" name="perimetro_m" id="perimetro_m" readonly placeholder="Se calcula al dibujar">
                    </div>
                </div>

                <div class="subtitulo-form">Linderos <small><button type="button" class="enlace" onclick="sugerirLinderos()">📏 Calcular medidas del dibujo</button></small></div>
                <div class="linderos">
                    <?php foreach (LINDEROS_TERRENO as $lado => $texto): ?>
                        <div class="lindero">
                            <span class="lado lado-<?= $lado ?>"><?= mb_substr($texto, 0, 1) ?></span>
                            <input type="text" name="lindero_<?= $lado ?>" id="lindero_<?= $lado ?>" maxlength="255" placeholder="<?= $texto ?>: colinda con...">
                            <input type="number" name="lindero_<?= $lado ?>_m" id="lindero_<?= $lado ?>_m" min="0" step="0.01" placeholder="m" title="Medida en metros">
                        </div>
                    <?php endforeach; ?>
                </div>
                <small class="ayuda-campo">Ej: "Norte: Calle Bolívar" · 25,40 m. Las medidas calculadas son aproximadas; ajústalas según el documento.</small>
            </div>

            <!-- ================= 4. SERVICIOS ================= -->
            <div class="panel-tab" data-panel="servicios">
                <div class="subtitulo-form">Servicios públicos <small>Toca los que tiene</small></div>
                <div class="caracteristicas tres">
                    <?php foreach (SERVICIOS_TERRENO as $campo => [$ico, $texto]): ?>
                        <label class="carac mini">
                            <input type="checkbox" name="<?= $campo ?>" id="<?= $campo ?>" value="1">
                            <span class="carac-card">
                                <span class="carac-check">✓</span>
                                <span class="carac-ico"><?= $ico ?></span>
                                <b><?= $texto ?></b>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>

                <div class="subtitulo-form">Características</div>
                <div class="caracteristicas">
                    <label class="carac mini">
                        <input type="checkbox" name="tiene_lago" id="tiene_lago" value="1">
                        <span class="carac-card">
                            <span class="carac-check">✓</span>
                            <span class="carac-ico">🌊</span>
                            <b>Lago / Agua</b>
                            <small>Río, laguna o quebrada</small>
                        </span>
                    </label>
                    <label class="carac mini">
                        <input type="checkbox" name="tiene_piscina" id="tiene_piscina" value="1">
                        <span class="carac-card">
                            <span class="carac-check">✓</span>
                            <span class="carac-ico">🏊</span>
                            <b>Piscina</b>
                            <small>Dentro del terreno</small>
                        </span>
                    </label>
                </div>

                <div class="subtitulo-form">Condiciones físicas</div>
                <div class="fila-2">
                    <div class="form-group">
                        <label>Topografía</label>
                        <select name="topografia" id="topografia"><?= htmlOpciones('topografia') ?></select>
                    </div>
                    <div class="form-group">
                        <label>Vegetación</label>
                        <select name="vegetacion" id="vegetacion"><?= htmlOpciones('vegetacion') ?></select>
                    </div>
                </div>
                <div class="form-group">
                    <label>Acceso Vial</label>
                    <select name="acceso_vial" id="acceso_vial"><?= htmlOpciones('acceso_vial') ?></select>
                </div>
            </div>

            <!-- ================= 5. RIESGO Y POBLACIÓN ================= -->
            <div class="panel-tab" data-panel="riesgo">
                <div class="subtitulo-form">Riesgos identificados <small>Marca todos los que apliquen</small></div>
                <div class="chips-riesgo">
                    <?php foreach (RIESGOS_TERRENO as $clave => [$ico, $texto]): ?>
                        <label class="chip-riesgo">
                            <input type="checkbox" name="riesgos[]" value="<?= $clave ?>">
                            <span><?= $ico ?> <?= $texto ?></span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="form-group" style="margin-top:8px;">
                    <label>Descripción del riesgo</label>
                    <textarea name="riesgo_descripcion" id="riesgo_descripcion" rows="2" maxlength="2000" placeholder="Ej: Grietas en el talud posterior después de las lluvias de junio"></textarea>
                </div>

                <div class="subtitulo-form">Población</div>
                <div class="fila-2">
                    <div class="form-group">
                        <label>👥 Personas que habitan</label>
                        <input type="number" name="personas" id="personas" value="0" min="0">
                    </div>
                    <div class="form-group">
                        <label>🏠 Familias</label>
                        <input type="number" name="familias" id="familias" value="0" min="0">
                    </div>
                </div>
                <div class="fila-3">
                    <div class="form-group">
                        <label>🧒 Niños</label>
                        <input type="number" name="ninos" id="ninos" value="0" min="0">
                    </div>
                    <div class="form-group">
                        <label>👴 Adultos mayores</label>
                        <input type="number" name="adultos_mayores" id="adultos_mayores" value="0" min="0">
                    </div>
                    <div class="form-group">
                        <label>♿ Con discapacidad</label>
                        <input type="number" name="personas_discapacidad" id="personas_discapacidad" value="0" min="0">
                    </div>
                </div>
            </div>

            <!-- ================= 6. FOTOS E INSPECCIÓN ================= -->
            <div class="panel-tab" data-panel="fotos">
                <div class="subtitulo-form">Fotos y videos <small>Opcional</small></div>
                <label class="zona-archivos" for="medios">
                    <span class="ico">📤</span>
                    <span>
                        <b>Toca para elegir fotos o videos</b>
                        <small>Varios a la vez. En el teléfono puedes usar la cámara.</small>
                    </span>
                </label>
                <input type="file" name="medios[]" id="medios" multiple hidden accept="image/jpeg,image/png,image/webp,video/*,.mov,.3gp" onchange="revisarArchivosFormulario()">
                <small class="ayuda-campo" id="mediosAyuda">Después de guardar podrás subir más desde la galería del terreno.</small>

                <div class="subtitulo-form">Inspección</div>
                <div class="fila-2">
                    <div class="form-group">
                        <label>Fecha de Inspección</label>
                        <input type="date" name="fecha_inspeccion" id="fecha_inspeccion" max="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="form-group">
                        <label>Inspector</label>
                        <input type="text" name="inspector" id="inspector" maxlength="100" placeholder="Nombre de quien inspeccionó">
                    </div>
                </div>
                <div class="form-group">
                    <label>Observaciones</label>
                    <textarea name="observaciones" id="observaciones" rows="3" maxlength="5000" placeholder="Cualquier dato adicional importante del terreno"></textarea>
                </div>
            </div>

            <div class="acciones-form">
                <button type="submit" name="guardar_terreno" id="btnGuardar" class="btn-guardar">Guardar Terreno</button>
                <button type="button" onclick="limpiarFormulario()" class="btn-limpiar" title="Cancelar y vaciar el formulario">✕ Limpiar</button>
            </div>
        </form>

        <form method="POST" id="formEliminar" action="geoterreno.php" style="display:none;">
            <input type="hidden" name="terreno_id" id="eliminar_terreno_id">
            <input type="hidden" name="eliminar_terreno" value="1">
        </form>

        <!-- Historial en forma de línea de tiempo -->
        <div class="historial">
            <div class="historial-cabecera">
                <h3>🕘 Historial de cambios</h3>
                <span class="badge" id="historialConteo"><?= count($auditorias) ?></span>
            </div>

            <?php if (!empty($auditorias)): ?>
                <div class="historial-filtros">
                    <button type="button" class="chip activo" data-filtro="">Todos</button>
                    <button type="button" class="chip" data-filtro="CREAR">🟢 Creados</button>
                    <button type="button" class="chip" data-filtro="EDITAR">🔵 Editados</button>
                    <button type="button" class="chip" data-filtro="ELIMINAR">🔴 Eliminados</button>
                </div>
                <input type="text" class="historial-buscar" id="historialBuscar" placeholder="Buscar por terreno o usuario..." oninput="filtrarHistorial()">

                <div class="historial-lista" id="historialLista">
                    <?php foreach ($auditorias as $aud):
                        $borrado = empty($aud['terreno_id']) || $aud['terreno_nombre'] === 'Terreno Eliminado';
                        // Si el terreno ya no existe, se recupera su nombre del detalle ("eliminó el terreno 'X'")
                        if ($borrado && preg_match("/'([^']+)'/", $aud['detalles'], $coincide)) {
                            $aud['terreno_nombre'] = $coincide[1];
                        }
                    ?>
                        <div class="hist-item <?= $borrado ? 'borrado' : '' ?>"
                            data-accion="<?= htmlspecialchars($aud['accion']) ?>"
                            data-texto="<?= htmlspecialchars(mb_strtolower($aud['terreno_nombre'] . ' ' . $aud['usuario_nombre'] . ' ' . $aud['detalles'])) ?>"
                            onclick="seleccionarHistorial(<?= htmlspecialchars(json_encode($aud)) ?>, this)">
                            <span class="hist-avatar" style="background:<?= colorAvatar($aud['usuario_nombre']) ?>" title="<?= htmlspecialchars($aud['usuario_nombre']) ?>"><?= htmlspecialchars(iniciales($aud['usuario_nombre'])) ?></span>
                            <div class="hist-cuerpo">
                                <div class="hist-linea1">
                                    <span class="hist-terreno"><?= htmlspecialchars($aud['terreno_nombre']) ?></span>
                                    <span class="hist-tag tag-<?= htmlspecialchars($aud['accion']) ?>"><?= ['CREAR' => 'Creado', 'EDITAR' => 'Editado', 'ELIMINAR' => 'Eliminado'][$aud['accion']] ?? htmlspecialchars($aud['accion']) ?></span>
                                </div>
                                <div class="hist-linea2"><?= htmlspecialchars($aud['usuario_nombre']) ?> · <span title="<?= htmlspecialchars($aud['fecha_hora']) ?>"><?= tiempoRelativo($aud['fecha_hora']) ?></span></div>
                                <div class="hist-detalle" title="<?= htmlspecialchars($aud['detalles']) ?>"><?= htmlspecialchars($aud['detalles']) ?></div>
                            </div>
                        </div>
                    <?php endforeach; ?>
                    <div class="historial-vacio" id="historialSinResultados" style="display:none;">Ningún cambio coincide con la búsqueda.</div>
                </div>
                <small class="historial-ayuda">Toca un cambio para ver el terreno en el mapa.</small>
            <?php else: ?>
                <div class="historial-vacio">📭 Todavía no hay cambios registrados.</div>
            <?php endif; ?>
        </div>

        <!-- Espacio Destacado para Detalle -->
        <div id="detalleHistorialBox">
            <span class="cerrar" onclick="cerrarDetalleHistorial()" title="Cerrar">✕</span>
            <h4 style="margin-bottom:6px; font-size:12px; color:#1e40af;">Detalle del cambio</h4>
            <div id="historialUsuario" style="font-size:13px; font-weight:bold;"></div>
            <div id="historialAccion" style="font-size:11px; margin-top:3px;"></div>
            <div id="historialDetalles" style="font-size:11px; margin-top:3px; font-style:italic; background:#fff; padding:6px; border-radius:4px; border:1px solid #cbd5e1;"></div>
            <div id="historialFecha" style="font-size:10px; color:#64748b; margin-top:5px;"></div>
        </div>

    </div> <!-- Cierre del #sidebar -->

    <!-- Contenedor del Mapa -->
    <div id="map-container">
        <div class="search-box">
            <input type="text" id="inputBuscar" placeholder="Buscar lugar, municipio, dirección..." onkeypress="if(event.key==='Enter') buscarLugar()">
            <button type="button" onclick="buscarLugar()">Buscar</button>
        </div>

        <!-- Panel de Fotos Lateral al Mapa -->
        <div id="fotos-panel">
            <h4>
                <span id="fotos-titulo">Fotos y Videos</span>
                <span class="close-btn" onclick="cerrarPanelFotos()" title="Cerrar">✕</span>
            </h4>
            <div class="sub" id="fotos-sub"></div>
            <div id="fotos-contenido"></div>
        </div>

        <div id="coords-box">Lat: 0.00000000 | Lng: 0.00000000</div>

        <div id="map"></div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@turf/turf@6/turf.min.js"></script>
    <script src="assets/galeria.js"></script>

    <script>
        var map = L.map('map').setView([10.4806, -66.9036], 12);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);

        var tempMarker;
        var layerGroup = L.layerGroup().addTo(map);
        var puedeEditar = <?= json_encode($puede_editar) ?>;
        var puedeEliminar = <?= json_encode($puede_eliminar) ?>;
        var PERMISOS = <?= json_encode(array_map(fn($a) => puede($a), array_combine(array_keys(PERMISOS), array_keys(PERMISOS)))) ?>;
        var rolActual = <?= json_encode(nombreRol($user_rol), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var terrenos = <?= json_encode($terrenos, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var usuarioActual = <?= json_encode($user_name, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        // Límites de subida configurados en PHP (php.ini)
        var LIMITE_ARCHIVO = <?= limiteArchivoBytes() ?>;
        var LIMITE_ENVIO = <?= iniABytes(ini_get('post_max_size')) ?>;
        var MAX_ARCHIVOS = <?= (int)ini_get('max_file_uploads') ?>;

        // Datos personales (cédula, teléfono, documento) solo para administradores
        var VER_PRIVADO = <?= json_encode($ver_privado) ?>;
        var OPCIONES = <?= json_encode(OPCIONES_TERRENO, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var RIESGOS = <?= json_encode(RIESGOS_TERRENO, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

        function etiquetaOpcion(lista, valor) {
            return valor ? ((OPCIONES[lista] || {})[valor] || valor) : '';
        }

        var ETIQUETAS_TIPO = {
            vivienda: 'Vivienda',
            montana: 'Montaña',
            hogar: 'Hogar / Casa',
            galpon: 'Galpón',
            comercio: 'Comercio'
        };
        var ETIQUETAS_ESTADO = {
            habitable: 'Habitable',
            no_habitable: 'No Habitable',
            en_riesgo: 'En Riesgo'
        };
        var ETIQUETAS_CATEGORIA = {
            nueva: 'Zona Nueva',
            antigua: 'Zona Antigua',
            riesgo: 'En Riesgo',
            nohabitable: 'No Habitable'
        };

        // Escapa texto antes de insertarlo como HTML
        function esc(valor) {
            return String(valor ?? '').replace(/[&<>"']/g, function(c) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                } [c];
            });
        }

        // Convierte "YYYY-MM-DD HH:MM:SS" de MySQL a Date (compatible con todos los navegadores)
        function parseFecha(f) {
            return f ? new Date(String(f).replace(' ', 'T')) : new Date();
        }

        function obtenerCategoria(t) {
            if (t.estado === 'en_riesgo') return {
                categoria: 'riesgo',
                color: '#e74c3c'
            };
            if (t.estado === 'no_habitable') return {
                categoria: 'nohabitable',
                color: '#7f8c8d'
            };
            var dias = (new Date() - parseFecha(t.creado_en)) / (1000 * 3600 * 24);
            if (dias <= 7) return {
                categoria: 'nueva',
                color: '#2ecc71'
            };
            return {
                categoria: 'antigua',
                color: '#3498db'
            };
        }

        function categoriaVisible(categoria) {
            var ids = {
                nueva: 'chk_nueva',
                antigua: 'chk_antigua',
                riesgo: 'chk_riesgo',
                nohabitable: 'chk_nohabitable'
            };
            return document.getElementById(ids[categoria]).checked;
        }

        function buscarTerreno(id) {
            return terrenos.find(function(t) {
                return t.id == id;
            });
        }

        // Detección de Coordenadas
        map.on('mousemove', function(e) {
            var lat = e.latlng.lat.toFixed(8);
            var lng = e.latlng.lng.toFixed(8);
            document.getElementById('coords-box').innerText = `Lat: ${lat} | Lng: ${lng}`;
        });

        // Dibujo y Medición
        var drawnItems = new L.FeatureGroup();
        map.addLayer(drawnItems);

        var drawControl = new L.Control.Draw({
            draw: {
                polygon: true,
                rectangle: true,
                polyline: false,
                circle: false,
                marker: false,
                circlemarker: false
            },
            edit: {
                featureGroup: drawnItems
            }
        });
        map.addControl(drawControl);

        // Al dibujar, editar o borrar el contorno se recalculan área, perímetro y el polígono guardado
        map.on(L.Draw.Event.CREATED, function(e) {
            drawnItems.clearLayers();
            drawnItems.addLayer(e.layer);
            actualizarPoligono(true);
        });
        map.on(L.Draw.Event.EDITED, function() {
            actualizarPoligono(false);
        });
        map.on(L.Draw.Event.DELETED, function() {
            actualizarPoligono(false);
        });

        function actualizarPoligono(recienDibujado) {
            var capas = drawnItems.getLayers();
            var estado = document.getElementById('estadoPoligono');
            var texto = document.getElementById('estadoPoligonoTexto');

            if (!capas.length) {
                document.getElementById('poligono_geojson').value = '';
                document.getElementById('area_m2').value = '';
                document.getElementById('perimetro_m').value = '';
                estado.classList.remove('listo');
                texto.innerHTML = '<b>Sin contorno dibujado.</b><br>Usa el botón ⬟ (polígono) o ◼ (rectángulo) a la izquierda del mapa.';
                marcarPestanasConDatos();
                return;
            }

            var geo = capas[0].toGeoJSON();
            var area = turf.area(geo);
            var perimetro = turf.length(turf.polygonToLine(geo), {
                units: 'kilometers'
            }) * 1000;

            document.getElementById('poligono_geojson').value = JSON.stringify(geo.geometry);
            document.getElementById('area_m2').value = area.toFixed(2);
            document.getElementById('perimetro_m').value = perimetro.toFixed(2);

            estado.classList.add('listo');
            texto.innerHTML = `<b>✔ Contorno dibujado</b><br>${area.toLocaleString('es-VE', {maximumFractionDigits: 2})} m² (${(area / 10000).toFixed(4)} ha) · Perímetro ${perimetro.toLocaleString('es-VE', {maximumFractionDigits: 2})} m`;

            // Si todavía no hay coordenada, se usa el centro del dibujo
            if (recienDibujado && !document.getElementById('latitud').value) {
                var c = turf.centroid(geo).geometry.coordinates;
                fijarUbicacion(c[1], c[0]);
            }
            if (recienDibujado) {
                sugerirLinderos(true);
                mostrarTab('linderos');
            }
            marcarPestanasConDatos();
        }

        // Estima la medida de cada lindero sumando los lados del polígono según hacia dónde miran
        function sugerirLinderos(soloVacios) {
            var capas = drawnItems.getLayers();
            if (!capas.length) {
                alert('Primero dibuja el contorno del terreno en el mapa.');
                return;
            }
            var geo = capas[0].toGeoJSON();
            var anillo = geo.geometry.coordinates[0];
            var centro = turf.centroid(geo).geometry.coordinates;
            var suma = {
                norte: 0,
                sur: 0,
                este: 0,
                oeste: 0
            };

            for (var i = 0; i < anillo.length - 1; i++) {
                var a = anillo[i],
                    b = anillo[i + 1];
                var medio = [(a[0] + b[0]) / 2, (a[1] + b[1]) / 2];
                var rumbo = turf.bearing(turf.point(centro), turf.point(medio)); // -180..180, 0 = norte
                var lado = (rumbo >= -45 && rumbo < 45) ? 'norte' : (rumbo >= 45 && rumbo < 135) ? 'este' : (rumbo >= -135 && rumbo < -45) ? 'oeste' : 'sur';
                suma[lado] += turf.distance(turf.point(a), turf.point(b), {
                    units: 'kilometers'
                }) * 1000;
            }

            Object.keys(suma).forEach(function(lado) {
                var campo = document.getElementById('lindero_' + lado + '_m');
                if (!soloVacios || !campo.value) campo.value = suma[lado] ? suma[lado].toFixed(2) : '';
            });
        }

        // Fija la coordenada del terreno y rellena estado, municipio y parroquia
        function fijarUbicacion(lat, lng) {
            lat = Number(lat).toFixed(8);
            lng = Number(lng).toFixed(8);
            document.getElementById('latitud').value = lat;
            document.getElementById('longitud').value = lng;
            document.getElementById('latitud').classList.remove('invalido');
            document.getElementById('longitud').classList.remove('invalido');

            if (tempMarker) map.removeLayer(tempMarker);
            tempMarker = L.marker([lat, lng]).addTo(map).bindPopup("Coordenada Seleccionada").openPopup();

            fetch(`https://nominatim.openstreetmap.org/reverse?format=jsonv2&lat=${lat}&lon=${lng}`)
                .then(res => res.json())
                .then(data => {
                    if (data.address) {
                        document.getElementById('estado_region').value = data.address.state || data.address.region || '';
                        document.getElementById('municipio').value = data.address.county || data.address.city || '';
                        document.getElementById('parroquia').value = data.address.suburb || data.address.neighbourhood || '';
                    }
                }).catch(() => {});
        }

        // Clic en Mapa: ubica el terreno.
        // Si se está editando un terreno, el clic solo cambia su ubicación (no crea uno nuevo).
        map.on('click', function(e) {
            fijarUbicacion(e.latlng.lat, e.latlng.lng);
        });

        // ---------------------------------------------------------------
        // PESTAÑAS DEL FORMULARIO
        // ---------------------------------------------------------------
        function mostrarTab(nombre) {
            document.querySelectorAll('#formTerreno .tab').forEach(function(t) {
                t.classList.toggle('activo', t.dataset.tab === nombre);
            });
            document.querySelectorAll('#formTerreno .panel-tab').forEach(function(p) {
                p.classList.toggle('activo', p.dataset.panel === nombre);
            });
        }

        document.querySelectorAll('#formTerreno .tab').forEach(function(t) {
            t.addEventListener('click', function() {
                mostrarTab(t.dataset.tab);
            });
        });

        // Marca con una rayita verde las pestañas que ya tienen algún dato
        function marcarPestanasConDatos() {
            document.querySelectorAll('#formTerreno .panel-tab').forEach(function(panel) {
                var tieneDatos = Array.from(panel.querySelectorAll('input, select, textarea')).some(function(c) {
                    if (c.type === 'file') return c.files && c.files.length > 0;
                    if (c.type === 'checkbox') return c.checked;
                    if (c.type === 'radio') return false;
                    if (c.type === 'number') return c.value !== '' && Number(c.value) !== 0;
                    if (c.tagName === 'SELECT') return c.value !== '' && c.selectedIndex > 0;
                    return c.value.trim() !== '';
                });
                var tab = document.querySelector(`#formTerreno .tab[data-tab="${panel.dataset.panel}"]`);
                tab.classList.toggle('con-datos', tieneDatos);
            });
        }
        document.getElementById('formTerreno').addEventListener('input', marcarPestanasConDatos);
        document.getElementById('formTerreno').addEventListener('change', marcarPestanasConDatos);

        function alternarPublico() {
            var publico = document.getElementById('naturaleza_publico').checked;
            document.getElementById('bloquePublico').style.display = publico ? 'block' : 'none';
        }

        // Muestra el documento de propiedad ya cargado (solo administradores)
        function mostrarDocumentoActual(t) {
            var caja = document.getElementById('docActual');
            if (!t || !t.doc_archivo) {
                caja.style.display = 'none';
                caja.innerHTML = '';
                return;
            }
            caja.style.display = 'block';
            caja.innerHTML = VER_PRIVADO ?
                `📄 Ya tiene documento: <a href="documento.php?id=${Number(t.id)}" target="_blank">Ver documento</a>
                   <label style="display:block; margin-top:4px; font-weight:normal;"><input type="checkbox" name="quitar_documento" value="1"> Quitar el documento actual</label>
                   <small>Si subes otro, reemplaza al actual.</small>` :
                '📄 Este terreno ya tiene documento cargado (solo visible para administradores).';
        }

        function buscarLugar() {
            var query = document.getElementById('inputBuscar').value.trim();
            if (!query) return;

            fetch(`https://nominatim.openstreetmap.org/search?format=json&q=${encodeURIComponent(query)}`)
                .then(res => res.json())
                .then(data => {
                    if (data && data.length > 0) {
                        map.setView([data[0].lat, data[0].lon], 14);
                    } else {
                        alert('Lugar no encontrado.');
                    }
                });
        }

        // Actualiza contadores, aspecto de cada fila y el resumen de la leyenda
        function actualizarLeyenda(visibles) {
            var conteo = {
                nueva: 0,
                antigua: 0,
                riesgo: 0,
                nohabitable: 0
            };
            terrenos.forEach(function(t) {
                conteo[obtenerCategoria(t).categoria]++;
            });

            Object.keys(conteo).forEach(function(cat) {
                document.getElementById('cnt_' + cat).innerText = conteo[cat];
                var chk = document.getElementById('chk_' + cat);
                chk.closest('.leyenda-item').classList.toggle('apagado', !chk.checked);
            });

            document.getElementById('leyendaResumen').innerText =
                `Mostrando ${visibles} de ${terrenos.length} terrenos`;
        }

        function marcarTodasCapas(valor) {
            ['chk_nueva', 'chk_antigua', 'chk_riesgo', 'chk_nohabitable'].forEach(function(id) {
                document.getElementById(id).checked = valor;
            });
            filtrarMapa();
        }

        function renderizarMapa() {
            layerGroup.clearLayers();
            marcadores = {};
            var visibles = 0;

            terrenos.forEach(function(t) {
                var info = obtenerCategoria(t);
                var colorMarker = info.color;

                if (!categoriaVisible(info.categoria)) return;
                visibles++;

                var customIcon = L.divIcon({
                    className: 'custom-icon',
                    html: `<div style="background-color:${colorMarker}; width:16px; height:16px; border-radius:50%; border:2px solid white; box-shadow:0 0 4px rgba(0,0,0,0.4);"></div>`
                });

                var marker = L.marker([t.latitud, t.longitud], {
                    icon: customIcon
                });

                var mapLink = `https://www.google.com/maps?q=${t.latitud},${t.longitud}`;
                var shareText = encodeURIComponent(`Terreno: ${t.nombre} - Mapa: ${mapLink}`);
                var urlWhatsApp = `https://api.whatsapp.com/send?text=${shareText}`;
                var urlEmail = `mailto:?subject=Terreno%20${encodeURIComponent(t.nombre)}&body=${shareText}`;

                var imgHTML = t.imagen ? `<img src="uploads/${esc(t.imagen)}" style="width:100%; height:120px; object-fit:cover; border-radius:4px; margin-bottom:8px;">` : '';

                var popupContent = `
                    <div style="font-size:12px; max-width:240px;">
                        ${imgHTML}
                        <b style="font-size:14px; color:#002b66;">${esc(t.nombre)}</b> (${esc(ETIQUETAS_TIPO[t.tipo] || t.tipo)})<br>
                        <b>Cód. Catastral:</b> ${esc(t.codigo_catastral || 'N/A')} · ${t.clase_predio === 'rural' ? 'Rural' : 'Urbano'}<br>
                        <b>Estado:</b> ${esc(ETIQUETAS_ESTADO[t.estado] || t.estado)}<br>
                        <b>Área:</b> ${t.area_m2 > 0 ? esc(t.area_m2) + ' m²' : 'N/A'}<br>
                        <b>Personas:</b> ${esc(t.personas)}<br>
                        <b>Ubicación:</b> ${esc(t.estado_region)}, ${esc(t.municipio)}<br>
                        <b>Zona:</b> ${esc(t.zona_especifica)}<br>
                        <b>Naturaleza:</b> ${esc(etiquetaOpcion('naturaleza', t.naturaleza))}${t.nivel_publico ? ' (' + esc(etiquetaOpcion('nivel_publico', t.nivel_publico)) + ')' : ''}<br>
                        ${t.regimen_tenencia ? `<b>Tenencia:</b> ${esc(etiquetaOpcion('regimen_tenencia', t.regimen_tenencia))}<br>` : ''}
                        ${t.situacion_legal && t.situacion_legal !== 'sin_problemas' ? `<b style="color:#b91c1c;">Situación legal:</b> ${esc(etiquetaOpcion('situacion_legal', t.situacion_legal))}<br>` : ''}
                        ${t.riesgos ? `<b style="color:#b91c1c;">Riesgos:</b> ${t.riesgos.split(',').map(r => esc(RIESGOS[r] ? RIESGOS[r][1] : r)).join(', ')}<br>` : ''}
                        <b>Referencias:</b> ${esc(t.puntos_referencia || 'N/A')}<br>
                        <small><b>Registrado por:</b> ${esc(t.creador)}</small><br>
                        <small><b>Fecha:</b> ${esc(t.creado_en)}</small><br><br>

                        <div style="margin-bottom:8px;">
                            <a href="${urlWhatsApp}" target="_blank" class="share-btn share-ws">WhatsApp</a>
                            <a href="${urlEmail}" class="share-btn share-mail">Correo</a>
                        </div>
                `;

                var nMedios = (t.medios || []).length;
                popupContent += `<button onclick='verGaleria(${Number(t.id)})' style='padding:4px 8px; font-size:11px; background:#7c3aed; color:white; border:none; border-radius:3px; cursor:pointer;'>📷 Galería (${nMedios})</button> `;
                popupContent += `<button onclick='location.href="consulta_catastral.php?id=${Number(t.id)}"' style='padding:4px 8px; font-size:11px; background:#23607a; color:white; border:none; border-radius:3px; cursor:pointer;'>Ficha Catastral</button> `;
                if (PERMISOS.imprimir_ficha) {
                    popupContent += `<button onclick='window.open("consulta_catastral.php?id=${Number(t.id)}&imprimir=1", "_blank")' style='padding:4px 8px; font-size:11px; background:#0f766e; color:white; border:none; border-radius:3px; cursor:pointer;'>Imprimir Ficha</button> `;
                }

                if (puedeEditar) {
                    popupContent += `<button onclick='cargarParaEditar(buscarTerreno(${Number(t.id)}))' style='padding:4px 8px; font-size:11px; background:#2563eb; color:white; border:none; border-radius:3px; cursor:pointer;'>Editar / Actualizar</button> `;
                }
                if (puedeEliminar) {
                    popupContent += `<button onclick='eliminarTerreno(${t.id})' style='padding:4px 8px; font-size:11px; background:#dc2626; color:white; border:none; border-radius:3px; cursor:pointer;'>Eliminar</button>`;
                }

                popupContent += `</div>`;
                marker.bindPopup(popupContent);
                layerGroup.addLayer(marker);
                marcadores[t.id] = marker;

                // Contorno del terreno (si se dibujó), con el color de su categoría
                if (t.poligono_geojson) {
                    try {
                        var contorno = L.geoJSON(JSON.parse(t.poligono_geojson), {
                            style: {
                                color: colorMarker,
                                weight: 2,
                                fillColor: colorMarker,
                                fillOpacity: 0.18
                            }
                        });
                        contorno.bindPopup(popupContent);
                        layerGroup.addLayer(contorno);
                    } catch (err) {}
                }
            });

            actualizarLeyenda(visibles);
        }

        var marcadores = {};

        // Abrir el mapa enfocado en un terreno: geoterreno.php?terreno=ID
        function enfocarTerrenoDesdeURL() {
            var id = new URLSearchParams(location.search).get('terreno');
            var t = id ? buscarTerreno(id) : null;
            if (!t) return;
            map.setView([t.latitud, t.longitud], 17);
            if (marcadores[t.id]) marcadores[t.id].openPopup();
            verGaleria(t.id);
        }

        function filtrarMapa() {
            renderizarMapa();
        }

        // Selección de Historial
        // Filtros del historial (chips por acción + buscador)
        var filtroAccion = '';

        document.querySelectorAll('.historial-filtros .chip').forEach(function(chip) {
            chip.addEventListener('click', function() {
                document.querySelectorAll('.historial-filtros .chip').forEach(function(c) {
                    c.classList.remove('activo');
                });
                chip.classList.add('activo');
                filtroAccion = chip.dataset.filtro;
                filtrarHistorial();
            });
        });

        function filtrarHistorial() {
            var buscador = document.getElementById('historialBuscar');
            var texto = buscador ? buscador.value.trim().toLowerCase() : '';
            var visibles = 0;
            document.querySelectorAll('#historialLista .hist-item').forEach(function(item) {
                var ok = (!filtroAccion || item.dataset.accion === filtroAccion) &&
                    (!texto || item.dataset.texto.indexOf(texto) !== -1);
                item.style.display = ok ? '' : 'none';
                if (ok) visibles++;
            });
            var vacio = document.getElementById('historialSinResultados');
            if (vacio) vacio.style.display = visibles ? 'none' : 'block';
            document.getElementById('historialConteo').innerText = visibles;
        }

        function cerrarDetalleHistorial() {
            document.getElementById('detalleHistorialBox').style.display = 'none';
            document.querySelectorAll('.hist-item.activo').forEach(function(i) {
                i.classList.remove('activo');
            });
        }

        function contarReferencia() {
            var n = document.getElementById('puntos_referencia').value.length;
            document.getElementById('refContador').innerText = n + ' / 500';
        }

        function seleccionarHistorial(aud, el) {
            document.querySelectorAll('.hist-item.activo').forEach(function(i) {
                i.classList.remove('activo');
            });
            if (el) el.classList.add('activo');

            var box = document.getElementById('detalleHistorialBox');
            box.style.display = 'block';
            document.getElementById('historialUsuario').innerText = `Modificado por: ${aud.usuario_nombre}`;
            document.getElementById('historialAccion').innerText = `Acción: ${aud.accion}`;
            document.getElementById('historialDetalles').innerText = `Detalle: "${aud.detalles}"`;
            document.getElementById('historialFecha').innerText = `Fecha y Hora: ${aud.fecha_hora}`;

            if (aud.terreno_id) {
                var terrenoEncontrado = terrenos.find(t => t.id == aud.terreno_id);
                if (terrenoEncontrado && puedeEditar) {
                    cargarParaEditar(terrenoEncontrado);
                } else if (terrenoEncontrado) {
                    map.setView([terrenoEncontrado.latitud, terrenoEncontrado.longitud], 15);
                    verGaleria(terrenoEncontrado.id);
                } else {
                    alert('Este terreno fue eliminado anteriormente.');
                }
            }
        }

        // Carga de datos para edición y desplegado de fotos al lado del mapa
        function cargarParaEditar(t) {
            if (!puedeEditar) {
                alert('Solo el Super Admin tiene permisos para modificar terrenos.');
                return;
            }

            // 1. Cargar todos los datos en el formulario (cada campo por su "name")
            limpiarFormulario(true);
            var form = document.getElementById('formTerreno');
            var riesgos = (t.riesgos || '').split(',');

            Array.from(form.elements).forEach(function(c) {
                if (!c.name || c.type === 'file' || c.type === 'submit' || c.type === 'button') return;
                if (c.name === 'riesgos[]') {
                    c.checked = riesgos.indexOf(c.value) !== -1;
                } else if (c.type === 'radio') {
                    c.checked = (t[c.name] || '') === c.value;
                } else if (c.type === 'checkbox') {
                    c.checked = t[c.name] == 1;
                } else if (c.name in t) {
                    var v = t[c.name];
                    // Datos reservados para administradores: no se cargan (se conservan al guardar)
                    c.value = (v === null || v === undefined || v === '(reservado)') ? '' : v;
                    c.placeholder = v === '(reservado)' ? '🔒 Reservado (sin cambios)' : c.placeholder;
                }
            });
            document.getElementById('terreno_id').value = t.id;
            alternarPublico();
            mostrarDocumentoActual(t);

            // Contorno guardado: se carga en el mapa para poder editarlo
            if (t.poligono_geojson) {
                try {
                    L.geoJSON(JSON.parse(t.poligono_geojson)).eachLayer(function(capa) {
                        drawnItems.addLayer(L.polygon(capa.getLatLngs(), {
                            color: '#2563eb'
                        }));
                    });
                } catch (err) {}
            }
            actualizarPoligono(false);
            // Se respetan las medidas guardadas (no las recalculadas)
            ['area_m2', 'perimetro_m'].forEach(function(c) {
                if (t[c]) document.getElementById(c).value = t[c];
            });

            document.getElementById('btnGuardar').innerText = 'Actualizar Terreno';
            document.getElementById('btnGuardar').classList.add('editando');
            var modo = document.getElementById('modoForm');
            modo.innerText = '✏️ Editando: ' + t.nombre;
            modo.classList.add('editando');
            contarReferencia();
            marcarPestanasConDatos();

            // 2. Centrar mapa en el terreno
            map.setView([t.latitud, t.longitud], 15);

            // 3. Mostrar su galería de fotos y videos al lado derecho del mapa
            verGaleria(t.id);
        }

        // Galería de fotos y videos del terreno (disponible para todos los usuarios)
        var galeriaActual = null;

        function verGaleria(id) {
            var t = buscarTerreno(id);
            if (!t) return;

            document.getElementById('fotos-titulo').innerText = 'Fotos y Videos · ' + t.nombre;
            document.getElementById('fotos-sub').innerHTML =
                `${esc(t.codigo_catastral || '')} · <a href="consulta_catastral.php?id=${Number(t.id)}#galeria">Ver en pantalla completa</a>`;

            Galeria.cerrarVisor();
            galeriaActual = Galeria.montar(document.getElementById('fotos-contenido'), {
                terrenoId: t.id,
                medios: t.medios || [],
                portada: t.imagen,
                puedeEliminar: puedeEliminar,
                limiteBytes: LIMITE_ARCHIVO,
                compacto: true,
                onCambio: function(medios, portada) {
                    // Mantiene sincronizados el popup y los reportes
                    t.medios = medios;
                    t.imagen = portada;
                    renderizarMapa();
                }
            });

            document.getElementById('fotos-panel').style.display = 'block';
        }

        function cerrarPanelFotos() {
            document.getElementById('fotos-panel').style.display = 'none';
            Galeria.cerrarVisor();
            galeriaActual = null;
        }

        // Aviso antes de enviar el formulario si los archivos pesan demasiado
        function revisarArchivosFormulario() {
            var input = document.getElementById('medios');
            var ayuda = document.getElementById('mediosAyuda');
            var archivos = Array.from(input.files);
            var total = archivos.reduce(function(s, f) {
                return s + f.size;
            }, 0);
            var grandes = archivos.filter(function(f) {
                return f.size > LIMITE_ARCHIVO;
            });
            var mb = function(b) {
                return (b / 1048576).toFixed(1) + ' MB';
            };

            ayuda.style.color = '';
            if (!archivos.length) {
                ayuda.innerText = 'Puedes elegir varios a la vez. Después de guardar podrás subir más desde la galería del terreno.';
                return true;
            }

            var problema = null;
            if (grandes.length) {
                problema = `${grandes.map(f => f.name).join(', ')} pesa(n) más de ${mb(LIMITE_ARCHIVO)}.`;
            } else if (total > LIMITE_ENVIO) {
                problema = `Entre todos pesan ${mb(total)} y el máximo por envío es ${mb(LIMITE_ENVIO)}. Guarda con menos archivos y sube el resto desde la galería (allí se suben de uno en uno).`;
            } else if (archivos.length > MAX_ARCHIVOS) {
                problema = `Máximo ${MAX_ARCHIVOS} archivos por envío. Sube el resto desde la galería.`;
            }

            if (problema) {
                ayuda.style.color = '#b91c1c';
                ayuda.innerText = '⚠ ' + problema;
                return false;
            }
            ayuda.innerText = `✔ ${archivos.length} archivo(s) seleccionados (${mb(total)}). Se subirán al guardar.`;
            return true;
        }

        // Validación antes de guardar: lleva a la pestaña donde falta algo
        document.getElementById('formTerreno').addEventListener('submit', function(e) {
            var form = this;
            document.querySelectorAll('#formTerreno .tab').forEach(function(t) {
                t.classList.remove('con-error');
            });
            form.querySelectorAll('.invalido').forEach(function(c) {
                c.classList.remove('invalido');
            });

            var faltan = [];
            form.querySelectorAll('[required]').forEach(function(c) {
                if (!c.value.trim()) faltan.push(c);
            });
            var doc = document.getElementById('propietario_documento');
            if (doc.value.trim() && !/^[VEJGP]-?\d{5,10}(-?\d)?$/i.test(doc.value.trim())) faltan.push(doc);

            if (faltan.length) {
                e.preventDefault();
                faltan.forEach(function(c) {
                    c.classList.add('invalido');
                    var panel = c.closest('.panel-tab');
                    document.querySelector(`#formTerreno .tab[data-tab="${panel.dataset.panel}"]`).classList.add('con-error');
                });
                mostrarTab(faltan[0].closest('.panel-tab').dataset.panel);
                var mensaje = faltan.some(c => c.id === 'latitud') ?
                    'Falta ubicar el terreno: haz clic en el mapa o dibuja su contorno.' :
                    (faltan[0] === doc ? 'La cédula o RIF debe tener el formato V-12345678 o J-12345678-9.' : 'Completa los campos marcados en rojo.');
                alert(mensaje);
                if (faltan[0].id !== 'latitud' && faltan[0].id !== 'longitud') faltan[0].focus();
                return;
            }

            if (!revisarArchivosFormulario()) {
                e.preventDefault();
                mostrarTab('fotos');
                alert(document.getElementById('mediosAyuda').innerText);
            }
        });

        function eliminarTerreno(id) {
            if (confirm('¿Estás seguro de que deseas eliminar este terreno?')) {
                document.getElementById('eliminar_terreno_id').value = id;
                document.getElementById('formEliminar').submit();
            }
        }

        // soloFormulario = true: vacía los campos pero deja abiertos el historial y la galería
        function limpiarFormulario(soloFormulario) {
            var form = document.getElementById('formTerreno');
            form.reset();
            document.getElementById('terreno_id').value = '';
            document.getElementById('poligono_geojson').value = '';
            form.querySelectorAll('input').forEach(function(c) {
                if (c.placeholder === '🔒 Reservado (sin cambios)') c.placeholder = '';
            });
            form.querySelectorAll('.invalido').forEach(function(c) {
                c.classList.remove('invalido');
            });
            document.querySelectorAll('#formTerreno .tab').forEach(function(t) {
                t.classList.remove('con-error');
            });
            drawnItems.clearLayers();
            actualizarPoligono(false);
            alternarPublico();
            mostrarDocumentoActual(null);
            revisarArchivosFormulario();

            document.getElementById('btnGuardar').innerText = 'Guardar Terreno';
            document.getElementById('btnGuardar').classList.remove('editando');
            var modo = document.getElementById('modoForm');
            modo.innerText = 'Nuevo';
            modo.classList.remove('editando');
            contarReferencia();
            marcarPestanasConDatos();

            if (soloFormulario === true) return;
            mostrarTab('general');
            cerrarDetalleHistorial();
            cerrarPanelFotos();
            if (tempMarker) map.removeLayer(tempMarker);
        }

        // ---------------------------------------------------------------
        // REPORTES IMPRIMIBLES
        // ---------------------------------------------------------------
        var ESTILOS_REPORTE = `
            * { box-sizing: border-box; }
            body { font-family: Arial, sans-serif; color: #000; margin: 24px; font-size: 12px; }
            .encabezado { border-bottom: 3px solid #002b66; padding-bottom: 8px; margin-bottom: 14px; display: flex; justify-content: space-between; align-items: flex-end; }
            .encabezado h1 { font-size: 20px; color: #002b66; margin: 0; }
            .encabezado .meta { text-align: right; font-size: 11px; color: #333; }
            h2 { font-size: 15px; color: #002b66; margin: 18px 0 8px; }
            table { width: 100%; border-collapse: collapse; margin-top: 6px; }
            th, td { border: 1px solid #94a3b8; padding: 6px 8px; text-align: left; vertical-align: top; }
            thead th { background: #e2e8f0; }
            tr { page-break-inside: avoid; }
            .ficha th { width: 22%; background: #f1f5f9; }
            .resumen { display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
            .resumen div { border: 1px solid #94a3b8; border-radius: 4px; padding: 6px 10px; }
            .foto { max-width: 100%; max-height: 320px; border: 1px solid #94a3b8; margin-top: 10px; }
            .pie { margin-top: 30px; font-size: 10px; color: #555; border-top: 1px solid #ccc; padding-top: 6px; }
            .firmas { display: flex; justify-content: space-around; margin-top: 60px; }
            .firmas div { border-top: 1px solid #000; width: 35%; text-align: center; padding-top: 4px; }
            .acciones { margin-bottom: 16px; }
            .acciones button { padding: 8px 14px; font-weight: bold; cursor: pointer; border: none; border-radius: 4px; background: #0f766e; color: #fff; }
            .acciones button.gris { background: #64748b; }
            @media print { .acciones { display: none; } body { margin: 0; } }
        `;

        function abrirVentanaReporte(titulo, cuerpoHTML) {
            var ventana = window.open('', '_blank');
            if (!ventana) {
                alert('El navegador bloqueó la ventana del reporte. Permita las ventanas emergentes para este sitio.');
                return;
            }

            var ahora = new Date().toLocaleString('es-VE');
            ventana.document.open();
            ventana.document.write(`<!DOCTYPE html>
                <html lang="es"><head><meta charset="UTF-8">
                <title>${esc(titulo)}</title>
                <base href="${esc(location.href)}">
                <style>${ESTILOS_REPORTE}</style>
                </head><body>
                <div class="acciones">
                    <button onclick="window.print()">🖨 Imprimir / Guardar PDF</button>
                    <button class="gris" onclick="window.close()">Cerrar</button>
                </div>
                <div class="encabezado">
                    <h1>GeoTerrenos · ${esc(titulo)}</h1>
                    <div class="meta">Generado por: <b>${esc(usuarioActual)}</b> (${esc(rolActual)})<br>Fecha: ${esc(ahora)}</div>
                </div>
                ${cuerpoHTML}
                <div class="pie">Documento generado automáticamente por el sistema GeoTerrenos.</div>
                </body></html>`);
            ventana.document.close();

            // Imprimir cuando carguen las imágenes
            ventana.onload = function() {
                ventana.focus();
                ventana.print();
            };
        }

        function imprimirReporteGeneral() {
            if (!PERMISOS.reporte_general) {
                alert('Su rol no permite generar el reporte general.');
                return;
            }
            var lista = terrenos.filter(function(t) {
                return categoriaVisible(obtenerCategoria(t).categoria);
            });

            if (lista.length === 0) {
                alert('No hay terrenos para mostrar con los filtros seleccionados.');
                return;
            }

            var totalPersonas = 0,
                totalArea = 0,
                conteo = {};
            lista.forEach(function(t) {
                totalPersonas += parseInt(t.personas) || 0;
                totalArea += parseFloat(t.area_m2) || 0;
                var cat = obtenerCategoria(t).categoria;
                conteo[cat] = (conteo[cat] || 0) + 1;
            });

            var resumen = `
                <div class="resumen">
                    <div><b>Total terrenos:</b> ${lista.length}</div>
                    <div><b>Personas habitando:</b> ${totalPersonas}</div>
                    <div><b>Área total:</b> ${totalArea.toLocaleString('es-VE', {maximumFractionDigits: 2})} m² (${(totalArea / 10000).toFixed(2)} ha)</div>
                    ${Object.keys(conteo).map(c => `<div><b>${ETIQUETAS_CATEGORIA[c]}:</b> ${conteo[c]}</div>`).join('')}
                </div>`;

            var filas = lista.map(function(t, i) {
                return `<tr>
                    <td>${i + 1}</td>
                    <td>${esc(t.codigo_catastral || '')}</td>
                    <td><b>${esc(t.nombre)}</b><br><small>${esc(etiquetaOpcion('uso_actual', t.uso_actual) || ETIQUETAS_TIPO[t.tipo] || t.tipo)}</small></td>
                    <td>${esc(ETIQUETAS_ESTADO[t.estado] || t.estado)}</td>
                    <td>${esc(etiquetaOpcion('naturaleza', t.naturaleza))}<br><small>${esc(etiquetaOpcion('regimen_tenencia', t.regimen_tenencia))}</small></td>
                    <td>${esc(etiquetaOpcion('situacion_legal', t.situacion_legal) || '—')}</td>
                    <td>${esc(t.personas)} / ${esc(t.familias || 0)}</td>
                    <td>${t.area_m2 > 0 ? esc(t.area_m2) : 'N/A'}</td>
                    <td>${esc([t.estado_region, t.municipio, t.parroquia].filter(Boolean).join(', '))}<br><small>${esc(t.zona_especifica)}</small></td>
                    <td>${esc(t.creador)}<br><small>${esc(t.creado_en)}</small></td>
                </tr>`;
            }).join('');

            var cuerpo = `
                ${resumen}
                <table>
                    <thead><tr>
                        <th>#</th><th>Código</th><th>Nombre / Uso</th><th>Estado</th><th>Naturaleza / Tenencia</th><th>Situación legal</th>
                        <th>Pers. / Fam.</th><th>Área (m²)</th><th>Ubicación / Zona</th><th>Registrado por</th>
                    </tr></thead>
                    <tbody>${filas}</tbody>
                </table>`;

            abrirVentanaReporte('Reporte General de Terrenos', cuerpo);
        }


        renderizarMapa();
        enfocarTerrenoDesdeURL();
    </script>

</body>

</html>