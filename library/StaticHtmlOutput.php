<?php
/**
 * @package WP Static Site Generator
 *
 * Copyright (c) 2011 Leon Stafford
 */

class StaticHtmlOutput_Controller {
	const VERSION = '5.8';
	const OPTIONS_KEY = 'wp-static-html-output-options';
	const HOOK = 'wp-static-html-output';

	protected static $_instance = null;
	protected $_options = null;
	protected $_view = null;
	protected $_uploadsPath;
	protected $_subdirectory;
	protected $_uploadsURL;
	protected function __construct() {}
	protected function __clone() {}

  public function loadSettingsFromClientOrDatabase() {
    $setting_keys = self::$_instance->_options->getSettingKeys();

    if (null !== (filter_input(INPUT_POST, 'selected_deployment_option'))) {
      error_log('loading all settings from post');
      foreach($setting_keys as $setting) {
        self::$_instance->{'_' . $setting} = filter_input(INPUT_POST, $setting); 
      } 

      // exceptional naming
      self::$_instance->_useBasicAuth = filter_input(INPUT_POST, 'sendViaBasic');
    } else {
      error_log('loading all settings from DB');
      // export being triggered via Cron/CLI, load settings from DB
      parse_str(self::$_instance->_options->getOption('static-export-settings'), $pluginOptions);

      foreach($setting_keys as $setting) {
        self::$_instance->{'_' . $setting} = filter_input(INPUT_POST, $setting); 
        if ( array_key_exists($setting, $pluginOptions )) {
          self::$_instance->{'_' . $setting} = $pluginOptions[$setting];
        }
      } 

      if ( array_key_exists('sendViaBasic', $pluginOptions )) {
        self::$_instance->_useBasicAuth = $pluginOptions['sendViaBasic'];
      }
    }
  } 

	public static function getInstance() {
		if (null === self::$_instance) {
			self::$_instance = new self();
			self::$_instance->_options = new StaticHtmlOutput_Options(self::OPTIONS_KEY);
			self::$_instance->_view = new StaticHtmlOutput_View();


        $tmp_var_to_hold_return_array = wp_upload_dir();
        self::$_instance->_uploadsPath = $tmp_var_to_hold_return_array['basedir'];
        self::$_instance->_uploadsURL = $tmp_var_to_hold_return_array['baseurl'];

		}

		return self::$_instance;
	}

	public static function init($bootstrapFile) {
		$instance = self::getInstance();

		register_activation_hook($bootstrapFile, array($instance, 'activate'));

		if (is_admin()) {
			add_action('admin_menu', array($instance, 'registerOptionsPage'));
			add_action(self::HOOK . '-saveOptions', array($instance, 'saveOptions'));
			add_action( 'admin_enqueue_scripts', array($instance, 'load_custom_wp_admin_script') );
      add_filter( 'custom_menu_order', '__return_true' );
      add_filter( 'menu_order', array( $instance, 'set_menu_order' ) );

		}
 
		return $instance;
	}

    public function set_menu_order( $menu_order ) {
        $order = array();
        $file  = plugin_basename( __FILE__ );
        foreach ( $menu_order as $index => $item ) {
            if ( $item == 'index.php') {
                $order[] = $item;
            } 
        }

		$order = array(
			'index.php',
			'wp-static-html-output'
		);

        return $order;
    }


	public function load_custom_wp_admin_script() {
		$pluginDirUrl = plugin_dir_url(dirname(__FILE__));
		wp_enqueue_script( 'wsho_custom_js', $pluginDirUrl . '/js/index.js' );
	}


	public function saveOptions() {
    // required
    }

  public function activate_for_single_site() {
      if (null === $this->_options->getOption('version')) {
        $this->_options
          ->setOption('version', self::VERSION)
          ->setOption('static_export_settings', self::VERSION)
          ->save();
      }
  }


	public function activate($network_wide) {
    if ( $network_wide ) {
      global $wpdb;

      $site_ids = $wpdb->get_col( "SELECT blog_id FROM $wpdb->blogs WHERE site_id = $wpdb->siteid;" );

      foreach ( $site_ids as $site_id ) {
        switch_to_blog( $site_id );
        $this->activate_for_single_site();  
      }

      restore_current_blog();


    } else {
        $this->activate_for_single_site();  
    } 
	}

	public function registerOptionsPage() {
		$pluginDirUrl = plugin_dir_url(dirname(__FILE__));
		$page = add_menu_page(
			__('WP Static Site Generator', 'static-html-output-plugin'), 
			__('WP Static Site Generator', 'static-html-output-plugin'), 
			'manage_options', 
			self::HOOK, 
			array(self::$_instance, 'renderOptionsPage'),
			//'dashicons-shield-alt'
			$pluginDirUrl . 'images/menu_icon_32x32.png'
		);

		add_action('admin_print_styles-' . $page, array($this, 'enqueueAdminStyles'));
	}

	public function enqueueAdminStyles() {
		$pluginDirUrl = plugin_dir_url(dirname(__FILE__));
		wp_enqueue_style(self::HOOK . '-admin', $pluginDirUrl . '/css/wp-static-html-output.css');
	}

