<?php
declare(strict_types=1);

require dirname(__DIR__) . '/app/bootstrap.php';

// Caminho base do painel (ex.: "/admin/" ou "/condominio/admin/"), para links e redirecionamentos
define('ADMIN_BASE', rtrim(str_replace(DIRECTORY_SEPARATOR, '/', dirname($_SERVER['SCRIPT_NAME'])), '/') . '/');

// Acesso por "/admin" (sem barra) quebra os caminhos relativos: redireciona para "/admin/"
$reqPath = (string) parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH);
if ($reqPath !== '' && !str_ends_with($reqPath, '/') && !str_ends_with($reqPath, '.php')) {
    $qs = $_SERVER['QUERY_STRING'] ?? '';
    header('Location: ' . ADMIN_BASE . ($qs !== '' ? "?$qs" : ''), true, 301);
    exit;
}

/* =====================================================================
   Sessão, login e CSRF
   ===================================================================== */
session_name('gpnu_admin');
session_set_cookie_params(['httponly' => true, 'samesite' => 'Strict', 'secure' => !empty($_SERVER['HTTPS'])]);
session_start();
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'self'; img-src 'self' https://drive.google.com https://*.googleusercontent.com data:; style-src 'self' 'unsafe-inline'; script-src 'self' 'unsafe-inline'");

$_SESSION['csrf'] ??= bin2hex(random_bytes(16));
function csrf_field(): string { return '<input type="hidden" name="csrf" value="' . $_SESSION['csrf'] . '">'; }
function check_csrf(): void
{
    if (!hash_equals($_SESSION['csrf'], (string) ($_POST['csrf'] ?? ''))) {
        http_response_code(400);
        exit('Sessão expirada. Volte e tente novamente.');
    }
}
function flash(?string $msg = null, string $type = 'ok'): ?array
{
    if ($msg !== null) { $_SESSION['flash'] = [$msg, $type]; return null; }
    $f = $_SESSION['flash'] ?? null;
    unset($_SESSION['flash']);
    return $f;
}
function redirect(string $qs = ''): void
{
    // Depois de qualquer alteração no painel, grava o conteúdo nos arquivos content/ (versionados no Git)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_SESSION['auth'])) {
        try { Store::exportContent(); } catch (Throwable $e) { error_log('exportContent: ' . $e->getMessage()); }
    }
    header('Location: ' . ADMIN_BASE . 'index.php' . ($qs ? "?$qs" : ''));
    exit;
}

/* ---------- Publicação no GitHub (só com o painel rodando no próprio computador) ---------- */
function is_local_git(): bool
{
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    return in_array($ip, ['127.0.0.1', '::1'], true) && is_dir(ROOT . '/.git') && function_exists('proc_open');
}

/** Roda um comando git na pasta do projeto. @return array{0:int,1:string} */
function git(string $args): array
{
    $proc = proc_open('git ' . $args, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, ROOT);
    if (!is_resource($proc)) return [1, 'Não foi possível executar o git.'];
    $out = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    return [proc_close($proc), trim($out)];
}

/** Arquivos de conteúdo alterados e ainda não publicados. */
function pending_changes(): array
{
    if (!is_local_git()) return [];
    [, $out] = git('status --porcelain -- content');
    return array_values(array_filter(array_map('trim', explode("\n", $out))));
}

$hash = (string) config('admin_password_hash', '');
$page = $_GET['p'] ?? 'dashboard';

if ($page === 'logout') {
    session_destroy();
    redirect();
}

if (empty($_SESSION['auth'])) {
    $error = null;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        check_csrf();
        $att = setting('login_attempts', ['n' => 0, 't' => 0]);
        if ($att['n'] >= 5 && time() - $att['t'] < 900) {
            $error = 'Muitas tentativas. Aguarde 15 minutos.';
        } elseif ($hash !== '' && password_verify((string) ($_POST['password'] ?? ''), $hash)) {
            session_regenerate_id(true);
            $_SESSION['auth'] = true;
            set_setting('login_attempts', ['n' => 0, 't' => 0]);
            redirect();
        } else {
            sleep(1);
            set_setting('login_attempts', ['n' => ($att['n'] >= 5 ? 0 : $att['n']) + 1, 't' => time()]);
            $error = 'Senha incorreta.';
        }
    }
    layout_start('Entrar', false);
    ?>
    <div class="login-page">
        <div class="login">
            <img class="login-logo" src="<?= ADMIN_BASE ?>../img/logo.svg" alt="Cury" width="124" height="40">
            <h1>Painel da Comissão</h1>
            <p class="login-sub">Guido Parque Nações Unidas · acesso restrito aos membros da Comissão.</p>
            <?php if ($hash === ''): ?>
                <p class="alert warn">Defina a senha: gere o hash com <code>php cron/hash-senha.php "sua senha"</code> e cole em <code>admin_password_hash</code> no <code>app/config.php</code>.</p>
            <?php endif; ?>
            <?php if ($error): ?><p class="alert err" role="alert"><?= h($error) ?></p><?php endif; ?>
            <form method="post" action="<?= ADMIN_BASE ?>index.php">
                <?= csrf_field() ?>
                <label for="password">Senha</label>
                <div class="pw-field">
                    <input type="password" id="password" name="password" autocomplete="current-password" autofocus required>
                    <button type="button" class="pw-toggle" aria-label="Mostrar senha" aria-pressed="false"
                        onclick="const i=document.getElementById('password');const s=i.type==='password';i.type=s?'text':'password';this.setAttribute('aria-pressed',s);this.setAttribute('aria-label',s?'Ocultar senha':'Mostrar senha');this.textContent=s?'Ocultar':'Mostrar';">Mostrar</button>
                </div>
                <button class="btn btn-block" onclick="if(this.form.checkValidity()){this.textContent='Entrando…';}">Entrar</button>
            </form>
            <a class="login-back" href="<?= ADMIN_BASE ?>../index.html">← Voltar ao site</a>
        </div>
    </div>
    <?php
    layout_end();
    exit;
}

