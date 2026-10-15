<?php

session_start();

if (!isset($_SESSION["user_id"])) {
  header("Location: ../auth/login.php");
  exit();
}

if ($_SESSION["role"] !== "user") {
  header("Location: ../auth/login.php");
  exit();
}

require_once "../config/database.php";
require_once "../includes/notifications.php";

date_default_timezone_set('Asia/Karachi');

$user_id = intval($_SESSION["user_id"]);

/* ---------- Seller info (for navbar) ---------- */
$user_stmt = $conn->prepare("SELECT full_name, avatar, kyc_status, kyc_level FROM users WHERE user_id = ?");
$user_stmt->bind_param("i", $user_id);
$user_stmt->execute();
$user_info = $user_stmt->get_result()->fetch_assoc();
$user_stmt->close();

$full_name = $user_info["full_name"] ?? "User";
$avatar = $user_info["avatar"] ?? "";
$initials = strtoupper(substr(preg_replace('/\s+/', '', $full_name), 0, 2));
$unread_notifications = nb_unread_count($conn, $user_id);
// ─── KYC status for banner ───
require_once "../includes/kyc-helpers.php";
$user_kyc_status = $user_info['kyc_status'] ?? 'unverified';

/* ---------- Active Bids ---------- */
$active_bids_sql = "
    SELECT COUNT(DISTINCT b.auction_id) AS total
    FROM bids b
    INNER JOIN auctions a ON b.auction_id = a.auction_id
    WHERE b.bidder_id = ?
    AND a.auction_status = 'active'
";
$stmt = $conn->prepare($active_bids_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$active_bids = $stmt->get_result()->fetch_assoc()["total"] ?? 0;
$stmt->close();

/* ---------- Watchlist ---------- */
$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM watchlist WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$watchlist_count = $stmt->get_result()->fetch_assoc()["total"] ?? 0;
$stmt->close();

/* ---------- Won Auctions ---------- */
$stmt = $conn->prepare("SELECT COUNT(*) AS total FROM winners WHERE user_id = ?");
$stmt->bind_param("i", $user_id);
$stmt->execute();
$won_auctions = $stmt->get_result()->fetch_assoc()["total"] ?? 0;
$stmt->close();

/* ---------- Pending Payments ---------- */
$pending_payments = 0;
try {
  $stmt = $conn->prepare("
        SELECT COUNT(*) AS total FROM orders
        WHERE buyer_id = ? AND payment_status IN ('unpaid', 'failed', 'pending')
        AND order_status NOT IN ('cancelled', 'refunded')
    ");
  $stmt->bind_param("i", $user_id);
  $stmt->execute();
  $pending_payments = intval($stmt->get_result()->fetch_assoc()["total"] ?? 0);
  $stmt->close();
} catch (Throwable $e) {
}

/* ---------- Recent Bids ---------- */
$recent_bids_sql = "
    SELECT
        b.bid_amount, b.bid_time,
        a.title, a.current_bid, a.auction_status, a.auction_id
    FROM bids b
    INNER JOIN auctions a ON b.auction_id = a.auction_id
    WHERE b.bidder_id = ?
    ORDER BY b.bid_time DESC
    LIMIT 6
";
$stmt = $conn->prepare($recent_bids_sql);
$stmt->bind_param("i", $user_id);
$stmt->execute();
$recent_bids = $stmt->get_result();
$recent_bids_data = $recent_bids ? $recent_bids->fetch_all(MYSQLI_ASSOC) : [];

/* Winning count — proper calculation */
$winning_count = 0;
foreach ($recent_bids_data as $b) {
  if ((float) $b['current_bid'] <= (float) $b['bid_amount']) {
    $winning_count++;
  }
}

?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>Dashboard | NexusBids</title>
  <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
  <meta name="description" content="Your NexusBids dashboard — monitor bids, watchlist, and winnings.">

  <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          fontFamily: {sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif']},
          animation: {
            'fade-up': 'fadeUp 0.6s ease-out forwards',
            'pulse-soft': 'pulseSoft 2s ease-in-out infinite',
            'float': 'float 6s ease-in-out infinite',
          },
          keyframes: {
            fadeUp: {'0%': {opacity: '0', transform: 'translateY(16px)'}, '100%': {opacity: '1', transform: 'translateY(0)'}},
            pulseSoft: {'0%, 100%': {opacity: '1'}, '50%': {opacity: '0.5'}},
            float: {'0%, 100%': {transform: 'translateY(0)'}, '50%': {transform: 'translateY(-10px)'}},
          }
        }
      }
    };
  </script>
  <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
    rel="stylesheet">
  <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">

  <style>
    html {
      scroll-behavior: smooth;
    }

    body {
      transition: background-color .3s ease, color .3s ease;
      font-feature-settings: 'cv02', 'cv03', 'cv04', 'cv11';
    }

    .glass {
      backdrop-filter: blur(16px) saturate(180%);
      -webkit-backdrop-filter: blur(16px) saturate(180%);
    }

    .gradient-text {
      background-clip: text;
      -webkit-background-clip: text;
      color: transparent;
    }

    .noise-overlay::before {
      content: '';
      position: absolute;
      inset: 0;
      background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.03'/%3E%3C/svg%3E");
      pointer-events: none;
      z-index: 1;
    }

    ::-webkit-scrollbar {
      width: 8px;
      height: 8px;
    }

    ::-webkit-scrollbar-track {
      background: transparent;
    }

    ::-webkit-scrollbar-thumb {
      background: #cbd5e1;
      border-radius: 4px;
    }

    .dark ::-webkit-scrollbar-thumb {
      background: #334155;
    }

    @media (prefers-reduced-motion: reduce) {

      *,
      *::before,
      *::after {
        animation-duration: .01ms !important;
        transition-duration: .01ms !important;
      }
    }

    .stat-card {
      position: relative;
      overflow: hidden;
      transition: transform .3s cubic-bezier(.4, 0, .2, 1), border-color .3s ease, box-shadow .3s ease;
    }

    .stat-card:hover {
      transform: translateY(-4px);
    }
  </style>
