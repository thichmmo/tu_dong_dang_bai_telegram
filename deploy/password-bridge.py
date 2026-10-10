# -*- coding: utf-8 -*-
"""One-time Telegram 2FA UI on loopback; relay only encrypted input to cPanel."""
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
import json
import re
import secrets
import subprocess
import tempfile
import threading
import time
from cpanel import CPanel, ROOT
from cron_lock import cron_digest, exclusive_cron

APP = '/home/dwhwlekshosting/telegram-mirror-thucunghaiza'
STORE = APP + '/storage'
CSRF = secrets.token_urlsafe(32)
gate = threading.Lock()
active = None
client = None
attempts = []
remote_sync_needed = True
empty_verified = False
empty_probe = None
retired_requests = set()
WATCH = '/bin/bash ' + APP + '/deploy/watch-worker.sh >/dev/null 2>&1'
ALLOWED = {'wrong_password', 'source_join_required', 'target_post_permission_required',
           'storage_binding_mismatch', 'invalid_channel', 'invalid_envelope', 'login_failed', 'request_conflict'}


def replace_metadata(name, value):
    client.uapi('Fileman', 'save_file_content', dir=STORE, file=name,
                content=json.dumps(value), from_charset='UTF-8', to_charset='UTF-8', fallback=0)


@exclusive_cron
def remove_own_cron(command):
    for _ in range(3):
        all_rows = client.api2('Cron', 'listcron')
        rows = [r for r in all_rows if r.get('command') == command]
        if not rows:
            return
        client.api2('Cron', 'remove_line', line=rows[0]['count'],
                    expected_command=command, expected_cron_digest=cron_digest(all_rows))
    raise RuntimeError('Owned cron cleanup incomplete')


class RemoteReadFailure(RuntimeError):
    pass


class RemoteUnavailable(RemoteReadFailure):
    pass


class RemoteMissing(RemoteReadFailure):
    pass


class RemoteMalformed(RemoteReadFailure):
    pass


def read_json(name):
    """Missing is verified separately; an API error is never an empty file."""
    try:
        raw = client.read(STORE, name)
    except FileNotFoundError:
        raise RemoteMissing() from None
    except RuntimeError:
        # cPanel get_file_content uses a generic UAPI error for missing files.
        # Confirm absence using a successful directory listing, never error text.
        try:
            rows = client.uapi('Fileman', 'list_files', dir=STORE)
            if (isinstance(rows, list)
                    and all(isinstance(row, dict) and isinstance(row.get('file'), str) for row in rows)
                    and not any(row['file'] == name for row in rows)):
                raise RemoteMissing()
        except RemoteMissing:
            raise
        except Exception:
            pass
        raise RemoteUnavailable() from None
    except Exception:
        raise RemoteUnavailable() from None
    if not isinstance(raw, str):
        raise RemoteMalformed()
    try:
        if len(raw.encode('utf-8')) > 65536:
            raise RemoteMalformed()
    except UnicodeError:
        raise RemoteMalformed() from None
    try:
        value = json.loads(raw)
    except (ValueError, TypeError):
        raise RemoteMalformed() from None
    if not isinstance(value, dict):
        raise RemoteMalformed()
    return value


def request_command(request_id):
    return '/bin/bash ' + APP + '/deploy/login-2fa-' + request_id + '.sh >/dev/null 2>&1'


def validated_binding():
    """Read both binding files; refuse partial/malformed identities."""
    values, errors = {}, []
    for key, filename in [('request', 'login-2fa-request.json'), ('state', 'login-2fa-state.json')]:
        try:
            values[key] = read_json(filename)
        except RemoteReadFailure as error:
            errors.append(error)
    if errors:
        if len(errors) == 2 and all(isinstance(error, RemoteMissing) for error in errors):
            raise RemoteMissing()
        if any(isinstance(error, RemoteUnavailable) for error in errors):
            raise RemoteUnavailable()
        raise RemoteMalformed()
    request, state = values['request'], values['state']
    if request:
        if (not isinstance(request.get('request_id'), str)
                or not re.fullmatch('[a-f0-9]{32}', request['request_id'])
                or type(request.get('expires_at')) is not int or request['expires_at'] <= 0
                or ('cancelled' in request and type(request['cancelled']) is not bool)):
            raise RemoteMalformed()
    if state and state != {'status': 'input'}:
        if (not isinstance(state.get('request_id'), str)
                or not re.fullmatch('[a-f0-9]{32}', state['request_id'])
                or state.get('status') not in {'pending', 'processing', 'success', 'failed', 'expired', 'cancelled'}):
            raise RemoteMalformed()
    return request, state


