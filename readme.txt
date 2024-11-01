=== ThreeWP Email Reflector ===
Tags: mailing list, mailinglist, reflector, email, newsletter, threewp, daemon, imap
Requires at least: 3.3
Tested up to: 3.5
Stable tag: trunk
Donate link: http://mindreantre.se/donate/
Contributors: edward mindreantre,Sverigedemokraterna IT

A mailing list deamon that reflects email from an IMAP account to readers.

== Description ==

Email Reflector is a simple, drop-in mailing list daemon that fetches mail from IMAP servers and then handles the mails according to various rules.
In order to use this plugin you must have one IMAP account per mailing list you create.

This reflector differs from a complete mailing list because it does not have archiving capability, nor any options for users to subscribe and unsubscribe themselves.
For that functionality a more complicated mailing list daemon is recommended. This plugin is for quick and simple handling of mailing lists, to the point that Wordpress users
themselves can administer the lists.

Each mailing list has various settings and lists of subscribers (readers) and writers. Lists can be completely open or closed to allow mail only from confirmed e-mail addresses.

Access settings allow the administrator to only allow specific users to administer specific list settings. 

Since the plugin can use any IMAP server and does not need to be contactable from the Internet, the Wordpress installation can be installed privately. As long as Wordpress gets to
run its scheduled cron jobs every once in a while the plugin will be able to fetch and send mail.

= Readers =

A reader recieves all accepted mail to the mailing list.

= Write access to the list =

There are several combinations of openness available:

* No writers or moderators means that all mail will be accepted.
* Having a list of email addresses in the writers box will accept mail from only those addresses.
* Authorization writers have to confirm their messages by replying to confirmation e-mails sent by the reflector.
* Having addresses supplied in the moderators box will send messages to the moderators for confirmation, except for mails sent from addresses in the unmoderated writers list.
* Unmoderated writers do not have to have their emails moderated, no matter if there are moderators set or not.

= Authorization =

Whenever a message needs to be authorized an e-mail will be sent to the user requesting that the user simply reply to the message. This e-mail will then be read by the
E-mail Reflector and then the message will be forwarded further (either to a moderator or to the group, depending on the settings).

When the mailing list becomes known to the world, nothing stops complete strangers from faking the from-address and getting through to the list. In order to combat this the list
can be locked down by using the "all messages must be authorized"-setting. When activated, all messages (including those from moderators) must be authorized by the sender before
being processed by the list.

This complicates usage of the list and should only be used when the group e-mail address is no longer secret. 

= Guest access =

If you want to allow unknown users to write to the list, you can use the "Non-writer action"-box. By default, all mail from unknown users is accepted. You can change this setting to:

* Reject
* Reject with an error message
* Require authorization to force the user to confirm that the message was not spoofed.
* Require the message to be moderated.
* Require authorization and then moderation.

= Access control =

The blog administrator can allow users to administer various parts of specific mailing lists.

The parts that can be administered are the general settings, the IMAP server settings and the list of readers and writers.

= Recommended installation instructions =

* Install the plugin on a separate Wordpress installation.
* Create an IMAP account.
* Create and configure a mailing list.
* Create a user to administer the mailing list.
* Allow the user access to the mailing list readers and writers.

If you leave a list disabled, it will not be collected automatically. Instead, each user that has "Collect"-access to the list can login and collect the list manually. This is good for installations with several tens of lists (to prevent PHP timeouts).

= Priority =

Each mail sent to a mailing list is assigned a priority. The default priority of 100 can be modified either globally or per list.

The global setting, priority decrease per, will decrease the priority of a batch depending on how many readers there are. This setting is useful if mails sent so several thousand users are deemed less important than mails sent to lists of only a couple of readers.

Each mailing list has a priority modifier. This setting, which can be a positive or negative number, will offset the base priority by a specific number. Set this to a positive number to make sure that the mails are sent out quicker. Set this to a negative number to decrease the priority.

When sending mails Email Reflector will sort them first by their priority. If several mails share the same priority, those with the fewest failures are sent first. After that the mails are sent in chronological order.

= Technical: Filters =

There are several filters that allow other plugins to interact with Email Reflector. See the __construct method for a list of available filters.

The filter methods are all documented. For a complete example of the filters, see the plugin SD Email Reflector Remote Access which provides remote access functionality via the filters. 

= Technical: wp-cron using cron =

If you "manually" cron your Wordpress by using a wget command or similar, set the cron time in the Email Reflector settings to one minute less than how often you manually cron.

= Technical: Simultaneous fetching and sending =

CURL code enables the plugin to check several IMAP accounts simultaneously and send several mails at once.

Ensure that your custom configured server has enough php-cgi children available and that your SMTP server accepts several connections
from the same IP address. 

= Technical: CURL follow location =

If you notice that mails are being queued but not send, enable the setting "curl follow location". This allows curl to be redirected to wherever your .htaccess file wants the request to go.

The author's own machine runs nginx which doesn't care about .htaccess files, but mails are sent perfectly anyways. In order to save CPU power, this setting should be kept off whenever possible, else the redirects will each require an extra start of the Wordpress engine.

