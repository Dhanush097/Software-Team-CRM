# Software Team — Professional Production Deployment Guide

Software Team is built as an ultra-fast, lightweight, mobile-first web application. It runs with zero dependencies and works instantly on both static hosting and full PHP/Apache/cPanel web servers.

---

## 📁 Clean Production Architecture

```
CRM/All/
├── index.php               # Master Production Application & Router (Single Entry Point)
├── export.sql              # 1-Click MySQL Database Dump (Schema + 86 Contacts + History + Media)
├── manifest.json           # Progressive Web App (PWA) Manifest
├── .htaccess               # Apache GZIP Compression, Caching & Security
├── DEPLOYMENT.md           # Hosting Deployment Instructions
│
├── api/                    # REST API Backend & Database Drivers
│   ├── db.php              # Multi-engine Database Connection (Hostinger / Localhost / SQLite)
│   ├── pocket_api.php      # Production REST API (CRUD, Search, Sync, Uploads)
│   └── setup_db.php        # Auto-migration & Database Seeder script
│
├── data/                   # Data Directory & Relational Schemas
│   ├── export.sql          # Database export backup
│   ├── schema.sql          # MySQL relational schema (contacts, history, media, vault)
│   ├── software_clients.json   # 86 Clients JSON database & cache
│   └── software_clients.csv    # Spreadsheet export
│
└── uploads/                # Categorized Media & Attachment Storage
    ├── photos/             # Contact photos & profile images
    ├── documents/          # PDF specifications & briefs
    ├── invoices/           # Billing receipts & spreadsheets
    └── media/              # General cloud attachments
```

---

## 💻 Localhost Deployment (Live Development)

1. **Prerequisites:**
   - Any Apache/PHP web server stack (e.g., XAMPP, WampServer, Laragon, or built-in PHP/Apache).
   - MySQL / MariaDB service running on default port `3306`.
2. **Access URL:**
   - `http://localhost/crm/` (or `http://localhost/crm/index.php`)
3. **Automatic Database Provisioning:**
   - To automatically create and seed the `pocket_crm_db` database, simply run or browse:
     `http://localhost/crm/api/setup_db.php`
   - Database credentials can be configured in [`api/db.php`](file:///api/db.php).

---

## 🚀 Option 1: Shared Hosting / cPanel / Hostinger (Production Deployment)

### Step 1: Create MySQL Database on Hostinger (As in your hPanel)
1. In Hostinger hPanel -> **Databases** -> **Management**:
   - **Database Name:** `u213657911_crm`
   - **Username:** `u213657911_crm`
   - **Password:** `Sarekam@1234`
2. Click **Create**.

### Step 2: Import `export.sql` in phpMyAdmin
1. In Hostinger hPanel -> **Databases** -> click **Enter phpMyAdmin** next to `u213657911_crm`.
2. Click on the database name `u213657911_crm` in the left sidebar.
3. Click the **Import** tab at the top.
4. Click **Choose File** and select **`export.sql`** from your project root.
5. Click **Import / Go** at the bottom.
   *(This automatically creates all 4 tables and inserts all 86 contacts, timeline notes, and media records in 1 single step!)*

### Step 3: Upload Project Files to Hostinger File Manager
1. Go to Hostinger **File Manager** -> `public_html` (or your subdomain folder, e.g. `public_html/crm`).
2. Upload the clean project files:
   - `index.html`
   - `index.php`
   - `manifest.json`
   - `.htaccess`
   - `api/`
   - `data/`
   - `uploads/`
3. Ensure write permissions (`0755`) on `uploads/` and `data/`.
4. Launch your app at `https://sarekam.com/crm` (or your domain)! All data connects to MySQL automatically.

2. **Upload Files:**
   - Upload the production files and folders:
     - `index.html`
     - `index.php`
     - `manifest.json`
     - `.htaccess`
     - `software_clients.json`
     - `api/`
     - `data/`
     - `uploads/`
     - `assets/`

3. **Set Permissions:**
   - Ensure write permissions (`0755` or `0775`) for:
     - `data/` folder and `software_clients.json`
     - `uploads/` folder and its subdirectories (`photos/`, `documents/`, `invoices/`, `media/`)

4. **Access the CRM:**
   - Visit `https://yourdomain.com/crm` in any browser or mobile phone!

---

## ⚡ Option 2: Static Hosting (Netlify, Vercel, GitHub Pages)

1. **Deploy Repository:**
   - Connect your GitHub repository to Netlify, Vercel, or GitHub Pages.
   - Publish directory: Root (`.`).
2. **Instant Offline Support:**
   - On static hosting, Software Team automatically operates with its browser `localStorage` engine and 1-click CSV/JSON export backups.

---

## 📱 Option 3: Install as Native App on Android / iOS (PWA)

1. Open your hosted CRM URL in Chrome (Android) or Safari (iPhone).
2. **Android:** Tap the 3 dots menu -> **"Install App"** or **"Add to Home screen"**.
3. **iPhone:** Tap the Share icon -> **"Add to Home Screen"**.
4. The Software Team icon will appear on your phone home screen just like a native app with full-screen experience.
