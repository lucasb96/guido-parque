<?php
declare(strict_types=1);

define('ROOT', dirname(__DIR__));

$configFile = __DIR__ . '/config.php';
if (!is_file($configFile)) {
    http_response_code(500);
    exit('Configuração ausente: copie app/config.example.php para app/config.php e preencha.');
}
$CONFIG = require $configFile;
// Recomendado: pasta fora do public_html (ex.: /home/usuario/condominio-dados)
define('STORAGE', rtrim((string) ($CONFIG['storage_path'] ?? '') ?: ROOT . '/storage', '/\\'));
date_default_timezone_set($CONFIG['timezone'] ?? 'America/Sao_Paulo');

require ROOT . '/vendor/autoload.php';
require __DIR__ . '/Text.php';
require __DIR__ . '/Drive.php';
require __DIR__ . '/Extractor.php';
require __DIR__ . '/Sync.php';
require __DIR__ . '/Search.php';
require __DIR__ . '/CuryProgress.php';
require __DIR__ . '/Store.php';
require __DIR__ . '/SiteData.php';

function config(string $key, $default = null)
{
    global $CONFIG;
    return $CONFIG[$key] ?? $default;
}

/* ---------------- Banco de dados (SQLite) ---------------- */
function db(): PDO
{
    static $pdo = null;
    if ($pdo) return $pdo;

    if (!is_dir(STORAGE)) mkdir(STORAGE, 0775, true);
    $file = STORAGE . '/database.sqlite';
    $isNew = !is_file($file);

    $pdo = new PDO('sqlite:' . $file);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
    // WAL + synchronous NORMAL: seguro e muito mais rápido (no Windows cada gravação avulsa levava ~140 ms)
    $pdo->exec('PRAGMA journal_mode = WAL; PRAGMA synchronous = NORMAL; PRAGMA foreign_keys = ON;');
    migrate($pdo);

    // Banco novo: reconstrói a partir dos arquivos content/ e state/ (versionados no Git)
    if ($isNew) Store::import($pdo);
    return $pdo;
}

function migrate(PDO $pdo): void
{
    $pdo->exec(<<<SQL
    CREATE TABLE IF NOT EXISTS docs (
        id            TEXT PRIMARY KEY,          -- id do arquivo no Drive
        name          TEXT NOT NULL,             -- nome do arquivo
        title         TEXT,                      -- título amigável (editável)
        description   TEXT,
        mime          TEXT,
        folder_id     TEXT,
        folder_path   TEXT,
        size          INTEGER,
        md5           TEXT,
        modified_time TEXT,
        first_seen    TEXT NOT NULL,
        last_seen     TEXT,
        text_status   TEXT DEFAULT 'pending',    -- pending | ok | scanned | manual | skipped | error
        text_error    TEXT,
        char_count    INTEGER DEFAULT 0,
        review_status TEXT DEFAULT 'new',        -- new | reviewed | ignored
        is_public     INTEGER DEFAULT 1,
        duplicate_of  TEXT,
        removed       INTEGER DEFAULT 0,
        doc_type      TEXT DEFAULT '',           -- Oficial | Comissão | Comercial | Colaborativo
        doc_date      TEXT DEFAULT ''            -- data do documento (texto livre: "02/10/2023", "08/2026")
    );
    CREATE TABLE IF NOT EXISTS chunks (
        id     INTEGER PRIMARY KEY AUTOINCREMENT,
        doc_id TEXT NOT NULL REFERENCES docs(id) ON DELETE CASCADE,
        n      INTEGER NOT NULL,
        text   TEXT NOT NULL,
        norm   TEXT NOT NULL
    );
    CREATE INDEX IF NOT EXISTS idx_chunks_doc ON chunks(doc_id);
    CREATE TABLE IF NOT EXISTS faq (
        id         TEXT PRIMARY KEY,
        cat        TEXT NOT NULL,
        status     TEXT NOT NULL DEFAULT 'oficial',
        q          TEXT NOT NULL,
        a          TEXT NOT NULL,
        tags       TEXT NOT NULL DEFAULT '[]',
        src        TEXT NOT NULL DEFAULT '[]',   -- ids de docs
        sort       INTEGER DEFAULT 0,
        published  INTEGER DEFAULT 1,
        updated_at TEXT
    );
    CREATE TABLE IF NOT EXISTS settings (
        key   TEXT PRIMARY KEY,
        value TEXT NOT NULL
    );
    CREATE TABLE IF NOT EXISTS sync_log (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        started_at TEXT,
        finished_at TEXT,
        ok         INTEGER,
        summary    TEXT
    );
    SQL);
    // Migrações de bancos criados por versões anteriores
    $cols = array_column($pdo->query('PRAGMA table_info(docs)')->fetchAll(), 'name');
    if (!in_array('doc_date', $cols, true)) $pdo->exec("ALTER TABLE docs ADD COLUMN doc_date TEXT DEFAULT ''");
}

function setting(string $key, $default = null)
{
    $st = db()->prepare('SELECT value FROM settings WHERE key = ?');
    $st->execute([$key]);
    $v = $st->fetchColumn();
    return $v === false ? $default : json_decode($v, true);
}

