<?php
/* =========================================================
   cron/auto-release-escrow.php — Auto-Release Escrow After 7 Days
   ---------------------------------------------------------
   Trigger:  Daily via crontab  OR  Manually via web (with token)
   Safety:   Releases escrow only if buyer DID NOT confirm receipt
             within 7 days of delivery.
   ========================================================= */

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}

date_default_timezone_set('Asia/Karachi');

/* ---------- Web access security ---------- */
$CRON_TOKEN = 'change-this-to-a-long-random-string-xyz123';
$is_cli = (PHP_SAPI === 'cli');

if (!$is_cli) {
    $token = $_GET['token'] ?? '';
    if (!hash_equals($CRON_TOKEN, $token)) {
        http_response_code(403);
        exit("Forbidden\n");
    }
}

/* ---------- Load DB + helpers ---------- */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/ledger.php';
require_once __DIR__ . '/../includes/notifications.php';

/* ---------- Config ---------- */
$AUTO_RELEASE_DAYS = 7;    // days after delivery
$DRY_RUN = isset($_GET['dry']) || in_array('--dry', $argv ?? [], true);
$LIMIT = 100;              // max per run

function cron_log(string $msg): void
{
    $line = '[' . date('Y-m-d H:i:s') . '] ' . $msg . PHP_EOL;
    echo $line;
    @file_put_contents(__DIR__ . '/cron-auto-release.log', $line, FILE_APPEND);
}

cron_log("=== Auto-release escrow cron started " . ($DRY_RUN ? '(DRY RUN)' : '') . " ===");

/* ---------- Check tables ---------- */
$tables = nb_ledger_tables_exist($conn);
if (!($tables['escrow_holds'] ?? false)) {
    cron_log("ERROR: escrow_holds table missing. Exiting.");
    exit(1);
}

/* ============================================================
   STEP 1 — Find candidates
   ---------------------------------------------------------
   Rule:
     - escrow.status IN ('held','partially_released')
     - escrow has available balance (> 0)
     - order.order_status = 'delivered'
     - order.delivered_at <= NOW() - X days
     - order.buyer_confirmed_at IS NULL
     - No open dispute (payment_disputes.status NOT IN open statuses)
   ============================================================ */
$sql = "
    SELECT
        e.id             AS escrow_id,
        e.order_id,
        e.seller_id,
        e.buyer_id,
        e.amount,
        e.released_amount,
        e.refunded_amount,
        e.status         AS escrow_status,
        e.auto_release_at,
        o.order_number,
        o.order_status,
        o.payment_status,
        o.delivered_at,
        o.buyer_confirmed_at,
        a.auction_id,
        a.title          AS auction_title
    FROM escrow_holds e
    INNER JOIN orders   o ON o.order_id   = e.order_id
    INNER JOIN auctions a ON a.auction_id = o.auction_id
    WHERE e.status IN ('held','partially_released')
      AND (e.amount - e.released_amount - e.refunded_amount) > 0
      AND o.order_status = 'delivered'
      AND o.delivered_at IS NOT NULL
      AND o.delivered_at <= DATE_SUB(NOW(), INTERVAL ? DAY)
      AND o.buyer_confirmed_at IS NULL
    ORDER BY o.delivered_at ASC
    LIMIT ?
";

$candidates = [];
if ($stmt = $conn->prepare($sql)) {
    $stmt->bind_param("ii", $AUTO_RELEASE_DAYS, $LIMIT);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc())
        $candidates[] = $row;
    $stmt->close();
} else {
    cron_log("ERROR preparing query: " . $conn->error);
    exit(1);
}

cron_log("Found " . count($candidates) . " candidate(s) for auto-release.");

if (empty($candidates)) {
    cron_log("Nothing to do. Exiting.");
    exit(0);
}

/* ============================================================
   STEP 2 — Filter out any disputed escrows
   ============================================================ */
$has_disputes = $tables['payment_disputes'] ?? false;

