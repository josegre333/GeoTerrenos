<?php
session_start();
require 'db.php';
require 'medios.php';
require 'campos_terreno.php';

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
$puede_imprimir = puede('imprimir_ficha');

$TIPOS = [
    'vivienda' => 'Vivienda',
    'montana'  => 'Montaña',
    'hogar'    => 'Hogar / Casa',
    'galpon'   => 'Galpón',
    'comercio' => 'Comercio',
];
$ESTADOS = [
    'habitable'    => 'Habitable',
    'no_habitable' => 'No habitable',
    'en_riesgo'    => 'En riesgo',
];
$CLASES = [
    'urbano' => 'Urbano',
    'rural'  => 'Rural',
];
$CONSERVACION = [
    'bueno'   => 'Bueno',
    'regular' => 'Regular',
    'malo'    => 'Malo',
];
$TIPOS_CONSTRUCCION = ['Casa', 'Apartamento', 'Edificio', 'Galpón', 'Local comercial', 'Anexo', 'Depósito', 'Rancho', 'Otro'];
$MATERIALES = ['Bloque / Concreto', 'Ladrillo', 'Madera', 'Metálica', 'Bahareque', 'Zinc / Lámina', 'Mixto', 'Otro'];

function h($v)
{
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

function num($v, $dec = 2)
{
    return number_format((float)$v, $dec, ',', '.');
}

// ---------------------------------------------------------------------
// PROCESAR POST (CONSTRUCCIONES)
// ---------------------------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$puede_editar) {
        die("Error 403: Únicamente el Super Admin puede modificar construcciones.");
    }

    $terreno_id = intval($_POST['terreno_id'] ?? 0);
    $stmtT = $pdo->prepare("SELECT id, nombre FROM terrenos WHERE id = ?");
    $stmtT->execute([$terreno_id]);
    $terreno_post = $stmtT->fetch();
    if (!$terreno_post) {
        die("Error: El terreno no existe.");
    }

    if (isset($_POST['agregar_construccion'])) {
        $tipo    = trim($_POST['tipo'] ?? '');
        $uso     = trim($_POST['uso'] ?? '');
        $pisos   = max(1, intval($_POST['pisos'] ?? 1));
        $area    = max(0, floatval($_POST['area_construida'] ?? 0));
        $anio    = ($_POST['anio_construccion'] ?? '') !== '' ? intval($_POST['anio_construccion']) : null;
        $material = trim($_POST['material'] ?? '');
        $conserv = $_POST['estado_conservacion'] ?? 'bueno';

        if ($tipo === '' || mb_strlen($tipo) > 60 || mb_strlen($uso) > 60 || mb_strlen($material) > 60) {
            die("Error: Revise el tipo, uso y material de la construcción.");
        }
        if (!isset($CONSERVACION[$conserv])) {
            die("Error: Estado de conservación no válido.");
        }
        if ($anio !== null && ($anio < 1800 || $anio > intval(date('Y')))) {
            die("Error: El año de construcción no es válido.");
        }

        $pdo->prepare("INSERT INTO construcciones (terreno_id, tipo, uso, pisos, area_construida, anio_construccion, material, estado_conservacion) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$terreno_id, $tipo, $uso ?: null, $pisos, $area, $anio, $material ?: null, $conserv]);

        $pdo->prepare("INSERT INTO auditoria_terrenos (terreno_id, usuario_id, usuario_nombre, accion, detalles) VALUES (?, ?, ?, 'EDITAR', ?)")
            ->execute([$terreno_id, $user_id, $user_name, "$user_name agregó una construcción ($tipo, " . num($area) . " m²)."]);
    }

    if (isset($_POST['eliminar_construccion'])) {
        $cid = intval($_POST['construccion_id'] ?? 0);
        $stmtC = $pdo->prepare("SELECT tipo, area_construida FROM construcciones WHERE id = ? AND terreno_id = ?");
        $stmtC->execute([$cid, $terreno_id]);
        if ($c = $stmtC->fetch()) {
            $pdo->prepare("DELETE FROM construcciones WHERE id = ?")->execute([$cid]);
            $pdo->prepare("INSERT INTO auditoria_terrenos (terreno_id, usuario_id, usuario_nombre, accion, detalles) VALUES (?, ?, ?, 'EDITAR', ?)")
                ->execute([$terreno_id, $user_id, $user_name, "$user_name eliminó una construcción ({$c['tipo']}, " . num($c['area_construida']) . " m²)."]);
        }
    }

    header("Location: consulta_catastral.php?id=$terreno_id#construcciones");
    exit;
}

// ---------------------------------------------------------------------
// MODO FICHA (un predio) o MODO BÚSQUEDA
// ---------------------------------------------------------------------
$ficha = null;
$construcciones = [];

