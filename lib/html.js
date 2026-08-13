/**
 * Customer-supplied text reaches two inboxes as HTML. Escaped at the point of
 * interpolation, not on the way in, so there is exactly one place to check
 * that nothing gets through raw.
 */
export function escapeHtml(value) {
  return String(value ?? '')
    .replace(/&/g, '&amp;')
    .replace(/</g, '&lt;')
    .replace(/>/g, '&gt;')
    .replace(/"/g, '&quot;')
    .replace(/'/g, '&#39;')
}
