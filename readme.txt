=== KMM KRoN ===
Contributors: kronemultimedia
Tags: scale,cronjobs
Requires at least: 4.9
Tested up to: 5.2.2
Requires PHP: 7.1
Stable tag: trunk
License: MIT

this plugin, disables the default cron implementation.
and ships with a WP-CLI based command, jobs are not stored in the options table.


to use it:
  - enable the plugin
  - run the wp-cli command on a server/container (can be a seperat one)
    - `wp krn_kron`

> to convert current cron jobs run `wp krn_kron_conver`


== Changelog ==
= 0.1.2 =

* add README

