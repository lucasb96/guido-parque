<?php
declare(strict_types=1);

/**
 * Persistência em arquivos de texto (versionados no Git) – a "fonte da verdade".
 * O banco SQLite em storage/ é só um cache reconstruído a partir destes arquivos.
 *
 *   content/   → editado pela Comissão no painel (perguntas, textos, configurações, dados dos documentos)
 *     faq.json, settings.json, docs.json, manual-text/<id>.txt
 *   state/     → escrito pela sincronização automática (GitHub Actions)
 *     docs.json, settings.json, texts/<id>.txt
 *
 * Separar os dois evita conflitos: o painel só altera content/, o robô só altera state/.
 */
final class Store
{
    public const CONTENT_SETTINGS = ['categories', 'quick_searches', 'status', 'stats', 'timeline', 'amenities', 'checklist',
        'glossary', 'doc_meta', 'updated_at', 'gallery_folder_id', 'vendor_sheet_id'];
    public const STATE_SETTINGS = ['vendors', 'obra_progress', 'obra_progress_history', 'initialized', 'initialized_at', 'last_sync'];
    public const DOC_EDITABLE = ['title', 'description', 'doc_type', 'doc_date', 'is_public', 'review_status'];

    public static function contentDir(): string { return ROOT . '/content'; }
    public static function stateDir(): string { return ROOT . '/state'; }

    /** O robô (GitHub Actions) grava state/; no computador local não, para não gerar conflitos no Git. */
    public static function writesState(): bool
    {
        return (bool) config('write_state', getenv('GITHUB_ACTIONS') === 'true');
    }

    /* ------------------------------------------------------------------ */
    /*  Exportação                                                         */
    /* ------------------------------------------------------------------ */

    public static function exportContent(): void
    {
        $pdo = db();
        $dir = self::contentDir();
        @mkdir("$dir/manual-text", 0775, true);

        $faq = [];
        foreach ($pdo->query('SELECT * FROM faq ORDER BY sort, rowid') as $r) {
            $faq[] = ['id' => $r['id'], 'cat' => $r['cat'], 'status' => $r['status'], 'q' => $r['q'], 'a' => $r['a'],
                'tags' => json_decode($r['tags'], true) ?: [], 'src' => json_decode($r['src'], true) ?: [],
                'sort' => (int) $r['sort'], 'published' => (int) $r['published'], 'updated_at' => $r['updated_at']];
        }
        self::writeJson("$dir/faq.json", $faq);

        $settings = [];
        foreach (self::CONTENT_SETTINGS as $k) {
            $v = setting($k);
            if ($v !== null) $settings[$k] = $v;
        }
        self::writeJson("$dir/settings.json", $settings);

        // Campos dos documentos que a Comissão edita no painel
        $docs = [];
        foreach ($pdo->query('SELECT * FROM docs ORDER BY id') as $d) {
            $row = [];
            foreach (self::DOC_EDITABLE as $f) $row[$f] = $d[$f];
            $row['is_public'] = (int) $row['is_public'];
            $docs[$d['id']] = $row;
        }
        self::writeJson("$dir/docs.json", $docs);
    }

    public static function exportState(): void
    {
        if (!self::writesState()) return;
        $pdo = db();
        $dir = self::stateDir();
        @mkdir("$dir/texts", 0775, true);

        $docs = [];
        foreach ($pdo->query('SELECT * FROM docs ORDER BY id') as $d) {
            unset($d['text_error'], $d['last_seen']); // last_seen muda todo dia e só geraria ruído no Git
            $d['size'] = (int) $d['size'];
            $d['char_count'] = (int) $d['char_count'];
            $d['is_public'] = (int) $d['is_public'];
            $d['removed'] = (int) $d['removed'];
            $docs[$d['id']] = $d;
        }
        self::writeJson("$dir/docs.json", $docs);

        $settings = [];
        foreach (self::STATE_SETTINGS as $k) {
            $v = setting($k);
            if ($v !== null) $settings[$k] = $v;
        }
        self::writeJson("$dir/settings.json", $settings);
    }

    /** Guarda o texto de um documento no arquivo certo (manual → content/, automático → state/). */
    public static function saveText(string $docId, string $text, string $status): void
    {
        $id = basename($docId);
        if ($status === 'manual') {
            @mkdir(self::contentDir() . '/manual-text', 0775, true);
            file_put_contents(self::contentDir() . "/manual-text/$id.txt", $text);
        } elseif ($status === 'ok' && self::writesState()) {
            @mkdir(self::stateDir() . '/texts', 0775, true);
            file_put_contents(self::stateDir() . "/texts/$id.txt", $text);
        }
    }

    /* ------------------------------------------------------------------ */
    /*  Importação (reconstrói o banco a partir dos arquivos)              */
    /* ------------------------------------------------------------------ */

    public static function import(PDO $pdo): void
    {
        $content = self::contentDir();
        $state = self::stateDir();

        $pdo->exec('DELETE FROM chunks; DELETE FROM docs; DELETE FROM faq; DELETE FROM settings;');

        $put = $pdo->prepare('INSERT OR REPLACE INTO settings(key, value) VALUES(?, ?)');
        foreach ([self::readJson("$state/settings.json"), self::readJson("$content/settings.json")] as $set) {
            foreach ($set as $k => $v) $put->execute([$k, json_encode($v, JSON_UNESCAPED_UNICODE)]);
        }

        $ins = $pdo->prepare('INSERT INTO faq (id, cat, status, q, a, tags, src, sort, published, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)');
        foreach (self::readJson("$content/faq.json") as $i => $f) {
            $ins->execute([$f['id'], $f['cat'], $f['status'], $f['q'], $f['a'],
                json_encode($f['tags'] ?? [], JSON_UNESCAPED_UNICODE), json_encode($f['src'] ?? []),
                $f['sort'] ?? $i, $f['published'] ?? 1, $f['updated_at'] ?? null]);
        }

        // Documentos: estado da sincronização + campos editados pela Comissão
        $edits = self::readJson("$content/docs.json");
        $cols = array_column($pdo->query('PRAGMA table_info(docs)')->fetchAll(), 'name');
        foreach (self::readJson("$state/docs.json") as $id => $d) {
            foreach (self::DOC_EDITABLE as $f) if (isset($edits[$id]) && array_key_exists($f, $edits[$id])) $d[$f] = $edits[$id][$f];
            $d = array_intersect_key($d, array_flip($cols));
            $pdo->prepare('INSERT OR REPLACE INTO docs (' . implode(',', array_keys($d)) . ') VALUES (' . implode(',', array_fill(0, count($d), '?')) . ')')
                ->execute(array_values($d));
        }

        // Textos para a busca
        foreach ($pdo->query('SELECT id, text_status FROM docs')->fetchAll() as $d) {
            $manual = "$content/manual-text/" . basename($d['id']) . '.txt';
            $auto = "$state/texts/" . basename($d['id']) . '.txt';
            if (is_file($manual)) Sync::indexText($d['id'], (string) file_get_contents($manual), 'manual');
            elseif (is_file($auto) && $d['text_status'] === 'ok') Sync::indexText($d['id'], (string) file_get_contents($auto), 'ok');
        }
    }

    /* ------------------------------------------------------------------ */

    private static function readJson(string $file): array
    {
        if (!is_file($file)) return [];
        $data = json_decode((string) file_get_contents($file), true);
        return is_array($data) ? $data : [];
    }

    private static function writeJson(string $file, $data): void
    {
        @mkdir(dirname($file), 0775, true);
        $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n";
        if (!is_file($file) || file_get_contents($file) !== $json) file_put_contents($file, $json);
    }
}
