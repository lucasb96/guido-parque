<?php
declare(strict_types=1);

/**
 * Busca de texto dentro dos documentos sincronizados do Drive.
 */
final class Search
{
    /** @return array<int, array> resultados agrupados por documento */
    public static function documents(string $q, int $limit = 8): array
    {
        $tokens = array_slice(Text::tokens($q), 0, 8);
        if (!$tokens) return [];

        // Pré-filtro no SQL: blocos que contenham ao menos um termo (ou variação)
        $likes = [];
        $params = [];
        $variantsByToken = [];
        foreach ($tokens as $t) {
            $variantsByToken[$t] = Text::variants($t);
            foreach ($variantsByToken[$t] as $v) {
                $likes[] = 'c.norm LIKE ?';
                $params[] = '%' . $v . '%';
            }
        }
        $sql = 'SELECT c.doc_id, c.n, c.text, c.norm, d.name, d.title, d.mime, d.folder_path, d.modified_time
                FROM chunks c JOIN docs d ON d.id = c.doc_id
                WHERE d.removed = 0 AND d.is_public = 1 AND d.review_status <> \'ignored\' AND (' . implode(' OR ', $likes) . ')
                LIMIT 3000';
        $st = db()->prepare($sql);
        $st->execute($params);

        $needed = count($tokens) <= 2 ? count($tokens) : (int) ceil(count($tokens) * 0.6);

        // 1ª passada: frequência de cada termo por bloco
        $rows = [];
        $df = array_fill_keys($tokens, 0);
        foreach ($st as $row) {
            $tf = [];
            foreach ($variantsByToken as $t => $variants) {
                $best = 0.0;
                foreach ($variants as $v) {
                    $count = substr_count($row['norm'], $v);
                    if ($count > 0) $best = max($best, min($count, 4) * ($v === $t ? 1.0 : 0.7));
                }
                $tf[$t] = $best;
                if ($best > 0) $df[$t]++;
            }
            $row['tf'] = $tf;
            $rows[] = $row;
        }
        $total = max(1, (int) db()->query('SELECT COUNT(*) FROM chunks')->fetchColumn());
        // termos raros pesam mais (IDF)
        $idf = array_map(fn ($n) => log(1 + $total / max(1, $n)), $df);

        // 2ª passada: pontuação
        $byDoc = [];
        foreach ($rows as $row) {
            $hits = 0;
            $score = 0.0;
            $firstPos = null;
            $matched = [];
            foreach ($variantsByToken as $t => $variants) {
                if ($row['tf'][$t] <= 0) continue;
                $hits++;
                $score += (1 + log($row['tf'][$t])) * $idf[$t];
                foreach ($variants as $v) {
                    $pos = strpos($row['norm'], $v);
                    if ($pos === false) continue;
                    $matched[] = $v;
                    // o trecho começa perto do termo mais raro
                    if ($firstPos === null || $idf[$t] > ($firstIdf ?? 0)) { $firstPos = $pos; $firstIdf = $idf[$t]; }
                }
            }
            unset($firstIdf);
            if ($hits < $needed) continue;
            $score *= ($hits / count($tokens)) ** 2;

            $id = $row['doc_id'];
            if (!isset($byDoc[$id]) || $byDoc[$id]['score'] < $score) {
                $byDoc[$id] = [
                    'doc_id' => $id,
                    'title' => doc_title($row),
                    'folder' => $row['folder_path'],
                    'url' => drive_url(['id' => $id, 'mime' => $row['mime']]),
                    'preview' => preview_url(['id' => $id, 'mime' => $row['mime']]),
                    'download' => download_url(['id' => $id, 'mime' => $row['mime']]),
                    'score' => $score,
                    'snippet' => self::snippet($row['text'], $row['norm'], (int) $firstPos, array_unique($matched)),
                    'matches' => ($byDoc[$id]['matches'] ?? 0) + 1,
                ];
            } else {
                $byDoc[$id]['matches']++;
            }
        }

        usort($byDoc, fn ($a, $b) => $b['score'] <=> $a['score']);
        return array_slice(array_values($byDoc), 0, $limit);
    }

    /** Recorta ~260 caracteres ao redor do primeiro termo e marca os termos com <mark>. */
    private static function snippet(string $text, string $norm, int $pos, array $terms): string
    {
        // $norm e $text têm o mesmo número de caracteres; converte posição em bytes para caracteres
        $charPos = mb_strlen(substr($norm, 0, $pos));
        $start = max(0, $charPos - 90);
        $len = 260;
        $cut = mb_substr($text, $start, $len);
        $cutNorm = mb_substr(Text::normalize($text), $start, $len);

        // Marca os termos usando posições do texto normalizado
        $ranges = [];
        foreach ($terms as $t) {
            if (mb_strlen($t) < 2) continue;
            $offset = 0;
            while (($p = mb_strpos($cutNorm, $t, $offset)) !== false) {
                $ranges[] = [$p, $p + mb_strlen($t)];
                $offset = $p + mb_strlen($t);
            }
        }
        usort($ranges, fn ($a, $b) => $a[0] <=> $b[0]);
        $html = '';
        $cursor = 0;
        foreach ($ranges as [$s, $e]) {
            if ($s < $cursor) continue;
            $html .= h(mb_substr($cut, $cursor, $s - $cursor)) . '<mark>' . h(mb_substr($cut, $s, $e - $s)) . '</mark>';
            $cursor = $e;
        }
        $html .= h(mb_substr($cut, $cursor));

        return ($start > 0 ? '… ' : '') . trim($html) . ($start + $len < mb_strlen($text) ? ' …' : '');
    }
}
