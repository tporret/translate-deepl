<?php
declare(strict_types=1);

namespace TranslateDeepL;

if (! defined('ABSPATH')) {
    exit;
}

use TranslateDeepL\Admin\SettingsPage;
use TranslateDeepL\Admin\PostMetaBox;
use TranslateDeepL\Admin\TranslatedContentAdmin;
use TranslateDeepL\Api\ApiClientInterface;
use TranslateDeepL\Api\DeepLApiClient;
use TranslateDeepL\Core\Container;
use TranslateDeepL\Database\Installer;
use TranslateDeepL\Queue\JobManager;
use TranslateDeepL\Queue\Sweeper;
use TranslateDeepL\Repository\PostRelationRepository;
use TranslateDeepL\Repository\TranslationMemoryRepository;
use TranslateDeepL\Repository\SettingsRepository;
use TranslateDeepL\Routing\LanguageRouter;
use TranslateDeepL\Service\BlockProcessor;
use TranslateDeepL\Service\PostTranslator;

final class Plugin
{
    private bool $booted = false;
    private bool $servicesRegistered = false;

    public function __construct(
        private readonly Container $container,
        private readonly Installer $installer
    ) {
    }

    public function activate(): void
    {
        $this->ensureServicesRegistered();
        $this->installer->install();
        $this->container->get(LanguageRouter::class)->registerRewriteRules();
        flush_rewrite_rules(false);
    }

    public function boot(): void
    {
        if ($this->booted) {
            return;
        }

        $this->ensureServicesRegistered();
        $this->registerHooks();
        $this->booted = true;
    }

    private function ensureServicesRegistered(): void
    {
        if ($this->servicesRegistered) {
            return;
        }

        $this->registerServices();
        $this->servicesRegistered = true;
    }

    private function registerServices(): void
    {
        $this->container->singleton(
            SettingsRepository::class,
            static fn (): SettingsRepository => new SettingsRepository()
        );

        $this->container->singleton(
            \wpdb::class,
            fn (): \wpdb => $this->resolveWpdb()
        );

        $this->container->singleton(
            DeepLApiClient::class,
            static fn (Container $container): DeepLApiClient => new DeepLApiClient(
                $container->get(SettingsRepository::class)->getApiKey()
            )
        );

        $this->container->singleton(
            ApiClientInterface::class,
            static fn (Container $container): ApiClientInterface => $container->get(DeepLApiClient::class)
        );

        $this->container->singleton(
            PostRelationRepository::class,
            static fn (Container $container): PostRelationRepository => new PostRelationRepository(
                $container->get(\wpdb::class)
            )
        );

        $this->container->singleton(
            TranslationMemoryRepository::class,
            static fn (Container $container): TranslationMemoryRepository => new TranslationMemoryRepository(
                $container->get(\wpdb::class)
            )
        );

        $this->container->singleton(
            BlockProcessor::class,
            static fn (): BlockProcessor => new BlockProcessor()
        );

        $this->container->singleton(
            PostTranslator::class,
            static fn (Container $container): PostTranslator => new PostTranslator(
                $container->get(BlockProcessor::class),
                $container->get(DeepLApiClient::class),
                $container->get(TranslationMemoryRepository::class),
                $container->get(PostRelationRepository::class)
            )
        );

        $this->container->singleton(
            JobManager::class,
            static fn (Container $container): JobManager => new JobManager(
                $container->get(PostTranslator::class)
            )
        );

        $this->container->singleton(
            Sweeper::class,
            static fn (Container $container): Sweeper => new Sweeper(
                $container->get(\wpdb::class),
                $container->get(SettingsRepository::class),
                $container->get(JobManager::class)
            )
        );

        $this->container->singleton(
            SettingsPage::class,
            static fn (): SettingsPage => new SettingsPage()
        );

        $this->container->singleton(
            PostMetaBox::class,
            static fn (Container $container): PostMetaBox => new PostMetaBox(
                $container->get(SettingsRepository::class),
                $container->get(PostRelationRepository::class),
                $container->get(JobManager::class)
            )
        );

        $this->container->singleton(
            TranslatedContentAdmin::class,
            static fn (): TranslatedContentAdmin => new TranslatedContentAdmin()
        );

        $this->container->singleton(
            LanguageRouter::class,
            static fn (Container $container): LanguageRouter => new LanguageRouter(
                $container->get(PostRelationRepository::class)
            )
        );
    }

    private function resolveWpdb(): \wpdb
    {
        $wpdb = $GLOBALS['wpdb'] ?? null;

        if (! $wpdb instanceof \wpdb) {
            throw new \RuntimeException('WordPress database object is not available.');
        }

        return $wpdb;
    }

    private function registerHooks(): void
    {
        add_action('plugins_loaded', [$this, 'onPluginsLoaded']);
    }

    public function onPluginsLoaded(): void
    {
        $this->container->get(JobManager::class)->registerHooks();
        $this->container->get(Sweeper::class)->registerHooks();
        $this->container->get(SettingsPage::class)->registerHooks();
        $this->container->get(PostMetaBox::class)->registerHooks();
        $this->container->get(TranslatedContentAdmin::class)->registerHooks();
        $this->container->get(LanguageRouter::class)->registerHooks();
    }
}
