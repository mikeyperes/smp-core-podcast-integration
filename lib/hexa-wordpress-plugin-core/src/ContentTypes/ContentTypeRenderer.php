<?php

namespace Hexa\PluginCore\ContentTypes;

use Hexa\PluginCore\WpAdminComponents\CoreUi;

final class ContentTypeRenderer {
    public function render( ContentTypeRegistry $registry, array $args = [] ): string {
        ob_start();
        CoreUi::render_assets();
        $assets = (string) ob_get_clean();
        $definitions = $registry->resolved_definitions();
        $title = (string) ( $args['title'] ?? 'Custom Post Types' );
        $description = (string) ( $args['description'] ?? 'Enable content types, control their public URL slugs and labels, and manage their related ACF structures.' );
        $ajax_url = function_exists( 'admin_url' ) ? admin_url( 'admin-ajax.php' ) : '';
        $nonce = function_exists( 'wp_create_nonce' ) ? wp_create_nonce( (string) $registry->config( 'nonce_action' ) ) : '';
        $persist_prefix = sanitize_key( (string) ( $args['persist_prefix'] ?? $registry->store()->option_name() ) );

        ob_start();
        ?>
        <?php echo $assets; ?>
        <?php echo $this->assets(); ?>
        <div class="hpc-ui hpc-content-types" data-hpc-content-types data-ajax-url="<?php echo esc_url( $ajax_url ); ?>" data-action="<?php echo esc_attr( (string) $registry->config( 'ajax_action' ) ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-nonce-field="<?php echo esc_attr( (string) $registry->config( 'nonce_field', 'nonce' ) ); ?>">
            <section class="hpc-card hpc-content-types-intro">
                <h3><?php echo esc_html( $title ); ?></h3>
                <p><?php echo esc_html( $description ); ?></p>
                <p class="hpc-small">The WordPress post-type key remains fixed so existing content cannot be orphaned. URL slug and labels are safely editable.</p>
            </section>
            <div class="hpc-stack">
                <?php foreach ( $definitions as $definition ) : ?>
                    <?php echo $this->content_type_card( $definition, $persist_prefix ); ?>
                <?php endforeach; ?>
            </div>
        </div>
        <?php
        return (string) ob_get_clean();
    }

