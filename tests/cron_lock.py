"""Offline OS-lock and cPanel indexed-delete guards; synthetic cron rows only."""
import json
import os
import subprocess
import sys
import tempfile
import threading
import unittest
import urllib.parse
from pathlib import Path
from unittest.mock import patch

DEPLOY = Path(__file__).resolve().parents[1] / 'deploy'
sys.path.insert(0, str(DEPLOY))
import cpanel
import cron_lock


class CronLockTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory();self.addCleanup(self.temp.cleanup)
        self.path = Path(self.temp.name) / 'writer.lock'
        self.patcher = patch.object(cron_lock, 'DEFAULT_LOCK', self.path)
        self.patcher.start();self.addCleanup(self.patcher.stop)

    def test_reentrant_same_thread_but_other_thread_rejected(self):
        errors=[]
        with cron_lock.cron_writer():
            with cron_lock.cron_writer():
                def attempt():
                    try:
                        with cron_lock.cron_writer():pass
                    except cron_lock.CronWriterBusy:errors.append('busy')
                thread=threading.Thread(target=attempt);thread.start();thread.join(2)
                self.assertEqual(errors,['busy'])
        with cron_lock.cron_writer():pass
        self.assertEqual(self.path.stat().st_mode & 0o777,0o600)

    def test_other_process_cannot_enter(self):
        code="import sys;sys.path.insert(0,sys.argv[1]);from cron_lock import cron_writer,CronWriterBusy\ntry:\n with cron_writer(sys.argv[2]): print('entered')\nexcept CronWriterBusy: print('busy')"
        with cron_lock.cron_writer():
            child=subprocess.run([sys.executable,'-c',code,str(DEPLOY),str(self.path)],capture_output=True,text=True,timeout=5)
            self.assertEqual(child.returncode,0);self.assertEqual(child.stdout.strip(),'busy')

    def test_cpanel_mutator_in_other_thread_uses_the_same_lock(self):
        client,calls=self.mock_client([]);errors=[]
        def mutate():
            try:client.api2('Cron','add_line',command='synthetic-job',minute='*')
            except cron_lock.CronWriterBusy:errors.append('busy')
        with cron_lock.cron_writer():
            thread=threading.Thread(target=mutate);thread.start();thread.join(2)
            self.assertEqual(errors,['busy']);self.assertEqual(calls,[])

    def test_symlink_lock_rejected_without_touching_target(self):
        target=self.path.with_name('target');target.write_text('synthetic')
        self.path.symlink_to(target)
        with self.assertRaises(OSError):
            with cron_lock.cron_writer():pass
        self.assertEqual(target.read_text(),'synthetic')

    def mock_client(self,rows):
        client=object.__new__(cpanel.CPanel);calls=[]
        def request(path,**kwargs):
            args={k:v[0] for k,v in urllib.parse.parse_qs(kwargs['body'].decode()).items()}
            calls.append(args)
            data=rows if args['cpanel_jsonapi_func']=='listcron' else [{'status':1}]
            return {'cpanelresult':{'event':{'result':1},'data':data}}
        client.request=request
        return client,calls

    def test_missing_identity_never_calls_delete(self):
        client,calls=self.mock_client([])
        with self.assertRaisesRegex(RuntimeError,'EXACT_CRON_IDENTITY_REQUIRED'):
            client.api2('Cron','remove_line',line=1)
        self.assertEqual(calls,[])

    def test_interleaving_row_shift_is_rejected_before_delete(self):
        before=[{'count':1,'command':'owned'},{'count':2,'command':'watch'}]
        after=[{'count':1,'command':'watch'},{'count':2,'command':'owned'}]
        self.assertEqual(cron_lock.cron_digest(before),cron_lock.cron_digest(after))
        client,calls=self.mock_client(after)
        with self.assertRaisesRegex(RuntimeError,'CRON_IDENTITY_CHANGED_BEFORE_DELETE'):
            client.api2('Cron','remove_line',line=1,expected_command='owned',expected_cron_digest=cron_lock.cron_digest(before))
        self.assertEqual([c['cpanel_jsonapi_func'] for c in calls],['listcron'])

    def test_guard_fields_not_sent_to_api_and_external_digest_change_rejected(self):
        rows=[{'count':1,'command':'owned'},{'count':2,'command':'watch'}]
        client,calls=self.mock_client(rows)
        client.api2('Cron','remove_line',line=1,expected_command='owned',expected_cron_digest=cron_lock.cron_digest(rows))
        self.assertEqual([c['cpanel_jsonapi_func'] for c in calls],['listcron','remove_line'])
        self.assertNotIn('expected_command',calls[-1]);self.assertNotIn('expected_cron_digest',calls[-1])
        changed=rows+[{'count':3,'command':'unrelated-new'}];client,calls=self.mock_client(changed)
        with self.assertRaisesRegex(RuntimeError,'CRON_IDENTITY_CHANGED_BEFORE_DELETE'):
            client.api2('Cron','remove_line',line=1,expected_command='owned',expected_cron_digest=cron_lock.cron_digest(rows))
        self.assertEqual([c['cpanel_jsonapi_func'] for c in calls],['listcron'])


if __name__=='__main__':unittest.main()
