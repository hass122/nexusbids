<?php
require_once "admin-auth.php";
require_once "../includes/csrf.php";
require_once "../includes/activity-log.php";

$id = intval($_GET['id'] ?? $_POST['user_id'] ?? 0);

if ($id <= 0) {
    header("Location: users.php");
    exit();
}

/* Fetch user */
$stmt = $conn->prepare("SELECT * FROM users WHERE user_id = ? LIMIT 1");
$stmt->bind_param("i", $id);
$stmt->execute();
$user = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$user || $user['role'] === 'admin') {
    $_SESSION['flash_error'] = "Cannot modify this user.";
    header("Location: users.php");
    exit();
}

$current_status = strtolower($user['status'] ?? 'active');
$new_status = ($current_status === 'active') ? 'blocked' : 'active';
$action_label = ($current_status === 'active') ? 'Block' : 'Activate';
$color = ($current_status === 'active') ? 'rose' : 'emerald';
$icon = ($current_status === 'active') ? 'fa-ban' : 'fa-circle-check';
$desc = ($current_status === 'active')
    ? 'This will block the user. They won\'t be able to log in or place bids.'
    : 'This will reactivate the user. They can log in and participate again.';

/* Handle POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nb_csrf_check();

    $upd = $conn->prepare("UPDATE users SET status = ? WHERE user_id = ?");
    $upd->bind_param("si", $new_status, $id);
    $upd->execute();
    $upd->close();

    nb_log_admin_action(
        $conn,
        $_SESSION["user_id"],
        "User " . strtolower($action_label) . "d",
        "User #{$id} ({$user['full_name']}) set to '{$new_status}'"
    );

    $_SESSION['flash_success'] = "User #{$id} " . strtolower($action_label) . "d successfully.";
    header("Location: users.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($action_label) ?> User · NexusBids Admin</title>
    <script src="https://cdn.tailwindcss.com?plugins=forms"></script>
    <script>tailwind.config = { darkMode: 'class', theme: { extend: { fontFamily: { sans: ['Inter', 'system-ui', 'sans-serif'] } } } };</script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800;900&display=swap"
        rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.7.2/css/all.min.css">
</head>

<body
    class="bg-gray-50 dark:bg-slate-950 text-gray-900 dark:text-gray-100 font-sans antialiased min-h-screen flex items-center justify-center p-4">

    <div class="w-full max-w-md">
        <div
            class="bg-white dark:bg-slate-900 rounded-3xl border border-gray-200 dark:border-gray-800 shadow-2xl overflow-hidden">

            <div class="p-6 border-b border-gray-100 dark:border-gray-800 flex items-center gap-3">
                <span
                    class="w-11 h-11 rounded-2xl bg-<?= $color ?>-500/10 text-<?= $color ?>-600 dark:text-<?= $color ?>-400 flex items-center justify-center">
                    <i class="fa-solid <?= $icon ?> text-lg"></i>
                </span>
                <div>
                    <h1 class="text-lg font-black"><?= htmlspecialchars($action_label) ?> User?</h1>
                    <p class="text-xs text-gray-500 dark:text-gray-400">Confirm this action</p>
                </div>
            </div>

            <div class="p-6 space-y-4">

                <!-- User preview -->
                <div
                    class="flex items-center gap-3 p-4 rounded-2xl bg-gray-50 dark:bg-slate-800/50 border border-gray-200 dark:border-gray-700">
                    <?php if (!empty($user['avatar'])): ?>
                        <img src="../uploads/avatars/<?= htmlspecialchars($user['avatar']) ?>"
                            class="w-14 h-14 rounded-xl object-cover shrink-0" alt="">
                    <?php else: ?>
                        <div
                            class="w-14 h-14 rounded-xl bg-gradient-to-br from-indigo-500 to-violet-500 text-white font-bold flex items-center justify-center shrink-0">
                            <?= htmlspecialchars(strtoupper(substr(preg_replace('/\s+/', '', $user['full_name']), 0, 2))) ?>
                        </div>
                    <?php endif; ?>
                    <div class="min-w-0 flex-1">
                        <div class="font-bold text-sm truncate"><?= htmlspecialchars($user['full_name']) ?></div>
                        <div class="text-xs text-gray-500 truncate"><?= htmlspecialchars($user['email']) ?></div>
                        <div class="text-[10px] text-gray-400 mt-0.5 uppercase tracking-wider">
                            <?= htmlspecialchars($user['role']) ?> · <?= htmlspecialchars($current_status) ?>
                        </div>
                    </div>
                </div>

                <!-- Description -->
                <div
                    class="p-4 rounded-2xl bg-<?= $color ?>-500/10 border border-<?= $color ?>-500/20 flex items-start gap-3">
                    <i class="fa-solid fa-info-circle text-<?= $color ?>-500 mt-0.5"></i>
                    <p class="text-xs text-<?= $color ?>-800 dark:text-<?= $color ?>-300 leading-relaxed">
                        <?= htmlspecialchars($desc) ?>
                    </p>
                </div>

                <!-- Form -->
                <form method="POST" class="space-y-3">
                    <?php nb_csrf_field(); ?>
                    <input type="hidden" name="user_id" value="<?= (int) $id ?>">

                    <div class="flex gap-2">
                        <a href="users.php"
                            class="flex-1 py-3 rounded-xl text-center text-sm font-semibold bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 transition">
                            Cancel
                        </a>
                        <button type="submit"
                            class="flex-1 py-3 rounded-xl bg-<?= $color ?>-600 hover:bg-<?= $color ?>-500 text-white text-sm font-bold shadow-lg shadow-<?= $color ?>-600/30 transition flex items-center justify-center gap-2">
                            <i class="fa-solid <?= $icon ?>"></i>
                            Yes, <?= htmlspecialchars($action_label) ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>

</html>