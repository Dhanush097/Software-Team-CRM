<?php
/**
 * Automated Test Suite for Task Attachments & File Viewing
 * Tests authentication, file uploads, metadata persistence, file streaming, MIME types, security, and downloads.
 */

$baseUrl = 'http://127.0.0.1:8000';
$cookieFile = __DIR__ . '/test_session_cookies.txt';
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

function logTest($name, $passed, $details = '') {
    $status = $passed ? "[PASS]" : "[FAIL]";
    echo sprintf("%-6s %-50s %s\n", $status, $name, $details ? "- $details" : '');
}

function makeRequest($url, $method = 'GET', $data = null, $headers = [], $isMultipart = false) {
    global $cookieFile;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
    curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, false);

    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST, true);
        if ($isMultipart) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
        } else {
            if (is_array($data)) {
                $postFields = json_encode($data);
                $headers[] = 'Content-Type: application/json';
            } else {
                $postFields = $data;
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $postFields);
        }
    }

    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $headerStr = substr($response, 0, $headerSize);
    $body = substr($response, $headerSize);
    curl_close($ch);

    // Parse headers
    $rawHeaders = explode("\r\n", $headerStr);
    $parsedHeaders = [];
    foreach ($rawHeaders as $line) {
        if (strpos($line, ':') !== false) {
            list($key, $val) = explode(':', $line, 2);
            $parsedHeaders[strtolower(trim($key))] = trim($val);
        }
    }

    return [
        'code' => $httpCode,
        'headers' => $parsedHeaders,
        'raw_headers' => $headerStr,
        'body' => $body
    ];
}

echo "=================================================================\n";
echo "       SOFTWARE CRM TASK ATTACHMENT & FILE VIEWING TEST SUITE    \n";
echo "=================================================================\n\n";

$allPassed = true;

// 1. Authenticate as suresh@softwarecrm.com
echo "--- STEP 1: Authentication ---\n";
$loginRes = makeRequest("$baseUrl/api/auth.php?action=login_credentials", 'POST', [
    'email' => 'suresh@softwarecrm.com',
    'password' => 'Password@123'
]);
$loginJson = json_decode($loginRes['body'], true);
$loginOk = ($loginRes['code'] === 200 && (($loginJson['step'] ?? '') === 'otp_required' || !empty($loginJson['success'])));
logTest("Step 1.1: Submit credentials", $loginOk, "Code: {$loginRes['code']}, Step: " . ($loginJson['step'] ?? 'None'));
if (!$loginOk) $allPassed = false;

// Read OTP from log
$logFile = __DIR__ . '/data/email_delivery.log';
$otp = null;
if (file_exists($logFile)) {
    $logContent = file_get_contents($logFile);
    if (preg_match_all('/Recipient: suresh@softwarecrm\.com.*?Your Verification Code: (\d{4})/s', $logContent, $matches)) {
        $otp = end($matches[1]);
    }
}

$otpRes = makeRequest("$baseUrl/api/auth.php?action=verify_otp", 'POST', [
    'email' => 'suresh@softwarecrm.com',
    'otp' => $otp,
    'purpose' => 'login'
]);
$otpJson = json_decode($otpRes['body'], true);
$otpOk = ($otpRes['code'] === 200 && (!empty($otpJson['success']) || !empty($otpJson['authenticated'])));
logTest("Step 1.2: Verify OTP ($otp)", $otpOk, "Code: {$otpRes['code']}, User: " . ($otpJson['user']['name'] ?? 'Suresh'));
if (!$otpOk) $allPassed = false;

// 2. Prepare test files on disk
echo "\n--- STEP 2: Create Test Files for Upload ---\n";
$tempDir = __DIR__ . '/scratch/test_uploads';
if (!is_dir($tempDir)) {
    mkdir($tempDir, 0777, true);
}

// 2.1 PNG Image (valid 1x1 transparent PNG)
$pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
$pngPath = $tempDir . '/test_spec.png';
file_put_contents($pngPath, $pngData);

// 2.2 PDF Document (minimal valid PDF)
$pdfData = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 300 144]/Parent 2 0 R/Resources<<>>>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000052 00000 n\n0000000101 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n178\n%%EOF\n";
$pdfPath = $tempDir . '/architecture_diagram.pdf';
file_put_contents($pdfPath, $pdfData);

