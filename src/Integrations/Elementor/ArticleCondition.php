<?php

namespace SMP\Podcast\Integrations\Elementor;

final class ArticleCondition extends ContentKindCondition {
    public function get_name(): string { return 'mpp_article'; }
    public function get_label(): string { return 'Articles'; }
    public function get_all_label(): string { return 'All Articles'; }
    protected function kind(): string { return 'article'; }
}
