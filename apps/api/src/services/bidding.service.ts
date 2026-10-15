import { withTransaction, query } from '../config/db.js';
import { PoolConnection } from 'mysql2/promise';
import crypto from 'crypto';
import { RealtimeService } from './realtime.service.js';
import { NotificationService } from './notification.service.js';

export class BiddingService {
  // Place manual bid with concurrency locking and anti-sniping
  static async placeBid(
    paramsOrAuctionId:
      | number
      | {
        auctionId: number;
        userId: number;
        bidAmount: number;
        ipAddress?: string;
        deviceHash?: string;
      },
    argUserId?: number,
    argBidAmount?: number,
    argIpAddress?: string,
    argDeviceHash?: string
  ) {
    const isObj = typeof paramsOrAuctionId === 'object' && paramsOrAuctionId !== null;
    const auctionId = isObj ? paramsOrAuctionId.auctionId : (paramsOrAuctionId as number);
    const userId = isObj ? paramsOrAuctionId.userId : (argUserId as number);
    const bidAmount = isObj ? paramsOrAuctionId.bidAmount : (argBidAmount as number);
    const ipAddress = isObj ? paramsOrAuctionId.ipAddress : argIpAddress;
    const deviceHash = isObj ? paramsOrAuctionId.deviceHash : argDeviceHash;

    let extendedInfo: { new_end_time: string; extended_count: number } | null = null;

    const result = await withTransaction(async (conn) => {
      // 1. Verify User status and KYC
      const [userRows] = await conn.execute<any[]>(
        'SELECT status, kyc_status FROM users WHERE user_id = ? LIMIT 1',
        [userId]
      );
      if (!userRows.length || userRows[0].status !== 'active') {
        throw new Error('User account is inactive or not found.');
      }
      if (userRows[0].kyc_status !== 'approved') {
        throw new Error('Please complete KYC verification before placing bids.');
      }

      // 2. Lock Auction Row FOR UPDATE (strictly prevents race conditions and concurrent bid corruption)
      const [auctionRows] = await conn.execute<any[]>(
        `SELECT auction_id, seller_id, title, starting_price, current_bid,
                bid_increment, auction_status, start_time, end_time,
                anti_sniping_enabled, extension_minutes, extended_count
         FROM auctions
         WHERE auction_id = ? FOR UPDATE`,
        [auctionId]
      );

      if (!auctionRows.length) {
        throw new Error('Auction not found.');
      }

      const auction = auctionRows[0];

      // 3. Business rule checks
      if (auction.seller_id === userId) {
        throw new Error('You cannot place a bid on your own auction.');
      }

      if (auction.auction_status !== 'active') {
        throw new Error(`This auction is not active (status: ${auction.auction_status}).`);
      }

      const now = Date.now();
      const startTime = new Date(auction.start_time).getTime();
      let endTime = new Date(auction.end_time).getTime();

      if (startTime > now) {
        throw new Error('This auction has not started yet.');
      }

      if (endTime <= now) {
        throw new Error('This auction has already ended.');
      }

      const currentBid = parseFloat(auction.current_bid || 0);
      const startingPrice = parseFloat(auction.starting_price);
      const increment = parseFloat(auction.bid_increment || 500);

      const basePrice = currentBid > 0 ? currentBid : startingPrice;
      const minimumRequiredBid = currentBid > 0 ? basePrice + increment : startingPrice;

      if (bidAmount < minimumRequiredBid) {
        throw new Error(`Your bid must be at least Rs. ${minimumRequiredBid.toLocaleString()}`);
      }

      // 4. Identify previous leader
      const [prevBidRows] = await conn.execute<any[]>(
        `SELECT bidder_id FROM bids
         WHERE auction_id = ?
         ORDER BY bid_amount DESC, bid_time ASC LIMIT 1`,
        [auctionId]
      );
      const previousLeaderId = prevBidRows.length ? prevBidRows[0].bidder_id : 0;

      if (previousLeaderId === userId) {
        throw new Error('You already hold the highest bid on this auction.');
      }

      // 5. Anti-Sniping Protection
      // If a bid is placed in the final extension_minutes, extend the auction ending time
      const extensionMinutes = auction.extension_minutes || 2;
      const antiSnipingWindowMs = extensionMinutes * 60 * 1000;
      const timeRemainingMs = endTime - now;
      const maxExtensions = 15; // Cap at 15 extensions (30 mins maximum extension)

      if (
        Boolean(auction.anti_sniping_enabled) &&
        timeRemainingMs > 0 &&
        timeRemainingMs <= antiSnipingWindowMs &&
        (auction.extended_count || 0) < maxExtensions
      ) {
        const newEndTimeDate = new Date(endTime + antiSnipingWindowMs);
        const newCount = (auction.extended_count || 0) + 1;

        await conn.execute(
          `UPDATE auctions 
           SET end_time = ?, extended_count = ?
           WHERE auction_id = ?`,
          [newEndTimeDate, newCount, auctionId]
        );

        endTime = newEndTimeDate.getTime();
        extendedInfo = {
          new_end_time: newEndTimeDate.toISOString(),
          extended_count: newCount,
        };
      }

      // 6. Insert Bid
      const safeDeviceHash = deviceHash
        ? (deviceHash.length > 64 ? crypto.createHash('sha256').update(deviceHash).digest('hex') : deviceHash)
        : null;
      const [insertResult] = await conn.execute<any>(
        `INSERT INTO bids (auction_id, bidder_id, bid_amount, bid_time, is_auto_bid, ip_address, device_hash)
         VALUES (?, ?, ?, NOW(), 0, ?, ?)`,
        [auctionId, userId, bidAmount, ipAddress || null, safeDeviceHash]
      );
      const bidId = insertResult.insertId;

      // 7. Update Auction current bid
      await conn.execute(
        'UPDATE auctions SET current_bid = ? WHERE auction_id = ?',
        [bidAmount, auctionId]
      );

      // 8. Notifications
      if (previousLeaderId && previousLeaderId !== userId) {
        await NotificationService.notifyOutbid(
          previousLeaderId,
          auctionId,
          auction.title,
          bidAmount,
          conn
        );
      }

      await NotificationService.notifyWinningBid(
        userId,
        auctionId,
        auction.title,
        bidAmount,
        conn
      );

      // 9. Resolve competing auto-bids atomically inside the transaction
      await this.resolveAutoBidsInternal(conn, auctionId, userId, auction.title);

      return {
        success: true,
        bid_id: bidId,
        auction_id: auctionId,
        bid_amount: bidAmount,
        extended: extendedInfo,
        data: {
          bid_id: bidId,
          auction_id: auctionId,
          bid_amount: bidAmount,
          current_bid: bidAmount,
        },
        message: `Your bid of Rs. ${bidAmount.toLocaleString()} has been placed successfully!`,
      };
    });

    // 10. Broadcast realtime updates outside transaction
    try {
      // Fetch masked bidder name to avoid leaking userId in SSE
      const nameRows = await query<any[]>('SELECT full_name FROM users WHERE user_id = ? LIMIT 1', [userId]).catch(() => []);
      const rawName = nameRows[0]?.full_name || 'Bidder';
      const maskedName = rawName.length > 2 ? `${rawName[0]}${'*'.repeat(Math.max(1, rawName.length - 2))}${rawName[rawName.length - 1]}` : 'Bidder';

      // Fetch actual bid count (was previously hardcoded to 1)
      const countRows = await query<any[]>(
        'SELECT COUNT(*) as cnt FROM bids WHERE auction_id = ?',
        [auctionId]
      ).catch(() => [{ cnt: 1 }]);
      const actualBidCount = parseInt(countRows[0]?.cnt || 1, 10);

      RealtimeService.broadcastBid(auctionId, {
        bid_amount: bidAmount,
        bidder_name: maskedName,
        bid_time: new Date().toISOString(),
        bid_count: actualBidCount,
      });

      if (extendedInfo) {
        RealtimeService.broadcastTimeExtension(auctionId, extendedInfo);
      }

      // Async fraud scoring
      const { SecurityService } = await import('./security.service.js');
      SecurityService.analyzeBidFraud(auctionId, result.bid_id, userId, bidAmount, ipAddress);
    } catch { }

    return result;
  }

