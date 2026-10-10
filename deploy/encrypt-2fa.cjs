'use strict';
const fs = require('node:fs');
const crypto = require('node:crypto');
try {
  const data = JSON.parse(fs.readFileSync(0, 'utf8'));
  if (typeof data.password !== 'string' || !data.password || Buffer.byteLength(data.password, 'utf8') > 4096 || !/^[a-f0-9]{32}$/.test(data.request_id) || typeof data.api_hash !== 'string') throw Error();
  const salt = crypto.randomBytes(16);
  const nonce = crypto.randomBytes(12);
  const key = crypto.hkdfSync('sha256', Buffer.from(data.api_hash, 'utf8'), salt, Buffer.from('telegram-mirror-2fa-v1'), 32);
  const cipher = crypto.createCipheriv('aes-256-gcm', key, nonce);
  cipher.setAAD(Buffer.from(data.request_id + '|' + data.expires_at));
  const clear = Buffer.from(JSON.stringify({password:data.password,request_id:data.request_id,expires_at:data.expires_at}));
  const encrypted = Buffer.concat([cipher.update(clear), cipher.final()]);
  clear.fill(0);
  process.stdout.write(JSON.stringify({version:1,request_id:data.request_id,expires_at:data.expires_at,salt:salt.toString('base64'),nonce:nonce.toString('base64'),ciphertext:encrypted.toString('base64'),tag:cipher.getAuthTag().toString('base64')}));
} catch (_) { process.stderr.write('Cannot encrypt authentication input.\n'); process.exitCode = 1; }
