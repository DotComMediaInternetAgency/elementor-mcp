<?php
/**
 * Shared safety helper for the Plugins & Themes MCP tools.
 *
 * Centralizes the guardrails that protect a site from an AI agent (or a buggy
 * caller) breaking it: a protected-plugin list (never disable/delete EMCP Tools,
 * Elementor, or Elementor Pro), active-target checks, a direct-filesystem gate
 * (so a headless MCP request never hangs on an FTP-credential prompt), on-demand
 * loading of the wp-admin upgrader includes (absent on REST/WP-CLI requests),
 * and a quiet upgrader skin so installer output is captured, not echoed.
 *
 * @package EMCP_Tools
 * @since   3.0.0
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Static guard utilities shared by the plugin + theme ability groups.
 *
 * @since 3.0.0
 */
class EMCP_Tools_Package_Guard {
	const ZIP_MAX_ENTRIES       = 5000;
	const ZIP_MAX_EXPANDED_SIZE = 268435456; // 256 MB.
	const ZIP_MAX_RATIO         = 200;
	const PACKAGE_HEADER_BYTES  = 16384;

	/**
	 * Plugin files that must never be deactivated or deleted via MCP.
	 *
	 * EMCP Tools itself (disabling it kills the MCP server mid-session) and
	 * Elementor / Elementor Pro (EMCP's hard dependency). The EMCP basename is
	 * self-resolved from the constant, never hardcoded.
	 *
	 * @since 3.0.0
	 * @return string[]
	 */
	public static function protected_plugin_files(): array {
		// Hardcoded core list — ALWAYS enforced. The filter can only ADD to it,
		// never remove an entry (a malicious plugin must not be able to empty the
		// list and then deactivate/delete Elementor or EMCP Tools itself).
		$core = array( 'elementor/elementor.php', 'elementor-pro/elementor-pro.php' );
		if ( defined( 'EMCP_TOOLS_BASENAME' ) ) {
			$core[] = EMCP_TOOLS_BASENAME;
		}
		/**
		 * Filter ADDITIONAL MCP-protected plugin basenames. The hardcoded core
		 * list above is always enforced regardless of what this filter returns.
		 *
		 * @since 3.0.0
		 * @param string[] $extra Extra protected plugin basenames (added to the core list).
		 */
		$extra = (array) apply_filters( 'emcp_tools_protected_plugins', array() );
		return array_values( array_unique( array_merge( $core, $extra ) ) );
	}

	/**
	 * Whether a plugin file is protected from deactivate/delete.
	 *
	 * @since 3.0.0
	 * @param string $file Plugin basename (e.g. "elementor/elementor.php").
	 * @return bool
	 */
	public static function is_protected_plugin( string $file ): bool {
		return in_array( $file, self::protected_plugin_files(), true );
	}

	/**
	 * Whether a plugin is currently active (site or network).
	 *
	 * @since 3.0.0
	 * @param string $file Plugin basename.
	 * @return bool
	 */
	public static function is_active_plugin( string $file ): bool {
		if ( function_exists( 'is_plugin_active' ) && is_plugin_active( $file ) ) {
			return true;
		}
		return function_exists( 'is_plugin_active_for_network' ) && is_plugin_active_for_network( $file );
	}

	/**
	 * The active stylesheet plus its template (parent), both protected from delete.
	 *
	 * @since 3.0.0
	 * @return string[]
	 */
	public static function active_theme_stylesheets(): array {
		$out = array();
		if ( function_exists( 'get_stylesheet' ) ) {
			$out[] = (string) get_stylesheet();
		}
		if ( function_exists( 'get_template' ) ) {
			$out[] = (string) get_template();
		}
		return array_values( array_unique( array_filter( $out ) ) );
	}

	/**
	 * Ensure the filesystem is directly writable before any install/update/delete.
	 *
	 * Returns true when WP can write without credentials; otherwise a WP_Error,
	 * so the tool fails cleanly instead of triggering an interactive FTP prompt
	 * that would hang a headless MCP request.
	 *
	 * @since 3.0.0
	 * @return true|\WP_Error
	 */
	public static function filesystem_ready() {
		self::load_upgrader_deps();
		$method = function_exists( 'get_filesystem_method' ) ? get_filesystem_method() : 'direct';
		if ( 'direct' !== $method ) {
			return new \WP_Error(
				'filesystem_unavailable',
				__( 'The WordPress filesystem is not directly writable on this host (it needs FTP/SSH credentials), so plugin/theme install, update, and delete cannot run over MCP. Use SFTP or set the FS_METHOD/credentials in wp-config.php.', 'emcp-tools' )
			);
		}
		if ( function_exists( 'WP_Filesystem' ) && ! WP_Filesystem() ) {
			return new \WP_Error( 'filesystem_unavailable', __( 'Could not initialise the WordPress filesystem.', 'emcp-tools' ) );
		}
		return true;
	}

