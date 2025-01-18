# WooCommerce VIP Products

A WordPress plugin that enables the creation of VIP-exclusive products in WooCommerce, allowing store owners to restrict product visibility to specific users.

## Description

WooCommerce VIP Products extends WooCommerce by adding the ability to create products that are only visible and purchasable by designated VIP users. This is perfect for stores that want to offer exclusive products to specific customers or maintain a VIP-only product catalog.

## Features

- Create VIP-exclusive products visible only to selected users
- Easy-to-use VIP product management interface in the WooCommerce product editor
- User search functionality for adding VIP customers
- Automatic VIP category assignment
- Integration with WooCommerce product search
- Compatible with Flatsome theme search
- My Account page integration for VIP products
- Select2 enhanced user selection interface

## Requirements

- WordPress
- WooCommerce
- PHP 5.6 or higher

## Installation

1. Upload the `vip-products` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Ensure WooCommerce is installed and activated

## Usage

### Creating VIP Products

1. Go to Products > Add New or edit an existing product
2. Look for the 'VIP Access' tab in the product data section
3. Set the 'VIP Status' to 'VIP Members Only'
4. Use the user search field to select which customers can access this product
5. Save the product

### Managing VIP Access

- Products can be set as either public (visible to everyone) or VIP-only
- VIP products will automatically be assigned to the VIP category (ID: 538)
- Only selected VIP users will be able to see and purchase VIP products
- VIP products are automatically filtered out of regular search results

## Version

Current Version: 1.1.5

## Author

David Baldaro

## Support

For support or feature requests, please contact the plugin author.

## License

This plugin is licensed under the terms of use provided by the author.

## Changelog

### 1.1.5
- Current stable release

## Notes

- VIP products without assigned users will be set to private visibility
- The plugin automatically integrates with the Flatsome theme's search functionality
- VIP products are excluded from regular search results to maintain exclusivity
