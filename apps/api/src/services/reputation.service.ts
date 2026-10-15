import { query } from '../config/db.js';

export interface RatingBreakdown {
  5: number;
  4: number;
  3: number;
  2: number;
  1: number;
}

export interface SellerReputationData {
  seller_id: number;
  seller_name: string;
  member_since: string;
  is_verified_seller: boolean;
  kyc_status: string;
  bio: string | null;
  profile_image: string | null;
  metrics: {
    average_rating: number;
    total_reviews: number;
    completed_orders: number;
    active_auctions: number;
    positive_rating_percentage: number;
    rating_breakdown: RatingBreakdown;
  };
  reviews: Array<{
    review_id: number;
    order_id: number;
    auction_id: number;
    auction_title: string;
    reviewer_name: string;
    rating: number;
    comment: string | null;
    seller_reply: string | null;
    replied_at: string | null;
    created_at: string;
    is_verified_purchase: boolean;
  }>;
}

export class ReputationService {
  /**
   * Helper to mask reviewer name for privacy (e.g. "Ahmed Khan" -> "Ah*** Kh**")
   */
  static maskName(name: string): string {
    if (!name || name.trim().length === 0) return 'Verified Buyer';
    const parts = name.trim().split(/\s+/);
    return parts
      .map((part) => {
        if (part.length <= 2) return part + '*';
        return part.slice(0, 2) + '*'.repeat(Math.max(2, part.length - 2));
      })
      .join(' ');
  }

  /**
   * Fetch complete public seller reputation, metrics, and verified review stream
   */
  static async getSellerReputation(sellerId: number): Promise<SellerReputationData> {
    // 1. Fetch public seller metadata (Strictly exclude CNIC numbers, hashed passwords, or private docs)
    const sellers = await query<any[]>(
      `SELECT user_id, full_name, bio, profile_image, role, seller_kyc_status, created_at
       FROM users 
       WHERE user_id = ? AND status = 'active' LIMIT 1`,
      [sellerId]
    );

    if (!sellers.length) {
      throw new Error('Seller profile not found or inactive.');
    }

    const seller = sellers[0];

    // 2. Aggregate ratings from eligible, unhidden reviews
    const [reviewStats, breakdownRows, completedRows, activeAuctionRows, reviewList] = await Promise.all([
      query<any[]>(
        `SELECT 
          COALESCE(AVG(rating), 0) as avg_rating,
          COUNT(*) as total_reviews,
          COUNT(CASE WHEN rating >= 4 THEN 1 END) as positive_reviews
         FROM reviews 
         WHERE seller_id = ? AND (is_reported = 0 OR is_reported IS NULL)`,
        [sellerId]
      ),
      query<any[]>(
        `SELECT rating, COUNT(*) as count 
         FROM reviews 
         WHERE seller_id = ? AND (is_reported = 0 OR is_reported IS NULL)
         GROUP BY rating`,
        [sellerId]
      ),
      query<any[]>(
        "SELECT COUNT(*) as count FROM orders WHERE seller_id = ? AND order_status = 'completed'",
        [sellerId]
      ),
      query<any[]>(
        "SELECT COUNT(*) as count FROM auctions WHERE seller_id = ? AND auction_status = 'active' AND end_time > NOW()",
        [sellerId]
      ),
      query<any[]>(
        `SELECT 
          r.review_id, r.order_id, r.auction_id, r.rating, r.comment, r.seller_reply, r.replied_at, r.created_at,
          a.title as auction_title,
          u.full_name as reviewer_name
         FROM reviews r
         INNER JOIN auctions a ON a.auction_id = r.auction_id
         LEFT JOIN users u ON u.user_id = r.reviewer_id
         WHERE r.seller_id = ? AND (r.is_reported = 0 OR r.is_reported IS NULL)
         ORDER BY r.created_at DESC 
         LIMIT 50`,
        [sellerId]
      ),
    ]);

    const stats = reviewStats[0] || {};
    const totalReviews = parseInt(stats.total_reviews || 0, 10);
    const avgRating = totalReviews > 0 ? Math.round(parseFloat(stats.avg_rating) * 10) / 10 : 0.0;
    const positiveReviews = parseInt(stats.positive_reviews || 0, 10);
    const positivePct = totalReviews > 0 ? Math.round((positiveReviews / totalReviews) * 100) : 0;

    const breakdown: RatingBreakdown = { 5: 0, 4: 0, 3: 0, 2: 0, 1: 0 };
    breakdownRows.forEach((row: any) => {
      const r = parseInt(row.rating, 10) as keyof RatingBreakdown;
      if (breakdown[r] !== undefined) {
        breakdown[r] = parseInt(row.count, 10);
      }
    });

    const reviews = reviewList.map((r: any) => ({
      review_id: r.review_id,
      order_id: r.order_id,
      auction_id: r.auction_id,
      auction_title: r.auction_title || 'Auction Lot',
      reviewer_name: this.maskName(r.reviewer_name),
      rating: parseInt(r.rating, 10),
      comment: r.comment || null,
      seller_reply: r.seller_reply || null,
      replied_at: r.replied_at || null,
      created_at: r.created_at,
      is_verified_purchase: true,
    }));

    return {
      seller_id: seller.user_id,
      seller_name: seller.full_name,
      member_since: seller.created_at ? new Date(seller.created_at).toISOString().split('T')[0] : '2026-01-01',
      is_verified_seller: seller.seller_kyc_status === 'approved',
      kyc_status: seller.seller_kyc_status || 'unverified',
      bio: seller.bio || null,
      profile_image: seller.profile_image || null,
      metrics: {
        average_rating: avgRating,
        total_reviews: totalReviews,
        completed_orders: parseInt(completedRows[0]?.count || 0, 10),
        active_auctions: parseInt(activeAuctionRows[0]?.count || 0, 10),
        positive_rating_percentage: positivePct,
        rating_breakdown: breakdown,
      },
      reviews,
    };
  }

  /**
   * Report a review for policy violation or abuse
   */
  static async reportReview(userId: number, reviewId: number, reason: string) {
    if (!reason || reason.trim().length < 5) {
      throw new Error('Please provide a reason for reporting this review (at least 5 characters).');
    }

    const reviews = await query<any[]>(
      'SELECT review_id, seller_id, reviewer_id FROM reviews WHERE review_id = ? LIMIT 1',
      [reviewId]
    );

    if (!reviews.length) throw new Error('Review not found.');

    await query(
      `UPDATE reviews SET 
         is_reported = 1, 
         report_reason = ?, 
         reported_at = NOW() 
       WHERE review_id = ?`,
      [reason.trim().slice(0, 255), reviewId]
    );

    return { success: true, message: 'Review reported for administrative moderation.' };
  }
}
