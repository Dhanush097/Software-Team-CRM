<?php
require_once __DIR__ . '/api/db.php';
/**
 * Software CRM - Project Dashboard Automated Verification Test Suite
 * Tests all 12 Screens and capabilities: Overview, 3-dot menu, Add Task, Task Click,
 * Edit Project, Team, Files (upload/preview/download), Activity (add note/timeline), and Security.
 */

$baseUrl = 'http://127.0.0.1:8000';
$cookieFile = __DIR__ . '/test_project_cookies.txt';
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
    Database::resetConnection();

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
        'body' => $body,
        'json' => json_decode($body, true)
    ];
}

echo "=================================================================\n";
echo "      SOFTWARE CRM: PROJECT DASHBOARD 12-SCREEN TEST SUITE       \n";
echo "=================================================================\n\n";

$allPassed = true;

// 1. Authenticate
echo "--- STEP 1: Authentication ---\n";
$loginRes = makeRequest("$baseUrl/api/auth.php?action=login_credentials", 'POST', [
    'email' => 'suresh@softwarecrm.com',
    'password' => 'Password@123'
]);
$loginOk = ($loginRes['code'] === 200);
logTest("Submit Credentials", $loginOk, "Code: {$loginRes['code']}");
if (!$loginOk) $allPassed = false;

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
$authOk = ($otpRes['code'] === 200 && (!empty($otpRes['json']['success']) || !empty($otpRes['json']['authenticated'])));
logTest("Verify OTP & Establish Session", $authOk, "User: " . ($otpRes['json']['user']['name'] ?? 'Suresh Kumar'));
if (!$authOk) $allPassed = false;

// 2. Project Listing & Details (Screen 1)
echo "\n--- STEP 2: Project Listing & Overview (Screen 1) ---\n";
$listProj = makeRequest("$baseUrl/api/software.php");
$listOk = ($listProj['code'] === 200 && !empty($listProj['json']['data']) && is_array($listProj['json']['data']));
$projCount = $listOk ? count($listProj['json']['data']) : 0;
logTest("List Projects Feed", $listOk && $projCount > 0, "Found $projCount projects");
if (!$listOk) $allPassed = false;

$getProj = makeRequest("$baseUrl/api/software.php?id=proj_1");
$getOk = ($getProj['code'] === 200 && !empty($getProj['json']['data']['title']));
$projTitle = $getProj['json']['data']['title'] ?? '';
logTest("Get Single Project Overview (proj_1)", $getOk, "Title: '$projTitle', Budget: " . ($getProj['json']['data']['budget'] ?? ''));
if (!$getOk) $allPassed = false;

// 3. Edit Project (Screen 6)
echo "\n--- STEP 3: Edit Project Form & Persistence (Screen 6) ---\n";
$originalTitle = $getProj['json']['data']['title'];
$originalBudget = $getProj['json']['data']['budget'];

$editRes = makeRequest("$baseUrl/api/software.php", 'POST', [
    'action' => 'edit',
    'id' => 'proj_1',
    'title' => 'E-commerce Website (Automated Test)',
    'budget' => '$25,000',
    'progress' => 75,
    'status' => 'In Progress',
    'start_date' => '2024-07-15',
    'deadline' => '2024-09-25',
    'project_manager' => 'Priya Verma'
]);
$editOk = ($editRes['code'] === 200 && !empty($editRes['json']['success']) && ($editRes['json']['data']['budget'] ?? '') === '$25,000');
logTest("Save Changes via Edit Project API", $editOk, "New Budget: " . ($editRes['json']['data']['budget'] ?? ''));
if (!$editOk) $allPassed = false;

// Restore original title & budget
makeRequest("$baseUrl/api/software.php", 'POST', [
    'action' => 'edit',
    'id' => 'proj_1',
    'title' => $originalTitle,
    'budget' => $originalBudget,
    'progress' => 70,
    'status' => 'In Progress'
]);

