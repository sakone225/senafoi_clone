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

define('DB_HOST', getenv('MEMBRES_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('MEMBRES_DB_NAME') ?: '');
define('DB_USER', getenv('MEMBRES_DB_USER') ?: '');
define('DB_PASS', getenv('MEMBRES_DB_PASS') ?: '');
define('MEMBRES_TABLE', getenv('MEMBRES_TABLE') ?: '');
define('SMS_API_URL', getenv('MEMBRES_SMS_API_URL') ?: getenv('SMS_API_URL') ?: '');
define('SMS_API_TOKEN', getenv('MEMBRES_SMS_API_TOKEN') ?: getenv('SMS_API_TOKEN') ?: '');
define('SMS_SENDER', getenv('MEMBRES_SMS_SENDER') ?: getenv('SMS_SENDER') ?: 'AEEMCI');
define('KYA_SMS_URL', 'https://route.kyasms.net/api/v3/sms/send');
define('KYA_SMS_API_KEY', getenv('KYA_SMS_API_KEY') ?: 'kyasmsd2ccf32b4aa62311eee9da3051b60bba18bb5236249abf9d1c5e5e873f');
define('KYA_SMS_SENDER', 'AEEMCI');
define('KYA_SMS_CALLBACK_URL', 'https://api.aeemci-ce.ci/senafoi/sms_dlr.php');

function respond(array $payload, int $status = 200): never {
    http_response_code($status);
    echo json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

class DbCompatStatement {
    private mixed $db;
    private string $sql;
    private array $rows = [];
    private int $cursor = 0;

    public function __construct(mixed $db, string $sql = '', array $rows = []) {
        $this->db = $db;
        $this->sql = $sql;
        $this->rows = $rows;
    }

    public function execute(array $params = []): bool {
        $result = $this->db->query($this->sql, $params);
        $this->rows = is_array($result) ? $result : [];
        $this->cursor = 0;
        return true;
    }

    public function fetch(): array|false {
        if (!isset($this->rows[$this->cursor])) return false;
        return $this->rows[$this->cursor++];
    }

    public function fetchAll(): array {
        return $this->rows;
    }
}

class DbCompat {
    private mixed $db;
    private mixed $pdo = null;

    public function __construct(mixed $db) {
        $this->db = $db;
        try {
            $ref = new ReflectionClass($db);
            if ($ref->hasProperty('pdo')) {
                $property = $ref->getProperty('pdo');
                $property->setAccessible(true);
                $this->pdo = $property->getValue($db);
            }
        } catch (Throwable $ignored) {
            $this->pdo = null;
        }
    }

    public function prepare(string $sql): mixed {
        if ($this->pdo) return $this->pdo->prepare($sql);
        return new DbCompatStatement($this->db, $sql);
    }

    public function query(string $sql): mixed {
        if ($this->pdo) return $this->pdo->query($sql);
        $result = $this->db->query($sql);
        return new DbCompatStatement($this->db, $sql, is_array($result) ? $result : []);
    }
}

function loadServerDatabaseConfig(): void {
    foreach ([__DIR__ . '/config/database.php', __DIR__ . '/../config/database.php'] as $file) {
        if (is_file($file)) {
            require_once $file;
            return;
        }
    }
}

function db(): mixed {
    static $pdo = null;
    if ($pdo) return $pdo;

    if (DB_NAME !== '' && DB_USER !== '') {
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

    loadServerDatabaseConfig();
    if (class_exists('Database')) {
        $pdo = new DbCompat(new Database());
        return $pdo;
    }

    respond(['success' => false, 'message' => 'Configuration BDD manquante.'], 500);
}

function inputJson(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function clean(?string $value): string {
    return trim((string)$value);
}

function qid(string $identifier): string {
    return '`' . str_replace('`', '``', $identifier) . '`';
}

function tableRows(): array {
    try {
        return db()->query('SHOW TABLES')->fetchAll();
    } catch (Throwable $e) {
        return [];
    }
}

function tableExists(string $table): bool {
    try {
        db()->query('SELECT 1 FROM ' . qid($table) . ' LIMIT 1')->fetch();
        return true;
    } catch (Throwable $e) {
        return false;
    }
}

function columnsFor(string $table): array {
    try {
        $rows = db()->query('SHOW COLUMNS FROM ' . qid($table))->fetchAll();
    } catch (Throwable $e) {
        return [];
    }

    return array_values(array_filter(array_map(static function ($row) {
        return strtolower((string)($row['Field'] ?? $row[0] ?? ''));
    }, $rows)));
}

function resolveMembersTable(): string {
    static $resolved = null;
    if ($resolved) return $resolved;

    if (MEMBRES_TABLE !== '' && tableExists(MEMBRES_TABLE)) {
        $resolved = qid(MEMBRES_TABLE);
        return $resolved;
    }

    $preferred = [
        'aeemciste_carte_membre',
        'membres',
        'membres_aeemci',
        'carte_membres',
        'cartes_membres',
        'membres_cartes',
        'api_carte_membre',
        'inscriptions_cartes',
        'demandes_cartes',
    ];

    $tables = [];
    foreach ($preferred as $table) {
        if (tableExists($table)) $tables[] = $table;
    }
    foreach (tableRows() as $row) {
        $table = (string)reset($row);
        if ($table !== '' && !in_array($table, $tables, true)) $tables[] = $table;
    }

    $bestTable = '';
    $bestScore = 0;
    foreach ($tables as $table) {
        $cols = columnsFor($table);
        if (!$cols) continue;

        $score = 0;
        foreach (['id', 'nom', 'prenom', 'contact', 'statut_paiement', 'card_status', 'secretariat_poste', 'region', 'sous_comite', 'matricule'] as $col) {
            if (in_array($col, $cols, true)) $score += 2;
        }
        foreach (['transaction_id', 'ref_paiement', 'somme_paye', 'type_membre', 'created_at', 'updated_at'] as $col) {
            if (in_array($col, $cols, true)) $score += 1;
        }
        if (stripos($table, 'membre') !== false || stripos($table, 'carte') !== false) $score += 2;

        if ($score > $bestScore) {
            $bestScore = $score;
            $bestTable = $table;
        }
    }

    if ($bestTable === '' || $bestScore < 8) {
        respond(['success' => false, 'message' => 'Table membres introuvable. Definissez MEMBRES_TABLE avec le vrai nom de table.'], 500);
    }

    $resolved = qid($bestTable);
    return $resolved;
}

function hasColumn(string $table, string $column, bool $refresh = false): bool {
    static $cache = [];
    $key = $table . '.' . $column;
    if (!$refresh && array_key_exists($key, $cache)) return $cache[$key];

    $rows = db()->query("SHOW COLUMNS FROM {$table}")->fetchAll();
    $cache[$key] = false;
    foreach ($rows as $row) {
        $field = (string)($row['Field'] ?? $row[0] ?? '');
        if (strcasecmp($field, $column) === 0) {
            $cache[$key] = true;
            break;
        }
    }
    return $cache[$key];
}

function firstExistingColumn(string $table, array $columns, bool $refresh = false): ?string {
    foreach ($columns as $column) {
        if (hasColumn($table, $column, $refresh)) return $column;
    }
    return null;
}

function columnRef(string $table, array $columns): ?string {
    $column = firstExistingColumn($table, $columns);
    return $column ? "{$table}." . qid($column) : null;
}

function idColumnRef(string $table): string {
    return columnRef($table, ['id', 'id_membre']) ?: "{$table}.id";
}

function secretariatExpr(string $table): string {
    $parts = array_filter([
        columnRef($table, ['secretariat_poste', 'secretariat_debut', 'sr_debut']),
        columnRef($table, ['region']),
    ]);
    $nullIf = array_map(static fn($col) => "NULLIF({$col}, '')", $parts);
    $nullIf[] = "'Non defini'";
    return 'COALESCE(' . implode(', ', $nullIf) . ')';
}

function normalizeMemberRow(array $row): array {
    $aliases = [
        'id_membre' => 'id',
        'matricule_gen' => 'matricule',
        'date_naiss' => 'date_naissance',
        'lieu_naiss' => 'lieu_naissance',
        'secretariat_debut' => 'secretariat_poste',
        'souscomite_debut' => 'sous_comite',
    ];

    foreach ($aliases as $source => $target) {
        if (array_key_exists($source, $row) && (!array_key_exists($target, $row) || $row[$target] === null || $row[$target] === '')) {
            $row[$target] = $row[$source];
        }
    }

    unset($row['password']);
    return $row;
}

function ensureNotificationColumns(string $table): ?string {
    $notifyColumn = firstExistingColumn($table, ['card_notified_at', 'retrait_sms_sent_at', 'sms_retrait_at']);
    if ($notifyColumn) return $notifyColumn;

    try {
        db()->query("ALTER TABLE {$table} ADD COLUMN card_notified_at DATETIME NULL");
    } catch (Throwable $ignored) {
        // La colonne existe peut-etre deja ou l'utilisateur SQL n'a pas le droit ALTER.
    }

    try {
        db()->query("ALTER TABLE {$table} ADD COLUMN card_notified_by VARCHAR(100) NULL");
    } catch (Throwable $ignored) {
        // Le suivi de l'administrateur est utile, mais non bloquant pour envoyer le SMS.
    }

    return firstExistingColumn($table, ['card_notified_at', 'retrait_sms_sent_at', 'sms_retrait_at'], true);
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

function sendSms(string $phone, string $message): array {
    if (KYA_SMS_API_KEY !== '') {
        return sendKyaSms($phone, $message);
    }

    if (SMS_API_URL === '') {
        return ['success' => false, 'message' => 'Configuration SMS manquante.'];
    }
    if (!function_exists('curl_init')) {
        return ['success' => false, 'message' => 'Extension PHP cURL manquante.'];
    }

    $payload = [
        'to' => $phone,
        'phone' => $phone,
        'message' => $message,
        'sender' => SMS_SENDER,
    ];

    $headers = ['Content-Type: application/json'];
    if (SMS_API_TOKEN !== '') {
        $headers[] = 'Authorization: Bearer ' . SMS_API_TOKEN;
    }

    $ch = curl_init(SMS_API_URL);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_TIMEOUT => 20,
    ]);

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($body === false || $error) {
        return ['success' => false, 'message' => $error ?: 'Envoi SMS impossible.'];
    }

    $json = json_decode($body, true);
    $ok = $status >= 200 && $status < 300;
    if (is_array($json) && array_key_exists('success', $json)) {
        $ok = $ok && (bool)$json['success'];
    }

    return [
        'success' => $ok,
        'message' => is_array($json) ? ($json['message'] ?? $json['error'] ?? null) : null,
        'raw_status' => $status,
        'raw' => $json ?: $body,
    ];
}

function sendKyaSms(string $phone, string $message): array {
    $normalizedPhone = normalizePhone($phone);
    if ($normalizedPhone === '') {
        return ['success' => false, 'message' => 'Numero SMS invalide.'];
    }
    if (!function_exists('curl_init')) {
        return ['success' => false, 'message' => 'Extension PHP cURL manquante.'];
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
    $curlError = curl_error($ch);
    curl_close($ch);

    $decoded = json_decode((string)$response, true);
    $success = !$curlError && $httpCode >= 200 && $httpCode < 400 && (($decoded['reason'] ?? '') === 'success' || !empty($decoded['data']));

    return [
        'success' => $success,
        'message' => $success ? null : ($decoded['message'] ?? $decoded['reason'] ?? $curlError ?: 'SMS non envoye.'),
        'phone' => $normalizedPhone,
        'http_code' => $httpCode,
        'provider_response' => $decoded ?? $response,
    ];
}

function baseWhere(array &$params, bool $paidOnly = true): string {
    $t = resolveMembersTable();
    $where = 'WHERE 1=1';

    if ($paidOnly) {
        $where .= " AND {$t}.statut_paiement = 'PAYE'";
    }

    $search = clean($_GET['search'] ?? $_GET['q'] ?? '');
    if ($search !== '') {
        $searchColumns = array_filter([
            columnRef($t, ['nom']),
            columnRef($t, ['prenom']),
            columnRef($t, ['matricule', 'matricule_gen']),
            columnRef($t, ['contact', 'telephone', 'phone']),
            columnRef($t, ['region']),
            columnRef($t, ['secretariat_poste', 'secretariat_debut', 'sr_debut']),
            columnRef($t, ['sous_comite', 'souscomite_debut']),
            columnRef($t, ['transaction_id']),
            columnRef($t, ['ref_paiement']),
        ]);
        $conditions = array_map(static fn($col) => "{$col} LIKE :search", $searchColumns);
        $prenom = columnRef($t, ['prenom']);
        $nom = columnRef($t, ['nom']);
        if ($prenom && $nom) {
            $conditions[] = "CONCAT({$prenom}, ' ', {$nom}) LIKE :search";
        }
        if ($conditions) {
            $where .= ' AND (' . implode(' OR ', $conditions) . ')';
        }
        $params[':search'] = '%' . $search . '%';
    }

    $type = clean($_GET['type_membre'] ?? $_GET['type'] ?? '');
    if (in_array($type, ['ACTUEL', 'ANCIEN'], true)) {
        $typeColumn = columnRef($t, ['type_membre']);
        if ($typeColumn) {
            $where .= " AND {$typeColumn} = :type_membre";
            $params[':type_membre'] = $type;
        }
    }

    $card = clean($_GET['card_status'] ?? '');
    if (in_array($card, ['pending', 'printed', 'retrieved'], true)) {
        $cardColumn = columnRef($t, ['card_status']);
        if ($cardColumn) {
            $where .= " AND COALESCE(NULLIF({$cardColumn}, ''), 'pending') = :card_status";
            $params[':card_status'] = $card;
        }
    }

    return $where;
}

function pagination(): array {
    $page = max(1, (int)($_GET['page'] ?? 1));
    $perPage = min(100, max(10, (int)($_GET['per_page'] ?? 25)));
    return [$page, $perPage, ($page - 1) * $perPage];
}

function listMembers(bool $paidOnly = true): void {
    $params = [];
    $where = baseWhere($params, $paidOnly);
    [$page, $perPage, $offset] = pagination();
    $t = resolveMembersTable();
    $idColumn = idColumnRef($t);
    $updatedColumn = columnRef($t, ['updated_at', 'created_at']) ?: $idColumn;

    $count = db()->prepare("SELECT COUNT(*) AS total FROM {$t} {$where}");
    $count->execute($params);
    $total = (int)($count->fetch()['total'] ?? 0);

    $stmt = db()->prepare("
        SELECT *
        FROM {$t}
        {$where}
        ORDER BY {$updatedColumn} DESC, {$idColumn} DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $rows = array_map('normalizeMemberRow', $stmt->fetchAll());

    respond([
        'success' => true,
        'data' => $rows,
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

function stats(): void {
    $t = resolveMembersTable();
    $secretariat = secretariatExpr($t);
    $row = db()->query("
        SELECT
            COUNT(*) AS total_members,
            SUM(statut_paiement = 'PAYE') AS paid_cards,
            SUM(statut_paiement = 'PAYE' AND COALESCE(NULLIF(card_status, ''), 'pending') = 'printed') AS printed_cards,
            SUM(statut_paiement = 'PAYE' AND COALESCE(NULLIF(card_status, ''), 'pending') = 'retrieved') AS retrieved_cards,
            SUM(statut_paiement = 'PAYE' AND COALESCE(NULLIF(card_status, ''), 'pending') = 'pending') AS pending_print_cards,
            COUNT(DISTINCT {$secretariat}) AS total_secretariats,
            SUM(statut_paiement = 'PAYE' AND created_at >= DATE_FORMAT(CURDATE(), '%Y-%m-01')) AS month_members,
            COALESCE(SUM(CASE WHEN statut_paiement = 'PAYE' THEN somme_paye ELSE 0 END), 0) AS total_paye,
            SUM(statut_paiement = 'PAYE' AND type_membre = 'ACTUEL') AS actuels,
            SUM(statut_paiement = 'PAYE' AND type_membre = 'ANCIEN') AS anciens
        FROM {$t}
    ")->fetch() ?: [];

    $bySecretariat = db()->query("
        SELECT
            {$secretariat} AS secretariat,
            COUNT(*) AS total,
            SUM(type_membre = 'ACTUEL') AS actuels,
            SUM(type_membre = 'ANCIEN') AS anciens,
            SUM(COALESCE(NULLIF(card_status, ''), 'pending') = 'pending') AS pending_cards,
            SUM(COALESCE(NULLIF(card_status, ''), 'pending') = 'printed') AS printed_cards,
            SUM(COALESCE(NULLIF(card_status, ''), 'pending') = 'retrieved') AS retrieved_cards
        FROM {$t}
        WHERE statut_paiement = 'PAYE'
        GROUP BY {$secretariat}
        ORDER BY total DESC, secretariat ASC
    ")->fetchAll();

    respond([
        'success' => true,
        'data' => [
            'total_members' => (int)($row['paid_cards'] ?? 0),
            'paid_cards' => (int)($row['paid_cards'] ?? 0),
            'printed_cards' => (int)($row['printed_cards'] ?? 0),
            'retrieved_cards' => (int)($row['retrieved_cards'] ?? 0),
            'pending_print_cards' => (int)($row['pending_print_cards'] ?? 0),
            'total_secretariats' => (int)($row['total_secretariats'] ?? 0),
            'month_members' => (int)($row['month_members'] ?? 0),
            'total_paye' => (float)($row['total_paye'] ?? 0),
            'valid_accounts' => (int)($row['paid_cards'] ?? 0),
            'stats_by_type' => [
                'actuels' => (int)($row['actuels'] ?? 0),
                'anciens' => (int)($row['anciens'] ?? 0),
            ],
            'par_secretariat' => array_map(static fn($item) => [
                'secretariat' => $item['secretariat'] ?? 'Non defini',
                'total' => (int)($item['total'] ?? 0),
                'actuels' => (int)($item['actuels'] ?? 0),
                'anciens' => (int)($item['anciens'] ?? 0),
                'pending_cards' => (int)($item['pending_cards'] ?? 0),
                'printed_cards' => (int)($item['printed_cards'] ?? 0),
                'retrieved_cards' => (int)($item['retrieved_cards'] ?? 0),
            ], $bySecretariat),
        ],
    ]);
}

function secretariatStats(): void {
    $t = resolveMembersTable();
    $secretariat = secretariatExpr($t);
    $rows = db()->query("
        SELECT
            {$secretariat} AS secretariat,
            COUNT(*) AS total,
            SUM(type_membre = 'ACTUEL') AS actuels,
            SUM(type_membre = 'ANCIEN') AS anciens,
            SUM(COALESCE(NULLIF(card_status, ''), 'pending') = 'pending') AS pending_cards,
            SUM(COALESCE(NULLIF(card_status, ''), 'pending') = 'printed') AS printed_cards,
            SUM(COALESCE(NULLIF(card_status, ''), 'pending') = 'retrieved') AS retrieved_cards
        FROM {$t}
        WHERE statut_paiement = 'PAYE'
        GROUP BY {$secretariat}
        ORDER BY total DESC, secretariat ASC
    ")->fetchAll();

    respond([
        'success' => true,
        'data' => array_map(static fn($item) => [
            'secretariat' => $item['secretariat'] ?? 'Non defini',
            'total' => (int)($item['total'] ?? 0),
            'actuels' => (int)($item['actuels'] ?? 0),
            'anciens' => (int)($item['anciens'] ?? 0),
            'pending_cards' => (int)($item['pending_cards'] ?? 0),
            'printed_cards' => (int)($item['printed_cards'] ?? 0),
            'retrieved_cards' => (int)($item['retrieved_cards'] ?? 0),
        ], $rows),
    ]);
}

function listUnpaid(): void {
    $params = [];
    $t = resolveMembersTable();
    $where = baseWhere($params, false) . " AND ({$t}.statut_paiement IS NULL OR {$t}.statut_paiement <> 'PAYE')";
    [$page, $perPage, $offset] = pagination();
    $idColumn = idColumnRef($t);
    $updatedColumn = columnRef($t, ['updated_at', 'created_at']) ?: $idColumn;

    $count = db()->prepare("SELECT COUNT(*) AS total FROM {$t} {$where}");
    $count->execute($params);
    $total = (int)($count->fetch()['total'] ?? 0);

    $stmt = db()->prepare("
        SELECT *
        FROM {$t}
        {$where}
        ORDER BY {$updatedColumn} DESC, {$idColumn} DESC
        LIMIT {$perPage} OFFSET {$offset}
    ");
    $stmt->execute($params);
    $rows = array_map('normalizeMemberRow', $stmt->fetchAll());

    respond([
        'success' => true,
        'data' => $rows,
        'pagination' => [
            'current_page' => $page,
            'last_page' => (int)ceil($total / $perPage),
            'per_page' => $perPage,
            'total' => $total,
        ],
    ]);
}

function validatePayment(): void {
    $data = inputJson();
    $id = (int)($data['membre_id'] ?? $data['id'] ?? 0);
    if ($id <= 0) respond(['success' => false, 'message' => 'membre_id requis.'], 400);

    $ref = clean($data['ref_paiement'] ?? '');
    $transaction = clean($data['transaction_id'] ?? '');
    $numeroWave = clean($data['numero_wave'] ?? '');
    $devise = clean($data['devise_paiement'] ?? 'XOF') ?: 'XOF';
    $montant = (float)($data['somme_paye'] ?? 0);
    $note = clean($data['note'] ?? '');

    if ($ref === '' || $transaction === '') {
        respond(['success' => false, 'message' => 'Référence et transaction requises.'], 400);
    }

    $t = resolveMembersTable();
    $idColumn = idColumnRef($t);
    $stmt = db()->prepare("
        UPDATE {$t}
        SET statut_paiement = 'PAYE',
            payment_status_wave = 'succeeded',
            ref_paiement = ?,
            transaction_id = ?,
            numero_wave = ?,
            somme_paye = ?,
            devise_paiement = ?,
            card_status = COALESCE(NULLIF(card_status, ''), 'pending'),
            updated_at = NOW()
        WHERE {$idColumn} = ?
        LIMIT 1
    ");
    $stmt->execute([$ref, $transaction, $numeroWave, $montant, $devise, $id]);

    try {
        $log = db()->prepare("
            INSERT INTO membre_paiements
                (membre_id, ref_paiement, transaction_id, numero_wave, somme_paye, devise_paiement, statut, note, created_at)
            VALUES (?, ?, ?, ?, ?, ?, 'succeeded', ?, NOW())
        ");
        $log->execute([$id, $ref, $transaction, $numeroWave, $montant, $devise, $note]);
    } catch (Throwable $ignored) {
        // La table d'historique est optionnelle; la validation du membre reste effective.
    }

    respond(['success' => true, 'message' => 'Paiement validé.', 'membre_id' => $id]);
}

function updateMember(): void {
    $data = inputJson();
    $id = (int)($data['membre_id'] ?? $data['id'] ?? 0);
    if ($id <= 0) respond(['success' => false, 'message' => 'membre_id requis.'], 400);

    $t = resolveMembersTable();
    $idColumn = idColumnRef($t);
    $fieldMap = [
        'prenom' => ['prenom'],
        'nom' => ['nom'],
        'contact' => ['contact', 'telephone', 'phone'],
        'sexe' => ['sexe'],
        'date_naissance' => ['date_naissance', 'date_naiss'],
        'lieu_naissance' => ['lieu_naissance', 'lieu_naiss'],
        'secretariat_poste' => ['secretariat_poste', 'secretariat_debut', 'sr_debut'],
        'region' => ['region'],
        'sous_comite' => ['sous_comite', 'souscomite_debut'],
        'section' => ['section'],
        'qualite_membre' => ['qualite_membre'],
        'statut' => ['statut'],
        'type_membre' => ['type_membre'],
        'annee_debut' => ['annee_debut'],
        'sr_debut' => ['sr_debut', 'secretariat_debut'],
        'card_status' => ['card_status'],
        'matricule' => ['matricule', 'matricule_gen'],
    ];

    $set = [];
    $params = [];
    foreach ($fieldMap as $field => $candidates) {
        if (!array_key_exists($field, $data)) continue;
        $actualField = firstExistingColumn($t, $candidates);
        if (!$actualField) continue;
        $value = is_string($data[$field]) ? clean($data[$field]) : $data[$field];
        if ($field === 'type_membre' && !in_array($value, ['ACTUEL', 'ANCIEN'], true)) continue;
        if ($field === 'card_status' && !in_array($value, ['pending', 'printed', 'retrieved'], true)) continue;
        $set[] = qid($actualField) . " = ?";
        $params[] = $value === '' ? null : $value;
    }

    if (!$set) respond(['success' => false, 'message' => 'Aucune donnee modifiable recue.'], 400);
    if (hasColumn($t, 'updated_at')) $set[] = "updated_at = NOW()";

    $params[] = $id;
    $stmt = db()->prepare("UPDATE {$t} SET " . implode(', ', $set) . " WHERE {$idColumn} = ? LIMIT 1");
    $stmt->execute($params);

    $fresh = db()->prepare("SELECT * FROM {$t} WHERE {$idColumn} = ? LIMIT 1");
    $fresh->execute([$id]);
    respond([
        'success' => true,
        'message' => 'Informations du membre mises a jour.',
        'data' => normalizeMemberRow($fresh->fetch() ?: []),
    ]);
}

function notifyCardReady(): void {
    $data = inputJson();
    $id = (int)($data['membre_id'] ?? $data['id'] ?? 0);
    if ($id <= 0) respond(['success' => false, 'message' => 'membre_id requis.'], 400);

    $t = resolveMembersTable();
    $idColumn = idColumnRef($t);
    $notifyColumn = ensureNotificationColumns($t);
    if (!$notifyColumn) {
        respond(['success' => false, 'message' => 'Colonne de suivi SMS manquante. Ajoutez card_notified_at sur la table des membres.'], 500);
    }

    $stmt = db()->prepare("SELECT * FROM {$t} WHERE {$idColumn} = ? LIMIT 1");
    $stmt->execute([$id]);
    $membre = $stmt->fetch();
    if (!$membre) respond(['success' => false, 'message' => 'Membre introuvable.'], 404);

    if (($membre['statut_paiement'] ?? '') !== 'PAYE') {
        respond(['success' => false, 'message' => 'Le membre doit avoir paye avant notification.'], 400);
    }

    $cardStatus = $membre['card_status'] ?: 'pending';
    if ($cardStatus !== 'printed') {
        respond(['success' => false, 'message' => 'La carte doit etre imprimee et en attente de retrait.'], 400);
    }

    if (!empty($membre[$notifyColumn])) {
        respond([
            'success' => true,
            'message' => 'Ce membre a deja ete notifie.',
            'notified_at' => $membre[$notifyColumn],
        ]);
    }

    $phone = normalizePhone((string)($membre['contact'] ?? $membre['telephone'] ?? $membre['phone'] ?? ''));
    if ($phone === '') respond(['success' => false, 'message' => 'Contact telephonique manquant.'], 400);

    $name = clean(trim(($membre['prenom'] ?? '') . ' ' . ($membre['nom'] ?? ''))) ?: 'cher membre';
    $message = "AEEMCI: Salam {$name}, votre carte de membre est disponible et en attente de retrait. Merci de passer la recuperer.";
    $sms = sendSms($phone, $message);
    if (!$sms['success']) {
        respond(['success' => false, 'message' => $sms['message'] ?: 'SMS non envoye.'], 502);
    }

    $updatedByColumn = firstExistingColumn($t, ['card_notified_by', 'retrait_sms_sent_by', 'sms_retrait_by']);
    $updatedAtSql = hasColumn($t, 'updated_at') ? ', updated_at = NOW()' : '';
    if ($updatedByColumn) {
        $update = db()->prepare("UPDATE {$t} SET " . qid($notifyColumn) . " = NOW(), " . qid($updatedByColumn) . " = ?{$updatedAtSql} WHERE {$idColumn} = ? LIMIT 1");
        $update->execute([clean($data['notified_by'] ?? 'admin'), $id]);
    } else {
        $update = db()->prepare("UPDATE {$t} SET " . qid($notifyColumn) . " = NOW(){$updatedAtSql} WHERE {$idColumn} = ? LIMIT 1");
        $update->execute([$id]);
    }

    $fresh = db()->prepare("SELECT " . qid($notifyColumn) . " AS notified_at FROM {$t} WHERE {$idColumn} = ? LIMIT 1");
    $fresh->execute([$id]);
    $row = $fresh->fetch() ?: [];

    respond([
        'success' => true,
        'message' => 'SMS envoye. Le membre est marque comme deja notifie.',
        'membre_id' => $id,
        'notified_at' => $row['notified_at'] ?? date('Y-m-d H:i:s'),
    ]);
}

try {
    $action = $_GET['action'] ?? 'membres';

    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'stats') stats();
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array($action, ['secretariats_stats', 'stats_secretariats', 'par_secretariat'], true)) secretariatStats();
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && $action === 'membres') listMembers(true);
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && in_array($action, ['non_payes', 'membres_non_payes', 'paiements_a_valider'], true)) listUnpaid();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $action === 'valider_paiement') validatePayment();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['modifier_membre', 'update_membre'], true)) updateMember();
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && in_array($action, ['notifier_retrait_carte', 'notify_card_pickup'], true)) notifyCardReady();

    respond(['success' => false, 'message' => 'Action inconnue.'], 400);
} catch (Throwable $e) {
    respond(['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()], 500);
}
