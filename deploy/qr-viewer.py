"""Temporary loopback-only QR viewer. No credentials or login URLs are served."""
from http.server import ThreadingHTTPServer, BaseHTTPRequestHandler
import base64
import json
import threading
import time
from cpanel import CPanel

client = None
directory = '/home/dwhwlekshosting/telegram-mirror-thucunghaiza/storage'
cache = {'at': 0, 'state': {'status': 'starting'}, 'png': None}
guard = threading.Lock()


def refresh():
    with guard:
        if time.time() - cache['at'] < 3:
            return dict(cache['state']), cache['png']
        try:
            state = json.loads(client.read(directory, 'login-qr-state.json'))
            png = None
            if state.get('status') == 'waiting_qr' and state.get('expires_at', 0) > time.time():
                png = base64.b64decode(client.read(directory, 'login-qr.png.b64'), validate=True)
                if not png.startswith(b'\x89PNG\r\n\x1a\n'):
                    raise ValueError('Invalid QR image')
            cache.update(at=time.time(), state=state, png=png)
        except Exception:
            cache.update(at=time.time(), state={'status': 'connecting'}, png=None)
        return dict(cache['state']), cache['png']


HTML = '''<!doctype html><html lang="vi"><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><title>Đăng nhập Telegram cho tool</title><style>body{font:16px system-ui;margin:0;background:#f5f7fb;color:#182536}main{max-width:520px;margin:32px auto;padding:24px;background:white;border-radius:20px}h1{font-size:24px}img{width:100%;max-width:480px;height:auto}#status{font-weight:600}small{color:#657487}#count{font-variant-numeric:tabular-nums}</style><main><h1>Đăng nhập Telegram cho tool</h1><p>Trên điện thoại: <b>Telegram → Cài đặt → Thiết bị → Liên kết thiết bị máy tính</b>, rồi quét mã bên dưới.</p><p id="status">Đang kết nối…</p><img id="qr" alt="Mã QR đăng nhập Telegram" hidden><p id="count"></p><a href="http://127.0.0.1:8765/">Mở trang xác minh hai bước</a><small>Trang chỉ chạy trên máy của bạn. Mã QR tự cập nhật và chỉ dùng cho tool thucunghaiza.site.</small></main><script>let last=0;const labels={starting:'Đang kết nối với Telegram…',connecting:'Đang lấy trạng thái từ hosting…',waiting_qr:'Quét mã QR này để đăng nhập',success:'Đăng nhập và kiểm tra channel thành công.',needs_2fa:'QR đã được chấp nhận. Mở trang xác minh riêng trên máy này để nhập mật khẩu hai bước.',expired:'Lượt đăng nhập đã hết thời gian chờ.',failed:'Kết nối đăng nhập chưa hoàn tất.'};async function poll(){try{let s=await(await fetch('/status',{cache:'no-store'})).json();statusEl.textContent=labels[s.status]||'Đang chờ…';let remain=Math.max(0,(s.expires_at||0)-Math.floor(Date.now()/1000));qr.hidden=s.status!=='waiting_qr'||remain===0;if(!qr.hidden){if(last!==s.expires_at){last=s.expires_at;qr.src='/qr.png?r='+last;}count.textContent='Mã còn hiệu lực khoảng '+remain+' giây.';}else count.textContent='';}catch{statusEl.textContent='Trang QR đang tạm ngắt kết nối.';qr.hidden=true;}setTimeout(poll,1500);}const statusEl=document.getElementById('status');const qr=document.getElementById('qr');const count=document.getElementById('count');poll();</script></html>'''


class Handler(BaseHTTPRequestHandler):
    def setup(self):
        super().setup()
        self.connection.settimeout(10)

    def do_GET(self):
        # Exact Host also blocks DNS rebinding to the local QR endpoint.
        authority = '127.0.0.1:' + str(self.server.server_port)
        if (self.headers.get_all('Host') != [authority]
                or self.headers.get('Origin') not in {None, 'http://' + authority}
                or (self.headers.get('Sec-Fetch-Site') == 'cross-site' and self.path != '/')):
            self.send_error(403)
            return
        if self.path == '/':
            body, mime = HTML.encode(), 'text/html; charset=utf-8'
        elif self.path == '/status':
            state, _ = refresh()
            body, mime = json.dumps(state).encode(), 'application/json'
        elif self.path.startswith('/qr.png?'):
            _, body = refresh()
            if body is None:
                self.send_error(404)
                return
            mime = 'image/png'
        else:
            self.send_error(404)
            return
        self.send_response(200)
        self.send_header('Content-Type', mime)
        self.send_header('Cache-Control', 'no-store')
        self.send_header('X-Content-Type-Options', 'nosniff')
        self.send_header('Content-Security-Policy', "default-src 'self'; script-src 'unsafe-inline'; style-src 'unsafe-inline'; img-src 'self'; frame-ancestors 'none'")
        self.send_header('Content-Length', str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *_):
        pass


if __name__ == '__main__':
    client = CPanel()
    server = ThreadingHTTPServer(('127.0.0.1', 0), Handler)
    server.daemon_threads = True
    server.timeout = 1
    print('QR viewer: http://127.0.0.1:' + str(server.server_port), flush=True)
    deadline = time.time() + 1200
    while time.time() < deadline:
        server.handle_request()
    server.server_close()
