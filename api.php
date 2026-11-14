<?php
declare(strict_types=1);

/*
 * API local (usada quando o site roda no seu computador com "php -S").
 * No GitHub Pages o site usa os arquivos gerados data.json e search.json.
 *   api.php?action=data          → todo o conteúdo da página
 *   api.php?action=search&q=...  → busca dentro dos documentos do Drive
 */
require __DIR__ . '/app/bootstrap.php';

$action = $_GET['action'] ?? 'data';

try {
    if ($action === 'search') {
        $q = trim((string) ($_GET['q'] ?? ''));
        if (mb_strlen($q) < 2 || mb_strlen($q) > 120) json_response(['results' => []]);
        json_response(['results' => Search::documents($q)]);
    }
    if ($action === 'data') json_response(SiteData::build());
    json_response(['error' => 'Ação inválida'], 400);
} catch (Throwable $e) {
    error_log($e->getMessage());
    json_response(['error' => 'Erro interno'], 500);
}