@exclusive_cron
def empty_gate_proof():
    """No auth metadata mutation: prove emptiness under the native session lock."""
    global empty_probe, empty_verified, remote_sync_needed
    if empty_probe is None:
        nonce = secrets.token_hex(16)
        name = 'login-gate-probe-' + nonce + '.sh'
        command = '/bin/bash ' + APP + '/deploy/' + name + ' >/dev/null 2>&1'
        script = '#!/bin/bash\nset -u\numask 077\n' + (
            '/opt/cpanel/ea-php83/root/usr/bin/php ' + APP + '/bin/login-gate-probe.php ' + nonce + ' >/dev/null 2>&1\n')
        empty_probe = {'request_id': nonce, 'command': command, 'name': name, 'script': script,
                       'scheduled': False}
    if not empty_probe['scheduled']:
        with tempfile.TemporaryDirectory(prefix='telegram-gate-') as temporary:
            path = Path(temporary, empty_probe['name'])
            path.write_text(empty_probe['script']); path.chmod(0o600)
            result = client.upload(APP + '/deploy', [path])
            if result.get('failed') or result.get('succeeded') != 1:
                if client.read(APP + '/deploy', empty_probe['name']) != empty_probe['script']:
                    raise RemoteUnavailable()
        if not any(row.get('command') == empty_probe['command'] for row in client.api2('Cron', 'listcron')):
            client.api2('Cron', 'add_line', minute='*', hour='*', day='*', month='*', weekday='*', command=empty_probe['command'])
        empty_probe['scheduled'] = True
    try:
        proof = read_json('login-gate-probe-' + empty_probe['request_id'] + '.json')
    except RemoteMissing:
        return False
    if (proof.get('request_id') != empty_probe['request_id'] or proof.get('lock_free') is not True
            or proof.get('input_safe') is not True or type(proof.get('checked_at')) is not int
            or not 0 <= time.time() - proof['checked_at'] <= 30):
        return False
    # Keep the gate closed if removal is uncertain, even with a valid proof.
    remove_own_cron(empty_probe['command'])
    empty_probe = None
    empty_verified = True
    remote_sync_needed = False
    return True


@exclusive_cron
def resync_remote():
    """Caller holds gate; uncertainty preserves the current request and input gate."""
    global active, remote_sync_needed, empty_verified, empty_probe
    try:
        request, state = validated_binding()
    except RemoteUnavailable:
        remote_sync_needed = True
        empty_verified = False
        return None
    except RemoteMissing:
        remote_sync_needed = True
        if active is not None:
            return None
        if empty_verified or empty_gate_proof():
            remote_sync_needed = False
            return {}, {}
        return None
    except RemoteMalformed:
        remote_sync_needed = True
        empty_verified = False
        return None
    rid = request.get('request_id') or state.get('request_id')
    if rid:
        if (rid in retired_requests and not remote_sync_needed and request.get('cancelled') is True
                and state.get('request_id') in {None, rid} and active is None):
            return {}, {}
        empty_verified = False
        if empty_probe is not None:
            remove_own_cron(empty_probe['command'])
            empty_probe = None
        if active is not None and (request.get('request_id') not in {None, active['request_id']}
                                   or state.get('request_id') not in {None, active['request_id']}):
            active['conflict'] = True
            remote_sync_needed = False
            return request, state
        if (request.get('request_id') and state.get('request_id')
                and request['request_id'] != state['request_id']):
            remote_sync_needed = True
            return None
        if state.get('status') == 'failed' and state.get('failure_code') == 'wrong_password':
            remove_own_cron(request_command(rid))
            active = None
            remote_sync_needed = False
            return request, state
        if active is None:
            expiry = request.get('expires_at')
            if type(expiry) is not int:
                remote_sync_needed = True
                return None
            active = {'request_id': rid, 'expires_at': expiry, 'release_at': expiry + 310,
                      'command': request_command(rid)}
            if request.get('cancelled') is True and not (
                    state.get('status') == 'success' and request.get('retired_status') == 'expired'):
                active['retiring'] = 'expired' if request.get('retired_status') == 'expired' else 'cancelled'
        remote_sync_needed = False
        return request, state
    if active is not None:
        # A local request may have been accepted before its metadata write reply
        # was lost. Retain its binding; native retirement verifies the envelope.
        remote_sync_needed = False
        return request, state
    if not empty_verified or remote_sync_needed:
        if not empty_gate_proof():
            return None
    remote_sync_needed = False
    return request, state


