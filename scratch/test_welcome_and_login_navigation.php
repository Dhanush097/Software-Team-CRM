<?php
/**
 * Test Suite: Welcome Intro & Login Navigation Full Verification
 * CRM Project: Software_Team_App
 * Tests Scenarios A through G from the user prompt.
 */

$projectRoot = dirname(__DIR__);
$indexPath = $projectRoot . '/index.php';
$content = file_get_contents($indexPath);

$passed = 0;
$failed = 0;

function assertTest($scenario, $name, $condition, $details = '') {
    global $passed, $failed;
    if ($condition) {
        echo "[+] PASS [$scenario]: $name\n";
        if ($details) echo "    -> $details\n";
        $passed++;
    } else {
        echo "[-] FAIL [$scenario]: $name\n";
        if ($details) echo "    -> $details\n";
        $failed++;
    }
}

echo "=================================================================\n";
echo "SOFTWARE TEAM CRM - NAVIGATION & INTRO VERIFICATION TEST SUITE\n";
echo "=================================================================\n\n";

// SCENARIO A: Fresh Visit Initial Page Load
assertTest(
    "Scenario A",
    "Welcome Intro is initial page on fresh visit (default state)",
    strpos($content, 'showIntro: true') !== false &&
    strpos($content, 'introStage: 1') !== false,
    "Alpine controller initializes showIntro=true and introStage=1 by default"
);

assertTest(
    "Scenario A",
    "Welcome Intro Screen 1 & Screen 2 present in DOM",
    strpos($content, 'data-component="CRMLogoAnimation"') !== false &&
    strpos($content, 'data-component="CRMWelcomeScreen"') !== false,
    "Both CRMLogoAnimation (Stage 1) and CRMWelcomeScreen (Stage 2) components defined"
);

assertTest(
    "Scenario A",
    "Welcome Screen Branding & Color Palette (Sage #A5A79B, Beige #E8D2B5, Burgundy #590907)",
    stripos($content, 'A5A79B') !== false &&
    stripos($content, 'E8D2B5') !== false &&
    stripos($content, '590907') !== false,
    "All three core brand hex values configured in CSS and components"
);

assertTest(
    "Scenario A",
    "Clear Enter CRM action button present on welcome screen",
    strpos($content, 'id="crm-enter-btn"') !== false &&
    strpos($content, '@click="enterCRM()"') !== false &&
    strpos($content, '<span>Enter CRM</span>') !== false,
    "Enter CRM CTA button rendered with proper click event binding"
);

// SCENARIO B: Click Enter CRM Navigation
assertTest(
    "Scenario B",
    "enterCRM() smoothly navigates to login without reload",
    strpos($content, 'enterCRM()') !== false &&
    strpos($content, 'this.introExiting = true') !== false &&
    strpos($content, 'this.showIntro = false') !== false &&
    strpos($content, "this.authMode = 'login'") !== false,
    "enterCRM method sets introExiting=true, then after transition sets showIntro=false, authMode='login'"
);

assertTest(
    "Scenario B",
    "enterCRM() pushes #login to browser history",
    strpos($content, "window.history.pushState({ page: 'login' }, 'Software CRM - Login', '#login')") !== false,
    "Browser URL hash cleanly synchronized with #login"
);

assertTest(
    "Scenario B",
    "No simultaneous appearance (introExiting guard on both intro and login)",
    strpos($content, 'x-show="showIntro && introStage === 1 && !introExiting"') !== false &&
    strpos($content, 'x-show="showIntro && introStage === 2 && !introExiting"') !== false &&
    strpos($content, 'x-show="!showIntro && !introExiting"') !== false,
    "All three screens guard with !introExiting to prevent simultaneous display during transition"
);

// SCENARIO C: Complete Removal of 'Watch Welcome Intro' Button
assertTest(
    "Scenario C",
    "'Watch Welcome Intro' button text completely removed",
    stripos($content, 'Watch Welcome Intro') === false,
    "Zero occurrences of 'Watch Welcome Intro' found in index.php"
);

assertTest(
    "Scenario C",
    "Associated replayIntro() event handler completely removed from login card",
    strpos($content, 'replayIntro()') === false,
    "Zero occurrences of 'replayIntro()' found in index.php"
);

// SCENARIO D: Refreshing on Login Page
assertTest(
    "Scenario D",
    "checkIntroPreference() respects explicit login and stays on login upon refresh",
    strpos($content, "const isLoginExplicit = hash === '#login' || urlParams.get('view') === 'login' || bypassIntro;") !== false &&
    strpos($content, "if (isLoginExplicit && !forceIntro)") !== false &&
    strpos($content, "this.showIntro = false;\n            this.introExiting = false;\n            this.isNavigatingToLogin = false;\n            this.authMode = 'login';") !== false,
    "When URL contains #login on page refresh, isLoginExplicit triggers immediate login display without intro"
);

