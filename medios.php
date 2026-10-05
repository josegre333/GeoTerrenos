<?php
// Funciones compartidas para la galería de fotos y videos de los terrenos.

// extensión => [tipo, tipos MIME aceptados]
const MEDIOS_PERMITIDOS = [
    'jpg'  => ['foto',  ['image/jpeg']],
    'jpeg' => ['foto',  ['image/jpeg']],
    'png'  => ['foto',  ['image/png']],
    'webp' => ['foto',  ['image/webp']],
    'mp4'  => ['video', ['video/mp4']],
    'm4v'  => ['video', ['video/mp4', 'video/x-m4v']],
    'webm' => ['video', ['video/webm']],
    'mov'  => ['video', ['video/quicktime', 'video/mp4']],
    '3gp'  => ['video', ['video/3gpp', 'video/mp4']],
];

const MEDIOS_DIR = __DIR__ . '/uploads/';

// Convierte "40M" de php.ini a bytes
function iniABytes($valor)
{
    $valor = trim((string)$valor);
    $num = (float)$valor;
    switch (strtoupper(substr($valor, -1))) {
        case 'G':
            $num *= 1024;
        case 'M':
            $num *= 1024;
        case 'K':
            $num *= 1024;
    }
    return (int)$num;
}

// Tamaño máximo de un archivo que acepta PHP
function limiteArchivoBytes()
{
    $limites = array_filter([iniABytes(ini_get('upload_max_filesize')), iniABytes(ini_get('post_max_size'))]);
    return $limites ? min($limites) : 0;
}

function formatoMB($bytes)
{
    return number_format($bytes / 1048576, 1, ',', '.') . ' MB';
}

// $_FILES['campo'] con varios archivos => lista de archivos individuales
function normalizarArchivos($campo)
{
    if (empty($campo) || !isset($campo['name'])) return [];
    if (!is_array($campo['name'])) return [$campo];

    $lista = [];
    foreach ($campo['name'] as $i => $nombre) {
        $lista[] = [
            'name'     => $nombre,
            'type'     => $campo['type'][$i],
            'tmp_name' => $campo['tmp_name'][$i],
            'error'    => $campo['error'][$i],
            'size'     => $campo['size'][$i],
        ];
    }
    return $lista;
}

/**
 * Valida y guarda un archivo subido en la galería del terreno.
 * Devuelve ['ok' => true, 'medio' => fila] o ['ok' => false, 'error' => mensaje].
 */