@exclusive_cron
def begin_retirement(phase):
    """Caller holds gate. Native cleanup opens gate only after owning the lock."""
    if active is None or active.get('watch_started'):
        return
    active['retiring'] = phase
    remove_own_cron(active['command'])
    request = read_json('login-2fa-request.json')
    if request.get('request_id') not in {None, '', active['request_id']}:
        active['conflict'] = True
        return
    request.update(request_id=active['request_id'], expires_at=active['expires_at'],
                   cancelled=True, retired_status=phase)
    replace_metadata('login-2fa-request.json', request)
    name = 'login-2fa-cleanup-' + active['request_id'] + '.sh'
    command = '/bin/bash ' + APP + '/deploy/' + name + ' >/dev/null 2>&1'
    active['cleanup_command'] = command
    active.setdefault('cleanup_requested_at', int(time.time()))
    script = f'''#!/bin/bash
set -u
umask 077
/opt/cpanel/ea-php83/root/usr/bin/php {APP}/bin/login-2fa-cleanup.php {active['request_id']} >/dev/null 2>&1
'''
    with tempfile.TemporaryDirectory(prefix='telegram-retire-') as temporary:
        path = Path(temporary, name); path.write_text(script); path.chmod(0o600)
        result = client.upload(APP + '/deploy', [path])
        if result.get('failed'):
            # An immutable wrapper can exist after a lost upload reply.
            if client.read(APP + '/deploy', name) != script:
                raise RuntimeError('Cleanup runner conflict')
    if not any(row.get('command') == command for row in client.api2('Cron', 'listcron')):
        client.api2('Cron', 'add_line', minute='*', hour='*', day='*', month='*', weekday='*', command=command)
    active['cleanup_scheduled'] = True


@exclusive_cron
def status():
    global active
    with gate:
        snapshot = resync_remote()
        if snapshot is None:
            return {'status': 'connection_error'}
        request, state = snapshot
        if active is None:
            if state.get('status') == 'failed' and state.get('failure_code') == 'wrong_password':
                return {'status': 'failed', 'failure_code': 'wrong_password'}
            return {'status': 'input'}
        if active.get('conflict'):
            return {'status': 'failed', 'failure_code': 'request_conflict'}
        # A completed login remains valid after the encrypted request expires.
        # Retire only unfinished requests; never cancel a successful session.
        phase = state.get('status') if state.get('request_id') == active['request_id'] else 'pending'
        if phase not in {'pending', 'processing', 'success', 'failed', 'expired', 'cancelled'}:
            phase = 'pending'
        if phase == 'success' and active.get('retiring') == 'expired':
            if active.get('cleanup_command'):
                remove_own_cron(active['cleanup_command'])
            for key in ('retiring', 'cleanup_command', 'cleanup_requested_at', 'cleanup_scheduled'):
                active.pop(key, None)
        if not active.get('retiring') and phase in {'pending', 'processing'} and time.time() >= active['release_at']:
            begin_retirement('expired')
        if active.get('retiring'):
            if active.get('conflict'):
                return {'status': 'failed', 'failure_code': 'request_conflict'}
            if not active.get('cleanup_scheduled'):
                begin_retirement(active['retiring'])
            try:
                cleanup = read_json('login-2fa-cleanup-' + active['request_id'] + '.json')
            except RemoteMissing:
                return {'status': 'cancelling'}
            if (cleanup.get('request_id') == active['request_id'] and cleanup.get('status') in {'expired', 'cancelled'}
                    and type(cleanup.get('updated_at')) is int
                    and active['cleanup_requested_at'] <= cleanup['updated_at'] <= time.time()
                    and time.time() - cleanup['updated_at'] <= 30):
                remove_own_cron(active['command']); remove_own_cron(active['cleanup_command'])
                retired_requests.add(active['request_id'])
                phase = cleanup['status']; active = None
                return {'status': phase}
            if cleanup.get('status') == 'conflict':
                return {'status': 'failed', 'failure_code': 'request_conflict'}
            return {'status': 'cancelling'}
        if phase in {'expired', 'cancelled'}:
            begin_retirement(phase)
            return {'status': 'cancelling'}
        if phase in {'success', 'failed'}:
            # A failed cleanup keeps the request gate; later polls retry only
            # removal of this exact immutable request's cron command.
            remove_own_cron(active['command'])
            if phase == 'success':
                if not active.get('watch_started'):
                    if not any(r.get('command') == WATCH for r in client.api2('Cron', 'listcron')):
                        client.api2('Cron', 'add_line', minute='*', hour='*', day='*', month='*', weekday='*', command=WATCH)
                    active['watch_started'] = True
                try:
                    live = json.loads(client.read(STORE + '/deploy', 'live-status.json'))
                    heartbeat = int(live.get('metadata', {}).get('worker_heartbeat_at') or 0)
                    generated = int(live.get('generated_at') or 0)
                    fresh = 0 <= time.time() - generated < 30
                    if fresh and (live.get('paused') or live.get('receiving_ready') and not live.get('ready')):
                        return {'status': 'paused'}
                    if fresh and live.get('ready') and live.get('worker_alive') and 0 <= time.time() - heartbeat < 30:
                        return {'status': 'ready' if live.get('all_targets_ready') else 'partial'}
                except Exception:
                    pass
                return {'status': 'starting'}
            code = state.get('failure_code')
            result = {'status': phase, 'failure_code': code if code in ALLOWED else 'login_failed'}
            if phase == 'failed' and code == 'wrong_password':
                active = None
            return result
        return {'status': phase}


