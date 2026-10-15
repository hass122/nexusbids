import { query } from '../../apps/api/src/config/db.js';

export async function cleanupTestUser(email: string) {
    const users = await query<any[]>('SELECT user_id FROM users WHERE email = ?', [email]);
    if (users.length) {
        const userId = users[0].user_id;
        await query('DELETE FROM refresh_tokens WHERE user_id = ?', [userId]);
        await query('DELETE FROM bids WHERE bidder_id = ?', [userId]);
        await query('DELETE FROM watchlist WHERE user_id = ?', [userId]);
        await query('DELETE FROM kyc_verifications WHERE user_id = ?', [userId]);
        await query('DELETE FROM users WHERE user_id = ?', [userId]);
    }
}

export async function approveKyc(userId: number) {
    // Approve both buyer and seller KYC statuses for testing purposes
    await query("UPDATE users SET kyc_status = 'approved', seller_kyc_status = 'approved' WHERE user_id = ?", [userId]);
}