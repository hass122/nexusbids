<?php
/* =========================================================
   includes/ledger.php — Seller Ledger + Escrow Helpers
   Aligned with escrow_holds schema:
     PK = id, columns: order_id, seller_id, buyer_id, amount,
     released_amount, refunded_amount, status, hold_reason,
     release_reason, auto_release_at, released_at, created_at
   ========================================================= */

if (!function_exists('nb_ledger_tables_exist')) {

    function nb_ledger_tables_exist(mysqli $conn): array
    {
        static $cache = null;
        if ($cache !== null)
            return $cache;
        $cache = [];
        foreach (['seller_ledger', 'payment_transactions', 'escrow_holds', 'refunds', 'payment_disputes', 'payout_approvals', 'payment_audit_log', 'payouts'] as $t) {
            try {
                $r = $conn->query("SHOW TABLES LIKE '" . $conn->real_escape_string($t) . "'");
                $cache[$t] = $r && $r->num_rows > 0;
            } catch (Throwable $e) {
                $cache[$t] = false;
            }
        }
        return $cache;
    }

    function nb_ledger_columns(mysqli $conn, string $table): array
    {
        static $cache = [];
        if (isset($cache[$table]))
            return $cache[$table];
        $cols = [];
        try {
            $r = $conn->query("SHOW COLUMNS FROM `$table`");
            if ($r)
                while ($c = $r->fetch_assoc())
                    $cols[] = $c['Field'];
        } catch (Throwable $e) {
        }
        $cache[$table] = $cols;
        return $cols;
    }

    function nb_ledger_pick(array $cands, array $haystack): ?string
    {
        foreach ($cands as $c)
            if (in_array($c, $haystack, true))
                return $c;
        return null;
    }

    /**
     * Seller balances:
     * - available, pending, held, in_dispute, total_earned, total_withdrawn, pending_payout
     */
    function nb_ledger_get_balances(mysqli $conn, int $seller_id): array
    {
        $out = [
            'available' => 0.0,
            'pending' => 0.0,
            'held' => 0.0,
            'in_dispute' => 0.0,
            'total_earned' => 0.0,
            'total_withdrawn' => 0.0,
            'pending_payout' => 0.0,
        ];
        $tables = nb_ledger_tables_exist($conn);

        /* Lifetime earned from completed/delivered orders */
        try {
            $r = $conn->query("
                SELECT COALESCE(SUM(seller_amount),0) AS t
                FROM orders
                WHERE seller_id = $seller_id
                  AND order_status IN ('completed','delivered')
            ");
            if ($r)
                $out['total_earned'] = floatval($r->fetch_assoc()['t'] ?? 0);
        } catch (Throwable $e) {
        }

        /* Total withdrawn from payouts */
        if ($tables['payouts'] ?? false) {
            $pc = nb_ledger_columns($conn, 'payouts');
            $amt = nb_ledger_pick(['amount'], $pc);
            $st = nb_ledger_pick(['status'], $pc);
            if ($amt && $st) {
                try {
                    $r = $conn->query("SELECT COALESCE(SUM(`$amt`),0) AS t FROM payouts WHERE seller_id = $seller_id AND `$st` IN ('approved','paid')");
                    if ($r)
                        $out['total_withdrawn'] = floatval($r->fetch_assoc()['t'] ?? 0);
                    $r = $conn->query("SELECT COALESCE(SUM(`$amt`),0) AS t FROM payouts WHERE seller_id = $seller_id AND `$st` = 'pending'");
                    if ($r)
                        $out['pending_payout'] = floatval($r->fetch_assoc()['t'] ?? 0);
                } catch (Throwable $e) {
                }
            }
        }

        /* Held money (escrow) */
        if ($tables['escrow_holds'] ?? false) {
            try {
                $r = $conn->query("
                    SELECT COALESCE(SUM(amount - released_amount - refunded_amount),0) AS t
                    FROM escrow_holds
                    WHERE seller_id = $seller_id AND status IN ('held','partially_released')
                ");
                if ($r)
                    $out['held'] = max(0, floatval($r->fetch_assoc()['t'] ?? 0));
            } catch (Throwable $e) {
            }
        }

        /* In-dispute money */
        if ($tables['payment_disputes'] ?? false) {
            try {
                $r = $conn->query("
                    SELECT COALESCE(SUM(amount),0) AS t
                    FROM payment_disputes
                    WHERE seller_id = $seller_id AND status IN ('open','under_review','evidence_required')
                ");
                if ($r)
                    $out['in_dispute'] = floatval($r->fetch_assoc()['t'] ?? 0);
            } catch (Throwable $e) {
            }
        }

        /* Available balance */
        if ($tables['seller_ledger'] ?? false) {
            try {
                $r = $conn->query("
                    SELECT COALESCE(SUM(
                        CASE
                            WHEN entry_type IN ('credit','release') AND status IN ('available','released') THEN amount
                            WHEN entry_type IN ('debit','fee','payout','refund') AND status IN ('available','released') THEN -amount
                            WHEN entry_type = 'adjustment' THEN amount
                            ELSE 0
                        END
                    ),0) AS t
                    FROM seller_ledger WHERE seller_id = $seller_id
                ");
                if ($r) {
                    $available = floatval($r->fetch_assoc()['t'] ?? 0);
                    $out['available'] = max(0, $available - $out['pending_payout']);
                }
            } catch (Throwable $e) {
            }
        } else {
            $out['available'] = max(
                0,
                $out['total_earned']
                - $out['total_withdrawn']
                - $out['pending_payout']
                - $out['held']
                - $out['in_dispute']
            );
        }

        /* Pending = delivered but not yet cleared */
        try {
            $r = $conn->query("
                SELECT COALESCE(SUM(seller_amount),0) AS t
                FROM orders
                WHERE seller_id = $seller_id
                  AND order_status IN ('delivered')
            ");
            if ($r)
                $out['pending'] = floatval($r->fetch_assoc()['t'] ?? 0);
        } catch (Throwable $e) {
        }

        return $out;
    }

    /** Add a ledger entry (safe) */
    function nb_ledger_add(mysqli $conn, array $e): bool
    {
        $tables = nb_ledger_tables_exist($conn);
        if (!($tables['seller_ledger'] ?? false))
            return false;
        try {
            $seller_id = intval($e['seller_id'] ?? 0);
            if ($seller_id <= 0)
                return false;

            $balance_after = floatval($e['balance_after'] ?? 0);

            $stmt = $conn->prepare("
                INSERT INTO seller_ledger
                (seller_id, order_id, payout_id, auction_id, entry_type, entry_category,
                 amount, balance_after, status, reference, description, metadata)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $order_id = $e['order_id'] ?? null;
            $payout_id = $e['payout_id'] ?? null;
            $auction_id = $e['auction_id'] ?? null;
            $type = $e['entry_type'];
            $category = $e['entry_category'] ?? 'general';
            $amount = floatval($e['amount'] ?? 0);
            $status = $e['status'] ?? 'available';
            $reference = $e['reference'] ?? null;
            $desc = $e['description'] ?? null;
            $meta = isset($e['metadata']) ? json_encode($e['metadata']) : null;

            $stmt->bind_param(
                "iiiisssdssss",
                $seller_id,
                $order_id,
                $payout_id,
                $auction_id,
                $type,
                $category,
                $amount,
                $balance_after,
                $status,
                $reference,
                $desc,
                $meta
            );
            $ok = $stmt->execute();
            $stmt->close();
            return (bool) $ok;
        } catch (Throwable $ex) {
            error_log("ledger add failed: " . $ex->getMessage());
            return false;
        }
    }

    /** Audit log */
    function nb_pay_audit(mysqli $conn, array $data): void
    {
        $tables = nb_ledger_tables_exist($conn);
        if (!($tables['payment_audit_log'] ?? false))
            return;
        try {
            $stmt = $conn->prepare("
                INSERT INTO payment_audit_log
                (actor_id, actor_type, entity_type, entity_id, action, old_value, new_value, ip_address, user_agent)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
            ");
            $actor_id = $data['actor_id'] ?? null;
            $actor_type = $data['actor_type'] ?? 'system';
            $etype = $data['entity_type'];
            $eid = $data['entity_id'] ?? null;
            $action = $data['action'];
            $old = isset($data['old']) ? json_encode($data['old']) : null;
            $new = isset($data['new']) ? json_encode($data['new']) : null;
            $ip = $data['ip'] ?? ($_SERVER['REMOTE_ADDR'] ?? null);
            $ua = substr($data['user_agent'] ?? ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255);

            $stmt->bind_param(
                "ississsss",
                $actor_id,
                $actor_type,
                $etype,
                $eid,
                $action,
                $old,
                $new,
                $ip,
                $ua
            );
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
        }
    }

    /** List ledger entries for seller */
    function nb_ledger_list(mysqli $conn, int $seller_id, array $f = []): array
    {
        $tables = nb_ledger_tables_exist($conn);
        if (!($tables['seller_ledger'] ?? false))
            return [];
        try {
            $where = ["seller_id = ?"];
            $params = [$seller_id];
            $types = "i";

            if (!empty($f['type'])) {
                $where[] = "entry_type = ?";
                $params[] = $f['type'];
                $types .= "s";
            }
            if (!empty($f['category'])) {
                $where[] = "entry_category = ?";
                $params[] = $f['category'];
                $types .= "s";
            }
            if (!empty($f['from'])) {
                $where[] = "created_at >= ?";
                $params[] = $f['from'];
                $types .= "s";
            }
            if (!empty($f['to'])) {
                $where[] = "created_at <= ?";
                $params[] = $f['to'];
                $types .= "s";
            }

            $limit = max(1, min(500, intval($f['limit'] ?? 100)));
            $offset = max(0, intval($f['offset'] ?? 0));

            $sql = "SELECT * FROM seller_ledger WHERE " . implode(' AND ', $where) . " ORDER BY created_at DESC LIMIT ? OFFSET ?";
            $params[] = $limit;
            $params[] = $offset;
            $types .= "ii";

            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Count ledger entries */
    function nb_ledger_count(mysqli $conn, int $seller_id, array $f = []): int
    {
        $tables = nb_ledger_tables_exist($conn);
        if (!($tables['seller_ledger'] ?? false))
            return 0;
        try {
            $where = ["seller_id = ?"];
            $params = [$seller_id];
            $types = "i";
            if (!empty($f['type'])) {
                $where[] = "entry_type = ?";
                $params[] = $f['type'];
                $types .= "s";
            }
            if (!empty($f['category'])) {
                $where[] = "entry_category = ?";
                $params[] = $f['category'];
                $types .= "s";
            }
            $sql = "SELECT COUNT(*) AS t FROM seller_ledger WHERE " . implode(' AND ', $where);
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $t = intval($stmt->get_result()->fetch_assoc()['t'] ?? 0);
            $stmt->close();
            return $t;
        } catch (Throwable $e) {
            return 0;
        }
    }

    /** Escrow holds list (for seller view) */
    function nb_escrow_list(mysqli $conn, int $seller_id, array $f = []): array
    {
        $tables = nb_ledger_tables_exist($conn);
        if (!($tables['escrow_holds'] ?? false))
            return [];
        try {
            $where = ["e.seller_id = ?"];
            $params = [$seller_id];
            $types = "i";
            if (!empty($f['status'])) {
                $where[] = "e.status = ?";
                $params[] = $f['status'];
                $types .= "s";
            }
            $limit = max(1, min(500, intval($f['limit'] ?? 100)));
            $sql = "SELECT e.*, o.order_number, o.total_amount, a.title AS auction_title, a.image AS auction_image
                    FROM escrow_holds e
                    LEFT JOIN orders o ON e.order_id = o.order_id
                    LEFT JOIN auctions a ON o.auction_id = a.auction_id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY e.created_at DESC LIMIT ?";
            $params[] = $limit;
            $types .= "i";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Transactions list */
    function nb_txn_list(mysqli $conn, int $seller_id, array $f = []): array
    {
        $tables = nb_ledger_tables_exist($conn);
        if (!($tables['payment_transactions'] ?? false))
            return [];
        try {
            $where = ["t.seller_id = ?"];
            $params = [$seller_id];
            $types = "i";
            if (!empty($f['status'])) {
                $where[] = "t.status = ?";
                $params[] = $f['status'];
                $types .= "s";
            }
            $limit = max(1, min(500, intval($f['limit'] ?? 100)));
            $sql = "SELECT t.*, o.order_number, a.title AS auction_title
                    FROM payment_transactions t
                    LEFT JOIN orders o ON t.order_id = o.order_id
                    LEFT JOIN auctions a ON o.auction_id = a.auction_id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY t.created_at DESC LIMIT ?";
            $params[] = $limit;
            $types .= "i";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Disputes list */
    function nb_disputes_list(mysqli $conn, int $seller_id, array $f = []): array
    {
        $tables = nb_ledger_tables_exist($conn);
        if (!($tables['payment_disputes'] ?? false))
            return [];
        try {
            $where = ["d.seller_id = ?"];
            $params = [$seller_id];
            $types = "i";
            if (!empty($f['status'])) {
                $where[] = "d.status = ?";
                $params[] = $f['status'];
                $types .= "s";
            }
            $limit = max(1, min(500, intval($f['limit'] ?? 100)));
            $sql = "SELECT d.*, o.order_number, a.title AS auction_title
                    FROM payment_disputes d
                    LEFT JOIN orders o ON d.order_id = o.order_id
                    LEFT JOIN auctions a ON o.auction_id = a.auction_id
                    WHERE " . implode(' AND ', $where) . "
                    ORDER BY d.created_at DESC LIMIT ?";
            $params[] = $limit;
            $types .= "i";
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
            $stmt->close();
            return $rows;
        } catch (Throwable $e) {
            return [];
        }
    }

    /** Summarize ledger entry for display */
    function nb_ledger_display(array $row): array
    {
        $type = strtolower($row['entry_type'] ?? 'adjustment');
        $map = [
            'credit' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'icon' => 'fa-arrow-down', 'label' => 'Credit', 'sign' => '+'],
            'debit' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'icon' => 'fa-arrow-up', 'label' => 'Debit', 'sign' => '-'],
            'hold' => ['bg' => 'bg-amber-500/10', 'text' => 'text-amber-700 dark:text-amber-400', 'icon' => 'fa-lock', 'label' => 'Escrow Hold', 'sign' => ''],
            'release' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'icon' => 'fa-unlock', 'label' => 'Escrow Release', 'sign' => '+'],
            'refund' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'icon' => 'fa-rotate-left', 'label' => 'Refund', 'sign' => '-'],
            'fee' => ['bg' => 'bg-slate-500/10', 'text' => 'text-slate-600 dark:text-slate-400', 'icon' => 'fa-percent', 'label' => 'Fee', 'sign' => '-'],
            'payout' => ['bg' => 'bg-indigo-500/10', 'text' => 'text-indigo-700 dark:text-indigo-400', 'icon' => 'fa-money-bill-transfer', 'label' => 'Payout', 'sign' => '-'],
            'adjustment' => ['bg' => 'bg-blue-500/10', 'text' => 'text-blue-700 dark:text-blue-400', 'icon' => 'fa-sliders', 'label' => 'Adjustment', 'sign' => ''],
            'dispute_hold' => ['bg' => 'bg-rose-500/10', 'text' => 'text-rose-700 dark:text-rose-400', 'icon' => 'fa-scale-balanced', 'label' => 'Dispute Hold', 'sign' => ''],
            'dispute_release' => ['bg' => 'bg-emerald-500/10', 'text' => 'text-emerald-700 dark:text-emerald-400', 'icon' => 'fa-shield', 'label' => 'Dispute Released', 'sign' => '+'],
        ];
        return $map[$type] ?? ['bg' => 'bg-gray-500/10', 'text' => 'text-gray-600 dark:text-gray-400', 'icon' => 'fa-circle', 'label' => ucfirst($type), 'sign' => ''];
    }

    /* ============================================================
       ESCROW HELPERS — schema-aligned (PK = id)
       ============================================================ */

    /**
     * Fetch single escrow + related order/seller/buyer
     */
    function nb_escrow_fetch(mysqli $conn, int $escrow_id): ?array
    {
        if ($escrow_id <= 0)
            return null;
        $tables = nb_ledger_tables_exist($conn);
        if (!($tables['escrow_holds'] ?? false))
            return null;

        try {
            $stmt = $conn->prepare("
                SELECT e.*,
                       o.order_id, o.order_number, o.order_status, o.payment_status,
                       o.total_amount, o.seller_amount, o.buyer_id, o.seller_id AS order_seller_id,
                       o.auction_id, o.buyer_confirmed_at, o.delivered_at,
                       a.title AS auction_title, a.image AS auction_image,
                       sb.full_name AS seller_name, sb.email AS seller_email,
                       bb.full_name AS buyer_name,  bb.email AS buyer_email
                FROM escrow_holds e
                INNER JOIN orders   o  ON o.order_id   = e.order_id
                INNER JOIN auctions a  ON a.auction_id = o.auction_id
                LEFT  JOIN users    sb ON sb.user_id   = e.seller_id
                LEFT  JOIN users    bb ON bb.user_id   = e.buyer_id
                WHERE e.id = ?
                LIMIT 1
            ");
            $stmt->bind_param("i", $escrow_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return $row ?: null;
        } catch (Throwable $e) {
            error_log("nb_escrow_fetch failed: " . $e->getMessage());
            return null;
        }
    }

    /**
     * Escrow metrics helper
     */
    function nb_escrow_metrics(array $esc): array
    {
        $total = (float) ($esc['amount'] ?? 0);
        $released = (float) ($esc['released_amount'] ?? 0);
        $refunded = (float) ($esc['refunded_amount'] ?? 0);
        return [
            'total' => $total,
            'released' => $released,
            'refunded' => $refunded,
            'available' => max(0, $total - $released - $refunded),
            'status' => strtolower((string) ($esc['status'] ?? 'held')),
        ];
    }

    /**
     * Update escrow row dynamically (schema-aware)
     * Supported keys:
     *   status, released_amount, refunded_amount, hold_reason, release_reason
     *   released_at (auto NOW), created_at (skip), auto_release_at (NOW + N days)
     */
    function nb_escrow_update(mysqli $conn, int $escrow_id, array $data): bool
    {
        if ($escrow_id <= 0)
            return false;
        $cols = nb_ledger_columns($conn, 'escrow_holds');
        if (empty($cols))
            return false;

        $sets = [];
        $vals = [];
        $types = '';

        $scalar_map = [
            'status' => 's',
            'released_amount' => 'd',
            'refunded_amount' => 'd',
            'hold_reason' => 's',
            'release_reason' => 's',
        ];
        $now_cols = ['released_at'];
        $future_cols = ['auto_release_at'];

        foreach ($data as $k => $v) {
            if (array_key_exists($k, $scalar_map)) {
                if (!in_array($k, $cols, true))
                    continue;
                $sets[] = "`$k` = ?";
                $vals[] = $v;
                $types .= $scalar_map[$k];
            } elseif (in_array($k, $now_cols, true) && in_array($k, $cols, true)) {
                $sets[] = "`$k` = NOW()";
            } elseif (in_array($k, $future_cols, true) && in_array($k, $cols, true)) {
                $days = max(1, (int) $v);
                $sets[] = "`$k` = DATE_ADD(NOW(), INTERVAL $days DAY)";
            }
        }

        if (empty($sets))
            return false;

        $vals[] = $escrow_id;
        $types .= 'i';

        try {
            $stmt = $conn->prepare("UPDATE escrow_holds SET " . implode(', ', $sets) . " WHERE id = ?");
            $stmt->bind_param($types, ...$vals);
            $ok = $stmt->execute();
            $stmt->close();
            return (bool) $ok;
        } catch (Throwable $e) {
            error_log("nb_escrow_update failed: " . $e->getMessage());
            return false;
        }
    }

    /**
     * RELEASE escrow to seller
     * $admin_id = 0 → system/auto-release (cron)
     */
    function nb_ledger_release_escrow(mysqli $conn, int $escrow_id, int $admin_id = 0, string $notes = ''): array
    {
        $out = ['ok' => false, 'amount' => 0.0, 'status' => '', 'error' => null];

        $esc = nb_escrow_fetch($conn, $escrow_id);
        if (!$esc) {
            $out['error'] = 'Escrow not found';
            return $out;
        }

        $m = nb_escrow_metrics($esc);
        if (in_array($m['status'], ['released', 'refunded', 'cancelled'], true)) {
            $out['error'] = "Cannot release escrow in '{$m['status']}' status";
            return $out;
        }
        if ($m['available'] <= 0) {
            $out['error'] = 'No available balance to release';
            return $out;
        }

        $amount = $m['available'];
        $new_released = $m['released'] + $amount;
        $new_status = ($new_released >= $m['total']) ? 'released' : 'partially_released';

        $conn->begin_transaction();
        try {
            $release_reason = ($admin_id > 0) ? 'admin_release' : 'auto_release';

            /* 1. Update escrow row */
            nb_escrow_update($conn, $escrow_id, [
                'status' => $new_status,
                'released_amount' => $new_released,
                'released_at' => true,
                'release_reason' => $release_reason,
            ]);

            /* 2. Seller ledger entry */
            $balances = nb_ledger_get_balances($conn, (int) $esc['seller_id']);
            $balance_after = (float) $balances['available'] + $amount;

            nb_ledger_add($conn, [
                'seller_id' => (int) $esc['seller_id'],
                'order_id' => (int) $esc['order_id'],
                'auction_id' => (int) $esc['auction_id'],
                'entry_type' => 'release',
                'entry_category' => 'escrow_release',
                'amount' => $amount,
                'balance_after' => $balance_after,
                'status' => 'available',
                'reference' => 'ESCROW-' . $escrow_id,
                'description' => 'Escrow released for order ' . ($esc['order_number'] ?? ''),
                'metadata' => [
                    'escrow_id' => $escrow_id,
                    'admin_id' => $admin_id,
                    'release_type' => $release_reason,
                    'notes' => $notes,
                    'released_at' => date('Y-m-d H:i:s'),
                ],
            ]);

            /* 3. Order → completed if fully released */
            if ($new_status === 'released') {
                $ou = $conn->prepare("UPDATE orders SET order_status = 'completed'
                                      WHERE order_id = ? AND order_status NOT IN ('cancelled','refunded')");
                $ou->bind_param("i", $esc['order_id']);
                $ou->execute();
                $ou->close();
            }

            /* 4. Audit */
            nb_pay_audit($conn, [
                'actor_id' => $admin_id,
                'actor_type' => $admin_id > 0 ? 'admin' : 'system',
                'entity_type' => 'escrow',
                'entity_id' => $escrow_id,
                'action' => 'escrow.released',
                'new' => [
                    'order_id' => $esc['order_id'],
                    'amount' => $amount,
                    'to' => 'seller',
                    'new_status' => $new_status,
                    'reason' => $release_reason,
                    'notes' => $notes,
                ],
            ]);

            $conn->commit();
            $out['ok'] = true;
            $out['amount'] = $amount;
            $out['status'] = $new_status;
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $e2) {
            }
            $out['error'] = $e->getMessage();
            error_log("nb_ledger_release_escrow failed: " . $e->getMessage());
        }
        return $out;
    }

    /**
     * REFUND escrow to buyer
     * Note: ENUM has no 'partially_refunded'.
     * Full refund → status 'refunded'. Partial → status stays, amount updates.
     */
    function nb_ledger_refund_escrow(mysqli $conn, int $escrow_id, int $admin_id = 0, float $amount = 0, string $notes = ''): array
    {
        $out = ['ok' => false, 'amount' => 0.0, 'status' => '', 'error' => null];

        $esc = nb_escrow_fetch($conn, $escrow_id);
        if (!$esc) {
            $out['error'] = 'Escrow not found';
            return $out;
        }

        $m = nb_escrow_metrics($esc);
        if (in_array($m['status'], ['released', 'refunded', 'cancelled'], true)) {
            $out['error'] = "Cannot refund escrow in '{$m['status']}' status";
            return $out;
        }

        if ($amount <= 0)
            $amount = $m['available'];
        if ($amount > $m['available']) {
            $out['error'] = 'Refund amount exceeds available balance';
            return $out;
        }

        $new_refunded = $m['refunded'] + $amount;
        $is_full = ($new_refunded >= $m['total']);
        $new_status = $is_full ? 'refunded' : $m['status'];

        $conn->begin_transaction();
        try {
            $update_data = [
                'refunded_amount' => $new_refunded,
                'release_reason' => 'refund_to_buyer',
            ];
            if ($is_full)
                $update_data['status'] = 'refunded';

            nb_escrow_update($conn, $escrow_id, $update_data);

            /* Seller ledger — refund reduces seller's claim */
            $balances = nb_ledger_get_balances($conn, (int) $esc['seller_id']);
            $balance_after = max(0, (float) $balances['available'] - $amount);

            nb_ledger_add($conn, [
                'seller_id' => (int) $esc['seller_id'],
                'order_id' => (int) $esc['order_id'],
                'auction_id' => (int) $esc['auction_id'],
                'entry_type' => 'refund',
                'entry_category' => 'escrow_refund',
                'amount' => $amount,
                'balance_after' => $balance_after,
                'status' => 'available',
                'reference' => 'ESCROW-' . $escrow_id,
                'description' => 'Refunded to buyer for order ' . ($esc['order_number'] ?? ''),
                'metadata' => [
                    'escrow_id' => $escrow_id,
                    'admin_id' => $admin_id,
                    'buyer_id' => (int) $esc['buyer_id'],
                    'notes' => $notes,
                    'refunded_at' => date('Y-m-d H:i:s'),
                ],
            ]);

            /* Refunds table insert (if exists) */
            $tables = nb_ledger_tables_exist($conn);
            if ($tables['refunds'] ?? false) {
                try {
                    $ref_cols = nb_ledger_columns($conn, 'refunds');
                    $cols = [];
                    $ph = [];
                    $vals2 = [];
                    $types2 = '';

                    $add = function ($col, $val, $type) use (&$cols, &$ph, &$vals2, &$types2, $ref_cols) {
                        if (in_array($col, $ref_cols, true)) {
                            $cols[] = $col;
                            $ph[] = '?';
                            $vals2[] = $val;
                            $types2 .= $type;
                        }
                    };
                    $add('order_id', $esc['order_id'], 'i');
                    $add('escrow_id', $escrow_id, 'i');
                    $add('buyer_id', $esc['buyer_id'], 'i');
                    $add('seller_id', $esc['seller_id'], 'i');
                    $add('amount', $amount, 'd');
                    $add('reason', ($notes ?: 'Admin refund'), 's');
                    $add('status', 'processed', 's');
                    $add('processed_by', $admin_id, 'i');

                    if (in_array('processed_at', $ref_cols, true)) {
                        $cols[] = 'processed_at';
                        $ph[] = 'NOW()';
                    }
                    if (in_array('created_at', $ref_cols, true)) {
                        $cols[] = 'created_at';
                        $ph[] = 'NOW()';
                    }

                    if (!empty($cols)) {
                        $sql = "INSERT INTO refunds (" . implode(',', $cols) . ") VALUES (" . implode(',', $ph) . ")";
                        $ri = $conn->prepare($sql);
                        if ($ri) {
                            if ($types2)
                                $ri->bind_param($types2, ...$vals2);
                            $ri->execute();
                            $ri->close();
                        }
                    }
                } catch (Throwable $e) {
                    error_log("refunds insert failed: " . $e->getMessage());
                }
            }

            /* Order fully refunded */
            if ($is_full) {
                $ou = $conn->prepare("UPDATE orders SET order_status = 'refunded', payment_status = 'refunded' WHERE order_id = ?");
                $ou->bind_param("i", $esc['order_id']);
                $ou->execute();
                $ou->close();
            }

            /* Audit */
            nb_pay_audit($conn, [
                'actor_id' => $admin_id,
                'actor_type' => 'admin',
                'entity_type' => 'escrow',
                'entity_id' => $escrow_id,
                'action' => 'escrow.refunded',
                'new' => [
                    'order_id' => $esc['order_id'],
                    'amount' => $amount,
                    'to' => 'buyer',
                    'buyer_id' => (int) $esc['buyer_id'],
                    'is_full' => $is_full,
                ],
            ]);

            $conn->commit();
            $out['ok'] = true;
            $out['amount'] = $amount;
            $out['status'] = $new_status;
        } catch (Throwable $e) {
            try {
                $conn->rollback();
            } catch (Throwable $e2) {
            }
            $out['error'] = $e->getMessage();
            error_log("nb_ledger_refund_escrow failed: " . $e->getMessage());
        }
        return $out;
    }

    /**
     * FREEZE escrow → 'disputed'
     */
    function nb_ledger_freeze_escrow(mysqli $conn, int $escrow_id, int $admin_id = 0): array
    {
        $out = ['ok' => false, 'error' => null];
        $esc = nb_escrow_fetch($conn, $escrow_id);
        if (!$esc) {
            $out['error'] = 'Escrow not found';
            return $out;
        }

        $status = strtolower((string) $esc['status']);
        if (in_array($status, ['released', 'refunded', 'cancelled', 'disputed'], true)) {
            $out['error'] = "Cannot freeze escrow in '{$status}' status";
            return $out;
        }

        $ok = nb_escrow_update($conn, $escrow_id, ['status' => 'disputed']);
        if ($ok) {
            nb_pay_audit($conn, [
                'actor_id' => $admin_id,
                'actor_type' => 'admin',
                'entity_type' => 'escrow',
                'entity_id' => $escrow_id,
                'action' => 'escrow.frozen',
                'new' => ['order_id' => $esc['order_id']],
            ]);
            $out['ok'] = true;
        } else {
            $out['error'] = 'Update failed';
        }
        return $out;
    }

    /**
     * UNFREEZE escrow → 'held'
     */
    function nb_ledger_unfreeze_escrow(mysqli $conn, int $escrow_id, int $admin_id = 0): array
    {
        $out = ['ok' => false, 'error' => null];
        $esc = nb_escrow_fetch($conn, $escrow_id);
        if (!$esc) {
            $out['error'] = 'Escrow not found';
            return $out;
        }

        if (strtolower((string) $esc['status']) !== 'disputed') {
            $out['error'] = 'Escrow is not frozen';
            return $out;
        }

        $ok = nb_escrow_update($conn, $escrow_id, ['status' => 'held']);
        if ($ok) {
            nb_pay_audit($conn, [
                'actor_id' => $admin_id,
                'actor_type' => 'admin',
                'entity_type' => 'escrow',
                'entity_id' => $escrow_id,
                'action' => 'escrow.unfrozen',
                'new' => ['order_id' => $esc['order_id']],
            ]);
            $out['ok'] = true;
        } else {
            $out['error'] = 'Update failed';
        }
        return $out;
    }
}