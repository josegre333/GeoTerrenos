<?php
// Definición central de los datos completos de un terreno: opciones de cada lista,
// validación del formulario y manejo del documento de propiedad escaneado.
// La usan geoterreno.php, consulta_catastral.php y analisis.php.

require_once __DIR__ . '/permisos.php';

const OPCIONES_TERRENO = [
    'naturaleza' => ['privado' => 'Privado', 'publico' => 'Público'],
    'nivel_publico' => ['nacional' => 'Nacional', 'estadal' => 'Estadal', 'municipal' => 'Municipal', 'ejido' => 'Ejido municipal'],
    'regimen_tenencia' => [
        'propio'    => 'Propio (con documento)',
        'arrendado' => 'Arrendado',
        'comodato'  => 'Comodato / Préstamo',
        'sucesion'  => 'Sucesión (herencia)',
        'ejido'     => 'Ejido municipal',
        'baldio'    => 'Baldío',
        'ocupacion' => 'Ocupación sin documento',
        'otro'      => 'Otro',
    ],
    'uso_actual' => [
        'residencial'   => 'Residencial',
        'comercial'     => 'Comercial',
        'agricola'      => 'Agrícola / Pecuario',
        'industrial'    => 'Industrial',
        'institucional' => 'Institucional',
        'recreacional'  => 'Recreacional',
        'mixto'         => 'Mixto',
        'sin_uso'       => 'Sin uso',
    ],
    'topografia' => ['plano' => 'Plano', 'ondulado' => 'Ondulado', 'pendiente_moderada' => 'Pendiente moderada', 'pendiente_fuerte' => 'Pendiente fuerte'],
    'vegetacion' => ['ninguna' => 'Ninguna', 'baja' => 'Baja', 'media' => 'Media', 'densa' => 'Densa'],
    'acceso_vial' => ['asfaltada' => 'Vía asfaltada', 'tierra' => 'Vía de tierra', 'peatonal' => 'Solo peatonal', 'sin_acceso' => 'Sin acceso'],
    'valor_moneda' => ['USD' => 'US$', 'VES' => 'Bs.'],
    'solvencia_inmobiliaria' => ['solvente' => 'Solvente', 'no_solvente' => 'No solvente', 'desconocido' => 'Se desconoce'],
    'situacion_legal' => [
        'sin_problemas' => 'Sin problemas',
        'litigio'       => 'En litigio',
        'invadido'      => 'Invadido',
        'sucesion'      => 'En sucesión',
        'en_tramite'    => 'Documentos en trámite',
        'otro'          => 'Otro',
    ],
];

const RIESGOS_TERRENO = [
    'inundacion'        => ['🌊', 'Inundación'],
    'deslizamiento'     => ['⛰️', 'Deslizamiento'],
    'falla_geologica'   => ['💥', 'Falla geológica'],
    'cercania_rio'      => ['🏞️', 'Cerca de río / quebrada'],
    'lineas_electricas' => ['⚡', 'Líneas de alta tensión'],
    'contaminacion'     => ['☣️', 'Contaminación'],
    'otro'              => ['❗', 'Otro'],
];

const SERVICIOS_TERRENO = [
    'srv_electricidad' => ['⚡', 'Electricidad'],
    'srv_agua'         => ['🚰', 'Agua potable'],
    'srv_cloacas'      => ['🚽', 'Cloacas'],
    'srv_gas'          => ['🔥', 'Gas'],
    'srv_internet'     => ['📶', 'Internet / Teléfono'],
    'srv_aseo'         => ['🗑️', 'Aseo urbano'],
];

const LINDEROS_TERRENO = ['norte' => 'Norte', 'sur' => 'Sur', 'este' => 'Este', 'oeste' => 'Oeste'];

// Datos personales que solo ven los administradores
const CAMPOS_PRIVADOS = ['propietario_documento', 'propietario_telefono', 'doc_archivo'];

const DOCUMENTOS_DIR = __DIR__ . '/documentos/';

function puedeVerDatosPrivados($rol)
{
    return puede('ver_datos_privados', $rol);
}

// Quita los datos personales de un terreno si el usuario no puede verlos
function ocultarDatosPrivados(array $t, $rol)
{
    if (!puedeVerDatosPrivados($rol)) {
        foreach (CAMPOS_PRIVADOS as $c) {
            if (array_key_exists($c, $t)) $t[$c] = $t[$c] ? '(reservado)' : null;
        }
    }
    return $t;
}

