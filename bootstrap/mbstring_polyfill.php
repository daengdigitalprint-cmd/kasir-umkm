<?php

if (! function_exists('mb_split')) {
    function mb_split($pattern, $string, $limit = -1)
    {
        if ($limit === -1) {
            return preg_split('/'.preg_quote($pattern, '/').'/u', $string);
        }

        return preg_split('/'.preg_quote($pattern, '/').'/u', $string, $limit);
    }
}

if (! function_exists('mb_strlen')) {
    function mb_strlen($string, $encoding = null)
    {
        return strlen($string);
    }
}

if (! function_exists('mb_substr')) {
    function mb_substr($string, $start, $length = null, $encoding = null)
    {
        if ($length === null) {
            return substr($string, $start);
        }

        return substr($string, $start, $length);
    }
}

if (! function_exists('mb_strtoupper')) {
    function mb_strtoupper($string, $encoding = null)
    {
        return strtoupper($string);
    }
}

if (! function_exists('mb_strtolower')) {
    function mb_strtolower($string, $encoding = null)
    {
        return strtolower($string);
    }
}

if (! function_exists('mb_convert_case')) {
    function mb_convert_case($string, $mode, $encoding = null)
    {
        switch ($mode) {
            case MB_CASE_UPPER:
                return strtoupper($string);
            case MB_CASE_LOWER:
                return strtolower($string);
            case MB_CASE_TITLE:
                return ucwords(strtolower($string));
            default:
                return $string;
        }
    }
}

// PDO constants polyfill if extension is not available
if (! extension_loaded('pdo')) {
    if (! defined('PDO::FETCH_OBJ')) {
        define('PDO::FETCH_DEFAULT', 0);
        define('PDO::FETCH_LAZY', 1);
        define('PDO::FETCH_ASSOC', 2);
        define('PDO::FETCH_NUM', 3);
        define('PDO::FETCH_BOTH', 4);
        define('PDO::FETCH_OBJ', 5);
        define('PDO::FETCH_BOUND', 6);
        define('PDO::FETCH_COLUMN', 7);
        define('PDO::FETCH_CLASS', 8);
        define('PDO::FETCH_INTO', 9);
        define('PDO::FETCH_FUNC', 10);
        define('PDO::FETCH_GROUP', 65536);
        define('PDO::FETCH_UNIQUE', 196608);
        define('PDO::FETCH_KEY_PAIR', 12);
        define('PDO::FETCH_CLASSTYPE', 262144);
        define('PDO::FETCH_SERIALIZE', 524288);
        define('PDO::FETCH_PROPS_LATE', 1048576);
        define('PDO::FETCH_NAMED', 11);

        define('PDO::ERRMODE_SILENT', 0);
        define('PDO::ERRMODE_WARNING', 1);
        define('PDO::ERRMODE_EXCEPTION', 2);

        define('PDO::ATTR_AUTOCOMMIT', 0);
        define('PDO::ATTR_PREFETCH', 1);
        define('PDO::ATTR_TIMEOUT', 2);
        define('PDO::ATTR_ERRMODE', 3);
        define('PDO::ATTR_SERVER_VERSION', 4);
        define('PDO::ATTR_CLIENT_VERSION', 5);
        define('PDO::ATTR_SERVER_INFO', 6);
        define('PDO::ATTR_CONNECTION_STATUS', 7);
        define('PDO::ATTR_CASE', 8);
        define('PDO::ATTR_CURSOR_NAME', 9);
        define('PDO::ATTR_CURSOR', 10);
        define('PDO::ATTR_ORACLE_NULLS', 11);
        define('PDO::ATTR_PERSISTENT', 12);
        define('PDO::ATTR_STATEMENT_CLASS', 13);
        define('PDO::ATTR_FETCH_TABLE_NAMES', 14);
        define('PDO::ATTR_FETCH_CATALOG_NAMES', 15);
        define('PDO::ATTR_DRIVER_NAME', 16);
        define('PDO::ATTR_STRINGIFY_FETCHES', 17);
        define('PDO::ATTR_MAX_COLUMN_LEN', 18);
        define('PDO::ATTR_EMULATE_PREPARES', 20);
        define('PDO::ATTR_DEFAULT_FETCH_MODE', 19);
    }
}
