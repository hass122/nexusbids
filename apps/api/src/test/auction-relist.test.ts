import { describe, it } from 'node:test';
import assert from 'node:assert/strict';
import { query } from '../config/db.js';
import { AuctionService } from '../services/auction.service.js';

describe('Auction Relist Feature Tests', () => {
  it('should successfully relist an unsold auction with reset stats and copied gallery images', async () => {
    // 1. Create a dummy seller
    const testEmail = `seller_relist_${Date.now()}@example.com`;
    const userResult = await query<any>(
      `INSERT INTO users (full_name, email, password, role, status, email_verified, kyc_status, seller_kyc_status, created_at)
       VALUES ('Relist Seller', ?, 'hash123', 'seller', 'active', 1, 'approved', 'approved', NOW())`,
      [testEmail]
    );
    const sellerId = userResult.insertId;

    // 2. Insert category
    const catResult = await query<any>('SELECT category_id FROM categories LIMIT 1');
    const categoryId = catResult[0]?.category_id || 1;

    // 3. Create an unsold auction
    const origAuctionResult = await query<any>(
      `INSERT INTO auctions
        (seller_id, category_id, title, description, starting_price, current_bid,
         reserve_price, buy_now_price, bid_increment, start_time, end_time,
         auction_status, image, view_count, watch_count, created_at)
       VALUES (?, ?, 'Vintage Rolex Watch', 'Authentic mechanical watch', 50000.00, 50000.00,
               60000.00, 80000.00, 1000.00, DATE_SUB(NOW(), INTERVAL 8 DAY), DATE_SUB(NOW(), INTERVAL 1 DAY),
               'unsold', 'https://example.com/watch1.jpg', 125, 14, DATE_SUB(NOW(), INTERVAL 8 DAY))`,
      [sellerId, categoryId]
    );
    const originalAuctionId = origAuctionResult.insertId;

    // Add 2 gallery images
    await query(
      'INSERT INTO auction_images (auction_id, image_path) VALUES (?, ?), (?, ?)',
      [originalAuctionId, 'https://example.com/watch1.jpg', originalAuctionId, 'https://example.com/watch2.jpg']
    );

    // 4. Test ownership check: unauthorized seller attempting to relist
    await assert.rejects(
      async () => {
        await AuctionService.relistAuction(originalAuctionId, sellerId + 999, 'seller');
      },
      {
        message: 'You do not have permission to relist this auction.',
      }
    );

    // 5. Relist as the rightful owner
    const relistRes = await AuctionService.relistAuction(originalAuctionId, sellerId, 'seller');

    assert.ok(relistRes.new_auction_id, 'New auction ID should be generated');
    assert.equal(relistRes.relisted_from, originalAuctionId, 'relisted_from should point to original');
    assert.equal(relistRes.status, 'pending');

    // 6. Verify original auction status is updated to 'relisted'
    const origRows = await query<any[]>('SELECT auction_status FROM auctions WHERE auction_id = ?', [originalAuctionId]);
    assert.equal(origRows[0].auction_status, 'relisted', 'Original auction status should be relisted');

    // 7. Verify new auction properties and reset stats
    const newRows = await query<any[]>('SELECT * FROM auctions WHERE auction_id = ?', [relistRes.new_auction_id]);
    const newAuction = newRows[0];

    assert.equal(newAuction.relisted_from, originalAuctionId);
    assert.equal(parseFloat(newAuction.current_bid), 0, 'Current bid must be reset to 0');
    assert.equal(newAuction.view_count, 0, 'View count must be reset to 0');
    assert.equal(newAuction.watch_count, 0, 'Watch count must be reset to 0');
    assert.equal(newAuction.title, 'Vintage Rolex Watch');
    assert.equal(newAuction.image, 'https://example.com/watch1.jpg');

    // 8. Verify gallery images were copied
    const newGalleryRows = await query<any[]>('SELECT image_path FROM auction_images WHERE auction_id = ? ORDER BY image_id ASC', [relistRes.new_auction_id]);
    assert.equal(newGalleryRows.length, 2, 'New auction must have both copied gallery images');
    assert.equal(newGalleryRows[0].image_path, 'https://example.com/watch1.jpg');
    assert.equal(newGalleryRows[1].image_path, 'https://example.com/watch2.jpg');

    // 9. Verify sold auction cannot be relisted
    await query("UPDATE auctions SET auction_status = 'sold', sold_at = NOW() WHERE auction_id = ?", [relistRes.new_auction_id]);
    await assert.rejects(
      async () => {
        await AuctionService.relistAuction(relistRes.new_auction_id, sellerId, 'seller');
      },
      /Cannot relist an auction with status 'sold'/
    );

    // Cleanup
    await query('DELETE FROM auction_images WHERE auction_id IN (?, ?)', [originalAuctionId, relistRes.new_auction_id]);
    await query('DELETE FROM auctions WHERE auction_id IN (?, ?)', [originalAuctionId, relistRes.new_auction_id]);
    await query('DELETE FROM users WHERE user_id = ?', [sellerId]);
    console.log('✅ Auction Relist test passed successfully!');
    process.exit(0);
  });
});
