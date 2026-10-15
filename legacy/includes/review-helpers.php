<?php
/*
|--------------------------------------------------------------------------
| Review Helpers
|--------------------------------------------------------------------------
| Central utilities for fetching seller ratings, checking review
| eligibility, and rendering star ratings.
*/

if (!function_exists('nb_seller_rating')) {
    /**
     * Returns ['avg' => float, 'count' => int] for a seller.
     * Uses reviewee_id (the person being reviewed).
     */
    function nb_seller_rating($conn, $seller_id)
    {
        $stmt = $conn->prepare("
            SELECT AVG(rating) AS avg_rating, COUNT(*) AS total
            FROM reviews
            WHERE reviewee_id = ?
        ");
        $stmt->bind_param("i", $seller_id);
        $stmt->execute();
        $row = $stmt->get_result()->fetch_assoc();
        $stmt->close();

        return [
            'avg' => floatval($row['avg_rating'] ?? 0),
            'count' => intval($row['total'] ?? 0),
        ];
    }
}

if (!function_exists('nb_user_has_reviewed')) {
    /**
     * Check if the given reviewer already reviewed this auction.
     */
    function nb_user_has_reviewed($conn, $auction_id, $reviewer_id)
    {
        $stmt = $conn->prepare("
            SELECT review_id FROM reviews
            WHERE auction_id = ? AND reviewer_id = ?
            LIMIT 1
        ");
        $stmt->bind_param("ii", $auction_id, $reviewer_id);
        $stmt->execute();
        $found = $stmt->get_result()->num_rows > 0;
        $stmt->close();
        return $found;
    }
}

if (!function_exists('nb_reviews_for_seller')) {
    /**
     * Get all reviews for a seller, with buyer name and auction info.
     */
    function nb_reviews_for_seller($conn, $seller_id, $limit = 20)
    {
        $stmt = $conn->prepare("
            SELECT
                r.review_id, r.rating, r.review_text, r.created_at,
                u.full_name AS reviewer_name,
                a.title AS auction_title,
                a.auction_id
            FROM reviews r
            INNER JOIN users u ON r.reviewer_id = u.user_id
            INNER JOIN auctions a ON r.auction_id = a.auction_id
            WHERE r.reviewee_id = ?
            ORDER BY r.created_at DESC
            LIMIT ?
        ");
        $stmt->bind_param("ii", $seller_id, $limit);
        $stmt->execute();
        $rows = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);
        $stmt->close();
        return $rows;
    }
}

if (!function_exists('nb_render_stars')) {
    /**
     * Render 5 stars for a given rating (supports half stars).
     */
    function nb_render_stars($rating, $size_class = 'text-base')
    {
        $rating = floatval($rating);
        $html = '';
        for ($i = 1; $i <= 5; $i++) {
            if ($rating >= $i) {
                $html .= '<i class="fa-solid fa-star text-amber-400 ' . $size_class . '"></i>';
            } elseif ($rating >= $i - 0.5) {
                $html .= '<i class="fa-solid fa-star-half-stroke text-amber-400 ' . $size_class . '"></i>';
            } else {
                $html .= '<i class="fa-regular fa-star text-gray-300 dark:text-gray-600 ' . $size_class . '"></i>';
            }
        }
        return $html;
    }
}

if (!function_exists('nb_order_review_status')) {
    /**
     * For a given order, return whether the buyer can review the seller.
     * Returns: ['can_review' => bool, 'already' => bool, 'reason' => string]
     */
    function nb_order_review_status($conn, $order)
    {
        // Only after delivery/completion
        if (!in_array($order['order_status'], ['delivered', 'completed'])) {
            return ['can_review' => false, 'already' => false, 'reason' => 'Order not yet delivered'];
        }

        $already = nb_user_has_reviewed($conn, $order['auction_id'], $order['buyer_id']);
        if ($already) {
            return ['can_review' => false, 'already' => true, 'reason' => 'Already reviewed'];
        }

        return ['can_review' => true, 'already' => false, 'reason' => ''];
    }
}
?>