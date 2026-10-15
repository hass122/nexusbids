import { query, withTransaction } from '../config/db.js';

export interface ShippingAddressInput {
  full_name: string;
  phone: string;
  address: string;
  city: string;
  province?: string;
  postal_code?: string;
  is_default?: boolean;
}

export interface CreateShipmentInput {
  order_id: number;
  courier: string;
  tracking_number: string;
  estimated_delivery?: string;
  note?: string;
}

// Aligned exactly with the `orders.shipping_status` ENUM in schema.sql
export const VALID_SHIPPING_STATUSES = [
  'not_started',
  'awaiting_address',
  'ready_to_ship',
  'shipped',
  'in_transit',
  'out_for_delivery',
  'delivered',
  'failed',
  'returned',
] as const;

export const VALID_SHIPPING_TRANSITIONS: Record<string, string[]> = {
  not_started: ['awaiting_address', 'ready_to_ship', 'shipped'],
  awaiting_address: ['ready_to_ship', 'shipped'],
  ready_to_ship: ['shipped', 'in_transit'],
  shipped: ['in_transit', 'out_for_delivery', 'failed', 'returned'],
  in_transit: ['out_for_delivery', 'delivered', 'failed', 'returned'],
  out_for_delivery: ['delivered', 'failed', 'returned'],
  failed: ['in_transit', 'out_for_delivery', 'returned'],
  delivered: [],
  returned: [],
};

export class ShippingService {
  // ─── Addresses ──────────────────────────────────────────────
  static async getAddresses(userId: number) {
    return await query<any[]>(
      `SELECT * FROM shipping_addresses 
       WHERE user_id = ? 
       ORDER BY is_default DESC, created_at DESC`,
      [userId]
    );
  }

  static async addAddress(userId: number, input: ShippingAddressInput) {
    return await withTransaction(async (conn) => {
      if (input.is_default) {
        await conn.execute(
          'UPDATE shipping_addresses SET is_default = 0 WHERE user_id = ?',
          [userId]
        );
      }

      // Check if this is the first address, make it default if so
      const [existing] = await conn.execute<any[]>(
        'SELECT COUNT(*) as count FROM shipping_addresses WHERE user_id = ?',
        [userId]
      );
      const isDefault = input.is_default || existing[0]?.count === 0 ? 1 : 0;

      const [res] = await conn.execute<any>(
        `INSERT INTO shipping_addresses 
          (user_id, full_name, phone, address, city, province, postal_code, is_default, created_at)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, NOW())`,
        [
          userId,
          input.full_name,
          input.phone,
          input.address,
          input.city,
          input.province || null,
          input.postal_code || null,
          isDefault,
        ]
      );

      return { address_id: res.insertId, ...input, is_default: isDefault === 1 };
    });
  }

  static async setDefaultAddress(userId: number, addressId: number) {
    return await withTransaction(async (conn) => {
      await conn.execute(
        'UPDATE shipping_addresses SET is_default = 0 WHERE user_id = ?',
        [userId]
      );
      await conn.execute(
        'UPDATE shipping_addresses SET is_default = 1 WHERE address_id = ? AND user_id = ?',
        [addressId, userId]
      );
      return { success: true };
    });
  }

  static async deleteAddress(userId: number, addressId: number) {
    await query(
      'DELETE FROM shipping_addresses WHERE address_id = ? AND user_id = ?',
      [addressId, userId]
    );
    return { deleted: true };
  }

  // ─── Shipments ──────────────────────────────────────────────
  static async createShipment(sellerId: number, input: CreateShipmentInput) {
    return await withTransaction(async (conn) => {
      // Verify seller owns this order
      const [orders] = await conn.execute<any[]>(
        `SELECT o.*, a.auction_id, a.title as auction_title 
         FROM orders o
         JOIN auctions a ON a.auction_id = o.auction_id
         WHERE o.order_id = ? AND o.seller_id = ? FOR UPDATE`,
        [input.order_id, sellerId]
      );

      if (!orders.length) {
        throw new Error('Order not found or unauthorized.');
      }

      const order = orders[0];

      if (order.shipping_status === 'shipped' || order.shipping_status === 'delivered') {
        throw new Error(`Order has already been marked as ${order.shipping_status}.`);
      }

      // Find buyer shipping address
      const [addresses] = await conn.execute<any[]>(
        `SELECT address_id FROM shipping_addresses 
         WHERE user_id = ? 
         ORDER BY is_default DESC, created_at DESC LIMIT 1`,
        [order.buyer_id]
      );

      const addressId = order.shipping_address_id || addresses[0]?.address_id || null;
      if (!addressId) {
        throw new Error('Buyer has no shipping address on file. Cannot create shipment.');
      }

      // Insert shipment record
      const [shipmentRes] = await conn.execute<any>(
        `INSERT INTO shipments 
          (auction_id, order_id, address_id, courier, tracking_number, shipped_at, estimated_delivery, status, created_at)
         VALUES (?, ?, ?, ?, ?, NOW(), ?, 'shipped', NOW())`,
        [
          order.auction_id,
          input.order_id,
          addressId,
          input.courier,
          input.tracking_number,
          input.estimated_delivery || null,
        ]
      );

      const shipmentId = shipmentRes.insertId;

      // Insert tracking event
      await conn.execute(
        `INSERT INTO shipment_tracking 
          (shipment_id, order_id, status, location, note, created_at)
         VALUES (?, ?, 'shipped', 'Origin Courier Facility', ?, NOW())`,
        [
          shipmentId,
          input.order_id,
          input.note || `Shipped via ${input.courier}. Tracking: ${input.tracking_number}`,
        ]
      );

      // Update order status
      await conn.execute(
        `UPDATE orders SET 
          order_status = 'shipped',
          shipping_status = 'shipped',
          tracking_number = ?,
          courier = ?,
          shipped_at = NOW(),
          updated_at = NOW()
         WHERE order_id = ?`,
        [input.tracking_number, input.courier, input.order_id]
      );

      // Notify buyer
      await conn.execute(
        `INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at)
         VALUES (?, 'order_shipped', 'Your order is on the way!', ?, ?, 0, NOW())`,
        [
          order.buyer_id,
          `Your order for "${order.auction_title}" has been shipped via ${input.courier} (Tracking: ${input.tracking_number}).`,
          `/buyer/order-details.html?id=${input.order_id}`,
        ]
      );

      return {
        shipment_id: shipmentId,
        order_id: input.order_id,
        tracking_number: input.tracking_number,
        courier: input.courier,
        status: 'in_transit',
      };
    });
  }

