<?php
/**
 * Admin settings page for MCP Tools for Elementor.
 *
 * Provides a UI to toggle individual MCP tools on/off and view
 * connection information for various MCP clients.
 *
 * @package EMCP_Tools
 * @since   1.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Admin page orchestrator.
 *
 * @since 1.0.0
 */
class EMCP_Tools_Admin {

	/**
	 * Hook suffixes returned by add_menu_page() / add_submenu_page(),
	 * used to scope asset enqueues to our screens only.
	 *
	 * @var string[]
	 */
	private $hook_suffixes = array();

	/**
	 * Option name for storing disabled tools.
	 *
	 * @var string
	 */
	const OPTION_DISABLED_TOOLS = 'emcp_tools_disabled_tools';

	/**
	 * Settings group name.
	 *
	 * @var string
	 */
	const SETTINGS_GROUP = 'emcp_tools_settings';

	/**
	 * Dedicated settings group for the "Activate Abilities API for EMCP" server
	 * gate. Kept separate from SETTINGS_GROUP so the Connection-tab toggle form
	 * submits only that option and can't wipe the Tools-page options on save.
	 *
	 * @since 1.7.4
	 * @var string
	 */
	const SETTINGS_GROUP_SERVER = 'emcp_tools_server_settings';

	/** Settings group for the Context page. */
	const SETTINGS_GROUP_CONTEXT = 'emcp_tools_context_settings';

	/** Settings group for the Modules tab (active-modules list + each module's knobs). */
	const SETTINGS_GROUP_MODULES = 'emcp_tools_modules_settings';

	/**
	 * Settings group for third-party service credentials (stock-image provider
	 * keys). Separate from SETTINGS_GROUP_SERVER so the "3rd Party Services"
	 * sub-tab form saves independently of the server-gate toggles.
	 *
	 * @var string
	 */
	const SETTINGS_GROUP_SERVICES = 'emcp_tools_services_settings';

	/**
	 * Page slug.
	 *
	 * @var string
	 */
	const PAGE_SLUG = 'emcp-tools';

	/**
	 * Map of sub-screen slug => label. The first entry is the dashboard
	 * (rendered when the parent menu item is clicked).
	 *
	 * @var array<string, string>|null
	 */
	private $submenus = null;

	/**
	 * Returns the map of submenu slugs to translated labels.
	 *
	 * Initialised lazily so the strings are localised at call time.
	 *
	 * @return array<string, string>
	 */
	/**
	 * Whether a module-backed admin tab should show. Visible when the module is
	 * not registered (free build / no overlay → keep the tab, e.g. an upsell) or
	 * it is active and available; hidden when registered but off or unavailable.
	 *
	 * @param string $module_id Module id.
	 * @return bool
	 */
	private function module_tab_visible( string $module_id ): bool {
		if ( ! class_exists( 'EMCP_Tools_Modules_Registry' ) ) {
			return true;
		}
		$module = EMCP_Tools_Modules_Registry::instance()->get( $module_id );
		if ( ! $module ) {
			return true;
		}
		return $module->is_active() && $module->is_available();
	}

	/**
	 * Whether the AI Chat submenu tab should show.
	 *
	 * @return bool
	 */
	private function ai_chat_tab_visible(): bool {
		return $this->module_tab_visible( 'ai-chat' );
	}

	/**
	 * Whether the Project Memory submenu tab should show (module active + Pro).
	 *
	 * @since 3.7.0
	 *
	 * @return bool
	 */
	public function memory_tab_visible(): bool {
		return $this->module_tab_visible( 'memory' );
	}

	/**
	 * Number of agent-proposed project-memory entries awaiting review (0 when the
	 * Memory tab is hidden or the store is unavailable). Surfaced as a count badge
	 * on the Memory submenu + in-page nav so pending proposals aren't forgotten.
	 *
	 * @return int
	 */
	public function memory_pending_count(): int {
		if ( ! $this->memory_tab_visible() || ! class_exists( 'EMCP_Tools_Memory_Store' ) ) {
			return 0;
		}
		return EMCP_Tools_Memory_Store::instance()->pending_count();
	}

	/**
	 * Dashicon class for a tab id, used by the in-header nav. Falls back to a
	 * generic marker for unknown ids.
	 *
	 * @param string $tab_id Tab id as returned by get_active_tab().
	 * @return string Dashicon class.
	 */
	public static function tab_icon( string $tab_id ): string {
		$icons = array(
			'dashboard'  => 'dashicons-dashboard',
			'tools'      => 'dashicons-admin-tools',
			'history'    => 'dashicons-undo',
			'redirects'  => 'dashicons-randomize',
			'migrate'    => 'dashicons-migrate',
			'modules'    => 'dashicons-screenoptions',
			'connection' => 'dashicons-admin-links',
			'ai-chat'    => 'dashicons-format-chat',
			'context'    => 'dashicons-info-outline',
			'memory'     => 'dashicons-database',
			'prompts'    => 'dashicons-lightbulb',
			'templates'  => 'dashicons-layout',
			'brand-kits' => 'dashicons-art',
			'skills'     => 'dashicons-superhero',
			'widgets'    => 'dashicons-editor-code',
			'mcp-log'    => 'dashicons-list-view',
			'changelog'  => 'dashicons-backup',
		);
		return $icons[ $tab_id ] ?? 'dashicons-marker';
	}

	private function get_submenus(): array {
		if ( null === $this->submenus ) {
			$this->submenus = array(
				self::PAGE_SLUG                 => __( 'Dashboard', 'emcp-tools' ),
				self::PAGE_SLUG . '-modules'    => __( 'Modules', 'emcp-tools' ),
				self::PAGE_SLUG . '-tools'      => __( 'Tools', 'emcp-tools' ),
				self::PAGE_SLUG . '-connection' => __( 'Connection', 'emcp-tools' ),
				self::PAGE_SLUG . '-ai-chat'    => __( 'AI Chat', 'emcp-tools' ),
				self::PAGE_SLUG . '-context'    => __( 'Context', 'emcp-tools' ),
				self::PAGE_SLUG . '-redirects'  => __( 'Redirects', 'emcp-tools' ),
				self::PAGE_SLUG . '-migrate'    => __( 'Backup & Migrate', 'emcp-tools' ),
				self::PAGE_SLUG . '-memory'     => __( 'Memory', 'emcp-tools' ),
				self::PAGE_SLUG . '-prompts'    => __( 'Prompts', 'emcp-tools' ),
				self::PAGE_SLUG . '-templates'  => __( 'Templates', 'emcp-tools' ),
				self::PAGE_SLUG . '-brand-kits' => __( 'Brand Kits', 'emcp-tools' ),
				self::PAGE_SLUG . '-skills'     => __( 'Skills', 'emcp-tools' ),
				self::PAGE_SLUG . '-widgets'    => __( 'Sandbox', 'emcp-tools' ),
				self::PAGE_SLUG . '-marketplace' => __( 'Marketplace', 'emcp-tools' ),
				self::PAGE_SLUG . '-mcp-log'    => __( 'MCP Log', 'emcp-tools' ),
				self::PAGE_SLUG . '-history'    => __( 'History', 'emcp-tools' ),
				self::PAGE_SLUG . '-changelog'  => __( 'Changelog', 'emcp-tools' ),
			);
			if ( ! $this->ai_chat_tab_visible() ) {
				unset( $this->submenus[ self::PAGE_SLUG . '-ai-chat' ] );
			}
			// Marketplace is a Cloud feature — drop the tab when the Cloud module is off.
			if ( ! ( class_exists( 'EMCP_Tools_Cloud_Module' ) && EMCP_Tools_Cloud_Module::is_enabled() ) ) {
				unset( $this->submenus[ self::PAGE_SLUG . '-marketplace' ] );
			}
			if ( ! $this->memory_tab_visible() ) {
				unset( $this->submenus[ self::PAGE_SLUG . '-memory' ] );
			}
			// Redirects tab is gated by the Redirect Manager module.
			if ( ! $this->module_tab_visible( 'redirects' ) ) {
				unset( $this->submenus[ self::PAGE_SLUG . '-redirects' ] );
			}
			// Backup & Migrate tab is gated by the Migrate (Pro) module.
			if ( ! $this->module_tab_visible( 'migrate' ) ) {
				unset( $this->submenus[ self::PAGE_SLUG . '-migrate' ] );
			}
			// Module-backed tabs: drop each when its module is off/unavailable.
			foreach ( array( 'prompts', 'templates', 'brand-kits' ) as $emcp_mod_id ) {
				if ( ! $this->module_tab_visible( $emcp_mod_id ) ) {
					unset( $this->submenus[ self::PAGE_SLUG . '-' . $emcp_mod_id ] );
				}
			}
		}
		return $this->submenus;
	}

	/**
	 * Determine which sub-screen is active from $_GET['page'].
	 *
	 * @return string One of 'tools', 'connection', 'prompts', 'changelog'.
	 */
	private function get_active_tab(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended
		$page = isset( $_GET['page'] ) ? sanitize_key( wp_unslash( $_GET['page'] ) ) : '';

		switch ( $page ) {
			case self::PAGE_SLUG . '-tools':
				return 'tools';
			case self::PAGE_SLUG . '-history':
				return 'history';
			case self::PAGE_SLUG . '-redirects':
				return 'redirects';
			case self::PAGE_SLUG . '-migrate':
				return 'migrate';
			case self::PAGE_SLUG . '-modules':
				return 'modules';
			case self::PAGE_SLUG . '-connection':
				return 'connection';
			case self::PAGE_SLUG . '-ai-chat':
				return 'ai-chat';
			case self::PAGE_SLUG . '-context':
				return 'context';
			case self::PAGE_SLUG . '-memory':
				return 'memory';
			case self::PAGE_SLUG . '-prompts':
				return 'prompts';
			case self::PAGE_SLUG . '-templates':
				return 'templates';
			case self::PAGE_SLUG . '-brand-kits':
				return 'brand-kits';
			case self::PAGE_SLUG . '-skills':
				return 'skills';
			case self::PAGE_SLUG . '-widgets':
				return 'widgets';
			case self::PAGE_SLUG . '-marketplace':
				return 'marketplace';
			case self::PAGE_SLUG . '-mcp-log':
				return 'mcp-log';
			case self::PAGE_SLUG . '-changelog':
				return 'changelog';
			default:
				return 'dashboard';
		}
	}

	/**
	 * Initialize hooks.
	 *
	 * @since 1.0.0
	 */
	public function init(): void {
		add_action( 'admin_menu', array( $this, 'add_settings_page' ) );
		add_action( 'admin_init', array( $this, 'register_settings' ) );
		add_action( 'admin_init', array( $this, 'maybe_apply_default_disabled_tools' ) );
		add_action( 'admin_enqueue_scripts', array( $this, 'enqueue_assets' ) );
		add_action( 'admin_head', array( $this, 'print_menu_icon_style' ) );
		add_action( 'wp_ajax_emcp_tools_create_app_password', array( $this, 'ajax_create_app_password' ) );
		add_action( 'wp_ajax_emcp_tools_test_connection', array( $this, 'ajax_test_connection' ) );
		add_action( 'wp_ajax_emcp_tools_test_oauth_discovery', array( $this, 'ajax_test_oauth_discovery' ) );
		add_action( 'wp_ajax_emcp_tools_toggle_widget', array( $this, 'ajax_toggle_widget' ) );
		add_action( 'wp_ajax_emcp_tools_delete_widget', array( $this, 'ajax_delete_widget' ) );
		add_action( 'wp_ajax_emcp_tools_toggle_block', array( $this, 'ajax_toggle_block' ) );
		add_action( 'wp_ajax_emcp_tools_delete_block', array( $this, 'ajax_delete_block' ) );
		add_action( 'wp_ajax_emcp_tools_backup_artifact', array( $this, 'ajax_backup_artifact' ) );
		add_action( 'wp_ajax_emcp_tools_bulk_backup_artifacts', array( $this, 'ajax_bulk_backup_artifacts' ) );
		add_action( 'wp_ajax_emcp_tools_push_update', array( $this, 'ajax_push_update' ) );
		add_action( 'wp_ajax_emcp_tools_marketplace_state', array( $this, 'ajax_marketplace_state' ) );
		add_action( 'wp_ajax_emcp_tools_resync_cloud', array( $this, 'ajax_resync_cloud' ) );
		add_action( 'wp_ajax_emcp_tools_cloud_library', array( $this, 'ajax_cloud_library' ) );
		add_action( 'wp_ajax_emcp_tools_cloud_import', array( $this, 'ajax_cloud_import' ) );
		add_action( 'wp_ajax_emcp_tools_memory_set_status', array( $this, 'ajax_memory_set_status' ) );
		add_action( 'wp_ajax_emcp_tools_memory_save_guidance', array( $this, 'ajax_memory_save_guidance' ) );
		add_action( 'wp_ajax_emcp_tools_memory_save_settings', array( $this, 'ajax_memory_save_settings' ) );
		add_action( 'wp_ajax_emcp_tools_save_php_snippet', array( $this, 'ajax_save_php_snippet' ) );
		add_action( 'wp_ajax_emcp_tools_toggle_php_snippet', array( $this, 'ajax_toggle_php_snippet' ) );
		add_action( 'wp_ajax_emcp_tools_delete_php_snippet', array( $this, 'ajax_delete_php_snippet' ) );
		add_action( 'wp_ajax_emcp_tools_notifications_read', array( $this, 'ajax_notifications_read' ) );
		add_action( 'admin_post_emcp_tools_download_mcpb', array( $this, 'handle_download_mcpb' ) );
		add_action( 'admin_post_' . self::ACTION_DISMISS_PROMPTS_NOTICE, array( $this, 'handle_dismiss_prompts_notice' ) );
		add_action( 'admin_post_' . self::ACTION_ROLLBACK_CHANGE, array( $this, 'handle_rollback_change' ) );
		add_action( 'admin_post_' . self::ACTION_DELETE_CHANGE, array( $this, 'handle_delete_change' ) );
		add_action( 'admin_post_' . self::ACTION_CLEAR_CHANGES, array( $this, 'handle_clear_changes' ) );
		add_action( 'admin_post_' . self::ACTION_REVOKE_OAUTH, array( $this, 'handle_revoke_oauth_client' ) );
		add_action( 'admin_post_' . self::ACTION_DELETE_OAUTH_CLIENT, array( $this, 'handle_delete_oauth_client' ) );
		add_action( 'admin_post_' . self::ACTION_EXPORT_ARTIFACT, array( $this, 'handle_export_artifact' ) );
		add_action( 'admin_post_' . self::ACTION_IMPORT_ARTIFACT, array( $this, 'handle_import_artifact' ) );
		add_action( 'admin_post_emcp_tools_settings_push', array( $this, 'handle_settings_push' ) );
		add_action( 'admin_post_emcp_tools_settings_pull', array( $this, 'handle_settings_pull' ) );
		add_action( 'admin_post_emcp_tools_marketplace_install', array( $this, 'handle_marketplace_install' ) );
		add_action( 'admin_post_emcp_tools_redirect_save', array( $this, 'handle_redirect_save' ) );
		add_action( 'admin_post_emcp_tools_redirect_delete', array( $this, 'handle_redirect_delete' ) );
		add_action( 'admin_post_emcp_tools_redirect_toggle', array( $this, 'handle_redirect_toggle' ) );
	}

	/** Nonce action for the .mcpb bundle download. */
	const NONCE_DOWNLOAD_MCPB = 'emcp_tools_download_mcpb';

	/** admin-post action that dismisses the "prompts rewritten" notice. */
	const ACTION_DISMISS_PROMPTS_NOTICE = 'emcp_tools_dismiss_prompts_notice';

	/** admin-post action that rolls back a change from the History tab. */
	const ACTION_ROLLBACK_CHANGE = 'emcp_tools_rollback_change';

	/** admin-post action that deletes one entry from the History ledger. */
	const ACTION_DELETE_CHANGE = 'emcp_tools_delete_change';

	/** admin-post action that clears the whole History ledger. */
	const ACTION_CLEAR_CHANGES = 'emcp_tools_clear_changes';

	/** Nonce action shared by the sandbox artifact export/import admin-post handlers. */
	const NONCE_SANDBOX_BUNDLE = 'emcp_tools_sandbox_bundle';

	/** admin-post action that streams a sandbox artifact as a portable JSON bundle download. */
	const ACTION_EXPORT_ARTIFACT = 'emcp_tools_export_artifact';

	/** admin-post action that imports an uploaded sandbox artifact bundle. */
	const ACTION_IMPORT_ARTIFACT = 'emcp_tools_import_artifact';

	/**
	 * admin-post action: revoke all tokens for one OAuth client.
	 *
	 * @var string
	 */
	const ACTION_REVOKE_OAUTH = 'emcp_tools_revoke_oauth_client';

	/**
	 * Delete an OAuth client registration outright (tokens included).
	 *
	 * Distinct from ACTION_REVOKE_OAUTH, which signs an app out but keeps the
	 * registration so it can sign back in. This one is for a registration that
	 * can never be used again, typically because the app now asks for a
	 * different callback than the one it registered.
	 *
	 * @since 3.15.0
	 */
	const ACTION_DELETE_OAUTH_CLIENT = 'emcp_tools_delete_oauth_client';

	/**
	 * Nonce-protected URL that rolls back one change-ledger entry.
	 *
	 * @since 3.3.0
	 * @param string $id Change id.
	 * @return string
	 */
	public static function rollback_change_url( string $id, bool $force = false ): string {
		$url = admin_url( 'admin-post.php?action=' . self::ACTION_ROLLBACK_CHANGE . '&change=' . rawurlencode( $id ) );
		if ( $force ) {
			$url .= '&force=1';
		}
		return wp_nonce_url( $url, self::ACTION_ROLLBACK_CHANGE . '_' . $id );
	}

