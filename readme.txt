=== Translate DeepL ===
Contributors: tporret
Tags: translation, deepl, multilingual, gutenberg, localization, automation
Requires at least: 6.3
Tested up to: 6.9
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

Translate WordPress posts, pages, and block-theme content with DeepL using async jobs, Gutenberg-safe processing, and language-aware routes.

== Description ==
Translate DeepL turns translation into a workflow that fits modern WordPress sites instead of forcing translation to happen during page loads or post saves.

You can use it to:

* Translate posts and pages with DeepL.
* Keep translation jobs asynchronous so publishing stays responsive.
* Preserve Gutenberg block structure during translation.
* Translate Full Site Editing assets, including templates, template parts, and patterns.
* Route translated content and archives through language-prefixed URLs.
* Keep translated entries linked to their original source content.

This plugin is aimed at site owners and editorial teams that want automation without losing control of source and translated content.

== Key Features ==

* DeepL integration with automatic Free/Pro endpoint detection.
* Asynchronous processing powered by Action Scheduler.
* Gutenberg-safe translation pipeline (block-aware extraction and reassembly).
* Translation memory table to reduce repeated API work.
* Language-specific relation mapping between source and translated posts.
* Admin metabox to trigger translation jobs from post/page edit screens.
* Admin filtering for translated/original content and language visibility.
* Language route handling for singular URLs and archives.
* FSE support for templates, template parts, synced patterns, and theme patterns.
* Daily sweeper settings to automate translation scheduling.

== Supported Content ==

Translate DeepL currently supports these main content paths:

* Posts
* Pages
* `wp_template`
* `wp_template_part`
* `wp_block` synced patterns
* Theme-registered patterns discovered from the active block theme

For block themes, the workflow is intentionally split:

* Theme templates and template parts are copy-first. Create an editable copy, then translate it.
* Theme patterns are registry-native. Translate them directly without creating editable copies.

== URLs And Archives ==

Translated content is available under language-prefixed routes such as:

* `/de/your-post/`
* `/de/category/news/`
* `/de/tag/example/`
* `/de/author/editor/`
* `/de/2026/04/13/`

Default-language archives continue to show original content, while language-prefixed archives show translated content for the active language when translations exist.

== Who Is It For? ==

* Content teams publishing in multiple languages.
* Agencies running multilingual WordPress sites.
* Editorial teams that need repeatable translation workflows.
* Block-theme sites that want translated templates, parts, and patterns.

== Installation ==

1. Upload the plugin folder to the `/wp-content/plugins/` directory, or install the release zip through WordPress admin.
2. Activate the plugin through the Plugins screen in WordPress.
3. Go to `Settings > Translate DeepL`.
4. Add your DeepL API key.
5. Choose active target languages.
6. Optionally enable the daily auto-translation sweep and set a run time.

== Basic Usage ==

1. Configure your DeepL API key and active languages.
2. Open a post or page and use the Translate DeepL metabox to queue translation.
3. For block-theme assets, open the Translate DeepL admin screen.
4. Create editable copies for templates or template parts when needed.
5. Trigger direct translation for theme patterns.
6. Visit the translated route under the language prefix, for example `/de/`.

== Frequently Asked Questions ==

= Does this work with Gutenberg content? =

Yes. The plugin processes block content safely so translated posts preserve their block structure.

= Does this work with Full Site Editing assets? =

Yes. The plugin supports block-theme templates, template parts, synced patterns, and theme patterns.

= Do translated archives work too? =

Yes. Category, tag, author, date, and paginated archives can resolve under language-prefixed routes when translated content exists.

= Will translation slow down post publishing? =

No. Jobs run asynchronously through Action Scheduler.

= Which time zone is used for the daily sweeper? =

The sweeper follows the WordPress time zone configured in Settings > General.

= Do I need DeepL Pro? =

No. DeepL Free and Pro keys are both supported.

= Does this support multisite? =

It works site-by-site in multisite. Settings and translation data are currently scoped to the individual site where the plugin is activated.

== Screenshots ==

1. Settings screen with API key, active languages, and daily sweeper controls.
2. Post editor metabox to queue translation jobs.
3. FSE translation manager for theme files, theme patterns, and database assets.
4. Admin list filters for original vs translated content.

== Changelog ==

= 0.1.0 =

* Initial public release.
* DeepL async translation workflow for posts and pages.
* Gutenberg-safe content processing.
* Language relation mapping and language-aware routing.
* Full Site Editing support for templates, template parts, and patterns.
* Language-prefixed archive routing for translated category, tag, author, date, and paginated views.
* Daily sweeper settings and scheduling support.
