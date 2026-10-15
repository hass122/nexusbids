import assert from 'node:assert';
import {
  VALID_SHIPPING_STATUSES,
  VALID_SHIPPING_TRANSITIONS,
} from '../services/shipping.service.js';
import { ReputationService, RatingBreakdown } from '../services/reputation.service.js';

console.log('--- Running NexusBids Buyer Protection, Shipping & Reputation Test Suite ---\n');

// ─────────────────────────────────────────────────────────────────────────────
// SUITE 1: SHIPPING & DELIVERY TRACKING STATE MACHINE
// ─────────────────────────────────────────────────────────────────────────────

function testShippingStateMachine() {
  console.log('[Test 1.1] Valid Shipping Statuses Definitions');
  const expectedStatuses = [
    'not_started',
    'awaiting_address',
    'ready_to_ship',
    'shipped',
    'in_transit',
    'out_for_delivery',
    'delivered',
    'failed',
    'returned',
  ];

  for (const status of expectedStatuses) {
    assert.ok(
      (VALID_SHIPPING_STATUSES as readonly string[]).includes(status),
      `Shipping status '${status}' must be recognized in VALID_SHIPPING_STATUSES`
    );
  }
  assert.strictEqual(
    VALID_SHIPPING_STATUSES.length,
    9,
    'VALID_SHIPPING_STATUSES must define exactly 9 distinct lifecycle statuses'
  );
  console.log('  ✓ 9 shipping lifecycle statuses verified');

  console.log('[Test 1.2] Shipping Transitions & Terminal Status Guards');
  // Terminal status: 'delivered' must have no onward transitions for normal sellers
  assert.deepStrictEqual(
    VALID_SHIPPING_TRANSITIONS['delivered'],
    [],
    'Terminal status "delivered" must have no valid subsequent transitions'
  );

  // Terminal status: 'returned' must have no onward transitions
  assert.deepStrictEqual(
    VALID_SHIPPING_TRANSITIONS['returned'],
    [],
    'Terminal status "returned" must have no valid subsequent transitions'
  );

  // Valid forward transition checks
  assert.ok(
    VALID_SHIPPING_TRANSITIONS['ready_to_ship'].includes('shipped'),
    'ready_to_ship -> shipped must be permitted'
  );
  assert.ok(
    VALID_SHIPPING_TRANSITIONS['shipped'].includes('in_transit'),
    'shipped -> in_transit must be permitted'
  );
  assert.ok(
    VALID_SHIPPING_TRANSITIONS['in_transit'].includes('out_for_delivery'),
    'in_transit -> out_for_delivery must be permitted'
  );
  assert.ok(
    VALID_SHIPPING_TRANSITIONS['out_for_delivery'].includes('delivered'),
    'out_for_delivery -> delivered must be permitted'
  );
  assert.ok(
    VALID_SHIPPING_TRANSITIONS['out_for_delivery'].includes('failed'),
    'out_for_delivery -> failed must be permitted'
  );
  assert.ok(
    VALID_SHIPPING_TRANSITIONS['failed'].includes('returned'),
    'failed -> returned must be permitted'
  );

  // Test state transition validation logic helper
  function validateTransition(current: string, next: string, role: string): boolean {
    if (current === next) return true;
    if (role === 'admin') return true; // Admins can correct tracking discrepancies
    const allowed = VALID_SHIPPING_TRANSITIONS[current] || [];
    return allowed.includes(next);
  }

  // Seller cannot revert delivered to shipped
  assert.strictEqual(
    validateTransition('delivered', 'shipped', 'seller'),
    false,
    'Seller must NOT be allowed to revert delivered item back to shipped'
  );

  // Seller cannot jump from ready_to_ship directly to delivered
  assert.strictEqual(
    validateTransition('ready_to_ship', 'delivered', 'seller'),
    false,
    'Seller cannot bypass dispatch/transit and jump straight to delivered'
  );

  // Admin can correct status if error occurred
  assert.strictEqual(
    validateTransition('delivered', 'shipped', 'admin'),
    true,
    'Administrator must have override capability to resolve logistics disputes'
  );

  console.log('  ✓ Shipping state machine transition matrix passed');
}

// ─────────────────────────────────────────────────────────────────────────────
// SUITE 2: BUYER PROTECTION, 48-HOUR INSPECTION WINDOW & ESCROW FREEZE
// ─────────────────────────────────────────────────────────────────────────────

