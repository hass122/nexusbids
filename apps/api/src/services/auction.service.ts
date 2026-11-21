import { query, withTransaction } from '../config/db.js';
import { Auction, AuctionStatus, DEFAULT_PAGE_LIMIT, MAX_PAGE_LIMIT } from '@nexusbids/shared';
import { NotificationService } from './notification.service.js';
import { RealtimeService } from './realtime.service.js';
import { CronService } from './cron.service.js';

/**
 * Safely parse auction datetime input in Pakistan Standard Time (PKT, UTC+5).
 * If the input already has a timezone indicator (Z or +HH:MM / -HH:MM),
 * it is parsed preserving that offset.
 * If the input is a naive datetime string (e.g. from HTML <input type="datetime-local"> like "2026-11-21T15:00"),
 * it is explicitly bound to Asia/Karachi (+05:00) so UTC servers (such as Vercel)
 * do not misinterpret local Pakistan seller time as UTC and add 5 extra hours.
 */
export function parseAuctionDateTime(input: string | Date | undefined | null): Date {
  if (!input) return new Date(NaN);
  if (input instanceof Date) return input;
  let s = String(input).trim();
  if (!s) return new Date(NaN);

  if (!/([zZ]|[+-]\d{2}(:?\d{2})?)$/.test(s)) {
    s = s.replace(' ', 'T');
    if (s.length === 16) {
      s += ':00';
    }
    s += '+05:00';
  }
  return new Date(s);
}

export class AuctionService {
  // Query public auctions with search, category, status, and pagination
  static async getAuctions(filters: {
    search?: string;
    category_id?: number;
    status?: AuctionStatus;
    page?: number;
    limit?: number;
    sort?: 'ending_soon' | 'newest' | 'price_low' | 'price_high' | 'most_bids';
  }) {
    // Process any ended auctions in the background if due
    CronService.checkAndProcessDueAuctions().catch(() => {});

    const page = Math.max(1, filters.page || 1);
    const limit = Math.min(MAX_PAGE_LIMIT, Math.max(1, filters.limit || DEFAULT_PAGE_LIMIT));
    const offset = (page - 1) * limit;

    const conditions: string[] = [];
    const params: any[] = [];

    // Default to active auctions if not specified
    if (filters.status) {
      conditions.push('a.auction_status = ?');
      params.push(filters.status);
      // For active auctions, also enforce end_time > NOW() so that auctions
      // whose end_time has passed but haven't been processed by the cron yet
      // are not returned as "live" to buyers.
      if (filters.status === 'active') {
        conditions.push('a.end_time > NOW()');
      }
    } else {
      conditions.push("a.auction_status = 'active' AND a.end_time > NOW()");
    }

    if (filters.category_id) {
      conditions.push('a.category_id = ?');
      params.push(filters.category_id);
    }

    if (filters.search) {
      conditions.push('(a.title LIKE ? OR a.description LIKE ?)');
      params.push(`%${filters.search}%`, `%${filters.search}%`);
    }

    const whereClause = conditions.length > 0 ? `WHERE ${conditions.join(' AND ')}` : '';

    let orderBy = 'a.end_time ASC';
    if (filters.sort === 'newest') orderBy = 'a.created_at DESC';
    if (filters.sort === 'price_low') orderBy = 'a.current_bid ASC';
    if (filters.sort === 'price_high') orderBy = 'a.current_bid DESC';
    if (filters.sort === 'most_bids') orderBy = 'bid_count DESC';

    // Count query
    const countSql = `SELECT COUNT(*) as total FROM auctions a ${whereClause}`;
    const countResult = await query<any[]>(countSql, params);
    const total = countResult[0]?.total || 0;

    // Data query
    const dataSql = `
      SELECT 
        a.auction_id, a.seller_id, u.full_name as seller_name,
        a.category_id, c.category_name as category_name,
        a.title, a.description, a.starting_price, a.current_bid,
        a.reserve_price, a.buy_now_price,
        a.bid_increment, a.start_time, a.end_time, a.auction_status,
        a.image, a.view_count, a.watch_count,
        (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.auction_id) as bid_count
      FROM auctions a
      LEFT JOIN users u ON u.user_id = a.seller_id
      LEFT JOIN categories c ON c.category_id = a.category_id
      ${whereClause}
      ORDER BY ${orderBy}
      LIMIT ? OFFSET ?
    `;

    const rows = await query<any[]>(dataSql, [...params, limit, offset]);

    return {
      auctions: rows as Auction[],
      meta: {
        page,
        limit,
        total,
        totalPages: Math.ceil(total / limit),
      },
    };
  }