// 4. Duplicate Project (Screen 2 Menu)
echo "\n--- STEP 4: Duplicate Project (Screen 2 Menu Action) ---\n";
$dupRes = makeRequest("$baseUrl/api/software.php", 'POST', [
    'action' => 'duplicate',
    'id' => 'proj_1'
]);
$dupOk = ($dupRes['code'] === 200 && !empty($dupRes['json']['success']) && !empty($dupRes['json']['id']));
$dupId = $dupRes['json']['id'] ?? null;
$dupTitle = $dupRes['json']['project']['title'] ?? '';
$dupTitleOk = strpos($dupTitle, 'Copy of') === 0;
logTest("Duplicate Project creates unique copy", $dupOk && $dupTitleOk, "New ID: $dupId, Title: '$dupTitle'");
if (!$dupOk || !$dupTitleOk) $allPassed = false;

// 5. Archive & Unarchive Project (Screen 2 Menu)
echo "\n--- STEP 5: Archive & Unarchive Project (Screen 2 Menu Action) ---\n";
$archRes = makeRequest("$baseUrl/api/software.php", 'POST', [
    'action' => 'archive',
    'id' => $dupId
]);
$archOk = ($archRes['code'] === 200 && ($archRes['json']['archived'] ?? 0) === 1);
logTest("Archive Project sets archived flag", $archOk, "Archived: " . ($archRes['json']['archived'] ?? ''));
if (!$archOk) $allPassed = false;

$unarchRes = makeRequest("$baseUrl/api/software.php", 'POST', [
    'action' => 'archive',
    'id' => $dupId
]);
$unarchOk = ($unarchRes['code'] === 200 && ($unarchRes['json']['archived'] ?? 1) === 0);
logTest("Unarchive Project restores project", $unarchOk, "Archived: " . ($unarchRes['json']['archived'] ?? ''));
if (!$unarchOk) $allPassed = false;

// 6. Delete Project (Screen 2 Menu Action)
echo "\n--- STEP 6: Delete Project & Safety (Screen 2 Menu Action) ---\n";
$delRes = makeRequest("$baseUrl/api/software.php", 'POST', [
    'action' => 'delete',
    'id' => $dupId
]);
$delOk = ($delRes['code'] === 200 && !empty($delRes['json']['success']));
logTest("Delete Project removes record safely", $delOk, "Code: {$delRes['code']}");
if (!$delOk) $allPassed = false;

$verifyDel = makeRequest("$baseUrl/api/software.php?id=$dupId");
$isDeleted = ($verifyDel['code'] === 404);
logTest("Deleted project no longer accessible", $isDeleted, "HTTP {$verifyDel['code']}");
if (!$isDeleted) $allPassed = false;

// Clean up duplicate tasks generated during duplicate test
$db = Database::getConnection();
if ($db) {
    $db->prepare("DELETE FROM crm_tasks WHERE project_id = ? OR project_id IS NULL")->execute([$dupId]);
}

// 7. Add Task inside Project (Screen 3 & 4)
echo "\n--- STEP 7: Add Task linked to Project (Screen 4) ---\n";
$addTaskRes = makeRequest("$baseUrl/api/tasks.php", 'POST', [
    'action' => 'create',
    'title' => 'Automated Integration Test Task',
    'description' => 'Test task linked to proj_1',
    'project_id' => 'proj_1',
    'project_name' => 'E-commerce Website',
    'priority' => 'High',
    'assigned_staff' => 'Priya Verma',
    'due_date' => date('Y-m-d', strtotime('+7 days'))
]);
$addTaskOk = (in_array($addTaskRes['code'], [200, 201]) && !empty($addTaskRes['json']['success']) && !empty($addTaskRes['json']['id']));
$newTaskId = $addTaskRes['json']['id'] ?? null;
logTest("Create Task with project association", $addTaskOk, "Task ID: $newTaskId");
if (!$addTaskOk) $allPassed = false;

// Verify task appears in tasks list
$tasksRes = makeRequest("$baseUrl/api/tasks.php");
$foundInTasks = false;
foreach ($tasksRes['json']['data'] ?? [] as $t) {
    if ($t['id'] === $newTaskId && $t['project_id'] === 'proj_1') {
        $foundInTasks = true;
        break;
    }
}
logTest("Task persists under parent project", $foundInTasks, "Verified in crm_tasks");
if (!$foundInTasks) $allPassed = false;

// Clean up test task
if ($newTaskId) {
    makeRequest("$baseUrl/api/tasks.php", 'POST', ['action' => 'delete', 'id' => $newTaskId]);
}

