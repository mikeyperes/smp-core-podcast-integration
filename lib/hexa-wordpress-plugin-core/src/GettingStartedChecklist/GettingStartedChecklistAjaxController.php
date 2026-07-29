<?php

namespace Hexa\PluginCore\GettingStartedChecklist;

use Hexa\PluginCore\CoreContracts\ModuleInterface;
use Hexa\PluginCore\WpAdminAjax\AjaxActionRegistry;
use Hexa\PluginCore\WpAdminAjax\AjaxRequest;

final class GettingStartedChecklistAjaxController implements ModuleInterface {
    private GettingStartedChecklistConfig $config;

    private GettingStartedChecklistRunner $runner;

    public function __construct( GettingStartedChecklistConfig|array $config ) {
        $this->config = is_array( $config ) ? new GettingStartedChecklistConfig( $config ) : $config;
        $this->runner = new GettingStartedChecklistRunner( $this->config );
    }

    public function register(): void {
        AjaxActionRegistry::create(
            [
                'capability'   => $this->config->capability(),
                'nonce_action' => $this->config->nonce_action(),
                'nonce_field'  => $this->config->nonce_field(),
            ]
        )->register(
            [
                $this->config->run_action() => [
                    'callback' => [ $this, 'run_item' ],
                ],
            ]
        );
    }

    /**
     * @return array<string,mixed>
     */
    public function run_item( AjaxRequest $request ): array {
        $inputs = $request->raw( 'inputs', [], 'post' );

        return $this->runner->run_item(
            $request->key( 'step_id', '', 'post' ),
            $request->key( 'subtask_id', '', 'post' ),
            is_array( $inputs ) ? $inputs : [],
            $request->key( 'template_id', '', 'post' )
        );
    }
}
