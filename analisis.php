<?php
session_start();
require 'db.php';
require 'campos_terreno.php';

// Control de Acceso
if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit;
}

$user_name = $_SESSION['usuario_nombre'];
$user_rol  = $_SESSION['usuario_rol'];

exigirPermiso('ver_analisis');
$puede_imprimir = puede('imprimir_analisis');

// ---------------------------------------------------------------------
// PERÍODO DE ANÁLISIS (semanas)
// ---------------------------------------------------------------------
$opciones_semanas = [4, 8, 12, 26];
$semanas = intval($_GET['semanas'] ?? 8);
if (!in_array($semanas, $opciones_semanas, true)) {
    $semanas = 8;
}

// Lunes de la semana actual y comienzo del período
$lunes_actual   = new DateTime('monday this week');
$inicio_periodo = (clone $lunes_actual)->modify('-' . ($semanas - 1) . ' weeks');
$inicio_anterior = (clone $inicio_periodo)->modify("-$semanas weeks");

$ESTADOS = [
    'habitable'    => 'Habitable',
    'no_habitable' => 'No habitable',
    'en_riesgo'    => 'En riesgo',
];
$TIPOS = [
    'vivienda' => 'Vivienda',
    'montana'  => 'Montaña',
    'hogar'    => 'Hogar / Casa',
    'galpon'   => 'Galpón',
    'comercio' => 'Comercio',
];

