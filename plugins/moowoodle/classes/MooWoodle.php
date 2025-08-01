<?php
/**
 * MooWoodle class file.
 *
 * @package MooWoodle
 */

namespace MooWoodle;

use Automattic\WooCommerce\Utilities\FeaturesUtil;

defined( 'ABSPATH' ) || exit;

/**
 * MooWoodle Main Class
 *
 * @version     3.3.0
 * @package     MooWoodle
 * @author      DualCube
 *
 * @property Util $util instance of utill class
 * @property Setting $setting instance of setting class
 * @property Core\Course $course instance of course class
 * @property Core\Category $category instance of category class
 * @property Core\Product $product instance of product class
 * @property RestAPI $restAPI instance of restapi class
 */
class MooWoodle {
    /**
     * Contain reference of MooWoodle class's object
     *
     * @var object | null
     */
	private static $instance = null;

    /**
     * Contain all helper class's reference
     *
     * @var array
     */
    private $container = array();

    /**
     * File path of moowoodle plugin.
     *
     * @var string | null
     */
    public $file = null;

    /**
     * MooWoodle class constructor function.
     *
     * @param string $file File path of moowoodle plugin.
     */
	public function __construct( $file ) {

        // load config file.
        require_once trailingslashit( dirname( $file ) ) . 'config.php';

        // store plugin info.
        $this->file                            = $file;
        $this->container['plugin_url']         = trailingslashit( plugins_url( '', $file ) );
        $this->container['plugin_path']        = trailingslashit( dirname( $file ) );
        $this->container['version']            = MOOWOODLE_PLUGIN_VERSION;
        $this->container['block_paths']        = array();
        $this->container['rest_namespace']     = 'moowoodle/v1';
        $this->container['moowoodle_logs_dir'] = ( trailingslashit( wp_upload_dir( null, false )['basedir'] ) . 'mw-logs' );
        $this->container['is_dev']             = defined( 'WP_ENV' ) && WP_ENV === 'development';
        $this->container['plugin_base']        = plugin_basename( $file );

        // activation and deactivation hook.
        register_activation_hook( $file, array( $this, 'activate' ) );
        register_deactivation_hook( $file, array( $this, 'deactivate' ) );

        // initialise plugin.
        add_action( 'before_woocommerce_init', array( $this, 'declare_compatibility' ) );
        add_action( 'woocommerce_loaded', array( $this, 'load_plugin' ) );
        add_action( 'plugins_loaded', array( $this, 'is_woocommerce_loaded' ) );
        add_filter( 'plugin_row_meta', array( $this, 'plugin_row_meta' ), 10, 2 );
        add_action( 'init', array( $this, 'migrate_from_previous_version' ) );
	}

    /**
     * Activation function.
     *
     * @return void
     */
    public function activate() {
        $this->container['install'] = new Installer();
    }

    /**
     * Deactivation function.
     *
     * @return void
     */
    public function deactivate() {
		// Nothing to write now.
    }

    /**
     * Add High Performance Order Storage Support.
     *
     * @return void
     */
    public function declare_compatibility() {
        FeaturesUtil::declare_compatibility( 'custom_order_tables', WP_CONTENT_DIR . '/plugins/moowoodle/moowoodle.php', true );
    }

    /**
     * Init plugin on woocommerce_loaded hook.
     *
     * @return void
     */
    public function load_plugin() {

        // add link on pugin 'active' button.
        if ( is_admin() && ! defined( 'DOING_AJAX' ) ) {
            add_filter( 'plugin_action_links_' . plugin_basename( $this->file ), array( $this, 'plugin_links' ) );
        }

        add_filter( 'woocommerce_email_classes', array( &$this, 'setup_email_class' ) );

        // Init required classes.
        $this->initialize_classes();

		// Init Text Domain.
		$this->load_plugin_textdomain();

        /**
         * Actiion hook after moowoodle loaded.
         */
        do_action( 'moowoodle_loaded' );
    }