/* =====================================================================
   Ações (POST)
   ===================================================================== */
$pdo = db();
$CATS = setting('categories', []);
$CAT_LABEL = array_column($CATS, 'label', 'id');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    check_csrf();
    $action = $_POST['action'] ?? '';

    switch ($action) {
        case 'publish':
            if (!is_local_git()) { flash('A publicação só funciona com o painel aberto no seu computador.', 'err'); redirect(); }
            @set_time_limit(300);
            // Andamento da obra: lido daqui (o site da Cury bloqueia os servidores do GitHub)
            $obraMsg = '';
            try {
                CuryProgress::update();
                Store::exportObraLocal();
            } catch (Throwable $e) {
                $obraMsg = ' (Não foi possível ler o andamento da obra agora: ' . $e->getMessage() . ')';
            }
            Store::exportContent();
            $log = [];
            if (pending_changes()) {
                [$c1, $o1] = git('add -- content');
                [$c2, $o2] = git('commit -m ' . escapeshellarg('Conteúdo atualizado pelo painel (' . date('d/m/Y H:i') . ')'));
                if ($c1 || $c2) { flash('Não foi possível registrar as alterações: ' . ($o1 ?: $o2), 'err'); redirect(); }
                $log[] = 'alterações registradas';
            }
            // Traz o que o robô atualizou (documentos novos, andamento da obra) e envia o conteúdo
            [$c3, $o3] = git('pull --rebase --autostash');
            if ($c3) { flash('Não foi possível baixar as atualizações do GitHub: ' . $o3, 'err'); redirect(); }
            [$c4, $o4] = git('push');
            if ($c4) { flash('Não foi possível enviar para o GitHub: ' . $o4, 'err'); redirect(); }
            // Reconstrói o banco local com a versão mais recente dos arquivos
            Store::import(db());
            flash(($log ? 'Publicado! ' : 'Nada novo para publicar. ') . 'O site é atualizado em 1 a 2 minutos. Seu painel também foi atualizado com as novidades do robô.' . $obraMsg);
            redirect();

        case 'sync':
            try {
                $r = (new Sync())->run();
                flash(!empty($r['ok'])
                    ? "Sincronizado: {$r['novos']} novo(s), {$r['alterados']} alterado(s), {$r['textos']} texto(s) extraído(s)."
                    : 'Falha: ' . ($r['erro_geral'] ?? 'erro desconhecido'), !empty($r['ok']) ? 'ok' : 'err');
            } catch (Throwable $e) {
                flash($e->getMessage(), 'err');
            }
            redirect();

        case 'doc_review':
            $pdo->prepare('UPDATE docs SET review_status = ? WHERE id = ?')
                ->execute([in_array($_POST['status'], ['new', 'reviewed', 'ignored'], true) ? $_POST['status'] : 'reviewed', $_POST['id']]);
            flash('Documento atualizado.');
            redirect($_POST['back'] ?? '');

        case 'doc_save':
            $id = (string) $_POST['id'];
            $pdo->prepare('UPDATE docs SET title = ?, description = ?, doc_type = ?, doc_date = ?, is_public = ?, review_status = ? WHERE id = ?')
                ->execute([trim($_POST['title']), trim($_POST['description']), trim($_POST['doc_type']), trim((string) $_POST['doc_date']),
                    isset($_POST['is_public']) ? 1 : 0, $_POST['review_status'] ?? 'reviewed', $id]);
            $manual = trim((string) ($_POST['manual_text'] ?? ''));
            if ($manual !== '' && isset($_POST['save_text'])) {
                Sync::saveText($id, $manual, 'manual');
            }
            if (isset($_POST['reextract'])) {
                $pdo->prepare("UPDATE docs SET text_status = 'pending' WHERE id = ?")->execute([$id]);
                flash('O texto será extraído de novo na próxima sincronização.');
            } else {
                flash('Documento salvo.');
            }
            redirect('p=doc&id=' . urlencode($id));

        case 'faq_save':
            $q = trim((string) $_POST['q']);
            $id = trim((string) ($_POST['id'] ?? ''));
            $isNew = $id === '';
            if ($isNew) {
                $id = substr(trim(preg_replace('/[^a-z0-9]+/', '-', Text::normalize($q)), '-'), 0, 60) ?: 'pergunta';
                $base = $id; $i = 2;
                while ($pdo->query('SELECT 1 FROM faq WHERE id = ' . $pdo->quote($id))->fetchColumn()) $id = $base . '-' . $i++;
            }
            $tags = array_values(array_filter(array_map('trim', explode(',', (string) $_POST['tags']))));
            $src = array_values(array_filter((array) ($_POST['src'] ?? [])));
            $pdo->prepare('INSERT INTO faq (id, cat, status, q, a, tags, src, sort, published, updated_at) VALUES (?,?,?,?,?,?,?,?,?,?)
                           ON CONFLICT(id) DO UPDATE SET cat = excluded.cat, status = excluded.status, q = excluded.q, a = excluded.a,
                           tags = excluded.tags, src = excluded.src, sort = excluded.sort, published = excluded.published, updated_at = excluded.updated_at')
                ->execute([$id, $_POST['cat'], $_POST['status'], $q, sanitize_html((string) $_POST['a']),
                    json_encode($tags, JSON_UNESCAPED_UNICODE), json_encode($src), (int) ($_POST['sort'] ?? 0),
                    isset($_POST['published']) ? 1 : 0, now()]);
            set_setting('updated_at', date('d/m/Y'));
            flash($isNew ? 'Pergunta criada.' : 'Pergunta salva.');
            redirect('p=faq_edit&id=' . urlencode($id));

        case 'faq_delete':
            $pdo->prepare('DELETE FROM faq WHERE id = ?')->execute([$_POST['id']]);
            flash('Pergunta excluída.');
            redirect('p=faq');

        case 'content_save':
            $status = [];
            foreach ((array) ($_POST['status_label'] ?? []) as $i => $label) {
                if (trim($label) === '') continue;
                $status[] = ['label' => trim($label), 'value' => trim($_POST['status_value'][$i] ?? ''),
                    'detail' => trim($_POST['status_detail'][$i] ?? ''), 'tone' => $_POST['status_tone'][$i] ?? 'neutral'];
            }
            $timeline = [];
            foreach ((array) ($_POST['tl_date'] ?? []) as $i => $date) {
                if (trim($date) === '' && trim($_POST['tl_title'][$i] ?? '') === '') continue;
                $timeline[] = ['date' => trim($date), 'title' => trim($_POST['tl_title'][$i] ?? ''),
                    'text' => trim($_POST['tl_text'][$i] ?? ''), 'state' => $_POST['tl_state'][$i] ?? 'next'];
            }
            set_setting('status', $status);
            set_setting('timeline', $timeline);
            set_setting('updated_at', trim($_POST['updated_at']) ?: date('d/m/Y'));
            flash('Situação e linha do tempo salvas.');
            redirect('p=content');

        case 'advanced_save':
            $key = (string) $_POST['key'];
            $allowed = ['stats', 'amenities', 'checklist', 'glossary', 'quick_searches', 'categories', 'places_pinned'];
            $val = json_decode((string) $_POST['json'], true);
            if (!in_array($key, $allowed, true)) { flash('Item inválido.', 'err'); redirect('p=advanced'); }
            if (!is_array($val)) { flash('JSON inválido: ' . json_last_error_msg(), 'err'); redirect('p=advanced&k=' . $key); }
            set_setting($key, $val);
            flash('Salvo.');
            redirect('p=advanced&k=' . $key);

        case 'settings_save':
            set_setting('gallery_folder_id', trim((string) $_POST['gallery_folder_id']) ?: null);
            set_setting('vendor_sheet_id', trim((string) $_POST['vendor_sheet_id']) ?: null);
            flash('Configurações salvas.');
            redirect('p=settings');

        case 'places_update':
            $lat = (float) str_replace(',', '.', (string) ($_POST['lat'] ?? ''));
            $lon = (float) str_replace(',', '.', (string) ($_POST['lon'] ?? ''));
            if ($lat < -34 || $lat > 6 || $lon < -74 || $lon > -34) { flash('Coordenadas inválidas (devem estar no Brasil).', 'err'); redirect('p=settings'); }
            set_setting('places_origin', ['lat' => $lat, 'lon' => $lon, 'address' => trim((string) $_POST['address']) ?: Places::DEFAULT_ORIGIN['address']]);
            @set_time_limit(300);
            try {
                $p = Places::update();
                $n = array_sum(array_map(fn ($c) => count($c['items']), $p['categories']));
                flash("Arredores atualizados: $n lugares em " . count($p['categories']) . ' categorias. Clique em "Publicar alterações" para enviar ao site.');
            } catch (Throwable $e) {
                flash($e->getMessage(), 'err');
            }
            redirect('p=settings');
    }
}

