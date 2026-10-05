<?php
// ---------------------------------------------------------------------
// JERARQUÍA DE ROLES Y PERMISOS
// ---------------------------------------------------------------------
// Cada rol tiene un nivel. Un permiso se concede a todos los roles cuyo
// nivel sea igual o mayor al nivel mínimo indicado. Para cambiar quién
// puede hacer algo, basta con cambiar el número en PERMISOS.

const ROLES = [
    'user'        => ['nivel' => 1, 'nombre' => 'Usuario',       'descripcion' => 'Consulta'],
    'admin'       => ['nivel' => 2, 'nombre' => 'Administrador', 'descripcion' => 'Reportes e impresión'],
    'super_admin' => ['nivel' => 3, 'nombre' => 'Super Admin',   'descripcion' => 'Programador · control total'],
];

// acción => [nivel mínimo, descripción, grupo]
const PERMISOS = [
    'imprimir_ficha'     => [1, 'Imprimir la ficha catastral de un terreno (sin datos privados si no tiene permiso)', 'Impresión y reportes'],
    'reporte_general'    => [2, 'Generar el reporte general de terrenos', 'Impresión y reportes'],
    'ver_analisis'       => [2, 'Ver el análisis y diagnóstico', 'Impresión y reportes'],
    'imprimir_analisis'  => [2, 'Imprimir el análisis y diagnóstico', 'Impresión y reportes'],
    'ver_datos_privados' => [2, 'Ver datos del propietario y documentos de propiedad', 'Datos'],
    'editar_terrenos'    => [3, 'Registrar y modificar terrenos, construcciones y archivos', 'Datos'],
    'eliminar_terrenos'  => [3, 'Eliminar terrenos', 'Datos'],
    'gestionar_usuarios' => [3, 'Asignar roles a los usuarios', 'Administración'],
];

function nivelRol($rol)
{
    return ROLES[$rol]['nivel'] ?? 0;
}

function nombreRol($rol)
{
    return ROLES[$rol]['nombre'] ?? $rol;
}

// ¿El rol (por defecto, el del usuario conectado) tiene este permiso?
function puede($accion, $rol = null)
{
    $rol = $rol ?? ($_SESSION['usuario_rol'] ?? '');
    return isset(PERMISOS[$accion]) && nivelRol($rol) >= PERMISOS[$accion][0];
}

// Corta la petición con un 403 si el usuario no tiene el permiso
function exigirPermiso($accion)
{
    if (puede($accion)) return;
    http_response_code(403);
    $minimo = '';
    foreach (ROLES as $r) {
        if ($r['nivel'] === PERMISOS[$accion][0]) $minimo = $r['nombre'];
    }
    echo '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><title>Acceso restringido</title></head>'
        . '<body style="font-family:Segoe UI,sans-serif; background:#f1f5f9; display:grid; place-items:center; min-height:100vh; margin:0;">'
        . '<div style="background:#fff; border:1px solid #cbd5e1; border-radius:10px; padding:24px; max-width:460px;">'
        . '<h2 style="color:#002b66; margin:0 0 8px; font-size:18px;">🔒 Acceso restringido</h2>'
        . '<p style="color:#334155; font-size:14px;">Su rol (<b>' . htmlspecialchars(nombreRol($_SESSION['usuario_rol'] ?? '')) . '</b>) no permite: '
        . htmlspecialchars(lcfirst(PERMISOS[$accion][1])) . '. Se requiere <b>' . htmlspecialchars($minimo) . '</b> o superior.</p>'
        . '<a href="geoterreno.php" style="display:inline-block; margin-top:10px; padding:9px 16px; background:#002b66; color:#fff; border-radius:6px; font-weight:bold; text-decoration:none;">← Volver al mapa</a>'
        . '</div></body></html>';
    exit;
}

// El rol se relee de la base de datos en cada petición, para que un cambio
// de rol se aplique de inmediato sin que el usuario tenga que volver a entrar.
if (isset($pdo, $_SESSION['usuario_id'])) {
    $stmt_rol = $pdo->prepare("SELECT rol FROM usuarios WHERE id = ?");
    $stmt_rol->execute([$_SESSION['usuario_id']]);
    $rol_bd = $stmt_rol->fetchColumn();
    if ($rol_bd === false) {
        // El usuario ya no existe: cerrar la sesión
        $_SESSION = [];
        session_destroy();
    } else {
        $_SESSION['usuario_rol'] = $rol_bd;
    }
}
