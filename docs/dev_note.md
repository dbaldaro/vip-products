# VIP Products - Developer Documentation

## Meta Data Structure

VIP products in this plugin use two standardized meta fields:

1. `_vip_product` (string)
   - Value: 'yes' or 'no'
   - Purpose: Indicates whether a product is VIP or not
   - Usage: Primary flag for VIP status

2. `_vip_user_ids` (array)
   - Value: Serialized array of user IDs
   - Format Examples:
     - Single user: `a:1:{i:0;i:1;}` (for user ID 1)
     - Multiple users: `a:2:{i:0;i:1;i:1;i:1027;}` (for user IDs 1 and 1027)
   - Purpose: Stores the list of users who have access to this VIP product

## Implementation Guidelines

### Creating VIP Products

When creating a VIP product, always set:
```php
update_post_meta($post_id, '_vip_product', 'yes');
update_post_meta($post_id, '_vip_user_ids', $user_ids_array);
```

### Querying VIP Products

When querying for VIP products, use this meta query structure to handle all possible serialized array formats:
```php
// Create the possible serialized formats
$single_user = serialize(array($current_user_id)); // a:1:{i:0;i:1;}
$user_at_start = sprintf('a:%%{i:0;i:%d;%%', $current_user_id); // a:2:{i:0;i:1;...}
$user_anywhere = sprintf('i:%d;', $current_user_id); // matches i:1; anywhere in string

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
            'relation' => 'OR',
            array(
                'key' => '_vip_user_ids',
                'value' => $single_user,
                'compare' => '='
            ),
            array(
                'key' => '_vip_user_ids',
                'value' => $user_at_start,
                'compare' => 'LIKE'
            ),
            array(
                'key' => '_vip_user_ids',
                'value' => $user_anywhere,
                'compare' => 'LIKE'
            )
        )
    )
);
```

### User Access Check

To check if a user has access to a VIP product:
```php
function user_has_vip_access($product_id, $user_id) {
    // Get VIP status
    $vip_status = get_post_meta($product_id, '_vip_product', true);
    if ($vip_status !== 'yes') {
        return false;
    }

    // Get VIP user IDs
    $vip_user_ids = get_post_meta($product_id, '_vip_user_ids', true);
    if (empty($vip_user_ids)) {
        return false;
    }

    // Unserialize if it's a string
    if (is_string($vip_user_ids)) {
        $vip_user_ids = maybe_unserialize($vip_user_ids);
    }

    // Convert to array if it's not already
    if (!is_array($vip_user_ids)) {
        $vip_user_ids = array($vip_user_ids);
    }

    // Check if user is in the allowed list
    return in_array($user_id, $vip_user_ids);
}
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
6. When querying VIP products, always handle all possible serialized array formats:
   - Single user array: `a:1:{i:0;i:1;}`
   - Multi-user array starting with target user: `a:2:{i:0;i:1;...}`
   - User ID anywhere in array: `i:1;`
7. Always use `maybe_unserialize()` when handling the user IDs array to prevent data corruption
