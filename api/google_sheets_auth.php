<?php
/**
 * Software CRM - Google Sheets Employee Authorization Service
 *
 * Implements strict, server-side employee authorization against Google Spreadsheet:
 *   Spreadsheet Name: Team_crm_1
 *   Spreadsheet ID:   1eC09oWaqhe2T-Uq8IoXhM-VIStb9KQLH-pEPqFwroO4
 *   Worksheet Name:   Sheet2
 *   Header Row (Row 1): employee_id, full_name, email, role, status
 *   Employee Records:   Row 2..N
 *
 * Security & Architecture Principles:
 * - Direct Google Sheets API v4 integration using Google Cloud Service Account (RS256 JWT via OpenSSL).
 * - General access is Restricted: Authenticated via Service Account OAuth 2.0 Bearer Token.
 * - Minimum permissions: https://www.googleapis.com/auth/spreadsheets.readonly.
 * - Configurable Spreadsheet ID, Worksheet Name, and Credentials Path via .env.
 * - Short-lived, secure server-side cache (default TTL 180s / 3 min).
 * - Exact, normalized email matching (trim + lowercase). No partial matches.
 * - Strict schema validation: employee_id unique, valid email unique, role in ['admin', 'member'], status in ['active', 'inactive'].
 * - Safe failure: If Google Sheets cannot be reached, credentials invalid, or permissions missing -> DENY NEW ACCESS SAFELY. Never fall back to accepting all users!
 * - Zero secrets exposed in frontend or logs.
 */

if (!defined('CRM_ACCESS')) {
    define('CRM_ACCESS', true);
}

require_once __DIR__ . '/security_audit.php';

class GoogleSheetsAuthService {
    public const DEFAULT_SPREADSHEET_ID = '1eC09oWaqhe2T-Uq8IoXhM-VIStb9KQLH-pEPqFwroO4';
    public const DEFAULT_SHEET_NAME      = 'Sheet2';
    public const DEFAULT_CACHE_TTL       = 180; // 3 minutes

    public const SUPPORTED_ROLES    = ['admin', 'member'];
    public const SUPPORTED_STATUSES = ['active', 'inactive'];

    private static bool $envLoaded = false;
    private static ?array $cachedAccessToken = null; // ['token' => ..., 'expires_at' => ...]
    private static ?array $mockEmployees = null;     // For automated testing only
    private static $mockHttpHandler = null;          // For automated testing only

    /**
     * Load environment configuration from root or api directory (.env)
     */
    public static function loadEnv(): void {
        if (self::$envLoaded) return;

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
        self::$envLoaded = true;
    }

    /**
     * Get configured Spreadsheet ID
     */
    public static function getSpreadsheetId(): string {
        self::loadEnv();
        $id = trim((string)(getenv('GOOGLE_SHEETS_SPREADSHEET_ID') ?: ($_ENV['GOOGLE_SHEETS_SPREADSHEET_ID'] ?? '')));
        return !empty($id) ? $id : self::DEFAULT_SPREADSHEET_ID;
    }

    /**
     * Get configured Worksheet Tab Name
     */
    public static function getSheetName(): string {
        self::loadEnv();
        $name = trim((string)(getenv('GOOGLE_SHEETS_SHEET_NAME') ?: ($_ENV['GOOGLE_SHEETS_SHEET_NAME'] ?? '')));
        return !empty($name) ? $name : self::DEFAULT_SHEET_NAME;
    }

    /**
     * Get Cache TTL in seconds
     */
    public static function getCacheTtl(): int {
        self::loadEnv();
        $ttl = (int)(getenv('GOOGLE_SHEETS_CACHE_TTL') ?: ($_ENV['GOOGLE_SHEETS_CACHE_TTL'] ?? 0));
        return $ttl > 0 ? $ttl : self::DEFAULT_CACHE_TTL;
    }

