import mysql from 'mysql2/promise';
import { ENV } from './env.js';

let pool: mysql.Pool | null = null;

// Detect if running on Vercel serverless
const isVercel = process.env.VERCEL === '1';
// Enable SSL for cloud MySQL providers (PlanetScale, Aiven, Railway, etc.)
const useSSL = process.env.DB_SSL === 'true' || ENV.IS_PRODUCTION;

export function getDbPool(): mysql.Pool {
  if (!pool) {
    pool = mysql.createPool({
      host: ENV.DB.HOST,
      port: ENV.DB.PORT,
      user: ENV.DB.USER,
      password: ENV.DB.PASSWORD,
      database: ENV.DB.NAME,
      waitForConnections: true,
      connectionLimit: ENV.DB.CONNECTION_LIMIT,
      queueLimit: 0,
      timezone: '+05:00', // Asia/Karachi timezone
      charset: 'utf8mb4',
      // Keep-alive is not needed in serverless (Vercel) — connections are ephemeral
      enableKeepAlive: !isVercel,
      keepAliveInitialDelay: isVercel ? 0 : 10000,
      // SSL for cloud databases (PlanetScale, Aiven, Railway)
      ...(useSSL && {
        ssl: {
          // Allow self-signed certs in production cloud providers
          rejectUnauthorized: false,
        },
      }),
    });
  }
  return pool;
}

// Transaction execution helper with automatic rollback on error
export async function withTransaction<T>(
  callback: (connection: mysql.PoolConnection) => Promise<T>
): Promise<T> {
  const db = getDbPool();
  const connection = await db.getConnection();
  await connection.beginTransaction();

  try {
    const result = await callback(connection);
    await connection.commit();
    return result;
  } catch (error) {
    await connection.rollback();
    throw error;
  } finally {
    connection.release();
  }
}

// Simple query helper
export async function query<T = any>(sql: string, params: any[] = []): Promise<T> {
  const db = getDbPool();
  const [rows] = await db.execute(sql, params);
  return rows as T;
}
