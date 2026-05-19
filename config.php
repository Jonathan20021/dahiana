<?php
session_start();

// Database configuration
define('DB_HOST', '129.121.81.172');
define('DB_USER', 'neetjbte_dahiana');
define('DB_PASS', 'Dahiana*2026');
define('DB_NAME', 'neetjbte_dahiana');

try {
    $pdo = new PDO("mysql:host=" . DB_HOST . ";dbname=" . DB_NAME . ";charset=utf8mb4", DB_USER, DB_PASS);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

function bootstrapAccessControlSchema() {
    global $pdo;
    static $bootstrapped = false;

    if ($bootstrapped) {
        return;
    }

    $bootstrapped = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS roles (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                slug VARCHAR(100) NOT NULL UNIQUE,
                access_level ENUM('admin', 'client') NOT NULL DEFAULT 'client',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            )
        ");

        $columnStmt = $pdo->prepare("
            SELECT DATA_TYPE
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = 'users'
              AND COLUMN_NAME = 'role'
            LIMIT 1
        ");
        $columnStmt->execute();
        $roleColumn = $columnStmt->fetch();

        if ($roleColumn && strtolower((string) $roleColumn['DATA_TYPE']) === 'enum') {
            $pdo->exec("ALTER TABLE users MODIFY role VARCHAR(100) NOT NULL DEFAULT 'client'");
        }

        $pdo->exec("
            INSERT IGNORE INTO roles (name, slug, access_level) VALUES
            ('Administrador', 'admin', 'admin'),
            ('Cliente', 'client', 'client')
        ");

        $existingRoles = $pdo->query("SELECT DISTINCT role FROM users WHERE role IS NOT NULL AND role <> ''")->fetchAll(PDO::FETCH_COLUMN);
        $insertRole = $pdo->prepare("INSERT IGNORE INTO roles (name, slug, access_level) VALUES (?, ?, ?)");

        foreach ($existingRoles as $roleSlug) {
            $normalizedSlug = trim((string) $roleSlug);
            if ($normalizedSlug === '') {
                continue;
            }

            $roleName = ucwords(str_replace(['_', '-'], ' ', $normalizedSlug));
            $accessLevel = $normalizedSlug === 'admin' ? 'admin' : 'client';
            $insertRole->execute([$roleName, $normalizedSlug, $accessLevel]);
        }
    } catch (PDOException $e) {
        // Ignore bootstrap errors to keep the app usable if the schema already changed manually.
    }
}

bootstrapAccessControlSchema();

function bootstrapCrmSchema() {
    global $pdo;
    static $bootstrapped = false;
    if ($bootstrapped) return;
    $bootstrapped = true;

    try {
        // Add CRM columns to users (idempotent)
        $existing = $pdo->query("
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users'
        ")->fetchAll(PDO::FETCH_COLUMN);
        $existing = array_map('strtolower', $existing);

        $columnsToAdd = [
            'rnc'              => "ALTER TABLE users ADD COLUMN rnc VARCHAR(30) DEFAULT NULL",
            'business_name'    => "ALTER TABLE users ADD COLUMN business_name VARCHAR(255) DEFAULT NULL",
            'business_type'    => "ALTER TABLE users ADD COLUMN business_type VARCHAR(20) DEFAULT 'fisica'",
            'client_status'    => "ALTER TABLE users ADD COLUMN client_status VARCHAR(20) DEFAULT 'activo'",
            'address'          => "ALTER TABLE users ADD COLUMN address VARCHAR(500) DEFAULT NULL",
            'started_at'       => "ALTER TABLE users ADD COLUMN started_at DATE DEFAULT NULL",
            'notes'            => "ALTER TABLE users ADD COLUMN notes TEXT DEFAULT NULL",
            'iguala_amount'    => "ALTER TABLE users ADD COLUMN iguala_amount DECIMAL(10,2) DEFAULT 0",
            'iguala_frequency' => "ALTER TABLE users ADD COLUMN iguala_frequency VARCHAR(20) DEFAULT 'mensual'",
            'tax_regime'       => "ALTER TABLE users ADD COLUMN tax_regime VARCHAR(50) DEFAULT 'ordinario'",
            'economic_activity'=> "ALTER TABLE users ADD COLUMN economic_activity VARCHAR(255) DEFAULT NULL",
            'fiscal_year_close'=> "ALTER TABLE users ADD COLUMN fiscal_year_close VARCHAR(5) DEFAULT '12-31'",
            'employee_count'   => "ALTER TABLE users ADD COLUMN employee_count INT DEFAULT 0",
            'operation_type'   => "ALTER TABLE users ADD COLUMN operation_type VARCHAR(50) DEFAULT 'servicios'",
        ];
        foreach ($columnsToAdd as $col => $sql) {
            if (!in_array(strtolower($col), $existing, true)) {
                try { $pdo->exec($sql); } catch (PDOException $e) { /* swallow */ }
            }
        }

        // Ensure invoices table exists
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS invoices (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                amount DECIMAL(10,2) NOT NULL DEFAULT 0,
                concept VARCHAR(500) NOT NULL,
                due_date DATE NOT NULL,
                period VARCHAR(20) DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pendiente',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                paid_at TIMESTAMP NULL DEFAULT NULL,
                INDEX (client_id), INDEX (status), INDEX (due_date), INDEX (period)
            )
        ");

        // Add period column to existing invoices if missing
        $invCols = $pdo->query("
            SELECT COLUMN_NAME
            FROM information_schema.COLUMNS
            WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'invoices'
        ")->fetchAll(PDO::FETCH_COLUMN);
        $invCols = array_map('strtolower', $invCols);
        if (!in_array('period', $invCols, true)) {
            try { $pdo->exec("ALTER TABLE invoices ADD COLUMN period VARCHAR(20) DEFAULT NULL, ADD INDEX idx_invoices_period (period)"); } catch (PDOException $e) {}
        }
        if (!in_array('paid_at', $invCols, true)) {
            try { $pdo->exec("ALTER TABLE invoices ADD COLUMN paid_at TIMESTAMP NULL DEFAULT NULL"); } catch (PDOException $e) {}
        }

        // Ensure request_comments and request_attachments exist
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS request_comments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                request_id INT NOT NULL,
                user_id INT NOT NULL,
                message TEXT NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (request_id)
            )
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS request_attachments (
                id INT AUTO_INCREMENT PRIMARY KEY,
                request_id INT NOT NULL,
                user_id INT NOT NULL,
                filename VARCHAR(255) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                file_size BIGINT DEFAULT 0,
                mime_type VARCHAR(100) DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (request_id)
            )
        ");

        // Ensure settings table exists
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS settings (
                setting_key VARCHAR(100) PRIMARY KEY,
                setting_value TEXT
            )
        ");

        // Client activity log (for CRM timeline)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS client_activity (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                actor_id INT DEFAULT NULL,
                kind VARCHAR(50) NOT NULL,
                summary VARCHAR(500) NOT NULL,
                meta TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX (client_id), INDEX (kind)
            )
        ");

        // Tax obligations (calendario fiscal DGII)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tax_obligations (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                obligation_type VARCHAR(50) NOT NULL,
                period VARCHAR(10) NOT NULL,
                due_date DATE NOT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'pendiente',
                completed_at TIMESTAMP NULL DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                auto_generated TINYINT(1) DEFAULT 1,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_client (client_id),
                INDEX idx_status (status),
                INDEX idx_due (due_date),
                INDEX idx_period (period),
                INDEX idx_type (obligation_type),
                UNIQUE KEY uniq_obligation (client_id, obligation_type, period)
            )
        ");

        // Tax filings (formularios 606/607/608)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tax_filings (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                filing_type VARCHAR(10) NOT NULL,
                period VARCHAR(7) NOT NULL,
                total_amount DECIMAL(15,2) DEFAULT 0,
                total_itbis DECIMAL(15,2) DEFAULT 0,
                total_records INT DEFAULT 0,
                status VARCHAR(20) DEFAULT 'borrador',
                sent_at TIMESTAMP NULL DEFAULT NULL,
                file_path VARCHAR(500) DEFAULT NULL,
                notes TEXT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_client (client_id),
                INDEX idx_type (filing_type),
                INDEX idx_period (period),
                UNIQUE KEY uniq_filing (client_id, filing_type, period)
            )
        ");

        // Tax filing rows (lineas dentro de cada 606/607/608)
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS tax_filing_rows (
                id INT AUTO_INCREMENT PRIMARY KEY,
                filing_id INT NOT NULL,
                rnc VARCHAR(30) DEFAULT NULL,
                ncf VARCHAR(30) DEFAULT NULL,
                ncf_modified VARCHAR(30) DEFAULT NULL,
                tax_type VARCHAR(10) DEFAULT NULL,
                date_doc DATE DEFAULT NULL,
                date_payment DATE DEFAULT NULL,
                amount DECIMAL(15,2) DEFAULT 0,
                itbis DECIMAL(15,2) DEFAULT 0,
                isr_retention DECIMAL(15,2) DEFAULT 0,
                itbis_retention DECIMAL(15,2) DEFAULT 0,
                INDEX idx_filing (filing_id)
            )
        ");
    } catch (PDOException $e) {
        // Ignore - keep app usable
    }
}

