<?php

/**
 * Manages admin-tools on a site.
 *
 * @package ee-cli
 */

use EE\Model\Site;
use Symfony\Component\Filesystem\Filesystem;
use function EE\Site\Utils\auto_site_name;
use function EE\Utils\download;
use function EE\Utils\extract_zip;
use function EE\Utils\random_password;

class Admin_Tools_Command extends EE_Command {

	/**
	 * @var Filesystem $fs Symfony Filesystem object.
	 */
	private $fs;

	/**
	 * @var array $site_data Object containing essential site related information.
	 */
	private $site_data;

	public function __construct() {

		$this->fs = new Filesystem();
		define( 'ADMIN_TOOL_DIR', EE_ROOT_DIR . '/admin-tools' );
	}

	/**
	 * Installs admin tools for EasyEngine.
	 */
	private function install() {

		if ( ! $this->is_installed() ) {
			EE::log( 'Installing admin-tools. This may take some time.' );
			$this->fs->mkdir( ADMIN_TOOL_DIR );
		}

		$tools_file_info = pathinfo( ADMIN_TOOLS_FILE );
		EE::debug( 'admin-tools file: ' . ADMIN_TOOLS_FILE );

		if ( 'json' !== $tools_file_info['extension'] ) {
			EE::error( 'Invalid admin-tools file found. Aborting.' );
		}

		$tools_file = file_get_contents( ADMIN_TOOLS_FILE );
		if ( empty( $tools_file ) ) {
			EE::error( 'admin-tools file is empty. Can\'t proceed further.' );
		}
		$tools      = json_decode( $tools_file, true );
		$json_error = json_last_error();
		if ( $json_error != JSON_ERROR_NONE ) {
			EE::debug( 'Json last error: ' . $json_error );
			EE::error( 'Error decoding admin-tools file.' );
		}
		if ( empty( $tools ) ) {
			EE::error( 'No data found in admin-tools file. Can\'t proceed further.' );
		}

		foreach ( $tools as $tool => $data ) {
			if ( ! $this->is_installed( $tool ) ) {
				EE::log( "Installing $tool" );
				$tool_path = ADMIN_TOOL_DIR . '/' . $tool;
				if ( method_exists( $this, "install_$tool" ) ) {
					call_user_func_array( [ $this, "install_$tool" ], [ $data, $tool_path ] );
				} else {
					EE::error( "No method found to install $tool. Aborting." );
				}
				EE::success( "Installed $tool successfully." );
			}
		}
	}

	/**
	 * Enables admin tools on site.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website to enable admin-tools on.
	 *
	 * [--force]
	 * : Force enabling of admin-tools for a site.
	 *
	 * ## EXAMPLES
	 *
	 *     # Enable admin tools on site
	 *     $ ee admin-tools enable example.com
	 *
	 *     # Force enable admin tools on site
	 *     $ ee admin-tools enable example.com --force
	 *
	 */
	public function enable( $args, $assoc_args ) {

		\EE\Auth\Utils\init_global_admin_tools_auth();

		EE\Utils\delem_log( 'admin-tools ' . __FUNCTION__ . ' start' );
		$args            = auto_site_name( $args, 'admin-tools', __FUNCTION__ );
		$force           = EE\Utils\get_flag_value( $assoc_args, 'force' );
		$this->site_data = Site::find( EE\Utils\remove_trailing_slash( $args[0] ) );
		if ( ! $this->site_data || ! $this->site_data->site_enabled ) {
			EE::error( sprintf( 'Site %s does not exist / is not enabled.', $args[0] ) );
		}

		if ( $this->site_data->admin_tools && ! $force ) {
			EE::error( sprintf( 'admin-tools already seem to be enabled for %s', $this->site_data->site_url ) );
		}

		chdir( $this->site_data->site_fs_path );

		$launch           = EE::launch( 'docker-compose config --services' );
		$services         = explode( PHP_EOL, trim( $launch->stdout ) );
		$min_req_services = [ 'nginx', 'php' ];

		if ( count( array_intersect( $services, $min_req_services ) ) !== count( $min_req_services ) ) {
			EE::error( sprintf( 'Site of type %s does not support admin-tools.', $this->site_data->site_type ) );
		}

		$this->install();
		chdir( $this->site_data->site_fs_path );

		$docker_compose_data  = [
			'ee_root_dir'   => EE_ROOT_DIR,
			'db_path'       => DB,
			'ee_admin_path' => '/var/www/htdocs/ee-admin',
			'redis_host'    => $this->site_data->cache_host,
			'db_host'       => $this->site_data->db_host,
		];
		$docker_compose_admin = EE\Utils\mustache_render( ADMIN_TEMPLATE_ROOT . '/docker-compose-admin.mustache', $docker_compose_data );
		$this->fs->dumpFile( $this->site_data->site_fs_path . '/docker-compose-admin.yml', $docker_compose_admin );

		if ( EE::exec( 'docker-compose -f docker-compose.yml -f docker-compose-admin.yml up -d nginx' ) ) {
			EE::success( sprintf( 'admin-tools enabled for %s site.', $this->site_data->site_url ) );
			$this->site_data->admin_tools = 1;
			$this->site_data->save();
		} else {
			EE::error( sprintf( 'Error in enabling admin-tools for %s site. Check logs.', $this->site_data->site_url ) );
		}

		EE\Utils\delem_log( 'admin-tools ' . __FUNCTION__ . ' stop' );
	}

