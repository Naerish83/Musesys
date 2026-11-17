-- Quick diagnostic before reimport
-- Run these in sequence to understand the current state

-- 1. How many products have ANY product_brand term attached?
SELECT COUNT(DISTINCT p.ID) as products_with_brand
FROM wp_posts p
INNER JOIN wp_term_relationships tr ON p.ID = tr.object_id
INNER JOIN wp_term_taxonomy tt ON tr.term_taxonomy_id = tt.term_taxonomy_id
WHERE tt.taxonomy = 'product_brand'
AND p.post_type = 'product'
AND p.post_status = 'publish';

-- 2. Sample: which brands actually have products?
SELECT t.name, tt.count
FROM wp_terms t
INNER JOIN wp_term_taxonomy tt ON t.term_id = tt.term_id
WHERE tt.taxonomy = 'product_brand'
AND tt.count > 0
ORDER BY tt.count DESC
LIMIT 20;

-- 3. Are the counts just stale/uncalculated?
-- If query 1 returns products but query 2 shows 0 counts,
-- run this to recalculate:
-- wp_update_term_count_all() via WP-CLI:
-- wp term recount product_brand
