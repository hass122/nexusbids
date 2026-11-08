"use strict";
Object.defineProperty(exports, "__esModule", { value: true });
exports.aiDescriptionSchema = exports.aiChatSchema = exports.disputeSchema = exports.reviewSchema = exports.shipOrderSchema = exports.initiatePaymentSchema = exports.updateAuctionSchema = exports.createAuctionSchema = exports.setAutoBidSchema = exports.placeBidSchema = exports.resetPasswordSchema = exports.forgotPasswordSchema = exports.verifyOtpSchema = exports.loginSchema = exports.registerSchema = void 0;
const zod_1 = require("zod");
// ============================================================
// Auth Schemas
// ============================================================
exports.registerSchema = zod_1.z.object({
    full_name: zod_1.z.string().min(3, 'Full name must be at least 3 characters').max(100),
    email: zod_1.z.string().email('Invalid email address').max(100),
    phone: zod_1.z.string().max(20).optional(),
    password: zod_1.z.string().min(6, 'Password must be at least 6 characters').max(100),
    role: zod_1.z.enum(['user', 'seller']).default('user'),
});
exports.loginSchema = zod_1.z.object({
    email: zod_1.z.string().email('Invalid email address'),
    password: zod_1.z.string().min(1, 'Password is required'),
});
exports.verifyOtpSchema = zod_1.z.object({
    user_id: zod_1.z.number().int().positive(),
    code: zod_1.z.string().length(6, 'Verification code must be 6 digits'),
});
exports.forgotPasswordSchema = zod_1.z.object({
    email: zod_1.z.string().email('Invalid email address'),
});
exports.resetPasswordSchema = zod_1.z.object({
    token: zod_1.z.string().min(1, 'Reset token is required'),
    password: zod_1.z.string().min(6, 'Password must be at least 6 characters'),
});
// ============================================================
// Auction & Bidding Schemas
// ============================================================
exports.placeBidSchema = zod_1.z.object({
    bid_amount: zod_1.z.number().positive('Bid amount must be greater than zero'),
});
exports.setAutoBidSchema = zod_1.z.object({
    max_amount: zod_1.z.number().positive('Maximum auto-bid amount must be greater than zero'),
});
exports.createAuctionSchema = zod_1.z.object({
    title: zod_1.z.string().min(3, 'Title is required').max(120),
    category_id: zod_1.z.number().int().positive('Category is required'),
    description: zod_1.z.string().min(10, 'Description must be at least 10 characters').max(2000),
    starting_price: zod_1.z.number().positive('Starting price must be greater than 0'),
    bid_increment: zod_1.z.number().positive('Bid increment must be greater than 0').default(500),
    start_time: zod_1.z.string().datetime({ offset: true }).or(zod_1.z.string().regex(/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/)),
    end_time: zod_1.z.string().datetime({ offset: true }).or(zod_1.z.string().regex(/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/)),
    action: zod_1.z.enum(['submit', 'draft']).default('submit'),
});
exports.updateAuctionSchema = exports.createAuctionSchema.partial();
// ============================================================
// Payment & Order Schemas
// ============================================================
exports.initiatePaymentSchema = zod_1.z.object({
    order_id: zod_1.z.number().int().positive(),
    payment_method: zod_1.z.enum(['jazzcash', 'easypaisa', 'bank_transfer', 'cash_on_delivery']),
    sender_number: zod_1.z.string().max(30).optional(),
    sender_name: zod_1.z.string().max(50).optional(),
    notes: zod_1.z.string().max(255).optional(),
});
exports.shipOrderSchema = zod_1.z.object({
    courier: zod_1.z.string().min(2, 'Courier name is required').max(100),
    tracking_number: zod_1.z.string().min(3, 'Tracking number is required').max(100),
    tracking_url: zod_1.z.string().url().max(500).optional(),
    notes: zod_1.z.string().max(500).optional(),
});
exports.reviewSchema = zod_1.z.object({
    order_id: zod_1.z.number().int().positive(),
    rating: zod_1.z.number().int().min(1).max(5),
    review_text: zod_1.z.string().min(3).max(1000).optional(),
    comment: zod_1.z.string().min(3).max(1000).optional(),
    seller_id: zod_1.z.number().int().positive().optional(),
    auction_id: zod_1.z.number().int().positive().optional(),
}).refine(data => !!(data.review_text || data.comment), {
    message: 'Review text or comment is required',
});
exports.disputeSchema = zod_1.z.object({
    order_id: zod_1.z.number().int().positive(),
    reason: zod_1.z.string().min(5, 'Reason is required').max(150),
    description: zod_1.z.string().min(10, 'Description must provide sufficient detail').max(2000),
    dispute_type: zod_1.z.enum(['chargeback', 'item_not_received', 'item_not_as_described', 'unauthorized', 'other']).default('other'),
});
// ============================================================
// AI Schemas
// ============================================================
exports.aiChatSchema = zod_1.z.object({
    message: zod_1.z.string().min(1, 'Message cannot be empty').max(1000),
    action: zod_1.z.enum(['send', 'clear', 'ping']).default('send'),
});
exports.aiDescriptionSchema = zod_1.z.object({
    title: zod_1.z.string().min(3).max(120),
    category: zod_1.z.string().max(100).optional(),
    condition: zod_1.z.string().max(200).optional(),
    features: zod_1.z.string().max(1500).optional(),
});
