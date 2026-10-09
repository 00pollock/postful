<?php
namespace Postful;

defined( 'ABSPATH' ) || exit;

/**
 * GET /wp-json/postful/v1/entry?type=devotional&sort=305
 * GET /wp-json/postful/v1/entry?type=devotional&id=123
 *
 * Returns the entry rendered in every language, for in-place swapping.
 * Only published entries are ever returned.
 */
final class Rest {

	public static function hooks(): void {
		add_action( 'rest_api_init', [ self::class, 'routes' ] );
	}

	public static function routes(): void {
		register_rest_route( 'postful/v1', '/entry', [
			'methods'             => \WP_REST_Server::READABLE,
			'callback'            => [ self::class, 'entry' ],
			'permission_callback' => '__return_true',
			'args'                => [
				'type' => [
					'required'          => true,
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_key',
				],
				'sort' => [
					'type'    => 'integer',
					'minimum' => 0,
				],
				'id'   => [
					'type'    => 'integer',
					'minimum' => 0,
				],
				// The page splits sidebar fields into their own container.
				'split' => [
					'type'    => 'boolean',
					'default' => false,
				],
			],
		] );
	}

	public static function entry( \WP_REST_Request $request ) {
		try {
			$type = Types::get( $request['type'] );
			if ( ! $type ) {
				return new \WP_Error( 'postful_type', 'Unknown type.', [ 'status' => 404 ] );
			}
			$preset = Presets::get( $type['preset'] );

			$id = (int) $request['id'];
			if ( $id ) {
				$post = get_post( $id );
				if ( ! $post || $post->post_type !== $type['key'] || 'publish' !== $post->post_status || '' !== $post->post_password ) {
					return new \WP_Error( 'postful_entry', 'Not found.', [ 'status' => 404 ] );
				}
				$key  = $preset->key_of( $id );
				$sort = $key ? $preset->sort_of( $key ) : 0;
			} else {
				$sort = (int) $request['sort'];
				$key  = $preset->key_from_sort( $sort );
				if ( ! $key ) {
					return new \WP_Error( 'postful_key', 'Invalid key.', [ 'status' => 400 ] );
				}
				$id = Index::find( $type['key'], $sort );
			}

			$split    = (bool) $request['split'];
			$response = rest_ensure_response( [
				'sort'  => $sort,
				'id'    => $id,
				'html'  => $id ? Renderer::entry( $type, $id, $split ? 'main' : 'all' ) : Renderer::empty_state( $type, $key ),
				'aside' => $id && $split ? Renderer::entry( $type, $id, 'aside' ) : '',
				// For a standalone [sp_current] sharing the page with a split layout.
				'all'   => $id && $split ? Renderer::entry( $type, $id, 'all' ) : '',
			] );
			$response->header( 'Cache-Control', 'public, max-age=60' );
			return $response;
		} catch ( \Throwable $e ) {
			return new \WP_Error( 'postful_error', 'Could not load entry.', [ 'status' => 500 ] );
		}
	}
}
