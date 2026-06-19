<?php

ini_set('display_errors', 0);
error_reporting(E_ALL);
date_default_timezone_set('Africa/Abidjan');

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
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
define('SENAFOI_YEAR', 2026);

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

function inputJson(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function bearerToken(): string {
    $header = $_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '';
    if (!$header && function_exists('getallheaders')) {
        foreach (getallheaders() as $k => $v) {
            if (strtolower($k) === 'authorization') $header = $v;
        }
    }
    return preg_match('/Bearer\s+(.+)/i', $header, $m) ? trim($m[1]) : '';
}

function normalizeAccess($access): array {
    global $PAGE_KEYS;
    $normalized = [];
    foreach ($PAGE_KEYS as $key) {
        $item = is_array($access) && isset($access[$key]) && is_array($access[$key]) ? $access[$key] : [];
        $canView = !empty($item['canView']) || !empty($item['view']) || !empty($item['allowed']);
        $mode = ($item['mode'] ?? 'viewer') === 'editor' ? 'editor' : 'viewer';
        $normalized[$key] = ['canView' => $canView, 'mode' => $canView ? $mode : 'viewer'];
    }
    return $normalized;
}

function currentUser(): ?array {
    $token = bearerToken();
    if ($token === '') return null;
    $raw = base64_decode($token, true);
    if (!$raw) return null;
    $parts = explode(':', $raw);
    if (count($parts) !== 3) return null;
    [$id, $expires, $sig] = $parts;
    if ((int)$expires < time()) return null;
    $payload = $id . ':' . $expires;
    if (!hash_equals(hash_hmac('sha256', $payload, APP_SECRET), $sig)) return null;
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

function requireCarsUser(string $mode = 'viewer'): array {
    $row = currentUser();
    if (!$row) respond(['success' => false, 'message' => 'Session invalide.'], 401);
    if (!userCan($row, 'cars', $mode) && !userCan($row, 'pointage', $mode)) {
        respond(['success' => false, 'message' => 'Acces cars non autorise.'], 403);
    }
    return $row;
}

function ensureTables(): void {
    db()->exec("
        CREATE TABLE IF NOT EXISTS seminaire_cars (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          code VARCHAR(40) NOT NULL,
          nom VARCHAR(120) NOT NULL,
          capacite INT UNSIGNED NOT NULL DEFAULT 0,
          description TEXT DEFAULT NULL,
          annee INT NOT NULL DEFAULT 2026,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          updated_at DATETIME DEFAULT NULL,
          PRIMARY KEY (id),
          UNIQUE KEY uq_seminaire_cars_code_annee (code, annee)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");

    $columns = [];
    $stmt = db()->query("SHOW COLUMNS FROM seminaire_cars");
    foreach ($stmt->fetchAll() as $col) {
        $columns[strtolower($col['Field'])] = true;
    }

    $migrations = [
        'code' => "ALTER TABLE seminaire_cars ADD COLUMN code VARCHAR(40) NOT NULL AFTER id",
        'nom' => "ALTER TABLE seminaire_cars ADD COLUMN nom VARCHAR(120) NOT NULL DEFAULT '' AFTER code",
        'capacite' => "ALTER TABLE seminaire_cars ADD COLUMN capacite INT UNSIGNED NOT NULL DEFAULT 0 AFTER nom",
        'description' => "ALTER TABLE seminaire_cars ADD COLUMN description TEXT DEFAULT NULL AFTER capacite",
        'annee' => "ALTER TABLE seminaire_cars ADD COLUMN annee INT NOT NULL DEFAULT 2026 AFTER description",
        'created_at' => "ALTER TABLE seminaire_cars ADD COLUMN created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER annee",
        'updated_at' => "ALTER TABLE seminaire_cars ADD COLUMN updated_at DATETIME DEFAULT NULL AFTER created_at",
    ];

    foreach ($migrations as $column => $sql) {
        if (empty($columns[$column])) {
            db()->exec($sql);
        }
    }

    $indexes = db()->query("SHOW INDEX FROM seminaire_cars WHERE Key_name = 'uq_seminaire_cars_code_annee'")->fetchAll();
    if (!$indexes) {
        try {
            db()->exec("ALTER TABLE seminaire_cars ADD UNIQUE KEY uq_seminaire_cars_code_annee (code, annee)");
        } catch (Throwable $e) {
            // Old duplicate data should not block the cars screen; create/update actions still validate by code.
        }
    }
}

function carOccupancySql(): string {
    return "
        SELECT c.id, c.code, c.nom, c.capacite, c.description, c.annee, c.created_at, c.updated_at,
               COUNT(s.id) AS assigned_count,
               GREATEST(c.capacite - COUNT(s.id), 0) AS places_restantes
        FROM seminaire_cars c
        LEFT JOIN seminaristes s
          ON s.car_transport = c.code
         AND CAST(s.annee_seminaire AS CHAR) = CAST(c.annee AS CHAR)
         AND s.statut_paiement = 'PAYE'
         AND s.statut_inscription = 'VALIDEE'
        WHERE CAST(c.annee AS CHAR) = ?
        GROUP BY c.id, c.code, c.nom, c.capacite, c.description, c.annee, c.created_at, c.updated_at
        ORDER BY c.code ASC, c.id ASC
    ";
}

function getCarByCode(string $code): ?array {
    $stmt = db()->prepare("SELECT * FROM seminaire_cars WHERE code = ? AND CAST(annee AS CHAR) = ? LIMIT 1");
    $stmt->execute([strtoupper(trim($code)), (string)SENAFOI_YEAR]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function carAssignedCount(string $code): int {
    $stmt = db()->prepare("
        SELECT COUNT(*) AS total
        FROM seminaristes
        WHERE car_transport = ?
          AND CAST(annee_seminaire AS CHAR) = ?
          AND statut_paiement = 'PAYE'
          AND statut_inscription = 'VALIDEE'
    ");
    $stmt->execute([strtoupper(trim($code)), (string)SENAFOI_YEAR]);
    return (int)($stmt->fetch()['total'] ?? 0);
}

try {
    ensureTables();
    $action = $_GET['action'] ?? '';

    if ($action === 'list') {
        requireCarsUser('viewer');
        $stmt = db()->prepare(carOccupancySql());
        $stmt->execute([(string)SENAFOI_YEAR]);
        respond(['success' => true, 'cars' => $stmt->fetchAll()]);
    }

    if ($action === 'create') {
        requireCarsUser('editor');
        $data = inputJson();
        $code = strtoupper(preg_replace('/[^A-Z0-9_-]/i', '', $data['code'] ?? ''));
        $nom = trim((string)($data['nom'] ?? ''));
        $capacite = max(0, (int)($data['capacite'] ?? 0));
        $description = trim((string)($data['description'] ?? ''));
        if ($code === '' || $nom === '') respond(['success' => false, 'message' => 'Code et nom requis.'], 400);
        $stmt = db()->prepare("
            INSERT INTO seminaire_cars (code, nom, capacite, description, annee, created_at, updated_at)
            VALUES (?, ?, ?, ?, ?, NOW(), NOW())
        ");
        $stmt->execute([$code, $nom, $capacite, $description, SENAFOI_YEAR]);
        respond(['success' => true, 'message' => 'Car cree avec succes.']);
    }

    if ($action === 'update') {
        requireCarsUser('editor');
        $data = inputJson();
        $id = (int)($data['id'] ?? 0);
        $code = strtoupper(preg_replace('/[^A-Z0-9_-]/i', '', $data['code'] ?? ''));
        $nom = trim((string)($data['nom'] ?? ''));
        $capacite = max(0, (int)($data['capacite'] ?? 0));
        $description = trim((string)($data['description'] ?? ''));
        if ($id <= 0 || $code === '' || $nom === '') respond(['success' => false, 'message' => 'Donnees car invalides.'], 400);

        $current = db()->prepare("SELECT * FROM seminaire_cars WHERE id = ? LIMIT 1");
        $current->execute([$id]);
        $car = $current->fetch();
        if (!$car) respond(['success' => false, 'message' => 'Car introuvable.'], 404);

        $assigned = carAssignedCount($car['code']);
        if ($capacite < $assigned) {
            respond(['success' => false, 'message' => "Quota impossible: $assigned seminaristes sont deja affectes a ce car."], 400);
        }

        $stmt = db()->prepare("UPDATE seminaire_cars SET code = ?, nom = ?, capacite = ?, description = ?, updated_at = NOW() WHERE id = ?");
        $stmt->execute([$code, $nom, $capacite, $description, $id]);
        if ($code !== $car['code']) {
            $up = db()->prepare("UPDATE seminaristes SET car_transport = ?, updated_at = NOW() WHERE car_transport = ? AND CAST(annee_seminaire AS CHAR) = ?");
            $up->execute([$code, $car['code'], (string)SENAFOI_YEAR]);
        }
        respond(['success' => true, 'message' => 'Car mis a jour.']);
    }

    if ($action === 'seminaristes') {
        requireCarsUser('viewer');
        $carCode = strtoupper(trim((string)($_GET['car_code'] ?? '')));
        $stmt = db()->prepare("
            SELECT id, matricule_seminaire, nom, prenom, sexe, contact, photo, niveau_seminaire, car_transport, date_inscription
            FROM seminaristes
            WHERE car_transport = ?
              AND CAST(annee_seminaire AS CHAR) = ?
              AND statut_paiement = 'PAYE'
              AND statut_inscription = 'VALIDEE'
            ORDER BY nom ASC, prenom ASC
            LIMIT 500
        ");
        $stmt->execute([$carCode, (string)SENAFOI_YEAR]);
        respond(['success' => true, 'seminaristes' => $stmt->fetchAll()]);
    }

    if ($action === 'search_seminaristes') {
        requireCarsUser('viewer');
        $q = '%' . trim((string)($_GET['q'] ?? '')) . '%';
        $stmt = db()->prepare("
            SELECT id, matricule_seminaire, nom, prenom, sexe, contact, photo, niveau_seminaire, car_transport
            FROM seminaristes
            WHERE CAST(annee_seminaire AS CHAR) = ?
              AND statut_paiement = 'PAYE'
              AND statut_inscription = 'VALIDEE'
              AND (matricule_seminaire LIKE ? OR nom LIKE ? OR prenom LIKE ? OR CONCAT(prenom, ' ', nom) LIKE ?)
            ORDER BY nom ASC, prenom ASC
            LIMIT 40
        ");
        $stmt->execute([(string)SENAFOI_YEAR, $q, $q, $q, $q]);
        respond(['success' => true, 'seminaristes' => $stmt->fetchAll()]);
    }

    if ($action === 'reassign') {
        $admin = requireCarsUser('editor');
        $data = inputJson();
        $matricule = strtoupper(trim((string)($data['matricule'] ?? '')));
        $carCode = strtoupper(trim((string)($data['car_code'] ?? '')));
        $car = getCarByCode($carCode);
        if (!$car) respond(['success' => false, 'message' => 'Car cible introuvable.'], 404);

        $assigned = carAssignedCount($carCode);
        if ($assigned >= (int)$car['capacite']) {
            respond(['success' => false, 'message' => 'Quota du car cible atteint.'], 400);
        }

        $stmt = db()->prepare("
            SELECT id, car_transport
            FROM seminaristes
            WHERE matricule_seminaire = ?
              AND CAST(annee_seminaire AS CHAR) = ?
              AND statut_paiement = 'PAYE'
              AND statut_inscription = 'VALIDEE'
            LIMIT 1
        ");
        $stmt->execute([$matricule, (string)SENAFOI_YEAR]);
        $s = $stmt->fetch();
        if (!$s) respond(['success' => false, 'message' => 'Seminariste valide introuvable.'], 404);

        $old = $s['car_transport'] ?? '';
        $up = db()->prepare("UPDATE seminaristes SET car_transport = ?, updated_at = NOW() WHERE id = ?");
        $up->execute([$carCode, (int)$s['id']]);

        db()->prepare("
            INSERT INTO seminaire_attributions_log
                (id_seminaire, type_attribution, ancienne_valeur, nouvelle_valeur, raison, attribue_par, created_at)
            VALUES (?, 'REAFFECTATION_CAR_ADMIN', ?, ?, ?, ?, NOW())
        ")->execute([(int)$s['id'], $old, $carCode, 'Reaffectation manuelle depuis admin cars', $admin['matricule'] ?? 'admin']);

        respond(['success' => true, 'message' => 'Seminariste reaffecte avec succes.']);
    }

    respond(['success' => false, 'message' => 'Action inconnue.'], 404);
} catch (Throwable $e) {
    respond(['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()], 500);
}