	/**
	 * Disables admin-tools on given site.
	 *
	 * ## OPTIONS
	 *
	 * [<site-name>]
	 * : Name of website to disable admin-tools on.
	 *
	 * [--force]
	 * : Force disabling of admin-tools for a site.
	 *
	 * ## EXAMPLES
	 *
	 *     # Disable admin tools on site
	 *     $ ee admin-tools disable example.com
	 *
	 *     # Force disable admin tools on site
	 *     $ ee admin-tools disable example.com --force
	 *
	 */
	public function disable( $args, $assoc_args ) {

		EE\Utils\delem_log( 'admin-tools ' . __FUNCTION__ . ' start' );
		$args            = auto_site_name( $args, 'admin-tools', __FUNCTION__ );
		$force           = EE\Utils\get_flag_value( $assoc_args, 'force' );
		$this->site_data = Site::find( EE\Utils\remove_trailing_slash( $args[0] ) );
		if ( ! $this->site_data || ! $this->site_data->site_enabled ) {
			EE::error( sprintf( 'Site %s does not exist / is not enabled.', $args[0] ) );
		}

		if ( ! $this->site_data->admin_tools && ! $force ) {
			EE::error( sprintf( 'admin-tools already seem to be disabled for %s', $this->site_data->site_url ) );
		}

		EE_DOCKER::docker_compose_up( $this->site_data->site_fs_path, [ 'nginx', 'php' ] );
		EE::success( sprintf( 'admin-tools disabled for %s site.', $this->site_data->site_url ) );
		$this->site_data->admin_tools = 0;
		$this->site_data->save();
		EE\Utils\delem_log( 'admin-tools ' . __FUNCTION__ . ' stop' );
	}

	/**
	 * Check if a tools directory is installed.
	 *
	 * @param string $tool The tool whose directory has to be checked.
	 *
	 * @return bool status.
	 */
	private function is_installed( $tool = '' ) {

		$tool = in_array( $tool, [ 'index', 'phpinfo' ] ) ? $tool . '.php' : $tool;
		$tool = 'opcache' === $tool ? $tool . '-gui.php' : $tool;

		return $this->fs->exists( ADMIN_TOOL_DIR . '/' . $tool );
	}

	/**
	 * Place config files from templates to tools.
	 *
	 * @param string $config_file   Destination Path where the config file needs to go.
	 * @param string $template_file Source Template file from which the config needs to be created.
	 */
	private function move_config_file( $template_file, $config_file ) {

		$this->fs->dumpFile( $config_file, file_get_contents( ADMIN_TEMPLATE_ROOT . '/' . $template_file ) );
	}

