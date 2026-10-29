<?php
/**
 * Automated Test Suite for "Delete File" feature in Task Details -> Files section
 * Verifies:
 * 1. Authentication
 * 2. Upload multiple distinct attachments to a task
 * 3. Delete only the selected file (via POST action=delete_file)
 * 4. Verify selected file is deleted from database and filesystem
 * 5. Verify other attachments remain completely intact in database and filesystem
 * 6. Verify parent task remains completely intact in database
 * 7. Verify persistence across list refresh (api/tasks.php?action=get_files)
 * 8. Verify DELETE HTTP method with action=delete_file
 * 9. Verify error handling for non-existent file ID
 * 10. Verify unauthenticated protection (401)
 * 11. Verify frontend UI (Delete File button, Confirmation Modal, Alpine state & methods)
 */

$baseUrl = 'http://127.0.0.1:8000';
$cookieFile = __DIR__ . '/test_delete_file_cookies.txt';
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

require_once __DIR__ . '/api/db.php';
$pdo = Database::getConnection();

function logTest($name, $passed, $details = '') {
    $status = $passed ? "[PASS]" : "[FAIL]";
    echo sprintf("%-6s %-52s %s\n", $status, $name, $details ? "- $details" : '');
    return $passed;
}

function makeRequest($url, $method = 'GET', $data = null, $headers = [], $isMultipart = false, $useCookies = true) {
    global $cookieFile;
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HEADER, true);
    if ($useCookies) {
        curl_setopt($ch, CURLOPT_COOKIEJAR, $cookieFile);
        curl_setopt($ch, CURLOPT_COOKIEFILE, $cookieFile);
    }
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
    } elseif ($method === 'DELETE') {
        curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
        if ($data !== null) {
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

    return [
        'code' => $httpCode,
        'body' => $body
    ];
}

echo "=================================================================\n";
echo "      SOFTWARE CRM - DELETE TASK ATTACHMENT FILE TEST SUITE      \n";
echo "=================================================================\n\n";

$allPassed = true;

// -------------------------------------------------------------
// STEP 1: Authentication
// -------------------------------------------------------------
echo "--- STEP 1: Authentication ---\n";
$loginRes = makeRequest("$baseUrl/api/auth.php?action=login_credentials", 'POST', [
    'email' => 'suresh@softwarecrm.com',
    'password' => 'Password@123'
]);
$loginJson = json_decode($loginRes['body'], true);
$loginOk = ($loginRes['code'] === 200 && (($loginJson['step'] ?? '') === 'otp_required' || !empty($loginJson['success'])));
$allPassed = logTest("Step 1.1: Submit credentials", $loginOk, "Code: {$loginRes['code']}") && $allPassed;

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
$authOk = ($otpRes['code'] === 200 && (!empty($otpJson['success']) || !empty($otpJson['authenticated'])));
$allPassed = logTest("Step 1.2: Verify OTP ($otp)", $authOk, "User: " . ($otpJson['user']['name'] ?? 'Suresh Kumar')) && $allPassed;

// -------------------------------------------------------------
// STEP 2: Prepare Target Task & Upload 2 Distinct Attachments
// -------------------------------------------------------------
echo "\n--- STEP 2: Upload 2 Distinct Attachments to Task ---\n";

$taskId = 'tsk_1';
$taskCheck = $pdo->prepare("SELECT id, title FROM crm_tasks WHERE id = ?");
$taskCheck->execute([$taskId]);
$parentTaskBefore = $taskCheck->fetch(PDO::FETCH_ASSOC);

if (!$parentTaskBefore) {
    $taskId = 'tsk_test_' . time();
    $stmt = $pdo->prepare("INSERT INTO crm_tasks (id, title, description, project_name, priority, status, completed, due_date) VALUES (?, 'Delete Test Task', 'Testing delete attachment file', 'CRM Mobile App', 'High', 'In Progress', 0, '2026-11-01')");
    $stmt->execute([$taskId]);
    $parentTaskBefore = ['id' => $taskId, 'title' => 'Delete Test Task'];
}

$testUploadDir = __DIR__ . '/scratch/test_delete_uploads';
if (!is_dir($testUploadDir)) {
    mkdir($testUploadDir, 0777, true);
}

// Generate File 1 (Alpha PNG via valid base64 PNG)
$fileAlphaPath = $testUploadDir . '/alpha_' . time() . '.png';
$pngData = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNk+M9QDwADhgGAWjR9awAAAABJRU5ErkJggg==');
file_put_contents($fileAlphaPath, $pngData);

// Generate File 2 (Beta PDF via valid minimal PDF)
$fileBetaPath = $testUploadDir . '/beta_' . time() . '.pdf';
$pdfData = "%PDF-1.4\n1 0 obj<</Type/Catalog/Pages 2 0 R>>endobj 2 0 obj<</Type/Pages/Kids[3 0 R]/Count 1>>endobj 3 0 obj<</Type/Page/MediaBox[0 0 300 144]/Parent 2 0 R/Resources<<>>>>endobj\nxref\n0 4\n0000000000 65535 f\n0000000009 00000 n\n0000000052 00000 n\n0000000101 00000 n\ntrailer<</Size 4/Root 1 0 R>>\nstartxref\n178\n%%EOF\n";
file_put_contents($fileBetaPath, $pdfData);

// Upload File Alpha
$cfileAlpha = new CURLFile($fileAlphaPath, 'image/png', basename($fileAlphaPath));
$uploadAlphaRes = makeRequest("$baseUrl/api/tasks.php?action=upload_file", 'POST', [
    'task_id' => $taskId,
    'file' => $cfileAlpha
], [], true);
$uploadAlphaJson = json_decode($uploadAlphaRes['body'], true);
$alphaUploaded = ($uploadAlphaRes['code'] === 200 && !empty($uploadAlphaJson['file']['id']));
$fileAlphaId = $uploadAlphaJson['file']['id'] ?? null;
$allPassed = logTest("Upload Attachment A (Alpha PNG)", $alphaUploaded, "File ID: $fileAlphaId") && $allPassed;

// Upload File Beta
$cfileBeta = new CURLFile($fileBetaPath, 'application/pdf', basename($fileBetaPath));
$uploadBetaRes = makeRequest("$baseUrl/api/tasks.php?action=upload_file", 'POST', [
    'task_id' => $taskId,
    'file' => $cfileBeta
], [], true);
$uploadBetaJson = json_decode($uploadBetaRes['body'], true);
$betaUploaded = ($uploadBetaRes['code'] === 200 && !empty($uploadBetaJson['file']['id']));
$fileBetaId = $uploadBetaJson['file']['id'] ?? null;
$allPassed = logTest("Upload Attachment B (Beta PDF)", $betaUploaded, "File ID: $fileBetaId") && $allPassed;

// Verify both exist in DB
$dbCheck = $pdo->prepare("SELECT id, file_path, original_name FROM crm_task_files WHERE id IN (?, ?)");
$dbCheck->execute([$fileAlphaId, $fileBetaId]);
$recordsBefore = $dbCheck->fetchAll(PDO::FETCH_ASSOC);
$bothInDb = count($recordsBefore) === 2;
$allPassed = logTest("Both attachments verified in DB", $bothInDb, "Records: " . count($recordsBefore)) && $allPassed;

// Resolve physical disk paths
$baseUploadDir = realpath(__DIR__ . '/uploads/task_files');
$alphaDiskPath = null;
$betaDiskPath = null;
foreach ($recordsBefore as $rec) {
    if ($rec['id'] === $fileAlphaId) {
        $alphaDiskPath = realpath(__DIR__ . '/' . $rec['file_path']);
    }
    if ($rec['id'] === $fileBetaId) {
        $betaDiskPath = realpath(__DIR__ . '/' . $rec['file_path']);
    }
}
$bothOnDisk = ($alphaDiskPath && file_exists($alphaDiskPath) && $betaDiskPath && file_exists($betaDiskPath));
$allPassed = logTest("Both attachments exist on disk", $bothOnDisk) && $allPassed;

// -------------------------------------------------------------
// STEP 3: Delete ONLY Attachment A
// -------------------------------------------------------------
echo "\n--- STEP 3: Delete ONLY Attachment A (POST action=delete_file) ---\n";
$delAlphaRes = makeRequest("$baseUrl/api/tasks.php", 'POST', [
    'action' => 'delete_file',
    'id' => $fileAlphaId,
    'task_id' => $taskId
]);
$delAlphaJson = json_decode($delAlphaRes['body'], true);
$delAlphaOk = ($delAlphaRes['code'] === 200 && ($delAlphaJson['status'] ?? '') === 'success' && !empty($delAlphaJson['success']));
$allPassed = logTest("Delete Attachment A request succeeded", $delAlphaOk, "Code: {$delAlphaRes['code']}, Msg: " . ($delAlphaJson['message'] ?? '')) && $allPassed;

// -------------------------------------------------------------
// STEP 4: Verification of Selective Deletion & Safety
// -------------------------------------------------------------
echo "\n--- STEP 4: Verify Selective Deletion & Isolation ---\n";

// 4.1 Verify Attachment A is gone from DB
$checkAlphaDb = $pdo->prepare("SELECT * FROM crm_task_files WHERE id = ?");
$checkAlphaDb->execute([$fileAlphaId]);
$alphaDbGone = ($checkAlphaDb->fetch() === false);
$allPassed = logTest("Attachment A removed from database", $alphaDbGone) && $allPassed;

// 4.2 Verify Attachment A is unlinked from disk
$alphaDiskGone = ($alphaDiskPath && !file_exists($alphaDiskPath));
$allPassed = logTest("Attachment A deleted from storage disk", $alphaDiskGone) && $allPassed;

// 4.3 Verify Attachment B is STILL in database!
$checkBetaDb = $pdo->prepare("SELECT * FROM crm_task_files WHERE id = ?");
$checkBetaDb->execute([$fileBetaId]);
$betaDbRecord = $checkBetaDb->fetch(PDO::FETCH_ASSOC);
$betaStillInDb = !empty($betaDbRecord);
$allPassed = logTest("Attachment B remains intact in database", $betaStillInDb, "ID: " . ($betaDbRecord['id'] ?? 'None')) && $allPassed;

// 4.4 Verify Attachment B STILL exists on disk!
$betaStillOnDisk = ($betaDiskPath && file_exists($betaDiskPath));
$allPassed = logTest("Attachment B remains intact on disk", $betaStillOnDisk) && $allPassed;

// 4.5 Verify parent task is STILL intact!
$checkTask = $pdo->prepare("SELECT id, title FROM crm_tasks WHERE id = ?");
$checkTask->execute([$taskId]);
$taskAfter = $checkTask->fetch(PDO::FETCH_ASSOC);
$taskIntact = (!empty($taskAfter) && $taskAfter['id'] === $taskId);
$allPassed = logTest("Parent task NOT deleted (completely intact)", $taskIntact, "Title: " . ($taskAfter['title'] ?? 'None')) && $allPassed;

// -------------------------------------------------------------
// STEP 5: Persistence Across List Refresh (api/tasks.php?action=get_files)
// -------------------------------------------------------------
echo "\n--- STEP 5: Persistence Across List Refresh ---\n";
$listRes = makeRequest("$baseUrl/api/tasks.php?action=get_files&task_id=" . urlencode($taskId));
$listJson = json_decode($listRes['body'], true);
$filesList = $listJson['files'] ?? [];

$fileIdsInList = array_map(function($f) { return $f['id']; }, $filesList);
$alphaNotInList = !in_array($fileAlphaId, $fileIdsInList);
$betaInList = in_array($fileBetaId, $fileIdsInList);

$allPassed = logTest("Deleted Attachment A does NOT reappear in list", $alphaNotInList) && $allPassed;
$allPassed = logTest("Intact Attachment B remains present in list", $betaInList) && $allPassed;

// -------------------------------------------------------------
// STEP 6: Delete Attachment B via DELETE HTTP Method
// -------------------------------------------------------------
echo "\n--- STEP 6: Test DELETE HTTP Method (action=delete_file) ---\n";
$delBetaRes = makeRequest("$baseUrl/api/tasks.php", 'DELETE', [
    'action' => 'delete_file',
    'id' => $fileBetaId
]);
$delBetaJson = json_decode($delBetaRes['body'], true);
$delBetaOk = ($delBetaRes['code'] === 200 && ($delBetaJson['status'] ?? '') === 'success' && !empty($delBetaJson['success']));
$allPassed = logTest("Delete Attachment B via DELETE method", $delBetaOk, "Code: {$delBetaRes['code']}") && $allPassed;

// Verify Beta gone from DB and disk
$checkBetaDb->execute([$fileBetaId]);
$betaGoneNow = ($checkBetaDb->fetch() === false);
$betaDiskGoneNow = ($betaDiskPath && !file_exists($betaDiskPath));
$allPassed = logTest("Attachment B removed from DB via DELETE method", $betaGoneNow) && $allPassed;
$allPassed = logTest("Attachment B unlinked from disk via DELETE method", $betaDiskGoneNow) && $allPassed;

// -------------------------------------------------------------
// STEP 7: Security & Edge Cases
// -------------------------------------------------------------
echo "\n--- STEP 7: Security & Edge Cases ---\n";

// 7.1 Deleting non-existent file returns 404
$delNonExistent = makeRequest("$baseUrl/api/tasks.php", 'POST', [
    'action' => 'delete_file',
    'id' => 'tf_non_existent_' . time()
]);
$nonExistentHandled = ($delNonExistent['code'] === 404);
$allPassed = logTest("Non-existent File ID returns HTTP 404", $nonExistentHandled, "Code: {$delNonExistent['code']}") && $allPassed;

// 7.2 Unauthenticated deletion request returns 401
$unauthRes = makeRequest("$baseUrl/api/tasks.php", 'POST', [
    'action' => 'delete_file',
    'id' => 'tf_any'
], [], false, false);
$unauthHandled = ($unauthRes['code'] === 401);
$allPassed = logTest("Unauthenticated request returns HTTP 401", $unauthHandled, "Code: {$unauthRes['code']}") && $allPassed;

// -------------------------------------------------------------
// STEP 8: Frontend UI & Alpine Component Verification
// -------------------------------------------------------------
echo "\n--- STEP 8: Frontend UI & Alpine Verification ---\n";
$indexContent = file_get_contents(__DIR__ . '/index.php');

// 8.1 Delete File button exists in Task Details -> Files tab
$hasDeleteButton = (strpos($indexContent, 'promptDeleteTaskFile(f, activeTask)') !== false &&
                    strpos($indexContent, 'Delete File') !== false);
$allPassed = logTest("Task Details has 'Delete File' button", $hasDeleteButton) && $allPassed;

// 8.2 Confirmation modal exists
$hasConfirmModal = (strpos($indexContent, 'modalConfirmDeleteTaskFileOpen') !== false &&
                    strpos($indexContent, 'Delete Attachment?') !== false);
$allPassed = logTest("Confirmation modal 'Delete Attachment?' markup exists", $hasConfirmModal) && $allPassed;

// 8.3 Confirmation modal has Cancel and Delete File buttons
$hasModalButtons = (strpos($indexContent, 'confirmDeleteTaskFileAction()') !== false &&
                    strpos($indexContent, 'modalConfirmDeleteTaskFileOpen = false') !== false);
$allPassed = logTest("Modal has Cancel and Confirm Delete buttons", $hasModalButtons) && $allPassed;

// 8.4 Alpine reactive state initialized
$hasAlpineState = (strpos($indexContent, 'modalConfirmDeleteTaskFileOpen: false') !== false &&
                   strpos($indexContent, 'taskFileToDelete: null') !== false &&
                   strpos($indexContent, 'isDeletingTaskFile: false') !== false);
$allPassed = logTest("Alpine reactive state properties initialized", $hasAlpineState) && $allPassed;

// 8.5 Alpine action methods present
$hasAlpineMethods = (strpos($indexContent, 'promptDeleteTaskFile(file, task)') !== false &&
                     strpos($indexContent, 'confirmDeleteTaskFileAction()') !== false);
$allPassed = logTest("Alpine prompt & confirm delete methods defined", $hasAlpineMethods) && $allPassed;

// 8.6 File preview modal also provides Delete button
$hasViewerDelete = (strpos($indexContent, 'promptDeleteTaskFile(activeFileDoc.rawFile || activeFileDoc, activeTask)') !== false);
$allPassed = logTest("File Viewer preview modal includes Delete option", $hasViewerDelete) && $allPassed;

// Clean up test upload files
@unlink($fileAlphaPath);
@unlink($fileBetaPath);
if (file_exists($cookieFile)) {
    unlink($cookieFile);
}

echo "\n=================================================================\n";
if ($allPassed) {
    echo "  ALL DELETE TASK ATTACHMENT TESTS PASSED SUCCESSFULLY (100%)!\n";
} else {
    echo "  SOME TESTS FAILED! Review details above.\n";
}
echo "=================================================================\n";

exit($allPassed ? 0 : 1);