// ---------------------------------------------------------------------
// DATOS
// ---------------------------------------------------------------------
$todos = $pdo->query("SELECT t.id, t.nombre, t.tipo, t.estado, t.personas, t.area_m2, t.municipio, t.estado_region,
                             t.zona_especifica, t.creado_en, u.nombre AS creador,
                             t.riesgos, t.naturaleza, t.situacion_legal, t.familias, t.ninos, t.adultos_mayores, t.personas_discapacidad,
                             t.latitud, t.longitud, t.poligono_geojson
                      FROM terrenos t JOIN usuarios u ON t.creado_por_id = u.id
                      ORDER BY t.creado_en DESC")->fetchAll();

$inicio_ts   = $inicio_periodo->getTimestamp();
$anterior_ts = $inicio_anterior->getTimestamp();

$periodo = [];
$total_anterior = 0;
foreach ($todos as $t) {
    $ts = strtotime($t['creado_en']);
    if ($ts >= $inicio_ts) {
        $periodo[] = $t;
    } elseif ($ts >= $anterior_ts) {
        $total_anterior++;
    }
}

// Conteo por estado (período e histórico)
function contarPorEstado(array $lista, array $estados)
{
    $c = array_fill_keys(array_keys($estados), 0);
    foreach ($lista as $t) {
        if (isset($c[$t['estado']])) $c[$t['estado']]++;
    }
    return $c;
}
$por_estado           = contarPorEstado($periodo, $ESTADOS);
$por_estado_historico = contarPorEstado($todos, $ESTADOS);
$total_periodo        = count($periodo);
$total_historico      = count($todos);

// Personas vulnerables de un terreno: niños, adultos mayores y personas con discapacidad
function vulnerables($t)
{
    return intval($t['ninos']) + intval($t['adultos_mayores']) + intval($t['personas_discapacidad']);
}

// Terrenos "en alerta": en riesgo, no habitables o con riesgos identificados, y con personas viviendo en ellos (todo el histórico)
$en_alerta = array_values(array_filter($todos, function ($t) {
    $con_riesgo = in_array($t['estado'], ['en_riesgo', 'no_habitable'], true) || !empty($t['riesgos']);
    return $con_riesgo && intval($t['personas']) > 0;
}));
usort($en_alerta, function ($a, $b) {
    // Primero los que están en riesgo, luego los que tienen más vulnerables, luego por número de personas
    $pa = $a['estado'] === 'en_riesgo' ? 1 : 0;
    $pb = $b['estado'] === 'en_riesgo' ? 1 : 0;
    return [$pb, vulnerables($b), intval($b['personas'])] <=> [$pa, vulnerables($a), intval($a['personas'])];
});
$personas_en_alerta    = array_sum(array_map(fn($t) => intval($t['personas']), $en_alerta));
$vulnerables_en_alerta = array_sum(array_map('vulnerables', $en_alerta));

// Registros por semana y estado
$semanas_labels = [];
$serie_semanal  = array_fill_keys(array_keys($ESTADOS), array_fill(0, $semanas, 0));
$meses = ['ene', 'feb', 'mar', 'abr', 'may', 'jun', 'jul', 'ago', 'sep', 'oct', 'nov', 'dic'];
for ($i = 0; $i < $semanas; $i++) {
    $d = (clone $inicio_periodo)->modify("+$i weeks");
    $semanas_labels[] = $d->format('j') . ' ' . $meses[intval($d->format('n')) - 1];
}
foreach ($periodo as $t) {
    $idx = intdiv(strtotime($t['creado_en']) - $inicio_ts, 7 * 86400);
    if ($idx >= 0 && $idx < $semanas && isset($serie_semanal[$t['estado']])) {
        $serie_semanal[$t['estado']][$idx]++;
    }
}

// Por municipio (período) apilado por estado, top 8 + "Otros"
$mun = [];
foreach ($periodo as $t) {
    $m = trim($t['municipio'] ?? '') !== '' ? trim($t['municipio']) : 'Sin municipio';
    if (!isset($mun[$m])) $mun[$m] = array_fill_keys(array_keys($ESTADOS), 0);
    if (isset($mun[$m][$t['estado']])) $mun[$m][$t['estado']]++;
}
uasort($mun, fn($a, $b) => array_sum($b) <=> array_sum($a));
if (count($mun) > 8) {
    $otros = array_fill_keys(array_keys($ESTADOS), 0);
    foreach (array_slice($mun, 7, null, true) as $vals) {
        foreach ($vals as $k => $v) $otros[$k] += $v;
    }
    $mun = array_slice($mun, 0, 7, true);
    $mun['Otros'] = $otros;
}

// Por tipo (período)
$por_tipo = array_fill_keys(array_keys($TIPOS), 0);
foreach ($periodo as $t) {
    if (isset($por_tipo[$t['tipo']])) $por_tipo[$t['tipo']]++;
}
arsort($por_tipo);

// Tipos de riesgo identificados (período)
$por_riesgo = array_fill_keys(array_keys(RIESGOS_TERRENO), 0);
foreach ($periodo as $t) {
    foreach (listaRiesgos($t['riesgos']) as $r) {
        if (isset($por_riesgo[$r])) $por_riesgo[$r]++;
    }
}
arsort($por_riesgo);
$hay_riesgos = array_sum($por_riesgo) > 0;

// Naturaleza y situación legal (período)
$por_naturaleza = array_fill_keys(array_keys(OPCIONES_TERRENO['naturaleza']), 0);
$por_situacion  = array_fill_keys(array_keys(OPCIONES_TERRENO['situacion_legal']), 0);
$sin_situacion  = 0;
foreach ($periodo as $t) {
    if (isset($por_naturaleza[$t['naturaleza']])) $por_naturaleza[$t['naturaleza']]++;
    if (isset($por_situacion[$t['situacion_legal']])) {
        $por_situacion[$t['situacion_legal']]++;
    } else {
        $sin_situacion++;
    }
}
$con_problema_legal = $total_periodo - $por_situacion['sin_problemas'] - $sin_situacion;

// ---------------------------------------------------------------------
// DIAGNÓSTICO AUTOMÁTICO
// ---------------------------------------------------------------------
function pct($parte, $total)
{
    return $total > 0 ? round($parte * 100 / $total, 1) : 0;
}

$pct_riesgo      = pct($por_estado['en_riesgo'], $total_periodo);
$pct_no_hab      = pct($por_estado['no_habitable'], $total_periodo);
$pct_habitable   = pct($por_estado['habitable'], $total_periodo);
$pct_no_sirven   = pct($por_estado['en_riesgo'] + $por_estado['no_habitable'], $total_periodo);

$hallazgos = [];

if ($total_periodo === 0) {
    $nivel = 'sin_datos';
} else {
    $n_alerta = count($en_alerta);
    if ($pct_no_sirven >= 40) {
        $nivel  = 'critico';
        $motivo = "El $pct_no_sirven% de los terrenos marcados en el período no sirve para habitar (40% o más).";
    } elseif ($personas_en_alerta >= 20) {
        $nivel  = 'critico';
        $motivo = "Hay $personas_en_alerta personas viviendo en terrenos en riesgo o no habitables (20 o más).";
    } elseif ($pct_no_sirven >= 20) {
        $nivel  = 'atencion';
        $motivo = "El $pct_no_sirven% de los terrenos marcados en el período no sirve para habitar (20% o más).";
    } elseif ($n_alerta > 0) {
        $nivel  = 'atencion';
        $motivo = "Hay $personas_en_alerta " . ($personas_en_alerta == 1 ? 'persona viviendo' : 'personas viviendo') . " en terrenos en riesgo o no habitables.";
    } else {
        $nivel  = 'normal';
        $motivo = "La mayoría de los terrenos marcados son habitables y no hay personas en zonas de alerta.";
    }

    $hallazgos[] = "Se " . ($total_periodo == 1 ? "registró <b>1</b> terreno" : "registraron <b>$total_periodo</b> terrenos") . " en las últimas <b>$semanas semanas</b>. "
        . "<b>$pct_habitable%</b> son habitables y <b>$pct_no_sirven%</b> no sirven para habitar "
        . "({$por_estado['en_riesgo']} en riesgo y {$por_estado['no_habitable']} no habitables).";

    if ($total_anterior > 0) {
        $var = round(($total_periodo - $total_anterior) * 100 / $total_anterior);
        $dir = $var > 0 ? "más" : "menos";
        $hallazgos[] = $var == 0
            ? "El ritmo de registros es igual al de las $semanas semanas anteriores ($total_anterior)."
            : "Se registraron <b>" . abs($var) . "% $dir</b> terrenos que en las $semanas semanas anteriores ($total_anterior).";
    } else {
        $hallazgos[] = "No hay registros en las $semanas semanas anteriores para comparar la tendencia.";
    }

    // Municipio con más terrenos en riesgo
    $peor_mun = null;
    foreach ($mun as $m => $vals) {
        if ($m !== 'Otros' && $vals['en_riesgo'] > 0 && ($peor_mun === null || $vals['en_riesgo'] > $mun[$peor_mun]['en_riesgo'])) {
            $peor_mun = $m;
        }
    }
    if ($peor_mun) {
        $hallazgos[] = "El municipio con más terrenos en riesgo es <b>" . htmlspecialchars($peor_mun) . "</b> "
            . "({$mun[$peor_mun]['en_riesgo']} en el período).";
    }

    // Tendencia de riesgo: últimas 2 semanas vs las 2 anteriores
    if ($semanas >= 4) {
        $r = $serie_semanal['en_riesgo'];
        $recientes = $r[$semanas - 1] + $r[$semanas - 2];
        $previas   = $r[$semanas - 3] + $r[$semanas - 4];
        if ($recientes > $previas) {
            $hallazgos[] = "Los terrenos en riesgo <b>están aumentando</b>: $recientes en las últimas 2 semanas frente a $previas en las 2 anteriores.";
        } elseif ($recientes < $previas) {
            $hallazgos[] = "Los terrenos en riesgo <b>están bajando</b>: $recientes en las últimas 2 semanas frente a $previas en las 2 anteriores.";
        }
    }
}

if (count($en_alerta) > 0) {
    $hallazgos[] = count($en_alerta) == 1
        ? "<b>1 terreno en alerta</b> (en riesgo o no habitable) tiene gente viviendo: <b>$personas_en_alerta personas</b>. Ver la lista al final."
        : "<b>" . count($en_alerta) . " terrenos en alerta</b> (en riesgo o no habitables) tienen gente viviendo: <b>$personas_en_alerta personas</b> en total. Ver la lista al final.";
    if ($vulnerables_en_alerta > 0) {
        $hallazgos[] = "Entre ellas hay <b>$vulnerables_en_alerta personas vulnerables</b> (niños, adultos mayores o con discapacidad) que deberían atenderse primero.";
    }
}
if ($total_periodo > 0 && $hay_riesgos) {
    $riesgo_top = array_key_first($por_riesgo);
    $hallazgos[] = "El riesgo más frecuente es <b>" . RIESGOS_TERRENO[$riesgo_top][1] . "</b> ({$por_riesgo[$riesgo_top]} terreno(s) en el período).";
}
if ($total_periodo > 0 && $con_problema_legal > 0) {
    $hallazgos[] = "<b>$con_problema_legal terreno(s)</b> del período tienen problemas legales (litigio, invasión, sucesión o documentos en trámite).";
}

$NIVELES = [
    'normal'    => ['icono' => '✔', 'titulo' => 'Situación normal'],
    'atencion'  => ['icono' => '!', 'titulo' => 'Requiere atención'],
    'critico'   => ['icono' => '✖', 'titulo' => 'Situación crítica'],
    'sin_datos' => ['icono' => '–', 'titulo' => 'Sin datos'],
];
if ($nivel === 'sin_datos') {
    $motivo = "No hay terrenos marcados en las últimas $semanas semanas. Pruebe con un período más largo.";
}

$json = JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT;

// Datos del tablero (terrenos del período, con su ubicación)
$tablero = array_map(fn($t) => [
    'id'        => intval($t['id']),
    'nombre'    => $t['nombre'],
    'tipo'      => $t['tipo'],
    'estado'    => $t['estado'],
    'municipio' => trim($t['municipio'] ?? '') !== '' ? trim($t['municipio']) : 'Sin municipio',
    'personas'  => intval($t['personas']),
    'lat'       => floatval($t['latitud']),
    'lng'       => floatval($t['longitud']),
    'geo'       => $t['poligono_geojson'] ?: null,
], $periodo);
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Análisis de Terrenos</title>
    <link rel="stylesheet" href="https://unpkg.com/leaflet@1.9.4/dist/leaflet.css" />
    <style>
        :root {
            color-scheme: light;
            --page: #f9f9f7;
            --surface: #fcfcfb;
            --text-primary: #0b0b0b;
            --text-secondary: #52514e;
            --text-muted: #898781;
            --grid: #e1e0d9;
            --baseline: #c3c2b7;
            --border: rgba(11, 11, 11, 0.10);
            --brand: #002b66;
            /* Estados (paleta de estado: se acompañan siempre de etiqueta) */
            --st-habitable: #0ca30c;
            --st-no-habitable: #898781;
            --st-riesgo: #d03b3b;
            --st-warning: #fab219;
            --series-1: #2a78d6;
        }

        @media (prefers-color-scheme: dark) {
            :root:not([data-theme="light"]) {
                color-scheme: dark;
                --page: #0d0d0d;
                --surface: #1a1a19;
                --text-primary: #ffffff;
                --text-secondary: #c3c2b7;
                --text-muted: #898781;
                --grid: #2c2c2a;
                --baseline: #383835;
                --border: rgba(255, 255, 255, 0.10);
                --brand: #86b6ef;
                --st-no-habitable: #6f6e69;
                --series-1: #3987e5;
            }
        }

        :root[data-theme="dark"] {
            color-scheme: dark;
            --page: #0d0d0d;
            --surface: #1a1a19;
            --text-primary: #ffffff;
            --text-secondary: #c3c2b7;
            --text-muted: #898781;
            --grid: #2c2c2a;
            --baseline: #383835;
            --border: rgba(255, 255, 255, 0.10);
            --brand: #86b6ef;
            --st-no-habitable: #6f6e69;
            --series-1: #3987e5;
        }

        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            background: var(--page);
            color: var(--text-primary);
            padding: 24px 16px 40px;
        }

        .contenedor {
            max-width: 1180px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        header {
            display: flex;
            flex-wrap: wrap;
            gap: 12px;
            justify-content: space-between;
            align-items: flex-end;
        }

        header h1 {
            font-size: 22px;
            color: var(--brand);
        }

        header p {
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: 2px;
        }

        .controles {
            display: flex;
            flex-wrap: wrap;
            gap: 8px;
            align-items: center;
        }

        .segmentos {
            display: flex;
            border: 1px solid var(--border);
            border-radius: 6px;
            overflow: hidden;
            background: var(--surface);
        }

        .segmentos a {
            padding: 7px 12px;
            font-size: 13px;
            color: var(--text-secondary);
            text-decoration: none;
            border-right: 1px solid var(--border);
        }

        .segmentos a:last-child {
            border-right: none;
        }

        .segmentos a.activo {
            background: var(--brand);
            color: var(--surface);
            font-weight: 600;
        }

        .btn {
            padding: 7px 12px;
            font-size: 13px;
            border-radius: 6px;
            border: 1px solid var(--border);
            background: var(--surface);
            color: var(--text-primary);
            text-decoration: none;
            cursor: pointer;
            font-weight: 600;
        }

        .tarjeta {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 10px;
            padding: 16px;
        }

        .tarjeta h2 {
            font-size: 15px;
            margin-bottom: 2px;
        }

        .tarjeta .sub {
            font-size: 12px;
            color: var(--text-secondary);
            margin-bottom: 12px;
        }

        /* Diagnóstico */
        .diagnostico {
            display: flex;
            gap: 16px;
            align-items: flex-start;
            border-left: 6px solid var(--nivel);
        }

        .diagnostico .icono {
            flex: none;
            width: 44px;
            height: 44px;
            border-radius: 50%;
            background: var(--nivel);
            color: #fff;
            font-size: 22px;
            font-weight: 700;
            display: grid;
            place-items: center;
        }

        .diagnostico h2 {
            font-size: 18px;
        }

        .diagnostico ul {
            margin: 8px 0 0 18px;
            font-size: 14px;
            color: var(--text-secondary);
            line-height: 1.55;
        }

        .diagnostico ul b {
            color: var(--text-primary);
        }

        .nivel-normal {
            --nivel: var(--st-habitable);
        }

        .nivel-atencion {
            --nivel: var(--st-warning);
        }

        .nivel-critico {
            --nivel: var(--st-riesgo);
        }

        .nivel-sin_datos {
            --nivel: var(--st-no-habitable);
        }

        /* KPIs */
        .kpis {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(170px, 1fr));
            gap: 12px;
        }

        .kpi .etiqueta {
            font-size: 13px;
            color: var(--text-secondary);
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .kpi .valor {
            font-size: 32px;
            font-weight: 600;
            margin-top: 4px;
        }

        .kpi .detalle {
            font-size: 12px;
            color: var(--text-muted);
            margin-top: 2px;
        }

        .punto {
            width: 10px;
            height: 10px;
            border-radius: 50%;
            display: inline-block;
            flex: none;
        }

        .kpi.principal .valor {
            font-size: 48px;
            line-height: 1;
        }

        /* Gráficos */
        .rejilla {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(340px, 1fr));
            gap: 16px;
        }

        .leyenda {
            display: flex;
            flex-wrap: wrap;
            gap: 14px;
            font-size: 12px;
            color: var(--text-secondary);
            margin-bottom: 10px;
        }

        .leyenda span {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .lienzo {
            position: relative;
            height: 300px;
        }

        details {
            margin-top: 10px;
            font-size: 12px;
            color: var(--text-secondary);
        }

        summary {
            cursor: pointer;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
            margin-top: 8px;
        }

        th,
        td {
            text-align: left;
            padding: 7px 8px;
            border-bottom: 1px solid var(--grid);
        }

        th {
            color: var(--text-secondary);
            font-weight: 600;
            font-size: 12px;
        }

        td.num,
        th.num {
            text-align: right;
            font-variant-numeric: tabular-nums;
        }

        .tabla-scroll {
            overflow-x: auto;
        }

        .estado-tag {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            white-space: nowrap;
        }

        .vacio {
            padding: 30px;
            text-align: center;
            color: var(--text-muted);
            font-size: 13px;
        }

        .barras-simples {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .barra-fila {
            display: grid;
            grid-template-columns: 70px 1fr 36px;
            align-items: center;
            gap: 8px;
            font-size: 13px;
        }

        .barra-etq {
            color: var(--text-secondary);
        }

        .barra-pista {
            height: 18px;
            background: var(--grid);
            border-radius: 4px;
            overflow: hidden;
        }

        .barra-pista span {
            display: block;
            height: 100%;
            border-radius: 0 4px 4px 0;
        }

        .barra-val {
            text-align: right;
            font-weight: 600;
            font-variant-numeric: tabular-nums;
        }

        /* ================= Tablero (estilo panel ICDE) ================= */
        .tablero {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .tablero-titulo {
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            gap: 10px 14px;
            padding: 10px 16px;
        }

        .tablero-titulo h2 {
            font-size: 20px;
            font-weight: 400;
            color: var(--text-secondary);
            margin-right: auto;
        }

        .filtros {
            display: flex;
            flex-wrap: wrap;
            gap: 6px;
            align-items: center;
            font-size: 12px;
            color: var(--text-muted);
        }

        .chip {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 6px 4px 10px;
            border-radius: 999px;
            background: var(--grid);
            color: var(--text-primary);
            border: none;
            font: inherit;
            cursor: pointer;
        }

        .chip b {
            font-weight: 600;
        }

        .chip .x {
            width: 16px;
            height: 16px;
            border-radius: 50%;
            display: grid;
            place-items: center;
            background: var(--surface);
            color: var(--text-secondary);
            font-size: 11px;
        }

        .tablero-rejilla {
            display: grid;
            grid-template-columns: 250px minmax(0, 1.35fr) minmax(0, 1fr);
            grid-template-rows: 350px 280px;
            gap: 8px;
        }

        .panel {
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 6px;
            padding: 10px 12px;
            display: flex;
            flex-direction: column;
            min-height: 0;
            min-width: 0;
        }

        .panel-titulo {
            font-size: 14px;
            color: var(--text-secondary);
            margin-bottom: 6px;
            display: flex;
            justify-content: space-between;
            gap: 8px;
        }

        .panel-titulo small {
            color: var(--text-muted);
            font-size: 11px;
        }

        .panel-cuerpo {
            position: relative;
            flex: 1;
            min-height: 0;
        }

        .col-izq {
            grid-row: 1 / 3;
            display: flex;
            flex-direction: column;
            gap: 8px;
            min-height: 0;
        }

        .kpi-tablero {
            display: grid;
            grid-template-columns: 64px 1fr;
            gap: 8px;
        }

        .kpi-tablero .logo {
            display: grid;
            place-items: center;
            padding: 8px;
        }

        .kpi-tablero .logo svg {
            width: 40px;
            height: 40px;
            color: var(--brand);
        }

        .kpi-tablero .cifra {
            text-align: center;
            justify-content: center;
            padding: 10px 6px;
        }

        .kpi-tablero .cifra strong {
            font-size: 40px;
            font-weight: 600;
            line-height: 1;
            font-variant-numeric: tabular-nums;
        }

        .kpi-tablero .cifra span {
            font-size: 13px;
            color: var(--text-secondary);
            margin-top: 4px;
        }

        .lista-terrenos {
            flex: 1;
            overflow-y: auto;
            padding: 0;
            list-style: none;
        }

        .lista-terrenos li {
            border-bottom: 1px solid var(--grid);
        }

        .lista-terrenos button {
            width: 100%;
            display: grid;
            grid-template-columns: 26px 1fr;
            gap: 10px;
            align-items: center;
            padding: 10px 12px;
            text-align: left;
            background: none;
            border: none;
            font: inherit;
            color: inherit;
            cursor: pointer;
        }

        .lista-terrenos button:hover,
        .lista-terrenos button.activo {
            background: var(--grid);
        }

        .lista-terrenos svg {
            width: 26px;
            height: 22px;
        }

        .lista-terrenos .nom {
            font-size: 14px;
            color: var(--text-primary);
            display: block;
        }

        .lista-terrenos .meta {
            font-size: 11px;
            color: var(--text-muted);
            display: block;
            margin-top: 2px;
        }

        #mapaTablero {
            position: absolute;
            inset: 0;
            border-radius: 4px;
        }

        .panel-mapa {
            padding: 0;
            overflow: hidden;
        }

        .leyenda-mapa {
            position: absolute;
            left: 8px;
            bottom: 8px;
            z-index: 500;
            background: var(--surface);
            border: 1px solid var(--border);
            border-radius: 4px;
            padding: 6px 8px;
            font-size: 11px;
            color: var(--text-secondary);
            display: flex;
            flex-direction: column;
            gap: 3px;
        }

        .leyenda-mapa span,
        .leyenda-dona span {
            display: flex;
            align-items: center;
            gap: 6px;
        }

        .scroll-y {
            overflow-y: auto;
        }

        .dona-cuerpo {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            align-items: center;
            gap: 12px;
        }

        .dona-lienzo {
            position: relative;
            height: 100%;
            min-height: 0;
        }

        .dona-centro {
            position: absolute;
            inset: 0;
            display: grid;
            place-items: center;
            pointer-events: none;
            text-align: center;
            font-size: 11px;
            color: var(--text-muted);
        }

        .dona-centro b {
            display: block;
            font-size: 24px;
            color: var(--text-primary);
            font-weight: 600;
        }

        .leyenda-dona {
            display: flex;
            flex-direction: column;
            gap: 8px;
            font-size: 13px;
        }

        .leyenda-dona button {
            display: grid;
            grid-template-columns: 10px auto;
            gap: 2px 8px;
            align-items: center;
            text-align: left;
            background: none;
            border: none;
            font: inherit;
            color: var(--text-secondary);
            cursor: pointer;
            padding: 2px 4px;
            border-radius: 4px;
        }

        .leyenda-dona button small {
            grid-column: 2;
            color: var(--text-muted);
            font-size: 11px;
            font-variant-numeric: tabular-nums;
        }

        .leyenda-dona button.apagado {
            opacity: .4;
        }

        .popup-terreno {
            font-size: 12px;
            line-height: 1.5;
        }

        .popup-terreno b {
            font-size: 13px;
        }

        @media (max-width: 960px) {
            .tablero-rejilla {
                grid-template-columns: 1fr;
                grid-template-rows: none;
            }

            .col-izq {
                grid-row: auto;
            }

            .lista-terrenos {
                max-height: 280px;
            }

            .panel-mapa {
                height: 340px;
            }

            .tablero-rejilla>.panel {
                min-height: 280px;
            }
        }

        @media print {
            body {
                background: #fff;
                padding: 0;
            }

            .controles {
                display: none;
            }

            .tarjeta {
                break-inside: avoid;
            }

            details {
                display: none;
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
    <?php if (!$puede_imprimir): ?><div class="aviso-sin-impresion">🔒 Su rol (<?= htmlspecialchars(nombreRol($user_rol)) ?>) no tiene permiso para imprimir el análisis.</div><?php endif; ?>
    <div class="contenedor">

        <header>
            <div>
                <h1>Análisis de Terrenos</h1>
                <p>Últimas <?= $semanas ?> semanas (desde el <?= $inicio_periodo->format('d/m/Y') ?>) · Generado por <?= htmlspecialchars($user_name) ?> (<?= htmlspecialchars(nombreRol($user_rol)) ?>) el <?= date('d/m/Y H:i') ?></p>
            </div>
            <div class="controles">
                <div class="segmentos" role="group" aria-label="Período">
                    <?php foreach ($opciones_semanas as $op): ?>
                        <a href="?semanas=<?= $op ?>" class="<?= $op === $semanas ? 'activo' : '' ?>"><?= $op ?> semanas</a>
                    <?php endforeach; ?>
                </div>
                <?php if ($puede_imprimir): ?><button class="btn" onclick="window.print()">🖨 Imprimir</button><?php endif; ?>
                <a class="btn" href="geoterreno.php">← Volver al mapa</a>
            </div>
        </header>

        <!-- Tablero -->
        <section class="tablero" aria-label="Tablero de terrenos">
            <div class="panel tablero-titulo">
                <h2>Tablero de terrenos marcados</h2>
                <div class="filtros" id="filtros">
                    <span>Haga clic en una barra, la dona o un terreno para filtrar.</span>
                </div>
            </div>

            <div class="tablero-rejilla">
                <div class="col-izq">
                    <div class="kpi-tablero">
                        <div class="panel logo" aria-hidden="true">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linejoin="round">
                                <path d="M3 7l6-3 6 3 6-3v13l-6 3-6-3-6 3z" />
                                <path d="M9 4v13M15 7v13" />
                            </svg>
                        </div>
                        <div class="panel cifra">
                            <strong id="kpiTotal">0</strong>
                            <span id="kpiDetalle">Terrenos</span>
                        </div>
                    </div>
                    <div class="panel" style="flex:1; padding:0; min-height:0;">
                        <ul class="lista-terrenos" id="listaTerrenos" aria-label="Terrenos del período"></ul>
                    </div>
                </div>

                <div class="panel panel-mapa">
                    <div class="panel-cuerpo">
                        <div id="mapaTablero" role="region" aria-label="Mapa de terrenos"></div>
                        <div class="leyenda-mapa">
                            <?php foreach ($ESTADOS as $k => $lbl): ?>
                                <span><span class="punto" style="background:var(--st-<?= $k === 'habitable' ? 'habitable' : ($k === 'en_riesgo' ? 'riesgo' : 'no-habitable') ?>)"></span><?= $lbl ?></span>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-titulo">Terrenos por municipio <small id="subMun"></small></div>
                    <div class="panel-cuerpo scroll-y">
                        <div id="cajaMun" style="position:relative;"><canvas id="graficoMunicipios" role="img" aria-label="Terrenos por municipio"></canvas></div>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-titulo">Terrenos por estado</div>
                    <div class="panel-cuerpo dona-cuerpo">
                        <div class="dona-lienzo">
                            <canvas id="graficoEstado" role="img" aria-label="Terrenos por estado"></canvas>
                            <div class="dona-centro"><div><b id="donaTotal">0</b>terrenos</div></div>
                        </div>
                        <div class="leyenda-dona" id="leyendaDona"></div>
                    </div>
                </div>

                <div class="panel">
                    <div class="panel-titulo">Terrenos por tipo de uso</div>
                    <div class="panel-cuerpo"><canvas id="graficoTipos" role="img" aria-label="Terrenos por tipo de uso"></canvas></div>
                </div>
            </div>
        </section>

        <!-- Diagnóstico -->
        <section class="tarjeta diagnostico nivel-<?= $nivel ?>">
            <div class="icono" aria-hidden="true"><?= $NIVELES[$nivel]['icono'] ?></div>
            <div>
                <h2>Diagnóstico: <?= $NIVELES[$nivel]['titulo'] ?></h2>
                <p style="font-size:13px; color:var(--text-secondary); margin-top:2px;"><b>Motivo:</b> <?= $motivo ?></p>
                <?php if ($hallazgos): ?>
                    <ul>
                        <?php foreach ($hallazgos as $h): ?>
                            <li><?= $h ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        </section>

        <!-- Indicadores -->
        <section class="kpis">
            <div class="tarjeta kpi principal">
                <div class="etiqueta">Terrenos marcados</div>
                <div class="valor"><?= $total_periodo ?></div>
                <div class="detalle">en <?= $semanas ?> semanas · <?= $total_historico ?> en total</div>
            </div>
            <div class="tarjeta kpi">
                <div class="etiqueta"><span class="punto" style="background:var(--st-habitable)"></span>Sirven (habitables)</div>
                <div class="valor"><?= $por_estado['habitable'] ?></div>
                <div class="detalle"><?= $pct_habitable ?>% del período</div>
            </div>
            <div class="tarjeta kpi">
                <div class="etiqueta"><span class="punto" style="background:var(--st-no-habitable)"></span>No sirven (no habitables)</div>
                <div class="valor"><?= $por_estado['no_habitable'] ?></div>
                <div class="detalle"><?= $pct_no_hab ?>% del período</div>
            </div>
            <div class="tarjeta kpi">
                <div class="etiqueta"><span class="punto" style="background:var(--st-riesgo)"></span>En rojo (en riesgo)</div>
                <div class="valor"><?= $por_estado['en_riesgo'] ?></div>
                <div class="detalle"><?= $pct_riesgo ?>% del período</div>
            </div>
            <div class="tarjeta kpi">
                <div class="etiqueta"><span class="punto" style="background:var(--st-warning)"></span>En alerta (con personas)</div>
                <div class="valor"><?= count($en_alerta) ?></div>
                <div class="detalle"><?= $personas_en_alerta ?> personas afectadas</div>
            </div>
        </section>

        <!-- Gráfico semanal -->
        <section class="tarjeta">
            <h2>Terrenos marcados por semana</h2>
            <div class="sub">Cantidad de terrenos registrados cada semana, según su estado.</div>
            <div class="leyenda">
                <?php foreach ($ESTADOS as $k => $lbl): ?>
                    <span><span class="punto" style="background:var(--st-<?= $k === 'habitable' ? 'habitable' : ($k === 'en_riesgo' ? 'riesgo' : 'no-habitable') ?>)"></span><?= $lbl ?></span>
                <?php endforeach; ?>
            </div>
            <div class="lienzo"><canvas id="graficoSemanal" aria-label="Terrenos marcados por semana y estado" role="img"></canvas></div>
            <details>
                <summary>Ver datos en tabla</summary>
                <div class="tabla-scroll">
                    <table>
                        <tr>
                            <th>Semana del</th>
                            <?php foreach ($ESTADOS as $lbl): ?><th class="num"><?= $lbl ?></th><?php endforeach; ?>
                            <th class="num">Total</th>
                        </tr>
                        <?php foreach ($semanas_labels as $i => $lbl): ?>
                            <tr>
                                <td><?= $lbl ?></td>
                                <?php $tot = 0;
                                foreach ($ESTADOS as $k => $_): $tot += $serie_semanal[$k][$i]; ?>
                                    <td class="num"><?= $serie_semanal[$k][$i] ?></td>
                                <?php endforeach; ?>
                                <td class="num"><b><?= $tot ?></b></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            </details>
        </section>

        <div class="rejilla">
            <!-- Riesgos -->
            <section class="tarjeta">
                <h2>Tipos de riesgo identificados</h2>
                <div class="sub">Cuántos terrenos del período tienen cada riesgo (un terreno puede tener varios).</div>
                <?php if ($hay_riesgos): ?>
                    <?php $por_riesgo_con_datos = array_filter($por_riesgo); ?>
                    <div class="lienzo" style="height:<?= count($por_riesgo_con_datos) * 38 + 40 ?>px"><canvas id="graficoRiesgos" role="img" aria-label="Terrenos por tipo de riesgo"></canvas></div>
                <?php else: ?>
                    <div class="vacio">✔ No hay riesgos identificados en el período.</div>
                <?php endif; ?>
            </section>

            <!-- Naturaleza y situación legal -->
            <section class="tarjeta">
                <h2>Naturaleza y situación legal</h2>
                <div class="sub">Terrenos públicos y privados del período, y su situación legal.</div>
                <?php if ($total_periodo > 0): ?>
                    <div class="barras-simples">
                        <?php foreach ($por_naturaleza as $k => $n): ?>
                            <div class="barra-fila">
                                <span class="barra-etq"><?= OPCIONES_TERRENO['naturaleza'][$k] ?></span>
                                <span class="barra-pista"><span style="width:<?= pct($n, $total_periodo) ?>%; background:var(--series-1);"></span></span>
                                <span class="barra-val"><?= $n ?></span>
                            </div>
                        <?php endforeach; ?>
                    </div>
                    <table style="margin-top:12px;">
                        <tr>
                            <th>Situación legal</th>
                            <th class="num">Terrenos</th>
                        </tr>
                        <?php foreach ($por_situacion as $k => $n): if (!$n) continue; ?>
                            <tr>
                                <td><span class="estado-tag"><span class="punto" style="background:var(--st-<?= $k === 'sin_problemas' ? 'habitable' : 'riesgo' ?>)"></span><?= OPCIONES_TERRENO['situacion_legal'][$k] ?></span></td>
                                <td class="num"><?= $n ?></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if ($sin_situacion): ?>
                            <tr>
                                <td><span class="estado-tag"><span class="punto" style="background:var(--st-no-habitable)"></span>Sin indicar</span></td>
                                <td class="num"><?= $sin_situacion ?></td>
                            </tr>
                        <?php endif; ?>
                    </table>
                <?php else: ?>
                    <div class="vacio">Sin registros en el período.</div>
                <?php endif; ?>
            </section>
        </div>

        <!-- Lista de alerta -->
        <section class="tarjeta">
            <h2>Terrenos en alerta</h2>
            <div class="sub">Terrenos en riesgo, no habitables o con riesgos identificados donde vive gente (todo el registro, no solo el período). Ordenados por prioridad de atención.</div>
            <?php if ($en_alerta): ?>
                <div class="tabla-scroll">
                    <table>
                        <tr>
                            <th>Terreno</th>
                            <th>Estado</th>
                            <th class="num">Personas</th>
                            <th class="num">Familias</th>
                            <th class="num" title="Niños, adultos mayores y personas con discapacidad">Vulnerables</th>
                            <th>Riesgos</th>
                            <th>Ubicación</th>
                            <th>Registrado</th>
                        </tr>
                        <?php foreach ($en_alerta as $t): ?>
                            <tr>
                                <td><b><?= htmlspecialchars($t['nombre']) ?></b></td>
                                <td>
                                    <span class="estado-tag">
                                        <span class="punto" style="background:var(--st-<?= $t['estado'] === 'en_riesgo' ? 'riesgo' : 'no-habitable' ?>)"></span>
                                        <?= $ESTADOS[$t['estado']] ?>
                                    </span>
                                </td>
                                <td class="num"><?= intval($t['personas']) ?></td>
                                <td class="num"><?= intval($t['familias']) ?></td>
                                <td class="num"><?= vulnerables($t) ? '<b>' . vulnerables($t) . '</b>' : '0' ?></td>
                                <td><?= htmlspecialchars(implode(', ', array_map(fn($r) => RIESGOS_TERRENO[$r][1] ?? $r, listaRiesgos($t['riesgos'])))) ?: '—' ?></td>
                                <td><?= htmlspecialchars(implode(', ', array_filter([$t['municipio'], $t['estado_region']]))) ?><br><small style="color:var(--text-muted)"><?= htmlspecialchars($t['zona_especifica']) ?></small></td>
                                <td><?= date('d/m/Y', strtotime($t['creado_en'])) ?><br><small style="color:var(--text-muted)"><?= htmlspecialchars($t['creador']) ?></small></td>
                            </tr>
                        <?php endforeach; ?>
                    </table>
                </div>
            <?php else: ?>
                <div class="vacio">✔ No hay terrenos en alerta con personas viviendo en ellos.</div>
            <?php endif; ?>
        </section>
    </div>

    <script src="https://unpkg.com/leaflet@1.9.4/dist/leaflet.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
    <script>
        var ESTADOS = <?= json_encode($ESTADOS, $json) ?>;
        var semanasLabels = <?= json_encode($semanas_labels, $json) ?>;
        var serieSemanal = <?= json_encode($serie_semanal, $json) ?>;
        var TIPOS = <?= json_encode($TIPOS, $json) ?>;
        var terrenosTablero = <?= json_encode($tablero, $json) ?>;

        var css = getComputedStyle(document.documentElement);
        var v = function(n) {
            return css.getPropertyValue(n).trim();
        };
        var COLORES = {
            habitable: v('--st-habitable'),
            no_habitable: v('--st-no-habitable'),
            en_riesgo: v('--st-riesgo')
        };

        Chart.defaults.font.family = 'system-ui, -apple-system, "Segoe UI", sans-serif';
        Chart.defaults.font.size = 12;
        Chart.defaults.color = v('--text-muted');

        var ejes = function(horizontal) {
            var valor = {
                beginAtZero: true,
                ticks: {
                    precision: 0
                },
                grid: {
                    color: v('--grid')
                },
                border: {
                    display: false
                }
            };
            var cat = {
                grid: {
                    display: false
                },
                border: {
                    color: v('--baseline')
                },
                ticks: {
                    color: v('--text-secondary')
                }
            };
            return horizontal ? {
                x: valor,
                y: cat
            } : {
                x: cat,
                y: valor
            };
        };

        var tooltip = {
            backgroundColor: v('--surface'),
            titleColor: v('--text-primary'),
            bodyColor: v('--text-secondary'),
            borderColor: v('--baseline'),
            borderWidth: 1,
            padding: 10,
            boxPadding: 4,
            usePointStyle: true
        };

        // Conjuntos de datos apilados por estado (con separación de 2px del color de fondo)
        function datasetsApilados(obtenerValores, horizontal) {
            var claves = Object.keys(ESTADOS);
            return claves.map(function(k, i) {
                return {
                    label: ESTADOS[k],
                    data: obtenerValores(k),
                    backgroundColor: COLORES[k],
                    borderColor: v('--surface'),
                    borderWidth: horizontal ? {
                        right: 2
                    } : {
                        top: 2
                    },
                    borderSkipped: false,
                    borderRadius: 0,
                    maxBarThickness: 24,
                    pointStyle: 'rectRounded'
                };
            });
        }

        // 1. Semanal
        new Chart(document.getElementById('graficoSemanal'), {
            type: 'bar',
            data: {
                labels: semanasLabels,
                datasets: datasetsApilados(function(k) {
                    return serieSemanal[k];
                }, false)
            },
            options: {
                responsive: true,
                maintainAspectRatio: false,
                interaction: {
                    mode: 'index',
                    intersect: false
                },
                scales: Object.assign(ejes(false), {
                    x: Object.assign(ejes(false).x, {
                        stacked: true
                    }),
                    y: Object.assign(ejes(false).y, {
                        stacked: true
                    })
                }),
                plugins: {
                    legend: {
                        display: false
                    },
                    tooltip: Object.assign({}, tooltip, {
                        callbacks: {
                            title: function(items) {
                                return 'Semana del ' + items[0].label;
                            },
                            footer: function(items) {
                                var total = items.reduce(function(s, i) {
                                    return s + i.parsed.y;
                                }, 0);
                                return 'Total: ' + total;
                            }
                        },
                        footerColor: v('--text-primary')
                    })
                }
            }
        });

        // =====================================================================
        // 2. TABLERO: lista + mapa + municipios + estado + tipos, con filtros cruzados
        // =====================================================================
        (function() {
            var ETQ_COLOR = {
                habitable: '--st-habitable',
                no_habitable: '--st-no-habitable',
                en_riesgo: '--st-riesgo'
            };
            var CAMPOS = {
                municipio: 'Municipio',
                estado: 'Estado',
                tipo: 'Tipo'
            };
            var filtro = {
                municipio: null,
                estado: null,
                tipo: null
            };
            var seleccionado = null;

            // Terrenos que cumplen todos los filtros (salvo el del campo indicado)
            function filtrados(excepto) {
                return terrenosTablero.filter(function(t) {
                    return Object.keys(filtro).every(function(c) {
                        return c === excepto || filtro[c] === null || t[c] === filtro[c];
                    });
                });
            }

            function contar(lista, campo) {
                var c = {};
                lista.forEach(function(t) {
                    c[t[campo]] = (c[t[campo]] || 0) + 1;
                });
                return c;
            }

            // Color atenuado para las categorías no seleccionadas
            function tono(color, campo, valor) {
                return filtro[campo] === null || filtro[campo] === valor ? color : color + '40';
            }

            function alternar(campo, valor) {
                filtro[campo] = filtro[campo] === valor ? null : valor;
                seleccionado = null;
                render();
            }

            function etiqueta(campo, valor) {
                if (campo === 'estado') return ESTADOS[valor] || valor;
                if (campo === 'tipo') return TIPOS[valor] || valor;
                return valor;
            }

            // ---------- Mapa ----------
            var mapa = L.map('mapaTablero', {
                zoomControl: true
            }).setView([10.4806, -66.9036], 11);
            L.tileLayer('https://server.arcgisonline.com/ArcGIS/rest/services/World_Topo_Map/MapServer/tile/{z}/{y}/{x}', {
                maxZoom: 19,
                attribution: 'Tiles &copy; Esri'
            }).addTo(mapa);
            var capaTerrenos = L.featureGroup().addTo(mapa);
            var capasPorId = {};

            function popup(t) {
                var d = document.createElement('div');
                d.className = 'popup-terreno';
                var b = document.createElement('b');
                b.textContent = t.nombre;
                d.appendChild(b);
                [ESTADOS[t.estado], TIPOS[t.tipo] || t.tipo, t.municipio, t.personas + ' persona(s)'].forEach(function(linea) {
                    d.appendChild(document.createElement('br'));
                    d.appendChild(document.createTextNode(linea));
                });
                return d;
            }

            function dibujarMapa(lista) {
                capaTerrenos.clearLayers();
                capasPorId = {};
                lista.forEach(function(t) {
                    var color = v(ETQ_COLOR[t.estado] || '--st-no-habitable');
                    var capa = null;
                    if (t.geo) {
                        try {
                            capa = L.geoJSON(JSON.parse(t.geo), {
                                style: {
                                    color: color,
                                    weight: 2,
                                    dashArray: '5 4',
                                    fillColor: color,
                                    fillOpacity: 0.18
                                }
                            });
                        } catch (e) {
                            capa = null;
                        }
                    }
                    if (!capa && (t.lat || t.lng)) {
                        capa = L.circleMarker([t.lat, t.lng], {
                            radius: 7,
                            color: v('--surface'),
                            weight: 2,
                            fillColor: color,
                            fillOpacity: 1
                        });
                    }
                    if (!capa) return;
                    capa.bindPopup(popup(t));
                    capa.on('click', function() {
                        seleccionar(t.id, false);
                    });
                    capa.addTo(capaTerrenos);
                    capasPorId[t.id] = capa;
                });
                if (capaTerrenos.getLayers().length) {
                    mapa.fitBounds(capaTerrenos.getBounds(), {
                        padding: [24, 24],
                        maxZoom: 16
                    });
                }
            }

            function seleccionar(id, enfocar) {
                seleccionado = id;
                document.querySelectorAll('#listaTerrenos button').forEach(function(b) {
                    var activo = Number(b.dataset.id) === id;
                    b.classList.toggle('activo', activo);
                    if (activo && !enfocar) b.scrollIntoView({
                        block: 'nearest'
                    });
                });
                var capa = capasPorId[id];
                if (capa && enfocar) {
                    if (capa.getBounds) {
                        mapa.fitBounds(capa.getBounds(), {
                            maxZoom: 17
                        });
                    } else {
                        mapa.setView(capa.getLatLng(), 16);
                    }
                    capa.openPopup();
                }
            }

            // ---------- Lista ----------
            var ICONO = '<svg viewBox="0 0 26 22" aria-hidden="true"><path d="M3 5 L14 2 L23 8 L20 19 L6 17 Z" fill="none" stroke="currentColor" stroke-width="1.6" stroke-dasharray="3 2.5" stroke-linejoin="round"/></svg>';

            function dibujarLista(lista) {
                var ul = document.getElementById('listaTerrenos');
                ul.innerHTML = '';
                if (!lista.length) {
                    ul.innerHTML = '<li class="vacio">Sin terrenos para este filtro.</li>';
                    return;
                }
                lista.forEach(function(t) {
                    var li = document.createElement('li');
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.dataset.id = t.id;
                    b.style.color = v(ETQ_COLOR[t.estado] || '--st-no-habitable');
                    b.innerHTML = ICONO + '<span><span class="nom"></span><span class="meta"></span></span>';
                    b.querySelector('.nom').textContent = t.nombre;
                    b.querySelector('.meta').textContent = t.municipio + ' · ' + (TIPOS[t.tipo] || t.tipo) + ' · ' + ESTADOS[t.estado];
                    b.addEventListener('click', function() {
                        seleccionar(t.id, true);
                    });
                    li.appendChild(b);
                    ul.appendChild(li);
                });
            }

            // ---------- Gráficos ----------
            var graficoMun = new Chart(document.getElementById('graficoMunicipios'), {
                type: 'bar',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Terrenos',
                        data: [],
                        backgroundColor: [],
                        borderRadius: {
                            topRight: 3,
                            bottomRight: 3
                        },
                        borderSkipped: false,
                        maxBarThickness: 16
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: 250
                    },
                    scales: Object.assign(ejes(true), {
                        y: Object.assign(ejes(true).y, {
                            ticks: {
                                autoSkip: false,
                                color: v('--text-secondary')
                            }
                        })
                    }),
                    onClick: function(e, el) {
                        if (el.length) alternar('municipio', graficoMun.data.labels[el[0].index]);
                    },
                    onHover: function(e, el) {
                        e.native.target.style.cursor = el.length ? 'pointer' : 'default';
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: tooltip
                    }
                }
            });

            var claveEstados = Object.keys(ESTADOS);
            var graficoEstado = new Chart(document.getElementById('graficoEstado'), {
                type: 'doughnut',
                data: {
                    labels: claveEstados.map(function(k) {
                        return ESTADOS[k];
                    }),
                    datasets: [{
                        data: [],
                        backgroundColor: [],
                        borderColor: v('--surface'),
                        borderWidth: 2
                    }]
                },
                options: {
                    responsive: true,
                    maintainAspectRatio: false,
                    cutout: '64%',
                    animation: {
                        duration: 250
                    },
                    onClick: function(e, el) {
                        if (el.length) alternar('estado', claveEstados[el[0].index]);
                    },
                    onHover: function(e, el) {
                        e.native.target.style.cursor = el.length ? 'pointer' : 'default';
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: tooltip
                    }
                }
            });

            var claveTipos = Object.keys(TIPOS);
            var graficoTipos = new Chart(document.getElementById('graficoTipos'), {
                type: 'bar',
                data: {
                    labels: [],
                    datasets: [{
                        label: 'Terrenos',
                        data: [],
                        backgroundColor: [],
                        borderRadius: {
                            topRight: 3,
                            bottomRight: 3
                        },
                        borderSkipped: false,
                        maxBarThickness: 18
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    animation: {
                        duration: 250
                    },
                    scales: ejes(true),
                    onClick: function(e, el) {
                        if (el.length) alternar('tipo', graficoTipos.data.claves[el[0].index]);
                    },
                    onHover: function(e, el) {
                        e.native.target.style.cursor = el.length ? 'pointer' : 'default';
                    },
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: tooltip
                    }
                }
            });

            function actualizarMunicipios() {
                var c = contar(filtrados('municipio'), 'municipio');
                var nombres = Object.keys(c).sort(function(a, b) {
                    return c[b] - c[a] || a.localeCompare(b);
                });
                var color = v('--series-1');
                graficoMun.data.labels = nombres;
                graficoMun.data.datasets[0].data = nombres.map(function(n) {
                    return c[n];
                });
                graficoMun.data.datasets[0].backgroundColor = nombres.map(function(n) {
                    return tono(color, 'municipio', n);
                });
                // Una fila por municipio, todas con etiqueta visible (desplazable si son muchas)
                document.getElementById('cajaMun').style.height = Math.max(nombres.length * 26 + 30, 120) + 'px';
                document.getElementById('subMun').textContent = nombres.length + ' municipio(s)';
                graficoMun.resize();
                graficoMun.update();
            }

            function actualizarEstado() {
                var lista = filtrados('estado');
                var c = contar(lista, 'estado');
                var total = lista.length;
                var ds = graficoEstado.data.datasets[0];
                ds.data = claveEstados.map(function(k) {
                    return c[k] || 0;
                });
                ds.backgroundColor = claveEstados.map(function(k) {
                    return tono(v(ETQ_COLOR[k]), 'estado', k);
                });
                graficoEstado.update();
                document.getElementById('donaTotal').textContent = filtro.estado ? (c[filtro.estado] || 0) : total;

                var ley = document.getElementById('leyendaDona');
                ley.innerHTML = '';
                claveEstados.forEach(function(k) {
                    var n = c[k] || 0;
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = filtro.estado && filtro.estado !== k ? 'apagado' : '';
                    b.innerHTML = '<span class="punto"></span><span></span><small></small>';
                    b.querySelector('.punto').style.background = v(ETQ_COLOR[k]);
                    b.children[1].textContent = ESTADOS[k];
                    b.querySelector('small').textContent = n + ' · ' + (total ? Math.round(n * 1000 / total) / 10 : 0) + '%';
                    b.addEventListener('click', function() {
                        alternar('estado', k);
                    });
                    ley.appendChild(b);
                });
            }

            function actualizarTipos() {
                var c = contar(filtrados('tipo'), 'tipo');
                var claves = claveTipos.slice().sort(function(a, b) {
                    return (c[b] || 0) - (c[a] || 0);
                });
                var color = v('--series-1');
                graficoTipos.data.claves = claves;
                graficoTipos.data.labels = claves.map(function(k) {
                    return TIPOS[k];
                });
                graficoTipos.data.datasets[0].data = claves.map(function(k) {
                    return c[k] || 0;
                });
                graficoTipos.data.datasets[0].backgroundColor = claves.map(function(k) {
                    return tono(color, 'tipo', k);
                });
                graficoTipos.update();
            }

            // ---------- Chips de filtros activos ----------
            function dibujarFiltros() {
                var cont = document.getElementById('filtros');
                cont.innerHTML = '';
                var activos = Object.keys(filtro).filter(function(c) {
                    return filtro[c] !== null;
                });
                if (!activos.length) {
                    cont.innerHTML = '<span>Haga clic en una barra, la dona o un terreno para filtrar.</span>';
                    return;
                }
                activos.forEach(function(c) {
                    var b = document.createElement('button');
                    b.type = 'button';
                    b.className = 'chip';
                    b.title = 'Quitar filtro';
                    b.innerHTML = '<span>' + CAMPOS[c] + ': <b></b></span><span class="x">✕</span>';
                    b.querySelector('b').textContent = etiqueta(c, filtro[c]);
                    b.addEventListener('click', function() {
                        alternar(c, filtro[c]);
                    });
                    cont.appendChild(b);
                });
                if (activos.length > 1) {
                    var todo = document.createElement('button');
                    todo.type = 'button';
                    todo.className = 'btn';
                    todo.textContent = 'Limpiar filtros';
                    todo.addEventListener('click', function() {
                        filtro.municipio = filtro.estado = filtro.tipo = null;
                        render();
                    });
                    cont.appendChild(todo);
                }
            }

            function render() {
                var lista = filtrados(null);
                var personas = lista.reduce(function(s, t) {
                    return s + t.personas;
                }, 0);
                document.getElementById('kpiTotal').textContent = lista.length;
                document.getElementById('kpiDetalle').textContent = (lista.length === 1 ? 'Terreno' : 'Terrenos') + ' · ' + personas + ' personas';
                dibujarFiltros();
                dibujarLista(lista);
                dibujarMapa(lista);
                actualizarMunicipios();
                actualizarEstado();
                actualizarTipos();
            }

            render();
        })();

        // 3. Tipos de riesgo (una sola serie, color de estado "crítico")
        if (document.getElementById('graficoRiesgos')) {
            var riesgos = <?= json_encode(array_map(fn($k, $n) => [RIESGOS_TERRENO[$k][1], $n], array_keys(array_filter($por_riesgo)), array_values(array_filter($por_riesgo))), $json) ?>;
            new Chart(document.getElementById('graficoRiesgos'), {
                type: 'bar',
                data: {
                    labels: riesgos.map(function(r) {
                        return r[0];
                    }),
                    datasets: [{
                        label: 'Terrenos',
                        data: riesgos.map(function(r) {
                            return r[1];
                        }),
                        backgroundColor: v('--st-riesgo'),
                        borderRadius: {
                            topRight: 4,
                            bottomRight: 4
                        },
                        borderSkipped: false,
                        maxBarThickness: 24
                    }]
                },
                options: {
                    indexAxis: 'y',
                    responsive: true,
                    maintainAspectRatio: false,
                    scales: ejes(true),
                    plugins: {
                        legend: {
                            display: false
                        },
                        tooltip: tooltip
                    }
                }
            });
        }
    </script>
</body>

</html>
