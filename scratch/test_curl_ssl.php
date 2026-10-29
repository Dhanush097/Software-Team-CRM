<?php
$ch = curl_init('https://oauth2.googleapis.com/tokeninfo');
curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
curl_setopt($ch, CURLOPT_TIMEOUT, 10);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err = curl_error($ch);
$errno = curl_errno($ch);
curl_close($ch);

echo "HTTP Code: $code\n";
echo "Errno: $errno\n";
echo "Error: $err\n";
echo "Response: " . substr((string)$res, 0, 100) . "\n";