bootstrapCrmSchema();

function logClientActivity($clientId, $kind, $summary, $meta = null) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("INSERT INTO client_activity (client_id, actor_id, kind, summary, meta) VALUES (?, ?, ?, ?, ?)");
        $stmt->execute([
            $clientId,
            $_SESSION['user_id'] ?? null,
            $kind,
            $summary,
            $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE) : null,
        ]);
    } catch (PDOException $e) {}
}

function getClientStatusBadge($status) {
    $map = [
        'activo'   => '<span class="badge-dot badge-green">Activo</span>',
        'lead'     => '<span class="badge-dot badge-blue">Lead</span>',
        'inactivo' => '<span class="badge-dot badge-slate">Inactivo</span>',
    ];
    return $map[$status] ?? $map['activo'];
}

function getBusinessTypeLabel($type) {
    return $type === 'juridica' ? 'Persona Juridica' : 'Persona Fisica';
}

// ==========================================================================
// DGII - Regimenes fiscales, obligaciones y formularios
// ==========================================================================

function getTaxRegimes() {
    return [
        'ordinario'              => 'Regimen Ordinario',
        'rst'                    => 'RST (Simplificado)',
        'simplificado_compras'   => 'Simplificado de Compras',
        'simplificado_ingresos'  => 'Simplificado de Ingresos',
        'exonerado'              => 'Exonerado / Sin fines de lucro',
    ];
}

