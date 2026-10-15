<?php
session_start();

/* Admin-only access */
if (!isset($_SESSION['user_id']) || ($_SESSION['role'] ?? '') !== 'admin') {
    http_response_code(403);
    die("Access denied. Admin only.");
}

require_once "../config/database.php";
require_once "../includes/ai-bid-fraud.php";

date_default_timezone_set('Asia/Karachi');

echo "<!DOCTYPE html><html><head><title>Fraud Test (Admin)</title>";
echo "<style>body{font-family:Inter,sans-serif;padding:24px;background:#f9fafb}";
echo ".box{background:#fff;padding:16px;border-radius:12px;border:1px solid #e5e7eb;margin:12px 0}";
echo "table{border-collapse:collapse;width:100%}th,td{padding:10px;border:1px solid #e5e7eb;font-size:13px}";
echo "th{background:#f3f4f6}code{background:#f3f4f6;padding:2px 6px;border-radius:4px}";
echo "pre{background:#1e293b;color:#e2e8f0;padding:12px;border-radius:8px;font-size:11px}";
echo "h2{color:#4338ca}hr{margin:20px 0;border:none;border-top:1px solid #e5e7eb}</style>";
echo "</head><body>";

echo "<h2>🧪 Shill Bidding Detection — Admin Test</h2>";

/* Setup check */
echo "<div class='box'><h3>Setup Check</h3>";

$has_alerts = $conn->query("SHOW TABLES LIKE 'bid_fraud_alerts'")->num_rows > 0;
$has_ip = $conn->query("SHOW COLUMNS FROM bids LIKE 'ip_address'")->num_rows > 0;
$has_dev = $conn->query("SHOW COLUMNS FROM bids LIKE 'device_hash'")->num_rows > 0;
$has_ld = $conn->query("SHOW TABLES LIKE 'login_devices'")->num_rows > 0;

echo "<p>" . ($has_alerts ? "✅" : "❌") . " bid_fraud_alerts table</p>";
echo "<p>" . ($has_ip ? "✅" : "❌") . " bids.ip_address</p>";
echo "<p>" . ($has_dev ? "✅" : "❌") . " bids.device_hash</p>";
echo "<p>" . ($has_ld ? "✅" : "⚠️") . " login_devices table</p>";
echo "</div>";

if (!$has_alerts || !$has_ip || !$has_dev) {
    echo "<div class='box'><p>❌ Setup incomplete. SQL migration run karo.</p></div>";
    echo "</body></html>";
    exit;
}

