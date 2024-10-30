=== Integration for Salsa and Gravity Forms ===
Contributors: drywallbmb, rxnlabs, harmoney
Tags: forms, crm, integration
Requires at least: 3.6
Tested up to: 6.0
Stable tag: trunk
License: GPLv2 or later
License URI: http://www.gnu.org/licenses/gpl-2.0.html

A Gravity Forms Add-On to feed submission data into the Salsa "Classic" CRM/fundraising/advocacy platform.

== Description ==

If you're using the [Gravity Forms](http://www.gravityforms.com/) plugin, you can now integrate it with the [Salsa Labs](https://www.salsalabs.com/) "Classic" platform. This Add-On supports creating or updating supporter records, as well as some preliminary support for donation records (still highly experimental).

To use this Add-On, you'll need to:

1. Have an licensed, active version of Gravity Forms >= 1.9.3
2. Have a working Salsa instance, as well as credentials for at least one administrative or api-level user

If you meet those requirements, this plugin is for you, and should make building new forms and passing supporter data into Salsa much easier than manually mucking with HTML provided by Salsa.

*Initial development of this plugin was funded in part by the [Children's Inn at NIH](http://childrensinn.org/).*

== Installation ==

1. Log into your Wordpress account and go to Plugins > Add New. Search for "Gravity Forms Salsa" in the "Add plugins" section, then click "Install Now". Once it installs, it will say "Activate". Click that and it should say "Active". Alternatively, you can upload the gravityforms-salsa directory directly to your plugins directory (typically /wp-content/plugins/)
2. Navigate to Forms > Settings in the WordPress admin
3. Click on "Salsa API" in the lefthand column of that page
4. Enter your organization's Salsa domain (aka "node") as well as a valid username/password combination for a Salsa administrator account. Your domain is typically something like salsa3.salsalabs.com or salsa.wiredforchange.com and you can find this in the URL of any Salsa page while logged in.
5. Once you've entered your Salsa account details, create a form or edit an existing form's settings. You'll see a "Salsa API" tab in settings where you can create a "Salsa API Feed". This allows you to pick and choose which form fields you'll send over to Salsa from the form. You also have the option of automatically putting form signers into groups, or setting some conditional logic to pick and choose which information gets sent.

== Frequently Asked Questions ==

= Does this work with Ninja Forms, Contact Form 7, Jetpack, etc? =

Nope. This is specifically an Add-On for Gravity Forms and will not have any effect if installed an activated without it.

= What version of Gravity Forms do I need? =

You must be running at least Gravity Forms 1.9.3.

= What kinds of data can this pass to Salsa? =

Right now, this Add-On is strictly for passing *constituent data* to Salsa. It does not support advocacy forms or other Salsa forms. (Some functionality for donations is provided but is considered **highly experimental** and should not be used.) It can pass all kinds of supporter data, including the usual name, address, phone, etc as well as groups, tags and custom fields.

= What's the deal with donations? =

Salsa does not support processing payment information via their API, so while donation information can be gathered and passed to Salsa with this Add-On, it will not actually trigger a financial transaction. As this Add-On provides no logic to ensure your site is on a secure HTTPS connection, that's probably for the best. Don't collect financial details on your own. :)

== Changelog ==

= 1.0.5 =
* BUGFIX: Will now fetch all groups from Salsa instead of just the first 500.

= 1.0.4 =
* Reducing time groups listing is cached for from 1 week to 1 day.
* Improving documentation.

= 1.0.3 =
* Cleanup to better conform to WP coding standards.
* Added links to facilitate communicating the value of this plugin to others.

= 1.0.2 =
* Removing explicit definition of CURLOPT_SSLVERSION to support servers blocking v3 due to POODLE
* Adding organization_KEY and chapter_KEY fields in config to provide more specificity to Salsa when submitting data

= 1.0 =
* Initial release.
