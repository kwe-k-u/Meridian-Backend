# Meridian Backend

The API behind **Meridian**, an AI-assisted platform for travel agencies: build trip
itineraries, send them to travelers for approval, collect payment, and pay out flights,
hotels, and activity providers — all from one dashboard.

Laravel 12 (PHP 8.2+), Sanctum-authenticated, backed by MySQL. Pairs with
[Meridian-Frontend](../Meridian-Frontend) (React + TypeScript + Vite).

## What it does

- **Trips & itineraries** — agencies create a trip, generate one or more itinerary options
  (flights, accommodation, day-by-day activities) via AI (`MeridianAiService`), and a traveler
  accepts one through a shareable link (no account required).
- **Payments via [WeWire](https://docs.wewire.com/)** — multi-currency virtual accounts (bank
  transfer / mobile money) and stablecoin crypto wallets (USDC/USDT on Base, Ethereum, Polygon,
  or Tron). A traveler pays through a Meridian-hosted `/pay/{reference}` page — WeWire has no
  hosted checkout — choosing USD, GHS, or crypto regardless of which the agency has actually
  provisioned yet. Collected funds can auto-disburse to a beneficiary, or be paid out later to
  the agency or a specific service provider (`WeWirePaymentController::payoutTrip`).
- **Live-call / simulated-fallback pattern** — every WeWire-backed write (account request,
  payout, beneficiary, wallet) tries the real API first; if it fails, the caller is offered a
  "Response from wewire server" popup showing what WeWire actually said, with the option to
  proceed with a simulated result instead (flagged `is_simulated` everywhere it lands in the
  database, so simulated and real data are always distinguishable). See `WeWireService::liveCall()`.
- **Inbox integrations** — Gmail (OAuth, thread ingestion, polling job) and Google Calendar
  (event watching) so agent-traveler correspondence and calls surface in the dashboard.
- **Subscriptions** — agency subscription billing via [Paystack](https://paystack.com/docs/).
- **Multi-currency accounting** — every "how much has been paid / is still outstanding"
  calculation converts through `CurrencyService` before summing, since a trip can be paid
  through several different currencies and wallets across its lifetime.

## Tech stack

| | |
|---|---|
| Framework | Laravel 12, PHP 8.2+ |
| Auth | Laravel Sanctum (bearer tokens) |
| Database | MySQL |
| Testing | Pest 3 |
| Payments | WeWire API (collections, payouts, crypto wallets), Paystack (subscriptions) |
| Email/Calendar | Gmail API, Google Calendar API |
| AI | `MeridianAiService` (itinerary generation) |

## Getting started

```bash
composer install
cp .env.example .env
php artisan key:generate
```

Fill in `.env`: a MySQL connection, `WEWIRE_API_KEY` / `WEWIRE_BASE_URL` /
`WEWIRE_WEBHOOK_SECRET`, Paystack keys, and Google/Firebase credentials for the Gmail/Calendar
integrations. Leave `WEWIRE_SIMULATE=true` if you don't have a live WeWire sandbox account —
KYC and beneficiary creation will be faked; everything else still needs a real account to call.

```bash
php artisan migrate
composer run dev
```

`composer run dev` runs the app server, queue worker, log tailer (`pail`), and Vite together.
Just the API: `php artisan serve`.

## Testing

```bash
php artisan test
```

Pest, `RefreshDatabase` per test, WeWire calls mocked via `Http::fake()` — no real API keys
needed to run the suite.

## Project structure

```
app/Http/Controllers/   One controller per resource (Trip, Itinerary, WeWire*, Gmail, ...)
app/Services/            External API clients (WeWireService, PaystackService, Gmail/*, CurrencyService)
app/Models/               Eloquent models
app/Enums/                Status/type enums shared between models and controllers
database/migrations/      Schema history
tests/Feature/Controllers/  One Pest file per controller, HTTP-level tests
routes/api.php            All routes (grouped: public, authenticated)
```

## Key routes

| Route | Purpose |
|---|---|
| `GET /api/trips`, `POST /api/trips/{trip}/generate-itinerary` | Trip + itinerary management |
| `POST /api/public/trips/{trip}/itineraries/{itinerary}/accept` | Traveler accepts an itinerary (no auth) |
| `GET /api/public/payments/wewire/lookup/{reference}` | Public pay-page data |
| `POST /api/public/payments/wewire/simulate/{reference}` | The pay page's "Proceed with payment" action |
| `POST /api/payments/wewire/webhook` | WeWire webhook receiver (HMAC-verified) |
| `GET /api/wewire/trip-balances`, `POST /api/trips/{trip}/payout` | Dashboard payout flow |
| `GET /api/trips/{trip}/costs` | Cost/payment summary for the trip overview and detail pages |

Full list: `php artisan route:list`.
