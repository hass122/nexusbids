import bcrypt from 'bcryptjs';
import { query } from './config/db.js';

async function seed() {
  console.log('--- Seeding NexusBids Demo Data ---');

  // 1. Seed Users
  const passwordHash = await bcrypt.hash('Nexus@1234', 10);

  // Admin
  const adminCheck = await query<any[]>('SELECT user_id FROM users WHERE email = ?', ['admin@nexusbids.pk']);
  let adminId: number;
  if (!adminCheck.length) {
    const res = await query<any>(
      `INSERT INTO users (full_name, email, phone, password, role, status, email_verified, kyc_status, seller_kyc_status, created_at)
       VALUES ('NexusBids Admin', 'admin@nexusbids.pk', '03001234567', ?, 'admin', 'active', 1, 'approved', 'approved', NOW())`,
      [passwordHash]
    );
    adminId = res.insertId;
    console.log('✓ Created Admin user: admin@nexusbids.pk / Nexus@1234');
  } else {
    adminId = adminCheck[0].user_id;
    console.log('✓ Admin user exists: ID', adminId);
  }

  // Seller
  const sellerCheck = await query<any[]>('SELECT user_id FROM users WHERE email = ?', ['seller@nexusbids.pk']);
  let sellerId: number;
  if (!sellerCheck.length) {
    const res = await query<any>(
      `INSERT INTO users (full_name, email, phone, password, role, status, email_verified, kyc_status, seller_kyc_status, created_at)
       VALUES ('Ahmed Tariq Automobiles', 'seller@nexusbids.pk', '03019876543', ?, 'seller', 'active', 1, 'approved', 'approved', NOW())`,
      [passwordHash]
    );
    sellerId = res.insertId;
    console.log('✓ Created Seller user: seller@nexusbids.pk / Nexus@1234');
  } else {
    sellerId = sellerCheck[0].user_id;
    console.log('✓ Seller user exists: ID', sellerId);
  }

  // Buyer
  const buyerCheck = await query<any[]>('SELECT user_id FROM users WHERE email = ?', ['buyer@nexusbids.pk']);
  let buyerId: number;
  if (!buyerCheck.length) {
    const res = await query<any>(
      `INSERT INTO users (full_name, email, phone, password, role, status, email_verified, kyc_status, seller_kyc_status, created_at)
       VALUES ('Usman Ghani', 'buyer@nexusbids.pk', '03215554321', ?, 'user', 'active', 1, 'approved', 'unverified', NOW())`,
      [passwordHash]
    );
    buyerId = res.insertId;
    console.log('✓ Created Buyer user: buyer@nexusbids.pk / Nexus@1234');
  } else {
    buyerId = buyerCheck[0].user_id;
    console.log('✓ Buyer user exists: ID', buyerId);
  }

  // 2. Fetch Category IDs
  const cats = await query<any[]>('SELECT category_id, category_name FROM categories');
  const catMap: Record<string, number> = {};
  cats.forEach((c) => {
    catMap[c.category_name] = c.category_id;
  });

  const carCat = catMap['Cars'] || catMap['Vehicles'] || cats[0]?.category_id || 1;
  const mobileCat = catMap['Mobiles'] || catMap['Electronics'] || cats[0]?.category_id || 1;
  const compCat = catMap['Computers'] || catMap['Electronics'] || cats[0]?.category_id || 1;
  const jewelCat = catMap['Jewelry'] || cats[0]?.category_id || 1;
  const collCat = catMap['Collectibles'] || cats[0]?.category_id || 1;

  // 3. Demo Auctions List
  const demoAuctions = [
    {
      title: 'Toyota Corolla Altis 1.6 Grande 2022 — Low Mileage Mint Condition',
      category_id: carCat,
      description: 'First owner Toyota Corolla Altis Grande 1.6. Bumper to bumper original paint, Islamabad registered, serviced only at Toyota Dealership. Fully loaded with Sunroof, Leather Seats, Cruise Control, and Push Start.',
      starting_price: 4800000,
      current_bid: 5150000,
      bid_increment: 25000,
      image: 'https://images.unsplash.com/photo-1549399542-7e3f8b79c341?w=800',
      days: 3,
    },
    {
      title: 'Apple iPhone 15 Pro Max 256GB Natural Titanium (PTA Approved)',
      category_id: mobileCat,
      description: 'Brand new condition Apple iPhone 15 Pro Max 256GB Natural Titanium. Official PTA Tax paid with invoice. 99% Battery Health, complete box and original cable included. Protected by NexusBids Escrow.',
      starting_price: 380000,
      current_bid: 415000,
      bid_increment: 5000,
      image: 'https://images.unsplash.com/photo-1695048133142-1a20484d2569?w=800',
      days: 2,
    },
    {
      title: 'MacBook Pro 16-inch M3 Max 36GB Unified RAM 1TB SSD Space Black',
      category_id: compCat,
      description: 'Top-tier powerhouse for video editing and AI machine learning. Pristine Space Black finish, AppleCare+ active until 2027. Original charger and Pakistani power adapter included.',
      starting_price: 750000,
      current_bid: 790000,
      bid_increment: 10000,
      image: 'https://images.unsplash.com/photo-1517336714731-489689fd1ca8?w=800',
      days: 4,
    },
    {
      title: '24K Gold Kundan Bridal Necklace & Earring Set (Hallmarked)',
      category_id: jewelCat,
      description: 'Certified 24 Karat Gold Bridal Set crafted by master jewelers in Lahore Anarkali. Accompanied by authentic gemological certificate and karat testing assay receipt.',
      starting_price: 1200000,
      current_bid: 1280000,
      bid_increment: 20000,
      image: 'https://images.unsplash.com/photo-1599643478518-a784e5dc4c8f?w=800',
      days: 5,
    },
    {
      title: 'Honda Civic Oriel 2021 Navigation Sunroof — Lahore Registered',
      category_id: carCat,
      description: 'Single owner executive driven Honda Civic Oriel 1.8. Lahore registered with computerized smart card and complete biometric on spot. Token tax paid for life.',
      starting_price: 5200000,
      current_bid: 5450000,
      bid_increment: 25000,
      image: 'https://images.unsplash.com/photo-1605559424843-9e4c228bf1c2?w=800',
      days: 3,
    },
    {
      title: '1947 Quaid-e-Azam Historic Independence Gold Coin (NGC Graded MS65)',
      category_id: collCat,
      description: 'Extremely rare commemorative coin celebrating the foundation of Pakistan in 1947. Graded and encapsulated MS65 by Numismatic Guaranty Company (NGC). High investor demand.',
      starting_price: 150000,
      current_bid: 185000,
      bid_increment: 5000,
      image: 'https://images.unsplash.com/photo-1621416894569-0f39ed31d247?w=800',
      days: 4,
    },
  ];

  for (const item of demoAuctions) {
    const existing = await query<any[]>('SELECT auction_id FROM auctions WHERE title = ? LIMIT 1', [item.title]);
    if (!existing.length) {
      const res = await query<any>(
        `INSERT INTO auctions 
          (seller_id, category_id, title, description, starting_price, current_bid, bid_increment, start_time, end_time, auction_status, image, view_count, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, NOW(), DATE_ADD(NOW(), INTERVAL ? DAY), 'active', ?, 0, NOW())`,
        [sellerId, item.category_id, item.title, item.description, item.starting_price, item.current_bid, item.bid_increment, item.days, item.image]
      );
      const auctionId = res.insertId;

      // Seed initial bid
      await query(
        `INSERT INTO bids (auction_id, bidder_id, bid_amount, bid_time, is_auto_bid)
         VALUES (?, ?, ?, NOW(), 0)`,
        [auctionId, buyerId, item.current_bid]
      );

      console.log(`✓ Seeded auction #${auctionId}: ${item.title.slice(0, 40)}...`);
    }
  }

  console.log('\nAll demo data seeded successfully!');
  process.exit(0);
}

seed().catch((err) => {
  console.error('Seed error:', err);
  process.exit(1);
});
