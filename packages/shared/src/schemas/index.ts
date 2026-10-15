import { z } from 'zod';

// ============================================================
// Auth Schemas
// ============================================================

export const registerSchema = z.object({
  full_name: z.string().min(3, 'Full name must be at least 3 characters').max(100),
  email: z.string().email('Invalid email address').max(100),
  phone: z.string().max(20).optional(),
  password: z.string().min(6, 'Password must be at least 6 characters').max(100),
  role: z.enum(['user', 'seller']).default('user'),
});

export const loginSchema = z.object({
  email: z.string().email('Invalid email address'),
  password: z.string().min(1, 'Password is required'),
});

export const verifyOtpSchema = z.object({
  user_id: z.number().int().positive(),
  code: z.string().length(6, 'Verification code must be 6 digits'),
});

export const forgotPasswordSchema = z.object({
  email: z.string().email('Invalid email address'),
});

export const resetPasswordSchema = z.object({
  token: z.string().min(1, 'Reset token is required'),
  password: z.string().min(6, 'Password must be at least 6 characters'),
});

// ============================================================
// Auction & Bidding Schemas
// ============================================================

export const placeBidSchema = z.object({
  bid_amount: z.number().positive('Bid amount must be greater than zero'),
});

export const setAutoBidSchema = z.object({
  max_amount: z.number().positive('Maximum auto-bid amount must be greater than zero'),
});

export const createAuctionSchema = z.object({
  title: z.string().min(3, 'Title is required').max(120),
  category_id: z.number().int().positive('Category is required'),
  description: z.string().min(10, 'Description must be at least 10 characters').max(2000),
  starting_price: z.number().positive('Starting price must be greater than 0'),
  bid_increment: z.number().positive('Bid increment must be greater than 0').default(500),
  start_time: z.string().datetime({ offset: true }).or(z.string().regex(/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/)),
  end_time: z.string().datetime({ offset: true }).or(z.string().regex(/^\d{4}-\d{2}-\d{2}[ T]\d{2}:\d{2}(:\d{2})?$/)),
  action: z.enum(['submit', 'draft']).default('submit'),
});

export const updateAuctionSchema = createAuctionSchema.partial();

// ============================================================
// Payment & Order Schemas
// ============================================================

export const initiatePaymentSchema = z.object({
  order_id: z.number().int().positive(),
  payment_method: z.enum(['jazzcash', 'easypaisa', 'bank_transfer', 'cash_on_delivery']),
  sender_number: z.string().max(30).optional(),
  sender_name: z.string().max(50).optional(),
  notes: z.string().max(255).optional(),
});

export const shipOrderSchema = z.object({
  courier: z.string().min(2, 'Courier name is required').max(100),
  tracking_number: z.string().min(3, 'Tracking number is required').max(100),
  tracking_url: z.string().url().max(500).optional(),
  notes: z.string().max(500).optional(),
});

export const reviewSchema = z.object({
  order_id: z.number().int().positive(),
  rating: z.number().int().min(1).max(5),
  review_text: z.string().min(3).max(1000).optional(),
  comment: z.string().min(3).max(1000).optional(),
  seller_id: z.number().int().positive().optional(),
  auction_id: z.number().int().positive().optional(),
}).refine(data => !!(data.review_text || data.comment), {
  message: 'Review text or comment is required',
});

export const disputeSchema = z.object({
  order_id: z.number().int().positive(),
  reason: z.string().min(5, 'Reason is required').max(150),
  description: z.string().min(10, 'Description must provide sufficient detail').max(2000),
  dispute_type: z.enum(['chargeback', 'item_not_received', 'item_not_as_described', 'unauthorized', 'other']).default('other'),
});

// ============================================================
// AI Schemas
// ============================================================

export const aiChatSchema = z.object({
  message: z.string().min(1, 'Message cannot be empty').max(1000),
  action: z.enum(['send', 'clear', 'ping']).default('send'),
});

export const aiDescriptionSchema = z.object({
  title: z.string().min(3).max(120),
  category: z.string().max(100).optional(),
  condition: z.string().max(200).optional(),
  features: z.string().max(1500).optional(),
});
