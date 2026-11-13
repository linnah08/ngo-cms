-- Admin users: first and last name instead of one free-text name, plus the
-- same in Latin letters for the English site's article bylines. `name` stays,
-- always saved as "first last", for everything that only needs a display name.
--
-- Existing names are split on the first space: "Мария Петрова Иванова" becomes
-- "Мария" + "Петрова Иванова". People correct theirs on their Account page.
--
-- Portable to MySQL 9 and MariaDB 10.5. A re-run skips the ALTER ("Duplicate
-- column") and the UPDATE touches only rows not split yet.

ALTER TABLE admin_users
  ADD COLUMN first_name    VARCHAR(100) NOT NULL DEFAULT '' AFTER name,
  ADD COLUMN last_name     VARCHAR(100) NOT NULL DEFAULT '' AFTER first_name,
  ADD COLUMN first_name_en VARCHAR(100) NOT NULL DEFAULT '' AFTER last_name,
  ADD COLUMN last_name_en  VARCHAR(100) NOT NULL DEFAULT '' AFTER first_name_en;

UPDATE admin_users
   SET first_name = SUBSTRING_INDEX(TRIM(name), ' ', 1),
       last_name  = TRIM(SUBSTRING(TRIM(name), CHAR_LENGTH(SUBSTRING_INDEX(TRIM(name), ' ', 1)) + 1))
 WHERE first_name = '' AND TRIM(name) <> '';
