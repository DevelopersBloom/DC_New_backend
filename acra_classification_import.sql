-- ACRA monthly classification import.
--
-- ACRA's monthly report (Book1.xlsx) has 4 columns per client:
--   A: ID document number(s) / tax registration number (not used for matching)
--   B: social card number (հծհ)                        -> social_card_number below
--   C: full name (or company name for legal entities)   -> full_name below
--   D: max overdue days for that period                 -> max_overdue_days below
--
-- Usage each month:
--   1. Open the new report, replace the INSERT VALUES block below with its rows
--      (social_card_number, full_name, max_overdue_days), keeping this file's
--      structure otherwise unchanged.
--   2. Run this whole file against the database (e.g. `mysql db < acra_classification_import.sql`
--      or paste it into phpMyAdmin's SQL tab in one go).
--   3. Check the last SELECT — it lists rows that matched no client, so they can
--      be resolved by hand.
--
-- This only sets clients.acra_classification_id (ACRA's reported classification,
-- kept purely as a reference/comparison field). It does NOT touch
-- clients.classification_id and does NOT run the reserve/write-off posting
-- cascade — that stays driven by the existing internal classification job
-- (UpdateClientClassificationsNew / ClientClassificationService).

-- 1) Staging table for the current month's report. Truncated and reloaded
--    every run, so it always reflects only the most recently imported file.
CREATE TABLE IF NOT EXISTS acra_classification_import (
    social_card_number VARCHAR(255) NULL,
    full_name          VARCHAR(255) NOT NULL,
    max_overdue_days   INT NOT NULL,
    client_id          BIGINT UNSIGNED NULL
);

TRUNCATE TABLE acra_classification_import;

