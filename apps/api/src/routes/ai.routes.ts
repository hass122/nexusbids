import { Router } from 'express';
import { requireAuth, optionalAuth } from '../middleware/auth.js';
import { sendSuccess, sendError } from '../utils/response.js';
import { query } from '../config/db.js';
import { ENV } from '../config/env.js';

const router = Router();

// ─── AI Chatbot ────────────────────────────────────────────
// POST /api/v1/ai/chat (Available to authenticated users and guests)
router.post('/chat', optionalAuth, async (req, res) => {
  try {
    // ✅ FIX: Enforce AI feature toggle globally
    if (!ENV.AI.FEATURES_ENABLED) {
      sendError(res, 'AI chat is currently disabled.', 503);
      return;
    }
    const { message, action } = req.body;
    const userId = req.user?.userId;
    const sessionId = userId ? `user_${userId}` : `guest_${(req.ip || 'guest').replace(/[^a-zA-Z0-9]/g, '_')}`;

    if (action === 'clear') {
      await query('DELETE FROM ai_chat_messages WHERE session_id = ?', [sessionId]);
      sendSuccess(res, { cleared: true }, 'Chat history cleared');
      return;
    }

    if (!message || message.trim().length === 0) {
      sendError(res, 'Message cannot be empty', 400);
      return;
    }

    // Rate limit check: 20 per 5 min
    const rateRows = await query<any[]>(
      'SELECT hit_count, window_start FROM ai_chat_rate WHERE rate_key = ? LIMIT 1',
      [sessionId]
    );

    if (rateRows.length > 0) {
      const { hit_count, window_start } = rateRows[0];
      const windowAge = (Date.now() - new Date(window_start).getTime()) / 1000;
      if (windowAge < 300 && hit_count >= 25) {
        sendError(res, 'Rate limit reached. Please wait a few minutes.', 429);
        return;
      }
      if (windowAge >= 300) {
        await query('UPDATE ai_chat_rate SET hit_count = 1, window_start = NOW() WHERE rate_key = ?', [sessionId]);
      } else {
        await query('UPDATE ai_chat_rate SET hit_count = hit_count + 1 WHERE rate_key = ?', [sessionId]);
      }
    } else {
      await query('INSERT IGNORE INTO ai_chat_rate (rate_key, hit_count, window_start) VALUES (?, 1, NOW())', [sessionId]);
    }

    // Fetch conversation history
    const history = await query<any[]>(
      "SELECT role, content FROM ai_chat_messages WHERE session_id = ? AND role IN ('user', 'assistant') ORDER BY message_id DESC LIMIT 6",
      [sessionId]
    );
    const messages = history.reverse().map((h: any) => ({ role: h.role, content: h.content }));

    // Save user message
    await query(
      'INSERT INTO ai_chat_messages (session_id, user_id, role, content, created_at) VALUES (?, ?, ?, ?, NOW())',
      [sessionId, userId || null, 'user', message]
    );

    // Contextual responses if API key is not present or offline
    if (!ENV.AI.API_KEY || ENV.AI.API_KEY === 'mock-key') {
      const lower = message.toLowerCase();
      let reply = 'Welcome to NexusBids Pakistan! I can help you with live auctions, automated proxy bidding, NADRA CNIC KYC verification, and escrow payments through JazzCash, Easypaisa, or Bank Wire. What would you like to know?';

      if (lower.includes('escrow') || lower.includes('safe') || lower.includes('payment') || lower.includes('fraud')) {
        reply = 'NexusBids uses a 100% Escrow Protection model. When you win an auction and pay via JazzCash, Easypaisa, or Bank Wire, your money is held in our secure escrow trust. The seller only receives payout after you receive and confirm delivery of the item.';
      } else if (lower.includes('kyc') || lower.includes('cnic') || lower.includes('verify') || lower.includes('nadra')) {
        reply = 'To maintain safety, all buyers and sellers must complete NADRA-compliant CNIC verification. Simply upload clear front/back images of your CNIC in your dashboard. Reviews take less than 1 business hour.';
      } else if (lower.includes('bid') || lower.includes('auto') || lower.includes('proxy')) {
        reply = 'You can bid manually by clicking "Bid Now", or set an Automated Proxy Bidding ceiling. Our system will bid on your behalf by the minimum increment only when you are outbid, up to your designated maximum.';
      } else if (lower.includes('jazzcash') || lower.includes('easypaisa') || lower.includes('bank')) {
        reply = 'NexusBids supports direct Pakistani payment methods: JazzCash (0300-1234567), Easypaisa (0321-7654321), and Direct Bank Wire (Meezan Bank & HBL). Once paid, upload your receipt screenshot for instant escrow verification.';
      } else if (lower.includes('seller') || lower.includes('sell') || lower.includes('payout')) {
        reply = 'To sell on NexusBids, register as a seller and submit your seller KYC. Once verified, you can list items with photos, start times, and buy-now prices. Earnings can be withdrawn directly to your Pakistani bank account.';
      }

      await query(
        'INSERT INTO ai_chat_messages (session_id, user_id, role, content, created_at) VALUES (?, ?, ?, ?, NOW())',
        [sessionId, userId || null, 'assistant', reply]
      );
      sendSuccess(res, { reply, session_id: sessionId });
      return;
    }

    const systemPrompt = `You are NexusBids Assistant, a helpful customer support bot for NexusBids, a Pakistani online auction marketplace. 
Help users with: bidding, auctions, KYC verification, payments (JazzCash, Easypaisa, Bank Transfer), orders, shipping, disputes, and account issues.
Be concise, professional, and helpful. Reply in the same language the user writes in (English, Urdu, or Roman Urdu).
Currency is PKR (Pakistani Rupees). Do not make up information.`;

    const payload = {
      model: ENV.AI.MODEL,
      messages: [
        { role: 'system', content: systemPrompt },
        ...messages,
        { role: 'user', content: message }
      ],
      temperature: 0.5,
      max_tokens: 500,
    };

    const response = await fetch(`${ENV.AI.BASE_URL}/chat/completions`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${ENV.AI.API_KEY}`,
      },
      body: JSON.stringify(payload),
    });

    if (!response.ok) {
      throw new Error(`AI provider error: ${response.status}`);
    }

    const data = await response.json() as any;
    const reply = data.choices?.[0]?.message?.content || 'Sorry, I could not process your request.';

    // Save assistant reply
    await query(
      'INSERT INTO ai_chat_messages (session_id, user_id, role, content, created_at) VALUES (?, ?, ?, ?, NOW())',
      [sessionId, userId || null, 'assistant', reply]
    );

    sendSuccess(res, { reply, session_id: sessionId });
  } catch (err: any) {
    sendError(res, 'AI service error. Please try again.', 500);
  }
});

// POST /api/v1/ai/generate-description (Seller only)
router.post('/generate-description', requireAuth, async (req, res) => {
  try {
    if (req.user!.role !== 'seller' && req.user!.role !== 'admin') {
      sendError(res, 'Only sellers can use this feature', 403);
      return;
    }

    const { title, category, condition, features } = req.body;
    if (!title) { sendError(res, 'Auction title is required', 400); return; }

    if (!features && !condition && !category) {
      sendSuccess(res, {
        description: `Selling my ${title}. Serious bidders only. Contact for more details.`,
        fallback: true,
      });
      return;
    }

    if (!ENV.AI.API_KEY) {
      sendSuccess(res, {
        description: `Selling ${title}. ${condition ? 'Condition: ' + condition + '.' : ''} ${features || ''}`.trim(),
        fallback: true,
      });
      return;
    }

    const prompt = `Write a professional auction listing description for a Pakistani marketplace.
Item: ${title}
Category: ${category || 'General'}
Condition: ${condition || 'Not specified'}
Key features/details provided by seller: ${features || 'None provided'}

Rules:
- Only describe what is explicitly mentioned by the seller
- Do NOT invent features, accessories, or delivery terms not mentioned
- Keep it to 150-200 words max
- Professional and persuasive tone
- End with "Serious bidders welcome. Contact for any queries."`;

    const response = await fetch(`${ENV.AI.BASE_URL}/chat/completions`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        'Authorization': `Bearer ${ENV.AI.API_KEY}`,
      },
      body: JSON.stringify({
        model: ENV.AI.MODEL,
        messages: [{ role: 'user', content: prompt }],
        temperature: 0.4,
        max_tokens: 400,
      }),
    });

    if (!response.ok) throw new Error('AI provider unavailable');

    const data = await response.json() as any;
    const description = data.choices?.[0]?.message?.content || '';

    sendSuccess(res, { description });
  } catch (err: any) {
    sendError(res, 'Could not generate description. Please try again or write it manually.', 500);
  }
});

// ─── AI Price Suggestion (Sellers only) ───────────────────────
// POST /api/v1/ai/suggest-price
router.post('/suggest-price', requireAuth, async (req, res) => {
  try {
    if (req.user!.role !== 'seller' && req.user!.role !== 'admin') {
      sendError(res, 'Only sellers can access AI price suggestions.', 403);
      return;
    }

    if (!ENV.AI.FEATURES_ENABLED) {
      sendError(res, 'AI features are currently disabled.', 503);
      return;
    }

    const {
      title,
      category_id,
      category,
      condition,
      description,
      brand,
      year,
    } = req.body;

    if (!title || typeof title !== 'string' || title.trim().length === 0) {
      sendError(res, 'Item title is required for price suggestion.', 400);
      return;
    }

    // Input length limits and sanitization
    const sanitizedTitle = title.trim().slice(0, 150).replace(/[<>]/g, '');
    const sanitizedCategory = (category || '').trim().slice(0, 80).replace(/[<>]/g, '');
    const sanitizedCondition = (condition || 'Used - Good').trim().slice(0, 50).replace(/[<>]/g, '');
    const sanitizedDesc = (description || '').trim().slice(0, 2000).replace(/[<>]/g, '');
    const sanitizedBrand = (brand || '').trim().slice(0, 80).replace(/[<>]/g, '');
    const sanitizedYear = (year || '').trim().slice(0, 20).replace(/[<>]/g, '');
    const categoryIdNum = parseInt(String(category_id || 0), 10) || 0;

    // Rate Limiting: 15 per 5 minutes per user
    const rateKey = `price_sug_${req.user!.userId}`;
    const rateRows = await query<any[]>(
      'SELECT hit_count, window_start FROM ai_chat_rate WHERE rate_key = ? LIMIT 1',
      [rateKey]
    );

    if (rateRows.length > 0) {
      const { hit_count, window_start } = rateRows[0];
      const windowAge = (Date.now() - new Date(window_start).getTime()) / 1000;
      if (windowAge < 300 && hit_count >= 15) {
        sendError(res, 'Price suggestion rate limit reached. Please wait a few minutes.', 429);
        return;
      }
      if (windowAge >= 300) {
        await query('UPDATE ai_chat_rate SET hit_count = 1, window_start = NOW() WHERE rate_key = ?', [rateKey]);
      } else {
        await query('UPDATE ai_chat_rate SET hit_count = hit_count + 1 WHERE rate_key = ?', [rateKey]);
      }
    } else {
      await query('INSERT IGNORE INTO ai_chat_rate (rate_key, hit_count, window_start) VALUES (?, 1, NOW())', [rateKey]);
    }

    // 1. Build context from OUR database first (Last sold auctions in same category or matching keywords)
    const titleWords = sanitizedTitle
      .split(/\s+/)
      .filter((w) => w.length > 3)
      .slice(0, 3);
    const titlePattern = titleWords.length > 0 ? `%${titleWords.join('%')}%` : '%';

    const comparables = await query<any[]>(
      `SELECT a.auction_id, a.title, a.starting_price, a.current_bid as final_price,
              (SELECT COUNT(*) FROM bids b WHERE b.auction_id = a.auction_id) as bid_count,
              TIMESTAMPDIFF(HOUR, a.start_time, a.end_time) as duration_hours
       FROM auctions a
       WHERE a.auction_status IN ('sold', 'completed')
         AND (
           (? > 0 AND a.category_id = ?)
           OR a.title LIKE ?
         )
       ORDER BY a.created_at DESC
       LIMIT 10`,
      [categoryIdNum, categoryIdNum, titlePattern]
    ).catch(() => []);

    const comparableCount = comparables.length;

    // Baseline calculation from database sales
    let avgSoldPrice = 0;
    if (comparableCount > 0) {
      const total = comparables.reduce((acc, c) => acc + parseFloat(c.final_price || c.starting_price || 0), 0);
      avgSoldPrice = Math.round(total / comparableCount);
    }

    // Fallback if API key missing or provider unavailable
    const fallbackResponse = () => {
      if (comparableCount >= 3) {
        const estStart = Math.round((avgSoldPrice * 0.7) / 100) * 100;
        const estReserve = Math.round((avgSoldPrice * 0.9) / 100) * 100;
        const estLow = Math.round((avgSoldPrice * 0.85) / 100) * 100;
        const estHigh = Math.round((avgSoldPrice * 1.25) / 100) * 100;
        return {
          suggested_start_price: Math.max(100, estStart),
          suggested_reserve_price: Math.max(estStart, estReserve),
          expected_price_range: { low: estLow, high: estHigh },
          confidence: 'medium' as const,
          reasoning: `Based on ${comparableCount} recent completed sales in this category with an average final price of PKR ${avgSoldPrice.toLocaleString()}.`,
          comparable_count: comparableCount,
        };
      }

      // Less than 3 comparables -> strictly low confidence
      const fallbackBase = avgSoldPrice > 0 ? avgSoldPrice : 5000;
      const estStart = Math.round((fallbackBase * 0.6) / 100) * 100;
      const estLow = Math.round((fallbackBase * 0.8) / 100) * 100;
      const estHigh = Math.round((fallbackBase * 1.3) / 100) * 100;

      return {
        suggested_start_price: Math.max(100, estStart),
        suggested_reserve_price: null,
        expected_price_range: { low: estLow, high: estHigh },
        confidence: 'low' as const,
        reasoning:
          comparableCount === 0
            ? 'No previous sales found for this exact item in our database. Valuation is an approximation.'
            : `Only ${comparableCount} recent comparable sale(s) found in our database. Confidence is low due to limited historical data.`,
        comparable_count: comparableCount,
      };
    };

    if (!ENV.AI.API_KEY || ENV.AI.API_KEY === 'mock-key') {
      sendSuccess(res, fallbackResponse());
      return;
    }

    // Call OpenAI-compatible model with timeout
    const comparablesSummary = comparables
      .map(
        (c, idx) =>
          `${idx + 1}. Title: "${c.title}", Final Price: PKR ${Number(c.final_price).toLocaleString()}, Bids: ${c.bid_count}, Duration: ${c.duration_hours || 24}h`
      )
      .join('\n');

    const prompt = `You are a certified auction valuation actuary and marketplace pricing expert for Pakistan (Currency: PKR).
Evaluate the following item for auction listing:

<user_listing>
Title: ${sanitizedTitle}
Category: ${sanitizedCategory || 'General'}
Condition: ${sanitizedCondition}
Brand: ${sanitizedBrand || 'Not specified'}
Year/Model: ${sanitizedYear || 'Not specified'}
Description: ${sanitizedDesc || 'None provided'}
</user_listing>

<historical_marketplace_sales>
Count of verified sold listings: ${comparableCount}
${comparablesSummary || 'No recent sold comparables in database.'}
</historical_marketplace_sales>

TASK:
Analyze the item and historical data to recommend an auction starting bid, an optional reserve price, and an expected winning price range in PKR.

CRITICAL INVARIANTS:
1. If comparable_count is less than 3, you MUST set "confidence": "low" and explicitly explain in "reasoning" that data is limited.
2. Prices must be realistic positive Pakistani Rupees (PKR), rounded to nearest 50 or 100.
3. Return ONLY a valid JSON object matching this schema. No markdown formatting, no code block backticks:
{
  "suggested_start_price": <number>,
  "suggested_reserve_price": <number or null>,
  "expected_price_range": { "low": <number>, "high": <number> },
  "confidence": "low" | "medium" | "high",
  "reasoning": "<short explanation, under 50 words>",
  "comparable_count": ${comparableCount}
}`;

    const controller = new AbortController();
    const timeoutId = setTimeout(() => controller.abort(), 12000);

    try {
      const response = await fetch(`${ENV.AI.BASE_URL}/chat/completions`, {
        method: 'POST',
        headers: {
          'Content-Type': 'application/json',
          Authorization: `Bearer ${ENV.AI.API_KEY}`,
        },
        body: JSON.stringify({
          model: ENV.AI.MODEL,
          messages: [
            {
              role: 'system',
              content: 'You output pure JSON without code fences or extra commentary.',
            },
            { role: 'user', content: prompt },
          ],
          temperature: 0.2,
          max_tokens: 300,
        }),
        signal: controller.signal,
      });

      clearTimeout(timeoutId);

      if (!response.ok) {
        throw new Error(`AI provider error: ${response.status}`);
      }

      const data = (await response.json()) as any;
      const rawContent = data.choices?.[0]?.message?.content || '';

      // Clean JSON string
      const jsonStr = rawContent.replace(/```json/gi, '').replace(/```/g, '').trim();
      const parsed = JSON.parse(jsonStr);

      // Validate structured schema
      const suggestedStart = Math.max(100, Math.round(Number(parsed.suggested_start_price) || 100));
      const suggestedReserve =
        parsed.suggested_reserve_price !== null && parsed.suggested_reserve_price !== undefined
          ? Math.max(suggestedStart, Math.round(Number(parsed.suggested_reserve_price)))
          : null;
      const lowRange = Math.max(100, Math.round(Number(parsed.expected_price_range?.low) || suggestedStart));
      const highRange = Math.max(
        lowRange,
        Math.round(Number(parsed.expected_price_range?.high) || suggestedStart * 1.3)
      );

      let confidence: 'low' | 'medium' | 'high' =
        ['low', 'medium', 'high'].includes(parsed.confidence) ? parsed.confidence : 'medium';

      // Strict rule: fewer than 3 comparables -> confidence MUST be low
      if (comparableCount < 3) {
        confidence = 'low';
      }

      const reasoning =
        typeof parsed.reasoning === 'string' && parsed.reasoning.length > 5
          ? parsed.reasoning
          : `Estimated based on market trends and ${comparableCount} comparable sales.`;

      sendSuccess(res, {
        suggested_start_price: suggestedStart,
        suggested_reserve_price: suggestedReserve,
        expected_price_range: { low: lowRange, high: highRange },
        confidence,
        reasoning,
        comparable_count: comparableCount,
      });
    } catch (aiErr: any) {
      clearTimeout(timeoutId);
      console.warn('[AI suggest-price] Provider failed, using fallback:', aiErr?.message || aiErr);
      // Safe graceful fallback
      sendSuccess(res, fallbackResponse());
    }
  } catch (err: any) {
    sendError(res, err.message || 'Failed to generate price suggestion.', 500);
  }
});

export default router;
