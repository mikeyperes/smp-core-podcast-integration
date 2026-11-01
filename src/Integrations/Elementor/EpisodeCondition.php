<?php

namespace SMP\Podcast\Integrations\Elementor;

final class EpisodeCondition extends ContentKindCondition {
    public function get_name(): string { return 'mpp_episode'; }
    public function get_label(): string { return 'Episodes'; }
    public function get_all_label(): string { return 'All Episodes'; }
    protected function kind(): string { return 'episode'; }
}
