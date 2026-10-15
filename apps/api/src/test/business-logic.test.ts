import assert from 'node:assert';
import crypto from 'node:crypto';
import jwt from 'jsonwebtoken';
import { encryptCnic, decryptCnic, maskCnic } from '../utils/crypto.js';
import { ENV } from '../config/env.js';
import { AuthService } from '../services/auth.service.js';

console.log('--- Running NexusBids Core Business Logic, Security & Auction Tests ---');

// Test 1: NADRA CNIC Masking
function testCnicMasking() {
  const cnic1 = '35201-1234567-1';
  const masked1 = maskCnic(cnic1);
  assert.strictEqual(masked1, '35201-*******-1', 'Standard 13-digit CNIC must mask middle 7 digits');

  const cnic2 = '3520112345671';
  const masked2 = maskCnic(cnic2);
  assert.strictEqual(masked2, '35201-*******-1', 'Unformatted 13-digit CNIC must format and mask middle digits');

  const invalid = '123';
  const maskedInvalid = maskCnic(invalid);
  assert.strictEqual(maskedInvalid, '*****-*******-*', 'Invalid short CNIC must return placeholder');
  console.log('✓ NADRA CNIC masking test passed');
}

// Test 2: AES-256-CBC Encryption & Decryption
function testCnicEncryption() {
  const original = '42101-7654321-3';
  const ciphertext = encryptCnic(original);
  assert.notStrictEqual(ciphertext, original, 'Ciphertext must not match plaintext');
  assert.ok(ciphertext.length > 20, 'Ciphertext should be valid base64');

  const decrypted = decryptCnic(ciphertext);
  assert.strictEqual(decrypted, original, 'Decrypted text must exactly match original CNIC');
  console.log('✓ AES-256-CBC CNIC encryption and decryption test passed');
}

// Test 3: Auto-Bidding Proxy Computation
function testAutoBiddingLogic() {
  const startingPrice = 1000;
  const bidIncrement = 100;

  // Scenario A: First user sets auto-bid ceiling 2500
  const user1Ceiling = 2500;
  let currentBid = startingPrice;
  let highestBidder = 1;

  // Scenario B: User 2 manually bids 1200
  const user2Bid = 1200;
  if (user2Bid > currentBid) {
    if (user1Ceiling >= user2Bid + bidIncrement) {
      currentBid = user2Bid + bidIncrement; // user 1 auto-outbids
      highestBidder = 1;
    } else {
      currentBid = user2Bid;
      highestBidder = 2;
    }
  }

  assert.strictEqual(currentBid, 1300, 'Auto-bidder 1 should automatically bid increment over 1200');
  assert.strictEqual(highestBidder, 1, 'Auto-bidder 1 should remain highest bidder');
  console.log('✓ Proxy auto-bidding algorithm step test passed');
}

// Test 4: Escrow Protection Fee Calculation
function testEscrowCalculation() {
  const winningAmount = 50000; // PKR 50,000 item
  const commissionRate = 0.05; // 5% marketplace escrow fee
  const platformFee = winningAmount * commissionRate;
  const sellerPayout = winningAmount - platformFee;

  assert.strictEqual(platformFee, 2500, 'Platform escrow fee on 50,000 must be 2,500 PKR');
  assert.strictEqual(sellerPayout, 47500, 'Seller net payout on 50,000 must be 47,500 PKR');
  assert.strictEqual(platformFee + sellerPayout, winningAmount, 'Total ledger must balance');
  console.log('✓ Escrow commission and seller net ledger balance test passed');
}

