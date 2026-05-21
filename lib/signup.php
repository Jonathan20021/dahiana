<?php
// lib/signup.php
// Workflow: registro publico de clientes + aprobacion desde admin.

if (!defined('SIGNUP_LIB_LOADED')) define('SIGNUP_LIB_LOADED', true);

function bootstrapSignupSchema() {
    global $pdo;
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        // Columnas en users: approval_status + registered_via + rejected_reason
        $cols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'")->fetchAll(PDO::FETCH_COLUMN);
        $cols = array_map('strtolower', $cols);
        if (!in_array('approval_status', $cols, true)) {
            try { $pdo->exec("ALTER TABLE users ADD COLUMN approval_status VARCHAR(20) DEFAULT 'approved', ADD INDEX idx_users_approval (approval_status)"); } catch (PDOException $e) {}
        }
        if (!in_array('registered_via', $cols, true)) {
            try { $pdo->exec("ALTER TABLE users ADD COLUMN registered_via VARCHAR(20) DEFAULT 'admin'"); } catch (PDOException $e) {}
        }
        if (!in_array('rejected_reason', $cols, true)) {
            try { $pdo->exec("ALTER TABLE users ADD COLUMN rejected_reason TEXT DEFAULT NULL"); } catch (PDOException $e) {}
        }
        if (!in_array('approved_at', $cols, true)) {
            try { $pdo->exec("ALTER TABLE users ADD COLUMN approved_at TIMESTAMP NULL DEFAULT NULL"); } catch (PDOException $e) {}
        }
        if (!in_array('approved_by', $cols, true)) {
            try { $pdo->exec("ALTER TABLE users ADD COLUMN approved_by INT DEFAULT NULL"); } catch (PDOException $e) {}
        }

        // Tabla de servicios solicitados al registrarse (se convierte en requests al aprobar)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS signup_requested_services (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT NOT NULL,
                service_id INT NOT NULL,
                service_type VARCHAR(20) DEFAULT NULL,
                period VARCHAR(20) DEFAULT NULL,
                estimated_date DATE DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_user (user_id)
            )
        ");

        // Seed: settings de signup form (idempotente)
        $defaults = [
            'signup_enabled'           => '1',
            'signup_title'             => 'Crea tu cuenta',
            'signup_subtitle'          => 'Completa tus datos y elige los servicios que necesitas. Tu cuenta sera revisada y activada por nuestro equipo.',
            'signup_success_message'   => 'Recibimos tu solicitud. Te contactaremos por correo cuando tu cuenta sea aprobada.',
            'signup_terms_text'        => 'Acepto que el equipo me contacte por email o WhatsApp para validar mis datos.',
            'signup_show_services'     => '1',
            'signup_require_rnc'       => '0',
            'signup_require_business'  => '0',
            'signup_require_address'   => '0',
            'signup_visible_fields'    => json_encode(['name','email','phone','password','business_name','rnc','business_type','address','tax_regime','economic_activity','operation_type','employee_count','notes']),
            'signup_required_fields'   => json_encode(['name','email','password','phone']),
        ];
        $seed = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
        foreach ($defaults as $k => $v) $seed->execute([$k, $v]);
    } catch (PDOException $e) {
        // Bootstrap silencioso
    }
}

bootstrapSignupSchema();

// --------------------------------------------------------------------------
// Form config helpers
// --------------------------------------------------------------------------

/**
 * Catalogo de todos los campos disponibles para el form publico.
 * key => [label, placeholder, type, options(optional)]
 */