// <option> de una lista para los <select> del formulario
function htmlOpciones($lista, $vacio = '— Seleccione —')
{
    $html = $vacio !== null ? '<option value="">' . htmlspecialchars($vacio) . '</option>' : '';
    foreach (OPCIONES_TERRENO[$lista] as $valor => $texto) {
        $html .= '<option value="' . htmlspecialchars($valor) . '">' . htmlspecialchars($texto) . '</option>';
    }
    return $html;
}

function etiqueta($lista, $valor, $vacio = '—')
{
    if ($valor === null || $valor === '') return $vacio;
    return OPCIONES_TERRENO[$lista][$valor] ?? $valor;
}

/**
 * Lee y valida del formulario todos los campos extendidos del terreno.
 * Devuelve [columna => valor] listo para guardar, o lanza InvalidArgumentException.
 */
function leerCamposExtendidos(array $post)
{
    $d = [];

    $texto = function ($campo, $max) use ($post) {
        $v = trim((string)($post[$campo] ?? ''));
        if (mb_strlen($v) > $max) throw new InvalidArgumentException("El campo '$campo' es demasiado largo (máximo $max caracteres).");
        return $v === '' ? null : $v;
    };
    $opcion = function ($campo, $obligatorio = false, $defecto = null) use ($post) {
        $v = $post[$campo] ?? '';
        if ($v === '' || $v === null) return $defecto;
        if (!isset(OPCIONES_TERRENO[$campo][$v])) throw new InvalidArgumentException("Valor no válido en '$campo'.");
        return $v;
    };
    $decimal = function ($campo, $max = 1e13) use ($post) {
        $v = trim((string)($post[$campo] ?? ''));
        if ($v === '') return null;
        $v = str_replace(',', '.', $v);
        if (!is_numeric($v) || $v < 0 || $v > $max) throw new InvalidArgumentException("Número no válido en '$campo'.");
        return round((float)$v, 2);
    };
    $entero = function ($campo) use ($post) {
        return max(0, min(1000000, intval($post[$campo] ?? 0)));
    };
    $fecha = function ($campo) use ($post) {
        $v = trim((string)($post[$campo] ?? ''));
        if ($v === '') return null;
        $f = DateTime::createFromFormat('Y-m-d', $v);
        if (!$f || $f->format('Y-m-d') !== $v) throw new InvalidArgumentException("Fecha no válida en '$campo'.");
        return $v;
    };

    // Propiedad y tenencia
    $d['naturaleza']            = $opcion('naturaleza', true, 'privado');
    $d['nivel_publico']         = $d['naturaleza'] === 'publico' ? $opcion('nivel_publico') : null;
    $d['organismo_responsable'] = $d['naturaleza'] === 'publico' ? $texto('organismo_responsable', 150) : null;
    $d['regimen_tenencia']      = $opcion('regimen_tenencia');
    $d['propietario_nombre']    = $texto('propietario_nombre', 150);
    $d['propietario_documento'] = $texto('propietario_documento', 30);
    $d['propietario_telefono']  = $texto('propietario_telefono', 30);
    if ($d['propietario_documento'] !== null) {
        $d['propietario_documento'] = strtoupper($d['propietario_documento']);
        if (!preg_match('/^[VEJGP]-?\d{5,10}(-?\d)?$/', $d['propietario_documento'])) {
            throw new InvalidArgumentException("La cédula o RIF debe tener el formato V-12345678, E-12345678 o J-12345678-9.");
        }
    }

    // Documento de propiedad
    $d['doc_numero']    = $texto('doc_numero', 40);
    $d['doc_tomo']      = $texto('doc_tomo', 20);
    $d['doc_folio']     = $texto('doc_folio', 20);
    $d['doc_protocolo'] = $texto('doc_protocolo', 40);
    $d['doc_fecha']     = $fecha('doc_fecha');
    $d['doc_oficina']   = $texto('doc_oficina', 150);

    // Linderos y forma
    $geo = trim((string)($post['poligono_geojson'] ?? ''));
    if ($geo !== '') {
        if (strlen($geo) > 500000) throw new InvalidArgumentException("El polígono dibujado es demasiado grande.");
        $obj = json_decode($geo, true);
        $tipo = $obj['type'] ?? '';
        $geom = $tipo === 'Feature' ? ($obj['geometry'] ?? []) : $obj;
        if (!in_array($geom['type'] ?? '', ['Polygon', 'MultiPolygon'], true) || empty($geom['coordinates'])) {
            throw new InvalidArgumentException("El polígono dibujado no es válido.");
        }
        $geo = json_encode($geom);
    }
    $d['poligono_geojson'] = $geo !== '' ? $geo : null;
    $d['perimetro_m']      = $decimal('perimetro_m');
    foreach (array_keys(LINDEROS_TERRENO) as $lado) {
        $d["lindero_$lado"]   = $texto("lindero_$lado", 255);
        $d["lindero_{$lado}_m"] = $decimal("lindero_{$lado}_m", 1e8);
    }

    // Uso y zonificación
    $d['uso_actual']   = $opcion('uso_actual');
    $d['zonificacion'] = $texto('zonificacion', 100);

    // Servicios
    foreach (array_keys(SERVICIOS_TERRENO) as $srv) {
        $d[$srv] = !empty($post[$srv]) ? 1 : 0;
    }

    // Condiciones físicas y riesgo
    $d['topografia']  = $opcion('topografia');
    $d['vegetacion']  = $opcion('vegetacion');
    $d['acceso_vial'] = $opcion('acceso_vial');
    $riesgos = array_values(array_intersect((array)($post['riesgos'] ?? []), array_keys(RIESGOS_TERRENO)));
    $d['riesgos']            = $riesgos ? implode(',', $riesgos) : null;
    $d['riesgo_descripcion'] = $texto('riesgo_descripcion', 2000);

    // Valor y situación legal
    $d['valor_catastral']        = $decimal('valor_catastral');
    $d['valor_moneda']           = $opcion('valor_moneda', true, 'USD');
    $d['valor_fecha']            = $fecha('valor_fecha');
    $d['solvencia_inmobiliaria'] = $opcion('solvencia_inmobiliaria');
    $d['situacion_legal']        = $opcion('situacion_legal');

    // Población
    $d['familias']              = $entero('familias');
    $d['ninos']                 = $entero('ninos');
    $d['adultos_mayores']       = $entero('adultos_mayores');
    $d['personas_discapacidad'] = $entero('personas_discapacidad');

    // Control
    $d['fecha_inspeccion'] = $fecha('fecha_inspeccion');
    $d['inspector']        = $texto('inspector', 100);
    $d['observaciones']    = $texto('observaciones', 5000);

    return $d;
}