/* Recent auctions */
echo "<div class='box'><h3>Recent Auctions (Real Data)</h3>";
$auctions = $conn->query("
    SELECT a.auction_id, a.title, a.seller_id,
           (SELECT COUNT(*) FROM bids WHERE auction_id = a.auction_id) AS bid_count
    FROM auctions a
    WHERE a.auction_status IN ('active','ended','completed')
    ORDER BY a.auction_id DESC
    LIMIT 10
")->fetch_all(MYSQLI_ASSOC);

if (empty($auctions)) {
    echo "<p>⚠️ Koi auction nahi mila.</p>";
} else {
    echo "<table>";
    echo "<tr><th>ID</th><th>Title</th><th>Seller</th><th>Bids</th><th>Analyze</th></tr>";
    foreach ($auctions as $a) {
        echo "<tr>";
        echo "<td>#" . $a['auction_id'] . "</td>";
        echo "<td>" . htmlspecialchars(substr($a['title'], 0, 50)) . "</td>";
        echo "<td>User #" . $a['seller_id'] . "</td>";
        echo "<td>" . $a['bid_count'] . "</td>";
        echo "<td><a href='?analyze=" . $a['auction_id'] . "' class='btn'>Analyze</a></td>";
        echo "</tr>";
    }
    echo "</table>";
}
echo "</div>";

/* Analyze specific auction */
if (isset($_GET['analyze'])) {
    $aid = (int)$_GET['analyze'];

    echo "<div class='box'><h3>Analyzing Auction #{$aid}</h3>";

    /* Get all bids for this auction */
    $bids = $conn->query("
        SELECT b.bid_id, b.bidder_id, b.bid_amount, b.ip_address, b.device_hash, b.bid_time,
               u.full_name AS bidder_name, u.created_at AS registered_at
        FROM bids b
        LEFT JOIN users u ON b.bidder_id = u.user_id
        WHERE b.auction_id = $aid
        ORDER BY b.bid_time DESC
    ")->fetch_all(MYSQLI_ASSOC);

    if (empty($bids)) {
        echo "<p>Koi bid nahi. Ye auction clean hai.</p>";
    } else {
        echo "<table>";
        echo "<tr><th>Bid ID</th><th>Bidder</th><th>Amount</th><th>IP</th><th>Device Hash</th><th>Time</th></tr>";
        foreach ($bids as $b) {
            echo "<tr>";
            echo "<td>#" . $b['bid_id'] . "</td>";
            echo "<td>" . htmlspecialchars($b['bidder_name'] ?? '?') . " (#" . $b['bidder_id'] . ")</td>";
            echo "<td>Rs. " . number_format($b['bid_amount']) . "</td>";
            echo "<td><code>" . htmlspecialchars($b['ip_address'] ?? '—') . "</code></td>";
            echo "<td><code>" . substr($b['device_hash'] ?? '—', 0, 16) . "...</code></td>";
            echo "<td>" . $b['bid_time'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";

        /* Analyze each unique bidder */
        $bidderIds = array_unique(array_column($bids, 'bidder_id'));
        echo "<h4 style='margin-top:16px'>Signals per Bidder</h4>";

        foreach ($bidderIds as $bid) {
            $signals = nb_bidfraud_signals($conn, $aid, (int)$bid);
            $score = nb_bidfraud_rule_score($signals);
            $color = $score >= 70 ? '#dc2626' : ($score >= 40 ? '#d97706' : '#059669');

            echo "<p>Bidder <strong>#" . $bid . "</strong> — Score: ";
            echo "<span style='color:{$color};font-weight:700'>" . $score . "</span></p>";

            if (!empty($signals)) {
                echo "<pre>" . htmlspecialchars(json_encode($signals, JSON_PRETTY_PRINT)) . "</pre>";
            } else {
                echo "<p style='color:#059669'>✅ No suspicious signals</p>";
            }
        }
    }
    echo "</div>";
}

/* Recent alerts */
echo "<div class='box'><h3>Recent Alerts (Last 20)</h3>";
$alerts = $conn->query("
    SELECT a.*, au.title AS auction_title, s.full_name AS seller_name, bd.full_name AS bidder_name
    FROM bid_fraud_alerts a
    LEFT JOIN auctions au ON a.auction_id = au.auction_id
    LEFT JOIN users s ON a.seller_id = s.user_id
    LEFT JOIN users bd ON a.bidder_id = bd.user_id
    ORDER BY a.alert_id DESC
    LIMIT 20
")->fetch_all(MYSQLI_ASSOC);

if (empty($alerts)) {
    echo "<p>ℹ️ Abhi koi alert nahi bana.</p>";
} else {
    echo "<table>";
    echo "<tr><th>ID</th><th>Auction</th><th>Seller</th><th>Bidder</th><th>Score</th><th>Status</th><th>Reason</th></tr>";
    foreach ($alerts as $a) {
        $color = $a['risk_score'] >= 70 ? '#dc2626' : ($a['risk_score'] >= 40 ? '#d97706' : '#059669');
        echo "<tr>";
        echo "<td>#" . $a['alert_id'] . "</td>";
        echo "<td>" . htmlspecialchars(substr($a['auction_title'] ?? '?', 0, 30)) . "</td>";
        echo "<td>" . htmlspecialchars($a['seller_name'] ?? '?') . "</td>";
        echo "<td>" . htmlspecialchars($a['bidder_name'] ?? '?') . "</td>";
        echo "<td style='color:{$color};font-weight:700'>" . $a['risk_score'] . "</td>";
        echo "<td>" . htmlspecialchars($a['status']) . "</td>";
        echo "<td style='font-size:11px'>" . htmlspecialchars(substr($a['ai_reason'] ?? '', 0, 60)) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
}
echo "</div>";

echo "<p style='color:#dc2626;font-size:12px;margin-top:24px'>";
echo "<strong>⚠️ Testing ke baad ye file delete kar do.</strong>";
echo "</p>";

echo "</body></html>";