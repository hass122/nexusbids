# NEXUSBIDS — PHASE 0 AUDIT REPORT & MIGRATION MAP
**Generated**: October 5, 2026  
**Auditor**: Senior Full-Stack Architect & Security Engineer  
**Source Codebase**: NexusBids (PHP 8.2 + MySQL + Vanilla HTML/CSS/JS)  
**Target Architecture**: Monorepo (Node.js/TypeScript REST API on Vercel + Existing Vanilla Web UI + React Native Mobile App)

---

## A. ARCHITECTURE (Current PHP Structure)

### 1. Overview
The legacy NexusBids platform is a monolithic procedural PHP application relying on:
- **Server-Side Session State**: PHP `$_SESSION` stored locally in file sessions or database table `sessions`/`active_sessions`.
- **Database Layer**: Direct MySQLi connections initialized in `config/database.php` (`new mysqli('localhost', 'root', '', 'nexus_bids')`) with manual `SET time_zone = '+05:00'` (Asia/Karachi).
- **Execution Model**: Monolithic synchronous execution. Crucially, background tasks such as auction finalization are run synchronously inside public page requests (e.g., `index.php` line 9 calls `processEndedAuctions($conn)` on every public visitor load!).
- **Asset Storage**: Unprotected local filesystem directories (`uploads/auctions`, `uploads/kyc`, `uploads/proofs`).
- **Realtime Layer**: Absent. Bidding updates require manual page refreshes or short-interval AJAX polling (`ajax/auction-status.php`).

### 2. Directory Structure Map
```
/
├── admin/                     # Admin control panel (41 files)
├── ajax/                      # Polling and status endpoints (2 files)
├── assets/                    # Static CSS, JS, fonts, images (11 files)
├── auth/                      # Authentication, registration, OTP, OAuth (14 files)
├── config/                    # Database, mail, payment gateways, OAuth configs (4 files)
├── cron/                      # Scheduled maintenance scripts (2 files)
├── includes/                  # Business logic, helpers, AI engines, notifications (24 files)
├── libs/                      # Third-party libraries (PHPMailer) (7 files)
├── payments/                  # Payment gateway integrations & callbacks (8 files)
├── seller/                    # Seller portal, auction creation, fulfillment (28 files)
├── uploads/                   # Uploaded media (52 files)
├── user/                      # Buyer dashboard, bids, orders, reviews (17 files)
└── [root]                     # Public browsing, auction details, bidding, checkout (24 files)
```

---

## B. BUYER FLOW

1. **Discovery & Onboarding**:
   - Guest visits `index.php` or `auctions.php`, searches/filters by category, search keyword, and price.
   - Registers via `auth/register.php` (POST `auth/register_process.php`), sends verification email with raw link `verify-email.php?token=...`.
   - Logs in via `auth/login.php` (POST `auth/login_process.php`). If risk score triggers (`includes/ai-security.php`), user is redirected to `auth/verify-login.php` to input a 6-digit email OTP.
2. **KYC Verification Gate**:
   - To place bids or set auto-bids, buyer MUST be KYC-verified (`kyc_status = 'approved'`).
   - Submits CNIC front/back, selfie, selfie with CNIC via `user/kyc.php` (POST `user/kyc-submit.php`).
   - Background/admin triggers Gemini Vision OCR (`includes/ai-kyc.php`) to extract CNIC and compare data. Admin reviews in `admin/kyc-review.php` and approves.
3. **Bidding & Watchlist**:
   - Buyer views `auction-details.php?id=X`.
   - Adds/removes from watchlist via `add-watchlist.php` / `remove-watchlist.php`.
   - Manual Bid: Submits to `place-bid.php`. Row locks auction (`FOR UPDATE`), validates `bid_amount >= current_bid + bid_increment`, inserts into `bids`, updates `auctions.current_bid`, runs fraud analysis (`includes/ai-bid-fraud.php`), triggers `nb_resolve_auto_bids()`.
   - Auto-Bid: Submits ceiling to `set-auto-bid.php`, which inserts/updates `auto_bids` and invokes `nb_resolve_auto_bids()`.
4. **Winning & Checkout**:
   - Auction ends (`end_time <= NOW()`). `processEndedAuctions()` creates a record in `winners` and `orders`.
   - Buyer visits `user/won-auctions.php` or `checkout.php?auction_id=X`.
   - Selects payment method: Easypaisa, JazzCash, Bank Transfer, or Cash on Delivery.
5. **Fulfillment & Confirmation**:
   - Tracks shipment in `user/order-details.php` with tracking number and courier.
   - Upon delivery, buyer confirms receipt via `user/confirm-receipt.php`, releasing escrow or triggering review window.
   - Buyer leaves rating/review via `user/add-review.php`, or files a dispute via `user/report-problem.php`.

---

## C. SELLER FLOW

1. **Onboarding & Seller KYC**:
   - Seller registers with `role = 'seller'`.
   - Must complete seller KYC (`seller_kyc_status = 'approved'`) via `seller/kyc.php` including bank account details (IBAN, account title, branch) and optional business NTN before creating auctions.