  // Get single auction by ID with detailed info and bid history
  static async getAuctionById(auctionId: number, currentUserId?: number) {
    CronService.checkAndProcessDueAuctions().catch(() => {});

    const auctions = await query<any[]>(
      `SELECT 
        a.*, 
        u.full_name as seller_name, u.created_at as seller_joined_at, u.seller_kyc_status,
        (SELECT ROUND(AVG(rating), 1) FROM reviews r WHERE r.seller_id = a.seller_id AND (r.is_reported = 0 OR r.is_reported IS NULL)) as seller_rating,
        (SELECT COUNT(*) FROM reviews r WHERE r.seller_id = a.seller_id AND (r.is_reported = 0 OR r.is_reported IS NULL)) as seller_review_count,
        c.category_name as category_name,
        (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.auction_id) as bid_count,
        (SELECT bidder_id FROM bids b WHERE b.auction_id = a.auction_id ORDER BY b.bid_amount DESC, b.bid_time ASC LIMIT 1) as highest_bidder_id
      FROM auctions a
      LEFT JOIN users u ON u.user_id = a.seller_id
      LEFT JOIN categories c ON c.category_id = a.category_id
      WHERE a.auction_id = ?
      LIMIT 1`,
      [auctionId]
    );

    if (auctions.length === 0) {
      throw new Error('Auction not found');
    }

    const auction = auctions[0];

    // Fetch bid history (does NOT expose auto-bid ceilings of competitors)
    const bids = await query<any[]>(
      `SELECT 
        b.bid_id, b.auction_id, b.bidder_id, u.full_name as bidder_name,
        b.bid_amount, b.bid_time, b.is_auto_bid
      FROM bids b
      LEFT JOIN users u ON u.user_id = b.bidder_id
      WHERE b.auction_id = ?
      ORDER BY b.bid_amount DESC, b.bid_time ASC
      LIMIT 20`,
      [auctionId]
    );
    // Note: bidder_id is the correct FK column in the bids table (verified via SHOW COLUMNS)

    let isWatched = false;
    let myAutoBid: number | null = null;

    if (currentUserId) {
      const watch = await query<any[]>(
        'SELECT watchlist_id FROM watchlist WHERE user_id = ? AND auction_id = ? LIMIT 1',
        [currentUserId, auctionId]
      );
      isWatched = watch.length > 0;

      // Only return the current user's OWN auto-bid ceiling
      const autoBidRow = await query<any[]>(
        "SELECT max_amount FROM auto_bids WHERE auction_id = ? AND user_id = ? AND status = 'active' LIMIT 1",
        [auctionId, currentUserId]
      );
      if (autoBidRow.length > 0) {
        myAutoBid = parseFloat(autoBidRow[0].max_amount);
      }
    }

    // Fetch multi-image gallery
    const galleryRows = await query<any[]>(
      'SELECT image_path FROM auction_images WHERE auction_id = ? ORDER BY image_id ASC',
      [auctionId]
    ).catch(() => []);
    const galleryImages: string[] = galleryRows.map((r: any) => r.image_path).filter(Boolean);
    if (galleryImages.length === 0 && auction.image) {
      galleryImages.push(auction.image);
    }

    const moodMeter = await AuctionService.getMoodMeter(auction);

    return {
      ...auction,
      images: galleryImages,
      bids,
      is_watched: isWatched,
      my_auto_bid: myAutoBid,
      mood_meter: moodMeter,
    };
  }

