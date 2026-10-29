<?php
/**
 * Software CRM - Database Upgrade & Seeder Script
 */
require_once __DIR__ . '/db.php';

$dbs = ['pocket_crm_db', 'u213657911_crm'];

foreach ($dbs as $dbname) {
    try {
        $pdo = new PDO("mysql:host=127.0.0.1;port=3306;dbname=$dbname;charset=utf8mb4", 'root', '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION
        ]);
    } catch (Exception $e) {
        continue;
    }

    // 1. Create tables if not exist
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS `crm_projects` (
          `id` VARCHAR(64) PRIMARY KEY,
          `title` VARCHAR(255) NOT NULL,
          `client_name` VARCHAR(128) NOT NULL,
          `category` VARCHAR(64) DEFAULT 'Web & App',
          `status` VARCHAR(32) DEFAULT 'In Progress',
          `progress` INT DEFAULT 50,
          `budget` VARCHAR(64) DEFAULT '$10,000',
          `start_date` DATE DEFAULT NULL,
          `deadline` VARCHAR(64) DEFAULT NULL,
          `team_members` TEXT DEFAULT NULL,
          `icon` VARCHAR(64) DEFAULT 'layout',
          `color` VARCHAR(32) DEFAULT 'blue',
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `crm_tasks` (
          `id` VARCHAR(64) PRIMARY KEY,
          `title` VARCHAR(255) NOT NULL,
          `client_name` VARCHAR(128) DEFAULT 'Customer',
          `phone` VARCHAR(64) DEFAULT '',
          `location` VARCHAR(128) DEFAULT '',
          `assigned_staff` VARCHAR(128) DEFAULT 'Dev Team',
          `due_time` VARCHAR(64) DEFAULT 'Today',
          `priority` VARCHAR(32) DEFAULT 'Medium',
          `category` VARCHAR(64) DEFAULT 'general',
          `domain` VARCHAR(128) DEFAULT 'software.local',
          `icon` VARCHAR(32) DEFAULT '⚡',
          `completed` TINYINT(1) DEFAULT 0,
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `crm_groups` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `name` VARCHAR(150) NOT NULL UNIQUE,
                `company_id` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL,
                `description` TEXT NULL,
                `leader_id` VARCHAR(64) NULL,
                `color` VARCHAR(50) DEFAULT '#2563eb',
                `icon` VARCHAR(50) DEFAULT 'users',
                `created_by` VARCHAR(64) NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_leader` (`leader_id`),
                INDEX `idx_crm_groups_company_id` (`company_id`)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

            CREATE TABLE IF NOT EXISTS `crm_group_members` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `group_id` INT NOT NULL,
                `employee_id` VARCHAR(64) NOT NULL,
                `role_in_group` VARCHAR(50) DEFAULT 'member',
                `added_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_group_member` (`group_id`, `employee_id`),
                INDEX `idx_group` (`group_id`),
                INDEX `idx_employee` (`employee_id`),
                CONSTRAINT `fk_group_members_group` FOREIGN KEY (`group_id`) REFERENCES `crm_groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

            CREATE TABLE IF NOT EXISTS `crm_group_chats` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `group_id` INT NOT NULL,
                `sender_id` VARCHAR(64) NOT NULL,
                `sender_name` VARCHAR(128) NOT NULL,
                `sender_role` VARCHAR(64) DEFAULT 'Member',
                `sender_color` VARCHAR(32) DEFAULT '#2563eb',
                `sender_avatar` VARCHAR(255) DEFAULT '',
                `message_type` VARCHAR(32) DEFAULT 'text',
                `message` TEXT NOT NULL,
                `attachment_type` VARCHAR(32) DEFAULT NULL,
                `attachment_url` TEXT DEFAULT NULL,
                `attachment_name` VARCHAR(255) DEFAULT NULL,
                `reply_to_id` INT DEFAULT NULL,
                `reply_to_name` VARCHAR(128) DEFAULT NULL,
                `reply_to_text` TEXT DEFAULT NULL,
                `deletion_state` ENUM('none', 'unsent', 'deleted_everyone') DEFAULT 'none',
                `edited_at` DATETIME DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_gc_group` (`group_id`),
                INDEX `idx_gc_sender` (`sender_id`),
                INDEX `idx_gc_created` (`created_at`),
                CONSTRAINT `fk_gc_group` FOREIGN KEY (`group_id`) REFERENCES `crm_groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

            CREATE TABLE IF NOT EXISTS `crm_group_message_deletions` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `message_id` INT NOT NULL,
                `user_id` VARCHAR(64) NOT NULL,
                `deleted_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_msg_user_del` (`message_id`, `user_id`),
                INDEX `idx_gmd_msg` (`message_id`),
                INDEX `idx_gmd_user` (`user_id`),
                CONSTRAINT `fk_gmd_msg` FOREIGN KEY (`message_id`) REFERENCES `crm_group_chats` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

            CREATE TABLE IF NOT EXISTS `crm_group_message_receipts` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `message_id` INT NOT NULL,
                `group_id` INT NOT NULL,
                `user_id` VARCHAR(64) NOT NULL,
                `user_name` VARCHAR(128) DEFAULT '',
                `delivered_at` DATETIME DEFAULT NULL,
                `read_at` DATETIME DEFAULT NULL,
                UNIQUE KEY `uq_msg_user_receipt` (`message_id`, `user_id`),
                INDEX `idx_gmr_msg` (`message_id`),
                INDEX `idx_gmr_user` (`user_id`),
                INDEX `idx_gmr_group` (`group_id`),
                CONSTRAINT `fk_gmr_msg` FOREIGN KEY (`message_id`) REFERENCES `crm_group_chats` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

            CREATE TABLE IF NOT EXISTS `crm_group_calls` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `group_id` INT NOT NULL,
                `initiator_id` VARCHAR(64) NOT NULL,
                `initiator_name` VARCHAR(128) NOT NULL,
                `call_type` ENUM('audio', 'video') NOT NULL DEFAULT 'audio',
                `call_status` ENUM('calling', 'ringing', 'connecting', 'connected', 'reconnecting', 'ended', 'failed') NOT NULL DEFAULT 'calling',
                `is_presenting` TINYINT(1) DEFAULT 0,
                `presenter_id` VARCHAR(64) DEFAULT NULL,
                `presenter_name` VARCHAR(128) DEFAULT NULL,
                `start_time` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `end_time` DATETIME DEFAULT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                `updated_at` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX `idx_call_group` (`group_id`),
                INDEX `idx_call_status` (`call_status`),
                CONSTRAINT `fk_call_group` FOREIGN KEY (`group_id`) REFERENCES `crm_groups` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

            CREATE TABLE IF NOT EXISTS `crm_group_call_participants` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `call_id` INT NOT NULL,
                `user_id` VARCHAR(64) NOT NULL,
                `user_name` VARCHAR(128) NOT NULL,
                `user_role` VARCHAR(64) DEFAULT 'Member',
                `user_avatar` VARCHAR(255) DEFAULT '',
                `status` ENUM('invited', 'ringing', 'joined', 'left', 'declined') DEFAULT 'invited',
                `is_muted` TINYINT(1) DEFAULT 0,
                `is_camera_off` TINYINT(1) DEFAULT 0,
                `is_screen_sharing` TINYINT(1) DEFAULT 0,
                `joined_at` DATETIME DEFAULT NULL,
                `left_at` DATETIME DEFAULT NULL,
                `last_seen` DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_call_user` (`call_id`, `user_id`),
                INDEX `idx_cp_call` (`call_id`),
                INDEX `idx_cp_user` (`user_id`),
                CONSTRAINT `fk_cp_call` FOREIGN KEY (`call_id`) REFERENCES `crm_group_calls` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

            CREATE TABLE IF NOT EXISTS `crm_group_call_signals` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `call_id` INT NOT NULL,
                `sender_id` VARCHAR(64) NOT NULL,
                `receiver_id` VARCHAR(64) DEFAULT NULL,
                `signal_type` VARCHAR(32) NOT NULL,
                `signal_data` MEDIUMTEXT NOT NULL,
                `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                INDEX `idx_cs_call_rec` (`call_id`, `receiver_id`),
                INDEX `idx_cs_created` (`created_at`),
                CONSTRAINT `fk_cs_call` FOREIGN KEY (`call_id`) REFERENCES `crm_group_calls` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;
        ");
    } catch (Exception $e) {
        // Table might already exist or FK not possible on secondary DB
    }

    try {
        $hasCmpCol = $pdo->query("SHOW COLUMNS FROM crm_groups LIKE 'company_id'")->rowCount() > 0;
        if (!$hasCmpCol) {
            $pdo->exec("ALTER TABLE crm_groups ADD COLUMN company_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER description, ADD INDEX idx_crm_groups_company_id (company_id)");
        }
    } catch (Exception $e) {
        // Column check ignored
    }

    try {
        $hasProjCmpCol = $pdo->query("SHOW COLUMNS FROM crm_projects LIKE 'company_id'")->rowCount() > 0;
        if (!$hasProjCmpCol) {
            $pdo->exec("ALTER TABLE crm_projects ADD COLUMN company_id VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL DEFAULT NULL AFTER client_name, ADD INDEX idx_projects_company_id (company_id)");
        }
    } catch (Exception $e) {
        // Column check ignored
    }

    try {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS `crm_task_members` (
                `id` INT AUTO_INCREMENT PRIMARY KEY,
                `task_id` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
                `employee_id` VARCHAR(64) CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci NOT NULL,
                `assigned_at` DATETIME DEFAULT CURRENT_TIMESTAMP,
                UNIQUE KEY `uq_task_employee` (`task_id`, `employee_id`),
                INDEX `idx_task_id` (`task_id`),
                INDEX `idx_employee_id` (`employee_id`),
                CONSTRAINT `fk_task_members_task` FOREIGN KEY (`task_id`) REFERENCES `crm_tasks` (`id`) ON DELETE CASCADE ON UPDATE CASCADE,
                CONSTRAINT `fk_task_members_employee` FOREIGN KEY (`employee_id`) REFERENCES `crm_team_staff` (`id`) ON DELETE CASCADE ON UPDATE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
        ");
    } catch (Exception $e) {
        // Table might already exist
    }

    $pdo->exec("

        CREATE TABLE IF NOT EXISTS `crm_calendar_events` (
          `id` INT AUTO_INCREMENT PRIMARY KEY,
          `title` VARCHAR(255) NOT NULL,
          `event_date` DATE NOT NULL,
          `event_time` VARCHAR(32) DEFAULT '10:00 AM',
          `category` VARCHAR(64) DEFAULT 'Meeting',
          `color` VARCHAR(32) DEFAULT '#2563eb',
          `description` TEXT,
          `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;

        CREATE TABLE IF NOT EXISTS `crm_cash_ledger` (
          `id` VARCHAR(64) PRIMARY KEY,
          `invoice_no` VARCHAR(64) DEFAULT NULL,
          `type` VARCHAR(32) DEFAULT 'income',
          `amount` DECIMAL(12, 2) NOT NULL,
          `title` VARCHAR(255) NOT NULL,
          `category` VARCHAR(64) DEFAULT 'General',
          `payment_mode` VARCHAR(32) DEFAULT 'Online Transfer',
          `handled_by` VARCHAR(128) DEFAULT 'Admin',
          `customer_name` VARCHAR(128) DEFAULT NULL,
          `customer_phone` VARCHAR(64) DEFAULT NULL,
          `status` VARCHAR(32) DEFAULT 'Paid',
          `due_date` VARCHAR(64) DEFAULT NULL,
          `domain` VARCHAR(128) DEFAULT 'software.local',
          `transaction_date` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    // 2. Check and seed contacts
    $chk = $pdo->query("SELECT count(*) FROM crm_contacts WHERE id = 'CNT_REF_01'")->fetchColumn();
    if ($chk == 0) {
        $contacts = [
            ["CNT_REF_01", "Rahul Sharma", "+91 98765 43210", "", "rahul@example.com", "ABC Tech Solutions", "Founder", "Client", "Mumbai, India", "Website", "Key client for web development and digital marketing projects. Interested in long-term partnership.", "https://www.abctech.com", "$15,000", "Paid", "100", "Important, Long Term", 1],
            ["CNT_REF_02", "Priya Verma", "+91 98765 43211", "", "priya@example.com", "Verma Solutions", "Director", "Lead", "Delhi, India", "Referral", "Mobile application design inquiry for fintech system.", "https://verma-solutions.com", "$22,000", "Pending", "40", "Mobile, Fintech", 1],
            ["CNT_REF_03", "Amit Patel", "+91 98765 43212", "", "amit@example.com", "Patel & Co.", "Managing Director", "Client", "Ahmedabad, India", "LinkedIn", "ERP and accounting automation contract.", "https://patelco.in", "$18,500", "Partially Paid", "50", "ERP, Retainer", 0],
            ["CNT_REF_04", "Sneha Kapoor", "+91 98765 43213", "", "sneha@example.com", "Kapoor Designs", "Creative Head", "Prospect", "Bengaluru, India", "Campaign", "Brand identity and design system revamps.", "https://kapoordesigns.com", "$6,500", "Pending", "0", "Design, Branding", 0],
            ["CNT_REF_05", "Vikram Singh", "+91 98765 43214", "", "vikram@example.com", "Singh Enterprises", "Owner", "Client", "Jaipur, India", "Direct", "Supply chain tracking and GPS logistics portal.", "https://singhenterprises.in", "$12,000", "Paid", "100", "Logistics, GPS", 1],
            ["CNT_REF_06", "Neha Gupta", "+91 98765 43215", "", "neha@example.com", "Gupta Media", "Editor-in-Chief", "Lead", "Pune, India", "Instagram", "Publishing CMS and creator subscription platform.", "https://guptamedia.com", "$9,000", "Pending", "20", "CMS, Media", 0],
            ["CNT_REF_07", "Karan Mehta", "+91 98765 43216", "", "karan@example.com", "Mehta Industries", "Operations VP", "Client", "Surat, India", "Trade Fair", "Factory SCADA dashboard and sensor telemetry system.", "https://mehtaind.com", "$28,000", "Paid", "80", "Industry, SCADA", 1],
            ["CNT_REF_08", "Anjali Rao", "+91 98765 43217", "", "anjali@example.com", "Rao Creations", "Principal Architect", "Prospect", "Hyderabad, India", "Google Ads", "Architecture 3D rendering and VR walkthrough gallery.", "https://raocreations.in", "$14,000", "Pending", "0", "3D, VR", 0]
        ];

        $stmt = $pdo->prepare("
            INSERT INTO crm_contacts 
            (id, name, phone, phone2, email, company, occupation, category, address, lead_from, notes, website_link, budget, amount_status, amount_percentage_paid, specifications, is_starred, created_at)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())
        ");
        foreach ($contacts as $c) {
            $stmt->execute([$c[0], $c[1], $c[2], $c[3], $c[4], $c[5], $c[6], $c[7], $c[8], $c[9], $c[10], $c[11], $c[12], $c[13], $c[14], $c[15], $c[16]]);
        }
    }

    // 3. Seed Projects
    $p_cnt = $pdo->query("SELECT count(*) FROM crm_projects")->fetchColumn();
    if ($p_cnt == 0) {
        $projects = [
            ["proj_1", "E-commerce Website", "ABC Tech Solutions", "Web & App", "In Progress", 70, "$15,000", "2024-06-01", "10 Aug 2024", "Rahul S., Akash D., Priya V.", "layout", "purple"],
            ["proj_2", "Mobile App Development", "Verma Solutions", "Mobile App", "In Progress", 40, "$22,000", "2024-07-15", "25 Sep 2024", "Priya V., Liam C., Sophia B.", "smartphone", "indigo"],
            ["proj_3", "Brand Identity Design", "Kapoor Designs", "Design", "Completed", 100, "$6,500", "2024-05-10", "10 Aug 2024", "Sneha K., Emma W.", "feather", "emerald"],
            ["proj_4", "CRM System", "Patel & Co.", "SaaS Enterprise", "On Hold", 25, "$18,500", "2024-07-01", "15 Nov 2024", "Amit P., Akash D.", "database", "amber"],
            ["proj_5", "Cloud ERP Integration", "Sheopur Univ", "Cloud & DevOps", "In Progress", 85, "$30,000", "2024-04-12", "05 Sep 2024", "Rohit S., Vikram S.", "cloud", "blue"],
            ["proj_6", "Factory IoT SCADA", "Apex Industrial Works", "IoT Telemetry", "In Progress", 60, "$25,000", "2024-05-20", "30 Oct 2024", "Karan M., Akash D.", "cpu", "cyan"]
        ];
        $stmt_p = $pdo->prepare("INSERT INTO crm_projects (id, title, client_name, category, status, progress, budget, start_date, deadline, team_members, icon, color) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($projects as $pr) {
            $stmt_p->execute($pr);
        }
    }

    // 4. Seed Tasks
    $t_cnt = $pdo->query("SELECT count(*) FROM crm_tasks")->fetchColumn();
    if ($t_cnt == 0) {
        $tasks = [
            ["tsk_1", "Design Homepage", "ABC Tech Solutions", "", "", "Akash Dev", "Today", "High", "Design", "software.local", "layout", 0],
            ["tsk_2", "Client Meeting - ABC Ltd", "ABC Tech Solutions", "", "", "Suresh Kumar", "Tomorrow", "Medium", "Meeting", "software.local", "users", 0],
            ["tsk_3", "Prepare Proposal", "Verma Solutions", "", "", "Akash Dev", "14 Aug", "Low", "Sales", "software.local", "file-text", 0],
            ["tsk_4", "Review UI Design", "Kapoor Designs", "", "", "Emma Wilson", "16 Aug", "High", "Design", "software.local", "eye", 0],
            ["tsk_5", "Update Project Documentation", "Patel & Co.", "", "", "Liam C.", "18 Aug", "Medium", "Docs", "software.local", "book", 0],
            ["tsk_6", "Database Optimization", "Sheopur Univ", "", "", "Akash Dev", "20 Aug", "Low", "Database", "software.local", "database", 1],
            ["tsk_7", "API Security Audit", "Apex Industrial Works", "", "", "Akash Dev", "22 Aug", "High", "Security", "software.local", "shield", 1],
            ["tsk_8", "Configure Production SSL", "ABC Tech Solutions", "", "", "Akash Dev", "24 Aug", "Medium", "DevOps", "software.local", "lock", 1]
        ];
        $stmt_t = $pdo->prepare("INSERT INTO crm_tasks (id, title, client_name, phone, location, assigned_staff, due_time, priority, category, domain, icon, completed) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($tasks as $tk) {
            $stmt_t->execute($tk);
        }
    }

    // 5. Seed Invoices
    $i_cnt = $pdo->query("SELECT count(*) FROM crm_cash_ledger")->fetchColumn();
    if ($i_cnt == 0) {
        $invoices = [
            ["inv_1", "INV-2024-001", "income", 2500.00, "Website Development Milestone 1", "Development", "Online Transfer", "Admin", "ABC Tech Solutions", "+91 98765 43210", "Paid", "10 Aug 2024"],
            ["inv_2", "INV-2024-002", "income", 1200.00, "Mobile App UI Design Phase", "Design", "Bank Wire", "Admin", "Verma Solutions", "+91 98765 43211", "Pending", "12 Aug 2024"],
            ["inv_3", "INV-2024-003", "income", 3000.00, "Brand Identity & Design System", "Branding", "Cheque", "Admin", "Kapoor Designs", "+91 98765 43213", "Overdue", "05 Aug 2024"],
            ["inv_4", "INV-2024-004", "income", 800.00, "Architecture & Cloud Consultation", "Consulting", "UPI", "Admin", "Patel & Co.", "+91 98765 43212", "Paid", "28 Jul 2024"],
            ["inv_5", "INV-2024-005", "income", 4950.00, "ERP Integration & Module Delivery", "Enterprise", "Online Transfer", "Admin", "Apex Industrial Works", "+91 98765 43216", "Paid", "15 Jul 2024"]
        ];
        $stmt_i = $pdo->prepare("INSERT INTO crm_cash_ledger (id, invoice_no, type, amount, title, category, payment_mode, handled_by, customer_name, customer_phone, status, due_date) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)");
        foreach ($invoices as $iv) {
            $stmt_i->execute($iv);
        }
    }

    // 6. Seed Calendar Events
    $c_cnt = $pdo->query("SELECT count(*) FROM crm_calendar_events")->fetchColumn();
    if ($c_cnt == 0) {
        $events = [
            ["Client Meeting", "2026-08-07", "10:00 AM", "Meeting", "#10b981", "Client meeting with ABC Tech Solutions team"],
            ["Project Review", "2026-08-09", "02:30 PM", "Review", "#3b82f6", "Sprint 3 deliverables review"],
            ["Design Deadline", "2026-08-14", "06:00 PM", "Deadline", "#ef4444", "Final Figma approval & handover"],
            ["Team Meeting", "2026-08-21", "11:00 AM", "Meeting", "#6366f1", "Bi-weekly engineering sync"],
            ["Invoice Due", "2026-08-26", "05:00 PM", "Billing", "#f59e0b", "Payment reminder for INV-2024-002"]
        ];
        $stmt_c = $pdo->prepare("INSERT INTO crm_calendar_events (title, event_date, event_time, category, color, description) VALUES (?, ?, ?, ?, ?, ?)");
        foreach ($events as $ev) {
            $stmt_c->execute($ev);
        }
    }

    echo "Upgraded and verified database: $dbname\n";
}

echo "Database upgrade and seeder complete.\n";
