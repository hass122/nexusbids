<?php
require_once "admin-auth.php";
require_once "../includes/csrf.php";
require_once "../includes/activity-log.php";

$id = intval($_GET['id'] ?? $_POST['category_id'] ?? 0);

if ($id <= 0) {
    header("Location: categories.php");
    exit();
}

/* Fetch category + auction count */
$stmt = $conn->prepare("
    SELECT c.*,
        (SELECT COUNT(*) FROM auctions WHERE category_id = c.category_id) AS auction_count
    FROM categories c
    WHERE c.category_id = ?
    LIMIT 1
");
$stmt->bind_param("i", $id);
$stmt->execute();
$category = $stmt->get_result()->fetch_assoc();
$stmt->close();

if (!$category) {
    $_SESSION['flash_error'] = "Category not found.";
    header("Location: categories.php");
    exit();
}

$auction_count = intval($category['auction_count'] ?? 0);
$can_delete = $auction_count === 0;

/* Handle POST */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    nb_csrf_check();

    if (!$can_delete) {
        $_SESSION['flash_error'] = "Cannot delete — this category has {$auction_count} auction(s).";
        header("Location: categories.php");
        exit();
    }

    $del = $conn->prepare("DELETE FROM categories WHERE category_id = ?");
    $del->bind_param("i", $id);
    $del->execute();
    $del->close();

    nb_log_admin_action(
        $conn,
        $_SESSION["user_id"],
        "Category deleted",
        "Category #{$id} (\"" . $category['category_name'] . "\") deleted"
    );

    $_SESSION['flash_success'] = "Category \"" . $category['category_name'] . "\" deleted.";
    header("Location: categories.php");
    exit();
}
?>
<!DOCTYPE html>
<html lang="en" class="scroll-smooth">

<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Delete Category · NexusBids Admin</title>
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
                    class="w-11 h-11 rounded-2xl bg-rose-500/10 text-rose-600 dark:text-rose-400 flex items-center justify-center">
                    <i class="fa-solid fa-trash text-lg"></i>
                </span>
                <div>
                    <h1 class="text-lg font-black">Delete Category?</h1>
                    <p class="text-xs text-gray-500 dark:text-gray-400">This action cannot be undone</p>
                </div>
            </div>

            <div class="p-6 space-y-4">

                <!-- Category preview -->
                <div
                    class="p-4 rounded-2xl bg-gray-50 dark:bg-slate-800/50 border border-gray-200 dark:border-gray-700">
                    <div class="flex items-center gap-3">
                        <div
                            class="w-12 h-12 rounded-xl bg-indigo-500/10 text-indigo-600 flex items-center justify-center text-lg">
                            <i class="fa-solid <?= htmlspecialchars($category['icon'] ?: 'fa-box-open') ?>"></i>
                        </div>
                        <div class="min-w-0 flex-1">
                            <div class="font-bold text-sm">
                                <?= htmlspecialchars($category['category_name']) ?>
                            </div>
                            <div class="text-[10px] text-gray-500 mt-0.5">
                                <?= $auction_count ?> auction
                                <?= $auction_count === 1 ? '' : 's' ?> in this category
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Warning / Block -->
                <?php if ($can_delete): ?>
                    <div class="p-4 rounded-2xl bg-amber-500/10 border border-amber-500/20 flex items-start gap-3">
                        <i class="fa-solid fa-triangle-exclamation text-amber-500 mt-0.5"></i>
                        <p class="text-xs text-amber-800 dark:text-amber-300 leading-relaxed">
                            This category is empty. Deleting it will remove it permanently. Sellers will no longer see it
                            when creating auctions.
                        </p>
                    </div>
                <?php else: ?>
                    <div class="p-4 rounded-2xl bg-rose-500/10 border border-rose-500/20 flex items-start gap-3">
                        <i class="fa-solid fa-ban text-rose-500 mt-0.5"></i>
                        <p class="text-xs text-rose-800 dark:text-rose-300 leading-relaxed">
                            <strong>Cannot delete.</strong> This category contains
                            <?= $auction_count ?> auction
                            <?= $auction_count === 1 ? '' : 's' ?>. Move or delete them first.
                        </p>
                    </div>
                <?php endif; ?>

                <!-- Form -->
                <form method="POST" class="space-y-3">
                    <?php nb_csrf_field(); ?>
                    <input type="hidden" name="category_id" value="<?= (int) $id ?>">

                    <div class="flex gap-2">
                        <a href="categories.php"
                            class="flex-1 py-3 rounded-xl text-center text-sm font-semibold bg-gray-100 dark:bg-slate-800 hover:bg-gray-200 dark:hover:bg-slate-700 transition">
                            Cancel
                        </a>
                        <?php if ($can_delete): ?>
                            <button type="submit"
                                class="flex-1 py-3 rounded-xl bg-rose-600 hover:bg-rose-500 text-white text-sm font-bold shadow-lg shadow-rose-600/30 transition flex items-center justify-center gap-2">
                                <i class="fa-solid fa-trash"></i>
                                Yes, Delete
                            </button>
                        <?php else: ?>
                            <button type="button" disabled
                                class="flex-1 py-3 rounded-xl bg-gray-300 dark:bg-slate-700 text-gray-500 dark:text-gray-400 text-sm font-bold cursor-not-allowed flex items-center justify-center gap-2">
                                <i class="fa-solid fa-lock"></i>
                                Cannot Delete
                            </button>
                        <?php endif; ?>
                    </div>
                </form>
            </div>
        </div>
    </div>
</body>

</html>