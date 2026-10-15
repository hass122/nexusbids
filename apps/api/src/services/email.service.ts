/**
 * NexusBids Email Service
 * Uses Nodemailer with Gmail SMTP
 * Set EMAIL_ENABLED=true in .env to activate
 * Generate Gmail App Password: Google Account → Security → 2FA → App Passwords
 */

import nodemailer, { type Transporter } from 'nodemailer';
import { ENV } from '../config/env.js';

interface EmailOptions {
  to: string;
  subject: string;
  html: string;
  text?: string;
}

// ─── Email Transporter ─────────────────────────────────────────
let transporter: Transporter | null = null;

function getTransporter(): Transporter | null {
  if (!ENV.EMAIL.ENABLED) return null;
  if (!ENV.EMAIL.USER || !ENV.EMAIL.PASS) return null;

  if (!transporter) {
    transporter = nodemailer.createTransport({
      service: 'gmail',
      auth: {
        user: ENV.EMAIL.USER,
        pass: ENV.EMAIL.PASS,
      },
      pool: true,
      maxConnections: 5,
    });
  }
  return transporter;
}

// ─── Base HTML Email Template ──────────────────────────────────
function baseTemplate(title: string, content: string): string {
  return `<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1.0">
  <title>${title}</title>
  <style>
    body{margin:0;padding:0;background:#0f0f1a;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif}
    .wrapper{max-width:600px;margin:0 auto;padding:20px}
    .card{background:linear-gradient(135deg,#1a1a2e 0%,#16213e 100%);border-radius:16px;border:1px solid rgba(99,102,241,0.3);overflow:hidden}
    .header{background:linear-gradient(135deg,#4f46e5 0%,#7c3aed 100%);padding:32px;text-align:center}
    .logo{font-size:28px;font-weight:800;color:#fff;letter-spacing:-1px}
    .logo span{color:#a78bfa}
    .body{padding:32px}
    h2{font-size:22px;font-weight:700;color:#f0f0ff;margin:0 0 16px}
    p{font-size:15px;color:#a0a0c0;line-height:1.7;margin:0 0 16px}
    .btn{display:inline-block;padding:14px 28px;background:linear-gradient(135deg,#4f46e5,#7c3aed);color:#fff!important;text-decoration:none;border-radius:10px;font-weight:600;font-size:15px;margin:16px 0}
    .info-box{background:rgba(99,102,241,0.08);border:1px solid rgba(99,102,241,0.2);border-radius:10px;padding:16px;margin:16px 0}
    table{width:100%;border-collapse:collapse}
    td{padding:8px 0;border-bottom:1px solid rgba(255,255,255,0.05);font-size:13px}
    td:first-child{color:#7070a0}
    td:last-child{color:#e0e0ff;font-weight:600;text-align:right}
    .badge{display:inline-block;padding:4px 12px;border-radius:20px;font-size:12px;font-weight:600}
    .badge-success{background:rgba(16,185,129,0.15);color:#10b981;border:1px solid rgba(16,185,129,0.3)}
    .badge-warning{background:rgba(245,158,11,0.15);color:#f59e0b;border:1px solid rgba(245,158,11,0.3)}
    .badge-info{background:rgba(99,102,241,0.15);color:#818cf8;border:1px solid rgba(99,102,241,0.3)}
    .amount{font-size:26px;font-weight:800;color:#a78bfa}
    .footer{padding:20px 32px;text-align:center;font-size:12px;color:#4a4a6a;line-height:1.6}
  </style>
</head>
<body>
  <div class="wrapper">
    <div class="card">
      <div class="header">
        <div class="logo">Nexus<span>Bids</span></div>
        <div style="color:rgba(255,255,255,0.7);font-size:13px;margin-top:6px">Pakistan's Trusted Online Auction Platform</div>
      </div>
      <div class="body">${content}</div>
      <div class="footer">
        &copy; ${new Date().getFullYear()} NexusBids Pakistan. All rights reserved.<br>
        nexusbids.pk &nbsp;|&nbsp; support@nexusbids.pk<br>
        <small>If you didn't request this email, please ignore it.</small>
      </div>
    </div>
  </div>
</body>
</html>`;
}