// 2.3 Plain Text Notes
$txtData = "Software CRM - Project Architecture Notes\n==========================================\n1. Database schema: crm_tasks & crm_task_files\n2. Secure file streaming with SessionGuard auth.\n3. Content-Disposition: inline vs attachment.\n";
$txtPath = $tempDir . '/release_notes.txt';
file_put_contents($txtPath, $txtData);

// 2.4 Unsupported / Download-only ZIP file
$zipData = "PK\x03\x04\x14\x00\x00\x00\x08\x00MockZipFileContentForTestingTaskAttachmentDownloads1234567890";
$zipPath = $tempDir . '/project_assets.zip';
file_put_contents($zipPath, $zipData);

echo "Test files generated in $tempDir\n";

$taskId = 'tsk_1';

// 3. Upload Test Files to Task
echo "\n--- STEP 3: Upload Attachments via API ---\n";

// 3.1 Upload PNG
$uploadPngRes = makeRequest("$baseUrl/api/tasks.php?action=upload_file", 'POST', [
    'task_id' => $taskId,
    'file' => new CURLFile($pngPath, 'image/png', 'test_spec.png')
], [], true);
$uploadPngJson = json_decode($uploadPngRes['body'], true);
$pngFileId = $uploadPngJson['file']['id'] ?? ($uploadPngJson['data']['id'] ?? null);
$pngUploaded = ($uploadPngRes['code'] === 200 && !empty($uploadPngJson['success']) && !empty($pngFileId));
logTest("Upload PNG Image", $pngUploaded, "File ID: $pngFileId, Type: " . ($uploadPngJson['data']['type'] ?? ($uploadPngJson['file']['type'] ?? '')));
if (!$pngUploaded) $allPassed = false;

// 3.2 Upload PDF
$uploadPdfRes = makeRequest("$baseUrl/api/tasks.php?action=upload_file", 'POST', [
    'task_id' => $taskId,
    'file' => new CURLFile($pdfPath, 'application/pdf', 'architecture_diagram.pdf')
], [], true);
$uploadPdfJson = json_decode($uploadPdfRes['body'], true);
$pdfFileId = $uploadPdfJson['file']['id'] ?? ($uploadPdfJson['data']['id'] ?? null);
$pdfUploaded = ($uploadPdfRes['code'] === 200 && !empty($uploadPdfJson['success']) && !empty($pdfFileId));
logTest("Upload PDF Document", $pdfUploaded, "File ID: $pdfFileId, Type: " . ($uploadPdfJson['data']['type'] ?? ($uploadPdfJson['file']['type'] ?? '')));
if (!$pdfUploaded) $allPassed = false;

// 3.3 Upload TXT
$uploadTxtRes = makeRequest("$baseUrl/api/tasks.php?action=upload_file", 'POST', [
    'task_id' => $taskId,
    'file' => new CURLFile($txtPath, 'text/plain', 'release_notes.txt')
], [], true);
$uploadTxtJson = json_decode($uploadTxtRes['body'], true);
$txtFileId = $uploadTxtJson['file']['id'] ?? ($uploadTxtJson['data']['id'] ?? null);
$txtUploaded = ($uploadTxtRes['code'] === 200 && !empty($uploadTxtJson['success']) && !empty($txtFileId));
logTest("Upload Plain Text File", $txtUploaded, "File ID: $txtFileId, Type: " . ($uploadTxtJson['data']['type'] ?? ($uploadTxtJson['file']['type'] ?? '')));
if (!$txtUploaded) $allPassed = false;

// 3.4 Upload ZIP
$uploadZipRes = makeRequest("$baseUrl/api/tasks.php?action=upload_file", 'POST', [
    'task_id' => $taskId,
    'file' => new CURLFile($zipPath, 'application/zip', 'project_assets.zip')
], [], true);
$uploadZipJson = json_decode($uploadZipRes['body'], true);
$zipFileId = $uploadZipJson['file']['id'] ?? ($uploadZipJson['data']['id'] ?? null);
$zipUploaded = ($uploadZipRes['code'] === 200 && !empty($uploadZipJson['success']) && !empty($zipFileId));
logTest("Upload Download-Only ZIP", $zipUploaded, "File ID: $zipFileId, Type: " . ($uploadZipJson['data']['type'] ?? ($uploadZipJson['file']['type'] ?? '')));
if (!$zipUploaded) $allPassed = false;

