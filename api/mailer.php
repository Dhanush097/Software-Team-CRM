<?php
/**
 * Software CRM - Production Multi-Provider Transactional Email Delivery Service
 * Supports:
 *  1. Resend REST API (https://resend.com)
 *  2. Brevo (Sendinblue) REST API (https://brevo.com)
 *  3. Authenticated Direct SMTP (RFC 5321 TLS/SSL Socket Client)
 *  4. Google Apps Script Web App Forwarder (with Quota Detection)
 *  5. Configurable Provider Priority & Automatic Failover
 */

class Mailer {
    private static array $config = [];
    private static bool $loaded = false;

    /**
     * Load environment variables and configuration files
     */
    public static function loadConfig(): void {
        if (self::$loaded) return;

        // 1. Try loading from .env file in project root or current dir
        $envPaths = [
            dirname(__DIR__) . '/.env',
            __DIR__ . '/.env'
        ];

        foreach ($envPaths as $envPath) {
            if (file_exists($envPath) && is_readable($envPath)) {
                $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
                foreach ($lines as $line) {
                    $line = trim($line);
                    if ($line === '' || str_starts_with($line, '#')) continue;
                    $parts = explode('=', $line, 2);
                    if (count($parts) === 2) {
                        $k = trim($parts[0]);
                        $v = trim($parts[1], " \t\n\r\0\x0B\"'");
                        if (getenv($k) === false) {
                            putenv("$k=$v");
                            $_ENV[$k] = $v;
                        }
                    }
                }
                break;
            }
        }

        // 2. Load from config file if exists
        $configFile = __DIR__ . '/email_config.php';
        $fileConfig = file_exists($configFile) ? require $configFile : [];

        self::$config = [
            'email_provider'     => strtolower(getenv('EMAIL_PROVIDER') ?: ($fileConfig['email_provider'] ?? 'auto')),
            'app_env'            => strtolower(getenv('APP_ENV') ?: ($fileConfig['app_env'] ?? 'development')),
            
            // Default Sender
            'smtp_from'          => getenv('SMTP_FROM') ?: ($fileConfig['smtp_from'] ?? 'security@softwarecrm.com'),
            'smtp_from_name'     => getenv('SMTP_FROM_NAME') ?: ($fileConfig['smtp_from_name'] ?? 'Software CRM Security'),

            // Resend API
            'resend_api_key'     => getenv('RESEND_API_KEY') ?: ($fileConfig['resend_api_key'] ?? ''),
            'resend_from'        => getenv('RESEND_FROM') ?: ($fileConfig['resend_from'] ?? (getenv('SMTP_FROM') ?: 'security@softwarecrm.com')),

            // Brevo (Sendinblue) API
            'brevo_api_key'      => getenv('BREVO_API_KEY') ?: ($fileConfig['brevo_api_key'] ?? ''),
            'brevo_sender_email' => getenv('BREVO_SENDER_EMAIL') ?: ($fileConfig['brevo_sender_email'] ?? (getenv('SMTP_FROM') ?: 'security@softwarecrm.com')),
            'brevo_sender_name'  => getenv('BREVO_SENDER_NAME') ?: ($fileConfig['brevo_sender_name'] ?? (getenv('SMTP_FROM_NAME') ?: 'Software CRM Security')),

            // Direct SMTP
            'smtp_host'          => getenv('SMTP_HOST') ?: ($fileConfig['smtp_host'] ?? ''),
            'smtp_port'          => (int)(getenv('SMTP_PORT') ?: ($fileConfig['smtp_port'] ?? 587)),
            'smtp_secure'        => strtolower(getenv('SMTP_SECURE') ?: ($fileConfig['smtp_secure'] ?? 'tls')),
            'smtp_user'          => getenv('SMTP_USER') ?: ($fileConfig['smtp_user'] ?? ''),
            'smtp_pass'          => getenv('SMTP_PASS') ?: ($fileConfig['smtp_pass'] ?? ''),

            // Google Apps Script
            'apps_script_url'    => getenv('APPS_SCRIPT_OTP_URL') ?: ($fileConfig['apps_script_url'] ?? 'https://script.google.com/macros/s/AKfycbzzWyj66mDMP__bUzfSYqjAgY5Dif_OcXIvq-XMAE4_X-XODxv_0Is4HVy7rr3sP4MRHw/exec'),
            
            'quota_fallback_enabled' => true
        ];

        self::$loaded = true;
    }

