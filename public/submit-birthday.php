<?php
// Endpoint del beneficio de cumpleaños. Port PHP de la Netlify Function original.
// Servido por Nginx/PHP-FPM (deploy con Laravel Forge). Sin dependencias externas:
// MySQL vía PDO, SendGrid vía cURL, loader .env propio.

// ── Loader .env propio (el .env vive arriba del web root, nunca es accesible por HTTP) ──
function loadEnv($path) {
    if (!is_readable($path)) {
        return;
    }
    foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') {
            continue;
        }
        if (strpos($line, '=') === false) {
            continue;
        }
        list($key, $value) = explode('=', $line, 2);
        $key = trim($key);
        $value = trim($value);
        // Quitar comillas envolventes si las hay.
        if (strlen($value) >= 2) {
            $first = $value[0];
            $last = $value[strlen($value) - 1];
            if (($first === '"' && $last === '"') || ($first === "'" && $last === "'")) {
                $value = substr($value, 1, -1);
            }
        }
        if (getenv($key) === false) {
            putenv($key . '=' . $value);
            $_ENV[$key] = $value;
        }
    }
}

loadEnv(dirname(__DIR__) . '/.env');

function env($key, $default = null) {
    $value = getenv($key);
    return $value === false ? $default : $value;
}

// ── Configuración de grupos A/B (mismo mapeo que la versión Node) ──
$GROUPS = array(
    'A' => array(
        'sucursales'  => array('R&F Pilar', 'R&F Unicenter', 'R&F Palermo'),
        'apiKey'      => env('SENDGRID_API_KEY_GROUP_A'),
        'templateId'  => env('SENDGRID_TEMPLATE_ID_GROUP_A'),
        'senderEmail' => env('SENDGRID_SENDER_EMAIL_GROUP_A'),
        'senderName'  => env('SENDGRID_SENDER_NAME_GROUP_A'),
    ),
    'B' => array(
        'sucursales'  => array('R&F Alto Rosario', 'R&F Oroño', 'R&F Savoy'),
        'apiKey'      => env('SENDGRID_API_KEY_GROUP_B'),
        'templateId'  => env('SENDGRID_TEMPLATE_ID_GROUP_B'),
        'senderEmail' => env('SENDGRID_SENDER_EMAIL_GROUP_B'),
        'senderName'  => env('SENDGRID_SENDER_NAME_GROUP_B'),
    ),
);

$ALL_SUCURSALES = array_merge($GROUPS['A']['sucursales'], $GROUPS['B']['sucursales']);

$RATE_LIMIT_PER_MINUTE = 5;
$RATE_LIMIT_PER_HOUR = 20;

$EMAIL_RE = '/^[^\s@]+@[^\s@]+\.[^\s@]+$/';
$DATE_RE = '/^\d{4}-\d{2}-\d{2}$/';

function jsonResponse($statusCode, $body) {
    http_response_code($statusCode);
    header('Content-Type: application/json');
    echo json_encode($body);
    exit;
}

function isBirthdayToday($fecha) {
    $now = new DateTime('now', new DateTimeZone('America/Argentina/Buenos_Aires'));
    return substr($fecha, 5) === $now->format('m-d');
}

function getClientIp() {
    if (!empty($_SERVER['HTTP_X_FORWARDED_FOR'])) {
        $parts = explode(',', $_SERVER['HTTP_X_FORWARDED_FOR']);
        return trim($parts[0]);
    }
    if (!empty($_SERVER['REMOTE_ADDR'])) {
        return $_SERVER['REMOTE_ADDR'];
    }
    return 'unknown';
}

function groupForSucursal($sucursal) {
    global $GROUPS;
    if (in_array($sucursal, $GROUPS['A']['sucursales'], true)) {
        return $GROUPS['A'];
    }
    if (in_array($sucursal, $GROUPS['B']['sucursales'], true)) {
        return $GROUPS['B'];
    }
    return null;
}

// Envío de email de confirmación vía SendGrid (API REST v3 con cURL).
function sendConfirmationEmail($group, $email, $nombre, $sucursal, $fecha) {
    $payload = array(
        'from' => array(
            'email' => $group['senderEmail'],
        ),
        'personalizations' => array(
            array(
                'to' => array(
                    array('email' => $email),
                ),
                'dynamic_template_data' => array(
                    'nombre'   => $nombre,
                    'sucursal' => $sucursal,
                    'fecha'    => $fecha,
                ),
            ),
        ),
        'template_id' => $group['templateId'],
    );
    if (!empty($group['senderName'])) {
        $payload['from']['name'] = $group['senderName'];
    }

    $ch = curl_init('https://api.sendgrid.com/v3/mail/send');
    curl_setopt_array($ch, array(
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => array(
            'Authorization: Bearer ' . $group['apiKey'],
            'Content-Type: application/json',
        ),
        CURLOPT_POSTFIELDS     => json_encode($payload),
        CURLOPT_TIMEOUT        => 10,
    ));

    $response = curl_exec($ch);
    $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($response === false || $status >= 300) {
        error_log('Error enviando email de confirmación vía SendGrid: ' . $curlError . ' (HTTP ' . $status . ') ' . $response);
    }
}

