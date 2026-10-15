<?php


if (!function_exists('nb_send_email')) {

    /**
     * Send an email and log it.
     *
     * @param mysqli $conn
     * @param int|null $recipient_id
     * @param string $to
     * @param string $subject
     * @param string $html_body
     * @param string|null $template
     * @return bool
     */
    function nb_send_email(
        mysqli $conn,
        ?int $recipient_id,
        string $to,
        string $subject,
        string $html_body,
        ?string $template = null
    ): bool {
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            nb_email_log($conn, $recipient_id, $to, $subject, $html_body, $template, 'failed', 'Invalid email address');
            return false;
        }

        // Optional: respect user preferences
        if ($recipient_id && !nb_email_pref_allows($conn, $recipient_id, $template)) {
            nb_email_log($conn, $recipient_id, $to, $subject, $html_body, $template, 'queued', 'Skipped by user preference');
            return false;
        }

        // Prepend any configured headers/from-address
        $headers = [
            'MIME-Version: 1.0',
            'Content-Type: text/html; charset=UTF-8',
            'From: NexusBids <no-reply@' . (parse_url('http://' . ($_SERVER['HTTP_HOST'] ?? 'nexusbids.local'), PHP_URL_HOST)) . '>',
            'Reply-To: support@nexusbids.local',
            'X-Mailer: PHP/' . phpversion(),
        ];

        $ok = false;
        $error = null;

        // Wrap body in minimal HTML template if raw HTML not detected
        if (stripos($html_body, '<html') === false) {
            $html_body = nb_email_wrap($subject, $html_body);
        }

        /* ---------- Use PHPMailer via SMTP ---------- */
        require_once __DIR__ . '/mailer.php';

        try {
            if (function_exists('nb_send_mail')) {
                $ok = nb_send_mail($to, $subject, $html_body);
                if (!$ok)
                    $error = 'SMTP send failed — check mail-config.php credentials';
            } else {
                // Fallback to mail() if mailer.php not loaded
                $ok = @mail($to, $subject, $html_body, implode("\r\n", $headers));
                if (!$ok)
                    $error = 'mail() returned false';
            }
        } catch (Throwable $e) {
            $ok = false;
            $error = $e->getMessage();
        }

        nb_email_log(
            $conn,
            $recipient_id,
            $to,
            $subject,
            $html_body,
            $template,
            $ok ? 'sent' : 'failed',
            $error
        );

        return $ok;
    }

    /**
     * Log an email attempt.
     */
    function nb_email_log(
        mysqli $conn,
        ?int $recipient_id,
        string $to,
        string $subject,
        string $body,
        ?string $template,
        string $status,
        ?string $error
    ): void {
        // Guard: skip if table doesn't exist
        static $has_table = null;
        if ($has_table === null) {
            try {
                $r = $conn->query("SHOW TABLES LIKE 'email_log'");
                $has_table = $r && $r->num_rows > 0;
            } catch (Throwable $e) {
                $has_table = false;
            }
        }
        if (!$has_table)
            return;

        try {
            $sent_at = ($status === 'sent') ? date('Y-m-d H:i:s') : null;
            $stmt = $conn->prepare("
                INSERT INTO email_log 
                (recipient_id, recipient_email, subject, body, template, status, error_message, sent_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
            ");
            if (!$stmt)
                return;
            $stmt->bind_param(
                "isssssss",
                $recipient_id,
                $to,
                $subject,
                $body,
                $template,
                $status,
                $error,
                $sent_at
            );
            $stmt->execute();
            $stmt->close();
        } catch (Throwable $e) {
            error_log("email_log insert failed: " . $e->getMessage());
        }
    }

    /**
     * Wrap a plain body in a minimal, inline-CSS HTML template.
     */
    function nb_email_wrap(string $subject, string $body): string
    {
        $safe_subject = htmlspecialchars($subject, ENT_QUOTES, 'UTF-8');
        $safe_body = nl2br(htmlspecialchars($body, ENT_QUOTES, 'UTF-8'));
        return <<<HTML
<!DOCTYPE html>
<html>
<head><meta charset="utf-8"><title>{$safe_subject}</title></head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:-apple-system,Segoe UI,Roboto,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:32px 16px;">
    <tr><td align="center">
      <table width="560" cellpadding="0" cellspacing="0" style="background:#fff;border-radius:16px;overflow:hidden;box-shadow:0 2px 12px rgba(0,0,0,0.06);">
        <tr><td style="background:linear-gradient(135deg,#6366f1,#8b5cf6);padding:24px 32px;">
          <div style="color:#fff;font-size:20px;font-weight:800;letter-spacing:-0.02em;">NexusBids</div>
        </td></tr>
        <tr><td style="padding:32px;">
          <h1 style="margin:0 0 16px;font-size:20px;color:#111827;">{$safe_subject}</h1>
          <div style="color:#374151;font-size:15px;line-height:1.65;">{$safe_body}</div>
        </td></tr>
        <tr><td style="padding:20px 32px;background:#f9fafb;color:#9ca3af;font-size:12px;text-align:center;">
          © NexusBids · This is an automated message.
        </td></tr>
      </table>
    </td></tr>
  </table>
</body>
</html>
HTML;
    }

    /**
     * Check whether recipient's preference allows this template type.
     */
    function nb_email_pref_allows(mysqli $conn, int $user_id, ?string $template): bool
    {
        if (!$template)
            return true;

        // Map template → preference column
        $map = [
            'bid' => 'email_bids',
            'bids' => 'email_bids',
            'new_bid' => 'email_bids',
            'order' => 'email_orders',
            'shipment' => 'email_orders',
            'payout' => 'email_payouts',
            'payouts' => 'email_payouts',
            'auction' => 'email_auctions',
        ];
        $key = strtolower($template);
        $col = null;
        foreach ($map as $k => $c) {
            if (strpos($key, $k) !== false) {
                $col = $c;
                break;
            }
        }
        if (!$col)
            return true;

        try {
            static $cols_checked = false;
            if (!$cols_checked) {
                $r = $conn->query("SHOW COLUMNS FROM users LIKE '$col'");
                if (!$r || $r->num_rows === 0)
                    return true;
                $cols_checked = true;
            }
            $stmt = $conn->prepare("SELECT `$col` AS pref FROM users WHERE user_id = ? LIMIT 1");
            if (!$stmt)
                return true;
            $stmt->bind_param("i", $user_id);
            $stmt->execute();
            $row = $stmt->get_result()->fetch_assoc();
            $stmt->close();
            return intval($row['pref'] ?? 1) === 1;
        } catch (Throwable $e) {
            return true;
        }
    }

    /**
     * Convenience: fetch a user's email + name.
     */
    function nb_get_user_email(mysqli $conn, int $user_id): ?array
    {
        $stmt = $conn->prepare("SELECT email, full_name FROM users WHERE user_id = ? LIMIT 1");
        if (!$stmt)
            return null;
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();
        return $row ?: null;
    }
}