    /** @param array<string,mixed> $definition */
    private function content_type_card( array $definition, string $persist_prefix ): string {
        $post = $definition['post_type'];
        $registered = function_exists( 'post_type_exists' ) && post_type_exists( $post['key'] );
        $external = 'external' === $definition['registration_mode'];
        $meta = CoreUi::pill( ! empty( $definition['enabled'] ) ? 'Enabled' : 'Disabled', ! empty( $definition['enabled'] ) ? 'success' : 'warning' )
            . CoreUi::pill( $registered ? 'Registered' : 'Not registered', $registered ? 'success' : ( ! empty( $definition['enabled'] ) ? 'danger' : 'warning' ) )
            . ( $external ? CoreUi::pill( 'Extended from another plugin', 'dark' ) : '' );

        ob_start();
        ?>
        <div class="hpc-content-type-form" data-content-type-id="<?php echo esc_attr( $definition['id'] ); ?>">
            <div class="hpc-content-type-grid">
                <div>
                    <?php echo CoreUi::toggle( 'enabled', ! empty( $definition['enabled'] ), $external ? 'Enable this plugin integration' : 'Enable ' . $post['plural'], [ 'class' => 'hpc-content-type-enabled' ] ); ?>
                    <p class="hpc-small"><?php echo esc_html( $definition['description'] ); ?></p>
                    <?php if ( $external ) : ?><p class="hpc-small">This plugin extends the post type but does not own its registration, URL slug, or labels.</p><?php endif; ?>
                </div>
                <dl class="hpc-content-type-facts">
                    <div><dt>Owner</dt><dd><?php echo esc_html( $definition['owner'] ?: 'Host plugin' ); ?></dd></div>
                    <div><dt>Post type key</dt><dd><span class="hpc-code"><?php echo esc_html( $post['key'] ); ?></span></dd></div>
                    <div><dt>Archive</dt><dd><?php echo ! empty( $post['args']['has_archive'] ) ? 'Enabled' : 'Disabled'; ?></dd></div>
                    <div><dt>Supports</dt><dd><?php echo esc_html( implode( ', ', (array) ( $post['args']['supports'] ?? [] ) ) ?: 'Default' ); ?></dd></div>
                </dl>
            </div>
            <?php if ( $external ) : ?>
                <input class="hpc-content-type-slug" type="hidden" value="<?php echo esc_attr( $post['rewrite_slug'] ); ?>">
                <input class="hpc-content-type-singular" type="hidden" value="<?php echo esc_attr( $post['singular'] ); ?>">
                <input class="hpc-content-type-plural" type="hidden" value="<?php echo esc_attr( $post['plural'] ); ?>">
            <?php else : ?>
                <div class="hpc-content-type-fields">
                    <label class="hpc-field"><span>URL slug</span><input class="hpc-content-type-slug" type="text" value="<?php echo esc_attr( $post['rewrite_slug'] ); ?>"></label>
                    <label class="hpc-field"><span>Singular label</span><input class="hpc-content-type-singular" type="text" value="<?php echo esc_attr( $post['singular'] ); ?>"></label>
                    <label class="hpc-field"><span>Plural label</span><input class="hpc-content-type-plural" type="text" value="<?php echo esc_attr( $post['plural'] ); ?>"></label>
                </div>
            <?php endif; ?>
            <section class="hpc-content-type-acf">
                <h4>Custom Fields</h4>
                <p>ACF structures are registered through Hexa WP Core and remain directly associated with this content type.</p>
                <?php if ( empty( $definition['field_groups'] ) ) : ?>
                    <p class="hpc-small">No ACF structures are defined for this content type.</p>
                <?php else : ?>
                    <div class="hpc-toggle-list">
                        <?php foreach ( $definition['field_groups'] as $group ) : ?>
                            <div class="hpc-toggle-row hpc-content-type-acf-row">
                                <div>
                                    <?php echo CoreUi::toggle( 'field_group_' . $group['id'], ! empty( $group['enabled'] ), $group['label'], [ 'class' => 'hpc-content-type-field-toggle', 'data' => [ 'field-group-id' => $group['id'] ] ] ); ?>
                                    <?php echo CoreUi::inline_details( 'Detailed field breakdown', $this->field_group_details( $group ) ); ?>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
            <?php if ( $definition['taxonomies'] ) : ?>
                <?php echo CoreUi::detail_card( [ 'title' => 'Taxonomies', 'body_html' => '<ul class="hpc-list"><li>' . implode( '</li><li>', array_map( 'esc_html', array_column( $definition['taxonomies'], 'key' ) ) ) . '</li></ul>', 'persist_key' => $persist_prefix . '-' . $definition['id'] . '-taxonomies' ] ); ?>
            <?php endif; ?>
            <div class="hpc-actions hpc-actions-bottom">
                <button type="button" class="hpc-button hpc-content-type-save">Save Content Type</button>
                <span class="hpc-content-type-status" aria-live="polite"></span>
            </div>
        </div>
        <?php
        $body = (string) ob_get_clean();
        return CoreUi::collapsible(
            [
                'title'       => $post['plural'],
                'body_html'   => $body,
                'meta_html'   => $meta,
                'open'        => false,
                'persist_key' => $persist_prefix . '-cpt-' . $definition['id'],
                'query_state' => false,
            ]
        );
    }

