import { Request, Response } from 'express';
import crypto from 'crypto';
import { AuctionService } from '../services/auction.service.js';
import { BiddingService } from '../services/bidding.service.js';
import { sendSuccess, sendError, sendPaginated } from '../utils/response.js';

export class AuctionController {
  // GET /api/v1/auctions
  static async getAuctions(req: Request, res: Response): Promise<void> {
    try {
      const VALID_STATUSES = ['active', 'pending', 'sold', 'ended', 'completed', 'cancelled', 'rejected', 'suspended', 'draft'];
      const VALID_SORTS = ['newest', 'ending_soon', 'price_low', 'price_high', 'most_bids', 'ending'];

      const rawStatus = typeof req.query.status === 'string' ? req.query.status : undefined;
      const rawSort = typeof req.query.sort === 'string' ? req.query.sort : undefined;

      const filters = {
        search: typeof req.query.search === 'string' ? req.query.search.trim().slice(0, 100) : undefined,
        category_id: req.query.category_id ? parseInt(req.query.category_id as string, 10) : undefined,
        status: rawStatus && VALID_STATUSES.includes(rawStatus) ? (rawStatus as any) : undefined,
        page: req.query.page ? Math.max(1, parseInt(req.query.page as string, 10) || 1) : 1,
        limit: req.query.limit ? Math.min(100, Math.max(1, parseInt(req.query.limit as string, 10) || 12)) : 12,
        sort: rawSort && VALID_SORTS.includes(rawSort) ? (rawSort as any) : undefined,
      };

      const result = await AuctionService.getAuctions(filters);
      const page = Math.max(1, filters.page || 1);
      const limit = Math.max(1, filters.limit || 12);
      sendPaginated(res, result.auctions, result.meta?.total || result.auctions.length, page, limit);
    } catch (err: any) {
      sendError(res, err.message, 500);
    }
  }

  // GET /api/v1/auctions/:id
  static async getAuctionById(req: Request, res: Response): Promise<void> {
    try {
      const auctionId = parseInt(req.params.id, 10);
      if (isNaN(auctionId)) {
        sendError(res, 'Invalid auction ID', 400);
        return;
      }

      const userId = req.user?.userId;
      const auction = await AuctionService.getAuctionById(auctionId, userId);
      if (!auction) {
        sendError(res, 'Auction not found', 404);
        return;
      }
      sendSuccess(res, auction);
    } catch (err: any) {
      sendError(res, err.message, 404);
    }
  }

  // POST /api/v1/auctions (Seller only)
  static async createAuction(req: Request, res: Response): Promise<void> {
    try {
      const sellerId = req.user!.userId;
      const result = await AuctionService.createAuction(sellerId, req.body);
      sendSuccess(res, result, result.message, 201);
    } catch (err: any) {
      sendError(res, err.message, 400);
    }
  }

  // POST /api/v1/auctions/:id/bids (Buyer KYC required)
  static async placeBid(req: Request, res: Response): Promise<void> {
    try {
      const auctionId = parseInt(req.params.id, 10);
      const userId = req.user!.userId;
      const rawBidAmount = req.body.bid_amount ?? req.body.amount;
      const parsedBidAmount = parseFloat(rawBidAmount);

      if (!rawBidAmount || !isFinite(parsedBidAmount) || parsedBidAmount <= 0) {
        sendError(res, 'Bid amount must be a positive number', 400);
        return;
      }
      if (parsedBidAmount > 1_000_000_000) {
        sendError(res, 'Bid amount exceeds maximum allowed limit', 400);
        return;
      }

      const ipAddress = (req.headers['x-forwarded-for'] as string) || req.socket.remoteAddress;
      const rawUA = req.headers['user-agent'] || '';
      const deviceHash = rawUA
        ? crypto.createHash('sha256').update(rawUA).digest('hex')
        : undefined;

      const result = await BiddingService.placeBid({
        auctionId,
        userId,
        bidAmount: parsedBidAmount,
        ipAddress,
        deviceHash,
      });

      sendSuccess(res, result, result.message, 201);
    } catch (err: any) {
      sendError(res, err.message, 400);
    }
  }

