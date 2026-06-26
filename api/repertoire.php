<?php

ini_set('display_errors', 0);
error_reporting(E_ALL);
date_default_timezone_set('Africa/Abidjan');

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

define('KYA_SMS_URL', 'https://route.kyasms.net/api/v3/sms/send');
define('KYA_SMS_API_KEY', getenv('KYA_SMS_API_KEY') ?: 'kyasmsd2ccf32b4aa62311eee9da3051b60bba18bb5236249abf9d1c5e5e873f');
define('KYA_SMS_SENDER', 'AEEMCI');
define('KYA_SMS_CALLBACK_URL', 'https://api.aeemci-ce.ci/senafoi/sms_dlr.php');

function respond(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function loadDatabase(): PDO {
    foreach ([__DIR__ . '/config/database.php', __DIR__ . '/../config/database.php'] as $file) {
        if (is_file($file)) {
            require_once $file;
            break;
        }
    }

    if (!class_exists('Database')) {
        respond(['success' => false, 'message' => 'Configuration BDD manquante.'], 500);
    }

    $db = new Database();
    $ref = new ReflectionClass($db);
    if ($ref->hasProperty('pdo')) {
        $property = $ref->getProperty('pdo');
        $property->setAccessible(true);
        $pdo = $property->getValue($db);
        if ($pdo instanceof PDO) return $pdo;
    }

    respond(['success' => false, 'message' => 'Connexion PDO introuvable.'], 500);
}

function db(): PDO {
    static $pdo = null;
    if (!$pdo) $pdo = loadDatabase();
    return $pdo;
}

function inputJson(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function clean(mixed $value): string {
    return trim((string)$value);
}

function normalizePhone(string $phone): string {
    $digits = preg_replace('/\D+/', '', $phone);
    if (!$digits) return '';
    if (str_starts_with($digits, '00225')) return substr($digits, 2);
    if (str_starts_with($digits, '225')) return $digits;
    if (strlen($digits) === 10) return '225' . $digits;
    if (strlen($digits) === 8) return '2250' . $digits;
    return $digits;
}

function ensureSchema(): void {
    $pdo = db();
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS aeemci_contacts_repertoire (
            id INT AUTO_INCREMENT PRIMARY KEY,
            prenom VARCHAR(100) NOT NULL,
            nom VARCHAR(100) NOT NULL,
            contact VARCHAR(30) NOT NULL,
            qualite VARCHAR(150) NULL,
            source VARCHAR(40) NULL DEFAULT 'manuel',
            notes TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL,
            INDEX idx_contact (contact),
            INDEX idx_nom (nom, prenom)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS aeemci_groupes (
            id INT AUTO_INCREMENT PRIMARY KEY,
            nom VARCHAR(120) NOT NULL,
            description TEXT NULL,
            created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at DATETIME NULL DEFAULT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS aeemci_groupe_membres (
            id INT AUTO_INCREMENT PRIMARY KEY,
            groupe_id INT NOT NULL,
            membre_id INT NOT NULL,
            membre_type VARCHAR(30) NOT NULL DEFAULT 'contact',
            added_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
            UNIQUE KEY uniq_groupe_membre (groupe_id, membre_id, membre_type),
            INDEX idx_groupe (groupe_id),
            CONSTRAINT fk_repertoire_groupe
                FOREIGN KEY (groupe_id) REFERENCES aeemci_groupes(id)
                ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
    ");
}

function pagination(): array {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? $_GET['limit'] ?? 25)));
    return [$page, $perPage, ($page - 1) * $perPage];
}

function stats(): void {
    ensureSchema();
    $pdo = db();
    $contacts = (int)$pdo->query("SELECT COUNT(*) FROM aeemci_contacts_repertoire")->fetchColumn();
    $groupes = (int)$pdo->query("SELECT COUNT(*) FROM aeemci_groupes")->fetchColumn();
    $messages = (int)$pdo->query("SELECT COUNT(*) FROM aeemci_sms_messages")->fetchColumn();
    $sent = (int)$pdo->query("SELECT COUNT(*) FROM aeemci_sms_messages WHERE status IN ('sent','queued')")->fetchColumn();

    respond([
        'success' => true,
        'data' => [
            'contacts' => $contacts,
            'groupes' => $groupes,
            'messages' => $messages,
            'sent' => $sent,
        ],
    ]);
}

function listContacts(): void {
    ensureSchema();
    [$page, $perPage, $offset] = pagination();
    $search = clean($_GET['search'] ?? '');
    $groupId = (int)($_GET['groupe_id'] ?? $_GET['group_id'] ?? 0);
    $params = [];
    $from = 'FROM aeemci_contacts_repertoire c';
    $where = 'WHERE 1=1';

    if ($groupId > 0) {
        $from .= ' INNER JOIN aeemci_groupe_membres gm ON gm.membre_id = c.id AND gm.groupe_id = :group_id';
        $params[':group_id'] = $groupId;
    }

    if ($search !== '') {
        $where .= ' AND (c.prenom LIKE :search_prenom OR c.nom LIKE :search_nom OR c.contact LIKE :search_contact OR c.qualite LIKE :search_qualite)';
        $like = '%' . $search . '%';
        $params[':search_prenom'] = $like;
        $params[':search_nom'] = $like;
        $params[':search_contact'] = $like;
        $params[':search_qualite'] = $like;
    }

    $count = db()->prepare("SELECT COUNT(DISTINCT c.id) {$from} {$where}");
    $count->execute($params);
    $total = (int)$count->fetchColumn();

    $stmt = db()->prepare("
        SELECT DISTINCT c.*
        {$from}
        {$where}
        ORDER BY COALESCE(c.updated_at, c.created_at) DESC, c.id DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute($params);

    respond([
        'success' => true,
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'pagination' => [
            'current_page' => $page,
            'last_page' => (int)ceil($total / $perPage),
            'per_page' => $perPage,
            'total' => $total,
            'from' => $total ? $offset + 1 : 0,
            'to' => min($offset + $perPage, $total),
        ],
    ]);
}

function saveContact(bool $update = false): void {
    ensureSchema();
    $data = inputJson();
    $prenom = clean($data['prenom'] ?? '');
    $nom = clean($data['nom'] ?? '');
    $contact = clean($data['contact'] ?? '');
    $qualite = clean($data['qualite'] ?? '');
    $notes = clean($data['notes'] ?? '');

    if ($prenom === '' || $nom === '' || $contact === '') {
        respond(['success' => false, 'message' => 'Prenom, nom et contact sont requis.'], 400);
    }

    if ($update) {
        $id = (int)($data['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) respond(['success' => false, 'message' => 'ID contact requis.'], 400);
        $stmt = db()->prepare("
            UPDATE aeemci_contacts_repertoire
            SET prenom = ?, nom = ?, contact = ?, qualite = ?, notes = ?, updated_at = NOW()
            WHERE id = ?
        ");
        $stmt->execute([$prenom, $nom, $contact, $qualite ?: null, $notes ?: null, $id]);
        respond(['success' => true, 'message' => 'Contact mis a jour.', 'id' => $id]);
    }

    $stmt = db()->prepare("
        INSERT INTO aeemci_contacts_repertoire (prenom, nom, contact, qualite, notes, source, created_at)
        VALUES (?, ?, ?, ?, ?, 'manuel', NOW())
    ");
    $stmt->execute([$prenom, $nom, $contact, $qualite ?: null, $notes ?: null]);
    respond(['success' => true, 'message' => 'Contact cree.', 'id' => (int)db()->lastInsertId()]);
}

function deleteContact(): void {
    ensureSchema();
    $id = (int)($_GET['id'] ?? inputJson()['id'] ?? 0);
    if ($id <= 0) respond(['success' => false, 'message' => 'ID contact requis.'], 400);
    db()->prepare("DELETE FROM aeemci_groupe_membres WHERE membre_type = 'contact' AND membre_id = ?")->execute([$id]);
    db()->prepare("DELETE FROM aeemci_contacts_repertoire WHERE id = ?")->execute([$id]);
    respond(['success' => true, 'message' => 'Contact supprime.']);
}

function listGroups(): void {
    ensureSchema();
    $groups = db()->query("
        SELECT g.*, COUNT(gm.id) AS membre_count
        FROM aeemci_groupes g
        LEFT JOIN aeemci_groupe_membres gm ON gm.groupe_id = g.id
        GROUP BY g.id
        ORDER BY COALESCE(g.updated_at, g.created_at) DESC, g.id DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    $memberStmt = db()->prepare("
        SELECT
            gm.membre_id,
            gm.membre_type,
            COALESCE(cm.prenom, cr.prenom) AS prenom,
            COALESCE(cm.nom, cr.nom) AS nom,
            COALESCE(cm.contact, cr.contact) AS contact,
            COALESCE(cm.qualite_membre, cm.type_membre, cr.qualite) AS qualite,
            cm.matricule_gen AS matricule,
            cm.photo_membre AS photo
        FROM aeemci_groupe_membres gm
        LEFT JOIN aeemci_contacts_repertoire cr ON cr.id = gm.membre_id
        LEFT JOIN aeemciste_carte_membre cm ON cm.id_membre = gm.membre_id AND gm.membre_type = 'membre'
        WHERE gm.groupe_id = ?
        ORDER BY nom ASC, prenom ASC
    ");

    foreach ($groups as &$group) {
        $memberStmt->execute([$group['id']]);
        $group['membres'] = $memberStmt->fetchAll(PDO::FETCH_ASSOC);
    }

    respond(['success' => true, 'data' => $groups]);
}

function saveGroup(bool $update = false): void {
    ensureSchema();
    $data = inputJson();
    $nom = clean($data['nom'] ?? '');
    $description = clean($data['description'] ?? '');
    if ($nom === '') respond(['success' => false, 'message' => 'Nom du groupe requis.'], 400);

    if ($update) {
        $id = (int)($data['id'] ?? $_GET['id'] ?? 0);
        if ($id <= 0) respond(['success' => false, 'message' => 'ID groupe requis.'], 400);
        db()->prepare("UPDATE aeemci_groupes SET nom = ?, description = ?, updated_at = NOW() WHERE id = ?")
            ->execute([$nom, $description ?: null, $id]);
        respond(['success' => true, 'message' => 'Groupe mis a jour.', 'id' => $id]);
    }

    db()->prepare("INSERT INTO aeemci_groupes (nom, description, created_at) VALUES (?, ?, NOW())")
        ->execute([$nom, $description ?: null]);
    respond(['success' => true, 'message' => 'Groupe cree.', 'id' => (int)db()->lastInsertId()]);
}

function deleteGroup(): void {
    ensureSchema();
    $id = (int)($_GET['id'] ?? inputJson()['id'] ?? 0);
    if ($id <= 0) respond(['success' => false, 'message' => 'ID groupe requis.'], 400);
    db()->prepare("DELETE FROM aeemci_groupes WHERE id = ?")->execute([$id]);
    respond(['success' => true, 'message' => 'Groupe supprime.']);
}

function addGroupMember(): void {
    ensureSchema();
    $data = inputJson();
    $groupId = (int)($data['groupe_id'] ?? 0);
    $memberId = (int)($data['membre_id'] ?? $data['contact_id'] ?? 0);
    $type = clean($data['membre_type'] ?? 'contact') ?: 'contact';
    if ($groupId <= 0 || $memberId <= 0) respond(['success' => false, 'message' => 'Groupe et contact requis.'], 400);
    try {
        db()->prepare("
            INSERT INTO aeemci_groupe_membres (groupe_id, membre_id, membre_type, added_at)
            VALUES (?, ?, ?, NOW())
        ")->execute([$groupId, $memberId, $type]);
        respond(['success' => true, 'message' => 'Contact ajoute au groupe.']);
    } catch (PDOException $e) {
        respond(['success' => false, 'message' => 'Ce contact est deja dans le groupe.'], 409);
    }
}

function removeGroupMember(): void {
    ensureSchema();
    $groupId = (int)($_GET['groupe_id'] ?? 0);
    $memberId = (int)($_GET['membre_id'] ?? $_GET['contact_id'] ?? 0);
    if ($groupId <= 0 || $memberId <= 0) respond(['success' => false, 'message' => 'Groupe et contact requis.'], 400);
    db()->prepare("DELETE FROM aeemci_groupe_membres WHERE groupe_id = ? AND membre_id = ?")
        ->execute([$groupId, $memberId]);
    respond(['success' => true, 'message' => 'Contact retire du groupe.']);
}

function sendKyaSms(string $phone, string $message): array {
    $normalizedPhone = normalizePhone($phone);
    if ($normalizedPhone === '' || !function_exists('curl_init')) {
        return ['success' => false, 'message' => 'Numero invalide ou cURL indisponible.'];
    }

    $payload = json_encode([
        'from' => KYA_SMS_SENDER,
        'to' => $normalizedPhone,
        'type' => 'text',
        'message' => $message,
        'callback_url' => KYA_SMS_CALLBACK_URL,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init(KYA_SMS_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'APIKEY: ' . KYA_SMS_API_KEY,
        ],
        CURLOPT_TIMEOUT => 35,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $response = curl_exec($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $error = curl_error($ch);
    curl_close($ch);

    $decoded = json_decode((string)$response, true);
    $success = !$error && $httpCode >= 200 && $httpCode < 400 && (($decoded['reason'] ?? '') === 'success' || !empty($decoded['data']));
    return [
        'success' => $success,
        'phone' => $normalizedPhone,
        'message' => $success ? null : ($decoded['message'] ?? $decoded['reason'] ?? $error ?: 'SMS non envoye.'),
        'http_code' => $httpCode,
    ];
}

function sendSms(): void {
    ensureSchema();
    $data = inputJson();
    $message = clean($data['message'] ?? '');
    $contacts = $data['contacts'] ?? [];
    $groupId = (int)($data['groupe_id'] ?? 0);

    if ($message === '') respond(['success' => false, 'message' => 'Message requis.'], 400);

    if ($groupId > 0) {
        $stmt = db()->prepare("
            SELECT
                COALESCE(cm.prenom, cr.prenom) AS prenom,
                COALESCE(cm.nom, cr.nom) AS nom,
                COALESCE(cm.contact, cr.contact) AS contact,
                COALESCE(cm.qualite_membre, cm.type_membre, cr.qualite) AS qualite
            FROM aeemci_groupe_membres gm
            LEFT JOIN aeemci_contacts_repertoire cr ON cr.id = gm.membre_id
            LEFT JOIN aeemciste_carte_membre cm ON cm.id_membre = gm.membre_id AND gm.membre_type = 'membre'
            WHERE gm.groupe_id = ?
        ");
        $stmt->execute([$groupId]);
        $contacts = array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC), static fn($row) => !empty($row['contact'])));
    }

    if (!is_array($contacts) || !$contacts) {
        respond(['success' => false, 'message' => 'Aucun destinataire.'], 400);
    }

    $phones = [];
    foreach ($contacts as $contact) {
        if (is_array($contact)) {
            $phone = clean($contact['contact'] ?? $contact['phone'] ?? '');
        } else {
            $phone = clean($contact);
        }
        $normalized = normalizePhone($phone);
        if ($normalized !== '') $phones[$normalized] = $normalized;
    }

    if (!$phones) respond(['success' => false, 'message' => 'Aucun numero valide.'], 400);

    $history = db()->prepare("
        INSERT INTO aeemci_sms_messages (matricule, phone_numbers, message, status, created_at)
        VALUES (?, ?, ?, 'queued', NOW())
    ");
    $history->execute([
        $groupId > 0 ? 'REPERTOIRE-GROUPE-' . $groupId : 'REPERTOIRE',
        json_encode(array_values($phones), JSON_UNESCAPED_UNICODE),
        $message,
    ]);
    $historyId = (int)db()->lastInsertId();

    $results = [];
    $sent = 0;
    foreach ($phones as $phone) {
        $result = sendKyaSms($phone, $message);
        $results[] = $result;
        if ($result['success']) $sent++;
    }

    $status = $sent === count($phones) ? 'sent' : ($sent > 0 ? 'partial' : 'failed');
    db()->prepare("UPDATE aeemci_sms_messages SET status = ? WHERE id = ?")->execute([$status, $historyId]);

    respond([
        'success' => $sent > 0,
        'message' => "{$sent}/" . count($phones) . ' SMS envoyes.',
        'id' => $historyId,
        'sent' => $sent,
        'total' => count($phones),
        'results' => $results,
    ], $sent > 0 ? 200 : 502);
}

function history(): void {
    ensureSchema();
    [$page, $perPage, $offset] = pagination();
    $total = (int)db()->query("SELECT COUNT(*) FROM aeemci_sms_messages")->fetchColumn();
    $stmt = db()->prepare("
        SELECT *
        FROM aeemci_sms_messages
        ORDER BY created_at DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute();
    respond([
        'success' => true,
        'data' => $stmt->fetchAll(PDO::FETCH_ASSOC),
        'pagination' => [
            'current_page' => $page,
            'last_page' => (int)ceil($total / $perPage),
            'per_page' => $perPage,
            'total' => $total,
        ],
    ]);
}

try {
    $action = $_GET['action'] ?? $_GET['request'] ?? 'contacts';
    $method = $_SERVER['REQUEST_METHOD'];

    if ($method === 'GET' && $action === 'stats') stats();
    if ($method === 'GET' && in_array($action, ['contacts', 'repertoire'], true)) listContacts();
    if ($method === 'POST' && in_array($action, ['contacts', 'create_contact'], true)) saveContact(false);
    if (in_array($method, ['PUT', 'POST'], true) && in_array($action, ['update_contact', 'modifier_contact'], true)) saveContact(true);
    if ($method === 'DELETE' && in_array($action, ['delete_contact', 'contacts'], true)) deleteContact();

    if ($method === 'GET' && in_array($action, ['groupes', 'groups'], true)) listGroups();
    if ($method === 'POST' && in_array($action, ['groupes', 'create_group'], true)) saveGroup(false);
    if (in_array($method, ['PUT', 'POST'], true) && in_array($action, ['update_group', 'modifier_groupe'], true)) saveGroup(true);
    if ($method === 'DELETE' && in_array($action, ['delete_group', 'groupes'], true)) deleteGroup();

    if ($method === 'POST' && in_array($action, ['groupe-membres', 'add_group_member'], true)) addGroupMember();
    if ($method === 'DELETE' && in_array($action, ['groupe-membres', 'remove_group_member'], true)) removeGroupMember();
    if ($method === 'POST' && in_array($action, ['send_sms', 'envoyer_sms'], true)) sendSms();
    if ($method === 'GET' && in_array($action, ['sms-history', 'history'], true)) history();

    respond(['success' => false, 'message' => 'Action non reconnue.'], 400);
} catch (Throwable $e) {
    respond(['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()], 500);
}
