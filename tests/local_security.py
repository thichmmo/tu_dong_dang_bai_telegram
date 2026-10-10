"""Local HTTP and API boundary checks; only synthetic credentials/artifacts."""
import http.client
import importlib.util
import io
from email.message import Message
from pathlib import Path
import sys
import tempfile
import threading
import unittest
import urllib.request
from unittest.mock import patch

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / 'deploy'))
import cpanel
spec = importlib.util.spec_from_file_location('qr_viewer', ROOT / 'deploy/qr-viewer.py')
qr = importlib.util.module_from_spec(spec); spec.loader.exec_module(qr)


class LocalSecurityTests(unittest.TestCase):
    def test_https_only_and_direct_origins(self):
        with tempfile.TemporaryDirectory() as directory:
            root = Path(directory)
            with patch.object(cpanel, 'ROOT', root):
                for origin in ('http://panel.example:2083', 'https://user:pass@panel.example',
                               'https://panel.example/path', 'https://panel.example?key=x',
                               'https://panel.example#x', 'https://panel.example:65536', 'file:///tmp/host'):
                    with self.subTest(origin=origin):
                        (root / 'host.txt').write_text(origin + '\nsynthetic\nfake-token\n')
                        with self.assertRaises(ValueError): cpanel.CPanel()
                (root / 'host.txt').write_text('https://panel.example:2083\nsynthetic\nfake-token\n')
                client = cpanel.CPanel()
                self.assertEqual(client.base, 'https://panel.example:2083')

    def test_redirect_refused_before_any_auth_forward(self):
        request = urllib.request.Request('https://panel.example:2083/execute/Fileman/get_file_content',
                                         headers={'Authorization': 'cpanel synthetic:fake-token'})
        for target in ('http://panel.example/elsewhere', 'https://attacker.example/stolen',
                       'https://panel.example:2083/elsewhere'):
            with self.subTest(target=target):
                headers = Message(); headers['Location'] = target
                with self.assertRaisesRegex(RuntimeError, 'redirects are not allowed'):
                    cpanel.NoRedirect().http_error_302(request, io.BytesIO(b''), 302, 'Found', headers)

    def test_private_file_mutation_uses_body(self):
        client = object.__new__(cpanel.CPanel)
        with patch.object(client, 'request', return_value={'status': 1, 'data': {}}) as request:
            client.uapi('Fileman', 'save_file_content', dir='/private', file='fixture.php', content='synthetic-only')
            args, kwargs = request.call_args
            self.assertEqual(len(args), 1)
            self.assertNotIn('synthetic-only', args[0])
            self.assertIn(b'synthetic-only', kwargs['body'])

    def test_qr_host_origin_and_fetch_guards(self):
        server = qr.ThreadingHTTPServer(('127.0.0.1', 0), qr.Handler)
        server.daemon_threads = True
        thread = threading.Thread(target=server.serve_forever, daemon=True); thread.start()
        authority = '127.0.0.1:' + str(server.server_port)

        def get(path='/status', headers=None, duplicate=False):
            connection = http.client.HTTPConnection('127.0.0.1', server.server_port, timeout=3)
            connection.putrequest('GET', path, skip_host=True)
            connection.putheader('Host', (headers or {}).get('Host', authority))
            if duplicate: connection.putheader('Host', authority)
            for key, value in (headers or {}).items():
                if key != 'Host': connection.putheader(key, value)
            connection.endheaders(); response = connection.getresponse()
            result = response.status; response.read(); connection.close(); return result

        try:
            with patch.object(qr, 'refresh', return_value=({'status': 'expired'}, None)) as refresh:
                self.assertEqual(get(headers={'Host': 'attacker.example'}), 403)
                self.assertEqual(get('/', {'Host': 'attacker.example'}), 403)
                self.assertEqual(get(duplicate=True), 403)
                self.assertEqual(get(headers={'Origin': 'https://attacker.example'}), 403)
                self.assertEqual(get(headers={'Sec-Fetch-Site': 'cross-site'}), 403)
                refresh.assert_not_called()
                self.assertEqual(get(), 200)
                refresh.assert_called_once()
        finally:
            server.shutdown(); server.server_close(); thread.join(3)


if __name__ == '__main__': unittest.main()
