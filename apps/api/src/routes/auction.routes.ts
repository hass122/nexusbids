import { Router } from 'express';
import { AuctionController } from '../controllers/auction.controller.js';
import { validateRequest } from '../middleware/validate.js';
import { requireAuth, requireKyc, requireSellerKyc, optionalAuth } from '../middleware/auth.js';
import { bidRateLimiter } from '../middleware/rateLimit.js';
import { createAuctionSchema, placeBidSchema, setAutoBidSchema } from '@nexusbids/shared';

const router = Router();

// Public routes (with optional auth for personalized state)
router.get('/', optionalAuth, AuctionController.getAuctions);

// ✅ FIX: Static routes MUST come BEFORE dynamic '/:id' routes
router.get('/seller/bids', requireAuth, AuctionController.getSellerBids);

// Protected routes
router.post(
  '/',
  requireAuth,
  requireSellerKyc,
  validateRequest(createAuctionSchema),
  AuctionController.createAuction
);

router.get('/:id', optionalAuth, AuctionController.getAuctionById);
router.put('/:id', requireAuth, requireSellerKyc, AuctionController.updateAuction);
router.patch('/:id', requireAuth, requireSellerKyc, AuctionController.updateAuction);
router.get('/:id/bids', optionalAuth, AuctionController.getAuctionBids);

// Manual Bidding (Rate-limited, validated, KYC verified)
router.post(
  '/:id/bids',
  requireAuth,
  requireKyc,
  bidRateLimiter,
  validateRequest(placeBidSchema),
  AuctionController.placeBid
);
router.post(
  '/:id/bid',
  requireAuth,
  requireKyc,
  bidRateLimiter,
  validateRequest(placeBidSchema),
  AuctionController.placeBid
);

// Proxy Auto-Bidding (Rate-limited, validated, KYC verified)
router.post(
  '/:id/auto-bid',
  requireAuth,
  requireKyc,
  bidRateLimiter,
  validateRequest(setAutoBidSchema),
  AuctionController.setAutoBid
);
router.post(
  '/:id/autobid',
  requireAuth,
  requireKyc,
  bidRateLimiter,
  validateRequest(setAutoBidSchema),
  AuctionController.setAutoBid
);

// Proxy Auto-Bidding Management
router.get('/:id/auto-bid', requireAuth, AuctionController.getMyAutoBid);
router.get('/:id/autobid', requireAuth, AuctionController.getMyAutoBid);
router.delete('/:id/auto-bid', requireAuth, AuctionController.cancelAutoBid);
router.delete('/:id/autobid', requireAuth, AuctionController.cancelAutoBid);

// Instant Buy-Now
router.post('/:id/buy-now', requireAuth, requireKyc, AuctionController.buyNow);

// Auction Cancellation & Relisting (Seller or Admin)
router.post('/:id/cancel', requireAuth, AuctionController.cancelAuction);
router.post('/:id/relist', requireAuth, requireSellerKyc, AuctionController.relistAuction);

// View Counter
router.post('/:id/view', AuctionController.incrementView);

export default router;
