<?php
require_once 'config.php';

$action = $_GET['action'] ?? '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'login') {
    $email = $_POST['email'] ?? '';
    $password = $_POST['password'] ?? '';

    $stmt = $pdo->prepare("SELECT * FROM users WHERE email = ? LIMIT 1");
    $stmt->execute([$email]);
    $user = $stmt->fetch();

    if ($user && password_verify($password, $user['password_hash'])) {
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
    session_destroy();
    header('Location: login.php');
    exit;
}
