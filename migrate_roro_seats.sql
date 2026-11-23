-- Every RORO vessel: 270 passengers, Economy seats only (Tourist / Business not offered)
UPDATE vessel SET max_passengers = 270 WHERE vessel_type = 'RORO';
DELETE FROM vessel_class_seat WHERE vessel_id IN (SELECT vessel_id FROM vessel WHERE vessel_type = 'RORO');
INSERT INTO vessel_class_seat (vessel_id, class, capacity)
    SELECT vessel_id, c.class, c.capacity FROM vessel
    CROSS JOIN (SELECT 'Economy' AS class, 270 AS capacity UNION ALL SELECT 'Tourist', 0 UNION ALL SELECT 'Business', 0) c
    WHERE vessel_type = 'RORO';
