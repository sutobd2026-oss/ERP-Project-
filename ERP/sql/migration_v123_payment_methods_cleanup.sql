-- Suto Accounting ERP v123
-- Only Cash, Cheque and active Bank Accounts are supported for new payments.
-- No separate Mobile Banking/Card/Other tables exist in this project.
-- The optional payment_methods master table is obsolete and can be dropped.

DROP TABLE IF EXISTS payment_methods;

-- Keep payment_lines: it stores transaction history. Existing historical rows are preserved.
