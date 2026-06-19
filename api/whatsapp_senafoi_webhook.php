<?php
// =============================================================================
// SENAFOI 26 — Webhook WhatsApp Admin + Claude AI
// =============================================================================
// Twilio WhatsApp webhook URL:
//   https://api.aeemci-ce.ci/senafoi/whatsapp_senafoi_webhook.php
//
// Capacites:
// - stats dashboard par jour/mois/date/periode
// - quota total/restant/valides
// - recherche seminariste par matricule
// - recherche par nom/prenom avec pagination 10 par 10
// - selection d'un resultat de recherche
// - liste des codes promo
// - creation de code promo apres confirmation claire
// =============================================================================

ini_set('display_errors', 0);
error_reporting(E_ALL);
date_default_timezone_set('Africa/Abidjan');

// -----------------------------------------------------------------------------
// Configuration
// -----------------------------------------------------------------------------

define('CLAUDE_API_KEY', getenv('ANTHROPIC_API_KEY') ?: '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');
define('CLAUDE_MODEL', getenv('ANTHROPIC_MODEL') ?: 'claude-sonnet-4-5');

define('DB_HOST', getenv('SENAFOI_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('SENAFOI_DB_NAME') ?: 'capbvkkqah_aeemci');
define('DB_USER', getenv('SENAFOI_DB_USER') ?: 'capbvkkqah_aeemci');
define('DB_PASS', getenv('SENAFOI_DB_PASS') ?: '0Objectif-');
define('SENAFOI_API_BASE', getenv('SENAFOI_API_BASE') ?: 'https://api.aeemci-ce.ci/senafoi');

// Laisse vide pour autoriser tous les numeros. Recommande: "whatsapp:+2250153676062,whatsapp:+225..."
define('ADMIN_WHATSAPP_ALLOWLIST', getenv('SENAFOI_WA_ADMINS') ?: '');

define('SENAFOI_YEAR', 2026);
define('MAX_HIST', 24);
define('PAGE_SIZE', 10);

$LOG_FILE = __DIR__ . '/senafoi_whatsapp_log.txt';
$STATE_FILE = __DIR__ . '/senafoi_whatsapp_states.json';

// -----------------------------------------------------------------------------
// Utilitaires
// -----------------------------------------------------------------------------

function logIt(string $tag, $data): void {
    global $LOG_FILE;
    file_put_contents(
        $LOG_FILE,
        '[' . date('Y-m-d H:i:s') . "] [$tag] " . json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n",
        FILE_APPEND
    );
}

function twiRespond(string $text): never {
    header('Content-Type: text/xml; charset=UTF-8');
    echo '<?xml version="1.0" encoding="UTF-8"?><Response><Message>'
        . htmlspecialchars($text, ENT_XML1, 'UTF-8')
        . '</Message></Response>';
    exit;
}

function normalizeWaNumber(string $from): string {
    return strtolower(trim($from));
}

function isAllowedAdmin(string $from): bool {
    $raw = trim(ADMIN_WHATSAPP_ALLOWLIST);
    if ($raw === '') return true;
    $allowed = array_map('normalizeWaNumber', array_filter(array_map('trim', explode(',', $raw))));
    return in_array(normalizeWaNumber($from), $allowed, true);
}

function loadStates(): array {
    global $STATE_FILE;
    if (!file_exists($STATE_FILE)) return [];
    $data = json_decode(file_get_contents($STATE_FILE), true);
    return is_array($data) ? $data : [];
}

function saveStates(array $states): void {
    global $STATE_FILE;
    file_put_contents($STATE_FILE, json_encode($states, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
}

function dbConnect(): ?PDO {
    try {
        return new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
            DB_USER,
            DB_PASS,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_TIMEOUT => 8,
            ]
        );
    } catch (Throwable $e) {
        logIt('DB_ERR', $e->getMessage());
        return null;
    }
}

function dbRequire(): PDO {
    $pdo = dbConnect();
    if (!$pdo) {
        throw new RuntimeException('Connexion base de données impossible. Vérifie DB_HOST, DB_NAME, DB_USER et DB_PASS.');
    }
    return $pdo;
}

function dbAll(string $sql, array $params = []): array {
    $pdo = dbRequire();
    try {
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt->fetchAll();
    } catch (Throwable $e) {
        logIt('DB_QUERY_ERR', ['sql' => $sql, 'error' => $e->getMessage()]);
        throw $e;
    }
}

function dbOne(string $sql, array $params = []): ?array {
    $rows = dbAll($sql, $params);
    return $rows[0] ?? null;
}

function dbExec(string $sql, array $params = []): bool {
    $pdo = dbRequire();
    try {
        $stmt = $pdo->prepare($sql);
        return $stmt->execute($params);
    } catch (Throwable $e) {
        logIt('DB_EXEC_ERR', ['sql' => $sql, 'error' => $e->getMessage()]);
        throw $e;
    }
}

function fmtMoney($amount, string $currency = 'XOF'): string {
    return number_format((float)$amount, 0, ',', ' ') . ' ' . $currency;
}

function fmtDate(?string $value): string {
    if (!$value) return 'N/A';
    $ts = strtotime($value);
    return $ts ? date('d/m/Y H:i', $ts) : $value;
}

function cleanText(string $s): string {
    return trim(preg_replace('/\s+/', ' ', $s));
}

// -----------------------------------------------------------------------------
// Requetes metier SENAFOI
// -----------------------------------------------------------------------------

function periodRange(string $period = 'today', ?string $date = null): array {
    $period = strtolower($period);
    $today = date('Y-m-d');
    $date = $date ?: $today;

    if ($period === 'all') {
        return [null, null, 'toute la periode'];
    }

    if ($period === 'month' || $period === 'mois') {
        $start = date('Y-m-01 00:00:00', strtotime($date));
        $end = date('Y-m-t 23:59:59', strtotime($date));
        return [$start, $end, 'mois de ' . date('m/Y', strtotime($date))];
    }

    if ($period === 'date' || $period === 'day' || $period === 'jour' || $period === 'today') {
        $start = date('Y-m-d 00:00:00', strtotime($date));
        $end = date('Y-m-d 23:59:59', strtotime($date));
        return [$start, $end, $date === $today ? "aujourd'hui" : date('d/m/Y', strtotime($date))];
    }

    return [date('Y-m-d 00:00:00'), date('Y-m-d 23:59:59'), "aujourd'hui"];
}

function getDashboardStats(string $period = 'today', ?string $date = null): array {
    [$start, $end, $label] = periodRange($period, $date);
    $where = "WHERE CAST(annee_seminaire AS CHAR) = :annee";
    $params = [':annee' => (string)SENAFOI_YEAR];

    if ($start && $end) {
        $where .= " AND date_inscription BETWEEN :start AND :end";
        $params[':start'] = $start;
        $params[':end'] = $end;
    }

    $row = dbOne("
        SELECT
            COUNT(*) AS total_dossiers,
            SUM(CASE WHEN payment_status_wave = 'succeeded' THEN 1 ELSE 0 END) AS paiements_valides,
            SUM(CASE WHEN statut_inscription = 'VALIDEE' THEN 1 ELSE 0 END) AS inscriptions_validees,
            SUM(CASE WHEN statut_inscription = 'VALIDEE' AND transport = 'convoi' THEN 1 ELSE 0 END) AS convoi,
            SUM(CASE WHEN statut_inscription = 'VALIDEE' AND transport = 'personnel' THEN 1 ELSE 0 END) AS personnel,
            SUM(CASE WHEN statut_inscription = 'VALIDEE' AND sexe = 'M' THEN 1 ELSE 0 END) AS hommes,
            SUM(CASE WHEN statut_inscription = 'VALIDEE' AND sexe = 'F' THEN 1 ELSE 0 END) AS femmes,
            COALESCE(SUM(CASE WHEN statut_inscription = 'VALIDEE' THEN somme_paye ELSE 0 END), 0) AS total_paye
        FROM seminaristes
        $where
    ", $params) ?: [];

    $byLevel = dbAll("
        SELECT COALESCE(NULLIF(niveau_seminaire, ''), 'Non defini') AS niveau, COUNT(*) AS total
        FROM seminaristes
        $where
          AND statut_inscription = 'VALIDEE'
        GROUP BY COALESCE(NULLIF(niveau_seminaire, ''), 'Non defini')
        ORDER BY total DESC
        LIMIT 8
    ", $params);

    $years = [];
    if ((int)($row['total_dossiers'] ?? 0) === 0) {
        $years = dbAll("
            SELECT COALESCE(CAST(annee_seminaire AS CHAR), 'NULL') AS annee, COUNT(*) AS total
            FROM seminaristes
            GROUP BY COALESCE(CAST(annee_seminaire AS CHAR), 'NULL')
            ORDER BY total DESC
            LIMIT 8
        ");
    }

    return ['label' => $label, 'stats' => $row, 'levels' => $byLevel, 'years' => $years];
}

function formatDashboardStats(array $data): string {
    $s = $data['stats'];
    $levels = $data['levels'] ?? [];
    $out = "📊 *Stats SENAFOI 26 — {$data['label']}*\n\n";
    $out .= "Dossiers: *" . (int)($s['total_dossiers'] ?? 0) . "*\n";
    $out .= "Paiements Wave validés: *" . (int)($s['paiements_valides'] ?? 0) . "*\n";
    $out .= "Inscriptions validées: *" . (int)($s['inscriptions_validees'] ?? 0) . "*\n";
    $out .= "Montant payé: *" . fmtMoney($s['total_paye'] ?? 0) . "*\n";
    $out .= "Transport: convoi *" . (int)($s['convoi'] ?? 0) . "* / personnel *" . (int)($s['personnel'] ?? 0) . "*\n";
    $out .= "Sexe: H *" . (int)($s['hommes'] ?? 0) . "* / F *" . (int)($s['femmes'] ?? 0) . "*";

    if ($levels) {
        $out .= "\n\n*Niveaux principaux*\n";
        foreach ($levels as $r) {
            $out .= "- {$r['niveau']}: {$r['total']}\n";
        }
    }

    if (empty($levels) && !empty($data['years'])) {
        $out .= "\n\n⚠️ Aucun dossier trouvé pour l'année " . SENAFOI_YEAR . ".";
        $out .= "\nAnnées présentes dans cette base:\n";
        foreach ($data['years'] as $r) {
            $out .= "- {$r['annee']}: {$r['total']}\n";
        }
    }
    return trim($out);
}

function getQuotaInfo(): array {
    $quota = dbOne("SELECT quota_total FROM seminaire_quotas WHERE CAST(annee_seminaire AS CHAR) = :annee LIMIT 1", [
        ':annee' => (string)SENAFOI_YEAR,
    ]);
    $quotaTotal = (int)($quota['quota_total'] ?? 0);

    $used = dbOne("
        SELECT COUNT(*) AS total
        FROM seminaristes
        WHERE CAST(annee_seminaire AS CHAR) = :annee
          AND payment_status_wave = 'succeeded'
    ", [':annee' => (string)SENAFOI_YEAR]);

    $valides = (int)($used['total'] ?? 0);
    return [
        'quota_total' => $quotaTotal,
        'inscriptions_validees' => $valides,
        'places_restantes' => max(0, $quotaTotal - $valides),
    ];
}

function formatQuota(array $q): string {
    return "🎯 *Quota SENAFOI 26*\n\n"
        . "Quota total: *{$q['quota_total']}*\n"
        . "Inscriptions validées Wave: *{$q['inscriptions_validees']}*\n"
        . "Places restantes: *{$q['places_restantes']}*";
}

function seminaristeStatusSql(string $mode = 'valid', string $alias = ''): string {
    $prefix = $alias !== '' ? $alias . '.' : '';
    if ($mode === 'invalid') {
        return " AND ({$prefix}payment_status_wave IS NULL OR {$prefix}payment_status_wave <> 'succeeded')";
    }
    if ($mode === 'all') {
        return '';
    }
    return " AND {$prefix}payment_status_wave = 'succeeded'";
}

function detectSearchMode(string $msg): string {
    $text = mb_strtolower($msg, 'UTF-8');
    if (preg_match('/\b(non valid|non-valid|pas valid|non pay|non-pay|pas pay|en attente|expire|expir|annul|erreur wave|paiement non|pas confirm)/u', $text)) {
        return 'invalid';
    }
    if (preg_match('/\b(tous|toutes|global|tout le monde)\b/u', $text)) {
        return 'all';
    }
    return 'valid';
}

function searchModeLabel(string $mode): string {
    if ($mode === 'invalid') return 'non validÃ©s';
    if ($mode === 'all') return 'tous statuts';
    return 'validÃ©s';
}

function tableExists(string $table): bool {
    $row = dbOne("
        SELECT TABLE_NAME
        FROM INFORMATION_SCHEMA.TABLES
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table
        LIMIT 1
    ", [':table' => $table]);
    return (bool)$row;
}

function tableColumns(string $table): array {
    $rows = dbAll("
        SELECT COLUMN_NAME
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = :table
    ", [':table' => $table]);
    return array_column($rows, 'COLUMN_NAME');
}

function firstExistingColumn(string $table, array $candidates): ?string {
    $columns = tableColumns($table);
    foreach ($candidates as $candidate) {
        if (in_array($candidate, $columns, true)) return $candidate;
    }
    return null;
}

function rememberSearch(array &$user, array $res, string $mode): void {
    $user['last_search'] = [
        'query' => $res['query'] ?? '',
        'page' => (int)($res['page'] ?? 1),
        'mode' => $mode,
        'result_ids' => array_column($res['rows'] ?? [], 'id'),
    ];
}

function getSeminaristeByMatricule(string $matricule, string $mode = 'valid'): ?array {
    return dbOne("
        SELECT *
        FROM seminaristes
        WHERE matricule_seminaire = :matricule
        " . seminaristeStatusSql($mode) . "
        LIMIT 1
    ", [':matricule' => trim($matricule)]);
}

function getSeminaristeById(int $id): ?array {
    return dbOne("SELECT * FROM seminaristes WHERE id = :id LIMIT 1", [':id' => $id]);
}

function formatSeminariste(?array $s): string {
    if (!$s) return "Je n'ai trouvé aucun séminariste avec cette information.";

    $nom = trim(($s['prenom'] ?? '') . ' ' . ($s['nom'] ?? ''));
    $out = "👤 *{$nom}*\n\n";
    $out .= "Matricule: *" . ($s['matricule_seminaire'] ?? 'N/A') . "*\n";
    $out .= "Niveau: " . ($s['niveau_seminaire'] ?? 'N/A') . "\n";
    $out .= "Sexe: " . ($s['sexe'] ?? 'N/A') . "\n";
    $out .= "Contact: " . ($s['contact'] ?? 'N/A') . "\n";
    $out .= "Wave: " . ($s['numero_wave'] ?? 'N/A') . "\n";
    $out .= "Parent: " . ($s['contact_parent'] ?? 'N/A') . "\n";
    $out .= "Région/Ville: " . ($s['secretariat_regional'] ?? 'N/A') . "\n";
    $out .= "Transport: " . ($s['transport'] ?? 'N/A') . " / " . ($s['car_transport'] ?? 'N/A') . "\n";
    $out .= "Dortoir: " . ($s['dortoir'] ?? 'N/A') . "\n";
    $out .= "Paiement: " . ($s['statut_paiement'] ?? 'N/A') . " / Wave " . ($s['payment_status_wave'] ?? 'N/A') . "\n";
    $out .= "Montant: " . fmtMoney($s['somme_paye'] ?? 0, $s['devise_paiement'] ?? 'XOF') . "\n";
    $out .= "Inscription: " . ($s['statut_inscription'] ?? 'N/A') . "\n";
    $out .= "Maladie: " . (((int)($s['malade'] ?? 0) === 1) ? ($s['detail_malade'] ?? 'Oui') : 'Non') . "\n";
    $out .= "Date: " . fmtDate($s['date_inscription'] ?? $s['created_at'] ?? null);
    return $out;
}

function searchSeminaristes(string $query, int $page = 1, string $mode = 'valid'): array {
    $page = max(1, $page);
    $offset = ($page - 1) * PAGE_SIZE;
    $needle = '%' . trim($query) . '%';
    $statusSql = seminaristeStatusSql($mode);
    $params = [
        ':annee' => (string)SENAFOI_YEAR,
        ':q1' => $needle,
        ':q2' => $needle,
        ':q3' => $needle,
    ];

    $totalRow = dbOne("
        SELECT COUNT(*) AS total
        FROM seminaristes
        WHERE CAST(annee_seminaire AS CHAR) = :annee
          AND (nom LIKE :q1 OR prenom LIKE :q2 OR CONCAT(prenom, ' ', nom) LIKE :q3)
          $statusSql
    ", $params);

    $rows = dbAll("
        SELECT id, prenom, nom, matricule_seminaire, contact, numero_wave,
               niveau_seminaire, statut_paiement, payment_status_wave, statut_inscription
        FROM seminaristes
        WHERE CAST(annee_seminaire AS CHAR) = :annee
          AND (nom LIKE :q1 OR prenom LIKE :q2 OR CONCAT(prenom, ' ', nom) LIKE :q3)
          $statusSql
        ORDER BY nom ASC, prenom ASC
        LIMIT " . PAGE_SIZE . " OFFSET " . (int)$offset,
        $params
    );

    return [
        'query' => $query,
        'page' => $page,
        'mode' => $mode,
        'total' => (int)($totalRow['total'] ?? 0),
        'rows' => $rows,
    ];
}

function formatDbDiagnostic(): string {
    $db = dbOne("SELECT DATABASE() AS db_name, @@hostname AS host_name");
    $total = dbOne("SELECT COUNT(*) AS total FROM seminaristes");
    $years = dbAll("
        SELECT COALESCE(CAST(annee_seminaire AS CHAR), 'NULL') AS annee, COUNT(*) AS total
        FROM seminaristes
        GROUP BY COALESCE(CAST(annee_seminaire AS CHAR), 'NULL')
        ORDER BY total DESC
        LIMIT 10
    ");

    $out = "🧪 *Diagnostic base SENAFOI*\n\n";
    $out .= "Base: *" . ($db['db_name'] ?? DB_NAME) . "*\n";
    $out .= "Host SQL: *" . ($db['host_name'] ?? DB_HOST) . "*\n";
    $out .= "Table seminaristes: *" . (int)($total['total'] ?? 0) . "* lignes\n\n";
    $out .= "*Années trouvées*\n";
    foreach ($years as $r) {
        $out .= "- {$r['annee']}: {$r['total']}\n";
    }
    return trim($out);
}

function formatSearchResults(array $res): string {
    $total = (int)$res['total'];
    $page = (int)$res['page'];
    $rows = $res['rows'] ?? [];
    $mode = $res['mode'] ?? 'valid';
    if (!$total) return "Aucun séminariste trouvé pour « {$res['query']} ». ";

    $start = (($page - 1) * PAGE_SIZE) + 1;
    $end = min($total, $start + count($rows) - 1);
    $out = "🔎 *Résultats pour « {$res['query']} »*\n";
    $out .= "{$start}-{$end} sur {$total}\n\n";

    foreach ($rows as $i => $s) {
        $n = $i + 1;
        $name = cleanText(($s['prenom'] ?? '') . ' ' . ($s['nom'] ?? ''));
        $mat = $s['matricule_seminaire'] ?: 'N/A';
        $pay = $s['payment_status_wave'] ?: ($s['statut_paiement'] ?? 'N/A');
        $out .= "{$n}. {$name}\n   {$mat} · {$s['niveau_seminaire']} · {$pay}\n";
    }

    $out .= "\nRéponds par le numéro (*1*, *2*...) pour voir une fiche.";
    if ($end < $total) $out .= "\nDis *suivant* pour la suite.";
    return trim($out);
}

function listRecentSeminaristes(int $limit = 5, ?string $sexe = null, string $mode = 'valid'): array {
    $limit = max(1, min(20, $limit));
    $where = "WHERE CAST(annee_seminaire AS CHAR) = :annee" . seminaristeStatusSql($mode);
    $params = [':annee' => (string)SENAFOI_YEAR];
    if ($sexe) {
        $where .= " AND sexe = :sexe";
        $params[':sexe'] = strtoupper($sexe);
    }
    $rows = dbAll("
        SELECT id, prenom, nom, matricule_seminaire, sexe, contact, numero_wave,
               niveau_seminaire, transport, dortoir, date_inscription, payment_status_wave
        FROM seminaristes
        $where
        ORDER BY date_inscription DESC, id DESC
        LIMIT " . (int)$limit,
        $params
    );
    return ['rows' => $rows, 'limit' => $limit, 'mode' => $mode, 'sexe' => $sexe];
}

function formatSeminaristeList(array $data, string $title): string {
    $rows = $data['rows'] ?? [];
    if (!$rows) return "Aucun resultat pour cette demande.";
    $out = "*{$title}*\n\n";
    foreach ($rows as $i => $s) {
        $n = $i + 1;
        $name = cleanText(($s['prenom'] ?? '') . ' ' . ($s['nom'] ?? ''));
        $out .= "{$n}. {$name}\n";
        $out .= "   " . ($s['matricule_seminaire'] ?? 'N/A') . " - " . ($s['niveau_seminaire'] ?? 'N/A') . " - " . fmtDate($s['date_inscription'] ?? null) . "\n";
    }
    return trim($out);
}

function getSeminaristeNotes(string $query, string $mode = 'valid'): string {
    $s = null;
    if (preg_match('/^SEM20/i', trim($query))) {
        $s = getSeminaristeByMatricule(trim($query), $mode);
    }
    if (!$s) {
        $res = searchSeminaristes($query, 1, $mode);
        if ((int)$res['total'] > 1) {
            return formatSearchResults($res);
        }
        $id = $res['rows'][0]['id'] ?? null;
        if ($id) $s = getSeminaristeById((int)$id);
    }
    if (!$s) return "Aucun seminariste " . searchModeLabel($mode) . " trouve pour les notes.";

    $nom = cleanText(($s['prenom'] ?? '') . ' ' . ($s['nom'] ?? ''));
    return "*Notes / evaluation - {$nom}*\n\n"
        . "Matricule: *" . ($s['matricule_seminaire'] ?? 'N/A') . "*\n"
        . "Niveau: " . ($s['niveau_seminaire'] ?? $s['niveau_actuel'] ?? 'N/A') . "\n"
        . "Moyenne finale: " . ($s['moyenne_finale'] ?? 'N/A') . "\n"
        . "Conduite: " . ($s['conduite'] ?? 'N/A') . "\n"
        . "Decision: " . ($s['decision_participant'] ?? 'N/A') . "\n"
        . "Niveau prochain: " . ($s['niveau_prochain'] ?? 'N/A');
}

function statsByField(string $field, string $label, string $mode = 'valid'): string {
    $allowed = [
        'niveau_seminaire' => 'niveau_seminaire',
        'secretariat_regional' => 'secretariat_regional',
        'dortoir' => 'dortoir',
    ];
    if (!isset($allowed[$field])) return "Statistique non supportee.";
    $column = $allowed[$field];
    $rows = dbAll("
        SELECT COALESCE(NULLIF({$column}, ''), 'Non defini') AS item,
               COUNT(*) AS total,
               SUM(CASE WHEN sexe = 'M' THEN 1 ELSE 0 END) AS hommes,
               SUM(CASE WHEN sexe = 'F' THEN 1 ELSE 0 END) AS femmes
        FROM seminaristes
        WHERE CAST(annee_seminaire AS CHAR) = :annee
        " . seminaristeStatusSql($mode) . "
        GROUP BY COALESCE(NULLIF({$column}, ''), 'Non defini')
        ORDER BY total DESC
        LIMIT 20
    ", [':annee' => (string)SENAFOI_YEAR]);

    if (!$rows) return "Aucune statistique trouvee pour {$label}.";
    $out = "*Stats par {$label} - " . searchModeLabel($mode) . "*\n\n";
    foreach ($rows as $r) {
        $out .= "- {$r['item']}: *{$r['total']}* (H {$r['hommes']} / F {$r['femmes']})\n";
    }
    return trim($out);
}

function statsForLevel(string $niveau, string $mode = 'valid'): string {
    $row = dbOne("
        SELECT COUNT(*) AS total,
               SUM(CASE WHEN sexe = 'M' THEN 1 ELSE 0 END) AS hommes,
               SUM(CASE WHEN sexe = 'F' THEN 1 ELSE 0 END) AS femmes
        FROM seminaristes
        WHERE CAST(annee_seminaire AS CHAR) = :annee
          AND niveau_seminaire LIKE :niveau
        " . seminaristeStatusSql($mode) . "
    ", [
        ':annee' => (string)SENAFOI_YEAR,
        ':niveau' => '%' . trim($niveau) . '%',
    ]) ?: [];

    return "*Niveau {$niveau} - " . searchModeLabel($mode) . "*\n\n"
        . "Total: *" . (int)($row['total'] ?? 0) . "*\n"
        . "Hommes: *" . (int)($row['hommes'] ?? 0) . "*\n"
        . "Femmes: *" . (int)($row['femmes'] ?? 0) . "*";
}

function listSeminaristesByPromo(string $code, string $mode = 'valid'): string {
    $columns = tableColumns('seminaristes');
    $promoColumn = null;
    foreach (['code_promo', 'promo_code', 'code_promo_utilise', 'coupon_code'] as $candidate) {
        if (in_array($candidate, $columns, true)) {
            $promoColumn = $candidate;
            break;
        }
    }
    if (!$promoColumn) {
        return "Je ne vois pas encore de colonne code promo dans seminaristes. Il faut enregistrer le code utilise lors de l'inscription pour sortir cette liste.";
    }

    $rows = dbAll("
        SELECT id, prenom, nom, matricule_seminaire, niveau_seminaire, somme_paye, date_inscription, payment_status_wave
        FROM seminaristes
        WHERE CAST(annee_seminaire AS CHAR) = :annee
          AND {$promoColumn} = :code
        " . seminaristeStatusSql($mode) . "
        ORDER BY date_inscription DESC, id DESC
        LIMIT 30
    ", [
        ':annee' => (string)SENAFOI_YEAR,
        ':code' => strtoupper(trim($code)),
    ]);

    return formatSeminaristeList(['rows' => $rows], "Seminaristes avec code promo " . strtoupper(trim($code)));
}

function extractPromoCodeFromText(string $msg): ?string {
    preg_match_all('/\b[A-Z0-9_-]{3,}\b/u', strtoupper($msg), $matches);
    $ignored = ['CODE', 'PROMO', 'PROMOS', 'PAYE', 'PAYES', 'PAYEE', 'UTILISE', 'UTILISES', 'LISTE', 'SEMINARISTE', 'SEMINARISTES'];
    foreach ($matches[0] ?? [] as $candidate) {
        if (!in_array($candidate, $ignored, true)) return $candidate;
    }
    return null;
}

function pointageStatsToday(): string {
    $table = null;
    foreach (['seminaire_pointages', 'seminaire_presences', 'seminaire_presence', 'pointages', 'presences'] as $candidate) {
        if (tableExists($candidate)) {
            $table = $candidate;
            break;
        }
    }
    if (!$table) return "Je n'ai pas trouve la table de pointage/presence.";

    $idCol = firstExistingColumn($table, ['seminariste_id', 'id_seminariste', 'participant_id']);
    $dateCol = firstExistingColumn($table, ['date_pointage', 'date_presence', 'created_at', 'date']);
    if (!$idCol || !$dateCol) return "La table {$table} existe, mais je ne reconnais pas ses colonnes id/date.";

    $total = dbOne("
        SELECT COUNT(*) AS total
        FROM seminaristes
        WHERE CAST(annee_seminaire AS CHAR) = :annee
        " . seminaristeStatusSql('valid') . "
    ", [':annee' => (string)SENAFOI_YEAR]);

    $present = dbOne("
        SELECT COUNT(DISTINCT p.{$idCol}) AS total
        FROM {$table} p
        INNER JOIN seminaristes s ON s.id = p.{$idCol}
        WHERE CAST(s.annee_seminaire AS CHAR) = :annee
          AND s.payment_status_wave = 'succeeded'
          AND DATE(p.{$dateCol}) = CURDATE()
    ", [':annee' => (string)SENAFOI_YEAR]);

    $valides = (int)($total['total'] ?? 0);
    $presents = (int)($present['total'] ?? 0);
    $restants = max(0, $valides - $presents);
    return "*Pointage du jour*\n\nPresents: *{$presents}*\nRestants non pointes: *{$restants}*\nTotal valides: *{$valides}*";
}

function updateSeminaristeField(string $matricule, string $field, string $value, string $mode = 'all'): string {
    $map = [
        'nom' => 'nom',
        'prenom' => 'prenom',
        'date_naissance' => 'date_naissance',
        'naissance' => 'date_naissance',
        'ville' => 'secretariat_regional',
        'secretariat' => 'secretariat_regional',
        'contact' => 'contact',
        'contact_parent' => 'contact_parent',
        'parent' => 'contact_parent',
        'maladie' => 'detail_malade',
        'taille' => 'taille_tshirt',
        'tshirt' => 'taille_tshirt',
        'tee_shirt' => 'taille_tshirt',
    ];
    $key = strtolower(trim($field));
    $column = $map[$key] ?? null;
    if (!$column) return "Champ non modifiable. Champs acceptes: nom, prenom, date_naissance, ville, contact, contact_parent, maladie, taille.";
    if (!in_array($column, tableColumns('seminaristes'), true)) {
        return "La colonne {$column} n'existe pas dans seminaristes.";
    }

    $s = getSeminaristeByMatricule($matricule, $mode);
    if (!$s) return "Seminariste introuvable pour {$matricule}.";

    $extra = '';
    if ($column === 'detail_malade') {
        $extra = ", malade = " . (trim($value) === '' || strtolower(trim($value)) === 'non' ? "0" : "1");
    }

    dbExec("
        UPDATE seminaristes
        SET {$column} = :value{$extra}, updated_at = NOW()
        WHERE id = :id
        LIMIT 1
    ", [
        ':value' => trim($value),
        ':id' => (int)$s['id'],
    ]);

    return "Modification effectuee pour *{$matricule}*.\n{$column}: *" . trim($value) . "*";
}

function calcAgeFromDate(string $date): int {
    $ts = strtotime($date);
    if (!$ts) return 0;
    $birth = new DateTime(date('Y-m-d', $ts));
    $today = new DateTime(date('Y-m-d'));
    return (int)$today->diff($birth)->y;
}

function normalizePhoneText(string $value): string {
    $digits = preg_replace('/\D/', '', $value);
    if (strlen($digits) === 10) {
        return substr($digits, 0, 2) . ' ' . substr($digits, 2, 2) . ' ' . substr($digits, 4, 2) . ' ' . substr($digits, 6, 2) . ' ' . substr($digits, 8, 2);
    }
    return trim($value);
}

function registrationPrompt(string $step): string {
    $questions = [
        'participation' => "On lance l'inscription WhatsApp. Le seminariste a-t-il participe au SENAFOI precedent ? Reponds oui ou non.",
        'ancien_search' => "Donne son matricule 2025/2026 ou son nom pour recuperer ses infos.",
        'ancien_confirm' => "Confirme avec oui si c'est le bon profil, sinon donne un autre matricule ou nom.",
        'nom_prenom' => "Donne le nom et le prenom du seminariste.",
        'sexe' => "Sexe ? Reponds M ou F.",
        'date_naissance' => "Date de naissance ? Format recommande: 2004-05-12.",
        'niveau_etude' => "Niveau d'etude ? PRIMAIRE, 6eme, 5eme, 4eme, 3eme, 2nd, 1ere, Terminal ou Universite.",
        'contact' => "Contact du seminariste ?",
        'contact_parent' => "Contact parent/tuteur ?",
        'ville' => "Ville ou secretariat regional ?",
        'malade' => "A-t-il une maladie particuliere ? oui ou non.",
        'maladie_detail' => "Precise la/les maladie(s): Asthme, Drepanocyte, Diabete, Ulcere gastro-duodenal, Colopathie fonctionnelle, Anemie +/-, ou autre.",
        'taille' => "Taille tee-shirt ? XS, S, M, L, XL, XXL ou XXXL.",
        'transport' => "Transport ? Reponds convoi ou personnel.",
        'promo' => "A-t-il un code promo ? Donne le code ou reponds non.",
        'numero_wave' => "Numero Wave de paiement ?",
        'confirm' => "Confirme avec oui pour generer le lien Wave. Pour corriger, ecris par exemple: modifier contact 01 02 03 04 05, ou modifier taille XL. Ecris non pour annuler.",
    ];
    return $questions[$step] ?? "Donne l'information suivante.";
}

function postJson(string $url, array $payload): array {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);
    return ['http' => $code, 'err' => $err, 'raw' => $raw, 'json' => json_decode((string)$raw, true)];
}

function registrationSummary(array $reg): string {
    $d = $reg['data'] ?? [];
    $amount = ($d['transport'] ?? 'convoi') === 'convoi' ? 35000 : 20000;
    if (!empty($d['amount'])) $amount = (int)$d['amount'];
    return "*Resume inscription SENAFOI 26*\n\n"
        . "Nom: *" . ($d['nom'] ?? 'N/A') . "*\n"
        . "Prenom: *" . ($d['prenom'] ?? 'N/A') . "*\n"
        . "Sexe: " . ($d['sexe'] ?? 'N/A') . "\n"
        . "Naissance: " . ($d['dateNaissance'] ?? 'N/A') . "\n"
        . "Niveau etude: " . ($d['niveauEtude'] ?? 'N/A') . "\n"
        . "Ville: " . ($d['ville'] ?? 'N/A') . "\n"
        . "Contact: " . ($d['contact'] ?? 'N/A') . "\n"
        . "Parent: " . ($d['contactParent'] ?? 'N/A') . "\n"
        . "Maladie: " . (($d['aUneMaladie'] ?? 'non') === 'oui' ? ($d['maladieDetail'] ?? 'Oui') : 'Non') . "\n"
        . "Taille: " . ($d['tailleTshirt'] ?? 'N/A') . "\n"
        . "Transport: " . ($d['transport'] ?? 'N/A') . "\n"
        . "Numero Wave: " . ($d['numeroWave'] ?? 'N/A') . "\n"
        . "Montant: *" . fmtMoney($amount) . "*\n\n"
        . registrationPrompt('confirm');
}

function applyRegistrationEdit(array &$reg, string $msg): ?string {
    $text = trim($msg);
    if (!preg_match('/^(modifier|modifie|changer|change)?\s*(nom|prenom|sexe|date_naissance|naissance|niveau|niveau_etude|ville|contact_parent|parent|contact|maladie|taille|tshirt|tee_shirt|transport|numero_wave|wave|promo)\s*[:=]?\s+(.+)$/iu', $text, $m)) {
        return null;
    }

    $field = mb_strtolower($m[2], 'UTF-8');
    $value = trim($m[3]);
    $d = &$reg['data'];

    if ($field === 'nom') $d['nom'] = strtoupper($value);
    elseif ($field === 'prenom') $d['prenom'] = ucfirst(strtolower($value));
    elseif ($field === 'sexe') $d['sexe'] = strtoupper(substr($value, 0, 1));
    elseif ($field === 'date_naissance' || $field === 'naissance') {
        $date = date('Y-m-d', strtotime($value));
        if (!$date || $date === '1970-01-01') return "Date invalide. Exemple: modifier naissance 2004-05-12";
        $d['dateNaissance'] = $date;
        $d['age'] = calcAgeFromDate($date);
    }
    elseif ($field === 'niveau' || $field === 'niveau_etude') $d['niveauEtude'] = $value;
    elseif ($field === 'ville') $d['ville'] = $value;
    elseif ($field === 'contact') $d['contact'] = normalizePhoneText($value);
    elseif ($field === 'contact_parent' || $field === 'parent') $d['contactParent'] = normalizePhoneText($value);
    elseif ($field === 'maladie') {
        if (mb_strtolower($value, 'UTF-8') === 'non') {
            $d['aUneMaladie'] = 'non';
            $d['maladieDetail'] = '';
        } else {
            $d['aUneMaladie'] = 'oui';
            $d['maladieDetail'] = $value;
        }
    }
    elseif ($field === 'taille' || $field === 'tshirt' || $field === 'tee_shirt') $d['tailleTshirt'] = strtoupper($value);
    elseif ($field === 'transport') {
        $d['transport'] = preg_match('/personnel|perso/iu', $value) ? 'personnel' : 'convoi';
        $d['amount'] = $d['transport'] === 'convoi' ? 35000 : 20000;
        unset($d['promoApplique']);
        $d['codePromo'] = '';
    }
    elseif ($field === 'numero_wave' || $field === 'wave') $d['numeroWave'] = normalizePhoneText($value);
    elseif ($field === 'promo') {
        if (mb_strtolower($value, 'UTF-8') === 'non') {
            $d['codePromo'] = '';
            unset($d['promoApplique']);
            $d['amount'] = ($d['transport'] ?? 'convoi') === 'convoi' ? 35000 : 20000;
        } else {
            $code = extractPromoCodeFromText($value);
            if (!$code) return "Code promo invalide. Exemple: modifier promo SENAFOI10";
            $promo = postJson(rtrim(SENAFOI_API_BASE, '/') . '/tarifs_api.php?action=verifier_code_promo', [
                'code' => $code,
                'transport' => ($d['transport'] ?? 'convoi') === 'convoi' ? 1 : 0,
            ]);
            if (empty($promo['json']['success']) || !isset($promo['json']['montant_reduit'])) {
                return "Code promo invalide ou non applicable.";
            }
            $d['codePromo'] = $code;
            $d['amount'] = (int)$promo['json']['montant_reduit'];
            $d['promoApplique'] = $promo['json'];
        }
    }

    $reg['step'] = 'confirm';
    return registrationSummary($reg);
}

function applyRegistrationEdits(array &$reg, string $msg): ?string {
    $parts = preg_split('/[\n;]+/', trim($msg));
    $changed = false;
    $lastError = null;
    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') continue;
        $result = applyRegistrationEdit($reg, $part);
        if ($result === null) {
            $lastError = $lastError ?: null;
            continue;
        }
        if (preg_match('/^(Date invalide|Code promo invalide)/u', $result)) {
            $lastError = $result;
        } else {
            $changed = true;
        }
    }
    if ($lastError && !$changed) return $lastError;
    return $changed ? registrationSummary($reg) : null;
}

function submitWhatsAppRegistration(array &$user): string {
    $reg = $user['wa_registration'] ?? [];
    $d = $reg['data'] ?? [];
    $amount = ($d['transport'] ?? 'convoi') === 'convoi' ? 35000 : 20000;
    if (!empty($d['amount'])) $amount = (int)$d['amount'];

    $payload = [
        'amount' => $amount,
        'currency' => 'XOF',
        'data' => array_merge($d, [
            'secretariatRegional' => $d['ville'] ?? '',
        ]),
    ];

    $result = postJson(rtrim(SENAFOI_API_BASE, '/') . '/inscription_seminaire.php', $payload);
    logIt('WA_REGISTRATION_SUBMIT', ['http' => $result['http'], 'err' => $result['err'], 'preview' => substr((string)$result['raw'], 0, 500)]);
    $json = $result['json'] ?? [];

    if (!empty($json['success']) && !empty($json['wave_launch_url'])) {
        $matricule = $json['matricule'] ?? '';
        $receiptUrl = $matricule ? "https://www.aeemci-ce.ci/seminaire_paye/{$matricule}" : '';
        $user['last_wave_registration'] = [
            'matricule' => $matricule,
            'wave_launch_url' => $json['wave_launch_url'],
            'receipt_url' => $receiptUrl,
            'amount' => $amount,
            'created_at' => date('Y-m-d H:i:s'),
        ];
        unset($user['wa_registration']);

        return "Inscription creee avec succes.\n"
            . "Matricule: *" . ($matricule ?: 'N/A') . "*\n"
            . "Montant: *" . fmtMoney($amount) . "*\n\n"
            . "Lien Wave:\n" . $json['wave_launch_url'] . "\n\n"
            . "Apres paiement, reponds simplement *j'ai paye* pour que je verifie.";
    }

    unset($user['wa_registration']);
    return "L'inscription n'a pas pu generer le lien Wave.\nErreur: " . ($json['message'] ?? $json['error'] ?? $result['err'] ?? 'reponse API invalide');
}

function receiptUrlForMatricule(string $matricule): string {
    return "https://www.aeemci-ce.ci/seminaire_paye/" . strtoupper(trim($matricule));
}

function formatReceiptReply(string $matricule, ?array $seminariste = null): string {
    $matricule = strtoupper(trim($matricule));
    $url = receiptUrlForMatricule($matricule);
    $name = '';
    if ($seminariste) {
        $name = cleanText(($seminariste['prenom'] ?? '') . ' ' . ($seminariste['nom'] ?? ''));
    }
    return ($name ? "Voici le recu de *{$name}*.\n" : "Voici le recu.\n")
        . "Matricule: *{$matricule}*\n\n"
        . "Lien d'impression:\n{$url}";
}

function isPaymentValidated(?array $s): bool {
    if (!$s) return false;
    return (($s['payment_status_wave'] ?? '') === 'succeeded')
        || (($s['statut_paiement'] ?? '') === 'PAYE')
        || (($s['statut_inscription'] ?? '') === 'VALIDEE');
}

function verifyLastWaveRegistration(array &$user): ?string {
    $last = $user['last_wave_registration'] ?? null;
    if (!$last || empty($last['matricule'])) return null;

    $matricule = $last['matricule'];
    $result = postJson(rtrim(SENAFOI_API_BASE, '/') . '/update_paiement.php', [
        'matricule' => $matricule,
    ]);
    logIt('WA_PAYMENT_VERIFY', ['matricule' => $matricule, 'http' => $result['http'], 'err' => $result['err'], 'preview' => substr((string)$result['raw'], 0, 500)]);
    $json = $result['json'] ?? [];

    if (!empty($json['success'])) {
        $s = $json['seminariste'] ?? getSeminaristeByMatricule($matricule, 'all');
        $receiptUrl = $last['receipt_url'] ?? receiptUrlForMatricule($matricule);
        $user['last_wave_registration']['verified_at'] = date('Y-m-d H:i:s');
        return "Paiement confirme.\n\n"
            . formatSeminariste($s) . "\n\n"
            . "Recu / fiche a imprimer:\n" . $receiptUrl;
    }

    $s = getSeminaristeByMatricule($matricule, 'all');
    if (isPaymentValidated($s)) {
        $receiptUrl = $last['receipt_url'] ?? receiptUrlForMatricule($matricule);
        $user['last_wave_registration']['verified_at'] = date('Y-m-d H:i:s');
        return "Paiement confirme.\n\n"
            . formatSeminariste($s) . "\n\n"
            . "Recu / fiche a imprimer:\n" . $receiptUrl;
    }

    return "Je n'ai pas encore pu confirmer le paiement pour *{$matricule}*.\n"
        . "Statut: " . ($json['message'] ?? $json['error'] ?? 'paiement non confirme') . "\n"
        . "Si tu viens juste de payer, attends quelques secondes puis redis *j'ai paye*.\n\n"
        . "Lien Wave si besoin:\n" . ($last['wave_launch_url'] ?? 'N/A');
}

function handleRegistrationFlow(string $msg, array &$user): ?string {
    $text = mb_strtolower(trim($msg), 'UTF-8');
    if (in_array($text, ['annuler', 'stop', 'cancel'], true)) {
        unset($user['wa_registration']);
        return "Inscription WhatsApp annulee.";
    }

    if (preg_match('/(pay|paiement\s+fait|c.?est\s+fait|cest\s+fait)/u', $text)) {
        if (preg_match('/\b(sem20\d+|sem\s*20\d+)\b/iu', $msg, $m)) {
            $matriculePaiement = strtoupper(preg_replace('/\s+/', '', $m[1]));
            $user['last_wave_registration'] = [
                'matricule' => $matriculePaiement,
                'receipt_url' => "https://www.aeemci-ce.ci/seminaire_paye/{$matriculePaiement}",
            ];
        }
        $verified = verifyLastWaveRegistration($user);
        if ($verified !== null) return $verified;
    }

    if (empty($user['wa_registration'])) {
        if (!preg_match('/\b(inscrire|inscription whatsapp|nouvelle inscription)\b/u', $text)) {
            return null;
        }
        $user['wa_registration'] = ['step' => 'participation', 'data' => [
            'transport' => 'convoi',
            'niveauSeminaire' => 'TEST_ENTREE',
            'a_participe_senafoi' => false,
        ]];
        return registrationPrompt('participation');
    }

    $reg = &$user['wa_registration'];
    $step = $reg['step'] ?? 'participation';
    $d = &$reg['data'];

    if ($step === 'participation') {
        if (preg_match('/^(oui|yes|y)$/u', $text)) {
            $d['a_participe_senafoi'] = true;
            $reg['step'] = 'ancien_search';
            return registrationPrompt('ancien_search');
        }
        $d['a_participe_senafoi'] = false;
        $reg['step'] = 'nom_prenom';
        return registrationPrompt('nom_prenom');
    }

    if ($step === 'ancien_search') {
        $candidate = null;
        if (preg_match('/\b(sem20\d+|sem\s*20\d+)\b/iu', $msg, $m)) {
            $candidate = getSeminaristeByMatricule(strtoupper(preg_replace('/\s+/', '', $m[1])), 'all');
        } else {
            $res = searchSeminaristes($msg, 1, 'all');
            $id = $res['rows'][0]['id'] ?? null;
            if ($id && (int)$res['total'] === 1) $candidate = getSeminaristeById((int)$id);
            if (!$candidate && (int)$res['total'] > 1) return formatSearchResults($res) . "\n\nDonne le matricule ou un nom plus precis.";
        }
        if (!$candidate) return "Profil precedent introuvable. Donne un matricule ou un nom plus precis.";
        $reg['ancien_candidate'] = $candidate;
        $reg['step'] = 'ancien_confirm';
        return formatSeminariste($candidate) . "\n\n" . registrationPrompt('ancien_confirm');
    }

    if ($step === 'ancien_confirm') {
        if (!preg_match('/^(oui|yes|y)$/u', $text)) {
            $reg['step'] = 'ancien_search';
            return registrationPrompt('ancien_search');
        }
        $p = $reg['ancien_candidate'] ?? [];
        $d['nom'] = $p['nom'] ?? '';
        $d['prenom'] = $p['prenom'] ?? '';
        $d['sexe'] = $p['sexe'] ?? '';
        $d['contact'] = $p['contact'] ?? '';
        $d['contactParent'] = $p['contact_parent'] ?? '';
        $d['ville'] = $p['secretariat_regional'] ?? '';
        $d['niveauEtude'] = $p['niveau_etude'] ?? '';
        $d['tailleTshirt'] = $p['taille_tshirt'] ?? '';
        $d['numeroWave'] = $p['numero_wave'] ?? '';
        $d['niveau_annee_passee'] = $p['niveau_actuel'] ?? $p['niveau_seminaire'] ?? '';
        $d['participant_trouve'] = true;
        $d['decision_participant'] = $p['decision_participant'] ?? '';
        $d['niveauSeminaire'] = $p['niveau_prochain'] ?? $p['niveau_seminaire'] ?? 'TEST_ENTREE';
        $reg['step'] = empty($d['dateNaissance']) ? 'date_naissance' : 'transport';
        return registrationPrompt($reg['step']);
    }

    if ($step === 'nom_prenom') {
        $parts = preg_split('/\s+/', trim($msg), 2);
        if (count($parts) < 2) return "Donne le nom et le prenom, exemple: KONE Aminata.";
        $d['nom'] = strtoupper($parts[0]);
        $d['prenom'] = ucfirst(strtolower($parts[1]));
        $reg['step'] = 'sexe';
        return registrationPrompt('sexe');
    }
    if ($step === 'sexe') {
        $sexe = strtoupper(substr(trim($msg), 0, 1));
        if (!in_array($sexe, ['M', 'F'], true)) return registrationPrompt('sexe');
        $d['sexe'] = $sexe;
        $reg['step'] = 'date_naissance';
        return registrationPrompt('date_naissance');
    }
    if ($step === 'date_naissance') {
        $date = date('Y-m-d', strtotime($msg));
        if (!$date || $date === '1970-01-01') return registrationPrompt('date_naissance');
        $d['dateNaissance'] = $date;
        $d['age'] = calcAgeFromDate($date);
        $reg['step'] = empty($d['niveauEtude']) ? 'niveau_etude' : 'contact';
        return registrationPrompt($reg['step']);
    }
    if ($step === 'niveau_etude') {
        $d['niveauEtude'] = trim($msg);
        $reg['step'] = empty($d['contact']) ? 'contact' : 'contact_parent';
        return registrationPrompt($reg['step']);
    }
    if ($step === 'contact') {
        $d['contact'] = normalizePhoneText($msg);
        $reg['step'] = empty($d['contactParent']) ? 'contact_parent' : 'ville';
        return registrationPrompt($reg['step']);
    }
    if ($step === 'contact_parent') {
        $d['contactParent'] = normalizePhoneText($msg);
        $reg['step'] = empty($d['ville']) ? 'ville' : 'malade';
        return registrationPrompt($reg['step']);
    }
    if ($step === 'ville') {
        $d['ville'] = trim($msg);
        $reg['step'] = 'malade';
        return registrationPrompt('malade');
    }
    if ($step === 'malade') {
        if (preg_match('/^(oui|yes|y)$/u', $text)) {
            $d['aUneMaladie'] = 'oui';
            $reg['step'] = 'maladie_detail';
            return registrationPrompt('maladie_detail');
        }
        $d['aUneMaladie'] = 'non';
        $d['maladieDetail'] = '';
        $reg['step'] = empty($d['tailleTshirt']) ? 'taille' : 'transport';
        return registrationPrompt($reg['step']);
    }
    if ($step === 'maladie_detail') {
        $d['maladieDetail'] = trim($msg);
        $reg['step'] = empty($d['tailleTshirt']) ? 'taille' : 'transport';
        return registrationPrompt($reg['step']);
    }
    if ($step === 'taille') {
        $d['tailleTshirt'] = strtoupper(trim($msg));
        $reg['step'] = 'transport';
        return registrationPrompt('transport');
    }
    if ($step === 'transport') {
        $d['transport'] = preg_match('/personnel|perso/u', $text) ? 'personnel' : 'convoi';
        $d['amount'] = $d['transport'] === 'convoi' ? 35000 : 20000;
        $reg['step'] = 'promo';
        return registrationPrompt('promo');
    }
    if ($step === 'promo') {
        $code = extractPromoCodeFromText($msg);
        $d['codePromo'] = ($text === 'non' || !$code) ? '' : $code;
        if ($d['codePromo'] !== '') {
            $promo = postJson(rtrim(SENAFOI_API_BASE, '/') . '/tarifs_api.php?action=verifier_code_promo', [
                'code' => $d['codePromo'],
                'transport' => ($d['transport'] ?? 'convoi') === 'convoi' ? 1 : 0,
            ]);
            if (!empty($promo['json']['success']) && isset($promo['json']['montant_reduit'])) {
                $d['amount'] = (int)$promo['json']['montant_reduit'];
                $d['promoApplique'] = $promo['json'];
            } else {
                return "Code promo invalide ou non applicable. Donne un autre code ou reponds non.";
            }
        }
        $reg['step'] = empty($d['numeroWave']) ? 'numero_wave' : 'confirm';
        return $reg['step'] === 'confirm' ? registrationSummary($reg) : registrationPrompt($reg['step']);
    }
    if ($step === 'numero_wave') {
        $d['numeroWave'] = normalizePhoneText($msg);
        $reg['step'] = 'confirm';
        return registrationSummary($reg);
    }
    if ($step === 'confirm') {
        $edited = applyRegistrationEdits($reg, $msg);
        if ($edited !== null) return $edited;

        if (!preg_match('/^(oui|yes|y|confirme|valider|ok)$/u', $text)) {
            return "Je n'ai pas genere le lien. Confirme avec *oui*, corrige avec *modifier champ valeur*, ou ecris *annuler*.";
        }
        return submitWhatsAppRegistration($user);
    }

    unset($user['wa_registration']);
    return null;
}

function promoTableName(): ?string {
    static $table = null;
    if ($table !== null) return $table;

    $candidates = [
        'seminaire_codes_promo',
        'codes_promo',
        'seminaire_promo_codes',
        'codes_promos',
    ];

    foreach ($candidates as $candidate) {
        $row = dbOne("
            SELECT TABLE_NAME
            FROM INFORMATION_SCHEMA.TABLES
            WHERE TABLE_SCHEMA = DATABASE()
              AND TABLE_NAME = :table
            LIMIT 1
        ", [':table' => $candidate]);
        if ($row) {
            $table = $candidate;
            return $table;
        }
    }
    return null;
}

function handleDirectIntent(string $msg, array &$user): ?string {
    $text = mb_strtolower(trim($msg), 'UTF-8');

    if ($text === '') return null;

    if (preg_match('/\b(recu|reçu|fiche|imprimer|impression|telecharger|télécharger)\b/u', $text)) {
        $matricule = null;
        if (preg_match('/\b(sem20\d+|sem\s*20\d+)\b/iu', $msg, $m)) {
            $matricule = strtoupper(preg_replace('/\s+/', '', $m[1]));
        } elseif (!empty($user['last_wave_registration']['matricule'])) {
            $matricule = $user['last_wave_registration']['matricule'];
        }

        if (!$matricule) {
            return "Oui, je peux te donner le recu. Envoie-moi juste le matricule, par exemple *recu SEM2026117*.";
        }

        $s = getSeminaristeByMatricule($matricule, 'all');
        if ($s && !empty($user['last_wave_registration'])) {
            $user['last_wave_registration']['matricule'] = $matricule;
            $user['last_wave_registration']['receipt_url'] = receiptUrlForMatricule($matricule);
        }
        return formatReceiptReply($matricule, $s);
    }

    if (preg_match('/^(salut|bonjour|bonsoir|hello|salam)\b/u', $text)) {
        return null;
    }

    if (preg_match('/\b(diagnostic|test base|quelle base|base de donn)/u', $text)) {
        return formatDbDiagnostic();
    }

    if (preg_match('/\b(code promo|codes promo|promo|promos)\b/u', $text) && !preg_match('/\b(paye|utilis|liste seminariste|seminariste)\b/u', $text)) {
        return listPromoCodes();
    }

    if (preg_match('/\b(quota|place restante|places restantes|places disponible|places disponibles)\b/u', $text)) {
        return formatQuota(getQuotaInfo());
    }

    if (preg_match('/\b(pointage|pointe|present|non pointe)\b/u', $text)) {
        return pointageStatsToday();
    }

    if (preg_match('/\b(dernier|derniere|5 derniers|cinq derniers)\b/u', $text) && preg_match('/\b(inscrit|inscrits|seminariste)\b/u', $text)) {
        $limit = preg_match('/\b(dernier|derniere)\b/u', $text) && !preg_match('/\b(5|cinq)\b/u', $text) ? 1 : 5;
        $sexe = null;
        if (preg_match('/\b(feminin|femme|filles?)\b/u', $text)) $sexe = 'F';
        if (preg_match('/\b(masculin|homme|garcons?)\b/u', $text)) $sexe = 'M';
        $mode = detectSearchMode($msg);
        return formatSeminaristeList(
            listRecentSeminaristes($limit, $sexe, $mode),
            ($limit === 1 ? 'Dernier seminariste inscrit' : "{$limit} derniers seminaristes inscrits") . ' - ' . searchModeLabel($mode)
        );
    }

    if (preg_match('/\b(notes?|evaluation|moyenne|conduite)\b/u', $text)) {
        if (preg_match('/\b(sem20\d+|sem\s*20\d+)\b/iu', $msg, $m)) {
            return getSeminaristeNotes(strtoupper(preg_replace('/\s+/', '', $m[1])), detectSearchMode($msg));
        }
        if (preg_match('/(?:de|pour)\s+(.+)$/u', $msg, $m)) {
            return getSeminaristeNotes(trim($m[1]), detectSearchMode($msg));
        }
    }

    if (preg_match('/\b(modifier|modifie|changer|change)\b/u', $text)
        && preg_match('/\b(sem20\d+|sem\s*20\d+)\b/iu', $msg, $mat)
        && preg_match('/\b(nom|prenom|date_naissance|naissance|ville|secretariat|contact_parent|parent|contact|maladie|taille|tshirt|tee_shirt)\b\s*[:=]?\s*(.+)$/iu', $msg, $m)) {
        return updateSeminaristeField(strtoupper(preg_replace('/\s+/', '', $mat[1])), $m[1], $m[2], 'all');
    }

    if (preg_match('/\b(code promo|promo)\b/u', $text) && preg_match('/\b(paye|utilis|liste|seminariste)\b/u', $text)) {
        $promoCode = extractPromoCodeFromText($msg);
        if (!$promoCode) return "Donne-moi le code promo a verifier.";
        return listSeminaristesByPromo($promoCode, detectSearchMode($msg));
    }

    if (preg_match('/\b(par niveau|niveau par sexe|stat niveau|stats niveau)\b/u', $text)) {
        return statsByField('niveau_seminaire', 'niveau', detectSearchMode($msg));
    }

    if (preg_match('/\b(ville|secretariat regional|region)\b/u', $text) && preg_match('/\b(nombre|stats?|statistique|combien)\b/u', $text)) {
        return statsByField('secretariat_regional', 'ville/secretariat', detectSearchMode($msg));
    }

    if (preg_match('/\b(dortoir)\b/u', $text) && preg_match('/\b(nombre|stats?|statistique|combien)\b/u', $text)) {
        return statsByField('dortoir', 'dortoir', detectSearchMode($msg));
    }

    if (preg_match('/\b(niveau)\s+([0-9A-Z ]{1,12})/iu', $msg, $m) && preg_match('/\b(nombre|stats?|combien)\b/u', $text)) {
        return statsForLevel(trim($m[2]), detectSearchMode($msg));
    }

    if (preg_match('/\b(suivant|suite|liste suivante|page suivante)\b/u', $text)) {
        if (empty($user['last_search']['query'])) {
            return "Je n'ai pas de recherche en cours. Donne-moi un nom à chercher.";
        }
        $q = $user['last_search']['query'];
        $page = ((int)($user['last_search']['page'] ?? 1)) + 1;
        $mode = $user['last_search']['mode'] ?? 'valid';
        $res = searchSeminaristes($q, $page, $mode);
        rememberSearch($user, $res, $mode);
        return formatSearchResults($res);
    }

    if (preg_match('/^\s*(\d{1,2})\s*$/u', $text, $m) && !empty($user['last_search']['result_ids'])) {
        $index = max(1, (int)$m[1]);
        $id = $user['last_search']['result_ids'][$index - 1] ?? null;
        if (!$id) return "Ce numéro ne correspond pas à la dernière liste.";
        return formatSeminariste(getSeminaristeById((int)$id));
    }

    if (preg_match('/\b(sem20\d+|sem\s*20\d+)\b/iu', $msg, $m)) {
        $matricule = strtoupper(preg_replace('/\s+/', '', $m[1]));
        $mode = detectSearchMode($msg);
        return formatSeminariste(getSeminaristeByMatricule($matricule, $mode));
    }

    if (preg_match('/\b(stat|stats|statistique|statistiques|inscrit|inscrits|inscription|inscriptions|dashboard)\b/u', $text)) {
        if (preg_match('/\b(mois|mensuel|mensuelle)\b/u', $text)) {
            return formatDashboardStats(getDashboardStats('month'));
        }
        if (preg_match('/\b(tout|toute|total|global|général|general)\b/u', $text)) {
            return formatDashboardStats(getDashboardStats('all'));
        }
        if (preg_match('/(\d{4}-\d{2}-\d{2})/u', $text, $m)) {
            return formatDashboardStats(getDashboardStats('date', $m[1]));
        }
        if (preg_match('/(\d{1,2})[\/.-](\d{1,2})[\/.-](\d{2,4})/u', $text, $m)) {
            $year = strlen($m[3]) === 2 ? '20' . $m[3] : $m[3];
            $date = sprintf('%04d-%02d-%02d', (int)$year, (int)$m[2], (int)$m[1]);
            return formatDashboardStats(getDashboardStats('date', $date));
        }
        return formatDashboardStats(getDashboardStats('all'));
    }

    if (preg_match('/^(cherche|recherche|trouve|nom)\s+(.+)$/u', $text, $m)) {
        $q = trim($m[2]);
        if ($q !== '') {
            $mode = detectSearchMode($msg);
            $res = searchSeminaristes($q, 1, $mode);
            rememberSearch($user, $res, $mode);
            return formatSearchResults($res);
        }
    }

    return null;
}

function listPromoCodes(): string {
    $table = promoTableName();
    if (!$table) return "Je n'ai pas trouvé la table des codes promo. Vérifie le nom de la table côté API paiement.";

    $rows = dbAll("
        SELECT *
        FROM {$table}
        ORDER BY actif DESC, id DESC
        LIMIT 20
    ");

    if (!$rows) return "Aucun code promo trouvé.";

    $out = "🏷️ *Codes promo récents*\n\n";
    foreach ($rows as $c) {
        $active = !empty($c['actif']) ? 'actif' : 'inactif';
        $code = $c['code'] ?? 'N/A';
        $reduction = $c['reduction'] ?? ($c['reduction_pct'] ?? 0);
        $usage = ($c['usage_count'] ?? 0) . '/' . (($c['usage_max'] ?? 0) ?: '∞');
        $out .= "- *{$code}* · {$reduction}% · {$active} · usage {$usage}\n";
    }
    return trim($out);
}

function createPromoCode(array $act): string {
    $table = promoTableName();
    if (!$table) return "Impossible de créer le code: table promo introuvable.";

    $code = strtoupper(preg_replace('/[^A-Z0-9_-]/i', '', $act['code'] ?? ''));
    $reduction = max(0, min(100, (float)($act['reduction'] ?? 0)));
    $usageMax = max(0, (int)($act['usage_max'] ?? 0));
    $transport = $act['transport'] ?? null;
    if ($transport === 'null' || $transport === '') $transport = null;
    if ($transport !== null) $transport = (int)$transport;
    $dateDebut = !empty($act['date_debut']) ? $act['date_debut'] : null;
    $dateFin = !empty($act['date_fin']) ? $act['date_fin'] : null;

    if ($code === '' || $reduction <= 0) {
        return "Il me faut au moins un code et une réduction supérieure à 0.";
    }

    $ok = dbExec("
        INSERT INTO {$table}
            (code, reduction, transport, usage_max, actif, date_debut, date_fin, created_at)
        VALUES
            (:code, :reduction, :transport, :usage_max, 1, :date_debut, :date_fin, NOW())
    ", [
        ':code' => $code,
        ':reduction' => $reduction,
        ':transport' => $transport,
        ':usage_max' => $usageMax,
        ':date_debut' => $dateDebut,
        ':date_fin' => $dateFin,
    ]);

    if (!$ok) return "Je n'ai pas réussi à créer le code promo. Vérifie les colonnes de la table {$table}.";

    return "✅ Code promo créé: *{$code}*\nRéduction: *{$reduction}%*\nUsage max: *" . ($usageMax ?: 'illimité') . "*";
}

// -----------------------------------------------------------------------------
// Claude
// -----------------------------------------------------------------------------

function systemPrompt(array $user): string {
    $ctx = '';
    if (!empty($user['last_search'])) {
        $ctx .= "\n• Dernière recherche: " . json_encode($user['last_search'], JSON_UNESCAPED_UNICODE);
    }
    if (!empty($user['pending_create_promo'])) {
        $ctx .= "\n• Code promo en attente de confirmation: " . json_encode($user['pending_create_promo'], JSON_UNESCAPED_UNICODE);
    }
    if ($ctx === '') $ctx = "\n• Aucun contexte actif.";

    return <<<PROMPT
Tu es l'assistant WhatsApp admin du SENAFOI 2026 de l'AEEMCI.
Tu réponds en français, de façon courte, claire et utile pour WhatsApp.
Tu peux consulter les données et exécuter certaines tâches administratives.

Contexte utilisateur actuel:{$ctx}

Actions disponibles. Tu dois ajouter UNE action JSON à la fin entre <ACT> et </ACT> quand une action est nécessaire.

Stats:
<ACT>{"a":"stats","period":"today"}</ACT>
<ACT>{"a":"stats","period":"month"}</ACT>
<ACT>{"a":"stats","period":"date","date":"2026-08-03"}</ACT>
period possibles: today, month, date, all.

Quota:
<ACT>{"a":"quota"}</ACT>

Recherche par matricule:
<ACT>{"a":"seminariste_matricule","matricule":"SEM2026837"}</ACT>

Recherche par nom/prénom:
<ACT>{"a":"search","q":"BALDE","page":1}</ACT>
Si l'utilisateur dit suivant, page suivante, liste suivante:
<ACT>{"a":"next_search"}</ACT>
Si l'utilisateur choisit un numéro dans la dernière liste:
<ACT>{"a":"select_result","index":1}</ACT>

Codes promo:
<ACT>{"a":"list_promo"}</ACT>
Pour créer un code promo, demander confirmation claire avant d'agir.
Quand c'est confirmé:
<ACT>{"a":"create_promo","code":"SENAFOI10","reduction":10,"transport":null,"usage_max":50,"date_debut":null,"date_fin":null}</ACT>

Regle de validation:
- Par defaut, recherche/liste/notes/stats nominatives = seulement payment_status_wave succeeded.
- Si l'admin precise non valide, non paye, en attente ou paiement non confirme, utilise "mode":"invalid".
- Si l'admin demande tous les statuts, utilise "mode":"all".

Derniers inscrits:
<ACT>{"a":"recent","limit":1,"mode":"valid"}</ACT>
<ACT>{"a":"recent","limit":5,"sexe":"F","mode":"valid"}</ACT>
<ACT>{"a":"recent","limit":5,"sexe":"M","mode":"valid"}</ACT>

Stats detaillees:
<ACT>{"a":"stats_field","field":"niveau_seminaire","mode":"valid"}</ACT>
<ACT>{"a":"stats_field","field":"secretariat_regional","mode":"valid"}</ACT>
<ACT>{"a":"stats_field","field":"dortoir","mode":"valid"}</ACT>
<ACT>{"a":"stats_level","niveau":"NIVEAU 3AF","mode":"valid"}</ACT>

Notes/evaluation:
<ACT>{"a":"notes","matricule":"SEM2026837","mode":"valid"}</ACT>
<ACT>{"a":"notes","q":"BALDE","mode":"valid"}</ACT>

Code promo utilise:
<ACT>{"a":"promo_users","code":"SENAFOI10","mode":"valid"}</ACT>

Pointage du jour:
<ACT>{"a":"pointage"}</ACT>

Modifier un seminariste:
<ACT>{"a":"update_seminariste","matricule":"SEM2026837","field":"contact","value":"01 02 03 04 05","mode":"all"}</ACT>
Champs modifiables: nom, prenom, date_naissance, ville, contact, contact_parent, maladie, taille.

Inscription WhatsApp:
- Si l'admin veut inscrire un seminariste, ne declenche pas d'action JSON.
- Reponds simplement qu'il peut dire "inscrire un seminariste"; le systeme collectera les informations question par question.

Diagnostic technique si l'admin dit "diagnostic", "test base", "quelle base":
<ACT>{"a":"diagnostic"}</ACT>

Règles:
- Ne demande jamais de mot de passe, PIN, clé API ou secret.
- Pour les actions sensibles comme create_promo, demande toujours confirmation avant.
- Si la demande est ambiguë, pose une question courte.
- Si tu déclenches une action, ton texte peut rester vide: le système répondra avec le résultat.
- Ne fais pas de longs pavés.
PROMPT;
}

function askClaude(array $history, array $user): array {
    if (CLAUDE_API_KEY === 'A_CONFIGURER') {
        return ['text' => "La clé Anthropic n'est pas encore configurée sur le webhook.", 'act' => null];
    }

    if (count($history) > MAX_HIST) {
        $history = array_slice($history, -MAX_HIST);
    }

    $body = json_encode([
        'model' => CLAUDE_MODEL,
        'max_tokens' => 700,
        'system' => systemPrompt($user),
        'messages' => $history,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

    $ch = curl_init('https://api.anthropic.com/v1/messages');
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 25,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'x-api-key: ' . CLAUDE_API_KEY,
            'anthropic-version: 2023-06-01',
        ],
        CURLOPT_POSTFIELDS => $body,
    ]);

    $raw = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    curl_close($ch);

    logIt('CLAUDE', ['http' => $code, 'err' => $err, 'preview' => substr((string)$raw, 0, 500)]);

    if ($err || $code < 200 || $code >= 300) {
        return ['text' => "Petit souci avec l'IA pour l'instant. Réessaie dans quelques secondes.", 'act' => null];
    }

    $data = json_decode($raw, true);
    $full = $data['content'][0]['text'] ?? '';
    $act = null;

    if (preg_match('/<ACT>(.*?)<\/ACT>/s', $full, $m)) {
        $act = json_decode(trim($m[1]), true);
        $full = trim(preg_replace('/<ACT>.*?<\/ACT>/s', '', $full));
    }

    return ['text' => $full, 'act' => $act];
}

// -----------------------------------------------------------------------------
// Actions
// -----------------------------------------------------------------------------

function dispatchAction(array $act, array &$user): ?string {
    switch ($act['a'] ?? '') {
        case 'stats':
            return formatDashboardStats(getDashboardStats($act['period'] ?? 'today', $act['date'] ?? null));

        case 'quota':
            return formatQuota(getQuotaInfo());

        case 'seminariste_matricule':
            return formatSeminariste(getSeminaristeByMatricule($act['matricule'] ?? '', $act['mode'] ?? 'valid'));

        case 'search': {
            $q = trim($act['q'] ?? '');
            if ($q === '') return "Donne-moi le nom ou prénom à rechercher.";
            $page = max(1, (int)($act['page'] ?? 1));
            $mode = $act['mode'] ?? 'valid';
            $res = searchSeminaristes($q, $page, $mode);
            rememberSearch($user, $res, $mode);
            return formatSearchResults($res);
        }

        case 'next_search': {
            if (empty($user['last_search']['query'])) return "Je n'ai pas de recherche en cours. Donne-moi un nom à chercher.";
            $q = $user['last_search']['query'];
            $page = ((int)($user['last_search']['page'] ?? 1)) + 1;
            $mode = $user['last_search']['mode'] ?? 'valid';
            $res = searchSeminaristes($q, $page, $mode);
            rememberSearch($user, $res, $mode);
            return formatSearchResults($res);
        }

        case 'select_result': {
            $index = max(1, (int)($act['index'] ?? 0));
            $ids = $user['last_search']['result_ids'] ?? [];
            $id = $ids[$index - 1] ?? null;
            if (!$id) return "Ce numéro ne correspond pas à la dernière liste. Réessaie avec un numéro affiché.";
            return formatSeminariste(getSeminaristeById((int)$id));
        }

        case 'list_promo':
            return listPromoCodes();

        case 'create_promo':
            return createPromoCode($act);

        case 'diagnostic':
            return formatDbDiagnostic();

        case 'recent':
            $limit = max(1, min(20, (int)($act['limit'] ?? 5)));
            $sexe = !empty($act['sexe']) ? strtoupper($act['sexe']) : null;
            $mode = $act['mode'] ?? 'valid';
            return formatSeminaristeList(
                listRecentSeminaristes($limit, $sexe, $mode),
                "{$limit} derniers seminaristes inscrits - " . searchModeLabel($mode)
            );

        case 'notes':
            return getSeminaristeNotes($act['q'] ?? ($act['matricule'] ?? ''), $act['mode'] ?? 'valid');

        case 'stats_field':
            $field = $act['field'] ?? 'niveau_seminaire';
            $labels = ['niveau_seminaire' => 'niveau', 'secretariat_regional' => 'ville/secretariat', 'dortoir' => 'dortoir'];
            return statsByField($field, $labels[$field] ?? $field, $act['mode'] ?? 'valid');

        case 'stats_level':
            return statsForLevel($act['niveau'] ?? '', $act['mode'] ?? 'valid');

        case 'promo_users':
            return listSeminaristesByPromo($act['code'] ?? '', $act['mode'] ?? 'valid');

        case 'pointage':
            return pointageStatsToday();

        case 'update_seminariste':
            return updateSeminaristeField($act['matricule'] ?? '', $act['field'] ?? '', $act['value'] ?? '', $act['mode'] ?? 'all');
    }

    return null;
}

// -----------------------------------------------------------------------------
// Point d'entree Twilio WhatsApp
// -----------------------------------------------------------------------------

$post = $_POST;
$msgIn = trim($post['Body'] ?? '');
$from = $post['From'] ?? 'unknown';

logIt('IN', ['from' => $from, 'msg' => $msgIn]);

if (!isAllowedAdmin($from)) {
    logIt('BLOCKED', ['from' => $from]);
    twiRespond("Accès réservé à l'équipe admin SENAFOI.");
}

$states = loadStates();
if (!isset($states[$from])) {
    $states[$from] = ['history' => []];
}

$user = &$states[$from];
if (!isset($user['history'])) $user['history'] = [];

if ($msgIn === '') {
    twiRespond("Écris-moi ta demande admin SENAFOI: stats, quota, matricule, nom, codes promo...");
}

try {
    $registrationReply = handleRegistrationFlow($msgIn, $user);
    $directReply = $registrationReply ?? handleDirectIntent($msgIn, $user);
    if ($directReply !== null) {
        $replyTxt = $directReply;
    } else {
        $user['history'][] = ['role' => 'user', 'content' => $msgIn];

        $result = askClaude($user['history'], $user);
        $replyTxt = $result['text'] ?? '';
        $act = $result['act'] ?? null;

        if (is_array($act)) {
            $actionReply = dispatchAction($act, $user);
            if ($actionReply !== null && $actionReply !== '') {
                $replyTxt = $actionReply;
            }
        }
    }
} catch (Throwable $e) {
    logIt('ACTION_FATAL', ['msg' => $msgIn, 'error' => $e->getMessage()]);
    $replyTxt = "Erreur pendant l'execution de la demande: " . $e->getMessage();
}

if (trim($replyTxt) === '') {
    $replyTxt = "Je n'ai pas bien compris. Tu peux demander: stats du jour, quota, chercher SEM2026..., chercher un nom, codes promo.";
}

if (empty($user['history']) || end($user['history'])['role'] !== 'user') {
    $user['history'][] = ['role' => 'user', 'content' => $msgIn];
}
$user['history'][] = ['role' => 'assistant', 'content' => $replyTxt];
if (count($user['history']) > MAX_HIST * 2) {
    $user['history'] = array_slice($user['history'], -(MAX_HIST * 2));
}

saveStates($states);
logIt('OUT', ['to' => $from, 'msg' => $replyTxt]);
twiRespond($replyTxt);
