# VIP Products - Developer Documentation

## Meta Data Structure

VIP products in this plugin use two standardized meta fields:

1. `_vip_product` (string)
   - Value: 'yes' or 'no'
   - Purpose: Indicates whether a product is VIP or not
   - Usage: Primary flag for VIP status

2. `_vip_user_ids` (array)
   - Value: Serialized array of user IDs
   - Format: `a:1:{i:0;i:1;}` (example for user ID 1)
   - Purpose: Stores the list of users who have access to this VIP product

## Implementation Guidelines

### Creating VIP Products

When creating a VIP product, always set:
```php
update_post_meta($post_id, '_vip_product', 'yes');
update_post_meta($post_id, '_vip_user_ids', $user_ids_array);
```

### Querying VIP Products

When querying for VIP products, use this meta query structure:
```php
$args = array(
    'post_type' => 'product',
    'meta_query' => array(
        'relation' => 'AND',
        array(
            'key' => '_vip_product',
            'value' => 'yes',
            'compare' => '='
        ),
        array(
            'key' => '_vip_user_ids',
            'value' => $user_id,
            'compare' => 'LIKE'
        )
    )
);
```

### User Access Check

To check if a user has access to a VIP product:
```php
$vip_status = get_post_meta($product_id, '_vip_product', true);
$vip_user_ids = get_post_meta($product_id, '_vip_user_ids', true);
$has_access = $vip_status === 'yes' && in_array($user_id, (array)$vip_user_ids);
```

## Legacy Notice

The legacy meta key `_product_visibility_type` is deprecated and should not be used. If found in code, it should be updated to use the standard meta keys above.

## Category Assignment

VIP products are automatically assigned to the VIP category (ID: 538) when marked as VIP products.

## Important Notes

1. Always use the standard meta keys described above
2. Never mix different meta key structures
3. When updating existing code, ensure it follows these standards
4. Use proper sanitization when saving user IDs
5. Always verify both VIP status AND user access when displaying products
