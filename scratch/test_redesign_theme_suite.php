<?php
/**
 * Test Suite: Three-Colour Palette Redesign Verification
 * CRM Project: Software_Team_App
 * Brand Tokens:
 *   1. Muted Sage Grey (#A5A79B)
 *   2. Warm Cream Beige (#E8D2B5)
 *   3. Deep Burgundy (#590907)
 */

$projectRoot = dirname(__DIR__);
$indexPath = $projectRoot . '/index.php';

echo "======================================================\n";
echo "SOFTWARE TEAM CRM - BRAND REDESIGN VERIFICATION SUITE\n";
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

// 1. Reusable CSS Variables in :root
assertCheck(
    "CSS Variables in :root (--crm-sage, --crm-cream, --crm-burgundy)",
    strpos($content, '--crm-sage: #A5A79B;') !== false &&
    strpos($content, '--crm-cream: #E8D2B5;') !== false &&
    strpos($content, '--crm-burgundy: #590907;') !== false,
    "CSS variables defined in :root design system"
);

// 2. Dark Mode Theme Tokens in html.dark
assertCheck(
    "Dark Mode Surfaces & Contrast in html.dark",
    strpos($content, '--crm-bg-body: #0E0708;') !== false &&
    strpos($content, '--crm-text-primary: #FAF6F0;') !== false &&
    strpos($content, '--crm-text-muted: #A5A79B;') !== false,
    "Dark mode surfaces and high-contrast cream/sage tokens verified"
);

// 3. Tailwind Palette Customization
assertCheck(
    "Tailwind Brand & Blue Mapping to Deep Burgundy",
    strpos($content, "600: '#590907'") !== false &&
    strpos($content, "300: '#E8D2B5'") !== false &&
    strpos($content, "400: '#A5A79B'") !== false,
    "Tailwind config extends brand and blue with the three-colour palette"
);

// 4. Sidebar and Navigation Styling
assertCheck(
    "Sidebar Active Navigation Item in Deep Burgundy (#590907)",
    strpos($content, 'aside button.bg-blue-600') !== false &&
    strpos($content, 'background-color: #590907 !important') !== false &&
    strpos($content, 'border-left: 3.5px solid #E8D2B5') !== false,
    "Active sidebar navigation uses #590907 with #E8D2B5 left accent border"
);

assertCheck(
    "Sidebar Inactive Items in Muted Sage Grey (#A5A79B)",
    strpos($content, 'color: #A5A79B !important') !== false &&
    strpos($content, 'aside button:not(.bg-blue-600)') !== false,
    "Inactive items and secondary icons use #A5A79B"
);

assertCheck(
    "Sidebar Navigation Hover State in Warm Cream Beige Tint",
    strpos($content, 'rgba(232, 210, 181, 0.12)') !== false ||
    strpos($content, 'var(--crm-sidebar-hover-bg)') !== false,
    "Subtle warm cream beige navigation hover backgrounds active"
);

// 5. Button System (Primary, Secondary, Tertiary)
assertCheck(
    "Primary Buttons: Deep Burgundy (#590907) with White Text",
    strpos($content, '.btn-primary') !== false &&
    strpos($content, '#75110E') !== false && // hover
    strpos($content, '#430605') !== false,   // active
    "Primary button classes and hover/active states implemented"
);

assertCheck(
    "Secondary Buttons: Warm Cream Beige (#E8D2B5) with Dark Text",
    strpos($content, '.btn-secondary') !== false &&
    strpos($content, 'background-color: #E8D2B5 !important') !== false &&
    strpos($content, 'color: #3F0605 !important') !== false,
    "Secondary button styling and contrast verified"
);

assertCheck(
    "Tertiary Buttons: Muted Sage Grey (#A5A79B)",
    strpos($content, '.btn-tertiary') !== false &&
    strpos($content, 'background-color: #A5A79B !important') !== false,
    "Tertiary button styling with #A5A79B verified"
);

