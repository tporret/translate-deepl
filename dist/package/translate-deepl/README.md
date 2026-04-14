# Translate DeepL

Enterprise-oriented WordPress translation orchestration powered by DeepL.

This plugin is designed for teams that need predictable, scalable multilingual publishing workflows across high-volume content operations.

## Why This Exists

Most translation plugins optimize for convenience. Enterprise teams need more:

- Controlled asynchronous processing instead of synchronous request-time translation
- Strong Gutenberg compatibility to protect content structure
- Traceable source-to-translation relationships
- Operational guardrails for retries, scheduling, and automated sweeping
- Clear extension points for future workflow and governance requirements

Translate DeepL addresses those constraints with a queue-driven architecture and cleanly separated services.

## Executive Summary

Translate DeepL provides a production-ready translation pipeline for WordPress that prioritizes operational reliability over ad-hoc convenience.

- Non-blocking translation flow using asynchronous workers
- Block-safe content handling for modern Gutenberg editorial stacks
- Persistent translation relationships for deterministic URL and content resolution
- Daily automated sweeping for continuous localization coverage
- Architecture designed for extension, governance, and future observability

## Core Capabilities

- DeepL API integration with Free/Pro endpoint auto-detection
- Asynchronous translation jobs via Action Scheduler
- Gutenberg-aware block extraction and reassembly
- Translation memory persistence to reduce duplicate API calls
- Original-to-translated post relationship mapping
- Language-aware routing for translated URLs
- Daily sweeper engine for automatic backlog translation
- Admin controls for API key, target languages, and sweeper scheduling

## Architecture Overview

High-level flow:

1. Admin configures API key, active languages, and sweeper settings.
2. Content is queued for translation from editor actions or scheduled sweeps.
3. Job workers process translations asynchronously through DeepL.
4. Translated posts are created/updated and mapped to source content.
5. Routing resolves language-specific URLs to the correct translated entity.

Data model highlights:

- `deepl_post_relations`: maps source post IDs to translated post IDs by language
- `deepl_translation_memory`: stores reusable translated fragments keyed by hash and target language

This structure enables idempotent translation behavior and efficient routing lookups.

Primary components:

- [src/Plugin.php](src/Plugin.php): bootstrap orchestration and service wiring
- [src/Core/Container.php](src/Core/Container.php): lightweight dependency injection container
- [src/Api/DeepLApiClient.php](src/Api/DeepLApiClient.php): DeepL transport and response/error handling
- [src/Service/PostTranslator.php](src/Service/PostTranslator.php): translation orchestration layer
- [src/Service/BlockProcessor.php](src/Service/BlockProcessor.php): Gutenberg-safe text extraction/reassembly
- [src/Queue/JobManager.php](src/Queue/JobManager.php): async scheduling and retry behavior
- [src/Queue/Sweeper.php](src/Queue/Sweeper.php): daily untranslated-content sweep engine
- [src/Repository](src/Repository): persistence for settings, relations, and memory
- [src/Routing/LanguageRouter.php](src/Routing/LanguageRouter.php): language-aware request and link resolution

## Architecture Decisions

### 1) Queue-first processing

Decision: Translation executes through Action Scheduler jobs, not in user-facing HTTP requests.

Why:

- Improves editor and frontend responsiveness
- Isolates third-party API latency/rate limit risk from publishing actions
- Enables retries and future throughput controls

Trade-off: Eventual consistency between source publish time and translated availability.

### 2) Gutenberg block-safe translation

Decision: Block content is extracted/reassembled from block internals instead of raw full-document transforms.

Why:

- Preserves block structure integrity
- Reduces risk of malformed post content
- Supports long-term compatibility with block-based editing

Trade-off: Additional complexity in content processing logic.

### 3) Relation and memory persistence

Decision: Use custom tables for relation mapping and translation memory.

Why:

- Predictable query patterns at scale
- Reduced repeated API usage for identical fragments
- Stronger data ownership than transient-only approaches

Trade-off: Additional schema lifecycle management.

## Non-Functional Requirements

### Reliability

