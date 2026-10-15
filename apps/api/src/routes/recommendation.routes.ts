import { Router } from 'express';
import { optionalAuth, requireAuth, requireRole } from '../middleware/auth.js';
import { sendSuccess, sendError } from '../utils/response.js';
import { RecommendationService } from '../services/recommendation.service.js';
import { SecurityService } from '../services/security.service.js';

const router = Router();

// ─── 1. Personalized Recommendations ─────────────────────────
// GET /api/v1/recommendations/personalized
router.get('/personalized', optionalAuth, async (req, res) => {
  try {
    const userId = req.user?.userId;
    const limit = Math.min(24, Math.max(1, parseInt((req.query.limit as string) || '10', 10)));

    if (!userId) {
      // Unauthenticated visitor -> High quality trending cold-start fallback
      const fallback = await RecommendationService.getTrendingAuctions(limit);
      sendSuccess(res, {
        recommendations: fallback,
        total: fallback.length,
        is_fallback: true,
        algorithm: 'cold_start_popularity_decay',
      });
      return;
    }

    const result = await RecommendationService.getPersonalizedRecommendations(userId, limit);
    sendSuccess(res, result);
  } catch (err: any) {
    sendError(res, err.message, 500);
  }
});

// ─── 2. Similar Auctions (Content-Based) ───────────────────────
// GET /api/v1/recommendations/similar/:auctionId
router.get('/similar/:auctionId', async (req, res) => {
  try {
    const auctionId = parseInt(req.params.auctionId, 10);
    const limit = Math.min(12, Math.max(1, parseInt((req.query.limit as string) || '6', 10)));

    if (isNaN(auctionId)) {
      sendError(res, 'Valid auctionId is required', 400);
      return;
    }

    const items = await RecommendationService.getSimilarAuctions(auctionId, limit);
    sendSuccess(res, {
      auction_id: auctionId,
      similar_auctions: items,
      count: items.length,
      algorithm: 'content_based_attribute_similarity',
    });
  } catch (err: any) {
    sendError(res, err.message, 500);
  }
});

// ─── 3. Trending Auctions (Statistical Velocity) ───────────────
// GET /api/v1/recommendations/trending
router.get('/trending', async (req, res) => {
  try {
    const limit = Math.min(24, Math.max(1, parseInt((req.query.limit as string) || '10', 10)));
    const trending = await RecommendationService.getTrendingAuctions(limit);
    sendSuccess(res, {
      trending,
      count: trending.length,
      algorithm: 'time_decayed_bid_view_velocity',
    });
  } catch (err: any) {
    sendError(res, err.message, 500);
  }
});

// ─── 4. Category Recommendations ──────────────────────────────
// GET /api/v1/recommendations/category/:categoryId
router.get('/category/:categoryId', async (req, res) => {
  try {
    const categoryId = parseInt(req.params.categoryId, 10);
    const limit = Math.min(24, Math.max(1, parseInt((req.query.limit as string) || '10', 10)));

    if (isNaN(categoryId)) {
      sendError(res, 'Valid categoryId is required', 400);
      return;
    }

    const items = await RecommendationService.getCategoryRecommendations(categoryId, limit);
    sendSuccess(res, {
      category_id: categoryId,
      recommendations: items,
      count: items.length,
    });
  } catch (err: any) {
    sendError(res, err.message, 500);
  }
});

// ─── 5. Fraud Diagnostic & Evaluation Tool ─────────────────────
// POST /api/v1/recommendations/fraud-check
// Protected: Admin only
router.post('/fraud-check', requireAuth, requireRole('admin'), async (req, res) => {
  try {
    const { auction_id, bid_id, bidder_id, bid_amount, bidder_ip } = req.body;
    if (!auction_id || !bidder_id || !bid_amount) {
      sendError(res, 'auction_id, bidder_id, and bid_amount are required', 400);
      return;
    }

    const result = await SecurityService.analyzeBidFraud(
      parseInt(auction_id, 10),
      parseInt(bid_id || '0', 10),
      parseInt(bidder_id, 10),
      parseFloat(bid_amount),
      bidder_ip || req.ip
    );

    sendSuccess(res, result, 'Fraud analysis completed');
  } catch (err: any) {
    sendError(res, err.message, 500);
  }
});

export default router;
