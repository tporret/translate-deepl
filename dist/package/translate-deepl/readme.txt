=== Translate DeepL ===
Contributors: tporret
Tags: translation, deepl, multilingual, gutenberg, localization, automation
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Translate WordPress content with DeepL using async jobs, Gutenberg-safe processing, and language-aware routing.

== Description ==
Managing multilingual content manually is slow, inconsistent, and expensive.

Translate DeepL solves that by turning translation into a reliable workflow:

* Trigger translations per post from the editor.
* Queue work asynchronously so publishing stays fast.
* Preserve Gutenberg block structure during translation.
* Route translated content by language-aware URLs.
* Keep translated entries linked to their original source post.

This plugin is built for teams that want production-grade automation without giving up editorial control.

== Key Features ==

* DeepL integration with automatic Free/Pro endpoint detection.
* Asynchronous processing powered by Action Scheduler.
* Gutenberg-safe translation pipeline (block-aware extraction and reassembly).
* Translation memory table to reduce repeated API work.
* Language-specific relation mapping between source and translated posts.
* Admin metabox to trigger translation jobs from post/page edit screens.
* Admin filtering for translated/original content and language visibility.
* Language route handling (for example, /es/your-post).
* Daily sweeper settings to automate translation scheduling.

== Who Is It For? ==

* Content teams publishing in multiple languages.
* Agencies running multilingual WordPress sites.
* Editorial teams that need repeatable translation workflows.

== Installation ==

1. Upload the plugin folder to the /wp-content/plugins/ directory.
2. Activate the plugin through the Plugins menu in WordPress.
3. Go to Settings > Translate DeepL.
4. Add your DeepL API key.
5. Choose active target languages.
6. (Optional) Enable Daily Auto-Translation Sweep and set a run time.

== Frequently Asked Questions ==

= Does this work with Gutenberg content? =

Yes. The plugin processes block content safely so translated posts preserve their block structure.

= Will translation slow down post publishing? =

No. Jobs run asynchronously through Action Scheduler.

= Which time zone is used for the daily sweeper? =

The sweeper follows the WordPress time zone configured in Settings > General.

= Do I need DeepL Pro? =

No. DeepL Free and Pro keys are both supported.

== Screenshots ==

1. Settings screen with API key, active languages, and daily sweeper controls.
2. Post editor metabox to queue translation jobs.
3. Admin list filters for original vs translated content.

== Changelog ==

= 0.1.0 =

* Initial public release.
* DeepL async translation workflow for posts and pages.
* Gutenberg-safe content processing.
* Language relation mapping and language-aware routing.
* Daily sweeper settings and scheduling support.
