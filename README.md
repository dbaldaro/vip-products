# WooCommerce VIP Products

A powerful WordPress plugin that enables the creation and management of VIP-exclusive products in WooCommerce. This plugin allows store owners to create exclusive product catalogs visible only to specific users, perfect for membership-based stores or exclusive product offerings.

## Description

WooCommerce VIP Products extends WooCommerce by adding sophisticated VIP product management capabilities. It enables store owners to create products that are only visible and purchasable by designated VIP users, with full integration into WooCommerce's existing product management system.

## Features

### Core VIP Product Management
- Create and manage VIP-exclusive products visible only to selected users
- Bulk creation of VIP products from existing product templates
- Automatic VIP category assignment (ID: 538)
- Support for both single-user and multi-user VIP product assignments
- Dedicated VIP Products admin page for centralized management

### User Interface & Experience
- Intuitive VIP product management interface in WooCommerce product editor
- Enhanced user search with Select2 integration for smooth user selection
- Dedicated "VIP Access" tab in product data section
- VIP products section in customer's My Account page
- Pagination support for VIP product listings
- Bulk actions for VIP product management
- Quick-view options for assigned users

### Search & Filtering
- Smart integration with WooCommerce product search
- Compatible with Flatsome theme search functionality
- Automatic exclusion of VIP products from public search results
- Advanced filtering in admin product lists
- VIP product filter button in products page

### Product Creation & Management
- Create VIP products from scratch
- Convert existing products to VIP products
- Create VIP products from order items
- Create VIP products from templates
- Bulk VIP product creation capabilities

### Security & Access Control
- Robust user access verification system
- Secure meta data structure for VIP product information
- Protected VIP product visibility
- Automatic private visibility for unassigned VIP products

### Admin Features
- Dedicated VIP Products admin page
- Advanced product filtering and sorting
- Bulk management capabilities
- Real-time user search and assignment
- Plugin update status monitoring
- Debug logging functionality

### Integration & Compatibility
- Full WooCommerce integration
- Flatsome theme compatibility
- WordPress multisite support
- AJAX-powered user and product search
- Compatible with major WordPress caching plugins

## Requirements

- WordPress 5.0 or higher
- WooCommerce 3.0 or higher
- PHP 7.2 or higher
- MySQL 5.6 or higher

## Installation

1. Upload the `vip-products` folder to the `/wp-content/plugins/` directory
2. Activate the plugin through the 'Plugins' menu in WordPress
3. Ensure WooCommerce is installed and activated
4. Configure plugin settings as needed

## Usage

### Creating VIP Products

1. **From Scratch:**
   - Go to Products > Add New
   - Navigate to the 'VIP Access' tab
   - Set 'VIP Status' to 'Yes'
   - Search and select VIP users
   - Save the product

2. **From Template:**
   - Go to VIP Products Admin page
   - Use the template product search
   - Select a product to use as template
   - Click 'Create VIP Product from Template'

3. **From Order Item:**
   - Go to an order containing the product
   - Click the 'Create VIP' button next to the item
   - Select users and confirm

### Managing VIP Products

- Access the dedicated VIP Products admin page
- View all VIP products in a sortable, filterable table
- Manage user assignments
- Monitor stock status and pricing
- Perform bulk actions

### User Assignment

- Search users in real-time
- Assign single or multiple users
- View assigned users in tooltip
- Bulk user assignment available

## Version

Current Version: 1.1.20

## Author

David Baldaro

## Support

For support, feature requests, or bug reports, please contact the plugin author.

## License

This plugin is licensed under the terms of use provided by the author.

## Changelog

### 1.1.20
- Current stable release
- Enhanced VIP product management interface
- Improved user search functionality
- Added bulk creation from templates
- Debug logging improvements

## Technical Notes

- Uses standardized meta keys: `_vip_product` and `_vip_user_ids`
- Supports serialized array formats for user assignments
- Implements proper sanitization for user IDs
- Includes debug logging functionality
- Automatic update checking and notification system
