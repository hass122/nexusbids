// ============================================================
// NexusBids Platform Constants
// ============================================================

export const PLATFORM_COMMISSION_PERCENT = 5.00;
export const ESCROW_AUTO_RELEASE_DAYS = 7;
export const ACCESS_TOKEN_EXPIRY = '15m';
export const REFRESH_TOKEN_EXPIRY = '7d';
export const DEFAULT_PAGE_LIMIT = 12;
export const MAX_PAGE_LIMIT = 50;
export const DEFAULT_BID_INCREMENT = 500.00;
export const TIMEZONE = 'Asia/Karachi';

export const ERROR_CODES = {
  UNAUTHORIZED: 'UNAUTHORIZED',
  FORBIDDEN: 'FORBIDDEN',
  NOT_FOUND: 'NOT_FOUND',
  BAD_REQUEST: 'BAD_REQUEST',
  VALIDATION_ERROR: 'VALIDATION_ERROR',
  CONFLICT: 'CONFLICT',
  RATE_LIMITED: 'RATE_LIMITED',
  KYC_REQUIRED: 'KYC_REQUIRED',
  SELLER_KYC_REQUIRED: 'SELLER_KYC_REQUIRED',
  AUCTION_ENDED: 'AUCTION_ENDED',
  INVALID_BID: 'INVALID_BID',
  INTERNAL_ERROR: 'INTERNAL_ERROR',
} as const;
