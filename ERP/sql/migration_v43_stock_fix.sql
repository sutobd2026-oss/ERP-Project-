-- Fix historical Purchase Bill stock movements created by older versions.
-- Older code stored purchase stock as negative sale movements.
UPDATE stock_movements sm
JOIN transactions t ON t.id = sm.transaction_id
SET sm.quantity = ABS(sm.quantity),
    sm.movement_type = 'purchase'
WHERE t.txn_type = 'purchase'
  AND sm.movement_type = 'sale'
  AND sm.quantity < 0;
