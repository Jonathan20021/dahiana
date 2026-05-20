<?php
// lib/ai_invoice.php
//
// Vision-based invoice extraction for the Dominican Republic tax filings
// (606 Compras, 607 Ventas, IT-1 ITBIS). Uses the OpenAI Chat Completions
// API with vision-capable models (gpt-4o family by default) and a strict
// JSON schema so the model only ever returns structured data.
//
// Entry points:
//   - bootstrapAiInvoiceSchema()    Creates tables on demand (idempotent).
//   - aiExtractInvoiceFromFile()    Calls OpenAI Vision on a single file.
//   - aiProcessUpload($uploadId)    Wraps extraction + DB persistence.
//   - aiApproveExtraction($id)      Pushes an extraction into tax_filing_rows.
//   - recalcTaxFilingTotals()       Recomputes 606/607/IT-1 aggregates.

if (!defined('AI_INVOICE_LIB_LOADED')) {
    define('AI_INVOICE_LIB_LOADED', true);
}

// --------------------------------------------------------------------------
// Schema bootstrap
// --------------------------------------------------------------------------
function bootstrapAiInvoiceSchema() {
    global $pdo;
    static $done = false;
    if ($done) return;
    $done = true;

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS invoice_uploads (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                uploaded_by INT DEFAULT NULL,
                filename VARCHAR(255) NOT NULL,
                original_name VARCHAR(255) NOT NULL,
                mime_type VARCHAR(100) DEFAULT NULL,
                file_size BIGINT DEFAULT 0,
                sha256 CHAR(64) DEFAULT NULL,
                doc_type VARCHAR(20) DEFAULT 'auto',
                period VARCHAR(10) DEFAULT NULL,
                status VARCHAR(20) NOT NULL DEFAULT 'uploaded',
                ai_model VARCHAR(80) DEFAULT NULL,
                ai_tokens INT DEFAULT 0,
                ai_cost_cents INT DEFAULT 0,
                error_message TEXT DEFAULT NULL,
                processed_at TIMESTAMP NULL DEFAULT NULL,
                approved_at TIMESTAMP NULL DEFAULT NULL,
                approved_by INT DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_client (client_id),
                INDEX idx_status (status),
                INDEX idx_period (period),
                INDEX idx_sha (sha256)
            )
        ");

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS invoice_extractions (
                id INT AUTO_INCREMENT PRIMARY KEY,
                upload_id INT NOT NULL,
                client_id INT NOT NULL,
                doc_type VARCHAR(20) DEFAULT 'compra',
                period VARCHAR(10) DEFAULT NULL,
                date_doc DATE DEFAULT NULL,
                date_payment DATE DEFAULT NULL,
                rnc VARCHAR(30) DEFAULT NULL,
                counterparty_name VARCHAR(255) DEFAULT NULL,
                ncf VARCHAR(30) DEFAULT NULL,
                ncf_modified VARCHAR(30) DEFAULT NULL,
                ncf_type VARCHAR(10) DEFAULT NULL,
                concept VARCHAR(500) DEFAULT NULL,
                expense_category VARCHAR(10) DEFAULT NULL,
                payment_method VARCHAR(10) DEFAULT NULL,
                currency VARCHAR(10) DEFAULT 'DOP',
                subtotal DECIMAL(15,2) DEFAULT 0,
                itbis DECIMAL(15,2) DEFAULT 0,
                propina_legal DECIMAL(15,2) DEFAULT 0,
                transporte DECIMAL(15,2) DEFAULT 0,
                isr_retention DECIMAL(15,2) DEFAULT 0,
                itbis_retention DECIMAL(15,2) DEFAULT 0,
                other_taxes DECIMAL(15,2) DEFAULT 0,
                total DECIMAL(15,2) DEFAULT 0,
                confidence DECIMAL(4,2) DEFAULT 0,
                ai_notes TEXT DEFAULT NULL,
                raw_json LONGTEXT DEFAULT NULL,
                filing_row_id INT DEFAULT NULL,
                approved TINYINT(1) DEFAULT 0,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_upload (upload_id),
                INDEX idx_client (client_id),
                INDEX idx_period (period),
                INDEX idx_doctype (doc_type),
                INDEX idx_approved (approved)
            )
        ");

        // Telegram bot schema
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS telegram_links (
                id INT AUTO_INCREMENT PRIMARY KEY,
                client_id INT NOT NULL,
                chat_id BIGINT NOT NULL,
                username VARCHAR(100) DEFAULT NULL,
                first_name VARCHAR(120) DEFAULT NULL,
                last_name VARCHAR(120) DEFAULT NULL,
                phone VARCHAR(30) DEFAULT NULL,
                active TINYINT(1) DEFAULT 1,
                last_seen_at TIMESTAMP NULL DEFAULT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY uniq_chat (chat_id),
                INDEX idx_client (client_id)
            )
        ");
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS telegram_state (
                chat_id BIGINT PRIMARY KEY,
                state VARCHAR(40) DEFAULT 'idle',
                data_json TEXT DEFAULT NULL,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            )
        ");

        // Add telegram_link_code + onboarding_completed_at to users (idempotent)
        $userCols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'")->fetchAll(PDO::FETCH_COLUMN);
        $userCols = array_map('strtolower', $userCols);
        if (!in_array('telegram_link_code', $userCols, true)) {
            try { $pdo->exec("ALTER TABLE users ADD COLUMN telegram_link_code VARCHAR(20) DEFAULT NULL, ADD INDEX idx_users_telegram_link (telegram_link_code)"); } catch (PDOException $e) {}
        }
        if (!in_array('onboarding_completed_at', $userCols, true)) {
            try { $pdo->exec("ALTER TABLE users ADD COLUMN onboarding_completed_at TIMESTAMP NULL DEFAULT NULL"); } catch (PDOException $e) {}
        }

        // Telegram source on uploads
        $upCols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='invoice_uploads'")->fetchAll(PDO::FETCH_COLUMN);
        $upCols = array_map('strtolower', $upCols);
        if (!in_array('source', $upCols, true)) {
            try { $pdo->exec("ALTER TABLE invoice_uploads ADD COLUMN source VARCHAR(20) DEFAULT 'web'"); } catch (PDOException $e) {}
        }

        // Seed AI + Telegram settings (idempotent)
        $defaults = [
            'openai_enabled'  => '1',
            'openai_api_key'  => '', // configure desde admin_settings.php
            'openai_model'    => 'gpt-4o',
            'openai_max_size_mb' => '12',
            'openai_auto_process' => '1',
            'openai_auto_approve_threshold' => '0',  // 0 = nunca auto-aprobar. 0.95 = aprobar si confianza >= 95%
            'notify_invoice_approved' => '1',         // emails al cliente al aprobar
            'openai_consensus_enabled' => '1',        // valida con segundo modelo en paralelo
            'openai_secondary_model'   => 'gpt-4o-mini', // modelo de validacion cruzada
            'telegram_enabled' => '0',
            'telegram_bot_token' => '',
            'telegram_bot_username' => '',
            'telegram_webhook_secret' => bin2hex(random_bytes(8)),
        ];
        $seed = $pdo->prepare("INSERT IGNORE INTO settings (setting_key, setting_value) VALUES (?, ?)");
        foreach ($defaults as $k => $v) $seed->execute([$k, $v]);

        // Ensure 'IT-1' is allowed in tax_filings (uses VARCHAR(10), already fits).
        // Ensure 608 filing rows can carry the optional fields we already use.
    } catch (PDOException $e) {
        // swallow
    }
}

