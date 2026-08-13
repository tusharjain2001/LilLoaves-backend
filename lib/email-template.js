/**
 * The order confirmation email, built to Figma node 314:243.
 *
 * ## Why this is tables and inline styles
 *
 * Mail clients are not browsers. Outlook renders HTML with Word, Gmail strips
 * most of what it is given, and nothing supports flexbox or grid reliably.
 * Everything below is nested tables with inline styles, which is the only
 * layout language all of them speak.
 *
 * ## Dark mode, and why it is not just a media query
 *
 * iOS Mail, Apple Mail, Gmail and Outlook.com each darken emails differently,
 * and by default iOS Mail *invents* its own colour changes for a message that
 * hasn't said it can handle dark mode - which is what washes a pale palette
 * like this one out to grey mush. Three things stop that, and all three are
 * needed:
 *
 * 1. `<meta name="color-scheme">` + `<meta name="supported-color-schemes">`
 *    and the matching `:root` declarations. These are the opt-out from iOS
 *    Mail's automatic colour mangling: once a message declares it handles
 *    both schemes, iOS stops rewriting colours and renders what it is told.
 * 2. `@media (prefers-color-scheme: dark)` rules carrying `!important`.
 *    Inline styles beat a stylesheet, but not an `!important` one - so the
 *    light palette can stay inline (where clients that ignore <style> still
 *    get it) while dark mode overrides it.
 * 3. `[data-ogsc]` / `[data-ogsb]` duplicates of those rules. Outlook.com
 *    rewrites the DOM instead of honouring the media query, prefixing the
 *    body with those attributes.
 *
 * Every colour that has to change therefore carries BOTH an inline style (the
 * light value) and a class (the hook the dark rules target). Add a colour, add
 * both, or it will look right in the light and wrong in the dark.
 *
 * The three images are transparent PNGs sent as CID attachments, deliberately:
 * a baked-in background would be a light rectangle floating in a dark email,
 * and CID is the one embedding method Gmail doesn't block (it drops `data:`
 * URIs). Nothing that carries meaning is an image - every word here is real
 * text, so the email still reads with images off.
 */

import { escapeHtml } from './html.js'

/* Sent as CID attachments by lib/notify.js. Paths are resolved there, so this
   module stays pure - it builds strings and touches no filesystem. */
export const IMAGES = [
  { cid: 'llwave', file: 'email-wave.png' },
  { cid: 'llflower', file: 'email-flower.png' },
  { cid: 'llmail', file: 'email-icon-mail.png' },
]

const BRAND = "Lil' Loaves Bakery"
const CONTACT_EMAIL = 'hello@lilloavesbakery.com'

// Parkinsans is the storefront's face. Apple Mail and iOS Mail honour the
// webfont; everything else falls through the stack, so nothing depends on it.
const FONT = "'Parkinsans','Trebuchet MS',Helvetica,Arial,sans-serif"

/* Figma states these as rgba over the cream page. Composited to solid hex
   here because rgba() is unreliable in Outlook, and because a translucent
   fill over a *dark* background in dark mode would come out a different
   colour than intended. */
const C = {
  page: '#fcf7ea', // page + card background
  ink: '#57423d', // headings and body copy
  muted: '#ab866f', // the small print
  soft: '#958175', // the sign-off line
  creamCard: '#f9f1db', // Pickup Details panel
  yellowLine: '#f2e0aa', // rgba(239,216,149,.75) over page
  yellowFill: '#f5e7be', // rgba(239,216,149,.52) over page - the date pill
  yellowSolid: '#f4e4b7', // the time pill
  roseCard: '#f6eae1', // rgba(240,220,215,.48) over page - Order Summary
  totalLine: '#e5c5bc', // the Total pill's border
  pill: '#f9f0e4', // the footer email pill
  link: '#b5796b',
}

/**
 * The dark palette. Not an inversion - the same design re-grounded, so the
 * rose and honey panels stay recognisably themselves instead of turning into
 * grey boxes. Every value here pairs with one in C above.
 */