  // Set or update Auto-Bid ceiling
  static async setAutoBid(
    paramsOrAuctionId: number | { auctionId: number; userId: number; maxAmount: number },
    argUserId?: number,
    argMaxAmount?: number
  ) {
    const isObj = typeof paramsOrAuctionId === 'object' && paramsOrAuctionId !== null;
    const auctionId = isObj ? paramsOrAuctionId.auctionId : (paramsOrAuctionId as number);
    const userId = isObj ? paramsOrAuctionId.userId : (argUserId as number);
    const maxAmount = isObj ? paramsOrAuctionId.maxAmount : (argMaxAmount as number);

    return await withTransaction(async (conn) => {
      // 1. Verify KYC and active user
      const [userRows] = await conn.execute<any[]>(
        'SELECT status, kyc_status FROM users WHERE user_id = ? LIMIT 1',
        [userId]
      );
      if (!userRows.length || userRows[0].status !== 'active') {
        throw new Error('User account is inactive or not found.');
      }
      if (userRows[0].kyc_status !== 'approved') {
        throw new Error('Please complete KYC verification before setting auto-bids.');
      }

      // 2. Lock auction row
      const [auctionRows] = await conn.execute<any[]>(
        `SELECT seller_id, current_bid, starting_price, bid_increment,
                auction_status, end_time, title
         FROM auctions
         WHERE auction_id = ? FOR UPDATE`,
        [auctionId]
      );
      if (!auctionRows.length) throw new Error('Auction not found.');

      const auction = auctionRows[0];
      if (auction.seller_id === userId) {
        throw new Error('You cannot set auto-bids on your own auction.');
      }
      if (auction.auction_status !== 'active' || new Date(auction.end_time).getTime() <= Date.now()) {
        throw new Error('This auction is not currently active.');
      }

      const currentPrice = parseFloat(auction.current_bid) > 0 ? parseFloat(auction.current_bid) : parseFloat(auction.starting_price);
      const increment = parseFloat(auction.bid_increment || 500);
      const minRequired = currentPrice > 0 ? currentPrice + increment : currentPrice;

      if (maxAmount < minRequired) {
        throw new Error(`Your auto-bid maximum must be at least Rs. ${minRequired.toLocaleString()}`);
      }

      // 3. Upsert into auto_bids
      await conn.execute(
        `INSERT INTO auto_bids (auction_id, user_id, max_amount, status, is_active, created_at)
         VALUES (?, ?, ?, 'active', 1, NOW())
         ON DUPLICATE KEY UPDATE max_amount = VALUES(max_amount), status = 'active', is_active = 1`,
        [auctionId, userId, maxAmount]
      );

      // 4. Resolve immediately
      await this.resolveAutoBidsInternal(conn, auctionId, userId, auction.title);

      return {
        success: true,
        auction_id: auctionId,
        max_amount: maxAmount,
        data: {
          auction_id: auctionId,
          max_amount: maxAmount,
        },
        message: `Auto-bid ceiling of Rs. ${maxAmount.toLocaleString()} configured. The system will bid automatically for you.`,
      };
    });
  }

