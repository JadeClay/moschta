<?php
session_start();
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(204);
    exit;
}

// ============================================
// LOAD ENVIRONMENT VARIABLES FROM .env
// ============================================
function loadEnv($file) {
    if (!file_exists($file)) return;
    $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '' || str_starts_with($line, '#')) continue;
        [$key, $val] = explode('=', $line, 2);
        $key = trim($key);
        $val = trim($val);
        putenv("$key=$val");
        $_ENV[$key] = $val;
    }
}
loadEnv(__DIR__ . '/.env');

define('DB_HOST', getenv('DB_HOST'));
define('DB_NAME', getenv('DB_NAME'));
define('DB_USER', getenv('DB_USER'));
define('DB_PASS', getenv('DB_PASS'));
define('ADMIN_EMAIL', getenv('ADMIN_EMAIL'));
define('ADMIN_PASSWORD', getenv('ADMIN_PASSWORD'));

// ============================================
// DATABASE CONNECTION
// ============================================
function getDB() {
    static $pdo = null;
    if ($pdo === null) {
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4';
        $pdo = new PDO($dsn, DB_USER, DB_PASS, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
    return $pdo;
}

// ============================================
// JSON RESPONSE HELPERS
// ============================================
function jsonSuccess($data = null, $code = 200) {
    http_response_code($code);
    echo json_encode(['success' => true, 'data' => $data]);
    exit;
}

function jsonError($message, $code = 400) {
    http_response_code($code);
    echo json_encode(['success' => false, 'error' => $message]);
    exit;
}

// ============================================
// AUTH CHECK
// ============================================
function requireAuth() {
    if (empty($_SESSION['admin_logged_in'])) {
        jsonError('Unauthorized', 401);
    }
}

// ============================================
// ROUTING
// ============================================
$action = isset($_GET['action']) ? $_GET['action'] : '';
$method = $_SERVER['REQUEST_METHOD'];

switch ($action) {

    // ---- AUTH: Login ----
    case 'login':
        if ($method !== 'POST') jsonError('POST required');
        $input = json_decode(file_get_contents('php://input'), true);
        $email = $input['email'] ?? '';
        $password = $input['password'] ?? '';
        if ($email === ADMIN_EMAIL && $password === ADMIN_PASSWORD) {
            $_SESSION['admin_logged_in'] = true;
            jsonSuccess(['message' => 'Logged in']);
        } else {
            jsonError('Invalid credentials', 401);
        }
        break;

    // ---- AUTH: Logout ----
    case 'logout':
        session_destroy();
        jsonSuccess(['message' => 'Logged out']);
        break;

    // ---- AUTH: Check session ----
    case 'check':
        if (!empty($_SESSION['admin_logged_in'])) {
            jsonSuccess(['logged_in' => true]);
        } else {
            jsonSuccess(['logged_in' => false]);
        }
        break;

    // ---- MEMORIAS: List all ----
    case 'memorias':
        $db = getDB();
        if (!empty($_GET['year'])) {
            $stmt = $db->prepare('SELECT id, title, year, description, file_path, created_at FROM memorias WHERE year = ? ORDER BY created_at DESC');
            $stmt->execute([$_GET['year']]);
        } else {
            $stmt = $db->query('SELECT id, title, year, description, file_path, created_at FROM memorias ORDER BY year DESC, created_at DESC');
        }
        $list = $stmt->fetchAll();
        jsonSuccess($list);
        break;

    // ---- MEMORIAS: List distinct years ----
    case 'memorias-years':
        $db = getDB();
        $stmt = $db->query('SELECT year, COUNT(*) as count FROM memorias GROUP BY year ORDER BY year DESC');
        $years = $stmt->fetchAll();
        jsonSuccess($years);
        break;

    // ---- MEMORIAS: Upload PDF ----
    case 'memoria-upload':
        if ($method !== 'POST') jsonError('POST required');
        requireAuth();
        $title = trim($_POST['title'] ?? '');
        $year = trim($_POST['year'] ?? '');
        $description = trim($_POST['description'] ?? '');
        if (!$title || !$year) jsonError('Title and year are required');
        if (empty($_FILES['file'])) jsonError('No file uploaded');
        $file = $_FILES['file'];
        if ($file['error'] !== UPLOAD_ERR_OK) jsonError('Upload error: ' . $file['error']);
        $maxSize = 20 * 1024 * 1024;
        if ($file['size'] > $maxSize) jsonError('File too large (max 20 MB)');
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($file['tmp_name']);
        if ($mime !== 'application/pdf') jsonError('Only PDF files are allowed');
        $name = uniqid('mem_', true) . '.pdf';
        $dir = __DIR__ . '/uploads/memorias';
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        $dest = $dir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) jsonError('Failed to save file');
        $filePath = 'uploads/memorias/' . $name;
        $db = getDB();
        $stmt = $db->prepare('INSERT INTO memorias (title, year, description, file_path) VALUES (?, ?, ?, ?)');
        $stmt->execute([$title, $year, $description, $filePath]);
        jsonSuccess(['id' => $db->lastInsertId(), 'message' => 'Memoria uploaded'], 201);
        break;

    // ---- MEMORIAS: Delete ----
    case 'memoria-delete':
        if ($method !== 'POST') jsonError('POST required');
        requireAuth();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? '';
        if (!$id) jsonError('Missing memoria ID');
        $db = getDB();
        $stmt = $db->prepare('SELECT file_path FROM memorias WHERE id = ?');
        $stmt->execute([$id]);
        $mem = $stmt->fetch();
        if (!$mem) jsonError('Memoria not found', 404);
        $stmt = $db->prepare('DELETE FROM memorias WHERE id = ?');
        $stmt->execute([$id]);
        if (!empty($mem['file_path'])) {
            $file = __DIR__ . '/' . $mem['file_path'];
            if (is_file($file)) unlink($file);
        }
        jsonSuccess(['message' => 'Memoria deleted']);
        break;

    // ---- VOLUNTEER: Submit form ----
    case 'volunteer':
        if ($method !== 'POST') jsonError('POST required');
        $input = json_decode(file_get_contents('php://input'), true);

        $name = trim($input['name'] ?? '');
        $email = trim($input['email'] ?? '');
        $phone = trim($input['phone'] ?? '');
        $area = trim($input['area'] ?? '');
        $message = trim($input['message'] ?? '');
        $website = trim($input['website'] ?? '');
        $pageLoadTime = floatval($input['pageLoadTime'] ?? 0);
        $submitTime = floatval($input['submitTime'] ?? 0);

        // --- Required fields ---
        if (!$name || !$email || !$message) {
            jsonError('Nombre, correo electrónico y mensaje son obligatorios.');
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            jsonError('Correo electrónico inválido.');
        }

        // --- Honeypot check ---
        if ($website !== '') {
            jsonError('Bot detected', 403);
        }

        // --- Time gate (must be >= 3 seconds on page) ---
        $elapsed = ($submitTime - $pageLoadTime) / 1000;
        if ($elapsed < 3) {
            jsonError('Por favor espera unos segundos antes de enviar.', 429);
        }

        // --- Rate limit: max 3 submissions per hour per IP ---
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $db = getDB();
        $stmt = $db->prepare('SELECT COUNT(*) as cnt FROM volunteer_submissions WHERE ip_address = ? AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)');
        $stmt->execute([$ip]);
        $row = $stmt->fetch();
        if ($row['cnt'] >= 3) {
            jsonError('Has excedido el límite de envíos. Intenta más tarde.', 429);
        }

        // --- Insert ---
        $stmt = $db->prepare('INSERT INTO volunteer_submissions (name, email, phone, area, message, ip_address, notified) VALUES (?, ?, ?, ?, ?, ?, 1)');
        $stmt->execute([$name, $email, $phone, $area, $message, $ip]);

        jsonSuccess(['message' => 'Solicitud enviada con éxito.'], 201);
        break;

    // ---- VOLUNTEER: List (admin) ----
    case 'volunteers':
        requireAuth();
        $db = getDB();
        $stmt = $db->query('SELECT id, name, email, phone, area, message, ip_address, notified, created_at FROM volunteer_submissions ORDER BY id DESC LIMIT 500');
        jsonSuccess($stmt->fetchAll());
        break;

    // ---- VOLUNTEER: Delete (admin) ----
    case 'volunteer-delete':
        if ($method !== 'POST') jsonError('POST required');
        requireAuth();
        $input = json_decode(file_get_contents('php://input'), true);
        $id = $input['id'] ?? '';
        if (!$id) jsonError('Missing submission ID');
        $db = getDB();
        $stmt = $db->prepare('DELETE FROM volunteer_submissions WHERE id = ?');
        $stmt->execute([$id]);
        jsonSuccess(['message' => 'Submission deleted']);
        break;

    default:
        jsonError('Invalid action', 404);
}