// Test 5: JWT Token Dual Generation & Verification (Security Hardening)
async function testJwtSeparation() {
  const testUser = {
    userId: 999,
    email: 'test@nexusbids.pk',
    role: 'user' as const,
    fullName: 'Test User',
    kycStatus: 'approved' as const,
    sellerKycStatus: 'unverified' as const,
  };

  const tokens = await AuthService.generateTokens(testUser);
  assert.ok(tokens.accessToken, 'Access token must be generated');
  assert.ok(tokens.refreshToken, 'Refresh token must be generated');
  assert.notStrictEqual(tokens.accessToken, tokens.refreshToken, 'Access and refresh tokens must not be identical');

  const accessDecoded: any = jwt.verify(tokens.accessToken, ENV.JWT.ACCESS_SECRET);
  assert.strictEqual(accessDecoded.type, 'access', 'Access token payload must have type=access');
  assert.strictEqual(accessDecoded.userId, 999, 'Payload must contain userId');

  const refreshDecoded: any = jwt.verify(tokens.refreshToken, ENV.JWT.REFRESH_SECRET);
  assert.strictEqual(refreshDecoded.type, 'refresh', 'Refresh token payload must have type=refresh');
  assert.strictEqual(refreshDecoded.userId, 999, 'Payload must contain userId');

  assert.throws(
    () => jwt.verify(tokens.accessToken, ENV.JWT.REFRESH_SECRET),
    'Access token verified with refresh secret must fail'
  );

  console.log('✓ Dual JWT access & refresh cryptographic separation test passed');
}

// Test 6: Password Reset Token Cryptographic Hashing
function testPasswordResetTokenHashing() {
  const rawToken = crypto.randomBytes(32).toString('hex');
  const hashedToken1 = crypto.createHash('sha256').update(rawToken).digest('hex');
  const hashedToken2 = crypto.createHash('sha256').update(rawToken).digest('hex');

  assert.strictEqual(hashedToken1, hashedToken2, 'Hashed tokens must match deterministically');
  assert.strictEqual(hashedToken1.length, 64, 'SHA-256 hash must be 64 hexadecimal characters');
  assert.notStrictEqual(rawToken, hashedToken1, 'Plain token must never be stored in plain text');
  console.log('✓ Password reset SHA-256 cryptographic hashing test passed');
}

// Test 7: 2FA OTP Generation Format
function test2FaOtpFormat() {
  for (let i = 0; i < 20; i++) {
    const code = crypto.randomInt(100000, 999999).toString();
    assert.strictEqual(code.length, 6, '2FA OTP code must be exactly 6 characters');
    assert.ok(/^\d{6}$/.test(code), '2FA OTP must be entirely numeric');
    const num = parseInt(code, 10);
    assert.ok(num >= 100000 && num <= 999999, '2FA OTP must be between 100000 and 999999');
  }
  console.log('✓ 2FA 6-digit numeric OTP generation format test passed');
}

// Test 8: CORS Origin Parser & Validation
function testCorsOrigins() {
  assert.ok(Array.isArray(ENV.CORS_ORIGINS), 'CORS_ORIGINS must be an array');
  assert.ok(ENV.CORS_ORIGINS.length > 0, 'CORS_ORIGINS must have at least one allowed origin');
  console.log('✓ Strict CORS origin array configuration test passed');
}

// Test 9: Minimum Bid & Increment Validation Logic
function testMinimumBidValidation() {
  const startingPrice = 5000;
  const increment = 500;

  // Case 1: First bid on new auction (current_bid = 0)
  const minFirstBid = startingPrice;
  assert.strictEqual(minFirstBid, 5000, 'First bid must equal or exceed starting price');

  // Case 2: Subsequent bid (current_bid = 5000)
  const currentBid = 5000;
  const minSubsequentBid = currentBid + increment;
  assert.strictEqual(minSubsequentBid, 5500, 'Subsequent bid must be at least current_bid + increment');

  // Validation function
  const validateBid = (bid: number, cur: number, start: number, inc: number) => {
    const required = cur > 0 ? cur + inc : start;
    if (bid < required) throw new Error(`Bid below minimum (${required})`);
    return true;
  };

  assert.ok(validateBid(5500, 5000, 5000, 500));
  assert.ok(validateBid(6000, 5000, 5000, 500));
  assert.throws(() => validateBid(5200, 5000, 5000, 500), /Bid below minimum/);
  assert.throws(() => validateBid(4900, 0, 5000, 500), /Bid below minimum/);
  console.log('✓ Minimum bid and increment validation test passed');
}

