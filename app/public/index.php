<?php
session_start();

const DB_FILE = '/data/admin.sqlite';
const CONF_FILE = '/data/cdn-location.conf';
const STATS_FILE = '/data/stats.json';

function db(): PDO {
    static $pdo = null;
    if ($pdo) return $pdo;
    $pdo = new PDO('sqlite:' . DB_FILE);
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $pdo->exec("CREATE TABLE IF NOT EXISTS settings (
        id INTEGER PRIMARY KEY CHECK (id = 1),
        origin TEXT NOT NULL DEFAULT 'https://example.com',
        host_header TEXT NOT NULL DEFAULT '',
        cache_size_gb INTEGER NOT NULL DEFAULT 20,
        cache_ttl_200 TEXT NOT NULL DEFAULT '60m',
        cache_ttl_404 TEXT NOT NULL DEFAULT '1m',
        stale_enabled INTEGER NOT NULL DEFAULT 1,
        ignore_query_string INTEGER NOT NULL DEFAULT 0,
        cors_enabled INTEGER NOT NULL DEFAULT 0,
        hotlink_protection INTEGER NOT NULL DEFAULT 0,
        allowed_referers TEXT NOT NULL DEFAULT '',
        custom_headers TEXT NOT NULL DEFAULT '',
        enabled INTEGER NOT NULL DEFAULT 0,
        updated_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    )");
    $pdo->exec("INSERT OR IGNORE INTO settings (id) VALUES (1)");
    return $pdo;
}

function settings(): array {
    return db()->query('SELECT * FROM settings WHERE id=1')->fetch(PDO::FETCH_ASSOC);
}

function h($v): string { return htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8'); }
function csrf(): string { $_SESSION['csrf'] ??= bin2hex(random_bytes(16)); return $_SESSION['csrf']; }
function check_csrf(): void { if (($_POST['csrf'] ?? '') !== ($_SESSION['csrf'] ?? '')) { http_response_code(419); exit('CSRF error'); } }
function is_auth(): bool { return !empty($_SESSION['auth']); }
function require_auth(): void { if (!is_auth()) { header('Location: /admin/login'); exit; } }

function valid_duration(string $v): bool { return preg_match('/^[0-9]+[smhd]$/', $v) === 1; }
function normalize_origin(string $origin): string {
    $origin = rtrim(trim($origin), '/');
    if (!preg_match('#^https?://#', $origin)) throw new RuntimeException('Источник должен начинаться с http:// или https://');
    if (!filter_var($origin, FILTER_VALIDATE_URL)) throw new RuntimeException('Некорректный URL источника');
    return $origin;
}
function safe_host(string $host): string {
    $host = trim($host);
    if ($host === '') return '';
    if (!preg_match('/^[a-zA-Z0-9.-]+(:[0-9]+)?$/', $host)) throw new RuntimeException('Некорректный Host header');
    return $host;
}
function nginx_escape(string $s): string { return str_replace(["\\", "'", "\n", "\r"], ["\\\\", "\\'", '', ''], $s); }

function write_nginx_config(array $s): void {
    $size = max(1, (int)($s['cache_size_gb'] ?? 20));
    file_put_contents('/data/cache-path.conf', "proxy_cache_path /var/cache/nginx/kubit levels=1:2 keys_zone=kubit_cache:256m max_size={$size}g inactive=7d use_temp_path=off;\n");
    $origin = nginx_escape($s['origin']);
    $host = $s['host_header'] !== '' ? nginx_escape($s['host_header']) : '$proxy_host';
    $ttl200 = $s['cache_ttl_200'];
    $ttl404 = $s['cache_ttl_404'];
    $cacheKey = ((int)$s['ignore_query_string'] === 1) ? '$scheme$proxy_host$uri' : '$scheme$proxy_host$request_uri';
    $stale = ((int)$s['stale_enabled'] === 1) ? 'proxy_cache_use_stale error timeout updating http_500 http_502 http_503 http_504;' : '';
    $cors = ((int)$s['cors_enabled'] === 1) ? "add_header Access-Control-Allow-Origin * always;\n        add_header Access-Control-Allow-Methods 'GET, HEAD, OPTIONS' always;" : '';
    $enabled = ((int)$s['enabled'] === 1);

    $refererBlock = '';
    if ((int)$s['hotlink_protection'] === 1 && trim($s['allowed_referers']) !== '') {
        $refs = preg_split('/\s+/', trim($s['allowed_referers']));
        $refs = array_map('nginx_escape', $refs);
        $refererBlock = "valid_referers none blocked " . implode(' ', $refs) . ";\n        if (\$invalid_referer) { return 403; }";
    }

    $custom = '';
    foreach (preg_split('/\R/', (string)$s['custom_headers']) as $line) {
        $line = trim($line);
        if ($line === '') continue;
        if (!preg_match('/^[A-Za-z0-9-]+:\s*.+$/', $line)) continue;
        [$k, $v] = explode(':', $line, 2);
        $custom .= "add_header " . nginx_escape(trim($k)) . " '" . nginx_escape(trim($v)) . "' always;\n        ";
    }

    if (!$enabled) {
        $conf = "return 503 'CDN node is disabled. Open /admin/ to enable it.\\n';\n";
    } else {
        $conf = <<<NGINX
set \$cdn_origin '$origin';

proxy_http_version 1.1;
proxy_set_header Host $host;
proxy_set_header X-Real-IP \$remote_addr;
proxy_set_header X-Forwarded-For \$proxy_add_x_forwarded_for;
proxy_set_header X-Forwarded-Proto \$scheme;
proxy_set_header Connection '';

proxy_cache kubit_cache;
proxy_cache_key $cacheKey;
proxy_cache_methods GET HEAD;
proxy_cache_min_uses 1;
proxy_cache_lock on;
proxy_cache_lock_timeout 10s;
proxy_cache_valid 200 301 302 $ttl200;
proxy_cache_valid 404 $ttl404;
$stale

add_header X-Kubit-CDN 'edge' always;
add_header X-Cache-Status \$upstream_cache_status always;
$cors
$custom
$refererBlock

proxy_pass \$cdn_origin;
NGINX;
    }
    file_put_contents(CONF_FILE, $conf);
}

function reload_nginx(): array {
    $out = [];
    $code = 0;
    exec('/bin/sh /var/www/admin/reload_nginx.sh 2>&1', $out, $code);
    return [$code === 0, implode("\n", $out)];
}

function ensure_config(): void {
    if (!file_exists(CONF_FILE)) write_nginx_config(settings());
}
ensure_config();

$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH) ?: '/admin/';
$error = '';
$notice = '';

