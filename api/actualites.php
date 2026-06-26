<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
date_default_timezone_set('Africa/Abidjan');

header('Content-Type: application/json; charset=UTF-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-Requested-With, Accept');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

define('DB_HOST', getenv('AEEMCI_DB_HOST') ?: 'localhost');
define('DB_NAME', getenv('AEEMCI_DB_NAME') ?: 'capbvkkqah_aeemci');
define('DB_USER', getenv('AEEMCI_DB_USER') ?: 'capbvkkqah_aeemci');
define('DB_PASS', getenv('AEEMCI_DB_PASS') ?: '0Objectif-');

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
    db()->exec("CREATE TABLE IF NOT EXISTS aeemci_actualites (
      id INT UNSIGNED NOT NULL AUTO_INCREMENT,
      titre VARCHAR(255) NOT NULL,
      slug VARCHAR(280) DEFAULT NULL,
      type VARCHAR(60) NOT NULL DEFAULT 'AUTRE',
      lieu VARCHAR(180) DEFAULT NULL,
      texte_affichage TEXT DEFAULT NULL,
      texte_detaille MEDIUMTEXT DEFAULT NULL,
      photos JSON DEFAULT NULL,
      statut VARCHAR(30) NOT NULL DEFAULT 'BROUILLON',
      date_debut DATE DEFAULT NULL,
      date_fin DATE DEFAULT NULL,
      date_specifique DATE DEFAULT NULL,
      auteur VARCHAR(160) DEFAULT NULL,
      created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
      updated_at DATETIME NULL DEFAULT NULL ON UPDATE CURRENT_TIMESTAMP,
      published_at DATETIME DEFAULT NULL,
      PRIMARY KEY (id),
      UNIQUE KEY uq_aeemci_actualites_slug (slug),
      KEY idx_aeemci_actualites_statut_date (statut, date_specifique, created_at),
      KEY idx_aeemci_actualites_type (type)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

function inputJson(): array {
    $raw = file_get_contents('php://input');
    $data = json_decode($raw ?: '{}', true);
    return is_array($data) ? $data : [];
}

function slugify(string $text): string {
    $text = iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $text) ?: $text;
    $text = strtolower(preg_replace('/[^a-zA-Z0-9]+/', '-', $text));
    $text = trim($text, '-');
    return $text ?: 'actualite';
}

function uniqueSlug(string $title, ?int $ignoreId = null): string {
    $base = slugify($title);
    $slug = $base;
    $i = 2;
    while (true) {
        $sql = 'SELECT id FROM aeemci_actualites WHERE slug = ?';
        $params = [$slug];
        if ($ignoreId) {
            $sql .= ' AND id <> ?';
            $params[] = $ignoreId;
        }
        $stmt = db()->prepare($sql . ' LIMIT 1');
        $stmt->execute($params);
        if (!$stmt->fetch()) return $slug;
        $slug = $base . '-' . $i++;
    }
}

function normalizeStatus(?string $status): string {
    $s = strtoupper(trim($status ?: 'BROUILLON'));
    $map = ['PUBLIE' => 'PUBLIÉ', 'PUBLIÉE' => 'PUBLIÉ', 'ACTIF' => 'PUBLIÉ', 'ACTIVE' => 'PUBLIÉ'];
    return $map[$s] ?? (in_array($s, ['BROUILLON', 'PUBLIÉ', 'ARCHIVÉ'], true) ? $s : 'BROUILLON');
}

function cleanPhotos($photos): array {
    if (is_string($photos)) {
        $decoded = json_decode($photos, true);
        $photos = is_array($decoded) ? $decoded : ($photos ? [$photos] : []);
    }
    if (!is_array($photos)) return [];
    $out = [];
    foreach ($photos as $photo) {
        if (is_string($photo) && trim($photo) !== '') {
            $url = trim($photo);
            if (preg_match('/^https?:\/\//i', $url)) {
                $out[] = ['url' => $url, 'preview' => $url];
            }
        } elseif (is_array($photo)) {
            $url = trim((string)($photo['url'] ?? $photo['preview'] ?? ''));
            if ($url !== '' && preg_match('/^https?:\/\//i', $url)) {
                $out[] = [
                    'url' => $url,
                    'preview' => $photo['preview'] ?? $url,
                    'filename' => $photo['filename'] ?? null,
                    'alt' => $photo['alt'] ?? null,
                ];
            }
        }
    }
    return array_slice($out, 0, 6);
}

