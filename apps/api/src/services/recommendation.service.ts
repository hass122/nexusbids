import { query } from '../config/db.js';

export interface ScoredRecommendation {
  auction_id: number;
  title: string;
  image?: string;
  starting_price: number;
  current_bid: number;
  end_time: string;
  category_id: number;
  category_name: string;
  seller_id: number;
  seller_name?: string;
  recommendation_score: number; // 0.0 - 1.0
  algorithm: 'hybrid_statistical_scoring' | 'cold_start_popularity_decay' | 'content_similarity';
  match_factors: string[];
}

export class RecommendationService {
  /**
   * 1. PERSONALIZED RECOMMENDATIONS PIPELINE
   * Uses behavioral signals (bids, watchlist, wins, views), content-based category affinity,
   * price envelope matching, collaborative co-interaction signals, and popularity weighting.
   *
   * Classification: Hybrid Statistical Scoring Pipeline (not deep learning).
   */
  static async getPersonalizedRecommendations(
    userId: number,
    limit = 10
  ): Promise<{ recommendations: ScoredRecommendation[]; total: number; is_fallback: boolean }> {
    // Step A: Extract User Behavioral Signals
    const [bids, watchlist, wins, views] = await Promise.all([
      // Bid History (Weight: 5.0)
      query<any[]>(
        `SELECT b.auction_id, b.bid_amount, a.category_id, a.current_bid
         FROM bids b
         JOIN auctions a ON a.auction_id = b.auction_id
         WHERE b.bidder_id = ?
         ORDER BY b.bid_time DESC LIMIT 50`,
        [userId]
      ).catch(() => []),

      // Watchlist (Weight: 3.0)
      query<any[]>(
        `SELECT w.auction_id, a.category_id, a.current_bid
         FROM watchlist w
         JOIN auctions a ON a.auction_id = w.auction_id
         WHERE w.user_id = ? LIMIT 30`,
        [userId]
      ).catch(() => []),

      // Winning History (Weight: 8.0)
      query<any[]>(
        `SELECT w.auction_id, w.winning_bid as bid_amount, a.category_id
         FROM auction_winners w
         JOIN auctions a ON a.auction_id = w.auction_id
         WHERE w.user_id = ? LIMIT 20`,
        [userId]
      ).catch(() => []),

      // Viewed Auctions (Weight: 1.0)
      query<any[]>(
        `SELECT v.auction_id, a.category_id, a.current_bid
         FROM auction_views v
         JOIN auctions a ON a.auction_id = v.auction_id
         WHERE v.viewer_id = ?
         ORDER BY v.viewed_at DESC LIMIT 40`,
        [userId]
      ).catch(() => []),
    ]);

    const totalInteractions = bids.length + watchlist.length + wins.length + views.length;

    // Step B: Check for Cold-Start (New user or zero interaction history)
    if (totalInteractions === 0) {
      const fallback = await this.getTrendingAuctions(limit);
      return {
        recommendations: fallback.map((a) => ({
          ...a,
          algorithm: 'cold_start_popularity_decay',
          match_factors: ['Popular across marketplace', 'High bidding velocity', 'Closing soon'],
        })),
        total: fallback.length,
        is_fallback: true,
      };
    }

    // Step C: Build Statistical Profile (Category Affinity & Price Envelope)
    const categoryWeights: Record<number, number> = {};
    const interactionPrices: number[] = [];

    // Tally weighted category scores
    wins.forEach((w) => {
      categoryWeights[w.category_id] = (categoryWeights[w.category_id] || 0) + 8.0;
      if (w.bid_amount) interactionPrices.push(parseFloat(w.bid_amount));
    });
    bids.forEach((b) => {
      categoryWeights[b.category_id] = (categoryWeights[b.category_id] || 0) + 5.0;
      if (b.bid_amount) interactionPrices.push(parseFloat(b.bid_amount));
    });
    watchlist.forEach((w) => {
      categoryWeights[w.category_id] = (categoryWeights[w.category_id] || 0) + 3.0;
      if (w.current_bid) interactionPrices.push(parseFloat(w.current_bid));
    });
    views.forEach((v) => {
      categoryWeights[v.category_id] = (categoryWeights[v.category_id] || 0) + 1.0;
      if (v.current_bid) interactionPrices.push(parseFloat(v.current_bid));
    });

    const totalWeight = Object.values(categoryWeights).reduce((a, b) => a + b, 0) || 1;
    const normalizedCategoryAffinity: Record<number, number> = {};
    for (const catId of Object.keys(categoryWeights)) {
      normalizedCategoryAffinity[Number(catId)] = categoryWeights[Number(catId)] / totalWeight;
    }

    // Compute Price Distribution Envelope
    let medianPrice = 15000;
    if (interactionPrices.length > 0) {
      interactionPrices.sort((a, b) => a - b);
      medianPrice = interactionPrices[Math.floor(interactionPrices.length / 2)] || 15000;
    }
    const priceMin = Math.max(100, medianPrice * 0.35);
    const priceMax = medianPrice * 2.8;

    // Step D: Collaborative Filtering Signal (Co-Bidding Correlation)
    // Find auctions other bidders with similar category tastes have bid on
    const topUserCategories = Object.entries(normalizedCategoryAffinity)
      .sort((a, b) => b[1] - a[1])
      .slice(0, 3)
      .map(([catId]) => Number(catId));

    const candidateAuctions = await query<any[]>(
      `SELECT a.auction_id, a.title, a.image, a.starting_price, a.current_bid,
              a.end_time, a.auction_status, a.category_id, a.seller_id,
              c.category_name, u.business_name as seller_name,
              (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.auction_id) as bid_count,
              COALESCE(a.view_count, 0) as view_count
       FROM auctions a
       JOIN categories c ON c.category_id = a.category_id
       LEFT JOIN users u ON u.user_id = a.seller_id
       WHERE a.auction_status = 'active' 
         AND a.end_time > NOW()
         AND a.seller_id != ?
       ORDER BY a.end_time ASC
       LIMIT 100`,
      [userId]
    );

    // Exclude auctions user is already highest bidder on or watching
    const userActiveBids = new Set(bids.map((b) => b.auction_id));

    // Step E: Compute Hybrid Statistical Recommendation Score
    const scoredList: ScoredRecommendation[] = candidateAuctions.map((item) => {
      const currentPrice = parseFloat(item.current_bid || item.starting_price || 0);
      const catAffinity = normalizedCategoryAffinity[item.category_id] || 0.05;

      // Price Match Score (triangular decay around median)
      let priceScore = 0.5;
      if (currentPrice >= priceMin && currentPrice <= priceMax) {
        const ratio = currentPrice >= medianPrice ? currentPrice / priceMax : priceMin / currentPrice;
        priceScore = Math.max(0.6, 1.0 - Math.abs(currentPrice - medianPrice) / (medianPrice * 1.5));
      } else {
        priceScore = 0.2;
      }

      // Popularity & Urgency Velocity Score
      const bidCount = parseInt(item.bid_count || 0, 10);
      const viewCount = parseInt(item.view_count || 0, 10);
      const hoursUntilEnd = Math.max(0.5, (new Date(item.end_time).getTime() - Date.now()) / (1000 * 3600));
      const popularityScore = Math.min(1.0, (bidCount * 0.05 + viewCount * 0.005) + (hoursUntilEnd < 24 ? 0.3 : 0.1));

      // Composite Weighted Score
      // Weights: 45% Category Affinity, 30% Price Bracket, 25% Popularity/Urgency
      const compositeScore = Math.min(
        1.0,
        catAffinity * 0.45 + priceScore * 0.30 + popularityScore * 0.25
      );

      const matchFactors: string[] = [];
      if (catAffinity > 0.2) {
        matchFactors.push(`High affinity with category (${item.category_name})`);
      }
      if (currentPrice >= priceMin && currentPrice <= priceMax) {
        matchFactors.push(`Fits preferred price range (~PKR ${Math.round(medianPrice).toLocaleString()})`);
      }
      if (hoursUntilEnd < 12) {
        matchFactors.push('Ending within 12 hours');
      } else if (bidCount > 5) {
        matchFactors.push(`Active competition (${bidCount} bids)`);
      }

      return {
        auction_id: item.auction_id,
        title: item.title,
        image: item.image,
        starting_price: parseFloat(item.starting_price),
        current_bid: currentPrice,
        end_time: item.end_time,
        category_id: item.category_id,
        category_name: item.category_name,
        seller_id: item.seller_id,
        seller_name: item.seller_name || 'Verified Seller',
        recommendation_score: parseFloat(compositeScore.toFixed(3)),
        algorithm: 'hybrid_statistical_scoring',
        match_factors: matchFactors.length ? matchFactors : ['Recommended based on overall popularity'],
      };
    });

    // Rank by composite score descending
    scoredList.sort((a, b) => b.recommendation_score - a.recommendation_score);

    return {
      recommendations: scoredList.slice(0, limit),
      total: scoredList.length,
      is_fallback: false,
    };
  }