  // Helper to compute Auction Mood Meter
  static async getMoodMeter(auctionOrId: any) {
    let auction = auctionOrId;
    if (typeof auctionOrId === 'number' || typeof auctionOrId === 'string') {
      const rows = await query<any[]>(
        `SELECT a.auction_id, a.watch_count, a.view_count, a.end_time,
          (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.auction_id) as bid_count
         FROM auctions a WHERE a.auction_id = ? LIMIT 1`,
        [auctionOrId]
      );
      if (!rows.length) {
        throw new Error('Auction not found');
      }
      auction = rows[0];
    }

    const auctionId = auction?.auction_id;
    const bidCount = Number(auction?.bid_count || 0);
    const watchCount = Number(auction?.watch_count || 0);
    const viewCount = Number(auction?.view_count || 0);

    // bid_velocity: bids in last 1 hour
    let bidsLastHour = 0;
    if (auctionId) {
      const velocityRows = await query<any[]>(
        `SELECT COUNT(*) as bids_last_hour FROM bids WHERE auction_id = ? AND bid_time > DATE_SUB(NOW(), INTERVAL 1 HOUR)`,
        [auctionId]
      ).catch(() => []);
      bidsLastHour = Number(velocityRows[0]?.bids_last_hour || 0);
    }

    // time_remaining_seconds: (end_time - NOW()) in seconds
    const endTimeMs = auction?.end_time ? new Date(auction.end_time).getTime() : 0;
    const nowMs = Date.now();
    const timeRemainingSeconds = endTimeMs > 0 ? Math.floor((endTimeMs - nowMs) / 1000) : 0;

    // base from bid_count: min(bid_count * 3, 40)
    const baseBidScore = Math.min(bidCount * 3, 40);

    // watch ratio: min(watch_count / max(view_count,1) * 30, 30)
    const watchRatioScore = Math.min((watchCount / Math.max(viewCount, 1)) * 30, 30);

    // time urgency: if < 3600s remaining → +20, if < 7200s → +10, else +0
    let timeUrgencyScore = 0;
    if (timeRemainingSeconds > 0 && timeRemainingSeconds < 3600) {
      timeUrgencyScore = 20;
    } else if (timeRemainingSeconds > 0 && timeRemainingSeconds < 7200) {
      timeUrgencyScore = 10;
    }

    // bid_velocity bonus: min(bids_last_hour * 5, 10)
    const velocityScore = Math.min(bidsLastHour * 5, 10);

    // mood_score = 0 to 100
    const rawScore = baseBidScore + watchRatioScore + timeUrgencyScore + velocityScore;
    const score = Math.min(100, Math.max(0, Math.round(rawScore)));

    // mood_label:
    // score >= 75 → { label: "🔥 On Fire", color: "red", description: "Bidding war going on!" }
    // score >= 50 → { label: "⚡ Heating Up", color: "orange", description: "Interest is growing fast" }
    // score >= 25 → { label: "👀 Warming Up", color: "yellow", description: "Getting some attention" }
    // else        → { label: "😴 Quiet", color: "blue", description: "Still waiting for action" }
    let moodMeta = {
      label: '😴 Quiet',
      color: 'blue',
      description: 'Still waiting for action',
    };

    if (score >= 75) {
      moodMeta = { label: '🔥 On Fire', color: 'red', description: 'Bidding war going on!' };
    } else if (score >= 50) {
      moodMeta = { label: '⚡ Heating Up', color: 'orange', description: 'Interest is growing fast' };
    } else if (score >= 25) {
      moodMeta = { label: '👀 Warming Up', color: 'yellow', description: 'Getting some attention' };
    }

    return {
      score,
      label: moodMeta.label,
      color: moodMeta.color,
      description: moodMeta.description,
      bid_count: bidCount,
      bids_last_hour: bidsLastHour,
      watch_count: watchCount,
      view_count: viewCount,
    };
  }

