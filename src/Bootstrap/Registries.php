<?php

namespace SMP\Podcast\Bootstrap;

use Hexa\PluginCore\ContentTypes\ContentTypeRegistry;
use Hexa\PluginCore\FieldStructures\AcfFieldGroupRegistry;
use Hexa\PluginCore\SnippetRegistry\SnippetRegistry;
use SMP\Podcast\Acf\EpisodeFieldGroup;
use SMP\Podcast\Acf\PodcastOptionsFieldGroup;
use SMP\Podcast\Settings\PodcastSettings;
use SMP\Podcast\Support\SnippetDefinitions;

final class Registries {
    public static function content_types(): ContentTypeRegistry {
        static $registry = null;
        if ( $registry instanceof ContentTypeRegistry ) {
            return $registry;
        }

        $content = PodcastSettings::content();
        $definition_id = 'post' === $content['post_type'] ? 'podcast-post-integration' : 'podcast-episode';
        $registry = new ContentTypeRegistry(
            [
                'option_name' => 'smp_podcast_content_types',
                'ajax_action' => 'smp_podcast_save_content_type',
                'nonce_action' => \SMP\Podcast\Config::NONCE_ACTION,
                'nonce_field' => 'nonce',
            ]
        );
        $registry->add(
            [
                'id' => $definition_id,
                'owner' => \SMP\Podcast\Config::NAME,
                'description' => 'Podcast episode metadata on the selected WordPress content type.',
                'registration_mode' => 'post' === $content['post_type'] ? 'external' : 'owned',
                'enabled_default' => true,
                'legacy_enabled_option' => 'regsiter_acf_post_podcast',
                'post_type' => [
                    'key' => $content['post_type'],
                    'singular' => $content['singular'],
                    'plural' => $content['plural'],
                    'rewrite_slug' => $content['rewrite_slug'],
                    'args' => [
                        'public' => true,
                        'show_ui' => true,
                        'show_in_rest' => true,
                        'has_archive' => true,
                        'menu_icon' => 'dashicons-microphone',
                        'supports' => [ 'title', 'author', 'editor', 'excerpt', 'revisions', 'thumbnail', 'custom-fields' ],
                        'taxonomies' => [ 'category', 'post_tag' ],
                    ],
                ],
                'field_groups' => [
                    [
                        'id' => 'episode-fields',
                        'label' => 'Podcast Episode Fields',
                        'description' => 'Platform URLs, hosts, summary, audio, numbering, duration, schema references, and citations.',
                        'group_key' => EpisodeFieldGroup::GROUP_KEY,
                        'enabled_default' => true,
                        'legacy_option' => 'regsiter_acf_post_podcast',
                        'definition' => [ EpisodeFieldGroup::class, 'definition' ],
                        'fields' => EpisodeFieldGroup::field_labels(),
                        'dependencies' => [ 'Advanced Custom Fields Pro' ],
                    ],
                ],
            ]
        );

        return $registry;
    }

    public static function option_fields(): AcfFieldGroupRegistry {
        static $registry = null;
        if ( $registry instanceof AcfFieldGroupRegistry ) {
            return $registry;
        }

        $registry = new AcfFieldGroupRegistry(
            [
                'option_name' => 'smp_podcast_acf_groups',
                'ajax_action' => 'smp_podcast_save_acf_group',
                'nonce_action' => \SMP\Podcast\Config::NONCE_ACTION,
                'nonce_field' => 'nonce',
            ]
        );
        $registry->add(
            [
                'id' => 'podcast-settings',
                'label' => 'Podcast Settings Fields',
                'description' => 'Content labels, importer references, default host, and platform URLs.',
                'group_key' => PodcastOptionsFieldGroup::GROUP_KEY,
                'enabled_default' => true,
                'legacy_option' => 'register_theme_options',
                'definition' => [ PodcastOptionsFieldGroup::class, 'definition' ],
                'fields' => PodcastOptionsFieldGroup::field_labels(),
                'location' => 'Podcast settings option object',
                'dependencies' => [ 'Advanced Custom Fields Pro' ],
            ]
        );

        return $registry;
    }

    public static function snippets(): SnippetRegistry {
        static $registry = null;
        if ( $registry instanceof SnippetRegistry ) {
            return $registry;
        }

        $registry = new SnippetRegistry();
        $registry->add_many( SnippetDefinitions::all() );
        return $registry;
    }
}