// 4. Retrieve files list for task
echo "\n--- STEP 4: Retrieve Task Attachments List ---\n";
$listRes = makeRequest("$baseUrl/api/tasks.php?action=get_files&task_id=$taskId");
$listJson = json_decode($listRes['body'], true);
$filesList = $listJson['files'] ?? ($listJson['data'] ?? []);
$listOk = ($listRes['code'] === 200 && !empty($listJson['success']) && is_array($filesList));
$fileCount = $listOk ? count($filesList) : 0;
logTest("List Task Attachments", $listOk && $fileCount >= 4, "Found $fileCount files for task $taskId");
if (!$listOk || $fileCount < 4) $allPassed = false;

// Check file properties in list
$foundIds = array_column($filesList, 'id');
$allFound = in_array($pngFileId, $foundIds) && in_array($pdfFileId, $foundIds) && in_array($txtFileId, $foundIds) && in_array($zipFileId, $foundIds);
logTest("All 4 uploaded file IDs returned in list", $allFound, "IDs verified");
if (!$allFound) $allPassed = false;

// 5. Verify File Viewing & Streaming Headers
echo "\n--- STEP 5: Verify File Serving & Headers ---\n";

// 5.1 View PNG Image
$viewPng = makeRequest("$baseUrl/api/tasks.php?action=view_file&id=$pngFileId");
$pngTypeOk = isset($viewPng['headers']['content-type']) && strpos($viewPng['headers']['content-type'], 'image/png') !== false;
$pngDispOk = isset($viewPng['headers']['content-disposition']) && strpos($viewPng['headers']['content-disposition'], 'inline') !== false;
$pngNosniff = isset($viewPng['headers']['x-content-type-options']) && $viewPng['headers']['x-content-type-options'] === 'nosniff';
$pngBodyOk = ($viewPng['body'] === $pngData);
logTest("View PNG (inline preview)", $viewPng['code'] === 200 && $pngTypeOk && $pngDispOk && $pngNosniff && $pngBodyOk, "Type: " . ($viewPng['headers']['content-type'] ?? '') . ", Disp: " . ($viewPng['headers']['content-disposition'] ?? ''));
if (!($viewPng['code'] === 200 && $pngTypeOk && $pngDispOk && $pngBodyOk)) $allPassed = false;

// 5.2 View PDF Document
$viewPdf = makeRequest("$baseUrl/api/tasks.php?action=view_file&id=$pdfFileId");
$pdfTypeOk = isset($viewPdf['headers']['content-type']) && strpos($viewPdf['headers']['content-type'], 'application/pdf') !== false;
$pdfDispOk = isset($viewPdf['headers']['content-disposition']) && strpos($viewPdf['headers']['content-disposition'], 'inline') !== false;
$pdfBodyOk = ($viewPdf['body'] === $pdfData);
logTest("View PDF (inline preview)", $viewPdf['code'] === 200 && $pdfTypeOk && $pdfDispOk && $pdfBodyOk, "Type: " . ($viewPdf['headers']['content-type'] ?? '') . ", Disp: " . ($viewPdf['headers']['content-disposition'] ?? ''));
if (!($viewPdf['code'] === 200 && $pdfTypeOk && $pdfDispOk && $pdfBodyOk)) $allPassed = false;

// 5.3 View Text File
$viewTxt = makeRequest("$baseUrl/api/tasks.php?action=view_file&id=$txtFileId");
$txtTypeOk = isset($viewTxt['headers']['content-type']) && strpos($viewTxt['headers']['content-type'], 'text/plain') !== false;
$txtDispOk = isset($viewTxt['headers']['content-disposition']) && strpos($viewTxt['headers']['content-disposition'], 'inline') !== false;
$txtBodyOk = ($viewTxt['body'] === $txtData);
logTest("View TXT (inline preview)", $viewTxt['code'] === 200 && $txtTypeOk && $txtDispOk && $txtBodyOk, "Type: " . ($viewTxt['headers']['content-type'] ?? '') . ", Content verified");
if (!($viewTxt['code'] === 200 && $txtTypeOk && $txtDispOk && $txtBodyOk)) $allPassed = false;

// 5.4 View ZIP File (unsupported preview -> forced download)
$viewZip = makeRequest("$baseUrl/api/tasks.php?action=view_file&id=$zipFileId");
$zipDispOk = isset($viewZip['headers']['content-disposition']) && strpos($viewZip['headers']['content-disposition'], 'attachment') !== false;
$zipBodyOk = ($viewZip['body'] === $zipData);
logTest("View ZIP (forces attachment download)", $viewZip['code'] === 200 && $zipDispOk && $zipBodyOk, "Disp: " . ($viewZip['headers']['content-disposition'] ?? ''));
if (!($viewZip['code'] === 200 && $zipDispOk && $zipBodyOk)) $allPassed = false;