	/**
	 * Roll back a change from the History tab, then bounce back with a notice.
	 *
	 * @since 3.3.0
	 */
	public function handle_rollback_change(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'emcp-tools' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified just below against the per-id action.
		$id = isset( $_GET['change'] ) ? sanitize_text_field( wp_unslash( $_GET['change'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified just below; force is a display flag on an admin-gated action.
		$force = ! empty( $_GET['force'] );
		check_admin_referer( self::ACTION_ROLLBACK_CHANGE . '_' . $id );

		$result = class_exists( 'EMCP_Tools_Change_Log' ) ? EMCP_Tools_Change_Log::rollback( $id, $force ) : new WP_Error( 'unavailable', 'unavailable' );
		if ( is_wp_error( $result ) ) {
			// A conflict is recoverable — bounce back with the id so the History
			// tab can offer a "roll back anyway" (force) action.
			$status = ( 'conflict' === $result->get_error_code() )
				? 'conflict&change=' . rawurlencode( $id )
				: 'error&msg=' . rawurlencode( $result->get_error_message() );
		} else {
			$status = ! empty( $result['partial'] ) ? 'partial' : 'ok';
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-history&rollback=' . $status ) );
		exit;
	}

	/**
	 * Nonce'd URL that deletes one History entry.
	 *
	 * @since 3.4.2
	 * @param string $id Entry id.
	 * @return string
	 */
	public static function delete_change_url( string $id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_DELETE_CHANGE . '&change=' . rawurlencode( $id ) ),
			self::ACTION_DELETE_CHANGE . '_' . $id
		);
	}

	/**
	 * Nonce'd URL that clears the whole History ledger.
	 *
	 * @since 3.4.2
	 * @return string
	 */
	public static function clear_changes_url(): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_CLEAR_CHANGES ),
			self::ACTION_CLEAR_CHANGES
		);
	}

	/**
	 * Delete one entry from the History ledger, then bounce back with a notice.
	 *
	 * @since 3.4.2
	 */
	public function handle_delete_change(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'emcp-tools' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified just below against the per-id action.
		$id = isset( $_GET['change'] ) ? sanitize_text_field( wp_unslash( $_GET['change'] ) ) : '';
		check_admin_referer( self::ACTION_DELETE_CHANGE . '_' . $id );

		$deleted = class_exists( 'EMCP_Tools_Change_Log' ) && EMCP_Tools_Change_Log::delete( $id );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-history&deleted=' . ( $deleted ? '1' : '0' ) ) );
		exit;
	}

	/**
	 * Clear the whole History ledger, then bounce back with a notice.
	 *
	 * @since 3.4.2
	 */
	public function handle_clear_changes(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'emcp-tools' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( self::ACTION_CLEAR_CHANGES );

		$count = class_exists( 'EMCP_Tools_Change_Log' ) ? EMCP_Tools_Change_Log::clear() : 0;

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-history&cleared=' . (int) $count ) );
		exit;
	}

	/**
	 * Bounce back to the Redirects tab with a status code.
	 *
	 * @param string $status Status slug for a notice.
	 */
	private function redirect_back_to_redirects( string $status ): void {
		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-redirects&notice=' . rawurlencode( $status ) ) );
		exit;
	}

	/**
	 * Create or update a redirect from the management form. Routes through the
	 * store + ledger so admin edits are reversible in History.
	 *
	 * @since 3.11.0
	 */
	public function handle_redirect_save(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'emcp-tools' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'emcp_tools_redirect_save' );
		if ( ! class_exists( 'EMCP_Tools_Redirect_Store' ) ) {
			$this->redirect_back_to_redirects( 'error' );
		}
		$id             = isset( $_POST['redirect_id'] ) ? absint( wp_unslash( $_POST['redirect_id'] ) ) : 0;
		$source         = isset( $_POST['source'] ) ? sanitize_text_field( wp_unslash( $_POST['source'] ) ) : '';
		$target_raw     = isset( $_POST['target'] ) ? esc_url_raw( wp_unslash( $_POST['target'] ) ) : '';
		$target_post_id = isset( $_POST['target_post_id'] ) ? absint( wp_unslash( $_POST['target_post_id'] ) ) : 0;
		$status_code    = isset( $_POST['status_code'] ) ? absint( wp_unslash( $_POST['status_code'] ) ) : 301;
		$ignore_query   = ! empty( $_POST['ignore_query'] );

		$data = array(
			'source'       => $source,
			'status_code'  => $status_code,
			'ignore_query' => $ignore_query,
		);
		if ( $target_post_id ) {
			$data['target_post_id'] = $target_post_id;
		} else {
			$data['target'] = $target_raw;
		}

		if ( $id ) {
			$prior = EMCP_Tools_Redirect_Store::get( $id );
			$res   = EMCP_Tools_Redirect_Store::update( $id, $data );
			if ( ! is_wp_error( $res ) && $prior && class_exists( 'EMCP_Tools_Change_Recorder' ) ) {
				EMCP_Tools_Change_Recorder::record_redirect( 'update', array( 'row' => $prior ), sprintf( 'Updated redirect %s', $res['source_path'] ), (string) $res['source_path'] );
			}
		} else {
			$res = EMCP_Tools_Redirect_Store::create( $data );
			if ( ! is_wp_error( $res ) && class_exists( 'EMCP_Tools_Change_Recorder' ) ) {
				EMCP_Tools_Change_Recorder::record_redirect( 'create', array( 'id' => (int) $res['id'] ), sprintf( 'Created redirect %s', $res['source_path'] ), (string) $res['source_path'] );
			}
		}
		$this->redirect_back_to_redirects( is_wp_error( $res ) ? 'error:' . $res->get_error_code() : ( $id ? 'updated' : 'created' ) );
	}

	/**
	 * Delete a redirect (nonce per-id), recorded for rollback.
	 *
	 * @since 3.11.0
	 */
	public function handle_redirect_delete(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'emcp-tools' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified just below against the per-id action.
		$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		check_admin_referer( 'emcp_tools_redirect_delete_' . $id );
		if ( class_exists( 'EMCP_Tools_Redirect_Store' ) ) {
			$prior = EMCP_Tools_Redirect_Store::get( $id );
			if ( $prior && EMCP_Tools_Redirect_Store::delete( $id ) && class_exists( 'EMCP_Tools_Change_Recorder' ) ) {
				EMCP_Tools_Change_Recorder::record_redirect( 'delete', array( 'row' => $prior ), sprintf( 'Deleted redirect %s', $prior['source_path'] ), (string) $prior['source_path'] );
			}
		}
		$this->redirect_back_to_redirects( 'deleted' );
	}

	/**
	 * Toggle a redirect's enabled state (nonce per-id), recorded for rollback.
	 *
	 * @since 3.11.0
	 */
	public function handle_redirect_toggle(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'emcp-tools' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified just below against the per-id action.
		$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;
		check_admin_referer( 'emcp_tools_redirect_toggle_' . $id );
		if ( class_exists( 'EMCP_Tools_Redirect_Store' ) ) {
			$prior = EMCP_Tools_Redirect_Store::get( $id );
			if ( $prior ) {
				$res = EMCP_Tools_Redirect_Store::update( $id, array( 'enabled' => empty( $prior['enabled'] ) ) );
				if ( ! is_wp_error( $res ) && class_exists( 'EMCP_Tools_Change_Recorder' ) ) {
					EMCP_Tools_Change_Recorder::record_redirect( 'update', array( 'row' => $prior ), sprintf( 'Toggled redirect %s', $prior['source_path'] ), (string) $prior['source_path'] );
				}
			}
		}
		$this->redirect_back_to_redirects( 'updated' );
	}

	/**
	 * Nonce'd URL that deletes one redirect.
	 *
	 * @since 3.11.0
	 * @param int $id Redirect id.
	 * @return string
	 */
	public static function redirect_delete_url( int $id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=emcp_tools_redirect_delete&id=' . $id ),
			'emcp_tools_redirect_delete_' . $id
		);
	}

	/**
	 * Nonce'd URL that toggles one redirect's enabled state.
	 *
	 * @since 3.11.0
	 * @param int $id Redirect id.
	 * @return string
	 */
	public static function redirect_toggle_url( int $id ): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=emcp_tools_redirect_toggle&id=' . $id ),
			'emcp_tools_redirect_toggle_' . $id
		);
	}

	/**
	 * Push the local EMCP settings to EMCP Cloud (paid Cloud feature).
	 */
	public function handle_settings_push(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'emcp-tools' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'emcp_tools_settings_sync' );
		$res = class_exists( 'EMCP_Tools_Settings_Sync' ) ? EMCP_Tools_Settings_Sync::push() : new \WP_Error( 'unavailable', '' );
		$this->redirect_settings_sync( is_wp_error( $res ) ? 'err' : 'push', is_wp_error( $res ) ? $res->get_error_code() : '' );
	}

	/**
	 * Pull the EMCP settings from EMCP Cloud and apply them (paid Cloud feature).
	 */
	public function handle_settings_pull(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'emcp-tools' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'emcp_tools_settings_sync' );
		$res = class_exists( 'EMCP_Tools_Settings_Sync' ) ? EMCP_Tools_Settings_Sync::pull_and_apply() : new \WP_Error( 'unavailable', '' );
		$this->redirect_settings_sync( is_wp_error( $res ) ? 'err' : 'pull', is_wp_error( $res ) ? $res->get_error_code() : '' );
	}

	/**
	 * Back up a Sandbox artifact (block/widget/snippet) to EMCP Cloud. AJAX.
	 */
	public function ajax_backup_artifact(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'emcp-tools' ) ), 403 );
		}
		$kind   = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$nonces = array(
			'widget'  => 'emcp_tools_widgets',
			'block'   => 'emcp_tools_blocks',
			'snippet' => 'emcp_tools_php_snippets',
		);
		if ( ! isset( $nonces[ $kind ] ) || ! check_ajax_referer( $nonces[ $kind ], 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'emcp-tools' ) ), 403 );
		}
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		if ( ! $id || ! class_exists( 'EMCP_Tools_Cloud_Sync' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to save.', 'emcp-tools' ) ) );
		}
		$res = EMCP_Tools_Cloud_Sync::backup( $kind, $id );
		if ( is_wp_error( $res ) ) {
			$msg = ( 'not_connected' === $res->get_error_code() )
				? __( 'Connect this site to EMCP Cloud first.', 'emcp-tools' )
				: $res->get_error_message();
			wp_send_json_error( array( 'message' => $msg ) );
		}
		// Record that this artifact now exists in the cloud + the checksum of what
		// was pushed (to later detect local edits), and refresh its marketplace
		// state so the buttons reflect reality.
		update_post_meta( $id, '_emcp_cloud_pushed', time() );
		self::store_artifact_checksum( $kind, $id );
		self::refresh_marketplace_state( $kind, $id );
		$payload            = self::cloud_action_payload( $kind, $id );
		$payload['message'] = __( 'Saved to cloud.', 'emcp-tools' );
		wp_send_json_success( $payload );
	}

	/**
	 * Back up EVERY Sandbox artifact of a kind to EMCP Cloud in one call — the
	 * bulk counterpart to ajax_backup_artifact(), driving the "Save all to Cloud"
	 * button. AJAX.
	 */
	public function ajax_bulk_backup_artifacts(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'emcp-tools' ) ), 403 );
		}
		$kind   = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$nonces = array(
			'widget'  => 'emcp_tools_widgets',
			'block'   => 'emcp_tools_blocks',
			'snippet' => 'emcp_tools_php_snippets',
		);
		if ( ! isset( $nonces[ $kind ] ) || ! check_ajax_referer( $nonces[ $kind ], 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'emcp-tools' ) ), 403 );
		}
		if ( ! class_exists( 'EMCP_Tools_Cloud_Sync' ) ) {
			wp_send_json_error( array( 'message' => __( 'Cloud sync is unavailable.', 'emcp-tools' ) ) );
		}
		$res = EMCP_Tools_Cloud_Sync::bulk_backup( array( $kind ) );
		if ( is_wp_error( $res ) ) {
			$msg = ( 'not_connected' === $res->get_error_code() )
				? __( 'Connect this site to EMCP Cloud first.', 'emcp-tools' )
				: $res->get_error_message();
			wp_send_json_error( array( 'message' => $msg ) );
		}
		// Mirror the per-artifact post-processing so each pushed row reflects "Saved".
		foreach ( (array) ( $res['items'] ?? array() ) as $emcp_item ) {
			if ( empty( $emcp_item['ok'] ) ) {
				continue;
			}
			$emcp_iid = (int) ( $emcp_item['id'] ?? 0 );
			if ( $emcp_iid ) {
				update_post_meta( $emcp_iid, '_emcp_cloud_pushed', time() );
				self::store_artifact_checksum( $kind, $emcp_iid );
				self::refresh_marketplace_state( $kind, $emcp_iid );
			}
		}
		$pushed = (int) ( $res['pushed'] ?? 0 );
		$failed = (int) ( $res['failed'] ?? 0 );
		/* translators: %d: number of artifacts saved to the cloud. */
		$message = sprintf( _n( 'Saved %d item to the cloud.', 'Saved %d items to the cloud.', $pushed, 'emcp-tools' ), $pushed );
		if ( $failed > 0 ) {
			/* translators: %d: number of artifacts that failed to save. */
			$message .= ' ' . sprintf( _n( '%d failed.', '%d failed.', $failed, 'emcp-tools' ), $failed );
		}
		wp_send_json_success(
			array(
				'pushed'  => $pushed,
				'failed'  => $failed,
				'message' => $message,
			)
		);
	}

	/** Nonce action for a sandbox artifact kind. */
	private static function cloud_nonce_action( string $kind ): string {
		$map = array( 'widget' => 'emcp_tools_widgets', 'block' => 'emcp_tools_blocks', 'snippet' => 'emcp_tools_php_snippets' );
		return $map[ $kind ] ?? '';
	}

	/** Cache the current content checksum as the last-pushed checksum. */
	private static function store_artifact_checksum( string $kind, int $id ): void {
		$sum = self::artifact_checksum( $kind, $id );
		if ( '' !== $sum ) {
			update_post_meta( $id, '_emcp_cloud_checksum', $sum );
		}
	}

	/** Current content checksum for an artifact ('' if unresolvable). */
	private static function artifact_checksum( string $kind, int $id ): string {
		if ( ! class_exists( 'EMCP_Tools_Sandbox_Cloud_Abilities' ) ) {
			return '';
		}
		$art = ( new EMCP_Tools_Sandbox_Cloud_Abilities() )->resolve_artifact( $kind );
		return $art ? (string) $art->checksum( $id ) : '';
	}

	/** True when local content differs from what was last pushed to the cloud. */
	private static function artifact_changed( string $kind, int $id ): bool {
		if ( ! get_post_meta( $id, '_emcp_cloud_pushed', true ) ) {
			return false;
		}
		$pushed = (string) get_post_meta( $id, '_emcp_cloud_checksum', true );
		if ( '' === $pushed ) {
			// No recorded baseline — e.g. the artifact was pushed/published before
			// checksum tracking existed. We can't prove the content is unchanged,
			// so allow an update rather than hide "Push update" forever. Pushing (or
			// re-saving) records a fresh baseline via store_artifact_checksum(),
			// which self-heals the state back to "Up to date".
			return true;
		}
		return self::artifact_checksum( $kind, $id ) !== $pushed;
	}

	/**
	 * Fetch marketplace state from the cloud and cache the useful bits locally.
	 * Best-effort — returns the state array, or null on any error.
	 */
	private static function refresh_marketplace_state( string $kind, int $id ): ?array {
		if ( ! class_exists( 'EMCP_Tools_Cloud_Sync' ) ) {
			return null;
		}
		$state = EMCP_Tools_Cloud_Sync::marketplace_state( $kind, $id );
		if ( is_wp_error( $state ) || ! is_array( $state ) ) {
			return null;
		}
		$slug = isset( $state['slug'] ) ? (string) $state['slug'] : '';
		if ( '' !== $slug ) {
			update_post_meta( $id, '_emcp_marketplace_slug', $slug );
			update_post_meta( $id, '_emcp_marketplace_status', (string) ( $state['status'] ?? '' ) );
			update_post_meta( $id, '_emcp_marketplace_pending', ! empty( $state['hasPendingUpdate'] ) ? 1 : 0 );
		} else {
			delete_post_meta( $id, '_emcp_marketplace_slug' );
			delete_post_meta( $id, '_emcp_marketplace_status' );
			delete_post_meta( $id, '_emcp_marketplace_pending' );
		}
		return $state;
	}

	/**
	 * Verify the artifact still exists as a CLOUD BACKUP (separate from any
	 * marketplace listing). If it was deleted remotely, clear the local
	 * "pushed" flag so the button reverts from "Saved" to "Save to Cloud".
	 *
	 * Only a definitive 404/410 resets the state — transient errors (network,
	 * 5xx, not-connected) leave it untouched so a blip never drops a real save.
	 */
	private static function verify_cloud_backup( string $kind, int $id ): void {
		if ( ! get_post_meta( $id, '_emcp_cloud_pushed', true ) ) {
			return; // nothing claims to be pushed.
		}
		if ( ! class_exists( 'EMCP_Tools_Cloud_Client' ) || ! class_exists( 'EMCP_Tools_Sandbox_Cloud_Abilities' ) ) {
			return;
		}
		$art  = ( new EMCP_Tools_Sandbox_Cloud_Abilities() )->resolve_artifact( $kind );
		$uuid = $art ? (string) $art->uuid( $id ) : '';
		if ( '' === $uuid ) {
			return;
		}
		$res = EMCP_Tools_Cloud_Client::get( '/api/cloud/v1/artifacts/' . rawurlencode( $uuid ) );
		if ( is_wp_error( $res ) && in_array( $res->get_error_code(), array( 'cloud_http_404', 'cloud_http_410' ), true ) ) {
			delete_post_meta( $id, '_emcp_cloud_pushed' );
			delete_post_meta( $id, '_emcp_cloud_checksum' );
		}
	}

	/** JS payload describing an artifact's cloud/marketplace state (from cached meta). */
	public static function cloud_action_payload( string $kind, int $id ): array {
		$slug   = (string) get_post_meta( $id, '_emcp_marketplace_slug', true );
		$status = (string) get_post_meta( $id, '_emcp_marketplace_status', true );
		return array(
			'kind'               => $kind,
			'id'                 => $id,
			'pushed'             => (bool) get_post_meta( $id, '_emcp_cloud_pushed', true ),
			'changed'            => self::artifact_changed( $kind, $id ),
			'slug'               => $slug,
			'status'             => $status,
			'published'          => ( 'published' === $status ),
			'has_pending_update' => (bool) get_post_meta( $id, '_emcp_marketplace_pending', true ),
			'publish_url'        => class_exists( 'EMCP_Tools_Cloud_Sync' ) ? EMCP_Tools_Cloud_Sync::publish_url( $kind, $id ) : '',
			'view_url'           => ( '' !== $slug && class_exists( 'EMCP_Tools_Cloud_Sync' ) ) ? EMCP_Tools_Cloud_Sync::marketplace_view_url( $slug ) : '',
		);
	}

	/**
	 * Renders the Sandbox cloud/marketplace button cluster. The correct buttons
	 * are shown server-side (works without JS); sandbox-cloud.js refines them
	 * after refreshing state. Visibility is toggled via inline display because
	 * WordPress's `.button` (display:inline-block) overrides the [hidden] attr.
	 */
	public static function render_sandbox_cloud_actions( string $kind, int $id ): string {
		if ( ! class_exists( 'EMCP_Tools_Cloud' ) || ! EMCP_Tools_Cloud::is_connected() ) {
			return '';
		}
		$s     = self::cloud_action_payload( $kind, $id );
		$nonce = wp_create_nonce( self::cloud_nonce_action( $kind ) );

		$pushed    = ! empty( $s['pushed'] );
		$published = ! empty( $s['published'] );
		$changed   = ! empty( $s['changed'] );
		$has_slug  = '' !== (string) $s['slug'];
		$pending   = ! empty( $s['has_pending_update'] );

		// Save button label/state. Hidden once published (updates go via Push update).
		$save_show = true;
		$save_dis  = false;
		if ( ! $pushed ) {
			$save_txt = __( 'Save to Cloud', 'emcp-tools' );
		} elseif ( $published ) {
			$save_show = false;
			$save_txt  = __( 'Save to Cloud', 'emcp-tools' );
		} elseif ( $changed ) {
			$save_txt = __( 'Update cloud', 'emcp-tools' );
		} else {
			$save_txt = __( 'Saved', 'emcp-tools' );
			$save_dis = true;
		}
		$publish_show = $pushed && ! $has_slug;
		$view_show    = $has_slug;
		$update_show  = $published && $changed && ! $pending;

		$tag_txt = '';
		if ( $pending ) {
			$tag_txt = __( 'Update in review', 'emcp-tools' );
		} elseif ( $has_slug && 'pending' === (string) $s['status'] ) {
			$tag_txt = __( 'In review', 'emcp-tools' );
		} elseif ( $published && ! $changed ) {
			$tag_txt = __( 'Up to date', 'emcp-tools' );
		}

		$hide = static function ( bool $show ): string {
			return $show ? '' : ' style="display:none"';
		};
		$icon = static function ( string $d ): string {
			return '<span class="dashicons dashicons-' . esc_attr( $d ) . '" aria-hidden="true"></span>';
		};

		ob_start();
		?>
		<span class="emcp-sb-cloud" data-kind="<?php echo esc_attr( $kind ); ?>" data-id="<?php echo esc_attr( (string) $id ); ?>" data-nonce="<?php echo esc_attr( $nonce ); ?>" data-state="<?php echo esc_attr( (string) wp_json_encode( $s ) ); ?>">
			<button type="button" class="button emcp-sb-save"<?php echo $hide( $save_show ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?><?php disabled( $save_dis ); ?>
				data-t-save="<?php echo esc_attr__( 'Save to Cloud', 'emcp-tools' ); ?>"
				data-t-update="<?php echo esc_attr__( 'Update cloud', 'emcp-tools' ); ?>"
				data-t-saved="<?php echo esc_attr__( 'Saved', 'emcp-tools' ); ?>"><?php
				echo $icon( 'backup' ) . '<span class="emcp-sb-txt">' . esc_html( $save_txt ) . '</span>'; // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
			?></button>
			<a class="button button-primary emcp-sb-publish" href="<?php echo esc_url( $s['publish_url'] ); ?>" target="_blank" rel="noopener"<?php echo $hide( $publish_show ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php
				echo $icon( 'upload' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html_e( 'Publish to Marketplace', 'emcp-tools' );
			?></a>
			<a class="button emcp-sb-view" href="<?php echo esc_url( $s['view_url'] ); ?>" target="_blank" rel="noopener"<?php echo $hide( $view_show ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php
				echo $icon( 'external' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html_e( 'View on Marketplace', 'emcp-tools' );
			?></a>
			<button type="button" class="button button-primary emcp-sb-update"<?php echo $hide( $update_show ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>><?php
				echo $icon( 'update' ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped
				esc_html_e( 'Push update', 'emcp-tools' );
			?></button>
			<span class="emcp-sb-tag"<?php echo $hide( '' !== $tag_txt ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped ?>
				data-t-inreview="<?php echo esc_attr__( 'Update in review', 'emcp-tools' ); ?>"
				data-t-pending="<?php echo esc_attr__( 'In review', 'emcp-tools' ); ?>"
				data-t-uptodate="<?php echo esc_attr__( 'Up to date', 'emcp-tools' ); ?>"><?php echo esc_html( $tag_txt ); ?></span>
			<span class="emcp-sb-msg" aria-live="polite"></span>
		</span>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * Renders the "Cloud Library" panel for a sandbox screen — a collapsible list
	 * of the whole workspace's cloud artifacts of this kind (across every connected
	 * site), each importable into THIS site as a new inactive draft. Empty string
	 * when the site isn't cloud-connected. The list is fetched lazily on first open
	 * (see assets/js/cloud-library.js); import runs EMCP_Tools_Cloud_Sync::pull().
	 *
	 * @param string $kind Artifact kind (widget/block/snippet).
	 * @return string
	 */
	public static function render_cloud_library( string $kind ): string {
		if ( ! class_exists( 'EMCP_Tools_Cloud' ) || ! EMCP_Tools_Cloud::is_connected() ) {
			return '';
		}
		$na = self::cloud_nonce_action( $kind );
		if ( '' === $na ) {
			return '';
		}
		$plural = array(
			'widget'  => __( 'widgets', 'emcp-tools' ),
			'block'   => __( 'blocks', 'emcp-tools' ),
			'snippet' => __( 'snippets', 'emcp-tools' ),
		);
		$kl = $plural[ $kind ] ?? $kind;

		ob_start();
		?>
		<details class="emcp-cloud-lib emcp-sb-disclosure emcp-sb-disclosure--cloud" data-kind="<?php echo esc_attr( $kind ); ?>" data-nonce="<?php echo esc_attr( wp_create_nonce( $na ) ); ?>" data-site="<?php echo esc_attr( EMCP_Tools_Cloud::site_uuid() ); ?>"
			data-t-loading="<?php echo esc_attr__( 'Loading…', 'emcp-tools' ); ?>"
			data-t-import="<?php echo esc_attr__( 'Import', 'emcp-tools' ); ?>"
			data-t-importing="<?php echo esc_attr__( 'Importing…', 'emcp-tools' ); ?>"
			data-t-imported="<?php echo esc_attr__( 'Imported', 'emcp-tools' ); ?>"
			data-t-thissite="<?php echo esc_attr__( 'This site', 'emcp-tools' ); ?>"
			data-t-othersite="<?php echo esc_attr__( 'Another site', 'emcp-tools' ); ?>"
			data-t-empty="<?php echo esc_attr__( 'Nothing in your cloud library yet. Save one from another connected site, then it appears here.', 'emcp-tools' ); ?>"
			data-t-error="<?php echo esc_attr__( 'Could not reach the cloud. Try again.', 'emcp-tools' ); ?>"
			data-t-reloadhint="<?php echo esc_attr__( 'Imported as a new inactive draft below.', 'emcp-tools' ); ?>"
			data-t-reload="<?php echo esc_attr__( 'Reload to view →', 'emcp-tools' ); ?>">
			<summary>
				<span class="dashicons dashicons-cloud" aria-hidden="true"></span>
				<?php
				/* translators: %s: artifact kind, plural (widgets / blocks / snippets). */
				echo esc_html( sprintf( __( 'Cloud Library — import %s from your other connected sites', 'emcp-tools' ), $kl ) );
				?>
				<span class="emcp-sb-disclosure__badge"><?php esc_html_e( 'Cross-site', 'emcp-tools' ); ?></span>
			</summary>
			<div class="emcp-cloud-lib__body" style="margin-top:12px;">
				<p class="emcp-cloud-lib__status description"><?php esc_html_e( 'Open to load your cloud library…', 'emcp-tools' ); ?></p>
				<table class="widefat striped emcp-cloud-lib__table" style="display:none;">
					<thead>
						<tr>
							<th><?php esc_html_e( 'Title', 'emcp-tools' ); ?></th>
							<th><?php esc_html_e( 'From', 'emcp-tools' ); ?></th>
							<th style="width:70px;"><?php esc_html_e( 'Version', 'emcp-tools' ); ?></th>
							<th style="width:110px;"><?php esc_html_e( 'Updated', 'emcp-tools' ); ?></th>
							<th style="width:120px;"></th>
						</tr>
					</thead>
					<tbody></tbody>
				</table>
			</div>
		</details>
		<?php
		return (string) ob_get_clean();
	}

	/**
	 * List the workspace's cloud artifacts of a kind (across all connected sites).
	 * Feeds the Cloud Library panel. AJAX.
	 */
	public function ajax_cloud_library(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'emcp-tools' ) ), 403 );
		}
		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$na   = self::cloud_nonce_action( $kind );
		if ( '' === $na || ! check_ajax_referer( $na, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'emcp-tools' ) ), 403 );
		}
		if ( ! class_exists( 'EMCP_Tools_Cloud_Sync' ) ) {
			wp_send_json_error( array( 'message' => __( 'Cloud is unavailable.', 'emcp-tools' ) ) );
		}
		$res = EMCP_Tools_Cloud_Sync::list_remote( $kind );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		$arts = ( is_array( $res ) && isset( $res['artifacts'] ) && is_array( $res['artifacts'] ) ) ? $res['artifacts'] : array();
		$out  = array();
		foreach ( $arts as $a ) {
			$out[] = array(
				'uuid'        => (string) ( $a['artifact_uuid'] ?? '' ),
				'title'       => (string) ( $a['title'] ?? '' ),
				'version'     => (int) ( $a['version'] ?? 1 ),
				'origin'      => (string) ( $a['origin_site_uuid'] ?? '' ),
				'origin_url'  => (string) ( $a['origin_site_url'] ?? '' ),
				'origin_name' => (string) ( $a['origin_site_name'] ?? '' ),
				'updated'     => (string) ( $a['updated_at'] ?? '' ),
			);
		}
		wp_send_json_success(
			array(
				'artifacts' => $out,
				'site'      => class_exists( 'EMCP_Tools_Cloud' ) ? EMCP_Tools_Cloud::site_uuid() : '',
			)
		);
	}

	/**
	 * Pull one cloud artifact into this site as a new inactive draft. AJAX.
	 * Delegates to EMCP_Tools_Cloud_Sync::pull() (imports the portable bundle).
	 */
	public function ajax_cloud_import(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'emcp-tools' ) ), 403 );
		}
		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$na   = self::cloud_nonce_action( $kind );
		if ( '' === $na || ! check_ajax_referer( $na, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'emcp-tools' ) ), 403 );
		}
		$uuid = isset( $_POST['uuid'] ) ? sanitize_text_field( wp_unslash( $_POST['uuid'] ) ) : '';
		if ( '' === $uuid || ! class_exists( 'EMCP_Tools_Cloud_Sync' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to import.', 'emcp-tools' ) ) );
		}
		$res = EMCP_Tools_Cloud_Sync::pull( $uuid, $kind );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		wp_send_json_success(
			array(
				'id'      => (int) ( $res['id'] ?? 0 ),
				'message' => __( 'Imported as a new inactive draft.', 'emcp-tools' ),
			)
		);
	}

	/** Push an update to an already-published marketplace listing. AJAX. */
	public function ajax_push_update(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'emcp-tools' ) ), 403 );
		}
		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$na   = self::cloud_nonce_action( $kind );
		if ( '' === $na || ! check_ajax_referer( $na, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'emcp-tools' ) ), 403 );
		}
		$id        = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$changelog = isset( $_POST['changelog'] ) ? sanitize_textarea_field( wp_unslash( $_POST['changelog'] ) ) : '';
		if ( ! $id || ! class_exists( 'EMCP_Tools_Cloud_Sync' ) ) {
			wp_send_json_error( array( 'message' => __( 'Nothing to update.', 'emcp-tools' ) ) );
		}
		$res = EMCP_Tools_Cloud_Sync::push_update( $kind, $id, $changelog );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ) );
		}
		self::store_artifact_checksum( $kind, $id );
		self::refresh_marketplace_state( $kind, $id );
		$payload            = self::cloud_action_payload( $kind, $id );
		$payload['message'] = __( 'Update pushed — pending review.', 'emcp-tools' );
		wp_send_json_success( $payload );
	}

	/** Refresh + return an artifact's marketplace state. AJAX (page-load sync). */
	public function ajax_marketplace_state(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'emcp-tools' ) ), 403 );
		}
		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$na   = self::cloud_nonce_action( $kind );
		if ( '' === $na || ! check_ajax_referer( $na, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'emcp-tools' ) ), 403 );
		}
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Missing id.', 'emcp-tools' ) ) );
		}
		self::refresh_marketplace_state( $kind, $id );
		wp_send_json_success( self::cloud_action_payload( $kind, $id ) );
	}

	/**
	 * Full resync of an artifact's cloud state: verify the backup still exists
	 * remotely (self-heals a stale "Saved" after a cloud-side delete) AND refresh
	 * its marketplace listing state. Drives the "Refresh cloud status" button.
	 */
	public function ajax_resync_cloud(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'emcp-tools' ) ), 403 );
		}
		$kind = isset( $_POST['kind'] ) ? sanitize_key( wp_unslash( $_POST['kind'] ) ) : '';
		$na   = self::cloud_nonce_action( $kind );
		if ( '' === $na || ! check_ajax_referer( $na, 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'emcp-tools' ) ), 403 );
		}
		$id = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Missing id.', 'emcp-tools' ) ) );
		}
		self::verify_cloud_backup( $kind, $id );
		self::refresh_marketplace_state( $kind, $id );
		$payload            = self::cloud_action_payload( $kind, $id );
		$payload['message'] = __( 'Cloud status refreshed.', 'emcp-tools' );
		wp_send_json_success( $payload );
	}

	/**
	 * Install a marketplace listing into this site (as a draft artifact).
	 */
	public function handle_marketplace_install(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'emcp-tools' ), '', array( 'response' => 403 ) );
		}
		check_admin_referer( 'emcp_tools_marketplace_install' );
		$slug = isset( $_POST['slug'] ) ? sanitize_title( wp_unslash( $_POST['slug'] ) ) : '';
		$back = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-marketplace' );

		$res = ( '' !== $slug && class_exists( 'EMCP_Tools_Cloud_Sync' ) )
			? EMCP_Tools_Cloud_Sync::marketplace_install( $slug )
			: new \WP_Error( 'bad_request', '' );

		if ( is_wp_error( $res ) ) {
			$data   = $res->get_error_data();
			$status = is_array( $data ) ? (int) ( $data['status'] ?? 0 ) : 0;
			$q      = ( 402 === $status || 'pro_required' === $res->get_error_message() ) ? 'pro' : 'err';
			wp_safe_redirect( add_query_arg( 'mk', $q, $back ) );
			exit;
		}
		$post_id = is_array( $res ) ? (int) ( $res['id'] ?? 0 ) : 0;
		wp_safe_redirect( add_query_arg( array( 'mk' => 'installed', 'mk_id' => $post_id ), $back ) );
		exit;
	}

	/**
	 * Redirect back to the Connection tab after a settings-sync action.
	 *
	 * @param string $status     push|pull|err.
	 * @param string $error_code Optional safe WP_Error code for a useful notice.
	 */
	private function redirect_settings_sync( string $status, string $error_code = '' ): void {
		$back = wp_get_referer();
		if ( ! $back ) {
			$back = admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-connection' );
		}
		$args = array( 'synced' => $status );
		if ( 'err' === $status && '' !== $error_code ) {
			$args['sync_error'] = sanitize_key( $error_code );
		}
		wp_safe_redirect( add_query_arg( $args, $back ) );
		exit;
	}

	/**
	 * Revoke every token issued to an OAuth client (disconnects it).
	 */
	public function handle_revoke_oauth_client(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'emcp-tools' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified just below against the per-client action.
		$client_id = isset( $_GET['client'] ) ? sanitize_text_field( wp_unslash( $_GET['client'] ) ) : '';
		check_admin_referer( self::ACTION_REVOKE_OAUTH . '_' . $client_id );

		if ( '' !== $client_id && class_exists( 'EMCP_Tools_Gateway_Credential' ) ) {
			// Run before revoke_client() below so the gateway teardown observes the
			// still-live token count. (Identity itself survives revoke_client(), which
			// only deletes token rows, not the client registration.)
			EMCP_Tools_Gateway_Credential::handle_client_revoked( $client_id );
		}

		if ( '' !== $client_id && class_exists( 'EMCP_Tools_OAuth_Store' ) ) {
			EMCP_Tools_OAuth_Store::revoke_client( $client_id );
		}

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-connection&oauth_revoked=1#emcp-conn-manage-apps' ) );
		exit;
	}

	/**
	 * Delete an OAuth client registration and every token issued to it.
	 *
	 * The recovery path for a registration an app can no longer use: it asks to
	 * come back at a different callback than the one it registered, so every
	 * authorization attempt is refused and nothing on the client side clears it.
	 * Removing the row here means the next connection attempt registers afresh.
	 *
	 * @since 3.15.0
	 */
	public function handle_delete_oauth_client(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'emcp-tools' ), '', array( 'response' => 403 ) );
		}
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce verified just below against the per-client action.
		$client_id = isset( $_GET['client'] ) ? sanitize_text_field( wp_unslash( $_GET['client'] ) ) : '';
		check_admin_referer( self::ACTION_DELETE_OAUTH_CLIENT . '_' . $client_id );

		if ( '' !== $client_id && class_exists( 'EMCP_Tools_Gateway_Credential' ) ) {
			// Same ordering as the revoke path: the teardown wants to see the
			// token count before the tokens go.
			EMCP_Tools_Gateway_Credential::handle_client_revoked( $client_id );
		}

		$removed = ( '' !== $client_id && class_exists( 'EMCP_Tools_OAuth_Store' ) )
			? EMCP_Tools_OAuth_Store::delete_client( $client_id )
			: false;

		wp_safe_redirect(
			admin_url(
				'admin.php?page=' . self::PAGE_SLUG . '-connection&oauth_removed=' . ( $removed ? '1' : '0' ) . '#emcp-conn-manage-apps'
			)
		);
		exit;
	}

	/**
	 * User meta flag recording that the current user has dismissed the notice
	 * announcing the rewritten (v2) prompt library. Per-user, not per-site, so
	 * one administrator dismissing it does not hide it from the others.
	 *
	 * Suffixed with the library generation: a future rewrite bumps the key and
	 * the notice surfaces again rather than staying permanently dismissed.
	 *
	 * @since 3.2.0
	 */
	const META_PROMPTS_NOTICE_DISMISSED = 'emcp_tools_prompts_v2_notice_dismissed';

	/**
	 * Whether the current user has dismissed the rewritten-prompts notice.
	 *
	 * @since 3.2.0
	 * @return bool
	 */
	public static function prompts_notice_dismissed(): bool {
		return (bool) get_user_meta( get_current_user_id(), self::META_PROMPTS_NOTICE_DISMISSED, true );
	}

	/**
	 * Nonce-protected URL that dismisses the rewritten-prompts notice.
	 *
	 * @since 3.2.0
	 * @return string
	 */
	public static function prompts_notice_dismiss_url(): string {
		return wp_nonce_url(
			admin_url( 'admin-post.php?action=' . self::ACTION_DISMISS_PROMPTS_NOTICE ),
			self::ACTION_DISMISS_PROMPTS_NOTICE
		);
	}

	/**
	 * Persist the dismissal, then bounce back to the Prompts screen.
	 *
	 * @since 3.2.0
	 */
	public function handle_dismiss_prompts_notice(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'emcp-tools' ), '', array( 'response' => 403 ) );
		}

		check_admin_referer( self::ACTION_DISMISS_PROMPTS_NOTICE );

		update_user_meta( get_current_user_id(), self::META_PROMPTS_NOTICE_DISMISSED, '1' );

		wp_safe_redirect( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-prompts' ) );
		exit;
	}

	/**
	 * Option that records which version of the default disabled-tools seeding
	 * has been applied. Stored as an integer-ish string: legacy '1' = the
	 * original Pro-widget defaults; '2' adds the SEO/A11y Pro MCP tools.
	 */
	const OPTION_DEFAULTS_APPLIED = 'emcp_tools_defaults_applied';

	/**
	 * Current defaults-seeding version. Bump when a new batch of slugs should
	 * ship disabled-by-default; add a guarded step in
	 * maybe_apply_default_disabled_tools() for the new version.
	 *
	 * @since 1.8.0
	 */
	const DEFAULTS_VERSION = 37;

	/**
	 * SEO/A11y Pro MCP tool slugs that ship disabled-by-default (v2 defaults).
	 *
	 * @since 1.8.0
	 *
	 * @return string[]
	 */
	public static function seo_a11y_tool_slugs(): array {
		return array(
			'emcp-tools/audit-page-seo',
			'emcp-tools/extract-keywords-from-content',
			'emcp-tools/generate-meta-tags',
			'emcp-tools/generate-schema-markup',
			'emcp-tools/set-social-image',
			'emcp-tools/audit-page-a11y',
			'emcp-tools/fix-color-contrast',
			'emcp-tools/add-alt-text-from-context',
		);
	}

	/**
	 * Widget Builder Pro MCP tool slugs that ship disabled-by-default (v3).
	 *
	 * @since 1.9.0
	 *
	 * @return string[]
	 */
	public static function widget_builder_tool_slugs(): array {
		return array(
			'emcp-tools/list-control-types',
			'emcp-tools/validate-widget-spec',
			'emcp-tools/create-custom-widget',
			'emcp-tools/update-custom-widget',
			'emcp-tools/get-custom-widget',
			'emcp-tools/list-custom-widgets',
			'emcp-tools/set-widget-status',
			'emcp-tools/delete-custom-widget',
		);
	}

	/**
	 * Block Builder Pro MCP tool slugs that ship disabled-by-default (v24).
	 *
	 * @since 3.7.0
	 *
	 * @return string[]
	 */
	public static function block_tool_slugs(): array {
		return array(
			'emcp-tools/list-block-control-types',
			'emcp-tools/validate-block-spec',
			'emcp-tools/create-custom-block',
			'emcp-tools/update-custom-block',
			'emcp-tools/get-custom-block',
			'emcp-tools/list-custom-blocks',
			'emcp-tools/set-block-status',
			'emcp-tools/delete-custom-block',
		);
	}

	/**
	 * Project Memory Pro MCP tool slugs that ship disabled-by-default (v25). The
	 * always-on value (approved-guidance injection) works with these tools off.
	 *
	 * @since 3.7.0
	 *
	 * @return string[]
	 */
	public static function memory_tool_slugs(): array {
		return array(
			'emcp-tools/recall',
			'emcp-tools/remember',
			'emcp-tools/save-session-summary',
		);
	}

	/**
	 * Which internal Sandbox pillar to render. The Sandbox parent page
	 * (?page=emcp-tools-widgets) is a 3-card overview; each pillar's full
	 * management UI lives at ?page=emcp-tools-widgets&view=<pillar> — a route
	 * deliberately not exposed as its own wp-admin menu entry.
	 *
	 * @since 3.7.0
	 *
	 * @return string One of 'overview' | 'blocks' | 'widgets' | 'snippets'.
	 */
	public static function sandbox_view(): string {
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only view switch, no state change.
		$view = isset( $_GET['view'] ) ? sanitize_key( wp_unslash( $_GET['view'] ) ) : 'overview';
		return in_array( $view, array( 'overview', 'blocks', 'widgets', 'snippets' ), true ) ? $view : 'overview';
	}

	/**
	 * The PHP Snippet (Sandbox) tool slugs. Free, but powerful, so they ship
	 * disabled-by-default and the admin opts in on the Tools tab.
	 *
	 * @since 2.1.0
	 *
	 * @return string[]
	 */
	public static function php_snippet_tool_slugs(): array {
		return array(
			'emcp-tools/validate-php-snippet',
			'emcp-tools/create-php-snippet',
			'emcp-tools/update-php-snippet',
			'emcp-tools/get-php-snippet',
			'emcp-tools/list-php-snippets',
			'emcp-tools/delete-php-snippet',
		);
	}

	/**
	 * Themer PHP-template tool slugs. The whole feature is gated behind a master
	 * switch (off by default), and even once enabled these 5 tools ship
	 * disabled-by-default like the PHP Snippets — the admin opts in on the Tools tab.
	 *
	 * @since 3.1.0
	 *
	 * @return string[]
	 */
	/**
	 * The EMCP Themer tool slugs.
	 *
	 * Module-gated: they only register while the Themer module is active, so the
	 * drift guard has to treat their absence as expected rather than as a
	 * renamed or removed tool.
	 *
	 * @since 3.13.0
	 * @return string[]
	 */
	/**
	 * The EMCP Cloud + Marketplace tool slugs.
	 *
	 * Doubly conditional: the Cloud module must be on AND the site must be
	 * connected to an account, so their absence is the normal case and must not
	 * read as drift.
	 *
	 * @since 3.13.0
	 * @return string[]
	 */
	public static function cloud_tool_slugs(): array {
		return array(
			'emcp-tools/cloud-status',
			'emcp-tools/cloud-list',
			'emcp-tools/cloud-backup',
			'emcp-tools/cloud-pull',
			'emcp-tools/cloud-config-sync',
			'emcp-tools/cloud-marketplace-list',
			'emcp-tools/cloud-marketplace-install',
		);
	}

	public static function themer_tool_slugs(): array {
		return array(
			'emcp-tools/create-theme-template',
			'emcp-tools/list-theme-templates',
			'emcp-tools/get-theme-template',
			'emcp-tools/update-theme-template',
			'emcp-tools/set-template-conditions',
			'emcp-tools/delete-theme-template',
			'emcp-tools/resolve-template',
			'emcp-tools/list-condition-targets',
			'emcp-tools/list-dynamic-sources',
		);
	}

	public static function themer_php_tool_slugs(): array {
		return array(
			'emcp-tools/create-theme-php-template',
			'emcp-tools/list-theme-php-templates',
			'emcp-tools/get-theme-php-template',
			'emcp-tools/update-theme-php-template',
			'emcp-tools/delete-theme-php-template',
		);
	}

	/**
	 * The 9 Plugins & Themes mutation tool slugs. Powerful (install/delete/
	 * activate), so they ship disabled-by-default; reads stay enabled. The admin
	 * opts in on the Tools tab.
	 *
	 * @since 3.0.0
	 *
	 * @return string[]
	 */
	public static function package_write_tool_slugs(): array {
		return array(
			'emcp-tools/install-plugin',
			'emcp-tools/activate-plugin',
			'emcp-tools/deactivate-plugin',
			'emcp-tools/update-plugin',
			'emcp-tools/delete-plugin',
			'emcp-tools/install-theme',
			'emcp-tools/switch-theme',
			'emcp-tools/update-theme',
			'emcp-tools/delete-theme',
		);
	}

	/**
	 * Media tool slugs that ship disabled-by-default. Only delete-media (the
	 * destructive, effectively-permanent op); get-media / update-media stay on.
	 *
	 * @since 3.0.0
	 *
	 * @return string[]
	 */
	public static function media_write_tool_slugs(): array {
		return array( 'emcp-tools/delete-media' );
	}

	/**
	 * Users mutation tool slugs that ship disabled-by-default. The reads
	 * (list-users/get-user) stay enabled. The admin opts in on the Tools tab.
	 *
	 * @since 3.0.0
	 *
	 * @return string[]
	 */
	public static function user_write_tool_slugs(): array {
		return array( 'emcp-tools/create-user', 'emcp-tools/update-user' );
	}

	/**
	 * Filesystem mutation tool slugs that ship disabled-by-default. The reads
	 * (read-file/list-directory/search-files) stay enabled.
	 *
	 * @since 3.0.0
	 * @return string[]
	 */
	public static function filesystem_write_tool_slugs(): array {
		return array( 'emcp-tools/write-file', 'emcp-tools/edit-file', 'emcp-tools/delete-file' );
	}

	/**
	 * Database mutation tool slugs that ship disabled-by-default. The reads
	 * (list-tables/describe-table/query) stay enabled.
	 *
	 * @since 3.0.0
	 * @return string[]
	 */
	public static function database_write_tool_slugs(): array {
		return array( 'emcp-tools/insert-row', 'emcp-tools/update-rows', 'emcp-tools/delete-rows' );
	}

	/**
	 * Redirect Manager write tool slugs that ship disabled-by-default. The reads
	 * (list-redirects/find-broken-links) stay enabled. The admin opts in on the
	 * Tools tab.
	 *
	 * @since 3.11.0
	 * @return string[]
	 */
	public static function redirect_tool_slugs(): array {
		return array( 'emcp-tools/create-redirect', 'emcp-tools/update-redirect', 'emcp-tools/delete-redirect' );
	}

	/**
	 * The Backup/Migrate/Sync MCP tool slugs (drift-guard exclusion — the group
	 * only registers when the Migrate module is active + premium). The two
	 * destructive tools (migrate-site/sync-to-live) ship disabled-by-default.
	 *
	 * @since 3.15.0
	 * @return string[]
	 */
	public static function migrate_tool_slugs(): array {
		return array(
			'emcp-tools/create-backup',
			'emcp-tools/list-backups',
			'emcp-tools/migrate-site',
			'emcp-tools/sync-to-live',
			'emcp-tools/list-syncable-changes',
			'emcp-tools/sync-content-item',
			'emcp-tools/discard-sync-change',
		);
	}

	/**
	 * The ACF dispatcher tool slugs. The domain registers as two dispatcher
	 * tools (acf-read enabled by default, acf-write disabled by default); the
	 * 15 operations live behind them. Both slugs are excluded from the drift
	 * guard since the domain only registers when ACF (free or Pro) is active.
	 *
	 * @since 3.2.1
	 * @return string[]
	 */
	public static function acf_tool_slugs(): array {
		return array(
			'emcp-tools/acf-read',
			'emcp-tools/acf-write',
		);
	}

	/**
	 * The WooCommerce integration's dispatcher slugs (drift-guard exclusion).
	 *
	 * @since 3.4.2
	 * @return string[]
	 */
	public static function woo_tool_slugs(): array {
		return array(
			'emcp-tools/woo-read',
			'emcp-tools/woo-write',
		);
	}

	/**
	 * The Meta Box dispatcher tool slugs. The domain registers as two dispatcher
	 * tools (metabox-read enabled by default, metabox-write disabled by default);
	 * the operations live behind them. Both slugs are excluded from the drift
	 * guard since the domain only registers when Meta Box is active.
	 *
	 * @since 3.4.2
	 * @return string[]
	 */
	public static function metabox_tool_slugs(): array {
		return array(
			'emcp-tools/metabox-read',
			'emcp-tools/metabox-write',
		);
	}

	/**
	 * The pre-release per-operation ACF slugs (the earlier 15-tool layout).
	 * Kept only so the defaults step can strip them from the stored option on
	 * sites that seeded them before the 2-dispatcher consolidation.
	 *
	 * @since 3.2.1
	 * @return string[]
	 */
	public static function legacy_acf_operation_slugs(): array {
		return array(
			'emcp-tools/list-acf-field-groups',
			'emcp-tools/get-acf-field-group',
			'emcp-tools/list-acf-options-pages',
			'emcp-tools/get-acf-fields',
			'emcp-tools/update-acf-fields',
			'emcp-tools/create-acf-field-group',
			'emcp-tools/update-acf-field-group',
			'emcp-tools/list-acf-post-types',
			'emcp-tools/get-acf-post-type',
			'emcp-tools/create-acf-post-type',
			'emcp-tools/update-acf-post-type',
			'emcp-tools/list-acf-taxonomies',
			'emcp-tools/get-acf-taxonomy',
			'emcp-tools/create-acf-taxonomy',
			'emcp-tools/update-acf-taxonomy',
		);
	}

	/**
	 * Seeds default disabled-tools on install/upgrade so new Pro tool batches
	 * ship off-by-default (keeping sites under client tool caps), then records
	 * the applied version. Each version step adds ONLY its newly-introduced
	 * slugs, so prior user enable/disable choices are preserved (union merge).
	 *
	 * @since 1.6.0
	 */
	/**
	 * The Themes-domain dispatcher slugs (Active Theme + framework packs). Both
	 * write dispatchers ship disabled-by-default; the per-framework packs are
	 * env-gated (register only when that framework is active). Excluded from the
	 * F-019 drift guard for that reason.
	 *
	 * @since 3.4.0
	 * @return string[]
	 */
	/**
	 * True when BeTheme is the active theme.
	 *
	 * Keys off the template rather than the stylesheet so a child theme of
	 * BeTheme counts, which is how most production BeTheme sites are built.
	 *
	 * @since 3.14.0
	 * @return bool
	 */
	public static function betheme_available(): bool {
		return 'betheme' === strtolower( (string) get_template() );
	}

	public static function theme_tool_slugs(): array {
		return array(
			'emcp-tools/theme-read',
			'emcp-tools/theme-write',
			'emcp-tools/astra-read',
			'emcp-tools/astra-write',
			'emcp-tools/spectra-read',
			'emcp-tools/spectra-write',
			'emcp-tools/kadence-read',
			'emcp-tools/kadence-write',
			'emcp-tools/kadence-blocks-read',
			'emcp-tools/kadence-blocks-write',
			'emcp-tools/generatepress-read',
			'emcp-tools/generatepress-write',
			'emcp-tools/generateblocks-read',
			'emcp-tools/generateblocks-write',
			'emcp-tools/blocksy-blocks-read',
			'emcp-tools/blocksy-blocks-write',
			'emcp-tools/betheme-read',
			'emcp-tools/betheme-write',
			'emcp-tools/blocksy-extensions-read',
			'emcp-tools/blocksy-extensions-write',
		);
	}

	public function maybe_apply_default_disabled_tools(): void {
		$applied = (int) get_option( self::OPTION_DEFAULTS_APPLIED, 0 );
		if ( $applied >= self::DEFAULTS_VERSION ) {
			return;
		}

		$existing = get_option( self::OPTION_DISABLED_TOOLS, array() );
		if ( ! is_array( $existing ) ) {
			$existing = array();
		}

		$add = array();

		// v1 — every Pro-badged tool. Only seeded on a truly fresh install
		// (applied < 1); re-running on an upgrade would clobber user re-enables.
		if ( $applied < 1 ) {
			foreach ( $this->get_all_tools() as $category ) {
				foreach ( $category['tools'] as $slug => $tool ) {
					if ( in_array( 'pro', $tool['badges'], true ) || in_array( 'elementor-pro', $tool['badges'], true ) ) {
						$add[] = $slug;
					}
				}
			}
		}

		// v2 — SEO/A11y Pro MCP tools ship disabled-by-default. Adding only the
		// new slugs means an existing user's other choices survive the upgrade.
		if ( $applied < 2 ) {
			$add = array_merge( $add, self::seo_a11y_tool_slugs() );
		}

		// v3 — Widget Builder Pro MCP tools ship disabled-by-default.
		if ( $applied < 3 ) {
			$add = array_merge( $add, self::widget_builder_tool_slugs() );
		}

		// v4 — PHP Snippet (Sandbox) MCP tools ship disabled-by-default.
		if ( $applied < 4 ) {
			$add = array_merge( $add, self::php_snippet_tool_slugs() );
		}

		// v5 — Widget consolidation (3.0.0). The 62 per-widget Pro slugs seeded
		// disabled in v1 no longer exist; strip them so they don't linger in the
		// stored option. add-pro-widget is a single tool, left ENABLED by default
		// (it only registers when Elementor Pro is active anyway).
		if ( $applied < 5 ) {
			$existing = array_values( array_diff( $existing, self::removed_widget_tool_slugs() ) );
		}

		// v6 — Plugins & Themes mutation tools ship disabled-by-default
		// (powerful: install/activate/deactivate/update/delete). Reads stay on.
		if ( $applied < 6 ) {
			$add = array_merge( $add, self::package_write_tool_slugs() );
		}

		// v7 — delete-media ships disabled-by-default (permanent deletion).
		if ( $applied < 7 ) {
			$add = array_merge( $add, self::media_write_tool_slugs() );
		}

		// v8 — Users mutation tools ship disabled-by-default (account changes).
		if ( $applied < 8 ) {
			$add = array_merge( $add, self::user_write_tool_slugs() );
		}

		// v9 — Filesystem mutation tools ship disabled-by-default (write/edit/delete).
		if ( $applied < 9 ) {
			$add = array_merge( $add, self::filesystem_write_tool_slugs() );
		}

		// v10 — Database mutation tools ship disabled-by-default (insert/update/delete).
		if ( $applied < 10 ) {
			$add = array_merge( $add, self::database_write_tool_slugs() );
		}

		// v11 — Themer PHP-template tools ship disabled-by-default (raw PHP; gated
		// behind the master switch too). The admin opts in on the Tools tab.
		if ( $applied < 11 ) {
			$add = array_merge( $add, self::themer_php_tool_slugs() );
		}

		// v14 — ACF is exposed as two dispatcher tools (acf-read / acf-write).
		// The write dispatcher ships disabled-by-default; the read dispatcher
		// stays on. Also strip any pre-release per-operation ACF slugs left in
		// the stored option from the earlier 15-tool layout. (Supersedes the
		// v12/v13 per-tool ACF seeding, which targeted slugs that no longer
		// exist as individual tools.)
		if ( $applied < 14 ) {
			$existing = array_values( array_diff( $existing, self::legacy_acf_operation_slugs() ) );
			$add[]    = 'emcp-tools/acf-write';
		}

		// v15 — set-social-image (Pro SEO) ships disabled-by-default, consistent
		// with the rest of the SEO/A11y toolkit.
		if ( $applied < 15 ) {
			$add[] = 'emcp-tools/set-social-image';
		}

		// v16 — Themes-domain write dispatchers ship disabled-by-default (theme_mod
		// writes + child-theme creation; per-framework settings writes). Reads on.
		if ( $applied < 16 ) {
			$add[] = 'emcp-tools/theme-write';
			$add[] = 'emcp-tools/astra-write';
		}

		// v17 — Spectra Blocks write dispatcher (add-block) ships disabled-by-default.
		if ( $applied < 17 ) {
			$add[] = 'emcp-tools/spectra-write';
		}

		// v18 — WP-CLI tools (run + background jobs) ship disabled-by-default
		// (command execution surface). All four are off until the admin opts in.
		if ( $applied < 18 ) {
			$add = array_merge( $add, EMCP_Tools_WPCLI_Abilities::slugs() );
		}

		// v19 — WooCommerce + Meta Box write dispatchers ship disabled-by-default.
		// Woo write is the money/PII surface; Meta Box write edits custom-field
		// values. Both read dispatchers stay enabled.
		if ( $applied < 19 ) {
			$add[] = 'emcp-tools/woo-write';
			$add[] = 'emcp-tools/metabox-write';
		}

		// v20 — Forms domain writes ship disabled-by-default (all six plugins).
		// Reads stay enabled; the five Pro reads render locked on free builds via
		// the get_all_tools() Pro-lock post-process.
		if ( $applied < 20 ) {
			$add[] = 'emcp-tools/cf7-write';
			$add[] = 'emcp-tools/wpforms-write';
			$add[] = 'emcp-tools/gravityforms-write';
			$add[] = 'emcp-tools/fluentforms-write';
			$add[] = 'emcp-tools/ninjaforms-write';
			$add[] = 'emcp-tools/formidable-write';
		}

		// v21 — MetForm + SureForms writes disabled-by-default.
		if ( $applied < 21 ) {
			$add[] = 'emcp-tools/metform-write';
			$add[] = 'emcp-tools/sureforms-write';
		}

		// v22 — SEO-plugin writes disabled-by-default (all 7 plugins).
		if ( $applied < 22 ) {
			$add[] = 'emcp-tools/slimseo-write';
			$add[] = 'emcp-tools/yoast-write';
			$add[] = 'emcp-tools/rankmath-write';
			$add[] = 'emcp-tools/aioseo-write';
			$add[] = 'emcp-tools/seopress-write';
			$add[] = 'emcp-tools/seoframework-write';
			$add[] = 'emcp-tools/surerank-write';
		}

		// v23 — Elementor addon domain. Only UAE has a write tool; Essential and
		// Premium Addons are discovery-only (placement stays on add-free-widget),
		// so there is nothing of theirs to disable.
		if ( $applied < 23 ) {
			$add[] = 'emcp-tools/uae-write';
		}

		// v24 — Block Builder Pro MCP tools ship disabled-by-default (author executable
		// block code; same posture as the Widget Builder + PHP Snippets).
		if ( $applied < 24 ) {
			$add = array_merge( $add, self::block_tool_slugs() );
		}

		// v25 — Project Memory Pro MCP tools ship disabled-by-default. The always-on
		// value (approved-guidance injection) works with the tools off.
		if ( $applied < 25 ) {
			$add = array_merge( $add, self::memory_tool_slugs() );
		}

		// v26 — Forminator write (delete-entry) disabled-by-default.
		if ( $applied < 26 ) {
			$add[] = 'emcp-tools/forminator-write';
		}

		// v27 — Kadence theme + Kadence Blocks write dispatchers disabled-by-default.
		if ( $applied < 27 ) {
			$add[] = 'emcp-tools/kadence-write';
			$add[] = 'emcp-tools/kadence-blocks-write';
		}

		// v28 — Elementor v4 Global Class write tools disabled-by-default.
		if ( $applied < 28 ) {
			$add[] = 'emcp-tools/create-global-class';
			$add[] = 'emcp-tools/update-global-class';
			$add[] = 'emcp-tools/delete-global-class';
		}

		// v29 — reorder-global-classes write tool disabled-by-default.
		if ( $applied < 29 ) {
			$add[] = 'emcp-tools/reorder-global-classes';
		}

		// v30 — GeneratePress + GenerateBlocks write dispatchers disabled-by-default.
		if ( $applied < 30 ) {
			$add[] = 'emcp-tools/generatepress-write';
			$add[] = 'emcp-tools/generateblocks-write';
		}

		// v31 — Blocksy write dispatchers disabled-by-default.
		if ( $applied < 31 ) {
			$add[] = 'emcp-tools/blocksy-blocks-write';
			$add[] = 'emcp-tools/blocksy-extensions-write';
		}

		// v32 — Redirect Manager write tools ship disabled-by-default (create/
		// update/delete a redirect). The reads (list-redirects/find-broken-links)
		// stay enabled. The admin opts in on the Tools tab.
		if ( $applied < 32 ) {
			$add = array_merge( $add, self::redirect_tool_slugs() );
		}

		// v33 — Backup/Migrate/Sync destructive MCP tools ship disabled-by-default
		// (migrate-site/sync-to-live push to and overwrite a live target). The
		// reads (create-backup/list-backups) stay enabled.
		if ( $applied < 33 ) {
			$add[] = 'emcp-tools/migrate-site';
			$add[] = 'emcp-tools/sync-to-live';
		}

		// v34 — the content-sync push tool overwrites an item on the live site, so
		// it ships disabled-by-default. The list + discard reads stay enabled.
		if ( $applied < 34 ) {
			$add[] = 'emcp-tools/sync-content-item';
		}

		// v35 — BeTheme write disabled-by-default, matching the other theme write
		// dispatchers. It changes global theme settings and can replace a page's
		// whole BeBuilder content, so an admin opts in on the Tools tab.
		if ( $applied < 35 ) {
			$add[] = 'emcp-tools/betheme-write';
		}

		// v36 — Elementor Global Variables mutate the site-wide design-token
		// system, so writes ship disabled-by-default. Listing stays enabled.
		if ( $applied < 36 ) {
			$add[] = 'emcp-tools/create-variable';
			$add[] = 'emcp-tools/update-variable';
			$add[] = 'emcp-tools/delete-variable';
			$add[] = 'emcp-tools/restore-variable';
			$add[] = 'emcp-tools/batch-variables';
		}

		// v37 — Cache regeneration can affect site-wide rendering; require opt-in.
		if ( $applied < 37 ) {
			$add[] = 'emcp-tools/regenerate-css';
		}

		$merged = array_values( array_unique( array_merge( $existing, $add ) ) );
		update_option( self::OPTION_DISABLED_TOOLS, $merged );
		update_option( self::OPTION_DEFAULTS_APPLIED, (string) self::DEFAULTS_VERSION );
	}

	/**
	 * The per-widget convenience tool slugs removed in 3.0.0 (widget
	 * consolidation). Used by the v5 defaults step to clear orphaned disabled
	 * entries from the stored option.
	 *
	 * @since 3.0.0
	 *
	 * @return string[]
	 */
	public static function removed_widget_tool_slugs(): array {
		return array(
			'emcp-tools/add-widget',
			'emcp-tools/add-heading', 'emcp-tools/add-text-editor', 'emcp-tools/add-image',
			'emcp-tools/add-button', 'emcp-tools/add-video', 'emcp-tools/add-icon',
			'emcp-tools/add-spacer', 'emcp-tools/add-divider', 'emcp-tools/add-icon-box',
			'emcp-tools/add-accordion', 'emcp-tools/add-alert', 'emcp-tools/add-counter',
			'emcp-tools/add-google-maps', 'emcp-tools/add-icon-list', 'emcp-tools/add-image-box',
			'emcp-tools/add-image-carousel', 'emcp-tools/add-progress', 'emcp-tools/add-social-icons',
			'emcp-tools/add-star-rating', 'emcp-tools/add-tabs', 'emcp-tools/add-testimonial',
			'emcp-tools/add-toggle', 'emcp-tools/add-html', 'emcp-tools/add-menu-anchor',
			'emcp-tools/add-shortcode', 'emcp-tools/add-rating', 'emcp-tools/add-text-path',
			'emcp-tools/add-form', 'emcp-tools/add-posts-grid', 'emcp-tools/add-countdown',
			'emcp-tools/add-price-table', 'emcp-tools/add-flip-box', 'emcp-tools/add-animated-headline',
			'emcp-tools/add-call-to-action', 'emcp-tools/add-slides', 'emcp-tools/add-testimonial-carousel',
			'emcp-tools/add-price-list', 'emcp-tools/add-gallery', 'emcp-tools/add-share-buttons',
			'emcp-tools/add-table-of-contents', 'emcp-tools/add-blockquote', 'emcp-tools/add-lottie',
			'emcp-tools/add-hotspot', 'emcp-tools/add-nav-menu', 'emcp-tools/add-loop-grid',
			'emcp-tools/add-loop-carousel', 'emcp-tools/add-media-carousel', 'emcp-tools/add-nested-tabs',
			'emcp-tools/add-nested-accordion', 'emcp-tools/add-portfolio', 'emcp-tools/add-author-box',
			'emcp-tools/add-login', 'emcp-tools/add-code-highlight', 'emcp-tools/add-reviews',
			'emcp-tools/add-off-canvas', 'emcp-tools/add-progress-tracker', 'emcp-tools/add-search',
			'emcp-tools/add-wc-products', 'emcp-tools/add-wc-add-to-cart', 'emcp-tools/add-wc-cart',
			'emcp-tools/add-wc-checkout', 'emcp-tools/add-wc-menu-cart',
		);
	}

	/**
	 * Add the settings page under the Settings menu.
	 *
	 * @since 1.0.0
	 */
	public function add_settings_page(): void {
		$this->hook_suffixes[] = add_menu_page(
			__( 'MCP Tools for Elementor', 'emcp-tools' ),
			__( 'EMCP Tools', 'emcp-tools' ),
			'manage_options',
			self::PAGE_SLUG,
			array( $this, 'render_page' ),
			EMCP_TOOLS_URL . 'assets/img/icon-xs.png',
			58
		);

		foreach ( $this->get_submenus() as $slug => $label ) {
			$menu_title = $label;
			// Native WordPress count bubble on the Memory submenu for pending proposals.
			if ( self::PAGE_SLUG . '-memory' === $slug ) {
				$pending = $this->memory_pending_count();
				if ( $pending > 0 ) {
					$menu_title = $label . ' <span class="awaiting-mod"><span class="pending-count" aria-hidden="true">' . (int) $pending . '</span></span>';
				}
			}
			$this->hook_suffixes[] = add_submenu_page(
				self::PAGE_SLUG,
				$label,
				$menu_title,
				'manage_options',
				$slug,
				array( $this, 'render_page' )
			);
		}

		// Changelog is surfaced as an app-bar button in the header, not the
		// sidebar. We deliberately do NOT remove_submenu_page() it: that drops
		// the page from $submenu, which breaks both user_can_access_admin_page()
		// (parent no longer resolves) and the render hook (admin.php recomputes
		// the page hook to a name with no attached callback → "Cannot load").
		// Instead the sidebar <li> is hidden with CSS in print_menu_icon_style(),
		// so the page stays a normal, fully-renderable submenu reachable by URL.
	}

	/**
	 * Print a tiny inline style on every admin page that constrains our menu
	 * icon to native-dashicon dimensions.
	 *
	 * WordPress renders a PNG menu icon at its natural size, which makes our
	 * 64×64 brand icon overflow the 34px-tall sidebar row. The native dashicon
	 * box is 20×20 with a small vertical inset — replicating that here keeps
	 * the icon visually aligned with Posts/Pages/etc. We inject globally
	 * (not via the EMCP page enqueue) because the WP sidebar shows on every
	 * admin screen, not just ours.
	 *
	 * @since 1.7.2
	 */
	public function print_menu_icon_style(): void {
		echo '<style>'
			. '#toplevel_page_' . esc_attr( self::PAGE_SLUG ) . ' .wp-menu-image img{'
			. 'width:20px;height:20px;padding:7px 0 0;object-fit:contain;opacity:.95;'
			. '}'
			. '#toplevel_page_' . esc_attr( self::PAGE_SLUG ) . ':hover .wp-menu-image img,'
			. '#toplevel_page_' . esc_attr( self::PAGE_SLUG ) . '.current .wp-menu-image img,'
			. '#toplevel_page_' . esc_attr( self::PAGE_SLUG ) . '.wp-has-current-submenu .wp-menu-image img{'
			. 'opacity:1;'
			. '}'
			// Changelog lives in the header app-bar, not the sidebar. It stays a
			// real submenu (so it renders + is URL-accessible); we only hide its
			// sidebar row. :has() hides the whole <li>; the anchor rule is a
			// fallback for browsers without :has() (collapses the row to 0).
			. '#toplevel_page_' . esc_attr( self::PAGE_SLUG ) . ' .wp-submenu li:has(> a[href$="page=' . esc_attr( self::PAGE_SLUG ) . '-changelog"]),'
			. '#toplevel_page_' . esc_attr( self::PAGE_SLUG ) . ' .wp-submenu a[href$="page=' . esc_attr( self::PAGE_SLUG ) . '-changelog"],'
			// History also lives in the app-bar (next to Changelog), not the sidebar.
			. '#toplevel_page_' . esc_attr( self::PAGE_SLUG ) . ' .wp-submenu li:has(> a[href$="page=' . esc_attr( self::PAGE_SLUG ) . '-history"]),'
			. '#toplevel_page_' . esc_attr( self::PAGE_SLUG ) . ' .wp-submenu a[href$="page=' . esc_attr( self::PAGE_SLUG ) . '-history"]{'
			. 'display:none !important;'
			. '}'
			. '</style>';
	}

	/**
	 * Register the settings with the WordPress Settings API.
	 *
	 * @since 1.0.0
	 */
	public function register_settings(): void {
		register_setting(
			self::SETTINGS_GROUP,
			self::OPTION_DISABLED_TOOLS,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => array( $this, 'sanitize_disabled_tools' ),
			)
		);

		// Compact tool mode (dispatcher) — Tools tab. OFF by default; surfaces 3
		// meta-tools (list-tools / get-tool-schema / call-tool) instead of every
		// individual tool for clients that cap the tool count. Registered under the
		// Tools form group so its toggle lives alongside the per-tool grid.
		register_setting(
			self::SETTINGS_GROUP,
			EMCP_Tools_Plugin::OPTION_DISPATCHER_MODE,
			array(
				'type'              => 'string',
				'default'           => '0',
				'sanitize_callback' => static function ( $value ) {
					return '1' === (string) $value ? '1' : '0';
				},
			)
		);

		// Themer PHP Templates master switch (Tools tab). Off by default — the
		// feature lets AI author raw PHP region templates, so the admin opts in.
		register_setting(
			self::SETTINGS_GROUP,
			EMCP_Tools_Themer_PHP::OPTION_ENABLED,
			array(
				'type'              => 'string',
				'default'           => '0',
				'sanitize_callback' => static function ( $value ) {
					return '1' === (string) $value ? '1' : '0';
				},
			)
		);

		// Content mirror auto-export (Tools tab). Off by default — when on, saving an
		// Elementor page/template also writes its JSON to uploads/emcp-content-mirror/
		// for external version control. The MCP export/restore tools work regardless.
		register_setting(
			self::SETTINGS_GROUP,
			EMCP_Tools_Content_Mirror::OPTION_ENABLED,
			array(
				'type'              => 'string',
				'default'           => '0',
				'sanitize_callback' => static function ( $value ) {
					return '1' === (string) $value ? '1' : '0';
				},
			)
		);

		// "Activate Abilities API for EMCP" server gate (Connection tab). On by
		// default; an absent checkbox on submit sanitizes to '0' (off).
		register_setting(
			self::SETTINGS_GROUP_SERVER,
			EMCP_Tools_Plugin::OPTION_SERVER_ENABLED,
			array(
				'type'              => 'string',
				'default'           => '1',
				'sanitize_callback' => static function ( $value ) {
					return '1' === (string) $value ? '1' : '0';
				},
			)
		);

		// OAuth sign-in for MCP clients (Connection tab). No stored default — the
		// effective default is "on when HTTPS", enforced by
		// EMCP_Tools_OAuth_Server (is_available). The form posts a hidden 0 +
		// checkbox 1 so an unchecked box saves '0'.
		register_setting(
			self::SETTINGS_GROUP_SERVER,
			EMCP_Tools_OAuth_Server::OPTION_ENABLED,
			array(
				'type'              => 'string',
				'sanitize_callback' => static function ( $value ) {
					return '1' === (string) $value ? '1' : '0';
				},
			)
		);

		// OpenAI-strict tool schemas (Connection tab). OFF by default — it's only
		// for OpenAI-compatible strict function-calling clients (CrewAI, etc.) and
		// would otherwise break Gemini/Antigravity. (GitHub #42)
		register_setting(
			self::SETTINGS_GROUP_SERVER,
			'emcp_tools_strict_schemas',
			array(
				'type'              => 'string',
				'default'           => '0',
				'sanitize_callback' => static function ( $value ) {
					return '1' === (string) $value ? '1' : '0';
				},
			)
		);

		// Server URL override (Connection tab). Empty = auto-detect from the REST
		// API. Set it when the site is served on a different URL than WordPress's
		// configured Site Address (e.g. staging with a pinned domain) so the
		// bundle / OAuth / configs use the reachable host. Accepts only http(s).
		register_setting(
			self::SETTINGS_GROUP_SERVER,
			EMCP_Tools_Site_Context::OPTION_BASE_URL,
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => static function ( $value ) {
					$value = trim( (string) $value );
					if ( '' === $value ) {
						return '';
					}
					$value  = esc_url_raw( $value );
					$scheme = wp_parse_url( $value, PHP_URL_SCHEME );
					if ( ! in_array( $scheme, array( 'http', 'https' ), true ) ) {
						return '';
					}
					return rtrim( $value, '/' );
				},
			)
		);

		// WP-CLI base command (Connection → 3rd Party Services) — the `wp` launcher
		// used for the shell / background-job path over HTTP (e.g. "wp" or
		// "php /path/to/wp-cli.phar"). Empty = in-process only (WP-CLI stdio).
		register_setting(
			self::SETTINGS_GROUP_SERVICES,
			'emcp_tools_wpcli_command',
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => static function ( $value ) {
					return sanitize_text_field( (string) $value );
				},
			)
		);

		// Stock-image provider API keys (Connection → 3rd Party Services sub-tab)
		// — power the stock-image tools (search-images / add-stock-image). All
		// three are free keys. Registered in their own group so that sub-tab's
		// form saves without touching the server-gate toggles. Keys are stored
		// encrypted at rest (EMCP_Tools_Secret) and never rendered back to the
		// form: the field posts empty when unchanged (we keep the stored value),
		// a per-field "__clear" checkbox removes it, and a new value is encrypted.
		foreach ( array( EMCP_Tools_Unsplash_Client::OPTION, EMCP_Tools_Pexels_Client::OPTION, EMCP_Tools_Pixabay_Client::OPTION ) as $emcp_stock_option ) {
			register_setting(
				self::SETTINGS_GROUP_SERVICES,
				$emcp_stock_option,
				array(
					'type'              => 'string',
					'default'           => '',
					'sanitize_callback' => static function ( $value ) use ( $emcp_stock_option ) {
						// phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php verifies the settings-group nonce before this runs.
						if ( ! empty( $_POST[ $emcp_stock_option . '__clear' ] ) ) {
							return '';
						}
						$value = sanitize_text_field( (string) $value );
						if ( '' === $value ) {
							// Unchanged (masked) submit — keep the stored value.
							return (string) get_option( $emcp_stock_option, '' );
						}
						// The Settings API can run this callback twice per save;
						// don't re-encrypt an already-encrypted token (would nest).
						if ( EMCP_Tools_Secret::is_encrypted( $value ) ) {
							return $value;
						}
						return EMCP_Tools_Secret::encrypt( $value );
					},
				)
			);
		}

		// Context page — the site-wide guidance + its on/off toggle.
		register_setting(
			self::SETTINGS_GROUP_CONTEXT,
			EMCP_Tools_Site_Context::OPTION_CONTEXT,
			array(
				'type'              => 'string',
				'default'           => '',
				'sanitize_callback' => static function ( $value ) {
					$value = sanitize_textarea_field( (string) $value );
					return mb_substr( $value, 0, EMCP_Tools_Site_Context::MAX_CHARS );
				},
			)
		);
		register_setting(
			self::SETTINGS_GROUP_CONTEXT,
			EMCP_Tools_Site_Context::OPTION_ENABLED,
			array(
				'type'              => 'string',
				'default'           => '1',
				'sanitize_callback' => static function ( $value ) {
					return '1' === (string) $value ? '1' : '0';
				},
			)
		);

		// Modules tab — the active-modules list + each registered module's own
		// option keys (declared by the module's settings_fields()).
		register_setting(
			self::SETTINGS_GROUP_MODULES,
			EMCP_Tools_Module::OPTION_ACTIVE,
			array(
				'type'              => 'array',
				'default'           => array(),
				'sanitize_callback' => static function ( $value ) {
					$value = is_array( $value ) ? $value : array();
					return array_values( array_map( 'sanitize_key', $value ) );
				},
			)
		);
		if ( class_exists( 'EMCP_Tools_Modules_Registry' ) ) {
			foreach ( EMCP_Tools_Modules_Registry::instance()->all() as $emcp_module ) {
				// Each module's keys live in the module's own group so its overlay
				// settings form saves independently of the active-modules toggles.
				$emcp_group = $emcp_module->settings_group();
				foreach ( $emcp_module->settings_fields() as $emcp_key => $emcp_args ) {
					register_setting( $emcp_group, $emcp_key, $emcp_args );
				}
			}
		}
	}

	/**
	 * Sanitize the disabled tools option value.
	 *
	 * The form submits an array of enabled tool slugs. We compute the
	 * disabled list as the difference between all known tools and the
	 * enabled ones submitted.
	 *
	 * @since 1.0.0
	 *
	 * @param mixed $input The raw form input.
	 * @return string[] Sanitized array of disabled tool slugs.
	 */
	public function sanitize_disabled_tools( $input ): array {
		$all_tools = $this->get_all_tool_slugs();

		// Only when the Tools settings form is being submitted do we INVERT the
		// posted "enabled" checkboxes into a disabled list. We read the enabled
		// set straight from $_POST (not from $input) so this callback is
		// IDEMPOTENT: WordPress re-runs sanitize_option a second time via
		// add_option() the first time the option is created, and inverting
		// $input twice would zero the result (all -> none). It also keeps
		// programmatic update_option() calls (e.g. the default-disabled seeder)
		// from being inverted at all.
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- options.php verifies the settings nonce before sanitization runs.
		$is_settings_form = isset( $_POST['option_page'] )
			&& self::SETTINGS_GROUP === sanitize_text_field( wp_unslash( $_POST['option_page'] ) );

		if ( $is_settings_form ) {
			$enabled = array();
			// phpcs:ignore WordPress.Security.NonceVerification.Missing
			if ( isset( $_POST[ self::OPTION_DISABLED_TOOLS ] ) && is_array( $_POST[ self::OPTION_DISABLED_TOOLS ] ) ) {
				// phpcs:ignore WordPress.Security.NonceVerification.Missing
				$enabled = array_map( 'sanitize_text_field', wp_unslash( $_POST[ self::OPTION_DISABLED_TOOLS ] ) );
			}
			// Disabled = all tools minus the ones that were checked (enabled).
			return array_values( array_diff( $all_tools, $enabled ) );
		}

		// Any other context: $input is already the final disabled list (e.g. the
		// default-disabled seeder). Clean against the known slugs and return —
		// this is idempotent, so a second sanitize pass leaves it unchanged.
		if ( ! is_array( $input ) ) {
			return array();
		}
		return array_values( array_intersect( $all_tools, array_map( 'sanitize_text_field', $input ) ) );
	}

	/**
	 * Enqueue admin CSS on our settings page only.
	 *
	 * @since 1.0.0
	 *
	 * @param string $hook The current admin page hook.
	 */
	public function enqueue_assets( string $hook ): void {
		if ( ! in_array( $hook, $this->hook_suffixes, true ) ) {
			return;
		}

		$css_path = EMCP_TOOLS_DIR . 'assets/css/admin.css';
		$js_path  = EMCP_TOOLS_DIR . 'assets/js/admin.js';

		// Some security software and hosts rename or quarantine .js files on
		// upload (admin.js -> admin.j_), which makes the script 404 and silently
		// breaks JS-driven features like the Connection-tab config generator. If
		// the asset is missing, warn the admin with an actionable fix instead of
		// failing silently. (GitHub #44)
		if ( ! file_exists( $js_path ) ) {
			add_action( 'admin_notices', array( $this, 'notice_missing_js_asset' ) );
		}

		// Use filemtime in dev (when WP_DEBUG is on) so iterating on CSS/JS doesn't get stuck
		// behind a cached file under the same plugin version. Falls back to EMCP_TOOLS_VERSION.
		$css_ver = ( defined( 'WP_DEBUG' ) && WP_DEBUG && file_exists( $css_path ) ) ? filemtime( $css_path ) : EMCP_TOOLS_VERSION;
		$js_ver  = ( defined( 'WP_DEBUG' ) && WP_DEBUG && file_exists( $js_path ) ) ? filemtime( $js_path ) : EMCP_TOOLS_VERSION;

		if ( file_exists( $css_path ) ) {
			wp_enqueue_style(
				'elementor-mcp-admin',
				EMCP_TOOLS_URL . 'assets/css/admin.css',
				array(),
				$css_ver
			);
		}

		// No script on disk -> nothing to enqueue or localize (the notice above
		// tells the admin how to fix it).
		if ( ! file_exists( $js_path ) ) {
			return;
		}

		wp_enqueue_script(
			'elementor-mcp-admin',
			EMCP_TOOLS_URL . 'assets/js/admin.js',
			array(),
			$js_ver,
			true
		);

		// Sandbox cloud/marketplace button state machine (no-op unless the page
		// renders .emcp-sb-cloud clusters).
		$sb_js = EMCP_TOOLS_DIR . 'assets/js/sandbox-cloud.js';
		if ( file_exists( $sb_js ) ) {
			wp_enqueue_script( 'emcp-tools-sandbox-cloud', EMCP_TOOLS_URL . 'assets/js/sandbox-cloud.js', array(), (string) filemtime( $sb_js ), true );
		}

		// Cloud Library: lazy list + import of the workspace's cloud artifacts
		// (no-op unless the page renders a .emcp-cloud-lib panel).
		$cl_js = EMCP_TOOLS_DIR . 'assets/js/cloud-library.js';
		if ( file_exists( $cl_js ) ) {
			wp_enqueue_script( 'emcp-tools-cloud-library', EMCP_TOOLS_URL . 'assets/js/cloud-library.js', array(), (string) filemtime( $cl_js ), true );
		}

		wp_localize_script(
			'elementor-mcp-admin',
			'emcpToolsAdmin',
			array(
				'copied'      => __( 'Copied!', 'emcp-tools' ),
				'copy'        => __( 'Copy', 'emcp-tools' ),
				'copyFailed'  => __( 'Copy failed', 'emcp-tools' ),
				'download'    => __( 'Download', 'emcp-tools' ),
				'mcpEndpoint' => class_exists( 'EMCP_Tools_Site_Context' ) ? EMCP_Tools_Site_Context::mcp_endpoint() : rest_url( 'mcp/emcp-tools-server' ),
				'oauthEnabled' => class_exists( 'EMCP_Tools_OAuth_Server' ) && EMCP_Tools_OAuth_Server::is_enabled(),
				'oauthSignin'  => __( 'The next time your AI client connects, your browser opens so you can authorize it. Approve to finish connecting.', 'emcp-tools' ),
				/* translators: %s: client label */
				'genFirst'     => __( 'Generate your credentials above, the config for %s then appears here.', 'emcp-tools' ),
				'siteUrl'     => class_exists( 'EMCP_Tools_Site_Context' ) ? EMCP_Tools_Site_Context::public_base_url() : site_url(),
				// Only the filename — never the absolute server path. The proxy runs
				// on the CLIENT machine, so the server path is both useless to the
				// user and a needless path disclosure (F-020). The UI points users at
				// the npx runner or their own local copy of the proxy.
				'proxyPath'   => 'mcp-proxy.mjs',
				// Full MCP connection + OAuth discovery diagnostics.
				'authTesting' => __( 'Testing the full MCP handshake…', 'emcp-tools' ),
				'authOk'      => __( '✓ Full MCP handshake succeeded: initialize, initialized notification, and tools/list all worked.', 'emcp-tools' ),
				'authError'   => __( 'Could not run the MCP connection test.', 'emcp-tools' ),
				'oauthTesting' => __( 'Checking public OAuth discovery endpoints…', 'emcp-tools' ),
				'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
				'createPwNonce' => wp_create_nonce( 'emcp_tools_create_app_password' ),
				'testConnNonce' => wp_create_nonce( 'emcp_tools_test_connection' ),
				'testOAuthNonce' => wp_create_nonce( 'emcp_tools_test_oauth_discovery' ),
				'trackPromptNonce' => wp_create_nonce( 'emcp_tools_track_prompt_copy' ),
				'generating'    => __( 'Generating…', 'emcp-tools' ),
				'pwCreated'     => __( 'Application password created, save it below, it is shown only once.', 'emcp-tools' ),
				'syncing'       => __( 'Syncing…', 'emcp-tools' ),
				// Brand Kits.
				'applying'      => __( 'Applying…', 'emcp-tools' ),
				'restoring'     => __( 'Restoring…', 'emcp-tools' ),
				/* translators: %s: brand kit title */
				'applyKitTitle' => __( 'Apply "%s" brand kit?', 'emcp-tools' ),
				/* translators: %s: brand kit title */
				'kitApplied'    => __( '%s applied.', 'emcp-tools' ),
				'restoreConfirm'     => __( 'Restore global colors and typography from this backup?', 'emcp-tools' ),
				'viewSite'           => __( 'View site →', 'emcp-tools' ),
				// Connection-tab client picker + .mcpb bundle.
				'connectionClients'  => self::connection_clients(),
				'mcpbNonce'          => wp_create_nonce( self::NONCE_DOWNLOAD_MCPB ),
				'adminPostUrl'       => admin_url( 'admin-post.php' ),
				'siteContextBase'      => EMCP_Tools_Site_Context::default_base(),
				'siteContextDelimiter' => EMCP_Tools_Site_Context::DELIMITER,
			)
		);

		// Modules tab: the bulk-optimizer progress UI.
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- read-only page routing.
		if ( isset( $_GET['page'] ) && ( self::PAGE_SLUG . '-modules' ) === sanitize_key( wp_unslash( $_GET['page'] ) ) ) {
			$bulk_path = EMCP_TOOLS_DIR . 'assets/js/modules-bulk.js';
			if ( file_exists( $bulk_path ) && class_exists( 'EMCP_Tools_Bulk_Optimizer' ) ) {
				$bulk_ver = ( defined( 'WP_DEBUG' ) && WP_DEBUG ) ? filemtime( $bulk_path ) : EMCP_TOOLS_VERSION;
				wp_enqueue_script( 'emcp-tools-modules-bulk', EMCP_TOOLS_URL . 'assets/js/modules-bulk.js', array(), $bulk_ver, true );
				wp_localize_script(
					'emcp-tools-modules-bulk',
					'emcpToolsModules',
					array(
						'ajaxUrl'       => admin_url( 'admin-ajax.php' ),
						'nonce'         => wp_create_nonce( EMCP_Tools_Bulk_Optimizer::NONCE ),
						'batchAction'   => EMCP_Tools_Bulk_Optimizer::ACTION_BATCH,
						'restoreAction' => EMCP_Tools_Bulk_Optimizer::ACTION_RESTORE,
						'batchSize'     => 10,
						'optimizing'    => __( 'Optimizing…', 'emcp-tools' ),
						'restoring'     => __( 'Restoring…', 'emcp-tools' ),
						'done'          => __( 'Done', 'emcp-tools' ),
						'unsaved'       => __( 'Unsaved changes, click Save Modules to apply.', 'emcp-tools' ),
					)
				);
			}
		}
	}

	/**
	 * Admin notice shown when assets/js/admin.js is missing from the plugin
	 * folder — usually because security software or a host renamed/quarantined
	 * the .js file on upload (e.g. admin.js -> admin.j_). Without it, JS-driven
	 * features (the Connection-tab config generator, tool toggles, etc.) silently
	 * do nothing, so we surface a precise, actionable message. (GitHub #44)
	 *
	 * @since 2.1.0
	 */
	public function notice_missing_js_asset(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		// Detect a mangled copy so we can name the exact file to restore.
		$dir     = EMCP_TOOLS_DIR . 'assets/js/';
		$mangled = '';
		foreach ( array( 'admin.j_', 'admin.js_', 'admin._s', 'admin.js.quarantine' ) as $candidate ) {
			if ( file_exists( $dir . $candidate ) ) {
				$mangled = $candidate;
				break;
			}
		}

		echo '<div class="notice notice-error"><p><strong>EMCP Tools:</strong> ';
		echo esc_html__( 'A required script is missing, assets/js/admin.js was not found in the plugin folder, so admin features like the Connection-tab config generator will not work.', 'emcp-tools' );
		echo ' ';
		if ( '' !== $mangled ) {
			printf(
				/* translators: %s: the mangled filename found, e.g. admin.j_ */
				esc_html__( 'It looks like security software renamed it to assets/js/%s, rename that file back to admin.js.', 'emcp-tools' ),
				esc_html( $mangled )
			);
		} else {
			echo esc_html__( 'Some security software and hosts rename or quarantine .js files on upload. Re-upload a fresh copy of the plugin from the official release, and restore assets/js/admin.js if your host renamed it.', 'emcp-tools' );
		}
		echo '</p></div>';
	}

	/**
	 * AJAX: create a fresh Application Password for a chosen administrator.
	 *
	 * Returns the chunked plaintext password once so the Connection tab can drop
	 * it straight into the generated client configs — no profile visit needed.
	 *
	 * @since 1.8.3
	 */
	public function ajax_create_app_password(): void {
		check_ajax_referer( 'emcp_tools_create_app_password', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'emcp-tools' ) ), 403 );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		if ( ! $user_id ) {
			wp_send_json_error( array( 'message' => __( 'No user selected.', 'emcp-tools' ) ), 400 );
		}

		$user = get_userdata( $user_id );
		if ( ! $user ) {
			wp_send_json_error( array( 'message' => __( 'That user no longer exists.', 'emcp-tools' ) ), 404 );
		}

		// Only administrators, and only those the current user is allowed to edit.
		if ( ! user_can( $user, 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Application passwords can only be generated for administrator accounts here.', 'emcp-tools' ) ), 403 );
		}
		if ( ! current_user_can( 'edit_user', $user_id ) ) {
			wp_send_json_error( array( 'message' => __( 'You cannot manage application passwords for this user.', 'emcp-tools' ) ), 403 );
		}

		if ( ! class_exists( 'WP_Application_Passwords' ) ) {
			wp_send_json_error( array( 'message' => __( 'Application Passwords are not supported on this WordPress version.', 'emcp-tools' ) ), 400 );
		}

		// Respect WordPress core and site-policy availability filters. A security
		// plugin may disable application passwords globally or for this user even
		// when the core class exists.
		if ( function_exists( 'wp_is_application_passwords_available' ) && ! wp_is_application_passwords_available() ) {
			wp_send_json_error(
				array(
					'message' => __( 'Application Passwords are disabled for this site. Check HTTPS and any security-plugin policy, or use OAuth.', 'emcp-tools' ),
				),
				400
			);
		}
		if ( function_exists( 'wp_is_application_passwords_available_for_user' ) && ! wp_is_application_passwords_available_for_user( $user ) ) {
			wp_send_json_error( array( 'message' => __( 'Application Passwords are disabled for this user by site policy.', 'emcp-tools' ) ), 400 );
		}

		// Compatibility fallback for WordPress versions without the availability
		// helper (the plugin normally requires a newer core release).
		if ( ! function_exists( 'wp_is_application_passwords_available' ) && ! is_ssl() && 'local' !== wp_get_environment_type() ) {
			wp_send_json_error( array( 'message' => __( 'Application Passwords require HTTPS.', 'emcp-tools' ) ), 400 );
		}

		$app_name = sprintf(
			/* translators: %s: current date and time */
			__( 'EMCP Tools (MCP), %s', 'emcp-tools' ),
			gmdate( 'Y-m-d H:i' )
		);

		$created = \WP_Application_Passwords::create_new_application_password( $user_id, array( 'name' => $app_name ) );

		if ( is_wp_error( $created ) ) {
			wp_send_json_error( array( 'message' => $created->get_error_message() ), 400 );
		}

		$raw_password = isset( $created[0] ) ? $created[0] : '';
		if ( '' === $raw_password ) {
			wp_send_json_error( array( 'message' => __( 'Could not create an application password.', 'emcp-tools' ) ), 500 );
		}

		wp_send_json_success(
			array(
				'username' => $user->user_login,
				'password' => \WP_Application_Passwords::chunk_password( $raw_password ),
				'name'     => $app_name,
			)
		);
	}

	/**
	 * AJAX: test Application Password credentials against the real MCP endpoint.
	 *
	 * Unlike the old `/wp/v2/users/me` probe, this exercises the complete MCP
	 * session lifecycle and therefore catches transport, routing, session, and
	 * tool-registration failures as well as a stripped Authorization header.
	 *
	 * @since 3.15.0
	 */
	public function ajax_test_connection(): void {
		check_ajax_referer( 'emcp_tools_test_connection', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to run this test.', 'emcp-tools' ) ), 403 );
		}

		$username = isset( $_POST['username'] ) ? sanitize_text_field( wp_unslash( $_POST['username'] ) ) : '';
		$password = isset( $_POST['password'] ) ? trim( (string) wp_unslash( $_POST['password'] ) ) : '';
		if ( '' === $username || '' === $password ) {
			wp_send_json_error( array( 'message' => __( 'Enter a username and Application Password first.', 'emcp-tools' ) ), 400 );
		}

		$result = $this->run_mcp_handshake( $username, $password );
		if ( is_wp_error( $result ) ) {
			wp_send_json_error(
				array(
					'message' => $result->get_error_message(),
					'stage'   => $result->get_error_code(),
				),
				400
			);
		}

		wp_send_json_success( $result );
	}

	/**
	 * Run initialize → notifications/initialized → tools/list via public HTTP.
	 *
	 * @param string $username WordPress login.
	 * @param string $password Application Password.
	 * @return array|WP_Error
	 */
	private function run_mcp_handshake( string $username, string $password ) {
		$endpoint = class_exists( 'EMCP_Tools_Site_Context' ) ? EMCP_Tools_Site_Context::mcp_endpoint() : rest_url( 'mcp/emcp-tools-server' );
		$auth     = 'Basic ' . base64_encode( $username . ':' . $password );
		$session  = '';

		$initialize = $this->mcp_diagnostic_request(
			$endpoint,
			$auth,
			'',
			array(
				'jsonrpc' => '2.0',
				'id'      => 1,
				'method'  => 'initialize',
				'params'  => array(
					'protocolVersion' => '2025-11-25',
					'capabilities'    => (object) array(),
					'clientInfo'      => array(
						'name'    => 'EMCP Tools connection test',
						'version' => defined( 'EMCP_TOOLS_VERSION' ) ? EMCP_TOOLS_VERSION : 'unknown',
					),
				),
			),
			'initialize'
		);
		if ( is_wp_error( $initialize ) ) {
			return $initialize;
		}

		$session = (string) wp_remote_retrieve_header( $initialize['response'], 'mcp-session-id' );
		if ( '' === $session ) {
			return new WP_Error( 'initialize', __( 'Initialize succeeded but the server did not return an MCP session ID.', 'emcp-tools' ) );
		}
		$protocol_version = isset( $initialize['json']['result']['protocolVersion'] ) ? sanitize_text_field( (string) $initialize['json']['result']['protocolVersion'] ) : '';
		if ( '' === $protocol_version ) {
			return new WP_Error( 'initialize', __( 'Initialize returned an invalid protocol version.', 'emcp-tools' ) );
		}

		try {
			$initialized = $this->mcp_diagnostic_request(
				$endpoint,
				$auth,
				$session,
				array(
					'jsonrpc' => '2.0',
					'method'  => 'notifications/initialized',
				),
				'initialized',
				true,
				$protocol_version
			);
			if ( is_wp_error( $initialized ) ) {
				return $initialized;
			}

			$tools = $this->mcp_diagnostic_request(
				$endpoint,
				$auth,
				$session,
				array(
					'jsonrpc' => '2.0',
					'id'      => 2,
					'method'  => 'tools/list',
					'params'  => (object) array(),
				),
				'tools_list',
				false,
				$protocol_version
			);
			if ( is_wp_error( $tools ) ) {
				return $tools;
			}
			if ( ! isset( $tools['json']['result']['tools'] ) || ! is_array( $tools['json']['result']['tools'] ) ) {
				return new WP_Error( 'tools_list', __( 'tools/list returned an invalid MCP response.', 'emcp-tools' ) );
			}

			return array(
				'message'    => __( 'Full MCP handshake succeeded.', 'emcp-tools' ),
				'tool_count' => count( $tools['json']['result']['tools'] ),
			);
		} finally {
			// Best-effort cleanup; never replace the useful diagnostic result with a
			// session-delete failure.
			wp_safe_remote_request(
				$endpoint,
				array(
					'method'  => 'DELETE',
					'timeout' => 10,
					'headers' => array(
						'Authorization'        => $auth,
						'Mcp-Protocol-Version' => $protocol_version,
						'Mcp-Session-Id'       => $session,
					),
				)
			);
		}
	}

	/**
	 * Send one JSON-RPC request used by the connection diagnostic.
	 *
	 * @param string $endpoint           Public MCP endpoint.
	 * @param string $authorization      Basic authorization header.
	 * @param string $session            MCP session ID, or empty for initialize.
	 * @param array  $payload            JSON-RPC payload.
	 * @param string $stage              Stable diagnostic stage.
	 * @param bool   $notification       Whether a 202 empty response is valid.
	 * @param string $protocol_version   Negotiated MCP protocol version.
	 * @return array|WP_Error
	 */
	private function mcp_diagnostic_request( string $endpoint, string $authorization, string $session, array $payload, string $stage, bool $notification = false, string $protocol_version = '' ) {
		$headers = array(
			'Accept'        => 'application/json, text/event-stream',
			'Authorization' => $authorization,
			'Content-Type'  => 'application/json',
		);
		if ( '' !== $session ) {
			$headers['Mcp-Session-Id'] = $session;
		}
		if ( '' !== $protocol_version ) {
			$headers['Mcp-Protocol-Version'] = $protocol_version;
		}

		$response = wp_safe_remote_post(
			$endpoint,
			array(
				'timeout'     => 15,
				'redirection' => 0,
				'headers'     => $headers,
				'body'        => wp_json_encode( $payload ),
			)
		);
		if ( is_wp_error( $response ) ) {
			return new WP_Error( $stage, sprintf( __( '%1$s request failed: %2$s', 'emcp-tools' ), $this->diagnostic_stage_label( $stage ), $response->get_error_message() ) );
		}

		$status = (int) wp_remote_retrieve_response_code( $response );
		if ( $notification && in_array( $status, array( 200, 202 ), true ) ) {
			return array( 'response' => $response, 'json' => array() );
		}
		if ( 200 !== $status ) {
			return new WP_Error(
				$stage,
				sprintf(
					/* translators: 1: MCP handshake stage, 2: HTTP status code. */
					__( '%1$s failed with HTTP %2$d. Check the endpoint, CDN/WAF rules, and whether the Authorization header reaches WordPress.', 'emcp-tools' ),
					$this->diagnostic_stage_label( $stage ),
					$status
				)
			);
		}

		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		if ( ! is_array( $decoded ) ) {
			return new WP_Error( $stage, sprintf( __( '%s returned a non-JSON response.', 'emcp-tools' ), $this->diagnostic_stage_label( $stage ) ) );
		}
		if ( isset( $decoded['error'] ) ) {
			$error_message = isset( $decoded['error']['message'] ) ? sanitize_text_field( (string) $decoded['error']['message'] ) : __( 'Unknown JSON-RPC error.', 'emcp-tools' );
			return new WP_Error( $stage, sprintf( __( '%1$s returned an MCP error: %2$s', 'emcp-tools' ), $this->diagnostic_stage_label( $stage ), $error_message ) );
		}

		return array( 'response' => $response, 'json' => $decoded );
	}

	/** Human-readable label for a stable diagnostic stage. */
	private function diagnostic_stage_label( string $stage ): string {
		$labels = array(
			'initialize'  => 'initialize',
			'initialized' => 'notifications/initialized',
			'tools_list'  => 'tools/list',
		);
		return isset( $labels[ $stage ] ) ? $labels[ $stage ] : $stage;
	}

	/**
	 * AJAX: check both standards-based well-known URLs and their REST aliases.
	 *
	 * @since 3.15.0
	 */
	public function ajax_test_oauth_discovery(): void {
		check_ajax_referer( 'emcp_tools_test_oauth_discovery', 'nonce' );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to run this test.', 'emcp-tools' ) ), 403 );
		}
		if ( ! class_exists( 'EMCP_Tools_OAuth_Metadata' ) || ! class_exists( 'EMCP_Tools_OAuth_Server' ) || ! EMCP_Tools_OAuth_Server::is_enabled() ) {
			wp_send_json_error( array( 'message' => __( 'Enable OAuth before testing discovery.', 'emcp-tools' ) ), 400 );
		}

		$base   = class_exists( 'EMCP_Tools_Site_Context' ) ? EMCP_Tools_Site_Context::public_base_url() : rtrim( (string) home_url(), '/' );
		$checks = array(
			'well_known_protected_resource' => $this->probe_oauth_document( $base . EMCP_Tools_OAuth_Metadata::PATH_PROTECTED_RESOURCE, 'resource', EMCP_Tools_OAuth_Metadata::resource() ),
			'well_known_authorization_server' => $this->probe_oauth_document( $base . EMCP_Tools_OAuth_Metadata::PATH_AUTH_SERVER, 'issuer', EMCP_Tools_OAuth_Metadata::issuer() ),
			'rest_protected_resource'       => $this->probe_oauth_document( EMCP_Tools_OAuth_Metadata::protected_resource_url(), 'resource', EMCP_Tools_OAuth_Metadata::resource() ),
			'rest_authorization_server'     => $this->probe_oauth_document( EMCP_Tools_OAuth_Metadata::authorization_server_url(), 'issuer', EMCP_Tools_OAuth_Metadata::issuer() ),
		);

		$root_ok = $checks['well_known_protected_resource']['ok'] && $checks['well_known_authorization_server']['ok'];
		$rest_ok = $checks['rest_protected_resource']['ok'] && $checks['rest_authorization_server']['ok'];
		if ( $root_ok && $rest_ok ) {
			wp_send_json_success( array( 'message' => __( 'OAuth discovery is publicly reachable through both standard well-known URLs and REST aliases.', 'emcp-tools' ), 'checks' => $checks ) );
		}
		if ( ! $root_ok && $rest_ok ) {
			wp_send_json_error(
				array(
					'message' => __( 'EMCP OAuth routes work, but the public .well-known URLs do not return EMCP metadata. A CDN/host may be intercepting them, or another plugin may own the shared paths. Review the failed values, then bypass or rewrite those routes before reconnecting the client.', 'emcp-tools' ),
					'checks'  => $checks,
				),
				400
			);
		}

		wp_send_json_error( array( 'message' => __( 'OAuth discovery is not reachable. Review the failed checks and confirm the REST API, HTTPS, permalink routing, and CDN/WAF rules.', 'emcp-tools' ), 'checks' => $checks ), 400 );
	}

	/** Probe one public OAuth metadata document without following redirects. */
	private function probe_oauth_document( string $url, string $required_key, string $expected_value ): array {
		$response = wp_safe_remote_get( $url, array( 'timeout' => 12, 'redirection' => 0 ) );
		if ( is_wp_error( $response ) ) {
			return array( 'ok' => false, 'status' => 0, 'message' => $response->get_error_message() );
		}
		$status  = (int) wp_remote_retrieve_response_code( $response );
		$decoded = json_decode( (string) wp_remote_retrieve_body( $response ), true );
		$actual  = is_array( $decoded ) && isset( $decoded[ $required_key ] ) && is_string( $decoded[ $required_key ] ) ? $decoded[ $required_key ] : '';
		$matches = '' !== $actual && EMCP_Tools_OAuth_Metadata::identifier_matches( $actual, $expected_value );
		$valid   = 200 === $status && $matches;
		$message = __( 'Expected OAuth metadata JSON was not returned.', 'emcp-tools' );
		if ( $valid ) {
			$message = __( 'OK', 'emcp-tools' );
		} elseif ( 200 === $status && '' !== $actual && ! $matches ) {
			$message = sprintf(
				/* translators: 1: metadata identifier returned, 2: EMCP identifier expected. */
				__( 'Metadata identifies "%1$s", but EMCP expected "%2$s". Another plugin, CDN, or host may own this URL.', 'emcp-tools' ),
				sanitize_text_field( $actual ),
				sanitize_text_field( $expected_value )
			);
		}
		return array(
			'ok'       => $valid,
			'status'   => $status,
			'actual'   => $actual,
			'expected' => $expected_value,
			'message'  => $message,
		);
	}

	/**
	 * AJAX: activate/deactivate a generated widget from the Widget Builder tab.
	 *
	 * @since 1.9.0
	 */
	public function ajax_toggle_widget(): void {
		check_ajax_referer( 'emcp_tools_widgets', 'nonce' );
		if ( ! class_exists( 'EMCP_Tools_Widget_Store' ) || ! EMCP_Tools_Widget_Store::user_has_access() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'emcp-tools' ) ), 403 );
		}
		$widget_id = isset( $_POST['widget_id'] ) ? absint( wp_unslash( $_POST['widget_id'] ) ) : 0;
		$status    = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		if ( ! $widget_id || ! in_array( $status, array( 'active', 'draft' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'emcp-tools' ) ), 400 );
		}
		$res = EMCP_Tools_Widget_Store::set_status( $widget_id, $status );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: delete a generated widget from the Widget Builder tab.
	 *
	 * @since 1.9.0
	 */
	public function ajax_delete_widget(): void {
		check_ajax_referer( 'emcp_tools_widgets', 'nonce' );
		if ( ! class_exists( 'EMCP_Tools_Widget_Store' ) || ! EMCP_Tools_Widget_Store::user_has_access() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'emcp-tools' ) ), 403 );
		}
		$widget_id = isset( $_POST['widget_id'] ) ? absint( wp_unslash( $_POST['widget_id'] ) ) : 0;
		if ( ! $widget_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'emcp-tools' ) ), 400 );
		}
		$res = EMCP_Tools_Widget_Store::delete( $widget_id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: mark app-bar notifications as read for the current user, called
	 * when the notifications dropdown is opened.
	 *
	 * @since 3.10.0
	 */
	public function ajax_notifications_read(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_send_json_error( array( 'message' => __( 'Forbidden.', 'emcp-tools' ) ), 403 );
		}
		if ( ! check_ajax_referer( 'emcp_tools_notifications', 'nonce', false ) ) {
			wp_send_json_error( array( 'message' => __( 'Security check failed.', 'emcp-tools' ) ), 403 );
		}

		$ids = isset( $_POST['ids'] ) ? (array) wp_unslash( $_POST['ids'] ) : array();
		$ids = array_map( 'sanitize_text_field', $ids );

		$user_id = get_current_user_id();
		EMCP_Tools_Notifications::mark_read( $user_id, $ids );

		wp_send_json_success( array( 'unread' => EMCP_Tools_Notifications::unread_count( $user_id ) ) );
	}

	/**
	 * AJAX: activate/deactivate a generated Gutenberg block from the Blocks tab.
	 *
	 * @since 3.7.0
	 */
	public function ajax_toggle_block(): void {
		check_ajax_referer( 'emcp_tools_blocks', 'nonce' );
		if ( ! class_exists( 'EMCP_Tools_Block_Store' ) || ! EMCP_Tools_Block_Store::user_has_access() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'emcp-tools' ) ), 403 );
		}
		$block_id = isset( $_POST['block_id'] ) ? absint( wp_unslash( $_POST['block_id'] ) ) : 0;
		$status   = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		if ( ! $block_id || ! in_array( $status, array( 'active', 'draft' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'emcp-tools' ) ), 400 );
		}
		$res = EMCP_Tools_Block_Store::instance()->set_status( $block_id, $status );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: delete a generated Gutenberg block from the Blocks tab.
	 *
	 * @since 3.7.0
	 */
	public function ajax_delete_block(): void {
		check_ajax_referer( 'emcp_tools_blocks', 'nonce' );
		if ( ! class_exists( 'EMCP_Tools_Block_Store' ) || ! EMCP_Tools_Block_Store::user_has_access() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'emcp-tools' ) ), 403 );
		}
		$block_id = isset( $_POST['block_id'] ) ? absint( wp_unslash( $_POST['block_id'] ) ) : 0;
		if ( ! $block_id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'emcp-tools' ) ), 400 );
		}
		$res = EMCP_Tools_Block_Store::instance()->delete( $block_id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}
		wp_send_json_success( $res );
	}

	/**
	 * Guards a Memory AJAX request (nonce + Pro/cap). wp_die/returns on failure.
	 *
	 * @since 3.7.0
	 */
	private function memory_ajax_guard(): void {
		check_ajax_referer( 'emcp_tools_memory', 'nonce' );
		if ( ! class_exists( 'EMCP_Tools_Memory_Store' ) || ! EMCP_Tools_Memory_Store::user_has_access() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'emcp-tools' ) ), 403 );
		}
	}

	/**
	 * AJAX: approve/reject/toggle a guidance entry from the Memory tab.
	 *
	 * @since 3.7.0
	 */
	public function ajax_memory_set_status(): void {
		$this->memory_ajax_guard();
		$id     = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		if ( ! $id || ! in_array( $status, array( 'publish', 'pending', 'draft', 'trash' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'emcp-tools' ) ), 400 );
		}
		$ok = EMCP_Tools_Memory_Store::instance()->set_guidance_status( $id, $status );
		$ok ? wp_send_json_success( array( 'id' => $id, 'status' => $status ) )
			: wp_send_json_error( array( 'message' => __( 'Not found.', 'emcp-tools' ) ), 400 );
	}

	/**
	 * AJAX: create (admin, approved) or edit a guidance entry from the Memory tab.
	 *
	 * @since 3.7.0
	 */
	public function ajax_memory_save_guidance(): void {
		$this->memory_ajax_guard();
		$store = EMCP_Tools_Memory_Store::instance();
		$id    = isset( $_POST['id'] ) ? absint( wp_unslash( $_POST['id'] ) ) : 0;
		$type  = isset( $_POST['type'] ) ? sanitize_key( wp_unslash( $_POST['type'] ) ) : '';
		$body  = isset( $_POST['body'] ) ? sanitize_textarea_field( wp_unslash( $_POST['body'] ) ) : '';
		if ( ! in_array( $type, EMCP_Tools_Memory_Store::TYPES, true ) || '' === trim( $body ) ) {
			wp_send_json_error( array( 'message' => __( 'A type and non-empty guidance are required.', 'emcp-tools' ) ), 400 );
		}
		if ( $id > 0 ) {
			$store->update_guidance( $id, array( 'type' => $type, 'body' => $body, 'title' => wp_trim_words( $body, 8, '' ) ) );
			wp_send_json_success( array( 'id' => $id ) );
		}
		$new = $store->add_guidance( array(
			'title'  => wp_trim_words( $body, 8, '' ),
			'body'   => $body,
			'type'   => $type,
			'source' => 'admin',
			'status' => 'publish',
		) );
		is_wp_error( $new )
			? wp_send_json_error( array( 'message' => $new->get_error_message() ), 400 )
			: wp_send_json_success( array( 'id' => (int) $new ) );
	}

	/**
	 * AJAX: persist Memory settings (auto-summarize, require-approval).
	 *
	 * @since 3.7.0
	 */
	public function ajax_memory_save_settings(): void {
		$this->memory_ajax_guard();
		if ( isset( $_POST['auto_summarize'] ) ) {
			update_option( 'emcp_tools_memory_auto_summarize', '1' === sanitize_text_field( wp_unslash( $_POST['auto_summarize'] ) ) ? '1' : '0' );
		}
		if ( isset( $_POST['require_approval'] ) ) {
			update_option( 'emcp_tools_memory_require_approval', '1' === sanitize_text_field( wp_unslash( $_POST['require_approval'] ) ) ? '1' : '0' );
		}
		wp_send_json_success( array( 'saved' => true ) );
	}

	/**
	 * AJAX: create or update a PHP snippet draft from the Sandbox tab. Validates
	 * and refuses critical findings (returning them so the form can show why).
	 *
	 * @since 2.1.0
	 */
	public function ajax_save_php_snippet(): void {
		check_ajax_referer( 'emcp_tools_php_snippets', 'nonce' );
		if ( ! class_exists( 'EMCP_Tools_PHP_Snippet_Store' ) || ! EMCP_Tools_PHP_Snippet_Store::can_edit() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to manage PHP snippets (requires manage_options and unfiltered_html).', 'emcp-tools' ) ), 403 );
		}
		$id = isset( $_POST['snippet_id'] ) ? absint( wp_unslash( $_POST['snippet_id'] ) ) : 0;
		// Code is raw PHP: keep it verbatim (unslash only). It is never executed
		// here — it is validated and stored; execution requires later activation.
		$args = array(
			'title'    => isset( $_POST['title'] ) ? sanitize_text_field( wp_unslash( $_POST['title'] ) ) : '',
			'code'     => isset( $_POST['code'] ) ? wp_unslash( (string) $_POST['code'] ) : '', // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- raw PHP source, validated by the snippet validator, never executed here.
			'context'  => isset( $_POST['context'] ) ? sanitize_key( wp_unslash( $_POST['context'] ) ) : 'shortcode',
			'hook'     => isset( $_POST['hook'] ) ? sanitize_text_field( wp_unslash( $_POST['hook'] ) ) : '',
			'priority' => isset( $_POST['priority'] ) ? absint( wp_unslash( $_POST['priority'] ) ) : 10,
		);

		$res = $id
			? EMCP_Tools_PHP_Snippet_Store::update( $id, $args )
			: EMCP_Tools_PHP_Snippet_Store::create_draft( $args );

		if ( is_wp_error( $res ) ) {
			$data    = $res->get_error_data();
			$payload = array( 'message' => $res->get_error_message() );
			if ( is_array( $data ) && isset( $data['validation'] ) ) {
				$payload['validation'] = $data['validation'];
				// Summarised here rather than in the browser, so the report reads
				// the same wherever it is shown.
				$payload['summary'] = EMCP_Tools_PHP_Snippet_Validator::summary( (array) $data['validation'] );
			}
			wp_send_json_error( $payload, 400 );
		}
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: activate/deactivate a PHP snippet (the human approval gate).
	 * Activation re-validates and writes the executable file.
	 *
	 * @since 2.1.0
	 */
	public function ajax_toggle_php_snippet(): void {
		check_ajax_referer( 'emcp_tools_php_snippets', 'nonce' );
		if ( ! class_exists( 'EMCP_Tools_PHP_Snippet_Store' ) || ! EMCP_Tools_PHP_Snippet_Store::can_edit() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'emcp-tools' ) ), 403 );
		}
		$id     = isset( $_POST['snippet_id'] ) ? absint( wp_unslash( $_POST['snippet_id'] ) ) : 0;
		$status = isset( $_POST['status'] ) ? sanitize_key( wp_unslash( $_POST['status'] ) ) : '';
		if ( ! $id || ! in_array( $status, array( 'active', 'draft' ), true ) ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'emcp-tools' ) ), 400 );
		}
		$res = EMCP_Tools_PHP_Snippet_Store::set_status( $id, $status );
		if ( is_wp_error( $res ) ) {
			$data    = $res->get_error_data();
			$payload = array( 'message' => $res->get_error_message() );
			if ( is_array( $data ) && isset( $data['validation'] ) ) {
				$payload['validation'] = $data['validation'];
				// Summarised here rather than in the browser, so the report reads
				// the same wherever it is shown.
				$payload['summary'] = EMCP_Tools_PHP_Snippet_Validator::summary( (array) $data['validation'] );
			}
			wp_send_json_error( $payload, 400 );
		}
		wp_send_json_success( $res );
	}

	/**
	 * AJAX: delete a PHP snippet from the Sandbox tab.
	 *
	 * @since 2.1.0
	 */
	public function ajax_delete_php_snippet(): void {
		check_ajax_referer( 'emcp_tools_php_snippets', 'nonce' );
		if ( ! class_exists( 'EMCP_Tools_PHP_Snippet_Store' ) || ! EMCP_Tools_PHP_Snippet_Store::can_edit() ) {
			wp_send_json_error( array( 'message' => __( 'You do not have permission to do this.', 'emcp-tools' ) ), 403 );
		}
		$id = isset( $_POST['snippet_id'] ) ? absint( wp_unslash( $_POST['snippet_id'] ) ) : 0;
		if ( ! $id ) {
			wp_send_json_error( array( 'message' => __( 'Invalid request.', 'emcp-tools' ) ), 400 );
		}
		$res = EMCP_Tools_PHP_Snippet_Store::delete( $id );
		if ( is_wp_error( $res ) ) {
			wp_send_json_error( array( 'message' => $res->get_error_message() ), 400 );
		}
		wp_send_json_success( $res );
	}

	/**
	 * admin-post.php callback: build + stream a Claude Desktop .mcpb bundle
	 * with the chosen admin's credentials baked in. POST body: user_id,
	 * app_password, _emcp_nonce. Halts execution at the end.
	 *
	 * @since 3.0.0
	 */
	public function handle_download_mcpb(): void {
		if (
			! isset( $_POST['_emcp_nonce'] )
			|| ! wp_verify_nonce( sanitize_text_field( wp_unslash( $_POST['_emcp_nonce'] ) ), self::NONCE_DOWNLOAD_MCPB )
		) {
			wp_die( esc_html__( 'Invalid request.', 'emcp-tools' ), '', array( 'response' => 403 ) );
		}
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to download this.', 'emcp-tools' ), '', array( 'response' => 403 ) );
		}

		$user_id = isset( $_POST['user_id'] ) ? absint( wp_unslash( $_POST['user_id'] ) ) : 0;
		$user    = $user_id ? get_userdata( $user_id ) : false;
		if ( ! $user || ! current_user_can( 'edit_user', $user_id ) || ! user_can( $user_id, 'manage_options' ) ) {
			wp_die( esc_html__( 'Pick a valid administrator account.', 'emcp-tools' ), '', array( 'response' => 400 ) );
		}

		// The app password was generated on the page (Step 1) and POSTed back —
		// same-origin, nonce-gated, the admin's own credential.
		$app_password = isset( $_POST['app_password'] ) ? sanitize_text_field( wp_unslash( $_POST['app_password'] ) ) : '';
		if ( '' === $app_password ) {
			wp_die( esc_html__( 'Generate an Application Password first, then download the bundle.', 'emcp-tools' ), '', array( 'response' => 400 ) );
		}

		// Bake the reachable public base (rest_url-derived / admin-overridable),
		// NOT home_url() — on a staging host whose Site Address is pinned to a
		// not-yet-live domain, home_url() would ship a bundle that can't connect.
		$emcp_base = class_exists( 'EMCP_Tools_Site_Context' ) ? EMCP_Tools_Site_Context::public_base_url() : home_url();
		$manifest  = EMCP_Tools_Mcpb_Builder::build_manifest( $emcp_base, $user->user_login, $app_password );
		$tmp      = EMCP_Tools_Mcpb_Builder::build_zip( $manifest );
		if ( is_wp_error( $tmp ) ) {
			wp_die( esc_html( $tmp->get_error_message() ), '', array( 'response' => 500 ) );
		}

		// Safety net: the temp file holds a live Application Password. Guarantee
		// it is removed even if streaming aborts (fatal, memory limit, etc.) —
		// the explicit unlink after readfile() handles the normal fast path.
		register_shutdown_function(
			static function () use ( $tmp ) {
				if ( file_exists( $tmp ) ) {
					@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
				}
			}
		);

		$host     = (string) wp_parse_url( $emcp_base, PHP_URL_HOST );
		$filename = 'emcp-tools-' . sanitize_file_name( $host ?: 'site' ) . '.mcpb';

		nocache_headers();
		header( 'Content-Type: application/octet-stream' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		header( 'Content-Length: ' . filesize( $tmp ) );
		header( 'X-Content-Type-Options: nosniff' );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		readfile( $tmp );
		@unlink( $tmp ); // phpcs:ignore WordPress.PHP.NoSilencedErrors
		exit;
	}

	/**
	 * Nonce'd admin-post URL that exports one sandbox artifact (block/widget/
	 * snippet) as a portable JSON bundle download. Mirrors delete_change_url().
	 *
	 * @since 3.7.0
	 *
	 * @param string $kind One of 'block' | 'widget' | 'snippet'.
	 * @param int    $id   The artifact's local post ID.
	 * @return string
	 */
	public static function sandbox_export_url( string $kind, int $id ): string {
		return wp_nonce_url(
			add_query_arg(
				array(
					'action' => self::ACTION_EXPORT_ARTIFACT,
					'kind'   => $kind,
					'id'     => $id,
				),
				admin_url( 'admin-post.php' )
			),
			self::NONCE_SANDBOX_BUNDLE
		);
	}

	/**
	 * admin-post.php callback: stream one sandbox artifact (custom block,
	 * custom widget, or PHP snippet) as a portable, checksum-verified JSON
	 * bundle download. GET: kind, id, _wpnonce. Halts execution at the end.
	 *
	 * Reuses EMCP_Tools_Sandbox_Cloud_Abilities::resolve_artifact() — the same
	 * resolver the MCP export-sandbox-artifact tool uses — so a block export
	 * cleanly fails here (no fatal) on a site without the Pro overlay.
	 *
	 * @since 3.7.0
	 */
	public function handle_export_artifact(): void {
		check_admin_referer( self::NONCE_SANDBOX_BUNDLE );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'emcp-tools' ), '', array( 'response' => 403 ) );
		}

		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce already verified above via check_admin_referer().
		$kind = isset( $_GET['kind'] ) ? sanitize_key( wp_unslash( $_GET['kind'] ) ) : '';
		// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- nonce already verified above via check_admin_referer().
		$id = isset( $_GET['id'] ) ? absint( wp_unslash( $_GET['id'] ) ) : 0;

		if ( ! in_array( $kind, EMCP_Tools_Sandbox_Bundle::KINDS, true ) ) {
			wp_die( esc_html__( 'Unsupported sandbox artifact kind.', 'emcp-tools' ), '', array( 'response' => 400 ) );
		}

		$artifact = ( new EMCP_Tools_Sandbox_Cloud_Abilities() )->resolve_artifact( $kind );
		if ( null === $artifact ) {
			wp_die( esc_html__( 'That artifact kind is unavailable on this site (it may require EMCP Tools Pro).', 'emcp-tools' ), '', array( 'response' => 400 ) );
		}

		$bundle = $artifact->to_bundle( $id );
		if ( is_wp_error( $bundle ) ) {
			wp_die( esc_html( $bundle->get_error_message() ), '', array( 'response' => 400 ) );
		}

		$filename = sanitize_file_name( 'emcp-' . $kind . '-' . $id . '.json' );

		nocache_headers();
		header( 'Content-Type: application/json; charset=utf-8' );
		header( 'Content-Disposition: attachment; filename="' . $filename . '"' );
		while ( ob_get_level() > 0 ) {
			ob_end_clean();
		}
		echo wp_json_encode( $bundle, JSON_PRETTY_PRINT ); // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- streamed JSON download body, not HTML.
		exit;
	}

	/**
	 * admin-post.php callback: import an uploaded sandbox artifact bundle
	 * (custom block, custom widget, or PHP snippet) as a new local draft.
	 * POST (multipart): the `bundle` file upload, `_wpnonce`. Redirects back
	 * to the pillar view for the imported kind with a minimal notice query
	 * arg. Halts execution at the end.
	 *
	 * Validates the upload (present, no error, size-capped, .json extension,
	 * decodes to an array) then defers to EMCP_Tools_Sandbox_Bundle::validate()
	 * (schema version, kind, checksum) before resolving the artifact and
	 * calling apply_bundle() — a block import on a non-Pro site resolves to
	 * null and redirects with a clean notice, never a fatal.
	 *
	 * @since 3.7.0
	 */
	public function handle_import_artifact(): void {
		check_admin_referer( self::NONCE_SANDBOX_BUNDLE );

		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'emcp-tools' ), '', array( 'response' => 403 ) );
		}

		$back = menu_page_url( 'emcp-tools-widgets', false );

		// phpcs:ignore WordPress.Security.ValidatedSanitizedInput.MissingUnslash, WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- $_FILES superglobal; every field is validated below before use.
		$file = isset( $_FILES['bundle'] ) && is_array( $_FILES['bundle'] ) ? $_FILES['bundle'] : array();

		if ( empty( $file ) || ! isset( $file['error'] ) || UPLOAD_ERR_OK !== $file['error'] ) {
			wp_safe_redirect( add_query_arg( 'import_error', rawurlencode( __( 'No bundle file was uploaded, or the upload failed.', 'emcp-tools' ) ), $back ) );
			exit;
		}

		$max_bytes = 2 * MB_IN_BYTES;
		if ( ! isset( $file['size'] ) || $file['size'] <= 0 || $file['size'] > $max_bytes ) {
			wp_safe_redirect( add_query_arg( 'import_error', rawurlencode( __( 'The bundle file is empty or larger than 2 MB.', 'emcp-tools' ) ), $back ) );
			exit;
		}

		$name = isset( $file['name'] ) ? sanitize_file_name( wp_unslash( $file['name'] ) ) : '';
		if ( '.json' !== strtolower( substr( $name, -5 ) ) ) {
			wp_safe_redirect( add_query_arg( 'import_error', rawurlencode( __( 'The bundle must be a .json file.', 'emcp-tools' ) ), $back ) );
			exit;
		}

		$tmp_name = isset( $file['tmp_name'] ) ? wp_unslash( $file['tmp_name'] ) : '';
		if ( '' === $tmp_name || ! is_uploaded_file( $tmp_name ) ) {
			wp_safe_redirect( add_query_arg( 'import_error', rawurlencode( __( 'The upload could not be read.', 'emcp-tools' ) ), $back ) );
			exit;
		}

		// phpcs:ignore WordPress.WP.AlternativeFunctions.file_get_contents_file_get_contents -- reading a validated PHP-upload tmp file (is_uploaded_file() checked above), not a remote URL.
		$contents = file_get_contents( $tmp_name );
		$data     = ( false !== $contents ) ? json_decode( $contents, true ) : null;

		if ( ! is_array( $data ) ) {
			wp_safe_redirect( add_query_arg( 'import_error', rawurlencode( __( 'The bundle is not valid JSON.', 'emcp-tools' ) ), $back ) );
			exit;
		}

		$valid = EMCP_Tools_Sandbox_Bundle::validate( $data );
		if ( is_wp_error( $valid ) ) {
			wp_safe_redirect( add_query_arg( 'import_error', rawurlencode( $valid->get_error_message() ), $back ) );
			exit;
		}

		$kind     = (string) $data['kind'];
		$artifact = ( new EMCP_Tools_Sandbox_Cloud_Abilities() )->resolve_artifact( $kind );
		if ( null === $artifact ) {
			wp_safe_redirect(
				add_query_arg(
					'import_error',
					rawurlencode(
						sprintf(
							/* translators: %s: artifact kind (e.g. "block") */
							__( 'The "%s" artifact kind requires EMCP Tools Pro.', 'emcp-tools' ),
							$kind
						)
					),
					$back
				)
			);
			exit;
		}

		$new_id = $artifact->apply_bundle( $data );
		if ( is_wp_error( $new_id ) ) {
			wp_safe_redirect( add_query_arg( 'import_error', rawurlencode( $new_id->get_error_message() ), $back ) );
			exit;
		}

		$view_by_kind = array(
			'block'   => 'blocks',
			'widget'  => 'widgets',
			'snippet' => 'snippets',
		);
		$view         = isset( $view_by_kind[ $kind ] ) ? $view_by_kind[ $kind ] : 'overview';

		wp_safe_redirect(
			add_query_arg(
				array(
					'view'     => $view,
					'imported' => '1',
				),
				$back
			)
		);
		exit;
	}

	/**
	 * Build the headline stat cards shown on the Dashboard.
	 *
	 * Always includes Total Tools, Active, and Pro Tools. Prompts, Brand Kits,
	 * and Templates are appended only when their module is active (and, for the
	 * Pro-gated counts, when a value is available) — mirroring the module-tab
	 * visibility rules. Each entry is `key`/`value`/`label`; the view maps `key`
	 * to an icon.
	 *
	 * @since 3.1.0
	 * @return array<int,array{key:string,value:int,label:string}>
	 */
	public function get_dashboard_stats(): array {
		$stats = array(
			array( 'key' => 'tools', 'value' => (int) $this->get_total_tool_count(), 'label' => __( 'Total Tools', 'emcp-tools' ) ),
			array( 'key' => 'active', 'value' => (int) $this->get_enabled_tool_count(), 'label' => __( 'Active', 'emcp-tools' ) ),
		);

		// Count Pro tools.
		$pro_count = 0;
		foreach ( $this->get_all_tools() as $category ) {
			foreach ( $category['tools'] as $tool ) {
				if ( in_array( 'pro', $tool['badges'], true ) || in_array( 'elementor-pro', $tool['badges'], true ) ) {
					$pro_count++;
				}
			}
		}
		$stats[] = array( 'key' => 'pro', 'value' => $pro_count, 'label' => __( 'Pro Tools', 'emcp-tools' ) );

		// Count prompts. For Pro sites with a synced bundle, use the actual
		// premium-library count (matches the Prompts tab). Otherwise count the
		// bundled sample files in prompts/.
		if ( $this->module_tab_visible( 'prompts' ) ) {
			$prompt_count = 0;
			if ( class_exists( 'EMCP_Tools_Pro_Prompts' ) && EMCP_Tools_Pro_Prompts::user_has_access() ) {
				$prompt_count = EMCP_Tools_Pro_Prompts::cached_count();
			}
			if ( 0 === $prompt_count ) {
				$prompts_dir  = EMCP_TOOLS_DIR . 'prompts/';
				$prompt_files = is_dir( $prompts_dir ) ? glob( $prompts_dir . '*.md' ) : array();
				$prompt_count = count( $prompt_files );
			}
			$stats[] = array( 'key' => 'prompts', 'value' => (int) $prompt_count, 'label' => __( 'Prompts', 'emcp-tools' ) );
		}

		// Brand kits: Pro shows the cached remote library count; everyone else
		// shows the bundled free-kit count (applying is a free feature).
		if ( $this->module_tab_visible( 'brand-kits' ) ) {
			$brand_kit_count = 0;
			$show_brand_kits = false;
			if ( class_exists( 'EMCP_Tools_Pro_Brand_Kits' ) && EMCP_Tools_Pro_Brand_Kits::user_has_access() ) {
				$brand_kit_count = EMCP_Tools_Pro_Brand_Kits::count_cached_kits();
				$show_brand_kits = true;
			} elseif ( class_exists( 'EMCP_Tools_Free_Brand_Kits' ) ) {
				$brand_kit_count = EMCP_Tools_Free_Brand_Kits::count_kits();
				$show_brand_kits = $brand_kit_count > 0;
			}
			if ( $show_brand_kits ) {
				$stats[] = array( 'key' => 'brand-kits', 'value' => (int) $brand_kit_count, 'label' => __( 'Brand Kits', 'emcp-tools' ) );
			}
		}

		// Templates: Pro shows the templates-library total (sum across
		// categories). Hidden for free users and when the bundle can't be fetched.
		if ( $this->module_tab_visible( 'templates' ) && class_exists( 'EMCP_Tools_Pro_Templates' ) && EMCP_Tools_Pro_Templates::user_has_access() ) {
			$template_count  = 0;
			$emcp_tpl_bundle = EMCP_Tools_Pro_Templates::get_bundle();
			if ( ! is_wp_error( $emcp_tpl_bundle ) && is_array( $emcp_tpl_bundle ) && ! empty( $emcp_tpl_bundle['categories'] ) ) {
				foreach ( $emcp_tpl_bundle['categories'] as $emcp_tpl_cat ) {
					$template_count += is_array( $emcp_tpl_cat['templates'] ?? null ) ? count( $emcp_tpl_cat['templates'] ) : 0;
				}
			}
			if ( $template_count > 0 ) {
				$stats[] = array( 'key' => 'templates', 'value' => $template_count, 'label' => __( 'Templates', 'emcp-tools' ) );
			}
		}

		return $stats;
	}

	/**
	 * Render the settings page.
	 *
	 * @since 1.0.0
	 */
	public function render_page(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			return;
		}

		$active_tab = $this->get_active_tab();

		?>
		<div class="wrap elementor-mcp-admin">
			<h1><?php esc_html_e( 'EMCP Tools', 'emcp-tools' ); ?></h1>

			<?php
			// Success notice after a Settings API save (options.php redirects back
			// with settings-updated=true). Shown for any EMCP settings tab.
			// phpcs:ignore WordPress.Security.NonceVerification.Recommended -- options.php verifies the settings nonce before redirecting.
			if ( isset( $_GET['settings-updated'] ) && 'true' === sanitize_text_field( wp_unslash( $_GET['settings-updated'] ) ) ) :
				?>
				<?php
				// Rendered as the finished toast rather than as a notice the script
				// then moves. Moving it meant the browser painted it at the top of
				// the page first and jumped it to the corner a frame later, and it
				// put the confirmation at the mercy of the script running at all.
				// This way the first paint is already bottom-right, and admin.js
				// only adds the dismissing.
				//
				// `inline` is load-bearing, not cosmetic. On jQuery ready core runs
				//   $( 'div.notice' ).not( '.inline, .below-h2' ).insertAfter( $headerEnd )
				// which relocates every other notice to just under the page h1, and
				// would pull this one straight back out of the toast.
				?>
				<div class="emcp-toasts" aria-live="polite">
					<div class="emcp-toast emcp-toast--success" role="status">
						<div class="notice notice-success inline emcp-saved-notice emcp-toast__notice">
							<p><strong><?php esc_html_e( 'Settings saved.', 'emcp-tools' ); ?></strong></p>
						</div>
						<button type="button" class="emcp-toast__close" aria-label="<?php esc_attr_e( 'Dismiss', 'emcp-tools' ); ?>">&times;</button>
					</div>
				</div>
				<?php
			endif;
			?>

			<?php
			// Only show the upgrade CTA to sites without a valid Pro license.
			// Freemius adds its own Contact / Account / Upgrade items to the
			// EMCP Tools menu, so we don't need a redundant header link.
			$emcp_tools_show_upgrade = ! function_exists( 'emcp_tools_fs' )
				|| ! emcp_tools_fs()->can_use_premium_code();
			?>

			<?php
			// App-bar notifications bell + cloud button state (Cloud-fed, cached,
			// graceful offline — see EMCP_Tools_Notifications).
			$emcp_notifs  = class_exists( 'EMCP_Tools_Notifications' ) ? EMCP_Tools_Notifications::get() : array();
			$emcp_uid     = get_current_user_id();
			$emcp_unread  = class_exists( 'EMCP_Tools_Notifications' ) ? EMCP_Tools_Notifications::unread_count( $emcp_uid ) : 0;
			$emcp_seen    = (array) get_user_meta( $emcp_uid, '_emcp_tools_read_notifications', true );
			$emcp_cloud_connected = class_exists( 'EMCP_Tools_Cloud' ) && EMCP_Tools_Cloud::is_connected();
			?>

			<!-- Rotating promo / announcement bar -->
			<?php
			$emcp_anncs = array(
				array(
					'key'   => 'cloud',
					'badge' => __( 'New', 'emcp-tools' ),
					'icon'  => 'dashicons-cloud',
					'title' => __( 'EMCP Cloud is live', 'emcp-tools' ),
					'text'  => __( 'Back up, sync and sell your blocks, widgets and snippets across every site you run.', 'emcp-tools' ),
					'cta'   => __( 'Explore Cloud', 'emcp-tools' ),
					'url'   => 'https://emcptools.com/cloud',
				),
			);
			if ( $emcp_tools_show_upgrade ) {
				$emcp_anncs[] = array(
					'key'   => 'ltd',
					'badge' => __( 'Limited', 'emcp-tools' ),
					'icon'  => 'dashicons-clock',
					'title' => __( 'Lifetime deal ends soon', 'emcp-tools' ),
					'text'  => __( 'Pay once, own EMCP Pro forever — this lifetime deal is going away for good.', 'emcp-tools' ),
					'cta'   => __( 'Get the LTD', 'emcp-tools' ),
					'url'   => function_exists( 'emcp_tools_upgrade_url' ) ? emcp_tools_upgrade_url() : 'https://emcptools.com/pricing',
				);
			}
			$emcp_annc_rotate = count( $emcp_anncs ) > 1;
			?>
			<div class="emcp-annc" data-emcp-annc data-rotate="<?php echo $emcp_annc_rotate ? '1' : '0'; ?>">
				<div class="emcp-annc-slides">
					<?php foreach ( $emcp_anncs as $emcp_i => $emcp_a ) : ?>
						<a class="emcp-annc-slide emcp-annc-slide--<?php echo esc_attr( $emcp_a['key'] ); ?><?php echo 0 === $emcp_i ? ' is-active' : ''; ?>" href="<?php echo esc_url( $emcp_a['url'] ); ?>" target="_blank" rel="noopener">
							<span class="emcp-annc-badge"><?php echo esc_html( $emcp_a['badge'] ); ?></span>
							<span class="emcp-annc-icon dashicons <?php echo esc_attr( $emcp_a['icon'] ); ?>" aria-hidden="true"></span>
							<span class="emcp-annc-text"><strong><?php echo esc_html( $emcp_a['title'] ); ?></strong> <?php echo esc_html( $emcp_a['text'] ); ?></span>
							<span class="emcp-annc-cta"><?php echo esc_html( $emcp_a['cta'] ); ?><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></span>
						</a>
					<?php endforeach; ?>
				</div>
				<?php if ( $emcp_annc_rotate ) : ?>
					<div class="emcp-annc-dots">
						<?php foreach ( $emcp_anncs as $emcp_i => $emcp_a ) : ?>
							<button type="button" class="emcp-annc-dot<?php echo 0 === $emcp_i ? ' is-active' : ''; ?>" data-i="<?php echo (int) $emcp_i; ?>" aria-label="<?php echo esc_attr( sprintf( /* translators: %d: announcement number */ __( 'Announcement %d', 'emcp-tools' ), $emcp_i + 1 ) ); ?>"></button>
						<?php endforeach; ?>
					</div>
				<?php endif; ?>
			</div>
			<script>
			( function () {
				var b = document.querySelector( '[data-emcp-annc]' );
				if ( ! b ) { return; }
				var slides = b.querySelectorAll( '.emcp-annc-slide' ), dots = b.querySelectorAll( '.emcp-annc-dot' ), i = 0, t;
				function go( n ) { i = ( n + slides.length ) % slides.length; slides.forEach( function ( s, x ) { s.classList.toggle( 'is-active', x === i ); } ); dots.forEach( function ( d, x ) { d.classList.toggle( 'is-active', x === i ); } ); }
				function reset() { if ( b.getAttribute( 'data-rotate' ) !== '1' ) { return; } clearInterval( t ); t = setInterval( function () { go( i + 1 ); }, 7000 ); }
				dots.forEach( function ( d ) { d.addEventListener( 'click', function () { go( parseInt( d.getAttribute( 'data-i' ), 10 ) ); reset(); } ); } );
				reset();
			} )();
			</script>

			<!-- App bar -->
			<div class="emcp-appbar">
				<div class="emcp-appbar-brand">
					<img class="emcp-appbar-logo" src="<?php echo esc_url( EMCP_TOOLS_URL . 'assets/img/icon-sm.png' ); ?>" alt="" />
					<span class="emcp-appbar-title emcp-appbar-title--full"><?php esc_html_e( 'EMCP Tools', 'emcp-tools' ); ?></span>
					<span class="emcp-appbar-title emcp-appbar-title--short"><?php esc_html_e( 'MCP Tools', 'emcp-tools' ); ?></span>
					<span class="emcp-appbar-version">v<?php echo esc_html( EMCP_TOOLS_VERSION ); ?></span>
				</div>
				<div class="emcp-appbar-actions">
					<a class="emcp-appbar-changelog<?php echo 'mcp-log' === $active_tab ? ' is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-mcp-log' ) ); ?>">
						<span class="dashicons dashicons-list-view" aria-hidden="true"></span>
						<?php esc_html_e( 'MCP Log', 'emcp-tools' ); ?>
					</a>
					<a class="emcp-appbar-changelog<?php echo 'history' === $active_tab ? ' is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-history' ) ); ?>">
						<span class="dashicons dashicons-clock" aria-hidden="true"></span>
						<?php esc_html_e( 'History', 'emcp-tools' ); ?>
					</a>
					<a class="emcp-appbar-changelog<?php echo 'changelog' === $active_tab ? ' is-active' : ''; ?>" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-changelog' ) ); ?>">
						<span class="dashicons dashicons-backup" aria-hidden="true"></span>
						<?php esc_html_e( 'Changelog', 'emcp-tools' ); ?>
					</a>
					<?php if ( self::affiliation_page_available() ) : ?>
						<a class="emcp-appbar-changelog" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-affiliation' ) ); ?>">
							<span class="dashicons dashicons-money-alt" aria-hidden="true"></span>
							<?php esc_html_e( 'Affiliate', 'emcp-tools' ); ?>
						</a>
					<?php endif; ?>
					<?php if ( $emcp_tools_show_upgrade ) : ?>
						<a class="emcp-appbar-upgrade" href="<?php echo esc_url( emcp_tools_upgrade_url() ); ?>" target="_blank" rel="noopener noreferrer">
							<span class="dashicons dashicons-star-filled" aria-hidden="true"></span>
							<?php esc_html_e( 'Upgrade to Pro', 'emcp-tools' ); ?>
						</a>
					<?php endif; ?>
					<div class="emcp-help-menu">
						<button type="button" class="emcp-help-toggle" aria-haspopup="true">
							<span class="dashicons dashicons-info-outline" aria-hidden="true"></span>
							<?php esc_html_e( 'Get Help', 'emcp-tools' ); ?>
							<span class="dashicons dashicons-arrow-down-alt2 emcp-help-caret" aria-hidden="true"></span>
						</button>
						<div class="emcp-help-dropdown" role="menu">
							<a role="menuitem" href="https://support.msrbuilds.com/" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-sos" aria-hidden="true"></span><?php esc_html_e( 'Ticket Support', 'emcp-tools' ); ?></a>
							<a role="menuitem" href="https://emcptools.com/docs" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-book" aria-hidden="true"></span><?php esc_html_e( 'Documentation', 'emcp-tools' ); ?></a>
							<a role="menuitem" href="https://www.facebook.com/groups/emcptools" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-groups" aria-hidden="true"></span><?php esc_html_e( 'Community', 'emcp-tools' ); ?></a>
							<a role="menuitem" href="https://discord.gg/vJfksd3S9j" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-format-chat" aria-hidden="true"></span><?php esc_html_e( 'Discord', 'emcp-tools' ); ?></a>
							<a role="menuitem" href="https://emcptools.com/tutorials" target="_blank" rel="noopener noreferrer"><span class="dashicons dashicons-video-alt3" aria-hidden="true"></span><?php esc_html_e( 'Tutorials', 'emcp-tools' ); ?></a>
						</div>
					</div>
					<div class="emcp-notif">
						<button type="button" class="emcp-notif-toggle" aria-haspopup="true" aria-expanded="false" data-nonce="<?php echo esc_attr( wp_create_nonce( 'emcp_tools_notifications' ) ); ?>">
							<span class="dashicons dashicons-bell" aria-hidden="true"></span>
							<span class="emcp-notif-badge<?php echo 0 === $emcp_unread ? ' is-empty' : ''; ?>"><?php echo esc_html( (string) $emcp_unread ); ?></span>
						</button>
						<div class="emcp-notif-overlay" aria-hidden="true"></div>
						<aside class="emcp-notif-drawer" role="dialog" aria-modal="true" aria-label="<?php esc_attr_e( 'Announcements', 'emcp-tools' ); ?>">
							<div class="emcp-notif-header">
								<span><?php esc_html_e( 'Announcements', 'emcp-tools' ); ?></span>
								<button type="button" class="emcp-notif-close" aria-label="<?php esc_attr_e( 'Close', 'emcp-tools' ); ?>"><span class="dashicons dashicons-no-alt" aria-hidden="true"></span></button>
							</div>
							<div class="emcp-notif-list">
								<?php if ( empty( $emcp_notifs ) ) : ?>
									<div class="emcp-notif-empty"><?php esc_html_e( 'No announcements yet.', 'emcp-tools' ); ?></div>
								<?php else : ?>
									<?php foreach ( $emcp_notifs as $emcp_n ) : ?>
										<?php
										$emcp_n_id      = isset( $emcp_n['id'] ) ? (string) $emcp_n['id'] : '';
										$emcp_n_unread  = '' !== $emcp_n_id && ! in_array( $emcp_n_id, $emcp_seen, true );
										$emcp_n_level   = isset( $emcp_n['level'] ) && '' !== $emcp_n['level'] ? sanitize_html_class( $emcp_n['level'] ) : 'info';
										$emcp_n_icon    = isset( $emcp_n['icon'] ) && '' !== $emcp_n['icon'] ? sanitize_html_class( $emcp_n['icon'] ) : 'megaphone';
										$emcp_n_created = isset( $emcp_n['created_at'] ) ? strtotime( (string) $emcp_n['created_at'] ) : false;
										?>
										<div class="emcp-notif-item emcp-notif-item--<?php echo esc_attr( $emcp_n_level ); ?><?php echo $emcp_n_unread ? ' is-unread' : ''; ?>" data-id="<?php echo esc_attr( $emcp_n_id ); ?>">
											<span class="emcp-notif-item-icon dashicons dashicons-<?php echo esc_attr( $emcp_n_icon ); ?>" aria-hidden="true"></span>
											<div class="emcp-notif-item-body">
												<strong><?php echo esc_html( isset( $emcp_n['title'] ) ? $emcp_n['title'] : '' ); ?></strong>
												<p><?php echo esc_html( isset( $emcp_n['body'] ) ? $emcp_n['body'] : '' ); ?></p>
												<div class="emcp-notif-item-meta">
													<?php if ( false !== $emcp_n_created && $emcp_n_created > 0 ) : ?>
														<span class="emcp-notif-item-time">
															<?php
															/* translators: %s: human-readable time difference (e.g. "2 hours") */
															echo esc_html( sprintf( __( '%s ago', 'emcp-tools' ), human_time_diff( $emcp_n_created ) ) );
															?>
														</span>
													<?php endif; ?>
													<?php if ( ! empty( $emcp_n['url'] ) ) : ?>
														<a class="emcp-notif-item-cta" href="<?php echo esc_url( $emcp_n['url'] ); ?>" target="_blank" rel="noopener">
															<?php echo esc_html( ! empty( $emcp_n['cta'] ) ? $emcp_n['cta'] : __( 'Learn more', 'emcp-tools' ) ); ?>
														</a>
													<?php endif; ?>
												</div>
											</div>
										</div>
									<?php endforeach; ?>
								<?php endif; ?>
							</div>
						</aside>
					</div>
					<a class="emcp-cloud-btn" href="<?php echo esc_url( admin_url( 'admin.php?page=' . self::PAGE_SLUG . '-connection' ) ); ?>" title="<?php echo esc_attr( $emcp_cloud_connected ? __( 'EMCP Cloud: Connected', 'emcp-tools' ) : __( 'EMCP Cloud: Not connected — click to connect', 'emcp-tools' ) ); ?>">
						<span class="dashicons dashicons-cloud emcp-cloud-icon" aria-hidden="true"></span>
						<span class="emcp-cloud-dot<?php echo $emcp_cloud_connected ? ' is-connected' : ''; ?>"></span>
					</a>
				</div>
			</div>

			<!-- Tab nav -->
						<div class="emcp-appnav-wrap">
				<button type="button" class="emcp-appnav-arrow emcp-appnav-arrow--prev" aria-label="<?php esc_attr_e( 'Scroll tabs left', 'emcp-tools' ); ?>" hidden><span class="dashicons dashicons-arrow-left-alt2" aria-hidden="true"></span></button>
