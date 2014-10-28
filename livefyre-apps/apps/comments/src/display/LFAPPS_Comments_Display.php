<?php
use Livefyre\Livefyre;

class LFAPPS_Comments_Display {

    /*
     * Designates what Livefyre's widget is binding to.
     *
     */
    function __construct( $lf_core ) {
        
        if (LFAPPS_Comments::comments_active()) {
            //add_action( 'wp_enqueue_scripts', array( &$this, 'load_strings' ) );
            add_action( 'wp_footer', array( &$this, 'lf_init_script' ) );
            
            // Set comments_template filter to maximum value to always override the default commenting widget
            
            add_filter( 'comments_template', array( &$this, 'livefyre_comments_template' ), $this->lf_widget_priority() );
            add_filter( 'comments_number', array( &$this, 'livefyre_comments_number' ), 10, 2 );
            
            add_shortcode('livefyre_livecomments', array('LFAPPS_Comments_Display', 'init_shortcode'));
        }
    
    }

    /*
     * Helper function to test if comments shouldn't be displayed.
     *
     */
    function livefyre_comments_off() {
    
        return ( Livefyre_Apps::get_option( 'livefyre_site_id', '' ) == '' || Livefyre_Apps::get_option( 'livefyre_site_key', '') == '');

    }

    /*
     * Gets the Livefyre priority.
     *
     */
    function lf_widget_priority() {

        return intval( get_option( 'livefyre_widget_priority', 99 ) );

    }
        
    /*
     * Builds the Livefyre JS code that will build the conversation and load it onto the page. The
     * bread and butter of the whole plugin.
     *
     */
    function lf_init_script() {
    /*  Reset the query data because theme code might have moved the $post gloabl to point 
        at different post rather than the current one, which causes our JS not to load properly. 
        We do this in the footer because the wp_footer() should be the last thing called on the page.
        We don't do it earlier, because it might interfere with what the theme code is trying to accomplish.  */
        wp_reset_query();
        
        global $post, $current_user, $wp_query;
        if ( comments_open() && self::livefyre_show_comments() ) {   // is this a post page?
            Livefyre_Apps::init_auth();
            
            $network = Livefyre_Apps::get_option( 'livefyre_domain_name', 'livefyre.com' );
            $network = ( $network == '' ? 'livefyre.com' : $network );
        
            $siteId = Livefyre_Apps::get_option( 'livefyre_site_id' );
            $siteKey = Livefyre_Apps::get_option( 'livefyre_site_key' );
            $network_key = Livefyre_Apps::get_option( 'livefyre_domain_key', '');
            $post = get_post();
            $articleId = get_the_ID();
            $title = get_the_title($articleId);
            $url = get_permalink($articleId);
            $tags = array();
            $posttags = get_the_tags( $wp_query->post->ID );
            if ( $posttags ) {
                foreach( $posttags as $tag ) {
                    array_push( $tags, $tag->name );
                }
            }
            
            $network = Livefyre::getNetwork($network, strlen($network_key) > 0 ? $network_key : null);            
            $site = $network->getSite($siteId, $siteKey);
            
            $collectionMetaToken = $site->buildCollectionMetaToken($title, $articleId, $url, array("tags"=>$tags, "type"=>"livecomments"));
            $checksum = $site->buildChecksum($title, $url, $tags);
            
            $strings = null;
            if ( Livefyre_Apps::get_option( 'livefyre_language', 'English') != 'English' ) {
                $strings = 'customStrings';
            }
            
            $livefyre_element = 'livefyre-comments';
            $display_template = false;
            LFAPPS_View::render_partial('script', 
                    compact('siteId', 'siteKey', 'network', 'articleId', 'collectionMetaToken', 'checksum', 'strings', 'livefyre_element', 'display_template'), 
                    'comments');   
            
            $ccjs = '//cdn.livefyre.com/libs/commentcount/v1.0/commentcount.js';
            echo '<script type="text/javascript" data-lf-domain="' . esc_attr( $network->getName() ) . '" id="ncomments_js" src="' . esc_attr( $ccjs ) . '"></script>';
            
        }
    }

    /*
     * Debug script that will point customers to what could be potential issues.
     *
     */
    function lf_debug() {
        return false;
        global $post;
        $post_type = get_post_type( $post );
        $article_id = $post->ID;
        $site_id = Livefyre_Apps::get_option( 'livefyre_site_id', '' );
        $display_posts = Livefyre_Apps::get_option( 'livefyre_display_posts', 'true' );
        $display_pages = Livefyre_Apps::get_option( 'livefyre_display_pages', 'true' );
        echo "\n";
        ?>
            <!-- LF DEBUG
            site-id: <?php echo esc_html($site_id) . "\n"; ?>
            article-id: <?php echo esc_html($article_id) . "\n"; ?>
            post-type: <?php echo esc_html($post_type) . "\n"; ?>
            comments-open: <?php echo esc_html(comments_open() ? "true\n" : "false\n"); ?>
            is-single: <?php echo is_single() ? "true\n" : "false\n"; ?>
            display-posts: <?php echo esc_html($display_posts) . "\n"; ?>
            display-pages: <?php echo esc_html($display_pages) . "\n"; ?>
            -->
        <?php
        
    }

