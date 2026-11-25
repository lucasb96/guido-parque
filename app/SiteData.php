<?php
declare(strict_types=1);

/**
 * Monta os dados públicos do site (usado pelo api.php no servidor local
 * e pelo tools/build.php para gerar o site estático do GitHub Pages).
 */
final class SiteData
{
    /** Conteúdo completo da página (data.json). */
    public static function build(): array
    {
        $pdo = db();

        // Documentos visíveis (sem duplicados, pastas, imagens e vídeos)
        $docs = [];
        foreach ($pdo->query("SELECT * FROM docs WHERE removed = 0 AND is_public = 1 AND duplicate_of IS NULL
                              AND review_status <> 'ignored'
                              AND mime NOT LIKE 'image/%' AND mime NOT LIKE 'video/%'
                              AND mime <> 'application/vnd.google-apps.folder'
                              ORDER BY COALESCE(modified_time, first_seen) DESC") as $d) {
            $docs[$d['id']] = [
                'title' => doc_title($d),
                'desc' => $d['description'] ?: last_folder((string) $d['folder_path']),
                'date' => $d['doc_date'] ?: ($d['modified_time'] ? 'enviado em ' . date('d/m/Y', strtotime($d['modified_time'])) : ''),
                'type' => $d['doc_type'] ?: 'Documento',
                'url' => drive_url($d),
                'preview' => preview_url($d),
                'download' => download_url($d),
                'searchable' => in_array($d['text_status'], ['ok', 'manual'], true),
                'added' => substr((string) $d['first_seen'], 0, 10), // quando o robô viu o arquivo pela 1ª vez (avisos de "novos documentos")
            ];
        }

        // Galeria: um álbum por pasta com fotos/vídeos (mais recente primeiro).
        // Prints de tela ficam de fora (costumam ter conversas e dados pessoais).
        $albums = [];
        $media = $pdo->query("SELECT id, name, mime, folder_id, folder_path, modified_time FROM docs
                              WHERE removed = 0 AND is_public = 1 AND review_status <> 'ignored' AND duplicate_of IS NULL
                                AND (mime LIKE 'image/%' OR mime LIKE 'video/%')
                                AND name NOT LIKE 'Screenshot%' AND name NOT LIKE 'Captura%'
                              ORDER BY name, modified_time, id");
        foreach ($media as $m) {
            $fid = $m['folder_id'];
            $albums[$fid] ??= ['id' => $fid, 'title' => last_folder((string) $m['folder_path']), 'latest' => '', 'items' => [],
                'folder' => "https://drive.google.com/drive/folders/$fid"];
            $albums[$fid]['items'][] = ['id' => $m['id'], 'type' => str_starts_with($m['mime'], 'video/') ? 'video' : 'image'];
            $albums[$fid]['latest'] = max($albums[$fid]['latest'], (string) $m['modified_time']);
        }
        $albums = array_values($albums);
        usort($albums, fn ($a, $b) => strcmp($b['latest'], $a['latest']));
        $pref = setting('gallery_folder_id');
        if ($pref) usort($albums, fn ($a, $b) => ($b['id'] === $pref) <=> ($a['id'] === $pref));

        // Fontes que apontam para cópias duplicadas passam a apontar para o original
        $dupOf = $pdo->query('SELECT id, duplicate_of FROM docs WHERE duplicate_of IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);

        $faq = [];
        foreach ($pdo->query('SELECT * FROM faq WHERE published = 1 ORDER BY sort, rowid') as $f) {
            $faq[] = [
                'id' => $f['id'], 'cat' => $f['cat'], 'status' => $f['status'], 'q' => $f['q'], 'a' => $f['a'],
                'tags' => json_decode($f['tags'], true) ?: [],
                'src' => array_values(array_unique(array_filter(
                    array_map(fn ($id) => $dupOf[$id] ?? $id, json_decode($f['src'], true) ?: []),
                    fn ($id) => isset($docs[$id])
                ))),
            ];
        }

        return [
            'updatedAt' => setting('updated_at', date('d/m/Y')),
            'lastSync' => setting('last_sync'),
            'docs' => $docs,
            'categories' => setting('categories', []),
            'quickSearches' => setting('quick_searches', []),
            'status' => setting('status', []),
            'stats' => setting('stats', []),
            'faq' => $faq,
            'timeline' => setting('timeline', []),
            'amenities' => setting('amenities', []),
            'checklist' => setting('checklist', []),
            'vendors' => setting('vendors', []),
            'vendorSheet' => setting('vendor_sheet_id'),
            'glossary' => setting('glossary', []),
            'obra' => setting('obra_progress'),
            'obraHistory' => array_slice(setting('obra_progress_history', []), -2),
            'gallery' => ['albums' => $albums],
            'places' => Places::withLines(setting('places')),
            'announcements' => array_values(array_filter((array) setting('announcements', []), fn ($a) => !empty($a['title']))),
        ];
    }

    /**
     * Índice para a busca nos documentos feita no navegador (search.json).
     * Só entram documentos públicos; o texto já está sem CPF/RG/e-mail/IP.
     */
    public static function searchIndex(): array
    {
        $pdo = db();
        $docs = [];
        $pos = [];
        foreach ($pdo->query("SELECT * FROM docs WHERE removed = 0 AND is_public = 1 AND duplicate_of IS NULL
                              AND review_status <> 'ignored' AND text_status IN ('ok', 'manual') ORDER BY title, name") as $d) {
            $pos[$d['id']] = count($docs);
            $docs[] = [
                'id' => $d['id'], 'title' => doc_title($d), 'folder' => (string) $d['folder_path'],
                'url' => drive_url($d), 'preview' => preview_url($d), 'download' => download_url($d),
            ];
        }
        $chunks = [];
        foreach ($pdo->query('SELECT doc_id, text FROM chunks ORDER BY doc_id, n') as $c) {
            if (isset($pos[$c['doc_id']])) $chunks[] = [$pos[$c['doc_id']], $c['text']];
        }
        return ['docs' => $docs, 'chunks' => $chunks];
    }
}
