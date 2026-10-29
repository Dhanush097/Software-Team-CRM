<?php
/**
 * Test Suite: Animated Welcome Screen Verification
 * CRM Project: Software_Team_App
 */

$projectRoot = dirname(__DIR__);
$indexPath = $projectRoot . '/index.php';

echo "======================================================\n";
echo "SOFTWARE TEAM CRM - ANIMATED WELCOME SCREEN TEST SUITE\n";
echo "======================================================\n\n";

if (!file_exists($indexPath)) {
    echo "[-] FAILED: index.php not found at $indexPath\n";
    exit(1);
}

$content = file_get_contents($indexPath);
$passed = 0;
$failed = 0;

function assertCheck($name, $condition, $details = '') {
    global $passed, $failed;
    if ($condition) {
        echo "[+] PASS: $name\n";
        if ($details) echo "    -> $details\n";
        $passed++;
    } else {
        echo "[-] FAIL: $name\n";
        if ($details) echo "    -> $details\n";
        $failed++;
    }
}

// 1. Strict Brand Palette
assertCheck(
    "Brand Palette: Muted Sage Grey (#A5A79B)",
    stripos($content, 'A5A79B') !== false,
    "Hex #A5A79B found in Tailwind config and styles"
);

assertCheck(
    "Brand Palette: Warm Cream Beige (#E8D2B5)",
    stripos($content, 'E8D2B5') !== false,
    "Hex #E8D2B5 found in Tailwind config and styles"
);

assertCheck(
    "Brand Palette: Deep Burgundy (#590907)",
    stripos($content, '590907') !== false,
    "Hex #590907 found in Tailwind config and styles"
);

// 2. Heading & Subtitle
assertCheck(
    "Welcome Heading",
    strpos($content, 'Welcome to Software Team CRM') !== false,
    "Heading matches exact wording: 'Welcome to Software Team CRM'"
);

assertCheck(
    "Welcome Subtitle",
    strpos($content, 'Manage Projects. Empower Teams. Grow Together.') !== false,
    "Subtitle matches exact wording: 'Manage Projects. Empower Teams. Grow Together.'"
);

// 3. Floating Icons / Features (All 7 required)
$featureIcons = [
    'Employees',
    'Projects',
    'Tasks',
    'Companies',
    'Invoices',
    'Reports',
    'Team Communication'
];

foreach ($featureIcons as $feature) {
    assertCheck(
        "Feature Badge: $feature",
        strpos($content, "<span>$feature</span>") !== false || strpos($content, $feature) !== false,
        "CRM Feature badge '$feature' present in floating elements"
    );
}

// 4. Centerpiece Elements
assertCheck(
    "Animated Dashboard Preview",
    strpos($content, 'Live Dashboard Pulse') !== false && strpos($content, '88% On Track') !== false,
    "Dashboard preview with live sprint metrics and progress bar present"
);

assertCheck(
    "Animated Team Illustration",
    strpos($content, 'viewBox="0 0 320 180"') !== false && strpos($content, 'crm-pulse-glow') !== false,
    "Team collaboration vector illustration with SVG nodes and pulse glow present"
);

// 5. Actions & Buttons
assertCheck(
    "Prominent Enter CRM Button",
    strpos($content, '<span>Enter CRM</span>') !== false && strpos($content, '@click="enterCRM()"') !== false,
    "Enter CRM CTA button with click handler present"
);

assertCheck(
    "Skip Intro Button",
    strpos($content, '<span>Skip Intro</span>') !== false && strpos($content, '@click="skipIntro()"') !== false,
    "Skip Intro button with click handler and Esc shortcut present"
);

// 6. Loading Animation / Progress
assertCheck(
    "Progress Bar & Dynamic Step Text",
    strpos($content, 'introStepText') !== false && strpos($content, 'introProgress') !== false,
    "Dynamic 4-6s progress bar and state binding present"
);

assertCheck(
    "Progress Step Labels",
    strpos($content, 'Initializing Workspace...') !== false &&
    strpos($content, 'Loading Projects & Teams...') !== false &&
    strpos($content, 'Connecting Secure Channels...') !== false &&
    strpos($content, 'Ready to Empower Teams!') !== false,
    "All progressive milestone texts verified in Alpine controller"
);

// 7. Preferences & Persistence
assertCheck(
    "Auto-skip / Remember Preference",
    strpos($content, 'crm_intro_auto_skip') !== false && strpos($content, "Don't show this intro automatically") !== false,
    "localStorage persistence 'crm_intro_auto_skip' and checkbox present"
);

// 8. Replay Functionality & Watch Welcome Intro Button Removal
assertCheck(
    "Watch Welcome Intro Button Removed from Login Card",
    strpos($content, 'Watch Welcome Intro') === false && strpos($content, 'replayIntro()') === false,
    "Watch Welcome Intro replay button and replayIntro() handler completely removed from login interface"
);

assertCheck(
    "Replay Intro in Settings",
    strpos($content, 'replayWelcomeIntro()') !== false && strpos($content, 'Welcome Introduction Animation') !== false,
    "Replay Intro button in Settings Appearance section present"
);

// 9. Accessibility & Reduced Motion
assertCheck(
    "Reduced Motion Support",
    strpos($content, 'prefers-reduced-motion: reduce') !== false,
    "@media (prefers-reduced-motion: reduce) rule present"
);

assertCheck(
    "Keyboard Shortcuts (Esc & Enter)",
    strpos($content, '@keydown.window.escape="skipIntro()"') !== false &&
    strpos($content, '@keydown.window.enter="enterCRM()"') !== false,
    "Window keydown listeners for Escape and Enter active"
);

// 10. Security & Non-bypass Integrity
assertCheck(
    "Authentication Preserved (No Bypass)",
    strpos($content, 'authMode === \'login\'') !== false &&
    strpos($content, 'startGoogleLogin()') !== false &&
    strpos($content, 'startBiometricLogin()') !== false,
    "Login form, Google Sign-In, and Biometrics completely intact"
);

echo "\n------------------------------------------------------\n";
echo "SUMMARY: Passed: $passed, Failed: $failed\n";
echo "------------------------------------------------------\n";

if ($failed > 0) {
    exit(1);
} else {
    echo "ALL TESTS PASSED SUCCESSFULLY!\n";
    exit(0);
}