bootstrapAiInvoiceSchema();

// --------------------------------------------------------------------------
// Catalog helpers
// --------------------------------------------------------------------------
function aiExpenseCategories() {
    return [
        '01' => 'Gastos de Personal',
        '02' => 'Gastos por Trabajos, Suministros y Servicios',
        '03' => 'Arrendamientos',
        '04' => 'Gastos de Activos Fijos',
        '05' => 'Gastos de Representacion',
        '06' => 'Otras Deducciones Admitidas',
        '07' => 'Gastos Financieros',
        '08' => 'Gastos Extraordinarios',
        '09' => 'Compras y Gastos del Periodo',
        '10' => 'Adquisiciones de Activos',
        '11' => 'Gastos de Seguros',
    ];
}

function aiPaymentMethods() {
    return [
        '01' => 'Efectivo',
        '02' => 'Cheques / Transferencias / Deposito',
        '03' => 'Tarjeta Credito / Debito',
        '04' => 'Compra a Credito',
        '05' => 'Permuta',
        '06' => 'Nota de Credito',
        '07' => 'Mixto',
    ];
}

// --------------------------------------------------------------------------
// Validation helpers (DGII patterns)
// --------------------------------------------------------------------------

/**
 * Valida un RNC dominicano (cedula 11 digitos o RNC 9 digitos).
 * Acepta y normaliza con/sin guiones.
 */
function aiValidateRnc($rnc) {
    $digits = preg_replace('/\D+/', '', (string)$rnc);
    if (strlen($digits) === 9 || strlen($digits) === 11) return $digits;
    return '';
}

/**
 * Valida un NCF segun los formatos vigentes de la DGII.
 * Acepta:
 *  - 11 chars: B0X + 8 digitos (B01,B02,...,B17)
 *  - 13 chars: E3X + 10 digitos (e-CF: E31, E32, E33, E34, E41-47)
 *
 * Devuelve el NCF normalizado en mayusculas sin espacios, o '' si invalido.
 */
function aiValidateNcf($ncf) {
    $clean = strtoupper(preg_replace('/\s+/', '', (string)$ncf));
    if (preg_match('/^B0[1-9]\d{8}$/', $clean) || preg_match('/^B1[0-7]\d{8}$/', $clean)) return $clean;
    if (preg_match('/^E[3-4]\d\d{10}$/', $clean)) return $clean;
    return '';
}

/**
 * Detecta y devuelve el tipo de NCF a partir del NCF (los 3 primeros chars).
 */
function aiNcfType($ncf) {
    $clean = strtoupper(preg_replace('/\s+/', '', (string)$ncf));
    if (strlen($clean) >= 3) return substr($clean, 0, 3);
    return '';
}

/**
 * Rate limit per chat_id / per client.
 * Usa la tabla telegram_state como cache simple (data_json).
 * Devuelve true si la accion esta permitida, false si excede.
 */
function aiCheckRateLimit($key, $maxPerHour = 30) {
    global $pdo;
    try {
        $stmt = $pdo->prepare("SELECT data_json FROM telegram_state WHERE chat_id = ?");
        // Reutilizamos telegram_state -- $key debe ser un int (chat_id) si es Telegram, o un client_id*-1 para diferenciar
        $stmt->execute([(int)$key]);
        $row = $stmt->fetch();
        $bucket = $row && !empty($row['data_json']) ? (json_decode($row['data_json'], true) ?: []) : [];
        $now = time();
        $bucket['rate'] = $bucket['rate'] ?? [];
        // Drop entries older than 1 hour
        $bucket['rate'] = array_values(array_filter($bucket['rate'], fn($ts) => $now - $ts < 3600));
        if (count($bucket['rate']) >= $maxPerHour) {
            return false;
        }
        $bucket['rate'][] = $now;
        $stmt = $pdo->prepare("INSERT INTO telegram_state (chat_id, state, data_json) VALUES (?, 'idle', ?) ON DUPLICATE KEY UPDATE data_json = VALUES(data_json), updated_at = NOW()");
        $stmt->execute([(int)$key, json_encode($bucket, JSON_UNESCAPED_UNICODE)]);
        return true;
    } catch (PDOException $e) {
        return true; // fail-open en caso de error de DB
    }
}

