SELECT DISTINCT p.ID, p.post_title, pm1.meta_value as is_vip, pm2.meta_value as vip_user_ids
FROM wp_posts p
INNER JOIN wp_postmeta pm1 ON p.ID = pm1.post_id 
  AND pm1.meta_key = '_vip_product' 
  AND pm1.meta_value = 'yes'
INNER JOIN wp_postmeta pm2 ON p.ID = pm2.post_id 
  AND pm2.meta_key = '_vip_user_ids'
  AND (
    pm2.meta_value LIKE 'a:1:{i:0;i:1;}' /* Exact match for array with just user 1 */
    OR pm2.meta_value LIKE 'a:%{i:0;i:1;%' /* Array starting with user 1 */
    OR pm2.meta_value LIKE '%i:1;%' /* Array containing user 1 anywhere */
  )
WHERE p.post_type = 'product'
AND p.post_status = 'publish'
ORDER BY p.ID;