    /**
     * Init all MooWoodle classess.
     * Access this classes using magic method.
     *
     * @return void
     */
    public function initialize_classes() {
        if ( is_admin() ) {
			$this->container['admin'] = new Admin();
		}

		$this->container['util']             = new Util();
        $this->container['setting']          = new Setting();
		$this->container['restAPI']          = new RestAPI();
		$this->container['course']           = new Core\Course();
		$this->container['category']         = new Core\Category();
		$this->container['product']          = new Core\Product();
        $this->container['external_service'] = new ExternalService();
		$this->container['enrollment']       = new Enrollment();
        $this->container['block']            = new Block();
        $this->container['frontendscripts']  = new FrontendScripts();
        $this->container['endpoint']         = new EndPoint();

        $this->initialize_moowoodle_log();
    }

    /**
     * Take action based on if woocommerce is not loaded.
     *
     * @return void
     */
    public function is_woocommerce_loaded() {
        if ( ! did_action( 'woocommerce_loaded' ) && is_admin() ) {
        	add_action( 'admin_notices', array( $this, 'woocommerce_admin_notice' ) );
        }
    }

    /**
     * Migrate data from previous version.
     */
    public function migrate_from_previous_version() {
        $previous_version = get_option( 'moowoodle_version', '' );

        if ( version_compare( $previous_version, MooWoodle()->version, '<' ) ) {
            new Installer();
        }
    }

    /**
     * Admin notice for woocommerce deactive.
     *
     * @return void
     */
    public function woocommerce_admin_notice() {
		?>
		<div id="message" class="error">
            <p>
            <?php
            printf(
                // translators: 1: <strong>, 2: </strong>, 3: <a>, 4: </a>, 5: <a>, 6: </a>.
                esc_html__(
                    '%1$sMooWoodle is inactive.%2$s The %3$sWooCommerce plugin%4$s must be active for the MooWoodle to work. Please %5$sinstall & activate WooCommerce%6$s',
                    'moowoodle'
                ),
                '<strong>',
                '</strong>',
                '<a target="_blank" href="https://wordpress.org/plugins/woocommerce/">',
                '</a>',
                '<a href="' . esc_url( admin_url( 'plugins.php' ) ) . '">',
                '&nbsp;&raquo;</a>'
            );
            ?>
            </p>
		</div>
    	<?php
	}

    /**
     * Add metadata links (e.g. documentation, support) to the plugin row on the Plugins screen.
     *
     * @param array  $links An array of the plugin's metadata links.
     * @param string $file  The path to the plugin file relative to the plugins directory.
     *
     * @return array Modified array of metadata links.
     */
    public function plugin_row_meta( $links, $file ) {
        if ( MooWoodle()->plugin_base === $file ) {
            $row_meta = array(
                'docs'    => '<a href="https://dualcube.com/docs/moowoodle-set-up-guide/?utm_source=wpadmin&utm_medium=pluginsettings&utm_campaign=moowoodle" aria-label="' . esc_attr__( 'View documentation', 'moowoodle' ) . '" target="_blank">' . esc_html__( 'Docs', 'moowoodle' ) . '</a>',
                'support' => '<a href="https://wordpress.org/support/plugin/moowoodle/" aria-label="' . esc_attr__( 'Visit community forums', 'moowoodle' ) . '" target="_blank">' . esc_html__( 'Support', 'moowoodle' ) . '</a>',
            );

            if ( ! Util::is_khali_dabba() ) {
                $row_meta['go_pro'] = '<a href="' . MOOWOODLE_PRO_SHOP_URL . '" class="moowoodle-pro-plugin" target="_blank" style="font-weight: 700;background: linear-gradient(110deg, rgb(63, 20, 115) 0%, 25%, rgb(175 59 116) 50%, 75%, rgb(219 75 84) 100%);-webkit-background-clip: text;-webkit-text-fill-color: transparent;">' . __( 'Upgrade to Pro', 'moowoodle' ) . '</a>';
            }

            return array_merge( $links, $row_meta );
        }

        return $links;
    }