// ─── Email Service ─────────────────────────────────────────────
export class EmailService {
  /** Send email. Returns true on success. Never throws. */
  static async send(options: EmailOptions): Promise<boolean> {
    const mailer = getTransporter();
    if (!mailer) {
      console.log(`[EmailService] Disabled. Skipping: "${options.subject}" → ${options.to}`);
      return false;
    }
    try {
      await mailer.sendMail({
        from: ENV.EMAIL.FROM,
        to: options.to,
        subject: options.subject,
        html: options.html,
        text: options.text || options.subject,
      });
      console.log(`[EmailService] ✅ Sent: "${options.subject}" → ${options.to}`);
      return true;
    } catch (err: any) {
      console.error(`[EmailService] ❌ Failed: "${options.subject}" → ${options.to}:`, err.message);
      return false;
    }
  }

  // ─── 1. Welcome Email ─────────────────────────────────────
  static async sendWelcome(to: string, fullName: string, role: string): Promise<void> {
    await this.send({
      to,
      subject: '🎉 Welcome to NexusBids Pakistan!',
      html: baseTemplate('Welcome', `
        <h2>Welcome, ${fullName}! 🎉</h2>
        <p>You've successfully joined <strong>NexusBids</strong>, Pakistan's most trusted online auction marketplace.</p>
        <div class="info-box">
          <table>
            <tr><td>Your Role</td><td><span class="badge badge-info">${role === 'seller' ? 'Seller' : 'Buyer'}</span></td></tr>
            <tr><td>Step 1</td><td>${role === 'seller' ? 'Complete Seller KYC verification' : 'Complete Buyer KYC verification'}</td></tr>
            <tr><td>Step 2</td><td>${role === 'seller' ? 'Create your first auction' : 'Explore live auctions'}</td></tr>
            <tr><td>Step 3</td><td>${role === 'seller' ? 'Earn with secure escrow payments' : 'Bid with 100% Escrow Protection'}</td></tr>
          </table>
        </div>
        <a href="https://nexusbids.pk" class="btn">Get Started →</a>
      `),
    });
  }

  // ─── 2. Email Verification ────────────────────────────────
  static async sendEmailVerification(to: string, fullName: string, verifyUrl: string): Promise<void> {
    await this.send({
      to,
      subject: '✉️ Verify Your NexusBids Email',
      html: baseTemplate('Email Verification', `
        <h2>Verify Your Email Address</h2>
        <p>Hi <strong>${fullName}</strong>, please verify your email to activate your account. This link expires in <strong>24 hours</strong>.</p>
        <a href="${verifyUrl}" class="btn">✅ Verify Email Address</a>
        <p style="font-size:13px;margin-top:16px;">Or paste this link in your browser:<br>
          <span style="color:#818cf8;word-break:break-all">${verifyUrl}</span></p>
      `),
    });
  }

  // ─── 3. Password Reset ────────────────────────────────────
  static async sendPasswordReset(to: string, fullName: string, resetUrl: string): Promise<void> {
    await this.send({
      to,
      subject: '🔑 Reset Your NexusBids Password',
      html: baseTemplate('Password Reset', `
        <h2>Password Reset Request</h2>
        <p>Hi <strong>${fullName}</strong>, we received a request to reset your NexusBids password. Link expires in <strong>1 hour</strong>.</p>
        <a href="${resetUrl}" class="btn">🔑 Reset Password</a>
        <div class="info-box">
          <p style="margin:0;color:#f59e0b;font-size:13px">⚠️ If you didn't request this, secure your account at <a href="https://nexusbids.pk/buyer/security.html" style="color:#818cf8">nexusbids.pk</a></p>
        </div>
      `),
    });
  }

  // ─── 4. Outbid Alert ─────────────────────────────────────
  static async sendOutbid(to: string, fullName: string, auctionTitle: string, newBid: number, auctionId: number): Promise<void> {
    await this.send({
      to,
      subject: `⚡ You've been outbid on "${auctionTitle}"`,
      html: baseTemplate('Outbid Alert', `
        <h2>You've Been Outbid!</h2>
        <p>Hi <strong>${fullName}</strong>, someone placed a higher bid on an auction you were leading.</p>
        <div class="info-box">
          <table>
            <tr><td>Auction</td><td>${auctionTitle}</td></tr>
            <tr><td>New Highest Bid</td><td style="color:#f59e0b">Rs. ${newBid.toLocaleString()}</td></tr>
          </table>
        </div>
        <a href="https://nexusbids.pk/auction-details.html?id=${auctionId}" class="btn">🏆 Bid Again Now</a>
      `),
    });
  }

