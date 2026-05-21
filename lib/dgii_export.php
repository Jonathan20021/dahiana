<?php
// lib/dgii_export.php
// Exportadores oficiales DGII (TXT + Excel HTML compatible) + bundle ZIP.

if (!defined('DGII_EXPORT_LOADED')) define('DGII_EXPORT_LOADED', true);

/**
 * Devuelve los datos de un filing con sus filas y datos del cliente.
 */
function dgiiFetchFiling($clientId, $filingType, $period) {
    global $pdo;
    $f = $pdo->prepare("
        SELECT f.*, u.name AS client_name, u.business_name, u.rnc
        FROM tax_filings f
        JOIN users u ON u.id = f.client_id
        WHERE f.client_id = ? AND f.filing_type = ? AND f.period = ?
    ");
    $f->execute([$clientId, $filingType, $period]);
    $filing = $f->fetch();
    if (!$filing) return null;

    $r = $pdo->prepare("SELECT * FROM tax_filing_rows WHERE filing_id = ? ORDER BY date_doc ASC, id ASC");
    $r->execute([$filing['id']]);
    $filing['rows'] = $r->fetchAll();
    return $filing;
}

/**
 * Solo digitos (para RNC y NCF que la DGII espera limpios).
 */
function dgiiDigits($s) { return preg_replace('/\D+/', '', (string)$s); }
function dgiiUpper($s)  { return strtoupper(preg_replace('/\s+/', '', (string)$s)); }
function dgiiDate($d)   { $t = $d ? strtotime($d) : false; return $t ? date('Ymd', $t) : ''; }
function dgiiNum($n)    { return number_format((float)$n, 2, '.', ''); }

/**
 * Numero formateado al estilo DGII de la consultora:
 * 2 decimales maximo, sin separadores, sin ceros finales y sin punto colgante.
 * Si el monto es cero o negativo en columnas opcionales, devuelve cadena vacia.
 * Ejemplos: 1330.00 -> "1330"  |  12182.40 -> "12182.4"  |  1097.92 -> "1097.92"
 */
function dgiiTrimNum($n, $blankIfZero = true) {
    $v = (float)$n;
    if ($blankIfZero && abs($v) < 0.005) return '';
    $s = number_format($v, 2, '.', '');
    if (strpos($s, '.') !== false) {
        $s = rtrim(rtrim($s, '0'), '.');
    }
    return $s;
}

/**
 * Clasifica el tipo de bien/servicio del 606 como Servicio o Bien
 * para decidir si el monto va en columna 8 (servicios) o columna 9 (bienes).
 * Coincide con como la consultora arma el archivo oficial.
 */
function dgiiIsService($taxType) {
    $code = str_pad((string)$taxType, 2, '0', STR_PAD_LEFT);
    return in_array($code, ['01','02','03','05','06','07','08','11'], true);
}

/**
 * Devuelve "codigo - etiqueta" para una celda del Excel a partir de un catalogo
 * tipo aiExpenseCategories(). Normaliza siempre el codigo a 2 digitos (09, no 9).
 * Si la etiqueta no esta en el catalogo, devuelve solo el codigo normalizado.
 * Si el codigo viene vacio, devuelve cadena vacia.
 */
function dgiiLabelCode($code, array $catalog, $pad = 2) {
    $code = (string)$code;
    if ($code === '') return '';
    if ($pad > 0) $code = str_pad($code, $pad, '0', STR_PAD_LEFT);
    return isset($catalog[$code]) ? "{$code} - {$catalog[$code]}" : $code;
}

// --------------------------------------------------------------------------
// TXT oficial DGII
// --------------------------------------------------------------------------

/**
 * Formato 606 (Compras de Bienes y Servicios).
 * Estructura por linea (pipe-delimited):
 *  1. RNC/Cedula proveedor (11 digitos)
 *  2. Tipo identificacion (1=RNC, 2=Cedula, 3=Pasaporte)
 *  3. Tipo bien/servicio (01-11)
 *  4. NCF
 *  5. NCF modificado (si aplica)
 *  6. Fecha comprobante (YYYYMMDD)
 *  7. Fecha pago (YYYYMMDD)
 *  8. Monto facturado servicios
 *  9. Monto facturado bienes
 * 10. Total monto facturado
 * 11. ITBIS facturado
 * 12. ITBIS retenido
 * 13. ITBIS sujeto a proporcionalidad
 * 14. ITBIS llevado al costo
 * 15. ITBIS por adelantar
 * 16. ITBIS percibido en compras
 * 17. Tipo retencion ISR (01 alquileres, 02 honorarios, 03 otros)
 * 18. Monto retencion renta
 * 19. ISR percibido en compras
 * 20. Impuesto selectivo al consumo (ISC)
 * 21. Otros impuestos / tasas
 * 22. Monto propina legal
 * 23. Forma de pago (01-07)
 */
function dgiiTxt606($filing) {
    $rncClient = dgiiDigits($filing['rnc'] ?? '');
    $period    = str_replace('-', '', $filing['period']);
    $rows      = $filing['rows'];
    $lines     = [];
    // Encabezado: 606|RNC|Periodo|TotalRegistros
    $lines[] = "606|{$rncClient}|{$period}|" . count($rows);

    foreach ($rows as $r) {
        $rnc       = dgiiDigits($r['rnc']);
        $tipoId    = ($r['identification_type'] ?? '') !== ''
                     ? (string)$r['identification_type']
                     : (strlen($rnc) === 11 ? '2' : '1');
        // DGII exige codigos de 2 digitos (01-11) para tipo bien/servicio.
        $tipoBien  = str_pad((string)($r['tax_type'] ?: '09'), 2, '0', STR_PAD_LEFT);
        $ncf       = dgiiUpper($r['ncf']);
        $ncfMod    = dgiiUpper($r['ncf_modified']);
        $fechaDoc  = dgiiDate($r['date_doc']);
        $fechaPago = dgiiDate($r['date_payment']);

        $amount    = (float)$r['amount'];
        $itbis     = (float)$r['itbis'];
        $itbisRet  = (float)$r['itbis_retention'];
        $isrRet    = (float)$r['isr_retention'];
        $isc       = (float)($r['isc'] ?? 0);
        $otros     = (float)($r['other_taxes'] ?? 0);
        $propina   = (float)($r['propina_legal'] ?? 0);
        $isService = dgiiIsService($tipoBien);

        // Col 15: ITBIS por adelantar (lo deducible) = facturado - retenido
        $itbisAdelantar = max(0, $itbis - $itbisRet);

        // Col 17: tipo de retencion ISR (catalogo 01-08). Si la fila lo trae lo usamos;
        // si no, inferimos por tipo bien: tipo 03 = alquileres, resto = honorarios.
        $tipoRetIsr = '';
        if ($isrRet > 0.005) {
            $tipoRetIsr = ($r['isr_retention_type'] ?? '')
                ?: (($tipoBien === '03') ? '01' : '02');
        }

        // Forma de pago: viene del extractor IA (codigos DGII 01-07). Siempre 2 digitos.
        $formaPago = ($r['payment_method'] ?? '') !== ''
            ? str_pad((string)$r['payment_method'], 2, '0', STR_PAD_LEFT)
            : '';

        $cols = [
            $rnc,                                          //  1 RNC/Cedula
            $tipoId,                                       //  2 Tipo ID
            $tipoBien,                                     //  3 Tipo bien/servicio
            $ncf,                                          //  4 NCF
            $ncfMod,                                       //  5 NCF modificado
            $fechaDoc,                                     //  6 Fecha comprobante
            $fechaPago,                                    //  7 Fecha pago
            $isService ? dgiiTrimNum($amount) : '',        //  8 Monto facturado en servicios
            $isService ? '' : dgiiTrimNum($amount),        //  9 Monto facturado en bienes
            dgiiTrimNum($amount),                          // 10 Total monto facturado
            dgiiTrimNum($itbis, false),                    // 11 ITBIS facturado (siempre se escribe, "0" si no aplica)
            dgiiTrimNum($itbisRet),                        // 12 ITBIS retenido
            '',                                            // 13 ITBIS sujeto a proporcionalidad
            '',                                            // 14 ITBIS llevado al costo
            dgiiTrimNum($itbisAdelantar, false),           // 15 ITBIS por adelantar (siempre se escribe, "0" si no aplica)
            '',                                            // 16 ITBIS percibido en compras
            $tipoRetIsr,                                   // 17 Tipo retencion ISR
            dgiiTrimNum($isrRet),                          // 18 Monto retencion renta
            '',                                            // 19 ISR percibido en compras
            dgiiTrimNum($isc),                             // 20 Impuesto selectivo al consumo
            dgiiTrimNum($otros),                           // 21 Otros impuestos / tasas
            dgiiTrimNum($propina),                         // 22 Propina legal
            $formaPago,                                    // 23 Forma de pago
        ];
        $lines[] = implode('|', $cols);
    }
    return implode("\r\n", $lines) . "\r\n";
}

/**
 * Mapea el codigo de forma de pago (payment_method 01-07) a la columna del
 * 607 donde se debe acumular el monto facturado. Devuelve el indice 1-based
 * (17..23) o null si no aplica.
 *  17. Efectivo                        (01)
 *  18. Cheque/Transferencia/Deposito   (02)
 *  19. Tarjeta Credito/Debito          (03)
 *  20. Venta a Credito                 (04)
 *  21. Bonos o Certificados de Regalo  (06 nota de credito y similares)
 *  22. Permuta                         (05)
 *  23. Otras Formas de Venta           (07 mixto / no especificado)
 */
function dgii607PaymentCol($paymentMethod) {
    switch ((string)$paymentMethod) {
        case '01': return 17;
        case '02': return 18;
        case '03': return 19;
        case '04': return 20;
        case '05': return 22;
        case '06': return 21;
        case '07': return 23;
        default:   return null;
    }
}

/**
 * Formato 607 (Ventas de Bienes y Servicios) - 23 columnas oficiales DGII.
 *  1. RNC, Cedula o ID Tributaria (cliente)
 *  2. Tipo Identificacion (1=RNC, 2=Cedula, 3=Pasaporte)
 *  3. NCF
 *  4. NCF Modificado
 *  5. Tipo Ingreso (01-06)
 *  6. Fecha Comprobante (YYYYMMDD)
 *  7. Fecha Retencion (YYYYMMDD)
 *  8. Monto Facturado
 *  9. ITBIS Facturado
 * 10. ITBIS Retenido por Terceros
 * 11. ITBIS Percibido
 * 12. Retencion Renta por Terceros
 * 13. ISR Percibido por Terceros
 * 14. Impuesto Selectivo al Consumo (ISC)
 * 15. Otros Impuestos / Tasas
 * 16. Monto Propina Legal
 * 17. Efectivo
 * 18. Cheque / Transferencia / Deposito
 * 19. Tarjeta Debito / Credito
 * 20. Venta a Credito
 * 21. Bonos o Certificados de Regalo
 * 22. Permuta
 * 23. Otras Formas de Venta
 * Las columnas 17-23 deben sumar exactamente el Monto Facturado (col 8).
 */
function dgiiTxt607($filing) {
    $rncClient = dgiiDigits($filing['rnc'] ?? '');
    $period    = str_replace('-', '', $filing['period']);
    $rows      = $filing['rows'];
    $lines     = [];
    $lines[] = "607|{$rncClient}|{$period}|" . count($rows);

    foreach ($rows as $r) {
        $rnc       = dgiiDigits($r['rnc']);
        $tipoId    = ($r['identification_type'] ?? '') !== ''
                     ? (string)$r['identification_type']
                     : ($rnc === '' ? '' : (strlen($rnc) === 11 ? '2' : '1'));
        $ncf       = dgiiUpper($r['ncf']);
        $ncfMod    = dgiiUpper($r['ncf_modified']);
        // DGII exige codigos de 2 digitos (01-06) para tipo de ingreso.
        $tipoIng   = str_pad((string)(!empty($r['income_type']) ? $r['income_type'] : '01'), 2, '0', STR_PAD_LEFT);
        $fechaDoc  = dgiiDate($r['date_doc']);
        $fechaRet  = dgiiDate($r['date_payment']);

        $monto     = (float)$r['amount'];
        $itbis     = (float)$r['itbis'];
        $itbisRet  = (float)$r['itbis_retention'];
        $isrRet    = (float)$r['isr_retention'];
        $isc       = (float)($r['isc'] ?? 0);
        $otros     = (float)($r['other_taxes'] ?? 0);
        $propina   = (float)($r['propina_legal'] ?? 0);

        // Desglose por forma de pago: el monto total cae en la columna correspondiente.
        $payCols = ['','','','','','','']; // 17..23
        $payIdx  = dgii607PaymentCol($r['payment_method'] ?? '');
        if ($payIdx !== null && $monto > 0) {
            $payCols[$payIdx - 17] = dgiiTrimNum($monto, false);
        }

        $cols = [
            $rnc,                                          //  1
            $tipoId,                                       //  2
            $ncf,                                          //  3
            $ncfMod,                                       //  4
            $tipoIng,                                      //  5
            $fechaDoc,                                     //  6
            $fechaRet,                                     //  7
            dgiiTrimNum($monto, false),                    //  8 monto facturado (siempre)
            dgiiTrimNum($itbis, false),                    //  9 ITBIS facturado (siempre)
            dgiiTrimNum($itbisRet),                        // 10 ITBIS retenido por terceros
            '',                                            // 11 ITBIS percibido (no aplica)
            dgiiTrimNum($isrRet),                          // 12 retencion renta por terceros
            '',                                            // 13 ISR percibido
            dgiiTrimNum($isc),                             // 14 ISC
            dgiiTrimNum($otros),                           // 15 otros impuestos
            dgiiTrimNum($propina),                         // 16 propina legal
            $payCols[0], $payCols[1], $payCols[2],         // 17-19
            $payCols[3], $payCols[4], $payCols[5],         // 20-22
            $payCols[6],                                   // 23
        ];
        $lines[] = implode('|', $cols);
    }
    return implode("\r\n", $lines) . "\r\n";
}

/**
 * Formato 608 (NCF Anulados) - 3 columnas oficiales DGII.
 *  1. NCF
 *  2. Fecha Comprobante (YYYYMMDD)
 *  3. Tipo Anulacion (catalogo 01-11)
 */
function dgiiTxt608($filing) {
    $rncClient = dgiiDigits($filing['rnc'] ?? '');
    $period    = str_replace('-', '', $filing['period']);
    $rows      = $filing['rows'];
    $lines     = [];
    $lines[] = "608|{$rncClient}|{$period}|" . count($rows);
    foreach ($rows as $r) {
        // Si la fila tiene tipo_anulacion lo usamos; si no, default '02' (errores de impresion).
        $tipo = $r['tipo_anulacion'] ?? '';
        if ($tipo === '' || $tipo === null) {
            // En filas viejas el catalogo viajaba en tax_type. Aceptamos esa convencion.
            $tipo = ($r['tax_type'] ?? '') ?: '02';
        }
        $tipo = str_pad((string)$tipo, 2, '0', STR_PAD_LEFT);
        $cols = [dgiiUpper($r['ncf']), dgiiDate($r['date_doc']), $tipo];
        $lines[] = implode('|', $cols);
    }
    return implode("\r\n", $lines) . "\r\n";
}

function dgiiTxt($filing) {
    return match ($filing['filing_type']) {
        '606' => dgiiTxt606($filing),
        '607' => dgiiTxt607($filing),
        '608' => dgiiTxt608($filing),
        default => '',
    };
}

function dgiiTxtFilename($filing) {
    $rnc = dgiiDigits($filing['rnc'] ?? '');
    $period = str_replace('-', '', $filing['period']);
    $type = $filing['filing_type'];
    return "DGII_F_{$type}_{$rnc}_{$period}.txt";
}

// --------------------------------------------------------------------------
// Excel HTML (Excel abre HTML como hoja nativa, sin necesidad de PhpSpreadsheet)
// --------------------------------------------------------------------------

function dgiiExcelHeader606() {
    // 23 columnas oficiales del formato DGII 606 (mismo orden que el TXT y el .xlsm de DGII).
    return [
        'RNC o Cedula',
        'Tipo Id',
        'Tipo Bienes/Servicios',
        'NCF',
        'NCF Modificado',
        'Fecha Comprobante',
        'Fecha Pago',
        'Monto Facturado Servicios',
        'Monto Facturado Bienes',
        'Total Monto Facturado',
        'ITBIS Facturado',
        'ITBIS Retenido',
        'ITBIS sujeto a Proporcionalidad',
        'ITBIS llevado al Costo',
        'ITBIS por Adelantar',
        'ITBIS percibido en compras',
        'Tipo Retencion ISR',
        'Monto Retencion Renta',
        'ISR percibido en compras',
        'Impuesto Selectivo al Consumo',
        'Otros Impuestos/Tasas',
        'Monto Propina Legal',
        'Forma de Pago',
    ];
}
function dgiiExcelHeader607() {
    // 23 columnas oficiales del formato DGII 607.
    return [
        'RNC / Cedula Cliente',
        'Tipo Id',
        'NCF',
        'NCF Modificado',
        'Tipo Ingreso',
        'Fecha Comprobante',
        'Fecha Retencion',
        'Monto Facturado',
        'ITBIS Facturado',
        'ITBIS Retenido por Terceros',
        'ITBIS Percibido',
        'Retencion Renta por Terceros',
        'ISR Percibido',
        'Impuesto Selectivo al Consumo',
        'Otros Impuestos/Tasas',
        'Monto Propina Legal',
        'Efectivo',
        'Cheque/Transferencia/Deposito',
        'Tarjeta Debito/Credito',
        'Venta a Credito',
        'Bonos o Certificados de Regalo',
        'Permuta',
        'Otras Formas de Venta',
    ];
}
function dgiiExcelHeader608() {
    return ['NCF Anulado','Fecha Comprobante','Tipo Anulacion'];
}

function dgiiExcelRows606($filing) {
    $catId   = function_exists('aiIdentificationTypes') ? aiIdentificationTypes() : [];
    $catBien = function_exists('aiExpenseCategories')   ? aiExpenseCategories()   : [];
    $catRet  = function_exists('aiIsrRetentionTypes')   ? aiIsrRetentionTypes()   : [];
    $catPay  = function_exists('aiPaymentMethods')      ? aiPaymentMethods()      : [];

    $out = [];
    foreach ($filing['rows'] as $r) {
        $rnc      = dgiiDigits($r['rnc']);
        $tipoBien = str_pad((string)($r['tax_type'] ?: '09'), 2, '0', STR_PAD_LEFT);
        $tipoId   = ($r['identification_type'] ?? '') !== ''
                    ? (string)$r['identification_type']
                    : (strlen($rnc) === 11 ? '2' : '1');

        $amount   = (float)$r['amount'];
        $itbis    = (float)$r['itbis'];
        $itbisRet = (float)$r['itbis_retention'];
        $isrRet   = (float)$r['isr_retention'];
        $isc      = (float)($r['isc'] ?? 0);
        $otros    = (float)($r['other_taxes'] ?? 0);
        $propina  = (float)($r['propina_legal'] ?? 0);
        $isService = dgiiIsService($tipoBien);
        $itbisAdelantar = max(0, $itbis - $itbisRet);

        $tipoRetIsr = '';
        if ($isrRet > 0.005) {
            $tipoRetIsr = ($r['isr_retention_type'] ?? '')
                ?: (($tipoBien === '03') ? '01' : '02');
        }

        $out[] = [
            $rnc,                                                                       // 1
            dgiiLabelCode($tipoId, $catId, 1),                                          // 2
            dgiiLabelCode($tipoBien, $catBien),                                         // 3
            dgiiUpper($r['ncf']),                                                       // 4
            dgiiUpper($r['ncf_modified']),                                              // 5
            dgiiDate($r['date_doc']),                                                   // 6 YYYYMMDD
            dgiiDate($r['date_payment']),                                               // 7
            $isService ? dgiiTrimNum($amount) : '',                                     // 8
            $isService ? '' : dgiiTrimNum($amount),                                     // 9
            dgiiTrimNum($amount),                                                       // 10
            dgiiTrimNum($itbis, false),                                                 // 11
            dgiiTrimNum($itbisRet),                                                     // 12
            '',                                                                         // 13
            '',                                                                         // 14
            dgiiTrimNum($itbisAdelantar, false),                                        // 15
            '',                                                                         // 16
            $tipoRetIsr,                                                                // 17
            dgiiTrimNum($isrRet),                                                       // 18
            '',                                                                         // 19
            dgiiTrimNum($isc),                                                          // 20
            dgiiTrimNum($otros),                                                        // 21
            dgiiTrimNum($propina),                                                      // 22
            dgiiLabelCode($r['payment_method'] ?? '', $catPay),                         // 23
        ];

        // Reemplaza la celda Tipo Ret. ISR (indice 16) por su etiqueta legible.
        $out[count($out) - 1][16] = dgiiLabelCode($tipoRetIsr, $catRet);
    }
    return $out;
}
function dgiiExcelRows607($filing) {
    $catId   = function_exists('aiIdentificationTypes') ? aiIdentificationTypes() : [];
    $catIng  = function_exists('aiIncomeTypes')         ? aiIncomeTypes()         : [];

    $out = [];
    foreach ($filing['rows'] as $r) {
        $rnc    = dgiiDigits($r['rnc']);
        $tipoId = ($r['identification_type'] ?? '') !== ''
                  ? (string)$r['identification_type']
                  : ($rnc === '' ? '' : (strlen($rnc) === 11 ? '2' : '1'));
        $tipoIng = str_pad((string)($r['income_type'] ?: '01'), 2, '0', STR_PAD_LEFT);

        $amount   = (float)$r['amount'];
        $itbis    = (float)$r['itbis'];
        $itbisRet = (float)$r['itbis_retention'];
        $isrRet   = (float)$r['isr_retention'];
        $isc      = (float)($r['isc'] ?? 0);
        $otros    = (float)($r['other_taxes'] ?? 0);
        $propina  = (float)($r['propina_legal'] ?? 0);

        $payCols = ['','','','','','',''];
        $payIdx  = dgii607PaymentCol($r['payment_method'] ?? '');
        if ($payIdx !== null && $amount > 0) {
            $payCols[$payIdx - 17] = dgiiTrimNum($amount, false);
        }

        $out[] = [
            $rnc,                                                                       //  1
            dgiiLabelCode($tipoId, $catId, 1),                                          //  2
            dgiiUpper($r['ncf']),                                                       //  3
            dgiiUpper($r['ncf_modified']),                                              //  4
            dgiiLabelCode($tipoIng, $catIng),                                           //  5
            dgiiDate($r['date_doc']),                                                   //  6
            dgiiDate($r['date_payment']),                                               //  7
            dgiiTrimNum($amount, false),                                                //  8
            dgiiTrimNum($itbis, false),                                                 //  9
            dgiiTrimNum($itbisRet),                                                     // 10
            '',                                                                         // 11
            dgiiTrimNum($isrRet),                                                       // 12
            '',                                                                         // 13
            dgiiTrimNum($isc),                                                          // 14
            dgiiTrimNum($otros),                                                        // 15
            dgiiTrimNum($propina),                                                      // 16
            $payCols[0], $payCols[1], $payCols[2],                                      // 17-19
            $payCols[3], $payCols[4], $payCols[5],                                      // 20-22
            $payCols[6],                                                                // 23
        ];
    }
    return $out;
}
function dgiiExcelRows608($filing) {
    $catalog = function_exists('aiCancellationTypes') ? aiCancellationTypes() : [];
    $out = [];
    foreach ($filing['rows'] as $r) {
        $tipo = $r['tipo_anulacion'] ?? '';
        if ($tipo === '' || $tipo === null) $tipo = ($r['tax_type'] ?? '') ?: '02';
        $label = isset($catalog[$tipo]) ? "{$tipo} - {$catalog[$tipo]}" : $tipo;
        $out[] = [
            dgiiUpper($r['ncf']),
            dgiiDate($r['date_doc']),
            $label,
        ];
    }
    return $out;
}

/**
 * Genera un archivo Excel-compatible (HTML con mimetype xls).
 * Se abre nativamente en Excel y LibreOffice manteniendo formato y formulas.
 */
function dgiiExcelHtml($filing) {
    $type = $filing['filing_type'];
    $period = $filing['period'];
    $client = $filing['business_name'] ?: $filing['client_name'];
    $rnc = $filing['rnc'] ?: 'N/A';

    if ($type === '606') {
        $headers = dgiiExcelHeader606();
        $rows = dgiiExcelRows606($filing);
        $title = '606 - Compras de Bienes y Servicios';
    } elseif ($type === '607') {
        $headers = dgiiExcelHeader607();
        $rows = dgiiExcelRows607($filing);
        $title = '607 - Ventas de Bienes y Servicios';
    } elseif ($type === '608') {
        $headers = dgiiExcelHeader608();
        $rows = dgiiExcelRows608($filing);
        $title = '608 - NCF Anulados';
    } elseif ($type === 'IT-1') {
        return dgiiExcelHtmlIT1($filing);
    } else {
        return '';
    }

    $totalMonto = (float)$filing['total_amount'];
    $totalItbis = (float)$filing['total_itbis'];

    $css = '
        <style>
            body { font-family: Arial, sans-serif; }
            h1 { font-size: 16pt; color: #0F172A; }
            h2 { font-size: 11pt; color: #475569; font-weight: normal; margin: 0 0 12pt 0; }
            table { border-collapse: collapse; }
            th { background: #0F172A; color: #fff; padding: 8px; font-size: 10pt; border: 1px solid #1E293B; text-align: left; }
            td { padding: 6px 8px; font-size: 10pt; border: 1px solid #E5E7EB; }
            .num { mso-number-format: "#,##0.00"; text-align: right; }
            .total td { background: #F4F4F5; font-weight: bold; }
        </style>
    ';

    ob_start();
    ?>
    <!DOCTYPE html>
    <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">
    <head>
        <meta charset="UTF-8">
        <title><?= htmlspecialchars($title) ?></title>
        <?= $css ?>
    </head>
    <body>
        <h1><?= htmlspecialchars($title) ?></h1>
        <h2><?= htmlspecialchars($client) ?> &middot; RNC <?= htmlspecialchars($rnc) ?> &middot; Periodo <?= htmlspecialchars($period) ?></h2>
        <table>
            <thead>
                <tr>
                    <?php foreach ($headers as $h): ?><th><?= htmlspecialchars($h) ?></th><?php endforeach; ?>
                </tr>
            </thead>
            <tbody>
                <?php
                    // Indices (0-based) que contienen montos en cada tipo:
                    // 606: servicios(7), bienes(8), total(9), ITBIS(10), ITBIS ret(11), ITBIS adelantar(14), ret. renta(17), ISC(19), otros(20), propina(21)
                    // 607: monto(7), ITBIS(8), ITBIS ret(9), ret. renta(11), ISC(13), otros(14), propina(15), Efectivo..Otras(16..22)
                    $numIdx = $type === '606'
                        ? [7,8,9,10,11,14,17,19,20,21]
                        : ($type === '607' ? [7,8,9,11,13,14,15,16,17,18,19,20,21,22] : []);
                ?>
                <?php foreach ($rows as $row): ?>
                <tr>
                    <?php foreach ($row as $i => $cell):
                        $isNum = in_array($i, $numIdx, true) && $cell !== '';
                    ?>
                    <td class="<?= $isNum ? 'num' : '' ?>"><?= htmlspecialchars((string)$cell) ?></td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
                <?php if ($type === '606' || $type === '607'):
                    // Posicion de las columnas de monto e ITBIS por tipo (1-indexed):
                    // 606: total monto = col 10, ITBIS facturado = col 11 (23 columnas)
                    // 607: monto facturado = col 8, ITBIS = col 9 (16 columnas)
                    $montoCol = $type === '606' ? 10 : 8;
                    $itbisCol = $type === '606' ? 11 : 9;
                    $totalCols = count($headers);
                    $leading   = $montoCol - 1; // celdas antes de monto
                    $trailing  = $totalCols - $itbisCol;
                ?>
                <tr class="total">
                    <td colspan="<?= $leading ?>">TOTALES</td>
                    <td class="num"><?= number_format($totalMonto, 2, '.', ',') ?></td>
                    <td class="num"><?= number_format($totalItbis, 2, '.', ',') ?></td>
                    <?php if ($trailing > 0): ?><td colspan="<?= $trailing ?>"></td><?php endif; ?>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

/**
 * IT-1 (Declaracion Mensual de ITBIS) - resumen alineado con las casillas
 * oficiales del formulario en Oficina Virtual DGII.
 *  - I. Operaciones (Casillas 1-5)
 *  - II. ITBIS en Ventas (Casilla 6)
 *  - III. ITBIS en Compras (Casillas 7-9)
 *  - IV. Liquidacion (Casillas 13-16, 23, 26, 29)
 */
function dgiiExcelHtmlIT1($filing) {
    global $pdo;
    $clientId = $filing['client_id'];
    $period   = $filing['period'];
    $client   = $filing['business_name'] ?: $filing['client_name'];
    $rnc      = $filing['rnc'] ?: 'N/A';

    $f606 = dgiiFetchFiling($clientId, '606', $period);
    $f607 = dgiiFetchFiling($clientId, '607', $period);

    // ---- Ventas (desde 607) ----
    $totalOperaciones = 0.0; // C1
    $opGravadas       = 0.0; // C2
    $opExentas        = 0.0; // C3
    $itbisCobrado     = 0.0; // C6
    $itbisRetTerceros = 0.0; // C26 (ITBIS retenido por terceros en ventas)
    if (!empty($f607['rows'])) {
        foreach ($f607['rows'] as $r) {
            $amt   = (float)$r['amount'];
            $itb   = (float)$r['itbis'];
            $itbR  = (float)$r['itbis_retention'];
            $totalOperaciones += $amt;
            if ($itb > 0.005) $opGravadas += $amt; else $opExentas += $amt;
            $itbisCobrado     += $itb;
            $itbisRetTerceros += $itbR;
        }
    }

    // ---- Compras (desde 606) ----
    $itbisPagado    = 0.0; // suma ITBIS facturado en compras
    $itbisRetenido  = 0.0; // ITBIS retenido a proveedores
    $itbisAdelantar = 0.0; // C7 - ITBIS deducible
    if (!empty($f606['rows'])) {
        foreach ($f606['rows'] as $r) {
            $itb  = (float)$r['itbis'];
            $itbR = (float)$r['itbis_retention'];
            $itbisPagado    += $itb;
            $itbisRetenido  += $itbR;
            $itbisAdelantar += max(0, $itb - $itbR);
        }
    }

    // ---- Liquidacion ----
    // C13 ITBIS resultante = C6 - C7
    $resultante = $itbisCobrado - $itbisAdelantar;
    // C29 Saldo a pagar = C13 - C26 (ITBIS retenido por terceros nos lo descuentan)
    $aPagar = $resultante - $itbisRetTerceros;
    $esSaldoFavor = $aPagar < -0.005;

    $css = '
        <style>
            body { font-family: Arial, sans-serif; }
            h1 { font-size: 16pt; color: #0F172A; margin: 0 0 4pt 0; }
            h2 { font-size: 11pt; color: #475569; font-weight: normal; margin: 0 0 16pt 0; }
            h3 { font-size: 12pt; color: #0F172A; margin: 18pt 0 6pt 0; text-transform: uppercase; letter-spacing: 0.04em; }
            table { border-collapse: collapse; width: 760px; }
            th { background: #0F172A; color: #fff; padding: 8px 10px; font-size: 10pt; border: 1px solid #1E293B; text-align: left; }
            td { padding: 7px 10px; font-size: 10pt; border: 1px solid #E5E7EB; }
            .casilla { width: 70px; text-align: center; font-family: Consolas, monospace; color: #64748B; font-weight: bold; }
            .num { mso-number-format: "#,##0.00"; text-align: right; font-family: Consolas, monospace; }
            .section td { background: #F1F5F9; font-weight: bold; color: #0F172A; }
            .total td { background: #FEF3C7; font-weight: bold; font-size: 12pt; }
            .pos { color: #047857; }
            .neg { color: #B91C1C; }
            .muted { color: #94A3B8; font-size: 9pt; }
        </style>
    ';
    ob_start();
    ?>
    <!DOCTYPE html>
    <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel">
    <head>
        <meta charset="UTF-8">
        <title>IT-1 ITBIS</title>
        <?= $css ?>
    </head>
    <body>
        <h1>IT-1 &middot; Declaracion Mensual de ITBIS</h1>
        <h2><?= htmlspecialchars($client) ?> &middot; RNC <?= htmlspecialchars($rnc) ?> &middot; Periodo <?= htmlspecialchars($period) ?></h2>

        <table>
            <thead>
                <tr><th>Casilla</th><th>Concepto</th><th>Monto RD$</th></tr>
            </thead>
            <tbody>
                <tr class="section"><td colspan="3">I. Operaciones (Ventas del periodo)</td></tr>
                <tr><td class="casilla">1</td><td>Total Operaciones</td><td class="num"><?= number_format($totalOperaciones, 2, '.', ',') ?></td></tr>
                <tr><td class="casilla">2</td><td>Operaciones Gravadas</td><td class="num"><?= number_format($opGravadas, 2, '.', ',') ?></td></tr>
                <tr><td class="casilla">3</td><td>Operaciones Exentas</td><td class="num"><?= number_format($opExentas, 2, '.', ',') ?></td></tr>

                <tr class="section"><td colspan="3">II. ITBIS Cobrado en Ventas</td></tr>
                <tr><td class="casilla">6</td><td>ITBIS Facturado en Ventas (607)</td><td class="num"><?= number_format($itbisCobrado, 2, '.', ',') ?></td></tr>

                <tr class="section"><td colspan="3">III. ITBIS en Compras</td></tr>
                <tr><td class="casilla">7</td><td>ITBIS por Adelantar (606, deducible)</td><td class="num"><?= number_format($itbisAdelantar, 2, '.', ',') ?></td></tr>
                <tr><td class="casilla">&mdash;</td><td><span class="muted">ITBIS Pagado Total en Compras</span></td><td class="num muted"><?= number_format($itbisPagado, 2, '.', ',') ?></td></tr>
                <tr><td class="casilla">&mdash;</td><td><span class="muted">ITBIS Retenido a Proveedores</span></td><td class="num muted"><?= number_format($itbisRetenido, 2, '.', ',') ?></td></tr>

                <tr class="section"><td colspan="3">IV. Liquidacion</td></tr>
                <tr><td class="casilla">13</td><td>ITBIS Resultante (C6 &minus; C7)</td><td class="num"><?= number_format($resultante, 2, '.', ',') ?></td></tr>
                <tr><td class="casilla">26</td><td>(&minus;) ITBIS Retenido por Terceros</td><td class="num"><?= number_format($itbisRetTerceros, 2, '.', ',') ?></td></tr>
                <tr class="total">
                    <td class="casilla"><?= $esSaldoFavor ? '23' : '29' ?></td>
                    <td><?= $esSaldoFavor ? 'Saldo a Favor del Contribuyente' : 'ITBIS a Pagar a DGII' ?></td>
                    <td class="num <?= $esSaldoFavor ? 'pos' : 'neg' ?>"><?= number_format(abs($aPagar), 2, '.', ',') ?></td>
                </tr>
            </tbody>
        </table>

        <p style="margin-top:16pt; font-size:9pt; color:#94a3b8">
            Calculo automatico a partir de los formularios 606 y 607 del periodo. Las casillas hacen referencia al formulario IT-1 oficial de DGII.
            Para presentar el IT-1, ingresa estos valores en la Oficina Virtual antes del dia 20 del mes siguiente.
        </p>
    </body>
    </html>
    <?php
    return ob_get_clean();
}

function dgiiExcelFilename($filing) {
    $rnc = dgiiDigits($filing['rnc'] ?? '');
    $period = str_replace('-', '', $filing['period']);
    $type = $filing['filing_type'];
    return "DGII_F_{$type}_{$rnc}_{$period}.xls";
}

// --------------------------------------------------------------------------
// HTTP stream helpers
// --------------------------------------------------------------------------

function dgiiStreamTxt($filing) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: text/plain; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . dgiiTxtFilename($filing) . '"');
    echo dgiiTxt($filing);
    exit;
}

function dgiiStreamExcel($filing) {
    while (ob_get_level() > 0) { @ob_end_clean(); }
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . dgiiExcelFilename($filing) . '"');
    // BOM para Excel reconozca UTF-8
    echo "\xEF\xBB\xBF" . dgiiExcelHtml($filing);
    exit;
}

/**
 * Bundle ZIP con TXT + Excel de 606/607/608/IT-1 de un cliente y periodo.
 */
function dgiiStreamBundle($clientId, $period) {
    global $pdo;
    if (!class_exists('ZipArchive')) {
        http_response_code(500);
        exit('ZipArchive no disponible en este servidor.');
    }
    $client = $pdo->prepare("SELECT name, business_name, rnc FROM users WHERE id=?");
    $client->execute([$clientId]);
    $c = $client->fetch();
    if (!$c) { http_response_code(404); exit('Cliente no encontrado'); }

    $rnc = dgiiDigits($c['rnc'] ?: '');
    $periodKey = str_replace('-', '', $period);
    $tmp = tempnam(sys_get_temp_dir(), 'dgii_bundle_');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
        http_response_code(500); exit('No se pudo crear el ZIP');
    }

    foreach (['606','607','608','IT-1'] as $type) {
        $filing = dgiiFetchFiling($clientId, $type, $period);
        if (!$filing) continue;
        if ($type !== 'IT-1') {
            $zip->addFromString(dgiiTxtFilename($filing), dgiiTxt($filing));
        }
        $zip->addFromString(dgiiExcelFilename($filing), "\xEF\xBB\xBF" . dgiiExcelHtml($filing));
    }
    $zip->close();

    $bundleName = "DGII_{$rnc}_{$periodKey}.zip";
    header('Content-Type: application/zip');
    header('Content-Disposition: attachment; filename="' . $bundleName . '"');
    header('Content-Length: ' . filesize($tmp));
    readfile($tmp);
    @unlink($tmp);
    exit;
}
