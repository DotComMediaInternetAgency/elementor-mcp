<?php

use PHPUnit\Framework\TestCase;

final class PackageGuardTest extends TestCase {
	private $temp_dir = '';

	protected function setUp(): void {
		parent::setUp();
		emcp_test_reset();
		if ( ! class_exists( 'ZipArchive' ) ) {
			$this->markTestSkipped( 'The ZIP extension is unavailable.' );
		}
		$this->temp_dir = sys_get_temp_dir() . '/emcp-package-guard-' . uniqid();
		mkdir( $this->temp_dir, 0777, true );
		$GLOBALS['emcp_test']['uploads_basedir'] = $this->temp_dir;
	}

	protected function tearDown(): void {
		$this->remove_tree( $this->temp_dir );
		parent::tearDown();
	}

	public function test_accepts_a_standard_zip_with_an_explicit_root_directory_entry(): void {
		$path = $this->temp_dir . '/normal-plugin.zip';
		$zip  = new ZipArchive();
		$this->assertTrue( $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		$zip->addEmptyDir( 'normal-plugin' );
		$zip->addFromString( 'normal-plugin/normal-plugin.php', "<?php\n/*\nPlugin Name: Normal Plugin\nRequires PHP: 8.1\n*/\n" );
		$zip->close();

		$this->register_attachment( 501, $path );
		$result = EMCP_Tools_Package_Guard::prepare_uploaded_zip( 501, hash_file( 'sha256', $path ), 'plugin' );

		$this->assertIsArray( $result );
		$this->assertSame( 'normal-plugin', $result['root'] );
		$this->assertSame( 'normal-plugin/normal-plugin.php', $result['main_file'] );
	}

	public function test_rejects_a_loose_top_level_plugin_file(): void {
		$path = $this->temp_dir . '/loose-plugin.zip';
		$zip  = new ZipArchive();
		$this->assertTrue( $zip->open( $path, ZipArchive::CREATE | ZipArchive::OVERWRITE ) );
		$zip->addFromString( 'loose-plugin.php', "<?php\n/* Plugin Name: Loose Plugin */\n" );
		$zip->close();

		$this->register_attachment( 502, $path );
		$result = EMCP_Tools_Package_Guard::prepare_uploaded_zip( 502, hash_file( 'sha256', $path ), 'plugin' );

		$this->assertInstanceOf( WP_Error::class, $result );
		$this->assertSame( 'invalid_zip_layout', $result->get_error_code() );
	}

	private function register_attachment( int $id, string $path ): void {
		$GLOBALS['emcp_test']['posts'][ $id ]          = new WP_Post( array( 'ID' => $id, 'post_type' => 'attachment' ) );
		$GLOBALS['emcp_test']['attached_files'][ $id ] = $path;
	}

	private function remove_tree( string $path ): void {
		if ( '' === $path || ! is_dir( $path ) ) {
			return;
		}
		foreach ( array_diff( scandir( $path ), array( '.', '..' ) ) as $entry ) {
			$child = $path . DIRECTORY_SEPARATOR . $entry;
			is_dir( $child ) ? $this->remove_tree( $child ) : unlink( $child );
		}
		rmdir( $path );
	}
}