	/**
	 * Function to install index.php file.
	 *
	 * @param array $data       Data about url and version from `tools.json`.
	 * @param string $tool_path Path to where the tool needs to be installed.
	 */
	private function install_index( $data, $tool_path ) {

		$index_path_data = [
			'db_path'       => DB,
			'ee_admin_path' => '/var/www/htdocs/ee-admin',
		];
		$index_file      = EE\Utils\mustache_render( ADMIN_TEMPLATE_ROOT . '/index.mustache', $index_path_data );
		$this->fs->dumpFile( $tool_path . '.php', $index_file );
	}

	/**
	 * Function to install phpinfo.php file.
	 *
	 * @param array $data       Data about url and version from `tools.json`.
	 * @param string $tool_path Path to where the tool needs to be installed.
	 */
	private function install_phpinfo( $data, $tool_path ) {

		$this->move_config_file( 'phpinfo.mustache', $tool_path . '.php' );
	}

	/**
	 * Function to install phpMyAdmin.
	 *
	 * @param array $data       Data about url and version from `tools.json`.
	 * @param string $tool_path Path to where the tool needs to be installed.
	 */
	private function install_pma( $data, $tool_path ) {

		$temp_dir      = EE\Utils\get_temp_dir();
		$download_path = $temp_dir . 'pma.zip';
		$unzip_folder  = $temp_dir . '/pma';
		$this->fs->remove( [ $download_path, $unzip_folder ] );
		download( $download_path, $data['url'] );
		extract_zip( $download_path, $unzip_folder );
		$zip_folder_name = scandir( $unzip_folder );
		$this->fs->rename( $unzip_folder . '/' . array_pop( $zip_folder_name ), $tool_path );

		$pma_config_data = [
			'blowfish_secret' => random_password( 32 ),
		];
		$pma_config      = EE\Utils\mustache_render( ADMIN_TEMPLATE_ROOT . '/pma.config.mustache', $pma_config_data );
		$this->fs->dumpFile( $tool_path . '/config.inc.php', $pma_config );
	}

	/**
	 * Function to install phpRedisAdmin.
	 *
	 * @param array $data       Data about url and version from `tools.json`.
	 * @param string $tool_path Path to where the tool needs to be installed.
	 */
	private function install_pra( $data, $tool_path ) {

		$temp_dir      = EE\Utils\get_temp_dir();
		$download_path = $temp_dir . 'pra.zip';
		$unzip_folder  = $temp_dir . '/pra';
		$this->fs->remove( [ $download_path, $unzip_folder ] );
		$vendor_zip   = $temp_dir . 'vendor.zip';
		$download_url = str_replace( '{version}', $data['version'], $data['url'] );
		download( $download_path, $download_url );
		extract_zip( $download_path, $unzip_folder );
		$zip_folder_name        = scandir( $unzip_folder );
		$pra_root_folder        = $unzip_folder . '/' . array_pop( $zip_folder_name );
		$vendor_path            = $pra_root_folder . '/vendor';
		$vendor_requirement_url = 'https://github.com/nrk/predis/archive/v1.1.1.zip';
		download( $vendor_zip, $vendor_requirement_url );
		extract_zip( $vendor_zip, $pra_root_folder );
		$this->fs->rename( $pra_root_folder . '/predis-1.1.1', $vendor_path );
		$this->fs->rename( $pra_root_folder, $tool_path );
		$this->move_config_file( 'pra.config.mustache', $tool_path . '/includes/config.inc.php' );
	}

	/**
	 * Function to install opcache gui.
	 *
	 * @param array $data       Data about url and version from `tools.json`.
	 * @param string $tool_path Path to where the tool needs to be installed.
	 */
	private function install_opcache( $data, $tool_path ) {

		$temp_dir      = EE\Utils\get_temp_dir();
		$download_path = $temp_dir . 'opcache-gui.php';
		$this->fs->remove( $download_path );
		download( $download_path, $data['url'] );
		$this->fs->rename( $temp_dir . 'opcache-gui.php', $tool_path . '-gui.php' );
	}

}
