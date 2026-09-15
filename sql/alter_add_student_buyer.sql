-- Registers the student-loan buyer (DocuPop) in the `buyers` registry, so a lead
-- routed to the student band renders that buyer's logo and dials that buyer's
-- own number on the thank-you page.
--
-- Idempotent: safe to run against a database that already has the row.
--
--   mysql -u <user> -p <database> < sql/alter_add_student_buyer.sql
--
-- Context: lead_routing_decision() puts a lead in the 'student' band when
-- Equifax reported a student-loan balance at or above ROUTING_STUDENT_QUALIFY_MIN
-- (default $10,000) AND LeadProsper named ROUTING_STUDENT_BUYER (default
-- 'DocuPop') as the buyer that accepted it. submit.php forwards that buyer name
-- to the thank-you page as ?buyer=, and thank-you.php looks it up HERE.
--
-- Without this row the band still works — the lead is still routed, still skips
-- the offerwall — but the page falls back to the house number and shows no logo,
-- which is the one outcome the band exists to avoid. Run it before enabling the
-- band in production.

-- `name` is a MATCH TOKEN compared as a case-insensitive SUBSTRING of the buyer
-- name LeadProsper returns, so 'DocuPop' matches 'DocuPop Student Loan Services'
-- without this column tracking their exact spelling. Keep it as short as stays
-- unambiguous against the campaign's other buyers.
--
-- ⚠ `did` below is a PLACEHOLDER. A wrong number here is worse than none: the
-- CALL NOW button is the page's primary action, and it would dial a stranger.
-- Replace it with DocuPop's real inbound line before enabling the band, or set
-- it NULL to fall back to the house number:
--
--   UPDATE `buyers` SET `did` = '(000) 000-0000' WHERE `name` = 'DocuPop';
--   UPDATE `buyers` SET `did` = NULL            WHERE `name` = 'DocuPop';
--
-- `use_callgrid` = 1 keeps our number pool in front of the line, matching JG's
-- setting: the button reads this DID and CallGrid swaps the tel: target once it
-- assigns. Set it to 0 if DocuPop takes these calls on a line they own, the way
-- InCharge does — putting our pool in front of that would re-route a call the
-- buyer already paid for.
--
-- THE DATABASE WINS: `ON DUPLICATE KEY UPDATE id = id` is a deliberate no-op, so
-- re-running this never overwrites a number or flag tuned in production. The
-- cost is that editing a value here does NOT reach a database that already has
-- the row — use the UPDATEs above for that.
INSERT INTO `buyers` (`name`, `label`, `logo_path`, `did`, `use_callgrid`, `show_logo`) VALUES
    ('DocuPop', 'DocuPop', 'assets/img/buyers/Docupop-Logo_v02.png', NULL, 1, 1)
ON DUPLICATE KEY UPDATE
    `id` = `id`;   -- no-op: never overwrite a live row

-- Verify with:
--
--   SELECT `name`, `label`, `logo_path`, `did`, `use_callgrid`, `show_logo`
--     FROM `buyers` WHERE `name` = 'DocuPop';
--
-- The logo file must also exist on disk at the path above — buyer_logo_of()
-- checks, logs a 'logo_missing' warning, and renders nothing rather than a
-- broken image, so a missing file degrades quietly instead of breaking the page.
