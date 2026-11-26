<?php
declare(strict_types=1);

/**
 * Extrai texto de arquivos do Drive.
 * Retorna ['status' => ok|scanned|skipped|error, 'text' => string, 'error' => ?string]
 */
final class Extractor
{
    public const TEXT_MIMES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/vnd.google-apps.document',
        'application/vnd.google-apps.spreadsheet',
        'text/plain',
        'text/csv',
    ];

    public function __construct(private Drive $drive, private int $maxMb = 40)
    {
    }

    public static function supports(string $mime): bool
    {
        return in_array($mime, self::TEXT_MIMES, true);
    }

    public function extract(array $file): array
    {
        $mime = $file['mimeType'] ?? $file['mime'];
        try {
            switch ($mime) {
                case 'application/vnd.google-apps.document':
                    return $this->ok($this->drive->export($file['id'], 'text/plain'));

                case 'application/vnd.google-apps.spreadsheet':
                    return $this->ok($this->csvToText($this->drive->export($file['id'], 'text/csv')));

                case 'text/plain':
                case 'text/csv':
                    return $this->withDownload($file, fn ($path) => $this->ok((string) file_get_contents($path)));

                case 'application/vnd.openxmlformats-officedocument.wordprocessingml.document':
                    return $this->withDownload($file, fn ($path) => $this->ok($this->docx($path)));

                case 'application/pdf':
                    return $this->withDownload($file, fn ($path) => $this->pdf($path));
            }
            return ['status' => 'skipped', 'text' => '', 'error' => null];
        } catch (Throwable $e) {
            return ['status' => 'error', 'text' => '', 'error' => $e->getMessage()];
        }
    }

    /* ------------------------------------------------------------------ */

    private function ok(string $text): array
    {
        return ['status' => 'ok', 'text' => Text::clean($text), 'error' => null];
    }

    private function withDownload(array $file, callable $fn): array
    {
        $size = (int) ($file['size'] ?? 0);
        if ($size > $this->maxMb * 1024 * 1024) {
            return ['status' => 'skipped', 'text' => '', 'error' => "Arquivo maior que {$this->maxMb} MB"];
        }
        $tmp = tempnam(sys_get_temp_dir(), 'gpnu');
        try {
            $this->drive->download($file['id'], $tmp);
            return $fn($tmp);
        } finally {
            @unlink($tmp);
        }
    }

    /** Sobe quando a leitura de PDF melhora: o robô relê uma vez os PDFs já extraídos. */
    public const VERSION = 4; // 3 = Docling; 4 = trava de qualidade (Docling só fica se não for pior)

    private function pdf(string $path): array
    {
        $text = '';
        $pages = 1;

        // 1) pdftotext (poppler), se o servidor tiver – costuma ter melhor qualidade
        if (function_exists('shell_exec') && !in_array('shell_exec', array_map('trim', explode(',', (string) ini_get('disable_functions'))), true)) {
            $bin = trim((string) @shell_exec(PHP_OS_FAMILY === 'Windows' ? 'where pdftotext 2>NUL' : 'command -v pdftotext 2>/dev/null'));
            if ($bin !== '') {
                $run = function (string $opts) use ($path): string {
                    $out = tempnam(sys_get_temp_dir(), 'gpnt');
                    @shell_exec('pdftotext -enc UTF-8 ' . $opts . escapeshellarg($path) . ' ' . escapeshellarg($out));
                    $t = (string) @file_get_contents($out);
                    @unlink($out);
                    return $t;
                };
                $text = self::mergeLayout($run(''), $run('-layout '));
                $pages = max(1, substr_count($text, "\f"));
            }
        }

        // 2) smalot/pdfparser (PHP puro)
        if (trim($text) === '') {
            $parser = new \Smalot\PdfParser\Parser();
            $pdf = $parser->parseFile($path);
            $pages = max(1, count($pdf->getPages()));
            $text = $pdf->getText();
        }

        $text = Text::clean($text);
        // Pouco texto por página = PDF escaneado (imagem)
        $letters = preg_match_all('/\p{L}/u', $text);

        // 1) Leitura comum: texto do PDF, ou OCR com Tesseract se for escaneado
        $ocrNote = "[Texto obtido por OCR automático – pode conter pequenos erros de leitura]\n\n";
        $scanned = $letters < 150 * $pages && $letters < 2000;
        $base = ['status' => 'ok', 'text' => $text, 'error' => null];
        if ($scanned) {
            $ocr = self::ocr($path);
            $base = ($ocr !== null && preg_match_all('/\p{L}/u', $ocr) > 200)
                ? ['status' => 'ok', 'text' => $ocrNote . Text::clean($ocr), 'error' => null]
                : ['status' => 'scanned', 'text' => $text, 'error' => 'PDF escaneado: sem texto selecionável'];
        }

        // 2) Docling (só no robô): monta as tabelas. Em PDF escaneado ou com texto duplicado/sobreposto,
        //    lê a página como imagem (OCR), o que elimina a duplicação.
        $ocrAll = $scanned || self::duplicatedText($text) >= 0.015;
        $md = self::docling($path, $ocrAll);
        if ($md === null) return $base;
        // Em PDF de texto normal, o Docling precisa ler pelo menos 80% do texto da leitura comum
        // (em PDFs cheios de imagens ele às vezes perde trechos)
        if (!$ocrAll && preg_match_all('/\p{L}/u', $md) < 0.8 * $letters) return $base;
        // Trava de qualidade: texto do Docling bem menos "português" que a leitura comum = leitura ruim
        // (ex.: página escaneada que ele leu de cabeça para baixo)
        if ($base['status'] === 'ok' && self::portugueseScore($md) < 0.7 * self::portugueseScore($base['text'])) return $base;
        return ['status' => 'ok', 'text' => ($ocrAll ? $ocrNote : '') . $md, 'error' => null];
    }

    /** Fração de palavras muito comuns do português ("de", "que", "para"…): ~25–35% em texto bem lido, < 12% em lixo de OCR. */
    public static function portugueseScore(string $text): float
    {
        static $sw = null;
        $sw ??= array_fill_keys(explode(' ', 'de da do das dos que a o e para com em no na nos nas os as um uma por ao se é ou não mais como pela pelo'), true);
        preg_match_all('/[\p{L}]+/u', mb_strtolower($text), $m);
        $words = $m[0];
        if (!$words) return 0.0;
        $hits = 0;
        foreach ($words as $w) if (isset($sw[$w])) $hits++;
        return $hits / count($words);
    }

    /**
     * Junta as duas leituras do pdftotext, página a página.
     * A leitura normal mantém a ordem de textos em colunas (ex.: Convenção em duas colunas), mas separa as tabelas
     * (todos os itens e depois todos os valores). Nas páginas com cara de tabela usa o modo -layout, que mantém
     * cada linha inteira; colunas viram " | " para o valor continuar ao lado do item.
     */
    public static function mergeLayout(string $plain, string $layout): string
    {
        $a = explode("\f", $plain);
        $b = explode("\f", $layout);
        if (count($a) !== count($b)) return $plain; // por segurança, fica com a leitura normal
        foreach ($a as $i => $page) {
            if (!self::looksTabular($page)) continue;
            $lines = array_map(fn ($l) => preg_replace('/ {3,}/', ' | ', trim($l)), explode("\n", $b[$i]));
            $a[$i] = implode("\n", $lines);
        }
        return implode("\f", $a);
    }

    /** Página com várias linhas só de números/valores (R$, %, datas) = tabela quebrada pela leitura normal. */
    public static function looksTabular(string $page): bool
    {
        $lines = array_values(array_filter(array_map('trim', explode("\n", $page)), fn ($l) => $l !== ''));
        if (count($lines) < 6) return false;
        $num = count(array_filter($lines, fn ($l) => preg_match('/^(R\$\s*)?[-–]?[\d.,%\/ ]+$/u', $l) && preg_match('/\d/', $l)));
        return $num >= 4 && $num / count($lines) >= 0.2;
    }

    /**
     * Fração de palavras repetidas em sequência ("10.600,97 10.600,97", "Subtotal Subtotal").
     * Acima de ~1,5% = PDF com duas camadas de texto sobrepostas (a Apresentação da AGI tinha 3,2%; os demais, até 0,5%).
     */
    public static function duplicatedText(string $text): float
    {
        $t = preg_split('/\s+/u', trim($text), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if (count($t) < 50) return 0.0;
        $rep = 0;
        for ($i = 1, $n = count($t); $i < $n; $i++) {
            if ($t[$i] === $t[$i - 1] && mb_strlen($t[$i]) > 2) $rep++;
        }
        return $rep / count($t);
    }

    /** Lê o PDF com tools/docling_pdf.py. Null se o Docling não estiver instalado ou a leitura falhar. */
    private static function docling(string $pdf, bool $ocrAll): ?string
    {
        static $py = null;
        if (!function_exists('shell_exec') || PHP_OS_FAMILY === 'Windows') return null;
        if ($py === null) {
            $cand = getenv('DOCLING_PYTHON') ?: 'python3';
            $ok = trim((string) @shell_exec(escapeshellarg($cand) . ' -c "import docling; print(1)" 2>/dev/null')) === '1';
            $py = $ok ? $cand : false;
            log_line($ok ? 'Leitura de PDF: Docling ativo' : 'Leitura de PDF: Docling não instalado, usando pdftotext/Tesseract');
        }
        if ($py === false) return null;
        $script = ROOT . '/tools/docling_pdf.py';
        $cmd = 'timeout 1200 ' . escapeshellarg($py) . ' ' . escapeshellarg($script) . ' ' . escapeshellarg($pdf)
            . ($ocrAll ? ' --ocr-pagina-inteira' : '') . ' 2>/dev/null';
        $md = Text::clean((string) @shell_exec($cmd));
        return preg_match_all('/\p{L}/u', $md) >= 200 ? $md : null;
    }

    /** OCR com pdftoppm + tesseract (instalados no GitHub Actions). Null se indisponível. */
    private static function ocr(string $pdf): ?string
    {
        if (!function_exists('shell_exec') || PHP_OS_FAMILY === 'Windows') return null;
        if (trim((string) @shell_exec('command -v pdftoppm')) === '' || trim((string) @shell_exec('command -v tesseract')) === '') return null;

        $dir = sys_get_temp_dir() . '/ocr_' . bin2hex(random_bytes(4));
        @mkdir($dir);
        try {
            // até 60 páginas, 300 dpi em tons de cinza (200 dpi perdia números pequenos)
            @shell_exec('pdftoppm -r 300 -gray -l 60 -png ' . escapeshellarg($pdf) . ' ' . escapeshellarg("$dir/p") . ' 2>/dev/null');
            $pages = glob("$dir/p*.png") ?: [];
            sort($pages, SORT_NATURAL);
            $out = [];
            foreach ($pages as $png) {
                $out[] = (string) @shell_exec('tesseract ' . escapeshellarg($png) . ' - -l por 2>/dev/null');
            }
            return $out ? implode("\n\n", $out) : null;
        } finally {
            foreach (glob("$dir/*") ?: [] as $f) @unlink($f);
            @rmdir($dir);
        }
    }

    private function docx(string $path): string
    {
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) throw new RuntimeException('Não foi possível abrir o .docx');
        $xml = (string) $zip->getFromName('word/document.xml');
        $zip->close();
        $xml = preg_replace('#</w:p>#', "\n\n", $xml);
        $xml = preg_replace('#<w:tab/>#', "\t", $xml);
        $xml = preg_replace('#<w:br[^>]*/>#', "\n", $xml);
        return html_entity_decode(strip_tags($xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
    }

    private function csvToText(string $csv): string
    {
        $rows = self::parseCsv($csv);
        if (!$rows) return '';
        $head = array_map('trim', array_shift($rows));
        $lines = [];
        foreach ($rows as $r) {
            $parts = [];
            foreach ($r as $i => $cell) {
                $cell = trim((string) $cell);
                if ($cell !== '') $parts[] = ($head[$i] ?? "Col$i") . ': ' . $cell;
            }
            if ($parts) $lines[] = implode(' · ', $parts);
        }
        return implode("\n\n", $lines);
    }

    /** @return array<int, array<int, string>> */
    public static function parseCsv(string $csv): array
    {
        $csv = preg_replace('/^\xEF\xBB\xBF/', '', $csv);
        $fh = fopen('php://temp', 'r+');
        fwrite($fh, $csv);
        rewind($fh);
        $rows = [];
        while (($r = fgetcsv($fh, 0, ',', '"', '')) !== false) {
            if ($r === [null]) continue;
            $rows[] = $r;
        }
        fclose($fh);
        return $rows;
    }
}
