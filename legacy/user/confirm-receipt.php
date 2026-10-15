<?php
/**
 * Buyer Confirms Receipt with Video + Photo Proof
 */
session_start();
date_default_timezone_set("Asia/Karachi");

if (empty($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'user') {
    header("Location: ../auth/login.php");
    exit();
}

require_once "../config/database.php";
require_once "../includes/csrf.php";
require_once "../includes/notifications.php";
require_once "../includes/order-proofs.php";

$user_id = (int) $_SESSION['user_id'];
$order_id = (int) ($_GET['order_id'] ?? $_POST['order_id'] ?? 0);

if ($order_id <= 0) {
    $_SESSION['flash_error'] = "Invalid order.";
    header("Location: orders.php");
    exit();
}

/* Fetch order */
$order = null;
if (
    $stmt = $conn->prepare("
    SELECT o.order_id, o.order_number, o.order_status, o.payment_status,
           o.delivered_at, o.buyer_confirmed_at, o.total_amount, o.seller_amount,
           o.auction_id, o.seller_id,
           a.title AS auction_title, a.image AS auction_image,
           s.full_name AS seller_name
    FROM orders o
    INNER JOIN auctions a ON a.auction_id = o.auction_id
    INNER JOIN users s ON s.user_id = o.seller_id
    WHERE o.order_id = ? AND o.buyer_id = ?
    LIMIT 1
")
) {
    $stmt->bind_param("ii", $order_id, $user_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();
    $stmt->close();
}

if (!$order) {
    $_SESSION['flash_error'] = "Order not found.";
    header("Location: orders.php");
    exit();
}

/* Guards */
if (!empty($order['buyer_confirmed_at'])) {
    $_SESSION['flash_error'] = "You have already confirmed this order.";
    header("Location: order-details.php?id=" . (int) $order['auction_id']);
    exit();
}

$status = strtolower((string) $order['order_status']);
if (!in_array($status, ['delivered', 'shipped', 'in_transit'], true)) {
    $_SESSION['flash_error'] = "You can confirm receipt only after the order is delivered.";
    header("Location: order-details.php?id=" . (int) $order['auction_id']);
    exit();
}

if (strtolower((string) $order['payment_status']) !== 'paid') {
    $_SESSION['flash_error'] = "Payment must be complete before confirming receipt.";
    header("Location: order-details.php?id=" . (int) $order['auction_id']);
    exit();
}

/* Existing proofs */
$existing_proofs = nb_proof_list($conn, $order_id, 'buyer');

/* User info */
$u_stmt = $conn->prepare("SELECT full_name, avatar FROM users WHERE user_id = ?");
$u_stmt->bind_param("i", $user_id);
$u_stmt->execute();
$uinfo = $u_stmt->get_result()->fetch_assoc();
$u_stmt->close();
$full_name = $uinfo['full_name'] ?? 'Buyer';
$avatar = $uinfo['avatar'] ?? '';
$initials = strtoupper(substr(preg_replace('/\s+/', '', $full_name), 0, 2));
$unread_notifications = nb_unread_count($conn, $user_id);

$error = '';

/* =========================================================
   HANDLE POST — Confirm with proof
========================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nb_csrf_check();

    $condition = $_POST['item_condition'] ?? '';
    $notes = trim($_POST['notes'] ?? '');
    $allowed_conditions = ['perfect', 'minor_issues', 'major_issues', 'wrong_item'];

    if (empty($_FILES['unboxing_video']) || $_FILES['unboxing_video']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Unboxing video is required. Please record yourself opening the package.';
    } elseif (empty($_FILES['received_photos']['name'][0])) {
        $error = 'At least 2 photos of the received item are required.';
    } elseif (!in_array($condition, $allowed_conditions, true)) {
        $error = 'Please select the item condition.';
    } else {
        $conn->begin_transaction();
        try {
            // Save unboxing video
            $vid = nb_proof_save($_FILES['unboxing_video'], $order_id, $user_id, 'unboxing_video');
            if (!$vid['ok'])
                throw new RuntimeException('Unboxing video: ' . $vid['error']);
            if ($vid['file_type'] !== 'video')
                throw new RuntimeException('Unboxing proof must be a video.');

            $vid_id = nb_proof_insert($conn, $order_id, $user_id, 'buyer', 'unboxing_video', $vid, $notes);
            if (!$vid_id)
                throw new RuntimeException('Failed to save video record.');

            // Save received photos
            $photo_count = 0;
            $total_photos = count($_FILES['received_photos']['name']);
            for ($i = 0; $i < $total_photos; $i++) {
                if ($_FILES['received_photos']['error'][$i] !== UPLOAD_ERR_OK)
                    continue;

                $single = [
                    'tmp_name' => $_FILES['received_photos']['tmp_name'][$i],
                    'name' => $_FILES['received_photos']['name'][$i],
                    'size' => $_FILES['received_photos']['size'][$i],
                    'error' => $_FILES['received_photos']['error'][$i],
                    'type' => $_FILES['received_photos']['type'][$i],
                ];
                $ph = nb_proof_save($single, $order_id, $user_id, 'received_photo');
                if ($ph['ok'] && $ph['file_type'] === 'image') {
                    nb_proof_insert($conn, $order_id, $user_id, 'buyer', 'received_photo', $ph, null);
                    $photo_count++;
                }
            }
            if ($photo_count < 2) {
                throw new RuntimeException('At least 2 valid received photos required. Got: ' . $photo_count);
            }

            // Update order
            $upd = $conn->prepare("
                UPDATE orders
                SET buyer_confirmed_at = NOW(),
                    buyer_proof_uploaded_at = NOW(),
                    order_status = CASE WHEN order_status='delivered' THEN 'completed' ELSE order_status END,
                    completed_at = CASE WHEN order_status='delivered' AND completed_at IS NULL THEN NOW() ELSE completed_at END,
                    admin_review_status = 'pending'
                WHERE order_id = ? AND buyer_id = ?
            ");
            $upd->bind_param("ii", $order_id, $user_id);
            $upd->execute();
            $upd->close();

            // Audit
            nb_proof_audit($conn, $order_id, null, 'buyer.confirmed_with_proof', $user_id, 'buyer', [
                'condition' => $condition,
                'video_id' => $vid_id,
                'photos' => $photo_count,
                'notes' => $notes,
            ]);

            // Notify seller
            if (function_exists('nb_notify')) {
                nb_notify(
                    $conn,
                    (int) $order['seller_id'],
                    'order',
                    'Buyer Confirmed Receipt',
                    'Buyer confirmed receipt of "' . $order['auction_title'] . '" with video proof. Admin will release your payout.',
                    'seller/orders.php'
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
                            'Order Ready for Escrow Review',
                            'Order ' . $order['order_number'] . ' — Buyer confirmed receipt with proof. Please verify and release payment.',
                            'admin/order-proof-review.php?id=' . $order_id
                        );
                    }
                }
            }

            $conn->commit();

            // Redirect logic based on condition
            if ($condition === 'perfect') {
                $_SESSION['flash_success'] = "Receipt confirmed! Admin will verify both proofs and release payment to the seller.";
            } else {
                $_SESSION['flash_success'] = "Receipt noted with concerns. Admin will review both proofs carefully before payment release.";
            }

            header("Location: order-details.php?id=" . (int) $order['auction_id']);
            exit();

        } catch (Throwable $e) {
            $conn->rollback();
            error_log("confirm-receipt with proof failed: " . $e->getMessage());
            $error = $e->getMessage();
        }
    }
}

$img_url = '';
if (!empty($order['auction_image'])) {
    $b = basename($order['auction_image']);
    if (file_exists(__DIR__ . '/../uploads/auctions/' . $b)) {
        $img_url = '../uploads/auctions/' . $b;
    }
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Confirm Receipt · NexusBids</title>
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
            border-color: #059669;
            background: rgba(5, 150, 105, .04)
        }

        .dark .upload-box {
            border-color: #475569
        }

        .upload-box.has-file {
            border-color: #10b981;
            background: rgba(16, 185, 129, .05)
        }

        .condition-card {
            border: 2px solid #e5e7eb;
            border-radius: 16px;
            padding: 16px;
            cursor: pointer;
            transition: all .2s;
            text-align: center
        }

        .condition-card:hover {
            border-color: #10b981
        }

        .condition-card.active {
            border-color: #10b981;
            background: rgba(16, 185, 129, .05)
        }
    </style>
</head>

<body class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 antialiased min-h-screen">

    <header
        class="sticky top-0 z-40 bg-white/80 dark:bg-slate-900/80 glass border-b border-gray-200/80 dark:border-gray-800/80">
        <div class="max-w-5xl mx-auto px-4 sm:px-8 h-20 flex items-center justify-between gap-4">
            <a class="flex items-center gap-2.5 text-xl font-black" href="../index.php">
                <span
                    class="w-10 h-10 rounded-xl bg-gradient-to-tr from-emerald-600 to-teal-500 flex items-center justify-center text-white shadow-lg"><i
                        class="fa-solid fa-box-open"></i></span>
                Nexus<span class="text-emerald-600 dark:text-emerald-400">Bids</span>
            </a>
            <div class="flex items-center gap-2">
                <button id="themeToggle"
                    class="w-10 h-10 rounded-full bg-gray-100 dark:bg-gray-800 flex items-center justify-center">
                    <i class="fa-solid fa-moon dark:hidden"></i><i
                        class="fa-solid fa-sun hidden dark:block text-amber-400"></i>
                </button>
                <a href="order-details.php?id=<?= (int) $order['auction_id'] ?>"
                    class="hidden sm:inline-flex px-4 py-2 text-sm font-semibold text-gray-700 dark:text-gray-200 hover:text-emerald-600 transition">
                    <i class="fa-solid fa-arrow-left mr-1.5"></i> Back to Order
                </a>
            </div>
        </div>
    </header>

    <main class="max-w-4xl mx-auto px-4 sm:px-8 py-8 space-y-6">

        <!-- Order summary -->
        <div
            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm p-5 flex items-center gap-4">
            <?php if ($img_url): ?>
                <img src="<?= htmlspecialchars($img_url) ?>" class="w-16 h-16 rounded-xl object-cover" alt="">
            <?php else: ?>
                <div class="w-16 h-16 rounded-xl bg-indigo-500/10 text-indigo-500 flex items-center justify-center"><i
                        class="fa-solid fa-image"></i></div>
            <?php endif; ?>
            <div class="flex-1 min-w-0">
                <div class="font-mono text-xs text-gray-500"><?= htmlspecialchars($order['order_number']) ?></div>
                <h1 class="font-bold truncate"><?= htmlspecialchars($order['auction_title']) ?></h1>
                <div class="text-xs text-gray-500 mt-0.5">Seller:
                    <strong><?= htmlspecialchars($order['seller_name']) ?></strong></div>
            </div>
            <div class="text-right shrink-0">
                <div class="text-[10px] font-bold uppercase tracking-wider text-gray-400">Amount</div>
                <div class="font-black text-emerald-600 dark:text-emerald-400 tabular-nums">Rs.
                    <?= number_format((float) $order['total_amount']) ?></div>
            </div>
        </div>

        <!-- Info banner -->
        <div class="p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/20 flex items-start gap-3">
            <i class="fa-solid fa-video text-emerald-600 dark:text-emerald-400 text-lg mt-0.5"></i>
            <div class="text-xs text-emerald-800 dark:text-emerald-300 leading-relaxed">
                <strong class="block mb-1">Record Your Unboxing</strong>
                Record a continuous video of opening the package + take clear photos. This proof is shared with admin to
                verify order accuracy before releasing payment to the seller.
            </div>
        </div>

        <?php if ($error): ?>
            <div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 flex items-start gap-3">
                <i class="fa-solid fa-circle-exclamation text-rose-600 dark:text-rose-400 mt-1"></i>
                <p class="text-sm text-rose-700 dark:text-rose-300 font-medium self-center"><?= htmlspecialchars($error) ?>
                </p>
            </div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" class="space-y-6">
            <?php nb_csrf_field(); ?>
            <input type="hidden" name="order_id" value="<?= $order_id ?>">

            <!-- Unboxing Video -->
            <div class="bg-white dark:bg-slate-900 rounded-3xl border-2 border-emerald-500/30 shadow-sm p-6 space-y-4">
                <div class="flex items-center gap-3 pb-2 border-b border-gray-100 dark:border-gray-800">
                    <span
                        class="w-11 h-11 rounded-xl bg-emerald-500/10 text-emerald-600 dark:text-emerald-400 flex items-center justify-center">
                        <i class="fa-solid fa-video"></i>
                    </span>
                    <div>
                        <h2 class="font-bold">Unboxing Video <span class="text-rose-500">*</span></h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Continuous video — no cuts, no edits</p>
                    </div>
                </div>

                <label class="upload-box block" id="videoBox">
                    <input type="file" name="unboxing_video" id="unboxing_video"
                        accept="video/mp4,video/webm,video/quicktime" required class="hidden">
                    <i class="fa-solid fa-cloud-arrow-up text-3xl text-emerald-500 mb-2"></i>
                    <p class="text-sm font-bold">Click to upload unboxing video</p>
                    <p class="text-[11px] text-gray-500 mt-1">MP4, WEBM, MOV · Max 50MB · Open the package on camera</p>
                    <video id="videoPreview" class="proof-preview hidden" controls muted></video>
                    <p id="videoName" class="text-xs font-bold mt-2 hidden"></p>
                </label>
            </div>

            <!-- Received Photos -->
            <div class="bg-white dark:bg-slate-900 rounded-3xl border-2 border-emerald-500/30 shadow-sm p-6 space-y-4">
                <div class="flex items-center gap-3 pb-2 border-b border-gray-100 dark:border-gray-800">
                    <span
                        class="w-11 h-11 rounded-xl bg-teal-500/10 text-teal-600 dark:text-teal-400 flex items-center justify-center">
                        <i class="fa-solid fa-camera"></i>
                    </span>
                    <div>
                        <h2 class="font-bold">Received Item Photos <span class="text-rose-500">*</span></h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400">At least 2 clear photos from different
                            angles</p>
                    </div>
                </div>

                <label class="upload-box block" id="photosBox">
                    <input type="file" name="received_photos[]" id="received_photos"
                        accept="image/jpeg,image/png,image/webp" multiple required class="hidden">
                    <i class="fa-solid fa-cloud-arrow-up text-3xl text-teal-500 mb-2"></i>
                    <p class="text-sm font-bold">Click to upload photos</p>
                    <p class="text-[11px] text-gray-500 mt-1">JPG, PNG, WEBP · Max 5MB each · At least 2 photos</p>
                </label>
                <div id="photosPreview" class="grid grid-cols-2 sm:grid-cols-4 gap-3 hidden"></div>
            </div>

            <!-- Item Condition -->
            <div
                class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-sm p-6 space-y-4">
                <div class="flex items-center gap-3 pb-2 border-b border-gray-100 dark:border-gray-800">
                    <span
                        class="w-11 h-11 rounded-xl bg-blue-500/10 text-blue-600 dark:text-blue-400 flex items-center justify-center">
                        <i class="fa-solid fa-clipboard-check"></i>
                    </span>
                    <div>
                        <h2 class="font-bold">Item Condition <span class="text-rose-500">*</span></h2>
                        <p class="text-xs text-gray-500 dark:text-gray-400">Be honest — this helps admin resolve any
                            issues</p>
                    </div>
                </div>

                <input type="hidden" name="item_condition" id="conditionInput" required>

                <div class="grid grid-cols-2 sm:grid-cols-4 gap-3">
                    <div class="condition-card" data-condition="perfect">
                        <div class="text-3xl mb-2">✅</div>
                        <div class="font-bold text-sm">Perfect</div>
                        <div class="text-[10px] text-gray-500 mt-1">Exactly as described</div>
                    </div>
                    <div class="condition-card" data-condition="minor_issues">
                        <div class="text-3xl mb-2">⚠️</div>
                        <div class="font-bold text-sm">Minor Issues</div>
                        <div class="text-[10px] text-gray-500 mt-1">Small cosmetic issues</div>
                    </div>
                    <div class="condition-card" data-condition="major_issues">
                        <div class="text-3xl mb-2">❌</div>
                        <div class="font-bold text-sm">Major Issues</div>
                        <div class="text-[10px] text-gray-500 mt-1">Damaged / not working</div>
                    </div>
                    <div class="condition-card" data-condition="wrong_item">
                        <div class="text-3xl mb-2">🚫</div>
                        <div class="font-bold text-sm">Wrong Item</div>
                        <div class="text-[10px] text-gray-500 mt-1">Different from listing</div>
                    </div>
                </div>

                <div>
                    <label class="block text-xs font-bold uppercase tracking-wider mb-2">Additional Notes <span
                            class="text-gray-400 font-normal normal-case">(optional)</span></label>
                    <textarea name="notes" rows="3" maxlength="500"
                        placeholder="Any issues or observations to share with admin..."
                        class="w-full px-4 py-3 bg-gray-50 dark:bg-slate-800 border border-gray-200 dark:border-gray-700 rounded-xl text-sm focus:outline-none focus:ring-2 focus:ring-emerald-500 resize-none"></textarea>
                </div>
            </div>

            <!-- Submit -->
            <div class="sticky bottom-4 z-20">
                <div
                    class="bg-white dark:bg-slate-900 rounded-2xl border border-gray-200 dark:border-gray-800 shadow-2xl p-4 space-y-3">
                    <div class="flex items-start gap-2 p-3 rounded-xl bg-amber-500/5 border border-amber-500/20">
                        <i class="fa-solid fa-shield-halved text-amber-500 text-xs mt-0.5"></i>
                        <p class="text-[10px] text-amber-800 dark:text-amber-300 leading-relaxed">
                            <strong>Note:</strong> Confirming receipt triggers admin review. Payment will be released to
                            the seller only after both sides' proofs are verified.
                        </p>
                    </div>
                    <button type="submit"
                        class="w-full py-4 rounded-xl bg-gradient-to-r from-emerald-600 to-teal-600 hover:from-emerald-500 hover:to-teal-500 text-white font-bold text-sm shadow-lg shadow-emerald-600/30 transition-all flex items-center justify-center gap-2">
                        <i class="fa-solid fa-handshake"></i>
                        Confirm Receipt with Proof
                    </button>
                </div>
            </div>
        </form>
    </main>

    <script>
        (function () {
            const html = document.documentElement;
            const t = document.getElementById('themeToggle');
            const s = localStorage.getItem('theme');
            if (s === 'dark' || (!s && window.matchMedia('(prefers-color-scheme: dark)').matches)) html.classList.add('dark');
            t?.addEventListener('click', () => {html.classList.toggle('dark'); localStorage.setItem('theme', html.classList.contains('dark') ? 'dark' : 'light')});

            // Video preview
            const vid = document.getElementById('unboxing_video');
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
            const photos = document.getElementById('received_photos');
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

            // Condition cards
            document.querySelectorAll('.condition-card').forEach(card => {
                card.addEventListener('click', () => {
                    document.querySelectorAll('.condition-card').forEach(c => c.classList.remove('active'));
                    card.classList.add('active');
                    document.getElementById('conditionInput').value = card.dataset.condition;
                });
            });
        })();
    </script>
    <?php require_once __DIR__ . '/../includes/chat-widget.php'; ?>
</body>

</html>