// --------------------------------------------------------------------------
// File helpers
// --------------------------------------------------------------------------
function aiUploadsDir() {
    $dir = __DIR__ . '/../uploads/invoices';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

function aiAcceptedMimes() {
    return ['image/jpeg','image/png','image/webp','image/gif','image/heic','image/heif','application/pdf'];
}

function aiIsImageMime($mime) {
    return strpos((string)$mime, 'image/') === 0;
}

/**
 * Encode a file as base64 data URL for the OpenAI Chat Completions API.
 * Handles images directly. PDFs are NOT supported by the chat vision endpoint,
 * so we surface an error early for those.
 */
function aiFileToDataUrl($absPath, $mime) {
    if (!is_file($absPath)) {
        return ['error' => 'Archivo no encontrado.'];
    }
    $bytes = file_get_contents($absPath);
    if ($bytes === false) {
        return ['error' => 'No se pudo leer el archivo.'];
    }
    $b64 = base64_encode($bytes);
    return ['data_url' => "data:{$mime};base64,{$b64}"];
}

// --------------------------------------------------------------------------
// OpenAI request
// --------------------------------------------------------------------------
function aiOpenAIConfig() {
    return [
        'enabled' => getSetting('openai_enabled', '1') === '1',
        'api_key' => trim(getSetting('openai_api_key', '')),
        'model'   => trim(getSetting('openai_model', 'gpt-4o')) ?: 'gpt-4o',
    ];
}

function aiSystemPrompt() {
    $cats = aiExpenseCategories();
    $pays = aiPaymentMethods();
    $catLines = [];
    foreach ($cats as $code => $label) $catLines[] = "  - {$code}: {$label}";
    $payLines = [];
    foreach ($pays as $code => $label) $payLines[] = "  - {$code}: {$label}";

    return implode("\n", [
        "Eres un asistente fiscal experto en la Republica Dominicana (DGII).",
        "Recibiras la foto de un comprobante fiscal (factura, recibo, conduce, nota de credito, NCF).",
        "Tu trabajo es EXTRAER LITERALMENTE lo que aparece en el documento. NO INVENTAR.",
        "",
        "=== REGLA DE ORO ===",
        "Si NO puedes leer un campo con certeza, devuelve string vacio (\"\") o 0 numerico.",
        "ES PREFERIBLE un campo vacio que un campo inventado.",
        "La consultora puede llenar datos faltantes en segundos, pero corregir datos inventados toma horas y genera multas DGII.",
        "Nunca calcules valores que no esten escritos en la factura. Solo extrae lo que ves.",
        "Si el ITBIS aparece como '0.00' explicitamente, devuelve 0.00 (es un comprobante exento).",
        "Si el ITBIS NO aparece en la factura, devuelve 0 y baja la confianza.",
        "",
        "=== REGLAS DE EXTRACCION ===",
        "1. NCF (Numero de Comprobante Fiscal): exactamente 11 caracteres alfanumericos. Empieza con una letra y dos digitos (B01, B02, B03, B04, B11, B13, B14, B15, B16, B17) o con E31/E32/E33/E34/E41/E43/E44/E45/E46/E47 para e-CF. Si lees algo como 'B0100000123' o 'E310000164476', son NCFs validos. Si no encuentras NCF formal pero ves un numero de factura, ponlo de todas formas (la consultora puede corregir).",
        "2. RNC: 9 digitos (personas juridicas) o 11 digitos (cedula de personas fisicas). SIEMPRE devuelvelo sin guiones ni espacios (ej '131611176', '00112345678').",
        "3. ITBIS: la tasa estandar en RD es 18%. En la mayoria de facturas viene desglosado. Si no, calculas total / 1.18 = subtotal e itbis = total - subtotal. Productos exentos: ITBIS=0.",
        "4. Propina legal (10% de ley): exclusiva de restaurantes y bares. NUNCA en supermercados, gasolina, ferreterias, farmacias.",
        "5. Transporte: solo si aparece como linea separada en la factura.",
        "6. Subtotal = base imponible (sin ITBIS, sin propina, sin transporte).",
        "7. Total = subtotal + ITBIS + propina + transporte + otros impuestos.",
        "8. doc_type:",
        "   - 'compra' si el RECEPTOR/CLIENTE de la factura es el negocio del usuario (gasto). Va al 606.",
        "   - 'venta' si el EMISOR de la factura es el negocio del usuario (ingreso). Va al 607.",
        "   - Si tienes contexto del cliente (su RNC), comparalo: si el RNC del cliente aparece como 'Cliente/Razon Social' del comprobante, es compra; si aparece como emisor, es venta.",
        "   - Si dudas, elige 'compra' (caso mas comun).",
        "9. Fechas en formato ISO YYYY-MM-DD. Si la fecha es ambigua (ej '03/05/25'), asume formato DD/MM/AA y normaliza.",
        "10. Montos en formato decimal con punto. Sin signo de moneda. Sin separador de miles.",
        "11. counterparty_name: el nombre de la OTRA parte (no del cliente). En compras es el proveedor, en ventas es el cliente final.",
        "",
        "=== CATEGORIAS DE GASTO (expense_category, solo para compras) ===",
        implode("\n", $catLines),
        "Heuristicas:",
        "- Combustible / gasolina -> 02",
        "- Restaurantes / comida (gasto de representacion) -> 05",
        "- Materiales de oficina, papeleria -> 02",
        "- Alquiler de local -> 03",
        "- Telecomunicaciones / internet -> 02",
        "- Servicios contables / legales -> 06",
        "- Equipos, computadoras, vehiculos -> 04 o 10",
        "- Suministros generales del periodo -> 09",
        "- Polizas / seguros -> 11",
        "- Sueldos / honorarios al personal -> 01",
        "",
        "=== FORMAS DE PAGO (payment_method) ===",
        implode("\n", $payLines),
        "Heuristicas:",
        "- Si dice 'EFECTIVO' o 'CASH' -> 01",
        "- Si dice 'TARJETA' o se ve un recibo de POS -> 03",
        "- Si dice 'TRANSFERENCIA' o 'CHEQUE' -> 02",
        "- Si dice 'CREDITO' o 'A PAGAR' -> 04",
        "- Por defecto, si no esta claro -> 03 (la forma mas comun)",
        "",
        "=== TIPOS DE NCF (ncf_type) ===",
        "- B01: Credito fiscal (genera derecho a deducir ITBIS)",
        "- B02: Consumidor final (NO genera credito fiscal)",
        "- B03: Nota de debito",
        "- B04: Nota de credito",
        "- B11: Comprobantes para regimenes especiales",
        "- B13: Comprobantes para gastos menores",
        "- B14: Regimenes especiales",
        "- B15: Gubernamentales",
        "- B16: Exportaciones",
        "- E31, E32, ..., E47: comprobantes electronicos (e-CF)",
        "",
        "=== VALIDACION DE COHERENCIA ===",
        "Antes de devolver el JSON, valida internamente:",
        "- Si subtotal + itbis + propina + transporte ~= total (tolerancia ±2.00), perfecto.",
        "- Si itbis > 0 y |itbis - subtotal*0.18| > 0.10 * itbis, recalcula subtotal = total - itbis - propina - transporte.",
        "- Si solo tienes el total y ves '18%' en la factura: subtotal = total/1.18, itbis = total - subtotal.",
        "",
        "=== CALIDAD DE LA EXTRACCION ===",
        "- confidence: 1.0 si la factura es nitida y todos los campos son legibles. 0.7-0.9 si algunos campos son ambiguos. 0.4-0.7 si hay borrosidad o angulos malos. <0.4 si la imagen es ilegible.",
        "- Si NO puedes leer un campo dejalo vacio (string vacia o 0 numerico) y BAJA la confianza. NO inventes.",
        "- En notes pon cualquier observacion: 'NCF parcialmente borroso', 'Total ilegible, calculado desde subtotal+itbis', etc.",
        "",
        "=== EJEMPLOS DE EXTRACCION ===",
        "Ejemplo 1 (factura combustible, B02):",
        '{"doc_type":"compra","date_doc":"2026-03-15","date_payment":"","rnc":"131611176","counterparty_name":"ESTACION SHELL PUNTA CANA","ncf":"B0200001234","ncf_modified":"","ncf_type":"B02","concept":"Combustible diesel","expense_category":"02","payment_method":"03","currency":"DOP","subtotal":1500.00,"itbis":270.00,"propina_legal":0.00,"transporte":0.00,"isr_retention":0.00,"itbis_retention":0.00,"other_taxes":0.00,"total":1770.00,"confidence":0.95,"notes":""}',
        "",
        "Ejemplo 2 (restaurante con propina, B02):",
        '{"doc_type":"compra","date_doc":"2026-03-08","date_payment":"","rnc":"130221642","counterparty_name":"PARADOR VISTA DEL MAR","ncf":"B0200271365","ncf_modified":"","ncf_type":"B02","concept":"Almuerzo equipo gerencial","expense_category":"05","payment_method":"03","currency":"DOP","subtotal":1599.15,"itbis":266.25,"propina_legal":159.92,"transporte":0.00,"isr_retention":0.00,"itbis_retention":0.00,"other_taxes":0.00,"total":2025.32,"confidence":0.92,"notes":"Propina 10% incluida"}',
        "",
        "Ejemplo 3 (venta empresa, e-CF):",
        '{"doc_type":"venta","date_doc":"2026-02-15","date_payment":"","rnc":"131907768","counterparty_name":"GREEN STAR PARTNERS","ncf":"E310000164476","ncf_modified":"","ncf_type":"E31","concept":"Venta mercaderia con transporte","expense_category":"","payment_method":"02","currency":"DOP","subtotal":316000.00,"itbis":56880.00,"propina_legal":0.00,"transporte":18000.00,"isr_retention":0.00,"itbis_retention":0.00,"other_taxes":0.00,"total":390880.00,"confidence":0.97,"notes":""}',
        "",
        "DEVUELVE UNICAMENTE el JSON estricto. Sin explicaciones. Sin markdown. Sin texto extra.",
    ]);
}

function aiInvoiceJsonSchema() {
    return [
        'type' => 'object',
        'additionalProperties' => false,
        'properties' => [
            'doc_type'         => ['type' => 'string', 'enum' => ['compra', 'venta'], 'description' => 'compra=606, venta=607'],
            'date_doc'         => ['type' => 'string', 'description' => 'Fecha del comprobante, formato YYYY-MM-DD'],
            'date_payment'     => ['type' => 'string', 'description' => 'Fecha de pago (opcional), YYYY-MM-DD o vacio'],
            'rnc'              => ['type' => 'string', 'description' => 'RNC o cedula de la contraparte (proveedor para compras, cliente para ventas)'],
            'counterparty_name'=> ['type' => 'string', 'description' => 'Nombre/razon social de la contraparte'],
            'ncf'              => ['type' => 'string', 'description' => 'NCF principal (11 chars)'],
            'ncf_modified'     => ['type' => 'string', 'description' => 'NCF modificado, si aplica'],
            'ncf_type'         => ['type' => 'string', 'description' => 'Tipo de NCF (B01, B02, E31, etc.)'],
            'concept'          => ['type' => 'string', 'description' => 'Descripcion corta del producto/servicio'],
            'expense_category' => ['type' => 'string', 'description' => 'Codigo 01-11 SOLO si es compra'],
            'payment_method'   => ['type' => 'string', 'description' => 'Codigo 01-07'],
            'currency'         => ['type' => 'string', 'description' => 'DOP o USD'],
            'subtotal'         => ['type' => 'number', 'description' => 'Monto sin ITBIS ni propina'],
            'itbis'            => ['type' => 'number', 'description' => 'ITBIS facturado'],
            'propina_legal'    => ['type' => 'number', 'description' => '10% de ley'],
            'transporte'       => ['type' => 'number', 'description' => 'Cargo de transporte separado'],
            'isr_retention'    => ['type' => 'number', 'description' => 'ISR retenido'],
            'itbis_retention'  => ['type' => 'number', 'description' => 'ITBIS retenido'],
            'other_taxes'      => ['type' => 'number', 'description' => 'Otros impuestos'],
            'total'            => ['type' => 'number', 'description' => 'Total final'],
            'confidence'       => ['type' => 'number', 'description' => 'Confianza 0.0 a 1.0'],
            'notes'            => ['type' => 'string', 'description' => 'Cualquier observacion relevante'],
        ],
        'required' => [
            'doc_type','date_doc','date_payment','rnc','counterparty_name',
            'ncf','ncf_modified','ncf_type','concept','expense_category',
            'payment_method','currency',
            'subtotal','itbis','propina_legal','transporte',
            'isr_retention','itbis_retention','other_taxes','total',
            'confidence','notes',
        ],
    ];
}

/**
 * Normalizes / sanitizes / cross-checks the AI extraction.
 * Returns the cleaned data array (with `confidence` possibly adjusted) plus
 * a list of soft warnings (in $data['_warnings']).
 */
function aiNormalizeExtraction(array $data) {
    $warnings = [];

    // RNC: validar y normalizar
    $rncRaw = (string)($data['rnc'] ?? '');
    $rnc = aiValidateRnc($rncRaw);
    if ($rnc === '' && $rncRaw !== '') {
        $warnings[] = 'RNC con formato invalido (' . preg_replace('/\D+/', '', $rncRaw) . ').';
        $rnc = preg_replace('/\D+/', '', $rncRaw);
    }
    $data['rnc'] = $rnc;

    // NCF: validar y normalizar (B0X + 8d, B1X + 8d, E3X/E4X + 10d)
    $ncfRaw = (string)($data['ncf'] ?? '');
    $ncfValidated = aiValidateNcf($ncfRaw);
    $ncfClean = strtoupper(preg_replace('/\s+/', '', $ncfRaw));
    if ($ncfClean !== '' && $ncfValidated === '') {
        $warnings[] = 'NCF con formato no estandar (' . $ncfClean . ').';
    }
    $data['ncf'] = $ncfValidated ?: $ncfClean;
    $data['ncf_modified'] = strtoupper(preg_replace('/\s+/', '', (string)($data['ncf_modified'] ?? '')));

    // ncf_type: si no viene o no concuerda con el NCF, lo derivamos del NCF
    $ncfTypeProvided = strtoupper(preg_replace('/\s+/', '', (string)($data['ncf_type'] ?? '')));
    $ncfTypeDerived = aiNcfType($data['ncf']);
    if ($ncfTypeDerived !== '' && $ncfTypeProvided !== $ncfTypeDerived) {
        $data['ncf_type'] = $ncfTypeDerived;
    } else {
        $data['ncf_type'] = $ncfTypeProvided;
    }

    // Force numeric
    $numKeys = ['subtotal','itbis','propina_legal','transporte','isr_retention','itbis_retention','other_taxes','total','confidence'];
    foreach ($numKeys as $k) {
        $data[$k] = round((float)($data[$k] ?? 0), 2);
    }

    // Coherencia: SOLO marcamos warnings. NO sobrescribimos valores.
    // La consultora prefiere ver lo que la IA realmente extrajo, no datos inventados.
    $reconstructed = round(($data['subtotal'] + $data['itbis'] + $data['propina_legal'] + $data['transporte']), 2);
    if ($data['total'] > 0 && abs($reconstructed - $data['total']) > 2.00) {
        $diff = round(abs($reconstructed - $data['total']), 2);
        $warnings[] = 'Inconsistencia: subtotal+ITBIS+propina+transporte (' . number_format($reconstructed, 2) . ') vs total (' . number_format($data['total'], 2) . '). Diferencia RD$ ' . number_format($diff, 2);
    }
    if ($data['total'] === 0.0 && ($data['subtotal'] > 0 || $data['itbis'] > 0)) {
        $warnings[] = 'Total no detectado; revisar manualmente.';
    }

    // Normalize doc_type
    if (!in_array($data['doc_type'] ?? '', ['compra','venta'], true)) $data['doc_type'] = 'compra';

    // Normalize date_doc to YYYY-MM-DD
    if (!empty($data['date_doc'])) {
        $t = strtotime((string)$data['date_doc']);
        if ($t) $data['date_doc'] = date('Y-m-d', $t);
    }
    if (!empty($data['date_payment'])) {
        $t = strtotime((string)$data['date_payment']);
        if ($t) $data['date_payment'] = date('Y-m-d', $t);
        else $data['date_payment'] = '';
    }

    // Currency uppercase
    $data['currency'] = strtoupper((string)($data['currency'] ?? 'DOP')) ?: 'DOP';

    // Confidence cap with warnings
    $data['confidence'] = max(0.0, min(1.0, $data['confidence']));
    if (!empty($warnings)) {
        $data['confidence'] = max(0.0, $data['confidence'] - 0.1 * min(3, count($warnings)));
    }

    $data['_warnings'] = $warnings;
    return $data;
}

/**
 * Sends one image to OpenAI Vision and returns structured invoice data.
 * Adds: retry on transient errors, normalization, coherence check.
 * Returns ['ok' => bool, 'data' => array|null, 'error' => string|null, 'tokens' => int]
 */
function aiExtractInvoiceFromFile($absPath, $mime, $clientHint = []) {
    $cfg = aiOpenAIConfig();
    if (!$cfg['enabled'])  return ['ok' => false, 'error' => 'IA deshabilitada en configuracion.'];
    if (!$cfg['api_key'])  return ['ok' => false, 'error' => 'OpenAI API key no configurada.'];
    if (!aiIsImageMime($mime)) {
        return ['ok' => false, 'error' => 'Formato no soportado. Sube una imagen JPG, PNG, WEBP o HEIC.'];
    }

    $enc = aiFileToDataUrl($absPath, $mime);
    if (isset($enc['error'])) return ['ok' => false, 'error' => $enc['error']];

    $hintLines = [];
    if (!empty($clientHint['business_name'])) $hintLines[] = "Negocio del cliente que sube la factura: " . $clientHint['business_name'];
    if (!empty($clientHint['rnc']))           $hintLines[] = "RNC del cliente (si aparece como receptor -> es compra; si aparece como emisor -> es venta): " . $clientHint['rnc'];
    if (!empty($clientHint['operation_type'])) $hintLines[] = "Tipo de operacion del cliente: " . $clientHint['operation_type'];
    if (!empty($clientHint['economic_activity'])) $hintLines[] = "Actividad economica: " . $clientHint['economic_activity'];
    $hintBlock = empty($hintLines) ? '' : "\n\nContexto del cliente:\n" . implode("\n", $hintLines);

    // Decision: usar consensus multi-modelo o single-model
    $consensusEnabled = getSetting('openai_consensus_enabled', '1') === '1';
    $secondaryModel = trim(getSetting('openai_secondary_model', 'gpt-4o-mini')) ?: 'gpt-4o-mini';

    if ($consensusEnabled && $secondaryModel && $secondaryModel !== $cfg['model']) {
        return aiExtractWithConsensus($enc['data_url'], $hintBlock, $cfg, $secondaryModel);
    }

    // Single-model path
    $payload = aiBuildPayload($cfg['model'], $enc['data_url'], $hintBlock);
    $r = aiCallOpenAI($payload, $cfg['api_key'], 2);
    if (!$r['ok']) return $r;
    $data = aiNormalizeExtraction($r['data']);
    return ['ok' => true, 'data' => $data, 'tokens' => $r['tokens'], 'raw' => $r['raw']];
}

/**
 * Construye el payload de la API para un modelo dado.
 */
function aiBuildPayload($model, $dataUrl, $hintBlock) {
    return [
        'model' => $model,
        'messages' => [
            ['role' => 'system', 'content' => aiSystemPrompt()],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => "Extrae LITERALMENTE los datos de esta factura para los formularios 606, 607 e IT-1 de la DGII. Si un campo NO aparece claramente en la imagen, dejalo vacio o 0. NO INVENTES valores. Devuelve solo el JSON estricto." . $hintBlock],
                ['type' => 'image_url', 'image_url' => ['url' => $dataUrl, 'detail' => 'high']],
            ]],
        ],
        'response_format' => [
            'type' => 'json_schema',
            'json_schema' => [
                'name'   => 'invoice_extraction',
                'strict' => true,
                'schema' => aiInvoiceJsonSchema(),
            ],
        ],
        'temperature' => 0.0,
        'max_tokens'  => 1500,
    ];
}

