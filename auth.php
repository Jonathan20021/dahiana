<?php
require_once 'config.php';

$action = $_GET['action'] ?? '';

// auth.php no llama a requireAuth() (es el que crea la sesion), asi que el
// token se valida a mano. Sin esto un formulario en otro sitio podia iniciar
// sesion en el navegador de la victima con una cuenta del atacante.
requireCsrf();

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
        $approvalStatus = $user['approval_status'] ?? 'approved';
        if ($approvalStatus === 'pending_approval') {
            $_SESSION['login_error'] = "Tu cuenta esta en revision por nuestro equipo. Te avisaremos por correo cuando este lista.";
            header('Location: login.php');
            exit;
        }
        if ($approvalStatus === 'rejected') {
            $reason = trim($user['rejected_reason'] ?? '');
            $_SESSION['login_error'] = "Tu solicitud fue rechazada." . ($reason ? " Motivo: {$reason}" : ' Contacta al equipo.');
            header('Location: login.php');
            exit;
        }
        // Id de sesion nuevo al autenticar: si el atacante habia fijado el
        // PHPSESSID del navegador de la victima, el suyo se queda sin valor.
        session_regenerate_id(true);

        $_SESSION['user_id'] = $user['id'];
        $_SESSION['role'] = $user['role'];
        $_SESSION['name'] = $user['name'];
        header('Location: ' . getDashboardForRole($user['role']));
        exit;
    } else {
        $_SESSION['login_error'] = "Credenciales incorrectas.";
        header('Location: login.php');
        exit;
    }
}

if ($action === 'logout') {
    // session_destroy() por si solo deja $_SESSION cargado en esta peticion y
    // la cookie viva en el navegador: se limpian las tres cosas.
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
    header('Location: login.php');
    exit;
}