function getTaxRegimeLabel($slug) {
    return getTaxRegimes()[$slug] ?? 'Regimen Ordinario';
}

function getOperationTypes() {
    return [
        'servicios'  => 'Servicios profesionales',
        'comercial'  => 'Comercial / Ventas',
        'industrial' => 'Industrial / Manufactura',
        'mixto'      => 'Mixto',
        'agricola'   => 'Agropecuario',
    ];
}

function getOperationTypeLabel($slug) {
    return getOperationTypes()[$slug] ?? $slug;
}

/**
 * Obligation types (DGII codes / aliases).
 */
function getObligationTypes() {
    return [
        'IT-1'     => ['label' => 'IT-1 ITBIS',                 'cadence' => 'monthly', 'due_day' => 20, 'priority' => 1],
        '606'      => ['label' => '606 Compras',                'cadence' => 'monthly', 'due_day' => 20, 'priority' => 2],
        '607'      => ['label' => '607 Ventas',                 'cadence' => 'monthly', 'due_day' => 20, 'priority' => 3],
        '608'      => ['label' => '608 NCF anulados',           'cadence' => 'monthly', 'due_day' => 20, 'priority' => 4],
        'IR-17'    => ['label' => 'IR-17 Retenciones',          'cadence' => 'monthly', 'due_day' => 10, 'priority' => 5],
        'IR-3'     => ['label' => 'IR-3 Asalariados',           'cadence' => 'monthly', 'due_day' => 10, 'priority' => 6],
        'TSS'      => ['label' => 'TSS Seguridad Social',       'cadence' => 'monthly', 'due_day' => 3,  'priority' => 7],
        'ANTICIPO' => ['label' => 'Anticipo ISR',               'cadence' => 'monthly', 'due_day' => 15, 'priority' => 8],
        'IR-1'     => ['label' => 'IR-1 Sociedades anual',      'cadence' => 'annual',  'due_day' => 60, 'priority' => 9],
        'IR-2'     => ['label' => 'IR-2 Sociedades balance',    'cadence' => 'annual',  'due_day' => 120,'priority' => 10],
        'IR-4'     => ['label' => 'IR-4 Otras retenciones',     'cadence' => 'annual',  'due_day' => 90, 'priority' => 11],
    ];
}