  // ─── 5. Auction Won ───────────────────────────────────────
  static async sendAuctionWon(to: string, fullName: string, auctionTitle: string, winAmount: number, orderId: number): Promise<void> {
    await this.send({
      to,
      subject: `🎊 You won "${auctionTitle}"!`,
      html: baseTemplate('Auction Won', `
        <h2>🎊 You Won the Auction!</h2>
        <p>Congratulations <strong>${fullName}</strong>!</p>
        <div class="info-box">
          <table>
            <tr><td>Item Won</td><td>${auctionTitle}</td></tr>
            <tr><td>Winning Bid</td><td><span class="amount">Rs. ${winAmount.toLocaleString()}</span></td></tr>
            <tr><td>Status</td><td><span class="badge badge-warning">Payment Required</span></td></tr>
          </table>
        </div>
        <p>Complete your payment to secure your item. Protected by NexusBids Escrow.</p>
        <a href="https://nexusbids.pk/checkout.html?order_id=${orderId}" class="btn">💳 Complete Payment</a>
      `),
    });
  }

  // ─── 6. Payment Received (Seller) ────────────────────────
  static async sendPaymentReceived(to: string, fullName: string, auctionTitle: string, amount: number, orderNumber: string): Promise<void> {
    await this.send({
      to,
      subject: `💰 Payment received — Order #${orderNumber}`,
      html: baseTemplate('Payment Received', `
        <h2>Payment Received into Escrow!</h2>
        <p>Hi <strong>${fullName}</strong>, payment for your sold item is now in secure escrow.</p>
        <div class="info-box">
          <table>
            <tr><td>Item</td><td>${auctionTitle}</td></tr>
            <tr><td>Order #</td><td>${orderNumber}</td></tr>
            <tr><td>Escrow Amount</td><td><span class="amount">Rs. ${amount.toLocaleString()}</span></td></tr>
            <tr><td>Status</td><td><span class="badge badge-success">Escrow Held</span></td></tr>
          </table>
        </div>
        <p>Ship the item promptly. Escrow releases to your wallet after buyer confirms delivery.</p>
        <a href="https://nexusbids.pk/seller/orders.html" class="btn">📦 Ship Order</a>
      `),
    });
  }

  // ─── 7. KYC Approved ─────────────────────────────────────
  static async sendKycApproved(to: string, fullName: string, kycType: string): Promise<void> {
    const isSeller = kycType === 'seller';
    await this.send({
      to,
      subject: '✅ KYC Verification Approved — NexusBids',
      html: baseTemplate('KYC Approved', `
        <h2>✅ KYC Verification Approved!</h2>
        <p>Hi <strong>${fullName}</strong>, your <strong>${isSeller ? 'Seller' : 'Buyer'} KYC</strong> has been approved!</p>
        <div class="info-box">
          <table>
            <tr><td>KYC Type</td><td>${isSeller ? 'Seller KYC' : 'Buyer KYC'}</td></tr>
            <tr><td>Status</td><td><span class="badge badge-success">✅ Approved</span></td></tr>
          </table>
        </div>
        <a href="https://nexusbids.pk/${isSeller ? 'seller/create-auction.html' : 'auctions.html'}" class="btn">
          ${isSeller ? '🏪 Create Your First Auction' : '🔍 Browse Live Auctions'}
        </a>
      `),
    });
  }

  // ─── 8. KYC Rejected ─────────────────────────────────────
  static async sendKycRejected(to: string, fullName: string, kycType: string, reason: string): Promise<void> {
    const isSeller = kycType === 'seller';
    await this.send({
      to,
      subject: '❌ KYC Verification — Action Required',
      html: baseTemplate('KYC Rejected', `
        <h2>KYC Requires Resubmission</h2>
        <p>Hi <strong>${fullName}</strong>, your ${isSeller ? 'Seller' : 'Buyer'} KYC was not approved.</p>
        <div class="info-box">
          <table>
            <tr><td>Status</td><td><span class="badge badge-warning">Rejected</span></td></tr>
            <tr><td>Reason</td><td>${reason || 'Documents unclear or incomplete'}</td></tr>
          </table>
        </div>
        <p style="font-size:13px">Common issues: blurry CNIC, selfie mismatch, missing documents.</p>
        <a href="https://nexusbids.pk/${isSeller ? 'seller/kyc.html' : 'buyer/kyc.html'}" class="btn">📄 Re-submit KYC</a>
      `),
    });
  }

