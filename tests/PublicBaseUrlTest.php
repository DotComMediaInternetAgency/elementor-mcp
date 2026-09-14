<?php
/**
 * Public unit tests for EMCP_Tools_Site_Context::public_base_url() /
 * detected_base_url() / mcp_endpoint() — the canonical reachable-URL resolver
 * that fixes staging hosts where home_url() (pinned) differs from rest_url()
 * (reachable).
 *
 * @package EMCP_Tools
 */

require_once dirname( __DIR__ ) . '/includes/class-site-context.php';

class PublicBaseUrlTest extends \PHPUnit\Framework\TestCase {

	protected function setUp(): void {
		emcp_test_reset();
	}

	/** Normal site: rest_url host == home_url host → detected == home_url base. */
	public function test_detected_matches_home_on_a_normal_site() {
		// Harness home_url() = http://example.test; default rest base derives from it.
		$this->assertSame( 'http://example.test', EMCP_Tools_Site_Context::detected_base_url() );
	}

	/** Staging split: rest_url answers on the reachable host, home_url is pinned. */
	public function test_detected_uses_rest_host_not_home_on_staging() {
		$GLOBALS['emcp_test']['rest_url_base'] = 'https://royalblue-stork-540633.hostingersite.com/wp-json';
		// home_url() (pinned) is still example.test, but detected follows rest_url.
		$this->assertSame( 'https://royalblue-stork-540633.hostingersite.com', EMCP_Tools_Site_Context::detected_base_url() );
	}

	/** No override → public_base_url() == detected. */
	public function test_public_base_url_defaults_to_detected() {
		$GLOBALS['emcp_test']['rest_url_base'] = 'https://reach.test/wp-json';
		$this->assertSame( 'https://reach.test', EMCP_Tools_Site_Context::public_base_url() );
	}

	/** Admin override wins over detection, trailing slash trimmed. */
	public function test_override_wins() {
		$GLOBALS['emcp_test']['rest_url_base']                          = 'https://reach.test/wp-json';
		$GLOBALS['emcp_test']['options']['emcp_tools_public_base_url'] = 'https://override.test/';
		$this->assertSame( 'https://override.test', EMCP_Tools_Site_Context::public_base_url() );
		$this->assertSame( 'override.test', EMCP_Tools_Site_Context::public_host() );
	}

	/** mcp_endpoint(): no override → rest_url() endpoint. */
	public function test_mcp_endpoint_no_override_uses_rest_url() {
		$GLOBALS['emcp_test']['rest_url_base'] = 'https://reach.test/wp-json';
		$this->assertSame( 'https://reach.test/wp-json/mcp/emcp-tools-server', EMCP_Tools_Site_Context::mcp_endpoint() );
	}

	/** mcp_endpoint(): override → override + pretty REST path. */
	public function test_mcp_endpoint_override() {
		$GLOBALS['emcp_test']['options']['emcp_tools_public_base_url'] = 'https://override.test';
		$this->assertSame( 'https://override.test/wp-json/mcp/emcp-tools-server', EMCP_Tools_Site_Context::mcp_endpoint() );
	}

	/** rest_endpoint(): override is authoritative for ANY path (oauth token/register). */
	public function test_rest_endpoint_override_is_authoritative() {
		$GLOBALS['emcp_test']['options']['emcp_tools_public_base_url'] = 'https://override.test';
		$this->assertSame( 'https://override.test/wp-json/emcp-tools/oauth/v1', EMCP_Tools_Site_Context::rest_endpoint( 'emcp-tools/oauth/v1' ) );
		$this->assertSame( 'https://override.test/wp-json/mcp/emcp-tools-server', EMCP_Tools_Site_Context::rest_endpoint( 'mcp/emcp-tools-server' ) );
	}

	/** rest_endpoint(): no override → rest_url() for the path. */
	public function test_rest_endpoint_no_override_uses_rest_url() {
		$GLOBALS['emcp_test']['rest_url_base'] = 'https://reach.test/wp-json';
		$this->assertSame( 'https://reach.test/wp-json/emcp-tools/oauth/v1', EMCP_Tools_Site_Context::rest_endpoint( 'emcp-tools/oauth/v1' ) );
	}

	/** Plain-permalink rest_url (?rest_route=) → detected keeps scheme+host. */
	public function test_detected_plain_permalinks() {
		$GLOBALS['emcp_test']['rest_url_base'] = 'https://plain.test/?rest_route=';
		// rest_url('') → 'https://plain.test/?rest_route=/'; no /wp-json/ to strip,
		// so it falls back to scheme+host from wp_parse_url.
		$this->assertSame( 'https://plain.test', EMCP_Tools_Site_Context::detected_base_url() );
	}

	/**
	 * Real-world plain permalinks: rest_url() on WordPress actually routes
	 * through index.php (https://host/index.php?rest_route=/), unlike the root
	 * query-only shape in test_detected_plain_permalinks() above. detected_base_url()
	 * must strip that index.php the same way it strips /wp-json/ on pretty
	 * permalinks — it's REST routing, not part of the site's base URL. Left
	 * unstripped, this leaked into the Connection tab's detected default and the
	 * generated .mcpb bundle's baked-in site URL on any plain-permalink site.
	 */
	public function test_detected_plain_permalinks_strips_index_php() {
		$GLOBALS['emcp_test']['rest_url_base'] = 'https://plain.test/index.php?rest_route=';
		$this->assertSame( 'https://plain.test', EMCP_Tools_Site_Context::detected_base_url() );
	}

	/** Same index.php stripping, but on a subdirectory plain-permalink install. */
	public function test_detected_plain_permalinks_strips_index_php_in_subdirectory() {
		$GLOBALS['emcp_test']['rest_url_base'] = 'https://plain.test/blog/index.php?rest_route=';
		$this->assertSame( 'https://plain.test/blog', EMCP_Tools_Site_Context::detected_base_url() );
	}
}
