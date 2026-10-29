<?php
/**
 * Test Apache Endpoints directly via HTTP
 */

echo "Testing Apache HTTP Endpoints:\n\n";

// 1. Test google_auth_init
$ch = curl_init('http://localhost/software_team/api/auth.php?action=google_auth_init');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

list($headers, $body) = explode("\r\n\r\n", $raw, 2);
$json = json_decode($body, true);

echo "1. google_auth_init endpoint:\n";
echo "   HTTP Code: $code\n";
echo "   Success: " . (($json['success'] ?? false) ? 'YES' : 'NO') . "\n";
echo "   Has auth_url: " . (!empty($json['auth_url']) ? 'YES' : 'NO') . "\n";
echo "   Has prompt=select_account: " . (strpos($json['auth_url'] ?? '', 'prompt=select_account') !== false ? 'YES' : 'NO') . "\n";
echo "   Has redirect_uri: " . (strpos($json['auth_url'] ?? '', urlencode('http://localhost/software_team/api/google_callback.php')) !== false ? 'YES' : 'NO') . "\n";

// 2. Test google_callback with access_denied
$ch = curl_init('http://localhost/software_team/api/google_callback.php?error=access_denied');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

preg_match('/Location:\s*([^\r\n]+)/i', $raw, $loc);
echo "\n2. google_callback?error=access_denied:\n";
echo "   HTTP Code: $code\n";
echo "   Location: " . ($loc[1] ?? 'None') . "\n";

// 3. Test google_callback with missing code
$ch = curl_init('http://localhost/software_team/api/google_callback.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

preg_match('/Location:\s*([^\r\n]+)/i', $raw, $loc);
echo "\n3. google_callback (missing code):\n";
echo "   HTTP Code: $code\n";
echo "   Location: " . ($loc[1] ?? 'None') . "\n";

// 4. Test root google_callback.php proxy
$ch = curl_init('http://localhost/software_team/google_callback.php');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_HEADER, true);
curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);
$raw = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

preg_match('/Location:\s*([^\r\n]+)/i', $raw, $loc);
echo "\n4. root google_callback.php proxy:\n";
echo "   HTTP Code: $code\n";
echo "   Location: " . ($loc[1] ?? 'None') . "\n";