/**
 * Llamada bloqueante a OpenAI Chat Completions con retry.
 */
function aiCallOpenAI(array $payload, string $apiKey, int $maxAttempts = 2) {
    $lastError = '';
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 45,
            CURLOPT_CONNECTTIMEOUT => 20,
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) { $lastError = 'Red: ' . $err; continue; }
        $json = json_decode($resp, true);
        if (!is_array($json)) { $lastError = "HTTP {$http}: " . substr((string)$resp, 0, 200); continue; }
        if ($http >= 500 || $http === 429) { $lastError = 'OpenAI HTTP ' . $http; continue; }
        if ($http >= 400) {
            return ['ok' => false, 'error' => 'OpenAI: ' . ($json['error']['message'] ?? 'HTTP ' . $http)];
        }
        $content = $json['choices'][0]['message']['content'] ?? '';
        $tokens  = (int)($json['usage']['total_tokens'] ?? 0);
        $data = json_decode($content, true);
        if (!is_array($data)) { $lastError = 'JSON no parseable'; continue; }
        return ['ok' => true, 'data' => $data, 'tokens' => $tokens, 'raw' => $content];
    }
    return ['ok' => false, 'error' => $lastError ?: 'Error desconocido'];
}

/**
 * Llama a 2 modelos en paralelo (curl_multi) y construye consenso.
 * Solo conserva valores cuando ambos modelos coinciden; donde difieren, mantiene
 * el del modelo principal pero baja la confianza y agrega un warning explicito.
 */
