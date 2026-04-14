<?php
declare(strict_types=1);

namespace TranslateDeepL\Service;

if (! defined('ABSPATH')) {
    exit;
}

use TranslateDeepL\Repository\AssetRegistryRepository;
use TranslateDeepL\Support\FseAssetCatalog;

final class AssetRegistrySyncService
{
    private const STATE_OPTION = 'deepl_registry_asset_sync_state_v1';
    private const SYNC_INTERVAL = HOUR_IN_SECONDS;

    public function __construct(
        private readonly AssetRegistryRepository $assetRegistryRepository,
        private readonly FseAssetCatalog $fseAssetCatalog
    ) {
    }

    public function registerHooks(): void
    {
        add_action('init', [$this, 'syncIfNeeded'], 20);
        add_action('switch_theme', [$this, 'resetSyncState']);
    }

    public function syncIfNeeded(): void
    {
        if (wp_installing()) {
            return;
        }

        $currentTheme = sanitize_key(get_stylesheet());
        $state = get_option(self::STATE_OPTION, []);

        if (is_array($state)) {
            $lastTheme = isset($state['theme']) ? (string) $state['theme'] : '';
            $lastSyncedAt = isset($state['synced_at']) ? (int) $state['synced_at'] : 0;

            if ($lastTheme === $currentTheme && ($lastSyncedAt + self::SYNC_INTERVAL) > time()) {
                return;
            }
        }

        $this->syncThemeAssets();
        update_option(
            self::STATE_OPTION,
            [
                'theme' => $currentTheme,
                'synced_at' => time(),
            ],
            false
        );
    }

    public function resetSyncState(): void
    {
        delete_option(self::STATE_OPTION);
    }

    private function syncThemeAssets(): void
    {
        $assets = array_merge(
            $this->fseAssetCatalog->getThemeTemplatesForRegistry(),
            $this->fseAssetCatalog->getThemePatternsForRegistry()
        );
        $assetKeys = [];
        $themeSlug = sanitize_key(get_stylesheet());

        foreach ($assets as $asset) {
            $assetKey = isset($asset['asset_key']) ? (string) $asset['asset_key'] : '';
            $content = isset($asset['content']) ? (string) $asset['content'] : '';

            if ($assetKey === '' || $content === '') {
                continue;
            }

            $assetKeys[] = $assetKey;

            $this->assetRegistryRepository->saveSourceAsset(
                $assetKey,
                isset($asset['asset_type']) ? (string) $asset['asset_type'] : '',
                isset($asset['source_kind']) ? (string) $asset['source_kind'] : '',
                $themeSlug,
                isset($asset['title']) ? (string) $asset['title'] : '',
                $content,
                hash('sha256', $content),
                isset($asset['metadata']) && is_array($asset['metadata']) ? $asset['metadata'] : []
            );
        }

        $this->assetRegistryRepository->deleteMissingSourceAssets($themeSlug, array_values(array_unique($assetKeys)));
    }
}