// 5.5 Download parameter test (download=1 on PNG)
$dlPng = makeRequest("$baseUrl/api/tasks.php?action=view_file&download=1&id=$pngFileId");
$dlDispOk = isset($dlPng['headers']['content-disposition']) && strpos($dlPng['headers']['content-disposition'], 'attachment') !== false;
logTest("Download flag forces attachment header on previewable file", $dlPng['code'] === 200 && $dlDispOk, "Disp: " . ($dlPng['headers']['content-disposition'] ?? ''));
if (!($dlPng['code'] === 200 && $dlDispOk)) $allPassed = false;

// 5.6 Dedicated Gateway (api/task_file.php)
$gwPdf = makeRequest("$baseUrl/api/task_file.php?id=$pdfFileId");
$gwOk = ($gwPdf['code'] === 200 && isset($gwPdf['headers']['content-type']) && strpos($gwPdf['headers']['content-type'], 'application/pdf') !== false);
logTest("Dedicated Gateway api/task_file.php", $gwOk, "Code: {$gwPdf['code']}, Type: " . ($gwPdf['headers']['content-type'] ?? ''));
if (!$gwOk) $allPassed = false;

// 6. Security & Error Handling Tests
echo "\n--- STEP 6: Security & Permissions Tests ---\n";

// 6.1 Path Traversal Reject
$travRes = makeRequest("$baseUrl/api/tasks.php?action=view_file&id=../../index.php");
$travOk = ($travRes['code'] === 404 || $travRes['code'] === 400 || $travRes['code'] === 403);
logTest("Path Traversal rejection (../../index.php)", $travOk, "Code: {$travRes['code']}");
if (!$travOk) $allPassed = false;

// 6.2 Non-existent File ID
$notFoundRes = makeRequest("$baseUrl/api/tasks.php?action=view_file&id=non_existent_file_99999");
$notFoundOk = ($notFoundRes['code'] === 404);
logTest("Non-existent File ID returns HTTP 404", $notFoundOk, "Code: {$notFoundRes['code']}");
if (!$notFoundOk) $allPassed = false;

// 6.3 Block Dangerous File Upload (.php)
$badPath = $tempDir . '/evil.php';
file_put_contents($badPath, '<?php phpinfo(); ?>');
$badUploadRes = makeRequest("$baseUrl/api/tasks.php?action=upload_file", 'POST', [
    'task_id' => $taskId,
    'file' => new CURLFile($badPath, 'application/x-php', 'evil.php')
], [], true);
$badUploadJson = json_decode($badUploadRes['body'], true);
$badBlocked = ($badUploadRes['code'] === 400 || !empty($badUploadJson['error']) || !empty($badUploadJson['message']));
logTest("Dangerous executable upload blocked (.php)", $badBlocked, "Code: {$badUploadRes['code']}, Msg: " . ($badUploadJson['message'] ?? ''));
if (!$badBlocked) $allPassed = false;

// 6.4 Unauthenticated Access Rejection
$unauthCh = curl_init();
curl_setopt($unauthCh, CURLOPT_URL, "$baseUrl/api/tasks.php?action=view_file&id=$pngFileId");
curl_setopt($unauthCh, CURLOPT_RETURNTRANSFER, true);
curl_setopt($unauthCh, CURLOPT_HEADER, true);
curl_setopt($unauthCh, CURLOPT_FOLLOWLOCATION, false);
$unauthResp = curl_exec($unauthCh);
$unauthCode = curl_getinfo($unauthCh, CURLINFO_HTTP_CODE);
curl_close($unauthCh);

$unauthOk = ($unauthCode === 401 || $unauthCode === 302);
logTest("Unauthenticated access rejected without session", $unauthOk, "HTTP Code: $unauthCode");
if (!$unauthOk) $allPassed = false;

// 7. Cleanup test files
@unlink($pngPath);
@unlink($pdfPath);
@unlink($txtPath);
@unlink($zipPath);
@unlink($badPath);
@rmdir($tempDir);
if (file_exists($cookieFile)) {
    @unlink($cookieFile);
}

echo "\n=================================================================\n";
if ($allPassed) {
    echo "  ALL TASK ATTACHMENT & FILE VIEWING TESTS PASSED (100%)!\n";
} else {
    echo "  SOME TESTS FAILED! Review test results above.\n";
}
echo "=================================================================\n";