function signupFieldsCatalog() {
    return [
        'name'              => ['label' => 'Nombre completo / Razon social', 'placeholder' => 'Tu nombre o el de tu negocio', 'type' => 'text', 'group' => 'Identificacion'],
        'email'             => ['label' => 'Correo electronico',             'placeholder' => 'tu@correo.com',                     'type' => 'email','group' => 'Identificacion'],
        'phone'             => ['label' => 'Telefono / WhatsApp',            'placeholder' => '+1 809 000 0000',                  'type' => 'tel',  'group' => 'Identificacion'],
        'password'          => ['label' => 'Contrasena',                     'placeholder' => 'Minimo 8 caracteres',              'type' => 'password','group' => 'Identificacion'],
        'business_name'     => ['label' => 'Nombre comercial',               'placeholder' => 'Nombre de tu empresa o marca',     'type' => 'text', 'group' => 'Negocio'],
        'rnc'               => ['label' => 'RNC / Cedula',                   'placeholder' => '000000000 o 000-0000000-0',         'type' => 'text', 'group' => 'Negocio'],
        'business_type'     => ['label' => 'Tipo de persona',                'placeholder' => '',                                  'type' => 'select','group' => 'Negocio',
            'options' => ['fisica' => 'Persona Fisica', 'juridica' => 'Persona Juridica']],
        'address'           => ['label' => 'Direccion',                      'placeholder' => 'Calle, sector, ciudad',             'type' => 'text', 'group' => 'Negocio'],
        'tax_regime'        => ['label' => 'Regimen fiscal',                 'placeholder' => '',                                  'type' => 'select','group' => 'Fiscal',
            'options' => 'tax_regimes'],
        'economic_activity' => ['label' => 'Actividad economica',            'placeholder' => 'Ej: Comercial de comida',           'type' => 'text', 'group' => 'Fiscal'],
        'operation_type'    => ['label' => 'Tipo de operacion',              'placeholder' => '',                                  'type' => 'select','group' => 'Fiscal',
            'options' => 'operation_types'],
        'employee_count'    => ['label' => 'Cantidad de empleados',          'placeholder' => '0',                                 'type' => 'number','group' => 'Fiscal'],
        'notes'             => ['label' => 'Comentarios adicionales',        'placeholder' => 'Cuentanos algo mas...',             'type' => 'textarea','group' => 'Extra'],
    ];
}

function signupVisibleFields() {
    $raw = getSetting('signup_visible_fields', '[]');
    $list = json_decode($raw, true);
    if (!is_array($list) || empty($list)) {
        return ['name','email','phone','password'];
    }
    return $list;
}

function signupRequiredFields() {
    $raw = getSetting('signup_required_fields', '[]');
    $list = json_decode($raw, true);
    if (!is_array($list)) $list = [];
    // Email y password siempre requeridos a nivel server
    if (!in_array('email', $list, true)) $list[] = 'email';
    if (!in_array('password', $list, true)) $list[] = 'password';
    if (!in_array('name', $list, true)) $list[] = 'name';
    return $list;
}

function signupIsEnabled() {
    return getSetting('signup_enabled', '1') === '1';
}

/**
 * Lista de servicios visibles en el form publico (los que el admin marca como visibles).
 * Por defecto: todos.
 */
function signupVisibleServices() {
    global $pdo;
    $rawHidden = getSetting('signup_hidden_services', '[]');
    $hiddenIds = json_decode($rawHidden, true) ?: [];
    $stmt = $pdo->query("SELECT id, title, type, delivery_days, delivery_label, description FROM services WHERE COALESCE(is_active, 1) = 1 ORDER BY type, title");
    $list = $stmt->fetchAll();
    return array_values(array_filter($list, fn($s) => !in_array((int)$s['id'], array_map('intval', $hiddenIds), true)));
}

// --------------------------------------------------------------------------
// Pending user creation + approval workflow
// --------------------------------------------------------------------------

/**
 * Crea un usuario en estado pending_approval.
 * Devuelve ['ok' => bool, 'user_id' => int, 'error' => string].
 */