const HEAD = `
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<meta name="color-scheme" content="light dark">
<meta name="supported-color-schemes" content="light dark">
<style>
  @import url('https://fonts.googleapis.com/css2?family=Parkinsans:wght@400;500;600&display=swap');

  :root { color-scheme: light dark; supported-color-schemes: light dark; }

  /* Stop iOS/macOS Mail from blue-shifting links it thinks are addresses. */
  a[x-apple-data-detectors] { color: inherit !important; text-decoration: none !important; }

  img { border: 0; outline: none; -ms-interpolation-mode: bicubic; }
  table { border-collapse: collapse; mso-table-lspace: 0; mso-table-rspace: 0; }
  body { -webkit-text-size-adjust: 100%; -ms-text-size-adjust: 100%; }

  /* The layout below is fluid by default and needs none of this to stack -
     see the note on .ll-col in the code. These rules only tighten the result
     on a phone, so a client that drops <style> entirely (the Gmail app on a
     non-Gmail account is the usual one) still gets a correct layout rather
     than the half-width panels this replaced. */
  @media only screen and (max-width: 640px) {
    .ll-pad { padding-left: 16px !important; padding-right: 16px !important; }
    .ll-hello { font-size: 24px !important; }
    .ll-flower { width: 44px !important; }
    .ll-banner { height: auto !important; padding: 28px 0 !important; background-size: cover !important; }
    /* The panels already stack on their own; this only lets them run the
       full width of the card once they have, so they match the Pickup
       Details and Order Summary panels above and below them. Without it
       they still stack, just centred at 300px. */
    .ll-col { max-width: 100% !important; }
    .ll-colpad { padding: 0 0 12px !important; }
  }

  @media (prefers-color-scheme: dark) {
    .ll-page, .ll-shell { background-color: #241d1a !important; }
    .ll-ink, .ll-ink a { color: #f3e7dd !important; }
    .ll-muted { color: #d3b39f !important; }
    .ll-soft { color: #c3ab9c !important; }
    .ll-cream-card { background-color: #3b3125 !important; }
    .ll-yellow-box { border-color: #6f5f38 !important; }
    .ll-yellow-fill { background-color: #4b4028 !important; }
    .ll-yellow-solid { background-color: #574a2c !important; }
    .ll-rose-card { background-color: #392e2b !important; }
    .ll-total { border-color: #8a6a61 !important; }
    .ll-pill { background-color: #3a2f2a !important; }
    .ll-link, .ll-link a { color: #e8b0a1 !important; }
  }

  /* Outlook.com rewrites the DOM rather than honouring the media query. */
  [data-ogsb] .ll-page, [data-ogsb] .ll-shell { background-color: #241d1a !important; }
  [data-ogsc] .ll-ink, [data-ogsc] .ll-ink a { color: #f3e7dd !important; }
  [data-ogsc] .ll-muted { color: #d3b39f !important; }
  [data-ogsc] .ll-soft { color: #c3ab9c !important; }
  [data-ogsb] .ll-cream-card { background-color: #3b3125 !important; }
  [data-ogsb] .ll-yellow-fill { background-color: #4b4028 !important; }
  [data-ogsb] .ll-yellow-solid { background-color: #574a2c !important; }
  [data-ogsb] .ll-rose-card { background-color: #392e2b !important; }
  [data-ogsb] .ll-pill { background-color: #3a2f2a !important; }
  [data-ogsc] .ll-link, [data-ogsc] .ll-link a { color: #e8b0a1 !important; }
</style>`

const spacer = (h) =>
  `<tr><td height="${h}" style="height:${h}px;line-height:${h}px;font-size:0">&nbsp;</td></tr>`

/**
 * The rose banner. The wave is a transparent PNG laid over the page colour
 * rather than a solid rose cell, so its curved lower edge reveals whatever
 * the page is - cream in light mode, near-black in dark - instead of needing
 * a second, baked-in version per scheme. With images off the greeting still
 * reads; it just sits on plain cream.
 */
function banner(heading) {
  // The flowers are sized in percent with a max, and the heading is allowed
  // to wrap, so the row survives a 320px screen without a media query. Fixed
  // pixel widths here were what pushed the banner wider than the viewport.
  const flower = `<img src="cid:llflower" class="ll-flower" width="79" alt="" style="display:block;width:100%;max-width:79px;height:auto">`
  return `
  <tr>
    <td class="ll-page ll-banner" bgcolor="${C.page}" background="cid:llwave" align="center" valign="middle"
        height="202"
        style="height:202px;background-color:${C.page};background-image:url(cid:llwave);background-repeat:no-repeat;background-position:top center;background-size:650px 203px">
      <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0"><tr>
        <td width="14%" align="right" valign="middle" style="width:14%;padding-right:14px">${flower}</td>
        <td class="ll-ink ll-hello" align="center" valign="middle"
            style="font-family:${FONT};font-size:32px;font-weight:400;color:${C.ink};text-align:center">${heading}</td>
        <td width="14%" align="left" valign="middle" style="width:14%;padding-left:14px">${flower}</td>
      </tr></table>
    </td>
  </tr>`
}