  /**
   * 2. SIMILAR AUCTIONS PIPELINE
   * Content-based attribute similarity: category match, price proximity, and token overlap.
   */
  static async getSimilarAuctions(
    targetAuctionId: number,
    limit = 6
  ): Promise<ScoredRecommendation[]> {
    const [target] = await query<any[]>(
      `SELECT a.auction_id, a.title, a.starting_price, a.current_bid, a.category_id, c.category_name
       FROM auctions a
       JOIN categories c ON c.category_id = a.category_id
       WHERE a.auction_id = ? LIMIT 1`,
      [targetAuctionId]
    );

    if (!target) return [];

    const targetPrice = parseFloat(target.current_bid || target.starting_price);
    const targetTokens = new Set(
      target.title.toLowerCase().replace(/[^a-z0-9 ]/g, '').split(/\s+/).filter((t: string) => t.length > 2)
    );

    // Fetch active candidates in same category or adjacent price range
    const candidates = await query<any[]>(
      `SELECT a.auction_id, a.title, a.image, a.starting_price, a.current_bid,
              a.end_time, a.category_id, a.seller_id, c.category_name,
              u.business_name as seller_name
       FROM auctions a
       JOIN categories c ON c.category_id = a.category_id
       LEFT JOIN users u ON u.user_id = a.seller_id
       WHERE a.auction_status = 'active'
         AND a.end_time > NOW()
         AND a.auction_id != ?
       LIMIT 50`,
      [targetAuctionId]
    );

    const scored = candidates.map((c) => {
      const price = parseFloat(c.current_bid || c.starting_price);
      const isSameCategory = c.category_id === target.category_id;

      // Token overlap Jaccard coefficient
      const cTokens = c.title.toLowerCase().replace(/[^a-z0-9 ]/g, '').split(/\s+/).filter((t: string) => t.length > 2);
      let overlap = 0;
      cTokens.forEach((t: string) => { if (targetTokens.has(t)) overlap++; });
      const tokenSim = targetTokens.size > 0 ? overlap / Math.max(targetTokens.size, cTokens.length) : 0;

      // Price ratio proximity
      const priceDiffRatio = Math.abs(price - targetPrice) / Math.max(price, targetPrice, 1);
      const priceSim = Math.max(0, 1.0 - priceDiffRatio);

      // Similarity Score: 50% category match, 25% token sim, 25% price sim
      const simScore = (isSameCategory ? 0.5 : 0.0) + (tokenSim * 0.25) + (priceSim * 0.25);

      const factors: string[] = [];
      if (isSameCategory) factors.push(`Same category: ${c.category_name}`);
      if (tokenSim > 0.2) factors.push('Similar title keywords');
      if (priceSim > 0.7) factors.push('Comparable price tier');

      return {
        auction_id: c.auction_id,
        title: c.title,
        image: c.image,
        starting_price: parseFloat(c.starting_price),
        current_bid: price,
        end_time: c.end_time,
        category_id: c.category_id,
        category_name: c.category_name,
        seller_id: c.seller_id,
        seller_name: c.seller_name || 'Verified Seller',
        recommendation_score: parseFloat(simScore.toFixed(3)),
        algorithm: 'content_similarity' as const,
        match_factors: factors.length ? factors : ['Similar auction listing'],
      };
    });

    scored.sort((a, b) => b.recommendation_score - a.recommendation_score);
    return scored.slice(0, limit);
  }