  // Create auction (Seller only, with full validation)
  static async createAuction(sellerId: number, data: {
    title: string;
    category_id: number;
    description: string;
    starting_price: number;
    reserve_price?: number | null;
    buy_now_price?: number | null;
    bid_increment?: number;
    start_time: string;
    end_time: string;
    image?: string;
    images?: string[];
    action?: 'submit' | 'draft';
  }) {
    // 1. Check seller role, status, and KYC verification
    const seller = await query<any[]>(
      'SELECT role, status, seller_kyc_status FROM users WHERE user_id = ? LIMIT 1',
      [sellerId]
    );

    if (!seller.length || seller[0].status !== 'active') {
      throw new Error('Seller account is not active or does not exist.');
    }

    if (seller[0].seller_kyc_status !== 'approved' && seller[0].role !== 'admin') {
      throw new Error('Please complete seller KYC verification before creating auctions.');
    }

    // 2. Validate category
    const category = await query<any[]>(
      "SELECT category_id FROM categories WHERE category_id = ? AND status = 'active' LIMIT 1",
      [data.category_id]
    );

    if (!category.length) {
      throw new Error('Selected category is invalid or inactive.');
    }

    // 3. Validate title & description
    const title = (data.title || '').trim();
    if (title.length < 3 || title.length > 120) {
      throw new Error('Title must be between 3 and 120 characters.');
    }

    const description = (data.description || '').trim();
    if (description.length < 10 || description.length > 2000) {
      throw new Error('Description must be between 10 and 2000 characters.');
    }

    // 4. Validate pricing logic
    const startingPrice = parseFloat(String(data.starting_price));
    if (isNaN(startingPrice) || startingPrice <= 0) {
      throw new Error('Starting price must be greater than zero.');
    }

    const bidIncrement = data.bid_increment ? parseFloat(String(data.bid_increment)) : 500.0;
    if (isNaN(bidIncrement) || bidIncrement < 100) {
      throw new Error('Minimum bid increment must be at least Rs. 100.');
    }

    let reservePrice: number | null = null;
    if (data.reserve_price !== null && data.reserve_price !== undefined) {
      reservePrice = parseFloat(String(data.reserve_price));
      if (isNaN(reservePrice) || reservePrice < startingPrice) {
        throw new Error('Reserve price must be greater than or equal to the starting price.');
      }
    }

    let buyNowPrice: number | null = null;
    if (data.buy_now_price !== null && data.buy_now_price !== undefined) {
      buyNowPrice = parseFloat(String(data.buy_now_price));
      if (isNaN(buyNowPrice) || buyNowPrice <= startingPrice) {
        throw new Error('Buy-Now price must be higher than the starting price.');
      }
      if (reservePrice && buyNowPrice < reservePrice) {
        throw new Error('Buy-Now price must be equal to or higher than the reserve price.');
      }
    }

    // 5. Validate dates in PKT (+05:00)
    const startDate = parseAuctionDateTime(data.start_time);
    const endDate = parseAuctionDateTime(data.end_time);
    const startTime = startDate.getTime();
    const endTime = endDate.getTime();
    if (isNaN(startTime) || isNaN(endTime)) {
      throw new Error('Invalid start or end time format.');
    }
    if (endTime <= startTime) {
      throw new Error('End time must be after start time.');
    }

    const durationMinutes = (endTime - startTime) / (1000 * 60);
    if (durationMinutes < 15) {
      throw new Error('Auction duration must be at least 15 minutes.');
    }
    const durationDays = durationMinutes / (60 * 24);
    if (durationDays > 30) {
      throw new Error('Auction duration cannot exceed 30 days.');
    }

    // 6. Validate & sanitize image(s)
    let image = data.image ? String(data.image).trim() : null;
    const additionalImages: string[] = Array.isArray(data.images)
      ? data.images.map((img: any) => String(img).trim()).filter(Boolean)
      : [];
    if (!image && additionalImages.length > 0) {
      image = additionalImages[0];
    }
    if (image) {
      if (image.includes('<script') || image.includes('javascript:') || image.length > 10000000) {
        throw new Error('Invalid image payload.');
      }
    }

    const status: AuctionStatus = data.action === 'draft' ? 'draft' : 'pending';

    const result = await query<any>(
      `INSERT INTO auctions
        (seller_id, category_id, title, description, starting_price, current_bid,
         reserve_price, buy_now_price, bid_increment, start_time, end_time,
         auction_status, image, anti_sniping_enabled, extension_minutes, extended_count, created_at)
       VALUES (?, ?, ?, ?, ?, 0.00, ?, ?, ?, ?, ?, ?, ?, 1, 2, 0, NOW())`,
      [
        sellerId,
        data.category_id,
        title,
        description,
        startingPrice,
        reservePrice,
        buyNowPrice,
        bidIncrement,
        startDate,
        endDate,
        status,
        image,
      ]
    );

    const auctionId = result.insertId;

    // Insert gallery images into auction_images
    if (additionalImages.length > 0) {
      for (const imgPath of additionalImages) {
        await query(
          'INSERT INTO auction_images (auction_id, image_path) VALUES (?, ?)',
          [auctionId, imgPath]
        ).catch(() => {});
      }
    } else if (image) {
      await query(
        'INSERT INTO auction_images (auction_id, image_path) VALUES (?, ?)',
        [auctionId, image]
      ).catch(() => {});
    }

    // Send notification to seller
    await NotificationService.notifyAuctionCreated(
      sellerId,
      auctionId,
      title,
      status as 'draft' | 'pending' | 'active'
    );

    return {
      auction_id: auctionId,
      status,
      message:
        status === 'draft'
          ? 'Auction saved as draft.'
          : 'Auction submitted for administrative approval.',
    };
  }