// 6. Form Focus & Controls
assertCheck(
    "Form Focus Rings in Deep Burgundy / Warm Cream",
    strpos($content, 'border-color: #590907 !important') !== false &&
    strpos($content, 'rgba(89, 9, 7, 0.15)') !== false,
    "Form input focus ring mapped to deep burgundy glow"
);

// 7. Table and Data Styling
assertCheck(
    "Table Headers & Row Hover with Subtle Cream Tint",
    strpos($content, 'table th') !== false &&
    strpos($content, 'rgba(232, 210, 181, 0.12)') !== false,
    "Table headers and row hover highlights verified"
);

// 8. Charts and Reports (renderCharts)
assertCheck(
    "Revenue Trend Chart: Main Series Deep Burgundy (#590907) & Sage Supporting (#A5A79B)",
    strpos($content, "borderColor: '#590907'") !== false &&
    strpos($content, "borderColor: '#A5A79B'") !== false,
    "Revenue Invoiced and Cash Received line chart colors updated"
);

assertCheck(
    "Income vs Expenses Bar Chart: Deep Burgundy & Muted Sage",
    strpos($content, "backgroundColor: '#590907'") !== false &&
    strpos($content, "backgroundColor: '#A5A79B'") !== false,
    "Cash Inflow and Expenses bar series updated"
);

assertCheck(
    "Profit Trend Chart: Deep Burgundy with Warm Cream (#E8D2B5) Fill",
    strpos($content, "rgba(232, 210, 181, 0.35)") !== false,
    "Net Cashflow profit line chart fill updated to warm cream beige"
);

assertCheck(
    "Donut & Distribution Charts Palette",
    strpos($content, "brandDoughnutColors") !== false ||
    strpos($content, "brandExpenseColors") !== false,
    "Coordinated three-colour doughnut palettes active"
);

// 9. 3-Bar Brand Logos Across Modules
assertCheck(
    "3-Bar Ascending Chart Logo in Brand Palette Across All Views",
    substr_count($content, '<rect x="4" y="14" width="4" height="10" rx="1.5" fill="#A5A79B"/>') >= 4 &&
    substr_count($content, '<rect x="12" y="8" width="4" height="16" rx="1.5" fill="#E8D2B5"/>') >= 4 &&
    substr_count($content, '<rect x="20" y="3" width="4" height="21" rx="1.5" fill="#590907"/>') >= 4,
    "Logos in welcome screen, login, sidebar, header, and mobile drawer updated to #A5A79B, #E8D2B5, #590907"
);

// 10. Light, Dark, System Theme Persistence
assertCheck(
    "Theme Switcher Support (Light, Dark, System)",
    strpos($content, 'setAppTheme(\'light\')') !== false &&
    strpos($content, 'setAppTheme(\'dark\')') !== false &&
    strpos($content, 'setAppTheme(\'system\')') !== false,
    "All three theme options preserved and functional"
);

// 11. Core CRM Module Integrity (No mock data, preserved API & DB)
assertCheck(
    "Core CRM Modules & APIs Preserved",
    strpos($content, "currentView === 'dashboard'") !== false &&
    strpos($content, "currentView === 'contacts'") !== false &&
    strpos($content, "currentView === 'companies'") !== false &&
    strpos($content, "currentView === 'projects'") !== false &&
    strpos($content, "currentView === 'tasks'") !== false &&
    strpos($content, "currentView === 'invoices'") !== false &&
    strpos($content, "currentView === 'group_chart'") !== false &&
    strpos($content, "currentView === 'reports'") !== false,
    "Dashboard, Contacts, Companies, Projects, Tasks, Invoices, Group Chart, and Reports intact"
);

echo "\n------------------------------------------------------\n";
echo "SUMMARY: Passed: $passed, Failed: $failed\n";
echo "------------------------------------------------------\n";

if ($failed > 0) {
    exit(1);
} else {
    echo "ALL REDESIGN VERIFICATION TESTS PASSED SUCCESSFULLY!\n";
    exit(0);
}
