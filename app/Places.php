<?php
declare(strict_types=1);

/**
 * "O que tem perto": lugares ao redor do empreendimento, a partir do OpenStreetMap (Overpass API).
 *
 * Os servidores públicos do Overpass pedem uso ocasional e humano, então isto NÃO roda no robô diário:
 * é atualizado pelo painel (Configurações → "Atualizar arredores") e guardado em content/settings.json.
 * Dados © colaboradores do OpenStreetMap (licença ODbL).
 */
final class Places
{
    public const DEFAULT_ORIGIN = [
        'lat' => -23.6594, 'lon' => -46.7251,
        'address' => 'Avenida Guido Caloi, 1987 - Jardim São Luís, São Paulo - SP, 05802-140',
    ];

    private const MIRRORS = [
        'https://overpass-api.de/api/interpreter',
        'https://overpass.private.coffee/api/interpreter',
        'https://maps.mail.ru/osm/tools/overpass/api/interpreter',
    ];

    /** Categorias exibidas no site: id => [rótulo, ícone, limite de itens] */
    public const CATEGORIES = [
        'transporte' => ['Metrô e trem', 'train-front', 6],
        'onibus' => ['Ônibus', 'bus', 8],
        'mercado' => ['Mercados', 'shopping-cart', 8],
        'farmacia' => ['Farmácias', 'pill', 6],
        'saude' => ['Saúde', 'hospital', 10],
        'escola' => ['Escolas', 'school', 12],
        'faculdade' => ['Faculdades', 'graduation-cap', 6],
        'padaria' => ['Padarias', 'croissant', 5],
        'posto' => ['Postos', 'fuel', 6],
        'autopecas' => ['Autopeças', 'cog', 8],
        'mecanica' => ['Mecânicas e borracharias', 'wrench', 8],
        'banco' => ['Bancos', 'landmark', 6],
        'shopping' => ['Shoppings', 'shopping-bag', 8],
        'parque' => ['Parques e praças', 'trees', 8],
        'academia' => ['Academias', 'dumbbell', 5],
        'pet' => ['Pet e veterinário', 'paw-print', 6],
        'servicos' => ['Segurança e serviços', 'shield', 6],
    ];

    /** Nomes que sempre aparecem na categoria (editável no painel → Avançado → "places_pinned"). */
    public static function pinned(): array
    {
        return (array) (setting('places_pinned') ?: ['shopping' => ['SP Market']]);
    }

    /** Linhas de ônibus por lugar (nome => [código => descrição]). Editável no painel → Avançado → "places_lines". */
    public const DEFAULT_LINES = [
        'Terminal Guido Caloi' => [
            '7004-10' => 'Term. Jd. Jacira / Est. Sto. Amaro – Guido Caloi',
            '709P-10' => 'Est. Sto. Amaro – Guido Caloi / Term. Pinheiros',
            '6008-10' => 'Jd. Planalto / Est. Sto. Amaro – Guido Caloi',
            '6028-10' => 'Atende a ligação na região da Estação Santo Amaro / Av. Guido Caloi',
        ],
    ];

    public static function lines(): array
    {
        return (array) (setting('places_lines') ?? self::DEFAULT_LINES);
    }

    /** Junta as linhas cadastradas aos lugares (feito ao gerar o site, então sobrevive a "Atualizar arredores"). */
    public static function withLines(?array $places): ?array
    {
        if (!$places || empty($places['categories'])) return $places;
        $lines = [];
        foreach (self::lines() as $name => $l) $lines[Text::normalize((string) $name)] = $l;
        foreach ($places['categories'] as &$c) {
            foreach ($c['items'] as &$it) {
                $l = $lines[Text::normalize($it['name'])] ?? null;
                if (!$l) continue;
                $it['lines'] = [];
                foreach ($l as $code => $desc) $it['lines'][] = ['code' => (string) $code, 'name' => (string) $desc];
            }
            unset($it);
        }
        unset($c);
        return $places;
    }

    public static function origin(): array
    {
        return array_merge(self::DEFAULT_ORIGIN, (array) (setting('places_origin') ?: []));
    }