  // Update auction (Seller or Admin)
  static async updateAuction(auctionId: number, userId: number, role?: string, data?: any) {
    const auctions = await query<any[]>(
      'SELECT * FROM auctions WHERE auction_id = ? LIMIT 1',
      [auctionId]
    );
    if (!auctions.length) throw new Error('Auction not found.');
    const auction = auctions[0];

    if (auction.seller_id !== userId && role !== 'admin') {
      throw new Error('You do not have permission to edit this auction.');
    }

    if (['ended', 'sold', 'completed', 'cancelled'].includes(auction.auction_status)) {
      throw new Error(`Cannot edit an auction that is already ${auction.auction_status}.`);
    }

    const bidRows = await query<any[]>(
      'SELECT COUNT(*) as count FROM bids WHERE auction_id = ?',
      [auctionId]
    );
    const hasBids = (bidRows[0]?.count || 0) > 0;

    const updates: string[] = [];
    const params: any[] = [];

    if (data.title !== undefined) {
      const title = String(data.title).trim();
      if (title.length < 3 || title.length > 120) throw new Error('Title must be between 3 and 120 characters.');
      updates.push('title = ?');
      params.push(title);
    }
    if (data.description !== undefined) {
      const description = String(data.description).trim();
      if (description.length < 10) throw new Error('Description must be at least 10 characters.');
      updates.push('description = ?');
      params.push(description);
    }
    if (data.category_id !== undefined) {
      updates.push('category_id = ?');
      params.push(parseInt(data.category_id, 10));
    }
    if (data.image !== undefined) {
      const img = data.image ? String(data.image).trim() : null;
      if (img && (img.includes('<script') || img.includes('javascript:') || img.length > 10000000)) {
        throw new Error('Invalid image payload.');
      }
      updates.push('image = ?');
      params.push(img);
    }

    // Status transition: submitting draft to pending approval
    if (data.action === 'submit' && auction.auction_status === 'draft') {
      updates.push("auction_status = 'pending'");
    } else if (data.auction_status && ['draft', 'pending'].includes(data.auction_status)) {
      updates.push('auction_status = ?');
      params.push(data.auction_status);
    }

    // Protected pricing and timeline rules
    if (hasBids) {
      if (data.starting_price !== undefined && parseFloat(data.starting_price) !== parseFloat(auction.starting_price)) {
        throw new Error('Starting price cannot be modified once bidding has started.');
      }
      if (data.reserve_price !== undefined && parseFloat(data.reserve_price) !== parseFloat(auction.reserve_price)) {
        throw new Error('Reserve price cannot be modified once bidding has started.');
      }
      if (data.bid_increment !== undefined && parseFloat(data.bid_increment) !== parseFloat(auction.bid_increment)) {
        throw new Error('Bid increment cannot be modified once bidding has started.');
      }
      if (data.start_time !== undefined) {
        throw new Error('Start time cannot be modified once bidding has started.');
      }
    } else {
      if (data.starting_price !== undefined) {
        const sp = parseFloat(data.starting_price);
        if (isNaN(sp) || sp < 1) throw new Error('Starting price must be at least PKR 1.');
        updates.push('starting_price = ?');
        params.push(sp);
      }
      if (data.reserve_price !== undefined) {
        const rp = data.reserve_price ? parseFloat(data.reserve_price) : null;
        updates.push('reserve_price = ?');
        params.push(rp);
      }
      if (data.buy_now_price !== undefined) {
        const bnp = data.buy_now_price ? parseFloat(data.buy_now_price) : null;
        updates.push('buy_now_price = ?');
        params.push(bnp);
      }
      if (data.bid_increment !== undefined) {
        const bi = parseFloat(data.bid_increment);
        if (isNaN(bi) || bi < 1) throw new Error('Bid increment must be at least PKR 1.');
        updates.push('bid_increment = ?');
        params.push(bi);
      }

      let parsedStart: Date | undefined;
      let parsedEnd: Date | undefined;

      if (data.start_time !== undefined) {
        parsedStart = parseAuctionDateTime(data.start_time);
        if (isNaN(parsedStart.getTime())) throw new Error('Invalid start time format.');
      }
      if (data.end_time !== undefined) {
        parsedEnd = parseAuctionDateTime(data.end_time);
        if (isNaN(parsedEnd.getTime())) throw new Error('Invalid end time format.');
      }

      if (parsedStart || parsedEnd) {
        const effectiveStart = parsedStart ? parsedStart.getTime() : parseAuctionDateTime(auction.start_time).getTime();
        const effectiveEnd = parsedEnd ? parsedEnd.getTime() : parseAuctionDateTime(auction.end_time).getTime();
        if (effectiveEnd <= effectiveStart) {
          throw new Error('End time must be after start time.');
        }
        const durMin = (effectiveEnd - effectiveStart) / (1000 * 60);
        if (durMin < 15) {
          throw new Error('Auction duration must be at least 15 minutes.');
        }
        if (durMin / (60 * 24) > 30) {
          throw new Error('Auction duration cannot exceed 30 days.');
        }
        if (parsedStart) {
          updates.push('start_time = ?');
          params.push(parsedStart);
        }
        if (parsedEnd) {
          updates.push('end_time = ?');
          params.push(parsedEnd);
        }
      }
    }

    if (updates.length > 0) {
      params.push(auctionId);
      await query(`UPDATE auctions SET ${updates.join(', ')} WHERE auction_id = ?`, params);
    }

    const updated = await query<any[]>('SELECT * FROM auctions WHERE auction_id = ?', [auctionId]);
    return {
      auction: updated[0],
      message: 'Auction updated successfully.',
    };
  }

