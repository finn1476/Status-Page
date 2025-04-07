<?php
/**
 * Custom Domain Helper
 * 
 * This file provides functions to handle custom domains and ensure
 * they're preserved in internal links and redirects
 */

/**
 * Get the current domain, preserving any custom domain from the request
 * 
 * @return string The domain to use in links and redirects
 */
function get_current_domain() {
    // If this is a custom domain request, use the original domain
    if (isset($_SERVER['ORIG_HTTP_HOST'])) {
        return $_SERVER['ORIG_HTTP_HOST'];
    }
    
    // Otherwise, use the normal HTTP_HOST
    return $_SERVER['HTTP_HOST'];
}

/**
 * Create an absolute URL that preserves the current domain
 * 
 * @param string $path The path part of the URL (e.g., /status_page.php)
 * @param array $params Optional query parameters as key-value pairs
 * @return string The complete URL
 */
function make_url($path, $params = []) {
    $domain = get_current_domain();
    $protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http';
    $url = $protocol . '://' . $domain . $path;
    
    // Add query parameters if provided
    if (!empty($params)) {
        $query = http_build_query($params);
        $url .= (strpos($path, '?') === false) ? '?' . $query : '&' . $query;
    }
    
    return $url;
}

/**
 * Redirect to a URL while preserving the custom domain
 * 
 * @param string $path The path to redirect to
 * @param array $params Optional query parameters
 * @param int $status HTTP status code for the redirect
 */
function domain_redirect($path, $params = [], $status = 302) {
    $url = make_url($path, $params);
    
    // Set appropriate status code
    if ($status === 301) {
        header('HTTP/1.1 301 Moved Permanently');
    } else {
        header('HTTP/1.1 302 Found');
    }
    
    header('Location: ' . $url);
    exit;
}
?> 