/* =====================================================================
   Páginas
   ===================================================================== */
$counts = $pdo->query("SELECT
    SUM(review_status = 'new' AND removed = 0 AND duplicate_of IS NULL AND mime <> 'application/vnd.google-apps.folder') AS novos,
    SUM(text_status = 'scanned' AND removed = 0 AND duplicate_of IS NULL) AS escaneados,
    SUM(removed = 0 AND mime NOT LIKE 'image/%' AND mime NOT LIKE 'video/%' AND mime <> 'application/vnd.google-apps.folder' AND duplicate_of IS NULL) AS docs
    FROM docs")->fetch();
$faqCount = (int) $pdo->query('SELECT COUNT(*) FROM faq')->fetchColumn();

function status_pill(string $s): string
{
    $map = ['ok' => ['Texto extraído', 'ok'], 'manual' => ['Texto manual', 'ok'], 'scanned' => ['Escaneado: sem texto', 'warn'],
        'pending' => ['Pendente', 'muted'], 'skipped' => ['Sem texto', 'muted'], 'error' => ['Erro', 'err'],
        'new' => ['Novo', 'warn'], 'reviewed' => ['Revisado', 'ok'], 'ignored' => ['Ignorado', 'muted']];
    [$l, $c] = $map[$s] ?? [$s, 'muted'];
    return "<span class=\"pill pill-$c\">" . h($l) . '</span>';
}
function fmt_date(?string $s): string { return $s ? date('d/m/Y H:i', strtotime($s)) : '–'; }

layout_start(match ($page) { 'docs' => 'Documentos', 'doc' => 'Documento', 'faq' => 'Perguntas', 'faq_edit' => 'Pergunta',
    'content' => 'Situação e linha do tempo', 'advanced' => 'Conteúdo avançado', 'settings' => 'Configurações', default => 'Início' });

$f = flash();
if ($f) echo '<p role="status" class="alert ' . ($f[1] === 'err' ? 'err' : 'ok') . '">' . h($f[0]) . '</p>';

switch ($page) {
/* ------------------------------------------------------------------ */
case 'dashboard':
    $lastLog = $pdo->query('SELECT * FROM sync_log ORDER BY id DESC LIMIT 5')->fetchAll();
    $new = $pdo->query("SELECT * FROM docs WHERE review_status = 'new' AND removed = 0 AND duplicate_of IS NULL
                        AND mime <> 'application/vnd.google-apps.folder' ORDER BY first_seen DESC LIMIT 50")->fetchAll();
    ?>
    <div class="cards">
        <div class="card"><strong><?= (int) $counts['novos'] ?></strong><span>arquivos para revisar</span></div>
        <div class="card"><strong><?= (int) $counts['docs'] ?></strong><span>documentos indexados</span></div>
        <div class="card"><strong><?= $faqCount ?></strong><span>perguntas</span></div>
        <div class="card"><strong><?= (int) $counts['escaneados'] ?></strong><span>escaneados sem texto</span></div>
    </div>

    <?php if (is_local_git()): $pend = pending_changes(); ?>
    <section class="box publish-box <?= $pend ? 'has-changes' : '' ?>">
        <div class="box-head">
            <div>
                <h2>Publicar no site</h2>
                <p class="muted" style="margin:4px 0 0">
                    <?= $pend ? '<strong>' . count($pend) . ' arquivo(s) de conteúdo alterado(s)</strong> ainda não publicado(s).' : 'Tudo publicado. O botão também traz as atualizações feitas pelo robô diário.' ?>
                </p>
            </div>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="publish">
                <button class="btn" onclick="this.disabled=true;this.textContent='Publicando…';this.form.submit()"><?= $pend ? 'Publicar alterações' : 'Atualizar do GitHub' ?></button>
            </form>
        </div>
    </section>
    <?php endif; ?>

    <section class="box">
        <div class="box-head">
            <h2>Sincronização com o Drive</h2>
            <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sync">
                <button class="btn" onclick="this.disabled=true;this.textContent='Sincronizando… (pode levar 1–2 min)';this.form.submit()">Sincronizar agora</button>
            </form>
        </div>
        <p class="muted">Última sincronização: <strong><?= h(fmt_date(setting('last_sync'))) ?></strong>. O cron faz isso automaticamente.</p>
        <?php $obra = setting('obra_progress'); $obraStale = $obra && strtotime($obra['checked_at']) < strtotime('-3 days'); ?>
        <p class="muted">Acompanhamento da obra (site da Cury):
            <?php if ($obra): ?>
                <strong>média <?= h((string) $obra['average']) ?>%</strong> · verificado em <?= h(fmt_date($obra['checked_at'])) ?> · última mudança em <?= h(fmt_date($obra['changed_at'])) ?>
                <?php if ($obraStale): ?><span class="pill pill-warn">sem leitura há mais de 3 dias: clique em "Publicar / Atualizar do GitHub" para ler daqui</span><?php endif; ?>
            <?php else: ?>
                <span class="pill pill-muted">ainda não lido</span>
            <?php endif; ?>
        </p>
        <?php if ($lastLog): ?>
        <table><thead><tr><th>Início</th><th>Resultado</th><th>Resumo</th></tr></thead><tbody>
        <?php foreach ($lastLog as $l): $s = json_decode($l['summary'], true) ?: []; ?>
            <tr><td><?= h(fmt_date($l['started_at'])) ?></td>
                <td><?= $l['ok'] ? '<span class="pill pill-ok">OK</span>' : '<span class="pill pill-err">Falhou</span>' ?></td>
                <td class="small"><?= h(isset($s['erro_geral']) ? $s['erro_geral'] : "novos {$s['novos']}, alterados {$s['alterados']}, removidos {$s['removidos']}, textos {$s['textos']}, escaneados {$s['escaneados']}, erros {$s['erros']}") ?></td></tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
    </section>

    <section class="box">
        <h2>Arquivos novos ou alterados para revisar</h2>
        <?php if (!$new): ?>
            <p class="muted">Nada para revisar. 🎉</p>
        <?php else: ?>
        <p class="muted">Eles já aparecem em "Novidades" e na busca do site. Revise para decidir se merecem uma pergunta nova ou uma atualização.</p>
        <table><thead><tr><th>Arquivo</th><th>Pasta</th><th>Chegou em</th><th>Texto</th><th></th></tr></thead><tbody>
        <?php foreach ($new as $d): ?>
            <tr>
                <td><a href="?p=doc&id=<?= urlencode($d['id']) ?>"><?= h(doc_title($d)) ?></a></td>
                <td class="small"><?= h($d['folder_path']) ?></td>
                <td class="small"><?= h(fmt_date($d['first_seen'])) ?></td>
                <td><?= status_pill($d['text_status']) ?></td>
                <td class="actions">
                    <a class="btn btn-sm" href="?p=faq_edit&src=<?= urlencode($d['id']) ?>">+ Pergunta</a>
                    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="doc_review"><input type="hidden" name="id" value="<?= h($d['id']) ?>"><input type="hidden" name="status" value="reviewed">
                        <button class="btn btn-sm btn-ghost">Marcar revisado</button></form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
        <?php endif; ?>
    </section>
    <?php
    break;

/* ------------------------------------------------------------------ */
case 'docs':
    $showMedia = !empty($_GET['media']);
    $sql = "SELECT * FROM docs WHERE removed = 0 AND mime <> 'application/vnd.google-apps.folder'"
        . ($showMedia ? '' : " AND mime NOT LIKE 'image/%' AND mime NOT LIKE 'video/%'")
        . ' ORDER BY folder_path, name';
    ?>
    <section class="box">
        <div class="box-head"><h2>Documentos do Drive</h2>
            <a class="btn btn-ghost btn-sm" href="?p=docs<?= $showMedia ? '' : '&media=1' ?>"><?= $showMedia ? 'Ocultar fotos e vídeos' : 'Mostrar fotos e vídeos' ?></a></div>
        <table><thead><tr><th>Título</th><th>Pasta</th><th>Texto</th><th>Revisão</th><th>No site</th></tr></thead><tbody>
        <?php foreach ($pdo->query($sql) as $d): ?>
            <tr class="<?= $d['duplicate_of'] ? 'dim' : '' ?>">
                <td><a href="?p=doc&id=<?= urlencode($d['id']) ?>"><?= h(doc_title($d)) ?></a>
                    <?= $d['duplicate_of'] ? '<span class="pill pill-muted">duplicado</span>' : '' ?>
                    <div class="small muted"><?= h($d['name']) ?></div></td>
                <td class="small"><?= h($d['folder_path']) ?></td>
                <td><?= status_pill($d['text_status']) ?></td>
                <td><?= status_pill($d['review_status']) ?></td>
                <td><?= $d['is_public'] ? 'Sim' : 'Não' ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
    </section>
    <?php
    break;

/* ------------------------------------------------------------------ */
case 'doc':
    $st = $pdo->prepare('SELECT * FROM docs WHERE id = ?');
    $st->execute([$_GET['id'] ?? '']);
    $d = $st->fetch();
    if (!$d) { echo '<p class="alert err">Documento não encontrado.</p>'; break; }
    $chunks = $pdo->prepare('SELECT text FROM chunks WHERE doc_id = ? ORDER BY n');
    $chunks->execute([$d['id']]);
    $text = implode("\n\n", $chunks->fetchAll(PDO::FETCH_COLUMN));
    $usedBy = $pdo->query('SELECT id, q FROM faq WHERE src LIKE ' . $pdo->quote('%"' . $d['id'] . '"%'))->fetchAll();
    ?>
    <p><a href="?p=docs">← Documentos</a></p>
    <section class="box">
        <div class="box-head"><h2><?= h(doc_title($d)) ?></h2>
            <div class="actions"><a class="btn btn-ghost btn-sm" href="<?= h(drive_url($d)) ?>" target="_blank" rel="noopener">Abrir no Drive</a>
                <a class="btn btn-sm" href="?p=faq_edit&src=<?= urlencode($d['id']) ?>">+ Criar pergunta com esta fonte</a></div></div>
        <p class="small muted">Arquivo: <?= h($d['name']) ?> · Pasta: <?= h($d['folder_path']) ?> · Modificado: <?= h(fmt_date($d['modified_time'])) ?> · Visto pela 1ª vez: <?= h(fmt_date($d['first_seen'])) ?></p>
        <?php if ($d['duplicate_of']): ?><p class="alert warn">Este arquivo é uma cópia idêntica de outro e não é indexado.</p><?php endif; ?>

        <form method="post" class="form">
            <?= csrf_field() ?><input type="hidden" name="action" value="doc_save"><input type="hidden" name="id" value="<?= h($d['id']) ?>">
            <div class="grid2">
                <label>Título exibido no site<input name="title" value="<?= h($d['title'] ?: doc_title($d)) ?>"></label>
                <label>Tipo
                    <select name="doc_type">
                        <?php foreach (['', 'Oficial', 'Comissão', 'Comercial', 'Colaborativo'] as $t): ?>
                            <option value="<?= h($t) ?>" <?= $d['doc_type'] === $t ? 'selected' : '' ?>><?= $t ?: '(nenhum)' ?></option>
                        <?php endforeach; ?>
                    </select></label>
            </div>
            <div class="grid2">
                <label>Descrição curta<input name="description" value="<?= h($d['description']) ?>"></label>
                <label>Data do documento (ex.: 02/10/2023 ou 08/2026)<input name="doc_date" value="<?= h($d['doc_date']) ?>" placeholder="vazio = data de envio ao Drive"></label>
            </div>
            <div class="grid2">
                <label>Revisão
                    <select name="review_status">
                        <?php foreach (['new' => 'Novo (para revisar)', 'reviewed' => 'Revisado', 'ignored' => 'Ignorado (some do site)'] as $k => $l): ?>
                            <option value="<?= $k ?>" <?= $d['review_status'] === $k ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select></label>
                <label class="check"><input type="checkbox" name="is_public" <?= $d['is_public'] ? 'checked' : '' ?>> Mostrar no site e na busca</label>
            </div>

            <h3>Texto indexado para a busca <?= status_pill($d['text_status']) ?></h3>
            <?php if ($d['text_status'] === 'scanned'): ?>
                <p class="alert warn">PDF escaneado: não há texto para extrair automaticamente. Cole abaixo o texto (digitado ou de um OCR) para que ele apareça na busca.</p>
            <?php elseif ($d['text_error']): ?>
                <p class="alert err"><?= h($d['text_error']) ?></p>
            <?php endif; ?>
            <label>Texto (CPF, RG, e-mails e IPs são ocultados automaticamente)
                <textarea name="manual_text" rows="12"><?= h($text) ?></textarea></label>
            <div class="actions">
                <button class="btn" name="save">Salvar dados</button>
                <button class="btn btn-ghost" name="save_text" value="1">Salvar dados e o texto acima</button>
                <button class="btn btn-ghost" name="reextract" value="1">Extrair texto de novo do Drive</button>
            </div>
        </form>
    </section>
    <?php if ($usedBy): ?>
    <section class="box"><h2>Perguntas que citam este documento</h2><ul>
        <?php foreach ($usedBy as $u): ?><li><a href="?p=faq_edit&id=<?= urlencode($u['id']) ?>"><?= h($u['q']) ?></a></li><?php endforeach; ?>
    </ul></section>
    <?php endif;
    break;

/* ------------------------------------------------------------------ */
case 'faq':
    $cat = $_GET['cat'] ?? '';
    $qq = trim((string) ($_GET['q'] ?? ''));
    $rows = $pdo->query('SELECT * FROM faq ORDER BY sort, rowid')->fetchAll();
    $rows = array_filter($rows, fn ($r) => ($cat === '' || $r['cat'] === $cat)
        && ($qq === '' || str_contains(Text::normalize($r['q'] . ' ' . strip_tags($r['a'])), Text::normalize($qq))));
    ?>
    <section class="box">
        <div class="box-head"><h2>Perguntas (<?= count($rows) ?>)</h2><a class="btn" href="?p=faq_edit">+ Nova pergunta</a></div>
        <form class="filters" method="get"><input type="hidden" name="p" value="faq">
            <input name="q" value="<?= h($qq) ?>" placeholder="Buscar…">
            <select name="cat" onchange="this.form.submit()"><option value="">Todas as categorias</option>
                <?php foreach ($CATS as $c) if ($c['id'] !== 'todas'): ?><option value="<?= h($c['id']) ?>" <?= $cat === $c['id'] ? 'selected' : '' ?>><?= h($c['label']) ?></option><?php endif; ?>
            </select><button class="btn btn-ghost btn-sm">Filtrar</button></form>
        <table><thead><tr><th>Pergunta</th><th>Categoria</th><th>Selo</th><th>Publicada</th><th>Atualizada</th></tr></thead><tbody>
        <?php foreach ($rows as $r): ?>
            <tr class="<?= $r['published'] ? '' : 'dim' ?>">
                <td><a href="?p=faq_edit&id=<?= urlencode($r['id']) ?>"><?= h($r['q']) ?></a></td>
                <td class="small"><?= h($CAT_LABEL[$r['cat']] ?? $r['cat']) ?></td>
                <td><?= h(['oficial' => 'Oficial', 'informado' => 'Informado', 'pendente' => 'Pendente'][$r['status']] ?? $r['status']) ?></td>
                <td><?= $r['published'] ? 'Sim' : 'Não' ?></td>
                <td class="small"><?= h(fmt_date($r['updated_at'])) ?></td>
            </tr>
        <?php endforeach; ?>
        </tbody></table>
    </section>
    <?php
    break;

/* ------------------------------------------------------------------ */
case 'faq_edit':
    $f = ['id' => '', 'cat' => 'entrega', 'status' => 'oficial', 'q' => '', 'a' => '', 'tags' => '[]', 'src' => '[]', 'sort' => 999, 'published' => 1];
    if (!empty($_GET['id'])) {
        $st = $pdo->prepare('SELECT * FROM faq WHERE id = ?');
        $st->execute([$_GET['id']]);
        $f = $st->fetch() ?: $f;
    }
    $src = json_decode($f['src'], true) ?: [];
    if (!empty($_GET['src'])) $src[] = $_GET['src'];
    $docs = $pdo->query("SELECT * FROM docs WHERE removed = 0 AND duplicate_of IS NULL AND mime NOT LIKE 'image/%' AND mime NOT LIKE 'video/%'
                         AND mime <> 'application/vnd.google-apps.folder' ORDER BY COALESCE(title, name)")->fetchAll();
    // fontes que apontam para cópias duplicadas: mostra o original marcado
    $dupOf = $pdo->query('SELECT id, duplicate_of FROM docs WHERE duplicate_of IS NOT NULL')->fetchAll(PDO::FETCH_KEY_PAIR);
    $src = array_map(fn ($id) => $dupOf[$id] ?? $id, $src);
    ?>
    <p><a href="?p=faq">← Perguntas</a></p>
    <section class="box">
        <h2><?= $f['id'] ? 'Editar pergunta' : 'Nova pergunta' ?></h2>
        <form method="post" class="form">
            <?= csrf_field() ?><input type="hidden" name="action" value="faq_save"><input type="hidden" name="id" value="<?= h($f['id']) ?>">
            <label>Pergunta<input name="q" value="<?= h($f['q']) ?>" required maxlength="200" placeholder="Ex.: Posso instalar ar-condicionado?"></label>
            <div class="grid3">
                <label>Categoria<select name="cat">
                    <?php foreach ($CATS as $c) if ($c['id'] !== 'todas'): ?><option value="<?= h($c['id']) ?>" <?= $f['cat'] === $c['id'] ? 'selected' : '' ?>><?= h($c['icon'] . ' ' . $c['label']) ?></option><?php endif; ?>
                </select></label>
                <label>Selo<select name="status">
                    <?php foreach (['oficial' => 'Oficial (documento ou resposta formal da Cury)', 'informado' => 'Informado em visita (não oficial)', 'pendente' => 'Pendente (sem resposta definitiva)'] as $k => $l): ?>
                        <option value="<?= $k ?>" <?= $f['status'] === $k ? 'selected' : '' ?>><?= $l ?></option>
                    <?php endforeach; ?>
                </select></label>
                <label>Ordem<input type="number" name="sort" value="<?= (int) $f['sort'] ?>"></label>
            </div>
            <label>Resposta
                <div class="toolbar" data-target="answer">
                    <button type="button" data-wrap="strong">Negrito</button>
                    <button type="button" data-wrap="p">Parágrafo</button>
                    <button type="button" data-list="ul">Lista</button>
                    <button type="button" data-list="ol">Lista numerada</button>
                    <button type="button" data-link>Link</button>
                </div>
                <textarea id="answer" name="a" rows="12" required><?= h($f['a']) ?></textarea>
                <span class="small muted">Aceita HTML simples: &lt;p&gt;, &lt;strong&gt;, &lt;ul&gt;&lt;li&gt;, &lt;a href&gt;, tabelas. Scripts são removidos.</span>
            </label>
            <label>Palavras-chave para a busca (separadas por vírgula)
                <input name="tags" value="<?= h(implode(', ', json_decode($f['tags'], true) ?: [])) ?>" placeholder="ex.: varanda, sacada, vidro"></label>
            <fieldset><legend>Fontes</legend>
                <div class="src-list">
                <?php foreach ($docs as $d): ?>
                    <label class="check"><input type="checkbox" name="src[]" value="<?= h($d['id']) ?>" <?= in_array($d['id'], $src, true) ? 'checked' : '' ?>> <?= h(doc_title($d)) ?></label>
                <?php endforeach; ?>
                </div>
            </fieldset>
            <label class="check"><input type="checkbox" name="published" <?= $f['published'] ? 'checked' : '' ?>> Publicada no site</label>
            <div class="actions"><button class="btn">Salvar</button></div>
        </form>
        <?php if ($f['id']): ?>
        <form method="post" onsubmit="return confirm('Excluir esta pergunta?')" class="danger-zone">
            <?= csrf_field() ?><input type="hidden" name="action" value="faq_delete"><input type="hidden" name="id" value="<?= h($f['id']) ?>">
            <button class="btn btn-danger btn-sm">Excluir pergunta</button>
            <a class="btn btn-ghost btn-sm" href="../index.html#faq-<?= h($f['id']) ?>" target="_blank">Ver no site</a>
        </form>
        <?php endif; ?>
    </section>
    <script>
    document.querySelectorAll('.toolbar button').forEach(b => b.addEventListener('click', () => {
        const ta = document.getElementById('answer');
        const [s, e] = [ta.selectionStart, ta.selectionEnd];
        const sel = ta.value.slice(s, e);
        let out = sel;
        if (b.dataset.wrap) out = `<${b.dataset.wrap}>${sel || 'texto'}</${b.dataset.wrap}>`;
        if (b.dataset.list) out = `<${b.dataset.list}>\n` + (sel || 'item 1\nitem 2').split('\n').filter(Boolean).map(l => `  <li>${l}</li>`).join('\n') + `\n</${b.dataset.list}>`;
        if (b.dataset.link !== undefined) { const u = prompt('Endereço do link (https://...)'); if (!u) return; out = `<a href="${u}">${sel || u}</a>`; }
        ta.setRangeText(out, s, e, 'end'); ta.focus();
    }));
    </script>
    <?php
    break;

/* ------------------------------------------------------------------ */
case 'content':
    $status = setting('status', []);
    while (count($status) < 4) $status[] = ['label' => '', 'value' => '', 'detail' => '', 'tone' => 'neutral'];
    $timeline = setting('timeline', []);
    $timeline[] = ['date' => '', 'title' => '', 'text' => '', 'state' => 'next'];
    $timeline[] = ['date' => '', 'title' => '', 'text' => '', 'state' => 'next'];
    $tones = ['ok' => 'Verde (concluído)', 'warn' => 'Laranja (previsão)', 'neutral' => 'Azul (indefinido)'];
    $states = ['done' => 'Concluído', 'current' => 'Em andamento', 'next' => 'Próximo'];
    ?>
    <form method="post" class="form">
        <?= csrf_field() ?><input type="hidden" name="action" value="content_save">
        <section class="box">
            <h2>Situação atual (topo do site)</h2>
            <label class="narrow">Data de atualização exibida<input name="updated_at" value="<?= h(setting('updated_at', date('d/m/Y'))) ?>"></label>
            <table class="edit"><thead><tr><th>Título</th><th>Valor</th><th>Detalhe</th><th>Cor</th></tr></thead><tbody>
            <?php foreach ($status as $s): ?>
                <tr><td><input name="status_label[]" value="<?= h($s['label']) ?>"></td>
                    <td><input name="status_value[]" value="<?= h($s['value']) ?>"></td>
                    <td><input name="status_detail[]" value="<?= h($s['detail']) ?>"></td>
                    <td><select name="status_tone[]"><?php foreach ($tones as $k => $l): ?><option value="<?= $k ?>" <?= $s['tone'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </section>
        <section class="box">
            <h2>Linha do tempo</h2>
            <p class="small muted">Para remover um item, apague a data e o título. Duas linhas em branco no final servem para itens novos.</p>
            <table class="edit"><thead><tr><th style="width:150px">Data</th><th>Título</th><th>Descrição</th><th style="width:150px">Estado</th></tr></thead><tbody>
            <?php foreach ($timeline as $t): ?>
                <tr><td><input name="tl_date[]" value="<?= h($t['date']) ?>"></td>
                    <td><input name="tl_title[]" value="<?= h($t['title']) ?>"></td>
                    <td><input name="tl_text[]" value="<?= h($t['text']) ?>"></td>
                    <td><select name="tl_state[]"><?php foreach ($states as $k => $l): ?><option value="<?= $k ?>" <?= $t['state'] === $k ? 'selected' : '' ?>><?= $l ?></option><?php endforeach; ?></select></td></tr>
            <?php endforeach; ?>
            </tbody></table>
        </section>
        <div class="actions"><button class="btn">Salvar situação e linha do tempo</button></div>
    </form>
    <?php
    break;

/* ------------------------------------------------------------------ */
case 'advanced':
    $labels = ['stats' => 'Números', 'amenities' => 'Lazer', 'checklist' => 'Checklist da vistoria', 'glossary' => 'Glossário',
        'quick_searches' => 'Buscas rápidas', 'categories' => 'Categorias', 'places_pinned' => 'Locais fixos'];
    $k = $_GET['k'] ?? 'amenities';
    if (!isset($labels[$k])) $k = 'amenities';
    if ($k === 'places_pinned' && setting('places_pinned') === null) set_setting('places_pinned', Places::pinned());
    ?>
    <section class="box">
        <h2>Conteúdo avançado</h2>
        <p class="small muted">Edição direta em JSON. Cuidado com vírgulas e aspas: se o JSON estiver inválido, nada é salvo.</p>
        <nav class="tabs"><?php foreach ($labels as $key => $l): ?><a class="<?= $key === $k ? 'active' : '' ?>" href="?p=advanced&k=<?= $key ?>"><?= $l ?></a><?php endforeach; ?></nav>
        <form method="post" class="form">
            <?= csrf_field() ?><input type="hidden" name="action" value="advanced_save"><input type="hidden" name="key" value="<?= $k ?>">
            <textarea name="json" rows="24" class="mono"><?= h(json_encode(setting($k, []), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)) ?></textarea>
            <div class="actions"><button class="btn">Salvar <?= h($labels[$k]) ?></button></div>
        </form>
    </section>
    <?php
    break;

/* ------------------------------------------------------------------ */
case 'settings':
    $folders = $pdo->query("SELECT DISTINCT folder_id, folder_path FROM docs WHERE removed = 0 AND (mime LIKE 'image/%' OR mime LIKE 'video/%') ORDER BY folder_path")->fetchAll();
    $sheets = $pdo->query("SELECT id, name FROM docs WHERE removed = 0 AND mime = 'application/vnd.google-apps.spreadsheet'")->fetchAll();
    ?>
    <section class="box">
        <h2>Configurações</h2>
        <form method="post" class="form">
            <?= csrf_field() ?><input type="hidden" name="action" value="settings_save">
            <label>Álbum que aparece primeiro na galeria
                <select name="gallery_folder_id"><option value="">Automático (o mais recente primeiro)</option>
                    <?php foreach ($folders as $fo): ?><option value="<?= h($fo['folder_id']) ?>" <?= setting('gallery_folder_id') === $fo['folder_id'] ? 'selected' : '' ?>><?= h($fo['folder_path']) ?></option><?php endforeach; ?>
                </select></label>
            <label>Planilha de fornecedores (sincronizada a cada execução do cron)
                <select name="vendor_sheet_id"><option value="">(nenhuma)</option>
                    <?php foreach ($sheets as $s): ?><option value="<?= h($s['id']) ?>" <?= setting('vendor_sheet_id') === $s['id'] ? 'selected' : '' ?>><?= h($s['name']) ?></option><?php endforeach; ?>
                </select></label>
            <div class="actions"><button class="btn">Salvar</button></div>
        </form>
        <h3>Pastas monitoradas</h3>
        <ul class="small"><?php foreach ((array) config('drive_folders', []) as $id): ?><li><a href="https://drive.google.com/drive/folders/<?= h($id) ?>" target="_blank" rel="noopener"><?= h($id) ?></a></li><?php endforeach; ?></ul>
        <p class="small muted">Para incluir outras pastas, edite <code>drive_folders</code> em <code>app/config.php</code>. As pastas precisam estar compartilhadas como "Qualquer pessoa com o link".</p>
    </section>

    <?php $origin = Places::origin(); $places = setting('places'); ?>
    <section class="box">
        <h2>Arredores (o que tem perto)</h2>
        <p class="small muted">Lugares próximos vindos do OpenStreetMap: metrô, ônibus, mercados, escolas, saúde etc.
            Como os servidores públicos pedem uso ocasional, a atualização é manual. Uma vez por mês já basta.
            <?php if ($places): ?>Última atualização: <strong><?= h(fmt_date($places['updated_at'])) ?></strong>.<?php endif; ?></p>
        <form method="post" class="form">
            <?= csrf_field() ?><input type="hidden" name="action" value="places_update">
            <label>Endereço (usado no "Como chegar" do Google Maps)<input name="address" value="<?= h($origin['address']) ?>"></label>
            <div class="grid2">
                <label>Latitude do ponto de origem<input name="lat" value="<?= h((string) $origin['lat']) ?>" inputmode="decimal"></label>
                <label>Longitude do ponto de origem<input name="lon" value="<?= h((string) $origin['lon']) ?>" inputmode="decimal"></label>
            </div>
            <p class="small muted">Dica: no Google Maps, clique com o botão direito no portão do condomínio e copie as coordenadas (ex.: <code>-23.6594, -46.7251</code>).</p>
            <div class="actions"><button class="btn" onclick="this.disabled=true;this.textContent='Consultando o OpenStreetMap… (até 2 min)';this.form.submit()">Atualizar arredores</button></div>
        </form>
    </section>
    <?php
    break;
}

layout_end();

/* =====================================================================
   Layout
   ===================================================================== */
function layout_start(string $title, bool $nav = true): void
{
    global $page, $counts;
    ?><!DOCTYPE html>
<html lang="pt-BR"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow">
<title><?= h($title) ?> · Painel · Guido Parque</title>
<link rel="icon" type="image/svg+xml" href="<?= ADMIN_BASE ?>../img/favicon.svg">
<link rel="stylesheet" href="<?= ADMIN_BASE ?>admin.css"></head><body class="<?= $nav ? '' : 'is-login' ?>">
<?php if ($nav): ?>
<header class="top"><div class="wrap">
    <span class="logo"><img src="<?= ADMIN_BASE ?>../img/logo.svg" alt="Cury" height="22"> <span>Painel da Comissão</span></span>
    <nav>
        <a href="index.php" class="<?= $page === 'dashboard' ? 'on' : '' ?>">Início<?= !empty($counts['novos']) ? ' <span class="badge">' . (int) $counts['novos'] . '</span>' : '' ?></a>
        <a href="?p=faq" class="<?= in_array($page, ['faq', 'faq_edit'], true) ? 'on' : '' ?>">Perguntas</a>
        <a href="?p=docs" class="<?= in_array($page, ['docs', 'doc'], true) ? 'on' : '' ?>">Documentos</a>
        <a href="?p=content" class="<?= $page === 'content' ? 'on' : '' ?>">Situação e linha do tempo</a>
        <a href="?p=advanced" class="<?= $page === 'advanced' ? 'on' : '' ?>">Avançado</a>
        <a href="?p=settings" class="<?= $page === 'settings' ? 'on' : '' ?>">Configurações</a>
    </nav>
    <span class="right"><a href="../index.html" target="_blank">Ver site ↗</a> · <a href="?p=logout">Sair</a></span>
</div></header>
<?php endif; ?>
<main class="wrap">
<?php
}

function layout_end(): void
{
    echo '</main></body></html>';
}
