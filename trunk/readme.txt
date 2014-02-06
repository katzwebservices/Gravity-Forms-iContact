=== Gravity Forms iContact Add-on ===
Tags: iContact, gravity forms, forms, gravity, form, crm, gravity form, email, newsletter, i Contact, mailing list, email marketing, newsletters
Requires at least: 2.8
Tested up to: 3.8.1
Stable tag: trunk
Contributors: katzwebdesign, katzwebservices
Donate link:https://www.paypal.com/cgi-bin/webscr?cmd=_donations&business=zackkatz%40gmail%2ecom&item_name=Gravity%20Forms%20iContact&no_shipping=0&no_note=1&tax=0&currency_code=USD&lc=US&bn=PP%2dDonationsBF&charset=UTF%2d8

Integrate the remarkable Gravity Forms plugin with iContact.

== Description ==

### The best way to integrate with iContact

Integrate your Gravity Forms forms so that when users submit a form entry, the entries get added to iContact. Link any field type with iContact, including custom fields.

####Learn about email marketing with iContact:

<strong>MessageBuilder&trade;</strong><br />
Create beautiful messages in minutes with <strong>iContact's drag-and-drop message creation tool</strong>. Simply pick a template from iContact's well-stocked template library, and make it your own by adding your brand colors, images, links, and text.

<strong>MessageCoder&trade;</strong><br />
If you are a design or HTML pro, this is the tool for you. Use MessageCoder to import HTML from your favorite template or to start a message from scratch. Then use iContact's table editing tools, message previews, and custom color swatches to put the final touches on a message that's exactly what you want.

<strong>Email Templates</strong><br />
Choose from hundreds of thoughtfully designed templates guaranteed to make your messages pop.

<strong>Email Delivery</strong><br />
iContact moves your messages straight from your business to your customers' inboxes, every time. How do they do it? With a team of delivery specialists, SpamCheck&trade;, and close relationships with all the major Internet service providers.

== Screenshots ==

1. It's easy to integrate Gravity Forms with iContact: set up a "Feed" and match up the fields you'd like sent to iContact
1. Create multiple feeds for different forms that get added to different iContact lists
1. The Gravity Forms iContact Add-on settings page

== Installation ==

1. Upload plugin files to your plugins folder, or install using WordPress' built-in Add New Plugin installer
1. Activate the plugin
1. Go to the plugin settings page (under Forms > Settings > iContact)
1. Enter the information requested by the plugin.
1. Click Save Settings.
1. If the settings are correct, it will say so.
1. Follow on-screen instructions for integrating with iContact.

== Frequently Asked Questions ==

= Does this plugin require Gravity Forms? =
Yes, it does.

= Does this plugin require iContact? =
Yes, it does.

= What's the license for this plugin? =
This plugin is released under a GPL license.

== Changelog ==

= 1.3.1 =
* Removed fatal error development code. Sorry about that!

= 1.3 =
* Tested with Gravity Forms 1.7
* Added: Now fully supports sandbox mode (<a href="http://wordpress.org/support/topic/broken-sandbox-mode-support" rel="nofollow">as requested</a>)
* Fixed: <a href="http://wordpress.org/support/topic/checkbox-fields-arent-mapping-to-icontact" rel="nofollow">Issue</a> with checkboxes not submitting values
* Improved: If list names have public names, they're added to the iContact name instead of replacing it (<a href="http://wordpress.org/support/topic/list-names" rel="nofollow">as requested</a>)

= 1.2.1 =
* Improved Custom Field support: now sorts alphabetically, no longer has limit on number of custom fields.
* Fixed PHP warning `array_diff() ... on line 37`
* Converted cached lists and custom fields to site_transient instead of transient; this will work better for WordPress Multisite.

= 1.2 =
* Fixed issue where only 20 lists were being fetched for Feed setup
* Improved error handling by considering "warnings" errors and stopping processing if iContact doesn't successfully create contact.
* Added: Entries now get assigned a iContact ID that links directly to the edit page for the iContact contact
* Added: Notes are now added to Entries with the success or error messages from exporting to iContact

= 1.1.1 =
* Fixed issue users were having where form merge fields don't get pulled properly (see <a href="http://wordpress.org/support/topic/plugin-gravity-forms-icontact-add-on-plugin-does-not-pull-fields-to-match">here</a> and <a href="http://wordpress.org/support/topic/plugin-gravity-forms-icontact-add-on-does-not-pull-in-data-from-forms">here</a>). Issue only affected accounts with no custom fields defined.

= 1.1 =
* Fixed issues where contacts were not being added to iContact
* Added support for lists with non-alphanumeric characters
* Hopefully fixed <a href="http://wordpress.org/support/topic/plugin-gravity-forms-icontact-add-on-plugin-does-not-pull-fields-to-match">issue where plugin doesn't pull fields</a> to match up with the forms in Gravity Forms feed setup.
* Added iContact signup link when plugin is not configured

= 1.0 =

* Liftoff!

== Upgrade Notice ==

= 1.3.1 =
* Removed fatal error development code. Sorry about that!

= 1.3 =
* Tested with Gravity Forms 1.7
* Added: Now fully supports sandbox mode (<a href="http://wordpress.org/support/topic/broken-sandbox-mode-support" rel="nofollow">as requested</a>)
* Fixed: <a href="http://wordpress.org/support/topic/checkbox-fields-arent-mapping-to-icontact" rel="nofollow">Issue</a> with checkboxes not submitting values
* Improved: If list names have public names, they're added to the iContact name instead of replacing it (<a href="http://wordpress.org/support/topic/list-names" rel="nofollow">as requested</a>)

= 1.2 =
* Fixed issue where only 20 lists were being fetched for Feed setup
* Improved error handling by considering "warnings" errors and stopping processing if iContact doesn't successfully create contact.
* Added: Entries now get assigned a iContact ID that links directly to the edit page for the iContact contact
* Added: Notes are now added to Entries with the success or error messages from exporting to iContact

= 1.1.1 =
* Fixed issue users were having where form merge fields don't get pulled properly (see <a href="http://wordpress.org/support/topic/plugin-gravity-forms-icontact-add-on-plugin-does-not-pull-fields-to-match">here</a> and <a href="http://wordpress.org/support/topic/plugin-gravity-forms-icontact-add-on-does-not-pull-in-data-from-forms">here</a>). Issue only affected accounts with no custom fields defined.

= 1.1 =
* Fixed issues where contacts were not being added to iContact
* Added support for lists with non-alphanumeric characters
* Hopefully fixed <a href="http://wordpress.org/support/topic/plugin-gravity-forms-icontact-add-on-plugin-does-not-pull-fields-to-match">issue where plugin doesn't pull fields</a> to match up with the forms in Gravity Forms feed setup.

= 1.0 =

* Liftoff!