	/**
	 * Load the wp-admin upgrader/plugin/theme includes on demand.
	 *
	 * These live under wp-admin/includes and are NOT loaded on the REST/WP-CLI
	 * requests the MCP server runs in. Guarded so each file loads at most once.
	 *
	 * @since 3.0.0
	 */
	public static function load_upgrader_deps(): void {
		if ( ! defined( 'ABSPATH' ) ) {
			return;
		}
		$includes = array(
			'wp-admin/includes/plugin.php',
			'wp-admin/includes/plugin-install.php',
			'wp-admin/includes/theme.php',
			'wp-admin/includes/theme-install.php',
			'wp-admin/includes/file.php',
			'wp-admin/includes/misc.php',
			'wp-admin/includes/update.php',
			'wp-admin/includes/class-wp-upgrader.php',
		);
		foreach ( $includes as $rel ) {
			$path = ABSPATH . $rel;
			if ( is_readable( $path ) ) {
				require_once $path;
			}
		}
	}

	/**
	 * A quiet upgrader skin that captures messages instead of echoing HTML.
	 *
	 * @since 3.0.0
	 * @return object|null Automatic_Upgrader_Skin instance, or null if unavailable.
	 */
	public static function make_skin() {
		self::load_upgrader_deps();
		if ( class_exists( '\Automatic_Upgrader_Skin' ) ) {
			return new \Automatic_Upgrader_Skin();
		}
		return null;
	}

	/**
	 * Pull captured messages off an upgrader skin, normalized to strings.
	 *
	 * @since 3.0.0
	 * @param object|null $skin
	 * @return string[]
	 */
	public static function skin_messages( $skin ): array {
		if ( $skin && method_exists( $skin, 'get_upgrade_messages' ) ) {
			return array_map( 'wp_strip_all_tags', (array) $skin->get_upgrade_messages() );
		}
		return array();
	}