2. **Auction Lifecycle**:
   - Creates auction via `seller/create-auction.php` (POST `seller/create-auction-process.php`).
   - Can use AI description generator (`seller/ai-generate-description.php`) powered by Groq/OpenAI-compatible LLM.
   - Can save as `draft` or submit for approval (`pending`).
   - Admin approves auction (`admin/auction-action.php`). Auction goes `scheduled` or `active`.
   - Seller monitors live bids in `seller/bid-activity.php` and analytics in `seller/auction-analytics.php`.
   - Can duplicate (`seller/duplicate-auction.php`), cancel before bids (`seller/cancel-auction.php`), or relist ended unsold auctions (`seller/relist-auction.php`).
3. **Order Fulfillment & Shipping**:
   - Once auction is won and paid, order appears in `seller/orders.php`.
   - Seller ships item, inputs courier and tracking number, and uploads shipping proof (receipt/airway bill photo) via `seller/ship-order.php`. Order moves to `shipped`.
4. **Earnings & Payouts**:
   - Escrow holds funds in `escrow_holds` until buyer confirms or 7-day auto-release timer expires (`cron/auto-release-escrow.php`).
   - Seller balance updates in `seller_ledger` / `seller_wallet`.
   - Seller views earnings in `seller/earnings.php` and requests payout in `seller/payouts.php`.

---

## D. ADMIN FLOW

1. **Authentication & Operations**:
   - Admin logs in via `auth/login.php` (role checked in `admin/admin-auth.php`).
   - Central KPI Dashboard in `admin/index.php`: total GMV, live auctions, pending KYC queue, active disputes, unread security alerts.
2. **Catalog & User Management**:
   - Users: View, activate, suspend, block users (`admin/users.php`, `admin/user-status.php`).
   - Categories: CRUD categories (`admin/categories.php`, `admin/add-category.php`, `admin/edit-category.php`).
   - Auctions: Approve, reject, cancel, freeze auctions (`admin/auctions.php`, `admin/auction-action.php`).
3. **KYC & Security Oversight**:
   - KYC Queue: Review buyer/seller submissions (`admin/kyc-queue.php`), inspect documents via secure proxy (`admin/kyc-file.php`), run AI OCR re-processing (`admin/kyc-reprocess-ai.php`), approve/reject (`admin/kyc-action.php`).
   - Shill Bid & Fraud Alerts: Review suspicious bids flagged by rule engine / AI (`admin/bid-fraud-alerts.php`, `admin/test-fraud.php`).
   - Security Alerts: Account takeover attempts, brute force alerts (`admin/security-alerts.php`).
   - Audit Log: Every administrative action logged to `admin_activity_log` (`admin/activity-log.php`).
4. **Financial Control & Dispute Resolution**:
   - Monitor payments and gateway responses (`admin/payments.php`).
   - Escrow Management: Inspect held funds, manually release or refund escrow (`admin/escrow.php`, `admin/release-escrow.php`).
   - Shipping Proof Verification: Review courier receipts uploaded by sellers (`admin/order-proof-review.php`, `admin/order-verifications.php`).
   - Payout Approvals: Review bank payout requests, mark completed with bank reference (`admin/payouts.php`).
   - Dispute Arbitrament: Mediate buyer/seller disputes, issue full or partial refunds (`admin/disputes.php`, `admin/refunds.php`).
   - Export Data: Export bids and winners to CSV (`admin/export-bids.php`, `admin/export-winners.php`).

---

## E. DATABASE SCHEMA AUDIT

The database `nexus_bids` contains **68 tables**. Below is the audit of core entities, keys, status fields, and critical schema anomalies:

### 1. Core Tables & Status Enums
| Table | Primary Key | Key Foreign Keys | Status Fields & Enums | Notes |
|---|---|---|---|---|
| `users` | `user_id` | - | `status`: `'active','inactive','blocked'`<br>`kyc_status`: `'unverified','pending','approved','rejected'`<br>`seller_kyc_status`: `'unverified','pending','approved','rejected'` | Holds both buyer and seller profile, 2FA, lockout, and payout fields. |
| `auctions` | `auction_id` | `seller_id` → `users.user_id`<br>`category_id` → `categories.category_id` | `auction_status`: `'draft','pending','approved','rejected','scheduled','active','ended','sold','unsold','cancelled','relisted'` | **CRITICAL BUG**: Code sets status to `'completed'`, which is NOT in this ENUM! |
| `bids` | `bid_id` | `auction_id` → `auctions.auction_id`<br>`bidder_id` → `users.user_id` | `is_auto_bid`: `tinyint(1)` | Indexed on `auction_id`, `bidder_id`, `bid_amount`. Has `device_hash` and `ip_address`. |
| `auto_bids` | `id` | `auction_id` → `auctions.auction_id`<br>`user_id` → `users.user_id` | `status`: `'active','paused','outbid','cancelled'` | Unique on `(auction_id, user_id)`. |
| `winners` | `winner_id` | `auction_id` → `auctions.auction_id`<br>`user_id` → `users.user_id` | `status`: `'pending_payment','paid','completed'` | Has trigger `trg_winners_auto_order` firing on INSERT. |
| `orders` | `order_id` | `auction_id` → `auctions.auction_id`<br>`winner_id` → `winners.winner_id`<br>`buyer_id` → `users.user_id`<br>`seller_id` → `users.user_id` | `order_status`: `'pending_payment','paid','processing','shipped','delivered','completed','cancelled','refunded','disputed'`<br>`payment_status`: `'unpaid','pending','paid','failed','refunded'`<br>`shipping_status`: `'not_started','awaiting_address','ready_to_ship','shipped','in_transit','out_for_delivery','delivered','failed','returned'` | Unique on `order_number` and `auction_id`. |
| `payments` | `payment_id` | `auction_id` → `auctions.auction_id`<br>`order_id` → `orders.order_id`<br>`winner_id` → `winners.winner_id`<br>`user_id` → `users.user_id` | `payment_status`: `'pending','processing','paid','failed','refunded'`<br>`payment_method`: `'jazzcash','easypaisa','bank_transfer','card','cash_on_delivery'` | Records gateway response and transaction ID. |
| `escrow_holds` | `id` | `order_id` → `orders.order_id`<br>`seller_id`, `buyer_id` → `users.user_id` | `status`: `'held','released','partially_released','refunded','disputed'` | Holds escrow balance and auto-release timestamp. |
| `payouts` | `id` | `seller_id` → `users.user_id` | `status`: `'requested','approved','processing','paid','rejected','cancelled'` | Tracks seller withdrawals to Pakistani bank accounts. |
| `kyc_verifications` | `kyc_id` | `user_id` → `users.user_id` | `status`: `'pending','under_review','approved','rejected'`<br>`kyc_scope`: `'buyer','seller','both'`<br>`ai_status`: `'pending','processing','completed','failed'` | Holds AES-256 encrypted CNIC and file paths. |
| `bid_fraud_alerts` | `alert_id` | `auction_id` → `auctions.auction_id`<br>`seller_id`, `bidder_id` → `users.user_id` | `status`: `'new','reviewing','dismissed','confirmed_fraud','action_taken'` | Shill bid detection signals and risk scores. |
| `security_alerts` | `alert_id` | `user_id` → `users.user_id` | `status`: `'new','investigating','resolved','false_positive'` | Account takeover and brute force telemetry. |

### 2. Schema Anomalies, Redundancies & Discrepancies Found
1. **Missing `'completed'` in `auctions.auction_status`**:
   `includes/process-auctions.php`, `process-ended-auctions.php`, and `admin/fix-winners.php` all run `UPDATE auctions SET auction_status = 'completed'`, but the enum definition only has:
   `('draft','pending','approved','rejected','scheduled','active','ended','sold','unsold','cancelled','relisted')`.
   *Consequence*: In strict MySQL mode, the query errors out with `Data truncated`. In non-strict mode, it saves as empty string `''`.
2. **Duplicate View and Watcher Counters in `auctions`**:
   - View counters: `view_count`, `views`, `views_count` (3 duplicate columns on the same table!).
   - Watchlist counters: `watch_count`, `watchlist_count` (2 duplicate columns!).
   - Increment counters: `bid_increment`, `minimum_increment` (2 duplicate columns!).
3. **Dead / Orphan Table `auction_winners`**:
   `auction_winners` exists in the SQL dump, but zero PHP files reference it. The application solely uses `winners`.
4. **Dual Disputes Tables**:
   - `disputes` (`dispute_id`, `order_id`, `buyer_id`, `seller_id`, `reason`, `description`, `status`) is queried in `seller/order-details.php` and `email-events.php`.
   - `payment_disputes` (`id`, `order_id`, `dispute_type`, `amount`, `evidence`, `status`) is queried in `user/report-problem.php`, `admin/disputes.php`, `seller/disputes.php`, and `includes/ledger.php`.
   *Consequence*: Disputes submitted by buyers via `report-problem.php` do not populate the view in `seller/order-details.php`!
5. **Database Trigger Conflict on `winners` (`trg_winners_auto_order`)**:
   When `winners` row is inserted, the trigger creates an `orders` record with `order_number = CONCAT('ORD-', ...)`. Simultaneously, `includes/process-auctions.php` attempts to create an order with `order_number = 'NB-2026-...'`. This causes order numbering collisions and conflicting platform fee arithmetic.

---

## F. FEATURE STATUS TABLE