function publicRow(array $row, bool $compact = false): array {
    $photos = cleanPhotos($row['photos'] ?? []);
    $image = '';
    if (!empty($photos[0])) $image = is_array($photos[0]) ? ($photos[0]['url'] ?? $photos[0]['preview'] ?? '') : $photos[0];
    $dateSpecifique = cleanDateValue($row['date_specifique'] ?? null);
    $dateDebut = cleanDateValue($row['date_debut'] ?? null);
    $dateFin = cleanDateValue($row['date_fin'] ?? null);
    $date = $dateSpecifique ?: ($dateDebut ?: $row['created_at']);
    $payload = [
        'id' => (int)$row['id'],
        'titre' => $row['titre'],
        'title' => $row['titre'],
        'slug' => $row['slug'],
        'type' => $row['type'],
        'category' => $row['type'],
        'lieu' => $row['lieu'],
        'location' => $row['lieu'],
        'texte_affichage' => $row['texte_affichage'],
        'excerpt' => $row['texte_affichage'],
        'texte_detaille' => $row['texte_detaille'],
        'content' => $row['texte_detaille'],
        'photos' => $photos,
        'image' => $image,
        'statut' => $row['statut'],
        'date_debut' => $dateDebut,
        'date_fin' => $dateFin,
        'date_specifique' => $dateSpecifique,
        'date' => $date,
        'auteur' => $row['auteur'],
        'author' => $row['auteur'],
        'created_at' => $row['created_at'],
        'updated_at' => $row['updated_at'],
        'published_at' => $row['published_at'],
    ];

    if ($compact) {
        $isRemoteImage = is_string($image) && preg_match('/^https?:\/\//i', $image);
        $payload['texte_detaille'] = '';
        $payload['content'] = '';
        $payload['image'] = $isRemoteImage ? $image : '';
        $payload['photos'] = $isRemoteImage ? [['url' => $image, 'preview' => $image]] : [];
    }

    return $payload;
}

function cleanDateValue($value): ?string {
    $value = trim((string)$value);
    if ($value === '' || str_starts_with($value, '0000-00-00')) return null;
    return substr($value, 0, 10);
}

function requireTitle(array $data): void {
    if (trim((string)($data['titre'] ?? $data['title'] ?? '')) === '') {
        respond(['success' => false, 'message' => 'Le titre est requis.'], 400);
    }
}

