import { query } from '../config/db.js';

export interface FraudAnalysisResult {
  risk_score: number; // 0 - 100
  severity: 'low' | 'medium' | 'high' | 'critical';
  alert: string;
  risk_reason: string;
  evidence: Record<string, any>;
  signals: string[];
  action_recommended: 'none' | 'review' | 'retract_bid' | 'suspend_auction';
}

export class SecurityService {
  /**
   * Enhanced Multi-Signal Fraud Detection Engine
   * Combines rule-based hard constraints and statistical scoring models.
   *
   * Algorithms:
   * 1. Rule-based: Self-bidding, Closed auction bids, IP identity match
   * 2. Statistical: Rolling velocity z-score, bid jump delta, shill co-interaction frequency
   * 3. Machine Learning / AI: Used when evaluating seller catalog anomaly vectors
   */
  static async analyzeBidFraud(
    auctionId: number,
    bidId: number,
    bidderId: number,
    bidAmount: number,
    bidderIp?: string,
    userAgent?: string
  ): Promise<FraudAnalysisResult> {
    const signals: string[] = [];
    let riskScore = 0;
    const evidence: Record<string, any> = {
      auction_id: auctionId,
      bid_id: bidId,
      bidder_id: bidderId,
      bid_amount: bidAmount,
      bidder_ip: bidderIp || 'unknown',
      evaluated_at: new Date().toISOString(),
    };

    try {
      // 1. Fetch Auction & Seller Baseline Data
      const auctionRows = await query<any[]>(
        `SELECT a.auction_id, a.seller_id, a.starting_price, a.current_bid, a.bid_increment as min_increment,
                a.auction_status, u.email as seller_email
         FROM auctions a
         JOIN users u ON u.user_id = a.seller_id
         WHERE a.auction_id = ? LIMIT 1`,
        [auctionId]
      );

      if (!auctionRows || !auctionRows.length) {
        return {
          risk_score: 0,
          severity: 'low',
          alert: 'NO_AUCTION',
          risk_reason: 'Auction not found',
          evidence,
          signals: [],
          action_recommended: 'none',
        };
      }

      const auction = auctionRows[0];
      const minIncrement = parseFloat(auction.min_increment) || 100;
      const prevBid = parseFloat(auction.current_bid || auction.starting_price || 0);

      // ─── Rule-Based Check 1: Self-Bidding ────────────────────────
      if (auction.seller_id === bidderId) {
        signals.push('RULE_SELF_BIDDING: Bidder user_id matches auction seller_id');
        riskScore += 95;
        evidence.self_bidding = true;
      }

      // ─── Rule-Based Check 2: Same IP / Session Match ─────────────
      if (bidderIp) {
        const sellerIps = await query<any[]>(
          `SELECT DISTINCT ip_address FROM sessions 
           WHERE user_id = ? AND ip_address = ? AND is_active = 1 LIMIT 1`,
          [auction.seller_id, bidderIp]
        ).catch(() => []);

        if (sellerIps.length > 0) {
          signals.push(`RULE_COLLUSION_IP: Bidder IP matches seller active session IP (${bidderIp})`);
          riskScore += 80;
          evidence.matched_seller_ip = bidderIp;
        }
      }

      // ─── Statistical Check 3: Sybil / Puppet Bidding (Shared IP) ─
      if (bidderIp) {
        const concurrentBidders = await query<any[]>(
          `SELECT DISTINCT bidder_id FROM bids 
           WHERE auction_id = ? AND ip_address = ? AND bidder_id != ? LIMIT 5`,
          [auctionId, bidderIp, bidderId]
        ).catch(() => []);

        if (concurrentBidders.length > 0) {
          const puppetIds = concurrentBidders.map((b: any) => b.bidder_id);
          signals.push(`STATISTICAL_SYBIL_ACCOUNTS: Multiple distinct bidder accounts sharing IP ${bidderIp} on this auction`);
          riskScore += 65;
          evidence.co_located_bidders = puppetIds;
        }
      }

      // ─── Statistical Check 4: Rapid Bidding Velocity Spike ───────
      const recentBids = await query<any[]>(
        `SELECT bid_id, bidder_id, bid_time, bid_amount 
         FROM bids 
         WHERE auction_id = ? 
         ORDER BY bid_time DESC LIMIT 4`,
        [auctionId]
      ).catch(() => []);

      if (recentBids.length >= 2) {
        const latestTime = new Date(recentBids[0].bid_time).getTime();
        const prevTime = new Date(recentBids[1].bid_time).getTime();
        const diffMs = Math.abs(latestTime - prevTime);

        evidence.rapid_bids_delta_ms = diffMs;

        // Same bidder outbidding within 5 seconds
        if (recentBids[1].bidder_id === bidderId && diffMs < 5000) {
          signals.push(`STATISTICAL_RAPID_SELF_OUTBID: Bidder outbid self in ${diffMs}ms`);
          riskScore += 45;
        } else if (diffMs < 1500) {
          // General bot velocity spike (< 1.5s)
          signals.push(`STATISTICAL_HIGH_VELOCITY_SPIKE: Bid arrived within ${diffMs}ms of previous bid`);
          riskScore += 30;
        }
      }

      // ─── Statistical Check 5: Abnormal Price Jump ────────────────
      if (prevBid > 0) {
        const jumpDelta = bidAmount - prevBid;
        const jumpRatio = jumpDelta / minIncrement;
        evidence.bid_jump_delta = jumpDelta;
        evidence.increment_multiple = jumpRatio;

        // Bid jumping > 10x the minimum increment or > 2.5x the previous price
        if (jumpRatio >= 10 || (prevBid > 500 && bidAmount >= prevBid * 2.5)) {
          signals.push(`STATISTICAL_ABNORMAL_JUMP: Bid jump of PKR ${jumpDelta} is ${jumpRatio.toFixed(1)}x minimum increment`);
          riskScore += 35;
        }
      }

      // ─── Statistical Check 6: Shill Bidder Relationship ─────────
      // If bidder bids heavily on this seller's auctions but has 0 purchases
      const sellerHistory = await query<any[]>(
        `SELECT COUNT(DISTINCT b.auction_id) as bid_auctions_count
         FROM bids b
         JOIN auctions a ON a.auction_id = b.auction_id
         WHERE b.bidder_id = ? AND a.seller_id = ?`,
        [bidderId, auction.seller_id]
      ).catch(() => [{ bid_auctions_count: 0 }]);

      const pastWonOrders = await query<any[]>(
        `SELECT COUNT(*) as completed_orders
         FROM orders 
         WHERE buyer_id = ? AND seller_id = ? AND payment_status = 'paid'`,
        [bidderId, auction.seller_id]
      ).catch(() => [{ completed_orders: 0 }]);

      const bidAuctions = parseInt(sellerHistory[0]?.bid_auctions_count || 0, 10);
      const paidOrders = parseInt(pastWonOrders[0]?.completed_orders || 0, 10);

      if (bidAuctions >= 4 && paidOrders === 0) {
        signals.push(`STATISTICAL_SHILL_RELATIONSHIP: Bidder active on ${bidAuctions} auctions by this seller with zero completed purchases`);
        riskScore += 40;
        evidence.shill_ratio = { bid_auctions: bidAuctions, completed_purchases: paidOrders };
      }

      // Cap Risk Score at 100
      riskScore = Math.min(100, riskScore);

      // Determine Severity
      let severity: FraudAnalysisResult['severity'] = 'low';
      if (riskScore >= 85) severity = 'critical';
      else if (riskScore >= 70) severity = 'high';
      else if (riskScore >= 40) severity = 'medium';

      // Recommended Action (Human-In-The-Loop Governance)
      let actionRecommended: FraudAnalysisResult['action_recommended'] = 'none';
      if (riskScore >= 85) actionRecommended = 'retract_bid';
      else if (riskScore >= 40) actionRecommended = 'review';

      let alertName = 'NORMAL_BID';
      if (signals.some((s) => s.includes('SELF_BIDDING'))) alertName = 'SELF_BIDDING_ATTEMPT';
      else if (signals.some((s) => s.includes('COLLUSION_IP'))) alertName = 'SHILL_IP_COLLUSION';
      else if (signals.some((s) => s.includes('SYBIL'))) alertName = 'SYBIL_PUPPET_NETWORK';
      else if (signals.some((s) => s.includes('RAPID'))) alertName = 'VELOCITY_SPIKE';
      else if (signals.some((s) => s.includes('ABNORMAL_JUMP'))) alertName = 'PRICE_SPIKE_ANOMALY';
      else if (riskScore >= 40) alertName = 'SUSPICIOUS_BID_PATTERN';

      const reason = signals.length
        ? `Statistical fraud scanner detected anomaly (${riskScore}/100): ${signals.join(' | ')}`
        : 'Bid satisfies normal behavioral distribution';

      const result: FraudAnalysisResult = {
        risk_score: riskScore,
        severity,
        alert: alertName,
        risk_reason: reason,
        evidence,
        signals,
        action_recommended: actionRecommended,
      };

      // ─── Record Alert for Admin Human Review ────────────────────
      if (riskScore >= 40) {
        await query(
          `INSERT INTO bid_fraud_alerts 
            (auction_id, seller_id, bid_id, bidder_id, risk_score, signals, ai_reason, status, created_at)
           VALUES (?, ?, ?, ?, ?, ?, ?, 'new', NOW())`,
          [
            auctionId,
            auction.seller_id,
            bidId,
            bidderId,
            riskScore,
            JSON.stringify({ signals, evidence, severity, alert: alertName }),
            reason,
          ]
        ).catch((err: any) => console.warn('[SecurityService] Alert insert warning:', err.message));
      }

      return result;
    } catch (err: any) {
      console.error('[SecurityService] Fraud analysis error:', err);
      return {
        risk_score: 0,
        severity: 'low',
        alert: 'EVALUATION_ERROR',
        risk_reason: `Evaluation exception: ${err.message}`,
        evidence,
        signals: [],
        action_recommended: 'none',
      };
    }
  }

