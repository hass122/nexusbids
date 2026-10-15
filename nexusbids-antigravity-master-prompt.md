# NEXUSBIDS — MASTER PROMPT: AUDIT, MIGRATION & FULL-STACK IMPLEMENTATION

You are a senior full-stack architect and engineer (Node.js/TypeScript, MySQL, React Native, realtime systems, payments/escrow, security, auction platforms).

I am providing a ZIP of my existing project **NexusBids**, a Pakistani online auction marketplace currently built in **PHP + MySQL + HTML/CSS/JS (AJAX, sessions)**.

## 0. MISSION

Take the existing NexusBids PHP project as the **source of truth** and deliver:

1. A complete audit of the existing project.
2. A **migration** of its backend to **Node.js (TypeScript) deployable on Vercel**, keeping the same MySQL data model (with only justified, documented changes).
3. The **existing website frontend (HTML/CSS/JS)** kept visually the same, now talking to the new REST API.
4. A **React Native + TypeScript mobile app** (Android + iOS, one codebase) using the same API.
5. Realtime bidding, AI features, cron automation and production deployment.

This is a **migration and improvement**, not a random rebuild. Preserve every existing feature, the database relationships and the visual identity. Do not remove or fake features.

> NOTE: This prompt intentionally overrides any "stay on PHP" instruction. PHP is being replaced by Node.js. Everything else (MySQL, business rules, UI identity) stays.

---

## 1. TARGET STACK (fixed)

| Part | Technology | Purpose |
|---|---|---|
| Website frontend | HTML + CSS + JavaScript (existing) | Keep design; replace PHP-rendered logic with API calls |
| Backend / API | Node.js + TypeScript (Vercel-compatible) | REST API, business logic |
| Database | MySQL (cloud-hosted) | Users, auctions, bids, orders, payments, etc. |
| Mobile app | React Native + TypeScript | Android + iOS |
| API communication | REST + JSON | Website and app ↔ backend |
| Authentication | JWT (access + refresh) / secure httpOnly cookies | Login/register |
| Realtime bidding | See section 2 (important constraint) | Live bids |
| AI features | Node.js (preferred) or a small Python service | Chatbot, fraud detection, KYC assist, descriptions, recommendations |
| Deployment | Vercel (frontend + API) | |
| DB hosting | Cloud MySQL (e.g. Aiven, TiDB Cloud, PlanetScale-compatible, Railway) | Production DB |

---

## 2. CRITICAL PLATFORM CONSTRAINTS (you MUST design around these)

Vercel serverless functions behave differently from PHP/XAMPP. Handle all of the following explicitly:

1. **WebSockets / Socket.IO do NOT work on Vercel serverless functions.** Do not build the realtime layer on a persistent Socket.IO server inside Vercel. Choose ONE and justify it:
   - A managed realtime service (Ably, Pusher, or Supabase Realtime) triggered from the bid API, **or**
   - A separate small always-on Node realtime server (Railway/Render/Fly.io) with Socket.IO, while the REST API stays on Vercel, **or**
   - Fallback: short-interval polling / SSE for auction status.
   Server remains the only authority on bid validity; realtime only broadcasts results.
2. **Database connections:** serverless opens many short connections. Use a pooled/serverless-friendly MySQL setup (connection reuse, small pool limits, or a proxy/pooler). Bidding must still use **real transactions with row locking** (`SELECT ... FOR UPDATE`).
3. **File uploads:** Vercel's filesystem is read-only/ephemeral. Replace `/uploads` with object storage (Vercel Blob, S3-compatible, or Cloudinary). **KYC documents must live in PRIVATE storage** and be served only through authenticated, authorized, short-lived signed URLs.
4. **Cron jobs:** replace `/cron` and `process-ended-auctions.php` with **Vercel Cron Jobs** (protected by a secret header). Jobs must be idempotent: finalize ended auctions, auto-release escrow, send auction-ending reminders. Also finalize lazily when an expired auction is read, so nothing stays "active" forever.
5. **Function limits:** keep handlers short; push slow work (AI calls, email) to background-safe patterns and respect timeouts.
6. **Secrets:** all in environment variables. Never in frontend or mobile code. Provide `.env.example` with masked placeholders.

