# How MANLOAD v2 answers the panel's comments

| Panel comment | What the system does now | Where |
|---|---|---|
| Sort the loads (A1–A2) beforehand so the Coast Guard only checks | Each vessel has load zones (A1 heavy lane, A2 light lane, B1 cargo hold…) with kg and vehicle limits. Vehicles and cargo are placed automatically: trucks go to A1, lighter vehicles to A2, hazardous cargo only to zones that allow it. Anything left unplaced blocks the manifest lock. | Load Plan (A1/A2), Vessels & Zones |
| Maximize the booking function | One public flow: search → trip cards → up to 10 passengers + optional vehicle → pay online or at the port → e-ticket. Staff also have Counter Booking for walk-ins. Booking reference and ticket lookup. | Online booking, Counter Booking |
| Make the interface visually complete; show available trips and vehicle availability | Trip cards with seats left, vehicle slots left, fare, and a status badge (Available / Fully booked / Closed / Under Maintenance) on the public page, the 7-day availability board and every staff dashboard. | Booking home, Trips & Availability, Dashboard |
| Monthly batch file so data doesn't get stuck in the database | The monthly batch writes a ZIP of CSVs per month (voyages, passengers, baggage, cargo, vehicles, payments, clearances), keeps a summary and all passenger names, and can purge old detail rows. It runs automatically from `cron/monthly_archive.bat` (Windows Task Scheduler), which also creates next month's voyages. | Archive & History, `cron/` |
| Include email when collecting information at the start | Email is required for online booking and asked first at walk-in check-in. It is also collected for vehicle owners, cargo shippers and staff users. E-tickets, payment results and confirmations are emailed. | Booking, Check-in, Cargo, Vehicles, Users |
| Specify the kg allowed to load | Every vessel has **Max allowable load (kg)**, passenger capacity, vehicle slots and per-zone kg limits. Every screen shows "Allowed / Loaded / Remaining kg". Anything that would exceed a limit is refused. | Vessels, Load Plan, Check-in, Cargo, Vehicles |
| Consider passenger capacity **and** load weight so the Coast Guard can check and issue a certificate | A single load formula is used everywhere: (checked-in + boarded passengers × 65 kg) + baggage + cargo + vehicle weights. Passenger capacity and allowable kg are automatic checklist items; the certificate is only issued when both pass. | Coast Guard → Ready to Board |
| Keep it simple, paperless | E-tickets by email, online payment verification, and a digital manifest and certificate. Staff look passengers up by booking reference or ticket number — no QR codes. Printing is optional. | — |
| Coordinate with the Coast Guard on required papers/documents | The PCG departure checklist is an editable table. System items (manifest, capacity, load, zones, lock, maintenance, crew, hazardous cargo) are checked automatically; manual items (life-saving appliances, weather…) are ticked by the inspector. **Update the list after meeting your PCG station.** | PCG Checklist (Admin) |
| QR code with expiration (adviser, Oct 1) | Every ticket has a **QR code** that holds the ticket number, its expiry and a tamper-proof signature. The gate refuses forged, edited or expired QRs. Every ticket has a **valid-until** time (departure + 2 h) and the gangway refuses expired tickets. The clearance certificate has a valid-until time (departure + 48 h). Both are set in System Settings. The Coast Guard sees the manifest, loads and certificate in the Ready to Board screen. | E-ticket, Boarding, Certificate |
| Final defense: printed tickets and a weighing scale | Printable tickets, receipts, manifest and certificate, with a Thermal 80mm option. A **Read scale** button on baggage, cargo and vehicle weights reads a USB/serial digital scale (Chrome/Edge). | Ticket / Receipt, Check-in, Cargo |

## Suggestions
1. **Archive data / daily records:** departed voyages stay in the system. **Archive & History → Daily history** shows any date's voyages and passenger names, even after a monthly purge. Passenger names can be searched across all history.
2. **Passenger names in the manifest:** full LAST, First Middle names, age, sex, nationality, seat, status and emergency contact. Checked-in and boarded passengers are both listed. There is a formal A4 print layout and CSV.
3. **Menu highlighting:** only the page you are on is blue; hover is a light grey.
4. **Vessel maintenance:** mark a vessel Under Maintenance with a note and dates. Its trips show "Under Maintenance" everywhere, can't be booked, checked in or cleared, and the schedule generator skips it. Affected trips can be cancelled in one click, and the page shows how many booked passengers need to be contacted.
5. **Online payment:** GCash, Maya or card through the PayMongo checkout (test-mode mock-up, no real money), when booking or later from the e-ticket. The OR is issued automatically, the ticket turns PAID and the passenger is emailed.
6. **Simpler online booking:** a clear 4-step layout that works on phones.
7. **Coast Guard role:** focused on **Vessels to Verify → Ready to Board**, with tabs for Summary, Passengers, Loads (zones) and Checklist. Boarding opens only after the Coast Guard confirms READY TO BOARD; passengers are then boarded by ticket number on the Boarding page.

## Other fixes from the code review
- The database file now contains every table and column; pages no longer change the database structure at runtime.
- Real roles are used everywhere (the old code used a non-existent "Staff" role, which locked out cashiers and cargo clerks). Editing a user no longer turns them into Admin.
- Security:
  - SQL injection fixed (check-in, baggage and report searches).
  - Passenger pages no longer reachable by guessing ID numbers.
  - Script injection in staff notifications fixed.
  - CSRF protection on every form.
  - The unprotected setup/fix scripts are removed.
  - The mail log with customer data is removed.
- Receipt, waybill, ticket and certificate numbers can no longer collide when two people save at the same time.
- Fares come from the database (Routes & Fares). The browser can no longer set a price.
- The vehicle module and the payments page work (they used columns that did not exist).
- Business settings and online-payment accounts are stored in the database (System Settings) instead of being hard-coded.