	public function renderOptionsPage() {
		// Check system requirements
		$uploadsFolderWritable = $this->_uploadsPath && is_writable($this->_uploadsPath);
		$supports_cURL = extension_loaded('curl');
		$permalinksStructureDefined = strlen(get_option('permalink_structure'));

		if (
			!$uploadsFolderWritable || 
			!$permalinksStructureDefined ||
		    !$supports_cURL
		) {
			$this->_view
				->setTemplate('system-requirements')
				->assign('uploadsFolderWritable', $uploadsFolderWritable)
				->assign('supports_cURL', $supports_cURL)
				->assign('permalinksStructureDefined', $permalinksStructureDefined)
				->assign('uploadsPath', $this->_uploadsPath)
				->render();
		} else {
			do_action(self::HOOK . '-saveOptions');
			$wp_upload_dir = wp_upload_dir();

			$this->_view
				->setTemplate('options-page-js')
				->assign('staticExportSettings', $this->_options->getOption('static-export-settings'))
				->assign('wpUploadsDir', $this->_uploadsURL)
				->assign('wpPluginDir', plugins_url('/', __FILE__))
				->assign('onceAction', self::HOOK . '-options')
				->render();

			$this->_view
				->setTemplate('options-page')
				->assign('staticExportSettings', $this->_options->getOption('static-export-settings'))
				->assign('wpUploadsDir', $this->_uploadsURL)
				->assign('wpPluginDir', plugins_url('/', __FILE__))
				->assign('onceAction', self::HOOK . '-options')
				->assign('uploadsPath', $this->_uploadsPath)
				->render();
		}
	}

    public function save_options () {
		if (!check_admin_referer(self::HOOK . '-options') || !current_user_can('manage_options')) {
			exit('You cannot change WP Static Site Generator Plugin options.');
		}

		$this->_options
			->setOption('static-export-settings', filter_input(INPUT_POST, 'staticExportSettings', FILTER_SANITIZE_URL))
			->save();
    }

	public function outputPath(){
		// TODO: a costly function, think about optimisations, we don't want this running for each request if possible

		// set default uploads path as output path
		$outputDir = $this->_uploadsPath;

		// check for outputDir set in saved options
		parse_str($this->_options->getOption('static-export-settings'), $pluginOptions);
		if ( array_key_exists('outputDirectory', $pluginOptions )) {
			if ( !empty($pluginOptions['outputDirectory']) ) {
				$outputDir = $pluginOptions['outputDirectory'];
			}
		} 

		// override if user has specified it in the UI
		if ( ! $this->_outputDirectory ) {
			$outputDir = $this->_outputDirectory;
		} 

		if ( !is_dir($outputDir) ) {
			// reverting back to default uploads path	
			$outputDir = $this->_uploadsPath;
		}

		// if path is not writeable, revert back to default	
		if ( empty($outputDir) || !is_writable($outputDir) ) {
			$outputDir = $this->_uploadsPath;
		}

		return $outputDir;
	}

    public function progressThroughExportTargets() {
        $exportTargetsFile = $this->_uploadsPath . '/WP-STATIC-EXPORT-TARGETS';

        // remove first line from file (disabled while testing)
        $exportTargets = file($exportTargetsFile, FILE_IGNORE_NEW_LINES);
        $filesRemaining = count($exportTargets) - 1;
        $first_line = array_shift($exportTargets);
        file_put_contents($exportTargetsFile, implode("\r\n", $exportTargets));
    }


  public function capture_last_deployment() {
      // skip for first export state
      if (is_file($this->_uploadsPath . '/WP-STATIC-CURRENT-ARCHIVE')) {
        $archiveDir = file_get_contents($this->_uploadsPath . '/WP-STATIC-CURRENT-ARCHIVE');
        $previous_export = $archiveDir;
        $dir_to_diff_against = $this->outputPath() . '/previous-export';

        if ($this->_diffBasedDeploys) {
          $archiveDir = file_get_contents($this->_uploadsPath . '/WP-STATIC-CURRENT-ARCHIVE');

          $previous_export = $archiveDir;
          $dir_to_diff_against = $this->outputPath() . '/previous-export';

          if (is_dir($previous_export)) {
            shell_exec("rm -Rf $dir_to_diff_against && mkdir -p $dir_to_diff_against && cp -r $previous_export/* $dir_to_diff_against");

          } 
        } else {
            if(is_dir($dir_to_diff_against)) {
                StaticHtmlOutput_FilesHelper::delete_dir_with_files($dir_to_diff_against);
                StaticHtmlOutput_FilesHelper::delete_dir_with_files($archiveDir);
              }
        }
      }

		echo 'SUCCESS';
  }

	public function cleanup_leftover_archives() {
		$leftover_files = preg_grep('/^([^.])/', scandir($this->_uploadsPath));

		foreach ($leftover_files as $fileName) {
			if( strpos($fileName, 'wp-static-html-output-') !== false ) {

				if (is_dir($this->_uploadsPath . '/' . $fileName)) {
					StaticHtmlOutput_FilesHelper::delete_dir_with_files($this->_uploadsPath . '/' . $fileName);
				} else {
					unlink($this->_uploadsPath . '/' . $fileName);
				}
			}
		}

		echo 'SUCCESS';
	}	

