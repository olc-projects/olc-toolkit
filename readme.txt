=== OLC Toolkit ===
Contributors: ourlittlecompany
Requires at least: 5.8
Tested up to: 6.8
Requires PHP: 7.4
Stable tag: 1.1.1
License: GPLv2 or later

A toolkit plugin for OLC development.

== Changelog ==

= 1.1.1 =
* Sites can now follow a different update branch (for example a test site on staging) by defining OLC_TOOLKIT_UPDATE_BRANCH in wp-config.php. Sites without it keep updating from master.

= 1.1.0 =
* New add-on system: the OLC Toolkit page now lists add-ons that can be enabled or disabled per site.
* SiteMailer Alerts is now an add-on with its own settings page (OLC Toolkit > SiteMailer).
* Sites that already had a SiteMailer webhook keep the add-on enabled after updating.
* New Traffic Log add-on (moved from the child theme): settings under OLC Toolkit > Traffic Log, reports under each tracked post type's menu. Existing settings and logged traffic carry over.

= 1.0.0 =
* Initial release.