  // Get bid history for a single auction
  static async getAuctionBids(auctionId: number, requesterId?: number, role?: string) {
    const auctions = await query<any[]>(
      'SELECT seller_id FROM auctions WHERE auction_id = ? LIMIT 1',
      [auctionId]
    );
    if (!auctions.length) throw new Error('Auction not found.');
    const isOwner = requesterId === auctions[0].seller_id || role === 'admin';

    const bids = await query<any[]>(
      `SELECT b.bid_id, b.auction_id, b.bid_amount, b.bid_time,
              b.bidder_id, u.full_name as bidder_name, u.email as bidder_email
       FROM bids b
       LEFT JOIN users u ON u.user_id = b.bidder_id
       WHERE b.auction_id = ?
       ORDER BY b.bid_amount DESC, b.bid_time ASC`,
      [auctionId]
    );

    return bids.map((b) => {
      if (isOwner) {
        return b;
      }
      // Mask bidder name for public privacy
      const name = b.bidder_name || 'Anonymous';
      const maskedName = name.length > 2 ? `${name[0]}***${name[name.length - 1]}` : 'Bidder';
      return {
        bid_id: b.bid_id,
        auction_id: b.auction_id,
        bid_amount: b.bid_amount,
        bid_time: b.bid_time,
        bidder_name: maskedName,
      };
    });
  }