  // Cancel Auto-Bid
  static async cancelAutoBid(auctionId: number, userId: number) {
    const [result] = await query<any>(
      "UPDATE auto_bids SET status = 'cancelled', is_active = 0 WHERE auction_id = ? AND user_id = ?",
      [auctionId, userId]
    );

    return {
      auction_id: auctionId,
      cancelled: Boolean(result?.affectedRows > 0),
      message: 'Automatic bidding cancelled.',
    };
  }

  // Get User's Own Auto-Bid
  static async getMyAutoBid(auctionId: number, userId: number) {
    const rows = await query<any[]>(
      "SELECT auto_bid_id, max_amount, status, created_at FROM auto_bids WHERE auction_id = ? AND user_id = ? AND status = 'active' LIMIT 1",
      [auctionId, userId]
    );

    return rows.length ? rows[0] : null;
  }

  // Private helper to resolve auto-bids in bounded loop with row locking
  private static async resolveAutoBidsInternal(
    conn: PoolConnection,
    auctionId: number,
    initiatorUserId: number,
    auctionTitle: string
  ) {
    let safetyCounter = 0;

    while (safetyCounter < 10) {
      safetyCounter++;

      // Fetch fresh auction state within lock
      const [auctionRows] = await conn.execute<any[]>(
        `SELECT current_bid, starting_price, bid_increment, auction_status, end_time,
                anti_sniping_enabled, extension_minutes, extended_count
         FROM auctions
         WHERE auction_id = ? FOR UPDATE`,
        [auctionId]
      );
      if (!auctionRows.length || auctionRows[0].auction_status !== 'active') return;

      const auction = auctionRows[0];
      const now = Date.now();
      let endTime = new Date(auction.end_time).getTime();
      if (endTime <= now) return;

      const currentPrice = parseFloat(auction.current_bid) > 0 ? parseFloat(auction.current_bid) : parseFloat(auction.starting_price);
      const increment = parseFloat(auction.bid_increment || 500);

      // Find current top bidder
      const [topBids] = await conn.execute<any[]>(
        'SELECT bidder_id FROM bids WHERE auction_id = ? ORDER BY bid_amount DESC, bid_time ASC LIMIT 1',
        [auctionId]
      );
      const currentLeaderId = topBids.length ? topBids[0].bidder_id : 0;

      // Find highest candidate auto-bid ceiling from another user
      const [candidates] = await conn.execute<any[]>(
        `SELECT user_id, max_amount FROM auto_bids
         WHERE auction_id = ? AND status = 'active' AND is_active = 1 AND user_id != ? AND max_amount > ?
         ORDER BY max_amount DESC, created_at ASC LIMIT 1`,
        [auctionId, currentLeaderId, currentPrice]
      );

      if (!candidates.length) return; // Equilibrium reached

      const challenger = candidates[0];
      const challengerId = challenger.user_id;
      const challengerMax = parseFloat(challenger.max_amount);

      // Proxy bidding: challenger MUST be able to bid at least (current + increment).
      // If their ceiling is below that, they cannot outbid — deactivate and try next candidate.
      const minimumRequired = currentPrice + increment;
      if (challengerMax < minimumRequired) {
        await conn.execute(
          "UPDATE auto_bids SET status = 'expired', is_active = 0 WHERE auction_id = ? AND user_id = ?",
          [auctionId, challengerId]
        );
        continue; // try next-highest challenger
      }

      const nextBidAmount = minimumRequired;
      if (nextBidAmount <= currentPrice) return;

      // Anti-sniping check for auto-bid
      const extensionMinutes = auction.extension_minutes || 2;
      const antiSnipingWindowMs = extensionMinutes * 60 * 1000;
      const timeRemainingMs = endTime - now;
      if (
        Boolean(auction.anti_sniping_enabled) &&
        timeRemainingMs > 0 &&
        timeRemainingMs <= antiSnipingWindowMs &&
        (auction.extended_count || 0) < 15
      ) {
        const newEndTimeDate = new Date(endTime + antiSnipingWindowMs);
        const newCount = (auction.extended_count || 0) + 1;
        await conn.execute(
          'UPDATE auctions SET end_time = ?, extended_count = ? WHERE auction_id = ?',
          [newEndTimeDate, newCount, auctionId]
        );
        endTime = newEndTimeDate.getTime();
        RealtimeService.broadcastTimeExtension(auctionId, {
          new_end_time: newEndTimeDate.toISOString(),
          extended_count: newCount,
        });
      }

      // Place automatic bid
      await conn.execute(
        `INSERT INTO bids (auction_id, bidder_id, bid_amount, is_auto_bid, bid_time)
         VALUES (?, ?, ?, 1, NOW())`,
        [auctionId, challengerId, nextBidAmount]
      );

      // Update current bid
      await conn.execute(
        'UPDATE auctions SET current_bid = ? WHERE auction_id = ?',
        [nextBidAmount, auctionId]
      );

      // Notify previous leader
      if (currentLeaderId && currentLeaderId !== challengerId) {
        await NotificationService.notifyOutbid(
          currentLeaderId,
          auctionId,
          auctionTitle,
          nextBidAmount,
          conn
        );
      }
    }
  }