if (isset($_GET['id'])) {
    $stmt = $pdo->prepare("SELECT t.*, u.nombre AS creador FROM terrenos t JOIN usuarios u ON t.creado_por_id = u.id WHERE t.id = ?");
    $stmt->execute([intval($_GET['id'])]);
    $ficha = $stmt->fetch();

    if ($ficha) {
        $ver_privado = puedeVerDatosPrivados($user_rol);
        $riesgos_ficha = listaRiesgos($ficha['riesgos']);
        $vulnerables = (int)$ficha['ninos'] + (int)$ficha['adultos_mayores'] + (int)$ficha['personas_discapacidad'];

        $stmtC = $pdo->prepare("SELECT * FROM construcciones WHERE terreno_id = ? ORDER BY id");
        $stmtC->execute([$ficha['id']]);
        $construcciones = $stmtC->fetchAll();

        $stmtH = $pdo->prepare("SELECT usuario_nombre, accion, detalles, fecha_hora FROM auditoria_terrenos WHERE terreno_id = ? ORDER BY fecha_hora DESC LIMIT 10");
        $stmtH->execute([$ficha['id']]);
        $historial = $stmtH->fetchAll();

        $galeria = mediosPorTerreno($pdo, [$ficha['id']])[$ficha['id']] ?? [];
        $n_fotos  = count(array_filter($galeria, fn($m) => $m['tipo'] === 'foto'));
        $n_videos = count($galeria) - $n_fotos;

        $area_construida_total = array_sum(array_column($construcciones, 'area_construida'));
        $area_terreno = (float)$ficha['area_m2'];
        $indice_construccion = $area_terreno > 0 ? $area_construida_total / $area_terreno : null;
    }
} else {
    // Filtros
    $f = [
        'q'             => trim($_GET['q'] ?? ''),
        'estado_region' => trim($_GET['estado_region'] ?? ''),
        'municipio'     => trim($_GET['municipio'] ?? ''),
        'parroquia'     => trim($_GET['parroquia'] ?? ''),
        'clase'         => $_GET['clase'] ?? '',
        'tipo'          => $_GET['tipo'] ?? '',
        'estado'        => $_GET['estado'] ?? '',
        'naturaleza'    => $_GET['naturaleza'] ?? '',
        'situacion'     => $_GET['situacion'] ?? '',
    ];

    $where = [];
    $params = [];
    if ($f['q'] !== '') {
        // Busca también por propietario; por cédula/RIF solo si es administrador
        $campos_busqueda = ['t.codigo_catastral', 't.nombre', 't.zona_especifica', 't.puntos_referencia', 't.propietario_nombre', 't.doc_numero'];
        if (puedeVerDatosPrivados($user_rol)) $campos_busqueda[] = 't.propietario_documento';
        $where[] = '(' . implode(' OR ', array_map(fn($c) => "$c LIKE ?", $campos_busqueda)) . ')';
        $like = '%' . $f['q'] . '%';
        array_push($params, ...array_fill(0, count($campos_busqueda), $like));
    }
    if (isset(OPCIONES_TERRENO['naturaleza'][$f['naturaleza']])) {
        $where[] = "t.naturaleza = ?";
        $params[] = $f['naturaleza'];
    }
    if (isset(OPCIONES_TERRENO['situacion_legal'][$f['situacion']])) {
        $where[] = "t.situacion_legal = ?";
        $params[] = $f['situacion'];
    }
    foreach (['estado_region', 'municipio', 'parroquia'] as $campo) {
        if ($f[$campo] !== '') {
            $where[] = "t.$campo = ?";
            $params[] = $f[$campo];
        }
    }
    if (isset($CLASES[$f['clase']])) {
        $where[] = "t.clase_predio = ?";
        $params[] = $f['clase'];
    }
    if (isset($TIPOS[$f['tipo']])) {
        $where[] = "t.tipo = ?";
        $params[] = $f['tipo'];
    }
    if (isset($ESTADOS[$f['estado']])) {
        $where[] = "t.estado = ?";
        $params[] = $f['estado'];
    }

    $sql = "SELECT t.id, t.codigo_catastral, t.clase_predio, t.nombre, t.tipo, t.estado, t.area_m2, t.latitud, t.longitud,
                   t.estado_region, t.municipio, t.parroquia, t.zona_especifica, t.naturaleza, t.propietario_nombre, t.poligono_geojson,
                   COUNT(c.id) AS n_construcciones, COALESCE(SUM(c.area_construida), 0) AS area_construida
            FROM terrenos t
            LEFT JOIN construcciones c ON c.terreno_id = t.id"
        . ($where ? " WHERE " . implode(' AND ', $where) : "")
        . " GROUP BY t.id ORDER BY t.codigo_catastral LIMIT 200";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $resultados = $stmt->fetchAll();

    // Opciones de los desplegables (valores que existen en la BD)
    $opciones = [];
    foreach (['estado_region', 'municipio', 'parroquia'] as $campo) {
        $opciones[$campo] = $pdo->query("SELECT DISTINCT $campo FROM terrenos WHERE $campo IS NOT NULL AND $campo <> '' ORDER BY $campo")->fetchAll(PDO::FETCH_COLUMN);
    }
    $hay_filtros = count(array_filter($f, fn($v) => $v !== '')) > 0;
}

