import { query } from '../config/db.js';
import { NotificationService } from './notification.service.js';

export interface PrivateOffer {
  offer_id: number;
  auction_id: number;
  buyer_id: number;
  seller_id: number;
  offer_amount: number;
  message: string | null;
  status: 'pending' | 'accepted' | 'declined' | 'expired';
  expires_at: string | Date;
  responded_at: string | Date | null;
  created_at: string | Date;
  updated_at: string | Date;
}

export class LastChanceService {
  private static tableEnsured = false;

  static async ensureTable() {
    if (this.tableEnsured) return;
    try {
      await query(`
        CREATE TABLE IF NOT EXISTS \`private_offers\` (
          \`offer_id\` INT AUTO_INCREMENT PRIMARY KEY,
          \`auction_id\` INT NOT NULL,
          \`buyer_id\` INT NOT NULL,
          \`seller_id\` INT NOT NULL,
          \`offer_amount\` DECIMAL(15,2) NOT NULL,
          \`message\` TEXT NULL,
          \`status\` ENUM('pending','accepted','declined','expired') NOT NULL DEFAULT 'pending',
          \`expires_at\` DATETIME NOT NULL,
          \`responded_at\` DATETIME NULL,
          \`created_at\` DATETIME DEFAULT CURRENT_TIMESTAMP,
          \`updated_at\` DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
          INDEX \`idx_po_auction_id\` (\`auction_id\`),
          INDEX \`idx_po_buyer_id\` (\`buyer_id\`),
          INDEX \`idx_po_seller_id\` (\`seller_id\`),
          INDEX \`idx_po_status_expires\` (\`status\`, \`expires_at\`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
      `);
      this.tableEnsured = true;
    } catch (e: any) {
      console.warn('[LastChanceService] ensureTable warning:', e?.message);
    }
  }

  /**
   * Helper: Mask user name for anonymity (e.g. "A***d")
   */
  static maskName(name?: string | null): string {
    if (!name) return 'Bidder';
    const trimmed = name.trim();
    if (trimmed.length <= 2) return trimmed[0] + '*';
    return trimmed[0] + '***' + trimmed[trimmed.length - 1];
  }

  /**
   * Create a private offer for a lost auction within 2 hours of auction end.
   */
  static async createOffer(
    buyerId: number,
    auctionId: number,
    offerAmount: number,
    message?: string
  ) {
    if (!offerAmount || isNaN(offerAmount) || offerAmount <= 0) {
      throw new Error('Offer amount must be a positive number.');
    }

    await this.ensureTable().catch(() => {});

    // 1. Fetch auction details
    const auctionRows = await query<any[]>(
      `SELECT auction_id, seller_id, title, auction_status, end_time, current_bid
       FROM auctions WHERE auction_id = ? LIMIT 1`,
      [auctionId]
    );

    if (!auctionRows || !auctionRows.length) {
      throw new Error('Auction not found.');
    }

    const auction = auctionRows[0];

    // Cannot make an offer on your own auction
    if (auction.seller_id === buyerId) {
      throw new Error('You cannot make a private offer on your own auction.');
    }

    // 2. Validate auction must be ended/sold
    const isEndedStatus = ['ended', 'sold', 'unsold', 'completed'].includes(auction.auction_status);
    const now = new Date();
    const endTime = new Date(auction.end_time);

    if (!isEndedStatus && now < endTime) {
      throw new Error('Auction is still active. Last Chance Rescue is only available once an auction ends.');
    }

    // 3. Must be within 2 hours of auction end
    const twoHoursMs = 2 * 60 * 60 * 1000;
    const timeSinceEnd = now.getTime() - endTime.getTime();
    if (timeSinceEnd > twoHoursMs) {
      throw new Error('Last Chance Rescue window has closed (offers must be placed within 2 hours of auction end).');
    }

    // 4. Buyer must have placed at least 1 bid on this auction
    const bidRows = await query<any[]>(
      `SELECT COUNT(*) as count FROM bids WHERE auction_id = ? AND bidder_id = ?`,
      [auctionId, buyerId]
    );
    if (!bidRows || (bidRows[0]?.count || 0) < 1) {
      throw new Error('Only participants who placed at least one bid on this auction can make a Last Chance offer.');
    }

    // 5. Buyer must NOT have won the auction
    const winnerRows = await query<any[]>(
      `SELECT user_id FROM winners WHERE auction_id = ? LIMIT 1`,
      [auctionId]
    );
    if (winnerRows && winnerRows.length && winnerRows[0].user_id === buyerId) {
      throw new Error('The winning bidder cannot make a Last Chance offer.');
    }

    const legacyWinnerRows = await query<any[]>(
      `SELECT user_id FROM auction_winners WHERE auction_id = ? LIMIT 1`,
      [auctionId]
    );
    if (legacyWinnerRows && legacyWinnerRows.length && legacyWinnerRows[0].user_id === buyerId) {
      throw new Error('The winning bidder cannot make a Last Chance offer.');
    }

    // 6. Only 1 offer per buyer per auction
    const existingOffers = await query<any[]>(
      `SELECT offer_id FROM private_offers WHERE auction_id = ? AND buyer_id = ? LIMIT 1`,
      [auctionId, buyerId]
    );
    if (existingOffers && existingOffers.length) {
      throw new Error('You have already submitted a private offer for this auction.');
    }

    // 7. Insert private offer (expires in 2 hours)
    const expiresAt = new Date(Date.now() + twoHoursMs);
    const result: any = await query(
      `INSERT INTO private_offers (auction_id, buyer_id, seller_id, offer_amount, message, status, expires_at, created_at)
       VALUES (?, ?, ?, ?, ?, 'pending', ?, NOW())`,
      [auctionId, buyerId, auction.seller_id, offerAmount, message || null, expiresAt]
    );

    const offerId = result.insertId;

    // 8. Notify seller of the new private offer
    await NotificationService.send({
      userId: auction.seller_id,
      type: 'general',
      title: 'New Last Chance Offer Received',
      message: `You received a private offer of PKR ${Number(offerAmount).toLocaleString('en-PK')} on "${auction.title}".`,
      link: `/auction-details.html?id=${auctionId}`,
    }).catch(() => {});

    return {
      offer_id: offerId,
      auction_id: auctionId,
      buyer_id: buyerId,
      seller_id: auction.seller_id,
      offer_amount: offerAmount,
      message: message || null,
      status: 'pending',
      expires_at: expiresAt,
    };
  }

