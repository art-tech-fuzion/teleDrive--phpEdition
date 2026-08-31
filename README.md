# TeleDrive (Self-Contained Telegram Cloud File Manager)

A lightweight, zero-database web-based file manager powered by pure PHP 8.x and vanilla JavaScript. It uses Telegram as both an unlimited cloud storage engine and a NoSQL metadata store.

---

## 📋 Features

- **Zero-Database Architecture**: No MySQL, SQLite, MongoDB, or local persistent JSON databases required.
- **50-Message Compaction / Checkpointing**: Automatic consolidation into a pinned `master_manifest.json` for instant (<1s) directory loading.
- **Client-Side Chunking**: Bypasses server `upload_max_filesize` and `post_max_size` limits with chunked uploads.
- **Proxy Streaming Download**: Streams multi-part chunks directly through PHP output buffers to the browser.
- **Full CRUD Support**: Create folders, in-place rename, and recursive cascade folder deletion.
- **Modern Responsive UI**: Google Drive-inspired design with dark mode, Grid & List views, Drag-and-Drop upload overlay, floating upload progress queue, and rich media previews (images, video, audio, PDF).

---

## 🔑 How to Get Telegram Bot Token & Channel IDs

TeleDrive requires **1 Telegram Bot** and **2 Private Telegram Channels** (`STORAGE_CHANNEL_ID` and `INDEX_CHANNEL_ID`).

### Step 1: Create a Telegram Bot & Get Token
1. Open Telegram and search for [@BotFather](https://t.me/BotFather).
2. Start a chat and send the command:
   ```text
   /newbot
   ```
3. Follow the prompts to name your bot and choose a unique username (e.g., `MyTeleDriveBot`).
4. BotFather will provide an **HTTP API Token** (e.g., `123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ`).
5. Copy this token into your `.env` file as `TELEGRAM_BOT_TOKEN`.

---

### Step 2: Create the Two Channels
Create two **Private Channels** in Telegram:
1. **Storage Channel**: Where actual binary files and multipart chunks are stored.
2. **Index Channel**: Where JSON metadata and pinned `master_manifest.json` files live.

---

### Step 3: Add Your Bot as Administrator in Both Channels
> ⚠️ **Important:** The bot must be an **Administrator** with full permissions (Post Messages, Edit Messages, Delete Messages, Pin Messages) in both channels.

1. Open your **Storage Channel** &rarr; Channel Settings &rarr; Administrators &rarr; **Add Administrator**.
2. Search for your bot username and add it. Grant permissions: *Post Messages*, *Delete Messages*.
3. Open your **Index Channel** &rarr; Channel Settings &rarr; Administrators &rarr; **Add Administrator**.
4. Search for your bot username and add it. Grant permissions: *Post Messages*, *Edit Messages*, *Delete Messages*, *Pin Messages*.

---

### Step 4: Get Channel IDs (`-100...`)

There are multiple easy ways to get your Channel IDs:

#### Method A: Using `@username_to_id_bot` or `@raw_data_bot`
1. Forward any message from your private channel to [@username_to_id_bot](https://t.me/username_to_id_bot) or [@raw_data_bot](https://t.me/raw_data_bot).
2. The bot will reply with the channel's ID (e.g., `-1001234567890`).

#### Method B: Using Telegram Web
1. Open [Telegram Web](https://web.telegram.org/) in your browser.
2. Open your channel and look at the URL in the address bar:
   ```text
   https://web.telegram.org/a/#-1001987654321
   ```
   The number `-1001987654321` is your Channel ID.

#### Method C: Using the Bot API directly
1. Post a test message in your channel.
2. Open this URL in your browser (replace `<YOUR_BOT_TOKEN>`):
   ```text
   https://api.telegram.org/bot<YOUR_BOT_TOKEN>/getUpdates
   ```
3. Look for `"chat":{"id": -100xxxxxxxxxx}` in the JSON response.

---

## ⚙️ Configuration Setup

1. Copy `.env.example` to `.env` if you haven't already:
   ```bash
   cp .env.example .env
   ```
2. Open `.env` and fill in your details:
   ```ini
   # Admin Authentication
   ADMIN_USER=admin
   # Default password is: admin123
   ADMIN_PASSWORD_HASH=$2y$10$i2aX7K4wKkXwEcmQbv2oU.F2sWn3n5gJzX5tF6a9qB8j9c4d4E4lW

   # Native Session Secret Key
   SESSION_SECRET=your_random_secure_secret_key_here

   # Telegram Credentials & Channels
   TELEGRAM_BOT_TOKEN=123456789:ABCdefGhIJKlmNoPQRsTUVwxyZ
   STORAGE_CHANNEL_ID=-1001234567890
   INDEX_CHANNEL_ID=-1009876543210

   # Temporary directory for chunk assembling
   TEMP_CHUNK_DIR=temp_chunks
   ```

> 💡 **Tip to change the password:** Run this one-line PHP command in your terminal to generate a new password hash:
> ```bash
> php -r 'echo password_hash("your_new_password", PASSWORD_BCRYPT);'
> ```
> Paste the generated hash into `ADMIN_PASSWORD_HASH`.

---

## 🚀 How to Run TeleDrive

### Option 1: Run Locally (Built-in PHP Server)
You can run TeleDrive locally with PHP 8.x:
```bash
php -S localhost:8080
```
Then open your browser and navigate to:
```text
http://localhost:8080
```

---

### Option 2: Deploy to Shared Hosting (cPanel / Apache)
1. Upload all files to your server (e.g. into `public_html/` or a subfolder).
2. Ensure the `temp_chunks/` directory has write permissions (`chmod 775` or `777`).
3. Make sure the `.htaccess` and `.env` files are present.
4. Access your domain: `https://yourdomain.com/`

---

## 🔐 Default Login Credentials

- **Username:** `admin`
- **Password:** `admin123`

---

## 📁 Directory Structure

```text
TeleDrive/
├── .env.example              # Configuration template
├── .env                      # Active environment credentials (kept private)
├── .htaccess                 # Apache / cPanel security hardening
├── index.php                 # Entry point & view router
├── config.php                # Environment parser & session configuration
├── api/
│   └── index.php             # Unified REST API handler
├── includes/
│   ├── Auth.php              # Secure session authentication
│   ├── Helpers.php           # Formatting & response utilities
│   ├── TelegramClient.php    # Telegram API client (cURL, multipart, streaming)
│   └── StorageEngine.php     # Zero-DB storage, checkpointing & manifest compaction
├── shared/
│   └── css/
│       └── global.css        # Global CSS variables & design tokens
├── assets/
│   ├── global.css            # Global CSS variables entry
│   ├── global.js             # Toast notifications & confirmation dialogs
│   ├── backend/              # Admin login styles and scripts
│   └── frontend/             # Dashboard styles and chunked upload client scripts
└── templates/
    ├── backend/login.php     # Admin login template
    └── frontend/app.php      # Main dashboard template
```