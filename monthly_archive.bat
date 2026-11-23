@echo off
REM ======================================================================
REM  MANLOAD - monthly batch file
REM  1. Archives the oldest finished month (ZIP in storage\archives\)
REM  2. Creates next month's voyages from the schedule template
REM
REM  Schedule this file in Windows Task Scheduler: Monthly, day 1, 01:00.
REM  See cron\README.txt. Adjust the paths below if XAMPP is elsewhere.
REM
REM  To ALSO remove the old detail rows from the database after they are
REM  safely in the ZIP, change the PURGE line to:   set PURGE=--purge
REM ======================================================================

set PHP="C:\xampp\php\php.exe"
set SCRIPT="C:\xampp\htdocs\MANLOAD\cron\monthly_archive.php"
set LOG="C:\xampp\htdocs\MANLOAD\storage\archives\monthly_archive.log"
set PURGE=

%PHP% %SCRIPT% --schedule-next %PURGE% >> %LOG% 2>&1
exit /b %ERRORLEVEL%