// ── Handler ──
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    jsonResponse(405, array('ok' => false, 'reason' => 'method_not_allowed'));
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw === false ? '' : $raw, true);
if (!is_array($payload)) {
    jsonResponse(400, array('ok' => false, 'reason' => 'invalid_json'));
}

$nombre   = isset($payload['nombre']) ? $payload['nombre'] : null;
$fecha    = isset($payload['fecha']) ? $payload['fecha'] : null;
$email    = isset($payload['email']) ? $payload['email'] : null;
$sucursal = isset($payload['sucursal']) ? $payload['sucursal'] : null;
$terminos = isset($payload['terminos']) ? $payload['terminos'] : null;
$website  = isset($payload['website']) ? $payload['website'] : null;

// Honeypot: si un bot completó este campo oculto, respondemos como si
// hubiera funcionado, sin tocar la base ni enviar nada.
if (!empty($website)) {
    jsonResponse(200, array('ok' => true));
}

if (
    !is_string($nombre) || trim($nombre) === '' ||
    !is_string($fecha) || !preg_match($DATE_RE, trim($fecha)) ||
    !is_string($email) || !preg_match($EMAIL_RE, trim($email)) ||
    !is_string($sucursal) || !in_array($sucursal, $ALL_SUCURSALES, true) ||
    !$terminos
) {
    jsonResponse(400, array('ok' => false, 'reason' => 'invalid_input'));
}

$fecha = trim($fecha);

if (!isBirthdayToday($fecha)) {
    jsonResponse(403, array('ok' => false, 'reason' => 'not_birthday'));
}

$cleanEmail = strtolower(trim($email));
$cleanNombre = trim($nombre);
$ip = getClientIp();
$userAgent = isset($_SERVER['HTTP_USER_AGENT']) ? $_SERVER['HTTP_USER_AGENT'] : null;

try {
    $pdo = new PDO(
        'mysql:host=' . env('DATABASE_HOST', '127.0.0.1') .
        ';port=' . env('DATABASE_PORT', '3306') .
        ';dbname=' . env('DATABASE_NAME') . ';charset=utf8mb4',
        env('DATABASE_USER'),
        env('DATABASE_PASSWORD'),
        array(
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        )
    );

    // ── Rate limit por IP ──
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS count FROM submission_attempts
         WHERE ip = :ip AND created_at > NOW() - INTERVAL 1 MINUTE'
    );
    $stmt->execute(array(':ip' => $ip));
    $countMinute = (int) $stmt->fetch()['count'];

    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS count FROM submission_attempts
         WHERE ip = :ip AND created_at > NOW() - INTERVAL 1 HOUR'
    );
    $stmt->execute(array(':ip' => $ip));
    $countHour = (int) $stmt->fetch()['count'];

    if ($countMinute >= $RATE_LIMIT_PER_MINUTE || $countHour >= $RATE_LIMIT_PER_HOUR) {
        jsonResponse(429, array('ok' => false, 'reason' => 'rate_limited'));
    }

    $pdo->prepare('INSERT INTO submission_attempts (ip) VALUES (:ip)')
        ->execute(array(':ip' => $ip));

    // ── Duplicado / elegibilidad (una vez por año) ──
    // Deshabilitado a pedido: se permite que el mismo correo reclame más de una vez
    // por año (ej. un padre usa su propio correo para varios hijos). Se restaura
    // agregando aquí un SELECT sobre birthday_claims comparando claimed_at + 1 año.

    // ── Upsert del reclamo (una fila por email) ──
    $stmt = $pdo->prepare(
        'INSERT INTO birthday_claims
            (email, nombre, fecha_nacimiento, sucursal, acepta_marketing, ip, user_agent, claimed_at)
         VALUES (:email, :nombre, :fecha, :sucursal, 1, :ip, :user_agent, NOW())
         ON DUPLICATE KEY UPDATE
            nombre = VALUES(nombre),
            fecha_nacimiento = VALUES(fecha_nacimiento),
            sucursal = VALUES(sucursal),
            acepta_marketing = VALUES(acepta_marketing),
            ip = VALUES(ip),
            user_agent = VALUES(user_agent),
            claimed_at = VALUES(claimed_at)'
    );
    $stmt->execute(array(
        ':email'      => $cleanEmail,
        ':nombre'     => $cleanNombre,
        ':fecha'      => $fecha,
        ':sucursal'   => $sucursal,
        ':ip'         => $ip,
        ':user_agent' => $userAgent,
    ));
} catch (PDOException $e) {
    error_log('Error de base de datos en submit-birthday: ' . $e->getMessage());
    jsonResponse(500, array('ok' => false, 'reason' => 'server_error'));
}

// ── Email de confirmación (no bloquea la respuesta si falla) ──
$group = groupForSucursal($sucursal);
if ($group && $group['apiKey'] && $group['templateId'] && $group['senderEmail']) {
    sendConfirmationEmail($group, $cleanEmail, $cleanNombre, $sucursal, $fecha);
} else {
    error_log('Configuración de SendGrid incompleta para la sucursal: ' . $sucursal);
}

jsonResponse(200, array('ok' => true));