$to_release = [];
foreach ($candidates as $c) {
    if ($has_disputes) {
        try {
            $ds = $conn->prepare("
                SELECT COUNT(*) AS t
                FROM payment_disputes
                WHERE order_id = ?
                  AND status IN ('open','under_review','evidence_required')
            ");
            $ds->bind_param("i", $c['order_id']);
            $ds->execute();
            $open = (int) ($ds->get_result()->fetch_assoc()['t'] ?? 0);
            $ds->close();

            if ($open > 0) {
                cron_log("SKIP escrow #{$c['escrow_id']} (order {$c['order_number']}): open dispute");
                continue;
            }
        } catch (Throwable $e) {
            cron_log("WARN dispute check failed for escrow #{$c['escrow_id']}: " . $e->getMessage());
        }
    }
    $to_release[] = $c;
}

if (empty($to_release)) {
    cron_log("All candidates skipped (disputes). Exiting.");
    exit(0);
}

/* ============================================================
   STEP 3 — Release each escrow
   ============================================================ */
$released_count = 0;
$released_total = 0.0;
$failed_count = 0;

foreach ($to_release as $c) {
    $escrow_id = (int) $c['escrow_id'];

    if ($DRY_RUN) {
        $avail = (float) $c['amount'] - (float) $c['released_amount'] - (float) $c['refunded_amount'];
        cron_log("DRY-RUN: Would release Rs. " . number_format($avail, 2) . " for escrow #{$escrow_id} (order {$c['order_number']})");
        continue;
    }

    /* Use helper — admin_id=0 signals system/auto */
    $res = nb_ledger_release_escrow(
        $conn,
        $escrow_id,
        0,
        'Auto-released: buyer did not confirm within ' . $AUTO_RELEASE_DAYS . ' days of delivery'
    );

    if (!empty($res['ok'])) {
        $amount = (float) $res['amount'];
        $released_count++;
        $released_total += $amount;
        cron_log("OK: Released Rs. " . number_format($amount, 2) . " for escrow #{$escrow_id} (order {$c['order_number']})");

        /* ---------- Notify seller ---------- */
        if (function_exists('nb_notify')) {
            try {
                nb_notify(
                    $conn,
                    (int) $c['seller_id'],
                    'payment',
                    'Payout Auto-Released',
                    'Rs. ' . number_format($amount) . ' was auto-released to your wallet for order ' . $c['order_number']
                    . ' (buyer did not confirm within ' . $AUTO_RELEASE_DAYS . ' days).',
                    'seller/wallet.php'
                );
            } catch (Throwable $e) {
            }
        }

        /* ---------- Notify buyer ---------- */
        if (function_exists('nb_notify')) {
            try {
                nb_notify(
                    $conn,
                    (int) $c['buyer_id'],
                    'order',
                    'Order Auto-Completed',
                    'Order ' . $c['order_number'] . ' has been auto-completed because it was not confirmed within '
                    . $AUTO_RELEASE_DAYS . ' days of delivery. Escrow has been released to the seller.',
                    'order-details.php?id=' . (int) $c['auction_id']
                );
            } catch (Throwable $e) {
            }
        }

        /* ---------- Notify admins (summary per item) ---------- */
        if (function_exists('nb_notify')) {
            try {
                $ar = $conn->query("SELECT user_id FROM users WHERE role = 'admin'");
                if ($ar) {
                    while ($adm = $ar->fetch_assoc()) {
                        nb_notify(
                            $conn,
                            (int) $adm['user_id'],
                            'system',
                            'Escrow Auto-Released',
                            'Order ' . $c['order_number'] . ' — Rs. ' . number_format($amount)
                            . ' auto-released to seller (buyer did not confirm in ' . $AUTO_RELEASE_DAYS . ' days).',
                            'escrow.php'
                        );
                    }
                }
            } catch (Throwable $e) {
            }
        }

    } else {
        $failed_count++;
        cron_log("FAIL escrow #{$escrow_id}: " . ($res['error'] ?? 'unknown error'));
    }
}

/* ============================================================
   STEP 4 — Summary log
   ============================================================ */
cron_log(sprintf(
    "=== Done. Released: %d | Total: Rs. %s | Failed: %d | Skipped/Disputed: %d ===",
    $released_count,
    number_format($released_total, 2),
    $failed_count,
    count($candidates) - count($to_release)
));

exit(0);