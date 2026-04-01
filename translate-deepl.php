<?php
declare(strict_types=1);

/**
 * Plugin Name: Translate DeepL
 * Plugin URI: https://wordpress.org/plugins/translate-deepl/
 * Description: Enterprise-grade DeepL translation integration for WordPress.
 * Version: 0.1.0
 * Requires at least: 6.3
 * Tests up to: 6.9
 * Requires PHP: 8.0
 * Author: Translate DeepL Team
 * License: GPL-2.0-or-later
 * License URI: https://www.gnu.org/licenses/gpl-2.0.html
 * Text Domain: translate-deepl
 */

use TranslateDeepL\Core\Container;
use TranslateDeepL\Database\Installer;
use TranslateDeepL\Plugin;

if (! defined('ABSPATH')) {
    exit;
}

$autoloadFile = __DIR__ . '/vendor/autoload.php';
$actionSchedulerBootstrap = __DIR__ . '/vendor/woocommerce/action-scheduler/action-scheduler.php';

if (! file_exists($autoloadFile)) {
    return;
}

require_once $autoloadFile;

if (file_exists($actionSchedulerBootstrap)) {
    require_once $actionSchedulerBootstrap;
}

$container = new Container();
$container->singleton(
    Installer::class,
    static fn (): Installer => new Installer()
);
$container->singleton(
    Plugin::class,
    static fn (Container $container): Plugin => new Plugin(
        $container,
        $container->get(Installer::class)
    )
);

$plugin = $container->get(Plugin::class);
register_activation_hook(__FILE__, [$plugin, 'activate']);
$plugin->boot();
