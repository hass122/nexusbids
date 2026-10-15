import express from 'express';
import cors from 'cors';
import cookieParser from 'cookie-parser';
import path from 'path';
import fs from 'fs';
import { fileURLToPath } from 'url';
import { ENV } from './config/env.js';
import { errorHandler } from './middleware/error.js';
import { apiRateLimiter } from './middleware/rateLimit.js';
import { sendSuccess, sendError } from './utils/response.js';

import authRoutes from './routes/auth.routes.js';
import auctionRoutes from './routes/auction.routes.js';
import cronRoutes from './routes/cron.routes.js';
import marketplaceRoutes from './routes/marketplace.routes.js';
import aiRoutes from './routes/ai.routes.js';
import kycRoutes from './routes/kyc.routes.js';
import paymentRoutes from './routes/payment.routes.js';
import adminRoutes from './routes/admin.routes.js';
import categoryRoutes from './routes/category.routes.js';
import shippingRoutes from './routes/shipping.routes.js';
import realtimeRoutes from './routes/realtime.routes.js';
import recommendationRoutes from './routes/recommendation.routes.js';
import uploadRoutes from './routes/upload.routes.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

// Resolve web static files — works both locally and on Vercel serverless
// On Vercel, __dirname is typically /var/task/apps/api/src so we need to go 3 levels up
function resolveWebPath(): string {
  const candidates = [
    path.resolve(__dirname, '../../web'),               // local: dist/index.js -> apps/web
    path.resolve(__dirname, '../../../web'),            // local alt
    path.resolve(__dirname, '../../../../apps/web'),    // deep dist structure
    path.resolve(process.cwd(), 'apps/web'),            // Vercel: cwd = project root
    path.resolve(process.cwd(), 'web'),                 // flat structure
  ];
  for (const p of candidates) {
    if (fs.existsSync(p)) return p;
  }
  // Last resort: cwd/apps/web (Vercel sets cwd = project root)
  return path.resolve(process.cwd(), 'apps/web');
}

const webPath = resolveWebPath();


const app = express();

// Trust proxy — so req.ip reads X-Forwarded-For header (needed for tests + production behind nginx/CDN)
app.set('trust proxy', 1);

// 1. Security Configuration: Disable information disclosure headers
app.disable('x-powered-by');

// 2. HTTP Security Response Headers
app.use((req, res, next) => {
  res.setHeader('X-Content-Type-Options', 'nosniff');
  res.setHeader('X-Frame-Options', 'SAMEORIGIN');
  res.setHeader('X-XSS-Protection', '1; mode=block');
  res.setHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
  // M-2: Content-Security-Policy — prevents XSS cookie/token exfiltration
  res.setHeader(
    'Content-Security-Policy',
    [
      "default-src 'self'",
      "script-src 'self' 'unsafe-inline' 'unsafe-eval' https://cdn.tailwindcss.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net",
      "style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net",
      "font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com https://cdn.jsdelivr.net data:",
      "img-src 'self' data: blob: https:",
      "connect-src 'self'",
      "frame-ancestors 'none'",
      "base-uri 'self'",
      "form-action 'self'",
    ].join('; ')
  );
  if (ENV.IS_PRODUCTION) {
    res.setHeader('Strict-Transport-Security', 'max-age=31536000; includeSubDomains');
  }
  next();
});

// 3. Strict CORS Protection
app.use(
  cors({
    origin: (origin, callback) => {
      // Allow requests with no origin (e.g. mobile apps, curl, native tools, same-origin)
      if (!origin) return callback(null, true);

      // In local development, permit localhost and 127.0.0.1
      if (!ENV.IS_PRODUCTION && (origin.includes('localhost') || origin.includes('127.0.0.1'))) {
        return callback(null, true);
      }

      // Check configured origins
      if (ENV.CORS_ORIGINS.includes(origin)) {
        return callback(null, true);
      }

      return callback(new Error(`Origin ${origin} is not allowed by CORS`));
    },
    credentials: true,
    methods: ['GET', 'POST', 'PUT', 'PATCH', 'DELETE', 'OPTIONS'],
    allowedHeaders: [
      'Content-Type',
      'Authorization',
      'X-Requested-With',
      'X-Cron-Secret',
      'X-Cron-Token',
      'x-refresh-token',
    ],
  })
);

app.use(cookieParser());
// Increased limit to 25mb to accommodate 15MB base64 KYC/auction images
app.use(express.json({ limit: '25mb' }));
app.use(express.urlencoded({ extended: true, limit: '25mb' }));