if ($path === '/admin/login' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    $u = $_POST['user'] ?? '';
    $p = $_POST['password'] ?? '';
    if ($u === getenv('ADMIN_USER') && $p === getenv('ADMIN_PASSWORD')) {
        $_SESSION['auth'] = true;
        header('Location: /admin/'); exit;
    }
    $error = 'Неверный логин или пароль';
}
if ($path === '/admin/logout') { session_destroy(); header('Location: /admin/login'); exit; }

if ($path === '/admin/save' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_auth(); check_csrf();
    try {
        $origin = normalize_origin($_POST['origin'] ?? '');
        $host = safe_host($_POST['host_header'] ?? '');
        $ttl200 = trim($_POST['cache_ttl_200'] ?? '60m');
        $ttl404 = trim($_POST['cache_ttl_404'] ?? '1m');
        if (!valid_duration($ttl200) || !valid_duration($ttl404)) throw new RuntimeException('TTL должен быть в формате 60s, 10m, 12h или 7d');
        $stmt = db()->prepare('UPDATE settings SET origin=?, host_header=?, cache_size_gb=?, cache_ttl_200=?, cache_ttl_404=?, stale_enabled=?, ignore_query_string=?, cors_enabled=?, hotlink_protection=?, allowed_referers=?, custom_headers=?, enabled=?, updated_at=CURRENT_TIMESTAMP WHERE id=1');
        $stmt->execute([
            $origin, $host, max(1, (int)($_POST['cache_size_gb'] ?? 20)), $ttl200, $ttl404,
            isset($_POST['stale_enabled']) ? 1 : 0,
            isset($_POST['ignore_query_string']) ? 1 : 0,
            isset($_POST['cors_enabled']) ? 1 : 0,
            isset($_POST['hotlink_protection']) ? 1 : 0,
            trim($_POST['allowed_referers'] ?? ''), trim($_POST['custom_headers'] ?? ''),
            isset($_POST['enabled']) ? 1 : 0
        ]);
        write_nginx_config(settings());
        [$ok, $msg] = reload_nginx();
        $notice = $ok ? 'Настройки сохранены, nginx перечитал конфигурацию.' : 'Настройки сохранены, но nginx не перезагрузился: ' . $msg;
    } catch (Throwable $e) { $error = $e->getMessage(); }
    $path = '/admin/';
}

