# 💬 NexTalk — Real-Time Chat Platform

**A full-stack, WhatsApp-inspired messaging platform built with vanilla PHP, MySQL, and JavaScript — featuring live messaging, communities with role-based access, group polls, and AI-powered smart replies & translation.**

![PHP](https://img.shields.io/badge/PHP-7.4%2B-777BB4?style=flat-square&logo=php&logoColor=white)
![MySQL](https://img.shields.io/badge/MySQL-InnoDB-4479A1?style=flat-square&logo=mysql&logoColor=white)
![JavaScript](https://img.shields.io/badge/JavaScript-Vanilla-F7DF1E?style=flat-square&logo=javascript&logoColor=black)
![Gemini AI](https://img.shields.io/badge/Gemini-2.5%20Flash-8E75B2?style=flat-square&logo=googlegemini&logoColor=white)
![License](https://img.shields.io/badge/License-Educational-informational?style=flat-square)

---

## ✨ Overview

NexTalk is a real-time chat application that combines **direct messages**, **groups**, and **admin-moderated communities** in a single, unified messaging experience — complete with read receipts, typing indicators, message replies/forwarding, polls, and Gemini-powered AI assistance. It was built as a Web Technology (SE3003) project and evolved through several phases into a genuinely feature-complete messaging platform.

## 🚀 Features

### 💬 Messaging Core
- **Real-time delivery** via Server-Sent Events (SSE) — no page refresh, no aggressive polling
- **Direct messages, groups, and communities** unified under one conversation model
- Message **replies**, **forwarding**, **delete for me / delete for everyone**
- **Read receipts** (sent → delivered → read) and live **typing indicators**
- Media attachments — images, documents, audio, and video
- Online presence & "last seen" tracking

### 🏘️ Communities & Groups
- **Role-based access control** (Admin / Moderator / Member) per conversation
- Community **join requests** with admin approval/rejection workflow
- Group and community creation, membership management, and leave/delete flows

### 🗳️ Interactive Polls
- Create in-chat polls with multiple options
- Live vote tallying pushed to all participants in real time via SSE

### 🚫 User Controls
- **Block / unblock** users to prevent unwanted direct messages

### 🤖 AI-Powered Assistance (Google Gemini)
- **Smart Reply suggestions** — three short, context-aware reply chips generated from the latest incoming message
- **One-click message translation** into any target language
- Secure, server-side API key handling (never exposed to the client)

### 🎨 Modern UI/UX
- Clean, WhatsApp-style dashboard with responsive layout
- Polished landing page with feature highlights and live auth-aware navigation

## 🏗️ Tech Stack

| Layer          | Technology                                   |
|----------------|-----------------------------------------------|
| Backend        | PHP 7.4+ (procedural, PDO)                   |
| Database       | MySQL / MariaDB (InnoDB, `utf8mb4`)          |
| Real-time      | Server-Sent Events (SSE)                     |
| Frontend       | HTML5, CSS3, Vanilla JavaScript (no frameworks) |
| AI Integration | Google Gemini API (`gemini-2.5-flash`)       |
| Auth           | PHP sessions + `password_hash` (bcrypt)      |

## 📁 Project Structure

```
NexTalk-Chat-Platform/
├── api/
│   ├── chat/
│   │   ├── ai_assist.php            # Gemini smart replies & translation
│   │   ├── community_requests.php   # Join requests: request/approve/reject
│   │   ├── get_messages.php         # Message history retrieval
│   │   ├── get_participants.php     # Conversation membership + roles
│   │   ├── manage_conversations.php # CRUD, typing, receipts, forward/delete
│   │   ├── polls.php                # Poll creation, voting, results
│   │   ├── send_message.php         # New message + media handling
│   │   └── sse.php                  # Server-Sent Events real-time stream
│   ├── check_auth.php               # Session/auth status check
│   ├── db.php                       # PDO database connection (env-driven)
│   ├── login.php / logout.php / register.php
│   ├── roles.php                    # Role hierarchy & permission helpers
│   └── setup.php                    # One-time DB migration/bootstrap
├── database/
│   └── nextalk_full.sql             # Full schema + seed data
├── html_pages/
│   ├── index.html                   # Marketing landing page
│   ├── auth.html                    # Login / registration
│   ├── dashboard.html + dashboard.js# Main chat application
│   └── style.css
├── uploads/                         # User-uploaded media (protected via .htaccess)
└── index.php                        # Root redirect → html_pages/index.html
```

## 🗄️ Database Schema

The schema centers on a unified **conversations** model (`direct` / `group` / `community`) with per-conversation membership:

- **users** — accounts, bcrypt passwords, global role, presence
- **conversations** — direct/group/community container
- **participants** — membership, per-conversation role, join status (`pending`/`approved`/`rejected`)
- **messages** — content, media, replies, forwards, delivery status
- **message_deletions** / **message_receipts** — per-user delete & read tracking
- **typing_status** — ephemeral typing indicators
- **blocked_users** — DM blocking
- **polls** / **poll_options** / **poll_votes** — in-chat polling

## ⚙️ Getting Started

### Prerequisites
- PHP 7.4+ with the `pdo_mysql` and `curl` extensions
- MySQL or MariaDB
- A web server (Apache/XAMPP recommended) with `mod_rewrite`/`.htaccess` support
- A [Google Gemini API key](https://ai.google.dev/) (for AI features)

### 1. Clone the repository
```bash
git clone https://github.com/Rana-Haseeb/NexTalk-Chat-Platform.git
cd NexTalk-Chat-Platform
```

### 2. Import the database
```bash
mysql -u root -p < database/nextalk_full.sql
```
This creates the `nextalk_db` database with the full schema and sample seed data (5 demo users, password: `password123`).

### 3. Configure environment variables
Create a `.env` file in the project root (kept out of version control):
```env
DB_HOST=localhost
DB_NAME=nextalk_db
DB_USER=root
DB_PASS=

GEMINI_API_KEY=your_gemini_api_key_here
GEMINI_MODEL=gemini-2.5-flash
```

### 4. Serve the app
Place the project in your web server's document root (e.g., `htdocs/` for XAMPP) and visit:
```
http://localhost/NexTalk-Chat-Platform/
```

## 🔐 Security Notes

- Passwords are hashed with bcrypt (`password_hash`)
- All chat/API endpoints validate session authentication and conversation membership before returning data
- Gemini API key is loaded server-side only, never sent to the browser
- The `uploads/` directory ships with a `.htaccess` to restrict direct script execution

## 🎓 Academic Context

Built for **Web Technology (SE3003)** as a progressive, multi-phase project — evolving from a basic room-based chat into a full-featured messaging platform with real-time delivery, RBAC-driven communities, and AI integration.

## 📄 License

This project was developed for educational purposes as part of a university course.