  // POST /api/v1/auctions/:id/auto-bid (Buyer KYC required)
  static async setAutoBid(req: Request, res: Response): Promise<void> {
    try {
      const auctionId = parseInt(req.params.id, 10);
      const userId = req.user!.userId;
      const rawMaxAmount = req.body.max_amount ?? req.body.amount;
      const parsedMaxAmount = parseFloat(rawMaxAmount);

      if (!rawMaxAmount || !isFinite(parsedMaxAmount) || parsedMaxAmount <= 0) {
        sendError(res, 'Max ceiling amount must be a positive number', 400);
        return;
      }
      if (parsedMaxAmount > 1_000_000_000) {
        sendError(res, 'Max ceiling amount exceeds maximum allowed limit', 400);
        return;
      }

      const result = await BiddingService.setAutoBid({
        auctionId,
        userId,
        maxAmount: parsedMaxAmount,
      });

      sendSuccess(res, result, result.message, 201);
    } catch (err: any) {
      sendError(res, err.message, 400);
    }
  }

  // POST /api/v1/auctions/:id/buy-now
  static async buyNow(req: Request, res: Response): Promise<void> {
    try {
      const auctionId = parseInt(req.params.id, 10);
      const userId = req.user!.userId;
      const result = await BiddingService.buyNow(auctionId, userId);
      sendSuccess(res, result, result.message, 201);
    } catch (err: any) {
      sendError(res, err.message, 400);
    }
  }

  // DELETE /api/v1/auctions/:id/auto-bid (or /autobid)
  static async cancelAutoBid(req: Request, res: Response): Promise<void> {
    try {
      const auctionId = parseInt(req.params.id, 10);
      const userId = req.user!.userId;
      const result = await BiddingService.cancelAutoBid(auctionId, userId);
      sendSuccess(res, result, result.message);
    } catch (err: any) {
      sendError(res, err.message, 400);
    }
  }

  // GET /api/v1/auctions/:id/auto-bid (or /autobid)
  static async getMyAutoBid(req: Request, res: Response): Promise<void> {
    try {
      const auctionId = parseInt(req.params.id, 10);
      const userId = req.user!.userId;
      const result = await BiddingService.getMyAutoBid(auctionId, userId);
      sendSuccess(res, result);
    } catch (err: any) {
      sendError(res, err.message, 400);
    }
  }

  // PUT/PATCH /api/v1/auctions/:id (Seller or Admin)
  static async updateAuction(req: Request, res: Response): Promise<void> {
    try {
      const auctionId = parseInt(req.params.id, 10);
      const userId = req.user!.userId;
      const role = req.user!.role;
      const result = await AuctionService.updateAuction(auctionId, userId, role, req.body);
      sendSuccess(res, result, result.message);
    } catch (err: any) {
      sendError(res, err.message, 400);
    }
  }

  // GET /api/v1/auctions/:id/bids
  static async getAuctionBids(req: Request, res: Response): Promise<void> {
    try {
      const auctionId = parseInt(req.params.id, 10);
      const userId = req.user?.userId;
      const role = req.user?.role;
      const result = await AuctionService.getAuctionBids(auctionId, userId, role);
      sendSuccess(res, result);
    } catch (err: any) {
      sendError(res, err.message, 400);
    }
  }

  // GET /api/v1/seller/bids
  static async getSellerBids(req: Request, res: Response): Promise<void> {
    try {
      const sellerId = req.user!.userId;
      const result = await AuctionService.getSellerBidActivity(sellerId);
      sendSuccess(res, result);
    } catch (err: any) {
      sendError(res, err.message, 500);
    }
  }

  // POST /api/v1/auctions/:id/cancel (Seller or Admin)
  static async cancelAuction(req: Request, res: Response): Promise<void> {
    try {
      const auctionId = parseInt(req.params.id, 10);
      const userId = req.user!.userId;
      const role = req.user!.role;
      const result = await AuctionService.cancelAuction(auctionId, userId, role);
      sendSuccess(res, result, result.message);
    } catch (err: any) {
      sendError(res, err.message, 400);
    }
  }

  // POST /api/v1/auctions/:id/relist (Seller or Admin)
  static async relistAuction(req: Request, res: Response): Promise<void> {
    try {
      const auctionId = parseInt(req.params.id, 10);
      const userId = req.user!.userId;
      const role = req.user!.role;
      const result = await AuctionService.relistAuction(auctionId, userId, role, req.body);
      sendSuccess(res, result, result.message);
    } catch (err: any) {
      sendError(res, err.message, 400);
    }
  }

  // POST /api/v1/auctions/:id/view
  static async incrementView(req: Request, res: Response): Promise<void> {
    try {
      const auctionId = parseInt(req.params.id, 10);
      await AuctionService.incrementViewCount(auctionId).catch(() => { });
      sendSuccess(res, { viewed: true });
    } catch {
      sendSuccess(res, { viewed: true });
    }
  }
}
