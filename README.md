# [VIP] Full Page Cache Helper

A lightweight, namespace-scoped full-page caching layer for WordPress.  
This plugin captures the final HTML output of a request, stores it in the object cache,  
and serves it instantly to subsequent visitors.

It automatically determines the "page type" (singular, front page, home, taxonomy archive, author archive)  
and generates unique cache keys per context.  
Admins may invalidate all cached pages through the WP admin settings or bypass caching entirely via a URL parameter.

---

## Features

- **Full-page output caching** using WordPress' object cache.
- **Automatic page-type normalization** to generate stable cache keys:
  - Singular posts, pages, CPTs  
  - Front page  
  - Blog home (posts index)  
  - Category, tag, and custom taxonomy archives  
  - Author archives  
- **Admin invalidation UI**: a checkbox in *Settings → Reading* invalidates all cached pages.
- **Versioned cache keys** using a timestamp option.
- **Admin-only bypass mode** using a URL flag for debugging.
- **Safe request detection**: caching never activates for admin, AJAX, or JSON requests.
- **Zero global variables**: everything is namespaced and self-contained.

---

## How it Works

1. During the `wp` action, the plugin determines whether the request is eligible for caching.
2. If allowed, caching is engaged:
   - Before templates load: `cache_start()` checks for an existing cached version.
   - After output finishes: `cache_end()` saves the generated HTML.
3. Cache keys are generated from:
   - Page type  
   - Page ID (post, term, user, or zero)  
   - Cache version (admin-controlled)
4. If a cached entry is found, output is sent immediately and execution stops.

---

## Using the Filter: `vip_pagecache_helper_should_cache`

Developers can disable caching for specific page types, IDs, or contexts.

### Example: Prevent caching of search results, 404 pages, and a specific page ID

```php
add_filter( 'vip_pagecache_helper_should_cache', function( $should_cache, $page_id, $page_type ) {

    // Disable caching for search queries and 404 pages
    if ( is_search() || is_404() ) {
        return false;
    }

    // Disable caching for a specific page (example: page ID 42)
    if ( $page_id === 42 && $page_type === 'page' ) {
        return false;
    }

    // Disable caching for author archives
    if ( $page_type === 'author' ) {
        return false;
    }

    // Otherwise keep the default behavior
    return $should_cache;

}, 10, 3 );
```

### Example: Only cache singular posts and the front page

```php
add_filter( 'vip_pagecache_helper_should_cache', function( $should_cache, $page_id, $page_type ) {

    $allowed = [ 'post', 'front_page' ];

    if ( in_array( $page_type, $allowed, true ) ) {
        return true;
    }

    return false;

}, 10, 3 );
```

## Admin Cache Invalidation

Go to:

**Settings → Reading → “[VIP] PageCache Helper”**

Check the box and save to increment the cache version timestamp.  
All existing full-page cache entries instantly become stale.

---

## Admin Debug Bypass

Admins with `manage_options` capability can bypass the cache by appending a query param:

```
https://example.com/page/?VIP_PAGEOBJECTCACHE_BYPASS=1
```

This forces a fresh uncached render while still allowing the cache to be populated for normal visitors.

---

## Requirements

- WordPress 6.0+
- PHP 8.0+
- Persistent object cache (Redis, Memcached, or equivalent)

---

## Notes

- Unsupported views such as search, 404, and date archives do not participate in caching unless added via filter.
- Caching only activates when the request passes all internal checks and the `vip_pagecache_helper_should_cache` filter.
- Cache entries expire after one hour by default and are invalidated globally when the admin resets the cache version.

---

## License

GPL-2.0-or-later  
© Automattic
