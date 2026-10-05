<?php
session_start();
require 'db.php';

$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre   = trim($_POST['nombre'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    // Toda cuenta nueva empieza con el nivel más bajo; solo el Super Admin puede subirla (usuarios.php)
    $rol      = 'user';

    if (!empty($nombre) && !empty($email) && !empty($password)) {
        try {
            $pass_hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, email, password, rol) VALUES (?, ?, ?, ?)");
            $stmt->execute([$nombre, $email, $pass_hash, $rol]);

            header("Lo  ion: index.php?registrado=1");
            exit;
        } catch (\PDOException $e) {
            $mensaje = "El correo electrónico ya se encuentra registrado.";
        }
    } else {
        $mensaje = "Por favor completa todos los campos.";
    }
}
?>
<!DOCTYPE html>
<html lang="es">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>GeoTerrenos - Registro</title>
    <style>
        * {
            box-sizing: border-box;
            font-family: 'Segoe UI', sans-serif;
            margin: 0;
            padding: 0;
        }

        body {
            /* Imagen de fondo a pantalla completa; el degradado oscurece los bordes para que resalte el recuadro */
            background:
                radial-gradient(ellipse at center, rgba(0, 20, 50, 0.15) 0%, rgba(0, 20, 50, 0.65) 100%),
                url("assets/img/fondo-ingreso.webp") center / cover no-repeat fixed,
                #121824;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
            padding: 24px 16px;
        }

        .marca {
            position: fixed;
            left: 28px;
            bottom: 24px;
            color: #fff;
            text-shadow: 0 2px 8px rgba(0, 0, 0, 0.6);
        }

        .marca strong {
            display: block;
            font-size: 26px;
            font-weight: 800;
            letter-spacing: 1px;
        }

        .marca span {
            font-size: 14px;
            opacity: 0.9;
        }

        @media (max-width: 700px) {
            .marca {
                display: none;
            }
        }

        .box {
            background: #ffffff;
            width: 100%;
            max-width: 400px;
            padding: 30px;
            border-radius: 14px;
            background: rgba(255, 255, 255, 0.94);
            backdrop-filter: blur(6px);
            box-shadow: 0 20px 45px rgba(0, 0, 0, 0.45);
        }

        h2 {
            text-align: center;
            color: #002b66;
            font-weight: 800;
        }

        p.sub {
            text-align: center;
            color: #64748b;
            font-size: 13px;
            margin-bottom: 20px;
            font-weight: 600;
        }

        .form-group {
            margin-bottom: 12px;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: bold;
            color: #475569;
            margin-bottom: 4px;
        }

        .form-control {
            width: 100%;
            padding: 9px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 13px;
            outline: none;
        }

        .btn-submit {
            width: 100%;
            background: #16a34a;
            color: white;
            border: none;
            padding: 11px;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
            margin-top: 10px;
        }

        .btn-submit:hover {
            background: #15803d;
        }

        .alert {
            background: #fee2e2;
            color: #991b1b;
            padding: 10px;
            border-radius: 6px;
            font-size: 12px;
            margin-bottom: 15px;
            text-align: center;
        }

        .link-back {
            display: block;
            text-align: center;
            margin-top: 15px;
            color: #2563eb;
            text-decoration: none;
            font-size: 13px;
            font-weight: bold;
        }
    </style>
</head>

<body>
    <div class="marca" aria-hidden="true"><strong>GEOTERRENOS</strong><span>Gestión y análisis de terrenos</span></div>

    <div class="box">
        <h2>GEOTERRENOS</h2>
        <p class="sub">CREAR NUEVA CUENTA</p>

        <?php if ($mensaje): ?>
            <div class="alert"><?= htmlspecialchars($mensaje) ?></div>
        <?php endif; ?>

        <form method="POST" action="registro.php">
            <div class="form-group">
                <label>Nombre Completo</label>
                <input type="text" name="nombre" class="form-control" placeholder="Ej. Juan Pérez" required>
            </div>

            <div class="form-group">
                <label>Correo Electrónico</label>
                <input type="email" name="email" class="form-control" placeholder="correo@ejemplo.com" required>
            </div>

            <div class="form-group">
                <label>Contraseña</label>
                <input type="password" name="password" class="form-control" placeholder="••••••••" required>
            </div>

            <div class="form-group">
                <label>Nivel de Acceso</label>
                <p style="font-size:13px; color:#475569; line-height:1.4;">Su cuenta se creará como <b>Usuario (Consulta)</b>. El administrador del sistema le asignará un nivel mayor si lo necesita.</p>
            </div>

            <button type="submit" class="btn-submit">Registrarme</button>
        </form>

        <a href="index.php" class="link-back">Volver al Inicio de Sesión</a>
    </div>

</body>

</html>