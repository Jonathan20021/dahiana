<?php
require_once 'config.php';

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false]);
    exit;
}

try {
    $pdo->prepare("UPDATE users SET onboarding_completed_at = NOW() WHERE id = ?")
        ->execute([(int)$_SESSION['user_id']]);
    echo json_encode(['ok' => true]);
} catch (PDOException $e) {
    echo json_encode(['ok' => false, 'error' => 'db']);
}
