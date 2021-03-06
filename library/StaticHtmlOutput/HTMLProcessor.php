<?php
// TODO: rewerite to be one loop of all elements,
// applying multiple transformations at once per link, reducing iterations
// TODO: deal with inline CSS blocks or style attributes on tags
class HTMLProcessor {

    public function processHTML( $html_document, $page_url ) {
        if ( $html_document == '' ) {
            return false;
        }

        $this->target_settings = array(
            'general',
            'crawling',
            'wpenv',
            'processing',
            'advanced',
        );

        $this->loadSettings();

        // instantiate the XML body here
        $this->xml_doc = new DOMDocument();
        $this->raw_html = $html_document;
        $this->page_url = $page_url;
        $this->placeholder_URL = 'https://PLACEHOLDER.wpsho/';

        // initial rewrite of all site URLs to placeholder URLs
        $this->rewriteSiteURLsToPlaceholder();

        // detect if a base tag exists while in the loop
        // use in later base href creation to decide: append or create
        $this->base_tag_exists = false;

        require_once dirname( __FILE__ ) . '/../URL2/URL2.php';
        $this->page_url = new Net_URL2( $page_url );

        $this->detectIfURLsShouldBeHarvested();

        $this->discovered_urls = [];

        // PERF: 70% of function time
        // prevent warnings, via https://stackoverflow.com/a/9149241/1668057
        libxml_use_internal_errors( true );
        $this->xml_doc->loadHTML( $this->raw_html );
        libxml_use_internal_errors( false );

        // start the full iterator here, along with copy of dom
        $elements = iterator_to_array(
            $this->xml_doc->getElementsByTagName( '*' )
        );

        foreach ( $elements as $element ) {
            switch ( $element->tagName ) {
                case 'meta':
                    $this->processMeta( $element );
                    break;
                case 'a':
                    $this->processAnchor( $element );
                    break;
                case 'img':
                    $this->processImage( $element );
                    break;
                case 'head':
                    $this->processHead( $element );
                    break;
                case 'link':
                    // NOTE: not to confuse with anchor element
                    $this->processLink( $element );
                    break;
                case 'script':
                    // can contain src=,
                    // can also contain URLs within scripts
                    // and escaped urls
                    $this->processScript( $element );
                    break;

                    // TODO: how about other places that can contain URLs
                    // data attr, reacty stuff, etc?
            }
        }

        if ( $this->base_tag_exists ) {
            $base_element =
                $this->xml_doc->getElementsByTagName( 'base' )->item( 0 );

            if ( empty( $this->settings['baseHREF'] ) ) {
                $base_element->parentNode->removeChild( $base_element );
            } else {
                $base_element->setAttribute(
                    'href',
                    $this->settings['baseHREF']
                );
            }
        } else {
            if ( ! empty( $this->settings['baseHREF'] ) ) {
                $base_element = $this->xml_doc->createElement( 'base' );
                $base_element->setAttribute(
                    'href',
                    $this->settings['baseHREF']
                );
                $head_element =
                    $this->xml_doc->getElementsByTagName( 'head' )->item( 0 );
                if ( $head_element ) {
                    $first_head_child = $head_element->firstChild;
                    $head_element->insertBefore(
                        $base_element,
                        $first_head_child
                    );
                } else {
                    require_once dirname( __FILE__ ) .
                        '/../StaticHtmlOutput/WsLog.php';
                    WsLog::l(
                        'WARNING: no valid head elemnent to attach base to: ' .
                            $this->page_url
                    );
                }
            }
        }

        // strip comments
        $this->stripHTMLComments();

        // $this->setBaseHref();
        $this->writeDiscoveredURLs();

        return true;
    }

    public function detectIfURLsShouldBeHarvested() {
        // TODO: handle both UI and WP-CLI
        $this->harvest_new_URLs = (
             $_POST['ajax_action'] === 'crawl_site'
        );
    }

