<?php
/**
 * EMCP Cloud OAuth client: DCR -> authorize -> callback -> token -> refresh -> revoke.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EMCP_Tools_Cloud_Connect {
	const ACTION_CONNECT    = 'emcp_tools_cloud_connect';
	const ACTION_CALLBACK   = 'emcp_tools_cloud_callback';
	const ACTION_DISCONNECT = 'emcp_tools_cloud_disconnect';
	const PENDING_TRANSIENT = 'emcp_tools_cloud_pending';
	// Treat the access token as expired this many seconds early (matches the
	// client's own leeway) when deciding whether a concurrent request already
	// refreshed it.
	const REFRESH_LEEWAY = 60;
	// Seconds a waiting request blocks on the refresh mutex before giving up and
	// proceeding best-effort. Kept short so an admin page never stalls.
	const REFRESH_LOCK_WAIT = 5;

	/**
	 * Register the admin-post handlers (called from the module's register()).
	 *
	 * @return void
	 */
	public static function init(): void {
		add_action( 'admin_post_' . self::ACTION_CONNECT, array( __CLASS__, 'handle_connect' ) );
		add_action( 'admin_post_' . self::ACTION_CALLBACK, array( __CLASS__, 'handle_callback' ) );
		add_action( 'admin_post_' . self::ACTION_DISCONNECT, array( __CLASS__, 'handle_disconnect' ) );
	}

	/**
	 * @return string The admin-post callback the provider redirects back to.
	 */
	public static function redirect_uri(): string {
		return admin_url( 'admin-post.php?action=' . self::ACTION_CALLBACK );
	}

	/**
	 * @return string Origin header value for token/refresh/revoke (the website origin).
	 */
	private static function origin(): string {
		return EMCP_Tools_Cloud::base_url();
	}

	/**
	 * Dynamic Client Registration: create a public PKCE client.
	 *
	 * @return array{client_id:string,registration_proof:string}|\WP_Error The registration, or an error.
	 */
	public static function register_client() {
		$res = EMCP_Tools_Cloud_Http::post_json(
			EMCP_Tools_Cloud::base_url() . '/api/auth/oauth2/register',
			array(
				'redirect_uris'              => array( self::redirect_uri() ),
				'token_endpoint_auth_method' => 'none',
				'client_name'                => (string) get_bloginfo( 'name' ),
				'scope'                      => EMCP_Tools_Cloud::SCOPES,
			)
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$id    = (string) ( $res['json']['client_id'] ?? '' );
		$proof = (string) ( $res['json']['emcp_site_registration_proof'] ?? '' );
		$code = (int) $res['code'];
		if ( ( 200 !== $code && 201 !== $code ) || '' === $id ) {
			return new \WP_Error( 'dcr_failed', __( 'Could not register this site with EMCP Cloud.', 'emcp-tools' ) );
		}
		return array(
			'client_id'         => $id,
			'registration_proof' => $proof,
		);
	}

	/**
	 * Build the browser authorize URL (PKCE + state carrying site identity).
	 *
	 * @param string $client_id Registered client id.
	 * @param string $verifier  PKCE verifier (challenge derived here).
	 * @param string $csrf      Opaque CSRF token embedded in state.
	 * @param string $registration_proof Optional server-issued DCR ownership proof.
	 * @return string
	 */
	public static function authorize_url( string $client_id, string $verifier, string $csrf, string $registration_proof = '' ): string {
		$state_payload = array(
			'site_uuid' => EMCP_Tools_Cloud::site_uuid(),
			'name'      => (string) get_bloginfo( 'name' ),
			// This is the only side that knows about an administrator's Server URL
			// override and WordPress subdirectory, so send the callback base directly.
			'base'      => class_exists( 'EMCP_Tools_Site_Context' )
				? EMCP_Tools_Site_Context::public_base_url()
				: rtrim( (string) home_url(), '/' ),
			'csrf'      => $csrf,
		);
		if ( '' !== $registration_proof ) {
			$state_payload['registration_proof'] = $registration_proof;
		}
		$state = EMCP_Tools_OAuth_Util::base64url_encode( (string) wp_json_encode( $state_payload ) );
		$params = array(
			'response_type'         => 'code',
			'client_id'             => $client_id,
			'redirect_uri'          => self::redirect_uri(),
			'scope'                 => EMCP_Tools_Cloud::SCOPES,
			'state'                 => $state,
			'code_challenge'        => EMCP_Tools_OAuth_Util::code_challenge_s256( $verifier ),
			'code_challenge_method' => 'S256',
		);
		return EMCP_Tools_Cloud::base_url() . '/api/auth/oauth2/authorize?' . http_build_query( $params );
	}

	/**
	 * Exchange an authorization code for tokens (form-encoded + Origin). On
	 * success, saves the connection bundle and returns it.
	 *
	 * @param string $code      Authorization code.
	 * @param string $verifier  PKCE verifier.
	 * @param string $client_id Client id.
	 * @return array|\WP_Error
	 */
	public static function exchange_code( string $code, string $verifier, string $client_id ) {
		$res = EMCP_Tools_Cloud_Http::post_form(
			EMCP_Tools_Cloud::base_url() . '/api/auth/oauth2/token',
			array(
				'grant_type'    => 'authorization_code',
				'code'          => $code,
				'redirect_uri'  => self::redirect_uri(),
				'client_id'     => $client_id,
				'code_verifier' => $verifier,
			),
			array( 'Origin' => self::origin() )
		);
		if ( is_wp_error( $res ) ) {
			return $res;
		}
		$j = $res['json'];
		if ( 200 !== (int) $res['code'] || empty( $j['access_token'] ) ) {
			return new \WP_Error( 'token_failed', __( 'EMCP Cloud rejected the connection.', 'emcp-tools' ) );
		}
		$bundle = array(
			'access_token'      => (string) $j['access_token'],
			'refresh_token'     => (string) ( $j['refresh_token'] ?? '' ),
			'access_expires_at' => time() + (int) ( $j['expires_in'] ?? 3600 ),
			'client_id'         => $client_id,
			'connected_at'      => time(),
		);
		EMCP_Tools_Cloud::save_connection( $bundle );
		return $bundle;
	}

	/**
	 * Refresh the stored access token.
	 *
	 * The Cloud provider (Better Auth) ROTATES the refresh token on every
	 * refresh — each success mints a new refresh token and invalidates the old
	 * one. Two concurrent WordPress requests (a second admin tab, a heartbeat,
	 * an MCP call) that both see the access token expired would each POST the
	 * same refresh token; the first wins and rotates it, the second is rejected
	 * with invalid_grant. Naively that second request would flip the connection
	 * to "unhealthy" and overwrite the freshly-rotated token with the dead one,
	 * which is exactly what surfaces as a spurious "Reconnect needed".
	 *
	 * Guards, in order: (1) a best-effort DB mutex serialises refreshes; (2) a
	 * double-check re-reads the bundle after the lock and bails if another
	 * request already refreshed; (3) an auth rejection that coincides with a
	 * concurrent rotation is treated as success and never clobbers the good
	 * bundle; (4) network/5xx blips are transient and do NOT mark unhealthy.
	 *
	 * @return bool
	 */
	public static function refresh(): bool {
		$c = EMCP_Tools_Cloud::get_connection();
		if ( empty( $c['refresh_token'] ) || empty( $c['client_id'] ) ) {
			return false;
		}

		$lock_key = 'emcp_cloud_refresh_' . substr( md5( (string) $c['client_id'] ), 0, 24 );
		$locked   = self::db_lock( $lock_key, self::REFRESH_LOCK_WAIT );

		// Double-checked locking: a request we waited behind may have already
		// refreshed. Re-read and short-circuit when the token is fresh again.
		$c = EMCP_Tools_Cloud::get_connection();
		if ( empty( $c['refresh_token'] ) || empty( $c['client_id'] ) ) {
			self::db_unlock( $lock_key, $locked );
			return false;
		}
		if ( self::access_token_fresh( $c ) ) {
			self::db_unlock( $lock_key, $locked );
			return true;
		}

		// Serialization failed: another request holds the refresh lock and is
		// still in-flight past our wait. Do NOT present our refresh token
		// concurrently — the loser reuses a token the provider (Better Auth) has
		// just rotated out, and reuse of a rotated refresh token trips its theft
		// detection, which invalidates the ENTIRE token family and hard-forces a
		// "Reconnect needed". Bail transiently instead: the in-flight winner will
		// refresh, and the next request short-circuits on the fresh token.
		// GET_LOCK auto-frees if the winner's connection dies, so this can't wedge.
		if ( ! $locked ) {
			return false;
		}

		$used_rt = (string) $c['refresh_token'];
		$res     = EMCP_Tools_Cloud_Http::post_form(
			EMCP_Tools_Cloud::base_url() . '/api/auth/oauth2/token',
			array(
				'grant_type'    => 'refresh_token',
				'refresh_token' => $used_rt,
				'client_id'     => (string) $c['client_id'],
			),
			array( 'Origin' => self::origin() )
		);

		// Transient failure (no response or a server-side 5xx): leave the
		// connection untouched so the next request retries. Marking it unhealthy
		// on a blip is a false "Reconnect needed".
		if ( is_wp_error( $res ) || (int) $res['code'] >= 500 ) {
			self::db_unlock( $lock_key, $locked );
			return false;
		}

		if ( 200 !== (int) $res['code'] || empty( $res['json']['access_token'] ) ) {
			// Auth rejection. If a concurrent request already rotated the token
			// (stored RT changed) or the access token is fresh again, this is
			// just the loser of a race — succeed without touching the bundle.
			$fresh = EMCP_Tools_Cloud::get_connection();
			$won   = ( ! empty( $fresh['refresh_token'] ) && (string) $fresh['refresh_token'] !== $used_rt )
				|| self::access_token_fresh( $fresh );
			if ( $won ) {
				self::db_unlock( $lock_key, $locked );
				return true;
			}
			$fresh['unhealthy'] = true;
			EMCP_Tools_Cloud::save_connection( $fresh );
			self::db_unlock( $lock_key, $locked );
			return false;
		}

		// Success. Merge onto the freshest stored bundle so we never drop a
		// concurrent write of an unrelated field.
		$j                         = $res['json'];
		$save                      = EMCP_Tools_Cloud::get_connection();
		$save['access_token']      = (string) $j['access_token'];
		$save['refresh_token']     = (string) ( $j['refresh_token'] ?? ( $save['refresh_token'] ?? $used_rt ) );
		$save['access_expires_at'] = time() + (int) ( $j['expires_in'] ?? 3600 );
		unset( $save['unhealthy'] );
		EMCP_Tools_Cloud::save_connection( $save );
		self::db_unlock( $lock_key, $locked );
		return true;
	}

	/**
	 * @param array $c Connection bundle.
	 * @return bool Whether the bundle's access token is still valid beyond the leeway.
	 */
	private static function access_token_fresh( array $c ): bool {
		return ! empty( $c['access_token'] )
			&& (int) ( $c['access_expires_at'] ?? 0 ) - self::REFRESH_LEEWAY > time();
	}

	/**
	 * Best-effort cross-request mutex via MySQL GET_LOCK (per-connection; auto
	 * released if the request dies). Degrades to a no-op where $wpdb is absent
	 * (unit tests) — the double-check + anti-clobber guards still hold.
	 *
	 * @param string $key     Lock name (<= 64 chars for MySQL).
	 * @param int    $timeout Seconds to wait for the lock.
	 * @return bool Whether the lock was actually acquired.
	 */
	private static function db_lock( string $key, int $timeout ): bool {
		global $wpdb;
		// No DB to serialize on (unit tests / single-process CLI): there is no
		// concurrency to guard, so treat the lock as acquired and let the refresh
		// proceed. In real WordPress $wpdb is always present, so this branch never
		// weakens the cross-request mutex — a genuine GET_LOCK timeout still
		// returns false and makes refresh() bail without reusing a rotated token.
		if ( ! is_object( $wpdb ) || ! method_exists( $wpdb, 'get_var' ) || ! method_exists( $wpdb, 'prepare' ) ) {
			return true;
		}
		return '1' === (string) $wpdb->get_var( $wpdb->prepare( 'SELECT GET_LOCK(%s, %d)', $key, $timeout ) );
	}

	/**
	 * @param string $key    Lock name.
	 * @param bool   $locked Whether db_lock() actually acquired it.
	 * @return void
	 */
	private static function db_unlock( string $key, bool $locked ): void {
		global $wpdb;
		if ( $locked && is_object( $wpdb ) && method_exists( $wpdb, 'query' ) && method_exists( $wpdb, 'prepare' ) ) {
			$wpdb->query( $wpdb->prepare( 'SELECT RELEASE_LOCK(%s)', $key ) );
		}
	}

	/**
	 * Best-effort remote revoke of the refresh token.
	 *
	 * @return void
	 */
	public static function revoke_remote(): void {
		$c = EMCP_Tools_Cloud::get_connection();
		if ( empty( $c['refresh_token'] ) ) {
			return;
		}
		EMCP_Tools_Cloud_Http::post_form(
			EMCP_Tools_Cloud::base_url() . '/api/auth/oauth2/revoke',
			array(
				'token'     => (string) $c['refresh_token'],
				'client_id' => (string) ( $c['client_id'] ?? '' ),
			),
			array( 'Origin' => self::origin() )
		);
	}

	// ── Admin-post handlers ──────────────────────────────────────────────────

	private static function guard_cap(): void {
		if ( ! current_user_can( 'manage_options' ) ) {
			wp_die( esc_html__( 'You do not have permission to do that.', 'emcp-tools' ), '', array( 'response' => 403 ) );
		}
	}

	private static function back( string $flag ): void {
		wp_safe_redirect( admin_url( 'admin.php?page=emcp-tools-connection&' . $flag . '#emcp-conn-main' ) );
		exit;
	}

	/**
	 * Outbound: DCR, then redirect the browser to authorize. Nonce-protected.
	 *
	 * @return void
	 */
	public static function handle_connect(): void {
		self::guard_cap();
		check_admin_referer( self::ACTION_CONNECT );
		$registration = self::register_client();
		if ( is_wp_error( $registration ) ) {
			self::back( 'cloud_error=dcr' );
		}
		$client_id          = (string) $registration['client_id'];
		$registration_proof = (string) $registration['registration_proof'];
		$verifier           = EMCP_Tools_OAuth_Util::generate_code_verifier();
		$csrf               = EMCP_Tools_OAuth_Util::generate_token();
		// phpcs:ignore WordPress.Security.NonceVerification.Missing -- nonce already checked by check_admin_referer() above.
		$gateway_optin = isset( $_POST['emcp_gateway_optin'] );
		set_transient(
			self::PENDING_TRANSIENT,
			array(
				'verifier'  => $verifier,
				'csrf'      => $csrf,
				'client_id' => $client_id,
				'gateway'   => $gateway_optin,
			),
			600
		);
		// The authorize URL is on the Cloud host, not this site. wp_safe_redirect()
		// blocks off-site hosts (falling back to wp-admin), so allow the Cloud host
		// for this one deliberate redirect to our own service.
		$cloud_host = wp_parse_url( EMCP_Tools_Cloud::base_url(), PHP_URL_HOST );
		if ( $cloud_host ) {
			add_filter(
				'allowed_redirect_hosts',
				static function ( $hosts ) use ( $cloud_host ) {
					$hosts[] = $cloud_host;
					return $hosts;
				}
			);
		}
		wp_safe_redirect( self::authorize_url( $client_id, $verifier, $csrf, $registration_proof ) );
		exit;
	}

	/**
	 * Inbound provider redirect: validate STATE (not a nonce), exchange the code.
	 *
	 * @return void
	 */
	public static function handle_callback(): void {
		self::guard_cap();
		// phpcs:disable WordPress.Security.NonceVerification.Recommended -- provider redirect; validated by state below.
		$code     = isset( $_GET['code'] ) ? sanitize_text_field( wp_unslash( $_GET['code'] ) ) : '';
		$state_in = isset( $_GET['state'] ) ? sanitize_text_field( wp_unslash( $_GET['state'] ) ) : '';
		// phpcs:enable WordPress.Security.NonceVerification.Recommended
		$pending = get_transient( self::PENDING_TRANSIENT );
		delete_transient( self::PENDING_TRANSIENT );
		if ( ! is_array( $pending ) || '' === $code ) {
			self::back( 'cloud_error=state' );
		}
		$decoded = json_decode( EMCP_Tools_OAuth_Util::base64url_decode( $state_in ), true );
		$csrf    = is_array( $decoded ) ? (string) ( $decoded['csrf'] ?? '' ) : '';
		if ( ! EMCP_Tools_OAuth_Util::secure_equals( (string) $pending['csrf'], $csrf ) ) {
			self::back( 'cloud_error=state' );
		}
		$bundle = self::exchange_code( $code, (string) $pending['verifier'], (string) $pending['client_id'] );
		if ( ! is_wp_error( $bundle ) && ! empty( $pending['gateway'] ) && class_exists( 'EMCP_Tools_Gateway_Credential' ) ) {
			// Best-effort: the Cloud connection has already succeeded above, so a
			// gateway provisioning failure here must never turn into a user-facing
			// error — it just leaves the gateway un-provisioned for this site.
			EMCP_Tools_Gateway_Credential::provision( get_current_user_id() );
		}
		self::back( is_wp_error( $bundle ) ? 'cloud_error=token' : 'cloud_connected=1' );
	}

	/**
	 * Disconnect: revoke remotely + clear local. Nonce-protected.
	 *
	 * @return void
	 */
	public static function handle_disconnect(): void {
		self::guard_cap();
		check_admin_referer( self::ACTION_DISCONNECT );
		if ( class_exists( 'EMCP_Tools_Gateway_Credential' ) ) {
			EMCP_Tools_Gateway_Credential::deprovision(); // Cloud delete needs the live connection → before clear_connection().
		}
		self::revoke_remote();
		EMCP_Tools_Cloud::clear_connection();
		self::back( 'cloud_disconnected=1' );
	}

	/**
	 * @return string Nonce'd connect button URL.
	 */
	public static function connect_url(): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_CONNECT ), self::ACTION_CONNECT );
	}

	/**
	 * @return string Nonce'd disconnect button URL.
	 */
	public static function disconnect_url(): string {
		return wp_nonce_url( admin_url( 'admin-post.php?action=' . self::ACTION_DISCONNECT ), self::ACTION_DISCONNECT );
	}
}
