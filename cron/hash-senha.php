<?php
/* Gera o hash da senha do painel:  php cron/hash-senha.php "minha senha forte" */
if (PHP_SAPI !== 'cli') exit;
$pw = $argv[1] ?? '';
if (strlen($pw) < 8) exit("Informe uma senha com pelo menos 8 caracteres.\n");
echo password_hash($pw, PASSWORD_DEFAULT), PHP_EOL;
