<?php

declare(strict_types=1);

use TelegramMirror\Control;

ini_set('display_errors', '0');
umask(0077);
header('Cache-Control: no-store, private, max-age=0');
header('Pragma: no-cache');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header("Content-Security-Policy: default-src 'none'; style-src 'unsafe-inline'; form-action 'self'; frame-ancestors 'none'; base-uri 'none'; upgrade-insecure-requests");

// Only this public entry point belongs in the webroot. PHP source/state stay private.
$appRoot = getenv('TELEGRAM_MIRROR_ROOT') ?: '/home/dwhwlekshosting/telegram-mirror-thucunghaiza';
$error = '';
$notice = '';
$control = null;
$identity = null;
$setup = false;
$document = ['version' => 1, 'revision' => 0, 'routes' => []];
$status = ['fresh' => false, 'running' => false, 'revision' => null, 'config_rejected' => false, 'routes' => []];
$edit = null;
$draft = ['source' => '', 'targets' => '', 'enabled' => true];

function escaped(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function redirectAdmin(): never
{
    header('Location: /admin.php', true, 303);
    exit;
}

function formString(string $key, int $maximum = 4096): string
{
    $value = $_POST[$key] ?? '';
    if (!is_string($value) || strlen($value) > $maximum) {
        throw new InvalidArgumentException('Thông tin trong biểu mẫu không hợp lệ.');
    }
    return $value;
}

function formInteger(string $key): int
{
    $value = formString($key, 20);
    if (!preg_match('/^(0|[1-9][0-9]*)$/D', $value) || filter_var($value, FILTER_VALIDATE_INT) === false) {
        throw new InvalidArgumentException('Biểu mẫu đã cũ hoặc không hợp lệ. Tải lại trang rồi thử lại.');
    }
    return (int) $value;
}

try {
    if (!is_file($appRoot . '/src/Control.php')) { throw new RuntimeException('Ứng dụng quản trị chưa sẵn sàng.'); }
    require_once $appRoot . '/src/Control.php';
    $control = new Control($appRoot . '/storage');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET' && ($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
        http_response_code(405);
        header('Allow: GET, POST');
        throw new RuntimeException('Phương thức không hỗ trợ.');
    }
    if ((int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 16384) {
        http_response_code(413);
        throw new RuntimeException('Biểu mẫu quá lớn.');
    }
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.gc_maxlifetime', '1800');
    session_name('telegram_admin');
    session_save_path($control->sessionDirectory());
    session_set_cookie_params(['lifetime' => 0, 'path' => '/admin.php', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
    if (!session_start()) { throw new RuntimeException('Không mở được phiên quản trị.'); }
    $now = time();
    $savedIdentity = $control->identity();
    if (isset($_SESSION['identity'])) {
        $current = $_SESSION['identity'];
        $valid = is_array($current) && $savedIdentity !== null
            && is_string($current['auth_epoch'] ?? null) && is_string($current['username'] ?? null)
            && hash_equals($savedIdentity['auth_epoch'], $current['auth_epoch'])
            && hash_equals($savedIdentity['username'], $current['username'])
            && is_int($_SESSION['login_at'] ?? null) && is_int($_SESSION['last_seen'] ?? null)
            && $now - $_SESSION['login_at'] >= 0 && $now - $_SESSION['login_at'] < 28800
            && $now - $_SESSION['last_seen'] >= 0 && $now - $_SESSION['last_seen'] < 1800;
        if (!$valid) {
            $_SESSION = [];
            session_regenerate_id(true);
            $notice = 'Phiên đăng nhập đã hết hạn. Đăng nhập lại để tiếp tục.';
        } else {
            $identity = $current;
            $_SESSION['last_seen'] = $now;
        }
    }
    // Remove the one-use invitation from the address bar before rendering any HTML.
    if (isset($_GET['setup'])) {
        $token = is_string($_GET['setup']) ? $_GET['setup'] : '';
        $grant = $identity === null ? $control->setupGrant($token) : null;
        if ($grant !== null) {
            $_SESSION = ['setup_grant' => $grant];
            session_regenerate_id(true);
        } else {
            $_SESSION['notice'] = 'Liên kết thiết lập đã hết hạn hoặc được sử dụng.';
        }
        redirectAdmin();
    }
    if (isset($_SESSION['notice']) && is_string($_SESSION['notice'])) {
        $notice = $_SESSION['notice'];
        unset($_SESSION['notice']);
    }
    $setupGrant = is_array($_SESSION['setup_grant'] ?? null) ? $_SESSION['setup_grant'] : [];
    $setup = $identity === null && $setupGrant !== [] && $control->grantAvailable($setupGrant);
    if (!$setup) { unset($_SESSION['setup_grant']); }
    $csrf = Control::csrfToken($_SESSION);

    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST') {
        try {
            if (!Control::validCsrf($_SESSION, $_POST['csrf'] ?? null)) {
                http_response_code(403);
                throw new RuntimeException('Phiên biểu mẫu đã hết hạn. Tải lại trang rồi thử lại.');
            }
            $action = formString('action', 32);
            if ($action === 'setup' && $setup && $identity === null) {
                $password = formString('password', 256);
                if (!hash_equals($password, formString('password_repeat', 256))) {
                    throw new InvalidArgumentException('Hai lần nhập mật khẩu chưa giống nhau.');
                }
                $identity = $control->setupFromGrant($setupGrant, formString('username', 64), $password);
                $_SESSION = ['identity' => $identity, 'login_at' => $now, 'last_seen' => $now, 'notice' => 'Đã tạo tài khoản quản trị. Bạn có thể quản lý nguồn và đích bên dưới.'];
                session_regenerate_id(true);
                redirectAdmin();
            } elseif ($action === 'login' && $identity === null) {
                $client = is_string($_SERVER['REMOTE_ADDR'] ?? null) ? $_SERVER['REMOTE_ADDR'] : 'unknown';
                $loggedIn = $control->authenticate(formString('username', 64), formString('password', 256), $client);
                if ($loggedIn === null) {
                    throw new RuntimeException('Tên đăng nhập hoặc mật khẩu chưa đúng, hoặc lượt thử đã bị giới hạn. Nếu đã thử nhiều lần, chờ 15 phút rồi thử lại.');
                }
                $_SESSION = ['identity' => $loggedIn, 'login_at' => $now, 'last_seen' => $now];
                session_regenerate_id(true);
                redirectAdmin();
            } elseif ($action === 'logout' && $identity !== null) {
                $_SESSION = [];
                setcookie(session_name(), '', ['expires' => time() - 3600, 'path' => '/admin.php', 'secure' => true, 'httponly' => true, 'samesite' => 'Strict']);
                session_destroy();
                redirectAdmin();
            } elseif ($identity !== null && in_array($action, ['route_save', 'route_toggle', 'route_delete'], true)) {
                $revision = formInteger('revision');
                $document = $control->routes();
                if ($revision !== $document['revision']) { throw new RuntimeException('Cấu hình đã thay đổi ở cửa sổ khác. Tải lại trang rồi thử lại.'); }
                $routes = $document['routes'];
                if ($action === 'route_save') {
                    $index = formString('index', 20);
                    $draft = ['source' => formString('source', 200), 'targets' => formString('targets', 2048), 'enabled' => isset($_POST['enabled'])];
                    $targets = preg_split('/[\s,;]+/u', trim($draft['targets']), -1, PREG_SPLIT_NO_EMPTY);
                    if ($targets === false) { throw new InvalidArgumentException('Danh sách channel đích không hợp lệ.'); }
                    $route = ['source' => $draft['source'], 'targets' => $targets, 'enabled' => $draft['enabled']];
                    if ($index === '') {
                        $routes[] = $route;
                    } else {
                        $edit = formInteger('index');
                        if (!isset($routes[$edit])) { throw new RuntimeException('Nguồn này không còn trong cấu hình.'); }
                        $routes[$edit] = $route;
                    }
                } else {
                    $index = formInteger('index');
                    if (!isset($routes[$index])) { throw new RuntimeException('Nguồn này không còn trong cấu hình.'); }
                    if ($action === 'route_toggle') { $routes[$index]['enabled'] = !$routes[$index]['enabled']; }
                    else { array_splice($routes, $index, 1); }
                }
                $control->saveRoutes($revision, array_values($routes));
                $_SESSION['notice'] = 'Đã lưu cấu hình. Worker sẽ kiểm tra channel và quyền đăng trước khi nhận bài mới.';
                redirectAdmin();
            } else {
                http_response_code($identity === null ? 403 : 400);
                throw new RuntimeException('Vui lòng đăng nhập và dùng biểu mẫu trên trang này.');
            }
        } catch (InvalidArgumentException | RuntimeException $formError) {
            $error = $formError->getMessage();
        }
    }
    if ($identity !== null) {
        $document = $control->routes();
        $status = $control->status();
        if (isset($_GET['edit'])) {
            $index = is_string($_GET['edit']) ? $_GET['edit'] : '';
            if (preg_match('/^(0|[1-9][0-9]*)$/D', $index) && strlen($index) <= 3 && isset($document['routes'][(int) $index])) {
                $edit = (int) $index;
                $route = $document['routes'][$edit];
                $draft = ['source' => $route['source'], 'targets' => implode("\n", $route['targets']), 'enabled' => $route['enabled']];
            }
        }
    }
} catch (Throwable) {
    if (http_response_code() < 400) { http_response_code(503); }
    $error = 'Trang quản trị tạm thời chưa sẵn sàng. Hãy thử lại sau.';
    $control = null;
    $identity = null;
    $setup = false;
}
$csrf = isset($_SESSION) && is_array($_SESSION) && $control !== null ? Control::csrfToken($_SESSION) : '';
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title><?= $identity !== null ? 'Quản lý nguồn và đích' : ($setup ? 'Thiết lập quản trị riêng' : 'Đăng nhập quản trị') ?> · Telegram Mirror</title>
    <style>
        :root { color-scheme: light dark; --bg:#f5f7fb; --surface:#fff; --text:#172539; --muted:#657389; --line:#e4eaf2; --accent:#1685ca; --soft:#edf7fd; --danger:#b63737; --danger-soft:#fff0f0; }
        * { box-sizing:border-box; }
        body { margin:0; padding:32px 22px; background:var(--bg); color:var(--text); font:15px/1.6 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; }
        main { max-width:1080px; margin:auto; }
        .brand,.top,.route-title,.actions { display:flex; align-items:center; gap:12px; }
        .brand { color:var(--text); font-weight:700; text-decoration:none; }
        .brand-icon { width:36px; height:36px; display:grid; place-items:center; border-radius:11px; background:#1685ca; color:white; }
        .top { justify-content:space-between; flex-wrap:wrap; margin-bottom:26px; }
        .top form { margin:0; }
        .card { padding:30px; background:var(--surface); border:1px solid var(--line); border-radius:20px; box-shadow:0 18px 65px rgb(23 37 57 / 4%); }
        .auth { max-width:480px; margin:64px auto; }
        h1 { margin:0 0 10px; font-size:clamp(26px,4vw,34px); line-height:1.25; letter-spacing:-.025em; }
        h2 { margin:0 0 8px; font-size:20px; }
        p { margin:8px 0 20px; }
        .muted,.help { color:var(--muted); }
        .help { font-size:13px; margin:7px 0 16px; }
        label { display:block; font-weight:600; margin:18px 0 7px; }
        input[type=text],input[type=password],textarea { width:100%; padding:11px 12px; font:inherit; color:var(--text); background:var(--bg); border:1px solid var(--line); border-radius:9px; }
        textarea { min-height:132px; resize:vertical; }
        input:focus,textarea:focus { outline:2px solid var(--accent); outline-offset:2px; }
        .check { display:flex; align-items:center; gap:9px; margin:18px 0; font-weight:500; }
        .check input { width:17px; height:17px; accent-color:#1685ca; }
        button,.button { cursor:pointer; display:inline-block; border:1px solid transparent; border-radius:9px; padding:9px 13px; font:600 14px/1.5 -apple-system,BlinkMacSystemFont,"Segoe UI",sans-serif; color:white; background:#1685ca; text-decoration:none; }
        button:focus-visible,.button:focus-visible,a:focus-visible { outline:2px solid var(--accent); outline-offset:3px; }
        .secondary { background:var(--surface); border-color:var(--line); color:var(--text); }
        .danger { color:var(--danger); background:var(--danger-soft); border-color:transparent; }
        .wide { width:100%; margin-top:10px; }
        .alert { padding:13px 16px; border-radius:10px; background:var(--soft); margin-bottom:18px; }
        .alert.error { color:var(--danger); background:var(--danger-soft); }
        .grid { display:grid; grid-template-columns:minmax(0,1.2fr) minmax(300px,.8fr); gap:22px; margin-top:24px; align-items:start; }
        .route { border:1px solid var(--line); border-radius:13px; padding:18px; margin-top:15px; }
        .route-title { justify-content:space-between; align-items:flex-start; }
        .peer { font-weight:650; overflow-wrap:anywhere; }
        .badge { font-size:12px; padding:3px 9px; border-radius:100px; white-space:nowrap; background:#e5f7ed; color:#197546; }
        .badge.paused { background:var(--bg); color:var(--muted); }
        .destinations { list-style:none; margin:12px 0; padding:0; }
        .destinations li { padding:3px 0; overflow-wrap:anywhere; }
        .destinations li::before { content:'→'; color:var(--muted); margin-right:9px; }
        .actions { flex-wrap:wrap; margin-top:16px; gap:8px; }
        .actions form { margin:0; }
        .actions button,.actions .button { font-size:13px; padding:7px 10px; }
        .policy { margin:20px 0 0; padding:15px 17px; border-radius:11px; background:var(--soft); color:var(--muted); font-size:13px; }
        footer { text-align:center; color:var(--muted); font-size:12px; margin-top:24px; }
        @media (prefers-color-scheme:dark) { :root { --bg:#111923; --surface:#182330; --text:#edf3fb; --muted:#a0afc2; --line:#2a3848; --accent:#66bffa; --soft:#1c3043; --danger:#ffafaf; --danger-soft:#3d2528; } .badge { background:#183629; color:#91d8aa; } }
        @media (max-width:780px) { .grid { grid-template-columns:1fr; } body { padding:24px 16px; } .card { padding:23px; } .auth { margin:36px auto; } }
    </style>
</head>
<body>
<main>
    <div class="top">
        <a class="brand" href="/"><span class="brand-icon" aria-hidden="true">↗</span>Telegram Mirror</a>
        <?php if ($identity !== null): ?>
        <form method="post" action="/admin.php">
            <input type="hidden" name="csrf" value="<?= escaped($csrf) ?>"><input type="hidden" name="action" value="logout">
            <span class="muted"><?= escaped($identity['username']) ?> · </span><button class="secondary" type="submit">Đăng xuất</button>
        </form>
        <?php endif; ?>
    </div>
    <?php if ($identity !== null): ?>
        <h1>Quản lý nguồn và đích</h1>
        <p class="muted">Chọn channel lấy bài và các channel nhận bài cho từng nguồn.</p>
    <?php endif; ?>
    <?php if ($error !== ''): ?><div class="alert error" role="alert"><?= escaped($error) ?></div><?php endif; ?>
    <?php if ($notice !== ''): ?><div class="alert" role="status"><?= escaped($notice) ?></div><?php endif; ?>
    <?php if ($control === null): ?>
        <section class="card auth"><h1>Quản trị chưa sẵn sàng</h1><p class="muted">Thử tải lại trang sau ít phút.</p><a class="button secondary" href="/">Về trang chính</a></section>
    <?php elseif ($identity === null): ?>
        <section class="card auth">
            <h1><?= $setup ? 'Thiết lập quản trị riêng' : 'Đăng nhập quản trị' ?></h1>
            <p class="muted"><?= $setup ? 'Bạn đã mở liên kết thiết lập riêng của chủ hosting. Đặt tên đăng nhập và mật khẩu để quản lý các channel.' : 'Dùng tài khoản quản trị website để chỉnh nguồn lấy bài và nơi đăng bài. Website không mở đăng ký tài khoản.' ?></p>
            <?php if ($setup): ?><p class="help">Chỉ tạo một tài khoản quản trị. Sau khi tạo thành công, liên kết và chức năng thiết lập tự khóa.</p><?php endif; ?>
            <form method="post" action="/admin.php">
                <input type="hidden" name="csrf" value="<?= escaped($csrf) ?>">
                <input type="hidden" name="action" value="<?= $setup ? 'setup' : 'login' ?>">
                <label for="username">Tên đăng nhập</label>
                <input type="text" id="username" name="username" autocomplete="username" autocapitalize="none" spellcheck="false" maxlength="32" <?= $setup ? 'minlength="3" pattern="[A-Za-z][A-Za-z0-9_.-]{2,31}"' : '' ?> required>
                <label for="password">Mật khẩu</label>
                <input type="password" id="password" name="password" autocomplete="<?= $setup ? 'new-password' : 'current-password' ?>" <?= $setup ? 'minlength="10"' : '' ?> maxlength="72" required>
                <?php if ($setup): ?>
                    <p class="help">Ít nhất 10 ký tự, tối đa 72 byte. Đây là mật khẩu quản trị website.</p>
                    <label for="password_repeat">Nhập lại mật khẩu</label>
                    <input type="password" id="password_repeat" name="password_repeat" autocomplete="new-password" minlength="10" maxlength="72" required>
                <?php endif; ?>
                <button type="submit" class="wide"><?= $setup ? 'Tạo tài khoản và vào quản lý' : 'Đăng nhập' ?></button>
            </form>
        </section>
    <?php else: ?>
        <div class="alert" role="status">
            <?php if (!$status['fresh']): ?>Chưa có trạng thái mới từ worker. Cấu hình đã lưu vẫn được giữ lại.
            <?php elseif ($status['config_rejected']): ?>Cấu hình mới chưa được áp dụng. Kiểm tra nguồn, đích và quyền của tài khoản Telegram; channel đang làm nguồn không được đồng thời làm đích.
            <?php elseif (!$status['running']): ?>Worker đang chờ hoặc cần kiểm tra. Bạn vẫn có thể cập nhật nguồn và đích.
            <?php elseif ($status['revision'] !== null && $status['revision'] === $document['revision']): ?>Worker đã đọc cấu hình hiện tại. Xem trạng thái từng nguồn bên dưới.
            <?php elseif ($status['revision'] !== null): ?>Đã lưu cấu hình mới. Worker đang kiểm tra và áp dụng thay đổi.
            <?php else: ?>Worker đang hoạt động. Cấu hình được kiểm tra trước khi áp dụng.
            <?php endif; ?>
        </div>
        <div class="grid">
            <section class="card" aria-labelledby="routes-title">
                <h2 id="routes-title">Nguồn đã cấu hình</h2>
                <p class="help"><?= count($document['routes']) ?> / 20 nguồn. Mỗi nguồn có thể đăng đến nhiều đích.</p>
                <?php if ($document['routes'] === []): ?><p class="muted">Chưa có nguồn. Thêm channel đầu tiên bằng biểu mẫu bên cạnh.</p><?php endif; ?>
                <?php foreach ($document['routes'] as $index => $route): ?>
                    <article class="route">
                        <div class="route-title"><span class="peer"><?= escaped($route['source']) ?></span><span class="badge<?= $route['enabled'] ? '' : ' paused' ?>"><?= $route['enabled'] ? 'Đã bật' : 'Tạm dừng' ?></span></div>
                        <ul class="destinations"><?php foreach ($route['targets'] as $target): ?><li><?= escaped($target) ?></li><?php endforeach; ?></ul>
                        <?php if (isset($status['routes'][$route['source']]) && $route['enabled']): ?><p class="help"><?= $status['routes'][$route['source']] ? 'Nguồn sẵn sàng nhận bài.' : 'Nguồn đang chờ kiểm tra channel hoặc quyền đăng.' ?></p><?php endif; ?>
                        <div class="actions">
                            <a class="button secondary" href="/admin.php?edit=<?= $index ?>">Chỉnh sửa</a>
                            <form method="post" action="/admin.php"><input type="hidden" name="csrf" value="<?= escaped($csrf) ?>"><input type="hidden" name="revision" value="<?= $document['revision'] ?>"><input type="hidden" name="index" value="<?= $index ?>"><input type="hidden" name="action" value="route_toggle"><button type="submit" class="secondary"><?= $route['enabled'] ? 'Tạm dừng' : 'Bật nguồn' ?></button></form>
                            <form method="post" action="/admin.php"><input type="hidden" name="csrf" value="<?= escaped($csrf) ?>"><input type="hidden" name="revision" value="<?= $document['revision'] ?>"><input type="hidden" name="index" value="<?= $index ?>"><input type="hidden" name="action" value="route_delete"><button type="submit" class="danger">Xóa cấu hình</button></form>
                        </div>
                    </article>
                <?php endforeach; ?>
                <p class="policy">Nguồn mới, đích mới và nguồn được bật lại chỉ nhận bài mới từ lúc kích hoạt. Bài đã xếp hàng giữ nguyên. Xóa cấu hình không xóa bài đã đăng trên Telegram.</p>
            </section>
            <section class="card" aria-labelledby="edit-title">
                <h2 id="edit-title"><?= $edit === null ? 'Thêm nguồn lấy bài' : 'Chỉnh sửa nguồn' ?></h2>
                <form method="post" action="/admin.php">
                    <input type="hidden" name="csrf" value="<?= escaped($csrf) ?>"><input type="hidden" name="action" value="route_save"><input type="hidden" name="revision" value="<?= $document['revision'] ?>"><input type="hidden" name="index" value="<?= $edit === null ? '' : $edit ?>">
                    <label for="source">Channel lấy bài</label>
                    <input type="text" id="source" name="source" placeholder="@channel_nguon" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="200" value="<?= escaped($draft['source']) ?>" required>
                    <label for="targets">Channel đăng bài</label>
                    <textarea id="targets" name="targets" placeholder="@channel_dich_1&#10;@channel_dich_2" autocomplete="off" autocapitalize="none" spellcheck="false" maxlength="2048" required><?= escaped($draft['targets']) ?></textarea>
                    <p class="help">Mỗi dòng một đích, tối đa 10 đích. Có thể nhập @username, link t.me hoặc ID -100...</p>
                    <label class="check"><input type="checkbox" name="enabled" value="1"<?= $draft['enabled'] ? ' checked' : '' ?>>Bật nguồn lấy bài</label>
                    <button type="submit"><?= $edit === null ? 'Thêm nguồn' : 'Lưu thay đổi' ?></button>
                    <?php if ($edit !== null): ?><a class="button secondary" href="/admin.php">Hủy chỉnh sửa</a><?php endif; ?>
                </form>
                <p class="policy">Tài khoản Telegram đã kết nối cần xem được nguồn và có quyền đăng bài ở các đích. Worker kiểm tra các quyền này khi áp dụng cấu hình.</p>
            </section>
        </div>
    <?php endif; ?>
    <footer>thucunghaiza.site · Quản lý Telegram Mirror</footer>
</main>
</body>
</html>
