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

function requirePointageUser(string $mode = 'viewer'): array {
    $row = currentUser();
    if (!$row) respond(['success' => false, 'message' => 'Session invalide.'], 401);
    $allowed = userCan($row, 'pointage', $mode) || userCan($row, 'participants', $mode === 'editor' ? 'editor' : 'viewer');
    if (!$allowed) respond(['success' => false, 'message' => 'AccÃ¨s pointage non autorisÃ©.'], 403);
    return $row;
}

function ensureTables(): void {
    db()->exec("
        CREATE TABLE IF NOT EXISTS senafoi26_pointages (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          seminariste_id INT UNSIGNED DEFAULT NULL,
          matricule VARCHAR(40) NOT NULL,
          type VARCHAR(40) NOT NULL,
          date_pointage DATE NOT NULL,
          repas VARCHAR(30) DEFAULT NULL,
          cours_index TINYINT DEFAULT NULL,
          car_code VARCHAR(40) DEFAULT NULL,
          dortoir_code VARCHAR(40) DEFAULT NULL,
          created_by VARCHAR(30) DEFAULT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_senafoi26_pointages_mat (matricule),
          KEY idx_senafoi26_pointages_type_date (type, date_pointage)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
    db()->exec("
        CREATE TABLE IF NOT EXISTS senafoi26_conduites (
          id INT UNSIGNED NOT NULL AUTO_INCREMENT,
          seminariste_id INT UNSIGNED DEFAULT NULL,
          matricule VARCHAR(40) NOT NULL,
          motif VARCHAR(180) NOT NULL,
          description TEXT DEFAULT NULL,
          created_by VARCHAR(30) DEFAULT NULL,
          created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
          PRIMARY KEY (id),
          KEY idx_senafoi26_conduites_mat (matricule)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
    ");
}

function carHasCapacity(string $code, int $excludeSeminaristeId = 0): bool {
    $code = strtoupper(trim($code));
    if ($code === '') return false;
    $stmt = db()->prepare("SELECT capacite FROM seminaire_cars WHERE code = ? AND CAST(annee AS CHAR) = ? LIMIT 1");
    $stmt->execute([$code, (string)SENAFOI_YEAR]);
    $car = $stmt->fetch();
    if (!$car) return false;
    $count = db()->prepare("
        SELECT COUNT(*) AS total
        FROM seminaristes
        WHERE car_transport = ?
          AND id <> ?
          AND CAST(annee_seminaire AS CHAR) = ?
          AND statut_paiement = 'PAYE'
          AND statut_inscription = 'VALIDEE'
    ");
    $count->execute([$code, $excludeSeminaristeId, (string)SENAFOI_YEAR]);
    return (int)($count->fetch()['total'] ?? 0) < (int)$car['capacite'];
}

function getSeminariste(string $matricule): ?array {
    $stmt = db()->prepare("
        SELECT *
        FROM seminaristes
        WHERE matricule_seminaire = ?
          AND CAST(annee_seminaire AS CHAR) = ?
        LIMIT 1
    ");
    $stmt->execute([strtoupper(trim($matricule)), (string)SENAFOI_YEAR]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function requireSeminariste(string $matricule): array {
    $row = getSeminariste($matricule);
    if (!$row) respond(['success' => false, 'message' => 'SÃ©minariste introuvable pour ce matricule.'], 404);
    return $row;
}

function publicSeminariste(array $s): array {
    return [
        'id' => (int)($s['id'] ?? 0),
        'matricule_seminaire' => $s['matricule_seminaire'] ?? '',
        'nom' => $s['nom'] ?? '',
        'prenom' => $s['prenom'] ?? '',
        'sexe' => $s['sexe'] ?? '',
        'contact' => $s['contact'] ?? '',
        'numero_wave' => $s['numero_wave'] ?? '',
        'contact_parent' => $s['contact_parent'] ?? '',
        'niveau_seminaire' => $s['niveau_seminaire'] ?? '',
        'secretariat_regional' => $s['secretariat_regional'] ?? '',
        'transport' => $s['transport'] ?? '',
        'car_transport' => $s['car_transport'] ?? '',
        'dortoir' => $s['dortoir'] ?? '',
        'statut_paiement' => $s['statut_paiement'] ?? '',
        'payment_status_wave' => $s['payment_status_wave'] ?? '',
        'statut_inscription' => $s['statut_inscription'] ?? '',
        'somme_paye' => $s['somme_paye'] ?? 0,
        'taille_tshirt' => $s['taille_tshirt'] ?? '',
        'malade' => $s['malade'] ?? 0,
        'detail_malade' => $s['detail_malade'] ?? '',
        'date_inscription' => $s['date_inscription'] ?? null,
    ];
}

function safeDate(?string $date): string {
    if (!$date || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) return date('Y-m-d');
    return $date;
}

try {
    ensureTables();
    $action = $_GET['action'] ?? '';

    if ($action === 'get_seminariste') {
        requirePointageUser('viewer');
        $matricule = $_GET['matricule'] ?? '';
        respond(['success' => true, 'seminariste' => publicSeminariste(requireSeminariste($matricule))]);
    }

    if ($action === 'list_cars') {
        requirePointageUser('viewer');
        $stmt = db()->prepare("SELECT id, code, nom, capacite, description, annee FROM seminaire_cars WHERE CAST(annee AS CHAR) = ? ORDER BY code ASC, nom ASC");
        $stmt->execute([(string)SENAFOI_YEAR]);
        respond(['success' => true, 'cars' => $stmt->fetchAll()]);
    }

    if ($action === 'list_dortoirs') {
        requirePointageUser('viewer');
        $stmt = db()->prepare("SELECT id, code, nom, sexe, capacite, description, annee FROM seminaire_dortoirs WHERE CAST(annee AS CHAR) = ? ORDER BY nom ASC");
        $stmt->execute([(string)SENAFOI_YEAR]);
        respond(['success' => true, 'dortoirs' => $stmt->fetchAll()]);
    }

    if ($action === 'list_pointages') {
        requirePointageUser('viewer');
        $type = preg_replace('/[^a-z_]/', '', strtolower($_GET['type'] ?? ''));
        $date = safeDate($_GET['date_pointage'] ?? null);
        $repas = trim((string)($_GET['repas'] ?? ''));
        $coursIndex = (int)($_GET['cours_index'] ?? 0);
        $carCode = strtoupper(trim((string)($_GET['car_code'] ?? '')));

        $where = ['p.type = :type'];
        $params = [':type' => $type];

        if ($date) {
            $where[] = 'p.date_pointage = :date_pointage';
            $params[':date_pointage'] = $date;
        }
        if ($repas !== '') {
            $where[] = 'p.repas = :repas';
            $params[':repas'] = $repas;
        }
        if ($coursIndex > 0) {
            $where[] = 'p.cours_index = :cours_index';
            $params[':cours_index'] = $coursIndex;
        }
        if ($carCode !== '') {
            $where[] = 'p.car_code = :car_code';
            $params[':car_code'] = $carCode;
        }

        $sql = "
            SELECT p.*, s.nom, s.prenom, s.photo, s.matricule_seminaire, s.car_transport, s.dortoir
            FROM senafoi26_pointages p
            LEFT JOIN seminaristes s ON s.id = p.seminariste_id
            WHERE " . implode(' AND ', $where) . "
            ORDER BY p.created_at DESC
            LIMIT 80
        ";
        $stmt = db()->prepare($sql);
        $stmt->execute($params);
        respond(['success' => true, 'pointages' => $stmt->fetchAll()]);
    }
    if ($action === 'pointage') {
        $admin = requirePointageUser('editor');
        $data = inputJson();
        $type = preg_replace('/[^a-z_]/', '', strtolower($data['type'] ?? ''));
        $allowedTypes = ['badge_recovery', 'car_entry', 'restauration', 'cours', 'dortoir', 'car_return'];
        if (!in_array($type, $allowedTypes, true)) respond(['success' => false, 'message' => 'Type de pointage invalide.'], 400);

        $s = requireSeminariste($data['matricule'] ?? '');
        $date = safeDate($data['date_pointage'] ?? null);
        $repas = $type === 'restauration' ? trim((string)($data['repas'] ?? '')) : null;
        $coursIndex = $type === 'cours' ? max(1, min(3, (int)($data['cours_index'] ?? 1))) : null;
        $carCode = in_array($type, ['car_entry', 'car_return'], true) ? strtoupper(trim((string)($data['car_code'] ?? ''))) : null;
        $dortoirCode = $type === 'dortoir' ? ($s['dortoir'] ?? null) : null;

        $dupe = db()->prepare("
            SELECT id FROM senafoi26_pointages
            WHERE matricule = ? AND type = ? AND date_pointage = ?
              AND COALESCE(repas, '') = COALESCE(?, '')
              AND COALESCE(cours_index, 0) = COALESCE(?, 0)
            LIMIT 1
        ");
        $dupe->execute([$s['matricule_seminaire'], $type, $date, $repas, $coursIndex]);
        if ($dupe->fetch()) {
            respond(['success' => true, 'message' => 'DÃ©jÃ  pointÃ© pour cette action.', 'seminariste' => publicSeminariste($s)]);
        }

        $stmt = db()->prepare("
            INSERT INTO senafoi26_pointages
                (seminariste_id, matricule, type, date_pointage, repas, cours_index, car_code, dortoir_code, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([
            (int)$s['id'], $s['matricule_seminaire'], $type, $date, $repas, $coursIndex, $carCode, $dortoirCode, $admin['matricule'] ?? null
        ]);

        if ($carCode && in_array($type, ['car_entry', 'car_return'], true) && $carCode !== ($s['car_transport'] ?? '')) {
            if (!carHasCapacity($carCode, (int)$s['id'])) {
                respond(['success' => false, 'message' => 'Quota du car cible atteint. Reaffectation impossible.'], 400);
            }
            $up = db()->prepare("UPDATE seminaristes SET car_transport = ?, updated_at = NOW() WHERE id = ?");
            $up->execute([$carCode, (int)$s['id']]);
            $s = requireSeminariste($s['matricule_seminaire']);
        }

        respond(['success' => true, 'message' => 'Pointage enregistrÃ© avec succÃ¨s.', 'seminariste' => publicSeminariste($s)]);
    }

    if ($action === 'conduite') {
        $admin = requirePointageUser('editor');
        $data = inputJson();
        $s = requireSeminariste($data['matricule'] ?? '');
        $motif = trim((string)($data['motif'] ?? ''));
        if ($motif === '') respond(['success' => false, 'message' => 'Le motif est requis.'], 400);
        $description = trim((string)($data['description'] ?? ''));
        $stmt = db()->prepare("
            INSERT INTO senafoi26_conduites
                (seminariste_id, matricule, motif, description, created_by, created_at)
            VALUES (?, ?, ?, ?, ?, NOW())
        ");
        $stmt->execute([(int)$s['id'], $s['matricule_seminaire'], $motif, $description, $admin['matricule'] ?? null]);
        respond(['success' => true, 'message' => 'Conduite enregistrÃ©e avec succÃ¨s.', 'seminariste' => publicSeminariste($s)]);
    }

    respond(['success' => false, 'message' => 'Action inconnue.'], 404);
} catch (Throwable $e) {
    respond(['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()], 500);
}
