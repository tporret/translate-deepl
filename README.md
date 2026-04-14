# Translate DeepL

Async DeepL translation for modern WordPress sites, with Gutenberg-safe content handling, FSE asset support, and language-aware routing.

This repository readme is for contributors, reviewers, and teams deploying from source. For the WordPress-facing plugin readme, see [readme.txt](readme.txt).

## What It Does

Translate DeepL turns translation into a queue-backed publishing workflow instead of a request-time convenience feature.

Current capabilities in this repository:

- Translate posts and pages through DeepL using Action Scheduler jobs.
- Translate editable FSE assets: `wp_template`, `wp_template_part`, and synced `wp_block` patterns.
- Translate theme-registered patterns through a registry-backed store.
- Preserve Gutenberg block structure during translation.
- Reuse translated fragments through translation memory.
- Route translated singular content under language-prefixed URLs such as `/de/your-slug/`.
- Route translated archives under language-prefixed category, tag, author, date, and paginated archive URLs.

## Current Frontend Behavior

The current codebase supports language-aware behavior for both singular content and archives.

- Translated posts get language-prefixed permalinks.
- Default-language archives exclude translated copies.
- Language-prefixed archives include translated copies for the active language only.
- Site-home, category, tag, author, and date links on translated routes stay inside the active language namespace.
- FSE translation is split by asset type:
   - Theme templates and template parts are copy-first and become editable database assets before translation.
   - Theme patterns are registry-native and translate without creating editable copies.

## FSE Coverage

The plugin now covers the main WordPress block-theme asset types that matter on real front ends.

- `wp_template`
- `wp_template_part`
- `wp_block`
- Theme-registered patterns discovered through `WP_Block_Patterns_Registry`

The runtime path is intentionally split:

- Template and template-part resolution is post-backed.
- Theme pattern resolution is registry-backed.

This avoids flattening theme patterns into posts just to render translated output.

## Architecture Snapshot

High-level flow:

1. Configure API key and active target languages.
2. Queue translation from editor/admin actions or scheduled sweeping.
3. Translate through DeepL asynchronously.
4. Persist relations, memory entries, and non-post asset translations.
5. Resolve translated output at runtime through routing and FSE resolvers.

Primary tables:

- `deepl_post_relations`
- `deepl_translation_memory`
- `deepl_registry_assets`
- `deepl_registry_asset_translations`

Primary code paths:

- [src/Plugin.php](src/Plugin.php): service wiring and bootstrap
- [src/Service/PostTranslator.php](src/Service/PostTranslator.php): post and editable FSE translation
- [src/Service/AssetRegistryTranslator.php](src/Service/AssetRegistryTranslator.php): registry-native non-post translation
- [src/Service/BlockProcessor.php](src/Service/BlockProcessor.php): block-safe string extraction and reassembly
- [src/Routing/LanguageRouter.php](src/Routing/LanguageRouter.php): singular and archive routing
- [src/Frontend/TemplateResolver.php](src/Frontend/TemplateResolver.php): translated template and template-part runtime resolution
- [src/Frontend/PatternResolver.php](src/Frontend/PatternResolver.php): translated pattern runtime resolution
- [src/Support/FseAssetCatalog.php](src/Support/FseAssetCatalog.php): shared FSE discovery/catalog layer

## Local Setup

Requirements:

- WordPress 6.3+
- PHP 8.0+
- Composer
- DeepL API key for real translation testing

Install from source:

```bash
composer install
```

Then activate the plugin and configure it in WordPress under `Settings > Translate DeepL`.

## Development Notes

Useful checks:

```bash
php -l translate-deepl.php
find src -name "*.php" -print0 | xargs -0 -n1 php -l
```

Lab and validation notes:

- [tests/Unit/testsite.md](tests/Unit/testsite.md): Docker multisite lab notes
- [docs/25-lab-smoke-flows.md](docs/25-lab-smoke-flows.md): queue, routing, and FSE smoke-flow commands used in the lab

Recent live-lab validation covered:

- Real post translation through the DeepL async pipeline
- German singular routing for translated posts
- Language-prefixed archive routing for category, tag, author, date, and pagination
- FSE runtime resolution for translated templates, template parts, and patterns on `/de/` routes

## Packaging

The production package is built as a runtime-only zip under `dist/translate-deepl.zip`.

When packaging for deployment, exclude repository-only content such as:

- `.git`
- `.github`
- `docs`
- `tests`
- temporary build artifacts

## Multisite Note

The plugin currently operates site-by-site in multisite.

- Settings are site-scoped.
- Custom tables are created per activated site prefix.
- The code is not yet network-admin-aware.

That is acceptable for current lab validation, but a true multisite rollout should be treated as a separate hardening round.

## Roadmap Focus

Near-term technical priorities are still practical rather than broad:

- package the current routing/FSE fixes into the release zip
- add more regression coverage around archive and FSE route behavior
- harden multisite behavior intentionally rather than implicitly

## License

GPL-2.0-or-later