function sectionTitle(text) {
  return `<div class="ll-ink" style="font-family:${FONT};font-size:20px;font-weight:500;letter-spacing:-1px;text-transform:uppercase;color:${C.ink};text-align:center">${text}</div>`
}

/** The Pickup Details / Pickup Dates / Pickup Time block. */
function pickupPanels(pickup) {
  return `
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
      <td class="ll-cream-card" bgcolor="${C.creamCard}" style="background-color:${C.creamCard};border-radius:16px;padding:8px 16px">
        ${sectionTitle('Pickup Details')}
        <div style="height:4px;line-height:4px;font-size:0">&nbsp;</div>
        <div class="ll-ink" style="font-family:${FONT};font-size:17px;color:${C.ink};text-align:center">${escapeHtml(pickup.storeName)}</div>
      </td>
    </tr>
    ${spacer(17)}
    <tr><td>${twoUp(
      panel('Pickup Dates', pickup.dateLabel, 'll-yellow-fill', C.yellowFill),
      panel('Pickup Time', pickup.slotLabel, 'll-yellow-solid', C.yellowSolid),
    )}</td></tr>
  </table>`
}

function panel(title, value, fillClass, fillColour) {
  return `
  <div class="ll-yellow-box" style="border:1px solid ${C.yellowLine};border-radius:16px;padding:8px">
    ${sectionTitle(title)}
    <div style="height:4px;line-height:4px;font-size:0">&nbsp;</div>
    <div class="${fillClass}" style="background-color:${fillColour};border-radius:16px;padding:4px 0">
      <div class="ll-ink" style="font-family:${FONT};font-size:17px;color:${C.ink};text-align:center">${escapeHtml(value)}</div>
    </div>
  </div>`
}

/**
 * Two side-by-side panels that stack on a narrow screen **without a media
 * query**.
 *
 * The previous version was a two-cell table whose cells were flipped to
 * `display:block` by a media query. That stacked them but left them at half
 * width on a real phone, because a `td` forced to block no longer resolves
 * `width:100%` against anything useful once the table has collapsed to
 * shrink-to-fit. It also meant any client that drops `<style>` — the Gmail
 * app on a non-Gmail account being the common one — got no stacking at all.
 *
 * Inline-blocks with `width:100%;max-width:` need no media query: two 300px
 * boxes simply cannot sit side by side in less than 600px, so they wrap on
 * their own, at full width, everywhere. `font-size:0` on the wrapper kills
 * the whitespace gap between them, and the bottom padding becomes the gap
 * once they stack (below the desktop layout it is invisible, since the next
 * section carries its own 43px spacer).
 *
 * Outlook's Word engine ignores inline-block, so it gets a ghost table.
 */
function twoUp(left, right) {
  const col = (inner) =>
    `<div class="ll-col" style="display:inline-block;width:100%;max-width:300px;vertical-align:top;font-size:0"><div class="ll-colpad" style="padding:0 9px 12px">${inner}</div></div>`

  return `
  <div style="font-size:0;text-align:center">
    <!--[if mso]><table role="presentation" width="600" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td width="300" valign="top"><![endif]-->
    ${col(left)}
    <!--[if mso]></td><td width="300" valign="top"><![endif]-->
    ${col(right)}
    <!--[if mso]></td></tr></table><![endif]-->
  </div>`
}

/**
 * Order Summary: one "Name × qty" line per item, then the total in a pill.
 * `lines` and `totalFormatted` are already resolved and priced by the caller
 * from the server's own quote - this module formats nothing.
 */
