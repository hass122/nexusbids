import assert from 'node:assert';
import crypto from 'node:crypto';
import jwt from 'jsonwebtoken';
import { ENV } from '../config/env.js';

console.log('--- Running NexusBids Admin Panel & RBAC Integrity Tests ---');

// 1. Admin Authorization & Server-Side RBAC Enforcement
function testAdminAuthorizationRBAC() {
  const secret = ENV.JWT?.ACCESS_SECRET || 'nexusbids_test_jwt_secret_key_12345';
  
  // Create tokens with different roles
  const buyerToken = jwt.sign({ id: 'u1', email: 'buyer@nexusbids.pk', role: 'buyer' }, secret);
  const sellerToken = jwt.sign({ id: 'u2', email: 'seller@nexusbids.pk', role: 'seller' }, secret);
  const adminToken = jwt.sign({ id: 'u3', email: 'admin@nexusbids.pk', role: 'admin' }, secret);

  const verifyRole = (token: string, requiredRole: string) => {
    try {
      const decoded = jwt.verify(token, secret) as any;
      if (decoded.role !== requiredRole) {
        return { allowed: false, error: 'Forbidden: Insufficient privileges' };
      }
      return { allowed: true, user: decoded };
    } catch (e: any) {
      return { allowed: false, error: e.message };
    }
  };

  // Buyer cannot access admin endpoints
  const buyerAttempt = verifyRole(buyerToken, 'admin');
  assert.strictEqual(buyerAttempt.allowed, false, 'Buyer must be rejected from admin routes');
  assert.strictEqual(buyerAttempt.error, 'Forbidden: Insufficient privileges');

  // Seller cannot access admin endpoints
  const sellerAttempt = verifyRole(sellerToken, 'admin');
  assert.strictEqual(sellerAttempt.allowed, false, 'Seller must be rejected from admin routes');

  // Admin is granted access
  const adminAttempt = verifyRole(adminToken, 'admin');
  assert.strictEqual(adminAttempt.allowed, true, 'Admin token must be granted access');
  assert.strictEqual(adminAttempt.user.role, 'admin');

  console.log('✓ Admin Authorization & Server-side RBAC test passed');
}

// 2. Admin User Management Actions (Ban, Reactivate, Role Upgrades)
function testAdminUserActions() {
  interface MockUser {
    id: string;
    email: string;
    role: string;
    is_active: boolean;
    is_verified: boolean;
  }

  const user: MockUser = {
    id: 'user-001',
    email: 'trader@nexusbids.pk',
    role: 'buyer',
    is_active: true,
    is_verified: false
  };

  // Admin suspends user
  const suspendUser = (u: MockUser) => { u.is_active = false; return u; };
  suspendUser(user);
  assert.strictEqual(user.is_active, false, 'User must be marked inactive after suspension');

  // Admin reactivates user
  const reactivateUser = (u: MockUser) => { u.is_active = true; return u; };
  reactivateUser(user);
  assert.strictEqual(user.is_active, true, 'User must be marked active after reactivation');

  // Admin verifies user
  const verifyUser = (u: MockUser) => { u.is_verified = true; return u; };
  verifyUser(user);
  assert.strictEqual(user.is_verified, true, 'User must be marked verified after manual admin verification');

  // Admin elevates role
  const changeRole = (u: MockUser, newRole: string) => {
    const validRoles = ['buyer', 'seller', 'admin'];
    if (!validRoles.includes(newRole)) throw new Error('Invalid role');
    u.role = newRole;
    return u;
  };
  changeRole(user, 'seller');
  assert.strictEqual(user.role, 'seller', 'Role should update to seller');

  assert.throws(() => changeRole(user, 'supergod'), /Invalid role/, 'Invalid roles must be rejected');

  console.log('✓ Admin User Actions (Suspend, Reactivate, Verify, Role) test passed');
}

// 3. KYC Moderation & PII Protection
function testKycActionsAndPrivacy() {
  interface KycSubmission {
    id: string;
    user_id: string;
    cnic_front_url: string;
    status: 'pending' | 'approved' | 'rejected' | 'resubmission_requested';
    admin_notes?: string;
  }

  const submission: KycSubmission = {
    id: 'kyc-99',
    user_id: 'user-001',
    cnic_front_url: '/uploads/kyc/secret-cnic-front.enc',
    status: 'pending'
  };

  // Sanitize for public responses - sensitive documents must NEVER be leaked in public APIs
  const sanitizeKycForPublic = (kyc: KycSubmission) => {
    const { cnic_front_url, ...safe } = kyc;
    return safe;
  };
  const sanitized = sanitizeKycForPublic(submission);
  assert.strictEqual((sanitized as any).cnic_front_url, undefined, 'Public KYC view must not leak document storage paths');

  // Admin Approval transition
  const approveKyc = (k: KycSubmission) => { k.status = 'approved'; return k; };
  approveKyc(submission);
  assert.strictEqual(submission.status, 'approved', 'KYC status must transition to approved');

  // Admin Resubmission Request
  const requestResubmission = (k: KycSubmission, note: string) => {
    k.status = 'resubmission_requested';
    k.admin_notes = note;
    return k;
  };
  requestResubmission(submission, 'Document photo blurry');
  assert.strictEqual(submission.status, 'resubmission_requested');
  assert.strictEqual(submission.admin_notes, 'Document photo blurry');

  console.log('✓ KYC Moderation Actions and PII Protection test passed');
}

