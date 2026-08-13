/**
 * The WooCommerce Store API returns money as a minor-unit string ("2113")
 * alongside the currency's minor-unit count (2), meaning $21.13.
 *
 * This module is the only place in this service that converts or formats
 * money. It is a deliberate byte-for-byte copy of the frontend repo's
 * src/lib/money.js: an email's figures and the storefront's must round and
 * group identically, and the two repos deploy separately so neither can
 * import the other. If you change one, change both.
 */

export function minorToMajor(minorString, minorUnit) {
  const n = Number(minorString)
  if (minorString === '' || minorString === null || !Number.isFinite(n)) return 0
  return n / 10 ** minorUnit
}

export function formatPrice(prices, minorString = prices.price) {
  const unit = prices.currency_minor_unit
  const amount = minorToMajor(minorString, unit)
  const [whole, fraction] = Math.abs(amount).toFixed(unit).split('.')
  const grouped = whole.replace(
    /\B(?=(\d{3})+(?!\d))/g,
    prices.currency_thousand_separator,
  )
  const body = fraction
    ? `${grouped}${prices.currency_decimal_separator}${fraction}`
    : grouped
  const sign = amount < 0 ? '-' : ''
  return `${sign}${prices.currency_prefix}${body}${prices.currency_suffix}`
}