</head>

<body
  class="bg-gray-50 text-gray-900 dark:bg-slate-950 dark:text-gray-100 font-sans antialiased selection:bg-indigo-500 selection:text-white min-h-screen flex flex-col">

  <!-- Top Utility Bar -->
  <div class="bg-gray-950 text-gray-400 text-xs py-2.5 px-4 sm:px-8 border-b border-gray-800/50 relative z-40">
    <div class="max-w-7xl mx-auto flex flex-col sm:flex-row justify-between items-center gap-2">
      <div class="flex items-center space-x-6">
        <span class="flex items-center gap-2"><i class="fa-solid fa-location-dot text-indigo-400"></i> Pakistan</span>
        <span class="hidden sm:flex items-center gap-2"><i class="fa-solid fa-phone text-indigo-400"></i> +92 300
          1234567</span>
      </div>
      <div class="flex items-center space-x-6">
        <a href="../about.php" class="hover:text-white transition-colors"><i
            class="fa-regular fa-circle-question text-indigo-400"></i> Help Center</a>
        <a href="../about.php#why-nexusbids" class="hover:text-white transition-colors"><i
            class="fa-solid fa-shield-halved text-indigo-400"></i> Secure Bidding</a>
      </div>
    </div>
  </div>

  <!-- Main Navbar -->
  <header
    class="sticky top-0 z-50 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
    <div class="max-w-7xl mx-auto px-4 sm:px-8 h-20 flex items-center justify-between gap-4">
      <a class="flex items-center gap-2.5 text-2xl font-black tracking-tight shrink-0 group" href="../index.php">
        <span
          class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg group-hover:scale-105 transition-all duration-300">
          <i class="fa-solid fa-gavel text-lg"></i>
        </span>
        Nexus<span class="text-indigo-600 dark:text-indigo-400">Bids</span>
      </a>

      <nav class="hidden lg:flex items-center gap-7 text-sm font-medium">
        <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 transition" href="../index.php">Home</a>
        <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 transition" href="../auctions.php">Auctions</a>
        <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 transition"
          href="../categories.php">Categories</a>
        <a class="relative text-indigo-600 dark:text-indigo-400 font-semibold after:absolute after:-bottom-1 after:left-0 after:w-full after:h-0.5 after:bg-indigo-600 dark:after:bg-indigo-400 after:rounded-full"
          href="dashboard.php">Dashboard</a>
        <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 transition" href="../about.php">About</a>
      </nav>

      <div class="flex items-center gap-2 sm:gap-3">
        <button aria-label="Toggle Theme" id="themeToggle"
          class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:scale-105 transition">
          <i class="fa-solid fa-moon dark:hidden"></i><i class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
        </button>

        <a href="../notifications.php"
          class="relative w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:scale-105 transition">
          <i class="fa-regular fa-bell"></i>
          <?php if ($unread_notifications > 0): ?>
            <span
              class="absolute top-1.5 right-1.5 w-2.5 h-2.5 rounded-full bg-rose-500 border-2 border-white dark:border-gray-800 animate-pulse-soft"></span>
          <?php endif; ?>
        </a>

        <div class="flex items-center gap-3 pl-3 border-l border-gray-200 dark:border-gray-800">
          <div class="relative">
            <?php if (!empty($avatar)): ?>
              <img src="../uploads/avatars/<?php echo htmlspecialchars($avatar); ?>"
                class="w-10 h-10 rounded-full object-cover ring-2 ring-white dark:ring-slate-900" alt="">
            <?php else: ?>
              <div
                class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-xs flex items-center justify-center shadow-lg ring-2 ring-white dark:ring-slate-900">
                <?php echo htmlspecialchars($initials); ?>
              </div>
            <?php endif; ?>
            <span
              class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full bg-emerald-500 border-2 border-white dark:border-slate-900"></span>
          </div>
          <div class="hidden sm:block text-left">
            <div class="text-sm font-bold leading-tight truncate max-w-32"><?php echo htmlspecialchars($full_name); ?>
            </div>
            <div class="text-[11px] text-emerald-600 dark:text-emerald-400 font-medium flex items-center gap-1">
              <i class="fa-solid fa-circle-check text-[8px]"></i> Verified Bidder
            </div>
          </div>
        </div>

        <a class="hidden sm:inline-flex px-4 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition"
          href="../auth/logout.php">
          <i class="fa-solid fa-right-from-bracket mr-1.5"></i> Logout
        </a>
      </div>
    </div>

    <!-- Mobile menu -->
    <div class="lg:hidden border-t border-gray-100 dark:border-gray-800 px-4 py-3 overflow-x-auto">
      <div class="flex items-center gap-5 text-sm whitespace-nowrap">
        <a class="text-gray-600 dark:text-gray-300" href="../index.php">Home</a>
        <a class="text-gray-600 dark:text-gray-300" href="../auctions.php">Auctions</a>
        <a class="text-gray-600 dark:text-gray-300" href="../categories.php">Categories</a>
        <a class="text-indigo-600 dark:text-indigo-400 font-semibold" href="dashboard.php">Dashboard</a>
        <a class="text-gray-600 dark:text-gray-300" href="../about.php">About</a>
      </div>
    </div>
  </header>


  <main class="flex-grow max-w-7xl mx-auto w-full px-4 sm:px-8 py-8 space-y-8">
    <?php if ($user_kyc_status !== 'approved'): ?>
      <?php $kyc_meta = nb_kyc_status_meta($user_kyc_status); ?>
      <section
        class="rounded-3xl border <?= $kyc_meta['border'] ?> <?= $kyc_meta['bg'] ?> p-5 sm:p-6 flex flex-col sm:flex-row items-start sm:items-center gap-4 animate-fade-up">
        <div
          class="w-12 h-12 rounded-2xl <?= $kyc_meta['accent'] ?> text-white flex items-center justify-center text-xl shrink-0 shadow-lg">
          <i class="fa-solid <?= $kyc_meta['icon'] ?>"></i>
        </div>
        <div class="flex-1 min-w-0">
          <h3 class="font-black text-gray-900 dark:text-white flex items-center gap-2">
            <?php if ($user_kyc_status === 'unverified'): ?>
              Verify your identity to unlock full access
            <?php elseif ($user_kyc_status === 'pending'): ?>
              KYC under review
            <?php else: ?>
              KYC was not approved
            <?php endif; ?>
          </h3>
          <p class="text-xs text-gray-600 dark:text-gray-400 mt-1 leading-relaxed">
            <?php if ($user_kyc_status === 'unverified'): ?>
              Complete a 2-minute verification to unlock higher bidding limits, faster payouts, and a trusted badge.
            <?php elseif ($user_kyc_status === 'pending'): ?>
              We're reviewing your documents. Typically 1-2 business days.
            <?php else: ?>
              Please retry with corrected documents to continue.
            <?php endif; ?>
          </p>
        </div>
        <a href="<?= in_array($user_kyc_status, ['unverified', 'rejected'], true) ? 'kyc.php' : 'kyc-status.php' ?>"
          class="px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-bold whitespace-nowrap shadow-lg shadow-indigo-600/30 transition">
          <?php if ($user_kyc_status === 'unverified'): ?>
            <i class="fa-solid fa-id-card mr-1.5"></i> Verify Now
          <?php elseif ($user_kyc_status === 'rejected'): ?>
            <i class="fa-solid fa-rotate mr-1.5"></i> Retry KYC
          <?php else: ?>
            View Status <i class="fa-solid fa-arrow-right ml-1.5"></i>
          <?php endif; ?>
        </a>
      </section>
    <?php endif; ?>

    <!-- Welcome Hero -->
    <section
      class="relative overflow-hidden rounded-3xl bg-slate-950 text-white p-6 sm:p-8 lg:p-10 shadow-2xl border border-gray-800 noise-overlay animate-fade-up">
      <div
        class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-indigo-900/40 via-slate-950 to-slate-950">
      </div>
      <div
        class="absolute -right-16 -top-16 w-80 h-80 bg-indigo-600/20 rounded-full blur-3xl pointer-events-none animate-float">
      </div>
      <div class="absolute left-1/4 bottom-0 w-64 h-64 bg-violet-600/10 rounded-full blur-3xl pointer-events-none">
      </div>

      <div class="relative z-10 flex flex-col lg:flex-row lg:items-center justify-between gap-6">
        <div class="space-y-3">
          <div class="flex flex-wrap items-center gap-2">
            <span
              class="inline-flex items-center gap-2 px-3 py-1.5 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-[11px] font-bold uppercase tracking-wider">
              <span class="relative flex h-2 w-2">
                <span
                  class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                <span class="relative inline-flex rounded-full h-2 w-2 bg-emerald-400"></span>
              </span>
              Account Active
            </span>
            <span class="text-[11px] text-gray-400 font-mono">ID #<?php echo $user_id; ?></span>
          </div>
          <h1 class="text-2xl sm:text-3xl lg:text-4xl font-black tracking-tight leading-tight">
            Welcome back, <span
              class="gradient-text bg-gradient-to-r from-blue-400 via-indigo-400 to-violet-400"><?php echo htmlspecialchars(explode(' ', $full_name)[0]); ?></span>
          </h1>
          <p class="text-gray-300 text-sm max-w-xl leading-relaxed">Monitor your live auction bids, review recent
            activity, and keep track of your winnings and watchlist.</p>
        </div>

        <div class="flex flex-wrap items-center gap-3">
          <a class="group px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold transition hover:-translate-y-0.5 shadow-lg shadow-indigo-600/30 flex items-center gap-2"
            href="../auctions.php">
            <i class="fa-solid fa-magnifying-glass"></i> Browse Auctions
            <i class="fa-solid fa-arrow-right text-xs group-hover:translate-x-1 transition-transform"></i>
          </a>
          <a class="px-5 py-3 rounded-xl bg-gray-800/80 hover:bg-gray-700/80 text-white text-sm font-semibold border border-gray-700 transition hover:-translate-y-0.5 flex items-center gap-2"
            href="profile.php">
            <i class="fa-solid fa-user"></i> My Profile
          </a>
        </div>
      </div>
    </section>

    <!-- Stats Grid -->
    <section class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4 animate-fade-up">
      <div
        class="stat-card group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-indigo-500 hover:shadow-lg text-indigo-500">
        <div class="flex items-center justify-between">
          <span class="text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Active
            Bids</span>
          <span
            class="w-10 h-10 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-sm group-hover:scale-110 transition">
            <i class="fa-solid fa-bolt"></i>
          </span>
        </div>
        <div class="mt-4 flex items-baseline gap-2">
          <span class="text-3xl font-black tabular-nums"><?php echo (int) $active_bids; ?></span>
          <span class="text-xs font-medium text-gray-500">items bidding</span>
        </div>
        <p class="mt-3 text-xs text-gray-500 flex items-center gap-1.5">
          <span class="w-1.5 h-1.5 rounded-full bg-emerald-500"></span> Auctions currently live
        </p>
      </div>

      <div
        class="stat-card group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-blue-500 hover:shadow-lg text-blue-500">
        <div class="flex items-center justify-between">
          <span class="text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Recent
            Bids</span>
          <span
            class="w-10 h-10 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center text-sm group-hover:scale-110 transition">
            <i class="fa-solid fa-gavel"></i>
          </span>
        </div>
        <div class="mt-4 flex items-baseline gap-2">
          <span class="text-3xl font-black tabular-nums"><?php echo count($recent_bids_data); ?></span>
          <span class="text-xs font-medium text-gray-500">shown</span>
        </div>
        <p class="mt-3 text-xs text-gray-500 flex items-center gap-1.5">
          <span class="text-emerald-600 dark:text-emerald-400 font-semibold"><?php echo $winning_count; ?>
            winning</span>
          <span class="text-gray-400">·</span>
          <span
            class="text-rose-600 dark:text-rose-400 font-semibold"><?php echo count($recent_bids_data) - $winning_count; ?>
            outbid</span>
        </p>
      </div>

      <div
        class="stat-card group bg-white dark:bg-slate-900 rounded-2xl p-5 border border-gray-200 dark:border-gray-800 hover:border-amber-500 hover:shadow-lg text-amber-500">
        <div class="flex items-center justify-between">
          <span class="text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Auctions
            Won</span>
          <span
            class="w-10 h-10 rounded-xl bg-amber-500/10 text-amber-600 dark:text-amber-400 flex items-center justify-center text-sm group-hover:scale-110 transition">
            <i class="fa-solid fa-trophy"></i>
          </span>
        </div>
        <div class="mt-4 flex items-baseline gap-2">
          <span class="text-3xl font-black tabular-nums"><?php echo (int) $won_auctions; ?></span>
          <span class="text-xs font-medium text-gray-500">total won</span>
        </div>
        <p class="mt-3 text-xs text-gray-500 flex items-center gap-1.5">
          <i class="fa-solid fa-award text-amber-500"></i> Lifetime winning record
        </p>
      </div>

      <div
        class="stat-card group bg-white dark:bg-slate-900 rounded-2xl p-5 border <?php echo $pending_payments > 0 ? 'border-rose-500/40' : 'border-gray-200 dark:border-gray-800'; ?> hover:border-rose-500 hover:shadow-lg text-rose-500">
        <div class="flex items-center justify-between">
          <span class="text-[11px] font-bold text-gray-500 dark:text-gray-400 uppercase tracking-wider">Pending
            Payment</span>
          <span
            class="w-10 h-10 rounded-xl bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center text-sm group-hover:scale-110 transition">
            <i class="fa-solid fa-credit-card"></i>
          </span>
        </div>
        <div class="mt-4 flex items-baseline gap-2">
          <span
            class="text-3xl font-black tabular-nums <?php echo $pending_payments > 0 ? 'text-rose-600 dark:text-rose-400' : ''; ?>"><?php echo (int) $pending_payments; ?></span>
          <span class="text-xs font-medium text-gray-500">orders</span>
        </div>
        <?php if ($pending_payments > 0): ?>
          <a href="orders.php"
            class="mt-3 inline-flex items-center gap-1 text-xs text-rose-600 dark:text-rose-400 font-semibold hover:underline">
            Pay Now
            <i class="fa-solid fa-arrow-right text-[10px]"></i>
          </a>
        <?php else: ?>
          <p class="mt-3 text-xs text-gray-500 flex items-center gap-1.5">
            <i class="fa-solid fa-circle-check text-emerald-500"></i> All settled
          </p>
        <?php endif; ?>
      </div>
    </section>

    <!-- Main Content Grid -->
    <div class="grid grid-cols-1 xl:grid-cols-12 gap-8 items-start">

      <!-- Left: Recent Bids -->
      <div class="xl:col-span-8 space-y-6 animate-fade-up">
        <div class="border-b border-gray-200 dark:border-gray-800 flex items-center gap-1 overflow-x-auto">
          <a class="relative px-4 py-3 text-indigo-600 dark:text-indigo-400 font-semibold flex items-center gap-2 whitespace-nowrap after:absolute after:bottom-0 after:left-0 after:w-full after:h-0.5 after:bg-indigo-600 dark:after:bg-indigo-400"
            href="dashboard.php">
            <i class="fa-solid fa-clock-rotate-left text-xs"></i>
            Recent Bids
            <span
              class="text-[10px] px-2 py-0.5 rounded-full bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 font-bold"><?php echo count($recent_bids_data); ?></span>
          </a>
          <a class="px-4 py-3 text-gray-500 dark:text-gray-400 hover:text-indigo-600 font-medium whitespace-nowrap transition"
            href="my-bids.php">My Bids</a>
          <a class="px-4 py-3 text-gray-500 dark:text-gray-400 hover:text-indigo-600 font-medium whitespace-nowrap transition"
            href="won-auctions.php">
            Won History
            <span
              class="text-[10px] px-2 py-0.5 rounded-full bg-gray-100 dark:bg-slate-800 text-gray-600 dark:text-gray-400 font-bold ml-1"><?php echo (int) $won_auctions; ?></span>
          </a>
          <a class="px-4 py-3 text-gray-500 dark:text-gray-400 hover:text-indigo-600 font-medium whitespace-nowrap transition"
            href="watchlist.php">
            Watchlist
            <span
              class="text-[10px] px-2 py-0.5 rounded-full bg-gray-100 dark:bg-slate-800 text-gray-600 dark:text-gray-400 font-bold ml-1"><?php echo (int) $watchlist_count; ?></span>
          </a>
        </div>

        <div
          class="bg-white dark:bg-slate-900 p-4 rounded-2xl border border-gray-200 dark:border-gray-800 flex flex-col sm:flex-row gap-3 items-center justify-between">
          <div class="relative w-full sm:w-80">
            <i class="fa-solid fa-magnifying-glass absolute left-3 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
            <input id="bidSearch"
              class="w-full pl-9 pr-4 py-2.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500"
              placeholder="Search my bids..." type="text" autocomplete="off">
          </div>
          <a href="my-bids.php"
            class="w-full sm:w-auto text-center px-5 py-2.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-xs font-semibold transition flex items-center justify-center gap-2">
            View All My Bids
            <i class="fa-solid fa-arrow-right text-[10px]"></i>
          </a>
        </div>

        <div id="bidsContainer" class="space-y-4">
          <?php if (!empty($recent_bids_data)): ?>
            <?php foreach ($recent_bids_data as $bid):
              $is_winning = ((float) $bid['current_bid'] <= (float) $bid['bid_amount']);
              $status = strtolower((string) $bid['auction_status']);
              ?>
              <div
                class="bid-card group bg-white dark:bg-slate-900 rounded-2xl border <?php echo $is_winning ? 'border-gray-200 dark:border-gray-800' : 'border-rose-200 dark:border-rose-900/60'; ?> p-5 shadow-sm hover:shadow-xl transition-all relative overflow-hidden"
                data-title="<?php echo htmlspecialchars(strtolower($bid['title'])); ?>">
                <div class="absolute top-0 left-0 w-1 h-full <?php echo $is_winning ? 'bg-emerald-500' : 'bg-rose-500'; ?>">
                </div>

                <div class="flex flex-col lg:flex-row lg:items-center justify-between gap-5">
                  <div class="flex items-start gap-4 min-w-0 flex-1">
                    <div
                      class="w-14 h-14 rounded-xl <?php echo $is_winning ? 'bg-emerald-50 dark:bg-emerald-950/40 text-emerald-600' : 'bg-rose-50 dark:bg-rose-950/40 text-rose-600'; ?> flex-shrink-0 flex items-center justify-center text-xl">
                      <i class="fa-solid fa-gavel"></i>
                    </div>
                    <div class="min-w-0 flex-1">
                      <div class="flex flex-wrap items-center gap-2 mb-1.5">
                        <span
                          class="inline-flex items-center gap-1.5 text-[11px] font-bold px-2.5 py-1 rounded-full border <?php echo $is_winning ? 'bg-emerald-500/10 text-emerald-600 border-emerald-500/20' : 'bg-rose-500/10 text-rose-600 border-rose-500/20'; ?>">
                          <span
                            class="w-1.5 h-1.5 rounded-full <?php echo $is_winning ? 'bg-emerald-500' : 'bg-rose-500 animate-pulse'; ?>"></span>
                          <?php echo $is_winning ? 'Winning' : 'Outbid'; ?>
                        </span>
                        <span
                          class="text-[11px] font-semibold uppercase tracking-wider text-gray-400"><?php echo htmlspecialchars(ucfirst($status)); ?></span>
                      </div>
                      <h3 class="text-base font-bold truncate group-hover:text-indigo-600 transition">
                        <?php echo htmlspecialchars($bid['title']); ?>
                      </h3>
                      <div class="text-xs text-gray-500 mt-1 flex items-center gap-2">
                        <i class="fa-regular fa-clock text-[10px]"></i>
                        <?php echo date('M d, Y · h:i A', strtotime($bid['bid_time'])); ?>
                      </div>
                    </div>
                  </div>

                  <div
                    class="grid grid-cols-3 gap-3 sm:gap-6 items-center bg-gray-50 dark:bg-slate-800/50 px-4 py-3 rounded-xl border border-gray-100 dark:border-gray-800 shrink-0">
                    <div>
                      <div class="text-[10px] text-gray-400 uppercase font-bold tracking-wider">Your Bid</div>
                      <div
                        class="text-sm font-black <?php echo $is_winning ? 'text-emerald-600' : 'text-gray-500'; ?> tabular-nums">
                        Rs. <?php echo number_format((float) $bid['bid_amount']); ?></div>
                    </div>
                    <div>
                      <div class="text-[10px] text-gray-400 uppercase font-bold tracking-wider">Current</div>
                      <div class="text-sm font-black tabular-nums">Rs.
                        <?php echo number_format((float) $bid['current_bid']); ?>
                      </div>
                    </div>
                    <div>
                      <div class="text-[10px] text-gray-400 uppercase font-bold tracking-wider">Diff</div>
                      <div
                        class="text-sm font-bold <?php echo $is_winning ? 'text-emerald-600' : 'text-rose-600'; ?> tabular-nums">
                        <?php
                        $diff = abs((float) $bid['bid_amount'] - (float) $bid['current_bid']);
                        echo ($is_winning ? '+' : '-') . 'Rs. ' . number_format($diff);
                        ?>
                      </div>
                    </div>
                  </div>

                  <div class="flex sm:flex-col lg:flex-row gap-2 shrink-0">
                    <a class="px-3.5 py-2 text-xs font-semibold rounded-lg bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 text-gray-700 dark:text-gray-200 transition whitespace-nowrap"
                      href="../auction-details.php?id=<?php echo (int) $bid['auction_id']; ?>">
                      <i class="fa-regular fa-eye mr-1"></i> View
                    </a>
                    <a class="px-3.5 py-2 text-xs font-semibold rounded-lg bg-indigo-600 hover:bg-indigo-500 text-white transition whitespace-nowrap"
                      href="my-bids.php">
                      Manage
                    </a>
                  </div>
                </div>
              </div>
            <?php endforeach; ?>
          <?php else: ?>
            <div
              class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 p-12 text-center">
              <div
                class="w-20 h-20 mx-auto rounded-2xl bg-gradient-to-br from-indigo-500/10 to-violet-500/10 text-indigo-500 flex items-center justify-center text-3xl mb-5">
                <i class="fa-solid fa-gavel"></i>
              </div>
              <h3 class="text-xl font-black">No bids yet</h3>
              <p class="text-sm text-gray-500 mt-2 max-w-md mx-auto">Start bidding on an auction to see your activity
                here.</p>
              <a href="../auctions.php"
                class="inline-flex mt-6 px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold items-center gap-2">
                <i class="fa-solid fa-magnifying-glass"></i> Browse Auctions
              </a>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <!-- Right: Quick Links + Live Activity -->
      <div class="xl:col-span-4 space-y-6 animate-fade-up">

        <?php if (!empty($recent_bids_data)): ?>
          <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-5 shadow-sm">
            <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-800 pb-3 mb-4">
              <div class="flex items-center gap-2">
                <span class="relative flex h-2.5 w-2.5">
                  <span
                    class="animate-ping absolute inline-flex h-full w-full rounded-full bg-emerald-400 opacity-75"></span>
                  <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-emerald-500"></span>
                </span>
                <h3 class="font-bold text-sm">Live Activity</h3>
              </div>
            </div>
            <div class="space-y-2.5">
              <?php foreach (array_slice($recent_bids_data, 0, 4) as $activity):
                $activity_winning = ((float) $activity['current_bid'] <= (float) $activity['bid_amount']);
                ?>
                <a href="../auction-details.php?id=<?php echo (int) $activity['auction_id']; ?>"
                  class="group flex items-start gap-3 p-3 rounded-xl <?php echo $activity_winning ? 'bg-gray-50 dark:bg-slate-800/50 hover:bg-gray-100' : 'bg-rose-50 dark:bg-rose-950/30 hover:bg-rose-100'; ?> border transition-colors">
                  <div
                    class="w-8 h-8 rounded-lg <?php echo $activity_winning ? 'bg-emerald-500/10 text-emerald-600' : 'bg-rose-500/10 text-rose-600'; ?> flex-shrink-0 flex items-center justify-center text-xs">
                    <i class="fa-solid <?php echo $activity_winning ? 'fa-check' : 'fa-exclamation'; ?>"></i>
                  </div>
                  <div class="text-xs min-w-0 flex-1">
                    <div
                      class="font-bold <?php echo $activity_winning ? 'text-gray-900 dark:text-white' : 'text-rose-700'; ?>">
                      <?php echo $activity_winning ? 'Currently leading' : 'Outbid — take action'; ?>
                    </div>
                    <p class="text-gray-500 mt-0.5 truncate"><?php echo htmlspecialchars($activity['title']); ?></p>
                    <div class="flex items-center justify-between mt-1.5">
                      <span
                        class="text-[10px] text-gray-400"><?php echo date('M d, h:i A', strtotime($activity['bid_time'])); ?></span>
                      <span
                        class="text-[10px] font-black <?php echo $activity_winning ? 'text-emerald-600' : 'text-rose-600'; ?> tabular-nums">Rs.
                        <?php echo number_format((float) $activity['current_bid']); ?></span>
                    </div>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          </div>
        <?php endif; ?>

        <div class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 p-5 shadow-sm">
          <div class="flex items-center justify-between border-b border-gray-100 dark:border-gray-800 pb-3 mb-2">
            <h3 class="font-bold text-sm">Quick Links</h3>
          </div>
          <div class="space-y-1">
            <a class="flex items-center justify-between p-3 rounded-xl hover:bg-gray-50 dark:hover:bg-slate-800/50 transition group"
              href="my-bids.php">
              <span class="flex items-center gap-3 text-sm font-medium">
                <span
                  class="w-8 h-8 rounded-lg bg-indigo-500/10 text-indigo-600 flex items-center justify-center text-xs"><i
                    class="fa-solid fa-gavel"></i></span>
                My Bids
              </span>
              <i class="fa-solid fa-chevron-right text-xs text-gray-400 group-hover:translate-x-0.5 transition"></i>
            </a>
            <a class="flex items-center justify-between p-3 rounded-xl hover:bg-gray-50 dark:hover:bg-slate-800/50 transition group"
              href="orders.php">
              <span class="flex items-center gap-3 text-sm font-medium">
                <span
                  class="w-8 h-8 rounded-lg bg-blue-500/10 text-blue-600 flex items-center justify-center text-xs"><i
                    class="fa-solid fa-receipt"></i></span>
                My Orders
              </span>
              <i class="fa-solid fa-chevron-right text-xs text-gray-400 group-hover:translate-x-0.5 transition"></i>
            </a>
            <a class="flex items-center justify-between p-3 rounded-xl hover:bg-gray-50 dark:hover:bg-slate-800/50 transition group"
              href="won-auctions.php">
              <span class="flex items-center gap-3 text-sm font-medium">
                <span
                  class="w-8 h-8 rounded-lg bg-amber-500/10 text-amber-600 flex items-center justify-center text-xs"><i
                    class="fa-solid fa-trophy"></i></span>
                Won Auctions
              </span>
              <span class="text-xs font-bold text-gray-500 tabular-nums"><?php echo (int) $won_auctions; ?></span>
            </a>
            <a class="flex items-center justify-between p-3 rounded-xl hover:bg-gray-50 dark:hover:bg-slate-800/50 transition group"
              href="watchlist.php">
              <span class="flex items-center gap-3 text-sm font-medium">
                <span
                  class="w-8 h-8 rounded-lg bg-rose-500/10 text-rose-600 flex items-center justify-center text-xs"><i
                    class="fa-solid fa-heart"></i></span>
                Watchlist
              </span>
              <span class="text-xs font-bold text-gray-500 tabular-nums"><?php echo (int) $watchlist_count; ?></span>
            </a>
            <a class="flex items-center justify-between p-3 rounded-xl hover:bg-gray-50 dark:hover:bg-slate-800/50 transition group"
              href="profile.php">
              <span class="flex items-center gap-3 text-sm font-medium">
                <span
                  class="w-8 h-8 rounded-lg bg-violet-500/10 text-violet-600 flex items-center justify-center text-xs"><i
                    class="fa-solid fa-user"></i></span>
                My Profile
              </span>
              <i class="fa-solid fa-chevron-right text-xs text-gray-400 group-hover:translate-x-0.5 transition"></i>
            </a>
          </div>
        </div>

        <div
          class="relative rounded-2xl bg-slate-950 text-white p-6 shadow-2xl border border-gray-800 overflow-hidden noise-overlay">
          <div
            class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-indigo-900/40 via-slate-950 to-slate-950">
          </div>
          <div class="relative z-10">
            <div class="flex items-center justify-between mb-4">
              <div class="flex items-center gap-2">
                <i class="fa-solid fa-shield-halved text-emerald-400"></i>
                <h4 class="font-bold text-sm">Secure Bidding</h4>
              </div>
              <span
                class="text-[10px] bg-emerald-500/20 text-emerald-400 border border-emerald-500/30 px-2 py-0.5 rounded-full font-bold">PROTECTED</span>
            </div>
            <p class="text-xs text-gray-300 leading-relaxed mb-5">Your account activity is connected to the NexusBids
              bidding system. Use auction pages to place and manage bids.</p>
            <div class="border-t border-gray-800 pt-4">
              <div class="text-[10px] text-gray-400 font-bold uppercase tracking-wider mb-2.5">Quick Actions</div>
              <div class="flex flex-wrap items-center gap-2">
                <a href="../auctions.php"
                  class="px-3 py-1.5 rounded-lg bg-slate-800/80 hover:bg-slate-700 border border-slate-700 text-slate-200 text-xs font-medium transition"><i
                    class="fa-solid fa-gavel text-[10px] mr-1"></i> Auctions</a>
                <a href="profile.php"
                  class="px-3 py-1.5 rounded-lg bg-slate-800/80 hover:bg-slate-700 border border-slate-700 text-slate-200 text-xs font-medium transition"><i
                    class="fa-solid fa-user text-[10px] mr-1"></i> Profile</a>
                <a href="../notifications.php"
                  class="px-3 py-1.5 rounded-lg bg-slate-800/80 hover:bg-slate-700 border border-slate-700 text-slate-200 text-xs font-medium transition"><i
                    class="fa-solid fa-bell text-[10px] mr-1"></i> Alerts</a>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>
  </main>

  <footer class="bg-slate-950 text-gray-400 text-xs border-t border-gray-900 mt-8">
    <div class="max-w-7xl mx-auto px-4 sm:px-8 py-16 grid grid-cols-1 md:grid-cols-5 gap-10">
      <div class="md:col-span-2 space-y-5">
        <a class="flex items-center gap-2.5 text-2xl font-black tracking-tight text-white" href="../index.php">
          <div
            class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white">
            <i class="fa-solid fa-gavel text-lg"></i>
          </div>
          Nexus<span class="text-indigo-400">Bids</span>
        </a>
        <p class="text-gray-400 text-xs leading-relaxed max-w-sm">Pakistan's modern online auction marketplace.
          Discover, bid and win products you love.</p>
      </div>
      <div class="space-y-4">
        <h4 class="text-sm font-bold text-white uppercase tracking-wider">Marketplace</h4>
        <ul class="space-y-2.5">
          <li><a class="hover:text-white" href="../auctions.php">Browse Auctions</a></li>
          <li><a class="hover:text-white" href="../categories.php">Categories</a></li>
        </ul>
      </div>
      <div class="space-y-4">
        <h4 class="text-sm font-bold text-white uppercase tracking-wider">Account</h4>
        <ul class="space-y-2.5">
          <li><a class="hover:text-white" href="dashboard.php">My Dashboard</a></li>
          <li><a class="hover:text-white" href="my-bids.php">My Bids</a></li>
          <li><a class="hover:text-white" href="won-auctions.php">Won Auctions</a></li>
          <li><a class="hover:text-white" href="watchlist.php">Watchlist</a></li>
          <li><a class="hover:text-white" href="../auth/logout.php">Logout</a></li>
        </ul>
      </div>
      <div class="space-y-4">
        <h4 class="text-sm font-bold text-white uppercase tracking-wider">Support</h4>
        <ul class="space-y-2.5">
          <li><a class="hover:text-white" href="../about.php">Help Center</a></li>
          <li><a class="hover:text-white" href="../about.php#contact">Contact Us</a></li>
          <li><a class="hover:text-white" href="../about.php">Terms</a></li>
        </ul>
      </div>
    </div>
    <div
      class="border-t border-gray-900 py-6 text-center text-xs text-gray-500 max-w-7xl mx-auto px-4 flex flex-col sm:flex-row justify-between items-center gap-4">
      <p>© <?php echo date("Y"); ?> NexusBids. All rights reserved.</p>
      <p>Made for Pakistan <span class="text-rose-500"><i class="fa-solid fa-heart"></i></span></p>
    </div>
  </footer>

  <script>
    (function () {
      const html = document.documentElement;
      const themeToggle = document.getElementById('themeToggle');
      const savedTheme = localStorage.getItem('theme');
      if (savedTheme === 'dark' || (!savedTheme && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        html.classList.add('dark');
      }
      themeToggle?.addEventListener('click', function () {
        html.classList.toggle('dark');
        localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light');
      });

      // Bid search
      const bidSearch = document.getElementById('bidSearch');
      bidSearch?.addEventListener('input', function () {
        const q = bidSearch.value.toLowerCase().trim();
        document.querySelectorAll('.bid-card').forEach(card => {
          const title = card.dataset.title || '';
          card.style.display = title.includes(q) ? '' : 'none';
        });
      });
    })();
  </script>
  <?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>