function guardarMedio(PDO $pdo, int $terreno_id, array $archivo, int $user_id, string $user_name, string $descripcion = '')
{
    $nombre_original = $archivo['name'] ?? 'archivo';

    if (($archivo['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        $errores = [
            UPLOAD_ERR_INI_SIZE   => 'supera el tamaño máximo permitido (' . formatoMB(limiteArchivoBytes()) . ')',
            UPLOAD_ERR_FORM_SIZE  => 'supera el tamaño máximo permitido',
            UPLOAD_ERR_PARTIAL    => 'se subió incompleto, inténtelo de nuevo',
            UPLOAD_ERR_NO_FILE    => 'no se recibió ningún archivo',
            UPLOAD_ERR_NO_TMP_DIR => 'falta la carpeta temporal del servidor',
            UPLOAD_ERR_CANT_WRITE => 'el servidor no pudo guardar el archivo',
        ];
        return ['ok' => false, 'error' => "$nombre_original: " . ($errores[$archivo['error']] ?? 'error desconocido al subir')];
    }

    $ext = strtolower(pathinfo($nombre_original, PATHINFO_EXTENSION));
    if (!isset(MEDIOS_PERMITIDOS[$ext])) {
        return ['ok' => false, 'error' => "$nombre_original: formato no permitido (use JPG, PNG, WEBP, MP4, MOV, WEBM o 3GP)"];
    }
    [$tipo, $mimes] = MEDIOS_PERMITIDOS[$ext];

    // Comprobar el contenido real del archivo, no solo su extensión
    $mime = (new finfo(FILEINFO_MIME_TYPE))->file($archivo['tmp_name']);
    if (!in_array($mime, $mimes, true)) {
        return ['ok' => false, 'error' => "$nombre_original: el contenido no corresponde a una " . ($tipo === 'foto' ? 'imagen' : 'video') . " válida"];
    }
    if ($tipo === 'foto' && @getimagesize($archivo['tmp_name']) === false) {
        return ['ok' => false, 'error' => "$nombre_original: la imagen está dañada"];
    }

    if (!is_dir(MEDIOS_DIR)) {
        mkdir(MEDIOS_DIR, 0775, true);
    }

    $nuevo_nombre = time() . '_' . bin2hex(random_bytes(6)) . '.' . $ext;
    if (!move_uploaded_file($archivo['tmp_name'], MEDIOS_DIR . $nuevo_nombre)) {
        return ['ok' => false, 'error' => "$nombre_original: el servidor no pudo guardar el archivo"];
    }

    $descripcion = mb_substr(trim($descripcion), 0, 255);
    $pdo->prepare("INSERT INTO terreno_medios (terreno_id, archivo, tipo, descripcion, tamano_bytes, subido_por_id, subido_por_nombre) VALUES (?, ?, ?, ?, ?, ?, ?)")
        ->execute([$terreno_id, $nuevo_nombre, $tipo, $descripcion !== '' ? $descripcion : null, (int)$archivo['size'], $user_id, $user_name]);
    $id = (int)$pdo->lastInsertId();

    // Si el terreno no tiene portada, la primera foto pasa a serlo
    if ($tipo === 'foto') {
        $pdo->prepare("UPDATE terrenos SET imagen = ? WHERE id = ? AND (imagen IS NULL OR imagen = '')")
            ->execute([$nuevo_nombre, $terreno_id]);
    }

    $stmt = $pdo->prepare("SELECT * FROM terreno_medios WHERE id = ?");
    $stmt->execute([$id]);
    return ['ok' => true, 'medio' => $stmt->fetch()];
}

/**
 * Elimina un medio (fila y archivo). Si era la portada, pone como portada la siguiente foto.
 */
function eliminarMedio(PDO $pdo, int $medio_id)
{
    $stmt = $pdo->prepare("SELECT m.*, t.imagen AS portada FROM terreno_medios m JOIN terrenos t ON t.id = m.terreno_id WHERE m.id = ?");
    $stmt->execute([$medio_id]);
    $m = $stmt->fetch();
    if (!$m) return null;

    $pdo->prepare("DELETE FROM terreno_medios WHERE id = ?")->execute([$medio_id]);
    $ruta = MEDIOS_DIR . basename($m['archivo']);
    if (is_file($ruta)) unlink($ruta);

    if ($m['portada'] === $m['archivo']) {
        $sig = $pdo->prepare("SELECT archivo FROM terreno_medios WHERE terreno_id = ? AND tipo = 'foto' ORDER BY id LIMIT 1");
        $sig->execute([$m['terreno_id']]);
        $pdo->prepare("UPDATE terrenos SET imagen = ? WHERE id = ?")->execute([$sig->fetchColumn() ?: null, $m['terreno_id']]);
    }
    return $m;
}

// Borra del disco todos los archivos de un terreno (antes de eliminarlo)
function eliminarArchivosDeTerreno(PDO $pdo, int $terreno_id)
{
    $stmt = $pdo->prepare("SELECT archivo FROM terreno_medios WHERE terreno_id = ?");
    $stmt->execute([$terreno_id]);
    foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $archivo) {
        $ruta = MEDIOS_DIR . basename($archivo);
        if (is_file($ruta)) unlink($ruta);
    }
}

// Medios de varios terrenos agrupados por terreno_id
function mediosPorTerreno(PDO $pdo, array $ids = null)
{
    $sql = "SELECT id, terreno_id, archivo, tipo, descripcion, tamano_bytes, subido_por_nombre, creado_en FROM terreno_medios";
    $params = [];
    if ($ids !== null) {
        if (!$ids) return [];
        $sql .= " WHERE terreno_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")";
        $params = array_values($ids);
    }
    $stmt = $pdo->prepare($sql . " ORDER BY id");
    $stmt->execute($params);

    $agrupados = [];
    foreach ($stmt as $m) {
        $agrupados[$m['terreno_id']][] = $m;
    }
    return $agrupados;
}

function registrarAuditoriaMedio(PDO $pdo, int $terreno_id, int $user_id, string $user_name, string $detalle)
{
    $pdo->prepare("INSERT INTO auditoria_terrenos (terreno_id, usuario_id, usuario_nombre, accion, detalles) VALUES (?, ?, ?, 'EDITAR', ?)")
        ->execute([$terreno_id, $user_id, $user_name, $detalle]);
}
