<?php

return [
    'account_sid' => trim((string)getenv('TWILIO_ACCOUNT_SID')),
    'auth_token' => trim((string)getenv('TWILIO_AUTH_TOKEN')),
    'from_number' => trim((string)(getenv('TWILIO_FROM_NUMBER') ?: '+13506005196')),
    'to_number' => trim((string)(getenv('TWILIO_TO_NUMBER') ?: '+17148015559')),
    'app_url' => trim((string)(getenv('APP_URL') ?: 'https://ghostlaser.com/project')),
];
