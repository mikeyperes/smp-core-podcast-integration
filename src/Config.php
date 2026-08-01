<?php

namespace SMP\Podcast;

final class Config {
    public const VERSION = '3.1.1';
    public const SLUG = 'smp-core-podcast-integration';
    public const MAIN_FILE = 'smp-core-podcast-integration.php';
    public const LEGACY_FILE = 'initialization.php';
    public const NAME = 'Scale My Podcast - Core Functionality';
    public const SETTINGS_PAGE = 'smp-core-podcast-integration';
    public const LEGACY_SETTINGS_PAGE = 'smp_core_podcast_functionality';
    public const CAPABILITY = 'manage_options';
    public const GITHUB_REPO = 'mikeyperes/smp-core-podcast-integration';
    public const GITHUB_BRANCH = 'main';
    public const NONCE_ACTION = 'smp_podcast_admin';

    public static function plugin_basename(): string {
        return plugin_basename( SMP_PODCAST_PLUGIN_FILE );
    }
}