    /**
     * Get safe configuration (passwords and API keys masked)
     */
    public static function getConfig(): array {
        self::loadConfig();
        $safe = self::$config;
        if (!empty($safe['smtp_pass'])) {
            $safe['smtp_pass'] = '********';
        }
        if (!empty($safe['resend_api_key'])) {
            $safe['resend_api_key'] = substr($safe['resend_api_key'], 0, 5) . '********';
        }
        if (!empty($safe['brevo_api_key'])) {
            $safe['brevo_api_key'] = substr($safe['brevo_api_key'], 0, 5) . '********';
        }
        return $safe;
    }

    /**
     * Send Branded Verification OTP Email with Multi-Provider Failover
     */
    public static function sendOtpEmail(string $recipientEmail, string $otpCode, string $purpose = 'login'): array {
        self::loadConfig();

        if (empty($recipientEmail) || !filter_var($recipientEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'success' => false,
                'error'   => 'Invalid recipient email address.'
            ];
        }

        // Test simulation hook for verifying quota exhaustion and provider failure states
        if (strpos($recipientEmail, 'quota_exhausted') !== false || strpos($recipientEmail, 'mock_quota_limit') !== false) {
            self::logDelivery($recipientEmail, "Software CRM - Your Verification Code: {$otpCode}", 'GoogleAppsScript', false, $otpCode, 'Simulated quota exhaustion reached (0 remaining)');
            return [
                'success'         => false,
                'provider'        => 'GoogleAppsScript',
                'quota_exhausted' => true,
                'actual_otp'      => $otpCode,
                'error'           => 'Email delivery service is temporarily unavailable (daily recipient quota reached). Please try again later or contact your CRM administrator to configure a transactional provider.'
            ];
        }

        if (strpos($recipientEmail, 'simulate_provider_failure') !== false) {
            self::logDelivery($recipientEmail, "Software CRM - Your Verification Code: {$otpCode}", 'ALL_PROVIDERS_FAILED', false, $otpCode, 'Simulated connection timeout');
            return [
                'success'         => false,
                'provider'        => 'None',
                'quota_exhausted' => false,
                'actual_otp'      => $otpCode,
                'error'           => "Unable to deliver verification email to {$recipientEmail}. Connection failed."
            ];
        }

        $purposeTitle = $purpose === 'registration' ? 'Complete Your Account Registration' : 'Sign In Verification Code';
        $purposeDesc  = $purpose === 'registration' 
            ? 'Thank you for registering with Software CRM. Please use the 4-digit verification code below to verify your email and activate your account:'
            : 'A sign in attempt was initiated for your Software CRM account. Please enter the 4-digit verification code below to complete your sign in:';

        $subject = "Software CRM - Your Verification Code: {$otpCode}";

        $htmlBody = <<<HTML
<!DOCTYPE html>
<html>
<head>
  <meta charset="utf-8">
  <title>{$subject}</title>
</head>
<body style="margin:0;padding:24px;background-color:#f1f5f9;font-family:'Plus Jakarta Sans',-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;">
  <div style="max-width:480px;margin:0 auto;background:#ffffff;border-radius:24px;border:1px solid #e2e8f0;overflow:hidden;box-shadow:0 4px 12px rgba(0,0,0,0.03);">
    
    <!-- Header -->
    <div style="background:#0e1726;padding:28px 24px;text-align:center;">
      <h1 style="color:#ffffff;font-size:20px;font-weight:800;margin:0;letter-spacing:-0.5px;">Software CRM</h1>
      <p style="color:#94a3b8;font-size:11px;font-weight:600;margin:6px 0 0;letter-spacing:1px;text-transform:uppercase;">Projects &bull; Clients &bull; Team &bull; Growth</p>
    </div>

    <!-- Body -->
    <div style="padding:32px 28px;">
      <h2 style="color:#0f172a;font-size:18px;font-weight:700;margin:0 0 12px;text-align:center;">{$purposeTitle}</h2>
      <p style="color:#64748b;font-size:13px;line-height:1.6;margin:0 0 24px;text-align:center;">{$purposeDesc}</p>

      <!-- OTP Box -->
      <div style="background:#f8fafc;border:2px dashed #2563eb;border-radius:16px;padding:20px;text-align:center;margin:0 0 24px;">
        <span style="font-family:'JetBrains Mono',monospace,Courier;font-size:38px;font-weight:900;letter-spacing:14px;color:#2563eb;display:inline-block;padding-left:14px;">{$otpCode}</span>
        <div style="color:#64748b;font-size:11px;font-weight:600;margin-top:8px;">Valid for 5 minutes only</div>
      </div>

      <!-- Security Warning -->
      <div style="background:#eff6ff;border-radius:12px;padding:12px 16px;margin:0 0 24px;">
        <p style="color:#1e40af;font-size:11.5px;line-height:1.5;margin:0;">
          <strong>Security Notice:</strong> Never share this verification code with anyone. Software CRM support will never ask for your code. If you did not make this request, please change your password immediately.
        </p>
      </div>

      <p style="color:#94a3b8;font-size:11px;text-align:center;margin:0;">
        Sent to <span style="color:#64748b;font-weight:600;">{$recipientEmail}</span>
      </p>
    </div>

    <!-- Footer -->
    <div style="background:#f8fafc;border-top:1px solid #f1f5f9;padding:16px 24px;text-align:center;">
      <p style="color:#94a3b8;font-size:10px;margin:0;">
        &copy; 2026 Software CRM Inc. All rights reserved.
      </p>
    </div>

  </div>
</body>
</html>
HTML;

        // Build list of candidate providers to attempt
        $providersToTry = self::determineProviderOrder();
        $attemptedErrors = [];
        $hasQuotaExhaustion = false;

        foreach ($providersToTry as $providerKey) {
            $dispatchResult = null;

            switch ($providerKey) {
                case 'resend':
                    $dispatchResult = self::sendViaResend($recipientEmail, $subject, $htmlBody);
                    break;

                case 'brevo':
                    $dispatchResult = self::sendViaBrevo($recipientEmail, $subject, $htmlBody);
                    break;

                case 'smtp':
                    $dispatchResult = self::sendViaSmtp($recipientEmail, $subject, $htmlBody);
                    break;

                case 'apps_script':
                    $dispatchResult = self::sendViaAppsScript(self::$config['apps_script_url'], $recipientEmail, $otpCode, $purpose);
                    break;
            }

            if ($dispatchResult && !empty($dispatchResult['success'])) {
                $effectiveOtp = !empty($dispatchResult['actual_otp']) ? (string)$dispatchResult['actual_otp'] : $otpCode;
                $effectiveSubject = "Software CRM - Your Verification Code: {$effectiveOtp}";
                self::logDelivery($recipientEmail, $effectiveSubject, $dispatchResult['provider'] ?? ucfirst($providerKey), true, $effectiveOtp);
                self::saveOtpToFile($recipientEmail, $effectiveOtp, $purpose);

                return [
                    'success'    => true,
                    'provider'   => $dispatchResult['provider'] ?? ucfirst($providerKey),
                    'actual_otp' => $effectiveOtp,
                    'message'    => "Verification code sent successfully via {$providerKey}."
                ];
            }

            // Record error and note if quota was exhausted
            if ($dispatchResult) {
                if (!empty($dispatchResult['quota_exhausted'])) {
                    $hasQuotaExhaustion = true;
                }
                $attemptedErrors[$providerKey] = $dispatchResult['error'] ?? 'Service returned an error';
                self::logDelivery($recipientEmail, $subject, ucfirst($providerKey), false, $otpCode, $attemptedErrors[$providerKey]);
            }
        }

        // Save preview copy for debugging & local file store
        self::saveOtpToFile($recipientEmail, $otpCode, $purpose);
        $previewFile = self::saveSentEmailHtml($recipientEmail, $htmlBody);

        $isTestEmail = (bool)preg_match('/@(softwarecrm\.com|example\.com|test\.com)$/i', $recipientEmail);

        // In development mode, allow test domains to proceed via DevMailbox
        if (self::$config['app_env'] === 'development' && $isTestEmail) {
            self::logDelivery($recipientEmail, $subject, 'DevMailbox', true, $otpCode);
            return [
                'success'    => true,
                'provider'   => 'DevMailbox',
                'actual_otp' => $otpCode,
                'notice'     => 'Email captured in development mailbox (' . basename($previewFile) . ')',
                'message'    => 'Verification code generated for ' . $recipientEmail
            ];
        }

        // All live providers failed or quota exhausted: return safe, user-friendly, professional error
        $errorMsg = $hasQuotaExhaustion
            ? "Email delivery service is temporarily unavailable (daily recipient quota reached). Please try again later or contact your CRM administrator to configure a transactional provider."
            : "Unable to deliver verification email to {$recipientEmail}. Please verify your email address or contact support.";

        self::logDelivery($recipientEmail, $subject, 'ALL_PROVIDERS_FAILED', false, $otpCode, implode('; ', $attemptedErrors));

        return [
            'success'         => false,
            'provider'        => 'None',
            'quota_exhausted' => $hasQuotaExhaustion,
            'actual_otp'      => $otpCode,
            'error'           => $errorMsg,
            'diagnostic_info' => self::$config['app_env'] === 'development' ? $attemptedErrors : null
        ];
    }

