-- One-off: create the CLAIMSYS database and the application account.
-- Run as the MariaDB root user (run/DBUP.sh does this in the container).
CREATE DATABASE IF NOT EXISTS claimsys DEFAULT CHARACTER SET latin1;
CREATE USER IF NOT EXISTS 'clmbatch'@'localhost' IDENTIFIED BY 'clm2004';
GRANT ALL PRIVILEGES ON claimsys.* TO 'clmbatch'@'localhost';
FLUSH PRIVILEGES;
