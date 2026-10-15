import { query } from '../config/db.js';
import { PoolConnection } from 'mysql2/promise';
import { EmailService } from './email.service.js';

export type NotificationType =
  | 'outbid'
  | 'winning_bid'
  | 'auction_ending_soon'
  | 'auction_won'
  | 'auction_lost'
  | 'auction_created'
  | 'auction_ended'
  | 'payment_required'
  | 'payment_received'
  | 'order_created'
  | 'general';

export class NotificationService {
  /**
   * Insert notification using existing pool query or within transaction
   */
  static async send(
    params: {
      userId: number;
      type: NotificationType;
      title: string;
      message: string;
      link?: string;
    },
    conn?: PoolConnection
  ): Promise<void> {
    const { userId, type, title, message, link } = params;
    const sql = `
      INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at)
      VALUES (?, ?, ?, ?, ?, 0, NOW())
    `;
    const values = [userId, type, title, message, link || null];

    try {
      if (conn) {
        await conn.execute(sql, values);
      } else {
        await query(sql, values);
      }
    } catch (err: any) {
      console.warn(`[NotificationService] Failed to insert notification for user #${userId}:`, err.message);
    }
  }

  // 1. Outbid notification
  static async notifyOutbid(
    userId: number,
    auctionId: number,
    auctionTitle: string,
    newBidAmount: number,
    conn?: PoolConnection
  ) {
    // Send background email alert
    query<any[]>('SELECT email, full_name FROM users WHERE user_id = ? LIMIT 1', [userId])
      .then((rows) => {
        if (rows.length && rows[0].email) {
          EmailService.sendOutbid(rows[0].email, rows[0].full_name || 'Valued Bidder', auctionTitle, newBidAmount, auctionId).catch(() => { });
        }
      })
      .catch(() => { });

    return this.send(
      {
        userId,
        type: 'outbid',
        title: "You've been outbid",
        message: `A higher bid of Rs. ${newBidAmount.toLocaleString()} was placed on "${auctionTitle}".`,
        link: `auction-details.html?id=${auctionId}`,
      },
      conn
    );
  }

  // 2. Currently winning bid notification
  static async notifyWinningBid(
    userId: number,
    auctionId: number,
    auctionTitle: string,
    bidAmount: number,
    conn?: PoolConnection
  ) {
    return this.send(
      {
        userId,
        type: 'winning_bid',
        title: 'You are the highest bidder!',
        message: `Your bid of Rs. ${bidAmount.toLocaleString()} on "${auctionTitle}" is currently the highest.`,
        link: `auction-details.html?id=${auctionId}`,
      },
      conn
    );
  }

  // 3. Auction won notification
  static async notifyAuctionWon(
    userId: number,
    auctionId: number,
    auctionTitle: string,
    winningAmount: number,
    orderId?: number,
    conn?: PoolConnection
  ) {
    // Send background email notification (only if we actually have an order to link)
    if (orderId) {
      query<any[]>('SELECT email, full_name FROM users WHERE user_id = ? LIMIT 1', [userId])
        .then((rows) => {
          if (rows.length && rows[0].email) {
            EmailService.sendAuctionWon(rows[0].email, rows[0].full_name || 'Auction Winner', auctionTitle, winningAmount, orderId).catch(() => { });
          }
        })
        .catch(() => { });
    }

    return this.send(
      {
        userId,
        type: 'auction_won',
        title: 'Congratulations! You won the auction!',
        message: `You won "${auctionTitle}" for Rs. ${winningAmount.toLocaleString()}. Please complete your order payment.`,
        link: orderId ? `checkout.html?order_id=${orderId}` : `checkout.html?auction_id=${auctionId}`,
      },
      conn
    );
  }

  // 4. Auction lost notification
  static async notifyAuctionLost(
    userId: number,
    auctionId: number,
    auctionTitle: string,
    conn?: PoolConnection
  ) {
    return this.send(
      {
        userId,
        type: 'auction_lost',
        title: 'Auction Ended',
        message: `The auction for "${auctionTitle}" has ended. You did not win this time. Keep exploring other auctions!`,
        link: `auction-details.html?id=${auctionId}`,
      },
      conn
    );
  }

