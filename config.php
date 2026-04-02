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
        'pendiente' => '<span class="inline-flex items-center rounded-full bg-red-100 px-2 flex-shrink-0 py-0.5 text-xs font-medium text-red-700">Pendiente</span>',
        'en_proceso' => '<span class="inline-flex items-center rounded-full bg-yellow-100 px-2 flex-shrink-0 py-0.5 text-xs font-medium text-yellow-700">En proceso</span>',
        'en_revision' => '<span class="inline-flex items-center rounded-full bg-blue-100 px-2 flex-shrink-0 py-0.5 text-xs font-medium text-blue-700">En revision</span>',
        'presentado' => '<span class="inline-flex items-center rounded-full bg-green-100 px-2 flex-shrink-0 py-0.5 text-xs font-medium text-green-700">Presentado</span>',
        'completado' => '<span class="inline-flex items-center rounded-full bg-green-100 px-2 flex-shrink-0 py-0.5 text-xs font-medium text-green-700">Completado</span>',
    ];

    return $badges[$status] ?? '<span class="inline-flex items-center rounded-md bg-gray-100 px-2 py-1 text-xs font-medium text-gray-600">Desconocido</span>';
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