// Health Check
app.get('/api/health', (req, res) => {
  sendSuccess(res, {
    status: 'healthy',
    timestamp: new Date().toISOString(),
    service: 'nexusbids-api',
    version: '1.0.0',
  });
});

// ── Public platform stats (no auth) ──────────────────────────────────────────
import { query as dbQuery } from './config/db.js';
app.get('/api/v1/stats', async (req, res) => {
  try {
    const [users, activeAuctions, orders] = await Promise.all([
      dbQuery<any[]>('SELECT COUNT(*) as c FROM users WHERE status = ?', ['active']),
      dbQuery<any[]>("SELECT COUNT(*) as c FROM auctions WHERE auction_status = 'active' AND end_time > NOW()"),
      dbQuery<any[]>('SELECT COUNT(*) as c FROM orders'),
    ]);
    sendSuccess(res, {
      total_users: users[0]?.c || 0,
      active_auctions: activeAuctions[0]?.c || 0,
      total_orders: orders[0]?.c || 0,
    });
  } catch {
    sendSuccess(res, { total_users: 0, active_auctions: 0, total_orders: 0 });
  }
});

// ── Notification unread count (auth required) ───────────────────
import { requireAuth as _reqAuth } from './middleware/auth.js';
app.get('/api/v1/notifications/unread-count', _reqAuth, async (req: any, res) => {
  try {
    const rows = await dbQuery<any[]>(
      "SELECT COUNT(*) as c FROM notifications WHERE user_id = ? AND is_read = 0",
      [(req as any).user?.user_id || (req as any).user?.userId]
    ).catch(() => [{ c: 0 }]);
    sendSuccess(res, { count: rows[0]?.c || 0 });
  } catch {
    sendSuccess(res, { count: 0 });
  }
});

// ── Contact Form Submission (public) ───────────────────────────
app.post('/api/v1/contact', async (req, res) => {
  try {
    const { name, email, subject, message } = req.body || {};
    if (!name || !email || !subject || !message) {
      sendError(res, 'All fields (name, email, subject, message) are required.', 400);
      return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      sendError(res, 'Invalid email address.', 400);
      return;
    }

    // Log to admin_notifications so admins see inquiries in dashboard
    await dbQuery(
      `INSERT INTO admin_notifications (type, title, message, link_url, created_at)
       VALUES ('contact_inquiry', ?, ?, '/admin/dashboard.html', NOW())`,
      [
        `Contact Inquiry: ${String(subject).slice(0, 100)}`,
        `From: ${String(name).slice(0, 80)} <${String(email).slice(0, 80)}>\n\n${String(message).slice(0, 1000)}`,
      ]
    ).catch(() => { });

    sendSuccess(res, { received: true }, 'Your message has been received. Our support team will get back to you shortly.');
  } catch (err: any) {
    sendError(res, err.message || 'Failed to submit contact message', 500);
  }
});

// Apply API Rate Limiting to all /api/v1 routes
app.use('/api/v1', apiRateLimiter);

// Mount API v1 routes
app.use('/api/v1/auth', authRoutes);
app.use('/api/v1/auctions', auctionRoutes);
app.use('/api/v1/categories', categoryRoutes);
app.use('/api/v1/cron', cronRoutes);
app.use('/api/v1/shipping', shippingRoutes);
app.use('/api/v1/realtime', realtimeRoutes);
app.use('/api/v1', marketplaceRoutes);
app.use('/api/v1/ai', aiRoutes);
app.use('/api/v1/kyc', kycRoutes);
app.use('/api/v1/payments', paymentRoutes);
app.use('/api/v1/admin', adminRoutes);
app.use('/api/v1/recommendations', recommendationRoutes);
app.use('/api/v1/upload', uploadRoutes);

// Static uploads serving (local fallback)
app.use('/uploads', express.static(path.resolve(process.cwd(), 'uploads')));

// Static Web Frontend Serving
app.use(express.static(webPath));