    /**
     * Locate Google Service Account JSON configuration
     */
    public static function getServiceAccountConfig(): ?array {
        self::loadEnv();

        $configuredPath = trim((string)(getenv('GOOGLE_SERVICE_ACCOUNT_JSON') ?: ($_ENV['GOOGLE_SERVICE_ACCOUNT_JSON'] ?? '')));
        $rawJson = trim((string)(getenv('GOOGLE_SERVICE_ACCOUNT_KEY') ?: ($_ENV['GOOGLE_SERVICE_ACCOUNT_KEY'] ?? '')));

        // If raw JSON string was supplied in env
        if (!empty($rawJson) && str_starts_with($rawJson, '{')) {
            $parsed = json_decode($rawJson, true);
            if (is_array($parsed) && !empty($parsed['client_email']) && !empty($parsed['private_key'])) {
                return $parsed;
            }
        }

        // Candidate file paths
        $candidatePaths = [];
        if (!empty($configuredPath)) {
            $candidatePaths[] = $configuredPath;
            $candidatePaths[] = dirname(__DIR__) . '/' . ltrim($configuredPath, '/\\');
        }
        $candidatePaths[] = dirname(__DIR__) . '/config/google_service_account.json';
        $candidatePaths[] = dirname(__DIR__) . '/google_service_account.json';
        $candidatePaths[] = dirname(__DIR__) . '/software-crm-team-0c8ac168a8ef.json';
        $candidatePaths[] = __DIR__ . '/google_service_account.json';

        // Auto-detect any service account key file in root or config dir
        $rootJsons = glob(dirname(__DIR__) . '/*.json');
        if (is_array($rootJsons)) {
            foreach ($rootJsons as $rj) {
                if (!in_array($rj, $candidatePaths, true)) {
                    $candidatePaths[] = $rj;
                }
            }
        }

        foreach ($candidatePaths as $path) {
            if (file_exists($path) && is_readable($path)) {
                $content = file_get_contents($path);
                $parsed = json_decode($content, true);
                if (is_array($parsed) && !empty($parsed['client_email']) && !empty($parsed['private_key'])) {
                    return $parsed;
                }
            }
        }

        return null;
    }

    /**
     * Get Service Account email if configured, or empty string
     */
    public static function getServiceAccountEmail(): string {
        $cfg = self::getServiceAccountConfig();
        return !empty($cfg['client_email']) ? trim($cfg['client_email']) : '';
    }

    /**
     * Helper to base64url-encode data
     */
    private static function base64UrlEncode(string $data): string {
        return rtrim(strtr(base64_encode($data), '+/', '-_'), '=');
    }

