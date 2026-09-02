# TeleDrive — Self-Hosted Telegram Cloud Storage Engine

**TeleDrive** is a high-performance, zero-database personal cloud storage manager built with pure **PHP 8.x** and **vanilla JavaScript**. It harnesses the Telegram API as both an unlimited, distributed binary storage engine and a real-time NoSQL metadata store, giving you a full-featured, Google Drive-style web interface without the overhead of database servers.

> 🚀 **Created by Rahul** and actively managed by the **Art-Tech Fuzion** team.

---

## 📌 Table of Contents

1. [Why TeleDrive? & Target Audience](#-why-teledrive--target-audience)
2. [Key Features & Technical Architecture](#-key-features--technical-architecture)
3. [Prerequisites](#-prerequisites)
4. [Step-by-Step Telegram Integration Setup](#-step-by-step-telegram-integration-setup)
   - [Step 1: Create a Telegram Bot & Get Bot Token](#step-1-create-a-telegram-bot--get-bot-token)
   - [Step 2: Create Storage and Index Channels](#step-2-create-storage-and-index-channels)
   - [Step 3: Add Bot as Administrator in Both Channels](#step-3-add-bot-as-administrator-in-both-channels)
   - [Step 4: Find Your Channel IDs (-100...)](#step-4-find-your-channel-ids--100)
5. [Installation & Configuration](#-installation--configuration)
   - [Environment Variables (.env)](#environment-variables-env)
   - [How to Change the Admin Password](#how-to-change-the-admin-password)
   - [Generating a Secure Session Secret](#generating-a-secure-session-secret)
6. [Running TeleDrive](#-running-teledrive)
   - [Option 1: Local Development (PHP Built-in Server)](#option-1-local-development-php-built-in-server)
   - [Option 2: Shared Hosting & cPanel (Apache / LiteSpeed)](#option-2-shared-hosting--cpanel-apache--litespeed)
   - [Option 3: VPS / Dedicated Server (Nginx / Apache)](#option-3-vps--dedicated-server-nginx--apache)
7. [Default Login Credentials](#-default-login-credentials)
8. [Directory Structure](#-directory-structure)
9. [Security Best Practices](#-security-best-practices)
10. [Troubleshooting & FAQs](#-troubleshooting--faqs)
11. [Author & Maintainers](#-author--maintainers)
12. [License](#-license)

---

## 💡 Why TeleDrive? & Target Audience

Traditional cloud storage setups require complex databases (MySQL, PostgreSQL, MongoDB), dedicated S3 buckets, object storage bills, and ongoing maintenance. **TeleDrive eliminates all of that.**

### Who should use TeleDrive?
- **Developers & Self-Hosters:** Anyone who wants unlimited personal cloud storage hosted on inexpensive shared hosting (cPanel), cheap VPS nodes, or local home servers without database configuration or maintenance.
- **Homelab Enthusiasts & Archivists:** Users looking to store backups, personal media collections, ISOs, and project archives securely without paying monthly S3 or cloud subscription fees.
- **Content Creators & Freelancers:** Individuals who need a fast, private, Google Drive-style web portal accessible from any browser to upload, organize, preview, and download digital assets.
- **Small Teams & Projects:** Teams seeking a shared internal document and asset repository with zero infrastructure overhead.

---

## ⚡ Key Features & Technical Architecture

| Feature | Description |
| :--- | :--- |
| **Zero-Database Architecture** | No MySQL, SQLite, PostgreSQL, or MongoDB needed. Telegram acts as both the raw object store and the persistent metadata database. |
| **50-Message Compaction & Checkpointing** | Fast directory tree loading (< 1s) through automated snapshot compaction into a pinned `master_manifest.json` on your Index Channel. |
| **Client-Side Chunked Uploads** | Large files are sliced into chunks on the client browser before upload, completely bypassing PHP's `upload_max_filesize` and `post_max_size` limits. |
| **Streaming Proxy Downloads** | Files and multi-part chunks stream directly through PHP output buffers to the client with HTTP range requests, keeping server memory footprint minimal. |
| **Complete File & Folder Management** | Full CRUD operations: create nested folder trees, search instantly, rename in place, and perform recursive cascade deletions. |
| **In-Browser Media Previews** | Native preview support for high-resolution images, streaming video (MP4, WebM), audio player (MP3, WAV, OGG), and embedded PDF viewer. |
| **Google Drive-Inspired Modern UI** | Polished dark/light interface, Grid and List viewing modes, breadcrumb navigation, drag-and-drop upload overlay, and floating upload progress queue. |
| **Production-Ready Security** | Bcrypt-hashed admin authentication, CSRF validation on all mutable endpoints, path-traversal hardening, and secure HTTP-only sessions. |

---

## 📋 Prerequisites

- **PHP 8.0 or higher** (PHP 8.1, 8.2, and 8.3 fully supported).
- **PHP cURL Extension** (`ext-curl`) enabled.
- **PHP JSON Extension** (`ext-json`) enabled.
- **PHP Fileinfo Extension** (`ext-fileinfo`) enabled.
- Write permissions for the temporary chunk assembly folder (`temp_chunks/`).
- A free **Telegram** account.

---

## 🔑 Step-by-Step Telegram Integration Setup

TeleDrive uses **1 Telegram Bot** and **2 Private Channels**:
1. **Storage Channel:** Stores the actual binary files and multipart data chunks.
2. **Index Channel:** Stores incremental JSON action logs and the pinned `master_manifest.json` directory tree.

```
+------------------+         +--------------------------+
|  TeleDrive Web   | <=====> |   Telegram Bot API       |
+------------------+         +--------------------------+
                                  /                 \
                                 v                   v
                     +--------------------+   +-------------------+
                     |  Storage Channel   |   |   Index Channel   |
                     |  (Binary Chunks)   |   | (JSON & Manifest) |
                     +--------------------+   +-------------------+
```

---

### Step 1: Create a Telegram Bot & Get Bot Token

1. Open your Telegram client and search for [@BotFather](https://t.me/BotFather).
2. Click **Start** (or send `/start`) and send:
   ```text
   /newbot
   ```
3. Enter a friendly display name for your bot (e.g., `My TeleDrive Bot`).
4. Enter a unique username ending in `bot` (e.g., `my_teledrive_drive_bot`).
5. BotFather will provide an **HTTP API Access Token** in this format:
   ```text
   123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ123456789
   ```
6. Keep this token safe — this is your `TELEGRAM_BOT_TOKEN`.

---

### Step 2: Create Storage and Index Channels

You must create two separate **Private Channels** in Telegram:

1. **Create Storage Channel:**
   - In Telegram, click the **New Message / Menu** icon &rarr; **New Channel**.
   - Name it (e.g., `TeleDrive Storage`).
   - Set Channel Type to **Private Channel**.
   - Save the channel.

2. **Create Index Channel:**
   - Click **New Channel** again.
   - Name it (e.g., `TeleDrive Index`).
   - Set Channel Type to **Private Channel**.
   - Save the channel.

---

### Step 3: Add Bot as Administrator in Both Channels

> [!IMPORTANT]
> The bot must be added as an **Administrator** with explicit posting, editing, and pinning permissions. Regular member status is not sufficient.

1. **In your Storage Channel:**
   - Go to **Channel Settings** &rarr; **Administrators** &rarr; **Add Administrator**.
   - Search for your bot's username and select it.
   - Grant the following permissions:
     - ✅ *Post Messages*
     - ✅ *Delete Messages*
   - Save changes.

2. **In your Index Channel:**
   - Go to **Channel Settings** &rarr; **Administrators** &rarr; **Add Administrator**.
   - Search for your bot's username and select it.
   - Grant the following permissions:
     - ✅ *Post Messages*
     - ✅ *Edit Messages*
     - ✅ *Delete Messages*
     - ✅ *Pin Messages*
   - Save changes.

---

### Step 4: Find Your Channel IDs (`-100...`)

Telegram Supergroup and Channel IDs always begin with `-100` followed by 9 to 10 digits (e.g., `-1001234567890`).

Choose any of the following methods to retrieve your IDs:

#### Method A: Using Telegram Web (Fastest)
1. Open [Telegram Web (web.telegram.org)](https://web.telegram.org/) in your browser.
2. Click on your **Storage Channel**.
3. Examine the browser's address bar URL:
   ```text
   https://web.telegram.org/a/#-1001987654321
   ```
4. The full string with the minus sign (`-1001987654321`) is your `STORAGE_CHANNEL_ID`.
5. Repeat for your **Index Channel** to get your `INDEX_CHANNEL_ID`.

#### Method B: Using Helper Bot
1. Forward any message from your channel to [@username_to_id_bot](https://t.me/username_to_id_bot) or [@raw_data_bot](https://t.me/raw_data_bot).
2. The bot will return the forward source metadata including the Channel ID (`-100...`).

#### Method C: Using the Telegram Bot API Directly
1. Post a test message (e.g. `hello`) into your channel.
2. In your browser, open the following URL (replace `<YOUR_BOT_TOKEN>` with your token):
   ```text
   https://api.telegram.org/bot<YOUR_BOT_TOKEN>/getUpdates
   ```
3. Locate the `chat` object in the returned JSON:
   ```json
   "chat": {
     "id": -1001234567890,
     "title": "TeleDrive Storage",
     "type": "channel"
   }
   ```
4. Copy the `-100xxxxxxxxxx` value.

---

## ⚙️ Installation & Configuration

### 1. Clone or Download the Repository
```bash
git clone https://github.com/yourusername/TeleDrive.git
cd TeleDrive
```

### 2. Configure the Environment (`.env`)
Create your `.env` configuration file from the template:
```bash
cp .env.example .env
```

Open `.env` in any text editor and fill in your values:

```ini
# =====================================================================
# TeleDrive Environment Configuration
# =====================================================================

# Admin Authentication
ADMIN_USER=admin
ADMIN_PASSWORD_HASH=$2y$10$w8T.N0Gg889M3mZkHlP/Q.iN8Xy9K9Y7E0q/Hw9s0q5lZkHlP/Q.i

# Session Security (Generate a random 32+ character string)
SESSION_SECRET=teledrive_super_secret_session_key_random_change_me_32char

# Telegram Bot & Channel IDs
TELEGRAM_BOT_TOKEN=123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ123456789
STORAGE_CHANNEL_ID=-1001234567890
INDEX_CHANNEL_ID=-1009876543210

# Temporary chunk storage folder
TEMP_CHUNK_DIR=temp_chunks
```

---

### 🔑 How to Change the Admin Password

TeleDrive uses native PHP **Bcrypt** password hashing for maximum authentication security.

#### Step 1: Generate a New Bcrypt Password Hash
Run the following one-line command in your terminal (replace `your_new_password` with your desired password):

```bash
php -r 'echo password_hash("your_new_password", PASSWORD_BCRYPT) . PHP_EOL;'
```

This will output a string similar to:
```text
$2y$10$e8wYf9jXgP.iK7vLqNm8Te5z0qG9y8s7D1r2K3u4V5w6X7y8Z9a0b
```

#### Step 2: Update the `.env` File
Open `.env` and paste the generated hash into `ADMIN_PASSWORD_HASH`:
```ini
ADMIN_USER=admin
ADMIN_PASSWORD_HASH=$2y$10$e8wYf9jXgP.iK7vLqNm8Te5z0qG9y8s7D1r2K3u4V5w6X7y8Z9a0b
```

#### Step 3: Log in
You can now log in to the TeleDrive portal with your updated password.

---

### 🛡️ Generating a Secure Session Secret

The `SESSION_SECRET` is used for cryptographic token signing and session hardening. Always generate a unique, random string for your deployment:

```bash
php -r 'echo bin2hex(random_bytes(32)) . PHP_EOL;'
```

Paste the generated 64-character hexadecimal string into `SESSION_SECRET` in `.env`.

---

## 🚀 Running TeleDrive

### Option 1: Local Development (PHP Built-in Server)

You can launch TeleDrive instantly on any computer with PHP 8.x installed:

```bash
# Start the built-in server on port 8080
php -S localhost:8080
```

Open your browser and navigate to:
```text
http://localhost:8080
```

---

### Option 2: Shared Hosting & cPanel (Apache / LiteSpeed)

1. **Upload Files:** Upload the TeleDrive repository contents to `public_html/` or a subdomain directory (e.g., `public_html/drive/`).
2. **Hidden Files:** Ensure hidden files like `.env` and `.htaccess` are uploaded and visible in your cPanel File Manager.
3. **Folder Permissions:** Ensure the `temp_chunks/` directory is writable by PHP:
   ```bash
   chmod 775 temp_chunks
   ```
4. **Access the Application:** Navigate to `https://yourdomain.com/` in your browser.

> [!NOTE]
> TeleDrive includes pre-configured `.htaccess` rules that block all direct browser access to `.env`, internal PHP include classes, and temporary chunk folders.

---

### Option 3: VPS / Dedicated Server (Nginx / Apache)

#### Apache VirtualHost Example
```apache
<VirtualHost *:80>
    ServerName drive.yourdomain.com
    DocumentRoot /var/www/teledrive

    <Directory /var/www/teledrive>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
    </Directory>

    ErrorLog ${APACHE_LOG_DIR}/teledrive_error.log
    CustomLog ${APACHE_LOG_DIR}/teledrive_access.log combined
</VirtualHost>
```

#### Nginx Server Block Example
```nginx
server {
    listen 80;
    server_name drive.yourdomain.com;
    root /var/www/teledrive;
    index index.php;

    client_max_body_size 100M;

    # Protect sensitive configuration and directories
    location ~ /\.(env|git|htaccess) {
        deny all;
        return 404;
    }

    location ^~ /includes/ {
        deny all;
        return 403;
    }

    location ^~ /temp_chunks/ {
        deny all;
        return 403;
    }

    location / {
        try_files $uri $uri/ /index.php?$args;
    }

    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/var/run/php/php8.2-fpm.sock;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
        include fastcgi_params;
    }
}
```

---

## 🔐 Default Login Credentials

If you have initialized `.env` from `.env.example` without modification:

- **Username:** `admin`
- **Default Password:** `admin123`

> [!CAUTION]
> For security, you must generate a new password hash and update `ADMIN_PASSWORD_HASH` and `SESSION_SECRET` before deploying TeleDrive to a public server.

---

## 📁 Directory Structure

```text
TeleDrive/
├── .env.example              # Template for environment configuration
├── .env                      # Active private configuration (ignored by git)
├── .htaccess                 # Apache / cPanel security directives & route hardening
├── index.php                 # Core entry point & view router
├── config.php                # Custom .env parser, security assertions & session bootstrap
├── api/
│   └── index.php             # Unified REST API controller (auth, files, folders, chunks)
├── includes/
│   ├── Auth.php              # Session lifecycle, CSRF tokens & Bcrypt authentication
│   ├── Helpers.php           # Response formatting, MIME type detection & sanitization
│   ├── TelegramClient.php    # Telegram Bot API client (cURL, multipart uploads, range streaming)
│   └── StorageEngine.php     # Zero-DB engine, 50-message compaction & manifest checkpointing
├── shared/
│   └── css/
│       └── global.css        # Global CSS variables, typography & design tokens
├── assets/
│   ├── global.css            # Global stylesheet entry point
│   ├── global.js             # Global UI helpers (Toast notifications, Confirm modals)
│   ├── backend/              # Admin login page styles & interactions
│   │   ├── login.css
│   │   └── login.js
│   └── frontend/             # Main dashboard UI styles & client-side upload engine
│       ├── app.css
│       └── app.js
└── templates/
    ├── backend/
    │   └── login.php         # Admin authentication view
    └── frontend/
        └── app.php           # File manager dashboard & media preview modals
```

---

## 🛡️ Security Best Practices

1. **Protect Your `.env` File:** Never commit your active `.env` file to public source control. Keep your `TELEGRAM_BOT_TOKEN` private.
2. **Private Channels Only:** Ensure both your Storage Channel and Index Channel are set to **Private** in Telegram to prevent unauthorized access to uploaded files.
3. **Use HTTPS in Production:** Always serve TeleDrive over SSL/TLS (`https://`) to protect login credentials and session cookies in transit.
4. **Update Default Passwords:** Never run production instances with the default `admin123` password or default `SESSION_SECRET`.

---

## ❓ Troubleshooting & FAQs

### 1. Error: "TeleDrive: SESSION_SECRET is not configured"
- **Cause:** The `SESSION_SECRET` variable in `.env` is either empty or matches the default placeholder value.
- **Fix:** Open `.env` and set `SESSION_SECRET` to any random 32+ character string.

### 2. Error: "Chat not found" or "Unauthorized" when uploading
- **Cause:** The bot has not been added as an Administrator to the channels, or the Channel ID is missing the `-100` prefix.
- **Fix:** Ensure both channel IDs in `.env` start with `-100` (e.g. `-1001234567890`) and confirm the bot has Administrator status with post/edit/pin permissions.

### 3. Chunk upload fails on shared hosting
- **Cause:** The `temp_chunks/` directory is missing or does not have write permissions.
- **Fix:** Create the folder if it does not exist and set permissions to `chmod 775 temp_chunks` or `chmod 777 temp_chunks`.

### 4. How large of a file can I upload?
- Because TeleDrive performs client-side slicing into standard chunks, file uploads are limited only by Telegram's maximum document size (2 GB per file/chunk for standard bots).

---

## 👨‍💻 Author & Maintainers

- **Created by:** **Rahul** 'ATF Owner'
- **Maintained & Supported by:** **Art-Tech Fuzion** Team

---

## 📄 License

This project is open-source software licensed under the **MIT License**.