// ============================================================
// NexusBids Shared Domain Types
// ============================================================

export type UserRole = 'user' | 'seller' | 'admin';
export type UserStatus = 'active' | 'inactive' | 'blocked';
export type KYCStatus = 'unverified' | 'pending' | 'approved' | 'rejected';
export type SellerKYCStatus = 'unverified' | 'pending' | 'approved' | 'rejected';

export interface User {
  user_id: number;
  full_name: string;
  email: string;
  phone: string | null;
  bio: string | null;
  role: UserRole;
  profile_image: string;
  status: UserStatus;
  email_verified: boolean;
  kyc_status: KYCStatus;
  seller_kyc_status: SellerKYCStatus;
  google_id?: string | null;
  auth_provider?: string;
  bank_name?: string | null;
  bank_account?: string | null;
  bank_iban?: string | null;
  business_name?: string | null;
  business_address?: string | null;
  payout_method?: string | null;
  payout_account_title?: string | null;
  payout_account_number?: string | null;
  payout_bank_name?: string | null;
  created_at: string;
}

export type AuctionStatus =
  | 'draft'
  | 'pending'
  | 'approved'
  | 'rejected'
  | 'scheduled'
  | 'active'
  | 'ended'
  | 'completed'
  | 'sold'
  | 'unsold'
  | 'cancelled'
  | 'relisted';

export interface Auction {
  auction_id: number;
  seller_id: number;
  seller_name?: string;
  category_id: number;
  category_name?: string;
  title: string;
  description: string;
  starting_price: number;
  current_bid: number;
  reserve_price?: number | null;
  buy_now_price?: number | null;
  bid_increment: number;
  start_time: string;
  end_time: string;
  auction_status: AuctionStatus;
  image: string | null;
  images?: string[];
  view_count: number;
  watch_count: number;
  bid_count?: number;
  is_watched?: boolean;
  highest_bidder_id?: number | null;
  created_at: string;
}

export interface Bid {
  bid_id: number;
  auction_id: number;
  bidder_id: number;
  bidder_name?: string;
  bid_amount: number;
  bid_time: string;
  is_auto_bid: boolean;
  ip_address?: string | null;
  device_hash?: string | null;
}

export interface AutoBid {
  id: number;
  auction_id: number;
  user_id: number;
  max_amount: number;
  status: 'active' | 'paused' | 'outbid' | 'cancelled';
  created_at: string;
}

export interface Winner {
  winner_id: number;
  auction_id: number;
  user_id: number;
  winning_bid: number;
  status: 'pending_payment' | 'paid' | 'completed';
  created_at: string;
}

export type OrderStatus =
  | 'pending_payment'
  | 'paid'
  | 'processing'
  | 'shipped'
  | 'delivered'
  | 'completed'
  | 'cancelled'
  | 'refunded'
  | 'disputed';

export type PaymentStatus = 'unpaid' | 'pending' | 'paid' | 'failed' | 'refunded';

export type ShippingStatus =
  | 'not_started'
  | 'awaiting_address'
  | 'ready_to_ship'
  | 'shipped'
  | 'in_transit'
  | 'out_for_delivery'
  | 'delivered'
  | 'failed'
  | 'returned';

export interface Order {
  order_id: number;
  order_number: string;
  auction_id: number;
  auction_title?: string;
  auction_image?: string | null;
  winner_id: number;
  buyer_id: number;
  buyer_name?: string;
  seller_id: number;
  seller_name?: string;
  winning_bid: number;
  platform_fee: number;
  seller_amount: number;
  total_amount: number;
  order_status: OrderStatus;
  payment_status: PaymentStatus;
  shipping_status: ShippingStatus;
  shipping_address_id?: number | null;
  courier?: string | null;
  tracking_number?: string | null;
  tracking_url?: string | null;
  shipped_at?: string | null;
  delivered_at?: string | null;
  buyer_confirmed_at?: string | null;
  paid_at?: string | null;
  completed_at?: string | null;
  cancelled_at?: string | null;
  admin_review_status: 'pending' | 'reviewing' | 'approved' | 'rejected' | 'disputed';
  escrow_released_at?: string | null;
  created_at: string;
}

export type PaymentMethod = 'jazzcash' | 'easypaisa' | 'bank_transfer' | 'card' | 'cash_on_delivery';

export interface Payment {
  payment_id: number;
  auction_id: number;
  user_id: number;
  winner_id: number;
  order_id?: number | null;
  amount: number;
  sender_number?: string | null;
  sender_name?: string | null;
  payment_method: PaymentMethod;
  payment_status: 'pending' | 'processing' | 'paid' | 'failed' | 'refunded';
  refund_amount: number;
  transaction_id?: string | null;
  created_at: string;
}

export interface KYCVerification {
  kyc_id: number;
  user_id: number;
  user_name?: string;
  user_email?: string;
  kyc_scope: 'buyer' | 'seller' | 'both';
  full_name_on_cnic: string;
  cnic_number_masked: string;
  father_name?: string | null;
  date_of_birth: string;
  address?: string | null;
  phone?: string | null;
  cnic_front_path: string;
  cnic_back_path: string;
  selfie_path: string;
  selfie_with_cnic_path: string;
  status: 'pending' | 'under_review' | 'approved' | 'rejected';
  rejection_reason?: string | null;
  seller_type?: 'individual' | 'business';
  bank_name?: string | null;
  bank_account_holder?: string | null;
  bank_account_number?: string | null;
  bank_iban?: string | null;
  ai_status: 'pending' | 'processing' | 'completed' | 'failed';
  ai_confidence: number;
  ai_recommendation?: string | null;
  ai_face_match?: boolean | null;
  ai_extracted_data?: Record<string, any> | null;
  created_at: string;
  reviewed_at?: string | null;
}

export interface Notification {
  notification_id: number;
  user_id: number;
  type: string;
  title: string;
  message: string;
  link?: string | null;
  is_read: boolean;
  created_at: string;
}

// API Response Envelope
export interface ApiResponse<T = any> {
  success: boolean;
  data?: T;
  error?: string;
  message?: string;
  meta?: {
    page?: number;
    limit?: number;
    total?: number;
    totalPages?: number;
    hasNext?: boolean;
    hasPrev?: boolean;
  };
}

// Session Token Payload
export interface JwtPayload {
  userId: number;
  email: string;
  role: UserRole;
  fullName: string;
  kycStatus: KYCStatus;
  sellerKycStatus: SellerKYCStatus;
  type: 'access' | 'refresh';
}