  // ─── 9. Order Shipped (Buyer) ─────────────────────────────
  static async sendOrderShipped(to: string, fullName: string, auctionTitle: string, courier: string, trackingNumber: string, trackingUrl?: string): Promise<void> {
    await this.send({
      to,
      subject: `📦 Your order is on its way — "${auctionTitle}"`,
      html: baseTemplate('Order Shipped', `
        <h2>📦 Your Order Has Been Shipped!</h2>
        <p>Hi <strong>${fullName}</strong>, your order is on its way!</p>
        <div class="info-box">
          <table>
            <tr><td>Item</td><td>${auctionTitle}</td></tr>
            <tr><td>Courier</td><td>${courier}</td></tr>
            <tr><td>Tracking #</td><td style="color:#a78bfa">${trackingNumber}</td></tr>
            <tr><td>Status</td><td><span class="badge badge-info">In Transit</span></td></tr>
          </table>
        </div>
        ${trackingUrl ? `<a href="${trackingUrl}" class="btn">🔍 Track My Order</a>` : ''}
        <p style="font-size:13px">After receiving, confirm delivery in your dashboard to release seller payment from escrow.</p>
      `),
    });
  }

  // ─── 10. New Device Login Alert ──────────────────────────
  static async sendNewDeviceAlert(to: string, fullName: string, ip: string, device: string, time: string): Promise<void> {
    await this.send({
      to,
      subject: '🔐 New Device Login Detected — NexusBids',
      html: baseTemplate('Security Alert', `
        <h2>🔐 New Device Login Detected</h2>
        <p>Hi <strong>${fullName}</strong>, a login was detected from a new device.</p>
        <div class="info-box">
          <table>
            <tr><td>Time</td><td>${time}</td></tr>
            <tr><td>IP Address</td><td>${ip}</td></tr>
            <tr><td>Device</td><td>${device}</td></tr>
          </table>
        </div>
        <p>If this was you, no action needed. If NOT you, secure your account immediately.</p>
        <a href="https://nexusbids.pk/buyer/security.html" class="btn">🔒 Secure My Account</a>
      `),
    });
  }

  // ─── 11. Payout Processed ────────────────────────────────
  static async sendPayoutProcessed(to: string, fullName: string, amount: number, method: string): Promise<void> {
    await this.send({
      to,
      subject: `💸 Payout of Rs. ${amount.toLocaleString()} processed`,
      html: baseTemplate('Payout Processed', `
        <h2>💸 Your Payout is On the Way!</h2>
        <p>Hi <strong>${fullName}</strong>, your payout has been processed.</p>
        <div class="info-box">
          <table>
            <tr><td>Amount</td><td><span class="amount">Rs. ${amount.toLocaleString()}</span></td></tr>
            <tr><td>Method</td><td>${method}</td></tr>
            <tr><td>Status</td><td><span class="badge badge-success">Processed</span></td></tr>
          </table>
        </div>
        <p style="font-size:13px">Funds should arrive within 24 business hours.</p>
        <a href="https://nexusbids.pk/seller/payouts.html" class="btn">📊 View Payout History</a>
      `),
    });
  }

  // ─── 12. Dispute Alert ───────────────────────────────────
  static async sendDisputeAlert(to: string, fullName: string, orderNumber: string, role: 'buyer' | 'seller'): Promise<void> {
    await this.send({
      to,
      subject: `⚠️ Dispute opened — Order #${orderNumber}`,
      html: baseTemplate('Dispute Alert', `
        <h2>⚠️ A Dispute Has Been Opened</h2>
        <p>Hi <strong>${fullName}</strong>, a dispute has been raised for Order <strong>#${orderNumber}</strong>.</p>
        <div class="info-box">
          <table>
            <tr><td>Order</td><td>#${orderNumber}</td></tr>
            <tr><td>Status</td><td><span class="badge badge-warning">Under Review</span></td></tr>
          </table>
        </div>
        <p>${role === 'seller'
          ? 'Please submit your response and evidence through your seller dashboard.'
          : 'Our team will review and contact you within 24-48 hours.'}</p>
        <a href="https://nexusbids.pk/${role}/disputes.html" class="btn">📋 View Dispute</a>
      `),
    });
  }
}