---

## 3. RULE: ANALYZE BEFORE MODIFYING

Before changing or writing code:

1. Extract and inspect the full ZIP.
2. Read every PHP file (~167), all CSS (6), all JS (5), config, `.env` handling and the migration SQL.
3. Map: auth, roles, Admin/Seller/Buyer panels, auctions, bidding, auto-bidding, payments, KYC, AI modules, notifications, security, cron, AJAX endpoints, uploads and inter-module relationships.
4. Do not assume a feature works because the file exists. Read the implementation and verify it (e.g. does `auto-bid.php` truly work? do payment files really update status? is a real AI provider actually configured?).
5. If PHP references a table/column that does not exist in the SQL schema, list it explicitly BEFORE migrating.
6. Never reproduce secret values. For any credential found, give only the variable name, a masked value and a recommendation to move it to env vars.

Existing directories: `/admin /auth /seller /user /payments /includes /ajax /cron /config /assets /uploads`
Key root pages: `index.php, auctions.php, auction-details.php, categories.php, about.php, place-bid.php, set-auto-bid.php, add-watchlist.php, remove-watchlist.php, checkout.php, order-confirm.php, notifications.php, ai-chat-api.php, process-ended-auctions.php`

---

## 4. REQUIRED AUDIT REPORT (deliver FIRST, wait for no one, then proceed)

A. Architecture (current PHP structure)
B. Buyer flow
C. Seller flow
D. Admin flow
E. Database: tables, PKs, FKs, indexes, status fields, missing/legacy columns
F. Feature status table: Working / Partially working / Broken / Missing / Unclear
G. Security issues
H. UI/UX issues
I. Actual bugs (code/schema)
J. Priority: Critical (security, data loss, payment, bidding) / High / Medium / Low
K. **Migration map:** every PHP file/endpoint → the new Node API route (or frontend page), with notes

---

## 5. NEW PROJECT STRUCTURE (propose, then follow)

Use a clear monorepo, for example:

```
/apps/web        existing HTML/CSS/JS frontend (kept, wired to API)
/apps/api        Node.js + TypeScript API (Vercel functions)
/apps/mobile     React Native + TypeScript (Expo or bare, justify)
/packages/shared types, validation schemas, constants, API client
/db              schema.sql, migrations, seed
/docs            audit, API docs, deployment guides
```

API conventions: versioned routes (`/api/v1/...`), JSON only, consistent response envelope `{ success, data, error }`, input validation (e.g. Zod) on every endpoint, OpenAPI/Markdown API docs, pagination on all lists.

---

## 6. FEATURES TO PRESERVE / IMPLEMENT (all must be REAL, DB-backed, not visual-only)

### Buyer
Register, login, Google login, email verification, forgot/reset password, profile, KYC, browse/search/filter auctions, auction details, place bid, auto-bid, watchlist, bid history, winning/outbid views, won auctions, checkout, payments, orders, shipment tracking, confirm receipt, reviews, report problem, notifications, AI chatbot.

### Seller
Dashboard, profile, seller KYC (required before creating auctions), create/save draft/edit/duplicate/relist/cancel auction, my auctions, analytics, bid activity, orders, ship order, earnings, payouts, transactions, escrow, disputes, reviews, notifications, security, AI-generated description.

### Admin
Dashboard (real counts), users + status/roles, categories CRUD + activate, auctions approve/reject/status, bids + export, winners + export + fix, payments, refunds, orders + proof verification, payouts, escrow + release, ledger, transactions, KYC queue/review/AI reprocess/approve/reject, security alerts, bid-fraud alerts, disputes, activity logs, reminders.