@exclusive_cron
def submit(password):
    global active
    with gate:
        # Bound manual retries on this private entry point without storing any
        # credential or changing Telegram account security settings.
        now = time.time()
        attempts[:] = [stamp for stamp in attempts if now - stamp < 900]
        if len(attempts) >= 5:
            raise RetryLimited()
        if resync_remote() is None:
            raise RemoteUnavailable()
        if active is not None:
            raise RuntimeError('Authentication request already pending')
        attempts.append(now)
        request_id = secrets.token_hex(16)
        expiry = int(time.time()) + 180
        found = re.findall(r"'api_hash'\s*=>\s*'([a-fA-F0-9]{32})'", (ROOT / 'config.php').read_text())
        if len(found) != 1:
            raise RuntimeError('Private API configuration unavailable')
        data = {'password': password, 'api_hash': found[0], 'request_id': request_id, 'expires_at': expiry}
        encrypted = subprocess.run(['/usr/local/bin/node', str(ROOT / 'deploy/encrypt-2fa.cjs')],
                                   input=json.dumps(data), text=True, capture_output=True, timeout=5, check=True)
        del data, password, found
        envelope = json.loads(encrypted.stdout)
        if envelope.get('request_id') != request_id or envelope.get('expires_at') != expiry:
            raise RuntimeError('Encrypted request mismatch')
        wrapper_name = 'login-2fa-' + request_id + '.sh'
        command = '/bin/bash ' + APP + '/deploy/' + wrapper_name + ' >/dev/null 2>&1'
        wrapper = f'''#!/bin/bash
set -u
umask 077
appdir={APP}
mkdir "$appdir/storage/deploy/login-2fa-{request_id}.started" 2>/dev/null || exit 0
/usr/bin/timeout --signal=TERM --kill-after=10s 300 /opt/cpanel/ea-php83/root/usr/bin/php "$appdir/bin/login-2fa.php" {request_id} > "$appdir/storage/deploy/login-2fa-runner.log" 2>&1
'''
        with tempfile.TemporaryDirectory(prefix='telegram-2fa-') as temporary:
            folder = Path(temporary)
            path = folder / 'login-2fa-envelope.json'
            path.write_text(json.dumps(envelope)); path.chmod(0o600)
            # Block further submissions even if an upload succeeds remotely
            # but its confirmation is lost on this connection.
            active = {'request_id': request_id, 'expires_at': expiry, 'command': command, 'release_at': expiry + 310}
            result = client.upload(STORE, [path])
            if result.get('failed') != 0 or result.get('succeeded') != 1:
                raise RuntimeError('Encrypted envelope not accepted')
            # Gate before further mutations: an uncertain API outcome must
            # never let another submission overwrite this envelope's binding.
            replace_metadata('login-2fa-request.json', {'request_id': request_id, 'expires_at': expiry})
            replace_metadata('login-2fa-state.json', {'request_id': request_id, 'status': 'pending', 'updated_at': int(time.time())})
            script = folder / wrapper_name
            script.write_text(wrapper); script.chmod(0o600)
            result = client.upload(APP + '/deploy', [script])
            if result.get('failed') != 0 or result.get('succeeded') != 1:
                raise RuntimeError('Private authentication runner not accepted')
            client.api2('Cron', 'add_line', minute='*', hour='*', day='*', month='*', weekday='*', command=command)


