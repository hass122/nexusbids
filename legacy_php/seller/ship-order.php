<?php
session_start();

if (!isset($_SESSION["user_id"]) || $_SESSION["role"] !== "seller") {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/notifications.php";
require_once "../includes/order-proofs.php";

date_default_timezone_set('Asia/Karachi');

$seller_id = intval($_SESSION["user_id"]);
$order_id = intval($_GET["id"] ?? $_POST["order_id"] ?? 0);

if ($order_id <= 0) {
    header("Location: orders.php");
    exit();
}

/* Seller info */
$s = $conn->prepare("SELECT full_name, avatar FROM users WHERE user_id = ?");
$s->bind_param("i", $seller_id);
$s->execute();
$seller_info = $s->get_result()->fetch_assoc();
$s->close();

$full_name = $seller_info["full_name"] ?? "Seller";
$avatar = $seller_info["avatar"] ?? "";
$initials = strtoupper(substr(preg_replace('/\s+/', '', $full_name), 0, 2));
$unread_notifications = nb_unread_count($conn, $seller_id);

$sidebar_total = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id")->fetch_assoc()["t"] ?? 0;
$sidebar_active = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'active'")->fetch_assoc()["t"] ?? 0;
$sidebar_pending = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'pending'")->fetch_assoc()["t"] ?? 0;
$sidebar_drafts = $conn->query("SELECT COUNT(*) AS t FROM auctions WHERE seller_id = $seller_id AND auction_status = 'draft'")->fetch_assoc()["t"] ?? 0;

/* Fetch order */
$stmt = $conn->prepare("
    SELECT o.*, a.title AS auction_title, a.image AS auction_image, a.auction_id,
           b.full_name AS buyer_name, b.email AS buyer_email
    FROM orders o
    INNER JOIN auctions a ON o.auction_id = a.auction_id
    INNER JOIN users b ON o.buyer_id = b.user_id
    WHERE o.order_id = ? AND o.seller_id = ?
    LIMIT 1
");
$stmt->bind_param("ii", $order_id, $seller_id);
$stmt->execute();
$order = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$order) {
    $_SESSION["error"] = "Order not found or you don't have permission.";
    header("Location: orders.php");
    exit();
}

/* Existing proofs */
$existing_proofs = nb_proof_list($conn, $order_id, 'seller');
$has_packing_video = false;
$item_photo_count = 0;
foreach ($existing_proofs as $p) {
    if ($p['proof_type'] === 'packing_video')
        $has_packing_video = true;
    if ($p['proof_type'] === 'item_photo')
        $item_photo_count++;
}

$error = '';

/* =========================================================
   HANDLE POST — Ship with proof
========================================================= */
if ($_SERVER["REQUEST_METHOD"] === "POST") {
    nb_csrf_check();
    $action = $_POST['action'] ?? '';

    if ($action === 'ship') {
        $courier = trim($_POST['courier'] ?? '');
        $tracking = trim($_POST['tracking_number'] ?? '');
        $est_delivery = trim($_POST['estimated_delivery'] ?? '');
        $notes = trim($_POST['shipping_notes'] ?? '');

        // Basic validation
        if ($courier === '' || $tracking === '') {
            $error = 'Please enter both courier name and tracking number.';
        }
        // Require packing video
        elseif (empty($_FILES['packing_video']) || $_FILES['packing_video']['error'] !== UPLOAD_ERR_OK) {
            $error = 'Packing video is required. Please record your item being packed.';
        }
        // Require at least 2 item photos
        elseif (empty($_FILES['item_photos']['name'][0])) {
            $error = 'At least 2 item photos are required.';
        } else {
            $conn->begin_transaction();
            try {
                // Save packing video
                $vid = nb_proof_save($_FILES['packing_video'], $order_id, $seller_id, 'packing_video');
                if (!$vid['ok'])
                    throw new RuntimeException('Packing video: ' . $vid['error']);
                if ($vid['file_type'] !== 'video')
                    throw new RuntimeException('Packing video must be a video file.');

                $vid_id = nb_proof_insert($conn, $order_id, $seller_id, 'seller', 'packing_video', $vid, 'Packing video');
                if (!$vid_id)
                    throw new RuntimeException('Failed to save video record.');

                // Save item photos
                $photo_count = 0;
                $total_photos = count($_FILES['item_photos']['name']);
                for ($i = 0; $i < $total_photos; $i++) {
                    if ($_FILES['item_photos']['error'][$i] !== UPLOAD_ERR_OK)
                        continue;

                    $single = [
                        'tmp_name' => $_FILES['item_photos']['tmp_name'][$i],
                        'name' => $_FILES['item_photos']['name'][$i],
                        'size' => $_FILES['item_photos']['size'][$i],
                        'error' => $_FILES['item_photos']['error'][$i],
                        'type' => $_FILES['item_photos']['type'][$i],
                    ];
                    $ph = nb_proof_save($single, $order_id, $seller_id, 'item_photo');
                    if ($ph['ok'] && $ph['file_type'] === 'image') {
                        nb_proof_insert($conn, $order_id, $seller_id, 'seller', 'item_photo', $ph, null);
                        $photo_count++;
                    }
                }
                if ($photo_count < 2) {
                    throw new RuntimeException('At least 2 valid item photos required. Got: ' . $photo_count);
                }

                // Save courier receipt (optional)
                if (!empty($_FILES['courier_receipt']) && $_FILES['courier_receipt']['error'] === UPLOAD_ERR_OK) {
                    $cr = nb_proof_save($_FILES['courier_receipt'], $order_id, $seller_id, 'courier_receipt');
                    if ($cr['ok']) {
                        nb_proof_insert($conn, $order_id, $seller_id, 'seller', 'courier_receipt', $cr, null);
                    }
                }

                // Update order
                $has_est = $conn->query("SHOW COLUMNS FROM orders LIKE 'estimated_delivery'")->num_rows > 0;
                if ($has_est && $est_delivery !== '') {
                    $upd = $conn->prepare("
                        UPDATE orders
                        SET order_status='shipped', shipping_status='shipped',
                            courier=?, tracking_number=?, estimated_delivery=?, shipped_at=NOW(),
                            seller_proof_uploaded_at=NOW()
                        WHERE order_id=? AND seller_id=?
                    ");
                    $upd->bind_param("sssii", $courier, $tracking, $est_delivery, $order_id, $seller_id);
                } else {
                    $upd = $conn->prepare("
                        UPDATE orders
                        SET order_status='shipped', shipping_status='shipped',
                            courier=?, tracking_number=?, shipped_at=NOW(),
                            seller_proof_uploaded_at=NOW()
                        WHERE order_id=? AND seller_id=?
                    ");
                    $upd->bind_param("ssii", $courier, $tracking, $order_id, $seller_id);
                }
                $upd->execute();
                $upd->close();

                // Audit
                nb_proof_audit($conn, $order_id, null, 'seller.shipped_with_proof', $seller_id, 'seller', [
                    'courier' => $courier,
                    'tracking' => $tracking,
                    'video_id' => $vid_id,
                    'photos' => $photo_count,
                ]);

                // Notify buyer
                if (function_exists('nb_notify')) {
                    nb_notify(
                        $conn,
                        $order['buyer_id'],
                        'shipment',
                        'Your order has been shipped!',
                        'Seller uploaded packing proof. Tracking: ' . $tracking . ' via ' . $courier,
                        'user/order-details.php?id=' . $order['auction_id']
                    );
                }

                // Notify admins
                $ar = $conn->query("SELECT user_id FROM users WHERE role = 'admin'");
                if ($ar) {
                    while ($a = $ar->fetch_assoc()) {
                        if (function_exists('nb_notify')) {
                            nb_notify(
                                $conn,
                                (int) $a['user_id'],
                                'order',
                                'Seller Shipped Order',
                                'Seller shipped order ' . $order['order_number'] . ' with proof. Awaiting buyer confirmation.',
                                'admin/order-verifications.php'
                            );
                        }
                    }
                }

                $conn->commit();
                $_SESSION['ship_success'] = 'Order shipped! Video + photos uploaded. Buyer will verify on delivery.';
                header("Location: orders.php");
                exit();

            } catch (Throwable $e) {
                $conn->rollback();
                error_log("ship with proof failed: " . $e->getMessage());
                $error = $e->getMessage();
            }
        }
    } elseif ($action === 'deliver') {
        // Simple deliver (only if seller already shipped with proof)
        if (empty($order['seller_proof_uploaded_at'])) {
            $error = 'You must upload shipping proof first before marking as delivered.';
        } else {
            $upd = $conn->prepare("
                UPDATE orders
                SET order_status='delivered', shipping_status='delivered', delivered_at=NOW()
                WHERE order_id=? AND seller_id=?
            ");
            $upd->bind_param("ii", $order_id, $seller_id);
            $upd->execute();
            $upd->close();

            if (function_exists('nb_notify')) {
                nb_notify(
                    $conn,
                    $order['buyer_id'],
                    'shipment',
                    'Order delivered',
                    'Please confirm receipt with video/photo proof.',
                    'user/order-details.php?id=' . $order['auction_id']
                );
            }
            $_SESSION['ship_success'] = 'Order marked as delivered!';
            header("Location: orders.php");
            exit();
        }
    }
}

$img_url = '';
if (!empty($order['auction_image'])) {
    $b = basename($order['auction_image']);
    $img_url = file_exists(__DIR__ . '/../uploads/auctions/' . $b)
        ? '../uploads/auctions/' . $b
        : '';
}

$is_shipped = in_array(strtolower($order['order_status'] ?? ''), ['shipped', 'in_transit']);
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Ship Order · NexusBids</title>
    <meta name="csrf-token" content="<?php echo htmlspecialchars($_SESSION['csrf_token'] ?? ''); ?>">
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>tailwind.config = {darkMode: 'class', theme: {extend: {fontFamily: {sans: ['Inter', 'system-ui', 'sans-serif']}}}};</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
    <style>
        body {
            font-family: 'Inter', system-ui, sans-serif;
            transition: background-color .3s, color .3s;
        }

        .glass {
            backdrop-filter: blur(16px) saturate(180%)
        }

        .proof-preview {
            max-height: 200px;
            border-radius: 12px;
            margin-top: 12px;
            object-fit: contain;
            width: 100%;
            background: #000
        }

        .upload-box {
            border: 2px dashed #cbd5e1;
            border-radius: 16px;
            padding: 20px;
            text-align: center;
            cursor: pointer;
            transition: all .2s
        }

        .upload-box:hover {
            border-color: #6366f1;
            background: rgba(99, 102, 241, .04)
        }

        .dark .upload-box {
            border-color: #475569
        }

        .upload-box.has-file {
            border-color: #10b981;
            background: rgba(16, 185, 129, .05)
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 antialiased min-h-screen">
    <div class="flex min-h-screen">

        <?php $sidebar_active_page = 'orders';
        include __DIR__ . '/../includes/seller-sidebar.php'; ?>

        <main class="flex-1 lg:ml-64 min-w-0">

            <header
                class="sticky top-0 z-30 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
                <div class="h-20 px-4 sm:px-8 flex items-center justify-between gap-4">
                    <div class="flex items-center gap-3 min-w-0">
                        <a href="orders.php"
                            class="hidden sm:flex w-10 h-10 rounded-xl bg-gray-100 dark:bg-slate-800 items-center justify-center hover:bg-gray-200 dark:hover:bg-slate-700">
                            <i class="fa-solid fa-arrow-left"></i>
                        </a>
                        <div class="min-w-0">
                            <div
                                class="text-[10px] font-bold uppercase tracking-wider text-gray-400 mb-0.5 flex items-center gap-1.5">
                                <span class="w-1.5 h-1.5 rounded-full bg-violet-500"></span> Shipping with Proof
                            </div>
                            <h1 class="text-lg sm:text-xl font-black truncate">
                                <?= htmlspecialchars($order['order_number'] ?? ('#' . $order_id)) ?></h1>
                        </div>
                    </div>
                    <button id="themeToggle"
                        class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                        <i class="fa-solid fa-moon dark:hidden"></i><i
                            class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                    </button>
                </div>
            </header>

            <div class="p-4 sm:p-6 lg:p-8 space-y-6 max-w-4xl mx-auto">

                <?php if ($error): ?>
                    <div class="flex items-start gap-3 p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30">
                        <i class="fa-solid fa-circle-exclamation text-rose-600 dark:text-rose-400 mt-1"></i>
                        <p class="text-sm text-rose-700 dark:text-rose-300 font-medium self-center">
                            <?= htmlspecialchars($error) ?></p>
                    </div>
                <?php endif; ?>

                <!-- Order preview -->
                <div
                    class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm p-6 flex items-center gap-4">
                    <?php if ($img_url): ?>
                        <img src="<?= htmlspecialchars($img_url) ?>" class="w-16 h-16 rounded-xl object-cover shrink-0"
                            alt="">
                    <?php else: ?>
                        <div
                            class="w-16 h-16 rounded-xl bg-indigo-500/10 text-indigo-500 flex items-center justify-center shrink-0">
                            <i class="fa-solid fa-image"></i>
                        </div>
                    <?php endif; ?>
                    <div class="flex-1 min-w-0">
                        <div class="font-mono text-xs text-gray-500">
                            <?= htmlspecialchars($order['order_number'] ?? ('#' . $order_id)) ?></div>
                        <h1 class="font-bold text-lg line-clamp-1"><?= htmlspecialchars($order['auction_title']) ?></h1>
                        <p class="text-xs text-gray-500 mt-0.5">Buyer:
                            <strong><?= htmlspecialchars($order['buyer_name']) ?></strong></p>
                    </div>
                </div>

                <!-- Info banner -->
                <div class="p-4 rounded-2xl bg-indigo-500/10 border border-indigo-500/20 flex items-start gap-3">
                    <i class="fa-solid fa-shield-halved text-indigo-600 dark:text-indigo-400 text-lg mt-0.5"></i>
                    <div class="text-xs text-indigo-800 dark:text-indigo-300 leading-relaxed">
                        <strong class="block mb-1">Proof Required for Payment Release</strong>
                        Record a video of you packing the item + take clear photos. Buyer will also record unboxing.
                        Admin will verify both proofs before releasing your payment.
                    </div>
                </div>

                <?php if ($is_shipped): ?>
                    <!-- Already shipped state -->
                    <div
                        class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm p-6 space-y-5">
                        <div class="flex items-center gap-3">
                            <span
                                class="w-11 h-11 rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center">
                                <i class="fa-solid fa-truck-fast"></i>
                            </span>
                            <div>
                                <h2 class="font-bold">Order is on the way</h2>
                                <p class="text-xs text-gray-500 dark:text-gray-400">
                                    Courier: <strong><?= htmlspecialchars($order['courier']) ?></strong> · Tracking: <strong
                                        class="font-mono"><?= htmlspecialchars($order['tracking_number']) ?></strong>
                                </p>
                            </div>
                        </div>

                        <div
                            class="p-4 rounded-xl bg-blue-500/10 border border-blue-500/20 text-xs text-blue-800 dark:text-blue-300">
                            <i class="fa-solid fa-circle-info mr-1"></i>
                            Mark as delivered once the buyer receives it. Buyer will then upload unboxing proof.
                        </div>

                        <form method="POST">
                            <?php nb_csrf_field(); ?>
                            <input type="hidden" name="action" value="deliver">
                            <input type="hidden" name="order_id" value="<?= $order_id ?>">
                            <button type="submit"
                                class="w-full py-3.5 rounded-xl bg-emerald-600 hover:bg-emerald-500 text-white font-bold text-sm shadow-lg shadow-emerald-600/30 transition-all">
                                <i class="fa-solid fa-check mr-2"></i> Mark as Delivered
                            </button>
                        </form>
                    </div>
                <?php else: ?>

                    <form method="POST" enctype="multipart/form-data" class="space-y-6">
                        <?php nb_csrf_field(); ?>
                        <input type="hidden" name="action" value="ship">
                        <input type="hidden" name="order_id" value="<?= $order_id ?>">

                        <!-- Shipping Details -->
                        <div
                            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm p-6 space-y-5">
                            <div class="flex items-center gap-3 pb-2 border-b border-gray-100 dark:border-gray-800">
                                <span
                                    class="w-11 h-11 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                                    <i class="fa-solid fa-box"></i>
                                </span>
                                <div>
                                    <h2 class="font-bold">Courier &amp; Tracking</h2>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Basic shipping details</p>
                                </div>
                            </div>

                            <div class="grid grid-cols-1 sm:grid-cols-2 gap-4">
                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider mb-2">Courier <span
                                            class="text-rose-500">*</span></label>
                                    <select name="courier" required
                                        class="w-full rounded-xl border-slate-300 dark:border-slate-700 dark:bg-slate-800 text-sm py-3 px-3 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                        <option value="">Select courier…</option>
                                        <option value="TCS Express">TCS Express</option>
                                        <option value="Leopards Courier">Leopards Courier</option>
                                        <option value="M&P Express">M&P Express</option>
                                        <option value="FedEx Pakistan">FedEx Pakistan</option>
                                        <option value="BlueEx">BlueEx</option>
                                        <option value="Pakistan Post">Pakistan Post</option>
                                        <option value="Other">Other</option>
                                    </select>
                                </div>
                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider mb-2">Tracking Number
                                        <span class="text-rose-500">*</span></label>
                                    <input type="text" name="tracking_number" required maxlength="100"
                                        placeholder="e.g. TCS123456789"
                                        class="w-full rounded-xl border-slate-300 dark:border-slate-700 dark:bg-slate-800 text-sm py-3 px-3 font-mono focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider mb-2">Estimated
                                        Delivery</label>
                                    <input type="date" name="estimated_delivery" min="<?= date('Y-m-d') ?>"
                                        value="<?= date('Y-m-d', strtotime('+3 days')) ?>"
                                        class="w-full rounded-xl border-slate-300 dark:border-slate-700 dark:bg-slate-800 text-sm py-3 px-3 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                </div>
                                <div>
                                    <label class="block text-xs font-bold uppercase tracking-wider mb-2">Shipping
                                        Notes</label>
                                    <input type="text" name="shipping_notes" maxlength="200"
                                        placeholder="e.g. Fragile, handle with care"
                                        class="w-full rounded-xl border-slate-300 dark:border-slate-700 dark:bg-slate-800 text-sm py-3 px-3 focus:outline-none focus:ring-2 focus:ring-indigo-500">
                                </div>
                            </div>
                        </div>

                        <!-- Packing Video -->
                        <div
                            class="bg-white dark:bg-slate-900 rounded-3xl border-2 border-indigo-500/30 shadow-sm p-6 space-y-4">
                            <div class="flex items-center gap-3 pb-2 border-b border-gray-100 dark:border-gray-800">
                                <span
                                    class="w-11 h-11 rounded-xl bg-indigo-500/10 text-indigo-600 dark:text-indigo-400 flex items-center justify-center">
                                    <i class="fa-solid fa-video"></i>
                                </span>
                                <div>
                                    <h2 class="font-bold">Packing Video <span class="text-rose-500">*</span></h2>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Record the item being packed in
                                        real-time</p>
                                </div>
                            </div>

                            <label class="upload-box block" id="videoBox">
                                <input type="file" name="packing_video" id="packing_video"
                                    accept="video/mp4,video/webm,video/quicktime" required class="hidden">
                                <i class="fa-solid fa-cloud-arrow-up text-3xl text-indigo-500 mb-2"></i>
                                <p class="text-sm font-bold">Click to upload packing video</p>
                                <p class="text-[11px] text-gray-500 mt-1">MP4, WEBM, MOV · Max 50MB · Show item being
                                    wrapped</p>
                                <video id="videoPreview" class="proof-preview hidden" controls muted></video>
                                <p id="videoName" class="text-xs font-bold mt-2 hidden"></p>
                            </label>
                        </div>

                        <!-- Item Photos -->
                        <div
                            class="bg-white dark:bg-slate-900 rounded-3xl border-2 border-indigo-500/30 shadow-sm p-6 space-y-4">
                            <div class="flex items-center gap-3 pb-2 border-b border-gray-100 dark:border-gray-800">
                                <span
                                    class="w-11 h-11 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center">
                                    <i class="fa-solid fa-camera"></i>
                                </span>
                                <div>
                                    <h2 class="font-bold">Item Photos <span class="text-rose-500">*</span></h2>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Upload at least 2 clear photos from
                                        different angles</p>
                                </div>
                            </div>

                            <label class="upload-box block" id="photosBox">
                                <input type="file" name="item_photos[]" id="item_photos"
                                    accept="image/jpeg,image/png,image/webp" multiple required class="hidden">
                                <i class="fa-solid fa-cloud-arrow-up text-3xl text-blue-500 mb-2"></i>
                                <p class="text-sm font-bold">Click to upload photos</p>
                                <p class="text-[11px] text-gray-500 mt-1">JPG, PNG, WEBP · Max 5MB each · At least 2 photos
                                </p>
                            </label>
                            <div id="photosPreview" class="grid grid-cols-2 sm:grid-cols-4 gap-3 hidden"></div>
                        </div>

                        <!-- Courier Receipt (optional) -->
                        <div
                            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm p-6 space-y-4">
                            <div class="flex items-center gap-3 pb-2 border-b border-gray-100 dark:border-gray-800">
                                <span
                                    class="w-11 h-11 rounded-xl bg-violet-500/10 text-violet-600 dark:text-violet-400 flex items-center justify-center">
                                    <i class="fa-solid fa-receipt"></i>
                                </span>
                                <div>
                                    <h2 class="font-bold">Courier Receipt <span
                                            class="text-[10px] font-medium text-gray-400 normal-case">(optional but
                                            recommended)</span></h2>
                                    <p class="text-xs text-gray-500 dark:text-gray-400">Snap of the receipt/booked slip</p>
                                </div>
                            </div>

                            <label class="upload-box block" id="receiptBox">
                                <input type="file" name="courier_receipt" id="courier_receipt"
                                    accept="image/jpeg,image/png,image/webp" class="hidden">
                                <i class="fa-solid fa-cloud-arrow-up text-3xl text-violet-500 mb-2"></i>
                                <p class="text-sm font-bold">Click to upload receipt</p>
                                <p class="text-[11px] text-gray-500 mt-1">Optional · JPG, PNG, WEBP · Max 5MB</p>
                                <img id="receiptPreview" class="proof-preview hidden" alt="">
                            </label>
                        </div>

                        <!-- Submit -->
                        <div class="sticky bottom-4 z-20">
                            <div
                                class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-2xl p-4">
                                <button type="submit"
                                    class="w-full py-4 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white font-bold text-sm shadow-lg shadow-emerald-600/30 transition-all flex items-center justify-center gap-2">
                                    <i class="fa-solid fa-truck-fast"></i>
                                    Submit Shipping with Proof
                                </button>
                                <p
                                    class="text-[10px] text-gray-500 text-center mt-2 flex items-center justify-center gap-1.5">
                                    <i class="fa-solid fa-shield-halved text-emerald-500"></i>
                                    Admin will verify before releasing your payout
                                </p>
                            </div>
                        </div>
                    </form>

                <?php endif; ?>
            </div>
        </main>
    </div>

    <script>
        (function () {
            const html = document.documentElement;
            const t = document.getElementById('themeToggle');
            const s = localStorage.getItem('theme');
            if (s === 'dark' || (!s && window.matchMedia('(prefers-color-scheme: dark)').matches)) html.classList.add('dark');
            t?.addEventListener('click', () => {html.classList.toggle('dark'); localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light')});

            // Video preview
            const vid = document.getElementById('packing_video');
            vid?.addEventListener('change', function () {
                const f = this.files[0]; if (!f) return;
                if (f.size > 50 * 1024 * 1024) {alert('Video must be under 50MB'); this.value = ''; return;}
                const url = URL.createObjectURL(f);
                const prev = document.getElementById('videoPreview');
                const nm = document.getElementById('videoName');
                prev.src = url; prev.classList.remove('hidden');
                nm.textContent = f.name + ' (' + (f.size / 1024 / 1024).toFixed(1) + ' MB)';
                nm.classList.remove('hidden');
                document.getElementById('videoBox').classList.add('has-file');
            });

            // Photos preview
            const photos = document.getElementById('item_photos');
            photos?.addEventListener('change', function () {
                const box = document.getElementById('photosPreview');
                box.innerHTML = '';
                if (!this.files.length) {box.classList.add('hidden'); return;}
                box.classList.remove('hidden');
                document.getElementById('photosBox').classList.add('has-file');

                Array.from(this.files).slice(0, 8).forEach(f => {
                    if (!f.type.startsWith('image/')) return;
                    const r = new FileReader();
                    r.onload = e => {
                        const img = document.createElement('img');
                        img.src = e.target.result;
                        img.className = 'w-full h-24 object-cover rounded-lg border border-gray-200 dark:border-gray-700';
                        box.appendChild(img);
                    };
                    r.readAsDataURL(f);
                });
            });

            // Receipt preview
            const rc = document.getElementById('courier_receipt');
            rc?.addEventListener('change', function () {
                const f = this.files[0]; if (!f) return;
                const r = new FileReader();
                r.onload = e => {
                    const prev = document.getElementById('receiptPreview');
                    prev.src = e.target.result;
                    prev.classList.remove('hidden');
                    document.getElementById('receiptBox').classList.add('has-file');
                };
                r.readAsDataURL(f);
            });
        })();
    </script>
    <?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>