  // Get all bid activity across a seller's auctions
  static async getSellerBidActivity(sellerId: number) {
    const bids = await query<any[]>(
      `SELECT b.bid_id, b.auction_id, b.bid_amount, b.bid_time,
              a.title as auction_title, a.image as auction_image, a.current_bid,
              b.bidder_id, u.full_name as bidder_name, u.email as bidder_email
       FROM bids b
       INNER JOIN auctions a ON a.auction_id = b.auction_id
       LEFT JOIN users u ON u.user_id = b.bidder_id
       WHERE a.seller_id = ?
       ORDER BY b.bid_time DESC
       LIMIT 100`,
      [sellerId]
    );
    return bids;
  }

  // Cancel auction (Seller or Admin)
  static async cancelAuction(auctionId: number, userId: number, role?: string) {
    const auctions = await query<any[]>(
      'SELECT auction_id, seller_id, title, auction_status FROM auctions WHERE auction_id = ? LIMIT 1',
      [auctionId]
    );
    if (!auctions.length) throw new Error('Auction not found.');
    const auction = auctions[0];

    if (auction.seller_id !== userId && role !== 'admin') {
      throw new Error('You do not have permission to cancel this auction.');
    }

    if (['ended', 'sold', 'cancelled'].includes(auction.auction_status)) {
      throw new Error(`Auction is already ${auction.auction_status}.`);
    }

    // If seller is cancelling, verify no bids have been placed
    if (role !== 'admin') {
      const bidRows = await query<any[]>(
        'SELECT COUNT(*) as count FROM bids WHERE auction_id = ?',
        [auctionId]
      );
      if (bidRows[0]?.count > 0) {
        throw new Error('Cannot cancel an auction that already has active bids.');
      }
    }

    await query(
      "UPDATE auctions SET auction_status = 'cancelled', cancelled_at = NOW() WHERE auction_id = ?",
      [auctionId]
    );

    // Deactivate auto-bids
    await query(
      "UPDATE auto_bids SET status = 'cancelled', is_active = 0 WHERE auction_id = ?",
      [auctionId]
    );

    RealtimeService.broadcastStatus(auctionId, 'cancelled');

    return {
      auction_id: auctionId,
      status: 'cancelled',
      message: 'Auction has been cancelled.',
    };
  }

