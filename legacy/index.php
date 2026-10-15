<?php

session_start();

require_once "config/database.php";
require_once "includes/csrf.php";
require_once "includes/process-auctions.php";

processEndedAuctions($conn);



function nb_initials($full_name)
{
  $parts = preg_split("/\s+/", trim($full_name));
  $initials = "";
  foreach (array_slice($parts, 0, 2) as $part) {
    if ($part !== "")
      $initials .= strtoupper($part[0]);
  }
  return $initials !== "" ? $initials : "?";
}

function nb_compact_amount($amount)
{
  $amount = floatval($amount);
  if ($amount >= 1000000)
    return round($amount / 1000000, 1) . "M";
  if ($amount >= 1000)
    return round($amount / 1000) . "K";
  return number_format($amount);
}

function nb_category_style($category)
{
  if (is_array($category) && !empty($category["icon"])) {
    return ["icon" => $category["icon"], "color" => $category["color"] ?? "blue"];
  }
  $category_name = is_array($category) ? ($category["category_name"] ?? "") : $category;
  $styles = [
    "electronics" => ["icon" => "fa-mobile-screen-button", "color" => "blue"],
    "vehicles" => ["icon" => "fa-car", "color" => "green"],
    "fashion" => ["icon" => "fa-shirt", "color" => "orange"],
    "jewelry" => ["icon" => "fa-gem", "color" => "purple"],
    "home & living" => ["icon" => "fa-house", "color" => "red"],
    "collectibles" => ["icon" => "fa-palette", "color" => "cyan"],
  ];
  $key = strtolower(trim($category_name));
  return $styles[$key] ?? ["icon" => "fa-box-open", "color" => "blue"];
}

function nb_time_remaining($end_time)
{
  $diff = max(0, strtotime($end_time) - time());
  return [
    "hours" => intdiv($diff, 3600),
    "minutes" => intdiv($diff % 3600, 60),
    "seconds" => $diff % 60,
  ];
}

/*
|--------------------------------------------------------------------------
| Data Queries
|--------------------------------------------------------------------------
*/

