<?php
session_start();
require 'db.php';

// Control de Acceso
if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit;
}

$user_id   = $_SESSION['usuario_id'];
$user_name = $_SESSION['usuario_nombre'];
$user_rol  = $_SESSION['usuario_rol'];

// Permisos estrictos: Solo super_admin edita y elimina
$puede_editar   = ($user_rol === 'super_admin');
$puede_eliminar = ($user_rol === 'super_admin');

// ---------------------------------------------------------------------
// PROCESAR POST (GUARDAR / EDITAR / ELIMINAR)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // ELIMINAR TERRENO
    if (isset($_POST['eliminar_terreno'])) {
        $id = intval($_POST['terreno_id']);

        if (!$puede_eliminar) {
            die("Error 403: Únicamente el Super Admin puede eliminar terrenos.");
        }

        $stmtTerreno = $pdo->prepare("SELECT nombre, imagen FROM terrenos WHERE id = ?");
        $stmtTerreno->execute([$id]);
        $terreno = $stmtTerreno->fetch();

        if ($terreno) {
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

        // Validaciones básicas
        $tipos_validos   = ['vivienda', 'montana', 'hogar', 'galpon', 'comercio'];
        $estados_validos = ['habitable', 'no_habitable', 'en_riesgo'];

        if ($nombre === '' || $zona_especifica === '') {
            die("Error: El nombre y la zona específica son obligatorios.");
        }
        if (!in_array($tipo, $tipos_validos, true) || !in_array($estado, $estados_validos, true)) {
            die("Error: Tipo o estado no válido.");
        }
        if (($_POST['latitud'] ?? '') === '' || ($_POST['longitud'] ?? '') === '' || abs($latitud) > 90 || abs($longitud) > 180) {
            die("Error: Debe seleccionar una coordenada válida en el mapa.");
        }

        // La imagen actual se toma de la BD (no del formulario) para evitar borrar archivos arbitrarios
        $nombre_imagen = null;
        if ($id) {
            $stmtImg = $pdo->prepare("SELECT imagen FROM terrenos WHERE id = ?");
            $stmtImg->execute([$id]);
            $nombre_imagen = $stmtImg->fetchColumn() ?: null;
        }

        if (isset($_FILES['imagen']) && $_FILES['imagen']['error'] === UPLOAD_ERR_OK) {
            $ext = strtolower(pathinfo($_FILES['imagen']['name'], PATHINFO_EXTENSION));
            $permitidas = ['jpg', 'jpeg', 'png', 'webp'];

            if (in_array($ext, $permitidas) && @getimagesize($_FILES['imagen']['tmp_name']) !== false) {
                if (!file_exists('uploads')) {
                    mkdir('uploads', 0777, true);
                }

                if ($nombre_imagen && file_exists("uploads/" . basename($nombre_imagen))) {
                    unlink("uploads/" . basename($nombre_imagen));
                }

                $nombre_imagen = time() . '_' . uniqid() . '.' . $ext;
                move_uploaded_file($_FILES['imagen']['tmp_name'], 'uploads/' . $nombre_imagen);
            }
        }

        $orientacion  = ($latitud >= 0) ? 'Norte' : 'Sur';
        $orientacion .= ' / ';
        $orientacion .= ($longitud >= 0) ? 'Este' : 'Oeste';

        if ($id) {
            $sql = "UPDATE terrenos SET nombre=?, tipo=?, estado=?, personas=?, latitud=?, longitud=?, orientacion=?, zona_especifica=?, estado_region=?, municipio=?, parroquia=?, puntos_referencia=?, tiene_lago=?, tiene_piscina=?, imagen=?, area_m2=? WHERE id=?";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nombre, $tipo, $estado, $personas, $latitud, $longitud, $orientacion, $zona_especifica, $estado_region, $municipio, $parroquia, $puntos_referencia, $tiene_lago, $tiene_piscina, $nombre_imagen, $area_m2, $id]);

            $stmtAudit = $pdo->prepare("INSERT INTO auditoria_terrenos (terreno_id, usuario_id, usuario_nombre, accion, detalles) VALUES (?, ?, ?, 'EDITAR', ?)");
            $stmtAudit->execute([$id, $user_id, $user_name, "Modificado por $user_name."]);
        } else {
            $sql = "INSERT INTO terrenos (nombre, tipo, estado, personas, latitud, longitud, orientacion, zona_especifica, estado_region, municipio, parroquia, puntos_referencia, tiene_lago, tiene_piscina, imagen, area_m2, creado_por_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$nombre, $tipo, $estado, $personas, $latitud, $longitud, $orientacion, $zona_especifica, $estado_region, $municipio, $parroquia, $puntos_referencia, $tiene_lago, $tiene_piscina, $nombre_imagen, $area_m2, $user_id]);
            $nuevo_id = $pdo->lastInsertId();

            $stmtAudit = $pdo->prepare("INSERT INTO auditoria_terrenos (terreno_id, usuario_id, usuario_nombre, accion, detalles) VALUES (?, ?, ?, 'CREAR', ?)");
            $stmtAudit->execute([$nuevo_id, $user_id, $user_name, "Registro inicial por $user_name."]);
        }

        header("Location: geoterreno.php");
        exit;
    }
}