- Translation jobs are asynchronous and retriable.
- Rate-limit responses are delayed and requeued.
- Sweeper schedule is auto-synchronized with admin settings.

### Performance

- Sweeper uses SQL selection of untranslated IDs only.
- Memory table avoids duplicate translation work.
- Job granularity keeps worker units bounded.

### Security

- Strict typing and explicit class boundaries
- WordPress capability checks and nonce verification in admin actions
- Prepared SQL parameters for dynamic values
- Direct file access guards (`ABSPATH`) across runtime classes

### Operability

- Settings surface current WordPress and server time for scheduling clarity
- Error logging on job failures for immediate diagnostics
- Clear service boundaries for adding tracing/metrics

## Installation

### Requirements

- WordPress 6.3+
- PHP 8.0+
- DeepL API key (Free or Pro)

### Setup

1. Place this plugin in `wp-content/plugins/translate-deepl`.
2. Install dependencies:

```bash
composer install
```

3. Activate the plugin in WordPress admin.
4. Configure settings under **Settings > Translate DeepL**:
   - DeepL API key
   - Active languages
   - Optional daily sweeper and run time

## Enterprise Configuration Guidance

### Recommended baseline

- Start with 1-2 target languages before expanding
- Enable daily sweeper after validating single-post workflows
- Set WordPress timezone to a named region (for example, `America/New_York`)
- Validate Action Scheduler health in staging before production rollout

### Rollout strategy

1. Pilot on a subset of content types or editorial teams.
2. Validate translation quality and queue behavior.
3. Expand language coverage in controlled stages.
4. Add observability hooks once traffic/content volume grows.

## Operational Notes

### Timezone and Sweeper Scheduling

The daily sweeper uses the WordPress timezone setting, not the server timezone. The settings page displays both to prevent scheduling mistakes.

### Throughput and Scale

- Translation is asynchronous to avoid blocking editorial workflows.
- Sweeper query is optimized to fetch untranslated IDs only.
- Translation memory reduces repeated payload translation costs.

### Consistency model

- Source content can publish before all target translations are complete.
- Translations converge as jobs complete.
- Daily sweeper backfills untranslated inventory continuously.

### Failure Handling

- Rate limits trigger delayed retries.
- Failed jobs are logged for operator visibility.

## Observability and Runbook

### Key signals to monitor

- Queue depth and oldest pending translation age
- Retry volume and rate-limit frequency
- Daily sweep job execution success/failure
- Ratio of untranslated published posts by language

### Incident response basics

1. Confirm Action Scheduler is executing jobs.
2. Validate DeepL key status and endpoint compatibility.
3. Verify WordPress timezone and sweeper schedule presence.
4. Check recent job failure logs for repeated patterns.

## Developer Workflow

### Linting

```bash
php -l translate-deepl.php
find src -name "*.php" -print0 | xargs -0 -n1 php -l
```

### Common Extension Points

- Replace or decorate API client behavior in DI wiring
- Add new admin settings in [src/Admin/SettingsPage.php](src/Admin/SettingsPage.php)
- Extend sweep selection criteria in [src/Queue/Sweeper.php](src/Queue/Sweeper.php)
- Integrate observability/metrics in [src/Queue/JobManager.php](src/Queue/JobManager.php) and [src/Service/PostTranslator.php](src/Service/PostTranslator.php)

### Testing strategy (recommended)

- Unit tests for content extraction/reassembly edge cases
- Integration tests for queue scheduling and relation persistence
- End-to-end staging checks for language routing and sweeper behavior

## Security and Compliance

- Strict typing across PHP classes
- Capability checks in admin workflows
- Nonce validation for translation triggers
- Prepared SQL for dynamic parameters
- ABSPATH guards to prevent direct file access

## Project Status

Current release track: `0.1.x`.

This codebase is functional for production-oriented workflows and intentionally structured for iterative hardening (bulk operations, observability, richer governance controls).

## Roadmap (Near-term)

- Bulk translation operations for editorial teams
- Improved telemetry and job dashboards
- More advanced scheduling controls and language policies
- Additional governance hooks for enterprise workflows

## License

GPL-2.0-or-later
