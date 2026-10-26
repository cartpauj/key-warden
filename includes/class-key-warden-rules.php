<?php
if (!defined('ABSPATH')) exit;

/**
 * Route rules. One rule per line:
 *
 *   /wp/v2/posts*            any method
 *   GET /wp/v2/*             one method
 *   GET,POST /wc/v3/orders*  several methods
 *   # comment
 *
 * Patterns are REST routes (no /wp-json prefix). "*" matches anything, including "/".
 */
class Key_Warden_Rules {
    const METHODS = array('GET', 'POST', 'PUT', 'PATCH', 'DELETE');

    /**
     * Turn textarea input into clean rule lines. Invalid lines are dropped and reported.
     *
     * @return array array(string[] $rules, string[] $invalid)
     */
    public static function sanitize_lines($text) {
        $rules = array();
        $invalid = array();
        foreach (preg_split('/\r\n|\r|\n/', (string) $text) as $line) {
            $line = trim($line);
            if ($line === '') continue;
            if ($line[0] === '#') {
                $rules[] = sanitize_text_field($line);
                continue;
            }
            $parsed = self::parse($line);
            if (!$parsed) {
                $invalid[] = $line;
                continue;
            }
            $rules[] = self::format($parsed);
        }
        return array(array_values(array_unique($rules)), $invalid);
    }

    /**
     * @return array|null array('methods' => string[], 'pattern' => string)
     */
    public static function parse($line) {
        $line = trim($line);
        if ($line === '' || $line[0] === '#') return null;

        $parts = preg_split('/\s+/', $line, 2);
        if (count($parts) === 1) {
            $methods = array('*');
            $pattern = $parts[0];
        } else {
            $methods = array_filter(array_map('trim', explode(',', strtoupper($parts[0]))));
            $pattern = trim($parts[1]);
            foreach ($methods as $m) {
                if ($m !== '*' && !in_array($m, self::METHODS, true)) return null;
            }
            if (!$methods) return null;
        }

        // Accept full "/wp-json/..." paths too and strip the prefix.
        $pattern = preg_replace('#^/?' . preg_quote(rest_get_url_prefix(), '#') . '(?=/|$)#', '', $pattern);
        if ($pattern === '' || $pattern[0] !== '/') $pattern = '/' . ltrim($pattern, '/');
        if (preg_match('/\s/', $pattern)) return null;

        if (in_array('*', $methods, true)) $methods = array('*');
        return array('methods' => array_values($methods), 'pattern' => $pattern);
    }

    public static function format(array $parsed) {
        $methods = $parsed['methods'] === array('*') ? '' : implode(',', $parsed['methods']) . ' ';
        return $methods . $parsed['pattern'];
    }

    public static function matches(array $parsed, $method, $route) {
        $method = strtoupper($method);
        if ($method === 'HEAD' || $method === 'OPTIONS') $method = 'GET';
        if ($parsed['methods'] !== array('*') && !in_array($method, $parsed['methods'], true)) return false;

        $route = '/' . trim($route, '/');
        $pattern = $parsed['pattern'] === '/' ? '/' : rtrim($parsed['pattern'], '/');
        $regex = '#^' . str_replace('\*', '.*', preg_quote($pattern, '#')) . '/?$#is';
        $result = preg_match($regex, $route);
        return $result === false ? null : (bool) $result; // null = the regex engine gave up.
    }

    /**
     * First rule in the list that matches, null if none, or false if matching failed.
     */
    public static function first_match(array $rules, $method, $route) {
        foreach ($rules as $line) {
            $parsed = self::parse($line);
            if (!$parsed) continue;
            $match = self::matches($parsed, $method, $route);
            if ($match === null) return false;
            if ($match) return $line;
        }
        return null;
    }

    /**
     * Every REST route on the site, grouped by namespace, with regex placeholders turned into *.
     *
     * @return array namespace => string[] routes
     */
    public static function site_routes() {
        $server = rest_get_server();
        $namespaces = $server->get_namespaces();
        $by_ns = array();
        foreach (array_keys($server->get_routes()) as $route) {
            $ns = '';
            foreach ($namespaces as $candidate) {
                if (strpos($route, '/' . $candidate) === 0 && strlen($candidate) > strlen($ns)) $ns = $candidate;
            }
            if ($ns === '' || $route === '/' . $ns) continue;
            $by_ns[$ns][] = preg_replace('/\(\?P<[^>]+>.*?\)(?=\/|$)/', '*', $route);
        }
        ksort($by_ns);
        return array_map(function ($routes) {
            return array_values(array_unique($routes));
        }, $by_ns);
    }

    /**
     * Is this request allowed under a profile?
     *
     * @return array array(bool $allowed, string|null $matched_rule)
     */
    public static function check(array $profile, $method, $route) {
        $rule = self::first_match($profile['rules'], $method, $route);
        if ($rule === false) return array(false, null); // Fail closed if matching broke.
        if ($profile['mode'] === 'allowlist') return array($rule !== null, $rule);
        return array($rule === null, $rule);
    }
}