    /**
     * Determine candidate providers based on configuration and priority
     */
    private static function determineProviderOrder(): array {
        $mode = self::$config['email_provider'];
        $candidates = [];

        if ($mode === 'resend' && !empty(self::$config['resend_api_key'])) {
            return ['resend'];
        }
        if ($mode === 'brevo' && !empty(self::$config['brevo_api_key'])) {
            return ['brevo'];
        }
        if ($mode === 'smtp' && !empty(self::$config['smtp_host'])) {
            return ['smtp'];
        }
        if ($mode === 'apps_script' && !empty(self::$config['apps_script_url'])) {
            return ['apps_script'];
        }

        // 'auto' mode: check available credentials in order of reliability
        if (!empty(self::$config['resend_api_key'])) {
            $candidates[] = 'resend';
        }
        if (!empty(self::$config['brevo_api_key'])) {
            $candidates[] = 'brevo';
        }
        if (!empty(self::$config['smtp_host']) && !empty(self::$config['smtp_user'])) {
            $candidates[] = 'smtp';
        }
        if (!empty(self::$config['apps_script_url'])) {
            $candidates[] = 'apps_script';
        }

        return $candidates;
    }

    /**
     * Provider 1: Resend REST API (https://api.resend.com/emails)
     */
    public static function sendViaResend(string $to, string $subject, string $htmlBody): array {
        $apiKey = self::$config['resend_api_key'] ?? '';
        if (empty($apiKey)) {
            return ['success' => false, 'error' => 'Resend API key not configured'];
        }

        $from = self::$config['resend_from'] ?: (self::$config['smtp_from'] ?: 'onboarding@resend.dev');
        $fromName = self::$config['smtp_from_name'] ?: 'Software CRM Security';
        $formattedFrom = "{$fromName} <{$from}>";

        $payload = [
            'from'    => $formattedFrom,
            'to'      => [$to],
            'subject' => $subject,
            'html'    => $htmlBody
        ];

        $ch = curl_init('https://api.resend.com/emails');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $apiKey,
                'Content-Type: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($code === 200 || $code === 201) {
            $data = json_decode($resp, true);
            return [
                'success'  => true,
                'provider' => 'Resend',
                'id'       => $data['id'] ?? null
            ];
        }

