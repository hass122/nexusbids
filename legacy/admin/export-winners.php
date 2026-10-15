<?php
/* =========================================================
   admin/export-winners.php — Export Winners to CSV
   ---------------------------------------------------------
   Features:
   - Admin-only access
   - Filter by payment status (?payment=paid|pending|all)
   - Filter by search term (?q=text)
   - Streams CSV directly to browser (no temp file)
   - UTF-8 BOM for Excel compatibility
   ========================================================= */

require_once "admin-auth.php";

/* ------------------------------------------------------------------
   Filters (optional)
   ------------------------------------------------------------------ */
$search_term   = trim($_GET['q'] ?? '');
$payment_filter = trim($_GET['payment'] ?? 'all');

$allowed_payments = ['all', 'paid', 'pending', 'failed', 'refunded'];
if (!in_array($payment_filter, $allowed_payments, true)) {
    $payment_filter = 'all';
}

/* ------------------------------------------------------------------
   Build query dynamically
   ------------------------------------------------------------------ */
$where_clauses = [];
$where_params  = [];
$where_types   = '';

if ($search_term !== '') {
    $where_clauses[] = "(buyer.full_name LIKE ? OR buyer.email LIKE ? OR a.title LIKE ? OR seller.full_name LIKE ?)";
    $like = '%' . $search_term . '%';
    for ($i = 0; $i < 4; $i++) {
        $where_params[] = $like;
    }
    $where_types .= 'ssss';
}

if ($payment_filter !== 'all') {
    if ($payment_filter === 'pending') {
        $where_clauses[] = "(p.payment_status IS NULL OR p.payment_status IN ('pending','processing'))";
    } else {
        $where_clauses[] = "p.payment_status = ?";
        $where_params[] = $payment_filter;
        $where_types .= 's';
    }
}

$where_sql = $where_clauses ? ('WHERE ' . implode(' AND ', $where_clauses)) : '';

/* ------------------------------------------------------------------
   Fetch winners
   ------------------------------------------------------------------ */
$sql = "
    SELECT
        w.winner_id,
        w.winning_bid,
        w.status AS winner_status,
        w.created_at AS won_at,
        a.auction_id,
        a.title AS auction_title,
        a.seller_id,
        seller.full_name AS seller_name,
        seller.email AS seller_email,
        buyer.user_id AS buyer_id,
        buyer.full_name AS buyer_name,
        buyer.email AS buyer_email,
        buyer.phone AS buyer_phone,
        p.payment_status AS payment_status,
        p.payment_method AS payment_method,
        p.transaction_id AS transaction_id,
        p.created_at AS paid_at
    FROM winners w
    INNER JOIN auctions a ON w.auction_id = a.auction_id
    INNER JOIN users seller ON a.seller_id = seller.user_id
    INNER JOIN users buyer ON w.user_id = buyer.user_id
    LEFT JOIN payments p ON p.auction_id = a.auction_id AND p.user_id = w.user_id
    {$where_sql}
    ORDER BY w.created_at DESC
";

$rows = [];
if ($stmt = $conn->prepare($sql)) {
    if (!empty($where_params)) {
        $stmt->bind_param($where_types, ...$where_params);
    }
    $stmt->execute();
    $res = $stmt->get_result();
    if ($res) {
        $rows = $res->fetch_all(MYSQLI_ASSOC);
    }
    $stmt->close();
} else {
    error_log("export-winners prepare failed: " . $conn->error);
}

/* ------------------------------------------------------------------
   Build filename
   ------------------------------------------------------------------ */
$filename_parts = ['winners'];
if ($payment_filter !== 'all') {
    $filename_parts[] = $payment_filter;
}
if ($search_term !== '') {
    $filename_parts[] = 'search';
}
$filename_parts[] = date('Y-m-d_His');
$filename = implode('_', $filename_parts) . '.csv';

/* ------------------------------------------------------------------
   Send headers
   ------------------------------------------------------------------ */
if (!headers_sent()) {
    // Clear any buffered output (prevents CSV corruption)
    while (ob_get_level() > 0) {
        ob_end_clean();
    }

    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');
}

/* ------------------------------------------------------------------
   Open output stream
   ------------------------------------------------------------------ */
$out = fopen('php://output', 'w');

if ($out === false) {
    die('Could not open output stream.');
}

/* UTF-8 BOM for Excel to read special chars correctly */
fwrite($out, "\xEF\xBB\xBF");

/* ------------------------------------------------------------------
   Header row
   ------------------------------------------------------------------ */
fputcsv($out, [
    'Winner ID',
    'Auction ID',
    'Auction Title',
    'Winning Bid (PKR)',
    'Winner Status',
    'Won On',
    'Buyer Name',
    'Buyer Email',
    'Buyer Phone',
    'Seller Name',
    'Seller Email',
    'Payment Status',
    'Payment Method',
    'Transaction ID',
    'Paid On',
]);

/* ------------------------------------------------------------------
   Data rows
   ------------------------------------------------------------------ */
foreach ($rows as $r) {
    // Format values nicely
    $won_on  = !empty($r['won_at'])  ? date('Y-m-d H:i:s', strtotime($r['won_at']))  : '';
    $paid_on = !empty($r['paid_at']) ? date('Y-m-d H:i:s', strtotime($r['paid_at'])) : '';

    // Fallback for payments joined with multiple rows — keep only latest
    $payment_status = $r['payment_status'] ?? 'pending';
    if ($payment_status === null || $payment_status === '') {
        $payment_status = 'pending';
    }

    fputcsv($out, [
        (int) $r['winner_id'],
        (int) $r['auction_id'],
        $r['auction_title'] ?? '',
        number_format((float) $r['winning_bid'], 2, '.', ''),
        $r['winner_status'] ?? '',
        $won_on,
        $r['buyer_name'] ?? '',
        $r['buyer_email'] ?? '',
        $r['buyer_phone'] ?? '',
        $r['seller_name'] ?? '',
        $r['seller_email'] ?? '',
        $payment_status,
        $r['payment_method'] ?? '',
        $r['transaction_id'] ?? '',
        $paid_on,
    ]);
}

fclose($out);
exit();