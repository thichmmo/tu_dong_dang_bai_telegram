"""Local deployment helper. Credentials are read from host.txt, never echoed."""
from pathlib import Path
import json
import mimetypes
import urllib.parse
import urllib.request
import uuid
from cron_lock import cron_digest, cron_writer

ROOT = Path(__file__).resolve().parent.parent


class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, *_):
        # Never forward the cPanel Authorization header to a redirect target.
        raise RuntimeError('API redirects are not allowed')


class CPanel:
    def __init__(self):
        lines = (ROOT / 'host.txt').read_text().splitlines()
        self.base = lines[0].strip().rstrip('/')
        parsed = urllib.parse.urlsplit(self.base)
        if (parsed.scheme != 'https' or not parsed.hostname or parsed.username is not None
                or parsed.password is not None or parsed.path not in {'', '/'}
                or parsed.query or parsed.fragment or any(c.isspace() for c in self.base)):
            raise ValueError('A direct HTTPS cPanel origin is required')
        if parsed.port is not None and not 1 <= parsed.port <= 65535:
            raise ValueError('Invalid cPanel port')
        self.opener = urllib.request.build_opener(NoRedirect())
        self.headers = {'Authorization': 'cpanel ' + lines[1].strip() + ':' + lines[2].strip(), 'Accept': 'application/json'}

    def request(self, path, params=None, body=None, content_type=None, timeout=45):
        url = self.base + path
        if params:
            url += '?' + urllib.parse.urlencode(params)
        headers = dict(self.headers)
        if content_type:
            headers['Content-Type'] = content_type
        with self.opener.open(urllib.request.Request(url, data=body, headers=headers), timeout=timeout) as response:
            return json.load(response)

    def uapi(self, module, function, **params):
        # File contents may include private configuration. Keep mutation
        # payloads out of URL/query access logs by sending an HTTPS form body.
        if module == 'Fileman' and function == 'save_file_content':
            result = self.request('/execute/' + module + '/' + function,
                                  body=urllib.parse.urlencode(params).encode(),
                                  content_type='application/x-www-form-urlencoded')
        else:
            result = self.request('/execute/' + module + '/' + function, params)
        result = result.get('result', result)
        if result.get('status') != 1:
            raise RuntimeError(str(result.get('errors') or result.get('messages') or 'UAPI failed'))
        return result.get('data')

    def api2(self, module, function, **params):
        if module == 'Cron' and function not in {'listcron', 'fetchcron', 'get_email'}:
            with cron_writer():
                if function == 'remove_line':
                    expected = params.pop('expected_command', None)
                    expected_digest = params.pop('expected_cron_digest', None)
                    if not isinstance(expected, str) or not isinstance(expected_digest, str):
                        raise RuntimeError('EXACT_CRON_IDENTITY_REQUIRED')
                    rows = self.api2('Cron', 'listcron')
                    matching = [r for r in rows if str(r.get('count')) == str(params.get('line'))]
                    if (cron_digest(rows) != expected_digest or len(matching) != 1
                            or matching[0].get('command') != expected):
                        raise RuntimeError('CRON_IDENTITY_CHANGED_BEFORE_DELETE')
                return self._api2(module, function, **params)
        return self._api2(module, function, **params)

    def _api2(self, module, function, **params):
        arguments = {'cpanel_jsonapi_apiversion': 2, 'cpanel_jsonapi_module': module, 'cpanel_jsonapi_func': function, **params}
        result = self.request('/json-api/cpanel', body=urllib.parse.urlencode(arguments).encode(),
                              content_type='application/x-www-form-urlencoded')['cpanelresult']
        if result.get('error') or result.get('event', {}).get('result') != 1:
            raise RuntimeError(str(result.get('error') or result.get('event')))
        for item in result.get('data') or []:
            if item.get('result') == 0 or item.get('status') == 0:
                raise RuntimeError(str(item.get('reason') or item.get('error') or item))
        return result.get('data')

    def mkdir(self, parent, name):
        return self.api2('Fileman', 'mkdir', path=parent, name=name, permissions='0700')

    def upload(self, directory, paths, permissions='0600'):
        boundary = 'TelegramMirror' + uuid.uuid4().hex
        chunks = []
        def field(name, data, filename=None):
            chunks.append(('--' + boundary + '\r\n').encode())
            disposition = 'Content-Disposition: form-data; name="' + name + '"'
            if filename:
                disposition += '; filename="' + filename + '"'
            chunks.append((disposition + '\r\n').encode())
            if filename:
                chunks.append(b'Content-Type: application/octet-stream\r\n')
            chunks.extend([b'\r\n', data, b'\r\n'])
        for name, value in {'dir': directory, 'overwrite': '0', 'permissions': permissions}.items():
            field(name, value.encode())
        for index, path in enumerate(paths, 1):
            path = Path(path)
            field('file-' + str(index), path.read_bytes(), path.name)
        chunks.append(('--' + boundary + '--\r\n').encode())
        result = self.request('/execute/Fileman/upload_files', body=b''.join(chunks), content_type='multipart/form-data; boundary=' + boundary)
        if result.get('status') != 1:
            raise RuntimeError(str(result.get('errors') or result.get('messages')))
        return result.get('data')

    def read(self, directory, name):
        data = self.uapi('Fileman', 'get_file_content', dir=directory, file=name, to_charset='utf-8', update_html_document_encoding=0)
        return data.get('content') if isinstance(data, dict) else data
