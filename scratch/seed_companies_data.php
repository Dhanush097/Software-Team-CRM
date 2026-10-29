<?php
require_once __DIR__ . '/../api/db.php';
require_once __DIR__ . '/../api/company_project_matcher.php';

$pdo = Database::getConnection();

// Seed initial companies if not exist
$companies = [
    [
        'id' => 'cmp_abc',
        'name' => 'ABC',
        'industry' => 'Cloud & Software Innovations',
        'website' => 'https://abc-cloud.com',
        'email' => 'contact@abc.com',
        'phone' => '+1 (555) 019-2831',
        'address' => '100 Silicon Way, San Jose, CA',
        'description' => 'Flagship modern cloud applications and software engineering partner.'
    ],
    [
        'id' => 'cmp_abctech',
        'name' => 'ABC Tech Solutions',
        'industry' => 'Web & Digital Engineering',
        'website' => 'https://www.abctech.com',
        'email' => 'rahul@abctech.com',
        'phone' => '+91 98765 43210',
        'address' => 'Mumbai, Maharashtra, India',
        'description' => 'Key client for full-stack web development and digital marketing projects.'
    ],
    [
        'id' => 'cmp_verma',
        'name' => 'Verma Solutions',
        'industry' => 'Mobile Apps & Fintech',
        'website' => 'https://verma-solutions.com',
        'email' => 'priya@verma-solutions.com',
        'phone' => '+91 98765 43211',
        'address' => 'Delhi, India',
        'description' => 'Mobile application design and financial engineering consulting.'
    ],
    [
        'id' => 'cmp_patel',
        'name' => 'Patel & Co.',
        'industry' => 'ERP & Cloud Automation',
        'website' => 'https://patelco.in',
        'email' => 'amit@patelco.in',
        'phone' => '+91 98765 43212',
        'address' => 'Ahmedabad, Gujarat, India',
        'description' => 'ERP, supply chain, and accounting automation contracts.'
    ],
    [
        'id' => 'cmp_kapoor',
        'name' => 'Kapoor Designs',
        'industry' => 'Creative & UI/UX Systems',
        'website' => 'https://kapoordesigns.com',
        'email' => 'sneha@kapoordesigns.com',
        'phone' => '+91 98765 43213',
        'address' => 'Bengaluru, Karnataka, India',
        'description' => 'Brand identity, design systems, and product styling.'
    ],
    [
        'id' => 'cmp_ecommerce',
        'name' => 'E-commerce Website',
        'industry' => 'Retail & Digital Commerce',
        'website' => 'https://ecom-website.store',
        'email' => 'support@ecom-website.store',
        'phone' => '+1 (800) 555-0199',
        'address' => 'Austin, TX, USA',
        'description' => 'Enterprise eCommerce storefront and retail logistics platform.'
    ]
];

foreach ($companies as $c) {
    $stmt = $pdo->prepare("
        INSERT INTO crm_companies (id, name, industry, website, email, phone, address, description, created_at)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE industry = VALUES(industry), website = VALUES(website), email = VALUES(email), phone = VALUES(phone), address = VALUES(address), description = VALUES(description)
    ");
    $stmt->execute([$c['id'], $c['name'], $c['industry'], $c['website'], $c['email'], $c['phone'], $c['address'], $c['description']]);
}

// Ensure Project ABC exists
$stmt = $pdo->prepare("
    INSERT INTO crm_projects (id, title, client_name, category, status, progress, budget, project_manager, start_date, deadline, created_at)
    VALUES ('proj_abc', 'ABC', 'ABC', 'Cloud & SaaS', 'In Progress', 80, '$45,000', 'Rahul Sharma', '2024-07-01', '2024-12-15', NOW())
    ON DUPLICATE KEY UPDATE title = VALUES(title), category = VALUES(category)
");
$stmt->execute();

// Run automatic backfill
$summary = CompanyProjectMatcher::backfillAll($pdo);
echo "Seeded companies and ran backfill:\n";
print_r($summary);
