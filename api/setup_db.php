<?php
/**
 * Software Team 1-Click Database Setup & Seeder
 * Creates schema and imports 86 software clients from software_clients.json
 */

header('Content-Type: application/json; charset=utf-8');
require_once __DIR__ . '/db.php';

$pdo = Database::getConnection();
$engine = Database::getEngine();

if (!$pdo) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Could not connect to MySQL or SQLite database'
    ], JSON_PRETTY_PRINT);
    exit;
}

$base_dir = dirname(__DIR__);
$schema_file = $base_dir . '/data/schema.sql';
$json_file = file_exists($base_dir . '/data/software_clients.json') 
    ? $base_dir . '/data/software_clients.json' 
    : $base_dir . '/software_clients.json';

try {
    // 1. Create Tables
    if ($engine === 'mysql') {
        $schema = file_get_contents($schema_file);
        // Execute multi-query statements
        $queries = explode(';', $schema);
        foreach ($queries as $q) {
            $q = trim($q);
            if (!empty($q)) {
                $pdo->exec($q);
            }
        }
    } else {
        // SQLite Tables
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS crm_contacts (
                id TEXT PRIMARY KEY,
                name TEXT NOT NULL,
                phone TEXT NOT NULL,
                phone2 TEXT,
                email TEXT,
                company TEXT,
                occupation TEXT,
                category TEXT,
                address TEXT,
                lead_from TEXT,
                notes TEXT,
                whatsapp_notes TEXT,
                drive_link TEXT,
                website_link TEXT,
                creds_server TEXT,
                creds_social TEXT,
                budget TEXT,
                amount_status TEXT,
                amount_percentage_paid TEXT,
                specifications TEXT,
                is_starred INTEGER DEFAULT 0,
                is_blocked INTEGER DEFAULT 0,
                last_contact TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
                updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS crm_history (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                contact_id TEXT NOT NULL,
                staff TEXT,
                time_str TEXT,
                duration TEXT,
                type TEXT,
                note_text TEXT NOT NULL,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS crm_media (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                contact_id TEXT NOT NULL,
                folder TEXT,
                file_name TEXT,
                file_url TEXT NOT NULL,
                file_size INTEGER DEFAULT 0,
                file_type TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
            CREATE TABLE IF NOT EXISTS crm_vault (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                contact_id TEXT NOT NULL,
                title TEXT,
                credential_type TEXT,
                username TEXT,
                password_val TEXT,
                notes TEXT,
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            );
        ");
    }

    // 2. Check if contacts already exist
    $stmt = $pdo->query("SELECT COUNT(*) as cnt FROM crm_contacts");
    $count = (int)$stmt->fetchColumn();

    $imported = 0;
    if ($count === 0 && file_exists($json_file)) {
        $raw = json_decode(file_get_contents($json_file), true);
        if (is_array($raw)) {
            $stmt_contact = $pdo->prepare("
                INSERT INTO crm_contacts 
                (id, name, phone, phone2, email, company, occupation, category, address, lead_from, notes, whatsapp_notes, drive_link, website_link, creds_server, creds_social, budget, amount_status, amount_percentage_paid, specifications, is_starred, is_blocked, last_contact)
                VALUES 
                (:id, :name, :phone, :phone2, :email, :company, :occupation, :category, :address, :lead_from, :notes, :whatsapp_notes, :drive_link, :website_link, :creds_server, :creds_social, :budget, :amount_status, :amount_percentage_paid, :specifications, :is_starred, :is_blocked, :last_contact)
            ");

            $stmt_hist = $pdo->prepare("
                INSERT INTO crm_history 
                (contact_id, staff, time_str, duration, type, note_text)
                VALUES 
                (:contact_id, :staff, :time_str, :duration, :type, :note_text)
            ");

            $stmt_media = $pdo->prepare("
                INSERT INTO crm_media 
                (contact_id, folder, file_name, file_url, file_size, file_type)
                VALUES 
                (:contact_id, :folder, :file_name, :file_url, :file_size, :file_type)
            ");

            foreach ($raw as $idx => $c) {
                $raw_status = $c['project_status'] ?? '';
                if ($raw_status === 'Interested') $cat = 'Lead';
                elseif (in_array($raw_status, ['Working', 'Done'])) $cat = 'Client';
                elseif ($raw_status === 'Pending') $cat = 'Follow-up';
                else $cat = 'Contact';

                $cid = $c['id'] ?? sprintf('CNT_%03d', $idx + 1);
                $is_starred = in_array($idx, [0, 1, 2, 5, 8]) ? 1 : 0;

                $stmt_contact->execute([
                    ':id' => $cid,
                    ':name' => trim($c['name'] ?? 'Unknown Contact'),
                    ':phone' => trim($c['phone'] ?? ''),
                    ':phone2' => trim($c['phone2'] ?? ''),
                    ':email' => trim($c['email1'] ?? ($c['email2'] ?? '')),
                    ':company' => trim($c['company'] ?? ($c['work_name'] ?? '')),
                    ':occupation' => trim($c['working_category'] ?? 'Business Client'),
                    ':category' => $cat,
                    ':address' => trim($c['address'] ?? 'Jaipur, Rajasthan'),
                    ':lead_from' => trim($c['lead_from'] ?? ''),
                    ':notes' => trim($c['notes'] ?? ''),
                    ':whatsapp_notes' => trim($c['whatsapp_notes'] ?? ''),
                    ':drive_link' => trim($c['drive_folder_link'] ?? ''),
                    ':website_link' => trim($c['website_link'] ?? ''),
                    ':creds_server' => trim($c['creds_server'] ?? ''),
                    ':creds_social' => trim($c['creds_social'] ?? ''),
                    ':budget' => trim($c['budget'] ?? ''),
                    ':amount_status' => trim($c['amount_status'] ?? 'Pending'),
                    ':amount_percentage_paid' => trim($c['amount_percentage_paid'] ?? '0'),
                    ':specifications' => trim($c['specifications'] ?? ''),
                    ':is_starred' => $is_starred,
                    ':is_blocked' => 0,
                    ':last_contact' => trim($c['last_followup_date'] ?? 'Recent')
                ]);

                // Insert Timeline Notes
                $notes = $c['timeline_notes'] ?? [];
                if (is_array($notes)) {
                    $times = ['05:25 PM', '08:28 PM', '09:30 PM', 'Yesterday 03:15 PM'];
                    $staff = ['Akash (Dev Lead)', 'Manish Saini', 'Amit Kumawat'];
                    foreach ($notes as $n_idx => $tn) {
                        if ($tn && trim($tn)) {
                            $stmt_hist->execute([
                                ':contact_id' => $cid,
                                ':staff' => $staff[$n_idx % count($staff)],
                                ':time_str' => $times[$n_idx % count($times)],
                                ':duration' => '15 mins',
                                ':type' => $n_idx % 2 === 0 ? 'call' : 'meet',
                                ':note_text' => trim($tn)
                            ]);
                        }
                    }
                }

                if (!empty($c['whatsapp_notes'])) {
                    $stmt_hist->execute([
                        ':contact_id' => $cid,
                        ':staff' => 'WhatsApp Interaction',
                        ':time_str' => '11:40 AM',
                        ':duration' => 'Chat Log',
                        ':type' => 'chat',
                        ':note_text' => trim($c['whatsapp_notes'])
                    ]);
                }

                // Insert Images
                $images = $c['images'] ?? [];
                if (is_array($images)) {
                    foreach ($images as $img_idx => $img_url) {
                        if ($img_url && trim($img_url)) {
                            $stmt_media->execute([
                                ':contact_id' => $cid,
                                ':folder' => 'photos',
                                ':file_name' => 'Attachment_' . ($img_idx + 1),
                                ':file_url' => trim($img_url),
                                ':file_size' => 102400,
                                ':file_type' => 'image/jpeg'
                            ]);
                        }
                    }
                }

                $imported++;
            }
        }
    }

    $final_count = (int)$pdo->query("SELECT COUNT(*) FROM crm_contacts")->fetchColumn();
    $hist_count = (int)$pdo->query("SELECT COUNT(*) FROM crm_history")->fetchColumn();
    $media_count = (int)$pdo->query("SELECT COUNT(*) FROM crm_media")->fetchColumn();

    echo json_encode([
        'status' => 'success',
        'engine' => $engine,
        'message' => 'Database initialized successfully',
        'stats' => [
            'contacts' => $final_count,
            'timeline_history_logs' => $hist_count,
            'media_attachments' => $media_count,
            'imported_new' => $imported
        ]
    ], JSON_PRETTY_PRINT);

} catch (Exception $e) {
    echo json_encode([
        'status' => 'error',
        'message' => $e->getMessage()
    ], JSON_PRETTY_PRINT);
}