| Area | Feature | Current Legacy Status | Findings & Defects |
|---|---|---|---|
| **Auth** | Email/Password Registration | Working | Works, but sends raw link without expiry hash. |
| **Auth** | Email Verification | Working | Verifies `verify_token` against database. |
| **Auth** | Login with Password Verify | Working | Uses standard bcrypt `password_verify`. |
| **Auth** | Account Takeover / AI Login Check | Working | Analyzes IP/device fingerprint, scores risk, enforces OTP. |
| **Auth** | Google OAuth | Partially Broken | Credentials hardcoded in `config/google-oauth.php`; redirect URI hardcoded to `http://localhost/NEXUS/...`. |
| **Auth** | Forgot / Reset Password | Working | Generates token with expiry; sends email via PHPMailer. |
| **Auctions** | Create Auction | Working | Validates seller KYC; writes file to disk; validates categories. |
| **Auctions** | Draft & Relist Auctions | Working | Drafts supported; duplicate and relist functional. |
| **Auctions** | Admin Auction Approval | Working | Status transition `pending` → `approved`/`rejected`. |
| **Bidding** | Manual Bidding | Working | Transaction with `FOR UPDATE` locking; KYC checked; min increment validated. |
| **Bidding** | Auto-Bidding | Partially Working | Logic works in isolation, but `nb_resolve_auto_bids()` runs **outside** transaction lock; vulnerable to race conditions under load. |
| **Auctions** | Finalize Ended Auctions | Broken / Glitchy | `processEndedAuctions` updates `auctions.auction_status = 'completed'` which is invalid enum value in MySQL; runs synchronously on user visits to `index.php`. |
| **Watchlist** | Add / Remove Watchlist | Working | Database backed with count update. |
| **KYC** | Buyer KYC Submission | Working | Collects 4 images, encrypts CNIC with AES-256. |
| **KYC** | Seller KYC Submission | Working | Collects CNIC + bank account details. |
| **KYC** | Gemini Vision AI OCR | Working | Calls Google Generative Language API, extracts CNIC and details. |
| **KYC** | Admin Review & Verification | Working | Secure proxy serves images without public URL exposure. |
| **Payments** | Bank Transfer Manual | Working | Uploads receipt slip, awaits admin verification in `order-proof-review.php`. |
| **Payments** | Cash on Delivery (COD) | Working | Marks order `pending_payment` until delivery confirmation. |
| **Payments** | JazzCash Gateway | Partially Broken | Callback hash verification is implemented, but credentials are placeholders; callback handler terminates on session mismatch. |
| **Payments** | Easypaisa Gateway | Partially Broken | Form post initiate implemented; callback lacks idempotent transaction guards. |
| **Escrow** | Escrow Holding & Release | Working | Holds funds in `escrow_holds`; ledger entries in `seller_ledger`. |
| **Escrow** | Auto-Release Cron (7 Days) | Working | Script exists with token check; requires external trigger. |
| **Shipping** | Courier & Proof Upload | Working | Seller inputs tracking number and courier; buyer/admin can track. |
| **Shipping** | Confirm Receipt | Working | Buyer confirms receipt; marks order `completed`; releases escrow. |
| **Reviews** | Seller Reviews | Working | Allowed only on delivered/completed orders once per order. |
| **Disputes** | Dispute Filing & Resolution | Partially Broken | Data split between `disputes` and `payment_disputes` tables. |
| **AI** | Chatbot (`ai-chat-api.php`) | Working | Uses Groq/OpenAI compatible API; language detection for Urdu/Roman Urdu. |
| **AI** | Description Generator | Working | Generates structured auction descriptions with fallback to safe template. |
| **AI** | Shill Bidding Detection | Working | Analyzes IP, subnet, device hash, and bid history to flag alerts. |
| **AI** | Recommendations | Missing / Stub | Table `ai_recommendations` exists in DB, but no algorithm generates recommendations. |
| **Realtime** | Live Bidding Updates | Missing | Relies entirely on full page refresh or periodic polling. |
| **Mobile App** | React Native App | Missing | No mobile codebase exists; to be built from scratch in Phase 10. |

---

## G. SECURITY AUDIT ISSUES

### 1. Hardcoded Credentials & Exposed Secrets (CRITICAL)
- **Google OAuth**: `config/google-oauth.php` contains hardcoded `GOOGLE_CLIENT_ID` (`1047729043749-***.apps.googleusercontent.com`) and `GOOGLE_CLIENT_SECRET` (`GOCSPX-***`).
- **SMTP Credentials**: `config/mail-config.php` hardcodes Gmail SMTP App Password (`jbow zqbf kfup xkwv`) and email (`hassanrazaq988@gmail.com`).
- **AI Keys**: `.env` in legacy root contains active Groq API Key (`gsk_***`) and Gemini API Key (`AQ.Ab8RN***`).
- **Cron Token**: `cron/auto-release-escrow.php` has hardcoded default token `'change-this-to-a-long-random-string-xyz123'`.

### 2. Ephemeral Filesystem & Path Traversal (CRITICAL)
- Files uploaded to local `uploads/` directory. On Vercel serverless functions, the local filesystem is **read-only and ephemeral**; local uploads will crash or vanish immediately.
- KYC files stored unencrypted on disk (though CNIC string is encrypted in DB). While `admin/kyc-file.php` has basic traversal checks, direct HTTP access to `uploads/kyc/` was not completely blocked at web server configuration level.

### 3. Session Fixation & Cookie Vulnerability (HIGH)
- Legacy relies on PHP native sessions (`PHPSESSID`). On Vercel serverless, PHP session persistence across lambda instances does not exist.
- Migration must switch to stateless JWTs (signed access tokens + rotating refresh tokens in `httpOnly` secure cookies for web, secure storage for mobile).

### 4. Concurrency & Race Conditions in Bidding (CRITICAL)
- `place-bid.php` uses `SELECT ... FOR UPDATE`, which is good; however, `nb_resolve_auto_bids()` runs **outside** the transaction. Multiple simultaneous bids can trigger interleaved auto-bid calculations, placing duplicate or skipped bids.

### 5. Denial of Service via Synchronous Auction Processing (HIGH)
- `index.php` and `user/won-auctions.php` invoke `processEndedAuctions($conn)` synchronously on every request. If hundreds of auctions expire simultaneously, user page requests will freeze or time out.