    public function loadSettings() {
        // TODO: move these into function, to allow stubbing in test
        if ( isset( $_POST['selected_deployment_option'] ) ) {
            require_once dirname( __FILE__ ) .
                '/../StaticHtmlOutput/PostSettings.php';

            $this->settings = WPSHO_PostSettings::get( $this->target_settings );
        } else {
            require_once dirname( __FILE__ ) .
                '/../StaticHtmlOutput/DBSettings.php';
            $this->settings = WPSHO_DBSettings::get( $this->target_settings );
        }
    }

    public function processLink( $element ) {
        $this->normalizeURL( $element, 'href' );
        $this->removeQueryStringFromInternalLink( $element );
        $this->addDiscoveredURL( $element->getAttribute( 'href' ) );
        $this->rewriteWPPaths( $element );
        $this->rewriteBaseURL( $element );
        $this->convertToRelativeURL( $element );
        $this->convertToOfflineURL( $element );

        if ( isset( $this->settings['removeWPLinks'] ) ) {
            $relativeLinksToRemove = array(
                'shortlink',
                'canonical',
                'pingback',
                'alternate',
                'EditURI',
                'wlwmanifest',
                'index',
                'profile',
                'prev',
                'next',
                'wlwmanifest',
            );

            $link_rel = $element->getAttribute( 'rel' );

            if ( in_array( $link_rel, $relativeLinksToRemove ) ) {
                $element->parentNode->removeChild( $element );
            } elseif ( strpos( $link_rel, '.w.org' ) !== false ) {
                $element->parentNode->removeChild( $element );
            }
        }
    }

    public function isValidURL( $url ) {
        // NOTE: not using native URL filter as it won't accept
        // non-ASCII URLs, which we want to support
        $url = trim( $url );

        if ( $url == '' ) {
            return false;
        }

        if ( strpos( $url, '.php' ) !== false ) {
            return false;
        }

        if ( strpos( $url, ' ' ) !== false ) {
            return false;
        }

        if ( $url[0] == '#' ) {
            return false;
        }

        return true;
    }

    public function addDiscoveredURL( $url ) {
        // trim any query strings or anchors
        $url = strtok( $url, '#' );
        $url = strtok( $url, '?' );

        if ( trim( $url ) === '' ) {
            return;
        }

        if ( isset( $this->harvest_new_URLs ) ) {
            if ( ! $this->isValidURL( $url ) ) {
                return;
            }

            if ( $this->isInternalLink( $url ) ) {
                $discovered_URL_without_site_URL =
                    str_replace(
                        'https://PLACEHOLDER.wpsho',
                        '',
                        $url
                    );

                $this->discovered_urls[] = $discovered_URL_without_site_URL;
            }
        }
    }

    public function processImage( $element ) {
        $this->normalizeURL( $element, 'src' );
        $this->removeQueryStringFromInternalLink( $element );
        $this->addDiscoveredURL( $element->getAttribute( 'src' ) );
        $this->rewriteWPPaths( $element );
        $this->rewriteBaseURL( $element );
        $this->convertToRelativeURL( $element );
        $this->convertToOfflineURL( $element );
    }

    public function stripHTMLComments() {
        if ( isset( $this->settings['removeHTMLComments'] ) ) {
            $xpath = new DOMXPath( $this->xml_doc );

            foreach ( $xpath->query( '//comment()' ) as $comment ) {
                $comment->parentNode->removeChild( $comment );
            }
        }
    }

    public function processHead( $element ) {
        // $this->setBaseHref( $element, 'src' );
        $head_elements = iterator_to_array(
            $element->childNodes
        );

        foreach ( $head_elements as $node ) {
            if ( $node instanceof DOMComment ) {
                if (
                    isset( $this->settings['removeConditionalHeadComments'] )
                ) {
                    $node->parentNode->removeChild( $node );
                }
            } elseif ( $node->tagName === 'base' ) {
                // as smaller iteration to run conditional against here
                $this->base_tag_exists = true;
            }
        }
    }

