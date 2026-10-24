"""Authenticated hosting-clock calibration; synthetic HTTP responses only."""
import email.utils
from email.message import Message
import io
from pathlib import Path
import sys
import threading
import unittest
import urllib.error
from unittest.mock import Mock, patch

sys.path.insert(0, str(Path(__file__).resolve().parent.parent / 'deploy'))
import cpanel


class Response(io.BytesIO):
    def __init__(self, dates=None, url='https://panel.example:2083/execute/fixture', code=200):
        super().__init__(b'{"status":1}')
        self.headers = Message()
        for date in dates if dates is not None else [email.utils.formatdate(1166, usegmt=True)]:
            self.headers['Date'] = date
        self.url, self.code = url, code

    def geturl(self):
        return self.url

    def getcode(self):
        return self.code


class ClockTests(unittest.TestCase):
    def setUp(self):
        # Avoid credentials/config and construct only the clock/request boundary.
        self.client = object.__new__(cpanel.CPanel)
        self.client.base = 'https://panel.example:2083'
        self.client.headers = {'Authorization': 'cpanel synthetic:fake-token'}
        self.client.opener = Mock()
        self.client._clock_lock = threading.Lock()
        self.client._server_clock = None

    def calibrate(self, response=None, stamps=(100, 100)):
        self.client.opener.open.return_value = response if response is not None else Response()
        with patch.object(cpanel.time, 'monotonic', side_effect=stamps):
            self.assertEqual(self.client.request('/execute/fixture'), {'status': 1})

    def test_header_round_trip_midpoint_and_monotonic_progress(self):
        self.calibrate(stamps=(100, 102))
        with patch.object(cpanel.time, 'monotonic', return_value=103):
            self.assertEqual(self.client.server_now(), 1168)
        request = self.client.opener.open.call_args.args[0]
        self.assertEqual(request.full_url, self.client.base + '/execute/fixture')
        self.assertEqual(request.get_header('Authorization'), 'cpanel synthetic:fake-token')

    def test_remote_clock_can_be_166_seconds_ahead_of_local_utc(self):
        with patch.object(cpanel.time, 'time', return_value=1000):
            self.calibrate()
            with patch.object(cpanel.time, 'monotonic', return_value=100):
                self.assertEqual(self.client.server_now(), 1166)

    def test_uninitialized_clock_never_uses_desktop_time_or_network(self):
        with patch.object(cpanel.time, 'time', return_value=1166):
            with self.assertRaises(cpanel.ServerClockUnavailable):
                self.client.server_now()
        self.client.opener.open.assert_not_called()

    def test_missing_malformed_duplicate_and_noncanonical_dates_fail_closed(self):
        good = email.utils.formatdate(1166, usegmt=True)
        for dates in ([], ['bad date'], [good, good], [good.replace('GMT', '+0000')],
                      [good.replace('Thu', 'Fri')], [good + ', ' + good],
                      ['Thursday, 01-Jan-70 00:19:26 GMT'], ['Thu, 01 Jan 1970 00:19:26']):
            with self.subTest(dates=dates):
                self.calibrate(Response(dates=dates))
                with self.assertRaises(cpanel.ServerClockUnavailable):
                    self.client.server_now()

    def test_response_origin_and_success_status_are_required(self):
        for url, code in [('http://panel.example:2083/x', 200),
                          ('https://attacker.example:2083/x', 200),
                          ('https://panel.example:2084/x', 200),
                          ('https://user@panel.example:2083/x', 200),
                          ('https://panel.example:2083/x', 401)]:
            with self.subTest(url=url, code=code):
                self.calibrate(Response(url=url, code=code))
                with self.assertRaises(cpanel.ServerClockUnavailable):
                    self.client.server_now()

    def test_response_rtt_must_be_bounded_nonnegative_and_finite(self):
        for stamps in ((100, 105.001), (101, 100), (float('nan'), 100), (100, float('inf'))):
            with self.subTest(stamps=stamps):
                self.calibrate(stamps=stamps)
                with self.assertRaises(cpanel.ServerClockUnavailable):
                    self.client.server_now()
        self.calibrate(stamps=(100, 105))
        with patch.object(cpanel.time, 'monotonic', return_value=105):
            self.assertEqual(self.client.server_now(), 1168.5)

    def test_calibration_age_has_strict_thirty_second_limit(self):
        self.calibrate()
        with patch.object(cpanel.time, 'monotonic', return_value=130):
            self.assertEqual(self.client.server_now(), 1196)
        for stamp in (130.001, 99.999, float('nan'), float('inf')):
            with self.subTest(stamp=stamp), patch.object(cpanel.time, 'monotonic', return_value=stamp):
                with self.assertRaises(cpanel.ServerClockUnavailable):
                    self.client.server_now()

    def test_new_bad_response_invalidates_previous_good_sample(self):
        self.calibrate()
        self.calibrate(Response(dates=[]), stamps=(101, 101))
        with self.assertRaises(cpanel.ServerClockUnavailable):
            self.client.server_now()

    def test_network_failure_invalidates_previous_sample(self):
        self.calibrate()
        self.client.opener.open.side_effect = urllib.error.URLError('synthetic transport failure')
        with patch.object(cpanel.time, 'monotonic', return_value=101):
            with self.assertRaises(urllib.error.URLError):
                self.client.request('/execute/fixture')
        with self.assertRaises(cpanel.ServerClockUnavailable):
            self.client.server_now()

    def test_fresh_response_replaces_stale_calibration(self):
        self.calibrate()
        with patch.object(cpanel.time, 'monotonic', return_value=200):
            with self.assertRaises(cpanel.ServerClockUnavailable):
                self.client.server_now()
        self.calibrate(Response(dates=[email.utils.formatdate(1336, usegmt=True)]), stamps=(200, 200))
        with patch.object(cpanel.time, 'monotonic', return_value=200):
            self.assertEqual(self.client.server_now(), 1336)


if __name__ == '__main__':
    unittest.main()
