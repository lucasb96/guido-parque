<?php
declare(strict_types=1);

/**
 * Sincroniza as pastas do Drive com o banco local:
 * novos arquivos, alterações, remoções, extração de texto e planilha de fornecedores.
 */
final class Sync
{
    private Drive $drive;
    private Extractor $extractor;
    private array $stats = ['novos' => 0, 'alterados' => 0, 'removidos' => 0, 'textos' => 0, 'escaneados' => 0, 'erros' => 0];

    public function __construct()
    {
        $key = (string) config('google_api_key');
        if ($key === '' || str_contains($key, 'COLE_AQUI')) throw new RuntimeException('Configure google_api_key em app/config.php');
        $this->drive = new Drive($key);
        $this->extractor = new Extractor($this->drive, (int) config('max_download_mb', 40));
    }

    public function run(): array
    {
        $lock = fopen(STORAGE . '/sync.lock', 'c');
        if (!flock($lock, LOCK_EX | LOCK_NB)) throw new RuntimeException('Já existe uma sincronização em andamento.');

        @set_time_limit(0);
        @ini_set('memory_limit', '512M');
        $pdo = db();
        $started = now();
        $firstRun = !setting('initialized', false);
        log_line('Sincronização iniciada' . ($firstRun ? ' (importação inicial)' : ''));

        try {
            /* 1. Lista tudo no Drive */
            $seen = [];
            foreach ((array) config('drive_folders', []) as $root) {
                $rootName = $this->drive->folderName($root);
                foreach ($this->drive->listRecursive($root, $rootName) as $f) {
                    $seen[$f['id']] = true;
                    $this->upsert($f, $firstRun);
                }
            }

            /* 2. Marca removidos */
            $all = $pdo->query('SELECT id FROM docs WHERE removed = 0')->fetchAll(PDO::FETCH_COLUMN);
            foreach ($all as $id) {
                if (!isset($seen[$id])) {
                    $pdo->prepare('UPDATE docs SET removed = 1 WHERE id = ?')->execute([$id]);
                    $pdo->prepare('DELETE FROM chunks WHERE doc_id = ?')->execute([$id]);
                    $this->stats['removidos']++;
                }
            }

            /* 3. Duplicados (mesmo conteúdo em mais de uma pasta) */
            // Original = o que tem texto transcrito manualmente; depois o mais antigo
            $pdo->exec("UPDATE docs SET duplicate_of = NULL");
            $groups = [];
            foreach ($pdo->query("SELECT id, md5, text_status, first_seen FROM docs
                                  WHERE removed = 0 AND md5 IS NOT NULL AND md5 <> ''
                                  ORDER BY (text_status = 'manual') DESC, first_seen, id") as $r) {
                $groups[$r['md5']][] = $r['id'];
            }
            $mark = $pdo->prepare('UPDATE docs SET duplicate_of = ? WHERE id = ?');
            foreach ($groups as $ids) {
                foreach (array_slice($ids, 1) as $dup) $mark->execute([$ids[0], $dup]);
            }
            $pdo->exec("DELETE FROM chunks WHERE doc_id IN (SELECT id FROM docs WHERE duplicate_of IS NOT NULL)");
            // O original herda título/descrição/tipo da cópia, se ainda não tiver
            $pdo->exec("UPDATE docs SET
                    title = COALESCE(NULLIF(title, ''), (SELECT d2.title FROM docs d2 WHERE d2.duplicate_of = docs.id AND d2.title <> '' LIMIT 1)),
                    description = COALESCE(NULLIF(description, ''), (SELECT d2.description FROM docs d2 WHERE d2.duplicate_of = docs.id AND d2.description <> '' LIMIT 1)),
                    doc_type = COALESCE(NULLIF(doc_type, ''), (SELECT d2.doc_type FROM docs d2 WHERE d2.duplicate_of = docs.id AND d2.doc_type <> '' LIMIT 1), ''),
                    doc_date = COALESCE(NULLIF(doc_date, ''), (SELECT d2.doc_date FROM docs d2 WHERE d2.duplicate_of = docs.id AND d2.doc_date <> '' LIMIT 1), '')
                WHERE duplicate_of IS NULL");

            /* 4. Extrai texto do que estiver pendente */
            $pending = $pdo->query("SELECT * FROM docs WHERE removed = 0 AND duplicate_of IS NULL
                                    AND text_status IN ('pending', 'error') ORDER BY first_seen")->fetchAll();
            foreach ($pending as $doc) {
                if (!Extractor::supports($doc['mime'])) {
                    $pdo->prepare("UPDATE docs SET text_status = 'skipped' WHERE id = ?")->execute([$doc['id']]);
                    continue;
                }
                log_line("Extraindo texto: {$doc['name']}");
                $r = $this->extractor->extract($doc);
                $this->saveText($doc['id'], $r['text'], $r['status'], $r['error']);
                if ($r['status'] === 'ok') $this->stats['textos']++;
                if ($r['status'] === 'scanned') $this->stats['escaneados']++;
                if ($r['status'] === 'error') { $this->stats['erros']++; log_line("  erro: {$r['error']}"); }
            }

            /* 5. Planilha de fornecedores */
            $sheet = setting('vendor_sheet_id');
            if ($sheet) {
                try {
                    $vendors = self::parseVendors($this->drive->export($sheet, 'text/csv'));
                    if ($vendors) set_setting('vendors', $vendors);
                } catch (Throwable $e) {
                    log_line('Planilha de fornecedores: ' . $e->getMessage());
                }
            }

            /* 6. Acompanhamento da obra (site da Cury) */
            try {
                CuryProgress::update();
            } catch (Throwable $e) {
                $this->stats['obra'] = $e->getMessage();
                log_line('Acompanhamento da obra: ' . $e->getMessage());
            }

            if ($firstRun) {
                set_setting('initialized', true);
                set_setting('initialized_at', now());
            }
            set_setting('last_sync', now());
            Store::exportState();
            $ok = true;
        } catch (Throwable $e) {
            $ok = false;
            $this->stats['erro_geral'] = $e->getMessage();
            log_line('ERRO: ' . $e->getMessage());
        } finally {
            $pdo->prepare('INSERT INTO sync_log(started_at, finished_at, ok, summary) VALUES(?,?,?,?)')
                ->execute([$started, now(), (int) ($ok ?? false), json_encode($this->stats, JSON_UNESCAPED_UNICODE)]);
            flock($lock, LOCK_UN);
            fclose($lock);
        }

        log_line('Concluído: ' . json_encode($this->stats, JSON_UNESCAPED_UNICODE));
        return $this->stats + ['ok' => $ok];
    }

    private function upsert(array $f, bool $firstRun): void
    {
        $pdo = db();
        $st = $pdo->prepare('SELECT * FROM docs WHERE id = ?');
        $st->execute([$f['id']]);
        $cur = $st->fetch();

        if (!$cur) {
            $pdo->prepare('INSERT INTO docs (id, name, mime, folder_id, folder_path, size, md5, modified_time, first_seen, last_seen, review_status, text_status)
                           VALUES (?,?,?,?,?,?,?,?,?,?,?,?)')
                ->execute([
                    $f['id'], $f['name'], $f['mimeType'], $f['folder_id'], $f['folder_path'], (int) ($f['size'] ?? 0),
                    $f['md5Checksum'] ?? null, $f['modifiedTime'] ?? null, now(), now(),
                    $firstRun ? 'reviewed' : 'new', 'pending',
                ]);
            // Metadados iniciais (título amigável, descrição, tipo)
            $meta = (setting('doc_meta', []) ?: [])[$f['id']] ?? null;
            if ($meta) {
                $pdo->prepare('UPDATE docs SET title = ?, description = ?, doc_type = ?, doc_date = ? WHERE id = ?')
                    ->execute([$meta['title'] ?? null, $meta['description'] ?? null, $meta['doc_type'] ?? '', $meta['doc_date'] ?? '', $f['id']]);
            }
            // Texto já transcrito (ex.: OCR de PDFs escaneados)
            $manual = Store::contentDir() . '/manual-text/' . basename($f['id']) . '.txt';
            if (is_file($manual)) self::indexText($f['id'], (string) file_get_contents($manual), 'manual');
            if (!$firstRun) {
                $this->stats['novos']++;
                log_line("Novo arquivo: {$f['folder_path']} / {$f['name']}");
            }
            return;
        }

        $changed = ($cur['modified_time'] ?? '') !== ($f['modifiedTime'] ?? '')
            && ($cur['md5'] ?? '') !== ($f['md5Checksum'] ?? '');
        // Google Docs/Sheets não têm md5: compara pela data de modificação
        if (empty($f['md5Checksum'])) $changed = ($cur['modified_time'] ?? '') !== ($f['modifiedTime'] ?? '');

        $pdo->prepare('UPDATE docs SET name = ?, mime = ?, folder_id = ?, folder_path = ?, size = ?, md5 = ?, modified_time = ?, last_seen = ?, removed = 0 WHERE id = ?')
            ->execute([$f['name'], $f['mimeType'], $f['folder_id'], $f['folder_path'], (int) ($f['size'] ?? 0),
                $f['md5Checksum'] ?? null, $f['modifiedTime'] ?? null, now(), $f['id']]);

        if ($changed && $cur['mime'] !== 'application/vnd.google-apps.folder') {
            // Texto colado manualmente é preservado; o resto é extraído de novo
            if ($cur['text_status'] !== 'manual') {
                $pdo->prepare("UPDATE docs SET text_status = 'pending' WHERE id = ?")->execute([$f['id']]);
            }
            // A planilha de fornecedores muda o tempo todo: não precisa de revisão
            if ($f['id'] !== setting('vendor_sheet_id')) {
                $pdo->prepare("UPDATE docs SET review_status = 'new' WHERE id = ? AND review_status <> 'ignored'")->execute([$f['id']]);
                $this->stats['alterados']++;
                log_line("Arquivo alterado: {$f['name']}");
            }
        }
    }

    /** Grava o texto do documento e reconstrói os blocos de busca. */
    /** Indexa o texto e também o guarda em arquivo (content/ ou state/). */
    public static function saveText(string $docId, string $text, string $status, ?string $error = null): void
    {
        self::indexText($docId, $text, $status, $error);
        Store::saveText($docId, Text::redact($text), $status);
    }

    /** Só grava no banco: blocos de busca + status do texto. */
    public static function indexText(string $docId, string $text, string $status, ?string $error = null): void
    {
        $pdo = db();
        $pdo->beginTransaction();
        $pdo->prepare('DELETE FROM chunks WHERE doc_id = ?')->execute([$docId]);
        $text = Text::redact($text);
        $ins = $pdo->prepare('INSERT INTO chunks (doc_id, n, text, norm) VALUES (?,?,?,?)');
        $n = 0;
        if ($status === 'ok' || $status === 'manual') {
            foreach (Text::chunk($text) as $c) {
                $ins->execute([$docId, $n++, $c, Text::normalize($c)]);
            }
        }
        $pdo->prepare('UPDATE docs SET text_status = ?, text_error = ?, char_count = ? WHERE id = ?')
            ->execute([$status, $error, mb_strlen($text), $docId]);
        $pdo->commit();
    }

    /** Converte a planilha de indicações em lista de fornecedores. */
    public static function parseVendors(string $csv): array
    {
        $rows = Extractor::parseCsv($csv);
        if (count($rows) < 2) return [];
        $head = array_map(fn ($h) => Text::normalize(trim((string) $h)), array_shift($rows));
        $col = function (string $needle) use ($head) {
            foreach ($head as $i => $h) if (str_contains($h, $needle)) return $i;
            return null;
        };
        $iService = $col('servic');
        $iName = $col('fornecedor');
        $iInsta = $col('instagram') ?? $col('site');
        $iPhone = $col('contato') ?? $col('telefone');
        $iNote = $col('observ');

        $out = [];
        foreach ($rows as $r) {
            $get = fn ($i) => $i === null ? '' : trim((string) ($r[$i] ?? ''));
            $service = $get($iService);
            $name = $get($iName);
            if ($service === '' && $name === '') continue;

            $phoneDigits = preg_replace('/\D/', '', $get($iPhone));
            $phone = '';
            if (strlen($phoneDigits) === 11) $phone = substr($phoneDigits, 0, 2) . ' ' . substr($phoneDigits, 2, 5) . '-' . substr($phoneDigits, 7);
            elseif (strlen($phoneDigits) === 10) $phone = substr($phoneDigits, 0, 2) . ' ' . substr($phoneDigits, 2, 4) . '-' . substr($phoneDigits, 6);
            elseif ($phoneDigits !== '') $phone = $get($iPhone);

            $out[] = [
                'service' => $service ?: 'Outros',
                'name' => $name ?: $service,
                'phone' => $phone,
                'link' => self::normalizeLink($get($iInsta)),
                'note' => $get($iNote),
            ];
        }
        return $out;
    }

    private static function normalizeLink(string $raw): string
    {
        if ($raw === '') return '';
        if (preg_match('#https?://\S+#i', $raw, $m)) {
            $url = $m[0];
            // remove parâmetros de rastreamento (?igsh=, ?utm_)
            return preg_replace('/\?.*$/', '', $url);
        }
        if (preg_match('/@?([a-z0-9._]{3,})\s*$/i', $raw, $m) && stripos($raw, 'insta') !== false) {
            return 'https://www.instagram.com/' . ltrim($m[1], '@');
        }
        if (preg_match('/^@([a-z0-9._]+)$/i', $raw, $m)) return 'https://www.instagram.com/' . $m[1];
        if (preg_match('/^[a-z0-9.\-]+\.[a-z]{2,}(\.[a-z]{2})?$/i', $raw)) return 'https://' . strtolower($raw);
        return '';
    }
}