function orderSummary(lines, totalFormatted) {
  const items = lines
    .map((line) => {
      const name = line.packSize
        ? `${escapeHtml(line.name)} (${escapeHtml(line.packSize)})`
        : escapeHtml(line.name)
      // The class goes on the same element as the inline colour, never on a
      // wrapper: a dark-mode rule on a parent is only inherited, and an
      // inline colour on the child beats inheritance - which would leave
      // these lines dark-on-dark.
      return `<div class="ll-ink" style="font-family:${FONT};font-size:17px;color:${C.ink};text-align:center;padding:2px 0">${name} &times; ${line.qty}</div>`
    })
    .join('')

  return `
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr>
      <td class="ll-rose-card" bgcolor="${C.roseCard}" align="center"
          style="background-color:${C.roseCard};border-radius:16px;padding:32px 16px">
        ${sectionTitle('Order Summary')}
        <div style="height:17px;line-height:17px;font-size:0">&nbsp;</div>
        ${items}
        <div style="height:24px;line-height:24px;font-size:0">&nbsp;</div>
        <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr>
          <td class="ll-total" align="center"
              style="border:2px solid ${C.totalLine};border-radius:100px;padding:8px 31px">
            <span class="ll-ink" style="font-family:${FONT};font-size:17px;font-weight:500;color:${C.ink};white-space:nowrap">Total: ${escapeHtml(totalFormatted)}</span>
          </td>
        </tr></table>
      </td>
    </tr>
  </table>`
}

function footerBlock() {
  return `
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr><td class="ll-soft" align="center" style="font-family:${FONT};font-size:14px;color:${C.soft};text-align:center">From our table to yours, one hand-shaped loaf at a time.</td></tr>
    ${spacer(8)}
    <tr><td class="ll-ink" align="center" style="font-family:${FONT};font-size:17px;font-weight:600;color:${C.ink};text-align:center">&#129505; The ${escapeHtml(BRAND.replace(' Bakery', ''))} Family</td></tr>
    ${spacer(38)}
    <tr><td align="center">
      <table role="presentation" cellpadding="0" cellspacing="0" border="0" align="center"><tr>
        <td class="ll-pill" bgcolor="${C.pill}" align="center"
            style="background-color:${C.pill};border-radius:100px;padding:8px 28px">
          <table role="presentation" cellpadding="0" cellspacing="0" border="0"><tr>
            <td valign="middle"><img src="cid:llmail" width="16" height="16" alt="" style="display:block;width:16px;height:16px"></td>
            <td width="5" style="width:5px">&nbsp;</td>
            <td class="ll-link" valign="middle" style="font-family:${FONT};font-size:14px;color:${C.link}">
              <a href="mailto:${CONTACT_EMAIL}" style="color:${C.link};text-decoration:underline">${CONTACT_EMAIL}</a>
            </td>
          </tr></table>
        </td>
      </tr></table>
    </td></tr>
  </table>`
}

/** The outer shell every message shares: page background, 650px card, banner. */
function shell({ heading, preheader, body }) {
  return `<!doctype html>
<html lang="en"><head>${HEAD}<title>${escapeHtml(heading)}</title></head>
<body class="ll-page" style="margin:0;padding:0;background-color:${C.page}">
  <div style="display:none;max-height:0;overflow:hidden;opacity:0">${escapeHtml(preheader)}</div>
  <table role="presentation" class="ll-page" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="${C.page}" style="background-color:${C.page}">
    <tr><td align="center" style="padding:0">
      <!--[if mso]><table role="presentation" width="650" cellpadding="0" cellspacing="0" border="0" align="center"><tr><td><![endif]-->
      <!-- width:100% + max-width, not width="650": a fixed width attribute is
           what made this wider than a phone screen and forced the client to
           shrink or crop it. Outlook, which ignores max-width, gets the ghost
           table above instead. -->
      <table role="presentation" class="ll-shell" width="100%" cellpadding="0" cellspacing="0" border="0" bgcolor="${C.page}" style="width:100%;max-width:650px;background-color:${C.page};border-radius:16px">
        ${banner(escapeHtml(heading))}
        ${spacer(40)}
        <tr><td class="ll-pad" style="padding:0 25px">
          ${body}
        </td></tr>
        ${spacer(48)}
      </table>
      <!--[if mso]></td></tr></table><![endif]-->
    </td></tr>
  </table>
</body></html>`
}

/* "Moona Patel" -> "MOONA". Figma greets by first name in caps. */
function greetingName(fullName) {
  return String(fullName ?? '').trim().split(/\s+/)[0].toUpperCase()
}

