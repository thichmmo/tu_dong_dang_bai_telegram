<?php

declare(strict_types=1);

return [
    // Get these at https://my.telegram.org → API development tools.
    // These identify your Telegram application; they are not a BotFather token.
    'api_id' => 0,
    'api_hash' => '',

    // The logged-in account must already have joined the source channel,
    // and own the destination or have its Post Messages admin permission.
    'source' => '@honghotduongpho0',
    'target' => '@hhdp3105',
    // Keep the legacy destination above. Each destination has its own delivery
    // queue and permission pause; new destinations begin at their activation.
    'targets' => ['@hhdp3105', '@honghotduongpho2027'],

    // MTProto over verified HTTPS works with many shared hosting networks.
    // Use 'tcp' on hosts that allow direct Telegram MTProto connections.
    // Use 'wss' for secure WebSocket when HTTPS RPC responses time out;
    // this mode requires WebSocket upgrades and disables HTTPS fallback.
    'telegram_transport' => 'https',

    // Use an absolute path OUTSIDE public_html. The application itself must
    // also be outside public_html. Keep this directory when upgrading.
    'storage_dir' => __DIR__ . '/storage',

    // Wait for the last album part before forwarding the whole album.
    'album_settle_seconds' => 5,
    'retry_base_seconds' => 15,
    'retry_max_seconds' => 3600,
];