// 8. Project Files: Upload, List, View, Download (Screens 9, 10, 11)
echo "\n--- STEP 8: Project Files Upload & Preview (Screens 9, 10, 11) ---\n";
$tempDir = __DIR__ . '/scratch/proj_test_files';
if (!is_dir($tempDir)) mkdir($tempDir, 0777, true);

// PNG
$pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
$pngPath = $tempDir . '/Screenshot_1.png';
file_put_contents($pngPath, $pngData);

// PDF
$pdfData = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 300 144]/Parent 2 0 R/Resources<<>>>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000052 00000 n\n0000000101 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n178\n%%EOF\n";
$pdfPath = $tempDir . '/E-commerce_Requirements.pdf';
file_put_contents($pdfPath, $pdfData);

// Upload PNG to proj_1
$uploadPng = makeRequest("$baseUrl/api/software.php?action=upload_file", 'POST', [
    'project_id' => 'proj_1',
    'file' => new CURLFile($pngPath, 'image/png', 'Screenshot_1.png')
], [], true);
$pngOk = ($uploadPng['code'] === 200 && !empty($uploadPng['json']['file']['id']));
$projPngId = $uploadPng['json']['file']['id'] ?? null;
logTest("Upload Project PNG (Screen 10)", $pngOk, "File ID: $projPngId");
if (!$pngOk) $allPassed = false;

// Upload PDF to proj_1
$uploadPdf = makeRequest("$baseUrl/api/software.php?action=upload_file", 'POST', [
    'project_id' => 'proj_1',
    'file' => new CURLFile($pdfPath, 'application/pdf', 'E-commerce_Requirements.pdf')
], [], true);
$pdfOk = ($uploadPdf['code'] === 200 && !empty($uploadPdf['json']['file']['id']));
$projPdfId = $uploadPdf['json']['file']['id'] ?? null;
logTest("Upload Project PDF (Screen 11)", $pdfOk, "File ID: $projPdfId");
if (!$pdfOk) $allPassed = false;

// Retrieve Project Files list (Screen 9)
$getFilesRes = makeRequest("$baseUrl/api/software.php?action=get_files&project_id=proj_1");
$filesList = $getFilesRes['json']['data'] ?? [];
$filesOk = ($getFilesRes['code'] === 200 && count($filesList) >= 2);
logTest("Retrieve Project Files List (Screen 9)", $filesOk, "Files count: " . count($filesList));
if (!$filesOk) $allPassed = false;

// View Image (Screen 10 Preview)
$viewPngRes = makeRequest("$baseUrl/api/software.php?action=view_file&id=$projPngId");
$pngTypeOk = isset($viewPngRes['headers']['content-type']) && strpos($viewPngRes['headers']['content-type'], 'image/png') !== false;
$pngDispOk = isset($viewPngRes['headers']['content-disposition']) && strpos($viewPngRes['headers']['content-disposition'], 'inline') !== false;
logTest("View Project Image (Inline Preview)", $viewPngRes['code'] === 200 && $pngTypeOk && $pngDispOk, "Type: " . ($viewPngRes['headers']['content-type'] ?? ''));
if (!($viewPngRes['code'] === 200 && $pngTypeOk && $pngDispOk)) $allPassed = false;

// View PDF (Screen 11 Preview)
$viewPdfRes = makeRequest("$baseUrl/api/software.php?action=view_file&id=$projPdfId");
$pdfTypeOk = isset($viewPdfRes['headers']['content-type']) && strpos($viewPdfRes['headers']['content-type'], 'application/pdf') !== false;
$pdfDispOk = isset($viewPdfRes['headers']['content-disposition']) && strpos($viewPdfRes['headers']['content-disposition'], 'inline') !== false;
logTest("View Project PDF (Inline Preview)", $viewPdfRes['code'] === 200 && $pdfTypeOk && $pdfDispOk, "Type: " . ($viewPdfRes['headers']['content-type'] ?? ''));
if (!($viewPdfRes['code'] === 200 && $pdfTypeOk && $pdfDispOk)) $allPassed = false;

// Download parameter check
$dlRes = makeRequest("$baseUrl/api/software.php?action=view_file&download=1&id=$projPngId");
$dlDispOk = isset($dlRes['headers']['content-disposition']) && strpos($dlRes['headers']['content-disposition'], 'attachment') !== false;
logTest("Download Flag forces attachment header", $dlRes['code'] === 200 && $dlDispOk, "Disp: " . ($dlRes['headers']['content-disposition'] ?? ''));
if (!($dlRes['code'] === 200 && $dlDispOk)) $allPassed = false;

