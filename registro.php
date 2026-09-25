<?php
session_start();
require 'db.php';

$mensaje = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nombre   = trim($_POST['nombre'] ?? '');
    $email    = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $rol      = $_POST['rol'] ?? 'user';

    if (!empty($nombre) && !empty($email) && !empty($password)) {
        try {
            $pass_hash = password_hash($password, PASSWORD_BCRYPT);
            $stmt = $pdo->prepare("INSERT INTO usuarios (nombre, email, password, rol) VALUES (?, ?, ?, ?)");
            $stmt->execute([$nombre, $email, $pass_hash, $rol]);

            header("Location: index.php?registrado=1");
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
    <title>GeoTerrenos - Registro</title>
    <style>
        * {
            box-sizing: border-box;
            font-family: 'Segoe UI', sans-serif;
            margin: 0;
            padding: 0;
        }

        body {
            background: #121824;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .box {
            background: #ffffff;
            width: 100%;
            max-width: 400px;
            padding: 30px;
            border-radius: 10px;
            box-shadow: 0 10px 25px rgba(0, 0, 0, 0.3);
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
                <select name="rol" class="form-control">
                    <option value="user">Usuario (Consulta)</option>
                    <option value="admin">Administrador (Edición)</option>
                    <option value="super_admin">Super Admin (Control Total)</option>
                </select>
            </div>

            <button type="submit" class="btn-submit">Registrarme</button>
        </form>

        <a href="index.php" class="link-back">Volver al Inicio de Sesión</a>
    </div>

</body>

</html>