assertTest(
    "Scenario D",
    "Fresh visit does not skip intro due to stale sessionStorage",
    strpos($content, "if (!isLoginExplicit && !forceIntro) {\n            try { sessionStorage.removeItem('crm_active_screen'); } catch (e) {}\n          }") !== false,
    "sessionStorage active screen cleaned when visiting root URL without #login"
);

// SCENARIO E: Logout Flow
assertTest(
    "Scenario E",
    "logout() securely invalidates session and redirects to login",
    strpos($content, "api/auth.php?action=logout") !== false &&
    strpos($content, "index.php?view=login#login") !== false,
    "Logout dispatches to backend API and redirects user to index.php?view=login#login"
);

// SCENARIO F: Route Protection for Unauthenticated Users
assertTest(
    "Scenario F",
    "Protected dashboard URLs redirect unauthenticated users to login",
    strpos($content, 'in_array($reqView, $protectedDashboardViews, true)') !== false &&
    strpos($content, "header('Location: index.php?view=login#login');") !== false,
    "Server-side PHP 302 redirect enforces authentication before loading any protected view"
);

assertTest(
    "Scenario F",
    "Login form preserves email, password, remember me, forgot password, biometrics, Google SSO",
    strpos($content, 'id="login_email_input"') !== false &&
    strpos($content, 'x-model="loginForm.password"') !== false &&
    strpos($content, 'x-model="loginForm.rememberMe"') !== false &&
    strpos($content, 'startBiometricLogin()') !== false &&
    strpos($content, 'startGoogleLogin()') !== false &&
    strpos($content, 'Forgot password?') !== false,
    "All credential and federated authentication components strictly intact"
);

// SCENARIO G: Clean Centered Login Card & Screen 2 Dynamic Workspace Animation
assertTest(
    "Scenario G",
    "Unified branding header present on login card",
    strpos($content, 'Unified Brand Header') !== false &&
    strpos($content, 'Software CRM') !== false &&
    strpos($content, 'ENTERPRISE TEAM PLATFORM') !== false,
    "Brand header renders cleanly at the top of the centered login card"
);

assertTest(
    "Scenario G",
    "Login screen is clean centered single card layout (left panel removed)",
    strpos($content, 'max-w-md mx-auto my-auto') !== false &&
    strpos($content, '<!-- LEFT BRANDING & WORKSPACE PANEL (Desktop md+) -->') === false,
    "Left workspace preview removed from login page, converted into a focused max-w-md centered card"
);

assertTest(
    "Scenario G",
    "Screen 2 Workspace & Desk illustration features live SVG animations",
    strpos($content, 'crm-data-flow') !== false &&
    strpos($content, 'crm-screen-bar-1') !== false &&
    strpos($content, 'crm-shimmer-glow') !== false,
    "Workspace preview desk illustration features animated flowing data line, pulsing screen bars, and sprint shimmer"
);

// HTTP ENDPOINT TESTS VIA APACHE
echo "\n--- Live Apache Server HTTP Checks ---\n";

function checkHttp($url, $followRedirect = false) {
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => true,
        CURLOPT_NOBODY         => true,
        CURLOPT_TIMEOUT        => 5,
        CURLOPT_FOLLOWLOCATION => $followRedirect
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $redirectUrl = curl_getinfo($ch, CURLINFO_REDIRECT_URL);
    curl_close($ch);
    return ['code' => $code, 'redirect' => $redirectUrl];
}

$rootCheck = checkHttp('http://127.0.0.1/software_team/index.php');
assertTest(
    "HTTP Check",
    "Root URL returns HTTP 200 OK",
    $rootCheck['code'] === 200,
    "HTTP {$rootCheck['code']} for http://127.0.0.1/software_team/index.php"
);

$protectedCheck = checkHttp('http://127.0.0.1/software_team/index.php?view=dashboard', false);
assertTest(
    "HTTP Check",
    "Protected view (?view=dashboard) unauthenticated returns HTTP 302 Redirect",
    $protectedCheck['code'] === 302 && strpos($protectedCheck['redirect'], 'view=login') !== false,
    "HTTP {$protectedCheck['code']} redirected to {$protectedCheck['redirect']}"
);

echo "\n-----------------------------------------------------------------\n";
echo "SUMMARY: Passed: $passed, Failed: $failed\n";
echo "-----------------------------------------------------------------\n";

if ($failed === 0) {
    echo "ALL TESTS PASSED SUCCESSFULLY! (100% compliant with Scenarios A-G)\n";
    exit(0);
} else {
    echo "FAILED: $failed test(s) failed.\n";
    exit(1);
}
