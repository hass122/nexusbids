import { Router, Request, Response, NextFunction } from 'express';
import { requireAuth, requireKyc } from '../middleware/auth.js';
import { LastChanceService } from '../services/last-chance.service.js';
import { sendSuccess, sendError } from '../utils/response.js';

const router = Router();

// POST /api/v1/last-chance/:auctionId/offer — requireAuth + requireKyc → createOffer
router.post(
  '/:auctionId/offer',
  requireAuth,
  requireKyc,
  async (req: Request, res: Response, next: NextFunction) => {
    try {
      const buyerId = (req as any).user?.user_id || (req as any).user?.userId;
      const auctionId = parseInt(req.params.auctionId, 10);
      const { offer_amount, amount, message } = req.body;
      const finalAmount = parseFloat(offer_amount || amount);

      if (isNaN(auctionId) || auctionId <= 0) {
        return sendError(res, 'Invalid auction ID.', 400);
      }

      if (isNaN(finalAmount) || finalAmount <= 0) {
        return sendError(res, 'Please provide a valid offer amount greater than zero.', 400);
      }

      const offer = await LastChanceService.createOffer(
        buyerId,
        auctionId,
        finalAmount,
        typeof message === 'string' ? message.trim() : undefined
      );

      sendSuccess(res, offer, 'Private offer submitted successfully.', 201);
    } catch (err: any) {
      sendError(res, err.message || 'Failed to submit private offer.', 400);
    }
  }
);

// GET /api/v1/last-chance/buyer/offers — requireAuth → getBuyerOffers
router.get(
  '/buyer/offers',
  requireAuth,
  async (req: Request, res: Response, next: NextFunction) => {
    try {
      const buyerId = (req as any).user?.user_id || (req as any).user?.userId;
      const offers = await LastChanceService.getBuyerOffers(buyerId);
      sendSuccess(res, offers, 'Buyer private offers retrieved successfully.');
    } catch (err: any) {
      sendError(res, err.message || 'Failed to fetch buyer offers.', 500);
    }
  }
);

// GET /api/v1/last-chance/seller/offers — requireAuth → getSellerOffers
router.get(
  '/seller/offers',
  requireAuth,
  async (req: Request, res: Response, next: NextFunction) => {
    try {
      const sellerId = (req as any).user?.user_id || (req as any).user?.userId;
      const offers = await LastChanceService.getSellerOffers(sellerId);
      sendSuccess(res, offers, 'Seller private offers retrieved successfully.');
    } catch (err: any) {
      sendError(res, err.message || 'Failed to fetch seller offers.', 500);
    }
  }
);

// PATCH /api/v1/last-chance/offer/:offerId/respond — requireAuth → respondToOffer (accept/decline in body)
router.patch(
  '/offer/:offerId/respond',
  requireAuth,
  async (req: Request, res: Response, next: NextFunction) => {
    try {
      const sellerId = (req as any).user?.user_id || (req as any).user?.userId;
      const offerId = parseInt(req.params.offerId, 10);
      const { action } = req.body;

      if (isNaN(offerId) || offerId <= 0) {
        return sendError(res, 'Invalid offer ID.', 400);
      }

      if (!action || !['accept', 'decline'].includes(action)) {
        return sendError(res, "Action must be 'accept' or 'decline'.", 400);
      }

      const updated = await LastChanceService.respondToOffer(sellerId, offerId, action);
      sendSuccess(res, updated, `Offer ${action}ed successfully.`);
    } catch (err: any) {
      sendError(res, err.message || 'Failed to respond to offer.', 400);
    }
  }
);

export default router;
