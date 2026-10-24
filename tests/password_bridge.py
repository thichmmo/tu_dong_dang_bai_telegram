"""Offline bridge regressions. Dummy credentials only; never call hosting."""
import importlib.util
import http.client
import json
from pathlib import Path
import shutil
import sys
import tempfile
import threading
import unittest
from unittest.mock import patch

ROOT = Path(__file__).resolve().parent.parent
sys.path.insert(0, str(ROOT / 'deploy'))
spec = importlib.util.spec_from_file_location('password_bridge', ROOT / 'deploy/password-bridge.py')
bridge = importlib.util.module_from_spec(spec)
spec.loader.exec_module(bridge)
RID = 'a' * 32


class FakeHosting:
    def __init__(self):
        self.files = {}
        self.crons = []
        self.fail = None
        self.read_failures = set()
        self.auto_probe = True
        self.calls = []
        self.clock_skew = 0
        self.clock_error = None

    def server_now(self):
        if self.clock_error is not None:
            raise self.clock_error
        return bridge.time.time() + self.clock_skew

    def read(self, directory, name):
        self.calls.append(('read', name))
        if name in self.read_failures:
            raise ConnectionError()
        value = self.files.get(name)
        if value is None:
            raise FileNotFoundError()
        return value if isinstance(value, str) else json.dumps(value)

    def uapi(self, module, action, **params):
        if action == 'list_files':
            self.calls.append((action, None))
            return [{'file': name} for name in self.files]
        self.calls.append((action, params['file']))
        if self.fail == 'metadata':
            raise ConnectionError()
        self.files[params['file']] = params['content']

    def upload(self, directory, paths):
        for path in paths:
            self.files[path.name] = path.read_text()
        if self.fail == 'upload':
            # The server accepted it, but the HTTP reply was lost.
            raise ConnectionError()
        return {'failed': 0, 'succeeded': len(paths)}

    def api2(self, module, action, **params):
        self.calls.append((action, params.get('command')))
        if action == 'listcron':
            return [{'count': i, 'command': command} for i, command in enumerate(self.crons)]
        if action == 'remove_line':
            del self.crons[params['line']]
        elif action == 'add_line':
            if self.fail == 'cron':
                raise ConnectionError()
            self.crons.append(params['command'])
            if self.auto_probe and '/login-gate-probe-' in params['command']:
                nonce = params['command'].split('/login-gate-probe-', 1)[1].split('.sh', 1)[0]
                request = self.files.get('login-2fa-request.json')
                state = self.files.get('login-2fa-state.json')
                safe = ((request is None and state is None)
                        or (request == {} and state in ({}, {'status': 'input'})))
                self.files['login-gate-probe-' + nonce + '.json'] = {
                    'request_id': nonce, 'lock_free': True,
                    'input_safe': safe and 'login-2fa-envelope.json' not in self.files,
                    'checked_at': int(self.server_now()),
                }
        return []