	public function pre_export_cleanup() {
		$files_to_clean = array(
			'/WP-STATIC-EXPORT-TARGETS',
			'/WP-STATIC-EXPORT-S3-FILES-TO-EXPORT',
			'/WP-STATIC-EXPORT-FTP-FILES-TO-EXPORT',
			'/WP-STATIC-EXPORT-GITHUB-FILES-TO-EXPORT',
			'/WP-STATIC-EXPORT-DROPBOX-FILES-TO-EXPORT',
			'/WP-STATIC-EXPORT-BUNNYCDN-FILES-TO-EXPORT',
			'/WP-STATIC-CRAWLED-LINKS',
			'/WP-STATIC-INITIAL-CRAWL-LIST',
//			'/WP-STATIC-CURRENT-ARCHIVE', // needed for zip download, diff deploys, etc
			'WP-STATIC-EXPORT-LOG'
		);

		foreach ($files_to_clean as $file_to_clean) {
			if ( file_exists($this->_uploadsPath . '/' . $file_to_clean) ) {
				unlink($this->_uploadsPath . '/' . $file_to_clean);
			} 
		}
		
	}

	// clean up files possibly left behind by a partial export
	public function cleanup_working_files() {
    // skip first explort state
    if (is_file($this->_uploadsPath . '/WP-STATIC-CURRENT-ARCHIVE')) {
      $archiveDir = file_get_contents($this->_uploadsPath . '/WP-STATIC-CURRENT-ARCHIVE');
      $dir_to_diff_against = $this->outputPath() . '/previous-export';

      if(is_dir($dir_to_diff_against)) {
        // TODO: rewrite to php native in case of shared hosting 
        // delete archivedir and then recursively copy 
        shell_exec("cp -r $dir_to_diff_against/* $archiveDir/");
      }
    }

		$files_to_clean = array(
			'/WP-STATIC-EXPORT-TARGETS',
			'/WP-STATIC-EXPORT-S3-FILES-TO-EXPORT',
			'/WP-STATIC-EXPORT-FTP-FILES-TO-EXPORT',
			'/WP-STATIC-EXPORT-GITHUB-FILES-TO-EXPORT',
			'/WP-STATIC-EXPORT-DROPBOX-FILES-TO-EXPORT',
			'/WP-STATIC-EXPORT-BUNNYCDN-FILES-TO-EXPORT',
			'/WP-STATIC-CRAWLED-LINKS',
			'/WP-STATIC-INITIAL-CRAWL-LIST',
			//'/WP-STATIC-CURRENT-ARCHIVE', // needed for zip download, diff deploys, etc
			//'WP-STATIC-EXPORT-LOG'
		);

		foreach ($files_to_clean as $file_to_clean) {
			if ( file_exists($this->_uploadsPath . '/' . $file_to_clean) ) {
				unlink($this->_uploadsPath . '/' . $file_to_clean);
			} 
		}

		echo 'SUCCESS';
	}