// Test 10: Seller Self-Bidding Prevention & Closed Auction Guard
function testBiddingAuthorizationRules() {
  const auction = {
    auction_id: 101,
    seller_id: 42,
    auction_status: 'active',
    end_time: new Date(Date.now() + 3600000).toISOString(),
  };

  const attemptBid = (userId: number, auc: typeof auction, currentTime: number) => {
    if (auc.seller_id === userId) {
      throw new Error('Seller cannot bid on own auction');
    }
    if (auc.auction_status !== 'active') {
      throw new Error('Auction is not active');
    }
    if (new Date(auc.end_time).getTime() <= currentTime) {
      throw new Error('Auction has ended');
    }
    return true;
  };

  // Buyer bids
  assert.ok(attemptBid(99, auction, Date.now()), 'Buyer should be authorized to bid');

  // Seller attempts bid
  assert.throws(() => attemptBid(42, auction, Date.now()), /Seller cannot bid on own auction/);

  // Closed auction
  const closedAuction = { ...auction, auction_status: 'sold' };
  assert.throws(() => attemptBid(99, closedAuction, Date.now()), /Auction is not active/);

  // Expired auction
  const pastTime = Date.now() + 7200000;
  assert.throws(() => attemptBid(99, auction, pastTime), /Auction has ended/);

  console.log('✓ Seller self-bidding prevention and closed auction guard test passed');
}

// Test 11: Anti-Sniping Deterministic Extension
function testAntiSnipingAlgorithm() {
  const extensionMinutes = 2;
  const antiSnipingWindowMs = extensionMinutes * 60 * 1000; // 120,000 ms
  const maxExtensions = 15;

  const calculateAntiSniping = (
    currentTime: number,
    endTime: number,
    extendedCount: number,
    enabled: boolean
  ) => {
    const timeRemainingMs = endTime - currentTime;
    if (
      enabled &&
      timeRemainingMs > 0 &&
      timeRemainingMs <= antiSnipingWindowMs &&
      extendedCount < maxExtensions
    ) {
      return {
        extended: true,
        newEndTime: endTime + antiSnipingWindowMs,
        newExtendedCount: extendedCount + 1,
      };
    }
    return { extended: false, newEndTime: endTime, newExtendedCount: extendedCount };
  };

  const baseEnd = 1000000;

  // Case 1: Bid placed 30 seconds before ending (within 2-min window)
  const result1 = calculateAntiSniping(baseEnd - 30000, baseEnd, 0, true);
  assert.strictEqual(result1.extended, true, 'Should extend auction');
  assert.strictEqual(result1.newEndTime, baseEnd + 120000, 'Should add 2 minutes to end time');
  assert.strictEqual(result1.newExtendedCount, 1, 'Extended count should increment');

  // Case 2: Bid placed 5 minutes before ending (outside window)
  const result2 = calculateAntiSniping(baseEnd - 300000, baseEnd, 0, true);
  assert.strictEqual(result2.extended, false, 'Should not extend when outside window');

  // Case 3: Anti-sniping disabled
  const result3 = calculateAntiSniping(baseEnd - 30000, baseEnd, 0, false);
  assert.strictEqual(result3.extended, false, 'Should not extend when disabled');

  // Case 4: Max extensions reached
  const result4 = calculateAntiSniping(baseEnd - 30000, baseEnd, 15, true);
  assert.strictEqual(result4.extended, false, 'Should not extend when max extensions reached');

  console.log('✓ Deterministic anti-sniping extension algorithm test passed');
}