function getObligationLabel($type) {
    return getObligationTypes()[$type]['label'] ?? $type;
}

/**
 * Returns required obligation types for a client according to its profile.
 */
function getObligationsForProfile($regime, $businessType, $employeeCount, $operationType) {
    $obligations = [];

    if ($regime === 'ordinario') {
        $obligations[] = 'IT-1';
        $obligations[] = '606';
        $obligations[] = '607';
        $obligations[] = '608';
        $obligations[] = 'IR-17';

        if ((int)$employeeCount > 0) {
            $obligations[] = 'IR-3';
            $obligations[] = 'TSS';
        }

        if ($businessType === 'juridica') {
            $obligations[] = 'IR-2';
            $obligations[] = 'ANTICIPO';
        } else {
            $obligations[] = 'IR-1';
        }
    } elseif ($regime === 'rst') {
        // RST personas fisicas: declaracion anual simplificada
        $obligations[] = 'IR-1';
        if ((int)$employeeCount > 0) {
            $obligations[] = 'TSS';
            $obligations[] = 'IR-3';
        }
    } elseif ($regime === 'simplificado_compras' || $regime === 'simplificado_ingresos') {
        $obligations[] = 'IR-1';
        $obligations[] = '606';
        if ((int)$employeeCount > 0) {
            $obligations[] = 'TSS';
        }
    } elseif ($regime === 'exonerado') {
        $obligations[] = 'IR-17';
        if ((int)$employeeCount > 0) {
            $obligations[] = 'TSS';
            $obligations[] = 'IR-3';
        }
    }

    return array_unique($obligations);
}

/**
 * Calculate the due date for a given obligation type, given a period (YYYY-MM for monthly, YYYY for annual).
 */
function calculateObligationDueDate($type, $period, $fiscalYearClose = '12-31') {
    $cfg = getObligationTypes()[$type] ?? null;
    if (!$cfg) return null;

    if ($cfg['cadence'] === 'monthly') {
        // period is YYYY-MM. Due date is day N of NEXT month
        $dt = strtotime($period . '-01 +1 month');
        $year = date('Y', $dt);
        $month = date('m', $dt);
        $day = str_pad((string)$cfg['due_day'], 2, '0', STR_PAD_LEFT);
        return "$year-$month-$day";
    }

    if ($cfg['cadence'] === 'annual') {
        // period is YYYY. Due date is N days after fiscal year close
        $year = (int)$period;
        // fiscalYearClose is MM-DD
        $closeDate = strtotime($year . '-' . $fiscalYearClose);
        if (!$closeDate) {
            $closeDate = strtotime($year . '-12-31');
        }
        $due = strtotime("+{$cfg['due_day']} days", $closeDate);
        return date('Y-m-d', $due);
    }

    return null;
}

/**
 * Generate (or update) tax obligations for a client for the upcoming N periods.
 * Idempotent: uses UNIQUE constraint to avoid duplicates.
 */
