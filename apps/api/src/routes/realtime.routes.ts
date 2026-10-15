import { Router } from 'express';
import { RealtimeService } from '../services/realtime.service.js';
import { SecurityService } from '../services/security.service.js';
import { sendSuccess, sendError } from '../utils/response.js';
import { optionalAuth } from '../middleware/auth.js';
import crypto from 'crypto';

const router = Router();

// ─── Live Auction SSE Stream ──────────────────────────────────
// GET /api/v1/realtime/stream/:auctionId
router.get('/stream/:auctionId', (req, res) => {
  const auctionId = parseInt(req.params.auctionId, 10);
  if (isNaN(auctionId)) {
    res.status(400).json({ success: false, error: 'Invalid auction ID' });
    return;
  }

  const clientId = crypto.randomUUID();
  RealtimeService.registerClient(clientId, auctionId, res);
});

// ─── AI Recommendations ───────────────────────────────────────
// GET /api/v1/realtime/recommendations
router.get('/recommendations', optionalAuth, async (req, res) => {
  try {
    const userId = req.user?.userId || 0;
    const limit = Math.min(20, parseInt(req.query.limit as string || '8', 10));
    const recommendations = await SecurityService.getRecommendations(userId, limit);
    sendSuccess(res, recommendations);
  } catch (err: any) {
    sendError(res, err.message, 500);
  }
});

export default router;
