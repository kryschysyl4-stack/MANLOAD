<?php
/**
 * Fills the voyages of the given dates with sample (test) passengers: 20–40 per voyage,
 * in bookings of 1–4 people, about 30% of them repeat travellers from passenger_profile.
 * All tickets are 'Booked', unpaid, with remarks 'Sample data' (no receipts are created).
 *
 *   C:\xampp\php\php.exe tools\sample_passengers.php 2026-10-25 2026-10-26 2026-10-27 2026-10-28
 *
 * Remove them again with sql/sample_passengers_cleanup.sql.
 */
if (PHP_SAPI !== 'cli') exit;
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/checkin_helpers.php';

const SAMPLE_TAG = 'Sample data';
$dates = array_slice($argv, 1);
foreach ($dates as $d) if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) exit("Bad date: $d\n");
if (!$dates) exit("Give one or more dates (YYYY-MM-DD).\n");
$staff = [4, 3];   // staff1, staff2 issue the counter bookings

$last  = ['Abella','Bacalso','Cabahug','Dela Cruz','Enriquez','Flores','Gonzaga','Herrera','Ibañez','Jumawan','Lagura','Maglasang',
          'Navarro','Ocampo','Pacquiao','Quiño','Ramos','Sarmiento','Tabanao','Ursal','Villaflor','Yap','Alcantara','Balili',
          'Cañete','Dagoc','Escaño','Fuentes','Gimeno','Labrador','Mendoza','Noval','Omega','Plaza','Rosales','Sumalpong','Tumulak',
          'Uy','Velasco','Zamora','Amancio','Bustamante','Calo','Dumdum','Galarrita','Lim','Montejo','Pelaez','Rebucas','Sanchez'];
$male  = ['Juan','Jose','Mark','John Paul','Rey','Ronald','Christian','Jerome','Kenneth','Arnel','Dennis','Romeo','Noel','Ariel',
          'Jasper','Paolo','Rodel','Vincent','Carlo','Joel','Renato','Edgar','Bryan','Junrey','Kent','Ramil','Danilo','Francis'];
$fem   = ['Maria','Ana','Kristine','Rose','Jessa','Mary Joy','Lorna','Grace','Jenny','Liza','Marites','Cherry','Rowena','Shiela',
          'Angelica','Joy','Nelia','Carmela','Divina','April','Precious','Rhea','Lovely','Mylene','Cristina','Gemma','Hazel','Janine'];
$towns = ['Kitcharao, Agusan del Norte','Jabonga, Agusan del Norte','Santiago, Agusan del Norte','Butuan City','Surigao City',
          'Placer, Surigao del Norte','Mainit, Surigao del Norte','Cabadbaran City','Tubay, Agusan del Norte','Claver, Surigao del Norte'];

function any_of(array $a) { return $a[array_rand($a)]; }
function weighted(array $w): string {
    $r = mt_rand(1, array_sum($w));
    foreach ($w as $k => $n) if (($r -= $n) <= 0) return $k;
    return array_key_first($w);
}
function mobile(): string { return '09' . any_of(['15','17','26','35','38','53','56','63','95','99']) . str_pad((string)mt_rand(0, 9999999), 7, '0', STR_PAD_LEFT); }
function philsys(): string { return implode('-', array_map(fn() => str_pad((string)mt_rand(0, 9999), 4, '0', STR_PAD_LEFT), range(1, 4))); }
function age_for(string $cat): int {
    return match ($cat) { 'Senior' => mt_rand(60, 84), 'Student' => mt_rand(12, 24), 'Minor' => mt_rand(3, 11),
                          'Infant' => mt_rand(0, 2), 'PWD' => mt_rand(18, 70), default => mt_rand(18, 59) };
}

$db = db();
$ph = implode(',', array_fill(0, count($dates), '?'));
$st = $db->prepare("SELECT voyage_id FROM voyage WHERE departure_date IN ($ph) AND manifest_status = 'Open' ORDER BY departure_date, departure_time");
$st->bind_param(str_repeat('s', count($dates)), ...$dates); $st->execute();
$voyages = array_column($st->get_result()->fetch_all(MYSQLI_NUM), 0);
$profiles = $db->query("SELECT * FROM passenger_profile")->fetch_all(MYSQLI_ASSOC);