    /** Consulta o OpenStreetMap, classifica e salva em settings('places'). */
    public static function update(): array
    {
        $o = self::origin();
        [$lat, $lon] = [(float) $o['lat'], (float) $o['lon']];
        // Raio por tipo: categorias densas (escolas, farmácias...) já têm muitos lugares a menos de 3 km;
        // as escassas (shoppings, autopeças, hospitais...) buscam mais longe. A lista sempre mostra os mais próximos.
        $q = "[out:json][timeout:120];(
            nwr[\"railway\"~\"^(station|halt)$\"][\"name\"](around:5000,$lat,$lon);
            nwr[\"amenity\"=\"bus_station\"][\"name\"](around:5000,$lat,$lon);
            node[\"highway\"=\"bus_stop\"](around:450,$lat,$lon);
            nwr[\"shop\"~\"^(supermarket|wholesale|bakery|pet)$\"][\"name\"](around:3000,$lat,$lon);
            nwr[\"shop\"~\"^(car_parts|car_repair|tyres)$\"][\"name\"](around:6000,$lat,$lon);
            nwr[\"shop\"=\"mall\"][\"name\"](around:10000,$lat,$lon);
            nwr[\"amenity\"~\"^(pharmacy|clinic|school|kindergarten|fuel|bank|police|fire_station|post_office|veterinary)$\"][\"name\"](around:3000,$lat,$lon);
            nwr[\"amenity\"~\"^(hospital|university|college)$\"][\"name\"](around:6000,$lat,$lon);
            nwr[\"leisure\"~\"^(park|fitness_centre)$\"][\"name\"](around:2500,$lat,$lon);
        );out center tags;";

        $data = self::query($q);
        $cats = [];
        foreach (self::CATEGORIES as $id => [$label, $icon]) $cats[$id] = ['id' => $id, 'label' => $label, 'icon' => $icon, 'items' => []];

        foreach ($data['elements'] ?? [] as $e) {
            $t = $e['tags'] ?? [];
            $pLat = $e['lat'] ?? $e['center']['lat'] ?? null;
            $pLon = $e['lon'] ?? $e['center']['lon'] ?? null;
            if ($pLat === null) continue;
            [$cat, $kind] = self::classify($t);
            if (!$cat) continue;
            $name = trim((string) ($t['name'] ?? ''));
            if ($name === '') continue;
            $item = [
                'name' => $name,
                'kind' => $kind,
                'lat' => round((float) $pLat, 6),
                'lon' => round((float) $pLon, 6),
                'dist' => (int) round(self::distance($lat, $lon, (float) $pLat, (float) $pLon)),
            ];
            $addr = trim(($t['addr:street'] ?? '') . (isset($t['addr:housenumber']) ? ', ' . $t['addr:housenumber'] : ''));
            if ($addr !== '' && $addr !== $name) $item['address'] = $addr;
            $cats[$cat]['items'][] = $item;
        }

        foreach ($cats as $id => &$c) {
            usort($c['items'], fn ($a, $b) => $a['dist'] <=> $b['dist']);
            // Remove repetidos (mesmo nome a menos de 300 m – ex.: estação e plataforma)
            $kept = [];
            foreach ($c['items'] as $it) {
                foreach ($kept as $k) {
                    // mesmo nome E mesmo tipo (metrô e trem "Santo Amaro" são estações diferentes)
                    if (mb_strtolower($k['name']) === mb_strtolower($it['name']) && $k['kind'] === $it['kind']
                        && self::distance($k['lat'], $k['lon'], $it['lat'], $it['lon']) < 300) continue 2;
                }
                $kept[] = $it;
            }
            $top = array_slice($kept, 0, self::CATEGORIES[$id][2]);
            // Lugares fixos: entram mesmo se estiverem além do limite (ex.: Shopping SP Market)
            foreach ((self::pinned()[$id] ?? []) as $want) {
                $w = Text::normalize($want);
                foreach ($top as $t) if (str_contains(Text::normalize($t['name']), $w)) continue 2;
                foreach ($kept as $k) {
                    if (str_contains(Text::normalize($k['name']), $w)) { $top[] = $k + ['pinned' => true]; break; }
                }
            }
            $c['items'] = $top;
        }
        unset($c);

        $result = [
            'origin' => $o,
            'updated_at' => now(),
            'categories' => array_values(array_filter($cats, fn ($c) => $c['items'])),
        ];
        set_setting('places', $result);
        return $result;
    }

    /** @return array{0:?string,1:string} [categoria, tipo exibido] */
    private static function classify(array $t): array
    {
        $am = $t['amenity'] ?? '';
        $shop = $t['shop'] ?? '';
        $op = $t['operator'] ?? $t['network'] ?? '';
        if (in_array($t['railway'] ?? '', ['station', 'halt'], true)) {
            $isMetro = ($t['station'] ?? '') === 'subway' || ($t['subway'] ?? '') === 'yes';
            return ['transporte', ($isMetro ? 'Metrô' : 'Trem') . ($op ? " · $op" : '')];
        }
        if ($am === 'bus_station') return ['onibus', 'Terminal de ônibus'];
        if (($t['highway'] ?? '') === 'bus_stop') return ['onibus', 'Ponto de ônibus'];
        if ($shop === 'supermarket') return ['mercado', 'Supermercado'];
        if ($shop === 'wholesale') return ['mercado', 'Atacadista'];
        if ($shop === 'bakery') return ['padaria', 'Padaria'];
        if ($shop === 'mall') return ['shopping', 'Shopping'];
        if ($shop === 'pet') return ['pet', 'Pet shop'];
        if ($shop === 'car_parts') return ['autopecas', 'Autopeças'];
        if ($shop === 'car_repair') return ['mecanica', 'Oficina mecânica'];
        if ($shop === 'tyres') return ['mecanica', 'Borracharia / pneus'];
        if ($am === 'veterinary') return ['pet', 'Veterinário'];
        if ($am === 'pharmacy') return ['farmacia', 'Farmácia'];
        if ($am === 'hospital') return ['saude', 'Hospital'];
        if ($am === 'clinic') return ['saude', preg_match('/\b(UBS|Unidade B[áa]sica)/iu', $t['name'] ?? '') ? 'UBS (posto de saúde)' : 'Clínica / ambulatório'];
        if ($am === 'kindergarten') return ['escola', 'Educação infantil'];
        if ($am === 'school') return ['escola', self::schoolKind($t)];
        if (in_array($am, ['university', 'college'], true)) return ['faculdade', 'Ensino superior / técnico'];
        if ($am === 'fuel') return ['posto', 'Posto de combustível' . (!empty($t['brand']) && stripos($t['name'] ?? '', $t['brand']) === false ? " · {$t['brand']}" : '')];
        if ($am === 'bank') return ['banco', 'Banco'];
        if (($t['leisure'] ?? '') === 'park') return ['parque', 'Parque / praça'];
        if (($t['leisure'] ?? '') === 'fitness_centre') return ['academia', 'Academia'];
        if ($am === 'police') return ['servicos', 'Polícia'];
        if ($am === 'fire_station') return ['servicos', 'Bombeiros'];
        if ($am === 'post_office') return ['servicos', 'Correios'];
        return [null, ''];
    }

    private static function schoolKind(array $t): string
    {
        $n = $t['name'] ?? '';
        if (preg_match('/^(E\.?\s?E\.?|Escola Estadual)\b/iu', $n)) return 'Escola estadual';
        if (preg_match('/^(EMEF|EMEI|CEI|CEU|E\.?\s?M\.?|Escola Municipal)\b/iu', $n)) return 'Escola municipal';
        if (($t['operator:type'] ?? '') === 'public' || ($t['operator:type'] ?? '') === 'government') return 'Escola pública';
        if (($t['operator:type'] ?? '') === 'private') return 'Escola particular';
        return 'Escola';
    }

    public static function distance(float $lat1, float $lon1, float $lat2, float $lon2): float
    {
        $r = 6371000;
        $dLat = deg2rad($lat2 - $lat1);
        $dLon = deg2rad($lon2 - $lon1);
        $a = sin($dLat / 2) ** 2 + cos(deg2rad($lat1)) * cos(deg2rad($lat2)) * sin($dLon / 2) ** 2;
        return 2 * $r * asin(min(1, sqrt($a)));
    }

    /** Tenta os servidores públicos com espera crescente (eles pedem isso quando estão cheios). */
    private static function query(string $q): array
    {
        $wait = 5;
        $last = '';
        foreach (self::MIRRORS as $i => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => http_build_query(['data' => $q]),
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 120,
                CURLOPT_USERAGENT => 'CentralDuvidasCondominio/1.0',
            ]);
            $body = (string) curl_exec($ch);
            $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            curl_close($ch);
            $json = json_decode($body, true);
            if ($code === 200 && is_array($json) && isset($json['elements'])) return $json;
            $last = "HTTP $code";
            if ($i < count(self::MIRRORS) - 1) { sleep($wait); $wait *= 2; }
        }
        throw new RuntimeException("OpenStreetMap indisponível no momento ($last). Tente de novo mais tarde.");
    }
}
