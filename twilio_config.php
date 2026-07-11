<?php

return [
    'account_sid' => trim((string)(getenv('TWILIO_ACCOUNT_SID') ?: 'ACe9afb35ec742acab9b0f6ecd9fc45ed6')),
    'auth_token' => trim((string)(getenv('TWILIO_AUTH_TOKEN') ?: '347094ddca9e50eacca49984deebaa54')),
    'from_number' => trim((string)(getenv('TWILIO_FROM_NUMBER') ?: '+13506005196')),
    'to_number' => trim((string)(getenv('TWILIO_TO_NUMBER') ?: '+17148015559')),
    'app_url' => trim((string)(getenv('APP_URL') ?: 'https://ghostlaser.com/project')),
];