    public function processScript( $element ) {
        $this->normalizeURL( $element, 'src' );
        $this->removeQueryStringFromInternalLink( $element );
        $this->addDiscoveredURL( $element->getAttribute( 'src' ) );
        $this->rewriteWPPaths( $element );
        $this->rewriteBaseURL( $element );
        $this->convertToRelativeURL( $element );
        $this->convertToOfflineURL( $element );
    }

    public function processAnchor( $element ) {
        $url = $element->getAttribute( 'href' );

        // TODO: DRY this up/move to higher exec position
        // early abort invalid links as early as possible
        // to save overhead/potential errors
        // apply to other functions
        if ( $url[0] === '#' ) {
            return;
        }

        if ( substr( $url, 0, 7 ) == 'mailto:' ) {
            return;
        }

        if ( ! $this->isInternalLink( $url ) ) {
            return;
        }

        $this->normalizeURL( $element, 'href' );
        $this->removeQueryStringFromInternalLink( $element );
        $this->addDiscoveredURL( $url );
        $this->rewriteWPPaths( $element );
        $this->rewriteBaseURL( $element );
        $this->convertToRelativeURL( $element );
        $this->convertToOfflineURL( $element );
    }

    public function processMeta( $element ) {
        // TODO: detect meta redirects here + build list for rewriting
        if ( isset( $this->settings['removeWPMeta'] ) ) {
            $meta_name = $element->getAttribute( 'name' );

            if ( strpos( $meta_name, 'generator' ) !== false ) {
                $element->parentNode->removeChild( $element );

                return;
            }

            if ( strpos( $meta_name, 'robots' ) !== false ) {
                $content = $element->getAttribute( 'content' );

                if ( strpos( $content, 'noindex' ) !== false ) {
                    $element->parentNode->removeChild( $element );
                }
            }
        }

        $url = $element->getAttribute( 'content' );
        $this->normalizeURL( $element, 'content' );
        $this->removeQueryStringFromInternalLink( $element );
        $this->addDiscoveredURL( $url );
        $this->rewriteWPPaths( $element );
        $this->rewriteBaseURL( $element );
        $this->convertToRelativeURL( $element );
        $this->convertToOfflineURL( $element );
    }

    public function writeDiscoveredURLs() {
        if ( $_POST['ajax_action'] === 'crawl_again' ) {
            return;
        }

        if ( defined( 'WP_CLI' ) ) {
            if ( defined( 'CRAWLING_DISCOVERED' ) ) {
                return;
            }
        }

        file_put_contents(
            $this->settings['wp_uploads_path'] .
                '/WP-STATIC-DISCOVERED-URLS.txt',
            PHP_EOL .
                implode( PHP_EOL, array_unique( $this->discovered_urls ) ),
            FILE_APPEND | LOCK_EX
        );

        chmod(
            $this->settings['wp_uploads_path'] .
                '/WP-STATIC-DISCOVERED-URLS.txt',
            0664
        );
    }

    // make link absolute, using current page to determine full path
    public function normalizeURL( $element, $attribute ) {
        $original_link = $element->getAttribute( $attribute );

        if ( $this->isInternalLink( $original_link ) ) {
            $abs = $this->page_url->resolve( $original_link );
            $element->setAttribute( $attribute, $abs );
        }

    }

    public function isInternalLink( $link, $domain = false ) {
        if ( ! $domain ) {
            $domain = $this->placeholder_URL;
        }

        // TODO: apply only to links starting with .,..,/,
        // or any with just a path, like banana.png
        // check link is same host as $this->url and not a subdomain
        $is_internal_link = parse_url( $link, PHP_URL_HOST ) === parse_url(
            $domain,
            PHP_URL_HOST
        );

        return $is_internal_link;
    }

