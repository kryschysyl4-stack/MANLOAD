-- Removes the sample (test) passengers made by tools/sample_passengers.php
-- (remarks = 'Sample data'), then recounts the passenger profiles and drops
-- profiles that no longer have any ticket. Real tickets are not touched.
START TRANSACTION;

DELETE FROM baggage   WHERE passenger_id IN (SELECT passenger_id FROM passenger WHERE remarks = 'Sample data');
DELETE FROM passenger WHERE remarks = 'Sample data';

UPDATE passenger_profile pp
   SET trips        = (SELECT COUNT(*)       FROM passenger p WHERE p.profile_id = pp.profile_id),
       last_trip_at = (SELECT MAX(booked_at) FROM passenger p WHERE p.profile_id = pp.profile_id);
DELETE FROM passenger_profile WHERE trips = 0;

COMMIT;
