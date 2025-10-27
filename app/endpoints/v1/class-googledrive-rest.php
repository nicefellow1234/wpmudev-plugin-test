<?php
/**
 * Google Drive API endpoints using Google Client Library.
 *
 * @package WPMUDEV\PluginTest
 */

namespace WPMUDEV\PluginTest\Endpoints\V1;

defined( 'WPINC' ) || die;

use Exception;
use Google_Client;
use Google_Service_Drive;
use Google_Service_Drive_DriveFile;
use WPMUDEV\PluginTest\App\GoogleDrive\Credentials_Manager;
use WPMUDEV\PluginTest\Base;
use WP_Error;
use WP_REST_Request;
use WP_REST_Response;
use WP_REST_Server;

/**
 * REST controller for Google Drive.
 */
class Drive_API extends Base {

	const ACCESS_TOKEN_OPTION = 'wpmudev_drive_access_token';
	const REFRESH_TOKEN_OPTION = 'wpmudev_drive_refresh_token';
	const TOKEN_EXPIRY_OPTION  = 'wpmudev_drive_token_expires';
	const STATE_TTL            = 10 * MINUTE_IN_SECONDS;
	const STATE_PREFIX         = 'wpmudev_drive_state_';

	/**
	 * Google Client instance.
	 *
	 * @var Google_Client|null
	 */
	private $client;

	/**
	 * Google Drive service.
	 *
	 * @var Google_Service_Drive|null
	 */
	private $drive_service;

	/**
	 * OAuth redirect URI.
	 *
	 * @var string
	 */
	private $redirect_uri = '';

	/**
	 * Initialize the class.
	 */
	public function init() {
		$this->redirect_uri = home_url( '/wp-json/wpmudev/v1/drive/callback' );
		$this->setup_google_client();

		add_action( 'rest_api_init', array( $this, 'register_routes' ) );
	}

	/**
	 * Rename a Drive file or folder.
	 *
	 * @param WP_REST_Request $request Request instance.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function rename_file( WP_REST_Request $request ) {
		if ( ! $this->ensure_valid_token() ) {
			return new WP_Error(
				'no_access_token',
				__( 'Authenticate with Google Drive to continue.', 'wpmudev-plugin-test' ),
				array( 'status' => 401 )
			);
		}

		$file_id = sanitize_text_field( (string) $request->get_param( 'file_id' ) );
		$new_name = sanitize_text_field( (string) $request->get_param( 'name' ) );

		if ( empty( $file_id ) || '' === $new_name ) {
			return new WP_Error(
				'invalid_parameters',
				__( 'A file and a new name are required to rename items.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		try {
			$drive_file = new Google_Service_Drive_DriveFile();
			$drive_file->setName( $new_name );

			$result = $this->drive_service->files->update(
				$file_id,
				$drive_file,
				array(
					'fields' => 'id,name,mimeType,size,modifiedTime,webViewLink,iconLink,thumbnailLink,hasThumbnail,parents,capabilities(canRename,canDelete,canTrash)',
				)
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'rename_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'file'    => $this->format_drive_file( $result ),
			)
		);
	}

	/**
	 * Delete (trash) a Drive file or folder.
	 *
	 * @param WP_REST_Request $request Request instance.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function delete_file( WP_REST_Request $request ) {
		if ( ! $this->ensure_valid_token() ) {
			return new WP_Error(
				'no_access_token',
				__( 'Authenticate with Google Drive to continue.', 'wpmudev-plugin-test' ),
				array( 'status' => 401 )
			);
		}

		$file_id = sanitize_text_field( (string) $request->get_param( 'file_id' ) );
		if ( empty( $file_id ) ) {
			return new WP_Error(
				'invalid_parameters',
				__( 'A file ID is required.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		try {
			$this->drive_service->files->delete( $file_id );
		} catch ( Exception $e ) {
			return new WP_Error( 'delete_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'file_id' => $file_id,
			)
		);
	}

	/**
	 * Re/initialize Google client.
	 * List of required scopes.
	 *
	 * @return array
	 */
	public static function get_scopes_list(): array {
		$scopes = array(
			Google_Service_Drive::DRIVE_FILE,
			Google_Service_Drive::DRIVE_READONLY,
		);

		/**
		 * Allow other code to adjust the requested scopes.
		 *
		 * @since 1.0.0
		 *
		 * @param array $scopes Default scopes.
		 */
		return apply_filters( 'wpmudev_plugintest_drive_scopes', $scopes );
	}

