<?php

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
date_default_timezone_set("Asia/Karachi");

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'user') {
    header("Location: ../auth/login.php");
    exit();
}

require_once __DIR__ . "/../config/database.php";
require_once __DIR__ . "/../includes/csrf.php";
require_once __DIR__ . "/../includes/notifications.php";
if (file_exists(__DIR__ . "/../includes/payment-helpers.php")) {
    require_once __DIR__ . "/../includes/payment-helpers.php";
}

$user_id = (int) $_SESSION['user_id'];
$auction_id = (int) ($_GET['auction_id'] ?? $_POST['auction_id'] ?? 0);

if ($auction_id <= 0) {
    $_SESSION['flash_error'] = "Invalid auction.";
    header("Location: orders.php");
    exit();
}

/* ---------- Helpers (inline fallback) ---------- */
if (!function_exists('nb_initials')) {
    function nb_initials(string $name, int $len = 2): string
    {
        $clean = preg_replace('/\s+/u', '', $name);
        if ($clean === '' || $clean === null)
            return 'U';
        return function_exists('mb_substr')
            ? mb_strtoupper(mb_substr($clean, 0, $len, 'UTF-8'), 'UTF-8')
            : strtoupper(substr($clean, 0, $len));
    }
}
if (!function_exists('nb_safe_date')) {
    function nb_safe_date(?string $dt, string $format = 'd M Y, h:i A'): string
    {
        if (empty($dt))
            return '';
        $ts = strtotime($dt);
        return $ts === false ? '' : date($format, $ts);
    }
}
if (!function_exists('nb_table_exists')) {
    function nb_table_exists(mysqli $conn, string $table): bool
    {
        $t = $conn->real_escape_string($table);
        $r = $conn->query("SHOW TABLES LIKE '$t'");
        return $r && $r->num_rows > 0;
    }
}
if (!function_exists('nb_column_exists')) {
    function nb_column_exists(mysqli $conn, string $table, string $column): bool
    {
        $t = $conn->real_escape_string($table);
        $c = $conn->real_escape_string($column);
        $r = $conn->query("SHOW COLUMNS FROM `$t` LIKE '$c'");
        return $r && $r->num_rows > 0;
    }
}

