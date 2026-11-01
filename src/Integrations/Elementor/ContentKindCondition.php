<?php

namespace SMP\Podcast\Integrations\Elementor;

abstract class ContentKindCondition extends \ElementorPro\Modules\ThemeBuilder\Conditions\Condition_Base {
    abstract protected function kind(): string;
    public static function get_type(): string { return 'singular'; }
    public static function get_priority() { return 36; }
    public function check( $args ): bool { return ContentKindRules::matches( $this->kind() ); }
}