$made = 0; $skipped = [];
foreach ($voyages as $vid) {
    $target = mt_rand(20, 40); $n = 0; $used = [];
    while ($n < $target) {
        $size = min($target - $n, (int)weighted(['1' => 35, '2' => 30, '3' => 20, '4' => 15]));
        $online  = mt_rand(1, 100) <= 35;
        $family  = any_of($last);
        $contact = mobile();
        $email   = mt_rand(1, 100) <= 60 ? strtolower(str_replace([' ', 'ñ'], ['', 'n'], $family)) . mt_rand(10, 999) . '@gmail.com' : null;
        $address = any_of($towns);
        $class   = weighted(['Tourist' => 60, 'Business' => 25, 'Economy' => 15]);
        $group = [];
        for ($i = 0; $i < $size; $i++) {
            $prof = (mt_rand(1, 100) <= 30 && $profiles) ? any_of($profiles) : null;
            if ($prof && !isset($used[$prof['profile_id']])) {
                $used[$prof['profile_id']] = true;
                $cat = $prof['pax_category'];
                $p = ['last_name' => $prof['last_name'], 'first_name' => $prof['first_name'], 'middle_name' => $prof['middle_name'],
                      'sex' => $prof['sex'], 'age' => (int)$prof['age'], 'nationality' => $prof['nationality'] ?: 'Filipino',
                      'id_type' => $prof['id_type'], 'id_number' => $prof['id_number'],
                      'contact_no' => $prof['contact_no'] ?: $contact, 'email' => $prof['email'] ?? $email,
                      'address' => $prof['address'] ?: $address, 'emergency_contact' => $prof['emergency_contact']];
            } else {
                $cat = $i === 0 ? weighted(['Adult' => 75, 'Senior' => 12, 'PWD' => 6, 'Student' => 7])
                                : weighted(['Adult' => 45, 'Student' => 15, 'Minor' => 20, 'Senior' => 10, 'Infant' => 5, 'PWD' => 5]);
                $sex = mt_rand(0, 1) ? 'Male' : 'Female';
                $id  = match ($cat) { 'Minor', 'Infant' => [null, null],
                                      'Student' => ['Student ID', date('Y') - mt_rand(0, 4) . '-' . mt_rand(10000, 99999)],
                                      default => ['PhilSys ID', philsys()] };
                $p = ['last_name' => ($i === 0 || mt_rand(1, 100) <= 70) ? $family : any_of($last),
                      'first_name' => any_of($sex === 'Male' ? $male : $fem), 'middle_name' => mt_rand(1, 100) <= 80 ? any_of($last) : null,
                      'sex' => $sex, 'age' => age_for($cat), 'nationality' => 'Filipino', 'id_type' => $id[0], 'id_number' => $id[1],
                      'contact_no' => $contact, 'email' => $email, 'address' => $address,
                      'emergency_contact' => $i === 0 ? any_of($fem) . ' ' . $family . ' ' . mobile() : null];
            }
            $group[] = $p + ['pax_category' => $cat, 'class' => $class, 'seat_no' => '', 'remarks' => SAMPLE_TAG];
        }

        $db->begin_transaction();
        try {
            $v = lock_voyage((int)$vid);
            if ($why = voyage_closed_reason($v) ?? capacity_error((int)$vid, count($group))) throw new RuntimeException($why);
            $ref = new_booking_ref();
            foreach ($group as $p) {
                try {
                    $pid = insert_passenger($p, $v, 'Booked', $ref, any_of($staff));
                } catch (RuntimeException $e) {   // class full: put this passenger in another class
                    foreach (['Tourist', 'Business', 'Economy'] as $c) {
                        if ($c === $p['class']) continue;
                        try { $pid = insert_passenger(['class' => $c] + $p, $v, 'Booked', $ref, any_of($staff)); break; }
                        catch (RuntimeException $e2) { $pid = 0; }
                    }
                    if (!$pid) throw $e;
                }
                if ($online) $db->query("UPDATE passenger SET booked_via = 'Online', booked_by = NULL WHERE passenger_id = $pid");
            }
            $db->commit();
            $n += count($group); $made += count($group);
        } catch (Throwable $e) {
            $db->rollback();
            $skipped[$vid] = $e->getMessage();
            break;
        }
    }
}
echo "Made $made sample passenger(s) on " . count($voyages) . " voyage(s).\n";
foreach ($skipped as $vid => $why) echo "  voyage $vid stopped early: $why\n";
