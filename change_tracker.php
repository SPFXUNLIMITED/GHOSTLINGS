<?php

return [
    'version' => '1.1.1',
    'changes' => [
        '2026-05-18: Paused session heartbeat while browser tab is hidden to reduce unnecessary ping traffic.',
        '2026-05-18: Added 401 session-expiry handling in ping.php so inactive sessions are handled gracefully.',
    ],
];
