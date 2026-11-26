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
    public const VERSION = 2;

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
        if ($letters < 150 * $pages && $letters < 2000) {
            // PDF escaneado: tenta OCR (Tesseract em português), se disponível no servidor
            $ocr = self::ocr($path);
            if ($ocr !== null && preg_match_all('/\p{L}/u', $ocr) > 200) {
                return ['status' => 'ok', 'text' => "[Texto obtido por OCR automático – pode conter pequenos erros de leitura]\n\n" . Text::clean($ocr), 'error' => null];
            }
            return ['status' => 'scanned', 'text' => $text, 'error' => 'PDF escaneado: sem texto selecionável'];
        }
        return ['status' => 'ok', 'text' => $text, 'error' => null];
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
