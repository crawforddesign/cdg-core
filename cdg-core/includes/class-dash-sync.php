<?php
/**
 * Dash Sync Class
 *
 * REST API endpoints for CDG Dash to push and manage code snippets on this site.
 * Auth uses a one-time bootstrap token (wp-config constant) to register, then an
 * API key stored in wp_options for all subsequent calls.
 *
 * Endpoints:
 *   POST /wp-json/cdg-core/v1/register          — one-time key exchange
 *   GET  /wp-json/cdg-core/v1/snippets          — list all Dash-managed snippets
 *   GET  /wp-json/cdg-core/v1/snippets/{id}     — single snippet + content hash
 *   PUT  /wp-json/cdg-core/v1/snippets/{id}     — upsert snippet (push from Dash)
 *   DELETE /wp-json/cdg-core/v1/snippets/{id}   — remove snippet
 *
 * @package CDG_Core
 * @since 1.8.0
 */

declare(strict_types=1);

class CDG_Core_Dash_Sync
{
  private const OPTION_API_KEY = 'cdg_core_dash_api_key';
  private const OPTION_KEY_HASH = 'cdg_core_dash_api_key_hash';
  private const BOOTSTRAP_CONSTANT = 'CDG_CORE_DASH_BOOTSTRAP';
  private const NAMESPACE = 'cdg-core/v1';

  private CDG_Core $plugin;

  public function __construct( CDG_Core $plugin )
  {
    $this->plugin = $plugin;
    add_action( 'rest_api_init', [ $this, 'register_routes' ] );
  }

  public function register_routes(): void
  {
    register_rest_route( self::NAMESPACE, '/register', [
      'methods'             => 'POST',
      'callback'            => [ $this, 'handle_register' ],
      'permission_callback' => '__return_true',
    ] );

    register_rest_route( self::NAMESPACE, '/snippets', [
      'methods'             => 'GET',
      'callback'            => [ $this, 'handle_list' ],
      'permission_callback' => [ $this, 'check_api_key' ],
    ] );

    register_rest_route( self::NAMESPACE, '/snippets/(?P<id>[a-f0-9\-]{36})', [
      [
        'methods'             => 'GET',
        'callback'            => [ $this, 'handle_get' ],
        'permission_callback' => [ $this, 'check_api_key' ],
      ],
      [
        'methods'             => 'PUT',
        'callback'            => [ $this, 'handle_upsert' ],
        'permission_callback' => [ $this, 'check_api_key' ],
      ],
      [
        'methods'             => 'DELETE',
        'callback'            => [ $this, 'handle_delete' ],
        'permission_callback' => [ $this, 'check_api_key' ],
      ],
    ] );
  }

  // ── Auth ──────────────────────────────────────────────────────────────────

  public function check_api_key( WP_REST_Request $request ): bool|WP_Error
  {
    $stored_hash = get_option( self::OPTION_KEY_HASH, '' );
    if ( empty( $stored_hash ) ) {
      return new WP_Error( 'cdg_not_registered', 'Site not registered with Dash.', [ 'status' => 403 ] );
    }

    $provided = sanitize_text_field( $request->get_header( 'x-cdg-api-key' ) ?? '' );
    if ( empty( $provided ) || ! hash_equals( $stored_hash, hash( 'sha256', $provided ) ) ) {
      return new WP_Error( 'cdg_unauthorized', 'Invalid API key.', [ 'status' => 401 ] );
    }

    return true;
  }

  // ── Register ──────────────────────────────────────────────────────────────

  public function handle_register( WP_REST_Request $request ): WP_REST_Response|WP_Error
  {
    // wp-config constant takes precedence if set (filesystem-level trust);
    // otherwise fall back to the token pasted into Settings > CDG Core > Code Snippets.
    $expected_token = defined( self::BOOTSTRAP_CONSTANT )
      ? (string) constant( self::BOOTSTRAP_CONSTANT )
      : (string) ( $this->plugin->get_settings()['dash_bootstrap_token'] ?? '' );

    if ( $expected_token === '' ) {
      return new WP_Error( 'cdg_no_token', 'No bootstrap token configured. Set CDG_CORE_DASH_BOOTSTRAP or paste one under Settings > CDG Core > Code Snippets.', [ 'status' => 403 ] );
    }

    if ( get_option( self::OPTION_KEY_HASH, '' ) !== '' ) {
      return new WP_Error( 'cdg_already_registered', 'Already registered. Delete cdg_core_dash_api_key option to reset.', [ 'status' => 409 ] );
    }

    $provided_token = sanitize_text_field( (string) $request->get_param( 'bootstrap_token' ) );

    if ( empty( $provided_token ) || ! hash_equals( $expected_token, $provided_token ) ) {
      return new WP_Error( 'cdg_bad_token', 'Invalid bootstrap token.', [ 'status' => 403 ] );
    }

    $api_key = bin2hex( random_bytes( 32 ) );
    update_option( self::OPTION_KEY_HASH, hash( 'sha256', $api_key ), false );

    // Clear the token from settings now that it's been used — it's single-use
    // and there's no reason to keep it sitting in the options row afterward.
    $settings = $this->plugin->get_settings();
    if ( ! empty( $settings['dash_bootstrap_token'] ) ) {
      $settings['dash_bootstrap_token'] = '';
      $this->plugin->update_settings( $settings );
    }

    return new WP_REST_Response( [ 'api_key' => $api_key ], 200 );
  }