-- 2) This month's rows — replace with the new report's data each month.
INSERT INTO acra_classification_import (social_card_number, full_name, max_overdue_days) VALUES
('5208610574', 'ԱԼՎԻՆԱ ԳՐԻԳՈՐՅԱՆ', 0),
('5912650286', 'ՍՎԵՏԼԱՆԱ ՊԵՏՐՈՍՅԱՆ', 78),
('3801910563', 'ՄԱՐՏԻՆ ՎԱՐԴԱՆՅԱՆ', 273),
('3201810053', 'ԿԱՐԵՆ ՄԿՐՏՉՅԱՆ', 2810),
('5107660932', 'ՀԻԼԴԱ ԲԱՂԴԱՍԱՐՅԱՆ', 13),
('2005780707', 'ԿԱՐԵՆ ԳՐԻԳՈՐՅԱՆ', 8),
('2603870521', 'ՏԻԳՐԱՆ ՀԱՐՈՒԹՅՈՒՆՅԱՆ', 21),
('7509670519', 'ԱՆԱՀԻՏ ՄԿՐՏՉՅԱՆ', 0),
('6809890106', 'ԱՆԻ ՍԱՐԳՍՅԱՆ', 0),
('7811930064', 'ԼՈՒԻԶԱ ՋԱՆՈՅԱՆ', 0),
('1411850661', 'ՄԱՆՎԵԼ ՄԻՆԱՍՅԱՆ', 1),
('6008860085', 'ՆԱՐԻՆԵ ՄԱԹԵՎՈՍՅԱՆ', 0),
('7803880797', 'ՔՆԱՐ ՄԵԼՔՈՆՅԱՆ', 4),
('4112930305', 'ԴԱՎԻԹ ՄԵԼՔՈՆՅԱՆ', 103),
('4103780320', 'ՀՐԱՉՅԱ ՂԱԶԱՐՅԱՆ', 0),
('2308850078', 'ԳԱԳԻԿ ՊՈՂՈՍՅԱՆ', 1593),
('6303960022', 'ԼԻԱՆՆԱ ՀՈՎՀԱՆՆԻՍՅԱՆ', 0),
('7111870158', 'ՌՈՒԶԱՆՆԱ ԱՊՐԵՍՅԱՆ', 0),
('3608910441', 'ԳՐԻՇԱ ՀՈՒՆԵՅԱՆ', 0),
('1604930373', 'ՆԱՐԵԿ ՄՈՒՐԱԴՅԱՆ', 0),
('8107920287', 'ՏԱԹԵՎԻԿ ՄԵԼՔՈՆՅԱՆ', 0),
('5105770447', 'ԼԻԼԻԹ ՔԵՇԻՇՅԱՆ', 2811),
('6102830431', 'ՄԱՐԻՆԵ ՀԱՅՐԱՊԵՏՅԱՆ', 0),
('3703990392', 'ՀՈՎՀԱՆՆԵՍ ԱՐԵՎՇԱՏՅԱՆ', 0),
('6604880516', 'ՆԱՐԻՆԵ ՀԱՅՐԱՊԵՏՅԱՆ', 0),
('3312970156', 'ԱՐԱՄ ԳԱԲՐԻԵԼՅԱՆ', 2),
('1112960260', 'ՄԽԻԹԱՐ ԱԲՈՎՅԱՆ', 0),
('1727020308', 'ԱՇՈՏ ԱՍԱՏՐՅԱՆ', 0),
('1212990064', 'ԴԱՎԻԹ ՀՈՎՀԱՆՆԻՍՅԱՆ', 0),
('2211960421', 'ԴԱՎԻԹ ՄԿՐՏՉՅԱՆ', 0),
('5312860727', 'ԱՆԻ ՊԱՊԻԿՅԱՆ', 82),
('6808910243', 'ՍՈՆԱ ԵԴՈՅԱՆ', 6),
('3803800315', 'ՀՈՎՀԱՆՆԵՍ ՄԱՐՏԻՐՈՍՅԱՆ', 3),
('3910930395', 'ՄՀԵՐ ՀՈՎՍԵՓՅԱՆ', 73),
('3109990539', 'ԱՇՈՏ ՍԱՐԳՍՅԱՆ', 0),
('4107720111', 'ԼԵՎՈՆ ԽԱՉԱՏՐՅԱՆ', 0),
('2011910250', 'ԳԵՎՈՐԳ ԶԱՔԱՐՅԱՆ', 0),
('3612890018', 'ՌԱԶՄԻԿ ԽԱԼԱՉՅԱՆ', 0),
('3509960440', 'ՎԱԽՏԱՆԳ ԳՐԻԳՈՐՅԱՆ', 0),
('3202910469', 'ԳԵՎՈՐԳ ԽՈՒՏԻԿՅԱՆ', 17),
('2407720261', 'ԳԵՎՈՐԳ ՀԱՅՐԱՊԵՏՅԱՆ', 46),
('3507940715', 'ՀՐԱՅՐ ԼԱԼԱՅԱՆ', 0),
('1214800351', 'ՀԱՄԼԵՏ ՄԱՐԿՈՍՅԱՆ', 0),
('2001890907', 'ՌԱԶՄԻԿ ՂՈՒԿԱՍՅԱՆ', 0),
('7802840139', 'ՎԱՐԴՈՒՀԻ ՀԱՄԲԱՐՁՈՒՄՅԱՆ', 3),
('5804990438', 'ԱՍՏՂԻԿ ՄԱԿԱՐՅԱՆ', 0),
('3004890615', 'ՎԱՍՊՈՒՐ ՄԱՆՈՒՉԱՐՅԱՆ', 0),
('3824030845', 'ԼԵՌՆԻԿ ՆԱՎԱՍԱՐԴՅԱՆ', 39),
('7609960055', 'ԼԻԱՆԱ ՄԱԹԵՎՈՍՅԱՆ', 142),
('2330040067', 'ԿԱՐԵՆ ՄԻՆԱՍՅԱՆ', 0),
('5322070478', 'ՍՅՈՒԶԱՆՆԱ ԳՐԻԳՈՐՅԱՆ', 0),
('2605881105', 'ԳԱԳԻԿ ՍԵՐՈԲՅԱՆ', 36),
('1305980190', 'ՆԱՐԵԿ ԲԱՂԴԱՍԱՐՅԱՆ', 0),
('5832050428', 'ԱՆԱՀԻՏ ԽԱՉԱՏՐՅԱՆ', 0),
('6032030415', 'ԱԼՎԱՐԴ ՂԱԶԱՐՅԱՆ', 0),
('1512000353', 'ՎԱՉԱԳԱՆ ՈՍԿԱՆՅԱՆ', 0),
('4025030306', 'ԳՐԻՇԱ ՄԵԼԻՔՅԱՆ', 0),
('1823040349', 'ԷԴՄՈՆ ՊԵՏՐՈՍՅԱՆ', 0),
('6734030059', 'ՇՈՒՇԱՆԻԿ ՄՈՎՍԻՍՅԱՆ', 15),
('2911750500', 'ՀՈՎՀԱՆՆԵՍ ՄՈՒՐԱԴՅԱՆ', 0),
('5108990332', 'ԿԱՐԻՆԱ ՄԱԹԵՎՈՍՅԱՆ', 0),
('2925020151', 'ՎԻՏԱԼԻ ԲԱԲԱՅԱՆ', 118),
('6305990344', 'ԴԻԱՆԱ ՊԱՊԻԿՅԱՆ', 12);

