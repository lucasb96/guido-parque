<?php
/*
 * Gera o site estático (pasta dist/) para o GitHub Pages.
 *
 *   php tools/build.php           → só gera o site com o conteúdo atual
 *   php tools/build.php --sync    → antes, sincroniza Drive + acompanhamento da obra (usado pelo robô diário)
 */
declare(strict_types=1);
if (PHP_SAPI !== 'cli') exit;

require dirname(__DIR__) . '/app/bootstrap.php';

$doSync = in_array('--sync', $argv, true);
$dist = ROOT . '/dist';

if ($doSync) {
    $r = (new Sync())->run();
    if (empty($r['ok'])) fwrite(STDERR, "Aviso: sincronização com problemas: " . json_encode($r, JSON_UNESCAPED_UNICODE) . "\n");
}

/* ---------- Limpa e recria dist/ ---------- */
function rrmdir(string $dir): void
{
    if (!is_dir($dir)) return;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST) as $f) {
        $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
    }
    rmdir($dir);
}
function copy_into(string $src, string $dst): void
{
    if (is_file($src)) { @mkdir(dirname($dst), 0775, true); copy($src, $dst); return; }
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($src, FilesystemIterator::SKIP_DOTS)) as $f) {
        $rel = substr($f->getPathname(), strlen($src) + 1);
        @mkdir(dirname("$dst/$rel"), 0775, true);
        copy($f->getPathname(), "$dst/$rel");
    }
}
if (realpath(dirname($dist)) !== realpath(ROOT)) exit("Caminho de saída inesperado.\n");
rrmdir($dist);
mkdir($dist, 0775, true);

foreach (['index.html', 'manifest.webmanifest', 'css', 'img', 'js/app.js', 'js/pwa.js'] as $p) copy_into(ROOT . "/$p", "$dist/$p");

/* ---------- Dados ---------- */
$json = JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;
file_put_contents("$dist/data.json", json_encode(SiteData::build(), $json));
file_put_contents("$dist/search.json", json_encode(SiteData::searchIndex(), $json));
file_put_contents("$dist/.nojekyll", '');

/* ---------- Service worker com versão automática (muda quando o site muda) ---------- */
$hash = '';
foreach (['index.html', 'css/styles.css', 'js/app.js', 'js/pwa.js', 'img/icons.svg'] as $p) $hash .= md5_file("$dist/$p");
$version = 'b-' . substr(md5($hash), 0, 10);
$sw = (string) file_get_contents(ROOT . '/sw.js');
$sw = preg_replace('/const VERSION = "[^"]*";/', 'const VERSION = "' . $version . '";', $sw, 1);
file_put_contents("$dist/sw.js", $sw);

$size = 0;
foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dist, FilesystemIterator::SKIP_DOTS)) as $f) $size += $f->getSize();
printf("Site gerado em dist/ (%.1f MB) · versão %s · %d perguntas\n", $size / 1048576, $version, count(SiteData::build()['faq']));
