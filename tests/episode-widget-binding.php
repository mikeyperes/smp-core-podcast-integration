<?php
// Run with wp eval-file tests/episode-widget-binding.php on an activated installation.
use SMP\Podcast\Frontend\EpisodeData;
$widget=new class {
    public array $settings=['post_query_query_id'=>'smp_verified_profiles_episode_guests','post_query_post_type'=>'profile'];
    public function get_settings($name){return $this->settings[$name]??null;}
    public function set_settings($name,$value){$this->settings[$name]=$value;}
};
EpisodeData::bind_widget_query($widget);
$types=EpisodeData::guest_types();
if ($widget->settings['post_query_post_type']!==($types[0]??'__smp_disabled')) {throw new RuntimeException('Guest widget did not resolve the configured type.');}
$widget->settings['post_query_query_id']='smp_verified_profiles_profile_episodes';
add_filter('pre_option_smp_podcast_content_model',static fn()=>'episode');
EpisodeData::bind_widget_query($widget);
if ($widget->settings['post_query_post_type']!=='episode') {throw new RuntimeException('Episode widget ignored the podcast content model.');}
remove_all_filters('pre_option_smp_podcast_content_model');
echo "Guest and dedicated-episode widget sources resolve before Elementor query validation.\n";