---

## H. UI/UX AUDIT ISSUES

1. **No Live Feedback**: When another bidder places a higher bid, the user continues looking at stale prices and count-downs until manually refreshing.
2. **Flash of Unstyled Layout & Dark Mode Drift**: Dark mode toggle in `script.js` uses `localStorage.getItem('theme')`, but page templates render in light mode before JS executes, causing a jarring visual flicker.
3. **Inconsistent Error/Success Messages**: Some pages redirect with `$_SESSION['error']`, others echo inline HTML alert boxes, and some redirect to a generic error page (`nb_gateway_die`).
4. **Mobile Navigation & Table Overflows**: Admin and Seller tables (`orders.php`, `auctions.php`, `transactions.php`) do not scroll horizontally on mobile devices, breaking the viewport.
5. **No Optimistic UI**: Bidding buttons lack disabled/loading states during submission, enabling double-clicking and multiple form submissions.

---

## I. ACTUAL BUGS (Code & Schema)

1. **`auctions.auction_status` Enum Mismatch**:
   - Schema defines: `enum('draft','pending','approved','rejected','scheduled','active','ended','sold','unsold','cancelled','relisted')`.
   - Code executes: `UPDATE auctions SET auction_status = 'completed'`.
   - *Result*: Causes database update failures on auction completion.
2. **Dispute Inconsistency**:
   - `user/report-problem.php` writes to `payment_disputes`.
   - `seller/order-details.php` reads from `disputes`.
   - *Result*: Sellers never see dispute details inside their order page.
3. **Double Order Creation Conflict**:
   - Database trigger `trg_winners_auto_order` automatically creates an order when a winner is inserted.
   - `includes/process-auctions.php` also executes an `INSERT INTO orders ... ON DUPLICATE KEY UPDATE`.
   - The trigger calculates commission differently (`winning_bid + fee` vs `fee deducted from seller`).
4. **`process-ended-auctions.php` vs `includes/process-auctions.php` Desynchronization**:
   - Root `process-ended-auctions.php` only inserts into `winners` and does not create orders or notify users.
   - `includes/process-auctions.php` creates orders and notifications.
   - Two different versions of the core auction conclusion engine exist simultaneously.
5. **Hardcoded Localhost URLs in Redirects**:
   - `auth/google-callback.php`, `payments/_bootstrap.php`, and `register_process.php` contain hardcoded `http://localhost/NEXUS/...` paths, which fail when deployed to any production domain.

---

## J. ISSUE PRIORITY MATRIX

| Priority | Issue / Defect | Remediation Plan |
|---|---|---|
| **CRITICAL** | Hardcoded secrets & API keys | Move all secrets to Vercel/Node environment variables (`.env`). |
| **CRITICAL** | `auctions.auction_status` missing `'completed'` | Run schema migration: `ALTER TABLE auctions MODIFY auction_status ENUM(..., 'completed')`. |
| **CRITICAL** | Ephemeral file storage on Vercel | Replace local `/uploads` with Cloudflare R2 / AWS S3 / Vercel Blob with signed private URLs for KYC. |
| **CRITICAL** | Race condition in auto-bidding | Wrap bidding and auto-bid resolution in a single atomic transaction with row locking (`SELECT ... FOR UPDATE`). |
| **CRITICAL** | Synchronous auction processing on web traffic | Move auction processing to automated Vercel Cron (`/api/v1/cron/process-auctions`) + lazy evaluation on read. |
| **HIGH** | Dual disputes table discrepancy | Unify on `disputes` schema or synchronize `payment_disputes` with a single unified API repository. |
| **HIGH** | Database trigger conflict on `orders` | Drop conflicting trigger `trg_winners_auto_order`; let the application business service manage order creation cleanly. |
| **HIGH** | Lack of JWT / Stateless Auth | Implement JWT access tokens (15m) + refresh tokens (7d) stored in secure httpOnly cookies. |
| **HIGH** | Absence of Realtime Bidding | Implement managed realtime events (Pusher / Ably / Supabase Realtime) triggered upon bid transaction commit. |
| **MEDIUM** | Duplicate counter columns (`view_count`, `watch_count`) | Normalize and maintain single authoritative counter columns in `auctions`. |
| **MEDIUM** | Inconsistent UI flash / error states | Unify frontend API client with toast notifications and optimistic state updates. |
| **LOW** | Missing recommendations engine | Build rule-based / collaborative filtering recommendation endpoint in Node.js. |

---

## K. COMPLETE MIGRATION MAP

Every legacy PHP file and endpoint mapped to the new Node.js TypeScript API route, frontend page, or shared service:

### 1. Public & Core Endpoints
| Legacy PHP File | New API Route / Location | Method | Purpose & Notes |
|---|---|---|---|
| `index.php` | `GET /api/v1/auctions/featured`<br>`GET /api/v1/categories` | GET | Serves homepage carousel, live auctions, ending-soon auctions, stats. |
| `auctions.php` | `GET /api/v1/auctions` | GET | Search, category filter, price filter, sorting, pagination. |
| `auction-details.php` | `GET /api/v1/auctions/:id` | GET | Detailed auction information, seller details, bid history, high bidder. |
| `categories.php` | `GET /api/v1/categories` | GET | Category listing with auction counts. |
| `about.php` | Static Frontend Page | - | Static informational page. |
| `place-bid.php` | `POST /api/v1/auctions/:id/bids` | POST | Atomic bid placement with `FOR UPDATE` locking, KYC check, and realtime broadcast. |
| `set-auto-bid.php` | `POST /api/v1/auctions/:id/auto-bid` | POST | Sets auto-bid ceiling and resolves auto-bids atomically. |
| `add-watchlist.php` | `POST /api/v1/watchlist/:auctionId` | POST | Adds auction to user's watchlist. |
| `remove-watchlist.php` | `DELETE /api/v1/watchlist/:auctionId` | DELETE | Removes auction from watchlist. |
| `process-ended-auctions.php`<br>`includes/process-auctions.php` | `POST /api/v1/cron/process-auctions`<br>(Vercel Cron) | POST | Idempotent cron job verifying expired auctions, declaring winners, creating orders. |
| `ai-chat-api.php` | `POST /api/v1/ai/chat` | POST | Customer support AI chatbot with session history and Urdu/English support. |

### 2. Authentication Subsystem (`/auth`)
| Legacy PHP File | New API Route | Method | Purpose & Notes |
|---|---|---|---|
| `auth/login_process.php` | `POST /api/v1/auth/login` | POST | Authenticates credentials, verifies status, evaluates AI security risk. |
| `auth/verify-login.php` | `POST /api/v1/auth/verify-otp` | POST | Validates 6-digit email OTP for suspicious logins. |
| `auth/register_process.php` | `POST /api/v1/auth/register` | POST | Validates input, creates user, dispatches email verification token. |
| `auth/verify-email.php` | `POST /api/v1/auth/verify-email` | POST | Activates account when verification token matches. |
| `auth/forgot-password.php` | `POST /api/v1/auth/forgot-password` | POST | Sends password reset token to email. |
| `auth/reset-password.php` | `POST /api/v1/auth/reset-password` | POST | Resets password using valid token. |
| `auth/google-login.php`<br>`auth/google-callback.php` | `POST /api/v1/auth/google` | POST | Verifies Google ID token server-side; registers/logs in user. |
| `auth/logout.php` | `POST /api/v1/auth/logout` | POST | Clears httpOnly JWT cookies and invalidates refresh token. |

### 3. Buyer Subsystem (`/user`)
| Legacy PHP File | New API Route | Method | Purpose & Notes |
|---|---|---|---|
| `user/dashboard.php` | `GET /api/v1/buyer/dashboard` | GET | Summary of active bids, won auctions, watchlist, orders. |
| `user/my-bids.php` | `GET /api/v1/buyer/bids` | GET | Paginated list of user's active and past bids with outbid status. |
| `user/won-auctions.php` | `GET /api/v1/buyer/won-auctions` | GET | List of won auctions pending checkout or completed. |
| `user/watchlist.php` | `GET /api/v1/watchlist` | GET | User's watchlist with current prices and countdowns. |
| `user/orders.php` | `GET /api/v1/orders` | GET | Order history for buyer. |
| `user/order-details.php` | `GET /api/v1/orders/:id` | GET | Order status, courier tracking, payment details, proofs. |
| `user/confirm-receipt.php` | `POST /api/v1/orders/:id/confirm-receipt` | POST | Confirms item arrival, completes order, releases escrow. |
| `user/add-review.php` | `POST /api/v1/reviews` | POST | Submits rating and review for completed order. |
| `user/report-problem.php` | `POST /api/v1/disputes` | POST | Submits dispute with reason and evidence photos. |
| `user/kyc.php`<br>`user/kyc-submit.php` | `POST /api/v1/kyc/buyer` | POST | Uploads buyer CNIC + selfie documents to secure object storage. |
| `user/kyc-status.php` | `GET /api/v1/kyc/status` | GET | Returns current KYC approval status and rejection reasons. |
| `user/profile.php` | `GET/PATCH /api/v1/user/profile` | GET/PATCH | View and edit personal profile and notification settings. |