function aiExtractWithConsensus($dataUrl, $hintBlock, $cfg, $secondaryModel) {
    $modelA = $cfg['model'];
    $modelB = $secondaryModel;

    $payloadA = aiBuildPayload($modelA, $dataUrl, $hintBlock);
    $payloadB = aiBuildPayload($modelB, $dataUrl, $hintBlock);

    $mh = curl_multi_init();
    $chA = curl_init('https://api.openai.com/v1/chat/completions');
    $chB = curl_init('https://api.openai.com/v1/chat/completions');
    $opts = function($ch, $payload) use ($cfg) {
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $cfg['api_key'],
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 50,
            CURLOPT_CONNECTTIMEOUT => 15,
        ]);
    };
    $opts($chA, $payloadA);
    $opts($chB, $payloadB);

    curl_multi_add_handle($mh, $chA);
    curl_multi_add_handle($mh, $chB);

    $active = null;
    do {
        $status = curl_multi_exec($mh, $active);
        if ($active) curl_multi_select($mh, 1.0);
    } while ($active && $status === CURLM_OK);

    $parse = function($ch, $modelName) {
        $resp = curl_multi_getcontent($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        if (!$resp) return ['ok' => false, 'model' => $modelName, 'error' => 'sin respuesta'];
        $json = json_decode($resp, true);
        if (!is_array($json)) return ['ok' => false, 'model' => $modelName, 'error' => "HTTP {$http}"];
        if ($http >= 400) return ['ok' => false, 'model' => $modelName, 'error' => $json['error']['message'] ?? "HTTP {$http}"];
        $content = $json['choices'][0]['message']['content'] ?? '';
        $tokens  = (int)($json['usage']['total_tokens'] ?? 0);
        $data = json_decode($content, true);
        if (!is_array($data)) return ['ok' => false, 'model' => $modelName, 'error' => 'JSON invalido'];
        return ['ok' => true, 'model' => $modelName, 'data' => $data, 'tokens' => $tokens, 'raw' => $content];
    };

    $resA = $parse($chA, $modelA);
    $resB = $parse($chB, $modelB);

    curl_multi_remove_handle($mh, $chA);
    curl_multi_remove_handle($mh, $chB);
    curl_close($chA);
    curl_close($chB);
    curl_multi_close($mh);

    // Si el primario falla pero el secundario tiene exito, usar el secundario.
    if (!$resA['ok'] && !$resB['ok']) {
        return ['ok' => false, 'error' => 'Ambos modelos fallaron. A: ' . $resA['error'] . ' | B: ' . $resB['error']];
    }
    if (!$resA['ok']) {
        $data = aiNormalizeExtraction($resB['data']);
        $data['_warnings'] = array_merge($data['_warnings'] ?? [], ['Modelo principal fallo, solo se uso ' . $modelB]);
        return ['ok' => true, 'data' => $data, 'tokens' => $resB['tokens'], 'raw' => $resB['raw']];
    }
    if (!$resB['ok']) {
        $data = aiNormalizeExtraction($resA['data']);
        $data['_warnings'] = array_merge($data['_warnings'] ?? [], ['Modelo secundario fallo, solo se uso ' . $modelA . ' (sin validacion cruzada)']);
        return ['ok' => true, 'data' => $data, 'tokens' => $resA['tokens'], 'raw' => $resA['raw']];
    }

    // Ambos exitosos: construir consenso
    $consensus = aiBuildConsensus($resA['data'], $resB['data'], $modelA, $modelB);
    return [
        'ok' => true,
        'data' => $consensus,
        'tokens' => ($resA['tokens'] + $resB['tokens']),
        'raw' => json_encode([
            'model_a' => ['model' => $modelA, 'data' => $resA['data']],
            'model_b' => ['model' => $modelB, 'data' => $resB['data']],
            'consensus' => $consensus,
        ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT),
    ];
}

/**
 * Compara dos extracciones y devuelve datos solo cuando coinciden.
 * Donde difieren: usa el valor del modelo A (principal) pero baja la confianza
 * y agrega warning explicito identificando el campo divergente.
 */
function aiBuildConsensus(array $a, array $b, string $modelA, string $modelB) {
    // Normalizar primero ambos (por igual)
    $a = aiNormalizeExtraction($a);
    $b = aiNormalizeExtraction($b);

    $warnings = array_merge($a['_warnings'] ?? [], $b['_warnings'] ?? []);
    $consensus = $a; // base
    $disagreements = [];
    $agreements = 0;
    $totalChecked = 0;

    // Campos string criticos: comparacion exacta tras normalizacion
    $strictKeys = ['rnc', 'ncf', 'ncf_type', 'doc_type', 'date_doc'];
    foreach ($strictKeys as $k) {
        $va = (string)($a[$k] ?? '');
        $vb = (string)($b[$k] ?? '');
        $totalChecked++;
        if ($va === $vb) {
            $agreements++;
            $consensus[$k] = $va;
        } else {
            $disagreements[] = "{$k}: '{$va}' vs '{$vb}'";
            // Preferir el no-vacio si uno esta vacio
            if ($va === '' && $vb !== '') $consensus[$k] = $vb;
            else $consensus[$k] = $va;
        }
    }

    // Campos numericos: tolerancia 1 RD$ o 1% (lo que sea mayor)
    $numKeys = ['subtotal', 'itbis', 'propina_legal', 'transporte', 'isr_retention', 'itbis_retention', 'other_taxes', 'total'];
    foreach ($numKeys as $k) {
        $va = (float)($a[$k] ?? 0);
        $vb = (float)($b[$k] ?? 0);
        $totalChecked++;
        $tolerance = max(1.0, max(abs($va), abs($vb)) * 0.01);
        if (abs($va - $vb) <= $tolerance) {
            $agreements++;
            // Promediar cuando coinciden dentro de la tolerancia (corrige errores de OCR de un centavo)
            $consensus[$k] = round(($va + $vb) / 2, 2);
        } else {
            $disagreements[] = "{$k}: " . number_format($va, 2) . " vs " . number_format($vb, 2);
            // Preferir el no-cero si uno es cero (a menudo el otro lo capturo y este lo perdio)
            if ($va == 0 && $vb != 0) $consensus[$k] = $vb;
            elseif ($vb == 0 && $va != 0) $consensus[$k] = $va;
            else $consensus[$k] = $va; // primary wins en desacuerdo genuino
        }
    }

    // Campos secundarios (counterparty, concept): usar el mas largo (probablemente mas completo)
    foreach (['counterparty_name', 'concept'] as $k) {
        $va = (string)($a[$k] ?? '');
        $vb = (string)($b[$k] ?? '');
        if ($va === '' && $vb !== '') $consensus[$k] = $vb;
        elseif ($vb === '' && $va !== '') $consensus[$k] = $va;
        elseif (strlen($vb) > strlen($va) * 1.5) $consensus[$k] = $vb; // mucho mas largo
        // si no, queda el de $a
    }

    // Confianza calibrada por agreement ratio
    $agreementRatio = $totalChecked > 0 ? ($agreements / $totalChecked) : 0;
    $avgConf = (((float)($a['confidence'] ?? 0)) + ((float)($b['confidence'] ?? 0))) / 2;

    // Si 100% acuerdo: boost a 0.95+ (alta confianza calibrada)
    // Si 80%+ acuerdo: confianza promedio sin penalizar
    // Si <80%: penalizar proporcionalmente
    if ($agreementRatio >= 1.0) {
        $consensus['confidence'] = min(0.99, max($avgConf, 0.92));
    } elseif ($agreementRatio >= 0.8) {
        $consensus['confidence'] = $avgConf;
    } else {
        $consensus['confidence'] = round($avgConf * $agreementRatio, 2);
    }

    if (!empty($disagreements)) {
        $warnings[] = 'Modelos {' . $modelA . '} y {' . $modelB . '} difieren en: ' . implode('; ', array_slice($disagreements, 0, 5));
    } else {
        $warnings[] = "Validacion cruzada {$modelA} + {$modelB}: 100% acuerdo.";
    }

    $consensus['_warnings'] = $warnings;
    $consensus['_consensus'] = [
        'models'         => [$modelA, $modelB],
        'agreement'      => $totalChecked > 0 ? round($agreementRatio, 2) : null,
        'disagreements'  => count($disagreements),
    ];
    return $consensus;
}

// --------------------------------------------------------------------------
// Persistence pipeline
// --------------------------------------------------------------------------
function aiPeriodFromDate($dateStr) {
    $t = strtotime((string)$dateStr);
    if (!$t) return null;
    return date('Y-m', $t);
}

/**
 * Returns the upload id if a previous upload from the same client with the
 * same sha256 already exists. 0 otherwise.
 */
function aiFindDuplicateUpload($clientId, $sha256) {
    global $pdo;
    if (empty($sha256)) return 0;
    $dup = $pdo->prepare("SELECT id FROM invoice_uploads WHERE client_id=? AND sha256=? LIMIT 1");
    $dup->execute([$clientId, $sha256]);
    return (int)($dup->fetchColumn() ?: 0);
}

function aiCreateUploadRecord($clientId, $fileMeta, $docTypeHint = 'auto', $uploaderId = null) {
    global $pdo;
    $stmt = $pdo->prepare("
        INSERT INTO invoice_uploads
        (client_id, uploaded_by, filename, original_name, mime_type, file_size, sha256, doc_type, status)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'uploaded')
    ");
    $stmt->execute([
        $clientId,
        $uploaderId,
        $fileMeta['filename'],
        $fileMeta['original_name'],
        $fileMeta['mime_type'],
        $fileMeta['file_size'],
        $fileMeta['sha256'] ?? null,
        $docTypeHint,
    ]);
    return (int)$pdo->lastInsertId();
}

/**
 * Process one upload: call OpenAI, persist extraction.
 * Returns ['ok' => bool, 'upload' => array|null, 'extraction' => array|null, 'error' => string|null]
 */
function aiProcessUpload($uploadId) {
    global $pdo;

    $u = $pdo->prepare("SELECT * FROM invoice_uploads WHERE id = ?");
    $u->execute([$uploadId]);
    $upload = $u->fetch();
    if (!$upload) return ['ok' => false, 'error' => 'Upload no encontrado.'];

    if ($upload['status'] === 'processing') {
        return ['ok' => false, 'error' => 'Ya esta siendo procesado.'];
    }

    $client = $pdo->prepare("SELECT id, name, business_name, rnc, operation_type, economic_activity FROM users WHERE id = ?");
    $client->execute([$upload['client_id']]);
    $cli = $client->fetch() ?: [];

    $pdo->prepare("UPDATE invoice_uploads SET status='processing' WHERE id=?")->execute([$uploadId]);

    $absPath = aiUploadsDir() . '/' . $upload['filename'];
    $res = aiExtractInvoiceFromFile($absPath, $upload['mime_type'], $cli ?: []);

    if (!$res['ok']) {
        $pdo->prepare("UPDATE invoice_uploads SET status='error', error_message=?, processed_at=NOW() WHERE id=?")
            ->execute([substr($res['error'] ?? 'Error', 0, 1000), $uploadId]);
        return ['ok' => false, 'error' => $res['error'] ?? 'Error desconocido'];
    }

    $d = $res['data'];
    // Respect explicit doc type hint if it is not 'auto'
    if (in_array($upload['doc_type'], ['compra','venta'], true)) {
        $d['doc_type'] = $upload['doc_type'];
    }
    $docType = ($d['doc_type'] ?? 'compra') === 'venta' ? 'venta' : 'compra';
    $period  = aiPeriodFromDate($d['date_doc'] ?? '');

    $cfg = aiOpenAIConfig();

    $ins = $pdo->prepare("
        INSERT INTO invoice_extractions
        (upload_id, client_id, doc_type, period, date_doc, date_payment,
         rnc, counterparty_name, ncf, ncf_modified, ncf_type,
         concept, expense_category, payment_method, currency,
         subtotal, itbis, propina_legal, transporte,
         isr_retention, itbis_retention, other_taxes, total,
         confidence, ai_notes, raw_json)
        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)
    ");
    $ins->execute([
        $uploadId,
        $upload['client_id'],
        $docType,
        $period,
        !empty($d['date_doc'])     ? date('Y-m-d', strtotime($d['date_doc']))     : null,
        !empty($d['date_payment']) ? date('Y-m-d', strtotime($d['date_payment'])) : null,
        substr((string)($d['rnc'] ?? ''), 0, 30),
        substr((string)($d['counterparty_name'] ?? ''), 0, 255),
        substr((string)($d['ncf'] ?? ''), 0, 30),
        substr((string)($d['ncf_modified'] ?? ''), 0, 30),
        substr((string)($d['ncf_type'] ?? ''), 0, 10),
        substr((string)($d['concept'] ?? ''), 0, 500),
        substr((string)($d['expense_category'] ?? ''), 0, 10),
        substr((string)($d['payment_method'] ?? ''), 0, 10),
        substr((string)($d['currency'] ?? 'DOP'), 0, 10),
        (float)($d['subtotal']        ?? 0),
        (float)($d['itbis']           ?? 0),
        (float)($d['propina_legal']   ?? 0),
        (float)($d['transporte']      ?? 0),
        (float)($d['isr_retention']   ?? 0),
        (float)($d['itbis_retention'] ?? 0),
        (float)($d['other_taxes']     ?? 0),
        (float)($d['total']           ?? 0),
        (float)($d['confidence']      ?? 0),
        substr(trim(((string)($d['notes'] ?? '')) . (empty($d['_warnings']) ? '' : (" [auto] " . implode(' | ', $d['_warnings'])))), 0, 2000),
        $res['raw'] ?? null,
    ]);
    $extractionId = (int)$pdo->lastInsertId();

    $pdo->prepare("
        UPDATE invoice_uploads
        SET status='extracted',
            doc_type = ?,
            period   = ?,
            ai_model = ?,
            ai_tokens = ?,
            processed_at = NOW(),
            error_message = NULL
        WHERE id=?
    ")->execute([$docType, $period, $cfg['model'], (int)($res['tokens'] ?? 0), $uploadId]);

    // Auto-aprobacion si la confianza supera el umbral configurado
    $autoApproveThreshold = (float)getSetting('openai_auto_approve_threshold', '0');
    $autoApproved = false;
    if ($autoApproveThreshold > 0 && (float)$d['confidence'] >= $autoApproveThreshold) {
        // Solo auto-aprobar si hay datos minimos: RNC + NCF + total > 0
        $hasMinimum = !empty($d['rnc']) && !empty($d['ncf']) && (float)$d['total'] > 0;
        if ($hasMinimum) {
            $ap = aiApproveExtraction($extractionId, null);
            if (!empty($ap['ok'])) {
                $autoApproved = true;
                if (function_exists('logClientActivity')) {
                    logClientActivity((int)$upload['client_id'], 'invoice_auto_approved', "Factura auto-aprobada por IA (confianza " . round(((float)$d['confidence']) * 100) . "%)", [
                        'extraction_id' => $extractionId,
                        'doc_type'      => $docType,
                        'period'        => $period,
                    ]);
                }
            }
        }
    }

    return ['ok' => true, 'upload_id' => $uploadId, 'extraction_id' => $extractionId, 'auto_approved' => $autoApproved];
}

// --------------------------------------------------------------------------
// Approve -> push into tax_filing_rows
// --------------------------------------------------------------------------
function ensureTaxFiling($clientId, $filingType, $period) {
    global $pdo;
    $sel = $pdo->prepare("SELECT id FROM tax_filings WHERE client_id=? AND filing_type=? AND period=?");
    $sel->execute([$clientId, $filingType, $period]);
    $id = $sel->fetchColumn();
    if ($id) return (int)$id;
    $pdo->prepare("INSERT INTO tax_filings (client_id, filing_type, period, status) VALUES (?, ?, ?, 'borrador')")
        ->execute([$clientId, $filingType, $period]);
    return (int)$pdo->lastInsertId();
}

function recalcTaxFilingTotals($filingId) {
    global $pdo;
    $tots = $pdo->prepare("SELECT COUNT(*) c, COALESCE(SUM(amount),0) a, COALESCE(SUM(itbis),0) i FROM tax_filing_rows WHERE filing_id=?");
    $tots->execute([$filingId]);
    $t = $tots->fetch();
    $pdo->prepare("UPDATE tax_filings SET total_records=?, total_amount=?, total_itbis=? WHERE id=?")
        ->execute([$t['c'], $t['a'], $t['i'], $filingId]);
}

/**
 * Recalculate the IT-1 totals from the corresponding 606/607 of the same period.
 * IT-1 carries ITBIS pagado (606) en `total_amount` y ITBIS cobrado (607) en `total_itbis`.
 * Para mostrar el saldo: `total_itbis - total_amount` = ITBIS a pagar (si positivo) o saldo a favor.
 */
function recalcIT1ForClient($clientId, $period) {
    global $pdo;
    if (!$clientId || !$period) return;
    $filingId = ensureTaxFiling($clientId, 'IT-1', $period);

    $c606 = $pdo->prepare("SELECT COALESCE(SUM(itbis),0) FROM tax_filing_rows r
                           JOIN tax_filings f ON f.id=r.filing_id
                           WHERE f.client_id=? AND f.filing_type='606' AND f.period=?");
    $c606->execute([$clientId, $period]);
    $itbisCompras = (float)$c606->fetchColumn();

    $c607 = $pdo->prepare("SELECT COALESCE(SUM(itbis),0) FROM tax_filing_rows r
                           JOIN tax_filings f ON f.id=r.filing_id
                           WHERE f.client_id=? AND f.filing_type='607' AND f.period=?");
    $c607->execute([$clientId, $period]);
    $itbisVentas = (float)$c607->fetchColumn();

    $records = $pdo->prepare("SELECT COUNT(*) FROM tax_filing_rows r
                              JOIN tax_filings f ON f.id=r.filing_id
                              WHERE f.client_id=? AND f.period=? AND f.filing_type IN ('606','607')");
    $records->execute([$clientId, $period]);
    $totalRecords = (int)$records->fetchColumn();

    // total_amount = ITBIS pagado (606), total_itbis = ITBIS cobrado (607)
    $pdo->prepare("UPDATE tax_filings SET total_records=?, total_amount=?, total_itbis=? WHERE id=?")
        ->execute([$totalRecords, $itbisCompras, $itbisVentas, $filingId]);
}

/**
 * Aprueba la extraccion y crea/actualiza la fila correspondiente en tax_filing_rows.
 * doc_type=compra -> 606, doc_type=venta -> 607.
 * Tambien recalcula IT-1 para ese cliente y periodo.
 */
function aiApproveExtraction($extractionId, $approverId = null) {
    global $pdo;
    $sel = $pdo->prepare("SELECT * FROM invoice_extractions WHERE id=?");
    $sel->execute([$extractionId]);
    $e = $sel->fetch();
    if (!$e) return ['ok' => false, 'error' => 'Extraccion no encontrada.'];

    $filingType = $e['doc_type'] === 'venta' ? '607' : '606';
    $period     = $e['period'];
    if (!$period) {
        $period = $e['date_doc'] ? date('Y-m', strtotime($e['date_doc'])) : date('Y-m');
    }

    $filingId = ensureTaxFiling((int)$e['client_id'], $filingType, $period);

    // Amount in 606/607 row: subtotal (base imponible). En el ejemplo del 606
    // ese "SUB-TOTAL" coincide con la base imponible (sin ITBIS ni propina).
    // En 607 el "TOTAL" del ejemplo es base + ITBIS + transporte, pero la base
    // imponible coincide con el subtotal del extractor. Usamos `subtotal`.
    $amount = (float)$e['subtotal'];
    if ($amount <= 0) {
        // fallback: total - itbis - propina - transporte
        $amount = max(0, (float)$e['total'] - (float)$e['itbis'] - (float)$e['propina_legal'] - (float)$e['transporte']);
    }

    if (!empty($e['filing_row_id'])) {
        // Update existing row
        $pdo->prepare("UPDATE tax_filing_rows SET rnc=?, ncf=?, ncf_modified=?, tax_type=?, date_doc=?, date_payment=?, amount=?, itbis=?, isr_retention=?, itbis_retention=? WHERE id=?")
            ->execute([
                $e['rnc'], $e['ncf'], $e['ncf_modified'],
                $filingType === '606' ? ($e['expense_category'] ?: '09') : '01',
                $e['date_doc'], $e['date_payment'],
                $amount, (float)$e['itbis'], (float)$e['isr_retention'], (float)$e['itbis_retention'],
                $e['filing_row_id'],
            ]);
        $rowId = (int)$e['filing_row_id'];
    } else {
        $pdo->prepare("INSERT INTO tax_filing_rows (filing_id, rnc, ncf, ncf_modified, tax_type, date_doc, date_payment, amount, itbis, isr_retention, itbis_retention) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([
                $filingId,
                $e['rnc'], $e['ncf'], $e['ncf_modified'],
                $filingType === '606' ? ($e['expense_category'] ?: '09') : '01',
                $e['date_doc'], $e['date_payment'],
                $amount, (float)$e['itbis'], (float)$e['isr_retention'], (float)$e['itbis_retention'],
            ]);
        $rowId = (int)$pdo->lastInsertId();
    }

    $pdo->prepare("UPDATE invoice_extractions SET approved=1, filing_row_id=? WHERE id=?")
        ->execute([$rowId, $extractionId]);
    $pdo->prepare("UPDATE invoice_uploads SET status='approved', approved_at=NOW(), approved_by=? WHERE id=?")
        ->execute([$approverId, $e['upload_id']]);

    recalcTaxFilingTotals($filingId);
    recalcIT1ForClient((int)$e['client_id'], $period);

    // Notificacion al cliente (best-effort)
    if (getSetting('notify_invoice_approved', '1') === '1') {
        // Trae el origen para no spamear si vino por Telegram (el bot ya respondio)
        $upMeta = $pdo->prepare("SELECT source FROM invoice_uploads WHERE id=?");
        $upMeta->execute([$e['upload_id']]);
        $source = $upMeta->fetchColumn();
        if (function_exists('sendInvoiceApprovedEmail') && $source !== 'telegram') {
            @sendInvoiceApprovedEmail((int)$e['client_id'], $extractionId, $filingType, $period);
        }
        // Si el cliente tiene Telegram vinculado, le mandamos un push corto
        if (function_exists('tgClientForChat') && function_exists('tgSendMessage')) {
            try {
                $link = $pdo->prepare("SELECT chat_id FROM telegram_links WHERE client_id = ? AND active = 1 LIMIT 1");
                $link->execute([(int)$e['client_id']]);
                $chatId = (int)($link->fetchColumn() ?: 0);
                if ($chatId > 0) {
                    $msg = "✅ Tu asesor aprobó la factura " . ($filingType === '607' ? '(Venta)' : '(Compra)')
                         . " del periodo {$period}. NCF <code>" . htmlspecialchars($e['ncf'] ?: '-') . "</code>"
                         . " · Total RD$ " . number_format((float)$e['total'], 2);
                    tgSendMessage($chatId, $msg);
                }
            } catch (PDOException $ex) {}
        }
    }

    return ['ok' => true, 'filing_id' => $filingId, 'row_id' => $rowId, 'filing_type' => $filingType, 'period' => $period];
}

/**
 * Reverts a previously approved extraction.
 */
function aiRejectExtraction($extractionId) {
    global $pdo;
    $sel = $pdo->prepare("SELECT * FROM invoice_extractions WHERE id=?");
    $sel->execute([$extractionId]);
    $e = $sel->fetch();
    if (!$e) return ['ok' => false, 'error' => 'Extraccion no encontrada.'];

    if (!empty($e['filing_row_id'])) {
        // Find its filing first
        $f = $pdo->prepare("SELECT filing_id FROM tax_filing_rows WHERE id=?");
        $f->execute([$e['filing_row_id']]);
        $filingId = (int)$f->fetchColumn();
        $pdo->prepare("DELETE FROM tax_filing_rows WHERE id=?")->execute([$e['filing_row_id']]);
        if ($filingId) recalcTaxFilingTotals($filingId);
    }
    $pdo->prepare("UPDATE invoice_extractions SET approved=0, filing_row_id=NULL WHERE id=?")->execute([$extractionId]);
    $pdo->prepare("UPDATE invoice_uploads SET status='rejected', approved_at=NULL WHERE id=?")->execute([$e['upload_id']]);
    if (!empty($e['period'])) {
        recalcIT1ForClient((int)$e['client_id'], (string)$e['period']);
    }
    return ['ok' => true];
}
