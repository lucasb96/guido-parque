<?php
/*
 * Sincronização com o Google Drive. Rode pelo cron, 1x por dia (ou de hora em hora):
 *   php /caminho/para/sistema-condominio/cron/sync.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Use pela linha de comando ou pelo sync-web.php');
}
require dirname(__DIR__) . '/app/bootstrap.php';

try {
    $r = (new Sync())->run();
    exit(!empty($r['ok']) ? 0 : 1);
} catch (Throwable $e) {
    fwrite(STDERR, 'ERRO: ' . $e->getMessage() . PHP_EOL);
    exit(1);
}
