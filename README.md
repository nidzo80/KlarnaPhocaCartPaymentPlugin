# Klarna (Hosted Payment Page) for Phoca Cart

A [Klarna](https://www.klarna.com) payment gateway plugin for [Phoca Cart](https://www.phoca.cz/phocacart) 6, built on Joomla 6's PSR-4 / `SubscriberInterface` plugin architecture. Uses Klarna's **Hosted Payment Page (HPP)** flow — the customer is redirected to a Klarna-hosted page to pay, then returned to the store.

## Features

- Full HPP payment flow: create Klarna Payments (KP) session → create HPP session → redirect → customer pays on Klarna's page → order is placed on return.
- **Refund** (full or partial) directly from the Phoca Cart admin order list.
- **Cancel order** at Klarna (only valid while the order is still `Uncaptured`).
- **Auto Capture** toggle: capture payment immediately on order placement (digital goods), or leave it authorized-only for manual capture later (physical goods — capture via Klarna's Order Management dashboard/API once shipped).
- **Market validation**: checks the customer's billing country against Klarna's actual supported country/currency table *before* redirecting, so an unsupported combination (e.g. billing country Hungary, store currency EUR) fails fast with a clear message instead of a confusing error on Klarna's own page.
- Cross-order authorization token protection: verifies the HPP `session_id` returned by Klarna matches the session that was actually created for that order, before placing the order — prevents a crafted `orderId` in the return URL from being paired with someone else's authorization.
- Sandbox/Playground and production mode, EU/NA/OC regions.
- English, Swedish, and Serbian (Latin) language files.

## Requirements

- Joomla 6
- Phoca Cart 6
- A Klarna merchant account (Playground for testing, or a live/production merchant account for real payments) with API username/password (Basic Auth)

## Installation

1. Download/build the plugin zip.
2. Phoca Cart Admin → **Payment Methods** → install/enable the Klarna plugin the way you'd add any other Phoca Cart payment method (this is a `pcp`-group plugin, so it's configured through Phoca Cart's own Payment Methods screen, not the generic Joomla Plugin Manager).

## Configuration

| Field | Description |
|---|---|
| **Sandbox Mode** | On = Klarna Playground (testing, no real money). Off = production. |
| **Region** | EU / North America / Oceania — determines the API base URL. |
| **Purchase Country (fallback)** | ISO 3166 alpha-2 code used only when the customer's billing country can't be determined. In normal checkout the billing address country is used automatically. |
| **Auto Capture** | Yes = capture payment immediately. No = authorize only, capture manually later. |
| **Order statuses** | Map Completed / Failed / Canceled / Refunded / Partially Refunded to your Phoca Cart order statuses. |
| **API Username / Password** | Basic Auth credentials from the Klarna Merchant Portal → Settings → API Credentials. |

Klarna requires the `purchase_country` sent to match its market/currency table exactly (e.g. Sweden **must** be `SEK`, Hungary **must** be `HUF` — no exceptions). If your store doesn't charge customers in the local currency of a country you want to support, Klarna simply won't offer payment methods for that combination. Restricting the payment method to specific currencies via Phoca Cart's own **Payment Method → Rules → Currency Rule** is the recommended way to hide Klarna at checkout for unsupported currencies, in addition to the in-code validation this plugin does as a second line of defense.

### Multi-market currency workaround

If your store needs to actually *serve* customers from several Klarna-supported countries (not just hide Klarna when unsupported), the currency itself has to match the shopper's country at checkout — which a single-currency or language-tied-currency store won't do on its own. For that, see the companion plugin:

**[Currency By Country for Phoca Cart](https://github.com/nidzo80/CurrencyByCountryForPhocaCart)**

It watches the billing country entered at checkout and either suggests (banner) or automatically switches the store's currency to match, so a German customer ends up paying (and being quoted to Klarna) in EUR, a Swedish customer in SEK, etc., without needing a separate site language per country. Used together, the two plugins mean: Currency By Country gets the customer into the right currency, and this plugin's market validation + Currency Rule catch anything that still falls through the cracks.

## How it works

Unlike a typical redirect-based gateway (e.g. bank redirect plugins where the order is created *before* the customer pays), Klarna's HPP flow creates the order **after** payment:

1. **`onPCPbeforeSetPaymentForm`** — builds the order payload (line items, amounts, billing country/currency), creates a Klarna Payments (KP) session, then a Hosted Payment Page (HPP) session wrapping it, and redirects the customer there. The KP and HPP session IDs are saved against the order.
2. Customer pays on Klarna's page.
3. **`onPCPafterRecievePayment`** — customer is redirected back with an `authorization_token` (and the HPP `session_id`, added specifically to verify the token belongs to this order). The plugin verifies the session match, then calls Klarna's `POST /payments/v1/authorizations/{token}/order` to actually place the order, and sets the Phoca Cart order status based on the resulting `fraud_status`.
4. **`onPCPonPaymentWebhook`** — Klarna's `status_update` callback, logged for visibility. Not used to drive state changes (the redirect-based flow above is the source of truth), by design — webhook payloads shouldn't be trusted blindly for state transitions.
5. **`onPCPgetPaymentBranchInfoAdminList`** + **`onAjaxKlarna`** — renders the Refund/Cancel panel in the admin order list and handles the AJAX calls behind it (CSRF-protected, IDOR-checked against the order's actual `payment_id`).

Order line amounts sent to Klarna must sum exactly to `order_amount` (Klarna validates this and will otherwise show an "order line details are not valid" warning in the merchant portal, though it doesn't block the order). Since Phoca Cart's shipping/discount totals aren't itemized the same way Klarna expects, any gap between the sum of product lines and the real order total is closed with a single generic "Shipping & Handling" adjustment line rather than trying to reverse-engineer every possible fee/discount row type.

## File structure

```
klarna.xml                         # Plugin manifest (group="pcp")
install.php                        # Enables the plugin on install
services/provider.php              # PSR-4 service provider
src/Extension/Klarna.php           # Event handlers, order payload building, admin panel
src/Helper/ApiHelper.php           # Klarna API client (sessions, orders, capture, refund, cancel)
src/Helper/ShopHelper.php          # Generic Phoca Cart wrapper (logging, cart, order status)
language/en-GB/ , sv-SE/ , sr-YU/
```

## Known limitations

- `captureOrder()` (Order Management API) exists in `ApiHelper` but isn't wired into any admin UI yet — for stores using `Auto Capture = No`, capturing after shipment currently has to be done from the Klarna Merchant Portal directly, not from Phoca Cart.
- `cancelOrder()` only works while Klarna considers the order `Uncaptured`. Once (partially) captured, use Refund instead — Klarna rejects cancel on a captured order, and the plugin surfaces that as an error rather than silently falling back to a refund.

## License

GNU General Public License version 3 or later, matching Phoca Cart itself.
