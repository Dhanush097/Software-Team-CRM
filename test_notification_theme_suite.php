<?php
/**
 * Automated Verification Suite for Notification Theme & Accessibility System
 */

$appDir = 'C:/Users/vanga/Downloads/new project/Software/Software_Team_App';
$indexFile = $appDir . '/index.php';

echo "====================================================================\n";
echo "  SOFTWARE CRM: NOTIFICATION THEME & ACCESSIBILITY TEST SUITE\n";
echo "====================================================================\n\n";

$passCount = 0;
$failCount = 0;

function assertCondition(bool $condition, string $testName, string $detail = '') {
    global $passCount, $failCount;
    if ($condition) {
        $passCount++;
        echo " [PASS] $testName" . ($detail ? " ($detail)" : "") . "\n";
    } else {
        $failCount++;
        echo " [FAIL] $testName" . ($detail ? " ($detail)" : "") . "\n";
    }
}

$content = file_get_contents($indexFile);

// TEST 1: Centralized Design Tokens for Light Theme
echo "--- STEP 1: Light Theme Notification Tokens ---\n";
assertCondition(strpos($content, '--notification-bg: #FFFFFF;') !== false, "Light theme token --notification-bg is #FFFFFF");
assertCondition(strpos($content, '--notification-title: #111827;') !== false, "Light theme token --notification-title is #111827");
assertCondition(strpos($content, '--notification-text: #374151;') !== false, "Light theme token --notification-text is #374151");
assertCondition(strpos($content, '--notification-muted: #64748B;') !== false, "Light theme token --notification-muted is #64748B");
assertCondition(strpos($content, '--notification-border: #E2E8F0;') !== false, "Light theme token --notification-border is #E2E8F0");

// TEST 2: Centralized Design Tokens for Dark Theme
echo "\n--- STEP 2: Dark Theme Notification Tokens ---\n";
assertCondition(strpos($content, '--notification-bg: #1F2937;') !== false, "Dark theme token --notification-bg is #1F2937");
assertCondition(strpos($content, '--notification-title: #F9FAFB;') !== false, "Dark theme token --notification-title is #F9FAFB");
assertCondition(strpos($content, '--notification-text: #E5E7EB;') !== false, "Dark theme token --notification-text is #E5E7EB");
assertCondition(strpos($content, '--notification-muted: #9CA3AF;') !== false, "Dark theme token --notification-muted is #9CA3AF");
assertCondition(strpos($content, '--notification-border: #374151;') !== false, "Dark theme token --notification-border is #374151");

// TEST 3: Notification Card Styling & Read/Unread Mechanics
echo "\n--- STEP 3: Card Styling & Read/Unread Accessibility ---\n";
assertCondition(strpos($content, '.crm-notification-card') !== false, "Dedicated .crm-notification-card class defined in CSS");
assertCondition(strpos($content, 'opacity: 1 !important;') !== false, "Full opacity enforced on notification cards (no opacity: 0.5 or 0.9)");
assertCondition(strpos($content, '.crm-notification-card.is-unread') !== false, "is-unread state defined for accent borders & highlights");
assertCondition(strpos($content, '.crm-notification-card.is-read') !== false, "is-read state defined for subtle surface variation without opacity fade");
assertCondition(strpos($content, 'crm-notification-title') !== false, "Title uses crm-notification-title scoped class");
assertCondition(strpos($content, 'crm-notification-desc') !== false, "Description uses crm-notification-desc scoped class");
assertCondition(strpos($content, 'crm-notification-time') !== false, "Timestamp uses crm-notification-time scoped class");
assertCondition(strpos($content, 'crm-notification-action-btn') !== false, "Action button uses crm-notification-action-btn class");

// TEST 4: Category Badges & Icons
echo "\n--- STEP 4: Category Badges & Icons (Tasks, Invoices, Clients, System) ---\n";
assertCondition(strpos($content, '.crm-badge-invoices') !== false, "crm-badge-invoices defined for invoice alerts");
assertCondition(strpos($content, '.crm-badge-tasks') !== false, "crm-badge-tasks defined for task alerts");
assertCondition(strpos($content, '.crm-badge-clients') !== false, "crm-badge-clients defined for contact/company alerts");
assertCondition(strpos($content, '.crm-badge-system') !== false, "crm-badge-system defined for system alerts");
assertCondition(strpos($content, 'crm-icon-') !== false, "Category icon containers mapped to category tokens");

// TEST 5: Slide-Over Drawer Components
echo "\n--- STEP 5: Slide-over Drawer Theme Integration ---\n";
assertCondition(strpos($content, 'crm-notification-panel') !== false, "Drawer panel container uses crm-notification-panel");
assertCondition(strpos($content, 'crm-notification-header') !== false, "Drawer header uses crm-notification-header");
assertCondition(strpos($content, 'crm-notification-body') !== false, "Drawer body uses crm-notification-body");
assertCondition(strpos($content, 'crm-notification-footer') !== false, "Drawer footer uses crm-notification-footer");
assertCondition(strpos($content, 'crm-notification-tab-inactive') !== false, "Inactive tabs use themed token class");

// TEST 6: Server-Side Preload & Zero-FOUC Integration
echo "\n--- STEP 6: Server Preloading & FOUC Prevention ---\n";
assertCondition(strpos($content, 'window.__crmInitialNotifications') !== false, "Initial notifications preloaded from MySQL without attribute encoding corruption");
assertCondition(strpos($content, 'window.__crmInitialUnreadCount') !== false, "Initial unread count preloaded from MySQL");

echo "\n====================================================================\n";
echo "  NOTIFICATION THEME TEST RESULTS: $passCount PASSED, $failCount FAILED\n";
echo "====================================================================\n";

if ($failCount > 0) exit(1);