  // Relist an unsold, ended, or cancelled auction (Owner or Admin)
  static async relistAuction(
    originalAuctionId: number,
    sellerId: number,
    role?: string,
    data?: {
      start_time?: string;
      end_time?: string;
      starting_price?: number;
      reserve_price?: number | null;
      buy_now_price?: number | null;
      bid_increment?: number;
    }
  ) {
    const auctions = await query<any[]>(
      'SELECT * FROM auctions WHERE auction_id = ? LIMIT 1',
      [originalAuctionId]
    );
    if (!auctions.length) {
      throw new Error('Original auction not found.');
    }
    const original = auctions[0];

    // Ownership check
    if (original.seller_id !== sellerId && role !== 'admin') {
      throw new Error('You do not have permission to relist this auction.');
    }

    // Status check: only unsold, ended, or cancelled auctions can be relisted
    const relistableStatuses = ['unsold', 'cancelled', 'ended'];
    if (!relistableStatuses.includes(original.auction_status)) {
      throw new Error(
        `Cannot relist an auction with status '${original.auction_status}'. Only unsold, ended, or cancelled auctions can be relisted.`
      );
    }

    // Guard against sold items
    if (original.sold_at || original.auction_status === 'sold' || original.auction_status === 'completed') {
      throw new Error('Sold items cannot be relisted.');
    }

    return await withTransaction(async (conn) => {
      // 1. Mark original auction as 'relisted'
      await conn.execute(
        "UPDATE auctions SET auction_status = 'relisted' WHERE auction_id = ?",
        [originalAuctionId]
      );

      // 2. Determine pricing and schedule
      const startingPrice =
        data?.starting_price !== undefined
          ? parseFloat(String(data.starting_price))
          : parseFloat(original.starting_price);
      const reservePrice =
        data?.reserve_price !== undefined
          ? (data.reserve_price ? parseFloat(String(data.reserve_price)) : null)
          : original.reserve_price;
      const buyNowPrice =
        data?.buy_now_price !== undefined
          ? (data.buy_now_price ? parseFloat(String(data.buy_now_price)) : null)
          : original.buy_now_price;
      const bidIncrement =
        data?.bid_increment !== undefined
          ? parseFloat(String(data.bid_increment))
          : parseFloat(original.bid_increment || 500);

      const now = new Date();
      const startTime = data?.start_time ? parseAuctionDateTime(data.start_time) : now;
      let endTime = data?.end_time
        ? parseAuctionDateTime(data.end_time)
        : new Date(startTime.getTime() + 7 * 24 * 60 * 60 * 1000);
      if (endTime.getTime() <= startTime.getTime() || (endTime.getTime() - startTime.getTime()) < 15 * 60 * 1000) {
        endTime = new Date(startTime.getTime() + 7 * 24 * 60 * 60 * 1000);
      }

      // 3. Insert new auction linking back to original via relisted_from, resetting all bids & stats
      const [insertResult] = await conn.execute<any>(
        `INSERT INTO auctions
          (seller_id, category_id, title, description, starting_price, current_bid,
           reserve_price, buy_now_price, bid_increment, start_time, end_time,
           auction_status, image, anti_sniping_enabled, extension_minutes, extended_count,
           view_count, watch_count, views, views_count, watchlist_count, relisted_from, created_at)
         VALUES (?, ?, ?, ?, ?, 0.00, ?, ?, ?, ?, ?, 'pending', ?, ?, ?, 0, 0, 0, 0, 0, 0, ?, NOW())`,
        [
          original.seller_id,
          original.category_id,
          original.title,
          original.description,
          startingPrice,
          reservePrice,
          buyNowPrice,
          bidIncrement,
          startTime,
          endTime,
          original.image,
          original.anti_sniping_enabled ?? 1,
          original.extension_minutes ?? 2,
          originalAuctionId,
        ]
      );

      const newAuctionId = insertResult.insertId;

      // 4. Copy gallery images from auction_images
      const [galleryRows] = await conn.execute<any[]>(
        'SELECT image_path FROM auction_images WHERE auction_id = ? ORDER BY image_id ASC',
        [originalAuctionId]
      );

      if (galleryRows.length > 0) {
        for (const row of galleryRows) {
          await conn.execute(
            'INSERT INTO auction_images (auction_id, image_path) VALUES (?, ?)',
            [newAuctionId, row.image_path]
          );
        }
      } else if (original.image) {
        await conn.execute(
          'INSERT INTO auction_images (auction_id, image_path) VALUES (?, ?)',
          [newAuctionId, original.image]
        );
      }

      // 5. Notify seller
      await NotificationService.notifyAuctionCreated(
        original.seller_id,
        newAuctionId,
        original.title,
        'pending',
        conn
      );

      return {
        new_auction_id: newAuctionId,
        relisted_from: originalAuctionId,
        status: 'pending',
        message: 'Auction relisted successfully and submitted for review.',
      };
    });
  }

  // Increment view count
  static async incrementViewCount(auctionId: number): Promise<void> {
    await query('UPDATE auctions SET view_count = view_count + 1 WHERE auction_id = ?', [auctionId]);
  }
}

export const getMoodMeter = AuctionService.getMoodMeter;