<nav class="emcp-appnav" aria-label="<?php esc_attr_e( 'EMCP Tools sections', 'emcp-tools' ); ?>">
				<?php
				foreach ( $this->get_submenus() as $emcp_slug => $emcp_label ) :
					$emcp_tab_id = ( self::PAGE_SLUG === $emcp_slug ) ? 'dashboard' : substr( $emcp_slug, strlen( self::PAGE_SLUG . '-' ) );
					// Changelog + History + MCP Log live in the app-bar top-right, not the tab nav.
					if ( 'changelog' === $emcp_tab_id || 'history' === $emcp_tab_id || 'mcp-log' === $emcp_tab_id ) {
						continue;
					}
					$emcp_is_on = ( $emcp_tab_id === $active_tab );
					?>
					<a class="emcp-appnav-item<?php echo $emcp_is_on ? ' is-active' : ''; ?>"
						href="<?php echo esc_url( admin_url( 'admin.php?page=' . $emcp_slug ) ); ?>"
						<?php echo $emcp_is_on ? 'aria-current="page"' : ''; ?>>
						<span class="dashicons <?php echo esc_attr( self::tab_icon( $emcp_tab_id ) ); ?>" aria-hidden="true"></span>
						<span class="emcp-appnav-label"><?php echo esc_html( $emcp_label ); ?></span>
						<?php
						if ( self::PAGE_SLUG . '-memory' === $emcp_slug ) {
							$emcp_pending = $this->memory_pending_count();
							if ( $emcp_pending > 0 ) {
								echo '<span class="emcp-appnav-badge" title="' . esc_attr__( 'Pending memory proposals awaiting review', 'emcp-tools' ) . '">' . (int) $emcp_pending . '</span>';
							}
						}
						?>
					</a>
				<?php endforeach; ?>
			</nav>
				<button type="button" class="emcp-appnav-arrow emcp-appnav-arrow--next" aria-label="<?php esc_attr_e( 'Scroll tabs right', 'emcp-tools' ); ?>" hidden><span class="dashicons dashicons-arrow-right-alt2" aria-hidden="true"></span></button>
			</div>

			<!-- Content -->
			<div class="tab-content<?php echo 'dashboard' === $active_tab ? ' tab-content--flush' : ''; ?>">
				<?php
				if ( 'dashboard' === $active_tab ) {
					include EMCP_TOOLS_DIR . 'includes/admin/views/page-dashboard.php';
				} elseif ( 'modules' === $active_tab ) {
					include EMCP_TOOLS_DIR . 'includes/admin/views/page-modules.php';
				} elseif ( 'connection' === $active_tab ) {
					include EMCP_TOOLS_DIR . 'includes/admin/views/page-connection.php';
				} elseif ( 'ai-chat' === $active_tab && $this->ai_chat_tab_visible() ) {
					$emcp_pro_view = EMCP_Tools_Pro_Loader::path( 'includes/admin/views/page-ai-chat.php' );
					if ( '' !== $emcp_pro_view ) {
						include $emcp_pro_view;
					} else {
						include EMCP_TOOLS_DIR . 'includes/admin/views/page-ai-chat-upsell.php';
					}
				} elseif ( 'context' === $active_tab ) {
					include EMCP_TOOLS_DIR . 'includes/admin/views/page-context.php';
				} elseif ( 'memory' === $active_tab && $this->memory_tab_visible() ) {
					$emcp_mem_view = EMCP_Tools_Pro_Loader::path( 'includes/admin/views/page-memory.php' );
					if ( '' !== $emcp_mem_view ) {
						include $emcp_mem_view;
					}
				} elseif ( 'prompts' === $active_tab && $this->module_tab_visible( 'prompts' ) ) {
					include EMCP_TOOLS_DIR . 'includes/admin/views/page-prompts.php';
				} elseif ( 'templates' === $active_tab && $this->module_tab_visible( 'templates' ) ) {
					include EMCP_TOOLS_DIR . 'includes/admin/views/page-templates.php';
				} elseif ( 'brand-kits' === $active_tab && $this->module_tab_visible( 'brand-kits' ) ) {
					include EMCP_TOOLS_DIR . 'includes/admin/views/page-brand-kits.php';
				} elseif ( 'skills' === $active_tab ) {
					$emcp_pro_view = EMCP_Tools_Pro_Loader::path( 'includes/admin/views/page-skills.php' );
					if ( '' !== $emcp_pro_view ) {
						include $emcp_pro_view;
					} else {
						$emcp_upsell_feature = __( 'Skills', 'emcp-tools' );
						include EMCP_TOOLS_DIR . 'includes/admin/views/page-pro-upsell.php';
					}
				} elseif ( 'history' === $active_tab ) {
					include EMCP_TOOLS_DIR . 'includes/admin/views/page-history.php';
				} elseif ( 'redirects' === $active_tab && $this->module_tab_visible( 'redirects' ) ) {
					include EMCP_TOOLS_DIR . 'includes/admin/views/page-redirects.php';
				} elseif ( 'migrate' === $active_tab && $this->module_tab_visible( 'migrate' ) ) {
					$emcp_migrate_view = EMCP_Tools_Pro_Loader::path( 'includes/admin/views/page-migrate.php' );
					if ( '' !== $emcp_migrate_view ) {
						include $emcp_migrate_view;
					}
				} elseif ( 'widgets' === $active_tab ) {
					include EMCP_TOOLS_DIR . 'includes/admin/views/page-widgets.php';
				} elseif ( 'marketplace' === $active_tab ) {
					include EMCP_TOOLS_DIR . 'includes/admin/views/page-marketplace.php';
				} elseif ( 'mcp-log' === $active_tab ) {
					include EMCP_TOOLS_DIR . 'includes/admin/views/page-mcp-log.php';
				} elseif ( 'changelog' === $active_tab ) {
					include EMCP_TOOLS_DIR . 'includes/admin/views/page-changelog.php';
				} else {
					include EMCP_TOOLS_DIR . 'includes/admin/views/page-tools.php';
				}
				?>
			</div>
		</div>
		<?php
	}

	/**
	 * The ordered platform sub-tabs for the Tools page. Keyed by the `platform`
	 * value a category carries; the value is the display label. A future page
	 * builder is added by giving its categories a new platform value and adding
	 * a matching entry here.
	 *
	 * @since 3.0.0
	 * @return array<string,string>
	 */
	public static function platform_tabs(): array {
		return array(
			'elementor' => __( 'Elementor', 'emcp-tools' ),
			'wordpress' => __( 'WordPress', 'emcp-tools' ),
			'plugins'   => __( 'Plugins', 'emcp-tools' ),
			'themes'    => __( 'Themes', 'emcp-tools' ),
			'gutenberg' => __( 'Gutenberg', 'emcp-tools' ),
			// EMCP's own subsystems: features this plugin implements rather than
			// WordPress APIs it drives. Keeps the WordPress tab to core management.
			'modules'   => __( 'EMCP Modules', 'emcp-tools' ),
		);
	}

	/**
	 * Plugin-integration groups, in display order. Categories on the Plugins tab
	 * carry a `group` key naming one of these; page-tools.php clusters each
	 * plugin card under its group heading so the tab stays organized as the
	 * number of integrations grows. A category with no (or an unknown) group
	 * renders inline, ungrouped.
	 *
	 * @since 3.4.3
	 * @return array<string,array{label:string,desc:string}>
	 */
	public static function plugin_groups(): array {
		return array(
			'dynamic'   => array(
				'label' => __( 'Dynamic Content', 'emcp-tools' ),
				'desc'  => __( 'Custom fields & metadata, read and write dynamic content.', 'emcp-tools' ),
			),
			'ecommerce' => array(
				'label' => __( 'E-Commerce', 'emcp-tools' ),
				'desc'  => __( 'Stores, products, orders, and customers.', 'emcp-tools' ),
			),
			'forms'     => array(
				'label' => __( 'Forms', 'emcp-tools' ),
				'desc'  => __( 'Form definitions and submissions.', 'emcp-tools' ),
			),
			'seo'       => array(
				'label' => __( 'SEO', 'emcp-tools' ),
				'desc'  => __( 'Read & write the SEO metadata your SEO plugin stores.', 'emcp-tools' ),
			),
			'addons'    => array(
				'label' => __( 'Elementor Addons', 'emcp-tools' ),
				'desc'  => __( 'Discover addon widget packs, and manage Ultimate Addons for Elementor templates.', 'emcp-tools' ),
			),
			'other'     => array(
				'label' => __( 'Other Integrations', 'emcp-tools' ),
				'desc'  => __( 'Additional plugin integrations.', 'emcp-tools' ),
			),
		);
	}

	/**
	 * Connection-tab client registry: the single source of truth for the
	 * client cards grid + per-client reveal. `methods` declares WHICH options
	 * a client supports; the actual JSON/CLI/prompt strings are assembled
	 * client-side in admin.js from the generated credentials.
	 *
	 * `cli` is a printf-style template with these tokens, substituted in JS:
	 *   %ENDPOINT% (REST MCP url), %B64% (base64 user:app-password).
	 *
	 * @since 3.0.0
	 * @return array<int,array<string,mixed>>
	 */
	public static function connection_clients(): array {
		$claude_cli = 'claude mcp add --transport http %NAME% "%ENDPOINT%" --header "Authorization: Basic %B64%"';
		$codex_cli  = 'codex mcp add %NAME% --transport http --url "%ENDPOINT%" --header "Authorization=Basic %B64%"';

		// OAuth-mode setup per client — the browser sign-in supplies auth, so no
		// password. Shapes: 'cmd' (terminal command), 'connector' (custom-connector
		// UI), 'config' (a config-file snippet). %NAME%/%ENDPOINT% are filled in JS.
		$oauth_claude_code = array(
			'type' => 'cmd',
			'cmd'  => 'claude mcp add %NAME% --transport http %ENDPOINT%',
		);
		$oauth_claude_desktop = array(
			'type' => 'connector',
			'app'  => __( 'Claude Desktop', 'emcp-tools' ),
		);
		$oauth_claude_ai = array(
			'type'     => 'connector',
			'app'      => 'claude.ai',
			'deeplink' => 'claude-ai',
			'note'     => __( 'Works in the browser and in Claude Desktop.', 'emcp-tools' ),
		);
		$oauth_cursor = array(
			'type'     => 'config',
			'lang'     => 'json',
			'paths'    => array(
				array( 'path' => '~/.cursor/mcp.json', 'label' => __( 'Global', 'emcp-tools' ) ),
				array( 'path' => '.cursor/mcp.json', 'label' => __( 'Project', 'emcp-tools' ) ),
			),
			'template' => "{\n    \"mcpServers\": {\n        \"%NAME%\": {\n            \"url\": \"%ENDPOINT%\"\n        }\n    }\n}",
			'deeplink' => 'cursor',
		);
		// The ChatGPT App signs in through its own MCP UI (Add server → Streamable
		// HTTP → Authenticate), not a config file — config.toml has no OAuth path.
		$oauth_codex = array(
			'type'  => 'steps',
			'steps' => array(
				array(
					'title' => __( 'a. Open the MCP settings', 'emcp-tools' ),
					'desc'  => __( 'In the ChatGPT app, go to File → Settings → Plugins, switch to the MCP tab, and click “Add server”.', 'emcp-tools' ),
				),
				array(
					'title' => __( 'b. Choose Streamable HTTP', 'emcp-tools' ),
					'desc'  => __( 'Set Type to “Streamable HTTP”, then enter a name and this server URL:', 'emcp-tools' ),
				),
				array( 'title' => __( 'Name', 'emcp-tools' ), 'copy' => '%NAME%' ),
				array( 'title' => __( 'URL', 'emcp-tools' ), 'copy' => '%ENDPOINT%' ),
				array(
					'title' => __( 'c. Save, then Authenticate', 'emcp-tools' ),
					'desc'  => __( 'Click Save. An “Authenticate” button appears on the server row, click it, then “Approve” on the consent screen that opens. Your site is now connected and you can start chatting.', 'emcp-tools' ),
				),
			),
		);
		$oauth_antigravity = array(
			'type'     => 'config',
			'lang'     => 'json',
			'paths'    => array(
				array( 'path' => '~/.gemini/antigravity/mcp_config.json', 'label' => __( 'macOS / Linux', 'emcp-tools' ) ),
				array( 'path' => '%USERPROFILE%\\.gemini\\antigravity\\mcp_config.json', 'label' => __( 'Windows', 'emcp-tools' ) ),
			),
			'template' => "{\n    \"mcpServers\": {\n        \"%NAME%\": {\n            \"command\": \"npx\",\n            \"args\": [\n                \"-y\",\n                \"mcp-remote\",\n                \"%ENDPOINT%\"\n            ]\n        }\n    }\n}",
		);
		$oauth_mcp_remote = array(
			'type' => 'cmd',
			'cmd'  => 'npx -y mcp-remote %ENDPOINT%',
		);
		// OpenClaw CLI — `openclaw mcp set <name> '<json>'` writes straight to
		// ~/.openclaw/openclaw.json (mcp.servers). Basic-auth via the headers map.
		$openclaw_cli   = 'openclaw mcp set %NAME% \'{"url":"%ENDPOINT%","transport":"streamable-http","headers":{"Authorization":"Basic %B64%"}}\'';
		// OpenClaw OAuth: same shape with auth:oauth; `openclaw mcp login` runs the flow.
		$oauth_openclaw = array(
			'type'      => 'config',
			'lang'      => 'json',
			'paths'     => array( array( 'path' => '~/.openclaw/openclaw.json', 'label' => '' ) ),
			// The "mcp" property (not a whole object) — openclaw.json usually has
			// other keys already; add this, or drop the server under an existing
			// mcp.servers.
			'template'  => "\"mcp\": {\n    \"servers\": {\n        \"%NAME%\": {\n            \"url\": \"%ENDPOINT%\",\n            \"transport\": \"streamable-http\",\n            \"auth\": \"oauth\"\n        }\n    }\n}",
			'merge_msg' => __( 'openclaw.json usually already has other settings. Add this "mcp" block, or if you already have one, add the server inside its "servers".', 'emcp-tools' ),
			'note'      => __( 'After saving, run  openclaw mcp login %NAME%  to authorize through your browser.', 'emcp-tools' ),
		);
		// Hermes uses ~/.hermes/config.yaml (mcp_servers). OAuth mode is url-only —
		// the server initiates the browser sign-in on first connect.
		$oauth_hermes = array(
			'type'     => 'config',
			'lang'     => 'yaml',
			'paths'    => array( array( 'path' => '~/.hermes/config.yaml', 'label' => '' ) ),
			'template' => "mcp_servers:\n  %NAME%:\n    url: \"%ENDPOINT%\"",
		);

		// Codex's "Connect to a custom MCP" UI form — a field-by-field mapping so
		// users know which Connection value goes where. %ENDPOINT%/%B64% are filled
		// with the live endpoint + Basic-auth token in JS (escaped). HTML tags are
		// kept outside the translation calls so they are not escaped.
		$codex_guide = '<p class="description">'
			. esc_html__( 'Prefer the ChatGPT App\'s UI? Choose “Connect to a custom MCP” → “Streamable HTTP”, then fill the form like this:', 'emcp-tools' )
			. '</p>'
			. '<table class="emcp-conn-guide"><tbody>'
			. '<tr><th>' . esc_html__( 'Name', 'emcp-tools' ) . '</th><td><code>%NAME%</code></td></tr>'
			. '<tr><th>' . esc_html__( 'Transport', 'emcp-tools' ) . '</th><td>' . esc_html__( 'Streamable HTTP', 'emcp-tools' ) . '</td></tr>'
			. '<tr><th>' . esc_html__( 'URL', 'emcp-tools' ) . '</th><td><code>%ENDPOINT%</code></td></tr>'
			. '<tr><th>' . esc_html__( 'Bearer token env var', 'emcp-tools' ) . '</th><td>' . esc_html__( 'Leave blank, EMCP uses a WordPress Application Password (HTTP Basic), not a bearer token.', 'emcp-tools' ) . '</td></tr>'
			. '<tr><th>' . esc_html__( 'Headers', 'emcp-tools' ) . '</th><td>' . esc_html__( 'Key', 'emcp-tools' ) . ' <code>Authorization</code> &middot; ' . esc_html__( 'Value', 'emcp-tools' ) . ' <code>Basic %B64%</code></td></tr>'
			. '</tbody></table>'
			. '<p class="description">' . esc_html__( 'Then Save. The config blocks below do the same thing, “direct HTTP” for the URL + header approach, or the “Node proxy / npx” config if the HTTP transport gives you handshake trouble.', 'emcp-tools' ) . '</p>';

		return array(
			array(
				'id'      => 'claude-desktop',
				'label'   => __( 'Claude Desktop', 'emcp-tools' ),
				'icon'    => 'desktop',
				'image'   => 'claude.png',
				'methods' => array( 'bundle' => true, 'cli' => null, 'ai_prompt' => true, 'json' => array( 'npx', 'http' ) ),
				'oauth'   => $oauth_claude_desktop,
			),
			array(
				'id'      => 'claude-ai',
				'label'   => __( 'Claude.ai', 'emcp-tools' ),
				'icon'    => 'admin-site-alt3',
				'image'   => 'claude.png',
				'methods' => array( 'bundle' => false, 'cli' => null, 'ai_prompt' => true, 'json' => array( 'remote' ) ),
				'oauth'   => $oauth_claude_ai,
			),
			array(
				'id'      => 'claude-code',
				'label'   => __( 'Claude Code', 'emcp-tools' ),
				'icon'    => 'editor-code',
				'image'   => 'claude.png',
				'methods' => array( 'bundle' => false, 'cli' => $claude_cli, 'ai_prompt' => false, 'json' => array( 'npx', 'http' ) ),
				'oauth'   => $oauth_claude_code,
			),
			array(
				'id'      => 'cursor',
				'label'   => __( 'Cursor', 'emcp-tools' ),
				'icon'    => 'editor-code',
				'image'   => 'cursor.png',
				'methods' => array( 'bundle' => false, 'cli' => null, 'ai_prompt' => true, 'json' => array( 'http' ) ),
				'oauth'   => $oauth_cursor,
			),
			array(
				'id'          => 'codex',
				'label'       => __( 'ChatGPT App', 'emcp-tools' ),
				'icon'        => 'editor-code',
				'image'       => 'gpt.png',
				'guide_title' => __( 'Using the ChatGPT App “Custom MCP” form', 'emcp-tools' ),
				'guide'       => $codex_guide,
				'methods'     => array( 'bundle' => false, 'cli' => $codex_cli, 'ai_prompt' => false, 'json' => array( 'toml', 'toml-stdio' ) ),
				'oauth'       => $oauth_codex,
			),
			array(
				'id'      => 'antigravity',
				'label'   => __( 'Antigravity', 'emcp-tools' ),
				'icon'    => 'editor-code',
				'image'   => 'antigravity.png',
				'methods' => array( 'bundle' => false, 'cli' => null, 'ai_prompt' => false, 'json' => array( 'http' ) ),
				'oauth'   => $oauth_antigravity,
			),
			array(
				'id'      => 'openclaw',
				'label'   => __( 'OpenClaw', 'emcp-tools' ),
				'icon'    => 'editor-code',
				'methods' => array( 'bundle' => false, 'cli' => $openclaw_cli, 'ai_prompt' => false, 'json' => array( 'openclaw-http', 'openclaw-npx' ) ),
				'oauth'   => $oauth_openclaw,
			),
			array(
				'id'      => 'hermes',
				'label'   => __( 'Hermes', 'emcp-tools' ),
				'icon'    => 'editor-code',
				'methods' => array( 'bundle' => false, 'cli' => null, 'ai_prompt' => false, 'json' => array( 'hermes-http', 'hermes-npx' ) ),
				'oauth'   => $oauth_hermes,
			),
			array(
				'id'      => 'mcp-remote',
				'label'   => __( 'npx mcp-remote', 'emcp-tools' ),
				'icon'    => 'admin-links',
				'methods' => array( 'bundle' => false, 'cli' => null, 'ai_prompt' => false, 'json' => array( 'remote' ) ),
				'oauth'   => $oauth_mcp_remote,
			),
		);
	}

	/**
	 * Group a tool-category map into one bucket per platform tab, preserving
	 * category order within each bucket. A category with a missing or unknown
	 * `platform` falls into the default ('elementor') bucket.
	 *
	 * @since 3.0.0
	 * @param array $categories Category map (id => category array) from get_all_tools().
	 * @return array<string,array> [ 'elementor' => [...], 'wordpress' => [...] ]
	 */
	public static function partition_by_platform( array $categories ): array {
		$buckets = array();
		foreach ( array_keys( self::platform_tabs() ) as $tab_id ) {
			$buckets[ $tab_id ] = array();
		}
		foreach ( $categories as $id => $cat ) {
			$platform = ( isset( $cat['platform'] ) && isset( $buckets[ $cat['platform'] ] ) ) ? $cat['platform'] : 'elementor';
			$buckets[ $platform ][ $id ] = $cat;
		}
		// Sort danger categories (filesystem/database) to the end of their tab —
		// the most powerful/destructive groups live at the bottom. Relative order
		// is otherwise preserved.
		foreach ( $buckets as $tab_id => $cats ) {
			$normal = array();
			$danger = array();
			foreach ( $cats as $id => $cat ) {
				if ( ! empty( $cat['danger'] ) ) {
					$danger[ $id ] = $cat;
				} else {
					$normal[ $id ] = $cat;
				}
			}
			$buckets[ $tab_id ] = $normal + $danger;
		}
		return $buckets;
	}

	/**
	 * Whether a tool category belongs to the Elementor platform (the default
	 * when no platform key is set), i.e. it is unavailable when Elementor
	 * is inactive.
	 *
	 * @since 3.0.0
	 *
	 * @param array $category A get_all_tools() category entry.
	 * @return bool
	 */
	public static function is_elementor_category( array $category ): bool {
		return 'elementor' === ( $category['platform'] ?? 'elementor' );
	}

	/**
	 * Whether the Astra theme integration's tools are available (Astra is the
	 * active parent theme). When false the admin greys out + disables the Astra
	 * toggles, the same way Elementor tools are gated when Elementor is inactive.
	 *
	 * @since 3.4.0
	 *
	 * @return bool
	 */
	public static function astra_available(): bool {
		return function_exists( 'get_template' ) && 'astra' === get_template();
	}

	/**
	 * Whether the Kadence theme integration's tools are available (Kadence is the
	 * active theme). When false the admin greys out + disables the Kadence
	 * theme-settings toggles.
	 *
	 * @since 3.9.0
	 * @return bool
	 */
	public static function kadence_available(): bool {
		return function_exists( 'get_template' ) && 'kadence' === get_template();
	}

	/**
	 * Whether the Kadence Blocks integration's tools are available (the Kadence
	 * Blocks plugin is active — independent of the active theme).
	 *
	 * @since 3.9.0
	 * @return bool
	 */
	public static function kadence_blocks_available(): bool {
		return class_exists( 'EMCP_Tools_Kadence_Blocks_Catalog' ) && EMCP_Tools_Kadence_Blocks_Catalog::is_active();
	}

	/**
	 * Whether the GeneratePress theme integration's tools are available
	 * (GeneratePress is the active theme; Pro).
	 *
	 * @since 3.9.1
	 * @return bool
	 */
	public static function generatepress_available(): bool {
		return function_exists( 'get_template' ) && 'generatepress' === get_template();
	}

	/**
	 * Whether the GenerateBlocks integration's tools are available (the
	 * GenerateBlocks plugin is active; Pro).
	 *
	 * @since 3.9.1
	 * @return bool
	 */
	public static function generateblocks_available(): bool {
		return class_exists( 'EMCP_Tools_GenerateBlocks_Catalog' ) && EMCP_Tools_GenerateBlocks_Catalog::is_active();
	}

	/**
	 * Whether the Blocksy blocks integration's tools are available (Blocksy
	 * Companion active; Pro).
	 *
	 * @since 3.9.1
	 * @return bool
	 */
	public static function blocksy_blocks_available(): bool {
		return class_exists( 'EMCP_Tools_Blocksy_Blocks_Catalog' ) && EMCP_Tools_Blocksy_Blocks_Catalog::is_active();
	}

	/**
	 * Whether the Blocksy extensions integration's tools are available (the Blocksy
	 * ExtensionsManager exists; Pro).
	 *
	 * @since 3.9.1
	 * @return bool
	 */
	public static function blocksy_extensions_available(): bool {
		return class_exists( '\\Blocksy\\ExtensionsManager' );
	}

	/**
	 * Whether the WooCommerce integration's tools are available (WooCommerce
	 * installed and active).
	 *
	 * @since 3.4.2
	 * @return bool
	 */
	public static function woo_available(): bool {
		return class_exists( 'WooCommerce' ) || function_exists( 'WC' );
	}

	/**
	 * Form-plugin availability — mirrors each adapter's is_active() so the admin
	 * card greys out its toggles when the plugin is inactive. Detection is
	 * reconciled with the adapter's own is_active() in the adapter tasks.
	 *
	 * @since 3.5.0
	 */
	public static function cf7_available(): bool {
		return class_exists( 'WPCF7_ContactForm' ) || defined( 'WPCF7_VERSION' );
	}

	/** @since 3.5.0 */
	public static function wpforms_available(): bool {
		return function_exists( 'wpforms' );
	}

	/** @since 3.5.0 */
	public static function gravityforms_available(): bool {
		return class_exists( 'GFForms' ) || class_exists( 'GFAPI' );
	}

	/** @since 3.5.0 */
	public static function fluentforms_available(): bool {
		return defined( 'FLUENTFORM_VERSION' ) || function_exists( 'wpFluentForm' );
	}

	/** @since 3.5.0 */
	public static function ninjaforms_available(): bool {
		return function_exists( 'Ninja_Forms' );
	}

	/** @since 3.5.0 */
	public static function formidable_available(): bool {
		return class_exists( 'FrmForm' ) || class_exists( 'FrmAppHelper' );
	}

	/** @since 3.5.0 */
	public static function metform_available(): bool {
		return defined( 'METFORM_VERSION' ) || post_type_exists( 'metform-form' );
	}

	/** @since 3.5.0 */
	public static function sureforms_available(): bool {
		return defined( 'SRFM_VER' ) || post_type_exists( 'sureforms_form' );
	}

	/** @since 3.8.0 */
	public static function forminator_available(): bool {
		return class_exists( 'Forminator_API' ) || defined( 'FORMINATOR_VERSION' );
	}

	/**
	 * SEO-plugin availability — mirrors each adapter's is_active() so the admin
	 * card greys out its toggles when the plugin is inactive. Reconciled with the
	 * adapter's own is_active() in the adapter tasks.
	 *
	 * @since 3.5.0
	 */
	public static function slimseo_available(): bool {
		return defined( 'SLIM_SEO_VER' ) || class_exists( '\\SlimSEO\\Plugin' );
	}

	/** @since 3.5.0 */
	public static function yoast_available(): bool {
		return defined( 'WPSEO_VERSION' );
	}

	/** @since 3.5.0 */
	public static function rankmath_available(): bool {
		return class_exists( 'RankMath' ) || defined( 'RANK_MATH_VERSION' );
	}

	/** @since 3.5.0 */
	public static function aioseo_available(): bool {
		return function_exists( 'aioseo' ) || defined( 'AIOSEO_VERSION' );
	}

	/** @since 3.5.0 */
	public static function seopress_available(): bool {
		return defined( 'SEOPRESS_VERSION' );
	}

	/** @since 3.5.0 */
	public static function seoframework_available(): bool {
		return defined( 'THE_SEO_FRAMEWORK_VERSION' ) || function_exists( 'tsf' );
	}

	/** @since 3.5.0 */
	public static function surerank_available(): bool {
		return defined( 'SURERANK_VERSION' ) || class_exists( '\\SureRank\\Inc\\Meta_Data' );
	}

	/**
	 * The 14 SEO dispatcher slugs — drift-guard exclusion (registered only when
	 * their plugin is active / Pro).
	 *
	 * @since 3.5.0
	 * @return string[]
	 */
	public static function seo_tool_slugs(): array {
		return array(
			'emcp-tools/slimseo-read',
			'emcp-tools/slimseo-write',
			'emcp-tools/yoast-read',
			'emcp-tools/yoast-write',
			'emcp-tools/rankmath-read',
			'emcp-tools/rankmath-write',
			'emcp-tools/aioseo-read',
			'emcp-tools/aioseo-write',
			'emcp-tools/seopress-read',
			'emcp-tools/seopress-write',
			'emcp-tools/seoframework-read',
			'emcp-tools/seoframework-write',
			'emcp-tools/surerank-read',
			'emcp-tools/surerank-write',
		);
	}

	/**
	 * The 12 Forms dispatcher slugs — drift-guard exclusion (registered only when
	 * their plugin is active / Pro, so the drift guard must not flag them as
	 * "missing" tools).
	 *
	 * @since 3.5.0
	 * @return string[]
	 */
	/**
	 * Elementor addon-domain tool slugs (Pro).
	 *
	 * The two widget packs contribute a single read tool each: they exist for
	 * discovery and curation, because their widgets are placed with the generic
	 * add-free-widget tool. HFE is a data plugin and keeps the read/write pair.
	 *
	 * @since 3.6.0
	 * @return string[]
	 */
	public static function addon_tool_slugs(): array {
		return array(
			'emcp-tools/essential-addons-read',
			'emcp-tools/premium-addons-read',
			'emcp-tools/uae-read',
			'emcp-tools/uae-write',
		);
	}

	/**
	 * The short status a card shows when a tool needs software that is missing.
	 *
	 * A greyed-out toggle looks identical whether the admin switched a tool off
	 * or the tool cannot be switched on at all, and users read the second case as
	 * "broken" or "not in my plan". Naming the missing dependency on the card
	 * removes that ambiguity at a glance.
	 *
	 * @since 3.14.0
	 * @param array $tool A tool entry from the catalog.
	 * @return string Badge text, or '' when the tool is available.
	 */
	public static function requirement_badge( array $tool ): string {
		$name = isset( $tool['requires']['name'] ) ? (string) $tool['requires']['name'] : '';
		if ( '' !== $name ) {
			/* translators: %s: the name of a required plugin or theme */
			return sprintf( __( 'Needs %s', 'emcp-tools' ), $name );
		}
		// Pro-locked tools already say so in their own badge, so a second one
		// would be noise.
		if ( in_array( 'pro', (array) ( $tool['badges'] ?? array() ), true ) ) {
			return '';
		}
		return __( 'Unavailable', 'emcp-tools' );
	}

	/**
	 * The sentence explaining what to do about a missing dependency.
	 *
	 * Built from the recorded name rather than written out per tool, so the two
	 * phrasings are translated once instead of twenty-seven times.
	 *
	 * @since 3.14.0
	 * @param array $tool A tool entry from the catalog.
	 * @return string
	 */
	public static function requirement_note( array $tool ): string {
		if ( ! empty( $tool['unavailable_note'] ) ) {
			return (string) $tool['unavailable_note'];
		}
		$name = isset( $tool['requires']['name'] ) ? (string) $tool['requires']['name'] : '';
		if ( '' === $name ) {
			return '';
		}
		if ( 'theme' === ( $tool['requires']['kind'] ?? 'plugin' ) ) {
			/* translators: %s: theme name */
			return sprintf( __( 'This tool works with the %s theme, which is not the active theme on this site.', 'emcp-tools' ), $name );
		}
		/* translators: %s: plugin name */
		return sprintf( __( 'This tool reads %s, which is not installed and active on this site.', 'emcp-tools' ), $name );
	}

	/**
	 * True when Essential Addons (Lite or Pro) is active.
	 *
	 * @since 3.6.0
	 * @return bool
	 */
	public static function essential_addons_available(): bool {
		return defined( 'EAEL_PLUGIN_VERSION' )
			|| class_exists( '\Essential_Addons_Elementor\Classes\Bootstrap' );
	}

	/**
	 * True when Premium Addons is active.
	 *
	 * @since 3.6.0
	 * @return bool
	 */
	public static function premium_addons_available(): bool {
		return defined( 'PREMIUM_ADDONS_VERSION' )
			|| defined( 'PREMIUM_ADDONS_FILE' )
			|| class_exists( 'PremiumAddons\Includes\Addons_Integration' );
	}

	/**
	 * True when the free Ultimate Addons for Elementor plugin (formerly Header
	 * Footer Elementor) is active. Its own identifiers still say HFE.
	 *
	 * This is what gates TEMPLATES: the `elementor-hf` CPT and its `ehf_*`
	 * display-condition meta belong to the free plugin.
	 *
	 * @since 3.6.2
	 * @return bool
	 */
	public static function uae_templates_available(): bool {
		return class_exists( 'Header_Footer_Elementor' ) || post_type_exists( 'elementor-hf' );
	}

	/**
	 * True when UAE Pro is active.
	 *
	 * UAE Pro ("Ultimate Addons for Elementor Pro", slug `ultimate-elementor`)
	 * is a SEPARATE standalone plugin, not an add-on to the free one, and can be
	 * installed on its own.
	 *
	 * @since 3.6.2
	 * @return bool
	 */
	public static function uae_pro_available(): bool {
		return defined( 'UAEL_VER' ) || class_exists( 'UAEL_Loader' );
	}

	/**
	 * True when either UAE plugin is active.
	 *
	 * @since 3.6.0
	 * @return bool
	 */
	public static function uae_available(): bool {
		return self::uae_templates_available() || self::uae_pro_available();
	}

	public static function form_tool_slugs(): array {
		return array(
			'emcp-tools/cf7-read',
			'emcp-tools/cf7-write',
			'emcp-tools/wpforms-read',
			'emcp-tools/wpforms-write',
			'emcp-tools/gravityforms-read',
			'emcp-tools/gravityforms-write',
			'emcp-tools/fluentforms-read',
			'emcp-tools/fluentforms-write',
			'emcp-tools/ninjaforms-read',
			'emcp-tools/ninjaforms-write',
			'emcp-tools/formidable-read',
			'emcp-tools/formidable-write',
			'emcp-tools/metform-read',
			'emcp-tools/metform-write',
			'emcp-tools/sureforms-read',
			'emcp-tools/sureforms-write',
			'emcp-tools/forminator-read',
			'emcp-tools/forminator-write',
		);
	}

	/**
	 * Whether Freemius's Affiliation page actually exists right now.
	 *
	 * We hide the Affiliation submenu (see the `is_submenu_visible` filter in
	 * the bootstrap) and link to it from the header instead. Hiding keeps the
	 * page URL-reachable, BUT Freemius only *registers* its submenu pages when
	 * `should_add_submenu_or_action_links()` passes — which is false in
	 * **activation mode**. A fresh install (free especially) sits in activation
	 * mode until the user opts in or skips, so the page doesn't exist yet and
	 * linking to it yields "Sorry, you are not allowed to access this page."
	 * Mirror Freemius's own condition so the link only shows when it works.
	 *
	 * @since 3.4.2
	 * @return bool
	 */
	public static function affiliation_page_available(): bool {
		if ( ! function_exists( 'emcp_tools_fs' ) ) {
			return false;
		}
		$fs = emcp_tools_fs();
		return $fs->has_affiliate_program() && ! $fs->is_activation_mode();
	}

	/**
	 * Whether the Spectra Blocks integration's tools are available (the Spectra
	 * plugin — Ultimate Addons for Gutenberg — is installed and active).
	 *
	 * @since 3.4.0
	 *
	 * @return bool
	 */
	public static function spectra_available(): bool {
		return class_exists( 'EMCP_Tools_Spectra_Catalog' ) && EMCP_Tools_Spectra_Catalog::is_active();
	}

	/**
	 * Whether Spectra is set to generate separate CSS/JS files (as opposed to its
	 * default inline CSS). In file mode, pages an AI builds or edits over MCP can
	 * render with stale cached CSS until the assets are regenerated — so the combo
	 * section shows a heads-up to switch to inline while building.
	 *
	 * @since 3.4.0
	 *
	 * @return bool
	 */
	public static function spectra_file_generation_on(): bool {
		if ( ! self::spectra_available() ) {
			return false;
		}
		// Spectra's default is inline CSS; treat an absent option as inline.
		if ( class_exists( 'UAGB_Admin_Helper' ) && method_exists( 'UAGB_Admin_Helper', 'get_admin_settings_option' ) ) {
			return 'enabled' === UAGB_Admin_Helper::get_admin_settings_option( '_uagb_allow_file_generation', 'disabled' );
		}
		return 'enabled' === get_option( '_uagb_allow_file_generation', 'disabled' );
	}

	/**
	 * The Astra + Spectra section notice, or null. Returns an actionable warning
	 * only when Spectra's separate-file CSS generation is on (the state that
	 * causes stale styling for AI-built pages).
	 *
	 * @since 3.4.0
	 *
	 * @return array{type:string,message:string}|null
	 */
	public static function spectra_file_generation_notice(): ?array {
		if ( ! self::spectra_file_generation_on() ) {
			return null;
		}
		return array(
			'type'    => 'warning',
			'message' => __( 'Spectra is set to generate separate CSS files. When an AI builds or edits pages over MCP, those cached files can go stale and a page may look unstyled until they are rebuilt. While building with AI, turn OFF Spectra → Settings → Asset Generation → File Generation (use inline CSS), or click "Regenerate Assets" there after edits.', 'emcp-tools' ),
		);
	}

	/**
	 * Returns the categories with the Elementor-platform ones removed. Used for
	 * truthful tool counts when Elementor is inactive (those tools never register).
	 *
	 * @since 3.0.0
	 *
	 * @param array $categories get_all_tools() output.
	 * @return array
	 */
	public static function filter_out_elementor( array $categories ): array {
		return array_filter(
			$categories,
			static function ( $cat ) {
				return ! self::is_elementor_category( $cat );
			}
		);
	}

	/**
	 * Get all tools grouped by category for the UI.
	 *
	 * Returns the curated catalog (see get_tool_catalog()) and, under WP_DEBUG,
	 * cross-checks it against the live ability registry so the hand-maintained
	 * catalog can't silently drift from the actually-registered tools (F-019).
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{label: string, tools: array<string, array{label: string, description: string, badges: string[]}>}> Grouped tools.
	 */
	public function get_all_tools(): array {
		$catalog = $this->get_tool_catalog();

		// F-019 drift guard: the catalog carries admin-UI metadata (labels,
		// descriptions, badges) the bare ability registry doesn't have, so it
		// stays curated rather than derived. To stop it drifting, cross-check
		// each catalog slug against the live registry and log any that isn't a
		// registered ability (a renamed/removed tool, or env-gated).
		if ( defined( 'WP_DEBUG' ) && WP_DEBUG && class_exists( 'WP_Abilities_Registry' ) ) {
			$emcp_registry = WP_Abilities_Registry::get_instance();
			// Tools that only register when their module/feature/flag is on are
			// legitimately absent — skip them so the guard flags genuine drift
			// (renamed/removed tools) and not expected environment-gating.
			$emcp_conditional = array_merge(
				self::cloud_tool_slugs(),
				self::themer_tool_slugs(),
				self::themer_php_tool_slugs(),
				self::acf_tool_slugs(),
				self::woo_tool_slugs(),
				self::metabox_tool_slugs(),
				self::form_tool_slugs(),
				self::seo_tool_slugs(),
				self::addon_tool_slugs(),
				self::theme_tool_slugs(),
				self::seo_a11y_tool_slugs(),
				self::widget_builder_tool_slugs(),
				self::block_tool_slugs(),
				self::memory_tool_slugs(),
				self::redirect_tool_slugs(),
				self::migrate_tool_slugs(),
				array( 'emcp-tools/list-redirects', 'emcp-tools/find-broken-links', 'emcp-tools/resize-media' )
			);
			foreach ( $catalog as $emcp_group ) {
				foreach ( array_keys( $emcp_group['tools'] ?? array() ) as $emcp_slug ) {
					// is_registered() is a silent isset() check — unlike wp_get_ability()
					// / get_registered(), it does not _doing_it_wrong() "Ability not
					// found" for env-gated tools, which was flooding debug.log (#71).
					if ( ! $emcp_registry->is_registered( $emcp_slug )
						&& ! in_array( $emcp_slug, $emcp_conditional, true ) ) {
						// phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log
						error_log( '[EMCP Tools] get_all_tools: catalog tool "' . $emcp_slug . '" is not in the ability registry (drift or environment-gated).' );
					}
				}
			}
		}

		// Pro sections are always present in the catalog so free users see the
		// (locked) Pro surface. On a build without a usable Pro license, lock
		// every tool in a `pro` category — disable its toggle and swap in a
		// "Requires EMCP Pro" note — and ensure it carries the `pro` badge. On a
		// licensed build the category's own availability (e.g. WooCommerce active)
		// is left untouched, and the abilities themselves stay license-gated.
		$emcp_is_pro = function_exists( 'emcp_tools_fs' ) && emcp_tools_fs()->can_use_premium_code();
		foreach ( $catalog as &$emcp_pro_cat ) {
			if ( empty( $emcp_pro_cat['pro'] ) || empty( $emcp_pro_cat['tools'] ) ) {
				continue;
			}
			foreach ( $emcp_pro_cat['tools'] as &$emcp_pro_tool ) {
				if ( empty( $emcp_pro_tool['badges'] ) || ! is_array( $emcp_pro_tool['badges'] ) ) {
					$emcp_pro_tool['badges'] = array();
				}
				if ( ! in_array( 'pro', $emcp_pro_tool['badges'], true ) ) {
					array_unshift( $emcp_pro_tool['badges'], 'pro' );
				}
				if ( ! $emcp_is_pro ) {
					$emcp_pro_tool['available']        = false;
					$emcp_pro_tool['unavailable_note'] = __( 'Requires EMCP Pro.', 'emcp-tools' );
				}
			}
			unset( $emcp_pro_tool );
		}
		unset( $emcp_pro_cat );

		return $catalog;
	}

	/**
	 * The curated admin tool catalog: every tool grouped by category with its
	 * label, description, and badges for the Tools admin screen. This is the
	 * source of the admin-UI metadata; get_all_tools() keeps it honest against
	 * the ability registry.
	 *
	 * @since 1.0.0
	 *
	 * @return array<string, array{label: string, tools: array<string, array{label: string, description: string, badges: string[]}>}> Grouped tools.
	 */
	private function get_tool_catalog(): array {
		$tools = array(
			'query'            => array(
				'platform' => 'elementor',
				'label' => __( 'Query & Discovery', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/list-widgets'         => array(
						'label'       => __( 'List Widgets', 'emcp-tools' ),
						'description' => __( 'Lists all available Elementor widget types and their names.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/get-widget-schema'    => array(
						'label'       => __( 'Get Widget Schema', 'emcp-tools' ),
						'description' => __( 'Returns the JSON schema for a specific widget type.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/get-page-structure'   => array(
						'label'       => __( 'Get Page Structure', 'emcp-tools' ),
						'description' => __( 'Returns the full Elementor element tree for a page.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/get-page-snapshot'    => array(
						'label'       => __( 'Get Page Snapshot', 'emcp-tools' ),
						'description' => __( 'One normalized page digest: structure, tokens-in-use, responsive overrides, content outline, SEO-lite (+ opt-in performance/a11y/seo).', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/get-element-settings' => array(
						'label'       => __( 'Get Element Settings', 'emcp-tools' ),
						'description' => __( 'Returns the settings of a specific element by ID.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/list-pages'           => array(
						'label'       => __( 'List Pages', 'emcp-tools' ),
						'description' => __( 'Lists all pages/posts that use Elementor.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/list-templates'       => array(
						'label'       => __( 'List Templates', 'emcp-tools' ),
						'description' => __( 'Lists all saved Elementor templates.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/get-global-settings'  => array(
						'label'       => __( 'Get Global Settings', 'emcp-tools' ),
						'description' => __( 'Returns global colors, typography, and theme settings.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
				),
			),
			'redirects'        => array(
				'platform' => 'modules',
				'label' => __( 'Redirects', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/list-redirects'    => array(
						'label'       => __( 'List Redirects', 'emcp-tools' ),
						'description' => __( 'Lists the site\'s managed 301/302 redirects (source → target, code, hits).', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/find-broken-links' => array(
						'label'       => __( 'Find Broken Links', 'emcp-tools' ),
						'description' => __( 'Scans published content for internal links to dead or already-redirected URLs. Read-only.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/create-redirect'   => array(
						'label'       => __( 'Create Redirect', 'emcp-tools' ),
						'description' => __( 'Creates a 301/302 redirect from an old path to a target URL or post. Disabled by default.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/update-redirect'   => array(
						'label'       => __( 'Update Redirect', 'emcp-tools' ),
						'description' => __( 'Updates an existing redirect by id. Disabled by default.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/delete-redirect'   => array(
						'label'       => __( 'Delete Redirect', 'emcp-tools' ),
						'description' => __( 'Deletes a redirect by id. Reversible from History. Disabled by default.', 'emcp-tools' ),
						'badges'      => array( 'destructive' ),
					),
				),
			),
			'migrate'          => array(
				'platform' => 'modules',
				'label' => __( 'Backup & Migrate', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/create-backup' => array(
						'label'       => __( 'Create Backup', 'emcp-tools' ),
						'description' => __( 'Creates a portable .emcp backup (full/database/files) and returns its id + size. Non-destructive.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/list-backups'  => array(
						'label'       => __( 'List Backups', 'emcp-tools' ),
						'description' => __( 'Lists this site\'s .emcp backups. Read-only.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/migrate-site'  => array(
						'label'       => __( 'Migrate Site to Live', 'emcp-tools' ),
						'description' => __( 'Pushes this whole site to a paired live target and restores it there. Destructive on the destination; requires confirm. Disabled by default.', 'emcp-tools' ),
						'badges'      => array( 'destructive' ),
					),
					'emcp-tools/sync-to-live'  => array(
						'label'       => __( 'Sync to Live', 'emcp-tools' ),
						'description' => __( 'Pushes a full or selective scope (chosen tables/files) to a paired live target. Destructive for the pushed scope; requires confirm. Disabled by default.', 'emcp-tools' ),
						'badges'      => array( 'destructive' ),
					),
					'emcp-tools/list-syncable-changes' => array(
						'label'       => __( 'List Syncable Changes', 'emcp-tools' ),
						'description' => __( 'Lists pages/posts/CPTs changed locally since they were last synced to a paired live target. Read-only.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/sync-content-item' => array(
						'label'       => __( 'Sync Content Item to Live', 'emcp-tools' ),
						'description' => __( 'Pushes one page/post/CPT (content + fields + attached media) to a paired live target, upserting it and remapping media. Overwrites only that item; requires confirm. Disabled by default.', 'emcp-tools' ),
						'badges'      => array( 'destructive' ),
					),
					'emcp-tools/discard-sync-change' => array(
						'label'       => __( 'Discard Sync Change', 'emcp-tools' ),
						'description' => __( 'Dismisses an item from the changes-to-sync list until it changes again. Local only.', 'emcp-tools' ),
						'badges'      => array(),
					),
				),
			),
			'gutenberg_blocks' => array(
				'platform' => 'gutenberg',
				'label' => __( 'Gutenberg Blocks', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/list-blocks'      => array(
						'label'       => __( 'List Blocks', 'emcp-tools' ),
						'description' => __( 'Lists registered block types (name, title, category).', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/get-block-schema' => array(
						'label'       => __( 'Get Block Schema', 'emcp-tools' ),
						'description' => __( 'Returns a block\'s attributes, supports, and a markup example.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/get-post-blocks'  => array(
						'label'       => __( 'Get Post Blocks', 'emcp-tools' ),
						'description' => __( 'Returns a post\'s block tree with an index path per block.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/list-patterns'    => array(
						'label'       => __( 'List Patterns', 'emcp-tools' ),
						'description' => __( 'Lists registered block patterns.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/add-block'        => array(
						'label'       => __( 'Add Block', 'emcp-tools' ),
						'description' => __( 'Inserts block markup into a post at a position.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/update-block'     => array(
						'label'       => __( 'Update Block', 'emcp-tools' ),
						'description' => __( 'Replaces the block at an index path with new markup.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/remove-block'     => array(
						'label'       => __( 'Remove Block', 'emcp-tools' ),
						'description' => __( 'Deletes the block at an index path.', 'emcp-tools' ),
						'badges'      => array( 'destructive' ),
					),
					'emcp-tools/move-block'       => array(
						'label'       => __( 'Move Block', 'emcp-tools' ),
						'description' => __( 'Moves a block to a new position.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/duplicate-block'  => array(
						'label'       => __( 'Duplicate Block', 'emcp-tools' ),
						'description' => __( 'Clones the block at a path and inserts the copy after it.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/insert-pattern'   => array(
						'label'       => __( 'Insert Pattern', 'emcp-tools' ),
						'description' => __( 'Inserts a registered block pattern into a post.', 'emcp-tools' ),
						'badges'      => array(),
					),
				),
			),
			'wp_nav_menus'     => array(
				'platform' => 'wordpress',
				'label' => __( 'Navigation Menus', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/menu-read'  => array(
						'label'       => __( 'Menu Read', 'emcp-tools' ),
						'description' => __( 'Read nav menus: list menus, get a menu\'s nested item tree, list theme locations, render a menu to HTML. Call with no operation to list read operations.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/menu-write' => array(
						'label'       => __( 'Menu Write', 'emcp-tools' ),
						'description' => __( 'Manage nav menus: create/rename/delete menus, assign theme locations, and add/update/delete/reorder items. Call with no operation to list write operations.', 'emcp-tools' ),
						'badges'      => array(),
					),
				),
			),
			'wp_content'       => array(
				'platform' => 'wordpress',
				'label' => __( 'WordPress Content', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/list-post-types' => array(
						'label'       => __( 'List Post Types', 'emcp-tools' ),
						'description' => __( 'Lists registered post types (posts, pages, CPTs).', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/list-taxonomies' => array(
						'label'       => __( 'List Taxonomies', 'emcp-tools' ),
						'description' => __( 'Lists taxonomies and optionally their terms.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/create-post'     => array(
						'label'       => __( 'Create Post', 'emcp-tools' ),
						'description' => __( 'Creates a post/page/CPT with content, terms, meta, featured image.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/get-post'        => array(
						'label'       => __( 'Get Post', 'emcp-tools' ),
						'description' => __( 'Returns a post\'s content, terms, meta, and featured image.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/get-page-html'   => array(
						'label'       => __( 'Get Page HTML', 'emcp-tools' ),
						'description' => __( 'Fetches chunked public front-end response HTML from this site. JavaScript is not executed.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/update-post'     => array(
						'label'       => __( 'Update Post', 'emcp-tools' ),
						'description' => __( 'Partial update of a post/page/CPT.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/list-posts'      => array(
						'label'       => __( 'List Posts', 'emcp-tools' ),
						'description' => __( 'Lists/searches posts, pages, or any CPT (compact).', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/delete-post'     => array(
						'label'       => __( 'Delete Post', 'emcp-tools' ),
						'description' => __( 'Trashes (or force-deletes) a post.', 'emcp-tools' ),
						'badges'      => array( 'destructive' ),
					),
					'emcp-tools/set-post-terms'  => array(
						'label'       => __( 'Set Post Terms', 'emcp-tools' ),
						'description' => __( 'Assigns category/tag/custom terms to a post.', 'emcp-tools' ),
						'badges'      => array(),
					),
				),
			),
			'wp_settings'      => array(
				'platform' => 'wordpress',
				'label' => __( 'WordPress Settings', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/get-settings'    => array(
						'label'       => __( 'Get Settings', 'emcp-tools' ),
						'description' => __( 'Reads curated site settings (general, reading, writing, discussion, media, permalinks).', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/update-settings' => array(
						'label'       => __( 'Update Settings', 'emcp-tools' ),
						'description' => __( 'Updates curated site settings; auto-flushes rewrite rules on permalink changes.', 'emcp-tools' ),
						'badges'      => array(),
					),
				),
			),
			'performance'      => array(
				'platform' => 'wordpress',
				'label' => __( 'Performance & Security', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/analyze-performance' => array(
						'label'       => __( 'Analyze Performance', 'emcp-tools' ),
						'description' => __( 'Audits server config, WordPress internals, and a target page; returns a scored report with recommendations.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/scan-security' => array(
						'label'       => __( 'Scan Security', 'emcp-tools' ),
						'description' => __( 'Scans for malware heuristics, core file integrity, configuration hardening, and outdated/abandoned software; returns a scored report with recommendations.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
				),
			),
			'filesystem'       => array(
				'platform' => 'wordpress',
				'danger'   => true,
				'label' => __( 'Filesystem', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/read-file'      => array( 'label' => __( 'Read File', 'emcp-tools' ),      'description' => __( 'Read a file in the WordPress install.', 'emcp-tools' ),          'badges' => array( 'read-only' ) ),
					'emcp-tools/list-directory' => array( 'label' => __( 'List Directory', 'emcp-tools' ), 'description' => __( 'List a directory in the WordPress install.', 'emcp-tools' ),      'badges' => array( 'read-only' ) ),
					'emcp-tools/search-files'   => array( 'label' => __( 'Search Files', 'emcp-tools' ),   'description' => __( 'Search file contents across the install.', 'emcp-tools' ),        'badges' => array( 'read-only' ) ),
					'emcp-tools/write-file'     => array( 'label' => __( 'Write File', 'emcp-tools' ),     'description' => __( 'Create/overwrite a file (backs up first). Disabled by default.', 'emcp-tools' ), 'badges' => array() ),
					'emcp-tools/edit-file'      => array( 'label' => __( 'Edit File', 'emcp-tools' ),      'description' => __( 'Replace a string in a file (backs up first). Disabled by default.', 'emcp-tools' ),  'badges' => array() ),
					'emcp-tools/delete-file'    => array( 'label' => __( 'Delete File', 'emcp-tools' ),    'description' => __( 'Delete a file (backs up; needs confirm). Disabled by default.', 'emcp-tools' ),     'badges' => array() ),
				),
			),
			'database'         => array(
				'platform' => 'wordpress',
				'danger'   => true,
				'label' => __( 'Database', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/list-tables'    => array( 'label' => __( 'List Tables', 'emcp-tools' ),    'description' => __( 'List database tables with sizes.', 'emcp-tools' ),                'badges' => array( 'read-only' ) ),
					'emcp-tools/describe-table' => array( 'label' => __( 'Describe Table', 'emcp-tools' ), 'description' => __( 'Show a table\'s columns and keys.', 'emcp-tools' ),               'badges' => array( 'read-only' ) ),
					'emcp-tools/query'          => array( 'label' => __( 'Query (read-only)', 'emcp-tools' ), 'description' => __( 'Run a read-only SQL query (SELECT/SHOW/etc.).', 'emcp-tools' ), 'badges' => array( 'read-only' ) ),
					'emcp-tools/insert-row'     => array( 'label' => __( 'Insert Row', 'emcp-tools' ),     'description' => __( 'Insert a row (parameterized). Disabled by default.', 'emcp-tools' ),   'badges' => array() ),
					'emcp-tools/update-rows'    => array( 'label' => __( 'Update Rows', 'emcp-tools' ),    'description' => __( 'Update rows matching a WHERE. Disabled by default.', 'emcp-tools' ),   'badges' => array() ),
					'emcp-tools/delete-rows'    => array( 'label' => __( 'Delete Rows', 'emcp-tools' ),    'description' => __( 'Delete rows matching a WHERE (confirm). Disabled by default.', 'emcp-tools' ), 'badges' => array() ),
				),
			),
			'wpcli'            => array(
				'platform' => 'wordpress',
				'danger'   => true,
				'label' => __( 'WP-CLI', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/run-wp-cli'       => array( 'label' => __( 'Run WP-CLI Command', 'emcp-tools' ), 'description' => __( 'Run a wp-cli command (blocklist-guarded: no eval/shell/raw-SQL/config-writes). Disabled by default.', 'emcp-tools' ), 'badges' => array() ),
					'emcp-tools/dispatch-wp-cli'  => array( 'label' => __( 'Dispatch WP-CLI Job', 'emcp-tools' ), 'description' => __( 'Run a wp-cli command as a detached background job (long migrations / bulk tasks). Disabled by default.', 'emcp-tools' ), 'badges' => array() ),
					'emcp-tools/get-wp-cli-job'   => array( 'label' => __( 'Get WP-CLI Job', 'emcp-tools' ), 'description' => __( 'Poll a background job\'s status, exit code, and output.', 'emcp-tools' ), 'badges' => array( 'read-only' ) ),
					'emcp-tools/list-wp-cli-jobs' => array( 'label' => __( 'List WP-CLI Jobs', 'emcp-tools' ), 'description' => __( 'List recent WP-CLI background jobs.', 'emcp-tools' ), 'badges' => array( 'read-only' ) ),
				),
			),
			'transactions'     => array(
				'platform' => 'modules',
				'label' => __( 'Changes & Rollback', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/list-changes'    => array( 'label' => __( 'List Changes', 'emcp-tools' ),    'description' => __( 'List recent AI-made changes (Elementor/filesystem/database), newest first.', 'emcp-tools' ), 'badges' => array( 'read-only' ) ),
					'emcp-tools/get-change'      => array( 'label' => __( 'Get Change', 'emcp-tools' ),      'description' => __( 'Full detail of one change-ledger entry, including its rollback reference.', 'emcp-tools' ), 'badges' => array( 'read-only' ) ),
					'emcp-tools/rollback-change' => array( 'label' => __( 'Roll Back Change', 'emcp-tools' ), 'description' => __( 'Undo one recorded change by id (page/file/database). Only reverts changes EMCP recorded.', 'emcp-tools' ), 'badges' => array() ),
				),
			),
			'search'           => array(
				'platform' => 'modules',
				'label' => __( 'Content Search', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/search-content'  => array( 'label' => __( 'Search Content', 'emcp-tools' ),  'description' => __( 'Search the site\'s pages, templates, widgets, and global styles to reuse existing content.', 'emcp-tools' ), 'badges' => array( 'read-only' ) ),
					'emcp-tools/reindex-search'  => array( 'label' => __( 'Reindex Search', 'emcp-tools' ),  'description' => __( 'Rebuild the content-search index (also updates on save).', 'emcp-tools' ), 'badges' => array() ),
				),
			),
			'content_mirror'   => array(
				'platform' => 'modules',
				'label' => __( 'Content Mirror (Git)', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/export-content'        => array( 'label' => __( 'Export Content', 'emcp-tools' ),        'description' => __( 'Export page/template content to git-trackable JSON files.', 'emcp-tools' ), 'badges' => array() ),
					'emcp-tools/restore-content'       => array( 'label' => __( 'Restore Content', 'emcp-tools' ),       'description' => __( 'Restore a page/template from its mirror file (file-based undo).', 'emcp-tools' ), 'badges' => array() ),
					'emcp-tools/list-content-exports'  => array( 'label' => __( 'List Content Exports', 'emcp-tools' ), 'description' => __( 'List the mirror files on disk.', 'emcp-tools' ), 'badges' => array( 'read-only' ) ),
				),
			),
			'wp_packages'      => array(
				'platform' => 'wordpress',
				'label' => __( 'Plugins & Themes', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/list-plugins'      => array(
						'label'       => __( 'List Plugins', 'emcp-tools' ),
						'description' => __( 'Lists installed plugins, status, versions, and updates.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/search-plugins'    => array(
						'label'       => __( 'Search Plugins', 'emcp-tools' ),
						'description' => __( 'Searches the wordpress.org plugin directory.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/install-plugin'    => array(
						'label'       => __( 'Install Plugin', 'emcp-tools' ),
						'description' => __( 'Installs a plugin from wordpress.org by slug.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/activate-plugin'   => array(
						'label'       => __( 'Activate Plugin', 'emcp-tools' ),
						'description' => __( 'Activates an installed plugin.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/deactivate-plugin' => array(
						'label'       => __( 'Deactivate Plugin', 'emcp-tools' ),
						'description' => __( 'Deactivates a plugin (never EMCP Tools or Elementor).', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/update-plugin'     => array(
						'label'       => __( 'Update Plugin', 'emcp-tools' ),
						'description' => __( 'Updates a plugin to the latest wordpress.org version.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/delete-plugin'     => array(
						'label'       => __( 'Delete Plugin', 'emcp-tools' ),
						'description' => __( 'Permanently deletes an inactive plugin.', 'emcp-tools' ),
						'badges'      => array( 'destructive' ),
					),
					'emcp-tools/list-themes'       => array(
						'label'       => __( 'List Themes', 'emcp-tools' ),
						'description' => __( 'Lists installed themes, active status, and updates.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/search-themes'     => array(
						'label'       => __( 'Search Themes', 'emcp-tools' ),
						'description' => __( 'Searches the wordpress.org theme directory.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/install-theme'     => array(
						'label'       => __( 'Install Theme', 'emcp-tools' ),
						'description' => __( 'Installs a theme from wordpress.org by slug.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/switch-theme'      => array(
						'label'       => __( 'Switch Theme', 'emcp-tools' ),
						'description' => __( 'Activates an installed theme.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/update-theme'      => array(
						'label'       => __( 'Update Theme', 'emcp-tools' ),
						'description' => __( 'Updates a theme to the latest wordpress.org version.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/delete-theme'      => array(
						'label'       => __( 'Delete Theme', 'emcp-tools' ),
						'description' => __( 'Permanently deletes an inactive theme.', 'emcp-tools' ),
						'badges'      => array( 'destructive' ),
					),
				),
			),
			'wp_users'         => array(
				'platform' => 'wordpress',
				'label' => __( 'Users', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/list-users'   => array(
						'label'       => __( 'List Users', 'emcp-tools' ),
						'description' => __( 'Lists users (admin-only); filter by role/search.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/get-user'     => array(
						'label'       => __( 'Get User', 'emcp-tools' ),
						'description' => __( 'Returns one user\'s profile detail.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/create-user'  => array(
						'label'       => __( 'Create User', 'emcp-tools' ),
						'description' => __( 'Creates a non-admin user; auto-password + email.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/update-user'  => array(
						'label'       => __( 'Update User', 'emcp-tools' ),
						'description' => __( 'Edits a non-admin user\'s profile (no role/password; admins refused).', 'emcp-tools' ),
						'badges'      => array(),
					),
				),
			),
			'wp_acf'           => array(
				'platform' => 'plugins',
				'group'    => 'dynamic',
				'label'    => __( 'ACF (Advanced Custom Fields)', 'emcp-tools' ),
				'note'     => __( 'Plugin integrations are exposed as two tools, one Read, one Write. The AI calls a tool with an operation name; each tool bundles the operations listed on its card. Toggle a tool to allow or block all of its operations at once. Post-type & taxonomy operations need ACF 6.1+.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/acf-read'  => array(
						'label'       => __( 'ACF Read', 'emcp-tools' ),
						'description' => __( 'Read Advanced Custom Fields data, field groups, field values, options pages, and (ACF 6.1+) ACF-managed post types and taxonomies.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
						'operations'  => array(
							'list-field-groups',
							'get-field-group',
							'list-options-pages',
							'get-fields',
							'list-post-types',
							'get-post-type',
							'list-taxonomies',
							'get-taxonomy',
						),
					),
					'emcp-tools/acf-write' => array(
						'label'       => __( 'ACF Write', 'emcp-tools' ),
						'description' => __( 'Write Advanced Custom Fields data, field values, field groups, and (ACF 6.1+) ACF-managed post types and taxonomies. No delete operations; slugs and field keys are immutable.', 'emcp-tools' ),
						'badges'      => array(),
						'operations'  => array(
							'update-fields',
							'create-field-group',
							'update-field-group',
							'create-post-type',
							'update-post-type',
							'create-taxonomy',
							'update-taxonomy',
						),
					),
				),
			),
			'wp_woo'           => array(
				'platform' => 'plugins',
				'group'    => 'ecommerce',
				'pro'      => true,
				'label'    => __( 'WooCommerce', 'emcp-tools' ),
				'note'     => __( 'WooCommerce is exposed as two tools, one Read and one Write, over wc/v3. Native WooCommerce Brands add list/get/create/update operations with validated existing image attachments and no-write dry runs; unsupported third-party brand taxonomies are refused. Use the active SEO integration term tools for brand SEO. Money/irreversible operations (refunds, deletes, batch) require confirm:true. Requires WooCommerce active.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/woo-read'  => array(
						'label'            => __( 'WooCommerce Read', 'emcp-tools' ),
						'description'      => __( 'Read products, native brands, variations, orders, refunds, customers, coupons, reports, settings, shipping, taxes, webhooks, and system status. Call with no operation to list all read operations.', 'emcp-tools' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-products', 'list-brands', 'get-brand', 'get-order', 'list-orders', 'list-customers', 'list-coupons', 'report-sales', 'get-settings', 'list-webhooks', 'system-status', '…' ),
						'available'        => self::woo_available(),
						'requires'         => array( 'name' => 'WooCommerce', 'kind' => 'plugin' ),
					),
					'emcp-tools/woo-write' => array(
						'label'            => __( 'WooCommerce Write', 'emcp-tools' ),
						'description'      => __( 'Create and update native brands, plus create/update/delete products, orders, refunds, customers, coupons, settings, shipping, taxes, and webhooks. Brand writes support dry_run:true. Refunds/deletes/batch require confirm:true. Call with no operation to list all write operations.', 'emcp-tools' ),
						'badges'           => array( 'destructive' ),
						'operations'       => array( 'create-product', 'create-brand', 'update-brand', 'update-order', 'create-refund', 'create-customer', 'delete-order', 'update-setting', '…' ),
						'available'        => self::woo_available(),
						'requires'         => array( 'name' => 'WooCommerce', 'kind' => 'plugin' ),
					),
				),
			),
			'wp_metabox'       => array(
				'platform' => 'plugins',
				'group'    => 'dynamic',
				'label'    => __( 'Meta Box', 'emcp-tools' ),
				'note'     => __( 'Plugin integrations are exposed as two tools, one Read, one Write. The AI calls a tool with an operation name; each tool bundles the operations listed on its card. Toggle a tool to allow or block all of its operations at once.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/metabox-read'  => array(
						'label'       => __( 'Meta Box Read', 'emcp-tools' ),
						'description' => __( 'Read Meta Box data, field groups, field definitions, and field values for posts and other supported object types.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
						'operations'  => array(
							'list-field-groups',
							'get-field-group',
							'get-fields',
						),
					),
					'emcp-tools/metabox-write' => array(
						'label'       => __( 'Meta Box Write', 'emcp-tools' ),
						'description' => __( 'Write Meta Box field values. No delete operations; unknown fields are skipped, not created.', 'emcp-tools' ),
						'badges'      => array(),
						'operations'  => array(
							'update-fields',
						),
					),
				),
			),
			'wp_ea'            => array(
				'platform' => 'plugins',
				'group'    => 'addons',
				'pro'      => true,
				'label'    => __( 'Essential Addons for Elementor', 'emcp-tools' ),
				'note'     => __( 'Discovery for the Essential Addons widget pack. Its widgets are placed with the standard Add Free Widget tool, so this adds no widget-adding tool of its own, just the catalog and a readable schema (an addon widget can carry 400+ controls).', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/essential-addons-read' => array(
						'label'            => __( 'Essential Addons Read', 'emcp-tools' ),
						'description'      => __( 'List Essential Addons widgets registered on this site and inspect a widget\'s content controls.', 'emcp-tools' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-widgets', 'get-widget-schema' ),
						'available'        => self::essential_addons_available(),
						'requires'         => array( 'name' => 'Essential Addons for Elementor', 'kind' => 'plugin' ),
					),
				),
			),
			'wp_premium'       => array(
				'platform' => 'plugins',
				'group'    => 'addons',
				'pro'      => true,
				'label'    => __( 'Premium Addons for Elementor', 'emcp-tools' ),
				'note'     => __( 'Discovery for the Premium Addons widget pack. As with Essential Addons, widgets are placed with the standard Add Free Widget tool; this supplies the catalog and a curated schema.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/premium-addons-read' => array(
						'label'            => __( 'Premium Addons Read', 'emcp-tools' ),
						'description'      => __( 'List Premium Addons widgets registered on this site and inspect a widget\'s content controls.', 'emcp-tools' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-widgets', 'get-widget-schema' ),
						'available'        => self::premium_addons_available(),
						'requires'         => array( 'name' => 'Premium Addons for Elementor', 'kind' => 'plugin' ),
					),
				),
			),
			'wp_uae'           => array(
				'platform' => 'plugins',
				'group'    => 'addons',
				'pro'      => true,
				'label'    => __( 'Ultimate Addons for Elementor', 'emcp-tools' ),
				'note'     => __( 'Ultimate Addons for Elementor (UAE, formerly Header Footer Elementor) exposed as two tools, one Read, one Write. UAE is both a widget pack and a template plugin: reads discover its widgets and list header/footer templates with their display conditions; writes create, update, retarget and delete templates. Widgets are placed, and template content built, with the normal Elementor tools. Delete requires confirm:true.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/uae-read'  => array(
						'label'            => __( 'Ultimate Addons for Elementor Read', 'emcp-tools' ),
						'description'      => self::uae_templates_available()
							? __( 'Discover UAE widgets, and list its header/footer/block templates with their type, status and display conditions.', 'emcp-tools' )
							: __( 'Discover the UAE widgets registered on this site and inspect the content controls of a widget.', 'emcp-tools' ),
						'badges'           => array( 'read-only' ),
						'operations'       => self::uae_templates_available()
							? array( 'list-widgets', 'get-widget-schema', 'list-templates', 'get-template' )
							: array( 'list-widgets', 'get-widget-schema' ),
						'available'        => self::uae_available(),
						'requires'         => array( 'name' => 'Ultimate Addons for Elementor', 'kind' => 'plugin' ),
					),
					'emcp-tools/uae-write' => array(
						'label'            => __( 'Ultimate Addons for Elementor Write', 'emcp-tools' ),
						'description'      => __( 'Create, update, retarget and delete UAE templates. These render site-wide, so this tool is off by default and delete needs confirmation.', 'emcp-tools' ),
						'badges'           => array( 'destructive' ),
						'operations'       => array( 'create-template', 'update-template', 'set-display-conditions', 'delete-template' ),
						'available'        => self::uae_templates_available(),
						// Recorded even though the note below is written by hand:
						// the badge needs the name, and without it this card was
						// the one unavailable tool on the screen with no badge.
						'requires'         => array( 'name' => 'Ultimate Addons for Elementor', 'kind' => 'plugin' ),
						'unavailable_note' => self::uae_pro_available()
							? __( 'UAE templates come from the free Ultimate Addons for Elementor plugin. UAE Pro on its own supplies widgets, which the Read tool already covers.', 'emcp-tools' )
							: __( 'Install & activate Ultimate Addons for Elementor to enable this tool.', 'emcp-tools' ),
					),
				),
			),
			'wp_cf7'           => array(
				'platform' => 'plugins',
				'group'    => 'forms',
				'label'    => __( 'Contact Form 7', 'emcp-tools' ),
				'note'     => __( 'Contact Form 7 exposed as two tools, one Read, one Write. Reads list forms, fields, mail templates and messages; writes update mail, messages, and settings. CF7 stores no submissions, so there are no entry operations.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/cf7-read'  => array(
						'label'            => __( 'Contact Form 7 Read', 'emcp-tools' ),
						'description'      => __( 'Read CF7 forms, fields, mail templates, messages, and settings.', 'emcp-tools' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-forms', 'get-form', 'list-notifications', 'get-settings' ),
						'available'        => self::cf7_available(),
						'requires'         => array( 'name' => 'Contact Form 7', 'kind' => 'plugin' ),
					),
					'emcp-tools/cf7-write' => array(
						'label'            => __( 'Contact Form 7 Write', 'emcp-tools' ),
						'description'      => __( 'Update CF7 mail templates, messages, and additional settings.', 'emcp-tools' ),
						'badges'           => array(),
						'operations'       => array( 'update-notification', 'update-messages', 'update-form-settings' ),
						'available'        => self::cf7_available(),
						'requires'         => array( 'name' => 'Contact Form 7', 'kind' => 'plugin' ),
					),
				),
			),
			'wp_wpforms'       => array(
				'platform' => 'plugins',
				'group'    => 'forms',
				'pro'      => true,
				'label'    => __( 'WPForms', 'emcp-tools' ),
				'note'     => __( 'WPForms exposed as two tools, one Read, one Write. Reads cover forms, fields, notifications, and entries (entries require WPForms Pro); writes update settings/notifications and manage entries. Requires WPForms active.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/wpforms-read'  => array(
						'label'            => __( 'WPForms Read', 'emcp-tools' ),
						'description'      => __( 'Read WPForms forms, fields, notifications, and entries (entries require WPForms Pro).', 'emcp-tools' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-forms', 'get-form', 'list-notifications', 'list-entries', 'get-entry', 'get-settings' ),
						'available'        => self::wpforms_available(),
						'requires'         => array( 'name' => 'WPForms', 'kind' => 'plugin' ),
					),
					'emcp-tools/wpforms-write' => array(
						'label'            => __( 'WPForms Write', 'emcp-tools' ),
						'description'      => __( 'Update WPForms notifications, set entry status, and delete entries (confirm:true). Entry operations require WPForms Pro.', 'emcp-tools' ),
						'badges'           => array( 'destructive' ),
						'operations'       => array( 'update-notification', 'update-entry-status', 'delete-entry' ),
						'available'        => self::wpforms_available(),
						'requires'         => array( 'name' => 'WPForms', 'kind' => 'plugin' ),
					),
				),
			),
			'wp_gravityforms'  => array(
				'platform' => 'plugins',
				'group'    => 'forms',
				'pro'      => true,
				'label'    => __( 'Gravity Forms', 'emcp-tools' ),
				'note'     => __( 'Gravity Forms exposed as two tools, one Read, one Write, over the GFAPI. Reads cover forms, fields, notifications, and entries; writes set entry status and delete entries. Requires Gravity Forms active.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/gravityforms-read'  => array(
						'label'            => __( 'Gravity Forms Read', 'emcp-tools' ),
						'description'      => __( 'Read Gravity Forms forms, fields, notifications, and entries.', 'emcp-tools' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-forms', 'get-form', 'list-notifications', 'list-entries', 'get-entry', 'get-settings' ),
						'available'        => self::gravityforms_available(),
						'requires'         => array( 'name' => 'Gravity Forms', 'kind' => 'plugin' ),
					),
					'emcp-tools/gravityforms-write' => array(
						'label'            => __( 'Gravity Forms Write', 'emcp-tools' ),
						'description'      => __( 'Set Gravity Forms entry status (active/spam/trash) and delete entries (confirm:true).', 'emcp-tools' ),
						'badges'           => array( 'destructive' ),
						'operations'       => array( 'update-entry-status', 'delete-entry' ),
						'available'        => self::gravityforms_available(),
						'requires'         => array( 'name' => 'Gravity Forms', 'kind' => 'plugin' ),
					),
				),
			),
			'wp_fluentforms'   => array(
				'platform' => 'plugins',
				'group'    => 'forms',
				'pro'      => true,
				'label'    => __( 'Fluent Forms', 'emcp-tools' ),
				'note'     => __( 'Fluent Forms exposed as two tools, one Read, one Write. Reads cover forms, fields, and submissions; writes set submission status and delete submissions. Requires Fluent Forms active.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/fluentforms-read'  => array(
						'label'            => __( 'Fluent Forms Read', 'emcp-tools' ),
						'description'      => __( 'Read Fluent Forms forms, fields, and submissions.', 'emcp-tools' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-forms', 'get-form', 'list-entries', 'get-entry' ),
						'available'        => self::fluentforms_available(),
						'requires'         => array( 'name' => 'Fluent Forms', 'kind' => 'plugin' ),
					),
					'emcp-tools/fluentforms-write' => array(
						'label'            => __( 'Fluent Forms Write', 'emcp-tools' ),
						'description'      => __( 'Set Fluent Forms submission status and delete submissions (confirm:true).', 'emcp-tools' ),
						'badges'           => array( 'destructive' ),
						'operations'       => array( 'update-entry-status', 'delete-entry' ),
						'available'        => self::fluentforms_available(),
						'requires'         => array( 'name' => 'Fluent Forms', 'kind' => 'plugin' ),
					),
				),
			),
			'wp_ninjaforms'    => array(
				'platform' => 'plugins',
				'group'    => 'forms',
				'pro'      => true,
				'label'    => __( 'Ninja Forms', 'emcp-tools' ),
				'note'     => __( 'Ninja Forms exposed as two tools, one Read, one Write. Reads cover forms, fields, and submissions; writes delete submissions. Requires Ninja Forms active.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/ninjaforms-read'  => array(
						'label'            => __( 'Ninja Forms Read', 'emcp-tools' ),
						'description'      => __( 'Read Ninja Forms forms, fields, and submissions.', 'emcp-tools' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-forms', 'get-form', 'list-entries', 'get-entry' ),
						'available'        => self::ninjaforms_available(),
						'requires'         => array( 'name' => 'Ninja Forms', 'kind' => 'plugin' ),
					),
					'emcp-tools/ninjaforms-write' => array(
						'label'            => __( 'Ninja Forms Write', 'emcp-tools' ),
						'description'      => __( 'Delete Ninja Forms submissions (confirm:true).', 'emcp-tools' ),
						'badges'           => array( 'destructive' ),
						'operations'       => array( 'delete-entry' ),
						'available'        => self::ninjaforms_available(),
						'requires'         => array( 'name' => 'Ninja Forms', 'kind' => 'plugin' ),
					),
				),
			),
			'wp_formidable'    => array(
				'platform' => 'plugins',
				'group'    => 'forms',
				'pro'      => true,
				'label'    => __( 'Formidable Forms', 'emcp-tools' ),
				'note'     => __( 'Formidable Forms exposed as two tools, one Read, one Write. Reads cover forms, fields, notifications, and entries; writes update notifications and delete entries. Requires Formidable Forms active.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/formidable-read'  => array(
						'label'            => __( 'Formidable Forms Read', 'emcp-tools' ),
						'description'      => __( 'Read Formidable forms, fields, notifications, and entries.', 'emcp-tools' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-forms', 'get-form', 'list-notifications', 'list-entries', 'get-entry' ),
						'available'        => self::formidable_available(),
						'requires'         => array( 'name' => 'Formidable Forms', 'kind' => 'plugin' ),
					),
					'emcp-tools/formidable-write' => array(
						'label'            => __( 'Formidable Forms Write', 'emcp-tools' ),
						'description'      => __( 'Delete Formidable entries (confirm:true).', 'emcp-tools' ),
						'badges'           => array( 'destructive' ),
						'operations'       => array( 'delete-entry' ),
						'available'        => self::formidable_available(),
						'requires'         => array( 'name' => 'Formidable Forms', 'kind' => 'plugin' ),
					),
				),
			),
			'wp_metform'       => array(
				'platform' => 'plugins',
				'group'    => 'forms',
				'pro'      => true,
				'label'    => __( 'MetForm', 'emcp-tools' ),
				'note'     => __( 'MetForm exposed as two tools, one Read, one Write. Reads cover forms, fields, and entries; writes delete entries. Requires MetForm (and Elementor) active.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/metform-read'  => array(
						'label'            => __( 'MetForm Read', 'emcp-tools' ),
						'description'      => __( 'Read MetForm forms, fields, and entries.', 'emcp-tools' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-forms', 'get-form', 'list-entries', 'get-entry' ),
						'available'        => self::metform_available(),
						'requires'         => array( 'name' => 'MetForm', 'kind' => 'plugin' ),
					),
					'emcp-tools/metform-write' => array(
						'label'            => __( 'MetForm Write', 'emcp-tools' ),
						'description'      => __( 'Delete MetForm entries (confirm:true).', 'emcp-tools' ),
						'badges'           => array( 'destructive' ),
						'operations'       => array( 'delete-entry' ),
						'available'        => self::metform_available(),
						'requires'         => array( 'name' => 'MetForm', 'kind' => 'plugin' ),
					),
				),
			),
			'wp_sureforms'     => array(
				'platform' => 'plugins',
				'group'    => 'forms',
				'pro'      => true,
				'label'    => __( 'SureForms', 'emcp-tools' ),
				'note'     => __( 'SureForms exposed as two tools, one Read, one Write. Reads cover forms, fields, and entries; writes set entry status and delete entries. Requires SureForms active.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/sureforms-read'  => array(
						'label'            => __( 'SureForms Read', 'emcp-tools' ),
						'description'      => __( 'Read SureForms forms, fields, and entries.', 'emcp-tools' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-forms', 'get-form', 'list-entries', 'get-entry' ),
						'available'        => self::sureforms_available(),
						'requires'         => array( 'name' => 'SureForms', 'kind' => 'plugin' ),
					),
					'emcp-tools/sureforms-write' => array(
						'label'            => __( 'SureForms Write', 'emcp-tools' ),
						'description'      => __( 'Set SureForms entry status and delete entries (confirm:true).', 'emcp-tools' ),
						'badges'           => array( 'destructive' ),
						'operations'       => array( 'update-entry-status', 'delete-entry' ),
						'available'        => self::sureforms_available(),
						'requires'         => array( 'name' => 'SureForms', 'kind' => 'plugin' ),
					),
				),
			),
			'wp_forminator'    => array(
				'platform' => 'plugins',
				'group'    => 'forms',
				'pro'      => true,
				'label'    => __( 'Forminator', 'emcp-tools' ),
				'note'     => __( 'Forminator exposed as two tools, one Read, one Write. Reads cover forms (id, name, shortcode, fields) and submissions; writes delete a submission. Requires Forminator active.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/forminator-read'  => array(
						'label'            => __( 'Forminator Read', 'emcp-tools' ),
						'description'      => __( 'Read Forminator forms, fields, shortcodes, and submissions.', 'emcp-tools' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-forms', 'get-form', 'list-entries', 'get-entry' ),
						'available'        => self::forminator_available(),
						'requires'         => array( 'name' => 'Forminator', 'kind' => 'plugin' ),
					),
					'emcp-tools/forminator-write' => array(
						'label'            => __( 'Forminator Write', 'emcp-tools' ),
						'description'      => __( 'Delete a Forminator submission (confirm:true).', 'emcp-tools' ),
						'badges'           => array( 'destructive' ),
						'operations'       => array( 'delete-entry' ),
						'available'        => self::forminator_available(),
						'requires'         => array( 'name' => 'Forminator', 'kind' => 'plugin' ),
					),
				),
			),
			'wp_slimseo'       => array(
				'platform' => 'plugins',
				'group'    => 'seo',
				'label'    => __( 'Slim SEO', 'emcp-tools' ),
				'note'     => __( 'Slim SEO exposed as two tools, one Read, one Write. Read and write the SEO metadata (title, description, canonical, robots, social) Slim SEO stores for posts and terms, plus its site settings.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/slimseo-read'  => array(
						'label'            => __( 'Slim SEO Read', 'emcp-tools' ),
						'description'      => __( 'Read Slim SEO post/term SEO metadata and site settings.', 'emcp-tools' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'get-post-seo', 'get-term-seo', 'get-settings' ),
						'available'        => self::slimseo_available(),
						'requires'         => array( 'name' => 'Slim SEO', 'kind' => 'plugin' ),
					),
					'emcp-tools/slimseo-write' => array(
						'label'            => __( 'Slim SEO Write', 'emcp-tools' ),
						'description'      => __( 'Update Slim SEO post/term SEO metadata.', 'emcp-tools' ),
						'badges'           => array(),
						'operations'       => array( 'update-post-seo', 'update-term-seo' ),
						'available'        => self::slimseo_available(),
						'requires'         => array( 'name' => 'Slim SEO', 'kind' => 'plugin' ),
					),
				),
			),
			'wp_yoast'         => array(
				'platform' => 'plugins',
				'group'    => 'seo',
				'pro'      => true,
				'label'    => __( 'Yoast SEO', 'emcp-tools' ),
				'note'     => __( 'Yoast SEO exposed as two tools, one Read, one Write. Read and write the SEO metadata (title, description, canonical, robots, social, focus keyword) Yoast stores for posts and terms, plus site settings.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/yoast-read'  => array(
						'label'            => __( 'Yoast SEO Read', 'emcp-tools' ),
						'description'      => __( 'Read Yoast post/term SEO metadata and site settings.', 'emcp-tools' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'get-post-seo', 'get-term-seo', 'get-settings' ),
						'available'        => self::yoast_available(),
						'requires'         => array( 'name' => 'Yoast SEO', 'kind' => 'plugin' ),
					),
					'emcp-tools/yoast-write' => array(
						'label'            => __( 'Yoast SEO Write', 'emcp-tools' ),
						'description'      => __( 'Update Yoast post/term SEO metadata.', 'emcp-tools' ),
						'badges'           => array(),
						'operations'       => array( 'update-post-seo', 'update-term-seo' ),
						'available'        => self::yoast_available(),
						'requires'         => array( 'name' => 'Yoast SEO', 'kind' => 'plugin' ),
					),
				),
			),
			'wp_rankmath'      => array(
				'platform' => 'plugins',
				'group'    => 'seo',
				'pro'      => true,
				'label'    => __( 'Rank Math', 'emcp-tools' ),
				'note'     => __( 'Rank Math exposed as two tools, one Read, one Write. Read/write post & term SEO metadata and site settings; also read schema (structured data).', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/rankmath-read'  => array(
						'label'            => __( 'Rank Math Read', 'emcp-tools' ),
						'description'      => __( 'Read Rank Math post/term SEO metadata, schema, and site settings.', 'emcp-tools' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'get-post-seo', 'get-term-seo', 'get-schema', 'get-settings' ),
						'available'        => self::rankmath_available(),
						'requires'         => array( 'name' => 'Rank Math', 'kind' => 'plugin' ),
					),
					'emcp-tools/rankmath-write' => array(
						'label'            => __( 'Rank Math Write', 'emcp-tools' ),
						'description'      => __( 'Update Rank Math post/term SEO metadata.', 'emcp-tools' ),
						'badges'           => array(),
						'operations'       => array( 'update-post-seo', 'update-term-seo' ),
						'available'        => self::rankmath_available(),
						'requires'         => array( 'name' => 'Rank Math', 'kind' => 'plugin' ),
					),
				),
			),
			'wp_aioseo'        => array(
				'platform' => 'plugins',
				'group'    => 'seo',
				'pro'      => true,
				'label'    => __( 'All in One SEO', 'emcp-tools' ),
				'note'     => __( 'All in One SEO exposed as two tools, one Read, one Write. Read/write post SEO metadata and read schema (structured data) + site settings.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/aioseo-read'  => array(
						'label'            => __( 'All in One SEO Read', 'emcp-tools' ),
						'description'      => __( 'Read AIOSEO post SEO metadata, schema, and site settings.', 'emcp-tools' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'get-post-seo', 'get-schema', 'get-settings' ),
						'available'        => self::aioseo_available(),
						'requires'         => array( 'name' => 'All in One SEO', 'kind' => 'plugin' ),
					),
					'emcp-tools/aioseo-write' => array(
						'label'            => __( 'All in One SEO Write', 'emcp-tools' ),
						'description'      => __( 'Update AIOSEO post SEO metadata.', 'emcp-tools' ),
						'badges'           => array(),
						'operations'       => array( 'update-post-seo' ),
						'available'        => self::aioseo_available(),
						'requires'         => array( 'name' => 'All in One SEO', 'kind' => 'plugin' ),
					),
				),
			),
			'wp_seopress'      => array(
				'platform' => 'plugins',
				'group'    => 'seo',
				'pro'      => true,
				'label'    => __( 'SEOPress', 'emcp-tools' ),
				'note'     => __( 'SEOPress exposed as two tools, one Read, one Write. Read/write post & term SEO metadata and site settings; also read schema (structured data).', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/seopress-read'  => array(
						'label'            => __( 'SEOPress Read', 'emcp-tools' ),
						'description'      => __( 'Read SEOPress post/term SEO metadata, schema, and site settings.', 'emcp-tools' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'get-post-seo', 'get-term-seo', 'get-settings', 'get-schema' ),
						'available'        => self::seopress_available(),
						'requires'         => array( 'name' => 'SEOPress', 'kind' => 'plugin' ),
					),
					'emcp-tools/seopress-write' => array(
						'label'            => __( 'SEOPress Write', 'emcp-tools' ),
						'description'      => __( 'Update SEOPress post/term SEO metadata.', 'emcp-tools' ),
						'badges'           => array(),
						'operations'       => array( 'update-post-seo', 'update-term-seo' ),
						'available'        => self::seopress_available(),
						'requires'         => array( 'name' => 'SEOPress', 'kind' => 'plugin' ),
					),
				),
			),
			'wp_seoframework'  => array(
				'platform' => 'plugins',
				'group'    => 'seo',
				'pro'      => true,
				'label'    => __( 'The SEO Framework', 'emcp-tools' ),
				'note'     => __( 'The SEO Framework exposed as two tools, one Read, one Write. Read and write the SEO metadata (title, description, canonical, robots, social) it stores for posts and terms.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/seoframework-read'  => array(
						'label'            => __( 'The SEO Framework Read', 'emcp-tools' ),
						'description'      => __( 'Read The SEO Framework post/term SEO metadata.', 'emcp-tools' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'get-post-seo', 'get-term-seo' ),
						'available'        => self::seoframework_available(),
						'requires'         => array( 'name' => 'The SEO Framework', 'kind' => 'plugin' ),
					),
					'emcp-tools/seoframework-write' => array(
						'label'            => __( 'The SEO Framework Write', 'emcp-tools' ),
						'description'      => __( 'Update The SEO Framework post/term SEO metadata.', 'emcp-tools' ),
						'badges'           => array(),
						'operations'       => array( 'update-post-seo', 'update-term-seo' ),
						'available'        => self::seoframework_available(),
						'requires'         => array( 'name' => 'The SEO Framework', 'kind' => 'plugin' ),
					),
				),
			),
			'wp_surerank'      => array(
				'platform' => 'plugins',
				'group'    => 'seo',
				'pro'      => true,
				'label'    => __( 'SureRank', 'emcp-tools' ),
				'note'     => __( 'SureRank exposed as two tools, one Read, one Write. Read and write the SEO metadata (title, description, canonical, robots, social) SureRank stores for posts and terms.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/surerank-read'  => array(
						'label'            => __( 'SureRank Read', 'emcp-tools' ),
						'description'      => __( 'Read SureRank post/term SEO metadata and site settings.', 'emcp-tools' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'get-post-seo', 'get-term-seo', 'get-settings' ),
						'available'        => self::surerank_available(),
						'requires'         => array( 'name' => 'SureRank', 'kind' => 'plugin' ),
					),
					'emcp-tools/surerank-write' => array(
						'label'            => __( 'SureRank Write', 'emcp-tools' ),
						'description'      => __( 'Update SureRank post/term SEO metadata.', 'emcp-tools' ),
						'badges'           => array(),
						'operations'       => array( 'update-post-seo', 'update-term-seo' ),
						'available'        => self::surerank_available(),
						'requires'         => array( 'name' => 'SureRank', 'kind' => 'plugin' ),
					),
				),
			),
			'theme_active'     => array(
				'platform' => 'themes',
				'label'    => __( 'Active Theme', 'emcp-tools' ),
				'note'     => __( 'Theme integrations are exposed as two tools, one Read, one Write, that bundle internal operations. The AI calls a tool with an operation name; toggle a tool to allow or block all of its operations at once.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/theme-read'  => array(
						'label'       => __( 'Theme Read', 'emcp-tools' ),
						'description' => __( 'Read the active theme: context (framework, block-theme, supports, menu locations, child status) and theme_mod values.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
						'operations'  => array( 'get-theme-context', 'get-mods' ),
					),
					'emcp-tools/theme-write' => array(
						'label'       => __( 'Theme Write', 'emcp-tools' ),
						'description' => __( 'Set theme_mod values and create + activate a child theme so the agent can edit theme files (create-child-theme requires confirm:true).', 'emcp-tools' ),
						'badges'      => array(),
						'operations'  => array( 'set-mods', 'create-child-theme' ),
					),
				),
			),
			'theme_astra_spectra' => array(
				'platform' => 'themes',
				'label'    => __( 'Astra + Spectra', 'emcp-tools' ),
				'note'     => __( 'The Astra theme and its Spectra blocks companion, grouped as one pack. Astra tools manage the theme\'s settings (enabled only when Astra is the active theme); Spectra tools give the block catalog + insertion (enabled only when the Spectra plugin is active). Toggles for an inactive component are disabled until you install and activate it.', 'emcp-tools' ),
				'notice'   => self::spectra_file_generation_notice(),
				'tools'    => array(
					'emcp-tools/astra-read'    => array(
						'label'            => __( 'Astra Read', 'emcp-tools' ),
						'description'      => __( 'Read Astra settings (colors, typography, layout, header/footer) with value + type/label/group metadata.', 'emcp-tools' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'get-settings' ),
						'available'        => self::astra_available(),
						'requires'         => array( 'name' => 'Astra', 'kind' => 'theme' ),
					),
					'emcp-tools/astra-write'   => array(
						'label'            => __( 'Astra Write', 'emcp-tools' ),
						'description'      => __( 'Write Astra settings; non-allowlisted keys are reported in skipped[].', 'emcp-tools' ),
						'badges'           => array(),
						'operations'       => array( 'update-settings' ),
						'available'        => self::astra_available(),
						'requires'         => array( 'name' => 'Astra', 'kind' => 'theme' ),
					),
					'emcp-tools/spectra-read'  => array(
						'label'            => __( 'Spectra Read', 'emcp-tools' ),
						'description'      => __( 'Catalog of available Spectra blocks (list-blocks) and each block\'s real attributes + example markup (get-block-schema).', 'emcp-tools' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-blocks', 'get-block-schema' ),
						'available'        => self::spectra_available(),
						'requires'         => array( 'name' => 'Spectra', 'kind' => 'plugin' ),
					),
					'emcp-tools/spectra-write' => array(
						'label'            => __( 'Spectra Write', 'emcp-tools' ),
						'description'      => __( 'Insert a Spectra block into a post with a generated block_id (add-block); Spectra applies its own defaults.', 'emcp-tools' ),
						'badges'           => array(),
						'operations'       => array( 'add-block' ),
						'available'        => self::spectra_available(),
						'requires'         => array( 'name' => 'Spectra', 'kind' => 'plugin' ),
					),
				),
			),
			'theme_kadence'    => array(
				'platform' => 'themes',
				'label'    => __( 'Kadence + Kadence Blocks', 'emcp-tools' ),
				'note'     => __( 'The Kadence theme and its Kadence Blocks companion, grouped as one pack. Kadence tools manage the theme\'s settings (enabled only when Kadence is the active theme); Kadence Blocks tools give the block catalog + insertion (enabled only when the Kadence Blocks plugin is active). Toggles for an inactive component are disabled until you install and activate it.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/kadence-read'         => array(
						'label'            => __( 'Kadence Read', 'emcp-tools' ),
						'description'      => __( 'Read Kadence settings (palette, colors, typography, layout, buttons, header/footer) with value + type/label/group/shape metadata.', 'emcp-tools' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'get-settings' ),
						'available'        => self::kadence_available(),
						'requires'         => array( 'name' => 'Kadence', 'kind' => 'theme' ),
					),
					'emcp-tools/kadence-write'        => array(
						'label'            => __( 'Kadence Write', 'emcp-tools' ),
						'description'      => __( 'Write Kadence settings as theme_mods; non-allowlisted keys are reported in skipped[].', 'emcp-tools' ),
						'badges'           => array(),
						'operations'       => array( 'update-settings' ),
						'available'        => self::kadence_available(),
						'requires'         => array( 'name' => 'Kadence', 'kind' => 'theme' ),
					),
					'emcp-tools/kadence-blocks-read'  => array(
						'label'            => __( 'Kadence Blocks Read', 'emcp-tools' ),
						'description'      => __( 'Catalog of available Kadence blocks (list-blocks) and each block\'s real attributes + example markup (get-block-schema).', 'emcp-tools' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'list-blocks', 'get-block-schema' ),
						'available'        => self::kadence_blocks_available(),
						'requires'         => array( 'name' => 'Kadence Blocks', 'kind' => 'plugin' ),
					),
					'emcp-tools/kadence-blocks-write' => array(
						'label'            => __( 'Kadence Blocks Write', 'emcp-tools' ),
						'description'      => __( 'Insert a Kadence block into a post with a generated uniqueID + scaffolded inner blocks (add-block).', 'emcp-tools' ),
						'badges'           => array(),
						'operations'       => array( 'add-block' ),
						'available'        => self::kadence_blocks_available(),
						'requires'         => array( 'name' => 'Kadence Blocks', 'kind' => 'plugin' ),
					),
				),
			),
			'theme_generatepress' => array(
				'platform' => 'themes',
				'label'    => __( 'GeneratePress + GenerateBlocks', 'emcp-tools' ),
				'note'     => __( 'The GeneratePress theme and its GenerateBlocks companion (Pro). GeneratePress tools manage the theme\'s settings (enabled only when GeneratePress is the active theme); GenerateBlocks tools give the block catalog + insertion (enabled only when the GenerateBlocks plugin is active). Toggles for an inactive component are disabled until you install and activate it.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/generatepress-read'   => array(
						'label'            => __( 'GeneratePress Read', 'emcp-tools' ),
						'description'      => __( 'Read GeneratePress settings (global palette, colors, layout, typography) with value + type/label/group/shape metadata.', 'emcp-tools' ),
						'badges'           => array( 'read-only', 'pro' ),
						'operations'       => array( 'get-settings' ),
						'available'        => self::generatepress_available(),
						'requires'         => array( 'name' => 'GeneratePress', 'kind' => 'theme' ),
					),
					'emcp-tools/generatepress-write'  => array(
						'label'            => __( 'GeneratePress Write', 'emcp-tools' ),
						'description'      => __( 'Write GeneratePress settings; non-allowlisted keys are reported in skipped[].', 'emcp-tools' ),
						'badges'           => array( 'pro' ),
						'operations'       => array( 'update-settings' ),
						'available'        => self::generatepress_available(),
						'requires'         => array( 'name' => 'GeneratePress', 'kind' => 'theme' ),
					),
					'emcp-tools/generateblocks-read'  => array(
						'label'            => __( 'GenerateBlocks Read', 'emcp-tools' ),
						'description'      => __( 'Catalog of the GenerateBlocks V2 blocks (list-blocks) and each block\'s attributes + styles model (get-block-schema).', 'emcp-tools' ),
						'badges'           => array( 'read-only', 'pro' ),
						'operations'       => array( 'list-blocks', 'get-block-schema' ),
						'available'        => self::generateblocks_available(),
						'requires'         => array( 'name' => 'GenerateBlocks', 'kind' => 'plugin' ),
					),
					'emcp-tools/generateblocks-write' => array(
						'label'            => __( 'GenerateBlocks Write', 'emcp-tools' ),
						'description'      => __( 'Insert a GenerateBlocks V2 block with a generated uniqueId, styles object + compiled css, and content (add-block).', 'emcp-tools' ),
						'badges'           => array( 'pro' ),
						'operations'       => array( 'add-block' ),
						'available'        => self::generateblocks_available(),
						'requires'         => array( 'name' => 'GenerateBlocks', 'kind' => 'plugin' ),
					),
				),
			),
			'theme_betheme'    => array(
				'platform' => 'themes',
				'pro'      => true,
				'label'    => __( 'BeTheme + BeBuilder', 'emcp-tools' ),
				'note'     => __( 'BeTheme (Muffin Group) and its BeBuilder page builder, as one pack. Read and write a curated set of the theme\'s 830 settings, and build page content as BeBuilder sections. Enabled only when BeTheme is the active theme. BeTheme\'s own template system is not covered here: use EMCP Themer, or BeTheme\'s Templates screen directly.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/betheme-read'  => array(
						'label'            => __( 'BeTheme Read', 'emcp-tools' ),
						'description'      => __( 'Read theme context, curated settings (colors, typography, layout, header/footer, blog), the BeBuilder item catalog with per-item schemas, and a page\'s BeBuilder structure.', 'emcp-tools' ),
						'badges'           => array( 'read-only' ),
						'operations'       => array( 'get-context', 'get-settings', 'list-item-types', 'get-item-schema', 'get-page' ),
						'available'        => self::betheme_available(),
						'requires'         => array( 'name' => 'BeTheme', 'kind' => 'theme' ),
					),
					'emcp-tools/betheme-write' => array(
						'label'            => __( 'BeTheme Write', 'emcp-tools' ),
						'description'      => __( 'Write curated theme settings, replace a page\'s BeBuilder content, or append a section. Settings outside the curated list are reported in skipped[]; an unknown item type is refused rather than written.', 'emcp-tools' ),
						'badges'           => array(),
						'operations'       => array( 'update-settings', 'set-page', 'add-section' ),
						'available'        => self::betheme_available(),
						'requires'         => array( 'name' => 'BeTheme', 'kind' => 'theme' ),
					),
				),
			),
			'theme_blocksy'    => array(
				'platform' => 'themes',
				'label'    => __( 'Blocksy', 'emcp-tools' ),
				'note'     => __( 'Blocksy (Pro): its dynamic content blocks (query/tax-query loops, dynamic-data, about-me, socials, share-box, breadcrumbs, …) and its Blocksy Companion extensions (activate/deactivate). Enabled when Blocksy Companion is active. Theme settings are reachable via the free Active Theme tools (theme-read/theme-write).', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/blocksy-blocks-read'      => array(
						'label'            => __( 'Blocksy Blocks Read', 'emcp-tools' ),
						'description'      => __( 'Catalog of the Blocksy blocks (list-blocks) and each block\'s attributes (get-block-schema).', 'emcp-tools' ),
						'badges'           => array( 'read-only', 'pro' ),
						'operations'       => array( 'list-blocks', 'get-block-schema' ),
						'available'        => self::blocksy_blocks_available(),
						'requires'         => array( 'name' => 'Blocksy Companion', 'kind' => 'plugin' ),
					),
					'emcp-tools/blocksy-blocks-write'     => array(
						'label'            => __( 'Blocksy Blocks Write', 'emcp-tools' ),
						'description'      => __( 'Insert a Blocksy block into a post (add-block); query/tax-query get a scaffolded template child.', 'emcp-tools' ),
						'badges'           => array( 'pro' ),
						'operations'       => array( 'add-block' ),
						'available'        => self::blocksy_blocks_available(),
						'requires'         => array( 'name' => 'Blocksy Companion', 'kind' => 'plugin' ),
					),
					'emcp-tools/blocksy-extensions-read'  => array(
						'label'            => __( 'Blocksy Extensions Read', 'emcp-tools' ),
						'description'      => __( 'List Blocksy Companion extensions with name, description, pro flag, and active status (list-extensions).', 'emcp-tools' ),
						'badges'           => array( 'read-only', 'pro' ),
						'operations'       => array( 'list-extensions' ),
						'available'        => self::blocksy_extensions_available(),
						'requires'         => array( 'name' => 'Blocksy Companion', 'kind' => 'plugin' ),
					),
					'emcp-tools/blocksy-extensions-write' => array(
						'label'            => __( 'Blocksy Extensions Write', 'emcp-tools' ),
						'description'      => __( 'Activate or deactivate a Blocksy Companion extension by slug.', 'emcp-tools' ),
						'badges'           => array( 'pro' ),
						'operations'       => array( 'activate-extension', 'deactivate-extension' ),
						'available'        => self::blocksy_extensions_available(),
						'requires'         => array( 'name' => 'Blocksy Companion', 'kind' => 'plugin' ),
					),
				),
			),
			'page'             => array(
				'platform' => 'elementor',
				'label' => __( 'Page Management', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/create-page'          => array(
						'label'       => __( 'Create Page', 'emcp-tools' ),
						'description' => __( 'Creates a new WordPress page with Elementor enabled.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/update-page-settings' => array(
						'label'       => __( 'Update Page Settings', 'emcp-tools' ),
						'description' => __( 'Updates Elementor page-level settings (layout, canvas, etc).', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/delete-page-content'  => array(
						'label'       => __( 'Delete Page Content', 'emcp-tools' ),
						'description' => __( 'Removes all Elementor content from a page.', 'emcp-tools' ),
						'badges'      => array( 'destructive' ),
					),
					'emcp-tools/import-template'      => array(
						'label'       => __( 'Import Template', 'emcp-tools' ),
						'description' => __( 'Imports an Elementor template JSON into a page.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/export-page'          => array(
						'label'       => __( 'Export Page', 'emcp-tools' ),
						'description' => __( 'Exports a page\'s Elementor data as JSON.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/regenerate-css' => array(
						'label' => __( 'Regenerate CSS & Data', 'emcp-tools' ),
						'description' => __( 'Clears Elementor CSS and render/asset caches for one page, or site-wide with administrator permission and confirmation.', 'emcp-tools' ),
						'badges' => array(),
					),
				),
			),
			'layout'           => array(
				'platform' => 'elementor',
				'label' => __( 'Layout & Structure', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/add-container'     => array(
						'label'       => __( 'Add Container', 'emcp-tools' ),
						'description' => __( 'Adds a new flexbox container to a page or inside another container.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/move-element'      => array(
						'label'       => __( 'Move Element', 'emcp-tools' ),
						'description' => __( 'Moves an element to a new parent or position.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/remove-element'    => array(
						'label'       => __( 'Remove Element', 'emcp-tools' ),
						'description' => __( 'Removes an element and all its children from the page.', 'emcp-tools' ),
						'badges'      => array( 'destructive' ),
					),
					'emcp-tools/duplicate-element'    => array(
						'label'       => __( 'Duplicate Element', 'emcp-tools' ),
						'description' => __( 'Creates a deep copy of an element and inserts it after the original.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/update-container'     => array(
						'label'       => __( 'Update Container', 'emcp-tools' ),
						'description' => __( 'Updates settings on an existing container element.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/get-container-schema' => array(
						'label'       => __( 'Get Container Schema', 'emcp-tools' ),
						'description' => __( 'Returns the JSON schema for container settings.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/find-element'         => array(
						'label'       => __( 'Find Element', 'emcp-tools' ),
						'description' => __( 'Finds elements by type, settings, or CSS class within a page.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/update-element'       => array(
						'label'       => __( 'Update Element', 'emcp-tools' ),
						'description' => __( 'Updates settings on any element (widget or container) by ID. Also writes v4 atomic styles / editor_settings when included.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/batch-update'         => array(
						'label'       => __( 'Batch Update', 'emcp-tools' ),
						'description' => __( 'Applies multiple element updates in a single call.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/set-element-label'    => array(
						'label'       => __( 'Set Element Label', 'emcp-tools' ),
						'description' => __( 'Sets an element\'s Navigator label (editor_settings.title).', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/reorder-elements'     => array(
						'label'       => __( 'Reorder Elements', 'emcp-tools' ),
						'description' => __( 'Reorders child elements within a container.', 'emcp-tools' ),
						'badges'      => array(),
					),
				),
			),
			'widgets'          => array(
				'platform' => 'elementor',
				'label' => __( 'Widgets', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/add-free-widget' => array(
						'label'       => __( 'Add Widget', 'emcp-tools' ),
						'description' => __( 'Adds any free/core Elementor widget by type (discover with list-widgets / get-widget-schema).', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/add-pro-widget'  => array(
						'label'       => __( 'Add Pro Widget', 'emcp-tools' ),
						'description' => __( 'Adds an Elementor Pro / WooCommerce widget by type. Registers only when Elementor Pro is active.', 'emcp-tools' ),
						'badges'      => array( 'elementor-pro' ),
					),
					'emcp-tools/update-widget'   => array(
						'label'       => __( 'Update Widget', 'emcp-tools' ),
						'description' => __( 'Updates settings on an existing widget (partial merge).', 'emcp-tools' ),
						'badges'      => array(),
					),
				),
			),
			'template'         => array(
				'platform' => 'elementor',
				'label' => __( 'Templates', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/save-as-template' => array(
						'label'       => __( 'Save as Template', 'emcp-tools' ),
						'description' => __( 'Saves the current page content as a reusable template.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/apply-template'       => array(
						'label'       => __( 'Apply Template', 'emcp-tools' ),
						'description' => __( 'Applies a saved template to a target page.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/create-elementor-theme-template' => array(
						'label'       => __( 'Create Elementor Theme Template', 'emcp-tools' ),
						'description' => __( 'Creates a native Elementor Pro theme builder template (header, footer, single, archive, etc). For the builder-agnostic EMCP Themer, use create-theme-template.', 'emcp-tools' ),
						'badges'      => array( 'elementor-pro' ),
					),
					'emcp-tools/set-elementor-template-conditions' => array(
						'label'       => __( 'Set Elementor Template Conditions', 'emcp-tools' ),
						'description' => __( 'Sets display conditions on a native Elementor Pro theme builder template.', 'emcp-tools' ),
						'badges'      => array( 'elementor-pro' ),
					),
					'emcp-tools/list-dynamic-tags'    => array(
						'label'       => __( 'List Dynamic Tags', 'emcp-tools' ),
						'description' => __( 'Lists all available dynamic tags and their categories.', 'emcp-tools' ),
						'badges'      => array( 'elementor-pro', 'read-only' ),
					),
					'emcp-tools/set-dynamic-tag'      => array(
						'label'       => __( 'Set Dynamic Tag', 'emcp-tools' ),
						'description' => __( 'Sets a dynamic tag on a specific element setting.', 'emcp-tools' ),
						'badges'      => array( 'elementor-pro' ),
					),
					'emcp-tools/create-popup'         => array(
						'label'       => __( 'Create Popup', 'emcp-tools' ),
						'description' => __( 'Creates an Elementor popup template.', 'emcp-tools' ),
						'badges'      => array( 'elementor-pro' ),
					),
					'emcp-tools/set-popup-settings'   => array(
						'label'       => __( 'Set Popup Settings', 'emcp-tools' ),
						'description' => __( 'Sets triggers, conditions, and timing on a popup template.', 'emcp-tools' ),
						'badges'      => array( 'elementor-pro' ),
					),
				),
			),
			'global'           => array(
				'platform' => 'elementor',
				'label' => __( 'Global Settings', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/update-global-colors'     => array(
						'label'       => __( 'Update Global Colors', 'emcp-tools' ),
						'description' => __( 'Updates the site-wide Elementor color palette.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/update-global-typography' => array(
						'label'       => __( 'Update Global Typography', 'emcp-tools' ),
						'description' => __( 'Updates the site-wide Elementor typography presets.', 'emcp-tools' ),
						'badges'      => array(),
					),
				),
			),
			'composite'        => array(
				'platform' => 'elementor',
				'label' => __( 'Composite', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/build-page' => array(
						'label'       => __( 'Build Page', 'emcp-tools' ),
						'description' => __( 'Creates a complete page from a declarative structure in one call.', 'emcp-tools' ),
						'badges'      => array(),
					),
				),
			),
			'stock_images'     => array(
				'platform' => 'wordpress',
				'label' => __( 'Stock & Media Images', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/list-media'       => array(
						'label'       => __( 'List Media', 'emcp-tools' ),
						'description' => __( 'Lists and searches images already in the WordPress Media Library (the site\'s own uploads).', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/get-media'        => array(
						'label'       => __( 'Get Media', 'emcp-tools' ),
						'description' => __( 'Full detail of one attachment (sizes, metadata, alt/caption).', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/upload-media'     => array(
						'label'       => __( 'Upload Media', 'emcp-tools' ),
						'description' => __( 'Upload a LOCAL file from the client machine into the Media Library by passing its base64 bytes (companion to sideload-image, which fetches a server-reachable URL). Only WordPress-allowed file types.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/update-media'     => array(
						'label'       => __( 'Update Media', 'emcp-tools' ),
						'description' => __( 'Edit an attachment\'s alt text, title, caption, description.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/delete-media'     => array(
						'label'       => __( 'Delete Media', 'emcp-tools' ),
						'description' => __( 'Delete an attachment (permanent; requires confirm).', 'emcp-tools' ),
						'badges'      => array( 'destructive' ),
					),
					'emcp-tools/search-images'    => array(
						'label'       => __( 'Search Images', 'emcp-tools' ),
						'description' => __( 'Searches a stock-photo provider (Unsplash, Pexels, or Pixabay) for images. Core WordPress tool, available without Elementor. Needs a free provider API key (Connection tab).', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/sideload-image'   => array(
						'label'       => __( 'Sideload Image', 'emcp-tools' ),
						'description' => __( 'Downloads an external image URL into the WordPress Media Library. Core WordPress tool, available without Elementor.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/add-stock-image'  => array(
						'label'       => __( 'Add Stock Image', 'emcp-tools' ),
						'description' => __( 'Searches, downloads, and adds a stock image to the page in one call.', 'emcp-tools' ),
						'badges'      => array(),
					),
				),
			),
			'svg_icons'        => array(
				'platform' => 'elementor',
				'label' => __( 'SVG Icons', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/upload-svg-icon'  => array(
						'label'       => __( 'Upload SVG Icon', 'emcp-tools' ),
						'description' => __( 'Uploads an SVG icon (from URL or raw markup) for use with icon/icon-box widgets.', 'emcp-tools' ),
						'badges'      => array(),
					),
				),
			),
			'custom_code'      => array(
				'platform' => 'elementor',
				'label' => __( 'Custom Code', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/add-custom-css'     => array(
						'label'       => __( 'Add Custom CSS', 'emcp-tools' ),
						'description' => __( 'Adds custom CSS to a specific element or the entire page.', 'emcp-tools' ),
						'badges'      => array( 'elementor-pro' ),
					),
					'emcp-tools/add-custom-js'      => array(
						'label'       => __( 'Add Custom JavaScript', 'emcp-tools' ),
						'description' => __( 'Adds a JavaScript snippet to a page via an HTML widget.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/add-code-snippet'   => array(
						'label'       => __( 'Add Code Snippet', 'emcp-tools' ),
						'description' => __( 'Creates a site-wide Custom Code snippet for head/body injection.', 'emcp-tools' ),
						'badges'      => array( 'elementor-pro' ),
					),
					'emcp-tools/list-code-snippets' => array(
						'label'       => __( 'List Code Snippets', 'emcp-tools' ),
						'description' => __( 'Lists all existing Custom Code snippets.', 'emcp-tools' ),
						'badges'      => array( 'elementor-pro', 'read-only' ),
					),
				),
			),
		);

		// Atomic elements (Elementor 4.0+). The underlying abilities are only
		// registered when Elementor >= 4.0 is active, so we mirror that gate
		// here to avoid showing toggles for tools that don't exist.
		if ( class_exists( 'EMCP_Tools_Atomic_Props' ) && EMCP_Tools_Atomic_Props::is_atomic_supported() ) {
			$tools['atomic_layout'] = array(
				'platform' => 'elementor',
				'label' => __( 'Atomic Layout (Elementor 4.0+)', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/detect-elementor-version' => array(
						'label'       => __( 'Detect Elementor Version', 'emcp-tools' ),
						'description' => __( 'Returns the Elementor version and whether atomic elements are supported.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/list-global-classes'      => array(
						'label'       => __( 'List Global Classes', 'emcp-tools' ),
						'description' => __( 'Resolves Class Manager "g-" class IDs to their names and CSS properties.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/create-global-class'      => array(
						'label'       => __( 'Create Global Class', 'emcp-tools' ),
						'description' => __( 'Create an Elementor v4 Global Class with a label + styles; returns the new g- id.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/update-global-class'      => array(
						'label'       => __( 'Update Global Class', 'emcp-tools' ),
						'description' => __( 'Update a Global Class label and/or its styles (per breakpoint/state).', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/delete-global-class'      => array(
						'label'       => __( 'Delete Global Class', 'emcp-tools' ),
						'description' => __( 'Delete a Global Class by g- id (also removes it from elements using it); requires confirm:true.', 'emcp-tools' ),
						'badges'      => array( 'destructive' ),
					),
					'emcp-tools/reorder-global-classes'   => array(
						'label'       => __( 'Reorder Global Classes', 'emcp-tools' ),
						'description' => __( 'Set the Class Manager order (= CSS source order / specificity) of the v4 Global Classes.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/list-variables'           => array(
						'label'       => __( 'List Global Variables', 'emcp-tools' ),
						'description' => __( 'List Elementor design tokens with stable ids, values, types, and watermark.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/create-variable'          => array(
						'label'       => __( 'Create Global Variable', 'emcp-tools' ),
						'description' => __( 'Create an Elementor Global Variable design token.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/update-variable'          => array(
						'label'       => __( 'Update Global Variable', 'emcp-tools' ),
						'description' => __( 'Update a Global Variable label, value, type, or order.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/delete-variable'          => array(
						'label'       => __( 'Delete Global Variable', 'emcp-tools' ),
						'description' => __( 'Soft-delete a Global Variable; requires confirm:true.', 'emcp-tools' ),
						'badges'      => array( 'destructive' ),
					),
					'emcp-tools/restore-variable'         => array(
						'label'       => __( 'Restore Global Variable', 'emcp-tools' ),
						'description' => __( 'Restore a soft-deleted Global Variable.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/batch-variables'           => array(
						'label'       => __( 'Batch Global Variables', 'emcp-tools' ),
						'description' => __( 'Atomically create, update, delete, or restore multiple design tokens.', 'emcp-tools' ),
						'badges'      => array( 'destructive' ),
					),
					'emcp-tools/add-flexbox'              => array(
						'label'       => __( 'Add Flexbox', 'emcp-tools' ),
						'description' => __( 'Adds an atomic flexbox container (e-flexbox).', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/add-div-block'            => array(
						'label'       => __( 'Add Div Block', 'emcp-tools' ),
						'description' => __( 'Adds an atomic div-block container (e-div-block).', 'emcp-tools' ),
						'badges'      => array(),
					),
				),
			);

			$tools['atomic_widgets'] = array(
				'platform' => 'elementor',
				'label' => __( 'Atomic Widgets (Elementor 4.0+)', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/add-atomic-widget'    => array(
						'label'       => __( 'Add Atomic Widget', 'emcp-tools' ),
						'description' => __( 'Universal: adds any atomic widget by type with raw $$type settings.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/update-atomic-widget' => array(
						'label'       => __( 'Update Atomic Widget', 'emcp-tools' ),
						'description' => __( 'Universal: partial-merge update on an existing atomic widget.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/add-atomic-heading'   => array(
						'label'       => __( 'Add Atomic Heading', 'emcp-tools' ),
						'description' => __( 'Adds an atomic heading element (e-heading).', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/add-atomic-paragraph' => array(
						'label'       => __( 'Add Atomic Paragraph', 'emcp-tools' ),
						'description' => __( 'Adds an atomic paragraph element (e-paragraph).', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/add-atomic-button'    => array(
						'label'       => __( 'Add Atomic Button', 'emcp-tools' ),
						'description' => __( 'Adds an atomic button element (e-button).', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/add-atomic-image'     => array(
						'label'       => __( 'Add Atomic Image', 'emcp-tools' ),
						'description' => __( 'Adds an atomic image element (e-image).', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/add-atomic-svg'       => array(
						'label'       => __( 'Add Atomic SVG', 'emcp-tools' ),
						'description' => __( 'Adds an atomic SVG element (e-svg).', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/add-atomic-youtube'   => array(
						'label'       => __( 'Add Atomic YouTube', 'emcp-tools' ),
						'description' => __( 'Adds an atomic YouTube embed (e-youtube).', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/add-atomic-video'     => array(
						'label'       => __( 'Add Atomic Video', 'emcp-tools' ),
						'description' => __( 'Adds an atomic self-hosted video (e-self-hosted-video).', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/add-atomic-divider'   => array(
						'label'       => __( 'Add Atomic Divider', 'emcp-tools' ),
						'description' => __( 'Adds an atomic divider element (e-divider).', 'emcp-tools' ),
						'badges'      => array(),
					),
				),
			);
		}

		// Brand Kits (Pro). Only shown to licensed sites — the underlying
		// abilities register only for Pro, matching this gate. No 'pro' badge so
		// they are NOT auto-disabled by maybe_apply_default_disabled_tools (this
		// is a headline Pro feature, on by default for licensed users).
		if (
			class_exists( 'EMCP_Tools_Pro_Brand_Kits' )
			&& EMCP_Tools_Pro_Brand_Kits::user_has_access()
		) {
			$tools['brand_kits'] = array(
				'platform' => 'elementor',
				'label' => __( 'Brand Kits', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/list-brand-kits'           => array(
						'label'       => __( 'List Brand Kits', 'emcp-tools' ),
						'description' => __( 'Lists available premium brand kits from the cached library.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/apply-brand-kit'           => array(
						'label'       => __( 'Apply Brand Kit', 'emcp-tools' ),
						'description' => __( 'Applies a brand kit: replaces system colors + typography site-wide.', 'emcp-tools' ),
						'badges'      => array( 'destructive' ),
					),
					'emcp-tools/replace-system-colors'     => array(
						'label'       => __( 'Replace System Colors', 'emcp-tools' ),
						'description' => __( 'Replaces the four Elementor system color slots atomically.', 'emcp-tools' ),
						'badges'      => array( 'destructive' ),
					),
					'emcp-tools/replace-system-typography' => array(
						'label'       => __( 'Replace System Typography', 'emcp-tools' ),
						'description' => __( 'Replaces the four Elementor system typography slots atomically.', 'emcp-tools' ),
						'badges'      => array( 'destructive' ),
					),
				),
			);
		}

		// PHP Code Snippets (Sandbox) — free, but capability-gated and powerful,
		// so all six ship disabled-by-default (maybe_apply_default_disabled_tools
		// v4) and the admin re-enables them here. There is no "activate" tool: an
		// AI can only create drafts; a human admin activates them on the Sandbox tab.
		$tools['php_snippets'] = array(
			'platform' => 'modules',
			'label' => __( 'PHP Snippets (Sandbox)', 'emcp-tools' ),
			'tools' => array(
				'emcp-tools/validate-php-snippet' => array(
					'label'       => __( 'Validate PHP Snippet', 'emcp-tools' ),
					'description' => __( 'Statically checks snippet code (parse + security scan) without storing or running it.', 'emcp-tools' ),
					'badges'      => array( 'read-only' ),
				),
				'emcp-tools/create-php-snippet'   => array(
					'label'       => __( 'Create PHP Snippet', 'emcp-tools' ),
					'description' => __( 'Creates an INACTIVE draft snippet (validated; an admin must activate it before it runs).', 'emcp-tools' ),
					'badges'      => array(),
				),
				'emcp-tools/update-php-snippet'   => array(
					'label'       => __( 'Update PHP Snippet', 'emcp-tools' ),
					'description' => __( 'Updates a snippet\'s code/settings and re-validates.', 'emcp-tools' ),
					'badges'      => array(),
				),
				'emcp-tools/get-php-snippet'      => array(
					'label'       => __( 'Get PHP Snippet', 'emcp-tools' ),
					'description' => __( 'Returns a snippet\'s code, status, shortcode, and validation report.', 'emcp-tools' ),
					'badges'      => array( 'read-only' ),
				),
				'emcp-tools/list-php-snippets'    => array(
					'label'       => __( 'List PHP Snippets', 'emcp-tools' ),
					'description' => __( 'Lists PHP snippets with their status and run context.', 'emcp-tools' ),
					'badges'      => array( 'read-only' ),
				),
				'emcp-tools/delete-php-snippet'   => array(
					'label'       => __( 'Delete PHP Snippet', 'emcp-tools' ),
					'description' => __( 'Permanently deletes a snippet and its sandbox file.', 'emcp-tools' ),
					'badges'      => array( 'destructive' ),
				),
			),
		);

		// Sandbox Cloud (export/import) — free, always-on. Lets a sandbox artifact
		// (custom widget/block/snippet) be exported as a portable bundle and
		// imported on another site, so authored sandbox code isn't stuck to one
		// install. Both read/write in nature but low-risk (data movement, not
		// arbitrary execution) — enabled-by-default, unlike the sandboxes themselves.
		$tools['sandbox_cloud'] = array(
			'platform' => 'modules',
			'label' => __( 'Sandbox Cloud (Export / Import)', 'emcp-tools' ),
			'tools' => array(
				'emcp-tools/export-sandbox-artifact' => array(
					'label'       => __( 'Export Sandbox Artifact', 'emcp-tools' ),
					'description' => __( 'Exports a custom widget/block/snippet as a portable bundle.', 'emcp-tools' ),
					'badges'      => array( 'read-only' ),
				),
				'emcp-tools/import-sandbox-artifact' => array(
					'label'       => __( 'Import Sandbox Artifact', 'emcp-tools' ),
					'description' => __( 'Imports a sandbox artifact bundle produced by export-sandbox-artifact.', 'emcp-tools' ),
					'badges'      => array(),
				),
			),
		);

		// Project Memory (Pro) — recall/remember/save-session-summary. Disabled by
		// default; the approved-guidance injection works with these off.
		$tools['memory'] = array(
			'platform' => 'modules',
			'pro'      => true,
			'label'    => __( 'Project Memory (Pro)', 'emcp-tools' ),
			'tools'    => array(
				'emcp-tools/recall' => array(
					'label'       => __( 'Recall Project Memory', 'emcp-tools' ),
					'description' => __( 'Read approved guidance + recent session summaries so the agent does not re-guess site context.', 'emcp-tools' ),
					'badges'      => array( 'pro', 'read-only' ),
				),
				'emcp-tools/remember' => array(
					'label'       => __( 'Remember Guidance', 'emcp-tools' ),
					'description' => __( 'Propose one guardrail/fact/convention/instruction. Stored pending until a human approves it.', 'emcp-tools' ),
					'badges'      => array( 'pro' ),
				),
				'emcp-tools/save-session-summary' => array(
					'label'       => __( 'Save Session Summary', 'emcp-tools' ),
					'description' => __( 'Record a session summary; the plugin attaches a factual digest of the actual changes.', 'emcp-tools' ),
					'badges'      => array( 'pro' ),
				),
			),
		);

		// EMCP Cloud — free module, but the tools only register once the site is
		// actually connected to a cloud account, so the whole section is gated on
		// that rather than showing toggles that control nothing.
		if ( class_exists( 'EMCP_Tools_Cloud_Module' ) && EMCP_Tools_Cloud_Module::is_enabled()
			&& class_exists( 'EMCP_Tools_Cloud' ) && EMCP_Tools_Cloud::is_connected() ) {
			$tools['cloud'] = array(
				'platform' => 'modules',
				'label'    => __( 'EMCP Cloud', 'emcp-tools' ),
				'note'     => __( 'Back up and sync your sandbox artifacts and settings to your EMCP Cloud account. These tools appear once this site is connected.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/cloud-status'      => array(
						'label'       => __( 'Cloud Status', 'emcp-tools' ),
						'description' => __( 'Plan, limits, and usage for the connected account.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/cloud-list'        => array(
						'label'       => __( 'Cloud List', 'emcp-tools' ),
						'description' => __( 'List the artifacts backed up to your account, optionally by kind.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/cloud-backup'      => array(
						'label'       => __( 'Cloud Backup', 'emcp-tools' ),
						'description' => __( 'Back up a local sandbox artifact (block, widget, or PHP snippet) to your account.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/cloud-pull'        => array(
						'label'       => __( 'Cloud Pull', 'emcp-tools' ),
						'description' => __( 'Pull a cloud artifact into this site by UUID. It lands as a new inactive draft.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/cloud-config-sync' => array(
						'label'       => __( 'Cloud Config Sync', 'emcp-tools' ),
						'description' => __( 'Push or pull a config blob (settings, brand kit, tool toggles) to or from EMCP Cloud.', 'emcp-tools' ),
						'badges'      => array(),
					),
				),
			);

			$tools['marketplace'] = array(
				'platform' => 'modules',
				'label'    => __( 'Marketplace', 'emcp-tools' ),
				'note'     => __( 'Browse and install published EMCP Cloud marketplace listings. An install always lands as a new inactive draft for you to review before it runs.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/cloud-marketplace-list'    => array(
						'label'       => __( 'Marketplace List', 'emcp-tools' ),
						'description' => __( 'Browse published marketplace listings (blocks, widgets, snippets).', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/cloud-marketplace-install' => array(
						'label'       => __( 'Marketplace Install', 'emcp-tools' ),
						'description' => __( 'Install a listing by slug. It is imported as a new inactive draft.', 'emcp-tools' ),
						'badges'      => array(),
					),
				),
			);
		}

		// Image Optimization — free, opt-in module (it mutates uploads). One tool;
		// the compression and WebP pipeline itself is settings, not MCP surface.
		if ( class_exists( 'EMCP_Tools_Image_Optimization_Module' ) && EMCP_Tools_Image_Optimization_Module::module_is_active() ) {
			$tools['image_optimization'] = array(
				'platform' => 'modules',
				'label'    => __( 'Image Optimization', 'emcp-tools' ),
				'note'     => __( 'Compression, WebP generation, and the bulk optimizer are configured on the Modules tab. This is the one operation exposed over MCP.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/resize-media' => array(
						'label'       => __( 'Resize Media', 'emcp-tools' ),
						'description' => __( 'Resize a Media Library image in place (scale to fit, or crop to exact size). The attachment id and URLs are unchanged, and the original is backed up.', 'emcp-tools' ),
						'badges'      => array(),
					),
				),
			);
		}

		// EMCP Themer — free, module-gated. The template CPT lives under its own
		// top-level menu, but the tools belong on this grid like every other
		// domain, so an admin can see and toggle them individually. All nine are
		// on by default; the module toggle on the Modules tab remains the single
		// kill switch for the whole feature.
		if ( class_exists( 'EMCP_Tools_Themer_Module' ) && EMCP_Tools_Themer_Module::is_enabled() ) {
			$tools['themer'] = array(
				'platform' => 'modules',
				'label'    => __( 'EMCP Themer (theme builder)', 'emcp-tools' ),
				'note'     => __( 'Build headers, footers, and single/archive/search/404 layouts with any page builder, then decide where each one applies. Template CONTENT is built with the Gutenberg or Elementor tools against the returned template_id; these tools create and route the templates.', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/create-theme-template'   => array(
						'label'       => __( 'Create Theme Template', 'emcp-tools' ),
						'description' => __( 'Create a typed theme template (header, footer, single, archive, search, 404).', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/list-theme-templates'    => array(
						'label'       => __( 'List Theme Templates', 'emcp-tools' ),
						'description' => __( 'List theme templates, optionally filtered by type.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/get-theme-template'      => array(
						'label'       => __( 'Get Theme Template', 'emcp-tools' ),
						'description' => __( 'Full detail for one template: type, conditions, detected builder, content status.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/update-theme-template'   => array(
						'label'       => __( 'Update Theme Template', 'emcp-tools' ),
						'description' => __( "Update a template's title or type.", 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/set-template-conditions' => array(
						'label'       => __( 'Set Template Conditions', 'emcp-tools' ),
						'description' => __( 'Set where a template applies. Granular selectors, Exclude rules, and priority require Pro.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/delete-theme-template'   => array(
						'label'       => __( 'Delete Theme Template', 'emcp-tools' ),
						'description' => __( 'Delete a theme template.', 'emcp-tools' ),
						'badges'      => array( 'destructive' ),
					),
					'emcp-tools/resolve-template'        => array(
						'label'       => __( 'Resolve Template', 'emcp-tools' ),
						'description' => __( 'Show which template wins each slot (header/body/footer) for a given post or context. Use it to debug conditions.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/list-condition-targets'  => array(
						'label'       => __( 'List Condition Targets', 'emcp-tools' ),
						'description' => __( 'Discovery: the selectors and objects a template can target, so conditions are built from real values.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/list-dynamic-sources'    => array(
						'label'       => __( 'List Dynamic Sources', 'emcp-tools' ),
						'description' => __( 'Discovery: the dynamic sources this site offers, what each produces, and where each can be used (widget, block, Elementor tag, block binding).', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
				),
			);
		}

		// Themer PHP Templates — free, capability-gated + master-switch-gated;
		// disabled by default. AI authors DRAFTS; a human attaches one in a
		// template metabox (the execution gate). Registered only when the Themer
		// module is active, alongside where the feature actually lives.
		if ( class_exists( 'EMCP_Tools_Themer_Module' ) && EMCP_Tools_Themer_Module::is_enabled() ) {
			$tools['themer_php'] = array(
				'platform' => 'modules',
				'label'    => __( 'Themer PHP Templates', 'emcp-tools' ),
				'tools'    => array(
					'emcp-tools/create-theme-php-template' => array(
						'label'       => __( 'Create Theme PHP Template', 'emcp-tools' ),
						'description' => __( 'Create a validated DRAFT PHP region template (never runs until a human attaches it).', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/list-theme-php-templates'  => array(
						'label'       => __( 'List Theme PHP Templates', 'emcp-tools' ),
						'description' => __( 'List draft PHP templates.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/get-theme-php-template'    => array(
						'label'       => __( 'Get Theme PHP Template', 'emcp-tools' ),
						'description' => __( 'Return one PHP template with its validation report.', 'emcp-tools' ),
						'badges'      => array( 'read-only' ),
					),
					'emcp-tools/update-theme-php-template' => array(
						'label'       => __( 'Update Theme PHP Template', 'emcp-tools' ),
						'description' => __( 'Update a PHP template and re-validate.', 'emcp-tools' ),
						'badges'      => array(),
					),
					'emcp-tools/delete-theme-php-template' => array(
						'label'       => __( 'Delete Theme PHP Template', 'emcp-tools' ),
						'description' => __( 'Delete a PHP template and its sandbox file.', 'emcp-tools' ),
						'badges'      => array( 'destructive' ),
					),
				),
			);
		}

		// SEO & Accessibility toolkit (Pro) + Widget Builder (Pro). ALWAYS added
		// to the catalog so free users see the (locked) Pro surface; get_all_tools()
		// flags each 'pro' category "Requires EMCP Pro" and disables its toggles on
		// free builds, and the abilities themselves stay license-gated. Bare block
		// keeps the two category assignments grouped.
		{
			$tools['seo'] = array(
				'platform' => 'wordpress',
				'pro'      => true,
				'label' => __( 'SEO', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/audit-page-seo'                => array(
						'label'       => __( 'Audit Page SEO', 'emcp-tools' ),
						'description' => __( 'Scored on-page SEO report (H1, title/meta, canonical, alts, links, word count).', 'emcp-tools' ),
						'badges'      => array( 'pro', 'read-only' ),
					),
					'emcp-tools/extract-keywords-from-content' => array(
						'label'       => __( 'Extract Keywords', 'emcp-tools' ),
						'description' => __( 'Frequency keyword + phrase extraction from page content.', 'emcp-tools' ),
						'badges'      => array( 'pro', 'read-only' ),
					),
					'emcp-tools/generate-meta-tags'            => array(
						'label'       => __( 'Generate Meta Tags', 'emcp-tools' ),
						'description' => __( 'Proposes (apply:true writes to Yoast/Rank Math) an SEO title and meta description. Dry-run by default.', 'emcp-tools' ),
						'badges'      => array( 'pro' ),
					),
					'emcp-tools/generate-schema-markup'        => array(
						'label'       => __( 'Generate Schema Markup', 'emcp-tools' ),
						'description' => __( 'Generates (apply:true injects) JSON-LD structured data (Article, LocalBusiness, FAQPage, etc.). Dry-run by default.', 'emcp-tools' ),
						'badges'      => array( 'pro' ),
					),
					'emcp-tools/set-social-image'              => array(
						'label'       => __( 'Set Social Image', 'emcp-tools' ),
						'description' => __( 'Sets the Open Graph + Twitter share image (Yoast / Rank Math) so link previews use the image you choose, not the first content image.', 'emcp-tools' ),
						'badges'      => array( 'pro' ),
					),
				),
			);

			$tools['a11y'] = array(
				'platform' => 'elementor',
				'label' => __( 'Accessibility', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/audit-page-a11y'           => array(
						'label'       => __( 'Audit Page Accessibility', 'emcp-tools' ),
						'description' => __( 'WCAG-oriented report: contrast, alts, heading order, link text, form labels.', 'emcp-tools' ),
						'badges'      => array( 'pro', 'read-only' ),
					),
					'emcp-tools/fix-color-contrast'        => array(
						'label'       => __( 'Fix Color Contrast', 'emcp-tools' ),
						'description' => __( 'Proposes (apply:true to write) adjusted text colors so failing pairs meet WCAG AA. Dry-run by default.', 'emcp-tools' ),
						'badges'      => array( 'pro', 'destructive' ),
					),
					'emcp-tools/add-alt-text-from-context' => array(
						'label'       => __( 'Add Alt Text from Context', 'emcp-tools' ),
						'description' => __( 'Proposes (apply:true to write) alt text for images lacking it, from filename/heading/title. Dry-run by default.', 'emcp-tools' ),
						'badges'      => array( 'pro', 'destructive' ),
					),
				),
			);

			$tools['widget_builder'] = array(
				'platform' => 'elementor',
				'pro'      => true,
				'label' => __( 'Widget Builder (Pro)', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/list-control-types'   => array(
						'label'       => __( 'List Control Types', 'emcp-tools' ),
						'description' => __( 'Returns the control types and template syntax for building widget specs.', 'emcp-tools' ),
						'badges'      => array( 'pro', 'read-only' ),
					),
					'emcp-tools/validate-widget-spec' => array(
						'label'       => __( 'Validate Widget Spec', 'emcp-tools' ),
						'description' => __( 'Validates a widget spec and dry-runs the generator without saving.', 'emcp-tools' ),
						'badges'      => array( 'pro', 'read-only' ),
					),
					'emcp-tools/create-custom-widget' => array(
						'label'       => __( 'Create Custom Widget', 'emcp-tools' ),
						'description' => __( 'Generates a custom Elementor widget from a spec into an isolated sandbox and activates it.', 'emcp-tools' ),
						'badges'      => array( 'pro' ),
					),
					'emcp-tools/update-custom-widget' => array(
						'label'       => __( 'Update Custom Widget', 'emcp-tools' ),
						'description' => __( 'Replaces a custom widget\'s spec and regenerates its code.', 'emcp-tools' ),
						'badges'      => array( 'pro' ),
					),
					'emcp-tools/get-custom-widget'    => array(
						'label'       => __( 'Get Custom Widget', 'emcp-tools' ),
						'description' => __( 'Returns a custom widget\'s spec, generated PHP, status, and last error.', 'emcp-tools' ),
						'badges'      => array( 'pro', 'read-only' ),
					),
					'emcp-tools/list-custom-widgets'  => array(
						'label'       => __( 'List Custom Widgets', 'emcp-tools' ),
						'description' => __( 'Lists all generated custom widgets with their status.', 'emcp-tools' ),
						'badges'      => array( 'pro', 'read-only' ),
					),
					'emcp-tools/set-widget-status'    => array(
						'label'       => __( 'Set Widget Status', 'emcp-tools' ),
						'description' => __( 'Activates or deactivates a custom widget.', 'emcp-tools' ),
						'badges'      => array( 'pro' ),
					),
					'emcp-tools/delete-custom-widget' => array(
						'label'       => __( 'Delete Custom Widget', 'emcp-tools' ),
						'description' => __( 'Permanently deletes a custom widget and its sandbox file.', 'emcp-tools' ),
						'badges'      => array( 'pro', 'destructive' ),
					),
				),
			);

			$tools['block_builder'] = array(
				'platform' => 'gutenberg',
				'pro'      => true,
				'label' => __( 'Block Builder (Pro)', 'emcp-tools' ),
				'tools' => array(
					'emcp-tools/list-block-control-types' => array(
						'label'       => __( 'List Block Control Types', 'emcp-tools' ),
						'description' => __( 'Returns the attribute types and template syntax for building block specs.', 'emcp-tools' ),
						'badges'      => array( 'pro', 'read-only' ),
					),
					'emcp-tools/validate-block-spec'      => array(
						'label'       => __( 'Validate Block Spec', 'emcp-tools' ),
						'description' => __( 'Validates a block spec and dry-runs the generator without saving.', 'emcp-tools' ),
						'badges'      => array( 'pro', 'read-only' ),
					),
					'emcp-tools/create-custom-block'      => array(
						'label'       => __( 'Create Custom Block', 'emcp-tools' ),
						'description' => __( 'Generates a custom Gutenberg block from a spec into an isolated sandbox and activates it.', 'emcp-tools' ),
						'badges'      => array( 'pro' ),
					),
					'emcp-tools/update-custom-block'      => array(
						'label'       => __( 'Update Custom Block', 'emcp-tools' ),
						'description' => __( 'Replaces a custom block\'s spec and regenerates its code.', 'emcp-tools' ),
						'badges'      => array( 'pro' ),
					),
					'emcp-tools/get-custom-block'          => array(
						'label'       => __( 'Get Custom Block', 'emcp-tools' ),
						'description' => __( 'Returns a custom block\'s spec, generated code, status, and last error.', 'emcp-tools' ),
						'badges'      => array( 'pro', 'read-only' ),
					),
					'emcp-tools/list-custom-blocks'        => array(
						'label'       => __( 'List Custom Blocks', 'emcp-tools' ),
						'description' => __( 'Lists all generated custom blocks with their status.', 'emcp-tools' ),
						'badges'      => array( 'pro', 'read-only' ),
					),
					'emcp-tools/set-block-status'          => array(
						'label'       => __( 'Set Block Status', 'emcp-tools' ),
						'description' => __( 'Activates or deactivates a custom block.', 'emcp-tools' ),
						'badges'      => array( 'pro' ),
					),
					'emcp-tools/delete-custom-block'       => array(
						'label'       => __( 'Delete Custom Block', 'emcp-tools' ),
						'description' => __( 'Permanently deletes a custom block and its sandbox file.', 'emcp-tools' ),
						'badges'      => array( 'pro', 'destructive' ),
					),
				),
			);
		}

		return $tools;
	}

	/**
	 * Get a flat list of all tool slugs.
	 *
	 * @since 1.0.0
	 *
	 * @return string[] All tool slugs.
	 */
	public function get_all_tool_slugs(): array {
		$slugs = array();
		foreach ( $this->get_all_tools() as $category ) {
			foreach ( $category['tools'] as $slug => $tool ) {
				$slugs[] = $slug;
			}
		}
		return $slugs;
	}

	/**
	 * Returns only the slugs of tools whose platform group is currently active.
	 *
	 * When Elementor is inactive, Elementor-platform tools are excluded because
	 * they are never registered and must not inflate "X of Y enabled" stats.
	 * Use get_all_tool_slugs() (unfiltered) anywhere the full canonical list is
	 * needed for data-management purposes (e.g. sanitize_disabled_tools).
	 *
	 * @since 3.0.0
	 *
	 * @return string[]
	 */
	public function get_available_tool_slugs(): array {
		$categories = $this->get_all_tools();
		if ( ! EMCP_Tools_Bootstrap::elementor_active() ) {
			$categories = self::filter_out_elementor( $categories );
		}
		$slugs = array();
		foreach ( $categories as $category ) {
			foreach ( $category['tools'] as $slug => $tool ) {
				$slugs[] = $slug;
			}
		}
		return $slugs;
	}

	/**
	 * Count enabled tools.
	 *
	 * @since 1.0.0
	 *
	 * @return int Number of enabled tools.
	 */
	public function get_enabled_tool_count(): int {
		$all = $this->get_available_tool_slugs();

		$disabled = get_option( self::OPTION_DISABLED_TOOLS, array() );
		if ( ! is_array( $disabled ) ) {
			$disabled = array();
		}

		return count( array_diff( $all, $disabled ) );
	}

	/**
	 * Count total tools.
	 *
	 * @since 1.0.0
	 *
	 * @return int Total number of tools.
	 */
	public function get_total_tool_count(): int {
		return count( $this->get_available_tool_slugs() );
	}
}