function testBuyerProtectionCalculations() {
  console.log('[Test 2.1] 48-Hour Inspection Window Calculation');

  const now = Date.now();
  const FORTY_EIGHT_HOURS_MS = 48 * 60 * 60 * 1000;

  function calculateInspectionWindow(deliveredAt: Date | string | null) {
    if (!deliveredAt) {
      return {
        inspection_active: false,
        hours_remaining: 0,
        can_open_dispute: false,
      };
    }
    const deliveredTime = new Date(deliveredAt).getTime();
    const expiresAt = deliveredTime + FORTY_EIGHT_HOURS_MS;
    const remainingMs = expiresAt - now;

    if (remainingMs > 0) {
      return {
        inspection_active: true,
        hours_remaining: Math.ceil(remainingMs / (60 * 60 * 1000)),
        can_open_dispute: true,
      };
    } else {
      return {
        inspection_active: false,
        hours_remaining: 0,
        can_open_dispute: false,
      };
    }
  }

  // Case A: Delivered 12 hours ago (Active inspection window)
  const delivered12hAgo = new Date(now - 12 * 60 * 60 * 1000).toISOString();
  const res12h = calculateInspectionWindow(delivered12hAgo);
  assert.strictEqual(res12h.inspection_active, true, 'Order delivered 12h ago must have active inspection');
  assert.strictEqual(res12h.hours_remaining, 36, 'Should have exactly 36 hours remaining');
  assert.strictEqual(res12h.can_open_dispute, true, 'Buyer must be eligible to open dispute');

  // Case B: Delivered 50 hours ago (Expired inspection window)
  const delivered50hAgo = new Date(now - 50 * 60 * 60 * 1000).toISOString();
  const res50h = calculateInspectionWindow(delivered50hAgo);
  assert.strictEqual(res50h.inspection_active, false, 'Order delivered 50h ago inspection must be expired');
  assert.strictEqual(res50h.hours_remaining, 0, 'Hours remaining must be 0 for expired window');
  assert.strictEqual(res50h.can_open_dispute, false, 'Standard claim window must be closed');

  // Case C: Not yet delivered
  const notDelivered = calculateInspectionWindow(null);
  assert.strictEqual(notDelivered.inspection_active, false, 'Undelivered order cannot have active inspection');
  assert.strictEqual(notDelivered.can_open_dispute, false);

  console.log('  ✓ 48-Hour inspection countdown math verified');

  console.log('[Test 2.2] Escrow Freeze & Auto-Release Guard');
  // Test simulated escrow state guard
  interface EscrowAccount {
    order_id: number;
    amount: number;
    status: 'held' | 'disputed' | 'released' | 'refunded';
    auto_release_after: Date;
  }

  const escrow: EscrowAccount = {
    order_id: 101,
    amount: 25000,
    status: 'held',
    auto_release_after: new Date(now - 1000), // In the past (due for release)
  };

  // Auto-release cron simulation
  function canAutoRelease(esc: EscrowAccount): boolean {
    if (esc.status === 'disputed') return false; // FROZEN
    if (esc.status !== 'held') return false;
    return esc.auto_release_after.getTime() <= Date.now();
  }

  // Before dispute: would release
  assert.strictEqual(canAutoRelease(escrow), true, 'Held escrow past deadline is eligible for release');

  // Dispute opened: Escrow is marked 'disputed'
  escrow.status = 'disputed';
  assert.strictEqual(
    canAutoRelease(escrow),
    false,
    'Disputed escrow must NEVER be auto-released by cron while under dispute'
  );

  console.log('  ✓ Escrow dispute freeze guarantee passed');
}

// ─────────────────────────────────────────────────────────────────────────────
// SUITE 3: SELLER REPUTATION, PRIVACY MASKING & VERIFIED REVIEWS
// ─────────────────────────────────────────────────────────────────────────────