    /** @param array<string,mixed> $group */
    private function field_group_details( array $group ): string {
        $html = '' !== $group['description'] ? '<p>' . esc_html( $group['description'] ) . '</p>' : '';
        if ( '' !== $group['group_key'] ) {
            $html .= '<p><strong>ACF group:</strong> <span class="hpc-code">' . esc_html( $group['group_key'] ) . '</span></p>';
        }
        if ( $group['fields'] ) {
            $html .= '<p><strong>Fields:</strong></p><ul class="hpc-list"><li>' . implode( '</li><li>', array_map( 'esc_html', $group['fields'] ) ) . '</li></ul>';
        }
        if ( $group['dependencies'] ) {
            $html .= '<p><strong>Dependencies:</strong> ' . esc_html( implode( ', ', $group['dependencies'] ) ) . '</p>';
        }
        return '' !== $html ? $html : '<p>No additional field metadata supplied.</p>';
    }

    private function assets(): string {
        static $done = false;
        if ( $done ) {
            return '';
        }
        $done = true;
        return <<<'HTML'
<style>.hpc-content-types-intro{margin-bottom:14px}.hpc-content-types-intro h3{font-size:20px}.hpc-content-type-grid{display:grid;gap:18px;grid-template-columns:minmax(0,1fr) minmax(320px,.8fr)}.hpc-content-type-facts{display:grid;gap:8px;grid-template-columns:repeat(2,minmax(0,1fr));margin:0}.hpc-content-type-facts div{background:#f8fafc;border:1px solid #e3e8f0;border-radius:8px;padding:9px 10px}.hpc-content-type-facts dt{color:#65758b;font-size:11px;font-weight:800;text-transform:uppercase}.hpc-content-type-facts dd{margin:4px 0 0;overflow-wrap:anywhere}.hpc-content-type-fields{display:grid;gap:12px;grid-template-columns:repeat(3,minmax(0,1fr));margin-top:16px}.hpc-content-type-acf{border-top:1px solid var(--hpc-line);margin-top:4px;padding-top:14px}.hpc-content-type-acf h4{font-size:15px;margin:0 0 5px}.hpc-content-type-acf-row>div{min-width:0;width:100%}.hpc-content-type-status{color:var(--hpc-muted);font-size:13px}.hpc-content-type-form.is-saving{opacity:.7;pointer-events:none}.hpc-content-type-form.is-error .hpc-content-type-status{color:var(--hpc-red)}@media(max-width:900px){.hpc-content-type-grid,.hpc-content-type-fields,.hpc-content-type-facts{grid-template-columns:1fr}}</style>
<script>(function(){if(window.hexaContentTypesReady)return;window.hexaContentTypesReady=true;document.addEventListener('click',function(event){var button=event.target.closest('.hpc-content-type-save');if(!button)return;var form=button.closest('.hpc-content-type-form');var root=button.closest('[data-hpc-content-types]');if(!form||!root)return;var status=form.querySelector('.hpc-content-type-status');var body=new URLSearchParams();body.set('action',root.dataset.action||'');body.set(root.dataset.nonceField||'nonce',root.dataset.nonce||'');body.set('content_type_id',form.dataset.contentTypeId||'');body.set('enabled',form.querySelector('.hpc-content-type-enabled input').checked?'1':'0');body.set('rewrite_slug',form.querySelector('.hpc-content-type-slug').value||'');body.set('singular',form.querySelector('.hpc-content-type-singular').value||'');body.set('plural',form.querySelector('.hpc-content-type-plural').value||'');form.querySelectorAll('.hpc-content-type-field-toggle input:checked').forEach(function(input){body.append('enabled_field_groups[]',input.dataset.fieldGroupId||'')});form.classList.add('is-saving');form.classList.remove('is-error');if(status)status.textContent='Saving...';fetch(root.dataset.ajaxUrl||window.ajaxurl,{method:'POST',credentials:'same-origin',headers:{'Content-Type':'application/x-www-form-urlencoded; charset=UTF-8'},body:body.toString()}).then(function(response){return response.json()}).then(function(payload){if(!payload||!payload.success)throw new Error(payload&&payload.data&&payload.data.message?payload.data.message:'Save failed');if(status)status.textContent=payload.data.message||'Saved.';}).catch(function(error){form.classList.add('is-error');if(status)status.textContent=error.message||'Unable to save.';}).finally(function(){form.classList.remove('is-saving')});});})();</script>
HTML;
    }
}