$json = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= $ficha ? 'Ficha ' . h($ficha['codigo_catastral']) : 'Consulta Catastral' ?> - GeoTerrenos</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <link rel="stylesheet" href="assets/galeria.css" />
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
        }

        body {
            background: #eef2f6;
            color: #1e293b;
        }

        .barra {
            background: #002b66;
            color: #fff;
            padding: 12px 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            justify-content: space-between;
            align-items: center;
        }

        .barra h1 {
            font-size: 18px;
        }

        .barra nav {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
        }

        .barra a,
        .btn {
            padding: 7px 12px;
            border-radius: 5px;
            font-size: 13px;
            font-weight: bold;
            text-decoration: none;
            border: none;
            cursor: pointer;
            display: inline-block;
        }

        .barra a {
            color: #fff;
            background: rgba(255, 255, 255, 0.12);
        }

        .barra a:hover {
            background: rgba(255, 255, 255, 0.25);
        }

        .contenedor {
            max-width: 1200px;
            margin: 0 auto;
            padding: 20px 16px 40px;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        .intro {
            background: #23607a;
            color: #fff;
            border-radius: 14px;
            padding: 18px 22px;
            display: flex;
            gap: 18px;
            align-items: center;
        }

        .intro .icono {
            font-size: 38px;
            flex: none;
        }

        .intro h2 {
            font-size: 20px;
            color: #dbeafe;
        }

        .intro p {
            font-size: 14px;
            line-height: 1.5;
            margin-top: 4px;
        }

        .tarjeta {
            background: #fff;
            border: 1px solid #cbd5e1;
            border-radius: 10px;
            padding: 16px;
        }

        .tarjeta h3 {
            font-size: 15px;
            color: #002b66;
            margin-bottom: 10px;
        }

        /* Buscador */
        .filtros {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 10px;
            align-items: end;
        }

        .filtros .ancho {
            grid-column: span 2;
        }

        label {
            display: block;
            font-size: 11px;
            font-weight: bold;
            color: #475569;
            margin-bottom: 3px;
        }

        input,
        select {
            width: 100%;
            padding: 7px 9px;
            border: 1px solid #cbd5e1;
            border-radius: 5px;
            font-size: 13px;
            background: #fff;
        }

        .acciones {
            display: flex;
            gap: 6px;
        }

        .btn-azul {
            background: #2563eb;
            color: #fff;
        }

        .btn-verde {
            background: #16a34a;
            color: #fff;
        }

        .btn-gris {
            background: #64748b;
            color: #fff;
        }

        .btn-teal {
            background: #23607a;
            color: #fff;
        }

        .btn-rojo {
            background: #dc2626;
            color: #fff;
            padding: 4px 8px;
            font-size: 11px;
        }

        /* Resultados */
        .resultados {
            display: grid;
            grid-template-columns: minmax(0, 3fr) minmax(0, 2fr);
            gap: 16px;
        }

        #mapa {
            height: 460px;
            border-radius: 8px;
        }

        #mapaFicha {
            height: 300px;
            border-radius: 8px;
        }

        .tabla-scroll {
            overflow-x: auto;
            max-height: 460px;
            overflow-y: auto;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        th,
        td {
            text-align: left;
            padding: 8px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: top;
        }

        th {
            background: #f1f5f9;
            color: #475569;
            font-size: 12px;
            position: sticky;
            top: 0;
        }

        .num {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        tr.fila:hover {
            background: #e0f2fe;
            cursor: pointer;
        }

        .codigo {
            font-family: Consolas, monospace;
            font-weight: bold;
            color: #002b66;
            white-space: nowrap;
        }

        .etiqueta {
            display: inline-block;
            padding: 2px 8px;
            border-radius: 10px;
            font-size: 11px;
            font-weight: bold;
            white-space: nowrap;
        }

        .et-urbano {
            background: #dbeafe;
            color: #1e40af;
        }

        .et-rural {
            background: #dcfce7;
            color: #166534;
        }

        .et-habitable {
            background: #dcfce7;
            color: #166534;
        }

        .et-no_habitable {
            background: #e5e7eb;
            color: #374151;
        }

        .et-en_riesgo {
            background: #fee2e2;
            color: #991b1b;
        }

        .vacio {
            padding: 30px;
            text-align: center;
            color: #64748b;
            font-size: 13px;
        }

        /* Ficha */
        .ficha-cabecera {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: space-between;
            align-items: flex-start;
        }

        .ficha-cabecera .codigo-grande {
            font-family: Consolas, monospace;
            font-size: 26px;
            font-weight: bold;
            color: #002b66;
        }

        .ficha-cabecera .nombre {
            font-size: 16px;
            margin-top: 2px;
        }

        .rejilla-2 {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
            gap: 16px;
        }

        .datos th {
            width: 42%;
            background: #f8fafc;
            position: static;
        }

        .resumen {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
            gap: 10px;
            margin-bottom: 12px;
        }

        .resumen div {
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 10px;
        }

        .resumen small {
            display: block;
            font-size: 11px;
            color: #64748b;
        }

        .resumen b {
            font-size: 20px;
        }

        .foto {
            width: 100%;
            max-height: 300px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #cbd5e1;
        }

        .form-construccion {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
            gap: 8px;
            align-items: end;
            margin-top: 12px;
            padding-top: 12px;
            border-top: 1px dashed #cbd5e1;
        }

        .historial li {
            font-size: 12px;
            padding: 6px 0;
            border-bottom: 1px solid #f1f5f9;
            list-style: none;
        }

        .historial small {
            color: #64748b;
        }

        .solo-impresion {
            display: none;
        }

        .servicios {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 8px;
        }

        .servicio {
            display: flex;
            flex-direction: column;
            gap: 2px;
            padding: 10px;
            border-radius: 8px;
            border: 1px solid #e2e8f0;
            font-size: 12px;
        }

        .servicio .ico {
            font-size: 20px;
        }

        .servicio.si {
            background: #f0fdf4;
            border-color: #bbf7d0;
        }

        .servicio.si b {
            color: #166534;
        }

        .servicio.no {
            background: #f8fafc;
            color: #64748b;
        }

        .servicio.no b {
            color: #94a3b8;
        }

        @media (max-width: 800px) {
            .resultados {
                grid-template-columns: 1fr;
            }

            .filtros .ancho {
                grid-column: auto;
            }
        }

        @media print {

            .barra,
            .no-print,
            .intro {
                display: none !important;
            }

            body {
                background: #fff;
            }

            .contenedor {
                padding: 0;
            }

            .tarjeta {
                border: 1px solid #94a3b8;
                break-inside: avoid;
            }

            .solo-impresion {
                display: block;
            }

            .tabla-scroll {
                max-height: none;
                overflow: visible;
            }
        }
    </style>
    <?php if (!$puede_imprimir): ?>
        <style>
            .aviso-sin-impresion { display: none; }
            @media print {
                body > *:not(.aviso-sin-impresion) { display: none !important; }
                .aviso-sin-impresion { display: block !important; font: 16px "Segoe UI", sans-serif; padding: 40px; text-align: center; }
            }
        </style>
    <?php endif; ?>
</head>

<body>
    <?php if (!$puede_imprimir): ?><div class="aviso-sin-impresion">🔒 Su rol (<?= h(nombreRol($user_rol)) ?>) no tiene permiso para imprimir fichas catastrales.</div><?php endif; ?>
    <div class="barra">
        <h1>🔎 Consulta Catastral · GeoTerrenos</h1>
        <nav>
            <?php if ($ficha): ?><a href="consulta_catastral.php">← Volver a la búsqueda</a><?php endif; ?>
            <a href="geoterreno.php">🗺 Mapa</a>
            <?php if (puede('ver_analisis')): ?><a href="analisis.php">📊 Análisis</a><?php endif; ?>
            <a href="logout.php">Cerrar sesión</a>
        </nav>
    </div>

    <div class="contenedor">

        <?php if (isset($_GET['id']) && !$ficha): ?>
            <div class="tarjeta vacio">El predio solicitado no existe o fue eliminado. <a href="consulta_catastral.php">Volver a la búsqueda</a></div>

        <?php elseif ($ficha): ?>
            <!-- ========================= FICHA CATASTRAL ========================= -->
            <div class="solo-impresion" style="border-bottom:3px solid #002b66; padding-bottom:6px;">
                <h2 style="color:#002b66;">GeoTerrenos · Ficha Catastral</h2>
                <small>Generada por <?= h($user_name) ?> (<?= h(nombreRol($user_rol)) ?>) el <?= date('d/m/Y H:i') ?></small>
            </div>

            <section class="tarjeta">
                <div class="ficha-cabecera">
                    <div>
                        <small style="color:#64748b;">Código catastral (Nº predial)</small>
                        <div class="codigo-grande"><?= h($ficha['codigo_catastral']) ?></div>
                        <div class="nombre"><b><?= h($ficha['nombre']) ?></b></div>
                        <div style="margin-top:6px; display:flex; gap:6px; flex-wrap:wrap;">
                            <span class="etiqueta et-<?= h($ficha['clase_predio']) ?>">Predio <?= h($CLASES[$ficha['clase_predio']] ?? $ficha['clase_predio']) ?></span>
                            <span class="etiqueta et-<?= h($ficha['estado']) ?>"><?= h($ESTADOS[$ficha['estado']] ?? $ficha['estado']) ?></span>
                            <span class="etiqueta" style="background:#f1f5f9; color:#334155;"><?= h($TIPOS[$ficha['tipo']] ?? $ficha['tipo']) ?></span>
                        </div>
                    </div>
                    <div class="acciones no-print">
                        <?php if ($puede_imprimir): ?><button class="btn btn-teal" onclick="window.print()">🖨 Imprimir ficha</button><?php endif; ?>
                        <a class="btn btn-azul" href="geoterreno.php?terreno=<?= intval($ficha['id']) ?>">🗺 Ver en el mapa</a>
                    </div>
                </div>
            </section>

            <div class="rejilla-2">
                <section class="tarjeta">
                    <h3>Ubicación</h3>
                    <table class="datos">
                        <tr>
                            <th>Estado</th>
                            <td><?= h($ficha['estado_region'] ?: '—') ?></td>
                        </tr>
                        <tr>
                            <th>Municipio</th>
                            <td><?= h($ficha['municipio'] ?: '—') ?></td>
                        </tr>
                        <tr>
                            <th>Parroquia</th>
                            <td><?= h($ficha['parroquia'] ?: '—') ?></td>
                        </tr>
                        <tr>
                            <th>Zona / Sector</th>
                            <td><?= h($ficha['zona_especifica'] ?: '—') ?></td>
                        </tr>
                        <tr>
                            <th>Puntos de referencia</th>
                            <td><?= h($ficha['puntos_referencia'] ?: '—') ?></td>
                        </tr>
                        <tr>
                            <th>Coordenadas</th>
                            <td><?= h($ficha['latitud']) ?>, <?= h($ficha['longitud']) ?><br><small style="color:#64748b;"><?= h($ficha['orientacion']) ?></small></td>
                        </tr>
                    </table>
                </section>

                <section class="tarjeta">
                    <h3>Localización</h3>
                    <div id="mapaFicha"></div>
                </section>
            </div>

            <div class="rejilla-2">
                <section class="tarjeta">
                    <h3>Características del terreno</h3>
                    <table class="datos">
                        <tr>
                            <th>Clase de predio</th>
                            <td><?= h($CLASES[$ficha['clase_predio']] ?? $ficha['clase_predio']) ?></td>
                        </tr>
                        <tr>
                            <th>Tipo de uso</th>
                            <td><?= h($TIPOS[$ficha['tipo']] ?? $ficha['tipo']) ?></td>
                        </tr>
                        <tr>
                            <th>Estado habitacional</th>
                            <td><?= h($ESTADOS[$ficha['estado']] ?? $ficha['estado']) ?></td>
                        </tr>
                        <tr>
                            <th>Uso actual</th>
                            <td><?= h(etiqueta('uso_actual', $ficha['uso_actual'])) ?></td>
                        </tr>
                        <tr>
                            <th>Zonificación</th>
                            <td><?= h($ficha['zonificacion'] ?: '—') ?></td>
                        </tr>
                        <tr>
                            <th>Área del terreno</th>
                            <td><?= $area_terreno > 0 ? num($area_terreno) . ' m² (' . num($area_terreno / 10000, 4) . ' ha)' : 'No calculada' ?></td>
                        </tr>
                        <tr>
                            <th>Perímetro</th>
                            <td><?= $ficha['perimetro_m'] > 0 ? num($ficha['perimetro_m']) . ' m' : '—' ?></td>
                        </tr>
                        <tr>
                            <th>Topografía</th>
                            <td><?= h(etiqueta('topografia', $ficha['topografia'])) ?></td>
                        </tr>
                        <tr>
                            <th>Vegetación</th>
                            <td><?= h(etiqueta('vegetacion', $ficha['vegetacion'])) ?></td>
                        </tr>
                        <tr>
                            <th>Acceso vial</th>
                            <td><?= h(etiqueta('acceso_vial', $ficha['acceso_vial'])) ?></td>
                        </tr>
                        <tr>
                            <th>Cuerpo de agua / lago</th>
                            <td><?= $ficha['tiene_lago'] ? 'Sí' : 'No' ?></td>
                        </tr>
                        <tr>
                            <th>Piscina</th>
                            <td><?= $ficha['tiene_piscina'] ? 'Sí' : 'No' ?></td>
                        </tr>
                        <tr>
                            <th>Registrado por</th>
                            <td><?= h($ficha['creador']) ?> · <?= $ficha['creado_en'] ? date('d/m/Y', strtotime($ficha['creado_en'])) : '' ?></td>
                        </tr>
                    </table>
                </section>

                <section class="tarjeta">
                    <h3>Foto de portada</h3>
                    <?php if (!empty($ficha['imagen'])): ?>
                        <img class="foto" id="fotoPortada" src="uploads/<?= h(basename($ficha['imagen'])) ?>" alt="Foto del predio">
                    <?php else: ?>
                        <div class="vacio" id="fotoPortadaVacia">Sin fotografía registrada.</div>
                    <?php endif; ?>
                    <p class="no-print" style="font-size:12px; color:#475569; margin-top:8px;">
                        📷 <?= $n_fotos ?> foto<?= $n_fotos == 1 ? '' : 's' ?> · 🎬 <?= $n_videos ?> video<?= $n_videos == 1 ? '' : 's' ?> ·
                        <a href="#galeria" style="color:#23607a; font-weight:bold;">Ver galería completa ↓</a>
                    </p>
                </section>
            </div>

            <!-- Propiedad y documento -->
            <div class="rejilla-2">
                <section class="tarjeta">
                    <h3>Propiedad y tenencia</h3>
                    <table class="datos">
                        <tr>
                            <th>Naturaleza</th>
                            <td><span class="etiqueta et-<?= $ficha['naturaleza'] === 'publico' ? 'urbano' : 'rural' ?>"><?= h(etiqueta('naturaleza', $ficha['naturaleza'])) ?></span></td>
                        </tr>
                        <?php if ($ficha['naturaleza'] === 'publico'): ?>
                            <tr>
                                <th>Nivel</th>
                                <td><?= h(etiqueta('nivel_publico', $ficha['nivel_publico'])) ?></td>
                            </tr>
                            <tr>
                                <th>Organismo responsable</th>
                                <td><?= h($ficha['organismo_responsable'] ?: '—') ?></td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <th>Régimen de tenencia</th>
                            <td><?= h(etiqueta('regimen_tenencia', $ficha['regimen_tenencia'])) ?></td>
                        </tr>
                        <tr>
                            <th>Propietario / Ocupante</th>
                            <td><?= h($ficha['propietario_nombre'] ?: '—') ?></td>
                        </tr>
                        <tr>
                            <th>Cédula / RIF</th>
                            <td><?= $ver_privado ? h($ficha['propietario_documento'] ?: '—') : ($ficha['propietario_documento'] ? '🔒 Reservado' : '—') ?></td>
                        </tr>
                        <tr>
                            <th>Teléfono</th>
                            <td><?= $ver_privado ? h($ficha['propietario_telefono'] ?: '—') : ($ficha['propietario_telefono'] ? '🔒 Reservado' : '—') ?></td>
                        </tr>
                    </table>
                </section>

                <section class="tarjeta">
                    <h3>Documento de propiedad</h3>
                    <table class="datos">
                        <tr>
                            <th>Nº de documento</th>
                            <td><?= h($ficha['doc_numero'] ?: '—') ?></td>
                        </tr>
                        <tr>
                            <th>Tomo / Folio / Protocolo</th>
                            <td><?= h(implode(' / ', [$ficha['doc_tomo'] ?: '—', $ficha['doc_folio'] ?: '—', $ficha['doc_protocolo'] ?: '—'])) ?></td>
                        </tr>
                        <tr>
                            <th>Fecha de registro</th>
                            <td><?= $ficha['doc_fecha'] ? date('d/m/Y', strtotime($ficha['doc_fecha'])) : '—' ?></td>
                        </tr>
                        <tr>
                            <th>Oficina de registro</th>
                            <td><?= h($ficha['doc_oficina'] ?: '—') ?></td>
                        </tr>
                        <tr>
                            <th>Documento escaneado</th>
                            <td>
                                <?php if (!$ficha['doc_archivo']): ?>—
                                <?php elseif ($ver_privado): ?><a class="no-print" href="documento.php?id=<?= intval($ficha['id']) ?>" target="_blank" style="color:#23607a; font-weight:bold;">📄 Ver documento</a><span class="solo-impresion">Cargado en el sistema</span>
                                <?php else: ?>🔒 Cargado (solo administradores)
                                <?php endif; ?>
                            </td>
                        </tr>
                        <tr>
                            <th>Situación legal</th>
                            <td><?php $sl = $ficha['situacion_legal']; ?>
                                <span class="etiqueta <?= $sl && $sl !== 'sin_problemas' ? 'et-en_riesgo' : 'et-habitable' ?>"><?= h(etiqueta('situacion_legal', $sl, 'Sin indicar')) ?></span>
                            </td>
                        </tr>
                        <tr>
                            <th>Impuesto inmobiliario</th>
                            <td><?= h(etiqueta('solvencia_inmobiliaria', $ficha['solvencia_inmobiliaria'])) ?></td>
                        </tr>
                        <tr>
                            <th>Valor catastral</th>
                            <td><?= $ficha['valor_catastral'] !== null ? h(etiqueta('valor_moneda', $ficha['valor_moneda'])) . ' ' . num($ficha['valor_catastral']) . ($ficha['valor_fecha'] ? ' <small style="color:#64748b;">(avalúo ' . date('d/m/Y', strtotime($ficha['valor_fecha'])) . ')</small>' : '') : '—' ?></td>
                        </tr>
                    </table>
                </section>
            </div>

            <!-- Linderos y servicios -->
            <div class="rejilla-2">
                <section class="tarjeta">
                    <h3>Linderos</h3>
                    <table>
                        <tr>
                            <th>Lado</th>
                            <th>Colinda con</th>
                            <th class="num">Medida</th>
                        </tr>
                        <?php foreach (LINDEROS_TERRENO as $lado => $texto): ?>
                            <tr>
                                <td><b><?= $texto ?></b></td>
                                <td><?= h($ficha["lindero_$lado"] ?: '—') ?></td>
                                <td class="num"><?= $ficha["lindero_{$lado}_m"] !== null ? num($ficha["lindero_{$lado}_m"]) . ' m' : '—' ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                    <?php if (!$ficha['poligono_geojson']): ?>
                        <p style="font-size:11px; color:#64748b; margin-top:8px;">El contorno del terreno no ha sido dibujado en el mapa.</p>
                    <?php endif; ?>
                </section>

                <section class="tarjeta">
                    <h3>Servicios públicos</h3>
                    <div class="servicios">
                        <?php foreach (SERVICIOS_TERRENO as $campo => [$ico, $texto]): ?>
                            <div class="servicio <?= $ficha[$campo] ? 'si' : 'no' ?>">
                                <span class="ico"><?= $ico ?></span>
                                <span><?= $texto ?></span>
                                <b><?= $ficha[$campo] ? '✔ Sí' : '✖ No' ?></b>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </section>
            </div>

            <!-- Riesgo y población -->
            <div class="rejilla-2">
                <section class="tarjeta">
                    <h3>Riesgos identificados</h3>
                    <?php if ($riesgos_ficha): ?>
                        <div style="display:flex; flex-wrap:wrap; gap:6px;">
                            <?php foreach ($riesgos_ficha as $r): ?>
                                <span class="etiqueta et-en_riesgo" style="font-size:12px; padding:4px 10px;"><?= RIESGOS_TERRENO[$r][0] ?? '' ?> <?= h(RIESGOS_TERRENO[$r][1] ?? $r) ?></span>
                            <?php endforeach; ?>
                        </div>
                    <?php else: ?>
                        <p style="font-size:13px; color:#166534;">✔ No se han identificado riesgos.</p>
                    <?php endif; ?>
                    <?php if ($ficha['riesgo_descripcion']): ?>
                        <p style="font-size:13px; margin-top:10px; background:#fef2f2; border-left:3px solid #ef4444; padding:8px 10px; border-radius:4px;"><?= nl2br(h($ficha['riesgo_descripcion'])) ?></p>
                    <?php endif; ?>
                </section>

                <section class="tarjeta">
                    <h3>Población</h3>
                    <div class="resumen">
                        <div><small>👥 Personas</small><b><?= intval($ficha['personas']) ?></b></div>
                        <div><small>🏠 Familias</small><b><?= intval($ficha['familias']) ?></b></div>
                        <div><small>🧒 Niños</small><b><?= intval($ficha['ninos']) ?></b></div>
                        <div><small>👴 Adultos mayores</small><b><?= intval($ficha['adultos_mayores']) ?></b></div>
                        <div><small>♿ Con discapacidad</small><b><?= intval($ficha['personas_discapacidad']) ?></b></div>
                    </div>
                    <?php if ($vulnerables > 0 && ($ficha['estado'] !== 'habitable' || $riesgos_ficha)): ?>
                        <p style="font-size:12px; color:#991b1b; background:#fef2f2; padding:8px 10px; border-radius:6px;">⚠ Hay <b><?= $vulnerables ?></b> persona(s) vulnerable(s) en un terreno con riesgo o no habitable.</p>
                    <?php endif; ?>
                </section>
            </div>

            <section class="tarjeta">
                <h3>Inspección y observaciones</h3>
                <table class="datos">
                    <tr>
                        <th style="width:25%;">Última inspección</th>
                        <td><?= $ficha['fecha_inspeccion'] ? date('d/m/Y', strtotime($ficha['fecha_inspeccion'])) : 'Sin inspección registrada' ?><?= $ficha['inspector'] ? ' · ' . h($ficha['inspector']) : '' ?></td>
                    </tr>
                    <tr>
                        <th>Observaciones</th>
                        <td><?= $ficha['observaciones'] ? nl2br(h($ficha['observaciones'])) : '—' ?></td>
                    </tr>
                </table>
            </section>

            <section class="tarjeta" id="galeria">
                <h3>Fotos y videos del terreno</h3>
                <div id="galeriaFicha"></div>
            </section>

            <section class="tarjeta" id="construcciones">
                <h3>Construcciones asociadas</h3>
                <div class="resumen">
                    <div><small>Construcciones</small><b><?= count($construcciones) ?></b></div>
                    <div><small>Área construida total</small><b><?= num($area_construida_total) ?></b> m²</div>
                    <div><small>Área del terreno</small><b><?= $area_terreno > 0 ? num($area_terreno) : '—' ?></b><?= $area_terreno > 0 ? ' m²' : '' ?></div>
                    <div title="Área construida total dividida entre el área del terreno"><small>Índice de construcción</small><b><?= $indice_construccion !== null ? num($indice_construccion) : '—' ?></b></div>
                </div>

                <?php if ($construcciones): ?>
                    <div class="tabla-scroll">
                        <table>
                            <tr>
                                <th>#</th>
                                <th>Tipo</th>
                                <th>Uso</th>
                                <th class="num">Pisos</th>
                                <th class="num">Área (m²)</th>
                                <th class="num">Año</th>
                                <th>Material</th>
                                <th>Conservación</th>
                                <?php if ($puede_editar): ?><th class="no-print"></th><?php endif; ?>
                            </tr>
                            <?php foreach ($construcciones as $i => $c): ?>
                                <tr>
                                    <td><?= $i + 1 ?></td>
                                    <td><b><?= h($c['tipo']) ?></b></td>
                                    <td><?= h($c['uso'] ?: '—') ?></td>
                                    <td class="num"><?= intval($c['pisos']) ?></td>
                                    <td class="num"><?= num($c['area_construida']) ?></td>
                                    <td class="num"><?= $c['anio_construccion'] ?: '—' ?></td>
                                    <td><?= h($c['material'] ?: '—') ?></td>
                                    <td><?= h($CONSERVACION[$c['estado_conservacion']] ?? $c['estado_conservacion']) ?></td>
                                    <?php if ($puede_editar): ?>
                                        <td class="no-print">
                                            <form method="POST" onsubmit="return confirm('¿Eliminar esta construcción?');">
                                                <input type="hidden" name="terreno_id" value="<?= intval($ficha['id']) ?>">
                                                <input type="hidden" name="construccion_id" value="<?= intval($c['id']) ?>">
                                                <button type="submit" name="eliminar_construccion" class="btn btn-rojo">Eliminar</button>
                                            </form>
                                        </td>
                                    <?php endif; ?>
                                </tr>
                            <?php endforeach; ?>
                        </table>
                    </div>
                <?php else: ?>
                    <div class="vacio">Este predio no tiene construcciones registradas.</div>
                <?php endif; ?>

                <?php if ($puede_editar): ?>
                    <form method="POST" class="form-construccion no-print">
                        <input type="hidden" name="terreno_id" value="<?= intval($ficha['id']) ?>">
                        <div>
                            <label>Tipo de construcción *</label>
                            <select name="tipo" required>
                                <?php foreach ($TIPOS_CONSTRUCCION as $tc): ?><option><?= h($tc) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Uso</label>
                            <input type="text" name="uso" maxlength="60" placeholder="Ej: Residencial">
                        </div>
                        <div>
                            <label>Pisos</label>
                            <input type="number" name="pisos" value="1" min="1" max="200">
                        </div>
                        <div>
                            <label>Área construida (m²) *</label>
                            <input type="number" name="area_construida" step="0.01" min="0" required>
                        </div>
                        <div>
                            <label>Año de construcción</label>
                            <input type="number" name="anio_construccion" min="1800" max="<?= date('Y') ?>" placeholder="Ej: 1998">
                        </div>
                        <div>
                            <label>Material</label>
                            <select name="material">
                                <option value="">—</option>
                                <?php foreach ($MATERIALES as $m): ?><option><?= h($m) ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <label>Conservación</label>
                            <select name="estado_conservacion">
                                <?php foreach ($CONSERVACION as $k => $lbl): ?><option value="<?= $k ?>"><?= $lbl ?></option><?php endforeach; ?>
                            </select>
                        </div>
                        <div>
                            <button type="submit" name="agregar_construccion" class="btn btn-verde" style="width:100%;">+ Agregar construcción</button>
                        </div>
                    </form>
                <?php endif; ?>
            </section>

            <?php if (!empty($historial)): ?>
                <section class="tarjeta no-print">
                    <h3>Últimos movimientos del predio</h3>
                    <ul class="historial">
                        <?php foreach ($historial as $hst): ?>
                            <li><b><?= h($hst['accion']) ?></b> · <?= h($hst['detalles']) ?><br><small><?= h($hst['usuario_nombre']) ?> · <?= h($hst['fecha_hora']) ?></small></li>
                        <?php endforeach; ?>
                    </ul>
                </section>
            <?php endif; ?>

        <?php else: ?>
            <!-- ========================= BÚSQUEDA ========================= -->
            <section class="intro">
                <div class="icono">🏛</div>
                <div>
                    <h2>Consulta Catastral</h2>
                    <p>Acceso a la información detallada de los predios rurales y urbanos: código catastral (número predial), estado, municipio y parroquia,
                        características del terreno y construcciones asociadas. Facilita los procesos relacionados con la propiedad inmobiliaria y la gestión del territorio.</p>
                </div>
            </section>

            <section class="tarjeta">
                <h3>Buscar predio</h3>
                <form method="GET" class="filtros">
                    <div class="ancho">
                        <label>Código catastral, nombre o sector</label>
                        <input type="text" name="q" value="<?= h($f['q']) ?>" placeholder="Ej: GT-000001, La Campiña..." autofocus>
                    </div>
                    <div>
                        <label>Estado</label>
                        <select name="estado_region">
                            <option value="">Todos</option>
                            <?php foreach ($opciones['estado_region'] as $o): ?><option <?= $o === $f['estado_region'] ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Municipio</label>
                        <select name="municipio">
                            <option value="">Todos</option>
                            <?php foreach ($opciones['municipio'] as $o): ?><option <?= $o === $f['municipio'] ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Parroquia</label>
                        <select name="parroquia">
                            <option value="">Todas</option>
                            <?php foreach ($opciones['parroquia'] as $o): ?><option <?= $o === $f['parroquia'] ? 'selected' : '' ?>><?= h($o) ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Clase de predio</label>
                        <select name="clase">
                            <option value="">Urbano y rural</option>
                            <?php foreach ($CLASES as $k => $lbl): ?><option value="<?= $k ?>" <?= $k === $f['clase'] ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Tipo de uso</label>
                        <select name="tipo">
                            <option value="">Todos</option>
                            <?php foreach ($TIPOS as $k => $lbl): ?><option value="<?= $k ?>" <?= $k === $f['tipo'] ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Estado habitacional</label>
                        <select name="estado">
                            <option value="">Todos</option>
                            <?php foreach ($ESTADOS as $k => $lbl): ?><option value="<?= $k ?>" <?= $k === $f['estado'] ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Naturaleza</label>
                        <select name="naturaleza">
                            <option value="">Públicos y privados</option>
                            <?php foreach (OPCIONES_TERRENO['naturaleza'] as $k => $lbl): ?><option value="<?= $k ?>" <?= $k === $f['naturaleza'] ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div>
                        <label>Situación legal</label>
                        <select name="situacion">
                            <option value="">Todas</option>
                            <?php foreach (OPCIONES_TERRENO['situacion_legal'] as $k => $lbl): ?><option value="<?= $k ?>" <?= $k === $f['situacion'] ? 'selected' : '' ?>><?= $lbl ?></option><?php endforeach; ?>
                        </select>
                    </div>
                    <div class="acciones">
                        <button type="submit" class="btn btn-azul" style="flex:1;">Buscar</button>
                        <?php if ($hay_filtros): ?><a href="consulta_catastral.php" class="btn btn-gris">Limpiar</a><?php endif; ?>
                    </div>
                </form>
            </section>

            <section class="tarjeta">
                <h3><?= count($resultados) ?> predio<?= count($resultados) == 1 ? '' : 's' ?> encontrado<?= count($resultados) == 1 ? '' : 's' ?><?= count($resultados) >= 200 ? ' (se muestran los primeros 200; afine la búsqueda)' : '' ?></h3>
                <?php if ($resultados): ?>
                    <div class="resultados">
                        <div class="tabla-scroll">
                            <table>
                                <tr>
                                    <th>Código</th>
                                    <th>Predio</th>
                                    <th>Ubicación</th>
                                    <th class="num">Área (m²)</th>
                                    <th class="num">Constr.</th>
                                </tr>
                                <?php foreach ($resultados as $r): ?>
                                    <tr class="fila" onclick="location.href='consulta_catastral.php?id=<?= intval($r['id']) ?>'" onmouseenter="resaltar(<?= intval($r['id']) ?>)">
                                        <td class="codigo"><?= h($r['codigo_catastral']) ?></td>
                                        <td>
                                            <b><?= h($r['nombre']) ?></b><br>
                                            <?php if ($r['propietario_nombre']): ?><small style="color:#475569;">👤 <?= h($r['propietario_nombre']) ?></small><br><?php endif; ?>
                                            <span class="etiqueta" style="background:#f1f5f9; color:#334155;"><?= h(etiqueta('naturaleza', $r['naturaleza'])) ?></span>
                                            <span class="etiqueta et-<?= h($r['clase_predio']) ?>"><?= h($CLASES[$r['clase_predio']] ?? '') ?></span>
                                            <span class="etiqueta et-<?= h($r['estado']) ?>"><?= h($ESTADOS[$r['estado']] ?? '') ?></span>
                                        </td>
                                        <td><?= h(implode(', ', array_filter([$r['parroquia'], $r['municipio'], $r['estado_region']]))) ?: '—' ?><br><small style="color:#64748b;"><?= h($r['zona_especifica']) ?></small></td>
                                        <td class="num"><?= $r['area_m2'] > 0 ? num($r['area_m2']) : '—' ?></td>
                                        <td class="num"><?= intval($r['n_construcciones']) ?><?php if ($r['area_construida'] > 0): ?><br><small style="color:#64748b;"><?= num($r['area_construida']) ?> m²</small><?php endif; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </table>
                        </div>
                        <div id="mapa"></div>
                    </div>
                <?php else: ?>
                    <div class="vacio">No se encontraron predios con esos criterios.</div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="assets/galeria.js"></script>
    <script>
        var COLORES_ESTADO = {
            habitable: '#16a34a',
            no_habitable: '#7f8c8d',
            en_riesgo: '#e74c3c'
        };

        function esc(v) {
            return String(v ?? '').replace(/[&<>"']/g, function(c) {
                return {
                    '&': '&amp;',
                    '<': '&lt;',
                    '>': '&gt;',
                    '"': '&quot;',
                    "'": '&#39;'
                } [c];
            });
        }

        function icono(estado, grande) {
            var s = grande ? 20 : 14;
            return L.divIcon({
                className: '',
                iconSize: [s, s],
                html: `<div style="background:${COLORES_ESTADO[estado] || '#3498db'}; width:${s}px; height:${s}px; border-radius:50%; border:2px solid white; box-shadow:0 0 4px rgba(0,0,0,0.5);"></div>`
            });
        }

        function capaBase(m) {
            L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png', {
                attribution: '© OpenStreetMap contributors'
            }).addTo(m);
        }

        <?php if ($ficha): ?>
            var predio = <?= json_encode(['lat' => (float)$ficha['latitud'], 'lng' => (float)$ficha['longitud'], 'estado' => $ficha['estado'], 'poligono' => $ficha['poligono_geojson']], $json) ?>;
            var mapaFicha = L.map('mapaFicha').setView([predio.lat, predio.lng], 17);
            capaBase(mapaFicha);
            L.marker([predio.lat, predio.lng], {
                icon: icono(predio.estado, true)
            }).addTo(mapaFicha);

            // Contorno del terreno
            if (predio.poligono) {
                try {
                    var contorno = L.geoJSON(JSON.parse(predio.poligono), {
                        style: {
                            color: COLORES_ESTADO[predio.estado] || '#2563eb',
                            weight: 3,
                            fillOpacity: 0.2
                        }
                    }).addTo(mapaFicha);
                    mapaFicha.fitBounds(contorno.getBounds(), {
                        padding: [20, 20],
                        maxZoom: 19
                    });
                } catch (e) {}
            }

            // "Imprimir Ficha" desde el mapa: abre la ficha e imprime al cargar
            if (<?= json_encode($puede_imprimir) ?> && new URLSearchParams(location.search).get('imprimir') === '1') {
                window.addEventListener('load', function() {
                    setTimeout(function() {
                        window.print();
                    }, 1200);
                });
            }

            Galeria.montar(document.getElementById('galeriaFicha'), {
                terrenoId: <?= (int)$ficha['id'] ?>,
                medios: <?= json_encode($galeria, $json) ?>,
                portada: <?= json_encode($ficha['imagen'], $json) ?>,
                puedeEliminar: <?= json_encode($puede_editar) ?>,
                limiteBytes: <?= limiteArchivoBytes() ?>,
                onCambio: function(medios, portada) {
                    // Actualiza la foto de portada de la ficha sin recargar
                    var img = document.getElementById('fotoPortada');
                    if (img && portada) img.src = 'uploads/' + encodeURIComponent(portada);
                    if ((!img && portada) || (img && !portada)) location.reload();
                }
            });
        <?php elseif (!empty($resultados)): ?>
            var predios = <?= json_encode(array_map(fn($r) => [
                                'id' => (int)$r['id'],
                                'codigo' => $r['codigo_catastral'],
                                'nombre' => $r['nombre'],
                                'estado' => $r['estado'],
                                'lat' => (float)$r['latitud'],
                                'lng' => (float)$r['longitud'],
                                'poligono' => $r['poligono_geojson'],
                            ], $resultados), $json) ?>;

            var mapa = L.map('mapa');
            capaBase(mapa);
            var marcadores = {};
            var grupo = L.featureGroup().addTo(mapa);

            predios.forEach(function(p) {
                var m = L.marker([p.lat, p.lng], {
                    icon: icono(p.estado)
                }).bindPopup(`<b>${esc(p.codigo)}</b><br>${esc(p.nombre)}<br><a href="consulta_catastral.php?id=${p.id}">Ver ficha catastral →</a>`);
                grupo.addLayer(m);
                marcadores[p.id] = m;

                if (p.poligono) {
                    try {
                        grupo.addLayer(L.geoJSON(JSON.parse(p.poligono), {
                            style: {
                                color: COLORES_ESTADO[p.estado] || '#2563eb',
                                weight: 2,
                                fillOpacity: 0.18
                            }
                        }));
                    } catch (e) {}
                }
            });

            if (predios.length === 1) {
                mapa.setView([predios[0].lat, predios[0].lng], 16);
            } else {
                mapa.fitBounds(grupo.getBounds(), {
                    padding: [30, 30],
                    maxZoom: 16
                });
            }

            function resaltar(id) {
                if (marcadores[id]) marcadores[id].openPopup();
            }
        <?php endif; ?>
    </script>
</body>

</html>