/**
 * Guarda el documento de propiedad escaneado (PDF o imagen) en la carpeta protegida.
 * Devuelve el nombre del archivo, null si no se envió, o lanza InvalidArgumentException.
 */
function guardarDocumentoPropiedad($archivo)
{
    if (empty($archivo) || ($archivo['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
    if ($archivo['error'] !== UPLOAD_ERR_OK) {
        throw new InvalidArgumentException("No se pudo subir el documento de propiedad (puede que sea demasiado grande).");
    }

    $permitidos = ['pdf' => 'application/pdf', 'jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png'];
    $ext = strtolower(pathinfo($archivo['name'], PATHINFO_EXTENSION));
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
    if (!isset($permitidos[$ext]) || $permitidos[$ext] !== $mime) {
        throw new InvalidArgumentException("El documento de propiedad debe ser PDF, JPG o PNG.");
    }

    if (!is_dir(DOCUMENTOS_DIR)) mkdir(DOCUMENTOS_DIR, 0775, true);
    $nombre = 'doc_' . time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($archivo['tmp_name'], DOCUMENTOS_DIR . $nombre)) {
        throw new InvalidArgumentException("El servidor no pudo guardar el documento de propiedad.");
    }
    return $nombre;
}

function eliminarDocumentoPropiedad($nombre)
{
    if ($nombre && is_file(DOCUMENTOS_DIR . basename($nombre))) {
        unlink(DOCUMENTOS_DIR . basename($nombre));
    }
}

// Lista de riesgos guardada como "a,b,c" => ['a','b','c']
function listaRiesgos($valor)
{
    return $valor ? array_values(array_filter(explode(',', $valor))) : [];
}