// SEO static files (must be before SPA catch-all)
app.get('/robots.txt', (req, res) => {
  res.type('text/plain');
  res.sendFile(path.join(webPath, 'robots.txt'));
});
app.get('/sitemap.xml', async (req, res) => {
  res.type('application/xml');
  try {
    const [auctions, categories] = await Promise.all([
      dbQuery<any[]>(
        "SELECT auction_id, updated_at FROM auctions WHERE auction_status = 'active' AND end_time > NOW() ORDER BY created_at DESC LIMIT 500"
      ).catch(() => []),
      dbQuery<any[]>('SELECT category_id FROM categories').catch(() => []),
    ]);

    let urlsXml = `
  <url><loc>https://nexusbids.pk/</loc><changefreq>daily</changefreq><priority>1.0</priority></url>
  <url><loc>https://nexusbids.pk/auctions.html</loc><changefreq>hourly</changefreq><priority>0.9</priority></url>
  <url><loc>https://nexusbids.pk/categories.html</loc><changefreq>weekly</changefreq><priority>0.8</priority></url>
  <url><loc>https://nexusbids.pk/how-it-works.html</loc><changefreq>monthly</changefreq><priority>0.6</priority></url>
  <url><loc>https://nexusbids.pk/about.html</loc><changefreq>monthly</changefreq><priority>0.5</priority></url>
  <url><loc>https://nexusbids.pk/faq.html</loc><changefreq>monthly</changefreq><priority>0.5</priority></url>
  <url><loc>https://nexusbids.pk/terms.html</loc><changefreq>monthly</changefreq><priority>0.3</priority></url>
  <url><loc>https://nexusbids.pk/privacy.html</loc><changefreq>monthly</changefreq><priority>0.3</priority></url>`;

    for (const cat of categories) {
      urlsXml += `
  <url><loc>https://nexusbids.pk/auctions.html?category=${cat.category_id}</loc><changefreq>daily</changefreq><priority>0.7</priority></url>`;
    }

    for (const a of auctions) {
      const lastmod = a.updated_at ? new Date(a.updated_at).toISOString().split('T')[0] : '';
      urlsXml += `
  <url><loc>https://nexusbids.pk/auction-details.html?id=${a.auction_id}</loc>${lastmod ? `<lastmod>${lastmod}</lastmod>` : ''}<changefreq>hourly</changefreq><priority>0.8</priority></url>`;
    }

    const xml = `<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">${urlsXml}
</urlset>`;
    res.send(xml);
  } catch {
    res.send(`<?xml version="1.0" encoding="UTF-8"?>
<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">
  <url><loc>https://nexusbids.pk/</loc><changefreq>daily</changefreq><priority>1.0</priority></url>
  <url><loc>https://nexusbids.pk/auctions.html</loc><changefreq>hourly</changefreq><priority>0.9</priority></url>
</urlset>`);
  }
});

// Fallback for HTML routing
app.get('*', (req, res, next) => {
  if (req.path.startsWith('/api')) return next();

  const filePath = path.join(webPath, req.path.endsWith('.html') || req.path.includes('.') ? req.path : `${req.path}.html`);

  res.sendFile(filePath, (err) => {
    if (err) {
      // If the file doesn't exist, serve the 404 page with proper 404 status code
      res.status(404).sendFile(path.join(webPath, '404.html'), (err404) => {
        if (err404) res.status(404).send('404 Not Found');
      });
    }
  });
});

// Global Error Handler
app.use(errorHandler);

// Start server locally if not invoked as a serverless function
if (process.env.NODE_ENV !== 'production' || process.env.VERCEL !== '1') {
  app.listen(ENV.PORT, () => {
    console.log(`[NexusBids API] Running on http://localhost:${ENV.PORT}`);
    console.log(`[NexusBids API] Health Check at http://localhost:${ENV.PORT}/api/health`);
  });

  // Scheduled background jobs (non-Vercel environment)
  import('node-cron').then(({ default: cron }) => {
    import('./services/cron.service.js').then(({ CronService }) => {
      // 1. Process ended auctions every minute
      cron.schedule('* * * * *', async () => {
        try {
          const res = await CronService.processEndedAuctions();
          if (res.processed > 0) {
            console.log(`[node-cron] Processed ${res.processed} ended auctions (${res.winners} winners declared)`);
          }
        } catch (err: any) {
          console.error('[node-cron:ended-auctions]', err.message);
        }
      });

      // 2. Auto-release escrow every 6 hours
      cron.schedule('0 */6 * * *', async () => {
        try {
          const res = await CronService.autoReleaseEscrow();
          if (res.released > 0) {
            console.log(`[node-cron] Auto-released escrow for ${res.released} orders`);
          }
        } catch (err: any) {
          console.error('[node-cron:auto-release-escrow]', err.message);
        }
      });

      // 3. Hourly KYC backlog check
      cron.schedule('0 * * * *', async () => {
        try {
          await CronService.checkKycBacklog();
        } catch (err: any) {
          console.error('[node-cron:kyc-backlog]', err.message);
        }
      });

      console.log('[NexusBids API] Background cron jobs scheduled successfully');
    });
  });
}

export default app;

