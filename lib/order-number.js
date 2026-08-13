import { randomInt } from 'node:crypto'

/**
 * The customer-facing reference on the confirmation email — "LL-10324" in
 * Figma 314:276, five digits after the prefix.
 *
 * It is generated here, randomly, because there is nothing to derive it from:
 * the /pickup flow places no WooCommerce order, so there is no order id, and
 * this service holds no database, so there is no counter to increment. Five
 * digits from a CSPRNG gives roughly a 1-in-90,000 chance of two orders
 * sharing a number, which is fine for what this is — a human reference the
 * customer reads out at the counter, next to their name and collection slot.
 * It is not a key, and nothing looks anything up by it.
 *
 * When Place Order eventually creates a real WooCommerce order, replace this
 * with that order's number and delete this file — a real order number is
 * unique, sequential, and findable in wp-admin, which this is not.
 */
export function makeOrderNumber() {
  return `LL-${randomInt(10000, 100000)}`
}