	public function start_export($viaCLI = false) {


		$this->pre_export_cleanup();

    $exportTargetsFile = $this->_uploadsPath . '/WP-STATIC-EXPORT-TARGETS';

    // add each export target to file
    if ($this->_sendViaGithub == 1) {
        file_put_contents($exportTargetsFile, 'GITHUB' . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    if ($this->_sendViaFTP == 1) {
        file_put_contents($exportTargetsFile, 'FTP' . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    if ($this->_sendViaS3 == 1) {
        file_put_contents($exportTargetsFile, 'S3' . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    if ($this->_sendViaNetlify == 1) {
        file_put_contents($exportTargetsFile, 'NETLIFY' . PHP_EOL, FILE_APPEND | LOCK_EX);
    }
    if ($this->_sendViaDropbox == 1) {
        file_put_contents($exportTargetsFile, 'DROPBOX' . PHP_EOL, FILE_APPEND | LOCK_EX);
    }

    // initilise log with environmental info
    WsLog::l('STARTING EXPORT' . date("Y-m-d h:i:s") );
    WsLog::l('STARTING EXPORT: PHP VERSION ' . phpversion() );
    WsLog::l('STARTING EXPORT: PHP MAX EXECUTION TIME ' . ini_get('max_execution_time') );
    WsLog::l('STARTING EXPORT: OS VERSION ' . php_uname() );
    WsLog::l('STARTING EXPORT: WP VERSION ' . get_bloginfo('version') );
    WsLog::l('STARTING EXPORT: WP URL ' . get_bloginfo('url') );
    WsLog::l('STARTING EXPORT: WP SITEURL ' . get_option('siteurl') );
    WsLog::l('STARTING EXPORT: WP HOME ' . get_option('home') );
    WsLog::l('STARTING EXPORT: WP ADDRESS ' . get_bloginfo('wpurl') );
    WsLog::l('STARTING EXPORT: PLUGIN VERSION ' . $this::VERSION );
    WsLog::l('STARTING EXPORT: VIA CLI? ' . $viaCLI);
    WsLog::l('STARTING EXPORT: STATIC EXPORT URL ' . $this->_baseUrl );

    $initial_file_list_count = StaticHtmlOutput_FilesHelper::buildInitialFileList(
      $viaCLI,
      $this->_additionalUrls,
      $this->_uploadsPath,
      $this->_uploadsURL,
      $this->outputPath(),
      self::HOOK,
      ! $this->_dontIncludeAllUploadFiles // TODO: neg neg here inelegant
);


    echo 'SUCCESS';
}

public function recursive_copy($srcdir, $dstdir) {
  $dir = opendir($srcdir);
  @mkdir($dstdir);
  while ($file = readdir($dir)) {
    if ($file != '.'  && $file != '..') {
      $src = $srcdir . '/' . $file;
      $dst = $dstdir . '/' . $file;
      if (is_dir($src)) { 
          $this->recursive_copy($src, $dst); 
      } else { 
        copy($src, $dst); 
      }
    }
  }
  closedir($dir);
}

	public function copyStaticSiteToPublicFolder() {
		if ( $this->_selected_deployment_option == 'folder' ) {
			$publicFolderToCopyTo = trim($this->_targetFolder);

			if ( ! empty($publicFolderToCopyTo) ) {
				// if folder isn't empty and current deployment option is "folder"
				$publicFolderToCopyTo = ABSPATH . $publicFolderToCopyTo;

				// mkdir for the new dir
				if (!file_exists($publicFolderToCopyTo)) {
					if (wp_mkdir_p($publicFolderToCopyTo)) {
						// file permissions to allow public viewing of files within
						chmod($publicFolderToCopyTo, 0755);

						// copy the contents of the current archive to the targetFolder
						$archiveDir = untrailingslashit(file_get_contents($this->_uploadsPath . '/WP-STATIC-CURRENT-ARCHIVE'));

						$this->recursive_copy($archiveDir, $publicFolderToCopyTo);	

					} else {
						error_log('Couldn\'t create target folder to copy files to');
					}
				} else {

					$archiveDir = untrailingslashit(file_get_contents($this->_uploadsPath . '/WP-STATIC-CURRENT-ARCHIVE'));

					$this->recursive_copy($archiveDir, $publicFolderToCopyTo);	
				}

			}
		}
	
	}

public function crawlABitMore($viaCLI = false) {
  $initial_crawl_list_file = $this->_uploadsPath . '/WP-STATIC-INITIAL-CRAWL-LIST';
  $crawled_links_file = $this->_uploadsPath . '/WP-STATIC-CRAWLED-LINKS';
  $initial_crawl_list = file($initial_crawl_list_file, FILE_IGNORE_NEW_LINES);
  $crawled_links = file($crawled_links_file, FILE_IGNORE_NEW_LINES);

  $first_line = array_shift($initial_crawl_list);
  file_put_contents($initial_crawl_list_file, implode("\r\n", $initial_crawl_list));
  $currentUrl = $first_line;

  if (empty($currentUrl)){
    // skip this empty file

    $f = file($initial_crawl_list_file, FILE_IGNORE_NEW_LINES);
    $filesRemaining = count($f);
    if ($filesRemaining > 0) {
      echo $filesRemaining;
    } else {
      echo 'SUCCESS';
    }

    return;
  }

  $basicAuth = array(
      'useBasicAuth' => $this->_useBasicAuth,
      'basicAuthUser' => $this->_basicAuthUser,
      'basicAuthPassword' => $this->_basicAuthPassword);

  $urlResponse = new StaticHtmlOutput_UrlRequest($currentUrl, $basicAuth);
  $urlResponseForFurtherExtraction = new StaticHtmlOutput_UrlRequest($currentUrl, $basicAuth);

  if ($urlResponse->checkResponse() == 'FAIL') {
    WsLog::l('FAILED TO CRAWL FILE: ' . $currentUrl);
  } else {
    file_put_contents($crawled_links_file, $currentUrl . PHP_EOL, FILE_APPEND | LOCK_EX);
  }

  $baseUrl = untrailingslashit(home_url());

  $tmp_upload_dir_var = wp_upload_dir(); // need to store as var first

  $wp_site_environment = array(
      'wp_inc' =>  '/' . WPINC,	
      'wp_content' => '/wp-content', // TODO: check if this has been modified/use constant
      'wp_uploads' =>  str_replace(ABSPATH, '/', $tmp_upload_dir_var['basedir']),	
      'wp_plugins' =>  str_replace(ABSPATH, '/', WP_PLUGIN_DIR),	
      'wp_themes' =>  str_replace(ABSPATH, '/', get_theme_root()),	
      'wp_active_theme' =>  str_replace(home_url(), '', get_template_directory_uri()),	
      'site_url' =>  get_site_url(),
      );

  $new_wp_content = '/' . $this->_rewriteWPCONTENT;
  $new_theme_root = $new_wp_content . '/' . $this->_rewriteTHEMEROOT;
  $new_theme_dir = $new_theme_root . '/' . $this->_rewriteTHEMEDIR;
  $new_uploads_dir = $new_wp_content . '/' . $this->_rewriteUPLOADS;
  $new_plugins_dir = $new_wp_content . '/' . $this->_rewritePLUGINDIR;

  $overwrite_slug_targets = array(
      'new_wp_content_path' => $new_wp_content,
      'new_themes_path' => $new_theme_root,
      'new_active_theme_path' => $new_theme_dir,
      'new_uploads_path' => $new_uploads_dir,
      'new_plugins_path' => $new_plugins_dir,
      'new_wpinc_path' => '/' . $this->_rewriteWPINC,
      );

  $urlResponse->cleanup(
      $wp_site_environment,
      $overwrite_slug_targets
      );

  // TODO: if it replaces baseurl here, it will be searching links starting with that...
  // TODO: shouldn't be doing this here...
  $urlResponse->replaceBaseUrl($baseUrl, $this->_baseUrl, $this->_allowOfflineUsage, $this->_useRelativeURLs, $this->_useBaseHref);
  $archiveDir = file_get_contents($this->_uploadsPath . '/WP-STATIC-CURRENT-ARCHIVE');
  $this->_saveUrlData($urlResponse, $archiveDir);

  // try extracting urls from a response that hasn't been changed yet...
  // this seems to do it...
  foreach ($urlResponseForFurtherExtraction->extractAllUrls($baseUrl) as $newUrl) {
    $path = parse_url($newUrl, PHP_URL_PATH);
    $extension = pathinfo($path, PATHINFO_EXTENSION);

    if ($newUrl != $currentUrl && 
        !in_array($newUrl, $crawled_links) && 
        $extension != 'php' && 
        !in_array($newUrl, $initial_crawl_list)
       ) {

      $urlResponse = new StaticHtmlOutput_UrlRequest($newUrl, $basicAuth);

      if ($urlResponse->checkResponse() == 'FAIL') {
        WsLog::l('FAILED TO CRAWL FILE: ' . $newUrl);
      } else {
        file_put_contents($crawled_links_file, $newUrl . PHP_EOL, FILE_APPEND | LOCK_EX);
        $crawled_links[] = $newUrl;
      }

      $urlResponse->cleanup(
          $wp_site_environment,
          $overwrite_slug_targets
          );

      $urlResponse->replaceBaseUrl($baseUrl, $this->_baseUrl, $this->_allowOfflineUsage, $this->_useRelativeURLs, $this->_useBaseHref);
      $archiveDir = file_get_contents($this->_uploadsPath . '/WP-STATIC-CURRENT-ARCHIVE');
      $this->_saveUrlData($urlResponse, $archiveDir);
    } 
  }

  // TODO: could avoid reading file again here as we should have it above
  $f = file($initial_crawl_list_file, FILE_IGNORE_NEW_LINES);
  $filesRemaining = count($f);
  if ($filesRemaining > 0) {
    echo $filesRemaining;
  } else {
    echo 'SUCCESS';
  }

  // if being called via the CLI, just keep crawling (TODO: until when?)
  if ($viaCLI) {
    $this->crawl_site($viaCLI);
  }
}

	public function crawl_site($viaCLI = false) {
		$initial_crawl_list_file = $this->_uploadsPath . '/WP-STATIC-INITIAL-CRAWL-LIST';
        $initial_crawl_list = file($initial_crawl_list_file, FILE_IGNORE_NEW_LINES);

		if ( !empty($initial_crawl_list) ) {
            $this->crawlABitMore($viaCLI);
		} 
    }

    public function create_zip() {
        $archiveDir = file_get_contents($this->_uploadsPath . '/WP-STATIC-CURRENT-ARCHIVE');
        $archiveName = rtrim($archiveDir, '/');
		$tempZip = $archiveName . '.tmp';
		$zipArchive = new ZipArchive();
		if ($zipArchive->open($tempZip, ZIPARCHIVE::CREATE) !== true) {
			return new WP_Error('Could not create archive');
		}

		$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($archiveDir));
		foreach ($iterator as $fileName => $fileObject) {
			$baseName = basename($fileName);
			if($baseName != '.' && $baseName != '..') {
				if (!$zipArchive->addFile(realpath($fileName), str_replace($archiveDir, '', $fileName))) {
					return new WP_Error('Could not add file: ' . $fileName);
				}
			}
		}

		$zipArchive->close();
        $zipDownloadLink = $archiveName . '.zip';
		rename($tempZip, $zipDownloadLink); 
        $publicDownloadableZip = str_replace(ABSPATH, trailingslashit(home_url()), $archiveName . '.zip');

		echo 'SUCCESS';
		// TODO: put the zip url somewhere in the interface
        //echo $publicDownloadableZip;
    }

	public function prepare_file_list($export_target) {
         $file_list_path = $this->_uploadsPath . '/WP-STATIC-EXPORT-' . $export_target . '-FILES-TO-EXPORT';

		// zero file
        $f = @fopen($file_list_path, "r+");
        if ($f !== false) {
            ftruncate($f, 0);
            fclose($f);
        }

        $archiveDir = file_get_contents($this->_uploadsPath . '/WP-STATIC-CURRENT-ARCHIVE');
        $archiveName = rtrim($archiveDir, '/');
        $siteroot = $archiveName . '/';

        error_log('preparing file list');

        StaticHtmlOutput_FilesHelper::recursively_scan_dir($siteroot, $siteroot, $file_list_path);
	}

    public function deploy($viaCLI = false) {
      error_log('deploying: ' . $this->_selected_deployment_option);
      switch($this->_selected_deployment_option) {
        case 'folder':
          $this->copyStaticSiteToPublicFolder();
        break;

        case 'github':
          $github = new StaticHtmlOutput_GitHub(
            $this->_githubRepo,
            $this->_githubPersonalAccessToken,
            $this->_githubBranch,
            $this->_githubPath,
            $this->_uploadsPath
          );

          $github->prepare_deployment();
          $github->upload_blobs($viaCLI);
          $github->commit_new_tree();
        break;

        case 'ftp':
          $ftp = new StaticHtmlOutput_FTP(
            $this->_ftpServer,
            $this->_ftpUsername,
            $this->_ftpPassword,
            $this->_ftpRemotePath,
            $this->_useActiveFTP,
            $this->_uploadsPath
          );

          $ftp->prepare_deployment();
          $ftp->transfer_files($viaCLI);
        break;

        case 'netlify':
          $this->create_zip();

          // will exclude the siteroot when copying
          $archiveDir = file_get_contents($this->_uploadsPath . '/WP-STATIC-CURRENT-ARCHIVE');
          $archiveName = rtrim($archiveDir, '/') . '.zip';

          $netlify = new StaticHtmlOutput_Netlify(
            $this->_netlifySiteID,
            $this->_netlifyPersonalAccessToken
          );

          echo $netlify->deploy($archiveName);
        break;

        case 'zip':
          $this->create_zip();
        break;

        case 's3':
          if ( wpsho_fr()->is__premium_only() ) {

            $s3 = new StaticHtmlOutput_S3(
              $this->_s3Key,
              $this->_s3Secret,
              $this->_s3Region,
              $this->_s3Bucket,
              $this->_s3RemotePath,
              $this->_uploadsPath
            );

            $s3->prepare_deployment();
            $s3->transfer_files($viaCLI);

            $cloudfront_id = $this->_cfDistributionId;

            if( !empty($cloudfront_id) ) {
              require_once(__DIR__.'/CloudFront/CloudFront.php');

              $cf = new CloudFront(
              $this->_s3Key,
              $this->_s3Secret,
                $cloudfront_id);

              $cf->invalidate('/*');
            
              if ( $cf->getResponseMessage() == 200 || $cf->getResponseMessage() == 201 )	{
                echo 'SUCCESS';
              } else {
                WsLog::l('CF ERROR: ' . $cf->getResponseMessage());
              }
            } else {
              echo 'SUCCESS';
            }
          }	
        break;

        case 'bunnycdn':
          if ( wpsho_fr()->is__premium_only() ) {
            $bunnyCDN = new StaticHtmlOutput_BunnyCDN(
              $this->_bunnycdnPullZoneName,
              $this->_bunnycdnAPIKey,
              $this->_bunnycdnRemotePath,
              $this->_uploadsPath
            );

            $bunnyCDN->prepare_export();
            $bunnyCDN->transfer_files($viaCLI);
            $bunnyCDN->purge_all_cache();
          }
        break;

        case 'dropbox':
          $dropbox = new StaticHtmlOutput_Dropbox(
            $this->_dropboxAccessToken,
            $this->_dropboxFolder,
            $this->_uploadsPath
          );

          $dropbox->prepare_export();
          $dropbox->transfer_files($viaCLI);
        break;
      }

      error_log('scheduled deploy complete');
      // TODO: email upon successful cron deploy
      // $current_user = wp_get_current_user();

      // $to = $current_user->user_email;
      // $subject = 'Static site deployment: ' . $site_title = get_bloginfo( 'name' );;
      // $body = 'Your WordPress site has been automatically deployed.';
      // $headers = array('Content-Type: text/html; charset=UTF-8');
      //  
      // wp_mail( $to, $subject, $body, $headers );
    }


    public function trigger_export_from_ui() {
      error_log('export triggered from UI');

        self::$_instance->loadSettingsFromClientOrDatabase();

        //$this->capture_last_deployment();
        //$this->cleanup_working_files();
        error_log('cleanup_leftover_archives export');
        $this->cleanup_leftover_archives();
        error_log('start export');
        $this->start_export();
        error_log('crawl_site');
        $this->crawl_site();
        error_log('create_symlink_to_latest_archive');
        $this->create_symlink_to_latest_archive();
        error_log('post_process_archive_dir');
        $this->post_process_archive_dir();
        error_log('deploy');
        $this->deploy();
        error_log('post_export_teardown');
        $this->post_export_teardown();

        echo 'SUCCESS';
    }

    public function doExportWithoutGUI() {
      if ( wpsho_fr()->is_plan('professional_edition') ) {
        self::$_instance->loadSettingsFromClientOrDatabase();
    
        //$this->capture_last_deployment(); 
        $this->cleanup_leftover_archives(true);
        $this->start_export(true);
        $this->crawl_site(true);
        $this->create_symlink_to_latest_archive(true);
        $this->post_process_archive_dir(true);

        if (in_array($this->_selected_deployment_option, array('zip', 'netlify'))) {
          $this->create_zip();
        }

        $this->deploy(true);
        $this->post_export_teardown();
      }
    }

	public function reset_default_settings() {
		$this->_options
			->setOption('static-export-settings', '')
			->save();

		echo 'SUCCESS';
	}	

	public function detect_base_url() {
		$site_url = get_option( 'siteurl' );
		$home = get_option( 'home' );

		// case for when WP is installed in a different place then being served
		if ( $site_url !== $home ) {
			$this->_subdirectory = '/mysubdirectory';
		}

		$base_url = parse_url($site_url);

		if ( array_key_exists('path', $base_url ) && $base_url['path'] != '/' ) {
			$this->_subdirectory = $base_url['path'];
		}
	}	

    public function post_process_archive_dir() {
        $archiveDir = untrailingslashit(file_get_contents($this->_uploadsPath . '/WP-STATIC-CURRENT-ARCHIVE'));

      error_log('archiveDir');
      error_log($archiveDir);


		$this->detect_base_url();

		$archiveDir .= $this->_subdirectory;

		// rename dirs (in reverse order than when doing in responsebody)
		// rewrite wp-content  dir
		$original_wp_content = $archiveDir . '/wp-content'; // TODO: check if this has been modified/use constant

		// rename the theme theme root before the nested theme dir
		// rename the theme directory 
    $new_wp_content = $archiveDir .'/' . $this->_rewriteWPCONTENT;
    $new_theme_root = $new_wp_content . '/' . $this->_rewriteTHEMEROOT;
    $new_theme_dir =  $new_theme_root . '/' . $this->_rewriteTHEMEDIR;

		// rewrite uploads dir
		$default_upload_dir = wp_upload_dir(); // need to store as var first
		$updated_uploads_dir =  str_replace(ABSPATH, '', $default_upload_dir['basedir']);
		
		$updated_uploads_dir =  str_replace('wp-content/', '', $updated_uploads_dir);
		$updated_uploads_dir = $new_wp_content . '/' . $updated_uploads_dir;
		$new_uploads_dir = $new_wp_content . '/' . $this->_rewriteUPLOADS;


		$updated_theme_root = str_replace(ABSPATH, '/', get_theme_root());
		$updated_theme_root = $new_wp_content . str_replace('wp-content', '/', $updated_theme_root);

		$updated_theme_dir = $new_theme_root . '/' . basename(get_template_directory_uri());
		$updated_theme_dir = str_replace('\/\/', '', $updated_theme_dir);

		// rewrite plugins dir
		$updated_plugins_dir = str_replace(ABSPATH, '/', WP_PLUGIN_DIR);
		$updated_plugins_dir = str_replace('wp-content/', '', $updated_plugins_dir);
		$updated_plugins_dir = $new_wp_content . $updated_plugins_dir;
		$new_plugins_dir = $new_wp_content . '/' . $this->_rewritePLUGINDIR;

		// rewrite wp-includes  dir
		$original_wp_includes = $archiveDir . '/' . WPINC;
		$new_wp_includes = $archiveDir . '/' . $this->_rewriteWPINC;


		// TODO: subdir installations are not being correctly detected here

    $this->rename_populated_directory($original_wp_content, $new_wp_content);

		if (file_exists($updated_uploads_dir)) {
			$this->rename_populated_directory($updated_uploads_dir, $new_uploads_dir);
		}

		$this->rename_populated_directory($updated_theme_root, $new_theme_root);
		$this->rename_populated_directory($updated_theme_dir, $new_theme_dir);

		if( file_exists($updated_plugins_dir) ) {
			$this->rename_populated_directory($updated_plugins_dir, $new_plugins_dir);

		}
		$this->rename_populated_directory($original_wp_includes, $new_wp_includes);

		// rm other left over WP identifying files

		if( file_exists($archiveDir . '/xmlrpc.php') ) {
			unlink($archiveDir . '/xmlrpc.php');
		}

		if( file_exists($archiveDir . '/wp-login.php') ) {
			unlink($archiveDir . '/wp-login.php');
		}

		StaticHtmlOutput_FilesHelper::delete_dir_with_files($archiveDir . '/wp-json/');
		
		// TODO: remove all text files from theme dir 

    if ($this->_diffBasedDeploys) {
      $this->remove_files_idential_to_previous_export();
    } 

		$this->copyStaticSiteToPublicFolder();


		echo 'SUCCESS';
	}

  public function files_are_equal($a, $b) {
    // if image, use sha, if html, use something else
    $pathinfo = pathinfo($a);
    if (isset($pathinfo['extension']) && in_array($pathinfo['extension'], array('jpg', 'png', 'gif', 'jpeg'))) {
      return sha1_file($a) === sha1_file($b);
    }

    $diff = exec("diff $a $b");
    $result = $diff === '';

    return $result;
  }

  public function remove_files_idential_to_previous_export() {
    $archiveDir = file_get_contents($this->_uploadsPath . '/WP-STATIC-CURRENT-ARCHIVE');
    $dir_to_diff_against = $this->outputPath() . '/previous-export';

    // iterate each file in current export, check the size and contents in previous, delete if match
    $objects = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(
          $archiveDir, 
          RecursiveDirectoryIterator::SKIP_DOTS));

    foreach($objects as $current_file => $object){
        if (is_file($current_file)) {
          // get relative filename
          $filename = str_replace($archiveDir, '', $current_file);
   
          $previously_exported_file = $dir_to_diff_against . '/' . $filename;

          // if file doesn't exist at all in previous export:
          if (is_file($previously_exported_file)) {
            if ( $this->files_are_equal($current_file, $previously_exported_file)) {
              unlink($current_file);
            } 
          } 
        }
    }

    // TODO: cleanup empty dirs in archiveDir to prevent them being attempted to export

    $files_in_previous_export = exec("find $dir_to_diff_against -type f | wc -l"); 
    $files_to_be_deployed = exec("find $archiveDir -type f | wc -l"); 
 
    // copy the newly changed files back into the previous export dir, else will never capture changes

 

    // TODO: this works the first time, but will fail the diff on subsequent runs, alternating each time`
  }
  
  // default rename in PHP throws warnings if dir is populated
  public function rename_populated_directory($source, $target) {
    $this->recursive_copy($source, $target);

    StaticHtmlOutput_FilesHelper::delete_dir_with_files($source);
  }

	public function remove_symlink_to_latest_archive() {
        global $blog_id;
        $archiveDir = file_get_contents($this->_uploadsPath . '/WP-STATIC-CURRENT-ARCHIVE');

		if (is_link($this->outputPath() . '/latest-' . $blog_id)) {
			unlink($this->outputPath() . '/latest-' . $blog_id );
		} 
	}	

	public function create_symlink_to_latest_archive() {
        global $blog_id;
        $archiveDir = file_get_contents($this->_uploadsPath . '/WP-STATIC-CURRENT-ARCHIVE');

		// rm and recreate
		$this->remove_symlink_to_latest_archive();


        symlink($archiveDir, $this->outputPath() . '/latest-' . $blog_id );

		echo 'SUCCESS';
	}	


    public function post_export_teardown() {


		$this->cleanup_working_files();


		// has SUCCESS returned already from cleanup working files..
	}

	protected function _saveUrlData(StaticHtmlOutput_UrlRequest $url, $archiveDir) {
		$urlInfo = parse_url($url->getUrl());
		$pathInfo = array();

		//WsLog::l('urlInfo :' . $urlInfo['path']);
		/* will look like
			
			(homepage)

			[scheme] => http
			[host] => 172.18.0.3
			[path] => /

			(closed url segment)

			[scheme] => http
			[host] => 172.18.0.3
			[path] => /feed/

			(file with extension)

			[scheme] => http
			[host] => 172.18.0.3
			[path] => /wp-content/themes/twentyseventeen/assets/css/ie8.css

		*/

		// TODO: here we can allow certain external host files to be crawled

		// validate our inputs
		if ( !isset($urlInfo['path']) ) {
			return false;
		}

		// set what the new path will be based on the given url
		if( $urlInfo['path'] != '/' ) {
			$pathInfo = pathinfo($urlInfo['path']);
		} else {
			$pathInfo = pathinfo('index.html');
		}

		// set fileDir to the directory name else empty	
		$fileDir = $archiveDir . (isset($pathInfo['dirname']) ? $pathInfo['dirname'] : '');

		// set filename to index if there is no extension and basename and filename are the same
		if (empty($pathInfo['extension']) && $pathInfo['basename'] == $pathInfo['filename']) {
			$fileDir .= '/' . $pathInfo['basename'];
			$pathInfo['filename'] = 'index';
		}

		//$fileDir = preg_replace('/(\/+)/', '/', $fileDir);

		if (!file_exists($fileDir)) {
			wp_mkdir_p($fileDir);
		}

		$fileExtension = ''; 

		// TODO: was isHtml() method modified to include more than just html
		// if there's no extension set or content type matches html, set it to html
		// TODO: seems to be flawed for say /feed/ urls, which would not be xml content type..
		if(  isset($pathInfo['extension'])) {
			$fileExtension = $pathInfo['extension']; 
		} else if( $url->isHtml() ) {
			$fileExtension = 'html'; 
		} else {
			// guess mime type
			
			$fileExtension = $url->getExtensionFromContentType(); 
		}

		$fileName = '';

		// set path for homepage to index.html, else build filename
		if ($urlInfo['path'] == '/') {
			$fileName = $fileDir . 'index.html';
		} else {
			$fileName = $fileDir . '/' . $pathInfo['filename'] . '.' . $fileExtension;
		}

    // fix for # 103 - weird case with inline style images in nested subdirs
    // should be a non-issue if using DOMDoc instead of regex parsing
		
		$fileName = str_replace(');', '', $fileName);
		// TODO: find where this extra . is coming from (current dir indicator?)
		$fileName = str_replace('.index.html', 'index.html', $fileName);
		// remove 2 or more slashes from paths
		$fileName = preg_replace('/(\/+)/', '/', $fileName);


		$fileContents = $url->getResponseBody();
		
		// TODO: what was the 'F' check for?1? Comments exist for a reason
		if ($fileContents != '' && $fileContents != 'F') {
			file_put_contents($fileName, $fileContents);
		} else {
			WsLog::l('SAVING URL: UNABLE TO SAVE FOR SOME REASON');
		}
	}
}
