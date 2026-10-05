<?php
// Administración de usuarios: asignar roles según la jerarquía (solo Super Admin).
session_start();
require 'db.php';
require 'permisos.php';

if (!isset($_SESSION['usuario_id'])) {
    header("Location: index.php");
    exit;
}
exigirPermiso('gestionar_usuarios');

$user_id = (int)$_SESSION['usuario_id'];
if (empty($_SESSION['csrf'])) {
    $_SESSION['csrf'] = bin2hex(random_bytes(16));
}

$mensaje = '';
$error   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $id  = (int)($_POST['id'] ?? 0);
    $rol = $_POST['rol'] ?? '';
    if (!hash_equals($_SESSION['csrf'], $_POST['csrf'] ?? '')) {
        $error = 'La sesión del formulario expiró. Intente de nuevo.';
    } elseif (!isset(ROLES[$rol])) {
        $error = 'Rol no válido.';
    } elseif ($id === $user_id) {
        // Evita que el Super Admin se quite a sí mismo el control total
        $error = 'No puede cambiar su propio rol.';
    } else {
        $stmt = $pdo->prepare("UPDATE usuarios SET rol = ? WHERE id = ?");
        $stmt->execute([$rol, $id]);
        $mensaje = $stmt->rowCount() ? 'Rol actualizado a ' . nombreRol($rol) . '. El cambio se aplica de inmediato.' : 'Sin cambios.';
    }
}

$usuarios = $pdo->query("SELECT id, nombre, email, rol, creado_en FROM usuarios ORDER BY FIELD(rol, 'super_admin', 'admin', 'user'), nombre")->fetchAll();

$roles_por_nivel = ROLES;
uasort($roles_por_nivel, fn($a, $b) => $a['nivel'] <=> $b['nivel']);