---

## 7. CORE BUSINESS LOGIC (must be correct and concurrency-safe)

### 7.1 Bidding (`POST /api/v1/auctions/:id/bids`)
Flow: authenticate → role/ownership (seller cannot bid own auction) → buyer KYC check → CSRF/origin protection (if cookies) → rate limit → **DB transaction** → `SELECT auction FOR UPDATE` → validate active + started + not expired → validate min bid and increment → insert bid → update current bid/highest bidder → process auto-bids → create notifications (outbid, new bid for seller) → commit → publish realtime event → return result. Anti-sniping extension only if it already exists in the PHP project.

### 7.2 Auto-bidding
User sets max; system stores it; on every new bid compute next valid bid; never exceed user max; no infinite loops (bounded resolution between competing auto-bids); transaction-safe; bid history marks automatic bids.

### 7.3 Auction ending & winner
With bids: select highest valid bidder → create/update winner → mark completed → create order/payment flow → notify winner and seller → block further bids. Without bids: mark ended, no winner. Idempotent (running twice must not duplicate winners/orders).

### 7.4 Payments
Methods in the existing project: bank transfer, COD (where applicable), Easypaisa, JazzCash (+ callbacks). Lifecycle: `Pending → Processing → Paid`, `Paid → Refunded`. **Never trust client-side payment status.** Verify gateway callbacks server-side (signature/hash validation) and make them idempotent. Link payment ↔ user ↔ winner ↔ auction ↔ order ↔ transaction ↔ payment_history. Use sandbox keys from env; no real keys in code.

### 7.5 Escrow & payouts
`Buyer pays → escrow held → seller ships → buyer receives/confirms → escrow released → seller payout.` Handle dispute, refund, partial release where supported, admin release, auto-release (cron), payout and ledger entries. All money movements must be in transactions with a ledger trail.

### 7.6 Orders & shipping
Order created from winner; no duplicate orders; buyer/seller see only their own. Shipping address (province, city, postal code), shipment, tracking number, status, estimated delivery, delivered date, tracking history (`shipping_addresses`, `shipments`, `shipment_tracking`).

### 7.7 Watchlist
Login required, auction exists, no duplicates, add/remove, accurate counts, UI state sync (web + mobile).

### 7.8 Notifications
New bid, outbid, auction approved/rejected/ending/won, payment, order, shipping, delivery, KYC status, security alert, dispute, payout. Accurate unread counts. Optional push notifications on mobile (Expo/FCM) after core is stable.

### 7.9 KYC
Buyer + seller KYC: submit, upload, pending/approved/rejected, resubmission, admin review, AI-assisted verification, private file access control. **Never expose KYC documents publicly.**

### 7.10 Reviews & disputes
Reviews only when business rules allow (completed/delivered order, once per order). Disputes linked to the correct order/auction/user.

---

## 8. AI FEATURES

First identify exactly what the PHP code implements (provider, endpoint, keys, prompts). Then re-implement in Node.js (or a small Python service if justified):

- **Chatbot:** sessions, history, clear chat, rate limiting, auth-aware context, no secrets in frontend, calls go through backend only.
- **Auction description generator** for sellers.
- **Bid fraud detection:** analyze suspicious patterns → create `bid_fraud_alerts`.
- **AI KYC assist:** document/identity assistance with admin final decision.
- **AI security:** analyze suspicious activity → `security_alerts`.
- **Recommendations** (new): suggest auctions based on bids/watchlist/categories.

Do not claim an AI feature works unless the code actually calls a real provider. Provide graceful fallback when the AI provider is unavailable.

---

## 9. AUTHENTICATION & AUTHORIZATION

