# NexusBids — Full-Stack Platform Migration & Operations Guide

## 1. Architecture Overview: Old vs. New

```mermaid
graph TD
  subgraph Old Architecture (PHP Monolith)
    PHP[PHP 8.2 Scripts & Apache]
    PHPSession[Filesystem PHP Sessions]
    PHPDB[Local MySQL Database]
    PHP --> PHPSession
    PHP --> PHPDB
  end

  subgraph New Architecture (TypeScript & Vercel)
    Web[apps/web: HTML5 + Tailwind + Vanilla JS Client]
    Mobile[apps/mobile: React Native + TypeScript App]
    API[apps/api: Node.js Express REST API on Vercel]
    SSE[Realtime Server-Sent Events / Pusher Live Bidding]
    MySQL[(Cloud MySQL: TiDB / Aiven / PlanetScale)]
    Cron[Vercel Serverless Cron Jobs]
    
    Web -->|REST / JSON| API
    Mobile -->|REST / JSON| API
    Web -.->|SSE Stream| SSE
    API --> MySQL
    Cron -->|Auth Header| API
  end
```

| Dimension | Legacy Architecture | Migrated Architecture |
|---|---|---|
| **Backend** | PHP 8.2 procedural/OOP monolith | Node.js + TypeScript REST API (`apps/api`) |
| **Frontend Web** | Server-side PHP rendering (`.php`) | Client-side HTML/CSS/JS + unified `ApiClient` (`apps/web`) |
| **Mobile App** | None | React Native (Expo) + TypeScript (`apps/mobile`) |
| **Authentication** | Server-side PHP session files | Stateless JWT tokens (httpOnly cookies for web, Bearer for mobile) |
| **Live Bidding** | Short interval AJAX polling | Server-Sent Events (SSE) `/api/v1/realtime/stream/:id` + Pusher ready |
| **Concurrency Safety** | Unlocked SQL queries | `SELECT ... FOR UPDATE` inside atomic MySQL transactions |
| **Identity Verification** | Manual PHP upload to local disk | NADRA CNIC KYC with AES-256 encryption at rest |
| **Escrow & Ledger** | Fragmented status columns | Double-entry `seller_ledger` and `escrow_holds` state machine |
| **AI Integration** | Unconfigured mock endpoints | Real OpenAI / Groq / Gemini integration with rate limits |
| **Deployment Target** | Apache / cPanel / XAMPP | Vercel Serverless Functions + Cloud MySQL |

---

## 2. Monorepo Structure

```
/apps
  /api          Node.js TypeScript REST API (Vercel Serverless Functions)
  /web          High-performance HTML/CSS/JS website (Tailwind + API Client)
  /mobile       React Native (Expo) cross-platform iOS & Android mobile application
/packages
  /shared       TypeScript models, Zod validation schemas, business constants
/db
  schema.sql    Complete 68-table MySQL database structure
  /migrations   001_phase0_fixes.sql non-destructive schema compatibility patch
/docs           Audit reports, API route specifications, and deployment runbooks
```

---

## 3. Local Development & Testing Instructions

### Step 1: Database Setup
1. Ensure your local MySQL or MariaDB server is running (e.g. via XAMPP or Docker).
2. Create the database:
   ```sql
   CREATE DATABASE nexus_bids CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
   ```
3. Import the base schema and Phase 0 compatibility fixes:
   ```bash
   mysql -u root -p nexus_bids < D:/FINAL-PROJECT/db/schema.sql
   mysql -u root -p nexus_bids < D:/FINAL-PROJECT/db/migrations/001_phase0_fixes.sql
   ```

### Step 2: Environment Configuration
Create `apps/api/.env` with your local credentials:
```env
PORT=5000
NODE_ENV=development

# MySQL Database
DB_HOST=127.0.0.1
DB_PORT=3306
DB_USER=root
DB_PASSWORD=
DB_NAME=nexus_bids
DB_CONNECTION_LIMIT=10

# Security Secrets (Generate 32+ character strings for production)
JWT_ACCESS_SECRET=nexusbids_super_secret_jwt_access_token_32chars!
JWT_REFRESH_SECRET=nexusbids_super_secret_jwt_refresh_token_32chars!
KYC_ENCRYPTION_KEY=nexusbids_aes_256_kyc_encryption_key_32c!
CRON_SECRET=nexusbids_cron_secret_token_123

# AI Provider (Groq / OpenAI / Gemini)
AI_BASE_URL=https://api.groq.com/openai/v1
AI_API_KEY=gsk_your_groq_api_key_here
AI_MODEL=openai/gpt-oss-120b
AI_FALLBACK_MODEL=openai/gpt-oss-20b
```

### Step 3: Build & Start
From the project root:
```bash
# 1. Build shared package
cd packages/shared && npm run build

# 2. Build API package
cd ../../apps/api && npm run build

# 3. Start Unified Server
node dist/index.js
```
The server will boot at `http://localhost:5000`:
- **Web App**: `http://localhost:5000/`
- **Auctions**: `http://localhost:5000/auctions`
- **Sign In**: `http://localhost:5000/login`
- **Seller Studio**: `http://localhost:5000/seller/dashboard`
- **Admin Command Center**: `http://localhost:5000/admin/dashboard`
- **API Health Check**: `http://localhost:5000/api/health`

---

## 4. Production Deployment on Vercel

1. Push your repository to GitHub.
2. In the Vercel Dashboard, import the project and set the Root Directory to `apps/api`.
3. In **Project Settings → Environment Variables**, add the variables specified in `apps/api/.env`.
4. Vercel will automatically build the TypeScript API and configure the automated Cron jobs defined in `apps/api/vercel.json`:
   - `* * * * *` (Every minute): `POST /api/v1/cron/process-auctions` — Checks expired auctions, assigns highest bidders as winners, and generates orders.
   - `0 0 * * *` (Daily midnight): `POST /api/v1/cron/auto-release-escrow` — Releases escrow funds to sellers for orders delivered > 7 days prior without disputes.

---

## 5. React Native Mobile App Run Instructions

1. Install dependencies:
   ```bash
   cd apps/mobile && npm install
   ```
2. Start the Expo development server:
   ```bash
   npm start
   ```
3. Press `a` to launch on Android emulator or scan the QR code with the Expo Go app on your physical Android or iPhone device.
4. For production APK / AAB generation:
   ```bash
   npx eas-cli build -p android --profile production
   ```
