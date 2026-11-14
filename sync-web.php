<?php
/*
 * Disparo da sincronização por URL, para hospedagens que só oferecem "cron por URL":
 *   https://seusite.com.br/sync-web.php?token=SEU_TOKEN
 */
declare(strict_types=1);
require __DIR__ . '/app/bootstrap.php';

$token = (string) config('sync_token', '');
if ($token === '' || !hash_equals($token, (string) ($_GET['token'] ?? ''))) {
    http_response_code(403);
    exit('Acesso negado');
}
ignore_user_abort(true);
try {
    json_response((new Sync())->run());
} catch (Throwable $e) {
    json_response(['ok' => false, 'error' => $e->getMessage()], 500);
}
