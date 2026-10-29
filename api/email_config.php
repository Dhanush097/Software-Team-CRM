<?php
/**
 * Software CRM - Transactional Email & SMTP Configuration
 * Server-side configuration loaded when environment variables are not set.
 */

return [
    // Preferred Email Provider: 'auto', 'resend', 'brevo', 'smtp', 'apps_script'
    // 'auto' tries providers in order: Resend -> Brevo -> SMTP -> Google Apps Script
    'email_provider' => getenv('EMAIL_PROVIDER') ?: 'auto',

    // Application Environment: 'production' or 'development'
    'app_env' => getenv('APP_ENV') ?: 'development',

    // Default Sender Identity
    'smtp_from'      => getenv('SMTP_FROM') ?: 'security@softwarecrm.com',
    'smtp_from_name' => getenv('SMTP_FROM_NAME') ?: 'Software CRM Security',

    // 1. Resend API Configuration (https://resend.com)
    'resend_api_key' => getenv('RESEND_API_KEY') ?: '',
    'resend_from'    => getenv('RESEND_FROM') ?: (getenv('SMTP_FROM') ?: 'security@softwarecrm.com'),

    // 2. Brevo (Sendinblue) API Configuration (https://brevo.com)
    'brevo_api_key'      => getenv('BREVO_API_KEY') ?: '',
    'brevo_sender_email' => getenv('BREVO_SENDER_EMAIL') ?: (getenv('SMTP_FROM') ?: 'security@softwarecrm.com'),
    'brevo_sender_name'  => getenv('BREVO_SENDER_NAME') ?: (getenv('SMTP_FROM_NAME') ?: 'Software CRM Security'),

    // 3. Direct Authenticated SMTP Configuration
    'smtp_host'   => getenv('SMTP_HOST') ?: '',
    'smtp_port'   => (int)(getenv('SMTP_PORT') ?: 587),
    'smtp_secure' => strtolower(getenv('SMTP_SECURE') ?: 'tls'), // 'tls' (587) or 'ssl' (465)
    'smtp_user'   => getenv('SMTP_USER') ?: '',
    'smtp_pass'   => getenv('SMTP_PASS') ?: '',

    // 4. Google Apps Script Web App Forwarder
    'apps_script_url' => getenv('APPS_SCRIPT_OTP_URL') ?: 'https://script.google.com/macros/s/AKfycbzzWyj66mDMP__bUzfSYqjAgY5Dif_OcXIvq-XMAE4_X-XODxv_0Is4HVy7rr3sP4MRHw/exec',

    // Fallback & Safety Settings
    'quota_fallback_enabled' => true,
    'max_resend_cooldown'    => 60,
    'token_ttl_seconds'      => 300,
];
