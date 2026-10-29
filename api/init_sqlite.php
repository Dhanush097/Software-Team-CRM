<?php
/**
 * SQLite Database Initializer for Render.com / Docker deployments
 * Auto-creates all tables needed for the CRM to run on SQLite.
 * Called by Dockerfile entrypoint on startup.
 */

require_once __DIR__ . '/db.php';

$pdo = Database::getConnection();
$engine = Database::getEngine();

if (!$pdo || $engine !== 'sqlite') {
    echo "Skipping SQLite init (engine: $engine)\n";
    exit(0);
}

echo "Initializing SQLite database...\n";

$tables = [

    // ── Users & Auth ──────────────────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS crm_users (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        name       TEXT NOT NULL,
        email      TEXT NOT NULL UNIQUE,
        password_hash TEXT NOT NULL,
        role       TEXT NOT NULL DEFAULT 'Superadmin',
        status     TEXT NOT NULL DEFAULT 'active',
        google_id  TEXT,
        avatar_url TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        last_login_at DATETIME
    )",

    "CREATE TABLE IF NOT EXISTS crm_otp_challenges (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        email        TEXT NOT NULL,
        otp_hash     TEXT NOT NULL,
        purpose      TEXT NOT NULL,
        temp_payload TEXT,
        attempts     INTEGER NOT NULL DEFAULT 0,
        max_attempts INTEGER NOT NULL DEFAULT 5,
        resend_count INTEGER NOT NULL DEFAULT 0,
        last_sent_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        expires_at   DATETIME NOT NULL,
        is_used      INTEGER NOT NULL DEFAULT 0,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    // ── Contacts ──────────────────────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS crm_contacts (
        id                      TEXT PRIMARY KEY,
        name                    TEXT NOT NULL,
        phone                   TEXT NOT NULL,
        phone2                  TEXT,
        email                   TEXT,
        company                 TEXT,
        occupation              TEXT,
        category                TEXT,
        address                 TEXT,
        lead_from               TEXT,
        notes                   TEXT,
        whatsapp_notes          TEXT,
        drive_link              TEXT,
        website_link            TEXT,
        creds_server            TEXT,
        creds_social            TEXT,
        budget                  TEXT,
        amount_status           TEXT,
        amount_percentage_paid  TEXT,
        specifications          TEXT,
        is_starred              INTEGER DEFAULT 0,
        is_blocked              INTEGER DEFAULT 0,
        last_contact            TEXT,
        created_at              DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at              DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    "CREATE TABLE IF NOT EXISTS crm_history (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        contact_id TEXT NOT NULL,
        staff      TEXT,
        time_str   TEXT,
        duration   TEXT,
        type       TEXT,
        note_text  TEXT NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    "CREATE TABLE IF NOT EXISTS crm_media (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        contact_id TEXT NOT NULL,
        folder     TEXT,
        file_name  TEXT,
        file_url   TEXT NOT NULL,
        file_size  INTEGER DEFAULT 0,
        file_type  TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    "CREATE TABLE IF NOT EXISTS crm_vault (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        contact_id      TEXT NOT NULL,
        title           TEXT,
        credential_type TEXT,
        username        TEXT,
        password_val    TEXT,
        notes           TEXT,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    // ── Tasks & Projects ──────────────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS crm_tasks (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        title       TEXT NOT NULL,
        description TEXT,
        status      TEXT DEFAULT 'pending',
        priority    TEXT DEFAULT 'medium',
        assigned_to TEXT,
        contact_id  TEXT,
        due_date    DATE,
        created_by  TEXT,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    "CREATE TABLE IF NOT EXISTS crm_task_files (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        task_id    INTEGER NOT NULL,
        file_name  TEXT,
        file_url   TEXT NOT NULL,
        file_size  INTEGER DEFAULT 0,
        file_type  TEXT,
        uploaded_by TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    // ── Teams & Staff ─────────────────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS crm_teams (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT NOT NULL,
        description TEXT,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    "CREATE TABLE IF NOT EXISTS crm_staff (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        name       TEXT NOT NULL,
        email      TEXT,
        phone      TEXT,
        role       TEXT,
        team_id    INTEGER,
        status     TEXT DEFAULT 'active',
        avatar_url TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    // ── Companies ─────────────────────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS crm_companies (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT NOT NULL,
        industry    TEXT,
        website     TEXT,
        phone       TEXT,
        email       TEXT,
        address     TEXT,
        notes       TEXT,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    // ── Projects ──────────────────────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS crm_projects (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT NOT NULL,
        description TEXT,
        status      TEXT DEFAULT 'active',
        company_id  INTEGER,
        contact_id  TEXT,
        start_date  DATE,
        end_date    DATE,
        budget      TEXT,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    "CREATE TABLE IF NOT EXISTS crm_project_files (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        project_id  INTEGER NOT NULL,
        file_name   TEXT,
        file_url    TEXT NOT NULL,
        file_size   INTEGER DEFAULT 0,
        file_type   TEXT,
        uploaded_by TEXT,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    // ── Invoices ──────────────────────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS crm_invoices (
        id           INTEGER PRIMARY KEY AUTOINCREMENT,
        invoice_no   TEXT UNIQUE,
        contact_id   TEXT,
        company_id   INTEGER,
        amount       REAL DEFAULT 0,
        status       TEXT DEFAULT 'draft',
        issue_date   DATE,
        due_date     DATE,
        notes        TEXT,
        created_at   DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at   DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    // ── Notifications ─────────────────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS crm_notifications (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id    INTEGER,
        title      TEXT NOT NULL,
        message    TEXT,
        type       TEXT DEFAULT 'info',
        is_read    INTEGER DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    // ── Groups & Chats ────────────────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS crm_groups (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        name        TEXT NOT NULL,
        description TEXT,
        created_by  INTEGER,
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    "CREATE TABLE IF NOT EXISTS crm_group_members (
        id        INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id  INTEGER NOT NULL,
        user_id   INTEGER NOT NULL,
        joined_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    "CREATE TABLE IF NOT EXISTS crm_group_messages (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        group_id   INTEGER NOT NULL,
        user_id    INTEGER NOT NULL,
        message    TEXT,
        file_url   TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    // ── Calendar ──────────────────────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS crm_calendar (
        id          INTEGER PRIMARY KEY AUTOINCREMENT,
        title       TEXT NOT NULL,
        description TEXT,
        start_time  DATETIME,
        end_time    DATETIME,
        user_id     INTEGER,
        contact_id  TEXT,
        type        TEXT DEFAULT 'event',
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    // ── Settings ──────────────────────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS crm_settings (
        id         INTEGER PRIMARY KEY AUTOINCREMENT,
        key_name   TEXT NOT NULL UNIQUE,
        value      TEXT,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

    // ── Biometric / WebAuthn ──────────────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS crm_webauthn_credentials (
        id              INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id         INTEGER NOT NULL,
        credential_id   TEXT NOT NULL UNIQUE,
        public_key      TEXT NOT NULL,
        sign_count      INTEGER DEFAULT 0,
        device_name     TEXT,
        created_at      DATETIME DEFAULT CURRENT_TIMESTAMP
    )",

];

$created = 0;
foreach ($tables as $sql) {
    try {
        $pdo->exec($sql);
        $created++;
    } catch (Exception $e) {
        echo "Warning: " . $e->getMessage() . "\n";
    }
}

echo "✅ SQLite initialized: $created tables ready.\n";
