-- Suto Accounting v58
-- Backfill legacy items.opening_stock into stock_movements so stock is movement-ledger based.
-- Safe to run once on the existing database.

INSERT INTO stock_movements(company_id,item_id,movement_date,quantity,unit_price,movement_type,note)
SELECT i.company_id, i.id, DATE(COALESCE(i.created_at, CURRENT_DATE)), i.opening_stock, i.purchase_price, 'opening_stock', 'Opening Stock (legacy backfill)'
FROM items i
WHERE i.active=1
  AND i.item_type='product'
  AND ABS(COALESCE(i.opening_stock,0)) > 0.0001
  AND NOT EXISTS (
      SELECT 1 FROM stock_movements sm
      WHERE sm.company_id=i.company_id
        AND sm.item_id=i.id
        AND sm.movement_type='opening_stock'
  );