- Passwords: bcrypt/argon2. **Migration plan for existing PHP `password_hash` (bcrypt) hashes** so current users can still log in.
- JWT access token (short-lived) + refresh token (rotating, revocable) or secure httpOnly SameSite cookies for web; secure token storage on mobile (Keychain/Keystore).
- Email verification, forgot/reset password, Google OAuth (validate `state`, verify ID token server-side), role-based redirects.
- **Roles enforced server-side on every endpoint** (middleware). Never rely on frontend checks.
- Admin routes separate and strictly protected; log admin actions to `admin_activity_log`.

---

## 10. SECURITY AUDIT & HARDENING

Check and fix: SQL injection (parameterized queries only), XSS (output encoding/sanitization), CSRF (for cookie auth), session/token fixation & hijacking, broken authorization / IDOR, file upload (MIME + extension + size + content sniffing, random names, private bucket), path traversal, rate limiting (login, bids, AI, OTP/reset), brute force, OAuth validation, payment callback verification, sensitive data exposure, error handling (no raw SQL errors to users, log server-side), security headers (CSP, HSTS, etc.), CORS allow-list, dependency hygiene. Never put API keys in frontend/mobile code.

---

## 11. DATABASE

Do NOT blindly redesign. Reuse the existing MySQL schema:
`users, categories, auctions, bids, auto_bids, watchlist, winners, orders, payments, payment_history, shipping_addresses, shipments, shipment_tracking, escrow_holds, payouts, transactions, reviews, disputes, notifications, kyc_verifications, security_alerts, bid_fraud_alerts, admin_activity_log`

Tasks: document relationships; list missing columns/tables referenced by code; add missing indexes (auction end time, status, bids by auction/user, etc.); provide **non-destructive** `ALTER/CREATE` migrations; explain any destructive change before making it; provide a data-migration/seed path from the current local MySQL to the cloud MySQL; use a migration tool (e.g. Prisma, Drizzle, Knex) only if it fits the existing schema without forcing a redesign (justify the choice).

---

## 12. WEBSITE FRONTEND

- Keep the existing NexusBids look and feel (dark/light, cards, badges, Pakistani marketplace feel). Do not destroy working UI.
- Replace PHP-rendered/AJAX parts with calls to the new API through one shared API client module.
- Add: toast notifications, loading/empty/error states, confirmation dialogs, live countdown, live current bid, mobile sidebar, responsive tables.
- Must work on desktop, laptop, tablet, mobile. Special attention: navbar, auction cards, auction detail + bid form, seller/admin dashboards, tables, payment & KYC forms, modals.
- If a server-rendered feel/SEO matters for public pages (home, auctions, auction details), propose a solution (prerender or lightweight SSR) without switching to a heavy framework unless justified.

---

## 13. MOBILE APP (React Native + TypeScript)

- Choose Expo or bare RN and justify; use React Navigation, a typed API client from `/packages/shared`, secure token storage, and the same business rules (no logic duplicated client-side beyond UX validation).
- Screens: Splash, Login/Register/Google, Email verify, Forgot password, Home, Auctions list + filters + search, Auction details + live bid, Place bid, Auto-bid, Watchlist, My bids, Won auctions, Checkout + payment, Orders + tracking, Notifications, Profile, KYC (camera/gallery upload), AI chatbot, Seller area (my auctions, create auction with image upload, orders, ship order, earnings), basic role-aware navigation.
- Realtime via the same realtime layer chosen in section 2.
- Handle offline/poor network, loading and error states, deep links, and push notifications (phase-late).
- Provide Android build instructions (APK/AAB) and iOS notes.

---

## 14. ERROR HANDLING & QUALITY

- Consistent error codes, user-friendly messages, structured logging, no stack traces to clients, valid JSON always.
- TypeScript strict mode, shared types, reusable services/repositories, validation schemas, ESLint/Prettier, small focused modules.
- Automated tests where valuable: unit tests for bid/auto-bid/winner/escrow logic, integration tests for critical API flows.
- No fake buttons: Place Bid, Pay Now, Approve KYC, Ship Order, Release Escrow, etc. must really update the database and downstream state.

---

## 15. IMPLEMENTATION PHASES (follow in order; complete and verify each before the next)

