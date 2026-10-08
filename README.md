# GPay — AstroPay integration (Laravel 12)

Deposits (collection) and withdrawals (payouts) through the AstroPay merchant
API (`https://api.gpay.one`) for **INR, PKR, BDT and USDT**, with a per-user
wallet, admin approval of withdrawals, signed callbacks and manual
reconciliation. Nothing runs on a schedule or queue: every status check is
started by a person (button or artisan command).

Built on Laravel 12 (PHP 8.2). The code only uses APIs that also exist in
Laravel 13, so after upgrading PHP to 8.3+ you can move to Laravel 13 with
`composer require laravel/framework:^13.0 -W`.

---

## 1. Setup

```bash
composer install
cp .env.example .env        # already done in this folder
php artisan key:generate    # already done in this folder
php artisan migrate
php artisan app:make-admin you@example.com --name="Your Name"
php artisan serve           # or point an XAMPP virtual host at /public
```

Then log in as the admin and save the merchant keys in **Admin → Settings**
(section 2).

**Database.** `.env` uses SQLite (`database/database.sqlite`) so it works out of
the box. For XAMPP MySQL/MariaDB in production:

```dotenv
DB_CONNECTION=mysql
DB_HOST=127.0.0.1
DB_PORT=3306
DB_DATABASE=gpay
DB_USERNAME=root
DB_PASSWORD=
```

then create the `gpay` database and run `php artisan migrate`. MySQL/MariaDB
(InnoDB) is recommended in production because wallet and order updates rely on
`SELECT … FOR UPDATE` row locks.

> `APP_KEY` encrypts the merchant keys and the beneficiary account numbers and
> phone numbers in the database. Back it up; if it changes, those fields can no
> longer be decrypted (the merchant keys then have to be entered again).

## 2. AstroPay configuration

### Merchant data: database only (Admin → Settings)

All AstroPay merchant data is stored in the database and is **never read from
`.env`**:

| Data | Table |
|---|---|
| Merchant key, secret key (encrypted with `APP_KEY`), enabled flag, deposit/withdrawal min/max — one row per currency | `astropay_accounts` |
| API base URL (seeded with `https://api.gpay.one`), callback base URL, callback IP allowlist | `astropay_settings` |

Log in as an admin, open **Admin → Settings** and re-enter your password
(required again every 15 minutes). Per currency you can:

- save the merchant key and secret key (never shown again; leave a field empty
  to keep the saved value),
- tick **Enabled** to accept new deposits/withdrawals in that currency,
- set deposit/withdrawal minimum and maximum (empty = AstroPay enforces the
  limit configured on your account),
- **Test connection**: calls `/v1/account/balance` with the saved keys and
  explains 401 (wrong keys) / 403 (IP not whitelisted),
- remove the saved keys.

