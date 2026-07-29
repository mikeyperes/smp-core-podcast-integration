<?php

namespace SMP\Podcast\Support;

use Hexa\PluginCore\CoreContracts\ModuleInterface;
use SMP\Podcast\Config;

final class RewriteLifecycle implements ModuleInterface {
    private const OPTION = 'smp_podcast_flush_rewrite_rules';
    private const VERSION_OPTION = 'smp_podcast_rewrite_version';

    public function register(): void {
        if ( Config::VERSION !== (string) get_option( self::VERSION_OPTION, '' ) ) {
            self::schedule();
        }
        add_action( 'init', [ $this, 'maybe_flush' ], 99 );
    }

    public static function schedule(): void {
        update_option( self::OPTION, 1, false );
    }

    public function maybe_flush(): void {
        if ( ! (bool) get_option( self::OPTION, false ) ) {
            return;
        }

        flush_rewrite_rules( false );
        update_option( self::VERSION_OPTION, Config::VERSION, false );
        delete_option( self::OPTION );
    }
}
