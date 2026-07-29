<?php

namespace SMP\Podcast\Content;

use Hexa\PluginCore\CoreContracts\ModuleInterface;

final class DefaultHostFieldPreview implements ModuleInterface {
    private const FIELD_KEY = 'field_6848b7b0373d3';

    public function register(): void {
        add_action( 'acf/render_field/key=' . self::FIELD_KEY, [ $this, 'render' ], 20 );
    }

    /** @param array<string,mixed> $field */
    public function render( array $field ): void {
        $profile_id = $this->object_id( $field['value'] ?? 0 );
        $profile = $profile_id > 0 ? get_post( $profile_id ) : null;
        if ( ! $profile ) {
            return;
        }

        $image = get_the_post_thumbnail( $profile_id, 'medium' );
        $links = function_exists( 'get_field' ) ? get_field( 'url', $profile_id ) : [];
        ?>
        <div class="smp-podcast-default-host-preview">
            <?php echo $image; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
            <div>
                <strong><?php echo esc_html( get_the_title( $profile_id ) ); ?></strong>
                <span>Profile #<?php echo (int) $profile_id; ?></span>
                <?php if ( is_array( $links ) ) : ?>
                    <ul>
                        <?php foreach ( $links as $label => $url ) : ?>
                            <?php if ( is_scalar( $url ) && '' !== trim( (string) $url ) ) : ?>
                                <li><strong><?php echo esc_html( ucwords( str_replace( '_', ' ', (string) $label ) ) ); ?>:</strong> <a href="<?php echo esc_url( (string) $url ); ?>" target="_blank" rel="noopener noreferrer"><?php echo esc_html( (string) $url ); ?></a></li>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
                <div class="hpc-actions">
                    <a class="hpc-button secondary hpc-external" href="<?php echo esc_url( (string) get_edit_post_link( $profile_id, 'raw' ) ); ?>" target="_blank" rel="noopener noreferrer">Edit Profile</a>
                    <a class="hpc-button secondary hpc-external" href="<?php echo esc_url( get_permalink( $profile_id ) ); ?>" target="_blank" rel="noopener noreferrer">View Profile</a>
                </div>
                <p><code>[podcast_host id="title"]</code> <code>[podcast_host id="biography"]</code></p>
            </div>
        </div>
        <?php
    }

    private function object_id( mixed $value ): int {
        if ( is_object( $value ) ) {
            return absint( $value->ID ?? 0 );
        }
        if ( is_array( $value ) ) {
            return absint( $value['ID'] ?? $value['id'] ?? $value['value'] ?? reset( $value ) );
        }
        return is_numeric( $value ) ? absint( $value ) : 0;
    }
}