### 4. Seller Subsystem (`/seller`)
| Legacy PHP File | New API Route | Method | Purpose & Notes |
|---|---|---|---|
| `seller/dashboard.php` | `GET /api/v1/seller/dashboard` | GET | Seller KPI metrics, active auctions, pending shipments, revenue. |
| `seller/create-auction.php`<br>`seller/create-auction-process.php` | `POST /api/v1/seller/auctions` | POST | Creates auction or draft; requires `seller_kyc_status = 'approved'`. |
| `seller/edit-auction.php`<br>`seller/edit-auction-process.php` | `PATCH /api/v1/seller/auctions/:id` | PATCH | Edits auction details (permitted only prior to first bid). |
| `seller/my-auctions.php` | `GET /api/v1/seller/auctions` | GET | Filterable auctions by status (`draft`, `pending`, `active`, `sold`). |
| `seller/cancel-auction.php` | `POST /api/v1/seller/auctions/:id/cancel`| POST | Cancels auction without bids. |
| `seller/duplicate-auction.php` | `POST /api/v1/seller/auctions/:id/duplicate`| POST | Clones existing auction into a new draft. |
| `seller/relist-auction.php` | `POST /api/v1/seller/auctions/:id/relist`| POST | Relists unsold or ended auction. |
| `seller/bid-activity.php` | `GET /api/v1/seller/auctions/:id/bids` | GET | Live bid logs and bidder details for seller's auction. |
| `seller/auction-analytics.php`| `GET /api/v1/seller/auctions/:id/analytics`| GET | Views, watchlist additions, bid velocity graphs. |
| `seller/orders.php` | `GET /api/v1/seller/orders` | GET | Orders awaiting shipment or completed. |
| `seller/order-details.php` | `GET /api/v1/seller/orders/:id` | GET | Buyer shipping address and order fulfillment status. |
| `seller/ship-order.php` | `POST /api/v1/seller/orders/:id/ship` | POST | Updates tracking number, courier, uploads shipping receipt. |
| `seller/earnings.php` | `GET /api/v1/seller/earnings` | GET | Ledger statements, escrow pending, completed sales. |
| `seller/payouts.php` | `POST /api/v1/seller/payouts` | POST | Requests bank withdrawal of cleared balance. |
| `seller/disputes.php` | `GET /api/v1/seller/disputes` | GET | Disputes filed against seller's orders with response option. |
| `seller/kyc.php`<br>`seller/kyc-submit.php` | `POST /api/v1/kyc/seller` | POST | Uploads CNIC, bank credentials, and business proof. |
| `seller/ai-generate-description.php`| `POST /api/v1/ai/generate-description` | POST | LLM-generated auction descriptions based on title and specs. |

### 5. Payments & Financial Subsystem (`/payments`)
| Legacy PHP File | New API Route | Method | Purpose & Notes |
|---|---|---|---|
| `checkout.php` | `POST /api/v1/payments/checkout` | POST | Initializes checkout for won auction order. |
| `payments/jazzcash/initiate.php` | `POST /api/v1/payments/jazzcash/initiate` | POST | Generates signed payload for JazzCash hosted gateway. |
| `payments/jazzcash/callback.php` | `POST /api/v1/payments/jazzcash/callback` | POST | Validates HMAC-SHA256 signature, updates payment and order state. |
| `payments/easypaisa/initiate.php`| `POST /api/v1/payments/easypaisa/initiate`| POST | Prepares Easypaisa checkout credentials. |
| `payments/easypaisa/callback.php`| `POST /api/v1/payments/easypaisa/callback`| POST | Idempotent callback handler for Easypaisa payment status. |
| `payments/bank_transfer/instructions.php`| `POST /api/v1/payments/bank-transfer` | POST | Generates reference code; accepts deposit slip upload. |
| `payments/cod/confirm.php` | `POST /api/v1/payments/cod` | POST | Confirms Cash on Delivery choice. |
| `cron/auto-release-escrow.php` | `POST /api/v1/cron/auto-release-escrow` | POST | Vercel Cron releasing escrow 7 days post-delivery. |

### 6. Admin Panel Subsystem (`/admin`)
| Legacy PHP File | New API Route | Method | Purpose & Notes |
|---|---|---|---|
| `admin/index.php` | `GET /api/v1/admin/dashboard` | GET | High-level metrics, active counters, revenue, pending items. |
| `admin/users.php`<br>`admin/user-status.php` | `GET/PATCH /api/v1/admin/users` | GET/PATCH | List users, toggle active/blocked status, change roles. |
| `admin/categories.php`<br>`admin/add-category-process.php`<br>`admin/update-category.php`<br>`admin/delete-category.php` | `/api/v1/admin/categories` | CRUD | Manage marketplace product categories. |
| `admin/auctions.php`<br>`admin/auction-action.php` | `GET/PATCH /api/v1/admin/auctions` | GET/PATCH | Review, approve, reject, or freeze auctions. |
| `admin/bids.php`<br>`admin/export-bids.php` | `GET /api/v1/admin/bids`<br>`GET /api/v1/admin/bids/export` | GET | All platform bids with CSV export. |
| `admin/winners.php`<br>`admin/export-winners.php` | `GET /api/v1/admin/winners`<br>`GET /api/v1/admin/winners/export` | GET | All auction winners with payment status. |
| `admin/fix-winners.php` | Deprecated (Replaced by auto-cron)| - | Manual repair tool rendered obsolete by robust cron engine. |
| `admin/kyc-queue.php`<br>`admin/kyc-review.php`<br>`admin/kyc-action.php` | `GET/PATCH /api/v1/admin/kyc` | GET/PATCH | KYC approval queue, AI analysis view, approve/reject buttons. |
| `admin/kyc-reprocess-ai.php` | `POST /api/v1/admin/kyc/:id/ai-reprocess`| POST | Re-runs Gemini Vision OCR on submitted documents. |
| `admin/kyc-file.php` | `GET /api/v1/admin/kyc/file/:id` | GET | Authenticated endpoint generating short-lived signed URL. |
| `admin/payments.php` | `GET /api/v1/admin/payments` | GET | All gateway transactions and audit log. |
| `admin/escrow.php`<br>`admin/release-escrow.php` | `GET/POST /api/v1/admin/escrow` | GET/POST | Escrow ledger inspection and manual administrative release. |
| `admin/order-proof-review.php`<br>`admin/order-verifications.php` | `GET/POST /api/v1/admin/order-proofs`| GET/POST | Verifies seller shipment receipts and buyer dispute evidence. |
| `admin/payouts.php` | `GET/POST /api/v1/admin/payouts` | GET/POST | Approves and settles seller bank payouts. |
| `admin/disputes.php`<br>`admin/refunds.php` | `GET/POST /api/v1/admin/disputes`| GET/POST | Resolves disputes, enforces refunds, updates ledger. |
| `admin/bid-fraud-alerts.php`<br>`admin/test-fraud.php` | `GET/PATCH /api/v1/admin/fraud-alerts`| GET/PATCH | Shill bidding detection alerts and reviews. |
| `admin/security-alerts.php` | `GET/PATCH /api/v1/admin/security-alerts`| GET/PATCH | Account takeover and brute force security incidents. |
| `admin/activity-log.php` | `GET /api/v1/admin/logs` | GET | Administrative audit trail (`admin_activity_log`). |