HTML = '''<!doctype html><html lang="vi"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Xác minh Telegram cho tool</title><style>body{font:16px system-ui;background:#f4f7fb;color:#182536;margin:0}main{max-width:500px;margin:48px auto;background:white;padding:28px;border-radius:20px}h1{font-size:24px}input,button{box-sizing:border-box;width:100%;font:inherit;border-radius:10px;padding:12px;margin:10px 0}input{border:1px solid #bac7d8}button{border:0;background:#087fbe;color:white}button:disabled{opacity:.6}small{color:#607188}#status{font-weight:600}</style><main><h1 id="heading">Xác minh hai bước Telegram</h1><p id="intro">QR đã được chấp nhận. Nhập mật khẩu xác minh hai bước để hoàn tất đăng nhập. Sau khi kiểm tra quyền channel, tool sẽ tự nhận bài mới.</p><form id="form"><label for="password">Mật khẩu Telegram</label><input id="password" name="password" type="password" autocomplete="off" maxlength="4096" required disabled><button id="submit" disabled>Xác minh đăng nhập</button><button id="cancel" type="button" hidden>Hủy lượt xác minh</button></form><p id="status" role="status">Đang chờ bạn nhập mật khẩu.</p><small id="privacy-note">Trang chạy riêng trên máy của bạn. Mật khẩu được gửi mã hóa đến hosting đã cấu hình cho thucunghaiza.site; không gửi vào chat.</small></main><script>const csrf='CSRF_TOKEN';const form=document.getElementById('form'),password=document.getElementById('password'),button=document.getElementById('submit'),notice=document.getElementById('status');let pending=true,rateUntil=0,authenticated=false;const heading=document.getElementById('heading'),intro=document.getElementById('intro'),privacy=document.getElementById('privacy-note');const cancel=document.getElementById('cancel');cancel.addEventListener('click',async()=>{cancel.disabled=true;try{await fetch('/cancel',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf})});}catch{}notice.textContent=labels.cancelling;});const labels={connection_error:'Chưa đọc được trạng thái hosting. Giữ phiên này và thử lại khi kết nối phục hồi.',rate_limited:'Đã thử quá nhiều lần. Chờ 15 phút rồi thử lại.',input:'Đang chờ bạn nhập mật khẩu.',pending:'Đã gửi yêu cầu. Có thể cần chờ khoảng một phút.',processing:'Đang xác minh với Telegram…',success:'Đăng nhập và kiểm tra quyền channel thành công.',starting:'Đăng nhập thành công. Worker đang khởi động…',ready:'Tool đang theo dõi bài mới từ @honghotduongpho0 sang @hhdp3105 và @honghotduongpho2027.',partial:'Tool đang nhận bài mới; một đích cần kiểm tra quyền. Đích còn lại tiếp tục nhận bài.',paused:'Worker cần kiểm tra lại quyền hoặc kết nối. Mình sẽ xem trạng thái riêng.',expired:'Yêu cầu đã hết hạn. Bạn có thể nhập lại.',cancelled:'Đã hủy lượt xác minh. Bạn có thể quay lại.',cancelling:'Đang kết thúc lượt xác minh trước; giữ nguyên phiên hiện tại.',failed:'Chưa hoàn tất xác minh.'};const errors={request_conflict:'Cần kiểm tra trạng thái phiên trước khi thử lại.',wrong_password:'Mật khẩu chưa đúng. Bạn có thể nhập lại.',source_join_required:'Đăng nhập đã xong. Tài khoản cần tham gia @honghotduongpho0.',target_post_permission_required:'Đăng nhập đã xong. Tài khoản cần quyền đăng bài ở @hhdp3105.'};form.addEventListener('submit',async e=>{e.preventDefault();if(pending)return;pending=true;button.disabled=true;let value=password.value;password.value='';try{let r=await fetch('/verify',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({csrf,password:value})});value='';if(r.status===429){rateUntil=Date.now()+900000;notice.textContent=labels.rate_limited;}else if(!r.ok)notice.textContent='Yêu cầu chưa hoàn tất gửi. Mình đang kiểm tra trạng thái.';else notice.textContent=labels.pending;}catch{value='';notice.textContent='Kết nối tạm ngắt. Mình đang kiểm tra trạng thái.';}});async function poll(){try{let s=await(await fetch('/status',{cache:'no-store'})).json();notice.textContent=Date.now()<rateUntil?labels.rate_limited:errors[s.failure_code]||labels[s.status]||'Đang kiểm tra…';pending=['pending','processing','cancelling','connection_error'].includes(s.status);button.disabled=pending||Date.now()<rateUntil;password.disabled=button.disabled;cancel.hidden=!pending||s.status==='connection_error';cancel.disabled=s.status==='cancelling';authenticated=authenticated||['success','starting','ready','partial','paused'].includes(s.status);heading.textContent=authenticated?'Telegram đã đăng nhập':'Xác minh hai bước Telegram';intro.hidden=authenticated;privacy.hidden=authenticated;form.hidden=authenticated||(s.status==='failed'&&s.failure_code!=='wrong_password');}catch{notice.textContent='Trang tạm ngắt kết nối.';}setTimeout(poll,2500);}poll();</script></html>'''