  // 5. Auction created notification for seller
  static async notifyAuctionCreated(
    sellerId: number,
    auctionId: number,
    auctionTitle: string,
    status: 'draft' | 'pending' | 'active',
    conn?: PoolConnection
  ) {
    const statusMsg =
      status === 'draft'
        ? 'saved as draft'
        : status === 'pending'
          ? 'submitted for administrative approval'
          : 'is now live';

    return this.send(
      {
        userId: sellerId,
        type: 'auction_created',
        title: 'Auction Created',
        message: `Your auction "${auctionTitle}" has been ${statusMsg}.`,
        link: `seller/manage-auctions.html`,
      },
      conn
    );
  }

  // 6. Auction ended notification for seller
  static async notifyAuctionEnded(
    sellerId: number,
    auctionId: number,
    auctionTitle: string,
    outcome: 'sold' | 'unsold',
    amount?: number,
    conn?: PoolConnection
  ) {
    const title = outcome === 'sold' ? 'Your auction was successfully sold!' : 'Auction ended without a sale';
    const message =
      outcome === 'sold'
        ? `Your item "${auctionTitle}" was sold for Rs. ${(amount || 0).toLocaleString()}! We have notified the buyer to proceed with payment.`
        : `Your auction "${auctionTitle}" has ended without meeting the required bids or reserve price. You can relist this item.`;

    return this.send(
      {
        userId: sellerId,
        type: 'auction_ended',
        title,
        message,
        link: outcome === 'sold' ? 'seller/orders.html' : 'seller/manage-auctions.html',
      },
      conn
    );
  }

  // 7. Payment required notification
  static async notifyPaymentRequired(
    buyerId: number,
    orderId: number,
    orderNumber: string,
    amount: number,
    conn?: PoolConnection
  ) {
    return this.send(
      {
        userId: buyerId,
        type: 'payment_required',
        title: 'Payment Required',
        message: `Order #${orderNumber} for Rs. ${amount.toLocaleString()} is waiting for payment. Please complete checkout to secure your item.`,
        link: `checkout.html?order_id=${orderId}`,
      },
      conn
    );
  }

  // 8. Payment received notification
  static async notifyPaymentReceived(
    sellerId: number,
    orderId: number,
    orderNumber: string,
    amount: number,
    conn?: PoolConnection
  ) {
    // Send background email to seller
    query<any[]>(
      `SELECT u.email, u.full_name, a.title as auction_title 
       FROM users u 
       LEFT JOIN orders o ON o.order_id = ?
       LEFT JOIN auctions a ON a.auction_id = o.auction_id
       WHERE u.user_id = ? LIMIT 1`,
      [orderId, sellerId]
    )
      .then((rows) => {
        if (rows.length && rows[0].email) {
          EmailService.sendPaymentReceived(
            rows[0].email,
            rows[0].full_name || 'Seller',
            rows[0].auction_title || 'Item',
            amount,
            orderNumber
          ).catch(() => { });
        }
      })
      .catch(() => { });

    return this.send(
      {
        userId: sellerId,
        type: 'payment_received',
        title: 'Payment Confirmed into Escrow',
        message: `Payment of Rs. ${amount.toLocaleString()} for Order #${orderNumber} has been received into secure escrow. Please ship the item!`,
        link: `seller/orders.html?order_id=${orderId}`,
      },
      conn
    );
  }

  // 9. Order created notification
  static async notifyOrderCreated(
    userId: number,
    orderId: number,
    orderNumber: string,
    isSeller: boolean,
    conn?: PoolConnection
  ) {
    return this.send(
      {
        userId,
        type: 'order_created',
        title: `Order #${orderNumber} Created`,
        message: isSeller
          ? `A new order #${orderNumber} has been created for your sold item.`
          : `Your order #${orderNumber} has been created. Complete payment to initiate delivery.`,
        link: isSeller ? `seller/orders.html?order_id=${orderId}` : `buyer/orders.html?order_id=${orderId}`,
      },
      conn
    );
  }
}
