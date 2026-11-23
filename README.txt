MANLOAD - MONTHLY BATCH FILE (archive + next month's schedule)
===============================================================

WHAT IT DOES
------------
cron/monthly_archive.php (command line only) runs once a month and:

 1. Picks the oldest month that is finished AND older than ARCHIVE_KEEP_MONTHS
    (config/app.php, default 2 -> on 1 October, June and older can be archived).
 2. Exports every voyage that departed in that month to
        storage/archives/MANLOAD-YYYY-MM.zip
    with voyages.csv, passengers.csv, baggage.csv, cargo.csv, vehicles.csv,
    payments.csv, clearances.csv and README.txt (totals).
 3. Saves a summary in the database (archive_batch, archive_voyage) and the
    passenger names (archive_passenger), so Archive & History > Daily history
    can still show any day's voyages and passenger list, and names stay searchable.
 4. With --purge: deletes the detail rows of that month (payments, baggage,
    vehicles, cargo, passengers) so data does not pile up in the live tables.
    Voyage rows and Coast Guard clearance rows are kept.
 5. With --schedule-next: creates next month's voyages from the schedule
    template (Voyages & Schedule > Schedule template), skipping vessels that are
    Under Maintenance.

Running it again for the same month is safe (it re-exports and replaces the
summary). A month that was already purged is never overwritten.

The ZIP files are downloaded from Archive & History > Monthly batches (login
required; the storage folder itself is blocked from the web).

COMMAND LINE
------------
  php cron/monthly_archive.php                  oldest eligible month
  php cron/monthly_archive.php 2026-05          a specific month
  php cron/monthly_archive.php --all            all eligible months
  php cron/monthly_archive.php --purge          also delete detail rows after export
  php cron/monthly_archive.php --schedule-next  also generate next month's voyages
  php cron/monthly_archive.php --user=1         record user #1 as the exporter

WINDOWS (XAMPP) - TASK SCHEDULER
--------------------------------
 1. Check the paths at the top of cron\monthly_archive.bat:
        "C:\xampp\php\php.exe"
        "C:\xampp\htdocs\MANLOAD\cron\monthly_archive.php"
    Optional: set PURGE=--purge to also clean the live tables.
 2. Test it once: double-click monthly_archive.bat, then open
        C:\xampp\htdocs\MANLOAD\storage\archives\monthly_archive.log
 3. Open Task Scheduler (Start > "Task Scheduler") > Create Basic Task...
      Name:     MANLOAD monthly batch
      Trigger:  Monthly -> Months: <Select all months> -> Days: 1 -> Start 1:00 AM
      Action:   Start a program
      Program:  C:\xampp\htdocs\MANLOAD\cron\monthly_archive.bat
      Start in: C:\xampp\htdocs\MANLOAD\cron
 4. In the task's Properties tick "Run whether user is logged on or not" and
    "Run with highest privileges". MySQL (XAMPP) must be running at that time.
 5. Right-click the task > Run, to test.

   Command-line alternative (run cmd as Administrator):
      schtasks /Create /TN "MANLOAD monthly batch" /SC MONTHLY /D 1 /ST 01:00 ^
               /TR "C:\xampp\htdocs\MANLOAD\cron\monthly_archive.bat" /RL HIGHEST

LINUX - CRON
------------
  crontab -e
  # 01:00 on day 1 of every month
  0 1 1 * * /usr/bin/php /var/www/html/MANLOAD/cron/monthly_archive.php --schedule-next >> /var/www/html/MANLOAD/storage/archives/monthly_archive.log 2>&1

  (add --purge to also delete old detail rows)

BACKUP
------
Copy storage/archives/*.zip to another drive or cloud storage every month.
After a purge, the ZIP is the only copy of the payments, baggage, cargo and
vehicle details of that month.
