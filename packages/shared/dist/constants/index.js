"use strict";
// ============================================================
// NexusBids Platform Constants
// ============================================================
Object.defineProperty(exports, "__esModule", { value: true });
exports.ERROR_CODES = exports.TIMEZONE = exports.DEFAULT_BID_INCREMENT = exports.MAX_PAGE_LIMIT = exports.DEFAULT_PAGE_LIMIT = exports.REFRESH_TOKEN_EXPIRY = exports.ACCESS_TOKEN_EXPIRY = exports.ESCROW_AUTO_RELEASE_DAYS = exports.PLATFORM_COMMISSION_PERCENT = void 0;
exports.PLATFORM_COMMISSION_PERCENT = 5.00;
exports.ESCROW_AUTO_RELEASE_DAYS = 7;
exports.ACCESS_TOKEN_EXPIRY = '15m';
exports.REFRESH_TOKEN_EXPIRY = '7d';
exports.DEFAULT_PAGE_LIMIT = 12;
exports.MAX_PAGE_LIMIT = 50;
exports.DEFAULT_BID_INCREMENT = 500.00;
exports.TIMEZONE = 'Asia/Karachi';
exports.ERROR_CODES = {
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
};
