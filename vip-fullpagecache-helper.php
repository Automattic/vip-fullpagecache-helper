<?php
/**
 * Plugin Name:       [VIP] Full Page Cache Helper
 * Plugin URI:        https://github.com/Automattic/vip-fullpagecache-helper
 * Description:       Full-page output caching with automatic page-type detection, cache versioning, and flexible per-request enable/disable control.
 * Version:           1.0.0
 * Author:            Automattic
 * Author URI:        https://automattic.com
 * License:           GPL-2.0-or-later
 * License URI:       https://www.gnu.org/licenses/gpl-2.0.html
 * Requires at least: 6.0
 * Tested up to:      6.8
 * Requires PHP:      8.0
 */

namespace VIP_PageCache_Helper {

    /**
     * Bootstrap: decide on `wp` whether to enable full-page caching for this request.
     *
     * If allowed, hooks cache_start() on `template_redirect` and cache_end() on `shutdown`.
     */
    \add_action( 'wp', '\VIP_PageCache_Helper\maybe_enable_caching', 0 );

    /**
     * Conditionnally enables full-page caching for the current request.
     *
     * - Skips admin, AJAX, JSON requests.
     * - Asks Cache\get_page_type() for a normalized page identifier.
     * - Runs `vip_pagecache_helper_should_cache` filter for external control.
     * - If allowed, wires cache_start/cache_end into the request lifecycle.
     *
     * @return void
     */
    function maybe_enable_caching() {
        if ( \is_admin() || \wp_doing_ajax() || \wp_is_json_request() ) {
            return;
        }

        $page = \VIP_PageCache_Helper\Cache\get_page_type();
        if ( null === $page['id'] || null === $page['type'] ) {
            return;
        }

        /**
         * Filter: decide whether this request should be full-page cached.
         *
         * @param bool   $should_cache Default true.
         * @param int    $page_id      Normalized page identifier (post/term/user ID or 0).
         * @param string $page_type    Normalized type (post type, 'front_page', 'home', taxonomy, 'author').
         */
        $should_cache = \apply_filters(
            'vip_pagecache_helper_should_cache',
            true,
            $page['id'],
            $page['type']
        );

        if ( ! $should_cache ) {
            return;
        }

        // Start caching before the template is loaded.
        \add_action(
            'template_redirect',
            function() {
                $page = \VIP_PageCache_Helper\Cache\get_page_type();
                if ( null === $page['id'] || null === $page['type'] ) {
                    return;
                }

                did_cache_start( true );
                \VIP_PageCache_Helper\Cache\cache_start();
            },
            0
        );

        // Finish caching at shutdown (after all output has been generated).
        \add_action(
            'shutdown',
            function() {
                $page = \VIP_PageCache_Helper\Cache\get_page_type();
                if ( null === $page['id'] || null === $page['type'] ) {
                    return;
                }

                if ( ! did_cache_start() ) {
                    return;
                }

                \VIP_PageCache_Helper\Cache\cache_end();
            },
            0
        );
    }

    /**
     * Stores and returns a boolean indicating whether caching has started for this request.
     *
     * Acts as a simple in-memory flag:
     * - Call with true once to mark that cache_start() ran.
     * - Call with null (default) to read the current state.
     *
     * @param bool|null $set Pass true to mark as started. Null to only read.
     * @return bool          True if caching has been marked as started.
     */
    function did_cache_start( ?bool $set = null ): bool {
        static $started = false;

        if ( $set === true ) {
            $started = true;
        }

        return $started;
    }

}

// Caching functions
namespace VIP_PageCache_Helper\Cache {

    const GROUP      = 'VIP';
    const NAME       = '_vip_fpg_';
    const BYPASS_KEY = 'VIP_PAGEOBJECTCACHE_BYPASS';

    /**
     * Entry point for full-page caching.
     *
     * - If a cached entry exists for the current page type, outputs and exits.
     * - Otherwise, starts output buffering to capture the final HTML.
     *
     * @return void
     */
    function cache_start() {
        $cached = get();

        if ( ! empty( $cached ) ) {
            echo $cached;
            exit;
        }

        \ob_start();
    }

    /**
     * Ends output buffering and stores the final HTML in cache.
     *
     * - Grabs the current output buffer contents.
     * - Writes them to the object cache using a normalized key.
     * - Outputs the captured content.
     *
     * @return void
     */
    function cache_end() {
        $template = \ob_get_contents();
        \ob_end_clean();

        set( $template );
        echo $template;
    }

