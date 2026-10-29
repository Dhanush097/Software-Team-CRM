<?php
$html = file_get_contents(__DIR__ . '/../index.php');
preg_match_all("/currentView\s*===\s*'([a-z0-9_]+)'/", $html, $matches);
$views = array_unique($matches[1]);
sort($views);
echo "Total views: " . count($views) . "\n";
print_r($views);
