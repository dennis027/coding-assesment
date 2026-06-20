-- Part 3a: Top 5 merchants by total completed payment volume in the last 30 days.
-- Merchants with zero completed payments must appear with 0, not be excluded.

SELECT
    m.id,
    m.business_name,
    COALESCE(SUM(p.amount), 0) AS total_payment_volume
FROM merchants m
-- LEFT JOIN ensures merchants with no orders (or no payments) are included
LEFT JOIN merchant_orders mo
    ON mo.merchant_id = m.id
LEFT JOIN payments p
    ON  p.merchant_order_id = mo.id
    AND p.status            = 'completed'
    AND p.created_at        >= NOW() - INTERVAL 30 DAY
GROUP BY
    m.id,
    m.business_name
ORDER BY
    total_payment_volume DESC,
    m.id ASC          -- deterministic tie-breaking
LIMIT 5;

-- Design notes:
-- • The date filter belongs on the JOIN condition, not in a WHERE clause.
--   Moving it to WHERE would turn the LEFT JOIN into an implicit INNER JOIN,
--   dropping merchants with no qualifying payments.
-- • COALESCE(SUM(...), 0) handles merchants whose SUM is NULL (no rows joined).
-- • SUM(p.amount) aggregates at the payment level, not the order level, to avoid
--   double-counting orders that have multiple payment attempts.