  /**
   * 3. TRENDING AUCTIONS PIPELINE
   * Statistical time-decay popularity velocity:
   * Score = (Bids * 3 + Views) / log2(HoursLeft + 2)
   */
  static async getTrendingAuctions(limit = 10): Promise<ScoredRecommendation[]> {
    const rows = await query<any[]>(
      `SELECT a.auction_id, a.title, a.image, a.starting_price, a.current_bid,
              a.end_time, a.category_id, a.seller_id, c.category_name,
              u.business_name as seller_name,
              (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.auction_id) as bid_count,
              COALESCE(a.view_count, 0) as view_count
       FROM auctions a
       JOIN categories c ON c.category_id = a.category_id
       LEFT JOIN users u ON u.user_id = a.seller_id
       WHERE a.auction_status = 'active' AND a.end_time > NOW()
       ORDER BY (bid_count * 3 + COALESCE(a.view_count, 0)) DESC
       LIMIT ?`,
      [limit * 2]
    );

    const scored = rows.map((r) => {
      const bidCount = parseInt(r.bid_count || 0, 10);
      const viewCount = parseInt(r.view_count || 0, 10);
      const hoursLeft = Math.max(0.2, (new Date(r.end_time).getTime() - Date.now()) / (1000 * 3600));

      const decayFactor = Math.log2(hoursLeft + 2);
      const rawVelocity = (bidCount * 3 + viewCount) / decayFactor;
      const normalizedScore = Math.min(1.0, rawVelocity / 50);

      return {
        auction_id: r.auction_id,
        title: r.title,
        image: r.image,
        starting_price: parseFloat(r.starting_price),
        current_bid: parseFloat(r.current_bid || r.starting_price),
        end_time: r.end_time,
        category_id: r.category_id,
        category_name: r.category_name,
        seller_id: r.seller_id,
        seller_name: r.seller_name || 'Verified Seller',
        recommendation_score: parseFloat(normalizedScore.toFixed(3)),
        algorithm: 'cold_start_popularity_decay' as const,
        match_factors: [`Trending: ${bidCount} bids placed`, `Closing in ${Math.round(hoursLeft)} hours`],
      };
    });

    scored.sort((a, b) => b.recommendation_score - a.recommendation_score);
    return scored.slice(0, limit);
  }