function buildPayload(array $data, ?array $existing = null): array {
    $titre = trim((string)($data['titre'] ?? $data['title'] ?? $existing['titre'] ?? ''));
    $statut = normalizeStatus($data['statut'] ?? $existing['statut'] ?? 'BROUILLON');
    return [
        'titre' => $titre,
        'slug' => !empty($data['slug']) ? slugify((string)$data['slug']) : uniqueSlug($titre, $existing ? (int)$existing['id'] : null),
        'type' => trim((string)($data['type'] ?? $existing['type'] ?? 'AUTRE')) ?: 'AUTRE',
        'lieu' => trim((string)($data['lieu'] ?? $data['location'] ?? $existing['lieu'] ?? '')),
        'texte_affichage' => trim((string)($data['texte_affichage'] ?? $data['texteAffichage'] ?? $data['excerpt'] ?? $existing['texte_affichage'] ?? '')),
        'texte_detaille' => (string)($data['texte_detaille'] ?? $data['texteDetaille'] ?? $data['content'] ?? $existing['texte_detaille'] ?? ''),
        'photos' => json_encode(cleanPhotos($data['photos'] ?? $data['gallery'] ?? $existing['photos'] ?? []), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
        'statut' => $statut,
        'date_debut' => ($data['date_debut'] ?? $data['dateDebut'] ?? $existing['date_debut'] ?? null) ?: null,
        'date_fin' => ($data['date_fin'] ?? $data['dateFin'] ?? $existing['date_fin'] ?? null) ?: null,
        'date_specifique' => ($data['date_specifique'] ?? $data['dateSpecifique'] ?? $existing['date_specifique'] ?? null) ?: null,
        'auteur' => trim((string)($data['auteur'] ?? $data['author'] ?? $existing['auteur'] ?? 'AEEMCI')),
        'published_at' => $statut === 'PUBLIÉ' ? ($existing['published_at'] ?? date('Y-m-d H:i:s')) : null,
    ];
}

try {
    ensureTable();
    $action = $_GET['action'] ?? 'latest';

    if ($action === 'latest' || $action === 'list') {
        $publicOnly = $action === 'latest' || !empty($_GET['public']);
        $limit = max(1, min(50, (int)($_GET['limit'] ?? ($action === 'latest' ? 4 : 100))));
        $offset = max(0, (int)($_GET['offset'] ?? 0));
        $where = [];
        $params = [];
        if ($publicOnly) {
            $where[] = "statut = 'PUBLIÉ'";
        } elseif (!empty($_GET['statut']) && $_GET['statut'] !== 'all') {
            $where[] = 'statut = :statut';
            $params[':statut'] = normalizeStatus($_GET['statut']);
        }
        if (!empty($_GET['search'])) {
            $where[] = '(titre LIKE :q_titre OR texte_affichage LIKE :q_resume OR texte_detaille LIKE :q_detail)';
            $like = '%' . $_GET['search'] . '%';
            $params[':q_titre'] = $like;
            $params[':q_resume'] = $like;
            $params[':q_detail'] = $like;
        }
        $sqlWhere = $where ? 'WHERE ' . implode(' AND ', $where) : '';
        $stmt = db()->prepare("SELECT * FROM aeemci_actualites {$sqlWhere} ORDER BY COALESCE(NULLIF(date_specifique, '0000-00-00'), NULLIF(date_debut, '0000-00-00'), DATE(created_at)) DESC, id DESC LIMIT {$limit} OFFSET {$offset}");
        $stmt->execute($params);
        $rows = array_map(fn($row) => publicRow($row, $action === 'latest'), $stmt->fetchAll());
        respond(['success' => true, 'data' => $rows, 'actualites' => $rows]);
    }

    if ($action === 'get' || $action === 'detail') {
        $id = (int)($_GET['id'] ?? 0);
        $slug = trim((string)($_GET['slug'] ?? ''));
        if (!$id && $slug === '') respond(['success' => false, 'message' => 'ID ou slug requis.'], 400);
        $stmt = $id ? db()->prepare('SELECT * FROM aeemci_actualites WHERE id = ? LIMIT 1') : db()->prepare('SELECT * FROM aeemci_actualites WHERE slug = ? LIMIT 1');
        $stmt->execute([$id ?: $slug]);
        $row = $stmt->fetch();
        if (!$row) respond(['success' => false, 'message' => 'Actualité introuvable.'], 404);
        respond(['success' => true, 'data' => publicRow($row), 'actualite' => publicRow($row)]);
    }

    if ($action === 'stats') {
        $row = db()->query("SELECT COUNT(*) total, SUM(statut='PUBLIÉ') publiees, SUM(statut='BROUILLON') brouillons, SUM(statut='ARCHIVÉ') archivees FROM aeemci_actualites")->fetch();
        respond(['success' => true, 'data' => array_map('intval', $row ?: [])]);
    }

    if ($action === 'create') {
        $data = inputJson();
        requireTitle($data);
        $p = buildPayload($data);
        $stmt = db()->prepare("INSERT INTO aeemci_actualites (titre, slug, type, lieu, texte_affichage, texte_detaille, photos, statut, date_debut, date_fin, date_specifique, auteur, published_at, created_at) VALUES (:titre, :slug, :type, :lieu, :texte_affichage, :texte_detaille, :photos, :statut, :date_debut, :date_fin, :date_specifique, :auteur, :published_at, NOW())");
        $stmt->execute($p);
        $id = (int)db()->lastInsertId();
        respond(['success' => true, 'message' => 'Actualité créée avec succès.', 'id' => $id]);
    }

    if ($action === 'update') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) respond(['success' => false, 'message' => 'ID requis.'], 400);
        $stmt = db()->prepare('SELECT * FROM aeemci_actualites WHERE id = ? LIMIT 1');
        $stmt->execute([$id]);
        $existing = $stmt->fetch();
        if (!$existing) respond(['success' => false, 'message' => 'Actualité introuvable.'], 404);
        $p = buildPayload(inputJson(), $existing);
        $p['id'] = $id;
        $stmt = db()->prepare("UPDATE aeemci_actualites SET titre=:titre, slug=:slug, type=:type, lieu=:lieu, texte_affichage=:texte_affichage, texte_detaille=:texte_detaille, photos=:photos, statut=:statut, date_debut=:date_debut, date_fin=:date_fin, date_specifique=:date_specifique, auteur=:auteur, published_at=:published_at, updated_at=NOW() WHERE id=:id");
        $stmt->execute($p);
        respond(['success' => true, 'message' => 'Actualité mise à jour avec succès.']);
    }

    if ($action === 'delete') {
        $id = (int)($_GET['id'] ?? 0);
        if (!$id) respond(['success' => false, 'message' => 'ID requis.'], 400);
        $stmt = db()->prepare('DELETE FROM aeemci_actualites WHERE id = ?');
        $stmt->execute([$id]);
        respond(['success' => true, 'message' => 'Actualité supprimée avec succès.']);
    }

    respond(['success' => false, 'message' => 'Action inconnue.'], 404);
} catch (Throwable $e) {
    respond(['success' => false, 'message' => 'Erreur serveur: ' . $e->getMessage()], 500);
}