function signupCreatePendingUser(array $data, array $requestedServiceIds = []) {
    global $pdo;

    $name     = trim($data['name'] ?? '');
    $email    = trim($data['email'] ?? '');
    $phone    = trim($data['phone'] ?? '');
    $password = $data['password'] ?? '';

    if ($name === '' || $email === '' || $password === '') {
        return ['ok' => false, 'error' => 'Nombre, correo y contrasena son obligatorios.'];
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'error' => 'El correo no tiene un formato valido.'];
    }
    if (strlen($password) < 8) {
        return ['ok' => false, 'error' => 'La contrasena debe tener al menos 8 caracteres.'];
    }

    // Duplicado
    $dup = $pdo->prepare("SELECT id, approval_status FROM users WHERE email = ?");
    $dup->execute([$email]);
    if ($d = $dup->fetch()) {
        if (($d['approval_status'] ?? 'approved') === 'pending_approval') {
            return ['ok' => false, 'error' => 'Ya tienes una solicitud pendiente con este correo.'];
        }
        return ['ok' => false, 'error' => 'Este correo ya esta registrado. Intenta iniciar sesion.'];
    }

    try {
        $hash = password_hash($password, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare("
            INSERT INTO users
            (name, email, phone, role, password_hash,
             rnc, business_name, business_type, client_status,
             address, started_at, notes,
             tax_regime, economic_activity, fiscal_year_close, employee_count, operation_type,
             approval_status, registered_via)
            VALUES (?, ?, ?, 'client', ?,
                    ?, ?, ?, 'lead',
                    ?, NULL, ?,
                    ?, ?, '12-31', ?, ?,
                    'pending_approval', 'public_signup')
        ");
        $stmt->execute([
            $name, $email, $phone, $hash,
            trim($data['rnc'] ?? ''),
            trim($data['business_name'] ?? ''),
            $data['business_type'] ?? 'fisica',
            trim($data['address'] ?? ''),
            trim($data['notes'] ?? ''),
            $data['tax_regime'] ?? 'ordinario',
            trim($data['economic_activity'] ?? ''),
            (int)($data['employee_count'] ?? 0),
            $data['operation_type'] ?? 'servicios',
        ]);
        $userId = (int)$pdo->lastInsertId();

        // Persistir servicios solicitados
        if (!empty($requestedServiceIds)) {
            $svcQ = $pdo->prepare("SELECT id, type FROM services WHERE id = ?");
            $insSvc = $pdo->prepare("INSERT INTO signup_requested_services (user_id, service_id, service_type) VALUES (?, ?, ?)");
            foreach ($requestedServiceIds as $sid) {
                $sid = (int)$sid;
                if ($sid <= 0) continue;
                $svcQ->execute([$sid]);
                $svc = $svcQ->fetch();
                if (!$svc) continue;
                $insSvc->execute([$userId, $sid, $svc['type']]);
            }
        }

        return ['ok' => true, 'user_id' => $userId];
    } catch (PDOException $e) {
        return ['ok' => false, 'error' => 'No se pudo crear la cuenta. Intenta de nuevo.'];
    }
}

function signupApproveUser($userId, $approverId = null) {
    global $pdo;
    $userId = (int)$userId;
    $u = $pdo->prepare("SELECT * FROM users WHERE id = ?");
    $u->execute([$userId]);
    $user = $u->fetch();
    if (!$user) return ['ok' => false, 'error' => 'Cliente no encontrado.'];
    if (($user['approval_status'] ?? '') === 'approved') {
        return ['ok' => false, 'error' => 'Esta cuenta ya esta aprobada.'];
    }

    $pdo->prepare("UPDATE users SET approval_status='approved', client_status='activo', approved_at=NOW(), approved_by=? WHERE id = ?")
        ->execute([$approverId, $userId]);

    // Convertir servicios solicitados en requests
    $req = $pdo->prepare("SELECT s.id AS service_id, s.type, s.title, s.delivery_days, r.period, r.estimated_date FROM signup_requested_services r JOIN services s ON s.id = r.service_id WHERE r.user_id = ?");
    $req->execute([$userId]);
    $created = 0;
    foreach ($req->fetchAll() as $rs) {
        if ($rs['type'] === 'iguala') {
            $period = $rs['period'] ?: date('Y-m');
            $pdo->prepare("INSERT INTO requests (client_id, service_id, status, period) VALUES (?, ?, 'pendiente', ?)")
                ->execute([$userId, $rs['service_id'], $period]);
        } else {
            // Auto-calcular fecha estimada desde delivery_days si no la dieron
            $est = $rs['estimated_date'] ?: null;
            if (!$est && !empty($rs['delivery_days']) && function_exists('calcDeliveryDate')) {
                $est = calcDeliveryDate((int)$rs['delivery_days']);
            }
            $pdo->prepare("INSERT INTO requests (client_id, service_id, status, estimated_delivery_date) VALUES (?, ?, 'pendiente', ?)")
                ->execute([$userId, $rs['service_id'], $est]);
        }
        $created++;
    }

    // Generar obligaciones DGII
    if (function_exists('generateObligationsForClient')) {
        generateObligationsForClient($userId, 6);
    }

    if (function_exists('logClientActivity')) {
        logClientActivity($userId, 'approved', "Cliente aprobado desde solicitud publica ({$created} servicios pre-asignados)");
    }

    // Email de bienvenida
    if (getSetting('notify_welcome', '1') === '1' && function_exists('sendWelcomeEmail')) {
        // No tenemos password plano; el email de welcome notificara aprobacion sin password
        @sendWelcomeEmail($userId, null);
    }

    return ['ok' => true, 'requests_created' => $created];
}

function signupRejectUser($userId, $reason = '', $approverId = null) {
    global $pdo;
    $userId = (int)$userId;
    $pdo->prepare("UPDATE users SET approval_status='rejected', rejected_reason=?, approved_by=? WHERE id = ?")
        ->execute([substr($reason, 0, 1000), $approverId, $userId]);
    if (function_exists('logClientActivity')) {
        logClientActivity($userId, 'rejected', "Solicitud publica rechazada. Motivo: {$reason}");
    }
    return ['ok' => true];
}

function signupPendingCount() {
    global $pdo;
    try {
        return (int)$pdo->query("SELECT COUNT(*) FROM users WHERE approval_status = 'pending_approval'")->fetchColumn();
    } catch (PDOException $e) { return 0; }
}