    /**
     * Render plugin page links.
     *
     * @param array $links all links.
     * @return array
     */
    public static function plugin_links( $links ) {

        // Create moowoodle plugin page link.
        $plugin_links = array(
            '<a href="' . admin_url( 'admin.php?page=moowoodle#&tab=settings&subtab=general' ) . '">' . __( 'Settings', 'moowoodle' ) . '</a>',
        );

        // Append the link.
        $links = array_merge( $plugin_links, $links );

        if ( ! Util::is_khali_dabba() ) {
            $links[] = '<a href="' . MOOWOODLE_PRO_SHOP_URL . '" target="_blank" style="font-weight: 700;background: linear-gradient(110deg, rgb(63, 20, 115) 0%, 25%, rgb(175 59 116) 50%, 75%, rgb(219 75 84) 100%);-webkit-background-clip: text;-webkit-text-fill-color: transparent;">' . __( 'Upgrade to Pro', 'moowoodle' ) . '</a>';
        }

        return $links;
    }

	/**
	 * Load Localisation files.
	 * Note: the first-loaded translation file overrides any following ones if the same translation is present.
     *
	 * @return void
	 */
	private function load_plugin_textdomain() {
        if ( version_compare( $GLOBALS['wp_version'], '6.7', '<' ) ) {
            load_plugin_textdomain( 'moowoodle', false, plugin_basename( dirname( __DIR__ ) ) . '/languages' );
        } else {
            load_textdomain( 'moowoodle', WP_LANG_DIR . '/plugins/moowoodle-' . determine_locale() . '.mo' );
        }
	}

    /**
     * Get moowoodle log file name.
     */
    public function initialize_moowoodle_log() {
        // The log file name is stored in the options table because it is generated with an arbitrary name.
        $log_file_name = get_option( 'moowoodle_log_file' );

        if ( ! $log_file_name ) {
            $log_file_name = uniqid( 'error' ) . '.txt';
            update_option( 'moowoodle_log_file', $log_file_name );
        }

        $this->container['log_file']          = MooWoodle()->moowoodle_logs_dir . '/' . $log_file_name;
        $this->container['show_advanced_log'] = in_array( 'moowoodle_adv_log', MooWoodle()->setting->get_setting( 'moowoodle_adv_log', array() ), true );
    }

	/**
     * Magic getter function to get the reference of class.
     * Accept class name, If valid return reference, else Wp_Error.
     *
     * @param   mixed $class class.
     * @return  object | \WP_Error
     */
    public function __get( $class ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.classFound
        if ( array_key_exists( $class, $this->container ) ) {
            return $this->container[ $class ];
        }

        return new \WP_Error( sprintf( 'Call to unknown class %s.', $class ) );
    }
    /**
     * Magic setter function to store a reference of a class.
     * Accepts a class name as the key and stores the instance in the container.
     *
     * @param string $class The class name or key to store the instance.
     * @param object $value The instance of the class to store.
     */
    public function __set( $class, $value ) { // phpcs:ignore Universal.NamingConventions.NoReservedKeywordParameterNames.classFound
        $this->container[ $class ] = $value;
    }
	/**
     * Initializes the MooWoodle class.
     * Checks for an existing instance.
     * And if it doesn't find one, create it.
     *
     * @param mixed $file file name.
     * @return object | null
     */
	public static function init( $file ) {
        if ( null === self::$instance ) {
            self::$instance = new self( $file );
        }

        return self::$instance;
    }

    /**
     * Add Enrollment Email Class
     *
     * @param array $emails List of WooCommerce email classes.
     * @return array Modified list of email classes including EnrollmentEmail.
     */
    public function setup_email_class( $emails ) {
        $emails['EnrollmentEmail'] = new Emails\EnrollmentEmail();
        return $emails;
    }
}
