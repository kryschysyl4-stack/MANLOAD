# MANLOAD v2 — developer notes

## Page skeleton (staff pages)
```php
<?php
require_once __DIR__ . '/../../config/auth.php';   // loads app.php, db.php, includes/functions.php
require_once __DIR__ . '/../../includes/flash.php';
require_role(ROLE_CHECKIN);                          // role groups are constants in config/auth.php
$page_title = 'Check-in';
$db = db();
// ... POST handling (CSRF is verified automatically by require_login()) ... redirect after POST
include __DIR__ . '/../../includes/header.php';
include __DIR__ . '/../../includes/sidebar.php';
?>
<div class="main-content">
  <?php show_flash(); ?>
  ...
<?php include __DIR__ . '/../../includes/footer.php'; /* closes .main-content and the wrapper */ ?>
```

## Public pages (no login: online booking, find my booking, online payment)
`require_once config/auth.php` (does NOT force login), `session_start_once()`, put `<?= csrf_field() ?>` in every POST form and call `csrf_verify()` at the top of POST handling. Public pages have their own simple layout (no sidebar).

## Rules
- Roles: only `Admin, Passage, Back Office, Coast Guard` (Admin = administration, Passage = passage booking/payment/check-in, Back Office = passage + cargo + load/manifest + reports). Never use 'Staff' or 'Encoder'. Use the ROLE_* groups in config/auth.php.
- NO `ALTER TABLE` / `CREATE TABLE` in PHP. The schema in `sql/manload.sql` is complete.
- Every value printed into HTML goes through `e()`. Values inside inline JS use `js()`.
- User input into SQL only through prepared statements (or `(int)` casts). Whitelist enums with `pick($value, CONST_ARRAY)`.
- Numbers: `next_number('OR')`, `next_number('WB')`, `next_number('TK')`, `next_number('CERT')` — never COUNT(*)+1.
- Fares: `compute_fare($route_id, $class, $category)` — never trust a price from the browser. Fare table = `route_fare`.
- Load: `voyage_load($vid)` and `capacity_error($vid, $pax, $kg, $vehicles)` (refuse the insert if it returns a message).
- Zones: `voyage_zones($vid)`, `pick_zone(...)`, `auto_assign_zones($vid)`.
- Voyage: `get_voyage($vid)` (has `under_maintenance`), `active_voyage_id()`, `voyage_options()`, `voyage_label($v)`, `voyage_closed_reason($v)`.
- Trips: `available_trips([...])` gives seats_left, vehicles_left, availability, bookable.
- Tickets: `ticket_valid_until` = `ticket_expiry($voyage)`; certificates: `valid_until` = `clearance_expiry($voyage)`.
- QR: `ticket_qr_data($pax)` (signed + expiring link), `parse_ticket_input($text)`, `ticket_gate_check($text, $vid)`, `qr_canvas($data, $size)`. Libraries are local in `assets/vendor`.
- Settings: business values (DISCOUNT_PCT, PAX_AVG_WEIGHT_KG, ...) come from table `system_setting` via includes/settings.php; payment accounts from `payment_account` (ONLINE_PAY_ACCOUNTS).
- Email: `includes/mailer.php` → `send_booking_confirmation($booking_ref)`, `send_payment_result(...)`, `send_mail($to, $subject, $html, $inline = [], $attach = [])`.
  Pictures inside an e-mail must be inline (`<img src="cid:...">` + `$inline`); mail apps can't run JavaScript, so use `qr_png($text)` (server-side PNG, bundled library in `lib/`) instead of `qr_canvas()`.
  Boarding-pass backup: `send_boarding_pass($pid)` (includes/checkin_helpers.php) — inline QR + `boarding_pass_png()` picture attachment (includes/boarding_pass_image.php).
- Notifications: `staff_notifications()` in includes/notifications.php → bell at the bottom-right (header.php + assets/js/notifications.js), refreshed from `api/notifications.php` every 20 s. Add a new kind of notification there.
- Money in a single payment: insert `payment` + update `is_paid/fare_paid` inside `$db->begin_transaction()` / `commit()`.
- Weighing scale: add `data-scale` to a weight `<input type="number">` and a "Read scale" button appears.
- Helpers for display: `status_badge()`, `maintenance_badge()`, `progress_bar($pct)`, `peso()`, `kg()`.

## Passenger status flow
Booked (online, not at port) → Checked-in (counter) → Boarded (ticket number entered at the gangway, only after READY TO BOARD) ; No-show / Cancelled.
Seats taken = Booked + Checked-in + Boarded. Load counts Checked-in + Boarded.

## Voyage flow
Open → In Progress → Locked (check-in closed) → Coast Guard verifies and sets `ready_to_board=1` + issues certificate → Departed.