function h($s)
{
    return htmlspecialchars((string)$s);
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Usuarios y permisos</title>
    <style>
        * {
            box-sizing: border-box;
            margin: 0;
            padding: 0;
        }

        body {
            font-family: system-ui, -apple-system, "Segoe UI", sans-serif;
            background: #f1f5f9;
            color: #0f172a;
            padding: 24px 16px 40px;
        }

        .contenedor {
            max-width: 1000px;
            margin: 0 auto;
            display: flex;
            flex-direction: column;
            gap: 16px;
        }

        header {
            display: flex;
            flex-wrap: wrap;
            justify-content: space-between;
            align-items: flex-end;
            gap: 12px;
        }

        h1 {
            font-size: 22px;
            color: #002b66;
        }

        header p,
        .sub {
            font-size: 13px;
            color: #475569;
            margin-top: 2px;
        }

        .btn {
            padding: 7px 12px;
            font-size: 13px;
            border-radius: 6px;
            border: 1px solid #cbd5e1;
            background: #fff;
            color: #0f172a;
            text-decoration: none;
            font-weight: 600;
            cursor: pointer;
        }

        .btn-azul {
            background: #002b66;
            color: #fff;
            border-color: #002b66;
        }

        .tarjeta {
            background: #fff;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 16px;
        }

        .tarjeta h2 {
            font-size: 16px;
        }

        .tabla-scroll {
            overflow-x: auto;
            margin-top: 12px;
        }

        table {
            width: 100%;
            border-collapse: collapse;
            font-size: 13px;
        }

        th,
        td {
            text-align: left;
            padding: 9px 8px;
            border-bottom: 1px solid #e2e8f0;
            vertical-align: middle;
        }

        th {
            font-size: 12px;
            color: #475569;
        }

        td.c,
        th.c {
            text-align: center;
        }

        .grupo td {
            background: #f8fafc;
            font-weight: 700;
            color: #002b66;
            font-size: 12px;
        }

        .si {
            color: #15803d;
            font-weight: 700;
        }

        .no {
            color: #cbd5e1;
        }

        .nivel {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 3px 9px;
            border-radius: 999px;
            font-size: 12px;
            font-weight: 600;
            white-space: nowrap;
        }

        .nivel-1 {
            background: #e2e8f0;
            color: #334155;
        }

        .nivel-2 {
            background: #dbeafe;
            color: #1e40af;
        }

        .nivel-3 {
            background: #002b66;
            color: #fff;
        }

        form.fila {
            display: flex;
            gap: 6px;
            align-items: center;
        }

        select {
            padding: 6px 8px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font: inherit;
        }

        .flash {
            padding: 10px 14px;
            border-radius: 8px;
            font-size: 14px;
        }

        .flash-ok {
            background: #dcfce7;
            color: #166534;
        }

        .flash-error {
            background: #fee2e2;
            color: #991b1b;
        }

        .escalera {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
            gap: 10px;
            margin-top: 12px;
        }

        .escalon {
            border: 1px solid #e2e8f0;
            border-radius: 8px;
            padding: 12px;
        }

        .escalon p {
            font-size: 12px;
            color: #475569;
            margin-top: 6px;
        }
    </style>
</head>

<body>
    <div class="contenedor">
        <header>
            <div>
                <h1>👥 Usuarios y permisos</h1>
                <p>Asigne a cada usuario su nivel en la jerarquía. Solo el Super Admin ve esta página.</p>
            </div>
            <a class="btn" href="geoterreno.php">← Volver al mapa</a>
        </header>

        <?php if ($mensaje): ?><div class="flash flash-ok">✔ <?= h($mensaje) ?></div><?php endif; ?>
        <?php if ($error): ?><div class="flash flash-error">✖ <?= h($error) ?></div><?php endif; ?>

        <!-- Jerarquía -->
        <section class="tarjeta">
            <h2>Niveles de jerarquía</h2>
            <div class="sub">Cada nivel tiene todo lo del nivel anterior y algo más.</div>
            <div class="escalera">
                <?php foreach ($roles_por_nivel as $clave => $r): ?>
                    <div class="escalon">
                        <span class="nivel nivel-<?= $r['nivel'] ?>">Nivel <?= $r['nivel'] ?> · <?= h($r['nombre']) ?></span>
                        <p><?= h($r['descripcion']) ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <!-- Usuarios -->
        <section class="tarjeta">
            <h2>Usuarios registrados (<?= count($usuarios) ?>)</h2>
            <div class="sub">Las cuentas nuevas se crean como Usuario. El cambio de rol se aplica en la siguiente acción del usuario, sin que tenga que volver a entrar.</div>
            <div class="tabla-scroll">
                <table>
                    <tr>
                        <th>Nombre</th>
                        <th>Correo</th>
                        <th>Rol actual</th>
                        <th>Registrado</th>
                        <th>Cambiar rol</th>
                    </tr>
                    <?php foreach ($usuarios as $u): ?>
                        <tr>
                            <td><b><?= h($u['nombre']) ?></b><?= (int)$u['id'] === $user_id ? ' <small style="color:#64748b">(usted)</small>' : '' ?></td>
                            <td><?= h($u['email']) ?></td>
                            <td><span class="nivel nivel-<?= nivelRol($u['rol']) ?>"><?= h(nombreRol($u['rol'])) ?></span></td>
                            <td><?= date('d/m/Y', strtotime($u['creado_en'])) ?></td>
                            <td>
                                <?php if ((int)$u['id'] === $user_id): ?>
                                    <small style="color:#64748b">—</small>
                                <?php else: ?>
                                    <form method="POST" class="fila">
                                        <input type="hidden" name="csrf" value="<?= h($_SESSION['csrf']) ?>">
                                        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                                        <select name="rol" aria-label="Rol de <?= h($u['nombre']) ?>">
                                            <?php foreach ($roles_por_nivel as $clave => $r): ?>
                                                <option value="<?= h($clave) ?>" <?= $clave === $u['rol'] ? 'selected' : '' ?>><?= $r['nivel'] ?> · <?= h($r['nombre']) ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <button class="btn btn-azul" type="submit">Guardar</button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </section>

        <!-- Matriz -->
        <section class="tarjeta">
            <h2>Qué puede hacer cada nivel</h2>
            <div class="sub">Esta tabla se genera desde <code>permisos.php</code>. Para mover un permiso a otro nivel, cambie su número allí.</div>
            <div class="tabla-scroll">
                <table>
                    <tr>
                        <th>Permiso</th>
                        <?php foreach ($roles_por_nivel as $r): ?><th class="c"><?= h($r['nombre']) ?></th><?php endforeach; ?>
                    </tr>
                    <?php $grupo = null;
                    foreach (PERMISOS as $accion => [$minimo, $desc, $g]): ?>
                        <?php if ($g !== $grupo): $grupo = $g; ?>
                            <tr class="grupo">
                                <td colspan="<?= count(ROLES) + 1 ?>"><?= h($g) ?></td>
                            </tr>
                        <?php endif; ?>
                        <tr>
                            <td><?= h($desc) ?></td>
                            <?php foreach ($roles_por_nivel as $clave => $r): ?>
                                <td class="c"><?= puede($accion, $clave) ? '<span class="si" aria-label="Sí">✔</span>' : '<span class="no" aria-label="No">—</span>' ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                </table>
            </div>
        </section>
    </div>
</body>

</html>
