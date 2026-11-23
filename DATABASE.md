# MANLOAD database — table by table

Import `sql/manload.sql` into an empty database named `manload`. 23 tables, all connected with
foreign keys. Every table below was checked: it is created by the SQL file, read by the system,
and written by the page listed (tested end to end on 2026-09-30).

| # | Table | What it stores | Written by (page / action) | Read by |
|---|---|---|---|---|
| 1 | `port` | Ports (code, name, location) | Setup › Ports | Routes, trips, tickets, manifest, reports |
| 2 | `route` | Origin → destination, travel time, baggage allowances, excess/freight rates, hazardous surcharge | Setup › Routes & Fares | Booking, check-in, cargo freight, reports |
| 3 | `route_fare` | Fare per class (Economy / Tourist / Business / Cabin) per route | Setup › Routes & Fares | Online booking, counter booking, check-in (fare is always computed from here) |
| 4 | `vessel` | Vessel, type, MARINA no., **max passengers, max allowable load (kg), max vehicles**, maintenance status/note/dates | Setup › Vessels & Zones | Every capacity check, trips board, Coast Guard |
| 5 | `vessel_zone` | Load zones per vessel (A1, A2, B1…) with kg / vehicle limits and hazardous flag | Setup › Vessels & Zones | Load Plan, cargo/vehicle auto-sorting, Coast Guard |
| 6 | `vehicle_type` | Vehicle categories with freight rate and standard weight (kg) | Setup › Vehicle Types | Vehicles, online booking, load computation |
| 7 | `schedule_template` | Daily trip pattern (vessel, route, time) | Setup › Voyages & Schedule | "Generate schedule" + monthly batch |
| 8 | `voyage` | Each trip: date/time, manifest status, crew, totals, locked by, **ready to board** by/at, departed, archived | Voyages, Manifest (lock, crew), Coast Guard | Everything |
| 9 | `passenger` | Passenger manifest: name, age, sex, nationality, ID, email, contact, emergency contact, class, category, seat, fare, payment status, **ticket valid until**, status (Booked → Checked-in → Boarded) | Online booking, Counter booking, Check-in, Boarding | Manifest, Coast Guard, payments, reports, archive |
| 10 | `baggage` | Weighed baggage per passenger; excess kg and fee are computed by the database | Check-in, Baggage | Load computation, payments |
| 11 | `cargo` | Waybills: shipper (with email), consignee, weight, type, freight, zone | Cargo Waybills, Load Plan (zone moves) | Load Plan, manifest, payments |
| 12 | `vehicle` | RoRo vehicles: plate, type, owner (with email/address), weight, driver, freight, zone | Vehicles, online booking, Load Plan | Load Plan, manifest, payments |
| 13 | `payment` | Official receipts: OR no., fee type, amount, method (Cash/GCash/Maya/Bank), reference no., void info | Payments › Collect / Void, Online Payments › Verify | Receipts, reports, archive |
| 14 | `payment_account` | GCash / Maya / bank account shown to passengers paying online | Setup › System Settings | Online booking, online payment page, cashier verification |
| 15 | `system_setting` | Discount %, average passenger weight, load warning levels, booking cutoff, ticket/certificate validity, archive months | Setup › System Settings | Every page (loaded at start-up) |
| 16 | `clearance_document` | PCG departure checklist items (system-checked or ticked by the inspector) | Setup › PCG Checklist | Coast Guard › Ready to Board |
| 17 | `departure_clearance` | Issued certificates: cert no., totals, checklist snapshot, valid until, issued by | Coast Guard › Confirm READY TO BOARD | Certificate, manifest, archive |
| 18 | `doc_counter` | Last number used for OR / WB / TK / CERT per year (no duplicate numbers) | Automatically, whenever a number is issued | — |
| 19 | `user` | Staff accounts, role, email, port, active flag, last login | Setup › Users, Profile, Login | Login, all pages |
| 20 | `audit_log` | Who did what and when (logins, bookings, payments, voids, locks, clearances, setting changes) | Automatically | Setup › Audit Log |
| 21 | `archive_batch` | One row per archived month: ZIP file, counts, revenue, purge date | Archive › Run batch / `cron/monthly_archive.bat` | Archive & History |
| 22 | `archive_voyage` | Summary of every archived voyage (kept forever) | Monthly batch | Archive › Daily history |
| 23 | `archive_passenger` | Names of every archived passenger (kept forever, searchable) | Monthly batch | Archive › Daily history / name search |

## Relationships (foreign keys)
- `route` → `port` (origin, destination) · `route_fare` → `route`
- `vessel_zone` → `vessel` · `schedule_template` → `vessel`, `route`
- `voyage` → `vessel`, `route`, `user` (created / locked / ready by)
- `passenger` → `voyage`, `user` (checked in / boarded by)
- `baggage` → `passenger`, `voyage`
- `cargo` → `voyage`, `vessel_zone`, `user` · `vehicle` → `voyage`, `vehicle_type`, `vessel_zone`, `passenger` (driver), `user`
- `payment` → `voyage`, `passenger` / `cargo` / `vehicle`, `user` (cashier, voided by)
- `departure_clearance` → `voyage`, `user` · `user` → `port`

## Passenger accounts, wallet, rewards, ledger (`sql/migrate_accounts_rewards.sql`, 2026-11)

Applied automatically by `config/db.php` (schema flag v5). Logic lives in `includes/wallet.php`.

| Table | What |
|---|---|
| `passenger_account` | Passenger's own login on the public site (`modules/account/`), `wallet_balance`, `points_balance`, `auto_pay` |
| `wallet_txn` | Every wallet movement: Cash-in, Payment (pays an OR), Refund (OR voided), Adjustment (Admin) — `balance_after` |
| `points_txn` | Reward points: Earned (fare ORs × tier), Redeemed (→ personal voucher), Reversed (OR voided), Bonus |
| `voucher` / `voucher_redemption` | Promo codes (Admin › Vouchers & Rewards) and personal vouchers (Welcome / Points / Loyalty) |
| `ledger_entry` | System ledger — one line per payment, void, wallet movement, voucher discount and points movement (Admin › System Ledger) |

- `passenger.account_id` links tickets to an account; `passenger.voucher_id` / `voucher_discount` record the discount taken off `fare`.
- `payment.pay_method` has `Wallet`; its `reference_no` is the wallet transaction no. (WL-YYYY-NNNNNN).
- All payment writes go through `collect_locked()` / `void_payment()` (`includes/checkin_helpers.php`), which post the ledger, wallet and points rows in the same transaction.
- Settings (System Settings): `POINTS_PESO_PER_POINT`, `POINTS_REDEEM_BLOCK`, `POINTS_REDEEM_VALUE`, `WELCOME_VOUCHER_PCT`, `LOYALTY_TRIP_EVERY`, `LOYALTY_TRIP_VALUE`, `WALLET_MIN_CASHIN`, `WALLET_MAX_BALANCE`.
- Reports: `includes/report_export.php` gives every summary page `?export=csv|pdf` (PDF written without a library).
