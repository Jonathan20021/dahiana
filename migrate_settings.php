<?php
require_once 'config.php';

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT NOT NULL
    )");
    
    // Insert defaults if not exist
    $defaults = [
        'company_name' => 'Asesoría Financiera',
        'company_rnc' => '',
        'company_address' => '',
        'company_phone' => '',
        'company_email' => '',
        'company_slogan' => 'Portal de Gestión Fiscal y Tributaria',
        'company_initials' => 'AF',
        'invoice_note' => 'Este documento no tiene valor fiscal.',
        'whatsapp_greeting' => 'te escribimos de tu Asesoría Financiera',
    ];
    
    $stmt = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
    foreach ($defaults as $key => $value) {
        $stmt->execute([$key, $value]);
    }
    
    echo "Tabla settings creada e inicializada.\n";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