-- 3) Resolve each row to a client_id.
--    Step 3a: match by social_card_number, but only when it identifies exactly
--    one active client (an empty/duplicated social card number is left for
--    the name fallback below rather than guessing).
UPDATE acra_classification_import a
JOIN clients c
  ON c.social_card_number = a.social_card_number
 AND c.deleted_at IS NULL
SET a.client_id = c.id
WHERE a.social_card_number IS NOT NULL
  AND a.social_card_number <> ''
  AND (
      SELECT COUNT(*) FROM clients c2
      WHERE c2.social_card_number = a.social_card_number
        AND c2.deleted_at IS NULL
  ) = 1;

--    Step 3b: for anything still unresolved, fall back to a normalized name
--    match (individual: name+surname in either order; legal: company_name),
--    again only when it identifies exactly one active client.
UPDATE acra_classification_import a
JOIN clients c
  ON c.deleted_at IS NULL
 AND (
      (c.type = 'legal' AND UPPER(TRIM(c.company_name)) = UPPER(TRIM(a.full_name)))
      OR (c.type = 'individual' AND (
          UPPER(TRIM(CONCAT(c.name, ' ', c.surname))) = UPPER(TRIM(a.full_name))
          OR UPPER(TRIM(CONCAT(c.surname, ' ', c.name))) = UPPER(TRIM(a.full_name))
      ))
 )
SET a.client_id = c.id
WHERE a.client_id IS NULL
  AND (
      SELECT COUNT(*) FROM clients c2
      WHERE c2.deleted_at IS NULL
        AND (
            (c2.type = 'legal' AND UPPER(TRIM(c2.company_name)) = UPPER(TRIM(a.full_name)))
            OR (c2.type = 'individual' AND (
                UPPER(TRIM(CONCAT(c2.name, ' ', c2.surname))) = UPPER(TRIM(a.full_name))
                OR UPPER(TRIM(CONCAT(c2.surname, ' ', c2.name))) = UPPER(TRIM(a.full_name))
            ))
        )
  ) = 1;

-- 4) Apply ACRA's classification to matched clients. Thresholds mirror
--    ClientClassificationService::classificationByOverdue().
UPDATE clients c
JOIN acra_classification_import a ON a.client_id = c.id
JOIN clients_classification cc
  ON cc.name = CASE
        WHEN a.max_overdue_days = 0                          THEN 'standard'
        WHEN a.max_overdue_days BETWEEN 1   AND 90            THEN 'monitored'
        WHEN a.max_overdue_days BETWEEN 91  AND 180           THEN 'substandard'
        WHEN a.max_overdue_days BETWEEN 181 AND 270           THEN 'suspicious'
        ELSE 'loss'
     END
SET c.acra_classification_id = cc.id;

-- 5) Rows that matched no client — resolve by hand (e.g. new client not yet
--    created, misspelled name, or a stale/blank social_card_number on file).
SELECT social_card_number, full_name, max_overdue_days
FROM acra_classification_import
WHERE client_id IS NULL;
