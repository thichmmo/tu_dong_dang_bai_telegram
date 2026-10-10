<?php
declare(strict_types=1);
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header("Content-Security-Policy: default-src 'self'; style-src 'unsafe-inline'; frame-ancestors 'none'; base-uri 'none'");
$ready = false;
$allReady = false;
$destinations = ['@hhdp3105' => false, '@honghotduongpho2027' => false];
$statusFile = '/home/dwhwlekshosting/telegram-mirror-thucunghaiza/storage/deploy/live-status.json';
if (is_file($statusFile) && filesize($statusFile) <= 32768) {
    $report = json_decode((string) @file_get_contents($statusFile), true);
    $report = is_array($report) ? $report : [];
    $heartbeat = (int) ($report['metadata']['worker_heartbeat_at'] ?? 0);
    $generated = (int) ($report['generated_at'] ?? 0);
    // A heartbeat alone can survive an exited worker. Require a fresh report
    // that verified the live process, and give a pause precedence over ready.
    $ready = is_array($report) && !empty($report['ready']) && !empty($report['worker_alive']) && empty($report['paused'])
        && $generated > 0 && time() - $generated >= 0 && time() - $generated < 30
        && $heartbeat > 0 && time() - $heartbeat >= 0 && time() - $heartbeat < 30;
    $allReady = $ready && !empty($report['all_targets_ready']);
    foreach (is_array($report['targets'] ?? null) ? $report['targets'] : [] as $destination) {
        if (is_array($destination) && is_string($destination['reference'] ?? null)
            && array_key_exists($destination['reference'], $destinations)) {
            $destinations[$destination['reference']] = $ready && !empty($destination['ready']);
        }
    }
}
?>
<!doctype html>
<html lang="vi">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <meta name="description" content="Công cụ tự đăng lại bài mới từ channel Telegram nguồn sang channel của bạn. Đang chờ hoàn tất thiết lập.">
    <title>Telegram Mirror · thucunghaiza.site</title>
    <style>
        :root {
            color-scheme: light dark;
            --background: #f5f7fb;
            --surface: #fff;
            --text: #172539;
            --muted: #657389;
            --line: #e4eaf2;
            --accent: #1685ca;
            --accent-soft: #edf7fd;
            --pending: #95632b;
            --pending-soft: #fff5e5;
            --shadow: 0 22px 70px rgb(23 37 57 / 6%);
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            padding: 32px 22px;
            min-height: 100svh;
            display: flex;
            align-items: center;
            justify-content: center;
            background: var(--background);
            color: var(--text);
            font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif;
            line-height: 1.65;
        }
        main { width: 100%; max-width: 760px; }
        .brand { display: flex; align-items: center; gap: 11px; margin: 0 0 32px; }
        .brand-icon {
            width: 38px; height: 38px; border-radius: 12px;
            display: grid; place-items: center;
            color: #fff; background: var(--accent);
        }
        .brand-name { font-size: 14px; font-weight: 700; letter-spacing: .01em; }
        .card { background: var(--surface); border: 1px solid var(--line); border-radius: 24px; padding: 38px; box-shadow: var(--shadow); }
        .status {
            display: inline-flex; align-items: center; gap: 8px;
            margin-bottom: 18px; padding: 5px 11px; border-radius: 100px;
            background: var(--pending-soft); color: var(--pending);
            font-size: 12px; font-weight: 600;
        }
        .dot { width: 6px; height: 6px; background: currentColor; border-radius: 50%; }
        .status.active { background: #e5f7ed; color: #197546; }
        .login { display: inline-block; margin-top: 14px; padding: 9px 15px; border-radius: 9px; background: #1685ca; color: white; font-weight: 600; font-size: 14px; text-decoration: none; }
        .login:focus-visible { outline: 2px solid var(--accent); outline-offset: 4px; }
        h1 { margin: 0; font-size: clamp(28px, 5vw, 40px); line-height: 1.23; letter-spacing: -.035em; font-weight: 720; }
        .intro { margin: 16px 0 30px; max-width: 570px; color: var(--muted); font-size: 16px; }
        .channels { display: grid; grid-template-columns: 1fr 34px 1fr; gap: 10px; align-items: center; }
        .channel { padding: 18px; border: 1px solid var(--line); border-radius: 14px; min-width: 0; }
        .label { display: block; margin-bottom: 6px; color: var(--muted); font-size: 12px; }
        .username { color: var(--accent); font-size: 16px; font-weight: 650; text-decoration: none; overflow-wrap: anywhere; }
        .username:hover { text-decoration: underline; text-underline-offset: 3px; }
        .username:focus-visible { outline: 2px solid var(--accent); outline-offset: 4px; border-radius: 2px; }
        .arrow { display: grid; place-items: center; color: var(--muted); }
        .setup { margin-top: 26px; padding: 19px 21px; background: var(--accent-soft); border-radius: 14px; }
        .setup strong { display: block; font-size: 14px; }
        .setup p { margin: 5px 0 0; color: var(--muted); font-size: 13px; }
        footer { margin-top: 18px; color: var(--muted); text-align: center; font-size: 12px; }
        @media (prefers-color-scheme: dark) {
            :root {
                --background: #111923; --surface: #182330; --text: #edf3fb;
                --muted: #a0afc2; --line: #2a3848; --accent: #66bffa;
                --accent-soft: #1c3043; --pending: #e8b875;
                --pending-soft: #3b3021; --shadow: none;
            }
            .brand-icon { background: #1685ca; }
        }
        @media (max-width: 520px) {
            body { padding: 24px 16px; }
            .brand { margin-bottom: 24px; }
            .card { padding: 26px 22px; border-radius: 20px; }
            .channels { grid-template-columns: 1fr; gap: 7px; }
            .arrow { transform: rotate(90deg); height: 24px; }
            .channel { padding: 14px 16px; }
            .setup { padding: 16px; }
        }
    </style>
</head>
<body>
<main>
    <div class="brand">
        <span class="brand-icon" aria-hidden="true">
            <svg width="21" height="21" viewBox="0 0 24 24" fill="none"><path d="m4 11 16-7-5 16-4-6-7-3Z" stroke="currentColor" stroke-width="1.7" stroke-linejoin="round"/><path d="m11 14 9-10" stroke="currentColor" stroke-width="1.7" stroke-linecap="round"/></svg>
        </span>
        <span class="brand-name">Telegram Mirror</span>
    </div>
    <section class="card" aria-labelledby="title">
        <div class="status<?= $allReady ? ' active' : '' ?>"><span class="dot" aria-hidden="true"></span><?= $allReady ? 'Đang hoạt động' : ($ready ? 'Một đích cần kiểm tra' : 'Chưa hoạt động') ?></div>
        <h1 id="title">Bài mới từ nguồn,<br>cập nhật về channel của bạn.</h1>
        <p class="intro">Tự đăng lại bài mới cùng ảnh, video và nội dung từ nguồn đến các channel của bạn.</p>
        <div class="channels" aria-label="Channel nguồn và channel đích">
            <div class="channel">
                <span class="label">Channel nguồn</span>
                <a class="username" href="https://t.me/honghotduongpho0" target="_blank" rel="noopener noreferrer">@honghotduongpho0</a>
            </div>
            <span class="arrow" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none"><path d="M4 12h15m-5-5 5 5-5 5" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"/></svg>
            </span>
            <div class="channel">
                <span class="label">Channel đích</span>
                <a class="username" href="https://t.me/hhdp3105" target="_blank" rel="noopener noreferrer">@hhdp3105</a>
                <span class="label"><?= $destinations['@hhdp3105'] ? 'Sẵn sàng nhận bài' : 'Chưa sẵn sàng' ?></span>
                <a class="username" href="https://t.me/honghotduongpho2027" target="_blank" rel="noopener noreferrer">@honghotduongpho2027</a>
                <span class="label"><?= $destinations['@honghotduongpho2027'] ? 'Sẵn sàng nhận bài' : 'Chưa sẵn sàng' ?></span>
            </div>
        </div>
        <div class="setup">
            <strong><?= $ready ? 'Đang nhận bài mới' : 'Hoàn tất đăng nhập Telegram' ?></strong>
            <p><?= $ready ? 'Tool đang theo dõi channel nguồn. Bài mới được đưa vào hàng đợi để đăng lại.' : 'Mở trang đăng nhập riêng trên máy đã cấu hình tool. Khi đăng nhập và kiểm tra quyền thành công, tool sẽ tự bắt đầu nhận bài mới.' ?></p>
            <a class="login" href="http://127.0.0.1:8765/">Đăng nhập trên máy này</a>
        </div>
    </section>
    <footer>thucunghaiza.site · Trạng thái dựa trên lần cập nhật gần nhất của tool.</footer>
</main>
</body>
</html>
