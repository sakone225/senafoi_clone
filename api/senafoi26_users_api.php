<?php

ini_set('display_errors', 0);
error_reporting(E_ALL);

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

define('DB_HOST', getenv('SENAFOI_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('SENAFOI_DB_NAME') ?: 'capbvkkqah_aeemci');
define('DB_USER', getenv('SENAFOI_DB_USER') ?: 'capbvkkqah_aeemci');
define('DB_PASS', getenv('SENAFOI_DB_PASS') ?: '0Objectif-');
define('APP_SECRET', getenv('SENAFOI_USERS_SECRET') ?: 'senafoi26_users_secret_change_me');

$PAGE_KEYS = [
    'dashboard', 'seminars', 'participants', 'quota', 'cars', 'speakers', 'rooms',
    'evaluations', 'sante', 'pointage', 'paiements_configuration', 'reports', 'settings', 'users'
];

function respond(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_TIMEOUT => 8,
        ]
    );
    return $pdo;
}

function ensureTable(): void {
    db()->exec("
        CREATE TABLE IF NOT EXISTS senafoi26_users (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          matricule VARCHAR(20) NOT NULL,
          password_hash VARCHAR(255) NOT NULL,
          nom VARCHAR(120) NOT NULL,
          prenom VARCHAR(160) NOT NULL,
          contact VARCHAR(40) DEFAULT NULL,
          photo TEXT DEFAULT NULL,
          access_json JSON DEFAULT NULL,
          actif TINYINT(1) NOT NULL DEFAULT 1,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          UNIQUE KEY uq_senafoi26_users_matricule (matricule)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function inputJson(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function normalizeAccess($access): array {
    global $PAGE_KEYS;
    $normalized = [];
    foreach ($PAGE_KEYS as $key) {
        $item = is_array($access) && isset($access[$key]) && is_array($access[$key]) ? $access[$key] : [];
        $canView = !empty($item['canView']) || !empty($item['view']) || !empty($item['allowed']);
        $mode = ($item['mode'] ?? 'viewer') === 'editor' ? 'editor' : 'viewer';
        $normalized[$key] = [
            'canView' => $canView,
            'mode' => $canView ? $mode : 'viewer',
        ];
    }
    if (
        empty($normalized['cars']['canView'])
        && (
            !empty($normalized['quota']['canView'])
            || !empty($normalized['pointage']['canView'])
            || !empty($normalized['users']['canView'])
        )
    ) {
        $sourceMode = 'viewer';
        foreach (['quota', 'pointage', 'users'] as $sourceKey) {
            if (($normalized[$sourceKey]['mode'] ?? 'viewer') === 'editor') {
                $sourceMode = 'editor';
                break;
            }
        }
        $normalized['cars'] = ['canView' => true, 'mode' => $sourceMode];
    }
    return $normalized;
}

function defaultAdminAccess(): array {
    global $PAGE_KEYS;
    $access = [];
    foreach ($PAGE_KEYS as $key) {
        $access[$key] = ['canView' => true, 'mode' => 'editor'];
    }
    return $access;
}

function publicUser(array $row): array {
    $access = json_decode($row['access_json'] ?? '{}', true);
    if (!is_array($access)) $access = [];
    return [
        'id' => (int)$row['id'],
        'matricule' => $row['matricule'],
        'nom' => $row['nom'],
        'prenom' => $row['prenom'],
        'name' => trim(($row['prenom'] ?? '') . ' ' . ($row['nom'] ?? '')),
        'contact' => $row['contact'],
        'photo' => $row['photo'],
        'role' => 'Utilisateur',
        'access' => normalizeAccess($access),
        'actif' => (int)$row['actif'],
        'created_at' => $row['created_at'] ?? null,
    ];
}

function createToken(int $userId): string {
    $expires = time() + (60 * 60 * 12);
    $payload = $userId . ':' . $expires;
    $sig = hash_hmac('sha256', $payload, APP_SECRET);
    return base64_encode($payload . ':' . $sig);
}

function currentUser(): ?array {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (!preg_match('/Bearer\s+(.+)/i', $header, $m)) return null;
    $raw = base64_decode($m[1], true);
    if (!$raw) return null;
    $parts = explode(':', $raw);
    if (count($parts) !== 3) return null;
    [$id, $expires, $sig] = $parts;
    if ((int)$expires < time()) return null;
    $payload = $id . ':' . $expires;
    $expected = hash_hmac('sha256', $payload, APP_SECRET);
    if (!hash_equals($expected, $sig)) return null;
    $stmt = db()->prepare("SELECT * FROM senafoi26_users WHERE id = ? AND actif = 1 LIMIT 1");
    $stmt->execute([(int)$id]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function userCan(array $row, string $pageKey, string $mode = 'viewer'): bool {
    $access = normalizeAccess(json_decode($row['access_json'] ?? '{}', true));
    if (empty($access[$pageKey]['canView'])) return false;
    if ($mode === 'editor') return ($access[$pageKey]['mode'] ?? 'viewer') === 'editor';
    return true;
}

function requireUser(string $pageKey, string $mode = 'viewer'): array {
    $row = currentUser();
    if (!$row) respond(['success' => false, 'message' => 'Session invalide.'], 401);
    if (!userCan($row, $pageKey, $mode)) respond(['success' => false, 'message' => 'AccÃ¨s non autorisÃ©.'], 403);
    return $row;
}

function generateMatricule(): string {
    $prefix = 'COM' . date('y');
    do {
        $matricule = $prefix . str_pad((string)random_int(1, 9999), 4, '0', STR_PAD_LEFT);
        $stmt = db()->prepare("SELECT id FROM senafoi26_users WHERE matricule = ? LIMIT 1");
        $stmt->execute([$matricule]);
    } while ($stmt->fetch());
    return $matricule;
}

function requireFields(array $data, array $fields): void {
    foreach ($fields as $field) {
        if (!isset($data[$field]) || trim((string)$data[$field]) === '') {
            respond(['success' => false, 'message' => "Le champ {$field} est requis."], 400);
        }
    }
}

try {
    ensureTable();
    $action = $_GET['action'] ?? '';

    if ($action === 'login') {
        $data = inputJson();
        requireFields($data, ['matricule', 'password']);
        $stmt = db()->prepare("SELECT * FROM senafoi26_users WHERE matricule = ? AND actif = 1 LIMIT 1");
        $stmt->execute([strtoupper(trim($data['matricule']))]);
        $row = $stmt->fetch();
        if (!$row || !password_verify((string)$data['password'], $row['password_hash'])) {
            respond(['success' => false, 'message' => 'Matricule ou mot de passe incorrect.'], 401);
        }
        respond(['success' => true, 'user' => publicUser($row), 'token' => createToken((int)$row['id'])]);
    }

    if ($action === 'list') {
        requireUser('users', 'viewer');
        $rows = db()->query("SELECT * FROM senafoi26_users ORDER BY id DESC")->fetchAll();
        respond(['success' => true, 'users' => array_map('publicUser', $rows)]);
    }

    if ($action === 'create') {
        requireUser('users', 'editor');
        $data = inputJson();
        requireFields($data, ['nom', 'prenom', 'password']);
        $matricule = !empty($data['matricule']) ? strtoupper(trim($data['matricule'])) : generateMatricule();
        $access = normalizeAccess($data['access'] ?? []);

        $stmt = db()->prepare("
            INSERT INTO senafoi26_users
                (matricule, password_hash, nom, prenom, contact, photo, access_json, actif, created_at)
            VALUES
                (?, ?, ?, ?, ?, ?, ?, 1, NOW())
        ");
        $stmt->execute([
            $matricule,
            password_hash((string)$data['password'], PASSWORD_DEFAULT),
            strtoupper(trim($data['nom'])),
            trim($data['prenom']),
            trim($data['contact'] ?? ''),
            trim($data['photo'] ?? ''),
            json_encode($access, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);

        $id = (int)db()->lastInsertId();
        $row = db()->prepare("SELECT * FROM senafoi26_users WHERE id = ?");
        $row->execute([$id]);
        respond(['success' => true, 'message' => 'Utilisateur crÃ©Ã©.', 'user' => publicUser($row->fetch())]);
    }

    if ($action === 'update') {
        requireUser('users', 'editor');
        $data = inputJson();
        $id = (int)($data['id'] ?? 0);
        if ($id <= 0) respond(['success' => false, 'message' => 'ID utilisateur requis.'], 400);
        requireFields($data, ['nom', 'prenom']);

        $params = [
            strtoupper(trim($data['nom'])),
            trim($data['prenom']),
            trim($data['contact'] ?? ''),
            trim($data['photo'] ?? ''),
            json_encode(normalizeAccess($data['access'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            !empty($data['actif']) ? 1 : 0,
        ];

        $sql = "UPDATE senafoi26_users SET nom = ?, prenom = ?, contact = ?, photo = ?, access_json = ?, actif = ?";
        if (!empty($data['password'])) {
            $sql .= ", password_hash = ?";
            $params[] = password_hash((string)$data['password'], PASSWORD_DEFAULT);
        }
        $sql .= ", updated_at = NOW() WHERE id = ?";
        $params[] = $id;

        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        $row = db()->prepare("SELECT * FROM senafoi26_users WHERE id = ?");
        $row->execute([$id]);
        respond(['success' => true, 'message' => 'Utilisateur mis Ã  jour.', 'user' => publicUser($row->fetch())]);
    }

    if ($action === 'bootstrap_admin') {
        $count = (int)db()->query("SELECT COUNT(*) AS total FROM senafoi26_users")->fetch()['total'];
        if ($count > 0) respond(['success' => false, 'message' => 'Un utilisateur existe dÃ©jÃ .'], 409);
        $password = $_GET['password'] ?? 'admin1234';
        $stmt = db()->prepare("
            INSERT INTO senafoi26_users
                (matricule, password_hash, nom, prenom, contact, photo, access_json, actif, created_at)
            VALUES
                ('COM260001', ?, 'ADMIN', 'Administrateur', '', '', ?, 1, NOW())
        ");
        $stmt->execute([
            password_hash($password, PASSWORD_DEFAULT),
            json_encode(defaultAdminAccess(), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        ]);
        respond(['success' => true, 'matricule' => 'COM260001', 'password' => $password]);
    }

    respond(['success' => false, 'message' => 'Action inconnue.'], 404);
} catch (Throwable $e) {
    respond(['success' => false, 'message' => 'Erreur serveur', 'details' => $e->getMessage()], 500);
}