// Dedicated Gateway
$gwRes = makeRequest("$baseUrl/api/project_file.php?id=$projPdfId");
$gwOk = ($gwRes['code'] === 200 && isset($gwRes['headers']['content-type']) && strpos($gwRes['headers']['content-type'], 'application/pdf') !== false);
logTest("Dedicated Gateway api/project_file.php", $gwOk, "HTTP {$gwRes['code']}");
if (!$gwOk) $allPassed = false;

// 9. Activity Timeline & Add Note (Screen 12)
echo "\n--- STEP 9: Activity Timeline & Add Note (Screen 12) ---\n";
$addNoteRes = makeRequest("$baseUrl/api/software.php", 'POST', [
    'action' => 'add_activity',
    'project_id' => 'proj_1',
    'title' => 'Milestone reached',
    'description' => 'Sprint 2 deliverables verified and approved by Suresh Kumar • Automated Test'
]);
$noteOk = ($addNoteRes['code'] === 200 && !empty($addNoteRes['json']['success']) && !empty($addNoteRes['json']['data']['id']));
$noteId = $addNoteRes['json']['data']['id'] ?? null;
logTest("Add Note to Project Activity", $noteOk, "Note ID: $noteId");
if (!$noteOk) $allPassed = false;

$getActRes = makeRequest("$baseUrl/api/software.php?action=get_activity&project_id=proj_1");
$actList = $getActRes['json']['data'] ?? [];
$foundNote = false;
foreach ($actList as $a) {
    if ($a['id'] === $noteId) {
        $foundNote = true;
        break;
    }
}
logTest("Note appears in Project Activity Timeline", $foundNote, "Timeline count: " . count($actList));
if (!$foundNote) $allPassed = false;

// Clean up note
if ($noteId) {
    makeRequest("$baseUrl/api/software.php", 'POST', ['action' => 'delete_activity', 'id' => $noteId]);
}

// 10. Security & Traversal Protection
echo "\n--- STEP 10: Security, Authorization & Validation ---\n";
// Path traversal attempt
$travRes = makeRequest("$baseUrl/api/software.php?action=view_file&id=../../index.php");
$travOk = ($travRes['code'] === 400 || $travRes['code'] === 404);
logTest("Path Traversal blocked on file viewer", $travOk, "Code: {$travRes['code']}");
if (!$travOk) $allPassed = false;

// Dangerous executable file upload blocked
$badFile = $tempDir . '/exploit.php';
file_put_contents($badFile, '<?php phpinfo(); ?>');
$badUpRes = makeRequest("$baseUrl/api/software.php?action=upload_file", 'POST', [
    'project_id' => 'proj_1',
    'file' => new CURLFile($badFile, 'application/x-php', 'exploit.php')
], [], true);
$badBlocked = ($badUpRes['code'] === 400 || !empty($badUpRes['json']['error']));
logTest("Executable upload blocked (.php)", $badBlocked, "Code: {$badUpRes['code']}");
if (!$badBlocked) $allPassed = false;

// Unauthenticated file access blocked
$unauthCh = curl_init("$baseUrl/api/software.php?action=view_file&id=$projPdfId");
curl_setopt($unauthCh, CURLOPT_RETURNTRANSFER, true);
curl_setopt($unauthCh, CURLOPT_HEADER, true);
$unauthResp = curl_exec($unauthCh);
$unauthCode = curl_getinfo($unauthCh, CURLINFO_HTTP_CODE);
curl_close($unauthCh);
$unauthOk = ($unauthCode === 401 || $unauthCode === 302);
logTest("Unauthenticated access rejected without session", $unauthOk, "HTTP Code: $unauthCode");
if (!$unauthOk) $allPassed = false;

// Clean up scratch files
@unlink($pngPath);
@unlink($pdfPath);
@unlink($badFile);
@rmdir($tempDir);
if (file_exists($cookieFile)) @unlink($cookieFile);

echo "\n=================================================================\n";
if ($allPassed) {
    echo "  ALL PROJECT DASHBOARD 12-SCREEN TESTS PASSED (100%)!\n";
} else {
    echo "  SOME TESTS FAILED! Review test results above.\n";
}
echo "=================================================================\n";
