<?php
/**
 * Plugin Name: FGT Temporary Imagery Importer
 * Description: Temporary authenticated importer for the approved Future Green Technology imagery pack. Remove after the implementation pass.
 * Version: 1.0.0
 * Author: Future Green Technology
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

add_action( 'rest_api_init', function () {
	register_rest_route( 'fgt-imagery/v1', '/ping', array(
		'methods'             => WP_REST_Server::READABLE,
		'permission_callback' => function () {
			return current_user_can( 'upload_files' );
		},
		'callback'            => function () {
			return rest_ensure_response( array( 'ok' => true ) );
		},
	) );

	register_rest_route( 'fgt-imagery/v1', '/import-bundle', array(
		'methods'             => WP_REST_Server::CREATABLE,
		'permission_callback' => function () {
			return current_user_can( 'upload_files' );
		},
		'callback'            => 'fgt_temporary_import_bundle',
	) );
} );

function fgt_temporary_find_attachment( $asset_key ) {
	$matches = get_posts( array(
		'post_type'      => 'attachment',
		'post_status'    => 'inherit',
		'posts_per_page' => 1,
		'fields'         => 'ids',
		'meta_key'       => '_fgt_asset_key',
		'meta_value'     => $asset_key,
	) );
	return empty( $matches ) ? 0 : (int) $matches[0];
}

function fgt_temporary_import_bundle( WP_REST_Request $request ) {
	if ( ! class_exists( 'ZipArchive' ) ) {
		return new WP_Error( 'zip_unavailable', 'ZipArchive is not available on this server.', array( 'status' => 500 ) );
	}

	$data     = (string) $request->get_param( 'data' );
	$manifest = $request->get_param( 'manifest' );
	if ( is_string( $manifest ) ) {
		$manifest = json_decode( $manifest, true );
	}
	if ( empty( $data ) || ! is_array( $manifest ) ) {
		return new WP_Error( 'missing_payload', 'Bundle data and a manifest are required.', array( 'status' => 400 ) );
	}

	$binary = base64_decode( $data, true );
	if ( false === $binary ) {
		return new WP_Error( 'invalid_base64', 'The bundle is not valid base64.', array( 'status' => 400 ) );
	}
	if ( strlen( $binary ) > 12 * MB_IN_BYTES ) {
		return new WP_Error( 'bundle_too_large', 'The bundle exceeds the 12 MB temporary limit.', array( 'status' => 413 ) );
	}

	$upload_dir = wp_upload_dir();
	if ( ! empty( $upload_dir['error'] ) ) {
		return new WP_Error( 'upload_directory_error', $upload_dir['error'], array( 'status' => 500 ) );
	}

	$temp_root = trailingslashit( $upload_dir['basedir'] ) . 'fgt-imagery-import-' . wp_generate_uuid4();
	if ( ! wp_mkdir_p( $temp_root ) ) {
		return new WP_Error( 'temp_directory_error', 'Could not create the temporary import directory.', array( 'status' => 500 ) );
	}
	$zip_path = trailingslashit( $temp_root ) . 'bundle.zip';
	if ( false === file_put_contents( $zip_path, $binary ) ) {
		fgt_temporary_remove_tree( $temp_root );
		return new WP_Error( 'temp_write_error', 'Could not write the temporary bundle.', array( 'status' => 500 ) );
	}

	$extract_dir = trailingslashit( $temp_root ) . 'files';
	wp_mkdir_p( $extract_dir );
	$zip = new ZipArchive();
	if ( true !== $zip->open( $zip_path ) ) {
		fgt_temporary_remove_tree( $temp_root );
		return new WP_Error( 'zip_open_error', 'Could not open the temporary bundle.', array( 'status' => 400 ) );
	}
	$zip->extractTo( $extract_dir );
	$zip->close();

	require_once ABSPATH . 'wp-admin/includes/image.php';
	$results = array();

	foreach ( $manifest as $item ) {
		$asset_key  = sanitize_key( isset( $item['asset_key'] ) ? $item['asset_key'] : '' );
		$source     = sanitize_file_name( isset( $item['source'] ) ? $item['source'] : '' );
		$filename   = sanitize_file_name( isset( $item['filename'] ) ? $item['filename'] : $source );
		$title      = sanitize_text_field( isset( $item['title'] ) ? $item['title'] : pathinfo( $filename, PATHINFO_FILENAME ) );
		$alt_text   = sanitize_text_field( isset( $item['alt_text'] ) ? $item['alt_text'] : '' );
		$caption    = sanitize_textarea_field( isset( $item['caption'] ) ? $item['caption'] : '' );
		$description = wp_kses_post( isset( $item['description'] ) ? $item['description'] : '' );

		if ( empty( $asset_key ) || empty( $source ) || empty( $filename ) ) {
			$results[] = array( 'asset_key' => $asset_key, 'error' => 'Manifest item is missing an asset key, source or filename.' );
			continue;
		}

		$existing_id = fgt_temporary_find_attachment( $asset_key );
		if ( $existing_id ) {
			$meta = wp_get_attachment_metadata( $existing_id );
			$results[] = array(
				'asset_key' => $asset_key,
				'id'        => $existing_id,
				'url'       => wp_get_attachment_url( $existing_id ),
				'width'     => isset( $meta['width'] ) ? (int) $meta['width'] : null,
				'height'    => isset( $meta['height'] ) ? (int) $meta['height'] : null,
				'existing'  => true,
			);
			continue;
		}

		$source_path = trailingslashit( $extract_dir ) . $source;
		if ( ! is_file( $source_path ) ) {
			$results[] = array( 'asset_key' => $asset_key, 'error' => 'Source file was not found in the bundle.' );
			continue;
		}

		$file_data = file_get_contents( $source_path );
		$uploaded  = wp_upload_bits( $filename, null, $file_data );
		if ( ! empty( $uploaded['error'] ) ) {
			$results[] = array( 'asset_key' => $asset_key, 'error' => $uploaded['error'] );
			continue;
		}

		$filetype = wp_check_filetype( $uploaded['file'], null );
		$attachment_id = wp_insert_attachment( array(
			'post_mime_type' => $filetype['type'],
			'post_title'     => $title,
			'post_content'   => $description,
			'post_excerpt'   => $caption,
			'post_status'    => 'inherit',
		), $uploaded['file'] );

		if ( is_wp_error( $attachment_id ) ) {
			@unlink( $uploaded['file'] );
			$results[] = array( 'asset_key' => $asset_key, 'error' => $attachment_id->get_error_message() );
			continue;
		}

		$metadata = wp_generate_attachment_metadata( $attachment_id, $uploaded['file'] );
		wp_update_attachment_metadata( $attachment_id, $metadata );
		update_post_meta( $attachment_id, '_fgt_asset_key', $asset_key );
		if ( '' !== $alt_text ) {
			update_post_meta( $attachment_id, '_wp_attachment_image_alt', $alt_text );
		}

		$results[] = array(
			'asset_key' => $asset_key,
			'id'        => (int) $attachment_id,
			'url'       => wp_get_attachment_url( $attachment_id ),
			'width'     => isset( $metadata['width'] ) ? (int) $metadata['width'] : null,
			'height'    => isset( $metadata['height'] ) ? (int) $metadata['height'] : null,
			'bytes'     => filesize( $uploaded['file'] ),
			'existing'  => false,
		);
	}

	fgt_temporary_remove_tree( $temp_root );
	return rest_ensure_response( array( 'imported' => $results ) );
}

function fgt_temporary_remove_tree( $path ) {
	if ( ! is_dir( $path ) ) {
		return;
	}
	$items = new RecursiveIteratorIterator(
		new RecursiveDirectoryIterator( $path, FilesystemIterator::SKIP_DOTS ),
		RecursiveIteratorIterator::CHILD_FIRST
	);
	foreach ( $items as $item ) {
		$item->isDir() ? @rmdir( $item->getPathname() ) : @unlink( $item->getPathname() );
	}
	@rmdir( $path );
}
