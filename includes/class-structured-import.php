<?php
/**
 * Shared contract for agent-parsed document imports.
 *
 * The agent parses PDF, spreadsheet, CSV, or document content locally. The
 * server only accepts bounded structured JSON and binds preview to apply with a
 * deterministic hash.
 *
 * @package EMCP_Tools
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class EMCP_Tools_Structured_Import {
	const MAX_ITEMS = 100;

	/** Validate and normalize the common item envelope. */
	public static function normalize_items( $items ) {
		if ( ! is_array( $items ) || array() === $items ) {
			return new \WP_Error( 'missing_items', __( 'A non-empty "items" array is required.', 'emcp-tools' ) );
		}
		if ( count( $items ) > self::MAX_ITEMS ) {
			return new \WP_Error( 'chunk_too_large', sprintf( __( 'A single import call is limited to %d items. Send additional chunks separately.', 'emcp-tools' ), self::MAX_ITEMS ) );
		}

		$normalized = array();
		$seen       = array();
		foreach ( array_values( $items ) as $index => $item ) {
			if ( ! is_array( $item ) ) {
				return new \WP_Error( 'invalid_item', sprintf( __( 'Import item %d must be an object.', 'emcp-tools' ), $index ) );
			}
			$client_ref = isset( $item['client_ref'] ) ? trim( (string) $item['client_ref'] ) : '';
			if ( '' === $client_ref ) {
				return new \WP_Error( 'missing_client_ref', sprintf( __( 'Import item %d requires client_ref.', 'emcp-tools' ), $index ) );
			}
			if ( isset( $seen[ $client_ref ] ) ) {
				return new \WP_Error( 'duplicate_client_ref', sprintf( __( 'Duplicate client_ref "%s".', 'emcp-tools' ), $client_ref ) );
			}
			if ( ! isset( $item['data'] ) || ! is_array( $item['data'] ) ) {
				return new \WP_Error( 'invalid_item_data', sprintf( __( 'Import item "%s" requires a data object.', 'emcp-tools' ), $client_ref ) );
			}
			$source = $item['source'] ?? array();
			if ( ! is_array( $source ) ) {
				return new \WP_Error( 'invalid_item_source', sprintf( __( 'Import item "%s" source must be an object.', 'emcp-tools' ), $client_ref ) );
			}

			$seen[ $client_ref ] = true;
			$normalized[]        = array(
				'client_ref' => $client_ref,
				'source'     => self::canonicalize( $source ),
				'data'       => self::canonicalize( $item['data'] ),
			);
		}
		return $normalized;
	}

	/** Build a stable payload hash without sorting the item sequence. */
	public static function plan_hash( string $domain, array $items ): string {
		$payload = array( 'domain' => $domain, 'items' => self::canonicalize( $items ) );
		return hash( 'sha256', (string) wp_json_encode( $payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE ) );
	}

	/** Compare a caller-provided plan hash in constant time. */
	public static function require_plan_hash( $provided, string $expected ) {
		$provided = strtolower( trim( (string) $provided ) );
		if ( ! preg_match( '/^[a-f0-9]{64}$/', $provided ) || ! hash_equals( $expected, $provided ) ) {
			return new \WP_Error( 'plan_mismatch', __( 'plan_hash is missing or does not match this exact import payload. Preview the unchanged payload again.', 'emcp-tools' ) );
		}
		return true;
	}

	/** Sort associative keys recursively while preserving list order. */
	private static function canonicalize( $value ) {
		if ( ! is_array( $value ) ) {
			return $value;
		}
		$is_list = array_keys( $value ) === range( 0, count( $value ) - 1 );
		if ( ! $is_list ) {
			ksort( $value, SORT_STRING );
		}
		foreach ( $value as $key => $item ) {
			$value[ $key ] = self::canonicalize( $item );
		}
		return $value;
	}
}