  // ─── Tracking ───────────────────────────────────────────────
  static async getTracking(orderId: number, userId: number, role: string) {
    const orders = await query<any[]>(
      'SELECT order_id, buyer_id, seller_id, shipping_status, tracking_number, courier, shipped_at, delivered_at FROM orders WHERE order_id = ? LIMIT 1',
      [orderId]
    );

    if (!orders.length) throw new Error('Order not found.');
    const order = orders[0];

    if (role !== 'admin' && order.buyer_id !== userId && order.seller_id !== userId) {
      throw new Error('Forbidden. You do not have permission to view this order.');
    }

    const shipments = await query<any[]>(
      'SELECT * FROM shipments WHERE order_id = ? ORDER BY created_at DESC LIMIT 1',
      [orderId]
    );

    const history = await query<any[]>(
      'SELECT * FROM shipment_tracking WHERE order_id = ? ORDER BY created_at ASC',
      [orderId]
    );

    return {
      order_id: orderId,
      shipping_status: order.shipping_status,
      courier: order.courier,
      tracking_number: order.tracking_number,
      shipped_at: order.shipped_at,
      delivered_at: order.delivered_at,
      shipment: shipments[0] || null,
      timeline: history,
    };
  }

  static async addTrackingEvent(
    userId: number,
    role: string,
    orderId: number,
    status: string,
    location?: string,
    note?: string
  ) {
    const orders = await query<any[]>(
      `SELECT o.order_id, o.seller_id, o.buyer_id, o.shipping_status, a.title as auction_title
       FROM orders o
       JOIN auctions a ON a.auction_id = o.auction_id
       WHERE o.order_id = ? LIMIT 1`,
      [orderId]
    );

    if (!orders.length) throw new Error('Order not found.');
    const order = orders[0];

    if (role !== 'admin' && order.seller_id !== userId) {
      throw new Error('Unauthorized. Only the seller or administrator can update tracking events.');
    }

    // Normalize old frontend statuses to match database ENUM
    let normStatus = status.toLowerCase().trim();
    if (normStatus === 'dispatched') normStatus = 'shipped';
    if (normStatus === 'delivery_failed') normStatus = 'failed';
    if (normStatus === 'awaiting_shipment') normStatus = 'ready_to_ship';

    if (!(VALID_SHIPPING_STATUSES as readonly string[]).includes(normStatus)) {
      throw new Error(`Invalid shipping status '${status}'. Allowed statuses: ${VALID_SHIPPING_STATUSES.join(', ')}`);
    }

    const currentStatus = (order.shipping_status || 'shipped').toLowerCase();
    if (currentStatus !== normStatus && role !== 'admin') {
      const allowed = VALID_SHIPPING_TRANSITIONS[currentStatus] || [];
      if (!allowed.includes(normStatus)) {
        throw new Error(`Invalid shipping transition from '${currentStatus}' to '${normStatus}'.`);
      }
    }

    const shipments = await query<any[]>(
      'SELECT shipment_id FROM shipments WHERE order_id = ? ORDER BY created_at DESC LIMIT 1',
      [orderId]
    );

    const shipmentId = shipments[0]?.shipment_id;
    if (!shipmentId) {
      throw new Error('No shipment found for this order. Please create a shipment first before adding tracking events.');
    }

    await query(
      `INSERT INTO shipment_tracking (shipment_id, order_id, status, location, note, created_at)
       VALUES (?, ?, ?, ?, ?, NOW())`,
      [shipmentId, orderId, normStatus, location || null, note || null]
    );

    await query(
      `UPDATE orders SET 
         shipping_status = ?,
         ${normStatus === 'delivered' ? "order_status = 'delivered', delivered_at = COALESCE(delivered_at, NOW())," : ''}
         updated_at = NOW() 
       WHERE order_id = ?`,
      [normStatus, orderId]
    );

    if (shipmentId > 0) {
      await query(
        'UPDATE shipments SET status = ?, updated_at = NOW() WHERE shipment_id = ?',
        [normStatus, shipmentId]
      ).catch(() => { });
    }

    const statusLabels: Record<string, string> = {
      in_transit: 'is in transit',
      out_for_delivery: 'is out for delivery today',
      delivered: 'has been delivered. 48-hour inspection window is now active',
      failed: 'delivery attempt was unsuccessful',
      returned: 'has been returned to origin',
    };
    const actionLabel = statusLabels[normStatus] || `status updated to ${normStatus}`;

    await query(
      `INSERT INTO notifications (user_id, type, title, message, link, is_read, created_at)
       VALUES (?, 'shipping_update', ?, ?, ?, 0, NOW())`,
      [
        order.buyer_id,
        `Tracking Update: Order #${orderId}`,
        `Your parcel for "${order.auction_title}" ${actionLabel}.`,
        `/buyer/order-details.html?id=${orderId}`,
      ]
    ).catch(() => { });

    return { success: true, status: normStatus };
  }
}
