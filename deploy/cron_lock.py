"""Single local cron writer shared by every tool controller, never a host lock.

cPanel API2 remove_line has no atomic command/CAS parameter. Its caller must
also ensure no external cPanel UI, other host or server script edits cron during
the final identity check and delete. This lock enforces local tool writers only.
"""
import contextlib
import fcntl
import functools
import hashlib
import json
import os
import stat
import threading
from pathlib import Path

DEFAULT_LOCK = Path(__file__).resolve().parent.parent / 'storage/local-cron-writer.lock'
_held = threading.local()


class CronWriterBusy(RuntimeError):
    pass


def cron_digest(rows):
    canonical = sorted(json.dumps({k: v for k, v in r.items()
                                   if k not in {'count', 'linekey', 'command_htmlsafe'}},
                                  sort_keys=True, separators=(',', ':')) for r in rows)
    return hashlib.sha256(json.dumps(canonical, separators=(',', ':')).encode()).hexdigest()


@contextlib.contextmanager
def cron_writer(lock_path=None):
    path = Path(lock_path) if lock_path is not None else DEFAULT_LOCK
    path.parent.mkdir(mode=0o700, parents=True, exist_ok=True)
    key = str(path.absolute())
    holders = getattr(_held, 'holders', {})
    _held.holders = holders
    prior = holders.get(key)
    if prior is not None and prior['pid'] == os.getpid():
        prior['depth'] += 1
        try:
            yield
        finally:
            prior['depth'] -= 1
        return
    if prior is not None:
        # An inherited descriptor must not unlock the parent's lock.
        os.close(prior['fd'])
        del holders[key]
    fd = os.open(path, os.O_CREAT | os.O_RDWR | os.O_NOFOLLOW, 0o600)
    creator_pid = os.getpid()
    os.set_inheritable(fd, False)
    try:
        info = os.fstat(fd)
        if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid() or info.st_mode & 0o077:
            raise RuntimeError('PRIVATE_CRON_WRITER_LOCK_REQUIRED')
        try:
            fcntl.flock(fd, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError as error:
            raise CronWriterBusy('ANOTHER_LOCAL_CRON_WRITER_ACTIVE') from error
        holders[key] = {'pid': os.getpid(), 'fd': fd, 'depth': 1}
        try:
            yield
        finally:
            if os.getpid() == creator_pid:
                del holders[key]
                fcntl.flock(fd, fcntl.LOCK_UN)
    finally:
        if os.getpid() == creator_pid:
            os.close(fd)


def exclusive_cron(function):
    @functools.wraps(function)
    def locked(*args, **kwargs):
        with cron_writer():
            return function(*args, **kwargs)
    return locked