Under **API connection** you set the API base URL (https only), the public
callback base URL (empty = the site's own URL) and the optional callback IP
allowlist.

Rules:

- A currency without saved keys is not available anywhere.
- A disabled currency accepts no new orders, but callbacks and status checks of
  its existing orders keep working as long as its keys are saved.
- Every change is logged (without key values) in `storage/logs/astropay-*.log`.

### Application behaviour (`.env`)

Only how the app behaves is configured in `.env`:

| Setting | Default | Meaning |
|---|---|---|
| `ASTROPAY_PAYOUT_REQUIRE_APPROVAL` | `true` | Withdrawals wait for an admin. `false` sends them immediately. |
| `ASTROPAY_DEPOSIT_WALLET_CREDIT` | `gross` | `gross` credits the requested amount (you absorb the commission); `net` credits amount − commission. |
| `ASTROPAY_WEBHOOK_CONFIRM_VIA_QUERY` | `true` | Before applying a final status from a callback, re-query AstroPay. A forged callback can then never settle an order. |
| `ASTROPAY_NOT_FOUND_GRACE_MINUTES` | `15` | How long a timed-out order must age before a 404 from the status query is treated as "never created". |
| `ASTROPAY_STATUS_CHECK_COOLDOWN` | `15` | Seconds between two manual status checks of the same order. |
| `ASTROPAY_PAY_URL_TTL_MINUTES` | `30` | How long "Continue payment" is offered for a pending deposit. |
| `ASTROPAY_ORDER_PREFIX` | `GP` | Prefix of generated orderIds. |
| `ASTROPAY_TIMEOUT`, `ASTROPAY_CONNECT_TIMEOUT`, `ASTROPAY_QUERY_RETRIES` | `30`, `10`, `2` | HTTP timeouts and retries for read-only calls. |

**Before going live:** give AstroPay support your server's outgoing IP for the
whitelist (withdrawals are refused with `403` otherwise) and confirm your
minimum deposit/withdrawal amounts.

Callback URLs are generated automatically per order:

```
https://pay.example.com/webhooks/astropay/deposit/{INR|PKR|BDT|USDT}
https://pay.example.com/webhooks/astropay/payout/{INR|PKR|BDT|USDT}
```

These routes have no session, cookies or CSRF; authenticity is proven by the
signature. For local testing, expose the app with a tunnel (e.g. ngrok or
Cloudflare Tunnel) and save that HTTPS URL as the callback base URL in
Admin → Settings.

## 3. Flows

### Deposit (Collection → Create Deposit Order)

1. User picks currency, optional method, amount and mobile number
   (`/deposits/create`).
2. `POST /v1/payins/create` with `orderId`, `amount` ("500.00"), `callbackUrl`,
   `name`, `phone`, `email` and `channel` (only when a method is chosen; never
   for USDT).
3. The user is redirected to AstroPay's hosted `pay_url`.
4. The callback (or a manual status check) moves the order to **success** and
   credits the wallet once, or to **failed**.

| Currency | `channel` values | Phone format |
|---|---|---|
| INR | `UPI` | `9876543210` |
| PKR | `EASYPAISA`, `JAZZCASH` | `03001234567` |
| BDT | `BKASH`, `NAGAD` | `01712345678` |
| USDT | omitted | any international number |

For INR, a customer who paid but is still pending can submit the bank UTR on the
transaction page (`/v1/utr/supplement`).

### Withdrawal (Payment → Create Withdrawal Order)

1. User requests a withdrawal; the amount is **held** (debited) from the wallet
   immediately and the request waits in **Admin → Approvals**.
2. Admin clicks *Approve and send* → `POST /v1/payouts/create`, or *Reject*
   (held amount refunded).
3. The callback / status check settles it: **success** keeps the debit,
   **failed** refunds the user automatically (AstroPay returns the funds to the
   merchant balance).

Request body per method (from *Reference → Payment Method Codes*):

| Currency | `accountType` | `account` | `bank_code` | `accountPhone` |
|---|---|---|---|---|
| INR | `UPI` | UPI ID (`name@bank`) or bank account no. | IFSC for a bank account, else `""` | 10-digit mobile |
| PKR | `EASYPAISA` / `JAZZCASH` | wallet mobile | `""` | same mobile |
| BDT | `BKASH` / `NAGAD` | wallet mobile | `""` | same mobile |
| USDT | *omitted* | TRC20 address (checksum-verified) | *omitted* | *omitted* |

`personName` is always sent (optional for USDT). AstroPay adds its commission on
top of `amount` and deducts the total from the merchant balance.

### Refunds

The AstroPay API has **no refund endpoint** for deposits, so none is
implemented. The refunds this app performs are internal wallet refunds:

- a withdrawal that AstroPay reports as **failed** (status 9),
- a withdrawal an admin **rejects**,
- a withdrawal AstroPay **refused** at creation (when approval is off), or that
  AstroPay still does not know after the grace period.

Each refund is written once to the wallet ledger (`wallet_entries` has a unique
index on transaction + type).

## 4. Order states

| Local status | Meaning |
|---|---|
| `awaiting_approval` | Withdrawal held, waiting for an admin |
| `initiated` | Saved, create call in progress |
| `pending` | Accepted by AstroPay (status 1) |
| `unknown` ("Processing") | Create call timed out or got 500/503: the order may or may not exist. **Never re-send it**; run a status check. |
| `success` | AstroPay status 10 |
| `failed` | AstroPay status 9 |
| `rejected` | Never created on AstroPay (refused, rejected by admin, or not found after the grace period) |

Final states are never overwritten. If AstroPay later reports something that
contradicts a final state (e.g. success after a refund), or a settled amount that
differs from the requested one, the order is flagged **Needs review** and no
balance changes automatically. In the admin panel you can then:

- **Credit** a deposit (credits once),
- **Reverse the refund** of a withdrawal that AstroPay did pay (may leave a
  negative balance),
- **Dismiss** with a note.

## 5. Manual reconciliation (no scheduler)

AstroPay does not retry callbacks, so missed ones must be picked up manually:

- **User:** *Check status* button on a transaction.
- **Admin:** *Check status with AstroPay* on any transaction.
- **CLI:**

```bash
php artisan astropay:sync                      # all unsettled orders older than 5 minutes
php artisan astropay:sync GPD01J...            # one order
php artisan astropay:sync --type=payout --currency=PKR --older-than=30 --limit=100
php artisan astropay:balance                   # balance of every configured account
php artisan astropay:balance INR
php artisan astropay:utr 437558231943          # UTR query (INR)
php artisan astropay:utr 437558231943 --order=GPD01J...   # attach UTR to a deposit
php artisan app:make-admin admin@example.com
```

Nothing is registered in `routes/console.php`. If you later want automatic
reconciliation, schedule `astropay:sync` there.

## 6. Security and edge cases handled

- Credentials only in `.env`; never logged (`storage/logs/astropay-*.log` redacts
  keys and masks phone numbers and accounts).
- Callback signature: `orderId, amount, commission, status, utr` sorted, empty
  values skipped, `secret=` appended, uppercase MD5, constant-time comparison.
  JSON numbers are kept verbatim (`500.0000`), and form-encoded callbacks work too.
- The callback must match the order's type and currency (per-URL secret).
- Callbacks optionally re-confirmed by a status query before money moves.
- Duplicate callbacks are no-ops; one row lock per order serialises callbacks,
  status checks and admin actions.
- Create calls are never retried (AstroPay does not de-duplicate `orderId`);
  read-only calls retry on network errors and 500/503 with backoff.
- Non-JSON responses (e.g. a proxy's 502 page) and HTTP statuses that mirror the
  `code` are handled.
- A callback that arrives before the create response is kept.
- Double-submitted forms create one order (idempotency key).
- A withdrawal AstroPay refused goes back to the approval queue and gets a fresh
  `orderId` when it is approved again.
- Amounts use exact decimals (no floats), with at most 2 decimals on input.
- TRC20 addresses are Base58Check-validated; IFSC, UPI IDs and PK/BD/IN mobile
  numbers are validated and normalised.
- Chinese internal error messages are never shown to customers.
- Every callback is logged in `astropay_webhook_logs` (Admin → Callbacks).

## 7. Code map

```
config/astropay.php                       configuration
app/Services/AstroPay/AstroPayClient.php  HTTP client (one per currency account)
app/Services/AstroPay/AstroPayManager.php credentials, limits, callback URLs
app/Services/AstroPay/AstroPaySettings.php merchant data from the database (Admin → Settings)
app/Http/Controllers/Admin/AdminSettingsController.php  Admin → Settings page
app/Services/AstroPay/DepositService.php  POST /v1/payins/create
app/Services/AstroPay/PayoutService.php   request / approve / reject, POST /v1/payouts/create
app/Services/AstroPay/StatusSyncService.php  /v1/payins/query, /v1/payouts/query
app/Services/AstroPay/UtrService.php      /v1/utr/query, /v1/utr/supplement
app/Services/AstroPay/WebhookHandler.php  callback verification and processing
app/Services/AstroPay/TransactionProcessor.php  the only place states and wallet balances change
app/Services/Wallet/WalletService.php     wallet balances + ledger
app/Events/AstroPay/*                     DepositSucceeded, DepositFailed, PayoutSucceeded, PayoutFailed, TransactionFlaggedForReview
routes/webhooks.php                       callback route (no session/CSRF)
tests/                                    89 tests (php artisan test)
```

Events are dispatched after the database commit; add listeners (e.g. to notify
users) without touching the payment code.

## 8. Tests

```bash
php artisan test
```

All AstroPay HTTP calls are faked in the tests; no real API is contacted.