  // ── List ──────────────────────────────────────────────────────────────────

  public function handle_list( WP_REST_Request $request ): WP_REST_Response
  {
    $snippets = $this->get_dash_snippets();
    return new WP_REST_Response( array_values( $snippets ), 200 );
  }

  // ── Get ───────────────────────────────────────────────────────────────────

  public function handle_get( WP_REST_Request $request ): WP_REST_Response|WP_Error
  {
    $id = sanitize_text_field( $request->get_param( 'id' ) );
    $snippet = $this->find_snippet( $id );

    if ( $snippet === null ) {
      return new WP_Error( 'cdg_not_found', 'Snippet not found.', [ 'status' => 404 ] );
    }

    return new WP_REST_Response( $this->format_snippet( $snippet ), 200 );
  }

  // ── Upsert ────────────────────────────────────────────────────────────────

  public function handle_upsert( WP_REST_Request $request ): WP_REST_Response|WP_Error
  {
    $id = sanitize_text_field( $request->get_param( 'id' ) );

    $name     = sanitize_text_field( (string) $request->get_param( 'name' ) );
    $type     = sanitize_text_field( (string) $request->get_param( 'type' ) );
    $location = sanitize_text_field( (string) $request->get_param( 'location' ) );
    $active   = (bool) $request->get_param( 'active' );

    if ( ! in_array( $type, [ 'css', 'js', 'html', 'php' ], true ) ) {
      return new WP_Error( 'cdg_invalid_type', 'Invalid snippet type.', [ 'status' => 400 ] );
    }
    if ( ! in_array( $location, [ 'head', 'footer' ], true ) ) {
      $location = 'head';
    }

    // Code is trusted — it came from Dash (authenticated). Strip extra slashes
    // but do not sanitize_text_field which would destroy newlines and indentation.
    $code         = wp_unslash( (string) $request->get_param( 'code' ) );
    $content_hash = sanitize_text_field( (string) $request->get_param( 'content_hash' ) );

    $settings = $this->plugin->get_settings();
    $all      = (array) ( $settings['code_snippets'] ?? [] );
    $found    = false;

    foreach ( $all as &$snippet ) {
      if ( ( $snippet['id'] ?? '' ) === $id ) {
        $snippet['name']         = $name;
        $snippet['code']         = $code;
        $snippet['type']         = $type;
        $snippet['location']     = $location;
        $snippet['active']       = $active;
        $snippet['content_hash'] = $content_hash;
        $found = true;
        break;
      }
    }
    unset( $snippet );

    if ( ! $found ) {
      $all[] = [
        'id'           => $id,
        'title'        => $name,
        'description'  => '',
        'name'         => $name,
        'code'         => $code,
        'type'         => $type,
        'location'     => $location,
        'active'       => $active,
        'content_hash' => $content_hash,
      ];
    }

    $settings['code_snippets'] = $all;
    $this->plugin->update_settings( $settings );

    return new WP_REST_Response( [ 'ok' => true ], 200 );
  }

  // ── Delete ────────────────────────────────────────────────────────────────

  public function handle_delete( WP_REST_Request $request ): WP_REST_Response|WP_Error
  {
    $id = sanitize_text_field( $request->get_param( 'id' ) );

    $settings = $this->plugin->get_settings();
    $all      = (array) ( $settings['code_snippets'] ?? [] );
    $filtered = array_values( array_filter( $all, fn( $s ) => ( $s['id'] ?? '' ) !== $id ) );

    if ( count( $filtered ) === count( $all ) ) {
      return new WP_Error( 'cdg_not_found', 'Snippet not found.', [ 'status' => 404 ] );
    }

    $settings['code_snippets'] = $filtered;
    $this->plugin->update_settings( $settings );

    return new WP_REST_Response( null, 204 );
  }

  // ── Helpers ───────────────────────────────────────────────────────────────

  private function get_dash_snippets(): array
  {
    $all  = (array) ( $this->plugin->get_settings()['code_snippets'] ?? [] );
    $dash = array_filter( $all, fn( $s ) => ! empty( $s['id'] ) );
    return array_map( [ $this, 'format_snippet' ], array_values( $dash ) );
  }

  private function find_snippet( string $id ): ?array
  {
    foreach ( (array) ( $this->plugin->get_settings()['code_snippets'] ?? [] ) as $snippet ) {
      if ( ( $snippet['id'] ?? '' ) === $id ) {
        return $snippet;
      }
    }
    return null;
  }

  private function format_snippet( array $snippet ): array
  {
    $code = (string) ( $snippet['code'] ?? '' );
    return [
      'id'           => $snippet['id'] ?? '',
      'name'         => $snippet['name'] ?? $snippet['title'] ?? '',
      'code'         => $code,
      'type'         => $snippet['type'] ?? 'css',
      'location'     => $snippet['location'] ?? 'head',
      'active'       => ! empty( $snippet['active'] ),
      'content_hash' => $snippet['content_hash'] ?? hash( 'sha256', $code ),
    ];
  }
}
