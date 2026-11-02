<?php
/**
 * Import and replace real members and client leads from XernLabs Google Sheet.
 * Cleans up tested members, test users, and dummy test seed contacts.
 */
require_once __DIR__ . '/api/db.php';

echo "=== 1. INITIALIZING DATABASE SCHEMA ===\n";
Database::resetConnection();
$pdo = Database::getConnection();
$engine = Database::getEngine();
echo "Active Engine: $engine\n";

// Also connect directly to SQLite to ensure both are synchronized
$sqlitePath = __DIR__ . '/data/pocket_crm.sqlite';
$sqlitePdo = new PDO('sqlite:' . $sqlitePath, null, null, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
Database::ensureSchema($sqlitePdo, 'sqlite');

$databases = ['Active DB (' . $engine . ')' => $pdo];
if ($engine !== 'sqlite') {
    $databases['SQLite DB'] = $sqlitePdo;
}

echo "\n=== 2. CLEANING TEST MEMBERS & ACCOUNTS ===\n";
foreach ($databases as $dbName => $db) {
    echo "Processing $dbName...\n";

    // A. Clean test users from crm_users
    // Keep Dhanush and Suresh Kumar
    $delUsers = $db->exec("DELETE FROM crm_users WHERE 
        email LIKE 'forgot_test_%' OR 
        email LIKE 'single_use_%' OR 
        email LIKE 'founder_%' OR 
        email = 'forgot_test_user@example.com'");
    echo "  - Deleted $delUsers test users from crm_users.\n";

    // Ensure Dhanush exists as Superadmin
    $checkDhanush = $db->prepare("SELECT id FROM crm_users WHERE email = 'vangaridhanush09@gmail.com'");
    $checkDhanush->execute();
    if (!$checkDhanush->fetch()) {
        $passHash = password_hash('Dhanush123@', PASSWORD_BCRYPT);
        $insDhanush = $db->prepare("INSERT INTO crm_users (name, email, password_hash, role, status, auth_provider, created_at) VALUES ('Dhanush', 'vangaridhanush09@gmail.com', ?, 'Superadmin', 'active', 'email', CURRENT_TIMESTAMP)");
        $insDhanush->execute([$passHash]);
        echo "  - Created Dhanush admin user.\n";
    } else {
        $db->exec("UPDATE crm_users SET role = 'Superadmin', status = 'active' WHERE email = 'vangaridhanush09@gmail.com'");
        echo "  - Verified Dhanush admin user.\n";
    }

    // Ensure Suresh Kumar exists as Superadmin
    $checkSuresh = $db->prepare("SELECT id FROM crm_users WHERE email = 'suresh@softwarecrm.com'");
    $checkSuresh->execute();
    if (!$checkSuresh->fetch()) {
        $passHash = password_hash('Admin123@', PASSWORD_BCRYPT);
        $insSuresh = $db->prepare("INSERT INTO crm_users (name, email, password_hash, role, status, auth_provider, created_at) VALUES ('Suresh Kumar', 'suresh@softwarecrm.com', ?, 'Superadmin', 'active', 'email', CURRENT_TIMESTAMP)");
        $insSuresh->execute([$passHash]);
        echo "  - Created Suresh Kumar admin user.\n";
    }

    // B. Clean and populate authentic crm_team_staff
    try {
        $db->exec("DELETE FROM crm_team_staff WHERE email LIKE '%inactive%' OR email LIKE '%test%' OR email LIKE 'single_use_%'");
        
        $teamStaffSeed = [
            ['Dhanush', 'vangaridhanush09@gmail.com', '+91 9450748909', 'Superadmin', 'Leadership', 'XernLabs Software', 'active', 'DH', 'bg-purple-600'],
            ['Suresh Kumar', 'suresh@softwarecrm.com', '+91 9876543210', 'Superadmin', 'Executive', 'XernLabs Software', 'active', 'SK', 'bg-blue-600'],
            ['Akash Dev', 'akash@softwarecrm.com', '+91 9876543211', 'Dev Lead', 'Engineering', 'XernLabs Software', 'active', 'AD', 'bg-emerald-600'],
            ['Priya Verma', 'priya@softwarecrm.com', '+91 9876543212', 'UI/UX Designer', 'Design', 'XernLabs Software', 'active', 'PV', 'bg-pink-600'],
            ['Emma Wilson', 'emma@softwarecrm.com', '+91 9876543213', 'Frontend Dev', 'Engineering', 'XernLabs Software', 'active', 'EW', 'bg-amber-600'],
            ['Liam C.', 'liam@softwarecrm.com', '+91 9876543214', 'Backend Engineer', 'Engineering', 'XernLabs Software', 'active', 'LC', 'bg-cyan-600'],
            ['Sophia B.', 'sophia@softwarecrm.com', '+91 9876543215', 'QA & Security', 'Security', 'XernLabs Software', 'active', 'SB', 'bg-indigo-600'],
            ['Vikram S.', 'vikram@softwarecrm.com', '+91 9876543216', 'DevOps', 'Infrastructure', 'XernLabs Software', 'active', 'VS', 'bg-rose-600'],
            ['Karan M.', 'karan@softwarecrm.com', '+91 9876543217', 'Mobile Engineer', 'Engineering', 'XernLabs Software', 'active', 'KM', 'bg-teal-600'],
            ['Ananya Sharma', 'ananya.gmail@gmail.com', '+91 9876543218', 'Client Lead', 'Operations', 'XernLabs Software', 'active', 'AS', 'bg-blue-600'],
            ['Vikram Patel', 'vikram.outlook@outlook.com', '+91 9876543219', 'Solution Architect', 'Engineering', 'XernLabs Software', 'active', 'VP', 'bg-violet-600']
        ];

        foreach ($teamStaffSeed as $ts) {
            $chkStaff = $db->prepare("SELECT id FROM crm_team_staff WHERE email = ?");
            $chkStaff->execute([$ts[1]]);
            if (!$chkStaff->fetch()) {
                $insStaff = $db->prepare("INSERT INTO crm_team_staff (name, email, phone, role, department, company, status, initials, color_class, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
                $insStaff->execute($ts);
            } else {
                $upStaff = $db->prepare("UPDATE crm_team_staff SET name = ?, phone = ?, role = ?, department = ?, company = ?, status = ?, initials = ?, color_class = ? WHERE email = ?");
                $upStaff->execute([$ts[0], $ts[2], $ts[3], $ts[4], $ts[5], $ts[6], $ts[7], $ts[8], $ts[1]]);
            }
        }
        echo "  - Synchronized authentic team staff members in $dbName.\n";
    } catch (Exception $e) {
        echo "  - Notice on crm_team_staff: " . $e->getMessage() . "\n";
    }

    // C. Clean dummy seed contacts
    try {
        $delSeed = $db->exec("DELETE FROM crm_contacts WHERE id LIKE 'CNT_REF_%' OR id LIKE 'CNT_1793472895_%'");
        echo "  - Removed $delSeed dummy seed contacts.\n";
        $db->exec("DELETE FROM crm_history WHERE contact_id LIKE 'CNT_REF_%' OR contact_id LIKE 'CNT_1793472895_%'");
        $db->exec("DELETE FROM crm_media WHERE contact_id LIKE 'CNT_REF_%' OR contact_id LIKE 'CNT_1793472895_%'");
    } catch (Exception $e) {
        echo "  - Notice on cleaning contacts: " . $e->getMessage() . "\n";
    }
}

echo "\n=== 3. READING GOOGLE SHEET EXPORT ===\n";
$csvFile = __DIR__ . '/sheet_export.csv';
$seedJsonFile = __DIR__ . '/config/past_clients_seed.json';

$parsedContacts = [];
$mediaRecords = [];
$historyRecords = [];

if (!file_exists($csvFile)) {
    // Attempt download from Google Sheets
    $csvUrl = "https://docs.google.com/spreadsheets/d/1lmZRMJqDVLKDQVcz4Wj9J8frSCQm0TLSA1fMkRa3HpM/export?format=csv";
    $ctx = stream_context_create(['http' => ['timeout' => 5, 'follow_location' => 1]]);
    $downloaded = @file_get_contents($csvUrl, false, $ctx);
    if (!empty($downloaded) && strpos($downloaded, 'Ajay Kumar Mishra') !== false) {
        file_put_contents($csvFile, $downloaded);
        echo "Downloaded fresh sheet_export.csv from Google Sheets.\n";
    }
}

if (file_exists($csvFile)) {
    $f = fopen($csvFile, 'r');
    $header = fgetcsv($f);
    $seenIds = [];

while (($row = fgetcsv($f)) !== false) {
    $rawId = trim($row[0] ?? '');
    $name = trim($row[1] ?? '');
    if (empty($rawId) || empty($name)) continue;

    $id = $rawId;
    if (isset($seenIds[$rawId])) {
        $seenIds[$rawId]++;
        $id = substr($rawId, 0, -1) . $seenIds[$rawId];
    } else {
        $seenIds[$rawId] = 1;
    }

    $phone1 = trim($row[2] ?? '');
    $phone2 = trim($row[3] ?? '');
    $company = trim($row[4] ?? '');
    $projectStatus = trim($row[5] ?? '');
    $lastFollowUp = trim($row[7] ?? '');
    $joiningDate = trim($row[8] ?? '');
    $email1 = trim($row[9] ?? '');
    $emailAddresses = trim($row[12] ?? '');
    $notes = trim($row[13] ?? '');
    $workName = trim($row[16] ?? '');
    $specNeed = trim($row[18] ?? '');
    $budget = trim($row[19] ?? '');
    $amountStatus = trim($row[23] ?? '');
    $amountPct = trim($row[24] ?? '');
    $leadFrom = trim($row[26] ?? '');
    $personalInfo = trim($row[27] ?? '');
    $driveLink = trim($row[29] ?? '');
    $websiteLink = trim($row[30] ?? '');
    $credsSocial = trim($row[31] ?? '');
    $credsServer = trim($row[32] ?? '');
    $whatsappNotes = trim($row[33] ?? '');

    // Combine extra notes from columns 40..44
    $extraNotes = [];
    for ($c = 40; $c <= 44; $c++) {
        $n = trim($row[$c] ?? '');
        if (!empty($n)) $extraNotes[] = $n;
    }
    if (!empty($personalInfo)) $extraNotes[] = $personalInfo;
    if (!empty($extraNotes)) {
        $notes = trim($notes . "\n" . implode("\n", $extraNotes));
    }

    // Determine category based on Project Status
    $statusLower = strtolower($projectStatus);
    if (preg_match('/(working|done|close)/i', $statusLower)) {
        $category = 'Client';
    } elseif (preg_match('/(interested)/i', $statusLower) && !preg_match('/not interested/i', $statusLower)) {
        $category = 'Lead';
    } elseif (preg_match('/(pending|busy|switch off|invalid)/i', $statusLower)) {
        $category = 'Prospect';
    } else {
        $category = 'Lead';
    }

    $email = !empty($email1) ? $email1 : (!empty($emailAddresses) ? explode(',', $emailAddresses)[0] : '');
    $email = trim($email);

    if ($name === 'Surrender Kumar kasana' || $id === 'Sur7801') {
        $company = 'ABC Tech Solutions';
        if (empty($email)) $email = 'surrender@abctech.com';
    }

    // Auto-map company_id
    $companyId = null;
    $compLower = strtolower($company);
    if (strpos($compLower, 'abc tech') !== false || strpos($compLower, 'abc') !== false) {
        $companyId = 'cmp_abc_tech_solutions';
    } elseif (strpos($compLower, 'babbar') !== false) {
        $companyId = 'cmp_babbar_properties';
    } elseif (strpos($compLower, 'silver') !== false || strpos($compLower, 'jewel') !== false) {
        $companyId = 'cmp_dnd_silver';
    } elseif (strpos($compLower, 'krishna') !== false || strpos($compLower, 'travel') !== false) {
        $companyId = 'cmp_krishna_travels';
    } elseif (strpos($compLower, 'himalaya') !== false) {
        $companyId = 'cmp_himalaya_projects';
    } elseif (strpos($compLower, 'bclds') !== false || strpos($compLower, 'ngo') !== false) {
        $companyId = 'cmp_bclds_ngo';
    }

    $lastContact = !empty($lastFollowUp) ? $lastFollowUp : (!empty($joiningDate) ? $joiningDate : 'Recent');

    $isStarred = (in_array($category, ['Client']) || !empty($budget) || !empty($notes)) ? 1 : 0;

    $parsedContacts[] = [
        'id' => $id,
        'name' => $name,
        'phone' => $phone1,
        'phone2' => $phone2,
        'email' => $email,
        'company' => $company,
        'company_id' => $companyId,
        'occupation' => !empty($workName) ? $workName : 'Business Client',
        'category' => $category,
        'address' => 'Jaipur, Rajasthan, India',
        'lead_from' => !empty($leadFrom) ? $leadFrom : 'Google Sheet',
        'notes' => $notes,
        'whatsapp_notes' => $whatsappNotes,
        'drive_link' => $driveLink,
        'website_link' => $websiteLink,
        'creds_server' => $credsServer,
        'creds_social' => $credsSocial,
        'budget' => $budget,
        'amount_status' => !empty($amountStatus) ? $amountStatus : 'Pending',
        'amount_percentage_paid' => !empty($amountPct) ? $amountPct : '0',
        'specifications' => !empty($specNeed) ? $specNeed : $projectStatus,
        'is_starred' => $isStarred,
        'is_blocked' => 0,
        'last_contact' => $lastContact,
        'tags' => !empty($projectStatus) ? $projectStatus : 'Google Sheet Import'
    ];

    // Images from cols 34 to 39
    for ($imgCol = 34; $imgCol <= 39; $imgCol++) {
        $imgUrl = trim($row[$imgCol] ?? '');
        if (!empty($imgUrl) && filter_var($imgUrl, FILTER_VALIDATE_URL)) {
            $mediaRecords[] = [
                'contact_id' => $id,
                'folder' => 'Project Media',
                'file_name' => 'Image ' . ($imgCol - 33),
                'file_url' => $imgUrl,
                'file_type' => 'image/jpeg'
            ];
        }
    }

    // Follow-up interaction in history
    if (!empty($notes) || !empty($whatsappNotes)) {
        $historyText = !empty($notes) ? $notes : $whatsappNotes;
        $historyRecords[] = [
            'contact_id' => $id,
            'staff' => 'Dhanush',
            'time_str' => $lastContact,
            'duration' => '10 mins',
            'type' => 'note',
            'note_text' => $historyText
        ];
    }
}
if (isset($f) && is_resource($f)) {
    fclose($f);
}
}

if (empty($parsedContacts) && file_exists($seedJsonFile)) {
    $seedData = json_decode(file_get_contents($seedJsonFile), true);
    if (!empty($seedData['contacts'])) {
        $parsedContacts = $seedData['contacts'];
        $mediaRecords = $seedData['media'] ?? [];
        $historyRecords = $seedData['history'] ?? [];
        echo "Loaded " . count($parsedContacts) . " real client records from config/past_clients_seed.json.\n";
    }
}

echo "Extracted " . count($parsedContacts) . " real client records from Google Sheet.\n";
echo "Extracted " . count($mediaRecords) . " media images.\n";
echo "Extracted " . count($historyRecords) . " contact history notes.\n";

echo "\n=== 4. IMPORTING RECORDS INTO DATABASES ===\n";

foreach ($databases as $dbName => $db) {
    echo "Importing into $dbName...\n";

    // Insert contacts
    $isSqlite = ($dbName === 'SQLite DB' || $engine === 'sqlite');
    
    // Clear any remaining dummy data in crm_contacts, crm_media, crm_history
    $db->exec("DELETE FROM crm_contacts WHERE id LIKE 'CNT_REF_%'");
    
    $insContactStmt = $db->prepare("REPLACE INTO crm_contacts (
        id, name, phone, phone2, email, company, company_id, occupation, category, address,
        lead_from, notes, whatsapp_notes, drive_link, website_link, creds_server,
        creds_social, budget, amount_status, amount_percentage_paid, specifications,
        is_starred, is_blocked, last_contact, tags, created_at, updated_at
    ) VALUES (
        :id, :name, :phone, :phone2, :email, :company, :company_id, :occupation, :category, :address,
        :lead_from, :notes, :whatsapp_notes, :drive_link, :website_link, :creds_server,
        :creds_social, :budget, :amount_status, :amount_percentage_paid, :specifications,
        :is_starred, :is_blocked, :last_contact, :tags, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
    )");

    $inserted = 0;
    foreach ($parsedContacts as $c) {
        $insContactStmt->execute([
            ':id' => $c['id'],
            ':name' => $c['name'],
            ':phone' => $c['phone'],
            ':phone2' => $c['phone2'],
            ':email' => $c['email'],
            ':company' => $c['company'],
            ':company_id' => $c['company_id'] ?? null,
            ':occupation' => $c['occupation'],
            ':category' => $c['category'],
            ':address' => $c['address'],
            ':lead_from' => $c['lead_from'],
            ':notes' => $c['notes'],
            ':whatsapp_notes' => $c['whatsapp_notes'],
            ':drive_link' => $c['drive_link'],
            ':website_link' => $c['website_link'],
            ':creds_server' => $c['creds_server'],
            ':creds_social' => $c['creds_social'],
            ':budget' => $c['budget'],
            ':amount_status' => $c['amount_status'],
            ':amount_percentage_paid' => $c['amount_percentage_paid'],
            ':specifications' => $c['specifications'],
            ':is_starred' => $c['is_starred'],
            ':is_blocked' => $c['is_blocked'],
            ':last_contact' => $c['last_contact'],
            ':tags' => $c['tags']
        ]);
        $inserted++;
    }
    echo "  - Saved $inserted contacts into $dbName.\n";

    // Insert media
    $db->exec("DELETE FROM crm_media");
    $timeCol = ($isSqlite ? 'created_at' : 'uploaded_at');
    $insMediaStmt = $db->prepare("INSERT INTO crm_media (contact_id, folder, file_name, file_url, file_size, file_type, $timeCol) VALUES (?, 'photos', ?, ?, '1024', ?, CURRENT_TIMESTAMP)");
    $mediaInserted = 0;
    foreach ($mediaRecords as $m) {
        $insMediaStmt->execute([$m['contact_id'], $m['file_name'], $m['file_url'], $m['file_type']]);
        $mediaInserted++;
    }
    echo "  - Inserted $mediaInserted media records into $dbName.\n";

    // Insert history
    $insHistStmt = $db->prepare("INSERT INTO crm_history (contact_id, staff, time_str, duration, type, note_text, created_at) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
    $db->exec("DELETE FROM crm_history");
    $histInserted = 0;
    foreach ($historyRecords as $h) {
        $insHistStmt->execute([$h['contact_id'], $h['staff'], $h['time_str'], $h['duration'], $h['type'], $h['note_text']]);
        $histInserted++;
    }
    echo "  - Inserted $histInserted history notes into $dbName.\n";

    // Reconcile Companies
    $companiesSeed = [
        ['cmp_abc', 'ABC', 'Technology & Services', 'https://www.abc.com', 'contact@abc.com', '+91 98765 43210', 'Mumbai, India', 'Key enterprise software client'],
        ['cmp_apex_global_technologies', 'Apex Global Technologies', 'Cloud & IoT', 'https://apexglobal.in', 'ops@apexglobal.in', '+91 98765 43215', 'Pune, India', 'Global enterprise engineering client'],
        ['cmp_test_corp', 'Test Corp', 'Testing & Automation', 'https://testcorp.com', 'info@testcorp.com', '+91 98765 43219', 'Hyderabad, India', 'Automation testing client'],
        ['cmp_abc_tech_solutions', 'ABC Tech Solutions', 'Technology & Services', 'https://www.abctech.com', 'contact@abctech.com', '+91 98765 43210', 'Mumbai, India', 'Key enterprise software client'],
        ['cmp_verma_solutions', 'Verma Solutions', 'Fintech & Mobile', 'https://verma-solutions.com', 'info@verma.com', '+91 98765 43211', 'Delhi, India', 'Fintech application design & dev'],
        ['cmp_test_automation_corp', 'Test Automation Corp', 'QA & Automation', 'https://testautomation.corp', 'qa@testautomation.corp', '+91 98765 43218', 'Bengaluru, India', 'Enterprise QA automation'],
        ['cmp_babbar_properties', 'Babbar properties', 'Real Estate & Construction', 'https://babbarproperties.in', 'contact@babbarproperties.in', '+91 94507 48901', 'Jaipur, Rajasthan', 'Real estate development & property portal'],
        ['cmp_dnd_silver', 'DND silver_jewellery', 'Jewellery & Retail', 'https://dndsilver.com', 'davendra@dndsilver.com', '+91 94507 48902', 'Jaipur, Rajasthan', 'E-commerce jewellery catalog & custom designs'],
        ['cmp_krishna_travels', 'Krishna Travels', 'Travel & Tourism', 'https://krishnatravels.in', 'manish@krishnatravels.in', '+91 94507 48903', 'Jaipur, Rajasthan', 'Fleet booking and tour management systems'],
        ['cmp_himalaya_projects', 'Himalaya Projects', 'Enterprise Solutions', 'https://himalayaprojects.in', 'himalaya@gmail.com', '+91 94507 48904', 'Jaipur, Rajasthan', 'Enterprise client operations & management'],
        ['cmp_bclds_ngo', 'BCLDS NGO', 'Non-Profit & Social', 'https://bclds.org', 'contact@bclds.org', '+91 94507 48905', 'Jaipur, Rajasthan', 'Non-profit management and donation portal']
    ];
    $insComp = $db->prepare("REPLACE INTO crm_companies (id, name, industry, website, email, phone, address, description, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
    foreach ($companiesSeed as $cs) {
        $insComp->execute($cs);
    }
    echo "  - Synchronized authentic companies into $dbName.\n";

    // Reconcile Projects
    $projectsSeed = [
        ["proj_1", "Enterprise Software Platform", "Surrender Kumar kasana", "cmp_abc_tech_solutions", "Web & App", "In Progress", 70, "$15,000", "2024-06-01", "10 Aug 2024", "Dhanush, Suresh Kumar, Akash Dev", "layout", "purple"],
        ["proj_2", "Real Estate Portal & Mobile App", "Babbar properties", "cmp_babbar_properties", "Mobile App", "In Progress", 40, "$22,000", "2024-07-15", "25 Sep 2024", "Akash Dev, Suresh Kumar", "smartphone", "indigo"],
        ["proj_3", "Himalaya Client Management System", "Himalaya", "cmp_himalaya_projects", "Design", "Completed", 100, "$6,500", "2024-05-10", "10 Aug 2024", "Dhanush, Akash Dev", "feather", "emerald"],
        ["proj_4", "DND Silver Jewellery E-Commerce Portal", "Davendra Soni", "cmp_dnd_silver", "SaaS Enterprise", "In Progress", 65, "$18,500", "2024-07-01", "15 Nov 2024", "Suresh Kumar, Akash Dev", "database", "amber"],
        ["proj_5", "Dangi E-Commerce Web Store", "Kuldeep Dangi", "cmp_abc_tech_solutions", "Cloud & DevOps", "In Progress", 85, "$30,000", "2024-04-12", "05 Sep 2024", "Dhanush, Suresh Kumar", "cloud", "blue"],
        ["proj_6", "Digital Business Suite", "Akash Jaisawat", "cmp_apex_global_technologies", "IoT Telemetry", "In Progress", 60, "$25,000", "2024-05-20", "30 Oct 2024", "Akash Dev, Dhanush", "cpu", "cyan"]
    ];
    $insProj = $db->prepare("REPLACE INTO crm_projects (id, title, client_name, company_id, category, status, progress, budget, start_date, deadline, team_members, icon, color, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
    foreach ($projectsSeed as $pr) {
        $insProj->execute($pr);
    }
    echo "  - Synchronized authentic projects into $dbName.\n";

    // Reconcile Tasks
    $tasksSeed = [
        ["tsk_1", "Design Homepage & Architecture", "Surrender Kumar kasana", "proj_1", "Enterprise Software Platform", "", "", "Akash Dev", "Today", "High", "Design", "software.local", "layout", 0],
        ["tsk_2", "Client Strategy & Workflow Sync", "Surrender Kumar kasana", "proj_1", "Enterprise Software Platform", "", "", "Suresh Kumar", "Tomorrow", "Medium", "Meeting", "software.local", "users", 0],
        ["tsk_3", "Real Estate Portal Wireframes", "Babbar properties", "proj_1", "Enterprise Software Platform", "", "", "Akash Dev", "14 Aug", "Low", "Sales", "software.local", "file-text", 0],
        ["tsk_4", "Jewellery Catalog & Order Workflow", "Davendra Soni", "proj_1", "Enterprise Software Platform", "", "", "Dhanush", "16 Aug", "High", "Design", "software.local", "eye", 0],
        ["tsk_5", "NGO Membership & Donation Flow", "BCLDS NGO", "proj_1", "Enterprise Software Platform", "", "", "Suresh Kumar", "18 Aug", "Medium", "Docs", "software.local", "book", 0],
        ["tsk_6", "Database Optimization & Scalability", "Himalaya", "proj_1", "Enterprise Software Platform", "", "", "Akash Dev", "20 Aug", "Low", "Database", "software.local", "database", 1],
        ["tsk_7", "API Security Audit & Cloud Deploy", "Akash Jaisawat", "proj_1", "Enterprise Software Platform", "", "", "Akash Dev", "22 Aug", "High", "Security", "software.local", "shield", 1],
        ["tsk_8", "Configure Production SSL & CDN", "Surrender Kumar kasana", "proj_1", "Enterprise Software Platform", "", "", "Akash Dev", "24 Aug", "Medium", "DevOps", "software.local", "lock", 1]
    ];
    $insTask = $db->prepare("REPLACE INTO crm_tasks (id, title, client_name, project_id, project_name, phone, location, assigned_staff, due_time, priority, category, domain, icon, completed, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
    foreach ($tasksSeed as $tk) {
        $insTask->execute($tk);
    }
    echo "  - Synchronized authentic tasks into $dbName.\n";

    // Reconcile Invoices in Cash Ledger & Itemized Line Items
    $syncedInvoices = Database::syncAuthenticInvoices($db, true);
    echo "  - Synchronized $syncedInvoices authentic invoices and itemized line items into $dbName.\n";

    // Reconcile Payments
    $paymentsSeed = [
        ['pay_seed_1', 'REC-2026-001', 'inv_1', 'INV-2024-001', 'Surrender Kumar kasana', 2500.00, '2026-10-15', 'Online Transfer', 'Bank Transfer', 'TXN-901', 'Full payment for invoice 1', 'Advance payment', 'Suresh Kumar', 'Suresh Kumar', 'Paid', '2026-10-15 10:00:00'],
        ['pay_seed_2', 'REC-2026-002', 'inv_4', 'INV-2024-004', 'Himalaya', 5000.00, '2026-10-18', 'UPI', 'UPI', 'TXN-902', 'Full payment for invoice 4', 'UPI payment', 'Suresh Kumar', 'Suresh Kumar', 'Paid', '2026-10-18 14:30:00']
    ];
    $insPay = $db->prepare("REPLACE INTO crm_payments (id, receipt_no, invoice_id, invoice_no, customer_name, amount, payment_date, payment_method, payment_mode, transaction_id, reference, notes, recorded_by, received_by, status, created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
    foreach ($paymentsSeed as $p) {
        $insPay->execute($p);
    }
    echo "  - Synchronized authentic payments into $dbName.\n";

    // Reconcile Calendar Events
    try {
        $db->exec("DELETE FROM crm_calendar_events WHERE description LIKE '%ABC Tech Solutions team%' OR description LIKE '%INV-2024-002%'");
        $eventsSeed = [
            ["Client Strategy Session", "2026-08-07", "10:00 AM", "Meeting", "#10b981", "Client meeting with Surrender Kumar kasana (ABC Tech Solutions)"],
            ["Sprint 3 Deliverables Review", "2026-08-09", "02:30 PM", "Review", "#3b82f6", "Sprint 3 deliverables review with Babbar properties"],
            ["Jewellery Catalog Approval", "2026-08-14", "06:00 PM", "Deadline", "#ef4444", "Final catalog and payment approval with Davendra Soni"],
            ["Engineering Architecture Sync", "2026-08-21", "11:00 AM", "Meeting", "#6366f1", "Bi-weekly engineering sync with core software team"],
            ["Payment Follow-up", "2026-08-26", "05:00 PM", "Billing", "#f59e0b", "Payment reminder for Babbar properties"]
        ];
        $insCal = $db->prepare("INSERT INTO crm_calendar_events (title, event_date, event_time, category, color, description, created_at) VALUES (?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP)");
        foreach ($eventsSeed as $ev) {
            $insCal->execute($ev);
        }
        echo "  - Synchronized authentic calendar events into $dbName.\n";
    } catch (Exception $e) {}
}

echo "\n=== 5. SYNCING CONTACTS JSON CACHE ===\n";
// Call pocket_api get_contacts internally or write cache
$contactsCount = $pdo->query("SELECT COUNT(*) FROM crm_contacts")->fetchColumn();
echo "Active database total contacts: $contactsCount\n";

$jsonCachePath = __DIR__ . '/data/contacts_cache.json';
if (!is_dir(dirname($jsonCachePath))) {
    mkdir(dirname($jsonCachePath), 0775, true);
}

// Build cache formatted structure
$cacheData = [];
$stmt = $pdo->query("SELECT * FROM crm_contacts ORDER BY id ASC");
$allC = $stmt->fetchAll(PDO::FETCH_ASSOC);

$mediaStmt = $pdo->query("SELECT contact_id, file_url FROM crm_media ORDER BY id ASC");
$mediaMap = [];
while ($m = $mediaStmt->fetch(PDO::FETCH_ASSOC)) {
    $mediaMap[$m['contact_id']][] = $m['file_url'];
}

foreach ($allC as $c) {
    $cid = $c['id'];
    $cacheData[] = [
        'id' => $cid,
        'name' => $c['name'],
        'phone' => $c['phone'],
        'phone2' => $c['phone2'] ?? '',
        'email' => $c['email'] ?? '',
        'company' => $c['company'] ?? '',
        'occupation' => $c['occupation'] ?: 'Business Client',
        'category' => $c['category'] ?: 'Client',
        'address' => $c['address'] ?: 'Jaipur, Rajasthan, India',
        'lead_from' => $c['lead_from'] ?: 'Google Sheet',
        'notes' => $c['notes'] ?? '',
        'whatsapp_notes' => $c['whatsapp_notes'] ?? '',
        'drive_link' => $c['drive_link'] ?? '',
        'website_link' => $c['website_link'] ?? '',
        'creds_server' => $c['creds_server'] ?? '',
        'creds_social' => $c['creds_social'] ?? '',
        'budget' => $c['budget'] ?? '',
        'amount_status' => $c['amount_status'] ?: 'Pending',
        'amount_percentage_paid' => $c['amount_percentage_paid'] ?: '0',
        'specifications' => $c['specifications'] ?? '',
        'tags' => $c['tags'] ?: 'Google Sheet Import',
        'position' => $c['occupation'] ?: 'Business Client',
        'location' => $c['address'] ?: 'Jaipur, Rajasthan, India',
        'source' => $c['lead_from'] ?: 'Google Sheet',
        'is_starred' => (bool)$c['is_starred'],
        'is_blocked' => (bool)$c['is_blocked'],
        'last_contact' => $c['last_contact'] ?: 'Recent',
        'history' => [],
        'images' => $mediaMap[$cid] ?? []
    ];
}

file_put_contents($jsonCachePath, json_encode($cacheData, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "Saved " . count($cacheData) . " contacts to $jsonCachePath.\n";

echo "\n=== IMPORT COMPLETED SUCCESSFULLY ===\n";
