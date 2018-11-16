<?php


class Deployer {

    public function __construct() {

        switch ( $this->selected_deployment_option ) {
            case 'folder':
                $this->copyStaticSiteToPublicFolder();
                break;

            case 'zip':
                $this->create_zip();
                break;

            case 'dropbox':
                $this->dropboxAccessToken =
                    filter_input( INPUT_POST, 'dropboxAccessToken' );
                $this->dropboxFolder =
                    filter_input( INPUT_POST, 'dropboxFolder' );

                $this->dropbox_prepare_export();
                $this->dropbox_do_export( true );
                break;
        }

        // TODO: email upon successful cron deploy
        // $this->emailDeployNotification();
        error_log( 'scheduled deploy complete' );
    }

    public function emailDeployNotification() {
        $current_user = wp_get_current_user();
        $to = $current_user->user_email;
        $subject = 'Static site deployment: ' .
            $site_title = get_bloginfo( 'name' );
        $body = 'Your WordPress site has been automatically deployed.';
        $headers = array( 'Content-Type: text/html; charset=UTF-8' );

        wp_mail( $to, $subject, $body, $headers );
    }

    public function dropbox_prepare_export() {
        $dropbox = new StaticHtmlOutput_Dropbox(
            $this->dropboxAccessToken,
            $this->dropboxFolder,
            $this->getWorkingDirectory()
        );

        $dropbox->prepare_export();
    }

    public function dropbox_do_export( $viaCLI = false ) {
        $dropbox = new StaticHtmlOutput_Dropbox(
            $this->dropboxAccessToken,
            $this->dropboxFolder,
            $this->getWorkingDirectory()
        );

        $dropbox->transfer_files( $viaCLI );
    }

    public function prepare_file_list( $export_target ) {
        $file_list_path = $this->getWorkingDirectory() .
            '/WP-STATIC-EXPORT-' . $export_target . '-FILES-TO-EXPORT';

        // zero write the file
        // TODO: avoid suppression
        $f = fopen( $file_list_path, 'r+' );
        if ( $f !== false ) {
            ftruncate( $f, 0 );
            fclose( $f );
        }

        $archiveDir = file_get_contents(
            $this->getWorkingDirectory() . '/WP-STATIC-CURRENT-ARCHIVE'
        );

        $archiveName = rtrim( $archiveDir, '/' );
        $siteroot = $archiveName . '/';

        error_log( 'preparing file list' );

        StaticHtmlOutput_FilesHelper::recursively_scan_dir(
            $siteroot,
            $siteroot,
            $file_list_path
        );
    }
}