---

## L. RECOMMENDED MONOREPO PROJECT STRUCTURE

```
/FINAL-PROJECT
├── apps/
│   ├── api/                   # Node.js + TypeScript REST API (Vercel Serverless Functions)
│   │   ├── api/               # Vercel entry points (/api/index.ts or route handlers)
│   │   ├── src/
│   │   │   ├── config/        # Environment validation (Zod), DB pools, S3/R2 client
│   │   │   ├── controllers/   # Request handlers for auth, auctions, bids, payments, kyc, etc.
│   │   │   ├── services/      # Core business services (BiddingService, EscrowService, AIService)
│   │   │   ├── repositories/  # MySQL query layers with strict parameterized queries
│   │   │   ├── middleware/    # Auth (JWT), role guards, rate limiting, request validation
│   │   │   └── utils/         # Crypto (AES-256 for CNIC), formatters, response envelopes
│   │   ├── package.json
│   │   ├── tsconfig.json
│   │   └── vercel.json        # Vercel function routing, headers, cron schedules
│   ├── web/                   # Existing HTML/CSS/JS frontend (preserved visuals, talking to API)
│   │   ├── assets/            # CSS and script assets
│   │   ├── js/                # Typed API client module (replaces PHP session logic)
│   │   ├── index.html
│   │   ├── auctions.html
│   │   ├── auction-details.html
│   │   ├── checkout.html
│   │   ├── admin/             # Admin HTML views wired to API
│   │   ├── seller/            # Seller portal HTML views wired to API
│   │   └── user/              # Buyer dashboard HTML views wired to API
│   └── mobile/                # React Native + TypeScript app (Expo managed workflow)
│       ├── src/
│       │   ├── navigation/    # Stack & tab navigators (Buyer, Seller, Auth)
│       │   ├── screens/       # Mobile screens matching marketplace features
│       │   ├── components/    # Reusable UI components
│       │   └── services/      # Shared API client & realtime hooks
│       ├── package.json
│       ├── app.json
│       └── tsconfig.json
├── packages/
│   └── shared/                # Shared TypeScript models, DTOs, Zod schemas, constants
│       ├── src/
│       │   ├── types/         # User, Auction, Bid, Order, Payment, KYC types
│       │   ├── schemas/       # Zod validation schemas
│       │   └── constants/     # Roles, statuses, commission rates, error codes
│       ├── package.json
│       └── tsconfig.json
├── db/
│   ├── schema.sql             # Authoritative MySQL schema
│   ├── migrations/            # Non-destructive ALTER/CREATE migration scripts
│   └── seed.sql               # Development seeds
├── docs/                      # Architecture, audit, and deployment guides
└── package.json               # Monorepo root (Turborepo or npm workspaces)
```

---

## M. SCHEMA PATCH SQL (Non-Destructive Fixes)

To fix the critical schema bugs found during this audit, the following non-destructive migration script must be applied:

```sql
-- 1. Fix auctions.auction_status ENUM to include 'completed'
ALTER TABLE `auctions` 
MODIFY COLUMN `auction_status` ENUM(
    'draft',
    'pending',
    'approved',
    'rejected',
    'scheduled',
    'active',
    'ended',
    'completed',
    'sold',
    'unsold',
    'cancelled',
    'relisted'
) NOT NULL DEFAULT 'pending';

-- 2. Drop the conflicting trigger that causes duplicate order creation and fee mismatch
DROP TRIGGER IF EXISTS `trg_winners_auto_order`;

-- 3. Add composite indexes for high-throughput bidding and ending queries
ALTER TABLE `auctions` 
ADD INDEX `idx_status_endtime` (`auction_status`, `end_time`),
ADD INDEX `idx_seller_status` (`seller_id`, `auction_status`);

ALTER TABLE `bids` 
ADD INDEX `idx_auction_amount_time` (`auction_id`, `bid_amount` DESC, `bid_time` ASC);

ALTER TABLE `auto_bids` 
ADD INDEX `idx_auto_bids_lookup` (`auction_id`, `status`, `user_id`, `max_amount`);

-- 4. Harmonize disputes table with payment_disputes
ALTER TABLE `payment_disputes`
ADD COLUMN `dispute_id` INT(11) NULL AFTER `id`;
```