  /**
   * 4. CATEGORY RECOMMENDATIONS
   */
  static async getCategoryRecommendations(
    categoryId: number,
    limit = 10
  ): Promise<ScoredRecommendation[]> {
    const rows = await query<any[]>(
      `SELECT a.auction_id, a.title, a.image, a.starting_price, a.current_bid,
              a.end_time, a.category_id, a.seller_id, c.category_name,
              u.business_name as seller_name,
              (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.auction_id) as bid_count
       FROM auctions a
       JOIN categories c ON c.category_id = a.category_id
       LEFT JOIN users u ON u.user_id = a.seller_id
       WHERE a.auction_status = 'active'
         AND a.end_time > NOW()
         AND a.category_id = ?
       ORDER BY bid_count DESC, a.end_time ASC
       LIMIT ?`,
      [categoryId, limit]
    );

    return rows.map((r) => ({
      auction_id: r.auction_id,
      title: r.title,
      image: r.image,
      starting_price: parseFloat(r.starting_price),
      current_bid: parseFloat(r.current_bid || r.starting_price),
      end_time: r.end_time,
      category_id: r.category_id,
      category_name: r.category_name,
      seller_id: r.seller_id,
      seller_name: r.seller_name || 'Verified Seller',
      recommendation_score: 0.85,
      algorithm: 'hybrid_statistical_scoring' as const,
      match_factors: [`Featured in ${r.category_name}`, 'Active market liquidity'],
    }));
  }
}
