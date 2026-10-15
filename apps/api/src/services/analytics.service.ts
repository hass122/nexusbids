import { query } from '../config/db.js';

export interface SellerAnalyticsData {
  views: number;
  watchlist_count: number;
  bids: number;
  highest_bid: number;
  sold_auctions: number;
  active_auctions: number;
  conversion_rate: number;
  revenue: number;
  escrow_held: number;
  pending_payouts: number;
  daily_bids: any[];
  daily_revenue: any[];
  days: number;
}

export class AnalyticsService {
  static async getSellerAnalytics(sellerId: number, days: number = 7): Promise<SellerAnalyticsData> {
    const cappedDays = Math.max(1, Math.min(30, days));

    const [
      dailyBids,
      dailyRevenue,
      statRows,
      soldCountRows,
      escrowRows,
      pendingPayoutRows
    ] = await Promise.all([
      query<any[]>(
        `SELECT DATE(b.bid_time) as date, COUNT(*) as bid_count
         FROM bids b
         INNER JOIN auctions a ON a.auction_id = b.auction_id
         WHERE a.seller_id = ? AND b.bid_time >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
         GROUP BY DATE(b.bid_time)
         ORDER BY date ASC`,
        [sellerId, cappedDays]
      ),
      query<any[]>(
        `SELECT DATE(o.created_at) as date, COALESCE(SUM(o.seller_amount), 0) as revenue
         FROM orders o
         INNER JOIN auctions a ON a.auction_id = o.auction_id
         WHERE a.seller_id = ? AND o.payment_status = 'paid'
           AND o.created_at >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
         GROUP BY DATE(o.created_at)
         ORDER BY date ASC`,
        [sellerId, cappedDays]
      ),
      query<any[]>(
        `SELECT 
          COALESCE(SUM(a.view_count), 0) as views_total,
          COALESCE(SUM(a.watch_count), 0) as watchlist_total,
          (SELECT COUNT(*) FROM bids b2 INNER JOIN auctions a2 ON a2.auction_id = b2.auction_id WHERE a2.seller_id = ?) as bids_total,
          (SELECT COALESCE(MAX(b3.bid_amount), 0) FROM bids b3 INNER JOIN auctions a3 ON a3.auction_id = b3.auction_id WHERE a3.seller_id = ?) as highest_bid,
          COUNT(a.auction_id) as total_auctions
         FROM auctions a WHERE a.seller_id = ?`,
        [sellerId, sellerId, sellerId]
      ),
      query<any[]>(
        `SELECT 
          COUNT(CASE WHEN auction_status = 'sold' THEN 1 END) as sold_count,
          COUNT(CASE WHEN auction_status = 'active' THEN 1 END) as active_count,
          COUNT(CASE WHEN auction_status IN ('ended', 'sold', 'unsold') THEN 1 END) as finished_count
         FROM auctions WHERE seller_id = ?`,
        [sellerId]
      ),
      query<any[]>(
        `SELECT 
          COALESCE(SUM(seller_amount), 0) as total_sales,
          (SELECT COALESCE(SUM(amount), 0) FROM escrow_holds WHERE seller_id = ? AND status = 'held') as escrow_held
         FROM orders WHERE seller_id = ? AND payment_status = 'paid'`,
        [sellerId, sellerId]
      ),
      query<any[]>(
        "SELECT COALESCE(SUM(amount), 0) as pending_payouts FROM payouts WHERE seller_id = ? AND status = 'pending'",
        [sellerId]
      ).catch(() => [{ pending_payouts: 0 }])
    ]);

    const stats = statRows[0] || {};
    const sold = soldCountRows[0] || {};
    const esc = escrowRows[0] || {};
    const pen = pendingPayoutRows[0] || {};

    const finished = sold.finished_count || 0;
    const conversionRate = finished > 0 ? Math.round(((sold.sold_count || 0) / finished) * 100) : 0;

    return {
      views: parseInt(stats.views_total || 0, 10),
      watchlist_count: parseInt(stats.watchlist_total || 0, 10),
      bids: parseInt(stats.bids_total || 0, 10),
      highest_bid: parseFloat(stats.highest_bid || 0),
      sold_auctions: parseInt(sold.sold_count || 0, 10),
      active_auctions: parseInt(sold.active_count || 0, 10),
      conversion_rate: conversionRate,
      revenue: parseFloat(esc.total_sales || 0),
      escrow_held: parseFloat(esc.escrow_held || 0),
      pending_payouts: parseFloat(pen.pending_payouts || 0),
      daily_bids: dailyBids,
      daily_revenue: dailyRevenue,
      days: cappedDays,
    };
  }
}