	/**
	 * Register REST API routes.
	 */
	public function register_routes() {
		register_rest_route(
			'wpmudev/v1/drive',
			'/save-credentials',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'save_credentials' ),
					'permission_callback' => array( $this, 'check_manage_permissions' ),
					'args'                => array(
						'client_id'     => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'client_secret' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			'wpmudev/v1/drive',
			'/auth',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'start_auth' ),
					'permission_callback' => array( $this, 'check_manage_permissions' ),
				),
			)
		);

		register_rest_route(
			'wpmudev/v1/drive',
			'/revoke',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'revoke_auth' ),
					'permission_callback' => array( $this, 'check_manage_permissions' ),
				),
			)
		);

		register_rest_route(
			'wpmudev/v1/drive',
			'/callback',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'handle_callback' ),
					'permission_callback' => '__return_true',
				),
			)
		);

		register_rest_route(
			'wpmudev/v1/drive',
			'/files',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'list_files' ),
					'permission_callback' => array( $this, 'check_manage_permissions' ),
					'args'                => array(
						'page_size'  => array(
							'type'              => 'integer',
							'default'           => 20,
							'sanitize_callback' => 'absint',
						),
						'page_token' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
				'folder_id'  => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'search'     => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
				'order'      => array(
					'type'              => 'string',
					'sanitize_callback' => 'sanitize_text_field',
				),
			),
		),
	)
);

		register_rest_route(
			'wpmudev/v1/drive',
			'/upload',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'upload_file' ),
					'permission_callback' => array( $this, 'check_manage_permissions' ),
				),
			)
		);

		register_rest_route(
			'wpmudev/v1/drive',
			'/download',
			array(
				array(
					'methods'             => WP_REST_Server::READABLE,
					'callback'            => array( $this, 'download_file' ),
					'permission_callback' => array( $this, 'check_manage_permissions' ),
					'args'                => array(
						'file_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			'wpmudev/v1/drive',
			'/create-folder',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'create_folder' ),
					'permission_callback' => array( $this, 'check_manage_permissions' ),
					'args'                => array(
						'name'      => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'parent_id' => array(
							'type'              => 'string',
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			'wpmudev/v1/drive',
			'/rename',
			array(
				array(
					'methods'             => WP_REST_Server::CREATABLE,
					'callback'            => array( $this, 'rename_file' ),
					'permission_callback' => array( $this, 'check_manage_permissions' ),
					'args'                => array(
						'file_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
						'name'    => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);

		register_rest_route(
			'wpmudev/v1/drive',
			'/delete',
			array(
				array(
					'methods'             => WP_REST_Server::DELETABLE,
					'callback'            => array( $this, 'delete_file' ),
					'permission_callback' => array( $this, 'check_manage_permissions' ),
					'args'                => array(
						'file_id' => array(
							'type'              => 'string',
							'required'          => true,
							'sanitize_callback' => 'sanitize_text_field',
						),
					),
				),
			)
		);
	}

	/**
	 * Save Google OAuth credentials.
	 *
	 * @param WP_REST_Request $request Request instance.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function save_credentials( WP_REST_Request $request ) {
		$client_id     = trim( (string) $request->get_param( 'client_id' ) );
		$client_secret = trim( (string) $request->get_param( 'client_secret' ) );

		if ( empty( $client_id ) || empty( $client_secret ) ) {
			return new WP_Error(
				'missing_credentials',
				__( 'Client ID and Client Secret are required.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		Credentials_Manager::save( $client_id, $client_secret );
		$this->setup_google_client( true );

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => __( 'Credentials saved successfully.', 'wpmudev-plugin-test' ),
			)
		);
	}

	/**
	 * Start Google OAuth flow.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function start_auth() {
		if ( ! $this->client ) {
			return new WP_Error(
				'missing_credentials',
				__( 'Google OAuth credentials are not configured yet.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		$state = wp_generate_uuid4();
		set_transient(
			$this->get_state_key( $state ),
			array(
				'user_id' => get_current_user_id(),
				'time'    => time(),
			),
			self::STATE_TTL
		);

		$this->client->setState( $state );
		$auth_url = $this->client->createAuthUrl();

		return new WP_REST_Response(
			array(
				'success' => true,
				'authUrl' => $auth_url,
			)
		);
	}

	/**
	 * Revoke stored Google OAuth tokens and clear options.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function revoke_auth() {
		$this->setup_google_client();

		$access_token  = get_option( self::ACCESS_TOKEN_OPTION, array() );
		$refresh_token = get_option( self::REFRESH_TOKEN_OPTION );
		$had_tokens    = ! empty( $access_token ) || ! empty( $refresh_token );

		if ( $this->client ) {
			try {
				$token_value = '';
				if ( is_array( $access_token ) ) {
					$token_value = isset( $access_token['access_token'] ) ? (string) $access_token['access_token'] : '';
				} elseif ( is_string( $access_token ) ) {
					$token_value = $access_token;
				}

				if ( $token_value ) {
					$this->client->revokeToken( $token_value );
				}

				if ( ! empty( $refresh_token ) ) {
					$this->client->revokeToken( (string) $refresh_token );
				}
			} catch ( Exception $e ) {
				return new WP_Error( 'revoke_failed', $e->getMessage(), array( 'status' => 500 ) );
			}
		}

		delete_option( self::ACCESS_TOKEN_OPTION );
		delete_option( self::REFRESH_TOKEN_OPTION );
		delete_option( self::TOKEN_EXPIRY_OPTION );

		if ( $this->client ) {
			$this->client->setAccessToken( null );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'message' => $had_tokens
					? __( 'Google Drive access revoked successfully.', 'wpmudev-plugin-test' )
					: __( 'No active Google Drive session was found.', 'wpmudev-plugin-test' ),
			)
		);
	}

	/**
	 * Handle OAuth callback.
	 *
	 * @param WP_REST_Request $request Request instance.
	 *
	 * @return WP_REST_Response|void
	 */
	public function handle_callback( WP_REST_Request $request ) {
		$code  = $request->get_param( 'code' );
		$state = $request->get_param( 'state' );

		if ( empty( $code ) || empty( $state ) ) {
			$this->auth_redirect( 'error', __( 'Authorization code missing.', 'wpmudev-plugin-test' ) );
			return;
		}

		$state_key = $this->get_state_key( $state );
		$state_data = get_transient( $state_key );
		delete_transient( $state_key );

		if ( empty( $state_data ) ) {
			$this->auth_redirect( 'error', __( 'Invalid or expired authorization state.', 'wpmudev-plugin-test' ) );
			return;
		}

		try {
			$access_token = $this->client->fetchAccessTokenWithAuthCode( $code );
		} catch ( Exception $e ) {
			$this->auth_redirect( 'error', $e->getMessage() );
			return;
		}

		if ( isset( $access_token['error'] ) ) {
			$this->auth_redirect( 'error', $access_token['error_description'] ?? __( 'Authentication failed.', 'wpmudev-plugin-test' ) );
			return;
		}

		$this->store_tokens( $access_token );
		$this->auth_redirect( 'success' );
	}

	/**
	 * Ensure we have a valid access token.
	 *
	 * @return bool
	 */
	private function ensure_valid_token(): bool {
		if ( ! $this->client ) {
			return false;
		}

		$current_token = $this->client->getAccessToken();
		if ( empty( $current_token ) ) {
			$saved_token = get_option( self::ACCESS_TOKEN_OPTION, array() );
			if ( empty( $saved_token ) ) {
				return false;
			}
			$this->client->setAccessToken( $saved_token );
		}

		if ( ! $this->client->isAccessTokenExpired() ) {
			return true;
		}

		$refresh_token = get_option( self::REFRESH_TOKEN_OPTION );
		if ( empty( $refresh_token ) ) {
			return false;
		}

		try {
			$new_token = $this->client->fetchAccessTokenWithRefreshToken( $refresh_token );
		} catch ( Exception $e ) {
			return false;
		}

		if ( isset( $new_token['error'] ) ) {
			return false;
		}

		$new_token['refresh_token'] = $refresh_token;
		$this->store_tokens( $new_token );

		return true;
	}

	/**
	 * List files in Google Drive.
	 *
	 * @param WP_REST_Request $request Request instance.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function list_files( WP_REST_Request $request ) {
		if ( ! $this->ensure_valid_token() ) {
			return new WP_Error(
				'no_access_token',
				__( 'Authenticate with Google Drive to continue.', 'wpmudev-plugin-test' ),
				array( 'status' => 401 )
			);
		}

		$page_size  = max( 1, min( 100, (int) $request->get_param( 'page_size' ) ?: 20 ) );
		$page_token = sanitize_text_field( (string) $request->get_param( 'page_token' ) );
		$folder_id  = $this->sanitize_parent_id( $request->get_param( 'folder_id' ) );
		$search     = sanitize_text_field( (string) $request->get_param( 'search' ) );

		$query_parts = array( 'trashed = false' );
		if ( $folder_id ) {
			$query_parts[] = sprintf( "'%s' in parents", $folder_id );
		}
		if ( $search ) {
			// Google query strings escape single quotes by doubling them.
			$search_term  = str_replace( "'", "\\'", $search );
			$query_parts[] = sprintf( "name contains '%s'", $search_term );
		}

	$order_param = strtolower( sanitize_text_field( (string) $request->get_param( 'order' ) ) );
	$order_by    = 'modifiedTime desc';

	if ( in_array( $order_param, array( 'asc', 'oldest', 'modified_asc' ), true ) ) {
		$order_by = 'modifiedTime asc';
	}

	$options = array(
		'q'       => implode( ' and ', $query_parts ),
		'pageSize'=> $page_size,
		'fields'  => 'files(id,name,mimeType,size,modifiedTime,webViewLink,iconLink,thumbnailLink,hasThumbnail,parents,capabilities(canRename,canDelete,canTrash)),nextPageToken',
		'orderBy' => $order_by,
	);

		if ( $page_token ) {
			$options['pageToken'] = $page_token;
		}

		try {
			$results = $this->drive_service->files->listFiles( $options );
		} catch ( Exception $e ) {
			return new WP_Error(
				'api_error',
				$e->getMessage(),
				array( 'status' => 500 )
			);
		}

		$files = array();
		foreach ( (array) $results->getFiles() as $file ) {
			$files[] = $this->format_drive_file( $file );
		}

		return new WP_REST_Response(
			array(
				'success'       => true,
				'files'         => $files,
				'nextPageToken' => $results->getNextPageToken(),
			)
		);
	}

	/**
	 * Upload file to Google Drive.
	 *
	 * @param WP_REST_Request $request Request instance.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function upload_file( WP_REST_Request $request ) {
		if ( ! $this->ensure_valid_token() ) {
			return new WP_Error(
				'no_access_token',
				__( 'Authenticate with Google Drive to continue.', 'wpmudev-plugin-test' ),
				array( 'status' => 401 )
			);
		}

		$files = $request->get_file_params();
		if ( empty( $files['file'] ) ) {
			return new WP_Error( 'no_file', __( 'Please attach a file to upload.', 'wpmudev-plugin-test' ), array( 'status' => 400 ) );
		}

		$file = $files['file'];
		if ( is_wp_error( $error = $this->validate_upload_file( $file ) ) ) {
			return $error;
		}

		$parent_id = $this->sanitize_parent_id( $request->get_param( 'parent_id' ) );

		try {
			$drive_file = new Google_Service_Drive_DriveFile();
			$drive_file->setName( $file['name'] );
			if ( $parent_id ) {
				$drive_file->setParents( array( $parent_id ) );
			}

			$result = $this->drive_service->files->create(
				$drive_file,
				array(
					'data'       => file_get_contents( $file['tmp_name'] ),
					'mimeType'   => $file['type'] ?: 'application/octet-stream',
					'uploadType' => 'multipart',
					'fields'     => 'id,name,mimeType,size,modifiedTime,webViewLink,iconLink,thumbnailLink,hasThumbnail,parents,capabilities(canRename,canDelete,canTrash)',
				)
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'upload_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'file'    => $this->format_drive_file( $result ),
			)
		);
	}

	/**
	 * Download file from Google Drive.
	 *
	 * @param WP_REST_Request $request Request instance.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function download_file( WP_REST_Request $request ) {
		if ( ! $this->ensure_valid_token() ) {
			return new WP_Error(
				'no_access_token',
				__( 'Authenticate with Google Drive to continue.', 'wpmudev-plugin-test' ),
				array( 'status' => 401 )
			);
		}

		$file_id = $request->get_param( 'file_id' );
		if ( empty( $file_id ) ) {
			return new WP_Error( 'missing_file_id', __( 'File ID is required.', 'wpmudev-plugin-test' ), array( 'status' => 400 ) );
		}

		try {
			$file_meta = $this->drive_service->files->get(
				$file_id,
				array(
					'fields' => 'id,name,mimeType,size',
				)
			);

			$response = $this->drive_service->files->get(
				$file_id,
				array(
					'alt' => 'media',
				)
			);

			$content = $response->getBody()->getContents();
		} catch ( Exception $e ) {
			return new WP_Error( 'download_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'success'  => true,
				'content'  => base64_encode( $content ), // phpcs:ignore WordPress.PHP.DiscouragedPHPFunctions
				'filename' => $file_meta->getName(),
				'mimeType' => $file_meta->getMimeType(),
			)
		);
	}

	/**
	 * Create folder in Google Drive.
	 *
	 * @param WP_REST_Request $request Request instance.
	 *
	 * @return WP_REST_Response|WP_Error
	 */
	public function create_folder( WP_REST_Request $request ) {
		if ( ! $this->ensure_valid_token() ) {
			return new WP_Error(
				'no_access_token',
				__( 'Authenticate with Google Drive to continue.', 'wpmudev-plugin-test' ),
				array( 'status' => 401 )
			);
		}

		$name = $request->get_param( 'name' );
		if ( empty( $name ) ) {
			return new WP_Error( 'missing_name', __( 'Folder name is required.', 'wpmudev-plugin-test' ), array( 'status' => 400 ) );
		}

		$parent_id = $this->sanitize_parent_id( $request->get_param( 'parent_id' ) );

		try {
			$folder = new Google_Service_Drive_DriveFile();
			$folder->setName( sanitize_text_field( $name ) );
			$folder->setMimeType( 'application/vnd.google-apps.folder' );

			if ( $parent_id ) {
				$folder->setParents( array( $parent_id ) );
			}

			$result = $this->drive_service->files->create(
				$folder,
				array(
					'fields' => 'id,name,mimeType,size,modifiedTime,webViewLink,iconLink,thumbnailLink,hasThumbnail,parents,capabilities(canRename,canDelete,canTrash)',
				)
			);
		} catch ( Exception $e ) {
			return new WP_Error( 'create_failed', $e->getMessage(), array( 'status' => 500 ) );
		}

		return new WP_REST_Response(
			array(
				'success' => true,
				'folder'  => $this->format_drive_file( $result ),
			)
		);
	}

	/**
	 * Normalise Google Drive file payload to consistent array.
	 *
	 * @param Google_Service_Drive_DriveFile $file Drive file instance.
	 *
	 * @return array
	 */
	private function format_drive_file( Google_Service_Drive_DriveFile $file ): array {
		$capabilities = method_exists( $file, 'getCapabilities' ) ? $file->getCapabilities() : null;

		return array(
			'id'            => $file->getId(),
			'name'          => $file->getName(),
			'mimeType'      => $file->getMimeType(),
			'size'          => $file->getSize(),
			'modifiedTime'  => $file->getModifiedTime(),
			'webViewLink'   => $file->getWebViewLink(),
			'iconLink'      => $file->getIconLink(),
			'thumbnailLink' => method_exists( $file, 'getThumbnailLink' ) ? $file->getThumbnailLink() : '',
			'hasThumbnail'  => method_exists( $file, 'getHasThumbnail' ) ? (bool) $file->getHasThumbnail() : false,
			'parents'       => (array) $file->getParents(),
			'capabilities'  => array(
				'canRename' => $capabilities && method_exists( $capabilities, 'getCanRename' ) ? (bool) $capabilities->getCanRename() : false,
				'canDelete' => $capabilities && method_exists( $capabilities, 'getCanDelete' ) ? (bool) $capabilities->getCanDelete() : false,
				'canTrash'  => $capabilities && method_exists( $capabilities, 'getCanTrash' ) ? (bool) $capabilities->getCanTrash() : false,
			),
		);
	}

	/**
	 * Re/initialize Google client.
	 *
	 * @param bool $force Force reinitialisation.
	 */
	private function setup_google_client( bool $force = false ) {
		if ( $this->client && ! $force ) {
			return;
		}

		$auth_creds = Credentials_Manager::get();

		if ( empty( $auth_creds['client_id'] ) || empty( $auth_creds['client_secret'] ) ) {
			$this->client        = null;
			$this->drive_service = null;
			return;
		}

		$this->client = new Google_Client();
		$this->client->setClientId( $auth_creds['client_id'] );
		$this->client->setClientSecret( $auth_creds['client_secret'] );
		$this->client->setRedirectUri( $this->redirect_uri );
		$this->client->setScopes( self::get_scopes_list() );
		$this->client->setAccessType( 'offline' );
		$this->client->setPrompt( 'consent' );

		$access_token = get_option( self::ACCESS_TOKEN_OPTION, array() );
		if ( ! empty( $access_token ) ) {
			$this->client->setAccessToken( $access_token );
		}

		$this->drive_service = new Google_Service_Drive( $this->client );
	}

	/**
	 * Capability check wrapper.
	 *
	 * @return bool
	 */
	public function check_manage_permissions(): bool {
		return current_user_can( 'manage_options' );
	}

	/**
	 * Build transient key for state validation.
	 *
	 * @param string $state OAuth state.
	 *
	 * @return string
	 */
	private function get_state_key( string $state ): string {
		return self::STATE_PREFIX . md5( $state );
	}

	/**
	 * Redirect back to admin with status.
	 *
	 * @param string $status Status string.
	 * @param string $message Optional message.
	 */
	private function auth_redirect( string $status, string $message = '' ) {
		$args = array( 'auth' => $status );
		if ( ! empty( $message ) ) {
			$args['message'] = $message;
		}

		$url = add_query_arg( $args, admin_url( 'admin.php?page=wpmudev_plugintest_drive' ) );
		wp_safe_redirect( $url );
		exit;
	}

	/**
	 * Persist tokens in WordPress options.
	 *
	 * @param array $token Token payload.
	 */
	private function store_tokens( array $token ) {
		update_option( self::ACCESS_TOKEN_OPTION, $token, false );

		if ( ! empty( $token['refresh_token'] ) ) {
			update_option( self::REFRESH_TOKEN_OPTION, $token['refresh_token'], false );
		}

		if ( ! empty( $token['expires_in'] ) ) {
			update_option( self::TOKEN_EXPIRY_OPTION, time() + (int) $token['expires_in'], false );
		}

		if ( $this->client ) {
			$this->client->setAccessToken( $token );
		}
	}

	/**
	 * Whitelist safe characters for parent identifiers.
	 *
	 * @param string|null $value Raw parent ID.
	 *
	 * @return string
	 */
	private function sanitize_parent_id( $value ): string {
		if ( empty( $value ) ) {
			return '';
		}

		return preg_replace( '/[^a-zA-Z0-9_\-]/', '', (string) $value );
	}

	/**
	 * Validate uploaded file array.
	 *
	 * @param array $file Uploaded file array.
	 *
	 * @return WP_Error|null
	 */
	private function validate_upload_file( array $file ) {
		if ( empty( $file['tmp_name'] ) || ! is_uploaded_file( $file['tmp_name'] ) ) {
			return new WP_Error( 'invalid_upload', __( 'Upload failed before reaching WordPress.', 'wpmudev-plugin-test' ), array( 'status' => 400 ) );
		}

		if ( (int) $file['error'] !== UPLOAD_ERR_OK ) {
			return new WP_Error( 'upload_error', __( 'File upload encountered an error.', 'wpmudev-plugin-test' ), array( 'status' => 400 ) );
		}

		$max_size = wp_max_upload_size();
		if ( $max_size && (int) $file['size'] > $max_size ) {
			return new WP_Error(
				'file_too_large',
				__( 'The selected file exceeds the allowed upload size.', 'wpmudev-plugin-test' ),
				array( 'status' => 400 )
			);
		}

		return null;
	}
}