function generateObligationsForClient($clientId, $monthsAhead = 6) {
    global $pdo;

    $stmt = $pdo->prepare("SELECT tax_regime, business_type, employee_count, operation_type, fiscal_year_close, client_status FROM users WHERE id = ?");
    $stmt->execute([$clientId]);
    $u = $stmt->fetch();
    if (!$u || ($u['client_status'] ?? 'activo') === 'inactivo') return 0;

    $regime = $u['tax_regime'] ?? 'ordinario';
    $businessType = $u['business_type'] ?? 'fisica';
    $employeeCount = (int)($u['employee_count'] ?? 0);
    $operationType = $u['operation_type'] ?? 'servicios';
    $fiscalClose = $u['fiscal_year_close'] ?? '12-31';

    $required = getObligationsForProfile($regime, $businessType, $employeeCount, $operationType);
    $types = getObligationTypes();

    $insertStmt = $pdo->prepare("
        INSERT IGNORE INTO tax_obligations
        (client_id, obligation_type, period, due_date, status, auto_generated, created_at)
        VALUES (?, ?, ?, ?, 'pendiente', 1, NOW())
    ");

    $count = 0;

    // Current period (this month)
    $currentPeriod = date('Y-m');

    // Generate monthly: from current month to monthsAhead
    foreach ($required as $type) {
        $cfg = $types[$type] ?? null;
        if (!$cfg) continue;

        if ($cfg['cadence'] === 'monthly') {
            for ($i = 0; $i < $monthsAhead; $i++) {
                $period = date('Y-m', strtotime("$currentPeriod-01 +$i month"));
                $due = calculateObligationDueDate($type, $period, $fiscalClose);
                if ($due) {
                    $insertStmt->execute([$clientId, $type, $period, $due]);
                    if ($insertStmt->rowCount() > 0) $count++;
                }
            }
        } elseif ($cfg['cadence'] === 'annual') {
            // current year + next year
            $thisYear = date('Y');
            foreach ([$thisYear, (int)$thisYear + 1] as $yr) {
                $due = calculateObligationDueDate($type, (string)$yr, $fiscalClose);
                if ($due) {
                    $insertStmt->execute([$clientId, $type, (string)$yr, $due]);
                    if ($insertStmt->rowCount() > 0) $count++;
                }
            }
        }
    }

    // Mark overdue
    $pdo->prepare("UPDATE tax_obligations SET status = 'vencido' WHERE client_id = ? AND status = 'pendiente' AND due_date < CURDATE()")->execute([$clientId]);

    return $count;
}

function getObligationStatusBadge($status, $dueDate = null) {
    if ($status === 'completado') return '<span class="badge-dot badge-green">Completado</span>';
    if ($status === 'no_aplica') return '<span class="badge-dot badge-slate">No aplica</span>';

    // pendiente or vencido
    if ($dueDate) {
        $days = (int)((strtotime($dueDate) - strtotime(date('Y-m-d'))) / 86400);
        if ($days < 0) return '<span class="badge-dot badge-red">Vencida</span>';
        if ($days <= 7) return '<span class="badge-dot badge-amber">Vence en ' . $days . ' d</span>';
        return '<span class="badge-dot badge-blue">Pendiente</span>';
    }
    return '<span class="badge-dot badge-blue">Pendiente</span>';
}

function formatPeriod($period) {
    $months = ['Ene','Feb','Mar','Abr','May','Jun','Jul','Ago','Sep','Oct','Nov','Dic'];
    if (preg_match('/^(\d{4})-(\d{2})$/', $period, $m)) {
        return $months[(int)$m[2] - 1] . ' ' . $m[1];
    }
    if (preg_match('/^\d{4}$/', $period)) {
        return 'Anual ' . $period;
    }
    return $period;
}

function slugify($value) {
    $value = trim((string) $value);
    $converted = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);
    if ($converted !== false) {
        $value = $converted;
    }

    $value = strtolower((string) $value);
    $value = preg_replace('/[^a-z0-9]+/', '_', $value);
    return trim((string) $value, '_');
}