  // Instant Buy-Now Purchase
  static async buyNow(auctionId: number, userId: number) {
    return withTransaction(async (conn) => {
      // 1. Check user KYC and active status
      const [userRows] = await conn.execute<any[]>(
        'SELECT status, kyc_status FROM users WHERE user_id = ? LIMIT 1',
        [userId]
      );
      if (!userRows.length || userRows[0].status !== 'active') {
        throw new Error('User account is inactive or not found.');
      }
      if (userRows[0].kyc_status !== 'approved') {
        throw new Error('Please complete KYC verification before purchasing items.');
      }

      // 2. Lock auction row
      const [auctionRows] = await conn.execute<any[]>(
        `SELECT auction_id, seller_id, title, buy_now_price, auction_status
         FROM auctions
         WHERE auction_id = ? FOR UPDATE`,
        [auctionId]
      );

      if (!auctionRows.length) throw new Error('Auction not found.');
      const auction = auctionRows[0];

      if (auction.seller_id === userId) {
        throw new Error('You cannot buy your own auction.');
      }

      if (auction.auction_status !== 'active') {
        throw new Error('This auction is no longer active.');
      }

      if (!auction.buy_now_price || parseFloat(auction.buy_now_price) <= 0) {
        throw new Error('Instant Buy-Now is not enabled on this auction.');
      }

      const buyNowPrice = parseFloat(auction.buy_now_price);

      // 3. Mark auction sold
      await conn.execute(
        "UPDATE auctions SET auction_status = 'sold', current_bid = ?, sold_at = NOW() WHERE auction_id = ?",
        [buyNowPrice, auctionId]
      );

      // 4. Create winner entry
      const [winnerRes] = await conn.execute<any>(
        `INSERT INTO winners (auction_id, user_id, winning_bid, created_at, status)
         VALUES (?, ?, ?, NOW(), 'pending_payment')
         ON DUPLICATE KEY UPDATE winning_bid = VALUES(winning_bid), user_id = VALUES(user_id)`,
        [auctionId, userId, buyNowPrice]
      );
      let winnerId = winnerRes.insertId;
      if (!winnerId) {
        const [wRows] = await conn.execute<any[]>(
          'SELECT winner_id FROM winners WHERE auction_id = ? LIMIT 1',
          [auctionId]
        );
        winnerId = wRows[0]?.winner_id || 0;
      }

      // Also update auction_winners for legacy compatibility
      await conn.execute(
        `INSERT INTO auction_winners (auction_id, user_id, winning_bid, won_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE winning_bid = VALUES(winning_bid)`,
        [auctionId, userId, buyNowPrice]
      ).catch(() => { });

      // 5. Create order
      // Use crypto random suffix to guarantee unique order numbers (prevents collision between Buy-Now and cron)
      const orderSuffix = crypto.randomBytes(3).toString('hex').toUpperCase();
      const orderNumber = `NB-${new Date().getFullYear()}-${String(auctionId).padStart(5, '0')}-${orderSuffix}`;

      // C-2: Standardized financial rounding — all amounts to 2 decimal places
      const roundTo2 = (v: number) => Math.round(v * 100) / 100;
      const platformFee = roundTo2(buyNowPrice * 0.05);
      const sellerAmount = roundTo2(buyNowPrice - platformFee);
      const totalAmount = roundTo2(buyNowPrice);

      // Integrity assertion: fees must sum to total
      if (roundTo2(platformFee + sellerAmount) !== totalAmount) {
        throw new Error(`Fee calculation error: ${platformFee} + ${sellerAmount} !== ${totalAmount}`);
      }

      const [orderRes] = await conn.execute<any>(
        `INSERT INTO orders 
          (order_number, auction_id, winner_id, buyer_id, seller_id,
           winning_bid, platform_fee, seller_amount, total_amount,
           order_status, payment_status, shipping_status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_payment', 'unpaid', 'not_started', NOW())
         ON DUPLICATE KEY UPDATE 
           winning_bid = VALUES(winning_bid),
           platform_fee = VALUES(platform_fee),
           seller_amount = VALUES(seller_amount),
           total_amount = VALUES(total_amount)`,
        [orderNumber, auctionId, winnerId, userId, auction.seller_id, buyNowPrice, platformFee, sellerAmount, totalAmount]
      );
      const orderId = orderRes.insertId;

      // 6. Expire all remaining auto_bids
      await conn.execute(
        "UPDATE auto_bids SET status = 'expired', is_active = 0 WHERE auction_id = ?",
        [auctionId]
      );

      // 7. Send notifications
      await NotificationService.notifyAuctionWon(userId, auctionId, auction.title, buyNowPrice, orderId, conn);
      await NotificationService.notifyAuctionEnded(auction.seller_id, auctionId, auction.title, 'sold', buyNowPrice, conn);

      // 8. Broadcast realtime status
      RealtimeService.broadcastStatus(auctionId, 'sold');

      return {
        winner_id: winnerId,
        order_id: orderId,
        amount: buyNowPrice,
        message: 'Auction purchased instantly via Buy-Now',
      };
    });
  }