$categories_result = $conn->query("
    SELECT c.category_id, c.category_name, COUNT(a.auction_id) AS auction_count
    FROM categories c
    LEFT JOIN auctions a ON a.category_id = c.category_id AND a.auction_status = 'active'
    WHERE c.status = 'active'
    GROUP BY c.category_id, c.category_name
    ORDER BY c.category_name ASC
");
$categories = $categories_result ? $categories_result->fetch_all(MYSQLI_ASSOC) : [];

$trending_result = $conn->query("
    SELECT a.auction_id, a.title, a.starting_price, a.current_bid, a.image, a.end_time,
           c.category_name, c.icon, c.color, COUNT(b.bid_id) AS bid_count
    FROM auctions a
    INNER JOIN categories c ON a.category_id = c.category_id
    LEFT JOIN bids b ON b.auction_id = a.auction_id
    WHERE a.auction_status = 'active' AND a.end_time > NOW()
    GROUP BY a.auction_id
    ORDER BY bid_count DESC, a.end_time ASC
    LIMIT 5
");
$trending_auctions = $trending_result ? $trending_result->fetch_all(MYSQLI_ASSOC) : [];
$hero_auction = !empty($trending_auctions) ? array_shift($trending_auctions) : null;
$featured_auctions = $trending_auctions;

$total_bidders = $conn->query("SELECT COUNT(*) AS total FROM users WHERE role = 'user'")->fetch_assoc()["total"] ?? 0;
$total_completed_auctions = $conn->query("SELECT COUNT(*) AS total FROM auctions WHERE auction_status IN ('completed', 'ended')")->fetch_assoc()["total"] ?? 0;
$total_products_listed = $conn->query("SELECT COUNT(*) AS total FROM auctions")->fetch_assoc()["total"] ?? 0;
$live_bid_count = $conn->query("SELECT COUNT(*) AS total FROM bids b INNER JOIN auctions a ON b.auction_id = a.auction_id WHERE a.auction_status = 'active'")->fetch_assoc()["total"] ?? 0;
$won_today_total = $conn->query("SELECT COALESCE(SUM(winning_bid), 0) AS total FROM winners WHERE DATE(created_at) = CURDATE()")->fetch_assoc()["total"] ?? 0;

$recent_activity = $conn->query("
    SELECT u.full_name, b.bid_amount, b.bid_time
    FROM bids b
    INNER JOIN users u ON b.bidder_id = u.user_id
    ORDER BY b.bid_time DESC
    LIMIT 4
");
$recent_activity = $recent_activity ? $recent_activity->fetch_all(MYSQLI_ASSOC) : [];

require_once "includes/notifications.php";
$unread_notifications = isset($_SESSION["user_id"]) ? nb_unread_count($conn, intval($_SESSION["user_id"])) : 0;

?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>NexusBids — Modern Online Auctions</title>
  <meta name="csrf-token" content="<?php echo htmlspecialchars(nb_csrf_token()); ?>">
  <meta name="description"
    content="NexusBids is a modern online auction marketplace for buying and selling products through secure online bidding.">
  <script src="https://cdn.tailwindcss.com?plugins=forms,container-queries"></script>
  <script>
    tailwind.config = {
      darkMode: 'class',
      theme: {
        extend: {
          fontFamily: {
            sans: ['Inter', 'system-ui', '-apple-system', 'Segoe UI', 'Roboto', 'sans-serif'],
          },
          colors: {
            brand: {
              50: '#eef2ff', 100: '#e0e7ff', 200: '#c7d2fe', 300: '#a5b4fc',
              400: '#818cf8', 500: '#6366f1', 600: '#4f46e5', 700: '#4338ca',
              800: '#3730a3', 900: '#312e81',
            }
          },
          animation: {
            'fade-up': 'fadeUp 0.6s ease-out forwards',
            'fade-in': 'fadeIn 0.4s ease-out forwards',
            'pulse-soft': 'pulseSoft 2s ease-in-out infinite',
            'float': 'float 6s ease-in-out infinite',
          },
          keyframes: {
            fadeUp: {'0%': {opacity: '0', transform: 'translateY(16px)'}, '100%': {opacity: '1', transform: 'translateY(0)'}},
            fadeIn: {'0%': {opacity: '0'}, '100%': {opacity: '1'}},
            pulseSoft: {'0%, 100%': {opacity: '1'}, '50%': {opacity: '0.5'}},
            float: {'0%, 100%': {transform: 'translateY(0)'}, '50%': {transform: 'translateY(-10px)'}},
          }
        }
      }
    };
  </script>
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
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

    .line-clamp-2 {
      display: -webkit-box;
      -webkit-line-clamp: 2;
      -webkit-box-orient: vertical;
      overflow: hidden;
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

    ::-webkit-scrollbar-thumb:hover {
      background: #94a3b8;
    }

    .dark ::-webkit-scrollbar-thumb:hover {
      background: #475569;
    }

    @media (prefers-reduced-motion: reduce) {

      *,
      *::before,
      *::after {
        animation-duration: .01ms !important;
        transition-duration: .01ms !important;
      }
    }

    .noise-overlay::before {
      content: '';
      position: absolute;
      inset: 0;
      background-image: url("data:image/svg+xml,%3Csvg viewBox='0 0 256 256' xmlns='http://www.w3.org/2000/svg'%3E%3Cfilter id='n'%3E%3CfeTurbulence type='fractalNoise' baseFrequency='0.9' numOctaves='4' stitchTiles='stitch'/%3E%3C/filter%3E%3Crect width='100%25' height='100%25' filter='url(%23n)' opacity='0.03'/%3E%3C/svg%3E");
      pointer-events: none;
      z-index: 1;
    }
  </style>
</head>



<body
  class="bg-gray-50 text-gray-900 dark:bg-slate-950 dark:text-gray-100 font-sans antialiased selection:bg-indigo-500 selection:text-white">

  <!-- Top Utility Bar -->
  <div class="bg-gray-950 text-gray-400 text-xs py-2.5 px-4 sm:px-8 border-b border-gray-800/50 relative z-40">
    <div class="max-w-7xl mx-auto flex flex-col sm:flex-row justify-between items-center gap-2">
      <div class="flex items-center space-x-6">
        <span class="flex items-center gap-2"><i class="fa-solid fa-location-dot text-indigo-400"></i> Pakistan</span>
        <span class="hidden sm:flex items-center gap-2"><i class="fa-solid fa-phone text-indigo-400"></i> +92 300
          1234567</span>
      </div>
      <div class="flex items-center space-x-6">
        <a href="about.php" class="hover:text-white transition-colors duration-200"><i
            class="fa-regular fa-circle-question text-indigo-400"></i> Help Center</a>
        <a href="#why-nexusbids" class="hover:text-white transition-colors duration-200"><i
            class="fa-solid fa-shield-halved text-indigo-400"></i> Secure Bidding</a>
      </div>
    </div>
  </div>

  <!-- Main Navbar -->
  <header
    class="sticky top-0 z-50 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
    <div class="max-w-7xl mx-auto px-4 sm:px-8 h-20 flex items-center justify-between gap-4">
      <a class="flex items-center gap-2.5 text-2xl font-black tracking-tight text-gray-900 dark:text-white shrink-0 group"
        href="index.php">
        <span
          class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg shadow-indigo-500/30 group-hover:shadow-indigo-500/50 group-hover:scale-105 transition-all duration-300">
          <i class="fa-solid fa-gavel text-lg"></i>
        </span>
        Nexus<span class="text-indigo-600 dark:text-indigo-400">Bids</span>
      </a>
      <nav class="hidden lg:flex items-center gap-8 text-sm font-medium">
        <a class="relative text-indigo-600 dark:text-indigo-400 font-semibold after:absolute after:-bottom-1 after:left-0 after:w-full after:h-0.5 after:bg-indigo-600 after:rounded-full"
          href="index.php">Home</a>
        <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200"
          href="auctions.php">Auctions</a>
        <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200"
          href="categories.php">Categories</a>
        <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200"
          href="#how-it-works">How It Works</a>
        <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200"
          href="about.php">About</a>
        <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors duration-200"
          href="about.php#contact">Contact</a>
      </nav>
      <div class="flex items-center gap-2 sm:gap-3">
        <button aria-label="Toggle Theme"
          class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition-all duration-200 hover:scale-105"
          id="themeToggle">
          <i class="fa-solid fa-moon dark:hidden"></i><i class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
        </button>

        <!-- ⭐ NOTIFICATION BELL — Always Visible -->
        <?php if (isset($_SESSION["user_id"])): ?>
          <a href="notifications.php"
            class="relative w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition-all duration-200 hover:scale-105"
            title="Notifications">
            <i class="fa-regular fa-bell"></i>
            <?php if ($unread_notifications > 0): ?>
              <span
                class="absolute -top-1 -right-1 min-w-[18px] h-[18px] px-1 rounded-full bg-rose-500 text-white text-[10px] font-bold flex items-center justify-center border-2 border-white dark:border-gray-800 animate-pulse-soft">
                <?php echo $unread_notifications > 99 ? '99+' : $unread_notifications; ?>
              </span>
            <?php endif; ?>
          </a>
        <?php endif; ?>

        <!-- Desktop Auth Buttons -->
        <div class="hidden lg:flex items-center gap-2 sm:gap-3">
          <?php if (isset($_SESSION["user_id"])): ?>
            <a class="text-sm font-medium text-gray-700 dark:text-gray-200 max-w-28 truncate hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors"
              href="<?php echo ($_SESSION["role"] ?? "") === "seller" ? "seller/dashboard.php" : "user/dashboard.php"; ?>">
              <?php echo htmlspecialchars($_SESSION["full_name"] ?? "Dashboard"); ?>
            </a>
            <a href="auth/logout.php"
              class="inline-flex px-4 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition-all duration-200 hover:shadow-lg hover:shadow-indigo-600/30">
              Logout
            </a>
          <?php else: ?>
            <a href="auth/login.php"
              class="text-sm font-semibold text-gray-700 dark:text-gray-200 hover:text-indigo-600 dark:hover:text-indigo-400 transition-colors">
              Login
            </a>
            <a href="auth/register.php"
              class="px-4 py-2 text-sm font-semibold text-white bg-indigo-600 hover:bg-indigo-700 rounded-xl transition-all duration-200 hover:shadow-lg hover:shadow-indigo-600/30">
              Register
            </a>
          <?php endif; ?>
        </div>

        <!-- Hamburger -->
        <button id="mobileMenuBtn" aria-label="Toggle Menu" aria-expanded="false"
          class="lg:hidden w-10 h-10 rounded-xl bg-gray-100 dark:bg-gray-800 flex items-center justify-center text-gray-700 dark:text-gray-300 hover:bg-gray-200 dark:hover:bg-gray-700 transition-colors">
          <i class="fa-solid fa-bars" id="menuIcon"></i>
        </button>
      </div>
    </div>
    <!-- Mobile Dropdown Menu -->
    <div id="mobileMenu"
      class="hidden lg:hidden border-t border-gray-200 dark:border-gray-800 bg-white dark:bg-slate-900 shadow-lg">
      <nav class="px-4 py-3 space-y-1">
        <a href="index.php"
          class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-semibold text-indigo-600 dark:text-indigo-400 bg-indigo-50 dark:bg-indigo-950/30 transition-all">
          <i class="fa-solid fa-house w-5 text-center"></i> Home
        </a>
        <a href="auctions.php"
          class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all">
          <i class="fa-solid fa-gavel w-5 text-center text-indigo-500"></i> Auctions
        </a>
        <a href="categories.php"
          class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all">
          <i class="fa-solid fa-layer-group w-5 text-center text-indigo-500"></i> Categories
        </a>
        <a href="#how-it-works"
          class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all">
          <i class="fa-solid fa-circle-question w-5 text-center text-indigo-500"></i> How It Works
        </a>
        <a href="about.php"
          class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all">
          <i class="fa-solid fa-circle-info w-5 text-center text-indigo-500"></i> About
        </a>
        <a href="about.php#contact"
          class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all">
          <i class="fa-solid fa-envelope w-5 text-center text-indigo-500"></i> Contact
        </a>

        <div class="my-2 border-t border-gray-100 dark:border-gray-800"></div>

        <?php if (isset($_SESSION["user_id"])): ?>
          <a href="<?php echo ($_SESSION["role"] ?? "") === "seller" ? "seller/dashboard.php" : "user/dashboard.php"; ?>"
            class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all">
            <i class="fa-solid fa-gauge-high w-5 text-center text-indigo-500"></i> Dashboard
          </a>
          <a href="notifications.php"
            class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all">
            <i class="fa-regular fa-bell w-5 text-center text-indigo-500"></i> Notifications
            <?php if ($unread_notifications > 0): ?>
              <span
                class="ml-auto px-2 py-0.5 rounded-full bg-rose-500 text-white text-[10px] font-bold"><?php echo $unread_notifications; ?></span>
            <?php endif; ?>
          </a>
          <a href="auth/logout.php"
            class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-semibold text-rose-600 dark:text-rose-400 hover:bg-rose-50 dark:hover:bg-rose-950/30 transition-all">
            <i class="fa-solid fa-right-from-bracket w-5 text-center"></i> Logout
          </a>
        <?php else: ?>
          <a href="auth/login.php"
            class="flex items-center gap-3 px-4 py-3 rounded-xl text-sm font-medium text-gray-700 dark:text-gray-200 hover:bg-indigo-50 dark:hover:bg-indigo-950/30 hover:text-indigo-600 dark:hover:text-indigo-400 transition-all">
            <i class="fa-solid fa-right-to-bracket w-5 text-center text-indigo-500"></i> Login
          </a>
          <a href="auth/register.php"
            class="flex items-center justify-center gap-2 mx-2 mt-1 px-4 py-3 rounded-xl text-sm font-bold text-white bg-gradient-to-r from-indigo-600 to-violet-600 hover:from-indigo-500 hover:to-violet-500 shadow-lg shadow-indigo-600/30 transition-all">
            <i class="fa-solid fa-user-plus"></i> Create Free Account
          </a>
        <?php endif; ?>
      </nav>
    </div>
  </header>

  <main>
    <!-- Hero -->
    <section class="relative bg-slate-950 text-white overflow-hidden py-20 lg:py-28 noise-overlay" id="home">
      <!-- Background effects -->
      <div
        class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-indigo-900/40 via-slate-950 to-slate-950">
      </div>
      <div
        class="absolute top-0 left-1/3 w-[500px] h-[500px] bg-indigo-600/20 rounded-full blur-[120px] pointer-events-none animate-float">
      </div>
      <div
        class="absolute bottom-0 right-1/4 w-[400px] h-[400px] bg-violet-600/15 rounded-full blur-[100px] pointer-events-none">
      </div>
      <div
        class="absolute top-1/2 left-0 w-[300px] h-[300px] bg-blue-600/10 rounded-full blur-[80px] pointer-events-none">
      </div>
      <!-- Grid pattern -->
      <div class="absolute inset-0 opacity-[0.03]"
        style="background-image: linear-gradient(rgba(255,255,255,.1) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,.1) 1px, transparent 1px); background-size: 60px 60px;">
      </div>

      <div class="max-w-7xl mx-auto px-4 sm:px-8 grid grid-cols-1 lg:grid-cols-12 gap-12 items-center relative z-10">
        <div class="lg:col-span-7 space-y-7">
          <div
            class="inline-flex items-center gap-2 px-4 py-2 rounded-full bg-emerald-500/10 border border-emerald-500/20 text-emerald-400 text-xs font-semibold uppercase tracking-wider animate-fade-up">
            <span class="w-2 h-2 rounded-full bg-emerald-400 animate-pulse"></span>
            Live Auctions Happening Now
          </div>
          <h1 class="text-4xl sm:text-5xl lg:text-6xl font-black tracking-tight leading-[1.1] animate-fade-up"
            style="animation-delay: 0.1s">
            Bid Smart.<br>
            <span class="gradient-text bg-gradient-to-r from-blue-400 via-indigo-400 to-violet-400">Win Big.</span><br>
            Own Something Amazing.
          </h1>
          <p class="text-gray-300 text-base sm:text-lg max-w-xl leading-relaxed animate-fade-up"
            style="animation-delay: 0.2s">
            Discover exclusive products, join live auctions, place competitive bids and win products at prices you
            choose.
          </p>
          <div class="flex flex-wrap items-center gap-4 pt-2 animate-fade-up" style="animation-delay: 0.3s">
            <a class="group px-7 py-3.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-semibold shadow-lg shadow-indigo-600/30 hover:shadow-indigo-500/40 transition-all duration-300 hover:-translate-y-0.5 flex items-center gap-2"
              href="auctions.php">
              Explore Auctions
              <i
                class="fa-solid fa-arrow-right text-sm group-hover:translate-x-1 transition-transform duration-300"></i>
            </a>
            <a class="group px-7 py-3.5 rounded-xl bg-gray-800/80 hover:bg-gray-700/80 text-white font-semibold border border-gray-700 hover:border-gray-600 transition-all duration-300 hover:-translate-y-0.5 flex items-center gap-2"
              href="#how-it-works">
              How It Works
              <i
                class="fa-solid fa-play text-xs text-indigo-400 group-hover:scale-110 transition-transform duration-300"></i>
            </a>
          </div>
          <div class="pt-8 flex items-center gap-4 border-t border-gray-800/80 animate-fade-up"
            style="animation-delay: 0.4s">
            <div class="flex -space-x-3">
              <div
                class="w-11 h-11 rounded-full border-2 border-slate-950 bg-gradient-to-br from-indigo-500 to-indigo-700 flex items-center justify-center text-xs font-bold shadow-lg">
                NB</div>
              <div
                class="w-11 h-11 rounded-full border-2 border-slate-950 bg-gradient-to-br from-violet-500 to-violet-700 flex items-center justify-center text-xs font-bold shadow-lg">
                PK</div>
              <div
                class="w-11 h-11 rounded-full border-2 border-slate-950 bg-gradient-to-br from-blue-500 to-blue-700 flex items-center justify-center text-xs font-bold shadow-lg">
                BD</div>
              <div
                class="w-11 h-11 rounded-full border-2 border-slate-950 bg-gradient-to-br from-indigo-600 to-violet-600 flex items-center justify-center text-xs font-bold shadow-lg">
                +</div>
            </div>
            <div>
              <p class="text-sm font-bold text-white"><?php echo number_format((int) $total_bidders); ?>+ bidders</p>
              <p class="text-xs text-gray-400">already joined NexusBids</p>
            </div>
          </div>
        </div>

        <!-- Dynamic Hero Auction -->
        <?php
        $hero_style = nb_category_style($hero_auction ?? "");
        $hero_price = $hero_auction ? (floatval($hero_auction["current_bid"]) > 0 ? floatval($hero_auction["current_bid"]) : floatval($hero_auction["starting_price"])) : 0;
        $hero_remaining = $hero_auction ? nb_time_remaining($hero_auction["end_time"]) : ["hours" => 0, "minutes" => 0, "seconds" => 0];
        ?>
        <div class="lg:col-span-5 animate-fade-up" style="animation-delay: 0.3s">
          <div
            class="relative bg-slate-900/80 glass border border-gray-700/50 rounded-3xl shadow-2xl shadow-black/40 overflow-hidden">
            <!-- Glow effect -->
            <div
              class="absolute -inset-px bg-gradient-to-b from-indigo-500/20 to-transparent rounded-3xl pointer-events-none">
            </div>
            <div class="relative p-5 border-b border-gray-800/80 flex justify-between items-center bg-slate-950/50">
              <span class="inline-flex items-center gap-2 text-xs font-bold uppercase tracking-wider text-rose-500">
                <span class="relative flex h-2.5 w-2.5">
                  <span
                    class="animate-ping absolute inline-flex h-full w-full rounded-full bg-rose-500 opacity-75"></span>
                  <span class="relative inline-flex rounded-full h-2.5 w-2.5 bg-rose-500"></span>
                </span>
                Live Auction
              </span>
              <?php if ($hero_auction): ?>
                <a href="auction-details.php?id=<?php echo (int) $hero_auction['auction_id']; ?>"
                  class="w-9 h-9 rounded-full bg-gray-800/80 hover:bg-gray-700 flex items-center justify-center text-gray-300 transition-all duration-200 hover:scale-110 hover:text-rose-400">
                  <i class="fa-regular fa-heart"></i>
                </a>
              <?php endif; ?>
            </div>
            <div
              class="relative h-64 bg-gradient-to-b from-slate-900 to-slate-950 flex items-center justify-center overflow-hidden group">
              <?php if ($hero_auction && !empty($hero_auction['image'])): ?>
                <img class="w-full h-full object-cover group-hover:scale-105 transition-transform duration-700"
                  src="uploads/auctions/<?php echo htmlspecialchars($hero_auction['image']); ?>"
                  alt="<?php echo htmlspecialchars($hero_auction['title']); ?>">
              <?php else: ?>
                <div
                  class="w-32 h-32 rounded-2xl bg-gradient-to-tr from-indigo-900/40 to-slate-800 border border-indigo-500/30 flex items-center justify-center">
                  <i class="fa-solid <?php echo htmlspecialchars($hero_style['icon']); ?> text-5xl text-indigo-400"></i>
                </div>
              <?php endif; ?>
            </div>
            <div class="p-6 bg-slate-950/80 space-y-5">
              <div>
                <span class="text-xs text-indigo-400 uppercase tracking-widest font-semibold">
                  <?php echo $hero_auction ? htmlspecialchars($hero_auction['category_name']) : 'No Live Auctions'; ?>
                </span>
                <h3 class="text-lg font-bold text-white mt-1.5 line-clamp-2 leading-snug">
                  <?php echo $hero_auction ? htmlspecialchars($hero_auction['title']) : 'Check back soon'; ?>
                </h3>
              </div>
              <div class="flex justify-between items-center text-sm">
                <div>
                  <span class="text-gray-400 text-xs block mb-0.5">Current Bid</span>
                  <span class="text-xl font-black text-white">Rs. <?php echo number_format($hero_price); ?></span>
                </div>
                <div class="text-right">
                  <span class="text-gray-400 text-xs block mb-0.5">Activity</span>
                  <span
                    class="text-xs font-semibold text-indigo-400 bg-indigo-500/10 px-2.5 py-1 rounded-lg border border-indigo-500/20">
                    <i class="fa-solid fa-gavel"></i> <?php echo (int) ($hero_auction['bid_count'] ?? 0); ?> Bids
                  </span>
                </div>
              </div>
              <div class="grid grid-cols-3 gap-2 text-center"
                data-countdown="<?php echo $hero_auction ? htmlspecialchars(date('c', strtotime($hero_auction['end_time']))) : ''; ?>">
                <div class="bg-slate-900/80 py-2.5 rounded-xl border border-gray-800/80">
                  <span
                    class="block text-lg font-black text-white countdown-hours tabular-nums"><?php echo str_pad($hero_remaining['hours'], 2, '0', STR_PAD_LEFT); ?></span>
                  <span class="text-[10px] text-gray-400 uppercase tracking-wider">Hours</span>
                </div>
                <div class="bg-slate-900/80 py-2.5 rounded-xl border border-gray-800/80">
                  <span
                    class="block text-lg font-black text-white countdown-minutes tabular-nums"><?php echo str_pad($hero_remaining['minutes'], 2, '0', STR_PAD_LEFT); ?></span>
                  <span class="text-[10px] text-gray-400 uppercase tracking-wider">Minutes</span>
                </div>
                <div class="bg-slate-900/80 py-2.5 rounded-xl border border-gray-800/80">
                  <span
                    class="block text-lg font-black text-white countdown-seconds tabular-nums"><?php echo str_pad($hero_remaining['seconds'], 2, '0', STR_PAD_LEFT); ?></span>
                  <span class="text-[10px] text-gray-400 uppercase tracking-wider">Seconds</span>
                </div>
              </div>
              <?php if ($hero_auction): ?>
                <a href="auction-details.php?id=<?php echo (int) $hero_auction['auction_id']; ?>"
                  class="group w-full py-3.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-sm shadow-lg shadow-indigo-600/30 hover:shadow-indigo-500/40 transition-all duration-300 flex items-center justify-center gap-2">
                  <i class="fa-solid fa-gavel group-hover:rotate-12 transition-transform duration-300"></i> View &amp; Bid
                </a>
              <?php else: ?>
                <a href="auctions.php"
                  class="group w-full py-3.5 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white font-bold text-sm shadow-lg shadow-indigo-600/30 transition-all duration-300 flex items-center justify-center gap-2">
                  <i class="fa-solid fa-gavel group-hover:rotate-12 transition-transform duration-300"></i> Browse
                  Auctions
                </a>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- Search -->
    <div
      class="bg-white dark:bg-slate-900 border-b border-gray-200 dark:border-gray-800 shadow-sm py-5 sticky top-20 z-30 glass">
      <div class="max-w-7xl mx-auto px-4 sm:px-8">
        <form action="auctions.php" method="get" class="flex flex-col md:flex-row gap-3 items-center">
          <div class="relative w-full md:flex-1 group">
            <span
              class="absolute inset-y-0 left-0 pl-4 flex items-center pointer-events-none text-gray-400 group-focus-within:text-indigo-500 transition-colors">
              <i class="fa-solid fa-magnifying-glass"></i>
            </span>
            <input name="search"
              class="w-full pl-11 pr-4 py-3.5 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent text-gray-900 dark:text-white transition-all duration-200 placeholder:text-gray-400"
              placeholder="Search for products, auctions or categories..." type="text">
          </div>
          <div class="flex w-full md:w-auto gap-3">
            <select name="category"
              class="w-full md:w-56 py-3.5 px-4 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all duration-200 cursor-pointer">
              <option value="0">All Categories</option>
              <?php foreach ($categories as $category): ?>
                <option value="<?php echo (int) $category['category_id']; ?>">
                  <?php echo htmlspecialchars($category['category_name']); ?>
                </option>
              <?php endforeach; ?>
            </select>
            <button
              class="px-7 py-3.5 bg-indigo-600 hover:bg-indigo-500 text-white font-semibold rounded-xl transition-all duration-200 hover:shadow-lg hover:shadow-indigo-600/30 whitespace-nowrap"
              type="submit">Search</button>
          </div>
        </form>
      </div>
    </div>

    <!-- Stats -->
    <section class="bg-white dark:bg-slate-950 border-b border-gray-200 dark:border-gray-800">
      <div class="max-w-7xl mx-auto px-4 sm:px-8 py-10 grid grid-cols-2 lg:grid-cols-4 gap-6">
        <div class="text-center group">
          <div
            class="text-3xl font-black text-gray-900 dark:text-white group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors duration-300">
            <?php echo number_format((int) $total_products_listed); ?>+
          </div>
          <div class="text-xs text-gray-500 dark:text-gray-400 mt-1.5 font-medium">Products Listed</div>
        </div>
        <div class="text-center group">
          <div
            class="text-3xl font-black text-gray-900 dark:text-white group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors duration-300">
            <?php echo number_format((int) $live_bid_count); ?>+
          </div>
          <div class="text-xs text-gray-500 dark:text-gray-400 mt-1.5 font-medium">Live Bids</div>
        </div>
        <div class="text-center group">
          <div
            class="text-3xl font-black text-gray-900 dark:text-white group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors duration-300">
            <?php echo number_format((int) $total_completed_auctions); ?>+
          </div>
          <div class="text-xs text-gray-500 dark:text-gray-400 mt-1.5 font-medium">Completed Auctions</div>
        </div>
        <div class="text-center group">
          <div
            class="text-3xl font-black text-gray-900 dark:text-white group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors duration-300">
            Rs. <?php echo nb_compact_amount($won_today_total); ?></div>
          <div class="text-xs text-gray-500 dark:text-gray-400 mt-1.5 font-medium">Won Today</div>
        </div>
      </div>
    </section>

    <!-- Featured Auctions -->
    <section class="py-20 max-w-7xl mx-auto px-4 sm:px-8" id="auctions">
      <div class="flex justify-between items-end mb-10">
        <div>
          <span class="text-indigo-600 dark:text-indigo-400 font-bold text-xs uppercase tracking-wider block mb-2">
            <i class="fa-solid fa-fire"></i> Trending Now
          </span>
          <h2 class="text-3xl sm:text-4xl font-black text-gray-900 dark:text-white tracking-tight">Featured Auctions
          </h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Don't miss these popular auctions ending soon.</p>
        </div>
        <a class="hidden sm:flex items-center gap-2 text-sm font-semibold text-indigo-600 dark:text-indigo-400 hover:underline group"
          href="auctions.php">
          View All Auctions
          <i class="fa-solid fa-arrow-right text-xs group-hover:translate-x-1 transition-transform duration-300"></i>
        </a>
      </div>
      <?php if (!empty($featured_auctions)): ?>
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-6">
          <?php foreach ($featured_auctions as $auction):
            $price = floatval($auction['current_bid']) > 0 ? floatval($auction['current_bid']) : floatval($auction['starting_price']);
            $remaining = nb_time_remaining($auction['end_time']); ?>
            <a href="auction-details.php?id=<?php echo (int) $auction['auction_id']; ?>"
              class="group rounded-2xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 overflow-hidden hover:border-indigo-500 dark:hover:border-indigo-500 hover:-translate-y-1.5 transition-all duration-300 shadow-sm hover:shadow-xl hover:shadow-indigo-500/10 flex flex-col">
              <div class="relative h-48 bg-gray-100 dark:bg-slate-800 overflow-hidden flex items-center justify-center">
                <?php if (!empty($auction['image'])): ?>
                  <img src="uploads/auctions/<?php echo htmlspecialchars($auction['image']); ?>"
                    alt="<?php echo htmlspecialchars($auction['title']); ?>"
                    class="w-full h-full object-cover group-hover:scale-110 transition-transform duration-700">
                <?php else: ?>
                  <i
                    class="fa-solid <?php echo htmlspecialchars(nb_category_style($auction)['icon']); ?> text-5xl text-indigo-400"></i>
                <?php endif; ?>
                <!-- Overlay gradient -->
                <div
                  class="absolute inset-0 bg-gradient-to-t from-black/40 to-transparent opacity-0 group-hover:opacity-100 transition-opacity duration-300">
                </div>
              </div>
              <div class="p-5 space-y-3 flex-1 flex flex-col">
                <span
                  class="text-[11px] font-bold uppercase tracking-wider text-indigo-600 dark:text-indigo-400"><?php echo htmlspecialchars($auction['category_name']); ?></span>
                <h3 class="font-bold text-gray-900 dark:text-white line-clamp-2 min-h-12 leading-snug">
                  <?php echo htmlspecialchars($auction['title']); ?>
                </h3>
                <div class="flex justify-between items-end">
                  <div>
                    <span class="block text-[11px] text-gray-400">Current Bid</span>
                    <strong class="text-lg text-gray-900 dark:text-white">Rs. <?php echo number_format($price); ?></strong>
                  </div>
                  <span class="text-xs text-gray-500 dark:text-gray-400 bg-gray-100 dark:bg-slate-800 px-2 py-1 rounded-lg">
                    <i class="fa-solid fa-gavel"></i> <?php echo (int) $auction['bid_count']; ?>
                  </span>
                </div>
                <div class="flex gap-2 text-center mt-auto"
                  data-countdown="<?php echo htmlspecialchars(date('c', strtotime($auction['end_time']))); ?>">
                  <div class="flex-1 bg-gray-50 dark:bg-slate-800 rounded-lg py-2">
                    <b
                      class="block text-sm countdown-hours tabular-nums"><?php echo str_pad($remaining['hours'], 2, '0', STR_PAD_LEFT); ?></b>
                    <small class="text-[9px] text-gray-400 uppercase">H</small>
                  </div>
                  <div class="flex-1 bg-gray-50 dark:bg-slate-800 rounded-lg py-2">
                    <b
                      class="block text-sm countdown-minutes tabular-nums"><?php echo str_pad($remaining['minutes'], 2, '0', STR_PAD_LEFT); ?></b>
                    <small class="text-[9px] text-gray-400 uppercase">M</small>
                  </div>
                  <div class="flex-1 bg-gray-50 dark:bg-slate-800 rounded-lg py-2">
                    <b
                      class="block text-sm countdown-seconds tabular-nums"><?php echo str_pad($remaining['seconds'], 2, '0', STR_PAD_LEFT); ?></b>
                    <small class="text-[9px] text-gray-400 uppercase">S</small>
                  </div>
                </div>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      <?php else: ?>
        <div
          class="p-12 rounded-2xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 text-center shadow-sm">
          <div
            class="w-16 h-16 rounded-2xl bg-indigo-50 dark:bg-indigo-950/50 flex items-center justify-center mx-auto mb-4">
            <i class="fa-solid fa-gavel text-2xl text-indigo-500"></i>
          </div>
          <p class="text-gray-500 dark:text-gray-400 text-sm mb-4">No live auctions right now — check back soon.</p>
          <a href="auctions.php"
            class="inline-block px-6 py-3 rounded-xl bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold transition-all duration-200">Browse
            Auctions</a>
        </div>
      <?php endif; ?>
    </section>

    <!-- Categories -->
    <section class="py-20 bg-gray-100/60 dark:bg-slate-900/40 border-y border-gray-200 dark:border-gray-800"
      id="categories">
      <div class="max-w-7xl mx-auto px-4 sm:px-8">
        <div class="text-center max-w-xl mx-auto mb-14">
          <span
            class="text-indigo-600 dark:text-indigo-400 font-bold text-xs uppercase tracking-wider block mb-2">Explore</span>
          <h2 class="text-3xl sm:text-4xl font-black text-gray-900 dark:text-white tracking-tight">Browse By Category
          </h2>
          <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Find exactly what you're looking for.</p>
        </div>
        <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-6 gap-4">
          <?php foreach ($categories as $category):
            $cat_style = nb_category_style($category);
            $cat_colors = [
              'blue' => 'bg-blue-50 dark:bg-blue-950/50 text-blue-600 dark:text-blue-400',
              'green' => 'bg-emerald-50 dark:bg-emerald-950/50 text-emerald-600 dark:text-emerald-400',
              'orange' => 'bg-amber-50 dark:bg-amber-950/50 text-amber-600 dark:text-amber-400',
              'purple' => 'bg-violet-50 dark:bg-violet-950/50 text-violet-600 dark:text-violet-400',
              'red' => 'bg-rose-50 dark:bg-rose-950/50 text-rose-600 dark:text-rose-400',
              'cyan' => 'bg-cyan-50 dark:bg-cyan-950/50 text-cyan-600 dark:text-cyan-400'
            ];
            $cat_class = $cat_colors[$cat_style['color']] ?? $cat_colors['blue']; ?>
            <a class="p-5 rounded-2xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 hover:border-indigo-500 dark:hover:border-indigo-500 transition-all duration-300 group flex flex-col justify-between shadow-sm hover:shadow-lg hover:shadow-indigo-500/10 hover:-translate-y-1"
              href="auctions.php?category=<?php echo (int) $category['category_id']; ?>">
              <div
                class="w-12 h-12 rounded-xl <?php echo $cat_class; ?> flex items-center justify-center text-xl mb-4 group-hover:scale-110 group-hover:rotate-3 transition-all duration-300">
                <i class="fa-solid <?php echo htmlspecialchars($cat_style['icon']); ?>"></i>
              </div>
              <div>
                <h3 class="font-bold text-gray-900 dark:text-white text-sm">
                  <?php echo htmlspecialchars($category['category_name']); ?>
                </h3>
                <p class="text-xs text-gray-400 mt-1"><?php echo (int) $category['auction_count']; ?>+ Auctions</p>
              </div>
              <div
                class="mt-4 text-right text-gray-300 dark:text-gray-600 group-hover:text-indigo-600 dark:group-hover:text-indigo-400 transition-colors duration-300">
                <i class="fa-solid fa-arrow-up-right-from-square text-xs"></i>
              </div>
            </a>
          <?php endforeach; ?>
        </div>
      </div>
    </section>

    <!-- How It Works -->
    <section class="py-24 max-w-7xl mx-auto px-4 sm:px-8" id="how-it-works">
      <div class="text-center max-w-xl mx-auto mb-16">
        <span class="text-indigo-600 dark:text-indigo-400 font-bold text-xs uppercase tracking-wider block mb-2">Simple
          Process</span>
        <h2 class="text-3xl sm:text-4xl font-black text-gray-900 dark:text-white tracking-tight">How NexusBids Works
        </h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Start bidding in just three simple steps.</p>
      </div>
      <div class="grid grid-cols-1 md:grid-cols-3 gap-8">
        <div
          class="group p-8 rounded-2xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 text-center relative shadow-sm hover:shadow-xl hover:shadow-indigo-500/10 hover:-translate-y-1.5 transition-all duration-300">
          <div
            class="absolute -top-4 left-1/2 -translate-x-1/2 w-9 h-9 rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-white text-xs font-bold flex items-center justify-center shadow-lg shadow-indigo-500/30">
            01</div>
          <div
            class="w-16 h-16 rounded-2xl bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-2xl mx-auto mb-6 mt-2 group-hover:scale-110 group-hover:-rotate-3 transition-all duration-300">
            <i class="fa-solid fa-user-plus"></i>
          </div>
          <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Create Your Account</h3>
          <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed">Register securely and set up your
            NexusBids account in seconds.</p>
        </div>
        <div
          class="group p-8 rounded-2xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 text-center relative shadow-sm hover:shadow-xl hover:shadow-indigo-500/10 hover:-translate-y-1.5 transition-all duration-300">
          <div
            class="absolute -top-4 left-1/2 -translate-x-1/2 w-9 h-9 rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-white text-xs font-bold flex items-center justify-center shadow-lg shadow-indigo-500/30">
            02</div>
          <div
            class="w-16 h-16 rounded-2xl bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-2xl mx-auto mb-6 mt-2 group-hover:scale-110 group-hover:-rotate-3 transition-all duration-300">
            <i class="fa-solid fa-gavel"></i>
          </div>
          <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Find &amp; Bid</h3>
          <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed">Explore thousands of auctions and place
            your competitive bid.</p>
        </div>
        <div
          class="group p-8 rounded-2xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 text-center relative shadow-sm hover:shadow-xl hover:shadow-indigo-500/10 hover:-translate-y-1.5 transition-all duration-300">
          <div
            class="absolute -top-4 left-1/2 -translate-x-1/2 w-9 h-9 rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-white text-xs font-bold flex items-center justify-center shadow-lg shadow-indigo-500/30">
            03</div>
          <div
            class="w-16 h-16 rounded-2xl bg-indigo-50 dark:bg-indigo-950/50 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-2xl mx-auto mb-6 mt-2 group-hover:scale-110 group-hover:-rotate-3 transition-all duration-300">
            <i class="fa-solid fa-trophy"></i>
          </div>
          <h3 class="text-lg font-bold text-gray-900 dark:text-white mb-2">Win &amp; Receive</h3>
          <p class="text-xs text-gray-500 dark:text-gray-400 leading-relaxed">Win the auction, complete payment and
            receive your product.</p>
        </div>
      </div>
    </section>

    <!-- Why NexusBids -->
    <section class="py-20 bg-gray-100/60 dark:bg-slate-900/40 border-y border-gray-200 dark:border-gray-800"
      id="why-nexusbids">
      <div class="max-w-7xl mx-auto px-4 sm:px-8 grid grid-cols-1 lg:grid-cols-12 gap-12 items-center">
        <div class="lg:col-span-6 space-y-7">
          <span class="text-indigo-600 dark:text-indigo-400 font-bold text-xs uppercase tracking-wider block">Why
            NexusBids</span>
          <h2 class="text-3xl sm:text-4xl font-black text-gray-900 dark:text-white tracking-tight leading-tight">
            A Better Way To <span class="gradient-text bg-gradient-to-r from-indigo-600 to-violet-600">Auction
              Online.</span>
          </h2>
          <p class="text-sm text-gray-600 dark:text-gray-400 leading-relaxed">NexusBids brings buyers and sellers
            together through a trusted, transparent and exciting online auction experience.</p>
          <div class="space-y-5 pt-2">
            <div class="flex items-start gap-4 group">
              <div
                class="w-11 h-11 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-lg shrink-0 group-hover:scale-110 transition-transform duration-300">
                <i class="fa-solid fa-shield-halved"></i>
              </div>
              <div>
                <h4 class="font-bold text-gray-900 dark:text-white text-sm">Secure &amp; Trusted</h4>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-relaxed">Your account and transactions
                  are protected with modern security.</p>
              </div>
            </div>
            <div class="flex items-start gap-4 group">
              <div
                class="w-11 h-11 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center text-lg shrink-0 group-hover:scale-110 transition-transform duration-300">
                <i class="fa-solid fa-bolt"></i>
              </div>
              <div>
                <h4 class="font-bold text-gray-900 dark:text-white text-sm">Real-Time Bidding</h4>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-relaxed">See live bids and compete with
                  bidders instantly.</p>
              </div>
            </div>
            <div class="flex items-start gap-4 group">
              <div
                class="w-11 h-11 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-lg shrink-0 group-hover:scale-110 transition-transform duration-300">
                <i class="fa-solid fa-circle-check"></i>
              </div>
              <div>
                <h4 class="font-bold text-gray-900 dark:text-white text-sm">Verified Products</h4>
                <p class="text-xs text-gray-500 dark:text-gray-400 mt-1 leading-relaxed">Quality products and verified
                  sellers for a safer marketplace.</p>
              </div>
            </div>
          </div>
        </div>
        <div class="lg:col-span-6">
          <div
            class="bg-slate-950 border border-gray-800 rounded-3xl shadow-2xl shadow-black/40 overflow-hidden p-6 relative">
            <div
              class="absolute -inset-px bg-gradient-to-b from-indigo-500/10 to-transparent rounded-3xl pointer-events-none">
            </div>
            <div class="relative flex items-center justify-between pb-4 border-b border-gray-800/80 mb-5">
              <div class="flex items-center gap-2">
                <span class="w-3 h-3 rounded-full bg-rose-500"></span>
                <span class="w-3 h-3 rounded-full bg-amber-500"></span>
                <span class="w-3 h-3 rounded-full bg-emerald-500"></span>
              </div>
              <span
                class="text-xs text-emerald-400 font-semibold bg-emerald-950/80 px-3 py-1 rounded-full border border-emerald-800/50 flex items-center gap-1.5">
                <i class="fa-solid fa-circle text-[8px] animate-pulse"></i> Online
              </span>
            </div>
            <div class="relative space-y-3">
              <?php if (!empty($recent_activity)):
                foreach ($recent_activity as $activity): ?>
                  <div
                    class="p-3.5 rounded-xl bg-slate-900/80 border border-gray-800/80 flex items-center justify-between hover:border-gray-700 transition-colors duration-200">
                    <div class="flex items-center gap-3">
                      <div
                        class="w-9 h-9 rounded-lg bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold flex items-center justify-center text-xs shadow-lg">
                        <?php echo htmlspecialchars(nb_initials($activity['full_name'])); ?>
                      </div>
                      <div>
                        <h5 class="text-xs font-bold text-white"><?php echo htmlspecialchars($activity['full_name']); ?>
                        </h5>
                        <p class="text-[10px] text-gray-400">placed a bid</p>
                      </div>
                    </div>
                    <span class="text-xs font-black text-indigo-400">Rs.
                      <?php echo nb_compact_amount($activity['bid_amount']); ?></span>
                  </div>
                <?php endforeach; else: ?>
                <div class="p-4 rounded-xl bg-slate-900/80 border border-gray-800/80 text-xs text-gray-400">No recent
                  bidding activity yet.</div>
              <?php endif; ?>
            </div>
            <div class="relative mt-5 pt-5 border-t border-gray-800/80 flex justify-between items-center gap-3">
              <span class="text-xs text-gray-400">Winner Announcement</span>
              <span
                class="px-3 py-1.5 bg-amber-500/10 text-amber-400 border border-amber-500/20 text-xs font-bold rounded-lg flex items-center gap-1.5">
                <i class="fa-solid fa-trophy"></i> Rs. <?php echo nb_compact_amount($won_today_total); ?> Won Today
              </span>
            </div>
          </div>
        </div>
      </div>
    </section>

    <!-- CTA -->
    <section class="py-24 max-w-7xl mx-auto px-4 sm:px-8">
      <div
        class="relative rounded-3xl bg-slate-950 text-white p-8 sm:p-14 overflow-hidden shadow-2xl border border-gray-800 noise-overlay">
        <div
          class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-indigo-900/40 via-slate-950 to-slate-950">
        </div>
        <div
          class="absolute right-0 top-1/2 -translate-y-1/2 translate-x-1/4 w-96 h-96 bg-indigo-600/20 rounded-full blur-3xl pointer-events-none">
        </div>
        <div
          class="absolute left-0 bottom-0 -translate-x-1/4 translate-y-1/4 w-80 h-80 bg-violet-600/15 rounded-full blur-3xl pointer-events-none">
        </div>
        <div class="relative z-10 max-w-2xl space-y-7">
          <span class="text-xs uppercase tracking-wider text-indigo-400 font-bold block">Start Today</span>
          <h2 class="text-3xl sm:text-5xl font-black tracking-tight leading-tight">
            Ready to Make <span class="gradient-text bg-gradient-to-r from-indigo-400 to-blue-400">Your First
              Bid?</span>
          </h2>
          <p class="text-gray-300 text-sm sm:text-base leading-relaxed">Join bidders across Pakistan and discover
            amazing deals.</p>
          <div class="flex flex-wrap gap-4 pt-2">
            <?php if (isset($_SESSION['user_id'])): ?>
              <a class="group px-7 py-3.5 rounded-xl bg-white text-slate-950 hover:bg-gray-100 font-bold text-sm flex items-center gap-2 transition-all duration-300 hover:-translate-y-0.5 hover:shadow-xl"
                href="<?php echo ($_SESSION['role'] ?? '') === 'seller' ? 'seller/dashboard.php' : 'user/dashboard.php'; ?>">
                Go to Dashboard
                <i
                  class="fa-solid fa-arrow-right text-xs group-hover:translate-x-1 transition-transform duration-300"></i>
              </a>
            <?php else: ?>
              <a class="group px-7 py-3.5 rounded-xl bg-white text-slate-950 hover:bg-gray-100 font-bold text-sm flex items-center gap-2 transition-all duration-300 hover:-translate-y-0.5 hover:shadow-xl"
                href="auth/register.php">
                Create Account
                <i
                  class="fa-solid fa-arrow-right text-xs group-hover:translate-x-1 transition-transform duration-300"></i>
              </a>
            <?php endif; ?>
            <a class="px-7 py-3.5 rounded-xl bg-slate-900/80 hover:bg-slate-800 text-white font-bold text-sm border border-gray-800 hover:border-gray-700 transition-all duration-300 hover:-translate-y-0.5"
              href="auctions.php">Browse Auctions</a>
          </div>
        </div>
      </div>
    </section>

    <!-- Newsletter -->
    <section class="py-14 bg-white dark:bg-slate-900 border-t border-gray-200 dark:border-gray-800">
      <div class="max-w-7xl mx-auto px-4 sm:px-8 flex flex-col md:flex-row items-center justify-between gap-6">
        <div>
          <span
            class="text-xs text-indigo-600 dark:text-indigo-400 font-bold uppercase tracking-wider block mb-1.5">Stay
            Updated</span>
          <h3 class="text-xl sm:text-2xl font-black text-gray-900 dark:text-white">Never Miss an Auction</h3>
          <p class="text-xs text-gray-500 dark:text-gray-400 mt-1.5">Get notifications about new auctions and exclusive
            deals.</p>
        </div>
        <form class="flex flex-col sm:flex-row w-full md:w-auto gap-3" action="#" method="post" onsubmit="return false">
          <input
            class="w-full md:w-80 px-4 py-3.5 rounded-xl bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 text-sm text-gray-900 dark:text-white focus:outline-none focus:ring-2 focus:ring-indigo-500 focus:border-transparent transition-all duration-200 placeholder:text-gray-400"
            placeholder="Enter your email address" type="email">
          <button
            class="px-6 py-3.5 bg-indigo-600 hover:bg-indigo-500 text-white text-sm font-semibold rounded-xl whitespace-nowrap transition-all duration-200 hover:shadow-lg hover:shadow-indigo-600/30 flex items-center gap-2"
            type="submit">
            Subscribe <i class="fa-solid fa-paper-plane text-xs"></i>
          </button>
        </form>
      </div>
    </section>
  </main>

  <!-- Footer -->
  <footer class="bg-slate-950 text-gray-400 text-xs border-t border-gray-900">
    <div class="max-w-7xl mx-auto px-4 sm:px-8 py-16 grid grid-cols-1 md:grid-cols-5 gap-10">
      <div class="md:col-span-2 space-y-5">
        <a class="flex items-center gap-2.5 text-2xl font-black tracking-tight text-white" href="index.php">
          <div
            class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg shadow-indigo-500/30">
            <i class="fa-solid fa-gavel text-lg"></i>
          </div>
          Nexus<span class="text-indigo-400">Bids</span>
        </a>
        <p class="text-gray-400 text-xs leading-relaxed max-w-sm">Pakistan's modern online auction marketplace.
          Discover, bid and win products you love.</p>
        <div class="flex items-center space-x-3 pt-2">
          <a aria-label="Facebook"
            class="w-10 h-10 rounded-xl bg-slate-900 border border-gray-800 flex items-center justify-center text-gray-300 hover:text-white hover:border-indigo-500/50 hover:bg-indigo-500/10 transition-all duration-200"
            href="#"><i class="fa-brands fa-facebook-f"></i></a>
          <a aria-label="Instagram"
            class="w-10 h-10 rounded-xl bg-slate-900 border border-gray-800 flex items-center justify-center text-gray-300 hover:text-white hover:border-indigo-500/50 hover:bg-indigo-500/10 transition-all duration-200"
            href="#"><i class="fa-brands fa-instagram"></i></a>
          <a aria-label="X"
            class="w-10 h-10 rounded-xl bg-slate-900 border border-gray-800 flex items-center justify-center text-gray-300 hover:text-white hover:border-indigo-500/50 hover:bg-indigo-500/10 transition-all duration-200"
            href="#"><i class="fa-brands fa-x-twitter"></i></a>
          <a aria-label="LinkedIn"
            class="w-10 h-10 rounded-xl bg-slate-900 border border-gray-800 flex items-center justify-center text-gray-300 hover:text-white hover:border-indigo-500/50 hover:bg-indigo-500/10 transition-all duration-200"
            href="#"><i class="fa-brands fa-linkedin-in"></i></a>
        </div>
      </div>
      <div class="space-y-4">
        <h4 class="text-sm font-bold text-white uppercase tracking-wider">Marketplace</h4>
        <ul class="space-y-2.5">
          <li><a class="hover:text-white transition-colors duration-200" href="auctions.php">Browse Auctions</a></li>
          <li><a class="hover:text-white transition-colors duration-200" href="auctions.php">Live Auctions</a></li>
          <li><a class="hover:text-white transition-colors duration-200" href="categories.php">Categories</a></li>
          <li><a class="hover:text-white transition-colors duration-200" href="#auctions">Featured Products</a></li>
          <li><a class="hover:text-white transition-colors duration-200" href="#how-it-works">How It Works</a></li>
        </ul>
      </div>
      <div class="space-y-4">
        <h4 class="text-sm font-bold text-white uppercase tracking-wider">Account</h4>
        <ul class="space-y-2.5">
          <?php if (isset($_SESSION['user_id'])): ?>
            <li><a class="hover:text-white transition-colors duration-200"
                href="<?php echo ($_SESSION['role'] ?? '') === 'seller' ? 'seller/dashboard.php' : 'user/dashboard.php'; ?>">My
                Dashboard</a></li>
            <li><a class="hover:text-white transition-colors duration-200" href="notifications.php">Notifications</a></li>
            <li><a class="hover:text-white transition-colors duration-200" href="auth/logout.php">Logout</a></li>
          <?php else: ?>
            <li><a class="hover:text-white transition-colors duration-200" href="auth/login.php">Login</a></li>
            <li><a class="hover:text-white transition-colors duration-200" href="auth/register.php">Register</a></li>
          <?php endif; ?>
        </ul>
      </div>
      <div class="space-y-4">
        <h4 class="text-sm font-bold text-white uppercase tracking-wider">Support</h4>
        <ul class="space-y-2.5">
          <li><a class="hover:text-white transition-colors duration-200" href="about.php">Help Center</a></li>
          <li><a class="hover:text-white transition-colors duration-200" href="about.php#contact">Contact Us</a></li>
          <li><a class="hover:text-white transition-colors duration-200" href="#why-nexusbids">Safety Center</a></li>
          <li><a class="hover:text-white transition-colors duration-200" href="about.php">Terms &amp; Conditions</a>
          </li>
          <li><a class="hover:text-white transition-colors duration-200" href="about.php">Privacy Policy</a></li>
        </ul>
      </div>
    </div>
    <div
      class="border-t border-gray-900 py-6 text-center text-xs text-gray-500 max-w-7xl mx-auto px-4 flex flex-col sm:flex-row justify-between items-center gap-4">
      <p>© 2026 NexusBids. All rights reserved.</p>
      <p>Made for Pakistan <span class="text-rose-500"><i class="fa-solid fa-heart"></i></span></p>
    </div>
  </footer>

  <script>
    (function () {
      const root = document.documentElement, btn = document.getElementById('themeToggle');
      // Mobile Menu Toggle
      const mobileMenuBtn = document.getElementById('mobileMenuBtn');
      const mobileMenu = document.getElementById('mobileMenu');
      const menuIcon = document.getElementById('menuIcon');
      if (mobileMenuBtn && mobileMenu) {
        mobileMenuBtn.addEventListener('click', function () {
          const isOpen = !mobileMenu.classList.contains('hidden');
          mobileMenu.classList.toggle('hidden');
          mobileMenuBtn.setAttribute('aria-expanded', !isOpen);
          if (menuIcon) menuIcon.className = isOpen ? 'fa-solid fa-bars' : 'fa-solid fa-xmark';
        });
      }
      const saved = localStorage.getItem('theme');
      if (saved === 'dark' || (!saved && window.matchMedia('(prefers-color-scheme: dark)').matches)) {
        root.classList.add('dark');
      }
      btn?.addEventListener('click', () => {
        root.classList.toggle('dark');
        localStorage.setItem('theme', root.classList.contains('dark') ? 'dark' : 'light');
      });

      // Countdown timer
      function updateCountdown(el) {
        const end = el.dataset.countdown;
        if (!end) return;
        const diff = Math.max(0, new Date(end).getTime() - Date.now());
        const s = Math.floor(diff / 1000);
        const h = Math.floor(s / 3600);
        const m = Math.floor((s % 3600) / 60);
        const sec = s % 60;
        const q = (c, v) => {
          const n = el.querySelector('.countdown-' + c);
          if (n) n.textContent = String(v).padStart(2, '0');
        };
        q('hours', h); q('minutes', m); q('seconds', sec);
      }
      document.querySelectorAll('[data-countdown]').forEach(el => {
        updateCountdown(el);
        setInterval(() => updateCountdown(el), 1000);
      });

      // Intersection Observer for scroll animations
      if ('IntersectionObserver' in window) {
        const observer = new IntersectionObserver((entries) => {
          entries.forEach(entry => {
            if (entry.isIntersecting) {
              entry.target.style.opacity = '1';
              entry.target.style.transform = 'translateY(0)';
              observer.unobserve(entry.target);
            }
          });
        }, {threshold: 0.1, rootMargin: '0px 0px -50px 0px'});

        document.querySelectorAll('section > div, .group').forEach(el => {
          el.style.opacity = '0';
          el.style.transform = 'translateY(20px)';
          el.style.transition = 'opacity 0.6s ease-out, transform 0.6s ease-out';
          observer.observe(el);
        });
      }
    })();
  </script>
  <?php require_once __DIR__ . '/includes/chat-widget.php'; ?>
</body>

</html>