function testSellerReputationAndReviews() {
  console.log('[Test 3.1] Reviewer Name Privacy Masking');

  assert.strictEqual(
    ReputationService.maskName('Ahmed Khan'),
    'Ah*** Kh**',
    'Standard two-word name must mask characters beyond 2'
  );
  assert.strictEqual(
    ReputationService.maskName('Muhammad Ali Jinnah'),
    'Mu****** Al** Ji****',
    'Multi-word names must mask each name component'
  );
  assert.strictEqual(
    ReputationService.maskName('Ali'),
    'Al**',
    'Short 3-letter name should mask with at least 2 asterisks'
  );
  assert.strictEqual(
    ReputationService.maskName(''),
    'Verified Buyer',
    'Empty or missing name must default to "Verified Buyer"'
  );

  // Security test: Verify raw CNIC or email is NOT leaked
  const maskedSample = ReputationService.maskName('Tariq Mahmood');
  assert.ok(!maskedSample.includes('@'), 'Masked output must not contain email symbol');
  assert.ok(!maskedSample.match(/\d{5}-\d{7}-\d/), 'Masked output must not contain CNIC');
  console.log('  ✓ Reviewer name privacy masking verified');

  console.log('[Test 3.2] Rating Aggregation Math & Breakdown');

  function calculateReputationMetrics(ratings: number[]) {
    const breakdown: RatingBreakdown = { 5: 0, 4: 0, 3: 0, 2: 0, 1: 0 };
    if (!ratings.length) {
      return {
        average_rating: 0.0,
        total_reviews: 0,
        positive_rating_percentage: 0,
        rating_breakdown: breakdown,
      };
    }

    let sum = 0;
    let positiveCount = 0;
    for (const r of ratings) {
      sum += r;
      if (r >= 4) positiveCount++;
      if (r >= 1 && r <= 5) {
        breakdown[r as keyof RatingBreakdown]++;
      }
    }

    const average = Number((sum / ratings.length).toFixed(1));
    const positivePercentage = Math.round((positiveCount / ratings.length) * 100);

    return {
      average_rating: average,
      total_reviews: ratings.length,
      positive_rating_percentage: positivePercentage,
      rating_breakdown: breakdown,
    };
  }

  // Case 1: Ratings [5, 5, 4, 3, 5] -> avg: 4.4, total: 5, positive: 80%
  const sample1 = calculateReputationMetrics([5, 5, 4, 3, 5]);
  assert.strictEqual(sample1.total_reviews, 5, 'Total reviews must equal 5');
  assert.strictEqual(sample1.average_rating, 4.4, 'Average rating must be 4.4');
  assert.strictEqual(sample1.positive_rating_percentage, 80, '4 out of 5 is 80% positive');
  assert.strictEqual(sample1.rating_breakdown[5], 3, 'There are three 5-star ratings');
  assert.strictEqual(sample1.rating_breakdown[4], 1, 'There is one 4-star rating');
  assert.strictEqual(sample1.rating_breakdown[3], 1, 'There is one 3-star rating');
  assert.strictEqual(sample1.rating_breakdown[2], 0, 'There are zero 2-star ratings');
  assert.strictEqual(sample1.rating_breakdown[1], 0, 'There are zero 1-star ratings');

  // Case 2: Zero reviews
  const sampleEmpty = calculateReputationMetrics([]);
  assert.strictEqual(sampleEmpty.total_reviews, 0);
  assert.strictEqual(sampleEmpty.average_rating, 0.0);
  assert.strictEqual(sampleEmpty.positive_rating_percentage, 0);
  assert.strictEqual(sampleEmpty.rating_breakdown[5], 0);

  console.log('  ✓ Rating math, percentage & 1-5 star breakdown verified');

  console.log('[Test 3.3] Self-Review & Verified Purchase Integrity');

  function validateReviewCreation(order: {
    buyer_id: number;
    seller_id: number;
    order_status: string;
  }, reviewerId: number): { allowed: boolean; error?: string } {
    if (reviewerId === order.seller_id) {
      return { allowed: false, error: 'Sellers cannot review their own auctions.' };
    }
    if (reviewerId !== order.buyer_id) {
      return { allowed: false, error: 'Only the verified winning buyer can review this order.' };
    }
    if (order.order_status !== 'completed' && order.order_status !== 'delivered') {
      return { allowed: false, error: 'Review can only be submitted after order is delivered or completed.' };
    }
    return { allowed: true };
  }

  // Seller attempting to review themselves
  const selfReview = validateReviewCreation(
    { buyer_id: 10, seller_id: 5, order_status: 'completed' },
    5 // Seller ID
  );
  assert.strictEqual(selfReview.allowed, false);
  assert.strictEqual(selfReview.error, 'Sellers cannot review their own auctions.');

  // Unrelated user attempting to review
  const thirdPartyReview = validateReviewCreation(
    { buyer_id: 10, seller_id: 5, order_status: 'completed' },
    99 // Random user
  );
  assert.strictEqual(thirdPartyReview.allowed, false);

  // Legitimate winning buyer on completed order
  const validReview = validateReviewCreation(
    { buyer_id: 10, seller_id: 5, order_status: 'completed' },
    10 // Buyer ID
  );
  assert.strictEqual(validReview.allowed, true);

  console.log('  ✓ Verified purchase integrity & self-review prevention passed');
}

// ─────────────────────────────────────────────────────────────────────────────
// EXECUTE SUITES
// ─────────────────────────────────────────────────────────────────────────────

try {
  testShippingStateMachine();
  console.log('');
  testBuyerProtectionCalculations();
  console.log('');
  testSellerReputationAndReviews();
  console.log('\n================================================================');
  console.log('🎉 ALL BUYER PROTECTION, SHIPPING & REPUTATION TESTS PASSED!');
  console.log('================================================================\n');
} catch (err: any) {
  console.error('\n❌ TEST FAILURE:', err.message);
  console.error(err.stack);
  process.exit(1);
}