/* ---------- Fetch order + winner + auction + seller ---------- */
$order = null;
if (
    $stmt = $conn->prepare("
        SELECT
            o.order_id, o.order_number, o.order_status, o.payment_status,
            o.winning_bid, o.platform_fee, o.seller_amount, o.total_amount,
            o.created_at AS order_created_at,
            o.shipping_status, o.courier, o.tracking_number,
            a.auction_id, a.title, a.image, a.seller_id,
            s.full_name AS seller_name, s.avatar AS seller_avatar,
            w.winner_id
        FROM orders o
        INNER JOIN auctions a ON a.auction_id = o.auction_id
        INNER JOIN users    s ON s.user_id    = o.seller_id
        LEFT  JOIN winners  w ON w.auction_id = o.auction_id AND w.user_id = o.buyer_id
        WHERE o.auction_id = ? AND o.buyer_id = ?
        LIMIT 1
    ")
) {
    $stmt->bind_param("ii", $auction_id, $user_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$order) {
    $_SESSION['flash_error'] = "Order not found or access denied.";
    header("Location: orders.php");
    exit();
}

$payment_status = strtolower((string) $order['payment_status']);
$order_status = strtolower((string) $order['order_status']);

/* ---------- Guards ---------- */
if (in_array($payment_status, ['paid', 'completed', 'success'], true)) {
    $_SESSION['flash_success'] = "This order is already paid.";
    header("Location: order-details.php?id=" . $auction_id);
    exit();
}
if (in_array($order_status, ['cancelled', 'refunded', 'disputed'], true)) {
    $_SESSION['flash_error'] = "This order cannot be paid.";
    header("Location: order-details.php?id=" . $auction_id);
    exit();
}

/* ---------- Existing payment ---------- */
$existing_payment = null;
if (
    $ps = $conn->prepare("
    SELECT payment_id, payment_method, payment_status, transaction_id,
           sender_name, sender_number, amount, initiated_at, created_at
    FROM payments
    WHERE order_id = ?
    ORDER BY payment_id DESC
    LIMIT 1
")
) {
    $ps->bind_param("i", $order['order_id']);
    $ps->execute();
    $existing_payment = $ps->get_result()->fetch_assoc();
    $ps->close();
}

$payment_pending = false;
if ($existing_payment) {
    $existing_ps = strtolower((string) $existing_payment['payment_status']);
    $payment_pending = in_array($existing_ps, ['pending', 'processing'], true);
}

/* ---------- POST: Submit payment ---------- */
$error = "";
$success = "";

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$payment_pending) {
    nb_csrf_check();

    $payment_method = trim((string) ($_POST['payment_method'] ?? ''));
    $sender_name = trim((string) ($_POST['sender_name'] ?? ''));
    $sender_number = trim((string) ($_POST['sender_number'] ?? ''));
    $transaction_id = trim((string) ($_POST['transaction_id'] ?? ''));
    $note = trim((string) ($_POST['note'] ?? ''));

    $allowed_methods = ['jazzcash', 'easypaisa', 'bank_transfer', 'cod'];

    if (!in_array($payment_method, $allowed_methods, true)) {
        $error = "Please select a valid payment method.";
    } elseif ($payment_method !== 'cod' && $sender_name === '') {
        $error = "Sender name is required.";
    } elseif ($payment_method !== 'cod' && $sender_number === '') {
        $error = "Sender number is required.";
    } elseif ($payment_method === 'bank_transfer' && $transaction_id === '') {
        $error = "Transaction / Reference ID is required for bank transfer.";
    } elseif (mb_strlen($note) > 500) {
        $error = "Note is too long (max 500 characters).";
    } else {
        $conn->begin_transaction();
        try {
            $amount = (float) $order['total_amount'];

            /* ---- Insert payment ---- */
            $ins = $conn->prepare("
                INSERT INTO payments
                    (order_id, user_id, auction_id, payment_method, payment_status,
                     transaction_id, sender_name, sender_number, amount, initiated_at, created_at)
                VALUES (?, ?, ?, ?, 'pending', ?, ?, ?, ?, NOW(), NOW())
            ");
            $ins->bind_param(
                "iiisssssd",
                $order['order_id'],
                $user_id,
                $auction_id,
                $payment_method,
                $transaction_id,
                $sender_name,
                $sender_number,
                $amount
            );
            $ins->execute();
            $payment_id = (int) $conn->insert_id;
            $ins->close();

            /* ---- Update order status ---- */
            $upd = $conn->prepare("
                UPDATE orders
                SET payment_status = 'pending',
                    order_status = CASE
                        WHEN order_status IN ('', 'pending', 'pending_payment') THEN 'pending_payment'
                        ELSE order_status
                    END
                WHERE order_id = ?
            ");
            $upd->bind_param("i", $order['order_id']);
            $upd->execute();
            $upd->close();

            /* ---- Auto-create escrow hold ---- */
            $escrow_id = null;
            if (nb_table_exists($conn, 'escrow_holds')) {
                $ex_check = $conn->prepare("SELECT escrow_id FROM escrow_holds WHERE order_id = ? LIMIT 1");
                $ex_check->bind_param("i", $order['order_id']);
                $ex_check->execute();
                $exists = $ex_check->get_result()->fetch_assoc();
                $ex_check->close();

                if (!$exists) {
                    /* Build insert based on available columns */
                    $cols = ['order_id', 'seller_id', 'buyer_id', 'amount', 'status'];
                    $types = 'iiids';
                    $params = [
                        $order['order_id'],
                        (int) $order['seller_id'],
                        $user_id,
                        $amount,
                        'held',
                    ];

                    if (nb_column_exists($conn, 'escrow_holds', 'held_at')) {
                        $cols[] = 'held_at';
                    }
                    if (nb_column_exists($conn, 'escrow_holds', 'created_at')) {
                        $cols[] = 'created_at';
                    }

                    $placeholders = array_fill(0, 5, '?');
                    $placeholders[] = 'NOW()'; // held_at
                    if (in_array('created_at', $cols, true)) {
                        $placeholders[] = 'NOW()';
                    }

                    $sql = "INSERT INTO escrow_holds (" . implode(',', $cols) . ") VALUES (" . implode(',', $placeholders) . ")";
                    $esc = $conn->prepare($sql);
                    if ($esc) {
                        $esc->bind_param($types, ...$params);
                        $esc->execute();
                        $escrow_id = (int) $conn->insert_id;
                        $esc->close();
                    }
                } else {
                    $escrow_id = (int) $exists['escrow_id'];
                }
            }

            /* ---- Audit log ---- */
            if (function_exists('nb_pay_audit')) {
                try {
                    nb_pay_audit($conn, [
                        'actor_id' => $user_id,
                        'actor_type' => 'buyer',
                        'entity_type' => 'payment',
                        'entity_id' => $payment_id,
                        'action' => 'payment.initiated',
                        'new' => [
                            'order_id' => $order['order_id'],
                            'method' => $payment_method,
                            'amount' => $amount,
                            'escrow_id' => $escrow_id,
                            'transaction_id' => $transaction_id,
                        ],
                    ]);
                } catch (Throwable $e) {
                }
            }

            /* ---- Notify admins ---- */
            try {
                $ar = $conn->query("SELECT user_id FROM users WHERE role = 'admin'");
                if ($ar) {
                    while ($row = $ar->fetch_assoc()) {
                        if (function_exists('nb_notify')) {
                            nb_notify(
                                $conn,
                                (int) $row['user_id'],
                                'payment',
                                'New Payment Submitted',
                                'Order ' . $order['order_number'] . ' — Rs. ' . number_format($amount)
                                . ' via ' . ucfirst(str_replace('_', ' ', $payment_method))
                                . '. Awaiting verification.',
                                'admin/payments.php'
                            );
                        }
                    }
                }
            } catch (Throwable $e) {
            }

            /* ---- Notify seller ---- */
            if (function_exists('nb_notify')) {
                try {
                    nb_notify(
                        $conn,
                        (int) $order['seller_id'],
                        'order',
                        'Buyer Initiated Payment',
                        'Buyer has submitted payment for "' . $order['title']
                        . '". Funds will be held in escrow until delivery is confirmed.',
                        'seller/orders.php'
                    );
                } catch (Throwable $e) {
                }
            }

            $conn->commit();

            $_SESSION['flash_success'] = "Payment submitted successfully! Admin will verify and confirm shortly. Funds are held in escrow until you confirm receipt.";
            header("Location: order-details.php?id=" . $auction_id);
            exit();

        } catch (Throwable $e) {
            $conn->rollback();
            error_log("checkout.php failed: " . $e->getMessage());
            $error = "Could not process payment. Please try again or contact support.";
        }
    }
}

/* ---------- User info for navbar ---------- */
$u_stmt = $conn->prepare("SELECT full_name, avatar FROM users WHERE user_id = ?");
$u_stmt->bind_param("i", $user_id);
$u_stmt->execute();
$uinfo = $u_stmt->get_result()->fetch_assoc();
$u_stmt->close();

$full_name = $uinfo['full_name'] ?? 'User';
$avatar = $uinfo['avatar'] ?? '';
$initials = nb_initials($full_name, 2);
$unread_notifications = function_exists('nb_unread_count') ? nb_unread_count($conn, $user_id) : 0;

/* ---------- Image ---------- */
$image_url = '';
if (!empty($order['image'])) {
    $b = basename($order['image']);
    if (file_exists(__DIR__ . '/../uploads/auctions/' . $b)) {
        $image_url = '../uploads/auctions/' . rawurlencode($b);
    }
}

/* ---------- Payment method config ---------- */
$methods = [
    'jazzcash' => [
        'label' => 'JazzCash',
        'icon' => 'fa-mobile-screen-button',
        'color' => 'rose',
        'subtitle' => 'Mobile Wallet',
        'account' => '0300-1234567',
        'account_label' => 'JazzCash Number',
    ],
    'easypaisa' => [
        'label' => 'EasyPaisa',
        'icon' => 'fa-mobile-screen-button',
        'color' => 'emerald',
        'subtitle' => 'Mobile Wallet',
        'account' => '0345-7654321',
        'account_label' => 'EasyPaisa Number',
    ],
    'bank_transfer' => [
        'label' => 'Bank Transfer',
        'icon' => 'fa-building-columns',
        'color' => 'indigo',
        'subtitle' => 'IBAN Transfer',
        'account' => 'PK36SCBL0000001123456702',
        'account_label' => 'IBAN',
    ],
    'cod' => [
        'label' => 'Cash on Delivery',
        'icon' => 'fa-hand-holding-dollar',
        'color' => 'amber',
        'subtitle' => 'Pay when delivered',
        'account' => '',
        'account_label' => '',
    ],
];
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Checkout · Order <?= htmlspecialchars((string) $order['order_number']) ?> · NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>
        tailwind.config = { darkMode: 'class', theme: { extend: { fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] }, animation: { 'pulse-soft': 'pulseSoft 2s ease-in-out infinite', 'fade-up': 'fadeUp 0.6s ease-out forwards' }, keyframes: { pulseSoft: { '0%, 100%': { opacity: '1' }, '50%': { opacity: '0.5' } }, fadeUp: { '0%': { opacity: '0', transform: 'translateY(12px)' }, '100%': { opacity: '1', transform: 'translateY(0)' } } } } } };
        (function () { try { const s = localStorage.getItem('theme'); const d = window.matchMedia('(prefers-color-scheme: dark)').matches; if (s === 'dark' || (!s && d)) document.documentElement.classList.add('dark'); } catch (e) { } })();
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

        .method-card {
            transition: all .2s ease;
        }

        .method-card:hover {
            transform: translateY(-2px);
        }

        input[type="radio"].method-radio:checked+.method-card {
            border-color: #6366f1;
            background: linear-gradient(to bottom right, rgba(99, 102, 241, .06), rgba(139, 92, 246, .06));
            box-shadow: 0 10px 25px -10px rgba(99, 102, 241, .4);
        }

        input[type="radio"].method-radio:checked+.method-card .method-check {
            opacity: 1;
            transform: scale(1);
        }

        .method-check {
            opacity: 0;
            transform: scale(.5);
            transition: all .2s ease;
        }

        /* Copy animation */
        .copy-flash {
            animation: copyFlash 1s ease;
        }

        @keyframes copyFlash {

            0%,
            100% {
                background-color: transparent;
            }

            30% {
                background-color: rgba(16, 185, 129, .15);
            }
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 font-sans antialiased min-h-screen">

    <!-- Top Utility Bar -->
    <div class="bg-gray-950 text-gray-400 text-xs py-2.5 px-4 sm:px-8 border-b border-gray-800/50">
        <div class="max-w-7xl mx-auto flex flex-col sm:flex-row justify-between items-center gap-2">
            <div class="flex items-center space-x-6">
                <span class="flex items-center gap-2"><i class="fa-solid fa-location-dot text-indigo-400"></i>
                    Pakistan</span>
                <span class="hidden sm:flex items-center gap-2"><i class="fa-solid fa-phone text-indigo-400"></i> +92
                    300 1234567</span>
            </div>
            <div class="flex items-center space-x-6">
                <a href="../about.php" class="hover:text-white"><i
                        class="fa-regular fa-circle-question text-indigo-400"></i> Help Center</a>
                <a href="../about.php#why-nexusbids" class="hover:text-white"><i
                        class="fa-solid fa-shield-halved text-indigo-400"></i> Secure Bidding</a>
            </div>
        </div>
    </div>

    <!-- Navbar -->
    <header
        class="sticky top-0 z-50 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
        <div class="max-w-7xl mx-auto px-4 sm:px-8 h-20 flex items-center justify-between gap-4">
            <a class="flex items-center gap-2.5 text-2xl font-black tracking-tight shrink-0 group" href="../index.php">
                <span
                    class="w-10 h-10 rounded-xl bg-gradient-to-tr from-indigo-600 to-violet-500 flex items-center justify-center text-white shadow-lg group-hover:scale-105 transition-all"><i
                        class="fa-solid fa-gavel text-lg"></i></span>
                Nexus<span class="text-indigo-600 dark:text-indigo-400">Bids</span>
            </a>
            <nav class="hidden lg:flex items-center gap-7 text-sm font-medium">
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 transition"
                    href="../index.php">Home</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 transition"
                    href="../auctions.php">Auctions</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 transition"
                    href="../categories.php">Categories</a>
                <a class="relative text-indigo-600 dark:text-indigo-400 font-semibold after:absolute after:-bottom-1 after:left-0 after:w-full after:h-0.5 after:bg-indigo-600 after:rounded-full"
                    href="dashboard.php">Dashboard</a>
                <a class="text-gray-600 dark:text-gray-300 hover:text-indigo-600 transition"
                    href="../about.php">About</a>
            </nav>
            <div class="flex items-center gap-2 sm:gap-3">
                <button aria-label="Toggle Theme" id="themeToggle"
                    class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:scale-105 transition">
                    <i class="fa-solid fa-moon dark:hidden"></i><i
                        class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                </button>
                <a href="../notifications.php"
                    class="relative w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center hover:scale-105 transition">
                    <i class="fa-regular fa-bell"></i>
                    <?php if ($unread_notifications > 0): ?><span
                            class="absolute top-1.5 right-1.5 w-2.5 h-2.5 rounded-full bg-rose-500 border-2 border-white dark:border-gray-800 animate-pulse-soft"></span><?php endif; ?>
                </a>
                <div class="flex items-center gap-3 pl-3 border-l border-gray-200 dark:border-gray-800">
                    <div class="relative">
                        <?php if (!empty($avatar)): ?>
                            <img src="../uploads/avatars/<?= htmlspecialchars($avatar) ?>"
                                class="w-10 h-10 rounded-full object-cover ring-2 ring-white dark:ring-slate-900" alt="">
                        <?php else: ?>
                            <div
                                class="w-10 h-10 rounded-full bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold text-xs flex items-center justify-center shadow-lg ring-2 ring-white dark:ring-slate-900">
                                <?= htmlspecialchars($initials) ?></div>
                        <?php endif; ?>
                        <span
                            class="absolute -bottom-0.5 -right-0.5 w-3 h-3 rounded-full bg-emerald-500 border-2 border-white dark:border-slate-900"></span>
                    </div>
                    <div class="hidden sm:block text-left">
                        <div class="text-sm font-bold leading-tight truncate max-w-32">
                            <?= htmlspecialchars($full_name) ?></div>
                        <div
                            class="text-[11px] text-emerald-600 dark:text-emerald-400 font-medium flex items-center gap-1">
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
    </header>

    <main class="max-w-5xl mx-auto px-4 sm:px-8 py-8 space-y-6">

        <!-- Breadcrumb -->
        <nav class="flex items-center gap-2 text-xs text-gray-500 dark:text-gray-400">
            <a href="dashboard.php" class="hover:text-indigo-600">Dashboard</a>
            <i class="fa-solid fa-chevron-right text-[8px]"></i>
            <a href="orders.php" class="hover:text-indigo-600">My Orders</a>
            <i class="fa-solid fa-chevron-right text-[8px]"></i>
            <span class="text-gray-900 dark:text-white font-semibold">Checkout</span>
        </nav>

        <?php if (!empty($error)): ?>
            <div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 flex items-start gap-3">
                <i class="fa-solid fa-circle-exclamation text-rose-600 mt-0.5"></i>
                <p class="text-sm text-rose-700 dark:text-rose-300 font-medium"><?= htmlspecialchars($error) ?></p>
            </div>
        <?php endif; ?>

        <!-- Header -->
        <div>
            <h1 class="text-3xl font-black tracking-tight flex items-center gap-3">
                <span
                    class="w-12 h-12 rounded-2xl bg-gradient-to-br from-emerald-500 to-teal-500 flex items-center justify-center text-white shadow-lg"><i
                        class="fa-solid fa-credit-card text-lg"></i></span>
                Complete Payment
            </h1>
            <p class="text-sm text-gray-500 dark:text-gray-400 mt-2">Submit your payment details. Funds will be held in
                escrow until you confirm receipt.</p>
        </div>

        <!-- Already pending banner -->
        <?php if ($payment_pending && $existing_payment): ?>
            <div class="p-5 rounded-2xl bg-blue-500/10 border border-blue-500/30 flex items-start gap-4">
                <div
                    class="w-11 h-11 rounded-xl bg-blue-500/20 text-blue-600 dark:text-blue-400 flex items-center justify-center text-lg shrink-0">
                    <i class="fa-solid fa-hourglass-half"></i>
                </div>
                <div class="flex-1">
                    <h3 class="font-bold text-blue-900 dark:text-blue-300 text-sm">Payment already submitted</h3>
                    <p class="text-xs text-blue-800/80 dark:text-blue-300/80 mt-1 leading-relaxed">
                        You submitted payment via
                        <strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', (string) $existing_payment['payment_method']))) ?></strong>
                        <?= !empty($existing_payment['initiated_at']) ? 'on ' . htmlspecialchars(nb_safe_date($existing_payment['initiated_at'])) : '' ?>.
                        Awaiting admin verification.
                    </p>
                    <div class="flex flex-wrap gap-2 mt-3">
                        <a href="order-details.php?id=<?= $auction_id ?>"
                            class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-blue-600 hover:bg-blue-500 text-white text-xs font-bold">
                            <i class="fa-solid fa-eye text-[10px]"></i> View Order
                        </a>
                        <a href="orders.php"
                            class="inline-flex items-center gap-1.5 px-4 py-2 rounded-xl bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-800 text-xs font-bold">
                            <i class="fa-solid fa-arrow-left text-[10px]"></i> Back to Orders
                        </a>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <div class="grid grid-cols-1 lg:grid-cols-12 gap-6 items-start">

            <!-- LEFT: Order summary -->
            <div class="lg:col-span-5 space-y-5 animate-fade-up">
                <div
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                    <div class="relative aspect-video bg-gray-100 dark:bg-slate-800 overflow-hidden">
                        <?php if ($image_url): ?>
                            <img src="<?= htmlspecialchars($image_url) ?>" class="w-full h-full object-cover" alt="">
                        <?php else: ?>
                            <div class="w-full h-full flex items-center justify-center text-gray-400"><i
                                    class="fa-solid fa-image text-4xl"></i></div>
                        <?php endif; ?>
                        <div
                            class="absolute top-3 left-3 inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full bg-amber-500 text-white text-[10px] font-bold uppercase tracking-wider">
                            <i class="fa-solid fa-trophy text-[9px]"></i> Won
                        </div>
                    </div>
                    <div class="p-5">
                        <div class="text-[10px] uppercase tracking-wider font-bold text-gray-400">Order #</div>
                        <div class="font-mono text-sm font-bold text-gray-900 dark:text-white mt-0.5">
                            <?= htmlspecialchars((string) $order['order_number']) ?></div>
                        <h3 class="font-bold leading-snug mt-2 line-clamp-2">
                            <?= htmlspecialchars((string) $order['title']) ?></h3>
                    </div>
                </div>

                <!-- Seller -->
                <div
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm p-5">
                    <div class="flex items-center gap-3">
                        <?php if (!empty($order['seller_avatar'])): ?>
                            <img src="../uploads/avatars/<?= htmlspecialchars($order['seller_avatar']) ?>"
                                class="w-12 h-12 rounded-xl object-cover" alt="">
                        <?php else: ?>
                            <div
                                class="w-12 h-12 rounded-xl bg-gradient-to-br from-violet-500 to-indigo-500 text-white font-bold text-sm flex items-center justify-center">
                                <?= htmlspecialchars(nb_initials((string) $order['seller_name'], 2)) ?></div>
                        <?php endif; ?>
                        <div class="min-w-0 flex-1">
                            <div class="text-[10px] uppercase tracking-wider font-bold text-gray-400">Seller</div>
                            <div class="font-bold truncate"><?= htmlspecialchars((string) $order['seller_name']) ?></div>
                        </div>
                    </div>
                </div>

                <!-- Amount breakdown -->
                <div
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">
                    <div class="p-5 border-b border-gray-100 dark:border-gray-800">
                        <h3 class="text-sm font-bold flex items-center gap-2"><i
                                class="fa-solid fa-receipt text-indigo-500"></i> Payment Summary</h3>
                    </div>
                    <div class="p-5 space-y-3 text-sm">
                        <div class="flex justify-between"><span class="text-gray-500">Winning Bid</span><span
                                class="font-semibold tabular-nums">Rs.
                                <?= number_format((float) $order['winning_bid']) ?></span></div>
                        <div class="flex justify-between"><span class="text-gray-500">Platform Fee</span><span
                                class="font-semibold tabular-nums">Rs.
                                <?= number_format((float) $order['platform_fee']) ?></span></div>
                        <div
                            class="pt-3 border-t border-gray-100 dark:border-gray-800 flex justify-between items-baseline">
                            <span class="font-bold">Total Payable</span>
                            <span class="text-2xl font-black text-emerald-600 dark:text-emerald-400 tabular-nums">Rs.
                                <?= number_format((float) $order['total_amount']) ?></span>
                        </div>
                    </div>
                </div>

                <!-- Escrow protection -->
                <div
                    class="relative rounded-3xl bg-slate-950 text-white p-5 overflow-hidden shadow-xl border border-gray-800">
                    <div
                        class="absolute inset-0 bg-[radial-gradient(ellipse_at_top_right,_var(--tw-gradient-stops))] from-emerald-900/40 via-slate-950 to-slate-950">
                    </div>
                    <div class="relative">
                        <div class="flex items-center gap-2 mb-3">
                            <i class="fa-solid fa-shield-halved text-emerald-400"></i>
                            <h4 class="text-sm font-bold">Escrow Protected</h4>
                        </div>
                        <p class="text-[11px] text-gray-300 leading-relaxed">
                            Your money is held safely in escrow. The seller only receives payout after you confirm
                            receipt of the item.
                        </p>
                    </div>
                </div>
            </div>

            <!-- RIGHT: Payment form -->
            <div class="lg:col-span-7 animate-fade-up" style="animation-delay:.05s">
                <div
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm overflow-hidden">

                    <?php if ($payment_pending): ?>
                        <!-- Locked state -->
                        <div class="p-8 text-center">
                            <div
                                class="w-20 h-20 mx-auto rounded-full bg-gradient-to-br from-blue-500 to-indigo-500 flex items-center justify-center text-white text-3xl shadow-lg mb-5">
                                <i class="fa-solid fa-lock"></i>
                            </div>
                            <h2 class="text-2xl font-black mb-2">Form Locked</h2>
                            <p class="text-sm text-gray-500 dark:text-gray-400 max-w-md mx-auto">You already have a pending
                                payment for this order. Please wait for admin verification before submitting a new one.</p>
                        </div>
                    <?php else: ?>

                        <div class="p-5 sm:p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                            <span
                                class="w-10 h-10 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center text-sm"><i
                                    class="fa-solid fa-money-bill-transfer"></i></span>
                            <div>
                                <h2 class="text-base font-bold">Payment Method</h2>
                                <p class="text-xs text-gray-500 mt-0.5">Choose how you'd like to pay</p>
                            </div>
                        </div>

                        <form method="POST" class="p-5 sm:p-6 space-y-6" id="checkoutForm">
                            <?php nb_csrf_field(); ?>
                            <input type="hidden" name="auction_id" value="<?= $auction_id ?>">

                            <!-- Method selection -->
                            <div>
                                <label class="block text-xs font-bold uppercase tracking-wider mb-3">Select Method <span
                                        class="text-rose-500">*</span></label>
                                <div class="grid grid-cols-2 gap-3">
                                    <?php foreach ($methods as $key => $m): ?>
                                        <label class="cursor-pointer">
                                            <input type="radio" name="payment_method" value="<?= $key ?>"
                                                class="method-radio sr-only" required>
                                            <div
                                                class="method-card relative p-4 rounded-2xl border-2 border-gray-200 dark:border-gray-700 bg-white dark:bg-slate-800 h-full">
                                                <div
                                                    class="method-check absolute top-2 right-2 w-6 h-6 rounded-full bg-indigo-600 text-white flex items-center justify-center">
                                                    <i class="fa-solid fa-check text-[10px]"></i>
                                                </div>
                                                <div class="flex flex-col items-center text-center gap-2">
                                                    <div
                                                        class="w-12 h-12 rounded-xl bg-<?= $m['color'] ?>-500/10 text-<?= $m['color'] ?>-600 dark:text-<?= $m['color'] ?>-400 flex items-center justify-center text-lg">
                                                        <i class="fa-solid <?= $m['icon'] ?>"></i>
                                                    </div>
                                                    <div>
                                                        <div class="text-sm font-bold"><?= htmlspecialchars($m['label']) ?>
                                                        </div>
                                                        <div class="text-[10px] text-gray-500 mt-0.5">
                                                            <?= htmlspecialchars($m['subtitle']) ?></div>
                                                    </div>
                                                </div>
                                            </div>
                                        </label>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Account details (dynamic) -->
                            <div id="accountBox" class="hidden p-4 rounded-2xl bg-indigo-500/5 border border-indigo-500/20">
                                <div class="flex items-start gap-3">
                                    <div
                                        class="w-9 h-9 rounded-lg bg-indigo-500/15 text-indigo-600 dark:text-indigo-400 flex items-center justify-center text-xs shrink-0">
                                        <i class="fa-solid fa-circle-info"></i>
                                    </div>
                                    <div class="flex-1 min-w-0">
                                        <div class="text-[10px] uppercase font-bold text-gray-400 tracking-wider">Send
                                            payment to</div>
                                        <div class="flex items-center gap-2 mt-1">
                                            <div id="accountValue"
                                                class="font-mono text-sm font-bold text-gray-900 dark:text-white break-all">
                                            </div>
                                            <button type="button" id="copyAccount"
                                                class="shrink-0 w-8 h-8 rounded-lg bg-white dark:bg-slate-900 border border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 flex items-center justify-center hover:bg-indigo-600 hover:text-white transition"
                                                title="Copy">
                                                <i class="fa-regular fa-copy text-xs"></i>
                                            </button>
                                        </div>
                                        <div class="text-[11px] text-gray-500 mt-1.5" id="accountHint"></div>
                                    </div>
                                </div>
                            </div>

                            <!-- COD notice -->
                            <div id="codBox" class="hidden p-4 rounded-2xl bg-amber-500/10 border border-amber-500/20">
                                <div class="flex items-start gap-3">
                                    <i
                                        class="fa-solid fa-hand-holding-dollar text-amber-600 dark:text-amber-400 mt-0.5"></i>
                                    <div class="text-xs text-amber-800 dark:text-amber-300">
                                        <strong>Cash on Delivery</strong> — Pay the courier in cash when the item arrives.
                                        No advance payment needed.
                                    </div>
                                </div>
                            </div>

                            <!-- Sender details -->
                            <div id="senderFields" class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label for="sender_name"
                                        class="block text-xs font-bold uppercase tracking-wider mb-2">Sender Name <span
                                            class="text-rose-500">*</span></label>
                                    <div class="relative">
                                        <i
                                            class="fa-solid fa-user absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                                        <input type="text" name="sender_name" id="sender_name" maxlength="120"
                                            placeholder="Name on account"
                                            class="w-full pl-10 pr-3 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    </div>
                                </div>
                                <div>
                                    <label for="sender_number"
                                        class="block text-xs font-bold uppercase tracking-wider mb-2">Sender Number <span
                                            class="text-rose-500">*</span></label>
                                    <div class="relative">
                                        <i
                                            class="fa-solid fa-phone absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                                        <input type="text" name="sender_number" id="sender_number" maxlength="30"
                                            placeholder="03XX-XXXXXXX"
                                            class="w-full pl-10 pr-3 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    </div>
                                </div>
                                <div class="sm:col-span-2">
                                    <label for="transaction_id"
                                        class="block text-xs font-bold uppercase tracking-wider mb-2">
                                        Transaction / Reference ID <span id="txnRequired" class="text-rose-500">*</span>
                                    </label>
                                    <div class="relative">
                                        <i
                                            class="fa-solid fa-hashtag absolute left-3.5 top-1/2 -translate-y-1/2 text-gray-400 text-sm"></i>
                                        <input type="text" name="transaction_id" id="transaction_id" maxlength="100"
                                            placeholder="e.g. TXN123456789"
                                            class="w-full pl-10 pr-3 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                    </div>
                                    <p class="text-[11px] text-gray-400 mt-1.5" id="txnHint">Found in your payment receipt /
                                        app history.</p>
                                </div>
                                <div class="sm:col-span-2">
                                    <label for="note" class="block text-xs font-bold uppercase tracking-wider mb-2">Note
                                        <span class="text-gray-400 font-normal normal-case">(optional)</span></label>
                                    <textarea name="note" id="note" rows="2" maxlength="500"
                                        placeholder="Any additional info for admin..."
                                        class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-indigo-500 resize-none"></textarea>
                                </div>
                            </div>

                            <!-- Info banner -->
                            <div
                                class="p-4 rounded-2xl bg-indigo-500/10 border border-indigo-500/20 flex items-start gap-3">
                                <i class="fa-solid fa-shield-halved text-indigo-600 dark:text-indigo-400 mt-0.5"></i>
                                <p class="text-[11px] text-indigo-800 dark:text-indigo-300 leading-relaxed">
                                    After submitting, admin will verify your payment. Once confirmed, funds are locked in
                                    escrow until you confirm delivery.
                                </p>
                            </div>

                            <div class="pt-2 flex flex-col sm:flex-row items-stretch sm:items-center gap-3">
                                <a href="order-details.php?id=<?= $auction_id ?>"
                                    class="inline-flex items-center justify-center gap-2 px-5 py-3 rounded-xl bg-gray-100 dark:bg-slate-800 text-gray-700 dark:text-gray-200 text-sm font-semibold transition">
                                    <i class="fa-solid fa-xmark text-xs"></i> Cancel
                                </a>
                                <button type="submit"
                                    class="group flex-1 inline-flex items-center justify-center gap-2 px-6 py-3 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white text-sm font-bold shadow-lg shadow-emerald-600/30 transition hover:-translate-y-0.5">
                                    <i class="fa-solid fa-paper-plane text-xs"></i> Submit Payment
                                    <i
                                        class="fa-solid fa-arrow-right text-xs group-hover:translate-x-0.5 transition-transform"></i>
                                </button>
                            </div>
                        </form>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </main>

    <script>
        (function () {
            const methodsConfig = {
                jazzcash: { account: '0300-1234567', hint: 'Send Rs. {amount} to this JazzCash account, then enter your sender details below.' },
                easypaisa: { account: '0345-7654321', hint: 'Send Rs. {amount} to this EasyPaisa account, then enter your sender details below.' },
                bank_transfer: { account: 'PK36SCBL0000001123456702', hint: 'Transfer Rs. {amount} to this IBAN via any bank app. Enter the reference/transaction ID below.' },
                cod: { account: '', hint: '' }
            };
            const amount = <?= (float) $order['total_amount'] ?>;

            const accountBox = document.getElementById('accountBox');
            const accountValue = document.getElementById('accountValue');
            const accountHint = document.getElementById('accountHint');
            const codBox = document.getElementById('codBox');
            const senderFields = document.getElementById('senderFields');
            const senderName = document.getElementById('sender_name');
            const senderNumber = document.getElementById('sender_number');
            const txnId = document.getElementById('transaction_id');
            const txnReq = document.getElementById('txnRequired');
            const copyBtn = document.getElementById('copyAccount');

            function applyMethod(val) {
                if (!val) return;
                const cfg = methodsConfig[val] || {};
                const isCod = val === 'cod';

                // Show/hide
                accountBox.classList.toggle('hidden', isCod || !cfg.account);
                codBox.classList.toggle('hidden', !isCod);
                senderFields.style.opacity = isCod ? '0.5' : '1';
                senderFields.style.pointerEvents = isCod ? 'none' : '';

                // Account info
                if (!isCod && cfg.account) {
                    accountValue.textContent = cfg.account;
                    accountHint.textContent = (cfg.hint || '').replace('{amount}', 'Rs. ' + amount.toLocaleString());
                }

                // Required flags
                if (isCod) {
                    senderName.required = false;
                    senderNumber.required = false;
                    txnId.required = false;
                    txnReq.classList.add('hidden');
                } else {
                    senderName.required = true;
                    senderNumber.required = true;
                    const needTxn = (val === 'bank_transfer');
                    txnId.required = needTxn;
                    txnReq.classList.toggle('hidden', !needTxn);
                }
            }

            document.querySelectorAll('input[name="payment_method"]').forEach(r => {
                r.addEventListener('change', e => applyMethod(e.target.value));
            });

            // Copy button
            copyBtn?.addEventListener('click', () => {
                const txt = accountValue.textContent.trim();
                if (!txt) return;
                navigator.clipboard.writeText(txt).then(() => {
                    copyBtn.classList.add('copy-flash');
                    copyBtn.innerHTML = '<i class="fa-solid fa-check text-xs"></i>';
                    setTimeout(() => {
                        copyBtn.classList.remove('copy-flash');
                        copyBtn.innerHTML = '<i class="fa-regular fa-copy text-xs"></i>';
                    }, 1400);
                }).catch(() => { });
            });

            // Theme toggle
            const btn = document.getElementById('themeToggle');
            btn?.addEventListener('click', () => {
                const isDark = document.documentElement.classList.toggle('dark');
                try { localStorage.setItem('theme', isDark ? 'dark' : 'light'); } catch (e) { }
            });

            // Pre-select if only one, otherwise leave blank
            // (leave blank — buyer must choose)
        })();
    </script>
    <?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>