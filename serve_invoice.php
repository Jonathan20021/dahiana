<?php
/**
 * serve_invoice.php — entrega el archivo original de una factura.
 *
 * Antes las imagenes se enlazaban directo a uploads/invoices/<archivo>. Ese
 * directorio vive bajo el docroot y `uploads/` esta en .gitignore, asi que en
 * produccion nacia sin ningun .htaccess: cualquiera con la URL (o con el
 * listado de Apache, si Options Indexes esta activo) veia las facturas de todos
 * los clientes con su RNC, NCF y montos, sin estar siquiera logueado.
 *
 * Ahora el unico camino al archivo pasa por aqui: sesion valida + dueno del
 * upload (o staff con ese cliente asignado).
 *
 * Uso:  serve_invoice.php?id=<upload_id>[&download=1]
 */
require_once 'config.php';

if (!isset($_SESSION['user_id'])) {
    http_response_code(401);
    exit('No autorizado.');
}

$uploadId = (int)($_GET['id'] ?? 0);
if ($uploadId <= 0) {
    http_response_code(400);
    exit('Solicitud invalida.');
}

$stmt = $pdo->prepare("SELECT id, client_id, filename, original_name, mime_type FROM invoice_uploads WHERE id = ?");
$stmt->execute([$uploadId]);
$upload = $stmt->fetch();

if (!$upload || !aiCanViewUpload($upload)) {
    // Mismo 404 para "no existe" y "no es tuyo": no confirmamos la existencia
    // de facturas ajenas a quien va probando ids.
    http_response_code(404);
    exit('Factura no encontrada.');
}

// basename() corta cualquier intento de path traversal si el nombre guardado en
// la BD fuera manipulado por otra via.
$path = aiUploadsDir() . '/' . basename((string)$upload['filename']);
if (!is_file($path)) {
    http_response_code(404);
    exit('El archivo ya no esta disponible.');
}

// Nunca confiamos en el mime guardado para decidir como responde el navegador:
// se sirve solo desde la lista blanca y, si no cuadra, como descarga opaca.
$mime = aiExtensionForMime($upload['mime_type']) ? $upload['mime_type'] : 'application/octet-stream';
$inline = $mime !== 'application/octet-stream' && empty($_GET['download']);

$safeName = preg_replace('/[^\w.\- ]+/u', '_', (string)($upload['original_name'] ?: $upload['filename']));
if ($safeName === '') $safeName = 'factura';

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $safeName . '"');
// El tipo ya se verifico por magic bytes al subir, y nosniff impide que el
// navegador reinterprete el contenido como HTML/JS en nuestro origen.
header('X-Content-Type-Options: nosniff');
if ($mime !== 'application/pdf') {
    // sandbox + object-src 'none' romperian el visor de PDF integrado del
    // navegador, asi que el CSP duro se aplica solo a las imagenes.
    header("Content-Security-Policy: default-src 'none'; img-src 'self'; object-src 'none'; sandbox");
}
// Privado: es documentacion fiscal de un cliente, no debe quedar en caches
// compartidos, pero si en la del navegador para no reenviar la imagen en cada
// scroll del listado.
header('Cache-Control: private, max-age=600, no-transform');
header('X-Frame-Options: SAMEORIGIN');

while (ob_get_level() > 0) ob_end_clean();
readfile($path);
