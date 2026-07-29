<?php

namespace SMP\Podcast\Compatibility;

use Hexa\PluginCore\CoreContracts\ModuleInterface;

final class LegacyCompatibility implements ModuleInterface {
    public function register(): void {
        require_once __DIR__ . '/legacy-functions.php';
    }
}