function getRoles($forceRefresh = false) {
    global $pdo;
    static $cache = null;

    if ($forceRefresh || $cache === null) {
        $cache = [];

        try {
            $rows = $pdo->query("SELECT * FROM roles ORDER BY access_level DESC, name ASC")->fetchAll();
            foreach ($rows as $row) {
                $cache[$row['slug']] = $row;
            }
        } catch (PDOException $e) {
            $cache = [
                'admin' => ['name' => 'Administrador', 'slug' => 'admin', 'access_level' => 'admin'],
                'client' => ['name' => 'Cliente', 'slug' => 'client', 'access_level' => 'client'],
            ];
        }
    }

    return $cache;
}

function getRole($slug) {
    $roles = getRoles();
    return $roles[$slug] ?? null;
}

function getRoleName($slug) {
    $role = getRole($slug);
    if ($role) {
        return $role['name'];
    }

    return $slug === 'admin' ? 'Administrador' : ucfirst(str_replace('_', ' ', (string) $slug));
}

function getRoleAccessLevel($slug) {
    $role = getRole($slug);
    if ($role) {
        return $role['access_level'];
    }

    return $slug === 'admin' ? 'admin' : 'client';
}

function canAccessArea($roleSlug, $requiredArea) {
    return getRoleAccessLevel($roleSlug) === $requiredArea;
}

function getDashboardForRole($roleSlug) {
    return getRoleAccessLevel($roleSlug) === 'admin' ? 'admin_dashboard.php' : 'client_dashboard.php';
}

function getWhatsAppTemplate($key, $default = '') {
    return getSetting($key, $default);
}

function renderWhatsAppTemplate($template, $variables = []) {
    $replacements = [];

    foreach ($variables as $key => $value) {
        $replacements['{{' . $key . '}}'] = (string) $value;
    }

    return strtr((string) $template, $replacements);
}

function normalizePhoneForWhatsApp($phone) {
    return preg_replace('/[^0-9]/', '', (string) $phone);
}

function getUsersByAccessLevelQuery($accessLevel, $select = 'u.*', $orderBy = 'u.created_at DESC') {
    $allowedLevels = ['admin', 'client'];
    $normalizedLevel = in_array($accessLevel, $allowedLevels, true) ? $accessLevel : 'client';

    return "
        SELECT {$select}
        FROM users u
        LEFT JOIN roles r ON r.slug = u.role
        WHERE COALESCE(r.access_level, CASE WHEN u.role = 'admin' THEN 'admin' ELSE 'client' END) = '{$normalizedLevel}'
        ORDER BY {$orderBy}
    ";
}

// Helper functions for statuses
function getStatusBadge($status) {
    $badges = [
        'pendiente'   => '<span class="badge-dot badge-red">Pendiente</span>',
        'en_proceso'  => '<span class="badge-dot badge-amber">En proceso</span>',
        'en_revision' => '<span class="badge-dot badge-blue">En revision</span>',
        'presentado'  => '<span class="badge-dot badge-green">Presentado</span>',
        'completado'  => '<span class="badge-dot badge-green">Completado</span>',
    ];

    return $badges[$status] ?? '<span class="badge-dot badge-slate">Desconocido</span>';
}

// Require login helper
function requireAuth($role = null) {
    if (!isset($_SESSION['user_id'])) {
        header('Location: login.php');
        exit;
    }

    if ($role && !canAccessArea($_SESSION['role'], $role)) {
        die('No tienes permisos para acceder a esta pagina.');
    }
}

// Load all company settings as associative array
function getSettings() {
    global $pdo;
    $rows = $pdo->query("SELECT setting_key, setting_value FROM settings")->fetchAll();
    $settings = [];
    foreach ($rows as $row) {
        $settings[$row['setting_key']] = $row['setting_value'];
    }
    return $settings;
}

// Get a single setting with default fallback
function getSetting($key, $default = '') {
    global $pdo;
    $stmt = $pdo->prepare("SELECT setting_value FROM settings WHERE setting_key = ?");
    $stmt->execute([$key]);
    $row = $stmt->fetch();
    return $row ? $row['setting_value'] : $default;
}
?>