function orderNumberBlock(orderNumber, note) {
  return `
  <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
    <tr><td class="ll-muted" align="center" style="font-family:${FONT};font-size:12px;color:${C.muted};text-align:center;line-height:18px">${note}</td></tr>
    ${spacer(19)}
    <tr><td class="ll-ink" align="center" style="font-family:${FONT};font-size:17px;font-weight:600;color:${C.ink};text-align:center">Order #: ${escapeHtml(orderNumber)}</td></tr>
  </table>`
}

/** The customer's confirmation - Figma 314:243. */
export function customerEmail({ contact, lines, pickup, totalFormatted, orderNumber }) {
  const body = `
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
      <tr><td class="ll-ink" align="center" style="font-family:${FONT};font-size:20px;font-weight:500;color:${C.ink};text-align:center">Pick Up Confirmed! See you soon.</td></tr>
      ${spacer(8)}
      <tr><td class="ll-muted" align="center" style="font-family:${FONT};font-size:12px;color:${C.muted};text-align:center;line-height:18px">Thank you for supporting our family bakery. We can&rsquo;t wait to welcome you and share our handcrafted bakes with you.</td></tr>
      ${spacer(43)}
      <tr><td>${pickupPanels(pickup)}</td></tr>
      ${spacer(43)}
      <tr><td>${orderSummary(lines, totalFormatted)}</td></tr>
      ${spacer(43)}
      <tr><td>${orderNumberBlock(orderNumber, 'Before You Arrive*<br>Please bring your order confirmation or order number when collecting your order')}</td></tr>
      ${spacer(48)}
      <tr><td>${footerBlock()}</td></tr>
    </table>`

  return {
    subject: `Pick up confirmed — order ${orderNumber}`,
    html: shell({
      heading: `Thank You, ${greetingName(contact.name)}!`,
      preheader: `Your ${BRAND} order ${orderNumber} is confirmed for ${pickup.dateLabel}, ${pickup.slotLabel}.`,
      body,
    }),
  }
}

/**
 * The bakery's copy. Same shell so the two read as one system, with the
 * customer's contact details in place of the "see you soon" note - this is
 * the message someone has to act on.
 */
export function bakeryEmail({ contact, lines, pickup, totalFormatted, orderNumber }) {
  const row = (label, value) => `
    <tr>
      <td class="ll-soft" width="90" style="width:90px;font-family:${FONT};font-size:14px;color:${C.soft};padding:6px 0">${label}</td>
      <td class="ll-ink" style="font-family:${FONT};font-size:17px;color:${C.ink};padding:6px 0">${value}</td>
    </tr>`

  const body = `
    <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
      <tr><td class="ll-ink" align="center" style="font-family:${FONT};font-size:20px;font-weight:500;color:${C.ink};text-align:center">New pickup order to prepare.</td></tr>
      ${spacer(43)}
      <tr><td class="ll-cream-card" bgcolor="${C.creamCard}" style="background-color:${C.creamCard};border-radius:16px;padding:16px">
        ${sectionTitle('Customer')}
        <div style="height:8px;line-height:8px;font-size:0">&nbsp;</div>
        <table role="presentation" width="100%" cellpadding="0" cellspacing="0" border="0">
          ${row('Name', escapeHtml(contact.name))}
          ${row('Email', `<a href="mailto:${escapeHtml(contact.email)}" class="ll-ink" style="color:${C.ink}">${escapeHtml(contact.email)}</a>`)}
          ${row('Phone', `<a href="tel:${escapeHtml(contact.phone)}" class="ll-ink" style="color:${C.ink}">${escapeHtml(contact.phone)}</a>`)}
        </table>
      </td></tr>
      ${spacer(43)}
      <tr><td>${pickupPanels(pickup)}</td></tr>
      ${spacer(43)}
      <tr><td>${orderSummary(lines, totalFormatted)}</td></tr>
      ${spacer(43)}
      <tr><td>${orderNumberBlock(orderNumber, 'The customer was asked to bring this number when collecting.')}</td></tr>
      ${spacer(48)}
      <tr><td>${footerBlock()}</td></tr>
    </table>`

  return {
    subject: `New Pickup Order — ${contact.name} — ${orderNumber}`,
    html: shell({
      heading: `New Order, ${orderNumber}`,
      preheader: `${contact.name} · ${pickup.dateLabel}, ${pickup.slotLabel} · ${totalFormatted}`,
      body,
    }),
  }
}