    /*
     * The template for the Livefyre div element.
     *
     */
    function livefyre_comments_template( ) {
        return dirname( __FILE__ ) . '/comments-template.php';        
    }

    /*
     * Handles the toggles on the settings page that decide which post types should be shown.
     * Also prevents comments from appearing on non single items and previews.
     *
     */
    public static function livefyre_show_comments() {
        
        global $post;
        /* Is this a post and is the settings checkbox on? */
        $display_posts = ( is_single() && Livefyre_Apps::get_option( 'livefyre_display_post','true') == 'true' );
        /* Is this a page and is the settings checkbox on? */
        $display_pages = ( is_page() && Livefyre_Apps::get_option( 'livefyre_display_page','true') == 'true' );
        /* Are comments open on this post/page? */
        $comments_open = ( $post->comment_status == 'open' );

        $display = $display_posts || $display_pages;
        $post_type = get_post_type();
        if ( $post_type != 'post' && $post_type != 'page' ) {
            
            $post_type_name = 'livefyre_display_' .$post_type;            
            $display = ( Livefyre_Apps::get_option( $post_type_name, 'true' ) == 'true' );
        }

        return $display
            && !is_preview()
            && $comments_open;

    }

    /*
     * Build the Livefyre comment count variable.
     *
     */
    function livefyre_comments_number( $count ) {

        global $post;
        return '<span data-lf-article-id="' . esc_attr($post->ID) . '" data-lf-site-id="' . esc_attr(Livefyre_Apps::get_option( 'livefyre_site_id', '' )) . '" class="livefyre-commentcount">'.esc_html($count).'</span>';

    }

    /*
     * Loads in JS variable to enable the widget to be internationalized.
     *
     */
    function load_strings() {

        $language = Livefyre_Apps::get_option( 'livefyre_language', 'English' );
        
        $lang_file = LFAPPS__PLUGIN_URL . "apps/comments/languages/" . $language . '.js';
        wp_enqueue_script( 'livefyre-lang-js', esc_url( $lang_file ) );

    }
    
    /**
     * Run shortcode [livecomments]
     * @param array $atts array of attributes passed to shortcode
     */
    public static function init_shortcode($atts=array()) {
        if(isset($atts['article_id'])) {
            $articleId = $atts['article_id'];
            $title = isset($pagename) ? $pagename : 'LiveComments (ID: ' . $atts['article_id'];
            global $wp;
            $url = add_query_arg( $_SERVER['QUERY_STRING'], '', home_url( $wp->request ) );
            $tags = array();
        } else {
            global $post;
            if(get_the_ID() !== false) {
                $articleId = $post->ID;
                $title = get_the_title($articleId);
                $url = get_permalink($articleId);
                $tags = array();
                $posttags = get_the_tags( $post->ID );
                if ( $posttags ) {
                    foreach( $posttags as $tag ) {
                        array_push( $tags, $tag->name );
                    }
                }
            } else {
                return;
            }
        }
        Livefyre_Apps::init_auth();
        $network = Livefyre_Apps::get_option( 'livefyre_domain_name', 'livefyre.com' );
        $network = ( $network == '' ? 'livefyre.com' : $network );

        $siteId = Livefyre_Apps::get_option( 'livefyre_site_id' );
        $siteKey = Livefyre_Apps::get_option( 'livefyre_site_key' );
        $network_key = Livefyre_Apps::get_option( 'livefyre_domain_key', '');
        
        $network = Livefyre::getNetwork($network, strlen($network_key) > 0 ? $network_key : null);            
        $site = $network->getSite($siteId, $siteKey);

        $collectionMetaToken = $site->buildCollectionMetaToken($title, $articleId, $url, array("tags"=>$tags, "type"=>"livecomments"));
        $checksum = $site->buildChecksum($title, $url, $tags);

        $strings = null;
        if ( Livefyre_Apps::get_option( 'livefyre_language', 'English') != 'English' ) {
            $strings = 'customStrings';
        }

        $livefyre_element = 'livefyre-comments-'.$articleId;
        $display_template = true;
        return LFAPPS_View::render_partial('script', 
                compact('siteId', 'siteKey', 'network', 'articleId', 'collectionMetaToken', 'checksum', 'strings', 'livefyre_element', 'display_template'), 
                'comments', true);   
    }    
}