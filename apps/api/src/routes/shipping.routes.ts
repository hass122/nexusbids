import { Router } from 'express';
import { requireAuth, requireSellerKyc } from '../middleware/auth.js';
import { sendSuccess, sendError } from '../utils/response.js';
import { ShippingService } from '../services/shipping.service.js';
import { MarketplaceController } from '../controllers/marketplace.controller.js';

const router = Router();

// ─── Addresses (Buyer) ────────────────────────────────────────
router.get('/addresses', requireAuth, async (req, res) => {
  try {
    const addresses = await ShippingService.getAddresses(req.user!.userId);
    sendSuccess(res, addresses);
  } catch (err: any) {
    sendError(res, err.message, 500);
  }
});

router.post('/addresses', requireAuth, async (req, res) => {
  try {
    const { full_name, phone, address, city, province, postal_code, is_default } = req.body;
    if (!full_name || !phone || !address || !city) {
      sendError(res, 'Full name, phone, address, and city are required', 400);
      return;
    }
    const result = await ShippingService.addAddress(req.user!.userId, {
      full_name,
      phone,
      address,
      city,
      province,
      postal_code,
      is_default,
    });
    sendSuccess(res, result, 'Address added successfully', 201);
  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});

router.patch('/addresses/:id/default', requireAuth, async (req, res) => {
  try {
    const addressId = parseInt(req.params.id, 10);
    const result = await ShippingService.setDefaultAddress(req.user!.userId, addressId);
    sendSuccess(res, result, 'Default address updated');
  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});

router.delete('/addresses/:id', requireAuth, async (req, res) => {
  try {
    const addressId = parseInt(req.params.id, 10);
    const result = await ShippingService.deleteAddress(req.user!.userId, addressId);
    sendSuccess(res, result, 'Address deleted');
  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});

// ─── Shipments (Seller) ───────────────────────────────────────
// ✅ FIX: Added requireSellerKyc to ensure only verified sellers can create shipments
router.post('/orders/:orderId/ship', requireAuth, requireSellerKyc, async (req, res) => {
  try {
    const orderId = parseInt(req.params.orderId, 10);
    const { courier, tracking_number, estimated_delivery, note } = req.body;

    if (!courier || !tracking_number) {
      sendError(res, 'Courier name and tracking number are required', 400);
      return;
    }

    const result = await ShippingService.createShipment(req.user!.userId, {
      order_id: orderId,
      courier,
      tracking_number,
      estimated_delivery,
      note,
    });

    sendSuccess(res, result, 'Shipment created and order marked as shipped', 201);
  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});

// ─── Tracking (Buyer, Seller, Admin) ──────────────────────────
router.get('/orders/:orderId/tracking', requireAuth, async (req, res) => {
  try {
    const orderId = parseInt(req.params.orderId, 10);
    const result = await ShippingService.getTracking(orderId, req.user!.userId, req.user!.role);
    sendSuccess(res, result);
  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});

router.post('/orders/:orderId/tracking', requireAuth, async (req, res) => {
  try {
    const orderId = parseInt(req.params.orderId, 10);
    const { status, location, note } = req.body;

    if (!status) {
      sendError(res, 'Tracking status is required', 400);
      return;
    }

    const result = await ShippingService.addTrackingEvent(
      req.user!.userId,
      req.user!.role,
      orderId,
      status,
      location,
      note
    );

    sendSuccess(res, result, 'Tracking event added');
  } catch (err: any) {
    sendError(res, err.message, 400);
  }
});

// ─── Delivery Confirmation (Buyer) ──────────────────────────
router.post('/orders/:id/confirm-delivery', requireAuth, MarketplaceController.confirmReceipt);

export default router;