    public function removeQueryStringFromInternalLink( $element ) {
        $attribute_to_change = '';
        $url_to_change = '';

        if ( $element->hasAttribute( 'href' ) ) {
            $attribute_to_change = 'href';
        } elseif ( $element->hasAttribute( 'src' ) ) {
            $attribute_to_change = 'src';
        } elseif ( $element->hasAttribute( 'content' ) ) {
            $attribute_to_change = 'content';
        } else {
            return;
        }

        $url_to_change = $element->getAttribute( $attribute_to_change );

        if ( $this->isInternalLink( $url_to_change ) ) {
            // strip anything from the ? onwards
            // https://stackoverflow.com/a/42476194/1668057
            $element->setAttribute(
                $attribute_to_change,
                strtok( $url_to_change, '?' )
            );
        }
    }

    public function detectEscapedSiteURLs( $processedHTML ) {
        // NOTE: this does return the expected http:\/\/172.18.0.3
        // but your error log may escape again and
        // show http:\\/\\/172.18.0.3
        $escaped_site_url = addcslashes( $this->placeholder_URL, '/' );

        if ( strpos( $processedHTML, $escaped_site_url ) !== false ) {
            return $this->rewriteEscapedURLs(
                $processedHTML,
                $wp_site_env,
                $new_paths
            );
        }

        return $processedHTML;
    }

    public function detectUnchangedURLs( $processedHTML ) {
        $siteURL = $this->placeholder_URL;

        if ( strpos( $processedHTML, $siteURL ) !== false ) {
            return $this->rewriteUnchangedURLs(
                $processedHTML,
                $wp_site_env,
                $new_paths
            );
        }

        return $processedHTML;
    }

    public function rewriteUnchangedURLs( $processedHTML ) {
        // TODO: theme is like /wp-content/themes/twentyseventeen
        // likely already changed... if so, let's skip those rewrites here...
        if ( isset( $this->settings['rewriteWPPaths'] ) ) {
            $rewritten_source = str_replace(
                array(
                    $this->settings['wp_active_theme'],
                    $this->settings['wp_themes'],
                    $this->settings['wp_uploads'],
                    $this->settings['wp_plugins'],
                    $this->settings['wp_content'],
                    $this->settings['wp_inc'],
                    $this->placeholder_URL,
                ),
                array(
                    $this->settings['new_active_theme_path'],
                    $this->settings['new_themes_path'],
                    $this->settings['new_uploads_path'],
                    $this->settings['new_plugins_path'],
                    $this->settings['new_wp_content_path'],
                    $this->settings['new_wpinc_path'],
                    $this->settings['baseUrl'],

                ),
                $processedHTML
            );
        } else {
            $rewritten_source = str_replace(
                array(
                    $this->placeholder_URL,
                ),
                array(
                    $this->settings['baseUrl'],
                ),
                $processedHTML
            );
        }

        return $rewritten_source;
    }

