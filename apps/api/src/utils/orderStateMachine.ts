export type OrderStatus =
  | 'pending_payment'
  | 'paid'
  | 'processing'
  | 'shipped'
  | 'delivered'
  | 'completed'
  | 'disputed'
  | 'cancelled'
  | 'refunded';

const VALID_ORDER_TRANSITIONS: Record<string, string[]> = {
  pending_payment: ['paid', 'processing', 'cancelled'],
  paid: ['processing', 'shipped', 'disputed', 'cancelled', 'refunded'],
  processing: ['shipped', 'disputed', 'cancelled', 'refunded'],
  shipped: ['delivered', 'disputed'],
  delivered: ['completed', 'disputed'],
  disputed: ['completed', 'refunded', 'processing'],
  completed: [], // Terminal
  cancelled: [], // Terminal
  refunded: [],  // Terminal
};

export function isValidOrderTransition(fromStatus: string, toStatus: string): boolean {
  if (fromStatus === toStatus) return true; // No-op is valid
  const allowed = VALID_ORDER_TRANSITIONS[fromStatus];
  if (!allowed) return false;
  return allowed.includes(toStatus);
}

export function validateOrderTransition(currentStatus: string, newStatus: string): void {
  if (!isValidOrderTransition(currentStatus, newStatus)) {
    throw new Error(
      `Invalid order status transition from '${currentStatus}' to '${newStatus}'.`
    );
  }
}
