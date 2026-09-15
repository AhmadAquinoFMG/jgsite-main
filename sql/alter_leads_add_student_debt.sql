-- Adds student loan debt field extracted from Equifax credit report.
--
-- Student debt is always sourced from Equifax's consumer credit report
-- (includes/equifax.php), independently of the total_debt figure from JG API.
-- Sent to LeadProsper as a separate field for buyer intake.
--
-- Idempotent: safe to run against a database that already has this column.
--
--   mysql -u <user> -p <database> < sql/alter_leads_add_student_debt.sql

ALTER TABLE `leads`
    ADD COLUMN IF NOT EXISTS `student_debt`  INT UNSIGNED DEFAULT NULL AFTER `total_debt`;