// Cargar Terrenos y Auditorías
$terrenos = $pdo->query("SELECT t.*, u.nombre as creador FROM terrenos t JOIN usuarios u ON t.creado_por_id = u.id ORDER BY t.id DESC")->fetchAll();
$auditorias = $pdo->query("SELECT a.*, COALESCE(t.nombre, 'Terreno Eliminado') as terreno_nombre FROM auditoria_terrenos a LEFT JOIN terrenos t ON a.terreno_id = t.id ORDER BY a.fecha_hora DESC LIMIT 50")->fetchAll();
?>

<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <title>GeoTerrenos - Gestión Avanzada</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.css" />
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
            width: 260px;
            max-height: calc(100vh - 50px);
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

        .img-card {
            width: 100%;
            border-radius: 6px;
            border: 1px solid #e2e8f0;
            margin-bottom: 8px;
            overflow: hidden;
            background: #f8fafc;
        }

        .img-card img {
            width: 100%;
            height: 160px;
            object-fit: cover;
            display: block;
            cursor: pointer;
            transition: transform 0.2s;
        }

        .img-card img:hover {
            transform: scale(1.03);
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
            margin-bottom: 6px;
            color: #1e293b;
            font-size: 12px;
        }

        .leyenda-item {
            display: flex;
            align-items: center;
            margin-bottom: 4px;
            cursor: pointer;
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

        .checklist-container {
            background: #f1f5f9;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            padding: 8px;
            margin-bottom: 8px;
        }

        .checklist-title {
            font-size: 11px;
            font-weight: bold;
            color: #475569;
            margin-bottom: 6px;
        }

        .checklist-item {
            display: flex;
            align-items: center;
            gap: 6px;
            font-size: 12px;
            color: #1e293b;
            margin-bottom: 4px;
            cursor: pointer;
        }

        .btn-green {
            width: 100%;
            padding: 9px;
            background: #16a34a;
            color: white;
            border: none;
            border-radius: 5px;
            font-weight: bold;
            cursor: pointer;
        }

        .btn-green:hover {
            background: #15803d;
        }

        .btn-gray {
            width: 100%;
            padding: 7px;
            background: #64748b;
            color: white;
            border: none;
            border-radius: 5px;
            font-weight: bold;
            cursor: pointer;
            margin-top: 4px;
        }

        .audit-container {
            background: #fafafa;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            min-height: 120px;
            max-height: 220px;
            overflow-y: auto;
            margin-bottom: 10px;
        }

        .audit-item {
            padding: 8px 10px;
            border-bottom: 1px solid #e2e8f0;
            cursor: pointer;
            transition: background 0.2s;
        }

        .audit-item:hover {
            background: #e0f2fe;
        }

        #detalleHistorialBox {
            display: none;
            background: #eff6ff;
            border: 1px solid #bfdbfe;
            border-radius: 6px;
            padding: 12px;
            font-size: 12px;
            color: #1e3a8a;
            margin-bottom: 20px;
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
                <small>Rol: <?= htmlspecialchars($user_rol) ?></small>
            </div>
            <a href="logout.php">Cerrar Sesión</a>
        </div>

        <!-- Leyenda -->
        <div class="leyenda">
            <h4>Filtrar Capas / Estado</h4>
            <div class="leyenda-item">
                <input type="checkbox" id="chk_nueva" checked onclick="filtrarMapa()">
                <span class="color-box" style="background: #2ecc71;"></span> Verde: Zona Nueva (&lt; 7 días)
            </div>
            <div class="leyenda-item">
                <input type="checkbox" id="chk_antigua" checked onclick="filtrarMapa()">
                <span class="color-box" style="background: #3498db;"></span> Azul: Zona Antigua (Habitable)
            </div>
            <div class="leyenda-item">
                <input type="checkbox" id="chk_riesgo" checked onclick="filtrarMapa()">
                <span class="color-box" style="background: #e74c3c;"></span> Rojo: Zona En Riesgo
            </div>
            <div class="leyenda-item">
                <input type="checkbox" id="chk_nohabitable" checked onclick="filtrarMapa()">
                <span class="color-box" style="background: #7f8c8d;"></span> Gris: No Habitable
            </div>
        </div>

        <!-- Reportes -->
        <div class="reporte-box">
            <h4>Reportes</h4>
            <small>Imprime (o guarda en PDF) los terrenos visibles según los filtros de arriba.</small>
            <button type="button" class="btn-reporte" onclick="imprimirReporteGeneral()">🖨 Generar Reporte General</button>
        </div>

        <!-- Formulario principal -->
        <form method="POST" id="formTerreno" action="geoterreno.php" enctype="multipart/form-data">
            <h3 style="font-size:13px; margin-bottom:6px; color:#002b66;">Gestión de Terrenos</h3>
            <input type="hidden" name="terreno_id" id="terreno_id">
            <input type="hidden" name="imagen_actual" id="imagen_actual">

            <div class="form-group">
                <label>Nombre del Terreno / Propiedad *</label>
                <input type="text" name="nombre" id="nombre" required placeholder="Ej. Parcela San José">
            </div>

            <div style="display:flex; gap:5px;">
                <div class="form-group" style="flex:1;">
                    <label>Tipo</label>
                    <select name="tipo" id="tipo">
                        <option value="vivienda">Vivienda</option>
                        <option value="montana">Montaña</option>
                        <option value="hogar">Hogar / Casa</option>
                        <option value="galpon">Galpón</option>
                        <option value="comercio">Comercio</option>
                    </select>
                </div>

                <div class="form-group" style="flex:1;">
                    <label>Estado Habitacional</label>
                    <select name="estado" id="estado">
                        <option value="habitable">Habitable</option>
                        <option value="no_habitable">No Habitable</option>
                        <option value="en_riesgo">En Riesgo</option>
                    </select>
                </div>
            </div>

            <div class="form-group">
                <label>Nº de Personas Habitándolo</label>
                <input type="number" name="personas" id="personas" value="0" min="0">
            </div>

            <div style="display:flex; gap:5px;">
                <div class="form-group" style="flex:1;">
                    <label>Estado / Región</label>
                    <input type="text" name="estado_region" id="estado_region" placeholder="Ej: Miranda">
                </div>
                <div class="form-group" style="flex:1;">
                    <label>Municipio</label>
                    <input type="text" name="municipio" id="municipio" placeholder="Ej: Sucre">
                </div>
            </div>

            <div style="display:flex; gap:5px;">
                <div class="form-group" style="flex:1;">
                    <label>Parroquia</label>
                    <input type="text" name="parroquia" id="parroquia" placeholder="Ej: Petare">
                </div>
                <div class="form-group" style="flex:1;">
                    <label>Zona Específica *</label>
                    <input type="text" name="zona_especifica" id="zona_especifica" required placeholder="Ej: Sector Norte">
                </div>
            </div>

            <div style="display:flex; gap:5px;">
                <div class="form-group" style="flex:1;">
                    <label>Latitud *</label>
                    <input type="text" name="latitud" id="latitud" readonly required placeholder="Clic en mapa">
                </div>
                <div class="form-group" style="flex:1;">
                    <label>Longitud *</label>
                    <input type="text" name="longitud" id="longitud" readonly required placeholder="Clic en mapa">
                </div>
            </div>

            <div class="form-group">
                <label>Área Calculada (m²)</label>
                <input type="number" step="0.01" name="area_m2" id="area_m2" readonly placeholder="Calculado con el dibujo">
            </div>

            <div class="form-group">
                <label>Foto de la Zona (JPG, PNG, WEBP)</label>
                <input type="file" name="imagen" id="imagen" accept="image/jpeg,image/png,image/webp">
            </div>

            <div class="checklist-container">
                <div class="checklist-title">Seleccionar Características:</div>
                <label class="checklist-item">
                    <input type="checkbox" name="tiene_lago" id="tiene_lago" value="1">
                    <span>Contiene Lago / Cuerpo de Agua</span>
                </label>
                <label class="checklist-item">
                    <input type="checkbox" name="tiene_piscina" id="tiene_piscina" value="1">
                    <span>Cuenta con Piscina</span>
                </label>
            </div>

            <div class="form-group">
                <label>Puntos de Referencia Cercanos</label>
                <textarea name="puntos_referencia" id="puntos_referencia" rows="2" placeholder="Ej: Cerca de la torre de luz..."></textarea>
            </div>

            <button type="submit" name="guardar_terreno" id="btnGuardar" class="btn-green">Guardar Terreno</button>
            <button type="button" onclick="limpiarFormulario()" class="btn-gray">Cancelar / Limpiar</button>
        </form>

        <form method="POST" id="formEliminar" action="geoterreno.php" style="display:none;">
            <input type="hidden" name="terreno_id" id="eliminar_terreno_id">
            <input type="hidden" name="eliminar_terreno" value="1">
        </form>

        <!-- Historial con Scroll -->
        <h3 style="font-size:12px; color:#002b66; margin-top:10px;">Historial de Modificaciones (Haz Clic para Cargar)</h3>

        <div class="audit-container">
            <?php if (!empty($auditorias)): ?>
                <?php foreach ($auditorias as $aud): ?>
                    <div class="audit-item" onclick="seleccionarHistorial(<?= htmlspecialchars(json_encode($aud)) ?>)">
                        <strong style="color:#2563eb;"><?= htmlspecialchars($aud['terreno_nombre']) ?></strong><br>
                        <span style="font-size:11px;">Por: <b><?= htmlspecialchars($aud['usuario_nombre']) ?></b> (<?= htmlspecialchars($aud['accion']) ?>)</span><br>
                        <small style="color:#64748b;"><?= $aud['fecha_hora'] ?></small>
                    </div>
                <?php endforeach; ?>
            <?php else: ?>
                <div style="padding:10px; font-size:11px; color:#64748b; text-align:center;">
                    No hay modificaciones registradas.
                </div>
            <?php endif; ?>
        </div>

        <!-- Espacio Destacado para Detalle -->
        <div id="detalleHistorialBox">
            <h4 style="margin-bottom:6px; font-size:12px; color:#1e40af;">Detalle de la Última Modificación</h4>
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
                <span>Fotos del Terreno</span>
                <span class="close-btn" onclick="cerrarPanelFotos()">✕</span>
            </h4>
            <div id="fotos-contenido"></div>
        </div>

        <div id="coords-box">Lat: 0.00000000 | Lng: 0.00000000</div>

        <div id="map"></div>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet.draw/1.0.4/leaflet.draw.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/@turf/turf@6/turf.min.js"></script>

    <script>
        var map = L.map('map').setView([10.4806, -66.9036], 12);

        L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
            attribution: '© OpenStreetMap contributors'
        }).addTo(map);

        var tempMarker;
        var layerGroup = L.layerGroup().addTo(map);
        var puedeEditar = <?= json_encode($puede_editar) ?>;
        var puedeEliminar = <?= json_encode($puede_eliminar) ?>;
        var terrenos = <?= json_encode($terrenos, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;
        var usuarioActual = <?= json_encode($user_name, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?>;

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

        map.on(L.Draw.Event.CREATED, function(e) {
            var layer = e.layer;
            drawnItems.clearLayers();
            drawnItems.addLayer(layer);

            var geojson = layer.toGeoJSON();
            var area = turf.area(geojson);

            document.getElementById('area_m2').value = area.toFixed(2);
            alert(`Superficie calculada: ${area.toFixed(2)} m² (${(area / 10000).toFixed(2)} Hectáreas)`);
        });

        // Clic en Mapa
        map.on('click', function(e) {
            var lat = e.latlng.lat.toFixed(8);
            var lng = e.latlng.lng.toFixed(8);

            // Si se está editando un terreno, el clic solo cambia su ubicación (no crea uno nuevo).
            // Para registrar uno nuevo use "Cancelar / Limpiar".
            document.getElementById('latitud').value = lat;
            document.getElementById('longitud').value = lng;

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
        });

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

        function renderizarMapa() {
            layerGroup.clearLayers();

            terrenos.forEach(function(t) {
                var info = obtenerCategoria(t);
                var colorMarker = info.color;

                if (!categoriaVisible(info.categoria)) return;

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
                        <b>Estado:</b> ${esc(ETIQUETAS_ESTADO[t.estado] || t.estado)}<br>
                        <b>Área:</b> ${t.area_m2 > 0 ? esc(t.area_m2) + ' m²' : 'N/A'}<br>
                        <b>Personas:</b> ${esc(t.personas)}<br>
                        <b>Ubicación:</b> ${esc(t.estado_region)}, ${esc(t.municipio)}<br>
                        <b>Zona:</b> ${esc(t.zona_especifica)}<br>
                        <b>Piscina / Lago:</b> ${t.tiene_piscina == 1 ? 'Sí' : 'No'} / ${t.tiene_lago == 1 ? 'Sí' : 'No'}<br>
                        <b>Referencias:</b> ${esc(t.puntos_referencia || 'N/A')}<br>
                        <small><b>Registrado por:</b> ${esc(t.creador)}</small><br>
                        <small><b>Fecha:</b> ${esc(t.creado_en)}</small><br><br>

                        <div style="margin-bottom:8px;">
                            <a href="${urlWhatsApp}" target="_blank" class="share-btn share-ws">WhatsApp</a>
                            <a href="${urlEmail}" class="share-btn share-mail">Correo</a>
                        </div>
                `;

                popupContent += `<button onclick='imprimirReporteTerreno(${Number(t.id)})' style='padding:4px 8px; font-size:11px; background:#0f766e; color:white; border:none; border-radius:3px; cursor:pointer;'>Imprimir Ficha</button> `;

                if (puedeEditar) {
                    popupContent += `<button onclick='cargarParaEditar(buscarTerreno(${Number(t.id)}))' style='padding:4px 8px; font-size:11px; background:#2563eb; color:white; border:none; border-radius:3px; cursor:pointer;'>Editar / Actualizar</button> `;
                }
                if (puedeEliminar) {
                    popupContent += `<button onclick='eliminarTerreno(${t.id})' style='padding:4px 8px; font-size:11px; background:#dc2626; color:white; border:none; border-radius:3px; cursor:pointer;'>Eliminar</button>`;
                }

                popupContent += `</div>`;
                marker.bindPopup(popupContent);
                layerGroup.addLayer(marker);
            });
        }

        function filtrarMapa() {
            renderizarMapa();
        }

        // Selección de Historial
        function seleccionarHistorial(aud) {
            var box = document.getElementById('detalleHistorialBox');
            box.style.display = 'block';
            document.getElementById('historialUsuario').innerText = `Modificado por: ${aud.usuario_nombre}`;
            document.getElementById('historialAccion').innerText = `Acción: ${aud.accion}`;
            document.getElementById('historialDetalles').innerText = `Detalle: "${aud.detalles}"`;
            document.getElementById('historialFecha').innerText = `Fecha y Hora: ${aud.fecha_hora}`;

            if (aud.terreno_id) {
                var terrenoEncontrado = terrenos.find(t => t.id == aud.terreno_id);
                if (terrenoEncontrado) {
                    cargarParaEditar(terrenoEncontrado);
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

            // 1. Cargar datos en el formulario
            document.getElementById('terreno_id').value = t.id;
            document.getElementById('nombre').value = t.nombre;
            document.getElementById('tipo').value = t.tipo;
            document.getElementById('estado').value = t.estado;
            document.getElementById('personas').value = t.personas;
            document.getElementById('estado_region').value = t.estado_region || '';
            document.getElementById('municipio').value = t.municipio || '';
            document.getElementById('parroquia').value = t.parroquia || '';
            document.getElementById('zona_especifica').value = t.zona_especifica;
            document.getElementById('latitud').value = t.latitud;
            document.getElementById('longitud').value = t.longitud;
            document.getElementById('area_m2').value = t.area_m2 || '';
            document.getElementById('puntos_referencia').value = t.puntos_referencia || '';
            document.getElementById('tiene_lago').checked = (t.tiene_lago == 1);
            document.getElementById('tiene_piscina').checked = (t.tiene_piscina == 1);
            document.getElementById('imagen_actual').value = t.imagen || '';
            document.getElementById('btnGuardar').innerText = 'Actualizar Terreno (Super Admin)';

            // 2. Centrar mapa en el terreno
            map.setView([t.latitud, t.longitud], 15);

            // 3. Cargar imágenes al lado derecho del mapa
            var panel = document.getElementById('fotos-panel');
            var contenedor = document.getElementById('fotos-contenido');
            contenedor.innerHTML = '';

            if (t.imagen) {
                contenedor.innerHTML = `
                    <div class="img-card">
                        <a href="uploads/${esc(t.imagen)}" target="_blank">
                            <img src="uploads/${esc(t.imagen)}" title="Haz clic para agrandar">
                        </a>
                        <div style="padding:6px; font-size:10px; color:#475569; text-align:center;">
                            Imagen principal registrada
                        </div>
                    </div>
                `;
            } else {
                contenedor.innerHTML = `
                    <div style="padding:15px; font-size:11px; color:#64748b; text-align:center; background:#f8fafc; border-radius:6px; border:1px dashed #cbd5e1;">
                        Sin imágenes registradas para este terreno.
                    </div>
                `;
            }

            panel.style.display = 'block';
        }

        function cerrarPanelFotos() {
            document.getElementById('fotos-panel').style.display = 'none';
        }

        function eliminarTerreno(id) {
            if (confirm('¿Estás seguro de que deseas eliminar este terreno?')) {
                document.getElementById('eliminar_terreno_id').value = id;
                document.getElementById('formEliminar').submit();
            }
        }

        function limpiarFormulario() {
            document.getElementById('formTerreno').reset();
            document.getElementById('terreno_id').value = '';
            document.getElementById('imagen_actual').value = '';
            document.getElementById('btnGuardar').innerText = 'Guardar Terreno';
            document.getElementById('detalleHistorialBox').style.display = 'none';
            cerrarPanelFotos();
            if (tempMarker) map.removeLayer(tempMarker);
            drawnItems.clearLayers();
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
                    <div class="meta">Generado por: <b>${esc(usuarioActual)}</b><br>Fecha: ${esc(ahora)}</div>
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
                    <td><b>${esc(t.nombre)}</b></td>
                    <td>${esc(ETIQUETAS_TIPO[t.tipo] || t.tipo)}</td>
                    <td>${esc(ETIQUETAS_ESTADO[t.estado] || t.estado)}</td>
                    <td>${esc(t.personas)}</td>
                    <td>${t.area_m2 > 0 ? esc(t.area_m2) : 'N/A'}</td>
                    <td>${esc([t.estado_region, t.municipio, t.parroquia].filter(Boolean).join(', '))}<br><small>${esc(t.zona_especifica)}</small></td>
                    <td>${esc(t.latitud)}<br>${esc(t.longitud)}</td>
                    <td>${t.tiene_lago == 1 ? 'Sí' : 'No'} / ${t.tiene_piscina == 1 ? 'Sí' : 'No'}</td>
                    <td>${esc(t.creador)}<br><small>${esc(t.creado_en)}</small></td>
                </tr>`;
            }).join('');

            var cuerpo = `
                ${resumen}
                <table>
                    <thead><tr>
                        <th>#</th><th>Nombre</th><th>Tipo</th><th>Estado</th><th>Pers.</th><th>Área (m²)</th>
                        <th>Ubicación / Zona</th><th>Lat / Lng</th><th>Lago / Piscina</th><th>Registrado por</th>
                    </tr></thead>
                    <tbody>${filas}</tbody>
                </table>`;

            abrirVentanaReporte('Reporte General de Terrenos', cuerpo);
        }

        function imprimirReporteTerreno(id) {
            var t = buscarTerreno(id);
            if (!t) return;

            var cuerpo = `
                <h2>Propiedad: ${esc(t.nombre)} (${esc(ETIQUETAS_ESTADO[t.estado] || t.estado)})</h2>
                <p><b>Ubicación Georreferenciada:</b> Lat: ${esc(t.latitud)} | Lng: ${esc(t.longitud)} (${esc(t.orientacion || '')})</p>
                <table class="ficha">
                    <tr><th>Tipo de Uso</th><td>${esc(ETIQUETAS_TIPO[t.tipo] || t.tipo)}</td>
                        <th>N° Personas Hab.</th><td>${esc(t.personas)}</td></tr>
                    <tr><th>Área Calculada</th><td>${t.area_m2 > 0 ? esc(t.area_m2) + ' m² (' + (t.area_m2 / 10000).toFixed(2) + ' ha)' : 'N/A'}</td>
                        <th>Estado / Región</th><td>${esc(t.estado_region)}</td></tr>
                    <tr><th>Municipio</th><td>${esc(t.municipio)}</td>
                        <th>Parroquia</th><td>${esc(t.parroquia)}</td></tr>
                    <tr><th>Zona Específica</th><td>${esc(t.zona_especifica)}</td>
                        <th>Puntos de Referencia</th><td>${esc(t.puntos_referencia || 'N/A')}</td></tr>
                    <tr><th>Cuerpo de Agua / Lago</th><td>${t.tiene_lago == 1 ? 'Sí' : 'No'}</td>
                        <th>Cuenta con Piscina</th><td>${t.tiene_piscina == 1 ? 'Sí' : 'No'}</td></tr>
                    <tr><th>Registrado Por</th><td>${esc(t.creador)}</td>
                        <th>Fecha Registro</th><td>${esc(t.creado_en)}</td></tr>
                    <tr><th>Mapa</th><td colspan="3">https://www.google.com/maps?q=${esc(t.latitud)},${esc(t.longitud)}</td></tr>
                </table>
                ${t.imagen ? `<img class="foto" src="uploads/${esc(t.imagen)}" alt="Foto del terreno">` : ''}
                <div class="firmas"><div>Elaborado por</div><div>Revisado por</div></div>`;

            abrirVentanaReporte('Ficha de Terreno', cuerpo);
        }

        renderizarMapa();
    </script>

</body>

</html>