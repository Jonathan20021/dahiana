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

        // Add telegram_link_code to users (idempotent)
        $userCols = $pdo->query("SELECT COLUMN_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME='users'")->fetchAll(PDO::FETCH_COLUMN);
        $userCols = array_map('strtolower', $userCols);
        if (!in_array('telegram_link_code', $userCols, true)) {
            try { $pdo->exec("ALTER TABLE users ADD COLUMN telegram_link_code VARCHAR(20) DEFAULT NULL, ADD INDEX idx_users_telegram_link (telegram_link_code)"); } catch (PDOException $e) {}
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
        "Recibiras la foto de un comprobante fiscal (factura, recibo, conduce, nota de credito, NCF) y debes extraer SIEMPRE todos los datos relevantes para los formatos 606 (Compras), 607 (Ventas) e IT-1 (ITBIS).",
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

    // Normalize RNC: only digits, 9 or 11.
    $rnc = preg_replace('/\D+/', '', (string)($data['rnc'] ?? ''));
    if ($rnc !== '' && !in_array(strlen($rnc), [9, 11], true)) {
        $warnings[] = 'RNC con longitud no estandar (' . strlen($rnc) . ' digitos).';
    }
    $data['rnc'] = $rnc;

    // Normalize NCF: uppercase, no spaces. Should match B/E + 10-12 chars typical.
    $ncf = strtoupper(preg_replace('/\s+/', '', (string)($data['ncf'] ?? '')));
    $data['ncf'] = $ncf;
    if ($ncf !== '' && !preg_match('/^[BE]\d{2}[A-Z0-9]{7,11}$/i', $ncf)) {
        $warnings[] = 'NCF con formato no estandar (' . $ncf . ').';
    }
    $data['ncf_modified'] = strtoupper(preg_replace('/\s+/', '', (string)($data['ncf_modified'] ?? '')));
    $data['ncf_type']     = strtoupper(preg_replace('/\s+/', '', (string)($data['ncf_type'] ?? '')));

    // Force numeric
    $numKeys = ['subtotal','itbis','propina_legal','transporte','isr_retention','itbis_retention','other_taxes','total','confidence'];
    foreach ($numKeys as $k) {
        $data[$k] = round((float)($data[$k] ?? 0), 2);
    }

    // Coherence: subtotal + itbis + propina + transporte ~= total (tolerance 2 RD$).
    $reconstructed = round(($data['subtotal'] + $data['itbis'] + $data['propina_legal'] + $data['transporte']), 2);
    if ($data['total'] > 0 && abs($reconstructed - $data['total']) > 2.00) {
        // If only total is reliable, recompute subtotal from total
        if ($data['itbis'] === 0.0 && $data['subtotal'] > 0) {
            // Maybe ITBIS missed; assume 18%
            $maybeItbis = round($data['subtotal'] * 0.18, 2);
            if (abs(($data['subtotal'] + $maybeItbis) - $data['total']) <= 2.00) {
                $data['itbis'] = $maybeItbis;
                $warnings[] = 'ITBIS recalculado a 18% del subtotal.';
            }
        } elseif ($data['subtotal'] === 0.0 && $data['total'] > 0) {
            // Reverse from total
            $data['subtotal'] = round($data['total'] / 1.18, 2);
            $data['itbis']    = round($data['total'] - $data['subtotal'], 2);
            $warnings[] = 'Subtotal/ITBIS recalculados desde el total (asumiendo 18%).';
        } else {
            $warnings[] = 'Suma de componentes (' . number_format($reconstructed,2) . ') no coincide con total (' . number_format($data['total'],2) . ').';
        }
    }

    // If total is 0 but subtotal+itbis>0, compute total
    if ($data['total'] === 0.0 && ($data['subtotal'] > 0 || $data['itbis'] > 0)) {
        $data['total'] = round($data['subtotal'] + $data['itbis'] + $data['propina_legal'] + $data['transporte'], 2);
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

    $payload = [
        'model' => $cfg['model'],
        'messages' => [
            ['role' => 'system', 'content' => aiSystemPrompt()],
            ['role' => 'user', 'content' => [
                ['type' => 'text', 'text' => "Extrae TODOS los datos de esta factura para los formularios 606, 607 e IT-1 de la DGII. Si el documento no tiene NCF formal, ponlo vacio. Si algun monto es 0 o no aparece, ponlo 0. Devuelve solo el JSON estricto." . $hintBlock],
                ['type' => 'image_url', 'image_url' => ['url' => $enc['data_url'], 'detail' => 'high']],
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

    $maxAttempts = 2;
    $lastError = '';
    for ($attempt = 1; $attempt <= $maxAttempts; $attempt++) {
        $ch = curl_init('https://api.openai.com/v1/chat/completions');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST           => true,
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $cfg['api_key'],
                'Content-Type: application/json',
            ],
            CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
            CURLOPT_TIMEOUT        => 150,
            CURLOPT_CONNECTTIMEOUT => 20,
        ]);
        $resp = curl_exec($ch);
        $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        if ($resp === false) {
            $lastError = 'Error de red: ' . $err;
            // retry on network error
            continue;
        }
        $json = json_decode($resp, true);
        if (!is_array($json)) {
            $lastError = "Respuesta invalida (HTTP {$http}): " . substr((string)$resp, 0, 300);
            continue;
        }
        if ($http >= 500 || $http === 429) {
            $lastError = 'OpenAI HTTP ' . $http . ': ' . ($json['error']['message'] ?? '');
            // retriable
            continue;
        }
        if ($http >= 400) {
            $msg = $json['error']['message'] ?? ('HTTP ' . $http);
            return ['ok' => false, 'error' => 'OpenAI: ' . $msg];
        }
        $content = $json['choices'][0]['message']['content'] ?? '';
        $tokens  = (int)($json['usage']['total_tokens'] ?? 0);
        $data = json_decode($content, true);
        if (!is_array($data)) {
            $lastError = 'JSON no parseable: ' . substr((string)$content, 0, 300);
            continue;
        }
        $data = aiNormalizeExtraction($data);
        return ['ok' => true, 'data' => $data, 'tokens' => $tokens, 'raw' => $content];
    }
    return ['ok' => false, 'error' => $lastError ?: 'Error desconocido tras reintentos.'];
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
    $cli = $client->fetch();

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

    return ['ok' => true, 'upload_id' => $uploadId, 'extraction_id' => $extractionId];
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