	/**
	 * Validate a ZIP stored as a WordPress media attachment.
	 *
	 * This never accepts a URL or server path. It returns the verified local
	 * attachment path only after archive and package-level checks pass.
	 *
	 * @param int    $attachment_id Attachment ID from upload-media.
	 * @param string $expected_sha256 Expected lowercase/uppercase SHA-256.
	 * @param string $kind plugin or theme.
	 * @return array|\WP_Error
	 */
	public static function prepare_uploaded_zip( int $attachment_id, string $expected_sha256, string $kind ) {
		if ( ! in_array( $kind, array( 'plugin', 'theme' ), true ) ) {
			return new \WP_Error( 'invalid_package_kind', __( 'Package kind must be plugin or theme.', 'emcp-tools' ) );
		}
		if ( ! $attachment_id || 'attachment' !== get_post_type( $attachment_id ) ) {
			return new \WP_Error( 'zip_attachment_not_found', __( 'zip_attachment_id must identify an existing media attachment.', 'emcp-tools' ) );
		}
		$expected_sha256 = strtolower( trim( $expected_sha256 ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $expected_sha256 ) ) {
			return new \WP_Error( 'invalid_sha256', __( 'expected_sha256 must be a 64-character SHA-256 digest.', 'emcp-tools' ) );
		}
		$path = function_exists( 'get_attached_file' ) ? get_attached_file( $attachment_id ) : '';
		$real = $path ? realpath( $path ) : false;
		$uploads = wp_get_upload_dir();
		$base = ! empty( $uploads['basedir'] ) ? realpath( $uploads['basedir'] ) : false;
		if ( ! $real || ! $base || ! is_file( $real ) ) {
			return new \WP_Error( 'zip_file_missing', __( 'The uploaded ZIP file is missing.', 'emcp-tools' ) );
		}
		$real_normalized = strtolower( str_replace( '\\', '/', $real ) );
		$base_normalized = rtrim( strtolower( str_replace( '\\', '/', $base ) ), '/' ) . '/';
		if ( 0 !== strpos( $real_normalized, $base_normalized ) ) {
			return new \WP_Error( 'zip_outside_uploads', __( 'The ZIP attachment must resolve inside the WordPress uploads directory.', 'emcp-tools' ) );
		}
		if ( 'zip' !== strtolower( pathinfo( $real, PATHINFO_EXTENSION ) ) ) {
			return new \WP_Error( 'invalid_zip_type', __( 'The attachment must be a .zip file.', 'emcp-tools' ) );
		}
		$actual_sha256 = strtolower( (string) hash_file( 'sha256', $real ) );
		if ( ! hash_equals( $expected_sha256, $actual_sha256 ) ) {
			return new \WP_Error( 'zip_hash_mismatch', __( 'The uploaded ZIP does not match expected_sha256.', 'emcp-tools' ) );
		}
		if ( ! class_exists( '\\ZipArchive' ) ) {
			return new \WP_Error( 'zip_unavailable', __( 'The PHP ZIP extension is required for uploaded package validation.', 'emcp-tools' ) );
		}

		$zip = new \ZipArchive();
		if ( true !== $zip->open( $real, \ZipArchive::CHECKCONS ) ) {
			return new \WP_Error( 'invalid_zip', __( 'The uploaded archive is malformed or failed its integrity check.', 'emcp-tools' ) );
		}
		if ( $zip->numFiles < 1 || $zip->numFiles > self::ZIP_MAX_ENTRIES ) {
			$zip->close();
			return new \WP_Error( 'zip_entry_limit', __( 'The archive is empty or has too many entries.', 'emcp-tools' ) );
		}

		$root = '';
		$total_size = 0;
		$total_compressed = 0;
		$entries = array();
		for ( $i = 0; $i < $zip->numFiles; $i++ ) {
			$stat = $zip->statIndex( $i );
			$name = str_replace( '\\', '/', (string) ( $stat['name'] ?? '' ) );
			if ( '' === $name || false !== strpos( $name, "\0" ) || '/' === $name[0] || preg_match( '/^[A-Za-z]:\//', $name ) ) {
				$zip->close(); return new \WP_Error( 'unsafe_zip_entry', __( 'The archive contains an absolute or malformed path.', 'emcp-tools' ) );
			}
			$parts = explode( '/', trim( $name, '/' ) );
			if ( in_array( '..', $parts, true ) || in_array( '.', $parts, true ) ) {
				$zip->close(); return new \WP_Error( 'unsafe_zip_entry', __( 'The archive contains a path traversal entry.', 'emcp-tools' ) );
			}
			if ( '__MACOSX' === ( $parts[0] ?? '' ) || '.DS_Store' === basename( $name ) ) { continue; }
			$attributes = 0;
			if ( $zip->getExternalAttributesIndex( $i, $opsys, $attributes ) && 0120000 === ( ( $attributes >> 16 ) & 0170000 ) ) {
				$zip->close(); return new \WP_Error( 'zip_symlink_rejected', __( 'Symbolic links are not allowed in uploaded packages.', 'emcp-tools' ) );
			}
			if ( count( $parts ) < 2 ) {
				if ( '/' !== substr( $name, -1 ) ) {
					$zip->close(); return new \WP_Error( 'invalid_zip_layout', __( 'The archive must contain exactly one top-level package directory.', 'emcp-tools' ) );
				}
				if ( '' === $root ) { $root = (string) $parts[0]; }
				elseif ( $root !== (string) $parts[0] ) {
					$zip->close(); return new \WP_Error( 'multiple_zip_roots', __( 'The archive contains more than one top-level package directory.', 'emcp-tools' ) );
				}
				continue;
			}
			if ( '' === $root ) { $root = (string) $parts[0]; }
			elseif ( $root !== (string) $parts[0] ) {
				$zip->close(); return new \WP_Error( 'multiple_zip_roots', __( 'The archive contains more than one top-level package directory.', 'emcp-tools' ) );
			}
			$total_size       += (int) ( $stat['size'] ?? 0 );
			$total_compressed += (int) ( $stat['comp_size'] ?? 0 );
			$entries[ $name ] = $i;
			if ( $total_size > self::ZIP_MAX_EXPANDED_SIZE ) {
				$zip->close(); return new \WP_Error( 'zip_expanded_size_limit', __( 'The archive expands beyond the allowed size.', 'emcp-tools' ) );
			}
		}
		if ( '' === $root || ( $total_compressed > 0 && ( $total_size / $total_compressed ) > self::ZIP_MAX_RATIO ) ) {
			$zip->close(); return new \WP_Error( 'zip_compression_ratio', __( 'The archive has an unsafe compression ratio or no valid package root.', 'emcp-tools' ) );
		}
		if ( sanitize_key( $root ) !== $root ) {
			$zip->close(); return new \WP_Error( 'invalid_package_slug', __( 'The package directory name must be a lowercase WordPress slug.', 'emcp-tools' ) );
		}

		$main_file = '';
		$headers   = array();
		if ( 'theme' === $kind ) {
			$main_file = $root . '/style.css';
			if ( ! isset( $entries[ $main_file ] ) ) {
				$zip->close(); return new \WP_Error( 'theme_header_missing', __( 'The archive root must contain style.css with a Theme Name header.', 'emcp-tools' ) );
			}
			$headers = self::parse_package_headers( (string) $zip->getFromIndex( $entries[ $main_file ], self::PACKAGE_HEADER_BYTES ), 'theme' );
			if ( '' === $headers['name'] ) { $zip->close(); return new \WP_Error( 'theme_header_missing', __( 'style.css does not contain a valid Theme Name header.', 'emcp-tools' ) ); }
		} else {
			foreach ( $entries as $name => $index ) {
				if ( 1 !== substr_count( $name, '/' ) || 'php' !== strtolower( pathinfo( $name, PATHINFO_EXTENSION ) ) ) { continue; }
				$candidate = self::parse_package_headers( (string) $zip->getFromIndex( $index, self::PACKAGE_HEADER_BYTES ), 'plugin' );
				if ( '' !== $candidate['name'] ) { $main_file = $name; $headers = $candidate; break; }
			}
			if ( '' === $main_file ) { $zip->close(); return new \WP_Error( 'plugin_header_missing', __( 'The archive root must contain a PHP file with a Plugin Name header.', 'emcp-tools' ) ); }
		}
		$zip->close();

		$basename = $main_file;
		if ( 'plugin' === $kind ) {
			foreach ( self::protected_plugin_files() as $protected ) {
				if ( $root === dirname( $protected ) || $basename === $protected ) {
					return new \WP_Error( 'protected_plugin', __( 'This uploaded package targets a protected plugin directory.', 'emcp-tools' ) );
				}
			}
			if ( defined( 'WP_PLUGIN_DIR' ) && file_exists( WP_PLUGIN_DIR . '/' . $root ) ) {
				return new \WP_Error( 'package_exists', __( 'A plugin with this destination directory already exists; uploaded packages never overwrite.', 'emcp-tools' ) );
			}
		} else {
			$theme_root = function_exists( 'get_theme_root' ) ? get_theme_root() : '';
			if ( $theme_root && file_exists( $theme_root . '/' . $root ) ) {
				return new \WP_Error( 'package_exists', __( 'A theme with this stylesheet directory already exists; uploaded packages never overwrite.', 'emcp-tools' ) );
			}
		}
		if ( '' !== $headers['requires_php'] && version_compare( PHP_VERSION, $headers['requires_php'], '<' ) ) {
			/* translators: %s: Minimum required PHP version. */
			return new \WP_Error( 'incompatible_php', sprintf( __( 'This package requires PHP %s or newer.', 'emcp-tools' ), $headers['requires_php'] ) );
		}
		$wp_version = function_exists( 'get_bloginfo' ) ? (string) get_bloginfo( 'version' ) : '';
		if ( '' !== $headers['requires_wp'] && '' !== $wp_version && version_compare( $wp_version, $headers['requires_wp'], '<' ) ) {
			/* translators: %s: Minimum required WordPress version. */
			return new \WP_Error( 'incompatible_wordpress', sprintf( __( 'This package requires WordPress %s or newer.', 'emcp-tools' ), $headers['requires_wp'] ) );
		}
		return array( 'path' => $real, 'sha256' => $actual_sha256, 'root' => $root, 'main_file' => $main_file, 'headers' => $headers, 'attachment_id' => $attachment_id );
	}

	/** Parse the small, explicit header subset needed before extraction. */
	private static function parse_package_headers( string $contents, string $kind ): array {
		$names = array(
			'name'         => 'plugin' === $kind ? 'Plugin Name' : 'Theme Name',
			'requires_wp'  => 'Requires at least',
			'requires_php' => 'Requires PHP',
		);
		$out = array();
		foreach ( $names as $key => $label ) {
			$out[ $key ] = preg_match( '/^[ \t\/*#@]*' . preg_quote( $label, '/' ) . '[ \t]*:[ \t]*(.+)$/mi', substr( $contents, 0, self::PACKAGE_HEADER_BYTES ), $match ) ? trim( preg_replace( '/\s*(?:\*\/)?\s*$/', '', $match[1] ) ) : '';
		}
		return $out;
	}
}