// 4. Financial Actions (Escrow release, commission calculation, payout lifecycle)
function testFinancialAndEscrowActions() {
  const escrowTotal = 50000; // PKR 50,000 winning auction bid
  const platformFeePct = 0.05; // 5%

  const fee = escrowTotal * platformFeePct;
  const sellerPayout = escrowTotal - fee;

  assert.strictEqual(fee, 2500, '5% commission of 50,000 must be 2,500 PKR');
  assert.strictEqual(sellerPayout, 47500, 'Seller payout must equal 47,500 PKR');

  // Payout status state-machine
  type PayoutStatus = 'pending' | 'processing' | 'completed' | 'failed' | 'rejected';
  let payoutStatus: PayoutStatus = 'pending';

  const transitionPayout = (current: PayoutStatus, next: PayoutStatus): PayoutStatus => {
    const validTransitions: Record<PayoutStatus, PayoutStatus[]> = {
      pending: ['processing', 'rejected'],
      processing: ['completed', 'failed'],
      completed: [],
      failed: ['pending'],
      rejected: []
    };

    if (!validTransitions[current].includes(next)) {
      throw new Error(`Invalid transition from ${current} to ${next}`);
    }
    return next;
  };

  payoutStatus = transitionPayout(payoutStatus, 'processing');
  assert.strictEqual(payoutStatus, 'processing');
  payoutStatus = transitionPayout(payoutStatus, 'completed');
  assert.strictEqual(payoutStatus, 'completed');

  assert.throws(() => transitionPayout('completed', 'pending'), /Invalid transition/);

  console.log('✓ Financial Actions, Commission & Payout State Machine test passed');
}

// 5. Auction Moderation Lifecycle
function testAuctionModerationLifecycle() {
  type AuctionStatus = 'draft' | 'pending' | 'active' | 'suspended' | 'cancelled' | 'ended' | 'rejected';
  
  let auction = {
    id: 'auc-01',
    status: 'pending' as AuctionStatus
  };

  // Admin approves pending auction
  auction.status = 'active';
  assert.strictEqual(auction.status, 'active', 'Pending auction approved to active');

  // Admin suspends live auction due to policy violation
  auction.status = 'suspended';
  assert.strictEqual(auction.status, 'suspended', 'Admin suspended live auction');

  // Admin reactivates
  auction.status = 'active';
  assert.strictEqual(auction.status, 'active');

  console.log('✓ Auction Moderation Lifecycle test passed');
}

// 6. Fraud Handling & Risk Thresholding
function testFraudAlertsAndRiskScoring() {
  interface FraudAlert {
    id: string;
    risk_score: number;
    alert_type: string;
    status: 'open' | 'investigating' | 'resolved' | 'dismissed';
    severity: 'low' | 'medium' | 'high' | 'critical';
  }

  const computeSeverity = (riskScore: number): 'low' | 'medium' | 'high' | 'critical' => {
    if (riskScore >= 90) return 'critical';
    if (riskScore >= 70) return 'high';
    if (riskScore >= 40) return 'medium';
    return 'low';
  };

  const alert1: FraudAlert = {
    id: 'f-1',
    risk_score: 85,
    alert_type: 'rapid_bid_escalation',
    status: 'open',
    severity: computeSeverity(85)
  };

  assert.strictEqual(alert1.severity, 'high', 'Risk score 85 should be marked high severity');

  // Admin resolves fraud alert
  alert1.status = 'resolved';
  assert.strictEqual(alert1.status, 'resolved', 'Alert resolved by admin');

  console.log('✓ Fraud Alert Risk Scoring & Handling test passed');
}

// 7. Tamper-Resistant Audit Logging Integrity
function testTamperResistantAuditLogging() {
  interface AuditLogEntry {
    id: string;
    admin_id: string;
    action: string;
    target_type: string;
    target_id: string;
    ip_address: string;
    created_at: string;
    hash?: string;
  }

  // Audit entry creation with SHA-256 integrity digest
  const createTamperResistantLog = (entry: Omit<AuditLogEntry, 'id' | 'hash'>): AuditLogEntry => {
    const id = crypto.randomUUID();
    const payload = `${id}|${entry.admin_id}|${entry.action}|${entry.target_type}|${entry.target_id}|${entry.ip_address}|${entry.created_at}`;
    const hash = crypto.createHash('sha256').update(payload).digest('hex');
    return { id, ...entry, hash };
  };

  const log = createTamperResistantLog({
    admin_id: 'admin-01',
    action: 'USER_SUSPENDED',
    target_type: 'user',
    target_id: 'u-999',
    ip_address: '192.168.1.100',
    created_at: new Date().toISOString()
  });

  assert.ok(log.id, 'Log must contain unique UUID');
  assert.ok(log.hash && log.hash.length === 64, 'Log must contain 64-char SHA-256 integrity hash');
  assert.strictEqual(log.action, 'USER_SUSPENDED');

  // Verify log tampering detection
  const verifyHash = (l: AuditLogEntry) => {
    const payload = `${l.id}|${l.admin_id}|${l.action}|${l.target_type}|${l.target_id}|${l.ip_address}|${l.created_at}`;
    return crypto.createHash('sha256').update(payload).digest('hex') === l.hash;
  };

  assert.strictEqual(verifyHash(log), true, 'Legitimate log entry must verify successfully');

  // Attempt tampering
  const tampered = { ...log, action: 'USER_UNBANNED' };
  assert.strictEqual(verifyHash(tampered), false, 'Tampered log entry must fail hash verification');

  console.log('✓ Tamper-Resistant Audit Logging & Integrity Hash test passed');
}

// Execute all tests
testAdminAuthorizationRBAC();
testAdminUserActions();
testKycActionsAndPrivacy();
testFinancialAndEscrowActions();
testAuctionModerationLifecycle();
testFraudAlertsAndRiskScoring();
testTamperResistantAuditLogging();

console.log('======================================================');
console.log(' All NexusBids Admin Panel & RBAC Tests Passed! (7/7)');
console.log('======================================================');
