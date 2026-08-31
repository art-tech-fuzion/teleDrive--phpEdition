Project Name: TeleDrive (Self-Contained Telegram Cloud File Manager)

Tech Stack: Pure PHP 8.x + HTML5 / CSS3 / Vanilla JavaScript (No Frameworks, No Docker)

Architecture: Zero-Database Architecture (Telegram as Storage & NoSQL Engine)

1. Overview & Architecture
Ek lightweight web-based file manager jo kisi bhi standard Shared Hosting (cPanel/PHP) par bina kisi MySQL ya local database ke run karega.

+-------------------------------------------------------------------------+
|                  Frontend (HTML5 + CSS3 + Vanilla JS)                   |
|           (Google Drive Style UI, Drag-and-Drop, Modal Windows)         |
+------------------------------------+------------------------------------+
                                     | (AJAX / Fetch API)
                                     v
+------------------------------------+------------------------------------+
|                  Backend API (Pure PHP 8.x Script)                      |
|            (Telegram Bot API / MadelineProto Client via cURL)           |
+-------------------+---------------------------------+-------------------+
                    |                                 |
                    v                                 v
   +-------------------------------+   +-------------------------------+
   |        Storage Channel        |   |       Index / DB Channel      |
   |   (Raw Files & 2GB+ Chunks)   |   |   (Metadata JSONs & Manifest) |
   +-------------------------------+   +-------------------------------+
2. Core Functional Specifications
A. Security & Environment Configuration (.env or config.php)
Single config file jisme credentials rahenge:

ADMIN_USERNAME & ADMIN_PASSWORD_HASH (PHP password_hash() encrypted)

SESSION_SECRET (For PHP native session authentication)

TELEGRAM_BOT_TOKEN

STORAGE_CHANNEL_ID (e.g., -100xxxxxxxxxx)

INDEX_CHANNEL_ID (e.g., -100yyyyyyyyyy)

B. The 50-Message Compaction/Checkpointing Logic
Index Channel Storage: Har nayi file upload ya folder creation par ek JSON text message Index Channel mein send hoga.

On Every 50th Entry Trigger:

PHP script Index Channel ka Pinned Message (master_manifest.json) fetch karegi (agar exist karta hai).

Pinned message ke baad ke 50 individual JSON messages ko Telegram API se read karke merge karegi.

Ek consolidated file master_manifest.json banakar Index Channel par as a document upload karegi.

Telegram API call ke through us nayi file ko PIN karegi aur purane pinned message ko delete/unpin karegi.

Instant Dashboard Load: App load hote waqt PHP script channel ke hazaron messages scan nahi karegi; sirf pinned master_manifest.json aur uske aage ke delta messages read karegi (< 1 second load time).

C. Large File Upload & 2GB Splitting
PHP upload_max_filesize aur post_max_size ko bypass karne ke liye JavaScript frontend file ko Client-side Chunking se chunks mein bhejega.

2GB se badi files ko backend split archive formats mein Storage Channel par bhejega aur har chunk ki message_id ko Index JSON mein track karega.

D. Download & Streaming
Jab download trigger hoga, PHP script background mein Telegram se chunks stream karegi aur output buffer ke through client browser ko ek continuous single file download provide karegi.

E. CRUD Operations (Folder & File Management)
Folder Creation: Index Channel mein { type: "folder", id: "uuid", parent_id: "root" } add hoga.

Rename (File/Folder): Index Channel ke corresponding message ko editMessageText ke through in-place update kiya jayega.

Delete File:

Storage Channel se chunk message_id delete hoga (deleteMessage).

Index Channel se metadata message delete hoga.

Delete Folder (Cascade): Recursive function jo us folder ke andar ke sabhi child files/sub-folders ko Storage aur Index Channel dono se completely wipe karega.

🤖 Master Prompt for AI Coding Agent
Is prompt ko copy karke Cursor, Claude, ya ChatGPT mein paste karo. Yeh direct shared hosting ready PHP codebase generate karega:

Markdown
You are an expert full-stack developer. Build a production-ready, self-hosted web-based Telegram File Manager called "TeleDrive-PHP" using Pure PHP 8.x (Backend) and HTML5 + CSS3 + Vanilla JavaScript (Frontend). NO Docker, NO Node.js, and NO external SQL/NoSQL databases are allowed.

### STRICT ARCHITECTURAL REQUIREMENTS:
1. ZERO DATABASE:
   - Do NOT use MySQL, SQLite, MongoDB, or local persistent JSON files.
   - Telegram itself must act as the database and storage engine.
2. TWO-CHANNEL SYSTEM:
   - Storage Channel: Holds all raw uploaded binary files and multipart chunks.
   - Index Channel: Stores file metadata and folder hierarchies as JSON messages.
3. 50-MESSAGE CHECKPOINTING ENGINE:
   - File/Folder metadata is posted as individual JSON messages in the Index Channel.
   - When 50 delta messages accumulate since the last checkpoint:
     a. Fetch the current pinned `master_manifest.json` document from the Index Channel.
     b. Fetch and merge the last 50 individual JSON messages into the manifest array.
     c. Upload the updated `master_manifest.json` as a document to the Index Channel.
     d. Pin the new manifest message using the Telegram Bot API (`pinChatMessage`) and delete/unpin the old one.
   - On page load / listing: PHP must ONLY read the Pinned `master_manifest.json` document + any un-checkpointed delta messages to ensure instant <1s load times.
4. LARGE FILE HANDLING & CHUNKING:
   - Use client-side chunked file upload via Vanilla JS Fetch/XHR to handle large files.
   - Split files larger than 2GB into parts for the Storage Channel.
   - Implement a streaming download in PHP (`readfile` / stream pipeline) to merge and serve multipart files directly to the browser.
5. FOLDER & FILE MANAGEMENT (CRUD):
   - Full folder tree support (root, subfolders, breadcrumbs).
   - In-place renaming using Telegram's `editMessageText` API.
   - Cascade delete for folders (recursively deleting all files in Storage Channel and their metadata in Index Channel).
6. SECURITY & DEPLOYMENT:
   - Store admin credentials (`ADMIN_USER`, `ADMIN_PASSWORD_HASH`), `SESSION_SECRET`, Telegram Bot Token, Storage Channel ID, and Index Channel ID in a `.env` file (parsed by a lightweight custom PHP helper) or `config.php`.
   - Protect all API endpoints using native PHP secure sessions (`$_SESSION`).
   - Clean UI inspired by modern cloud drives (Google Drive/Dropbox style) using pure CSS (Flexbox/Grid) without external CSS frameworks.

Provide the complete directory structure, all PHP backend files (`index.php`, `api.php`, `telegram.php`, `config.php`), and frontend files (`app.js`, `style.css`). All code must be fully written, secure, and ready to deploy on standard Apache/cPanel hosting.