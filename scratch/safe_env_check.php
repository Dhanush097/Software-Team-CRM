<?php
$lines = file(__DIR__ . '/../.env', FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
foreach ($lines as $line) {
    if (strpos(trim($line), '#') === 0) continue;
    $parts = explode('=', $line, 2);
    if (count($parts) === 2) {
        $k = trim($parts[0]);
        $v = trim($parts[1]);
        if ($k === 'GOOGLE_REDIRECT_URI') {
            echo "$k = $v\n";
        } elseif ($k === 'GOOGLE_CLIENT_ID') {
            echo "$k = " . substr($v, 0, 15) . "... (len: " . strlen($v) . ")\n";
        } elseif ($k === 'GOOGLE_CLIENT_SECRET') {
            echo "$k = [CONFIGURED, len: " . strlen($v) . "]\n";
        } else {
            echo "$k = [len: " . strlen($v) . "]\n";
        }
    }
}
