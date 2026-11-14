<?php
declare(strict_types=1);

final class Text
{
    public const STOPWORDS = [
        'a','o','as','os','de','da','do','das','dos','e','em','no','na','nos','nas','um','uma','uns','umas',
        'para','por','pra','com','que','qual','quais','quando','como','onde','se','sao','ser','tem','ter',
        'vai','vou','eu','meu','minha','meus','minhas','ja','ou','ao','aos','sobre','posso','pode',
    ];

    public const SYNONYMS = [
        'luz' => ['energia', 'eletrica', 'enel'],
        'energia' => ['luz', 'enel'],
        'varanda' => ['terraco', 'sacada'],
        'sacada' => ['terraco', 'varanda'],
        'terraco' => ['varanda', 'sacada'],
        'garagem' => ['vaga', 'estacionamento'],
        'estacionamento' => ['vaga', 'garagem'],
        'carro' => ['vaga', 'garagem'],
        'academia' => ['fitness'],
        'pet' => ['animal', 'animais'],
        'cachorro' => ['animal', 'animais', 'pet'],
        'gato' => ['animal', 'animais', 'pet'],
        'split' => ['ar-condicionado'],
        'tomada' => ['110v', '220v', 'voltagem'],
        'camera' => ['cftv'],
        'cameras' => ['cftv'],
        'churrasco' => ['churrasqueira'],
        'mercado' => ['easy market'],
        'atraso' => ['carencia', 'prazo'],
        'sindica' => ['sindico'],
    ];

    /** Minúsculas e sem acentos (mantém o mesmo número de caracteres em português). */
    public static function normalize(string $s): string
    {
        if (class_exists('Normalizer')) {
            $s = Normalizer::normalize($s, Normalizer::FORM_D);
            $s = preg_replace('/\p{Mn}+/u', '', $s);
        } else {
            $s = strtr($s, [
                'á'=>'a','à'=>'a','â'=>'a','ã'=>'a','ä'=>'a','é'=>'e','è'=>'e','ê'=>'e','ë'=>'e','í'=>'i','ì'=>'i','î'=>'i','ï'=>'i',
                'ó'=>'o','ò'=>'o','ô'=>'o','õ'=>'o','ö'=>'o','ú'=>'u','ù'=>'u','û'=>'u','ü'=>'u','ç'=>'c','ñ'=>'n',
                'Á'=>'A','À'=>'A','Â'=>'A','Ã'=>'A','Ä'=>'A','É'=>'E','È'=>'E','Ê'=>'E','Ë'=>'E','Í'=>'I','Ì'=>'I','Î'=>'I','Ï'=>'I',
                'Ó'=>'O','Ò'=>'O','Ô'=>'O','Õ'=>'O','Ö'=>'O','Ú'=>'U','Ù'=>'U','Û'=>'U','Ü'=>'U','Ç'=>'C','Ñ'=>'N',
            ]);
        }
        return mb_strtolower($s, 'UTF-8');
    }

    /** @return string[] */
    public static function tokens(string $q): array
    {
        $parts = preg_split('/[^a-z0-9\-]+/', self::normalize($q), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_filter($parts, fn ($t) => strlen($t) > 1 && !in_array($t, self::STOPWORDS, true)));
    }

    /** Variações de um termo: sinônimos, singular e radical simples. */
    public static function variants(string $t): array
    {
        $v = array_merge([$t], self::SYNONYMS[$t] ?? []);
        if (strlen($t) > 4 && str_ends_with($t, 's')) $v[] = substr($t, 0, -1);
        if (strlen($t) >= 6) $v[] = substr($t, 0, max(5, strlen($t) - 3));
        return array_values(array_unique($v));
    }

    /** Limpa texto extraído: espaços, hifenização de quebra de linha, linhas vazias repetidas. */
    public static function clean(string $s): string
    {
        $s = str_replace(["\r\n", "\r", "\f"], "\n", $s);
        $s = preg_replace('/[ \t\x{00A0}]+/u', ' ', $s);
        $s = preg_replace('/ *\n */', "\n", $s);
        $s = preg_replace("/\n{3,}/", "\n\n", $s);
        return trim($s);
    }

    /**
     * Remove dados pessoais antes de indexar (CPF, RG, e-mail, IP).
     * O texto indexado aparece em trechos públicos da busca.
     */
    public static function redact(string $s): string
    {
        $s = preg_replace('/\b\d{3}\.?\d{3}\.?\d{3}-?\d{2}\b/', '[CPF]', $s);
        $s = preg_replace('/\b\d{2}\.\d{3}\.\d{3}-?[\dxX]\b/', '[RG]', $s);
        $s = preg_replace('/[A-Za-z0-9._%+\-]+@[A-Za-z0-9.\-]+\.[A-Za-z]{2,}/', '[e-mail]', $s);
        $s = preg_replace('/\b(?:IP:?\s*)?\d{1,3}\.\d{1,3}\.\d{1,3}\.\d{1,3}\b/', '[IP]', $s);
        return $s;
    }

    /** Divide o texto em blocos de ~700 caracteres respeitando parágrafos. */
    public static function chunk(string $text, int $size = 700): array
    {
        $paras = preg_split("/\n{2,}|(?<=[.:;])\n/", $text);
        $chunks = [];
        $cur = '';
        foreach ($paras as $p) {
            $p = trim(preg_replace('/\s*\n\s*/', ' ', $p));
            if ($p === '') continue;
            if (mb_strlen($cur) + mb_strlen($p) + 1 > $size && $cur !== '') {
                $chunks[] = $cur;
                $cur = '';
            }
            if (mb_strlen($p) > $size * 1.5) {
                foreach (mb_str_split($p, $size) as $piece) $chunks[] = $piece;
                continue;
            }
            $cur = $cur === '' ? $p : "$cur $p";
        }
        if ($cur !== '') $chunks[] = $cur;
        return $chunks;
    }
}