class BridgeTests(unittest.TestCase):
    def setUp(self):
        bridge.client = FakeHosting()
        bridge.client.files.update({'login-2fa-request.json': {}, 'login-2fa-state.json': {}})
        bridge.active = None
        bridge.attempts.clear()
        bridge.remote_sync_needed = True
        bridge.empty_verified = False
        bridge.empty_probe = None
        bridge.retired_requests.clear()
        bridge.START_WORKER_AFTER_LOGIN = True

    def test_recovery_login_does_not_start_worker_until_release(self):
        self.pending('success', expiry=1000)
        bridge.START_WORKER_AFTER_LOGIN = False
        bridge.client.crons = [bridge.request_command(RID)]
        self.assertEqual(bridge.status(), {'status': 'success'})
        self.assertNotIn(bridge.WATCH, bridge.client.crons)
        self.assertEqual(bridge.client.crons, [])
        self.assertEqual(bridge.status(), {'status': 'success'})
        self.assertNotIn(bridge.WATCH, bridge.client.crons)

    @patch.object(bridge.time, 'time', return_value=1000)
    def test_host_clock_skew_preserves_gate_and_remote_expiry_headroom(self, _):
        bridge.client.clock_skew = 166
        self.assertEqual(bridge.status(), {'status': 'input'})
        self.assertIsNone(bridge.empty_probe)
        # Stub encryption only; no actual password, config, Node or network.
        encrypted = type('Encrypted', (), {'stdout': ''})()
        def encrypt(*args, **kwargs):
            value = json.loads(kwargs['input'])
            self.assertEqual(value['expires_at'], 1336)
            self.assertEqual(value['expires_at'] - bridge.client.server_now(), 170)
            encrypted.stdout = json.dumps({'request_id': value['request_id'],
                                          'expires_at': value['expires_at']})
            return encrypted
        with tempfile.TemporaryDirectory() as temporary:
            root = Path(temporary)
            (root / 'config.php').write_text("<?php return ['api_hash' => '" + '0' * 32 + "'];")
            with patch.object(bridge, 'ROOT', root), \
                    patch.object(bridge.subprocess, 'run', side_effect=encrypt):
                bridge.submit('synthetic-only')
        self.assertEqual(bridge.active['expires_at'], 1336)
        self.assertEqual(bridge.active['release_at'], 1646)
        state = json.loads(bridge.client.files['login-2fa-state.json'])
        self.assertEqual(state['updated_at'], 1166)
        envelope = json.loads(bridge.client.files['login-2fa-envelope.json'])
        self.assertEqual(envelope['expires_at'], 1336)
        self.assertEqual(envelope['request_id'], bridge.active['request_id'])
        # Retry accounting stays on the local clock.
        self.assertEqual(bridge.attempts, [1000])

    @patch.object(bridge.time, 'time', return_value=1400)
    def test_host_clock_skew_controls_release_and_fresh_cleanup(self, _):
        bridge.client.clock_skew = 166
        self.pending('processing', expiry=1200)
        self.assertEqual(bridge.status()['status'], 'cancelling')
        self.assertEqual(bridge.active['cleanup_requested_at'], 1566)
        name = 'login-2fa-cleanup-' + RID + '.json'
        # Native proof is fresh on hosting even though it is future-dated here.
        bridge.client.files[name] = {'request_id': RID, 'status': 'expired', 'updated_at': 1566}
        self.assertEqual(bridge.status(), {'status': 'expired'})
        self.assertIsNone(bridge.active)
        self.assertEqual(bridge.client.crons, [])

    @patch.object(bridge.time, 'time', return_value=1100)
    def test_host_clock_skew_live_freshness_keeps_strict_thirty_second_gate(self, _):
        bridge.client.clock_skew = 166
        self.pending('success', expiry=1200)
        live = {'ready': True, 'worker_alive': True, 'paused': False,
                'all_targets_ready': True, 'generated_at': 1266,
                'metadata': {'worker_heartbeat_at': 1266}}
        bridge.client.files['live-status.json'] = live
        self.assertEqual(bridge.status(), {'status': 'ready'})
        live['generated_at'] = 1236
        self.assertEqual(bridge.status(), {'status': 'starting'})
        live['generated_at'] = 1267
        self.assertEqual(bridge.status(), {'status': 'starting'})

    def test_unavailable_host_clock_never_opens_input_or_uploads_envelope(self):
        for error in (RuntimeError('malformed sample'), RuntimeError('stale sample')):
            with self.subTest(error=str(error)):
                self.setUp(); bridge.client.clock_error = error
                self.assertEqual(bridge.status(), {'status': 'connection_error'})
                with self.assertRaises(bridge.RemoteUnavailable):
                    bridge.submit('synthetic-only')
                self.assertIsNone(bridge.active)
                self.assertEqual(bridge.attempts, [])
                self.assertNotIn('login-2fa-envelope.json', bridge.client.files)
                self.assertFalse(any(call[0] in {'add_line', 'save_file_content'}
                                     for call in bridge.client.calls))

    @patch.object(bridge.time, 'time', return_value=1400)
    def test_unavailable_host_clock_cannot_release_pending_request(self, _):
        self.pending('processing', expiry=1)
        bridge.client.clock_error = RuntimeError('stale sample')
        self.assertEqual(bridge.status(), {'status': 'connection_error'})
        self.assertEqual(bridge.active['request_id'], RID)
        self.assertFalse(bridge.active.get('retiring'))
        self.assertFalse(any(call[0] in {'add_line', 'save_file_content'}
                             for call in bridge.client.calls))

    def pending(self, phase='pending', expiry=1000):
        bridge.client.files['login-2fa-request.json'] = {'request_id': RID, 'expires_at': expiry}
        bridge.client.files['login-2fa-state.json'] = {'request_id': RID, 'status': phase}
        bridge.restore_active()

    def test_cold_missing_and_empty_require_native_proof(self):
        for files in ({}, {'login-2fa-request.json': {}, 'login-2fa-state.json': {}},
                      {'login-2fa-request.json': {}, 'login-2fa-state.json': {'status': 'input'}}):
            with self.subTest(files=files):
                self.setUp(); bridge.client.files = files.copy()
                bridge.client.auto_probe = False
                bridge.restore_active()
                self.assertEqual(bridge.status(), {'status': 'connection_error'})
                self.assertIsNone(bridge.active)
                nonce = bridge.empty_probe['request_id']
                proof_name = 'login-gate-probe-' + nonce + '.json'
                bridge.client.files[proof_name] = {'request_id': nonce, 'lock_free': True,
                                                 'input_safe': True, 'checked_at': int(bridge.time.time())}
                self.assertEqual(bridge.status(), {'status': 'input'})
                self.assertEqual(bridge.client.crons, [])

    def test_cold_malformed_stays_closed_until_repaired_and_proved(self):
        for value in (None, '{broken', '[]', 'null', '"text"'):
            with self.subTest(value=value):
                self.setUp()
                bridge.client.files = {} if value is None else {'login-2fa-state.json': value, 'login-2fa-request.json': value}
                bridge.client.auto_probe = False
                bridge.restore_active()
                self.assertEqual(bridge.status(), {'status': 'connection_error'})
                self.assertIsNone(bridge.active)
                bridge.client.files.update({'login-2fa-request.json': {}, 'login-2fa-state.json': {}})
                bridge.status()
                nonce = bridge.empty_probe['request_id']
                bridge.client.files['login-gate-probe-' + nonce + '.json'] = {
                    'request_id': nonce, 'lock_free': True, 'input_safe': True,
                    'checked_at': int(bridge.time.time()),
                }
                self.assertEqual(bridge.status(), {'status': 'input'})

    @patch.object(bridge.time, 'time', return_value=1100)
    def test_stale_success_never_retired(self, _):
        self.pending('success', 1)
        self.assertEqual(bridge.status(), {'status': 'starting'})
        self.assertIn(bridge.WATCH, bridge.client.crons)
        self.assertFalse(bridge.active.get('retiring'))
        bridge.status()
        self.assertEqual(bridge.client.crons.count(bridge.WATCH), 1)

    @patch.object(bridge.time, 'time', return_value=1100)
    def test_paused_wins_and_dead_worker_not_ready(self, _):
        self.pending('success')
        live = {'ready': True, 'worker_alive': True, 'paused': True, 'all_targets_ready': True, 'generated_at': 1100,
                'metadata': {'worker_heartbeat_at': 1100}}
        bridge.client.files['live-status.json'] = live
        self.assertEqual(bridge.status()['status'], 'paused')
        live['paused'] = False; live['worker_alive'] = False
        self.assertEqual(bridge.status()['status'], 'starting')
        live['worker_alive'] = True; live['generated_at'] = 1000
        self.assertEqual(bridge.status()['status'], 'starting')
        live['generated_at'] = 1101
        self.assertEqual(bridge.status()['status'], 'starting')
        live['generated_at'] = 1100
        self.assertEqual(bridge.status()['status'], 'ready')
        live['all_targets_ready'] = False
        self.assertEqual(bridge.status()['status'], 'partial')
        live['ready'] = False; live['receiving_ready'] = True
        self.assertEqual(bridge.status()['status'], 'paused')

    @patch.object(bridge.time, 'time', return_value=1400)
    def test_pending_missing_mismatched_and_unknown_are_bounded(self, _):
        for phase in ('pending', 'processing'):
            with self.subTest(phase=phase):
                self.setUp(); self.pending(phase, 1000)
                self.assertEqual(bridge.status()['status'], 'cancelling')
                self.assertTrue(bridge.active['cleanup_scheduled'])
                self.assertNotIn(bridge.WATCH, bridge.client.crons)
                self.assertIsNotNone(bridge.active)
                bridge.client.files['login-2fa-cleanup-' + RID + '.json'] = {'request_id': RID, 'status': 'busy'}
                self.assertEqual(bridge.status()['status'], 'cancelling')
                bridge.client.files['login-2fa-cleanup-' + RID + '.json'] = {'request_id': RID, 'status': 'expired', 'updated_at': 1400}
                self.assertEqual(bridge.status()['status'], 'expired')
                self.assertIsNone(bridge.active)
                self.assertEqual(bridge.client.crons, [])

    @patch.object(bridge.time, 'time', return_value=1100)
    def test_cancel_restart_preserves_unrelated_cron(self, _):
        self.pending('processing')
        bridge.client.crons.append('unrelated existing job')
        bridge.begin_retirement('cancelled')
        bridge.restore_active()
        self.assertEqual(bridge.status()['status'], 'cancelling')
        bridge.client.files['login-2fa-cleanup-' + RID + '.json'] = {'request_id': RID, 'status': 'cancelled', 'updated_at': 1100}
        self.assertEqual(bridge.status()['status'], 'cancelled')
        self.assertEqual(bridge.client.crons, ['unrelated existing job'])
        self.assertEqual(bridge.status()['status'], 'input')

    @patch.object(bridge.time, 'time', return_value=1400)
    def test_foreign_request_never_overwritten(self, _):
        self.pending('pending', 1000)
        foreign = {'request_id': 'b' * 32, 'expires_at': 1500}
        bridge.client.files['login-2fa-request.json'] = foreign.copy()
        self.assertEqual(bridge.status()['failure_code'], 'request_conflict')
        self.assertEqual(bridge.client.files['login-2fa-request.json'], foreign)
        self.assertNotIn(bridge.WATCH, bridge.client.crons)

    def test_lost_upload_metadata_and_cron_gate_then_retirement(self):
        with tempfile.TemporaryDirectory() as directory:
            dummy = Path(directory); (dummy / 'deploy').mkdir()
            (dummy / 'config.php').write_text("<?php return ['api_hash' => '" + '0' * 32 + "'];")
            shutil.copy(ROOT / 'deploy/encrypt-2fa.cjs', dummy / 'deploy/encrypt-2fa.cjs')
            with patch.object(bridge, 'ROOT', dummy):
                for failure in ('upload', 'metadata', 'cron'):
                    with self.subTest(failure=failure):
                        self.setUp(); bridge.status(); bridge.client.fail = failure
                        with self.assertRaises(ConnectionError): bridge.submit('synthetic-only')
                        self.assertIsNotNone(bridge.active)
                        self.assertNotIn('synthetic-only', bridge.client.files['login-2fa-envelope.json'])
                        with self.assertRaises(RuntimeError): bridge.submit('synthetic-only')
                        bridge.client.fail = None
                        bridge.begin_retirement('cancelled')
                        rid = bridge.active['request_id']
                        bridge.client.files['login-2fa-cleanup-' + rid + '.json'] = {'request_id': rid, 'status': 'cancelled', 'updated_at': int(bridge.time.time())}
                        self.assertEqual(bridge.status()['status'], 'cancelled')
                        self.assertIsNone(bridge.active)

    def test_retry_limit_before_encryption_or_upload(self):
        bridge.attempts[:] = [bridge.time.time()] * 5
        with self.assertRaises(bridge.RetryLimited): bridge.submit('synthetic-only')
        self.assertEqual(bridge.client.calls, [])

    @patch.object(bridge.time, 'time', return_value=1400)
    def test_transport_failure_preserves_pending_or_processing_binding(self, _):
        for phase in ('pending', 'processing'):
            for broken in ('login-2fa-request.json', 'login-2fa-state.json'):
                with self.subTest(phase=phase, broken=broken):
                    self.setUp(); self.pending(phase, 1000)
                    before = bridge.client.files['login-2fa-request.json'].copy()
                    bridge.client.read_failures.add(broken)
                    self.assertEqual(bridge.status(), {'status': 'connection_error'})
                    with self.assertRaises(bridge.RemoteReadFailure): bridge.submit('synthetic-only')
                    self.assertEqual(bridge.active['request_id'], RID)
                    self.assertEqual(bridge.client.files['login-2fa-request.json'], before)
                    self.assertNotIn(bridge.WATCH, bridge.client.crons)
                    self.assertFalse(any(call[0] in {'save_file_content', 'add_line'} for call in bridge.client.calls))
                    bridge.client.read_failures.clear()
                    self.assertEqual(bridge.status()['status'], 'cancelling')
                    with self.assertRaises(RuntimeError): bridge.submit('synthetic-only')
                    self.assertEqual(bridge.active['request_id'], RID)

    @patch.object(bridge.time, 'time', return_value=1100)
    def test_cold_transport_recovery_restores_existing_request_without_overwrite(self, _):
        for phase in ('pending', 'processing', 'success'):
            with self.subTest(phase=phase):
                self.setUp()
                bridge.client.files['login-2fa-request.json'] = {'request_id': RID, 'expires_at': 1000}
                bridge.client.files['login-2fa-state.json'] = {'request_id': RID, 'status': phase}
                bridge.client.read_failures.update({'login-2fa-request.json', 'login-2fa-state.json'})
                self.assertFalse(bridge.restore_active())
                self.assertEqual(bridge.status(), {'status': 'connection_error'})
                with self.assertRaises(bridge.RemoteReadFailure): bridge.submit('synthetic-only')
                self.assertIsNone(bridge.active)
                bridge.client.read_failures.clear()
                recovered = bridge.status()['status']
                self.assertEqual(recovered, 'starting' if phase == 'success' else phase)
                self.assertEqual(bridge.active['request_id'], RID)
                with self.assertRaises(RuntimeError): bridge.submit('synthetic-only')
                self.assertEqual(bridge.client.files['login-2fa-request.json']['request_id'], RID)
                self.assertFalse(any(call[0] == 'save_file_content' for call in bridge.client.calls))

    def test_missing_state_or_malformed_status_never_expires_unknown_success(self):
        for broken in (None, '{broken', {'request_id': RID, 'status': 'unknown'}):
            with self.subTest(broken=broken):
                self.setUp(); self.pending('processing', 1)
                if broken is None: del bridge.client.files['login-2fa-state.json']
                else: bridge.client.files['login-2fa-state.json'] = broken
                self.assertEqual(bridge.status(), {'status': 'connection_error'})
                self.assertEqual(bridge.active['request_id'], RID)
                self.assertFalse(bridge.active.get('retiring'))
                with self.assertRaises(bridge.RemoteReadFailure): bridge.submit('synthetic-only')
                self.assertFalse(any(call[0] == 'save_file_content' for call in bridge.client.calls))

    def test_unknown_to_empty_requires_fresh_matching_native_lock_proof(self):
        bridge.client.auto_probe = False
        bridge.client.read_failures.add('login-2fa-request.json')
        self.assertFalse(bridge.restore_active())
        bridge.client.read_failures.clear()
        self.assertEqual(bridge.status(), {'status': 'connection_error'})
        nonce = bridge.empty_probe['request_id']
        name = 'login-gate-probe-' + nonce + '.json'
        good = {'request_id': nonce, 'lock_free': True, 'input_safe': True, 'checked_at': int(bridge.time.time())}
        for change in ({'request_id': 'b' * 32}, {'lock_free': False}, {'input_safe': False},
                       {'checked_at': int(bridge.time.time()) - 31}, {'checked_at': int(bridge.time.time()) + 1}):
            with self.subTest(change=change):
                bridge.client.files[name] = {**good, **change}
                self.assertEqual(bridge.status(), {'status': 'connection_error'})
                with self.assertRaises(bridge.RemoteReadFailure): bridge.submit('synthetic-only')
                self.assertNotIn('login-2fa-envelope.json', bridge.client.files)
        bridge.client.files[name] = good
        self.assertEqual(bridge.status(), {'status': 'input'})
        self.assertEqual(bridge.client.crons, [])

    def test_input_read_failure_recloses_gate_and_requires_new_probe(self):
        self.assertEqual(bridge.status(), {'status': 'input'})
        bridge.client.read_failures.add('login-2fa-request.json')
        self.assertEqual(bridge.status(), {'status': 'connection_error'})
        with self.assertRaises(bridge.RemoteReadFailure): bridge.submit('synthetic-only')
        bridge.client.read_failures.clear(); bridge.client.auto_probe = False
        self.assertEqual(bridge.status(), {'status': 'connection_error'})
        self.assertIsNotNone(bridge.empty_probe)
        self.assertNotIn('login-2fa-envelope.json', bridge.client.files)

    def test_generic_cpanel_error_is_missing_only_with_verified_listing(self):
        with patch.object(bridge.client, 'read', side_effect=RuntimeError('synthetic UAPI error')):
            with self.assertRaises(bridge.RemoteUnavailable): bridge.read_json('login-2fa-request.json')
            del bridge.client.files['login-2fa-request.json']
            with self.assertRaises(bridge.RemoteMissing): bridge.read_json('login-2fa-request.json')
            with patch.object(bridge.client, 'uapi', side_effect=ConnectionError()):
                with self.assertRaises(bridge.RemoteUnavailable): bridge.read_json('login-2fa-request.json')

    @patch.object(bridge.time, 'time', return_value=1400)
    def test_success_observed_after_expiry_retirement_is_preserved(self, _):
        self.pending('pending', 1)
        self.assertEqual(bridge.status()['status'], 'cancelling')
        cleanup_command = bridge.active['cleanup_command']
        bridge.client.files['login-2fa-state.json'] = {'request_id': RID, 'status': 'success'}
        self.assertEqual(bridge.status()['status'], 'starting')
        self.assertFalse(bridge.active.get('retiring'))
        self.assertNotIn(cleanup_command, bridge.client.crons)
        bridge.active = None
        bridge.restore_active()
        self.assertEqual(bridge.status()['status'], 'starting')
        self.assertFalse(bridge.active.get('retiring'))

    @patch.object(bridge.time, 'time', return_value=1400)
    def test_stale_cleanup_ack_cannot_reopen_input(self, _):
        self.pending('processing', 1)
        self.assertEqual(bridge.status()['status'], 'cancelling')
        bridge.client.files['login-2fa-cleanup-' + RID + '.json'] = {
            'request_id': RID, 'status': 'expired', 'updated_at': 1300,
        }
        self.assertEqual(bridge.status()['status'], 'cancelling')
        with self.assertRaises(RuntimeError): bridge.submit('synthetic-only')
        self.assertEqual(bridge.active['request_id'], RID)

    def test_http_read_failure_keeps_server_alive_and_submission_closed(self):
        bridge.client.read_failures.update({'login-2fa-request.json', 'login-2fa-state.json'})
        server = bridge.ThreadingHTTPServer(('127.0.0.1', 0), bridge.Handler)
        server.daemon_threads = True
        thread = threading.Thread(target=server.serve_forever, daemon=True); thread.start()
        authority = '127.0.0.1:' + str(server.server_port)

        def get_status():
            connection = http.client.HTTPConnection('127.0.0.1', server.server_port, timeout=3)
            connection.request('GET', '/status', headers={'Host': authority, 'Sec-Fetch-Site': 'same-origin'})
            response = connection.getresponse(); body = json.loads(response.read()); connection.close()
            return body

        def verify():
            connection = http.client.HTTPConnection('127.0.0.1', server.server_port, timeout=3)
            body = json.dumps({'csrf': bridge.CSRF, 'password': 'synthetic-only'})
            connection.request('POST', '/verify', body=body, headers={'Host': authority,
                               'Origin': 'http://' + authority, 'Sec-Fetch-Site': 'same-origin',
                               'Content-Type': 'application/json'})
            response = connection.getresponse(); result = (response.status, json.loads(response.read()))
            connection.close(); return result

        try:
            self.assertEqual(get_status(), {'status': 'connection_error'})
            self.assertEqual(verify(), (503, {'status': 'connection_error'}))
            self.assertNotIn('login-2fa-envelope.json', bridge.client.files)
            bridge.client.read_failures.clear()
            remote = {'request_id': RID, 'expires_at': int(bridge.time.time()) + 180}
            bridge.client.files['login-2fa-request.json'] = remote.copy()
            bridge.client.files['login-2fa-state.json'] = {'request_id': RID, 'status': 'processing'}
            self.assertEqual(get_status(), {'status': 'processing'})
            self.assertEqual(verify()[0], 503)
            self.assertEqual(bridge.active['request_id'], RID)
            self.assertEqual(bridge.client.files['login-2fa-request.json'], remote)
            self.assertNotIn(bridge.WATCH, bridge.client.crons)
        finally:
            server.shutdown(); server.server_close(); thread.join(3)

    def test_http_origin_host_csrf_and_body_guards(self):
        server = bridge.ThreadingHTTPServer(('127.0.0.1', 0), bridge.Handler)
        server.daemon_threads = True
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        authority = '127.0.0.1:' + str(server.server_port)
        payload = json.dumps({'csrf': bridge.CSRF, 'password': 'synthetic-only'}).encode()

        def send(extra=None, body=payload, path='/verify', duplicate_host=False, duplicate_length=False):
            connection = http.client.HTTPConnection('127.0.0.1', server.server_port, timeout=3)
            connection.putrequest('POST', path, skip_host=True)
            headers = {'Host': authority, 'Origin': 'http://' + authority,
                       'Sec-Fetch-Site': 'same-origin', 'Content-Type': 'application/json',
                       'Content-Length': str(len(body))}
            headers.update(extra or {})
            for key, value in headers.items():
                if value is not None: connection.putheader(key, value)
            if duplicate_host: connection.putheader('Host', authority)
            if duplicate_length: connection.putheader('Content-Length', str(len(body)))
            connection.endheaders(body)
            response = connection.getresponse(); code = response.status
            response.read(); connection.close()
            return code

        try:
            with patch.object(bridge, 'submit') as submit:
                for change in ({'Host': 'attacker.example'}, {'Origin': 'https://attacker.example'},
                               {'Origin': None}, {'Sec-Fetch-Site': 'cross-site'},
                               {'Content-Length': '32769'}, {'Transfer-Encoding': 'chunked'}):
                    self.assertEqual(send(change), 403)
                self.assertEqual(send(duplicate_host=True), 403)
                self.assertEqual(send(duplicate_length=True), 403)
                self.assertEqual(send(body=b'{"password":"synthetic-only"}'), 403)
                self.assertEqual(send(body=b'{"csrf":"wrong","password":"synthetic-only"}'), 403)
                self.assertEqual(send(body=json.dumps({'csrf': bridge.CSRF, 'password': ''}).encode()), 400)
                self.assertEqual(send(body=json.dumps({'csrf': bridge.CSRF, 'password': 'x' * 4097}).encode()), 400)
                self.assertEqual(send(body=json.dumps({'csrf': bridge.CSRF, 'password': '日' * 1400}).encode()), 400)
                self.assertEqual(send(path='/unknown'), 403)
                submit.assert_not_called()
                self.assertEqual(send(), 202)
                submit.assert_called_once_with('synthetic-only')
        finally:
            server.shutdown(); server.server_close(); thread.join(3)


if __name__ == '__main__':
    unittest.main()