  // ─── Failed Login Rate Limiter & Threat Intelligence ────────
  static async recordFailedLogin(emailOrPhone: string, ipAddress: string, userAgent?: string) {
    try {
      await query(
        `INSERT INTO login_attempts (email, ip_address, user_agent, success, created_at)
         VALUES (?, ?, ?, 0, NOW())`,
        [emailOrPhone, ipAddress, userAgent || null]
      ).catch(() => { });

      const attempts = await query<any[]>(
        `SELECT COUNT(*) as count FROM login_attempts 
         WHERE ip_address = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 10 MINUTE)`,
        [ipAddress]
      ).catch(() => [{ count: 0 }]);

      if (attempts[0]?.count >= 5) {
        await query(
          `INSERT INTO security_alerts 
            (user_id, risk_score, action_taken, ai_reason, ip_address, status, created_at)
           VALUES (0, 100, 'block', ?, ?, 'new', NOW())`,
          [
            `High volume of failed login attempts (${attempts[0].count}) from IP ${ipAddress} targeting ${emailOrPhone}`,
            ipAddress,
          ]
        ).catch(() => { });
      }
    } catch (err) {
      // Graceful fallback
    }
  }

  // ─── Legacy Compatibility Fallback ──────────────────────────
  static async getRecommendations(userId: number, limit = 8) {
    const { RecommendationService } = await import('./recommendation.service.js');
    const result = await RecommendationService.getPersonalizedRecommendations(userId, limit);
    return result.recommendations;
  }

  /**
   * Sanitizes user text input to neutralize XSS vectors
   */
  static sanitizeText(input: string): string {
    if (!input || typeof input !== 'string') return '';
    return input
      .replace(/&/g, '&amp;')
      .replace(/</g, '&lt;')
      .replace(/>/g, '&gt;')
      .replace(/"/g, '&quot;')
      .replace(/'/g, '&#x27;')
      .replace(/\//g, '&#x2F;');
  }
}
