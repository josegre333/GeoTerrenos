<?php
session_start();
require 'db.php';

// Si ya inició sesión, redirige al sistema principal
if (isset($_SESSION['usuario_id'])) {
    header("Location: geoterreno.php");
    exit;
}

$mensaje = '';
if (isset($_GET['registrado'])) {
    $mensaje = "¡Registro exitoso! Ya puedes iniciar sesión.";
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email    = trim($_POST['email'] ?? '');
    $password = trim($_POST['password'] ?? '');

    if (!empty($email) && !empty($password)) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM usuarios WHERE email = ?");
            $stmt->execute([$email]);
            $usuario = $stmt->fetch();

            if ($usuario && password_verify($password, $usuario['password'])) {
                $_SESSION['usuario_id']     = $usuario['id'];
                $_SESSION['usuario_nombre'] = $usuario['nombre'];
                $_SESSION['usuario_rol']    = $usuario['rol'];

                header("Location: geoterreno.php");
                exit;
            } else {
                $mensaje = "Usuario no registrado o contraseña incorrecta.";
            }
        } catch (\PDOException $e) {
            $mensaje = "Error en el servidor: " . $e->getMessage();
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
    <title>GeoTerrenos - Acceso</title>
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
            max-width: 380px;
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
            margin-bottom: 15px;
        }

        .form-group label {
            display: block;
            font-size: 12px;
            font-weight: bold;
            color: #475569;
            margin-bottom: 5px;
        }

        .form-group input {
            width: 100%;
            padding: 10px;
            border: 1px solid #cbd5e1;
            border-radius: 6px;
            font-size: 14px;
            outline: none;
        }

        .btn-submit {
            width: 100%;
            background: #1d4ed8;
            color: white;
            border: none;
            padding: 11px;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
        }

        .btn-submit:hover {
            background: #1e40af;
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

        .register-box {
            margin-top: 20px;
            text-align: center;
            border-top: 1px solid #e2e8f0;
            padding-top: 15px;
        }

        .btn-register {
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
        <p class="sub">INICIAR SESIÓN</p>

        <?php if ($mensaje): ?>
            <div class="alert"><?= htmlspecialchars($mensaje) ?></div>
        <?php endif; ?>

        <form method="POST" action="index.php">
            <div class="form-group">
                <label>Correo Electrónico</label>
                <input type="email" name="email" required placeholder="correo@ejemplo.com">
            </div>

            <div class="form-group">
                <label>Contraseña</label>
                <input type="password" name="password" required placeholder="••••••••">
            </div>

            <button type="submit" class="btn-submit">Ingresar</button>
        </form>

        <div class="register-box">
            <p style="font-size:12px; color:#64748b; margin-bottom: 5px;">¿No estás registrado?</p>
            <a href="registro.php" class="btn-register">Crear una cuenta nueva</a>
        </div>
    </div>

</body>

</html>