<?php
require_once 'config.php';

try {
    // Comments table
    $pdo->exec("CREATE TABLE IF NOT EXISTS request_comments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        request_id INT NOT NULL,
        user_id INT NOT NULL,
        message TEXT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    // Attachments table
    $pdo->exec("CREATE TABLE IF NOT EXISTS request_attachments (
        id INT AUTO_INCREMENT PRIMARY KEY,
        request_id INT NOT NULL,
        user_id INT NOT NULL,
        filename VARCHAR(255) NOT NULL,
        original_name VARCHAR(255) NOT NULL,
        file_size INT NOT NULL DEFAULT 0,
        mime_type VARCHAR(100) NOT NULL DEFAULT '',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (request_id) REFERENCES requests(id) ON DELETE CASCADE,
        FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
    )");

    echo "Tablas de interacción creadas.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