    /**
     * Returns a normalized "page type" describing the current main query.
     *
     * Supported contexts:
     * - Singular posts/pages/CPTs  → [ 'id' => post ID, 'type' => post_type ]
     * - Front page                 → [ 'id' => 0,       'type' => 'front_page' ]
     * - Blog home (posts index)    → [ 'id' => 0,       'type' => 'home' ]
     * - Taxonomy archives          → [ 'id' => term_id, 'type' => taxonomy ]
     * - Author archives            → [ 'id' => user ID, 'type' => 'author' ]
     *
     * Unsupported contexts (search, 404, date archives, etc.) return [ 'id' => null, 'type' => null ].
     *
     * The values are cached in statics for repeated calls within a request.
     *
     * @return array{ id:int|null, type:string|null }
     */
    function get_page_type() {
        static $page_type = null;
        static $page_id   = null;

        if ( null === $page_type || null === $page_id ) {
            if ( \is_singular() ) {
                $page_id   = \get_queried_object_id();
                $page_type = \get_post_type( $page_id );
            } elseif ( \is_front_page() ) {
                $page_id   = 0;
                $page_type = 'front_page';
            } elseif ( \is_home() ) {
                $page_id   = 0;
                $page_type = 'home';
            } elseif ( \is_category() || \is_tag() || \is_tax() ) {
                $term      = \get_queried_object();
                $page_id   = $term->term_id;
                $page_type = $term->taxonomy;
            } elseif ( \is_author() ) {
                $page_id   = \get_queried_object_id();
                $page_type = 'author';
            }
        }

        return [
            'id'   => $page_id,
            'type' => $page_type,
        ];
    }

    /**
     * Generates a cache key for the current page type and version.
     *
     * Base format: NAME . "{$type}_{$id}" . "_{$version}" (if version > 1)
     *
     * @return string Cache key.
     */
    function get_key() {
        $page = get_page_type();
        $key  = NAME . $page['type'] . '_' . $page['id'];

        $cache_var = \VIP_PageCache_Helper\Admin\get_cache_version();
        if ( $cache_var > 1 ) {
            $key .= '_' . $cache_var;
        }

        return $key;
    }

    /**
     * Fetches the cached content for the current page, if any.
     *
     * - Skips cache if admin bypass is active.
     * - Returns null on miss or bypass.
     *
     * @return string|null
     */
    function get() {
        if ( is_insane_admin_bypass() ) {
            return null;
        }

        $raw = \wp_cache_get(
            get_key(),
            GROUP
        );

        return \maybe_unserialize( $raw );
    }

    /**
     * Stores the given content in the cache for the current page key.
     *
     * @param string $content Full HTML output of the page.
     * @return void
     */
    function set( $content ) {
        \wp_cache_set(
            get_key(),
            $content,
            GROUP,
            HOUR_IN_SECONDS
        );
    }

    /**
     * Returns true if the current request should bypass the full-page cache.
     *
     * - Only admins (`manage_options`) can bypass.
     * - Bypass is triggered via a GET parameter matching BYPASS_KEY.
     *
     * @return bool
     */
    function is_insane_admin_bypass() {
        if (
            \current_user_can( 'manage_options' )
            && isset( $_GET[ BYPASS_KEY ] )
        ) {
            return true;
        }
        return false;
    }

}

// Admin functions
namespace VIP_PageCache_Helper\Admin {

    const DOMAIN               = 'vip-pagecache-helper';
    // Option name holding a timestamp used as a cache version for invalidation.
    const PAGECACHE_VERSION_NAME = 'vip_cache_version';

    /**
     * Registers admin UI on `admin_init`.
     *
     * @return void
     */
    \add_action( 'admin_init', function() {
        ui_register_enable_option();
    } );

    /**
     * Returns the current full-page cache version.
     *
     * Stored as an integer timestamp in the PAGECACHE_VERSION_NAME option.
     *
     * @return int
     */
    function get_cache_version() {
        return (int) \get_option( PAGECACHE_VERSION_NAME, 1 );
    }

    /**
     * Registers the "Invalidate all Full Page Cache" checkbox on the Reading settings screen.
     *
     * - Field name is PAGECACHE_VERSION_NAME.
     * - When checked, it stores the current time() as the option value.
     * - That timestamp is then used as a cache version suffix in keys.
     *
     * @return void
     */
    function ui_register_enable_option() {

        \add_settings_field(
            \VIP_PageCache_Helper\Admin\PAGECACHE_VERSION_NAME,
            \esc_html__( '[VIP] PageCache Helper', DOMAIN ),
            function() { ?>
                <label>
                    <input type="checkbox"
                           name="<?php echo \esc_attr( \VIP_PageCache_Helper\Admin\PAGECACHE_VERSION_NAME ); ?>"
                           value="<?php echo \esc_attr( time() ); ?>">
                    <?php \esc_html_e( 'Invalidate all Full Page Cache.', DOMAIN ); ?>
                </label>
                <label>
                    <span><?php \esc_html_e( 'Current Version is:', DOMAIN ); ?></span>
                    <strong><?php echo \esc_html( \VIP_PageCache_Helper\Admin\get_cache_version() ); ?></strong>
                </label>
                <?php
            },
            'reading'
        );

        \register_setting(
            'reading',
            \VIP_PageCache_Helper\Admin\PAGECACHE_VERSION_NAME,
            [
                'type'              => 'integer',
                'sanitize_callback' => 'absint',
                'default'           => 1,
            ]
        );
    }

}
