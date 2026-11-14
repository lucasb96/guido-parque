<?php
declare(strict_types=1);

/**
 * Lê o "Acompanhamento de obras" da página pública do empreendimento no site da Cury
 * e guarda os percentuais por etapa (com histórico das mudanças).
 */
final class CuryProgress
{
    public const DEFAULT_URL = 'https://cury.net/imovel/SP/zona-sul/guido-parque-nacoes-unidas';

    /** Busca, interpreta e salva. Retorna o resumo salvo. */
    public static function update(): array
    {
        $url = (string) config('cury_progress_url', self::DEFAULT_URL);
        $html = self::fetch($url);
        $items = self::parse($html);
        if (count($items) < 3) {
            // Layout da página mudou ou a leitura falhou: mantém os últimos dados bons
            throw new RuntimeException('Não foi possível ler o acompanhamento de obras (o site da Cury pode ter mudado).');
        }

        $prev = setting('obra_progress');
        $now = now();
        $data = [
            'items' => $items,
            'average' => round(array_sum(array_column($items, 'pct')) / count($items), 1),
            'checked_at' => $now,                                   // última verificação
            'changed_at' => $prev['changed_at'] ?? $now,            // última vez que algum número mudou
            'source' => $url,
        ];

        $changed = !$prev || array_column($prev['items'] ?? [], 'pct', 'label') !== array_column($items, 'pct', 'label');
        if ($changed) {
            $data['changed_at'] = $now;
            $history = setting('obra_progress_history', []);
            $history[] = ['date' => $now, 'items' => array_column($items, 'pct', 'label'), 'average' => $data['average']];
            set_setting('obra_progress_history', array_slice($history, -120));
            log_line('Acompanhamento da obra atualizado: média ' . $data['average'] . '%');
        }
        set_setting('obra_progress', $data);
        return $data;
    }

    /** @return array<int, array{label: string, pct: int}> */
    public static function parse(string $html): array
    {
        $start = stripos($html, 'Acompanhamento de obras');
        if ($start === false) return [];
        $section = substr($html, $start, 20000);

        $items = [];
        // Cada etapa: <div class="progress-work"> ... <p>Nome</p> ... <p>NN%</p>
        if (preg_match_all('#class="progress-work".*?<p>\s*([^<]+?)\s*</p>.*?<p>\s*(\d{1,3})\s*%\s*</p>#si', $section, $m, PREG_SET_ORDER)) {
            foreach ($m as $row) {
                $label = html_entity_decode(trim($row[1]), ENT_QUOTES | ENT_HTML5, 'UTF-8');
                $pct = max(0, min(100, (int) $row[2]));
                $items[] = ['label' => $label, 'pct' => $pct];
            }
        }
        return $items;
    }

    private static function fetch(string $url): string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_CONNECTTIMEOUT => 20,
            CURLOPT_TIMEOUT => 60,
            CURLOPT_USERAGENT => 'Mozilla/5.0 (compatible; CentralDuvidas/1.0)',
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        if ($body === false || $code >= 400) throw new RuntimeException("Site da Cury indisponível ($code $err)");
        return (string) $body;
    }
}