    public function rewriteEscapedURLs( $processedHTML ) {
        /*
        This function will be a bit more costly. To cover bases like:

         data-images="[&quot;https:\/\/mysite.example.com\/wp...
        from the onepress(?) theme, for example

        */
        if ( isset( $this->settings['rewriteWPPaths'] ) ) {
            $rewritten_source = str_replace(
                array(
                    addcslashes( $this->settings['wp_active_theme'], '/' ),
                    addcslashes( $this->settings['wp_themes'], '/' ),
                    addcslashes( $this->settings['wp_uploads'], '/' ),
                    addcslashes( $this->settings['wp_plugins'], '/' ),
                    addcslashes( $this->settings['wp_content'], '/' ),
                    addcslashes( $this->settings['wp_inc'], '/' ),
                    addcslashes( $this->placeholder_URL, '/' ),
                ),
                array(
                    addcslashes(
                        $this->settings['new_active_theme_path'],
                        '/'
                    ),
                    addcslashes( $this->settings['new_themes_path'], '/' ),
                    addcslashes( $this->settings['new_uploads_path'], '/' ),
                    addcslashes( $this->settings['new_plugins_path'], '/' ),
                    addcslashes( $this->settings['new_wp_content_path'], '/' ),
                    addcslashes( $this->settings['new_wpinc_path'], '/' ),
                    addcslashes( $this->settings['baseUrl'], '/' ),

                ),
                $processedHTML
            );
        } else {
            $rewritten_source = str_replace(
                array(
                    addcslashes( $this->placeholder_URL, '/' ),
                ),
                array(
                    addcslashes( $this->settings['baseUrl'], '/' ),
                ),
                $processedHTML
            );
        }

        $rewritten_source = str_replace(
            array(
                addcslashes( $this->settings['wp_active_theme'], '/' ),
                addcslashes( $this->settings['wp_themes'], '/' ),
                addcslashes( $this->settings['wp_uploads'], '/' ),
                addcslashes( $this->settings['wp_plugins'], '/' ),
                addcslashes( $this->settings['wp_content'], '/' ),
                addcslashes( $this->settings['wp_inc'], '/' ),
                addcslashes( $this->placeholder_URL, '/' ),
            ),
            array(
                addcslashes( $this->settings['new_active_theme_path'], '/' ),
                addcslashes( $this->settings['new_themes_path'], '/' ),
                addcslashes( $this->settings['new_uploads_path'], '/' ),
                addcslashes( $this->settings['new_plugins_path'], '/' ),
                addcslashes( $this->settings['new_wp_content_path'], '/' ),
                addcslashes( $this->settings['new_wpinc_path'], '/' ),
                addcslashes( $this->settings['baseUrl'], '/' ),

            ),
            $processedHTML
        );

        return $rewritten_source;
    }

    public function rewriteWPPaths( $element ) {
        if ( ! isset( $this->settings['rewriteWPPaths'] ) ) {
            return;
        }

        $attribute_to_change = '';
        $url_to_change = '';

        if ( $element->hasAttribute( 'href' ) ) {
            $attribute_to_change = 'href';
        } elseif ( $element->hasAttribute( 'src' ) ) {
            $attribute_to_change = 'src';
        } elseif ( $element->hasAttribute( 'content' ) ) {
            $attribute_to_change = 'content';
        } else {
            return;
        }

        $url_to_change = $element->getAttribute( $attribute_to_change );

        if ( $this->isInternalLink( $url_to_change ) ) {
            // rewrite URLs, starting with longest paths down to shortest
            // TODO: is the internal link check needed here or these
            // arr values are already normalized?
            $rewritten_url = str_replace(
                array(
                    $this->settings['wp_active_theme'],
                    $this->settings['wp_themes'],
                    $this->settings['wp_uploads'],
                    $this->settings['wp_plugins'],
                    $this->settings['wp_content'],
                    $this->settings['wp_inc'],
                ),
                array(
                    $this->settings['new_active_theme_path'],
                    $this->settings['new_themes_path'],
                    $this->settings['new_uploads_path'],
                    $this->settings['new_plugins_path'],
                    $this->settings['new_wp_content_path'],
                    $this->settings['new_wpinc_path'],
                ),
                $url_to_change
            );

            $element->setAttribute( $attribute_to_change, $rewritten_url );
        }
    }

    public function getHTML() {
        $processedHTML = $this->xml_doc->saveHtml();

        // process the resulting HTML as text
        $processedHTML = $this->detectEscapedSiteURLs( $processedHTML );
        $processedHTML = $this->detectUnchangedURLs( $processedHTML );

        return $processedHTML;
    }