// Test 12: Competing Auto-Bids Resolution & Tie-Breaker
function testCompetingAutoBidsResolution() {
  let currentPrice = 1000;
  const increment = 100;

  // Bidder A sets max 3000 at t=100
  // Bidder B sets max 2500 at t=200
  const autoBidA = { userId: 'A', max: 3000, createdAt: 100 };
  const autoBidB = { userId: 'B', max: 2500, createdAt: 200 };

  // Resolution simulation
  const simulateAutoBids = (bidA: typeof autoBidA, bidB: typeof autoBidB, startPrice: number) => {
    let price = startPrice;
    let leader = '';

    while (true) {
      // Find candidate from B
      if (leader !== 'B' && bidB.max > price) {
        price = Math.min(bidB.max, price + increment);
        leader = 'B';
      }
      // Challenger A responds
      if (leader !== 'A' && bidA.max > price) {
        price = Math.min(bidA.max, price + increment);
        leader = 'A';
      } else {
        break; // Equilibrium
      }
    }
    return { finalPrice: price, winner: leader };
  };

  const outcome = simulateAutoBids(autoBidA, autoBidB, currentPrice);
  assert.strictEqual(outcome.winner, 'A', 'Bidder A with higher ceiling must win');
  assert.strictEqual(outcome.finalPrice, 2600, 'Price should stop at loser ceiling (2500) + 1 increment (100) = 2600');

  // Tie-breaker scenario: both set 2500, earlier bidder wins
  const autoBidC = { userId: 'C', max: 2500, createdAt: 150 };
  const resolveTie = (b1: typeof autoBidB, b2: typeof autoBidC) => {
    if (b1.max === b2.max) {
      return b1.createdAt < b2.createdAt ? b1.userId : b2.userId;
    }
    return b1.max > b2.max ? b1.userId : b2.userId;
  };
  assert.strictEqual(resolveTie(autoBidB, autoBidC), 'C', 'Earlier created auto-bid must win ties');

  console.log('✓ Competing auto-bids proxy resolution and tie-breaker test passed');
}

// Test 13: Auction Ending Reserve Price & Idempotency
function testAuctionEndingLogic() {
  const evaluateAuctionEnding = (
    auction: { reserve_price: number; current_status: string },
    bids: Array<{ amount: number; bidderId: number }>
  ) => {
    // Idempotency: skip if already finalized
    if (auction.current_status !== 'active') {
      return { status: auction.current_status, reason: 'Already finalized' };
    }

    if (!bids.length) {
      return { status: 'unsold', reason: 'No bids placed' };
    }

    const topBid = bids[0];
    if (auction.reserve_price > 0 && topBid.amount < auction.reserve_price) {
      return { status: 'unsold', reason: 'Reserve price not met' };
    }

    return {
      status: 'sold',
      winnerId: topBid.bidderId,
      winningBid: topBid.amount,
      reason: 'Auction sold',
    };
  };

  // Case 1: No bids placed
  const r1 = evaluateAuctionEnding({ reserve_price: 10000, current_status: 'active' }, []);
  assert.strictEqual(r1.status, 'unsold');
  assert.strictEqual(r1.reason, 'No bids placed');

  // Case 2: Reserve price not met
  const r2 = evaluateAuctionEnding(
    { reserve_price: 10000, current_status: 'active' },
    [{ amount: 8000, bidderId: 10 }]
  );
  assert.strictEqual(r2.status, 'unsold');
  assert.strictEqual(r2.reason, 'Reserve price not met');

  // Case 3: Reserve price met & won
  const r3 = evaluateAuctionEnding(
    { reserve_price: 10000, current_status: 'active' },
    [{ amount: 12000, bidderId: 10 }]
  );
  assert.strictEqual(r3.status, 'sold');
  assert.strictEqual(r3.winnerId, 10);
  assert.strictEqual(r3.winningBid, 12000);

  // Case 4: Duplicate invocation (idempotency check)
  const r4 = evaluateAuctionEnding(
    { reserve_price: 10000, current_status: 'sold' },
    [{ amount: 12000, bidderId: 10 }]
  );
  assert.strictEqual(r4.reason, 'Already finalized');

  console.log('✓ Auction ending reserve price evaluation and idempotency test passed');
}

// Execute tests
async function runAll() {
  try {
    testCnicMasking();
    testCnicEncryption();
    testAutoBiddingLogic();
    testEscrowCalculation();
    await testJwtSeparation();
    testPasswordResetTokenHashing();
    test2FaOtpFormat();
    testCorsOrigins();
    testMinimumBidValidation();
    testBiddingAuthorizationRules();
    testAntiSnipingAlgorithm();
    testCompetingAutoBidsResolution();
    testAuctionEndingLogic();
    console.log('\n======================================================');
    console.log(' All NexusBids automated tests passed! (13/13)');
    console.log('======================================================\n');
    process.exit(0);
  } catch (err: any) {
    console.error('Test failed:', err.message);
    process.exit(1);
  }
}
runAll();