The setting is defaulted to off, due to lack of feedback.  

= Technical: Empty messages for Hotmail users =

Some users have reported receiving empty messages. This seems to be caused by using a self-configured postfix server.

The solution was to use a web hotel's SMTP server instead (see Debian package msmtp). I think it has something to do with incorrectly set TXT DNS settings.

= Technical: Event driver =

If (1) you have access to your e-mail server's log files and (2) your users can't wait several minutes for the cron to run, why not install the event driver? A monitor script run on the mail server informs the e-mail reflector that mail has arrived and the relevant IMAP accounts should be checked.

See the event_driver directory for installation instructions.

= Technical: Forwarding emails =

The Email Reflector doesn't not modify the body of the forwarded e-mails so GPG keys remain valid.

= Technical: Mail size =

In order to receive large emails (>10mb) check the following settings:

- In your mysql my.cnf, set max_allowed_packet to 256M.
- In your php.ini for cgi/fpm, set memory_limit to 256M.
- In your wp-config.php, add ``define('WP_MEMORY_LIMIT', '256M');``

Note that not all mail servers allow mails larger than 10MB.

== Installation ==

1. Activate the plugin sitewide through the 'Plugins' menu in WordPress.
1. Create an IMAP account for each mailing list.

== Screenshots ==

1. Email reflector overview
1. Editing a mailing list
1. Queue
1. Activity log
1. Plugin settings
1. Uninstall tab
1. Access settings for a mailing list. Shows only one user with access to the Readers and Writers section.

== Changelog ==

= 1.19 2013-01-05 =
* New: Return-path in sent e-mails.
* Fix: Compatability with WP 3.5 roles

= 1.18 =
* New: Added MULTITAIL_OPTIONS into event_driver monitor.conf
* Change: Random and hash sent from event driver are 4 characters long now.
* Dev: Added threewp_email_reflector_discard_message filter
* Dev: Refactoring of code


= 1.17 2012-12-05 =
* New: Added access: View readers+writers.
* New: Event driver.
* Fix: pass-by-reference removed.
* Fix: access for lists other than ID can now be reached.

= 1.16 2012-08-09 =
* Allow setting of curl_follow_location
* Fixed global enable/disable bug
* Fixed global settings checkbox bug
* Allow global 1 minute cron
* Fix: http://www.exploit-db.com/exploits/20365/ Subject filtered.

= 1.15 2012-06-15 =
* Fix reply-to addressing.
* Sending through Gmail should work now.
* CURL now follows locations, needed on some installations.
* Code cleanup.

= 1.14 2012-04-18 =
* Added lots of filters to help with modifying list settings.
* Added filters for plugins to hook themselves into global / list settings.
* Logging is now handled by ThreeWP Activity Monitor.

= 1.13 2012-02-02 =
* Fixed warning message on saving with no logging
* Priority can decrease by size of readers
* Priority modifier per list
* Outgoing mails are now sorted in this order: priority DESC, failures ASC, message_id ASC

= 1.12 2012-02-01 =
* New queue system
* Uninstall tab works and doesn't automatically uninstall all settings anymore
= 1.11 2012-01-25 =
* Allow for empty reply-to address.
= 1.10 =
* message_is_from_ourselves now first checks the administrative reply to or the normal reply to address.
* New access class: message modification.
* Queue: From row works. 
= 1.9 =
* Codes are now 32 chars, from 64.
* Changed code messages (from, not to)
* Added rejection and confirmation messages for codes
* Checks for circular sending (rejects mails sent from the list itself)
= 1.8 =
* Simultaeneous mail sending 
* New logging system
* Ability to delete log messages
* Codes have an overview
* UI improvements
* No more errors on activation
* Code cleanup
= 1.7 =
* Administrative reply-to address added
* All of a user's list access can be viewed (lists per user)
* Asynchronous mail collection added
* Collection settings added (max connections, connections per server, cron minutes)
* Log is paged
= 1.6 =
* Extra save button
* List name is displayed in the header when editing
* New access level: Collect
* Verbose collect messages (when collecting manually) 
= 1.5 =
* User access bug fixed (couldn't allow several users at once)
* Reply-to address is visible in the overview
= 1.4 =
* Precedence: Bulk added
* Multiline headers are now properly removed
= 1.3 =
* Moderation and authorization log messages are now info level
* In-Reply-To headers are no longer butchered
= 1.2 =
* CC, BCC and Subject fields are cleared, now case-insensitive
= 1.1 =
* Queue limit actually works this time
* Queue is cleaned
* Addresses in TO, CC and BCC fields are scanned for the list address
= 1.0 =
* Initial public release

== Upgrade Notice ==

= 1.14 =
* The log table will be deleted. If you want to keep it, then save it before you upgrade. 

= 1.12 =
* Make sure that the queue is empty before upgrading. 

= 1.10 =
* Be sure to not have any messages or codes queued, since the data format in the message storage has changed. 

= 1.9 =
Since codes are now half as long, all codes still waiting for answers won't work anymore. Ask the admin to manually accept the codes.