class RetryLimited(RuntimeError):
    pass


def restore_active():
    """Cold-open stays alive with input blocked until remote state is verified."""
    global remote_sync_needed
    with gate:
        try:
            return resync_remote() is not None
        except Exception:
            remote_sync_needed = True
            return False


class Handler(BaseHTTPRequestHandler):
    def setup(self):
        super().setup()
        self.connection.settimeout(10)

    def valid_host(self):
        expected = '127.0.0.1:' + str(self.server.server_port)
        return self.headers.get_all('Host') == [expected]

    def respond(self, code, body, mime='application/json'):
        self.send_response(code)
        self.send_header('Content-Type', mime)
        self.send_header('Cache-Control', 'no-store')
        self.send_header('X-Content-Type-Options', 'nosniff')
        self.send_header('Content-Security-Policy', "default-src 'self'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; frame-ancestors 'none'; form-action 'self'")
        self.send_header('Content-Length', str(len(body)))
        self.end_headers(); self.wfile.write(body)

    def do_GET(self):
        if not self.valid_host() or (self.path != '/' and self.headers.get('Sec-Fetch-Site') == 'cross-site'):
            self.respond(403, b'{}'); return
        if self.path == '/':
            self.respond(200, HTML.replace('CSRF_TOKEN', CSRF).encode(), 'text/html; charset=utf-8')
        elif self.path == '/status':
            try: self.respond(200, json.dumps(status()).encode())
            except Exception: self.respond(503, b'{"status":"connection_error"}')
        else: self.respond(404, b'{}')

    def do_POST(self):
        expected = 'http://127.0.0.1:' + str(self.server.server_port)
        lengths = self.headers.get_all('Content-Length') or []
        if (not self.valid_host() or self.path not in {'/verify', '/cancel'}
                or self.headers.get_all('Origin') != [expected]
                or self.headers.get('Sec-Fetch-Site') not in {'same-origin', None}
                or self.headers.get('Transfer-Encoding') is not None
                or len(lengths) != 1 or not lengths[0].isdigit()
                or not 1 <= int(lengths[0]) <= 32768):
            self.respond(403, b'{}'); return
        try:
            payload = json.loads(self.rfile.read(int(lengths[0])).decode('utf-8'))
            if not isinstance(payload, dict) or not isinstance(payload.get('csrf'), str) or not secrets.compare_digest(payload['csrf'], CSRF):
                self.respond(403, b'{}'); return
            if self.path == '/cancel':
                with gate:
                    begin_retirement('cancelled')
                self.respond(202, b'{"status":"cancelling"}'); return
            password = payload.get('password')
            if not isinstance(password, str) or not 1 <= len(password.encode('utf-8')) <= 4096:
                self.respond(400, b'{}'); return
            del payload
            submit(password)
            del password
            self.respond(202, b'{"status":"pending"}')
        except RetryLimited:
            self.respond(429, b'{"status":"rate_limited"}')
        except RemoteReadFailure:
            self.respond(503, b'{"status":"connection_error"}')
        except Exception:
            # No body, password, Node stderr, API errors or traceback in logs.
            self.respond(503, b'{"status":"pending"}')

    def log_message(self, *_):
        pass


if __name__ == '__main__':
    client = CPanel()
    restore_active()
    server = ThreadingHTTPServer(('127.0.0.1', 8765), Handler)
    server.daemon_threads = True
    server.timeout = 1
    print('Private Telegram login: http://127.0.0.1:' + str(server.server_port), flush=True)
    deadline = time.time() + 43200
    while time.time() < deadline:
        server.handle_request()
    server.server_close()