if ($path === '/admin/purge' && $_SERVER['REQUEST_METHOD'] === 'POST') {
    require_auth(); check_csrf();
    exec('rm -rf /var/cache/nginx/kubit/* 2>&1', $out, $code);
    $notice = $code === 0 ? 'Кеш очищен.' : 'Ошибка очистки кеша: ' . implode("\n", $out);
    $path = '/admin/';
}

if ($path === '/admin/login') render_login($error);
else { require_auth(); render_dashboard(settings(), $error, $notice); }

function render_head(string $title): void { ?>
<!doctype html><html lang="ru"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?=h($title)?></title><link rel="stylesheet" href="/admin/assets/style.css"></head><body>
<?php }
function render_login(string $error): void { render_head('Кубит CDN — вход'); ?>
<div class="login-wrap"><form class="card login" method="post"><div class="brand">Кубит <span>CDN</span></div><h1>Вход в админку</h1><?php if($error): ?><div class="alert err"><?=h($error)?></div><?php endif; ?><label>Логин<input name="user" autocomplete="username"></label><label>Пароль<input name="password" type="password" autocomplete="current-password"></label><button>Войти</button></form></div></body></html><?php exit; }
function render_dashboard(array $s, string $error, string $notice): void { render_head('Кубит CDN — панель управления'); ?>
<header><div class="brand">Кубит <span>CDN</span></div><nav><a href="/health">health</a><a href="/admin/logout">выход</a></nav></header>
<main>
<section class="hero"><div><p class="eyebrow">edge node</p><h1>CDN-сервер для кеширования контента</h1><p>Укажи origin-сервер, TTL и режимы кеширования. После сохранения нода сразу начинает отдавать контент через nginx proxy cache.</p></div><div class="status <?=((int)$s['enabled']===1?'on':'off')?>"><?=((int)$s['enabled']===1?'Работает':'Остановлен')?></div></section>
<?php if($error): ?><div class="alert err"><?=nl2br(h($error))?></div><?php endif; ?><?php if($notice): ?><div class="alert ok"><?=nl2br(h($notice))?></div><?php endif; ?>
<form class="grid" method="post" action="/admin/save"><input type="hidden" name="csrf" value="<?=csrf()?>">
<div class="card wide"><h2>Источник контента</h2><label>Origin URL<input name="origin" value="<?=h($s['origin'])?>" placeholder="https://site.ru"></label><label>Host header <small>пусто = Host origin-сервера</small><input name="host_header" value="<?=h($s['host_header'])?>" placeholder="example.com"></label><label class="check"><input type="checkbox" name="enabled" <?=((int)$s['enabled']===1?'checked':'')?>> CDN включен</label></div>
<div class="card"><h2>Кеш</h2><label>Размер кеша, GB<input type="number" name="cache_size_gb" value="<?=h($s['cache_size_gb'])?>"></label><label>TTL 200/301/302<input name="cache_ttl_200" value="<?=h($s['cache_ttl_200'])?>"></label><label>TTL 404<input name="cache_ttl_404" value="<?=h($s['cache_ttl_404'])?>"></label><label class="check"><input type="checkbox" name="stale_enabled" <?=((int)$s['stale_enabled']===1?'checked':'')?>> отдавать stale при ошибке origin</label><label class="check"><input type="checkbox" name="ignore_query_string" <?=((int)$s['ignore_query_string']===1?'checked':'')?>> игнорировать query string в ключе кеша</label></div>
<div class="card"><h2>Защита и заголовки</h2><label class="check"><input type="checkbox" name="cors_enabled" <?=((int)$s['cors_enabled']===1?'checked':'')?>> включить CORS *</label><label class="check"><input type="checkbox" name="hotlink_protection" <?=((int)$s['hotlink_protection']===1?'checked':'')?>> защита от hotlink</label><label>Разрешённые referer<textarea name="allowed_referers" rows="3" placeholder="*.site.ru site.ru"><?=h($s['allowed_referers'])?></textarea></label><label>Дополнительные заголовки<textarea name="custom_headers" rows="4" placeholder="Cache-Control: public\nX-Frame-Options: SAMEORIGIN"><?=h($s['custom_headers'])?></textarea></label></div>
<div class="actions"><button>Сохранить и применить</button></div></form>
<form method="post" action="/admin/purge" class="purge"><input type="hidden" name="csrf" value="<?=csrf()?>"><button class="danger">Очистить весь кеш</button></form>
<section class="card"><h2>Как проверять</h2><pre>curl -I http://IP_НОДЫ/path/to/file.jpg
# X-Cache-Status: MISS, затем HIT</pre></section>
</main></body></html><?php exit; }