    /**
     * Obtain a Google Cloud OAuth 2.0 Bearer Access Token via Service Account JWT assertion
     */
    public static function getServiceAccountAccessToken(): array {
        // Return active cached access token if still valid
        if (self::$cachedAccessToken !== null && self::$cachedAccessToken['expires_at'] > (time() + 60)) {
            return ['success' => true, 'token' => self::$cachedAccessToken['token']];
        }

        $cfg = self::getServiceAccountConfig();
        if (!$cfg) {
            return [
                'success' => false,
                'error' => 'Google Service Account credentials not configured.',
                'code' => 'MISSING_SERVICE_ACCOUNT_CONFIG'
            ];
        }

        $clientEmail = $cfg['client_email'] ?? '';
        $privateKey  = $cfg['private_key'] ?? '';

        if (empty($clientEmail) || empty($privateKey)) {
            return [
                'success' => false,
                'error' => 'Service account JSON key is missing client_email or private_key.',
                'code' => 'INVALID_SERVICE_ACCOUNT_JSON'
            ];
        }

        // Build JWT Header & Claims
        $now = time();
        $header = ['alg' => 'RS256', 'typ' => 'JWT'];
        $claims = [
            'iss'   => $clientEmail,
            'scope' => 'https://www.googleapis.com/auth/spreadsheets.readonly',
            'aud'   => 'https://oauth2.googleapis.com/token',
            'exp'   => $now + 3600,
            'iat'   => $now
        ];

        $encodedHeader = self::base64UrlEncode(json_encode($header));
        $encodedClaims = self::base64UrlEncode(json_encode($claims));
        $toSign        = $encodedHeader . '.' . $encodedClaims;

        $binarySignature = '';
        $pkeyResource = openssl_pkey_get_private($privateKey);
        if (!$pkeyResource) {
            SecurityAuditLog::log('SHEETS_API_ERROR', ['error' => 'Could not parse service account private key with OpenSSL']);
            return [
                'success' => false,
                'error' => 'Could not parse private key. Please check Google Service Account key format.',
                'code' => 'PRIVATE_KEY_PARSE_FAILED'
            ];
        }

        $signSuccess = openssl_sign($toSign, $binarySignature, $pkeyResource, OPENSSL_ALGO_SHA256);
        if (!$signSuccess) {
            SecurityAuditLog::log('SHEETS_API_ERROR', ['error' => 'OpenSSL failed to sign Google JWT assertion']);
            return [
                'success' => false,
                'error' => 'Failed to cryptographically sign JWT assertion with private key.',
                'code' => 'JWT_SIGNING_FAILED'
            ];
        }

        $jwt = $toSign . '.' . self::base64UrlEncode($binarySignature);

        // Exchange JWT with Google OAuth2 Token Endpoint
        if (self::$mockHttpHandler !== null) {
            $resp = call_user_func(self::$mockHttpHandler, 'https://oauth2.googleapis.com/token', 'POST', [
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt
            ]);
            $httpCode = $resp['code'] ?? 200;
            $body = $resp['body'] ?? '';
        } else {
            $ch = curl_init('https://oauth2.googleapis.com/token');
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
                'grant_type' => 'urn:ietf:params:oauth:grant-type:jwt-bearer',
                'assertion'  => $jwt
            ]));
            curl_setopt($ch, CURLOPT_HTTPHEADER, ['Content-Type: application/x-www-form-urlencoded']);
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            $body = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 0) {
                SecurityAuditLog::log('SHEETS_API_ERROR', ['error' => 'Network failure connecting to oauth2.googleapis.com', 'curl_error' => $curlError]);
                return [
                    'success' => false,
                    'error' => 'Network error connecting to Google OAuth2 token server: ' . $curlError,
                    'code' => 'NETWORK_ERROR'
                ];
            }
        }

        $data = json_decode($body, true);
        if ($httpCode !== 200 || empty($data['access_token'])) {
            $errDesc = $data['error_description'] ?? ($data['error'] ?? 'Google rejected service account JWT authorization assertion.');
            SecurityAuditLog::log('SHEETS_API_ERROR', ['error' => $errDesc, 'http_code' => $httpCode]);
            return [
                'success' => false,
                'error' => 'Google OAuth token error: ' . $errDesc,
                'code' => 'TOKEN_EXCHANGE_REJECTED'
            ];
        }

        $accessToken = $data['access_token'];
        $expiresIn   = (int)($data['expires_in'] ?? 3600);

        self::$cachedAccessToken = [
            'token'      => $accessToken,
            'expires_at' => time() + ($expiresIn > 300 ? $expiresIn - 120 : $expiresIn)
        ];

        return ['success' => true, 'token' => $accessToken];
    }

    /**
     * Path to local JSON cache file
     */
    private static function getCacheFilePath(): string {
        return dirname(__DIR__) . '/data/google_sheets_employee_cache.json';
    }

    /**
     * Fetch and parse employee records from Google Sheets (or cache)
     *
     * @param bool $forceFresh If true, bypasses the cache and queries Google Sheets API directly
     * @return array ['success' => bool, 'employees' => array, 'raw_rows' => int, 'error' => string]
     */
    public static function fetchEmployeeRecords(bool $forceFresh = false): array {
        // Return test mock data if active in memory or session
        if (self::$mockEmployees !== null) {
            return self::parseAndValidateRows(self::$mockEmployees);
        }
        if (!empty($_SESSION['test_mock_employees']) && is_array($_SESSION['test_mock_employees'])) {
            return self::parseAndValidateRows($_SESSION['test_mock_employees']);
        }

        $cacheFile = self::getCacheFilePath();
        $ttl = self::getCacheTtl();

        // Check local cache if not forced fresh
        if (!$forceFresh && file_exists($cacheFile) && is_readable($cacheFile)) {
            $cacheContent = @file_get_contents($cacheFile);
            $cacheData = json_decode($cacheContent, true);
            if (is_array($cacheData) && !empty($cacheData['cached_at']) && (time() - $cacheData['cached_at']) < $ttl) {
                if (isset($cacheData['employees']) && is_array($cacheData['employees'])) {
                    return [
                        'success'    => true,
                        'employees'  => $cacheData['employees'],
                        'from_cache' => true,
                        'cached_at'  => $cacheData['cached_at']
                    ];
                }
            }
        }

        // Fetch fresh from Google Sheets API v4
        $tokenRes = self::getServiceAccountAccessToken();
        if (!$tokenRes['success']) {
            // Check if fallback API key is provided
            $apiKey = trim((string)(getenv('GOOGLE_SHEETS_API_KEY') ?: ($_ENV['GOOGLE_SHEETS_API_KEY'] ?? '')));
            if (empty($apiKey)) {
                return [
                    'success'   => false,
                    'error'     => $tokenRes['error'],
                    'code'      => $tokenRes['code'],
                    'employees' => []
                ];
            }
        }

        $spreadsheetId = self::getSpreadsheetId();
        $sheetName     = self::getSheetName();
        $range         = rawurlencode($sheetName . '!A:E');
        $endpoint      = "https://sheets.googleapis.com/v4/spreadsheets/{$spreadsheetId}/values/{$range}";

        $headers = [];
        if (!empty($tokenRes['token'])) {
            $headers[] = 'Authorization: Bearer ' . $tokenRes['token'];
        } elseif (!empty($apiKey)) {
            $endpoint .= '?key=' . urlencode($apiKey);
        }

        if (self::$mockHttpHandler !== null) {
            $resp = call_user_func(self::$mockHttpHandler, $endpoint, 'GET', null, $headers);
            $httpCode = $resp['code'] ?? 200;
            $body = $resp['body'] ?? '';
        } else {
            $ch = curl_init($endpoint);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            if (!empty($headers)) {
                curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            }
            curl_setopt($ch, CURLOPT_TIMEOUT, 12);
            $body = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($httpCode === 0) {
                SecurityAuditLog::log('SHEETS_API_ERROR', ['error' => 'Network timeout connecting to Google Sheets API', 'curl_error' => $curlErr]);
                return [
                    'success'   => false,
                    'error'     => 'Network failure connecting to Google Sheets API: ' . $curlErr,
                    'code'      => 'NETWORK_TIMEOUT',
                    'employees' => []
                ];
            }
        }

        $json = json_decode($body, true);

        if ($httpCode !== 200) {
            $msg = $json['error']['message'] ?? ('HTTP ' . $httpCode . ' from Google Sheets API');
            $status = $json['error']['status'] ?? 'API_ERROR';
            SecurityAuditLog::log('SHEETS_API_ERROR', ['http_code' => $httpCode, 'status' => $status, 'message' => $msg]);

            return [
                'success'   => false,
                'error'     => "Google Sheets API Error ($status): $msg",
                'code'      => $status,
                'http_code' => $httpCode,
                'employees' => []
            ];
        }

        $rawRows = $json['values'] ?? [];
        $validationResult = self::parseAndValidateRows($rawRows);

        if ($validationResult['success']) {
            // Write to local cache
            $cachePayload = [
                'cached_at'      => time(),
                'spreadsheet_id' => $spreadsheetId,
                'sheet_name'     => $sheetName,
                'employees'      => $validationResult['employees']
            ];
            $dir = dirname($cacheFile);
            if (!is_dir($dir)) {
                @mkdir($dir, 0777, true);
            }
            @file_put_contents($cacheFile, json_encode($cachePayload, JSON_PRETTY_PRINT), LOCK_EX);
        }

        return $validationResult;
    }

    /**
     * Parse and strictly validate 2D rows from Sheet2
     */
    public static function parseAndValidateRows(array $rows): array {
        if (empty($rows) || count($rows) < 1) {
            return [
                'success'   => false,
                'error'     => 'Worksheet Sheet2 is empty. Header row (Row 1) and employee records (Row 2+) are required.',
                'code'      => 'EMPTY_WORKSHEET',
                'employees' => []
            ];
        }

        // Validate Row 1: Header Row
        $headerRow = $rows[0];
        $colA = strtolower(trim((string)($headerRow[0] ?? '')));
        $colB = strtolower(trim((string)($headerRow[1] ?? '')));
        $colC = strtolower(trim((string)($headerRow[2] ?? '')));
        $colD = strtolower(trim((string)($headerRow[3] ?? '')));
        $colE = strtolower(trim((string)($headerRow[4] ?? '')));

        if ($colA !== 'employee_id' || $colB !== 'full_name' || $colC !== 'email' || $colD !== 'role' || $colE !== 'status') {
            return [
                'success'   => false,
                'error'     => "Invalid Sheet2 header row. Expected: [employee_id, full_name, email, role, status]. Received: [$colA, $colB, $colC, $colD, $colE].",
                'code'      => 'INVALID_HEADER_SCHEMA',
                'employees' => []
            ];
        }

        $employeesByEmail = [];
        $seenEmployeeIds = [];
        $duplicateIds = [];
        $duplicateEmails = [];
        $malformedRows = [];

        // Validate Rows 2..N: Employee Records
        $rowCount = count($rows);
        for ($i = 1; $i < $rowCount; $i++) {
            $row = $rows[$i];
            $rowNum = $i + 1;

            $empId    = trim((string)($row[0] ?? ''));
            $fullName = trim((string)($row[1] ?? ''));
            $rawEmail = trim((string)($row[2] ?? ''));
            $rawRole  = strtolower(trim((string)($row[3] ?? '')));
            $rawStatus= strtolower(trim((string)($row[4] ?? '')));

            // Skip completely empty rows
            if ($empId === '' && $fullName === '' && $rawEmail === '' && $rawRole === '' && $rawStatus === '') {
                continue;
            }

            // 1. employee_id must be present
            if ($empId === '') {
                $malformedRows[] = "Row $rowNum: employee_id is missing.";
                continue;
            }

            // employee_id must be unique
            if (isset($seenEmployeeIds[$empId])) {
                $duplicateIds[] = "Row $rowNum: duplicate employee_id '$empId'.";
                continue;
            }
            $seenEmployeeIds[$empId] = true;

            // 2. full_name must be present
            if ($fullName === '') {
                $malformedRows[] = "Row $rowNum: full_name is missing.";
                continue;
            }

            // 3. email must be valid format
            $normEmail = strtolower($rawEmail);
            if (empty($normEmail) || !filter_var($normEmail, FILTER_VALIDATE_EMAIL)) {
                $malformedRows[] = "Row $rowNum: invalid email format '$rawEmail'.";
                continue;
            }

            // email must be unique
            if (isset($employeesByEmail[$normEmail])) {
                $duplicateEmails[] = "Row $rowNum: duplicate email address '$normEmail'.";
                continue;
            }

            // 4. role must be supported ('admin' or 'member')
            if (!in_array($rawRole, self::SUPPORTED_ROLES, true)) {
                $malformedRows[] = "Row $rowNum: unsupported role '$rawRole' (must be 'admin' or 'member').";
                continue;
            }

            // 5. status must be supported ('active' or 'inactive')
            if (!in_array($rawStatus, self::SUPPORTED_STATUSES, true)) {
                $malformedRows[] = "Row $rowNum: unsupported status '$rawStatus' (must be 'active' or 'inactive').";
                continue;
            }

            // Valid employee record!
            $employeesByEmail[$normEmail] = [
                'employee_id' => $empId,
                'full_name'   => $fullName,
                'email'       => $normEmail,
                'role'        => $rawRole,
                'status'      => $rawStatus,
                'row_index'   => $rowNum
            ];
        }

        return [
            'success'          => true,
            'employees'        => $employeesByEmail,
            'total_valid'      => count($employeesByEmail),
            'duplicate_ids'    => $duplicateIds,
            'duplicate_emails' => $duplicateEmails,
            'malformed_rows'   => $malformedRows
        ];
    }

    /**
     * Authorize a specific email address against the Sheet2 registry.
     * Normalizes the email (trim + lowercase) and enforces exact matching.
     *
     * @param string $email User input email
     * @param bool $forceFresh If true, bypasses the cache to guarantee real-time verification
     * @return array ['authorized' => bool, 'employee' => array|null, 'error' => string|null, 'code' => string]
     */
    public static function authorizeEmployee(string $email, bool $forceFresh = false): array {
        $normEmail = strtolower(trim($email));

        if (empty($normEmail) || !filter_var($normEmail, FILTER_VALIDATE_EMAIL)) {
            return [
                'authorized' => false,
                'error'      => 'Please provide a valid work email address.',
                'code'       => 'INVALID_EMAIL_FORMAT',
                'employee'   => null
            ];
        }

        $res = self::fetchEmployeeRecords($forceFresh);
        if (!$res['success']) {
            SecurityAuditLog::log('SHEETS_API_ERROR', [
                'email' => $normEmail,
                'error' => $res['error'] ?? 'Google Sheets connection failed',
                'code'  => $res['code'] ?? 'UNKNOWN'
            ]);

            return [
                'authorized' => false,
                'error'      => 'Unable to verify employee registry at this time. Please contact your system administrator.',
                'code'       => $res['code'] ?? 'SHEETS_UNAVAILABLE',
                'employee'   => null
            ];
        }

        $employees = $res['employees'] ?? [];

        // Exact match check
        if (!isset($employees[$normEmail])) {
            SecurityAuditLog::log('LOGIN_FAILED_UNLISTED', [
                'email' => $normEmail,
                'note'  => 'Email is not registered in Google Sheets Sheet2'
            ]);

            return [
                'authorized' => false,
                'error'      => 'Access restricted. This email address is not registered in the employee directory.',
                'code'       => 'EMPLOYEE_NOT_FOUND',
                'employee'   => null
            ];
        }

        $emp = $employees[$normEmail];

        // Active status check
        if ($emp['status'] !== 'active') {
            SecurityAuditLog::log('LOGIN_FAILED_INACTIVE', [
                'email'       => $normEmail,
                'employee_id' => $emp['employee_id'],
                'status'      => $emp['status']
            ]);

            return [
                'authorized' => false,
                'error'      => 'Access restricted. Your employee account is currently marked inactive in the registry.',
                'code'       => 'EMPLOYEE_INACTIVE',
                'employee'   => $emp
            ];
        }

        return [
            'authorized' => true,
            'error'      => null,
            'code'       => 'AUTHORIZED',
            'employee'   => $emp
        ];
    }

    /**
     * Invalidate the local cache
     */
    public static function invalidateCache(): void {
        $cacheFile = self::getCacheFilePath();
        if (file_exists($cacheFile)) {
            @unlink($cacheFile);
        }
    }

    /**
     * Diagnostic testing helper
     */
    public static function testConnection(): array {
        $spreadsheetId = self::getSpreadsheetId();
        $sheetName     = self::getSheetName();
        $serviceEmail  = self::getServiceAccountEmail();
        $hasKey        = (self::getServiceAccountConfig() !== null);

        $result = [
            'spreadsheet_id'        => $spreadsheetId,
            'sheet_name'            => $sheetName,
            'service_account_email' => $serviceEmail ?: 'None configured',
            'has_credentials_key'   => $hasKey,
            'connection_status'     => 'pending',
            'instructions'          => []
        ];

        if (!$hasKey) {
            $result['connection_status'] = 'MISSING_CREDENTIALS';
            $result['instructions'] = [
                "1. Go to Google Cloud Console (https://console.cloud.google.com/iam-admin/serviceaccounts).",
                "2. Create a Service Account (e.g. 'team-member-crm-reader').",
                "3. Create and download a JSON key.",
                "4. Save the key file to: " . dirname(__DIR__) . "/config/google_service_account.json",
                "5. Open your spreadsheet 'Team_crm_1' (ID: $spreadsheetId), click 'Share', paste the Service Account's email, set role to 'Viewer', and click 'Share'."
            ];
            return $result;
        }

        $fetchRes = self::fetchEmployeeRecords(true);
        if ($fetchRes['success']) {
            $result['connection_status'] = 'CONNECTED';
            $result['total_employees']   = count($fetchRes['employees'] ?? []);
            $activeCount = 0;
            $adminCount = 0;
            foreach ($fetchRes['employees'] as $e) {
                if ($e['status'] === 'active') $activeCount++;
                if ($e['role'] === 'admin') $adminCount++;
            }
            $result['active_employees'] = $activeCount;
            $result['admin_count']      = $adminCount;
        } else {
            $result['connection_status'] = 'CONNECTION_FAILED';
            $result['error']             = $fetchRes['error'];
            $result['code']              = $fetchRes['code'] ?? 'UNKNOWN';

            if (!empty($serviceEmail)) {
                $result['instructions'] = [
                    "Your Service Account email is: $serviceEmail",
                    "Open Google Spreadsheet: https://docs.google.com/spreadsheets/d/$spreadsheetId/edit",
                    "Click the 'Share' button in the top right corner.",
                    "Paste '$serviceEmail', set permission to 'Viewer', uncheck 'Notify people', and click 'Share'.",
                    "Ensure Google Sheets API is enabled in your Google Cloud Project: https://console.cloud.google.com/apis/library/sheets.googleapis.com"
                ];
            }
        }

        return $result;
    }

    /**
     * Testing mock helpers
     */
    public static function setMockEmployees(?array $mockRows): void {
        self::$mockEmployees = $mockRows;
        self::invalidateCache();
    }

    public static function setMockHttpHandler(?callable $handler): void {
        self::$mockHttpHandler = $handler;
    }
}