    public function convertToRelativeURL( $element ) {
        if ( ! isset( $this->settings['useRelativeURLs'] ) ) {
            return;
        }

        if ( $element->hasAttribute( 'href' ) ) {
            $attribute_to_change = 'href';
        } elseif ( $element->hasAttribute( 'src' ) ) {
            $attribute_to_change = 'src';
        } elseif ( $element->hasAttribute( 'content' ) ) {
            $attribute_to_change = 'content';
        } else {
            return;
        }

        $url_to_change = $element->getAttribute( $attribute_to_change );

        $site_root = '';

        // for same server test deploys, we'll need the subdir after root
        if ( isset( $_POST['targetFolder'] ) &&
            $_POST['selected_deployment_option'] === 'folder' ) {
            $site_root .= $_POST['targetFolder'] . '/';
        }

        // check it actually needs to be changed
        if ( $this->isInternalLink(
            $url_to_change,
            $this->settings['baseUrl']
        ) ) {
            $rewritten_url = str_replace(
                $this->settings['baseUrl'],
                $site_root,
                $url_to_change
            );

            $element->setAttribute( $attribute_to_change, $rewritten_url );
        }
    }

    public function getDotsBackToRoot( $url ) {
        $dots_path = '';

        return $dots_path;
    }

    public function convertToOfflineURL( $element ) {
        if ( ! isset( $this->settings['allowOfflineUsage'] ) ) {
            return;
        }

        if ( $element->hasAttribute( 'href' ) ) {
            $attribute_to_change = 'href';
        } elseif ( $element->hasAttribute( 'src' ) ) {
            $attribute_to_change = 'src';
        } elseif ( $element->hasAttribute( 'content' ) ) {
            $attribute_to_change = 'content';
        } else {
            return;
        }

        $url_to_change = $element->getAttribute( $attribute_to_change );
        $current_page_path_to_root = '';
        $current_page_path = parse_url( $this->page_url, PHP_URL_PATH );
        $number_of_segments_in_path = explode( '/', $current_page_path );
        $num_dots_to_root = count( $number_of_segments_in_path ) - 2;

        for ( $i = 0; $i < $num_dots_to_root; $i++ ) {
            $current_page_path_to_root .= '../';
        }

        if ( $this->isInternalLink(
            $url_to_change,
            $this->settings['baseUrl']
        ) ) {
            $rewritten_url = str_replace(
                $this->settings['baseUrl'],
                '',
                $url_to_change
            );

            $offline_url = $current_page_path_to_root . $rewritten_url;

            // add index.html if no extension
            if ( substr( $offline_url, -1 ) === '/' ) {
                // TODO: check XML/RSS case
                $offline_url .= 'index.html';
            }

            $element->setAttribute( $attribute_to_change, $offline_url );
        }
    }

    // NOTE: separate from WP rewrites in case people have disabled that
    public function rewriteBaseURL( $element ) {
        if ( $element->hasAttribute( 'href' ) ) {
            $attribute_to_change = 'href';
        } elseif ( $element->hasAttribute( 'src' ) ) {
            $attribute_to_change = 'src';
        } elseif ( $element->hasAttribute( 'content' ) ) {
            $attribute_to_change = 'content';
        } else {
            return;
        }

        $url_to_change = $element->getAttribute( $attribute_to_change );

        // check it actually needs to be changed
        if ( $this->isInternalLink( $url_to_change ) ) {
            $rewritten_url = str_replace(
                // TODO: test this won't touch subdomains, shouldn't
                $this->placeholder_URL,
                $this->settings['baseUrl'],
                $url_to_change
            );

            $element->setAttribute( $attribute_to_change, $rewritten_url );
        }
    }

    public function rewriteSiteURLsToPlaceholder() {
        $rewritten_source = str_replace(
            array(
                $this->settings['wp_site_url'],
                addcslashes( $this->settings['wp_site_url'], '/' ),
            ),
            array(
                $this->placeholder_URL,
                addcslashes( $this->placeholder_URL, '/' ),
            ),
            $this->raw_html
        );

        $this->raw_html = $rewritten_source;

    }
}