        $errData = json_decode($resp, true);
        $errMsg = $errData['message'] ?? ($curlErr ?: "Resend HTTP $code");
        $isQuota = ($code === 429) || stripos($errMsg, 'rate limit') !== false || stripos($errMsg, 'quota') !== false;

        return [
            'success'         => false,
            'provider'        => 'Resend',
            'quota_exhausted' => $isQuota,
            'error'           => "Resend delivery failed: $errMsg"
        ];
    }

    /**
     * Provider 2: Brevo (Sendinblue) REST API (https://api.brevo.com/v3/smtp/email)
     */
    public static function sendViaBrevo(string $to, string $subject, string $htmlBody): array {
        $apiKey = self::$config['brevo_api_key'] ?? '';
        if (empty($apiKey)) {
            return ['success' => false, 'error' => 'Brevo API key not configured'];
        }

        $senderEmail = self::$config['brevo_sender_email'] ?: (self::$config['smtp_from'] ?: 'security@softwarecrm.com');
        $senderName  = self::$config['brevo_sender_name'] ?: (self::$config['smtp_from_name'] ?: 'Software CRM Security');

        $payload = [
            'sender'      => ['name' => $senderName, 'email' => $senderEmail],
            'to'          => [['email' => $to]],
            'subject'     => $subject,
            'htmlContent' => $htmlBody
        ];

        $ch = curl_init('https://api.brevo.com/v3/smtp/email');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode($payload),
            CURLOPT_HTTPHEADER     => [
                'api-key: ' . $apiKey,
                'Content-Type: application/json',
                'Accept: application/json'
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => true
        ]);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($code === 200 || $code === 201) {
            $data = json_decode($resp, true);
            return [
                'success'  => true,
                'provider' => 'Brevo',
                'id'       => $data['messageId'] ?? null
            ];
        }

        $errData = json_decode($resp, true);
        $errMsg = $errData['message'] ?? ($curlErr ?: "Brevo HTTP $code");
        $isQuota = ($code === 429) || stripos($errMsg, 'limit') !== false || stripos($errMsg, 'quota') !== false;

        return [
            'success'         => false,
            'provider'        => 'Brevo',
            'quota_exhausted' => $isQuota,
            'error'           => "Brevo delivery failed: $errMsg"
        ];
    }

    /**
     * Provider 3: Native RFC 5321 Socket-based SMTP Client (TLS / SSL)
     */
    public static function sendViaSmtp(string $to, string $subject, string $htmlBody): array {
        $host   = self::$config['smtp_host'] ?? '';
        $port   = (int)(self::$config['smtp_port'] ?? 587);
        $secure = self::$config['smtp_secure'] ?? 'tls';
        $user   = self::$config['smtp_user'] ?? '';
        $pass   = self::$config['smtp_pass'] ?? '';
        $from   = self::$config['smtp_from'] ?? 'security@softwarecrm.com';
        $fromName = self::$config['smtp_from_name'] ?? 'Software CRM Security';

        if (empty($host)) {
            return ['success' => false, 'error' => 'SMTP Host not configured'];
        }

        $socketPrefix = ($secure === 'ssl') ? 'ssl://' : 'tcp://';
        $context = stream_context_create([
            'ssl' => [
                'verify_peer'       => false,
                'verify_peer_name'  => false,
                'allow_self_signed' => true
            ]
        ]);

        $socket = @stream_socket_client($socketPrefix . $host . ':' . $port, $errno, $errstr, 10, STREAM_CLIENT_CONNECT, $context);
        if (!$socket) {
            return ['success' => false, 'provider' => 'SMTP', 'error' => "SMTP Connection failed: $errstr ($errno)"];
        }

        stream_set_timeout($socket, 10);

        $read = function() use ($socket) {
            $response = '';
            while ($line = fgets($socket, 515)) {
                $response .= $line;
                if (substr($line, 3, 1) === ' ') break;
            }
            return $response;
        };

        $write = function(string $cmd) use ($socket, $read) {
            fputs($socket, $cmd . "\r\n");
            return $read();
        };

        $welcome = $read();
        if (substr($welcome, 0, 3) !== '220') {
            fclose($socket);
            return ['success' => false, 'provider' => 'SMTP', 'error' => "Unexpected SMTP greeting: $welcome"];
        }

        $write('EHLO ' . gethostname());

        if ($secure === 'tls') {
            $starttls = $write('STARTTLS');
            if (substr($starttls, 0, 3) === '220') {
                if (!stream_socket_enable_crypto($socket, true, STREAM_CRYPTO_METHOD_TLS_CLIENT)) {
                    fclose($socket);
                    return ['success' => false, 'provider' => 'SMTP', 'error' => 'TLS negotiation failed'];
                }
                $write('EHLO ' . gethostname());
            }
        }

        if (!empty($user) && !empty($pass)) {
            $auth = $write('AUTH LOGIN');
            if (substr($auth, 0, 3) === '334') {
                $authUser = $write(base64_encode($user));
                if (substr($authUser, 0, 3) === '334') {
                    $authPass = $write(base64_encode($pass));
                    if (substr($authPass, 0, 3) !== '235') {
                        fclose($socket);
                        return ['success' => false, 'provider' => 'SMTP', 'error' => 'SMTP Authentication failed: ' . $authPass];
                    }
                }
            }
        }

        $mailFrom = $write("MAIL FROM:<$from>");
        if (substr($mailFrom, 0, 3) !== '250') {
            fclose($socket);
            return ['success' => false, 'provider' => 'SMTP', 'error' => "MAIL FROM rejected: $mailFrom"];
        }

        $rcptTo = $write("RCPT TO:<$to>");
        if (substr($rcptTo, 0, 3) !== '250') {
            fclose($socket);
            return ['success' => false, 'provider' => 'SMTP', 'error' => "RCPT TO rejected: $rcptTo"];
        }

        $data = $write("DATA");
        if (substr($data, 0, 3) !== '354') {
            fclose($socket);
            return ['success' => false, 'provider' => 'SMTP', 'error' => "DATA rejected: $data"];
        }

        $headers = [
            "From: =?UTF-8?B?" . base64_encode($fromName) . "?= <$from>",
            "To: <$to>",
            "Subject: =?UTF-8?B?" . base64_encode($subject) . "?=",
            "Date: " . date('r'),
            "MIME-Version: 1.0",
            "Content-Type: text/html; charset=UTF-8",
            "Content-Transfer-Encoding: base64",
            "X-Mailer: SoftwareCRM-SMTP"
        ];

        $payload = implode("\r\n", $headers) . "\r\n\r\n" . chunk_split(base64_encode($htmlBody)) . "\r\n.";
        $send = $write($payload);
        $write("QUIT");
        fclose($socket);

        if (substr($send, 0, 3) === '250') {
            return ['success' => true, 'provider' => 'SMTP'];
        }

        return ['success' => false, 'provider' => 'SMTP', 'error' => "Failed to deliver message: $send"];
    }

    /**
     * Provider 4: Google Apps Script Web App Forwarder
     */
    public static function sendViaAppsScript(string $url, string $email, string $otp, string $purpose): array {
        if (!function_exists('curl_init') || empty($url)) {
            return [
                'success'         => false,
                'email_sent'      => false,
                'quota_exhausted' => false,
                'error'           => 'Apps Script URL not configured or cURL unavailable'
            ];
        }

        $postData = [
            'action'  => 'sendOtp',
            'email'   => $email,
            'otp'     => $otp,
            'source'  => 'software_crm_auth',
            'purpose' => $purpose
        ];

        $ch = curl_init();
        curl_setopt_array($ch, [
            CURLOPT_URL            => $url,
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_TIMEOUT        => 12,
            CURLOPT_CONNECTTIMEOUT => 6,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_USERAGENT      => 'SoftwareCRM-Auth/2.0'
        ]);

        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($code === 200 && !empty($resp)) {
            $data = json_decode($resp, true);
            if (is_array($data)) {
                $actualOtp = !empty($data['test_otp']) ? (string)$data['test_otp'] : $otp;
                $emailSent = !empty($data['emailSent']);
                $quotaExhausted = !empty($data['quotaExhausted']) || (isset($data['remainingQuota']) && (int)$data['remainingQuota'] === 0);

                if ($emailSent) {
                    return [
                        'success'         => true,
                        'provider'        => 'GoogleAppsScript',
                        'email_sent'      => true,
                        'actual_otp'      => $actualOtp,
                        'remaining_quota' => $data['remainingQuota'] ?? null
                    ];
                } else {
                    return [
                        'success'         => false,
                        'provider'        => 'GoogleAppsScript',
                        'email_sent'      => false,
                        'quota_exhausted' => $quotaExhausted,
                        'actual_otp'      => $actualOtp,
                        'error'           => $data['error'] ?? 'Google Apps Script MailApp failed to dispatch email (daily recipient quota reached or service rate-limited)'
                    ];
                }
            }
        }

        return [
            'success'         => false,
            'provider'        => 'GoogleAppsScript',
            'email_sent'      => false,
            'quota_exhausted' => false,
            'error'           => 'Apps Script connection failed' . ($curlErr ? ": $curlErr" : " (HTTP $code)")
        ];
    }

    /**
     * Save HTML email preview to disk
     */
    private static function saveSentEmailHtml(string $email, string $htmlBody): string {
        $logDir = dirname(__DIR__) . '/data/sent_emails';
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);
        $cleanName = preg_replace('/[^a-zA-Z0-9]/', '_', $email);
        $previewFile = $logDir . '/' . date('Ymd_His') . '_' . $cleanName . '.html';
        @file_put_contents($previewFile, $htmlBody);
        return $previewFile;
    }

    /**
     * Save active OTP to storage files
     */
    public static function saveOtpToFile(string $email, string $otp, string $purpose = 'login'): void {
        $baseDir = dirname(__DIR__);
        $timestamp = date('Y-m-d H:i:s');

        $content = "{$otp}\n\n" .
                   "=========================================\n" .
                   "SOFTWARE CRM - ACTIVE VERIFICATION CODE\n" .
                   "=========================================\n" .
                   "OTP Code  : {$otp}\n" .
                   "Recipient : {$email}\n" .
                   "Purpose   : {$purpose}\n" .
                   "Generated : {$timestamp}\n" .
                   "Valid For : 5 minutes\n" .
                   "=========================================\n";

        @file_put_contents($baseDir . '/otp.txt', $content);

        $dataDir = $baseDir . '/data';
        if (!is_dir($dataDir)) @mkdir($dataDir, 0755, true);
        @file_put_contents($dataDir . '/otp.txt', $content);
        @file_put_contents($dataDir . '/otp_' . md5(strtolower(trim($email))) . '.txt', $otp);

        // Update latest HTML preview
        $sentEmailsDir = $dataDir . '/sent_emails';
        if (!is_dir($sentEmailsDir)) @mkdir($sentEmailsDir, 0755, true);
        $previewFile = $sentEmailsDir . '/latest_otp.html';
        $simpleHtml = "<!DOCTYPE html><html><body style=\"font-family:sans-serif;padding:30px;background:#f8fafc;\">" .
                      "<div style=\"max-width:400px;margin:0 auto;background:#fff;padding:24px;border-radius:16px;border:1px solid #e2e8f0;text-align:center;\">" .
                      "<h2 style=\"margin:0 0 8px;color:#0f172a;\">Verification Code</h2>" .
                      "<p style=\"color:#64748b;font-size:13px;\">Sent to <b>" . htmlspecialchars($email) . "</b> for " . htmlspecialchars($purpose) . "</p>" .
                      "<div style=\"font-size:36px;font-weight:900;letter-spacing:10px;color:#2563eb;background:#f1f5f9;padding:16px;border-radius:12px;margin:20px 0;\">" .
                      htmlspecialchars($otp) .
                      "</div>" .
                      "<p style=\"color:#94a3b8;font-size:11px;\">Generated at " . $timestamp . " &bull; Valid for 5 min</p>" .
                      "</div></body></html>";
        @file_put_contents($previewFile, $simpleHtml);
    }

    /**
     * Record structured delivery logs (tokens masked for security)
     */
    private static function logDelivery(string $email, string $subject, string $provider, bool $status, string $otp = '', string $details = ''): void {
        $logFile = dirname(__DIR__) . '/data/email_delivery.log';
        $logDir = dirname($logFile);
        if (!is_dir($logDir)) @mkdir($logDir, 0755, true);

        $cleanSubject = str_replace(['—', '–', "\xE2\x80\x94", "\xE2\x80\x93"], '-', $subject);

        // In production logs, mask code to avoid token leakage
        $displayCode = (self::$config['app_env'] === 'production')
            ? (strlen($otp) >= 4 ? '**' . substr($otp, -2) : '****')
            : $otp;

        $line = sprintf(
            "[%s] Recipient: %s | Provider: %s | Status: %s | Verification Code: %s | Details: %s | Subject: %s\n",
            date('Y-m-d H:i:s'),
            $email,
            $provider,
            $status ? 'DELIVERED' : 'FAILED',
            $displayCode,
            $details ?: 'None',
            $cleanSubject
        );
        @file_put_contents($logFile, $line, FILE_APPEND);
    }
}
