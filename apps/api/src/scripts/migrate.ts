import fs from 'fs';
import path from 'path';
import { fileURLToPath } from 'url';
import mysql from 'mysql2/promise';
import { ENV } from '../config/env.js';

const __filename = fileURLToPath(import.meta.url);
const __dirname = path.dirname(__filename);

async function runMigrations() {
  console.log('[Migration] Connecting to database:', ENV.DB.NAME);

  const conn = await mysql.createConnection({
    host: ENV.DB.HOST,
    port: ENV.DB.PORT,
    user: ENV.DB.USER,
    password: ENV.DB.PASSWORD,
    database: ENV.DB.NAME,
    multipleStatements: true,
  });

  try {
    // 1. Ensure schema_migrations table exists
    await conn.query(`
      CREATE TABLE IF NOT EXISTS \`schema_migrations\` (
        \`id\` INT AUTO_INCREMENT PRIMARY KEY,
        \`filename\` VARCHAR(255) NOT NULL UNIQUE,
        \`applied_at\` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
      ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
    `);

    // 2. Fetch already applied migrations
    const [appliedRows] = await conn.query<any[]>('SELECT filename FROM schema_migrations');
    const appliedSet = new Set(appliedRows.map((r: any) => r.filename));

    // 3. Find migration files
    const migrationsDir = path.resolve(__dirname, '../../../../db/migrations');
    if (!fs.existsSync(migrationsDir)) {
      throw new Error(`Migrations directory not found at: ${migrationsDir}`);
    }

    const files = fs
      .readdirSync(migrationsDir)
      .filter((f) => f.endsWith('.sql'))
      .sort();

    console.log(`[Migration] Found ${files.length} migration files in db/migrations`);

    let appliedCount = 0;
    for (const file of files) {
      if (appliedSet.has(file)) {
        console.log(`[Migration] [SKIP] ${file} (already applied)`);
        continue;
      }

      console.log(`[Migration] [APPLYING] ${file}...`);
      const filePath = path.join(migrationsDir, file);
      const sql = fs.readFileSync(filePath, 'utf8');

      if (sql.trim()) {
        await conn.query(sql);
      }

      await conn.query('INSERT INTO schema_migrations (filename, applied_at) VALUES (?, NOW())', [file]);
      console.log(`[Migration] [SUCCESS] ${file}`);
      appliedCount++;
    }

    console.log(`[Migration] Complete! ${appliedCount} migration(s) newly applied.`);
  } finally {
    await conn.end();
  }
}

runMigrations()
  .then(() => process.exit(0))
  .catch((err) => {
    console.error('[Migration] Failed with error:', err);
    process.exit(1);
  });
