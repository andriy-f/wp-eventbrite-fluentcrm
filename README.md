# Eventbrite to FluentCRM Integration

A WordPress plugin that automatically syncs attendee information from Eventbrite to FluentCRM using webhooks.

## Description

This plugin receives webhooks from Eventbrite when attendees register for your events and automatically creates or updates their contact information in FluentCRM. This seamless integration helps you build your email list and manage your event attendees more effectively.

## Features

- 🔗 Automatic webhook integration with Eventbrite
- 📧 Creates or updates contacts in FluentCRM
- 🏷️ Supports custom tags and list assignments
- 🔐 Webhook signature verification for security
- 📝 Debug logging for troubleshooting
- ⚙️ Easy configuration through WordPress admin

## Requirements

- WordPress 5.0 or higher
- PHP 7.2 or higher
- [FluentCRM](https://wordpress.org/plugins/fluent-crm/) plugin installed and activated
- Eventbrite account with API access

## Installation

1. Download the plugin files
2. Upload the `wp-eventbrite-fluentcrm` folder to `/wp-content/plugins/` directory
3. Activate the plugin through the 'Plugins' menu in WordPress
4. Install and activate FluentCRM if not already installed
5. Go to Settings → Eventbrite FluentCRM to configure

## Configuration

### Step 1: Get Your Eventbrite API Token

1. Log in to your Eventbrite account
2. Go to [Account Settings → App Management](https://www.eventbrite.com/account-settings/apps)
3. Click "Create New Key" or use an existing Private Token
4. Copy your Private Token

### Step 2: Configure the Plugin

1. In WordPress admin, go to **Settings → Eventbrite FluentCRM**
2. Enter your **Eventbrite API Token**
3. (Optional) Set a **Webhook Secret** for added security
4. (Optional) Configure **Default Tags** to automatically tag all contacts from Eventbrite
5. (Optional) Configure **Default Lists** to add contacts to specific FluentCRM lists
6. Enable **Debug Mode** if you need to troubleshoot issues
7. Click **Save Changes**

### Step 3: Set Up Eventbrite Webhook

1. Copy the **Webhook URL** displayed at the top of the settings page
2. Go to your Eventbrite event dashboard
3. Navigate to **Manage → Webhooks**
4. Click **Create Webhook** or **Add Webhook**
5. Paste the Webhook URL from step 1
6. Select the events you want to track:
   - **Order Placed** - Triggers when a new order is created
   - **Attendee Updated** - Triggers when attendee information is updated
7. (Optional) Enter the same Webhook Secret you configured in the plugin
8. Save the webhook

## Supported Webhook Events

The plugin currently handles the following Eventbrite webhook events:

- `order.placed` - When a new order is placed
- `attendee.updated` - When attendee information is updated

## Data Mapping

The plugin maps the following data from Eventbrite to FluentCRM:

| Eventbrite Field | FluentCRM Field |
|------------------|-----------------|
| Email            | Email           |
| First Name       | First Name      |
| Last Name        | Last Name       |
| Cell Phone       | Phone           |

## Debug Logging

When debug mode is enabled, the plugin logs webhook activities to the WordPress debug log. To view logs:

1. Enable debug mode in plugin settings
2. Add these lines to your `wp-config.php`:
   ```php
   define('WP_DEBUG', true);
   define('WP_DEBUG_LOG', true);
   define('WP_DEBUG_DISPLAY', false);
   ```
3. Check the log file at `/wp-content/debug.log`

## Troubleshooting

### Webhook not working

1. Verify FluentCRM is installed and activated
2. Check that your Eventbrite API token is correct
3. Enable debug mode and check the debug log for errors
4. Test the webhook URL directly using a tool like Postman
5. Ensure your WordPress site is publicly accessible (webhooks won't work on localhost)

### Contacts not appearing in FluentCRM

1. Check FluentCRM is properly configured
2. Verify the webhook is firing from Eventbrite (check webhook history in Eventbrite)
3. Enable debug logging to see detailed error messages
4. Check that the email address is valid in the Eventbrite order

## Security

- The plugin uses WordPress REST API for webhook endpoints
- Supports webhook signature verification using HMAC SHA256
- All settings are properly sanitized and escaped
- Follows WordPress coding standards and security best practices

## Support

For issues, questions, or contributions, please visit:
- [GitHub Repository](https://github.com/andriy-f/wp-eventbrite-fluentcrm)

## License

This plugin is licensed under the MIT License. See the [LICENSE](LICENSE) file for details.

## Author

**Andriy Fetsyuk**
- GitHub: [@andriy-f](https://github.com/andriy-f)

## Changelog

### 1.0.0
- Initial release
- Webhook integration with Eventbrite
- FluentCRM contact synchronization
- Admin settings page
- Debug logging support