## Adviser's comments (Oct 1)
| Comment | What the system does now | Where |
|---|---|---|
| Staff should be able to encode passenger information | Walk-in check-in and Counter Booking encode new passengers; **Edit passenger information** lets staff complete or correct any passenger (including online bookings): name, age, sex, nationality, ID, contact, email, emergency contact, address, class and category. Fare is recomputed when class/category change (blocked once paid). Every change is saved in the Audit Log. | Check-in list (pencil button), Passenger detail |
| Ticket with a tear-off portion | Printed ticket has a **Passenger's Copy** (given to the passenger) and a **Company's Copy** (kept by the staff, signed by the passenger) separated by a ✂ tear line. A4, A6 and 80 mm thermal. | Print ticket |
| Include the QR code with expiration | QR on both copies and on the online e-ticket; valid until departure + 2 h (System Settings). Boarding page accepts QR scans; phones scanning it open a verification page. | Ticket, e-ticket, Boarding |

## Adviser's comments (Oct 2)
| Comment | What the system does now | Where |
|---|---|---|
| Notifications should be at the bottom, with a count; clicking the icon shows all | The pile of yellow pop-ups at the top-right is gone. A **bell at the bottom-right** of every staff page shows a red number = how many notifications are waiting. **Click it** to open the list of **all** notifications (bookings waiting for check-in; for cashiers also online payments to verify), newest first, new ones marked **NEW**. A new booking shows **one** small pop-up above the bell that hides by itself after 8 s (several new bookings at once = one "3 new notifications" pop-up). Click a notification to go straight to its check-in / verification. | Every staff page (Check-in staff, Cashier, Supervisor, Admin) |
| "Thank you for booking!" and send the confirmation to the e-mail | After booking, the page shows **Thank you for booking!** with the booking reference and the e-mail address the confirmation was sent to. The confirmation e-mail starts with **Thank you for booking!** and now has the **QR code of each e-ticket inside the e-mail**. | Online booking → E-ticket page; e-mail |
| E-mail a copy of the boarding pass as a backup in case the original is lost | When a passenger is **checked in** (online booking confirmed at the counter, or walk-in) and has an e-mail, the system e-mails a **copy of the boarding pass**: the pass inside the e-mail with its QR code, plus the same pass **attached as a picture** (save it to the phone — works without internet). If the printed ticket is lost, the gangway scans the QR from the phone. Staff can re-send it with **Email boarding pass** on the passenger page. | Check-in; Passenger detail |


## Panel suggestions (November 2026)
| Panel suggestion | What the system does now | Where |
|---|---|---|
| **Passenger Account** — travel history, booking records, transaction history, account information | Passengers sign up / log in on the booking site, with email address or mobile number. Other ways in: **Login with OTP** (code by email) and **Forgot Password**. Tabs: Overview, **Travel history**, **Bookings**, **Transactions** (receipts + wallet, downloadable CSV/PDF statement), Wallet, Rewards, Profile. Old or counter bookings are added with booking reference + last name. **BOOK NOW** opens the login page; occasional travellers can **Continue as guest**. | Booking site → LOG IN / MY ACCOUNT |
| **Passenger Credits / Wallet** — cash-in; excess baggage and fees deducted automatically | Cash-in online (PayMongo test checkout) or at the counter (Passenger Accounts, printed slip). Pay fares from the wallet when booking. With **auto-pay** on, excess baggage and other fees due at check-in are deducted from the wallet automatically, with an OR for each. The cashier can also choose **Wallet** in Collect. A voided / refunded wallet payment goes back to the wallet. | My Account → Wallet; Admin → Passenger Accounts; Check-in |
| **Loyalty and Rewards** — vouchers, reward points, loyalty incentives | Points on every fare paid (1 point per ₱10). Tiers: Member, Silver ×1.25 (5+ trips), Gold ×1.5 (15+ trips). Points → voucher (100 pts = ₱50). Rewards given automatically: **welcome voucher** on sign-up and a **loyalty voucher every 5 completed trips**. All values are set in System Settings. | My Account → Rewards; Admin → Vouchers & Rewards |
| **Customer Retention** — rewards programme and promotional vouchers | Admin creates **promo codes** (% or ₱ off, max discount, minimum fare, valid dates, total uses, uses per account) and can give a personal voucher to any account. Passengers enter the code on the Payment step and see the discount before confirming. Running promos are shown in My Account → Rewards. | Admin → Vouchers & Rewards; online booking |
| **Sales and Trip Reports** — CSV and PDF export inside Sales Dashboard, Sales Summary, Trip Summary | **CSV** and **PDF** buttons on Sales Summary, Trip Summary, Voyage Summary, Voyage Sales, Daily Collection Report, My Sales Report, System Ledger, Passenger Accounts and Vouchers — no need to open the archive. | Admin → Dashboard pages |
| **Reporting Efficiency** — one-click export of filtered data and transaction summaries | Each export button keeps the filters on screen (dates, route, vessel, cashier, entry type, search) and downloads exactly what is shown. The file includes totals and summary tables. Each export is written to the audit log. | Same pages |
| **Digital Transactions** — credits, vouchers, discounts, excess baggage payments and records in the ledger and audit logs | New **System Ledger**: every official receipt and void, wallet cash-in / payment / refund / adjustment, voucher discount and points movement is posted automatically, in the same database transaction as the payment itself. Each movement also writes an **audit log** row. Filter and export to CSV / PDF. Sales Summary shows wallet payments, wallet cash-ins and voucher discounts. | Admin → System Ledger; Activity History |