1. **Phase 0 — Audit & migration map** (section 4) + schema compatibility report.
2. **Phase 1 — Core stability:** project scaffold, DB layer, auth, sessions/JWT, authorization, auctions, bidding, auto-bidding, auction ending (cron).
3. **Phase 2 — Marketplace:** watchlist, buyer dashboard, seller dashboard, orders, reviews, notifications.
4. **Phase 3 — KYC & security:** buyer/seller KYC, private storage, AI KYC, fraud detection, security alerts.
5. **Phase 4 — Financial:** checkout, payments (+callbacks), payment history, escrow, refunds, payouts, ledger.
6. **Phase 5 — Shipping:** addresses, shipments, tracking, delivery confirmation.
7. **Phase 6 — AI:** chatbot, description generator, fraud, KYC, security, recommendations.
8. **Phase 7 — Admin panel** fully wired to API.
9. **Phase 8 — Realtime** layer (per section 2) on web + mobile.
10. **Phase 9 — Website UI/UX polish & responsiveness.**
11. **Phase 10 — Mobile app** screens and flows.
12. **Phase 11 — Deployment:** Vercel setup (`vercel.json`, env vars, cron), cloud MySQL, storage, domains, CI notes.
13. **Phase 12 — Final QA** of every journey below.

Rule: do not fix one file and leave dependent code broken. Update all related modules consistently.

---

## 16. FLOWS TO TEST (logically + with tests where possible)

**Buyer:** Register → verify email → login → KYC → approved → browse → open auction → watchlist → bid → auto-bid → outbid → bid again → win → checkout → pay → order → track shipment → confirm receipt → review seller.

**Seller:** Register/login → seller KYC → approved → create auction → draft → edit → submit → admin approval → live → receive bids → ends → winner → buyer pays → seller ships → buyer receives → escrow released → payout.

**Admin:** Login → dashboard → users → categories → KYC → auctions → monitor bids → fraud → orders → payments → escrow → disputes → refunds → payouts → security logs.

**Concurrency tests:** two users bid at the same moment; two auto-bids competing; cron running twice; duplicate payment callback.

---

## 17. DEPLOYMENT DELIVERABLES

Provide clear step-by-step guides for:
- **Local development** (Node, local MySQL/XAMPP MySQL, env setup, running web + API + mobile)
- **Production** (Vercel project setup, cloud MySQL, object storage, realtime service, cron, environment variables, domain/HTTPS, payment gateway sandbox → live checklist)
- Rollback and backup strategy for the database.

---

## 18. SECRETS RULE

The ZIP may contain `.env`, API keys, OAuth, DB, mail or payment credentials. Never print real values. Report only variable name + masked value, recommend rotating anything exposed, and move all of them to environment variables. Nothing sensitive in frontend or mobile bundles.

---

## 19. FINAL OUTPUT REQUIRED

1. Architecture summary (old vs new)
2. Complete feature list with status
3. Database relationship summary
4. Bugs found
5. Security issues found
6. Missing features
7. Fixed/migrated features
8. Files created/modified — for each: `File / What changed / Why / Impact`
9. Database changes (`ALTER/CREATE/INDEX` SQL, destructive changes explicitly flagged)
10. Environment variable list (names only)
11. Testing checklist (what was tested and results)
12. Deployment guide
13. Known limitations and next steps

## 20. WORKING STYLE

- Explain simply, code professionally.
- For every fix/feature: explain the issue → reason → implementation → what changed → how to test.
- Work phase by phase; at the end of each phase, summarize what is done, what was tested, and what is next.
- If something is ambiguous or a PHP behavior is unclear, state your assumption explicitly and continue with the safest option, rather than silently guessing.
- **Most important:** do not sacrifice any existing functionality while migrating. The goal is a stable, secure, production-ready NexusBids on Node.js + MySQL + React Native — built by understanding and carrying over what already exists.
