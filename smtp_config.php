<?php
// SMTP settings for the contact form handler.
// Environment variables are used when available, with local fallbacks.
$SMTP_HOST       = getenv('SMTP_HOST') ?: 'mail.LaserCutterRepair.com';
$SMTP_PORT       = (int) (getenv('SMTP_PORT') ?: 587);
$SMTP_USERNAME   = getenv('SMTP_USERNAME') ?: 'sales@LaserCutterRepair.com';
$SMTP_PASSWORD   = getenv('SMTP_PASSWORD') ?: 'zhJBwxMc*rz-';
$SMTP_FROM_EMAIL = getenv('SMTP_FROM_EMAIL') ?: 'sales@LaserCutterRepair.com';
$SMTP_FROM_NAME  = getenv('SMTP_FROM_NAME') ?: 'Ghost Laser';
$ADMIN_EMAIL     = getenv('ADMIN_EMAIL') ?: 'sales@LaserCutterRepair.com';
$COMPANY_WEBSITE = getenv('COMPANY_WEBSITE') ?: 'https://LaserCutterRepair.com';

// Hidden honeypot field for spam protection.
$HONEYPOT_FIELD = 'website';