function set_setting(string $key, $value): void
{
    db()->prepare('INSERT INTO settings(key, value) VALUES(?, ?) ON CONFLICT(key) DO UPDATE SET value = excluded.value')
        ->execute([$key, json_encode($value, JSON_UNESCAPED_UNICODE)]);
}

/* ---------------- Utilidades ---------------- */
function now(): string
{
    return date('Y-m-d H:i:s');
}

function h(?string $s): string
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function json_response($data, int $code = 200): void
{
    http_response_code($code);
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function drive_url(array $doc): string
{
    $id = $doc['id'];
    return match ($doc['mime'] ?? '') {
        'application/vnd.google-apps.document'     => "https://docs.google.com/document/d/$id/view",
        'application/vnd.google-apps.spreadsheet'  => "https://docs.google.com/spreadsheets/d/$id/edit",
        'application/vnd.google-apps.presentation' => "https://docs.google.com/presentation/d/$id/view",
        'application/vnd.google-apps.folder'       => "https://drive.google.com/drive/folders/$id",
        default                                    => "https://drive.google.com/file/d/$id/view",
    };
}

/** URL para exibir o arquivo dentro do site (iframe do Google). Null = sem pré-visualização. */
function preview_url(array $doc): ?string
{
    $id = rawurlencode($doc['id']);
    return match (true) {
        ($doc['mime'] ?? '') === 'application/vnd.google-apps.document'    => "https://docs.google.com/document/d/$id/preview",
        ($doc['mime'] ?? '') === 'application/vnd.google-apps.spreadsheet' => "https://docs.google.com/spreadsheets/d/$id/preview",
        ($doc['mime'] ?? '') === 'application/vnd.google-apps.presentation' => "https://docs.google.com/presentation/d/$id/preview",
        ($doc['mime'] ?? '') === 'application/vnd.google-apps.folder'      => null,
        default                                                             => "https://drive.google.com/file/d/$id/preview",
    };
}

/** URL de download direto (Docs/Planilhas são exportados em PDF/XLSX). */
function download_url(array $doc): ?string
{
    $id = rawurlencode($doc['id']);
    return match ($doc['mime'] ?? '') {
        'application/vnd.google-apps.document'    => "https://docs.google.com/document/d/$id/export?format=pdf",
        'application/vnd.google-apps.spreadsheet' => "https://docs.google.com/spreadsheets/d/$id/export?format=xlsx",
        'application/vnd.google-apps.folder'      => null,
        default                                   => "https://drive.google.com/uc?export=download&id=$id",
    };
}

/** Última pasta de um caminho "Pasta A / Pasta B" (nomes podem conter "/"). */
function last_folder(string $path): string
{
    $parts = explode(' / ', $path);
    return (string) end($parts);
}

function doc_title(array $doc): string
{
    if (!empty($doc['title'])) return $doc['title'];
    return preg_replace('/\.(pdf|docx?|xlsx?|txt)$/i', '', $doc['name']);
}

function log_line(string $msg): void
{
    $line = '[' . now() . '] ' . $msg . PHP_EOL;
    @file_put_contents(STORAGE . '/sync.log', $line, FILE_APPEND);
    if (PHP_SAPI === 'cli') echo $line;
}

/**
 * Sanitiza o HTML das respostas (escritas no painel) mantendo só tags seguras.
 */
function sanitize_html(string $html): string
{
    $allowed = ['p', 'br', 'strong', 'b', 'em', 'i', 'ul', 'ol', 'li', 'a', 'table', 'thead', 'tbody', 'tr', 'th', 'td', 'mark', 'small', 'span'];
    $doc = new DOMDocument();
    libxml_use_internal_errors(true);
    $doc->loadHTML('<?xml encoding="UTF-8"><div id="__root">' . $html . '</div>', LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
    libxml_clear_errors();

    $root = $doc->getElementById('__root');
    if (!$root) return h(strip_tags($html));

    $walk = function (DOMNode $node) use (&$walk, $allowed, $doc) {
        foreach (iterator_to_array($node->childNodes) as $child) {
            if ($child instanceof DOMElement) {
                $tag = strtolower($child->tagName);
                if (!in_array($tag, $allowed, true)) {
                    if (in_array($tag, ['script', 'style', 'iframe', 'object', 'embed'], true)) {
                        $node->removeChild($child);
                        continue;
                    }
                    // desembrulha a tag mantendo o conteúdo
                    while ($child->firstChild) $node->insertBefore($child->firstChild, $child);
                    $node->removeChild($child);
                    continue;
                }
                foreach (iterator_to_array($child->attributes) as $attr) {
                    $name = strtolower($attr->name);
                    $keep = ($tag === 'a' && $name === 'href' && preg_match('#^(https?:|mailto:|tel:|\#)#i', $attr->value))
                        || ($name === 'class' && in_array($attr->value, ['note', 'mini-table'], true));
                    if (!$keep) $child->removeAttribute($attr->name);
                }
                if ($tag === 'a' && str_starts_with($child->getAttribute('href'), 'http')) {
                    $child->setAttribute('target', '_blank');
                    $child->setAttribute('rel', 'noopener');
                }
                $walk($child);
            } elseif ($child instanceof DOMComment) {
                $node->removeChild($child);
            }
        }
    };
    $walk($root);

    $out = '';
    foreach ($root->childNodes as $c) $out .= $doc->saveHTML($c);
    return trim($out);
}
