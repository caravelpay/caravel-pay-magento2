# Caravel Pay for Magento 2, Adobe Commerce and Mage-OS

- Docs: <https://caravelpay.app/docs/integrations#magento>
- Download and checksums: <https://caravelpay.app/plugins/#magento>
- Status: beta. It passes our tests against a stand-in of the platform and has not yet run on a production install. Tell us if anything looks off.
- Questions: [hello@caravelpay.app](mailto:hello@caravelpay.app)

Accept card, Apple Pay and Google Pay and get paid in USDC on Polygon (or in the token the buyer paid
with), minus the 2% Caravel fee. No account, dashboard or API key: paste your payout wallet, save, and the
module connects the store.

| | |
|---|---|
| Magento / Adobe Commerce | 2.4.4 or later (Luma-based checkout; Hyvä Checkout needs its own payment renderer, not included) |
| Mage-OS | 1.0 or later |
| PHP | 7.4 or 8.1–8.4, whichever your Magento version supports, with `curl` and `json` |
| Order currency | USD, and EUR, GBP, CAD, AUD, NZD, SEK, NOK, DKK, CHF, PLN, CZK, HUF, RON, HKD, SGD, MXN, BRL, ZAR, ILS, INR, THB, MYR, PHP, TRY, JPY and KRW, converted to US dollars at checkout (see *Other currencies*). Caravel Pay isn't offered for any other order currency. |
| Cron | Magento cron must run (unpaid orders are cancelled by the module's cron job) |
| Payout | Your own Polygon wallet. USDC for card payments; USDC.e or POL when the buyer paid in those. Never swapped. |

## Install

**Option A, Composer artifact (recommended):**

```sh
mkdir -p artifacts && cp caravel-pay-magento2-composer.zip artifacts/
composer config repositories.caravelpay artifact ./artifacts
composer require caravelpay/module-caravel-pay:1.2.1
bin/magento module:enable Caravel_Pay
bin/magento setup:upgrade
bin/magento setup:di:compile            # production mode
bin/magento setup:static-content:deploy # production mode
bin/magento cache:flush
```

**Option B, app/code:** unzip `caravel-pay-magento2.zip` in the Magento root (it creates
`app/code/Caravel/Pay`), then run the `bin/magento` commands above.

Then go to **Stores › Configuration › Sales › Payment Methods › Caravel Pay**, set **Enabled** to Yes,
paste your **Payout wallet** (a Polygon address you control, starting with `0x`) and **Save Config**.
The **Connection** line shows **Connected** with your merchant id. Place a test order with a cheap
product and pay it on the hosted checkout.

## Settings

| Setting | What it does |
|---|---|
| Enabled | Offer Caravel Pay at checkout. |
| Payout wallet | Every payout goes here (default scope; one connection per Magento installation). Saving a new wallet connects again: new orders pay out to it, orders already started finish on the old one (the old credentials stay valid 7 days for their webhooks). |
| Reconnect on save | Gets new credentials for the same wallet once, for example if the connection line says they were rejected. |
| Title, Instructions at checkout | What buyers see. |
| Who pays the fees | *Seller pays everything* (default): your buyer pays the order total and both fees come off what you receive. *Split: buyer pays the card fee*: the card provider adds its fee to your buyer's total and you pay the 2% Caravel fee. *Buyer pays everything*: the 2% Caravel fee is added to your buyer's total as well, so you receive the full order total. Buyers see a line about it under the payment method and the full amount on the checkout before they pay. Card surcharges are restricted in some countries (for example on consumer cards in the EU and the UK): check your local rules first. |
| Cancel unpaid orders after (hours) | Default 24. See below. |
| Applicable countries, Sort order | As for any payment method. |

Staging only: set `CARAVELPAY_API_BASE` (an `https://` origin, or `http://127.0.0.1:…`) as an
environment variable or PHP constant to talk to another API host.

## How orders update

Place Order creates the order as **Pending Payment** with the status **Awaiting Caravel Pay payment**
(not Magento's own *pending_payment* status, so Magento's "Pending Payment Order Lifetime" cleanup
doesn't cancel it) and sends the buyer to the hosted checkout. The module then waits for a signed
webhook, verifies it, re-reads the checkout with the signed API (`GET /v1/checkouts/{id}`) and only
then changes the order:

| Checkout status | Magento order |
|---|---|
| `awaiting_payment`, `confirming` | Awaiting Caravel Pay payment (a comment when a payment is detected) |
| `paid` | **Invoiced** (`registerCaptureNotification`, transaction id = payout tx hash) → Processing; the order email is sent now |
| `underpaid` | **On Hold**, comment with how much arrived |
| `review` | **On Hold**, comment |
| `expired` | a comment; the order is **cancelled** by cron after *Cancel unpaid orders after* hours, once the module has re-checked that the checkout is still expired |

Why the delay: Magento can't invoice a cancelled order, and a payment sent just before a checkout
expired can still confirm after it (`expired` → `paid`). Until the order is cancelled such a payment
invoices it normally. If it arrives after the cancellation (or with the setting at 0), the order stays
cancelled and gets a comment with the payout, so you can fulfil a replacement or refund from your wallet.

Orders that moved on (shipped, complete, closed…) are never changed, only commented. Each checkout's
`order_id` is the order's increment id plus its random protect code, for example `000001042-a1b2c3`.

The webhook URL is `https://<your store>/caravelpay/webhook/index/`. It must accept POST requests from
outside (no maintenance mode, basic auth, firewall or CDN block).

## Other currencies

Orders in a supported currency other than US dollars go to Caravel Pay as they are. The total is
converted to US dollars once, when the checkout is created, and that amount is fixed for the checkout:
your buyer is charged in US dollars and you are paid in USDC. Buyers read it before they leave your
store ("You'll be charged in US dollars: about $48.60"), and the checkout shows both prices before
they pay.

- The order currency is what counts (the currency the customer checks out in). The estimate uses today's rate from Caravel Pay, kept for ten minutes.
- The order is marked paid from the checkout's status, for its own total in its own currency; the note on
  the order names both figures. Amounts in different currencies are never compared.
- **Exchange-rate buffer (%)** (0 to 5, default 0) adds a margin to the converted amount, to cover
  exchange-rate movement until you convert your USDC. Leave it at 0 to charge the plain converted amount.
- When no confirmed exchange rate is available, the checkout isn't created and the buyer is asked to try
  again shortly or choose another payment method.

## Switching from PayGate

- Install Caravel Pay next to the PayGate module; both can be enabled while you switch, and PayGate
  orders keep being handled by PayGate.
- Copy your payout wallet into **Payout wallet** (a Polygon address you control).
- PayGate's *hosted* method corresponds to Caravel Pay (one hosted checkout with card, Apple Pay and
  Google Pay). PayGate's *single provider* method has no equivalent.
- PayGate's own order status and its cron cancellation (default 24 h) correspond to *Awaiting Caravel
  Pay payment* and *Cancel unpaid orders after (hours)* (default 24 h, counted from the checkout's expiry).
- PayGate's *Fees Tolerance* has no equivalent: the amount is fixed on Caravel Pay's side, and a short
  payment becomes **underpaid** → On Hold (PayGate's below-tolerance → On Hold).
- When you're happy, disable the PayGate methods.

## Troubleshooting

- **Connection says Not connected**: it says why (wallet refused, server can't reach
  `api.caravelpay.app`, too many attempts). Fix it, set *Reconnect on save* to Yes and save.
- **Orders stay Awaiting after paying**: the webhook isn't reaching the store; see
  `var/log/caravel_pay.log`. Webhooks are retried for 24 hours.
- **Unpaid orders are never cancelled**: Magento cron isn't running (`bin/magento cron:run`).
- **Caravel Pay isn't offered**: Enabled, Connected, a supported order currency, applicable countries.

Support: hello@caravelpay.app with your store URL and the order number.

## Download

- [Latest release](https://github.com/caravelpay/caravel-pay-magento2/releases/latest): `caravel-pay-magento2.zip` and `caravel-pay-magento2-composer.zip`, with `SHA256SUMS`.
- The same files are at <https://caravelpay.app/plugins/#magento>, and its checksum is in <https://caravelpay.app/download/SHA256SUMS>.

## Docs and support

- Guide: <https://caravelpay.app/docs/integrations#magento>
- API reference: <https://caravelpay.app/docs/>
- Questions and bug reports: [hello@caravelpay.app](mailto:hello@caravelpay.app), or open an issue here.

## Licence

MIT. See `LICENSE`.