  /**
   * Seller accepts or declines a private offer
   */
  static async respondToOffer(
    sellerId: number,
    offerId: number,
    action: 'accept' | 'decline'
  ) {
    if (!['accept', 'decline'].includes(action)) {
      throw new Error("Invalid action. Must be 'accept' or 'decline'.");
    }

    // Auto-expire old offers first
    await this.expireOldOffers().catch(() => {});

    const offerRows = await query<any[]>(
      `SELECT po.*, a.title as auction_title
       FROM private_offers po
       JOIN auctions a ON a.auction_id = po.auction_id
       WHERE po.offer_id = ? LIMIT 1`,
      [offerId]
    );

    if (!offerRows || !offerRows.length) {
      throw new Error('Private offer not found.');
    }

    const offer = offerRows[0];

    if (offer.seller_id !== sellerId) {
      throw new Error('You are not authorized to respond to this offer.');
    }

    if (offer.status !== 'pending') {
      throw new Error(`Cannot respond to an offer that is already ${offer.status}.`);
    }

    const newStatus = action === 'accept' ? 'accepted' : 'declined';

    await query(
      `UPDATE private_offers SET status = ?, responded_at = NOW() WHERE offer_id = ?`,
      [newStatus, offerId]
    );

    // Notify buyer
    if (action === 'accept') {
      await NotificationService.send({
        userId: offer.buyer_id,
        type: 'general',
        title: 'Private Offer Accepted!',
        message: `Your private offer of PKR ${Number(offer.offer_amount).toLocaleString('en-PK')} for "${offer.auction_title}" was accepted by the seller!`,
        link: `/auction-details.html?id=${offer.auction_id}`,
      }).catch(() => {});
    } else {
      await NotificationService.send({
        userId: offer.buyer_id,
        type: 'general',
        title: 'Private Offer Declined',
        message: `Your private offer for "${offer.auction_title}" was declined by the seller.`,
        link: `/auction-details.html?id=${offer.auction_id}`,
      }).catch(() => {});
    }

    return {
      offer_id: offerId,
      status: newStatus,
      responded_at: new Date(),
    };
  }

  /**
   * List all offers sent by a specific buyer
   */
  static async getBuyerOffers(buyerId: number) {
    if (!buyerId) return [];
    await this.ensureTable().catch(() => {});
    await this.expireOldOffers().catch(() => {});

    try {
      const rows = await query<any[]>(
        `SELECT po.*, a.title as auction_title, a.current_bid, a.image as auction_image,
                u.full_name as seller_name
         FROM private_offers po
         LEFT JOIN auctions a ON a.auction_id = po.auction_id
         LEFT JOIN users u ON u.user_id = po.seller_id
         WHERE po.buyer_id = ?
         ORDER BY po.created_at DESC`,
        [buyerId]
      );

      return rows;
    } catch (err: any) {
      if (err?.code === 'ER_NO_SUCH_TABLE' || err?.message?.includes("doesn't exist")) {
        await this.ensureTable().catch(() => {});
        return [];
      }
      console.error('[LastChanceService.getBuyerOffers] error:', err?.message);
      return [];
    }
  }

  /**
   * List all pending offers received by a seller with buyer info (masked name)
   */
  static async getSellerOffers(sellerId: number) {
    if (!sellerId) return [];
    await this.ensureTable().catch(() => {});
    await this.expireOldOffers().catch(() => {});

    try {
      const rows = await query<any[]>(
        `SELECT po.*, a.title as auction_title, a.image as auction_image, a.current_bid,
                u.full_name as buyer_full_name
         FROM private_offers po
         LEFT JOIN auctions a ON a.auction_id = po.auction_id
         LEFT JOIN users u ON u.user_id = po.buyer_id
         WHERE po.seller_id = ? AND po.status = 'pending' AND po.expires_at > NOW()
         ORDER BY po.created_at DESC`,
        [sellerId]
      );

      return rows.map((r) => ({
        ...r,
        buyer_name: this.maskName(r.buyer_full_name),
        buyer_full_name: undefined, // mask name for privacy
      }));
    } catch (err: any) {
      if (err?.code === 'ER_NO_SUCH_TABLE' || err?.message?.includes("doesn't exist")) {
        await this.ensureTable().catch(() => {});
        return [];
      }
      console.error('[LastChanceService.getSellerOffers] error:', err?.message);
      return [];
    }
  }

  /**
   * Expire offers that exceeded expires_at
   */
  static async expireOldOffers() {
    try {
      await query(
        `UPDATE private_offers SET status = 'expired' WHERE status = 'pending' AND expires_at < NOW()`
      );
    } catch {
      // Table may not exist yet or no rows to update
    }
  }
}