  // Evaluate and finalize an ended auction
  static async evaluateEndedAuction(auctionId: number) {
    return withTransaction(async (conn) => {
      const [auctionRows] = await conn.execute<any[]>(
        `SELECT auction_id, seller_id, title, starting_price, current_bid,
                reserve_price, auction_status, end_time
         FROM auctions
         WHERE auction_id = ? FOR UPDATE`,
        [auctionId]
      );
      if (!auctionRows.length) throw new Error('Auction not found');
      const auction = auctionRows[0];

      const [highestBids] = await conn.execute<any[]>(
        `SELECT b.bid_id, b.bidder_id, b.bid_amount, u.full_name as bidder_name
         FROM bids b
         LEFT JOIN users u ON u.user_id = b.bidder_id
         WHERE b.auction_id = ? 
         ORDER BY b.bid_amount DESC, b.bid_time ASC LIMIT 1`,
        [auctionId]
      );

      if (!highestBids.length) {
        await conn.execute("UPDATE auctions SET auction_status = 'unsold' WHERE auction_id = ?", [auctionId]);
        await conn.execute("UPDATE auto_bids SET status = 'expired', is_active = 0 WHERE auction_id = ?", [auctionId]);
        RealtimeService.broadcastStatus(auctionId, 'unsold');
        return { status: 'unsold', winner_id: null, amount: 0 };
      }

      const topBid = highestBids[0];
      const winnerId = topBid.bidder_id;
      const winningBid = parseFloat(topBid.bid_amount);
      const reservePrice = auction.reserve_price ? parseFloat(auction.reserve_price) : 0;

      if (reservePrice > 0 && winningBid < reservePrice) {
        await conn.execute("UPDATE auctions SET auction_status = 'unsold' WHERE auction_id = ?", [auctionId]);
        await conn.execute("UPDATE auto_bids SET status = 'expired', is_active = 0 WHERE auction_id = ?", [auctionId]);
        RealtimeService.broadcastStatus(auctionId, 'unsold');
        return { status: 'reserve_not_met', winner_id: winnerId, amount: winningBid };
      }

      // Mark sold
      await conn.execute(
        "UPDATE auctions SET auction_status = 'sold', current_bid = ?, sold_at = NOW() WHERE auction_id = ?",
        [winningBid, auctionId]
      );
      await conn.execute("UPDATE auto_bids SET status = 'expired', is_active = 0 WHERE auction_id = ?", [auctionId]);

      // Winners record
      const [winnerResult] = await conn.execute<any>(
        `INSERT INTO winners (auction_id, user_id, winning_bid, status, created_at)
         VALUES (?, ?, ?, 'pending_payment', NOW())
         ON DUPLICATE KEY UPDATE winning_bid = VALUES(winning_bid), user_id = VALUES(user_id)`,
        [auctionId, winnerId, winningBid]
      );
      let winnerPk = winnerResult.insertId;
      if (!winnerPk) {
        const [wRows] = await conn.execute<any[]>('SELECT winner_id FROM winners WHERE auction_id = ? LIMIT 1', [auctionId]);
        winnerPk = wRows[0]?.winner_id || 0;
      }

      await conn.execute(
        `INSERT INTO auction_winners (auction_id, user_id, winning_bid, won_at)
         VALUES (?, ?, ?, NOW())
         ON DUPLICATE KEY UPDATE winning_bid = VALUES(winning_bid)`,
        [auctionId, winnerId, winningBid]
      ).catch(() => { });

      // Create order
      // Use crypto random suffix to guarantee unique order numbers (prevents collision between Buy-Now and cron)
      const evalSuffix = crypto.randomBytes(3).toString('hex').toUpperCase();
      const orderNumber = `NB-${new Date().getFullYear()}-${String(auctionId).padStart(5, '0')}-${evalSuffix}`;

      // C-2: Standardized financial rounding — all amounts to 2 decimal places
      const roundTo2Eval = (v: number) => Math.round(v * 100) / 100;
      const platformFee = roundTo2Eval(winningBid * 0.05);
      const sellerAmount = roundTo2Eval(winningBid - platformFee);
      const totalAmount = roundTo2Eval(winningBid);

      // Integrity assertion
      if (roundTo2Eval(platformFee + sellerAmount) !== totalAmount) {
        throw new Error(`Fee calculation error: ${platformFee} + ${sellerAmount} !== ${totalAmount}`);
      }

      const [orderResult] = await conn.execute<any>(
        `INSERT INTO orders 
          (order_number, auction_id, winner_id, buyer_id, seller_id,
           winning_bid, platform_fee, seller_amount, total_amount,
           order_status, payment_status, shipping_status, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'pending_payment', 'unpaid', 'not_started', NOW())
         ON DUPLICATE KEY UPDATE 
           winning_bid = VALUES(winning_bid),
           platform_fee = VALUES(platform_fee),
           seller_amount = VALUES(seller_amount),
           total_amount = VALUES(total_amount)`,
        [orderNumber, auctionId, winnerPk, winnerId, auction.seller_id, winningBid, platformFee, sellerAmount, totalAmount]
      );
      const orderId = orderResult.insertId;

      await NotificationService.notifyAuctionWon(winnerId, auctionId, auction.title, winningBid, orderId, conn).catch(() => { });
      await NotificationService.notifyAuctionEnded(auction.seller_id, auctionId, auction.title, 'sold', winningBid, conn).catch(() => { });
      RealtimeService.broadcastStatus(auctionId, 'sold');

      return {
        status: 'sold',
        winner_id: winnerId,
        order_id: orderId,
        amount: winningBid,
      };
    });
  }
}

