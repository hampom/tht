<?php

namespace o;

// All sensitive operations in one place, for easier auditing

class Security {

    static private $CSP_NONCE = '';
    static private $CSRF_TOKEN_LENGTH = 64;
    static private $NONCE_LENGTH = 40;

    static private $SESSION_ID_LENGTH = 48;
    static private $SESSION_COOKIE_DURATION = 0;  // until browser is closed

    static private $THROTTLE_POST_SECS = 1;

    static private $isCrossOrigin = null;
    static private $isCsrfTokenValid = null;
    static private $isPostRequestValidated = false;
    static private $isAdmin = false;

    static private $prevHash = null;

    static private $PHP_BLACKLIST_MATCH = '/pcntl_|posix_|proc_|ini_|mysql|sqlite/i';

    static private $PHP_BLACKLIST = [
        'assert',
        'call_user_func',
        'call_user_func_array',
        'create_function',
        'dl',
        'eval',
        'exec',
        'extract',
        'file',
        'file_get_contents',
        'file_put_contents',
        'fopen',
        'include',
        'include_once',
        'parse_str',
        'passthru',
        'phpinfo',
        'popen',
        'require',
        'require_once',
        'rmdir',
        'serialize',
        'shell_exec',
        'system',
        'unlink',
        'unserialize',
        'url_exec',
    ];

    static function error($msg) {
        ErrorHandler::addOrigin('security');
        Tht::error($msg);
    }

    // TODO: allow multiple IPs (list in app.jcon)
    static function isAdmin() {
        $ip = Tht::getPhpGlobal('server', 'REMOTE_ADDR');
        $adminIp = Tht::getConfig('adminIp');
        $isWhitelistedIp = $adminIp && $adminIp == $ip;
        if ($isWhitelistedIp || Tht::isMode('testServer') || $ip == '127.0.0.1') {
            return true;
        }
        return false;
    }

    // Print sensitive data.  Only print to log if not in Admin mode.
    static function safePrint($data) {
        if (Security::isAdmin()) {
            Tht::module('Bare')->u_print($data);
        }
        else {
            Tht::module('Bare')->u_print('Info written to `data/files/app.log`');
            Tht::module('Bare')->u_log($data);
        }
    }

    // Filter super globals and move them to internal data
    static function initRequestData () {

        $data = [
            'get'     => $_GET,
            'post'    => $_POST,
            'cookie'  => $_COOKIE,
            'files'   => $_FILES,
            'server'  => $_SERVER,
            'env'     => $_ENV,
            'headers' => self::initHttpRequestHeaders($_SERVER),
        ];

        if (isset($data['headers']['content-type']) && $data['headers']['content-type'] == 'application/json') {
            $raw = file_get_contents("php://input");
            $data['post'] = Tht::module('Json')->u_decode($raw);
            $data['post']['_raw'] = $raw;
        }
        else if (isset($HTTP_RAW_POST_DATA)) {
            $data['post']['_raw'] = $HTTP_RAW_POST_DATA;
            unset($HTTP_RAW_POST_DATA);
        }

        // Remove all php globals
        // TODO: re-adding to enable PHP interop linbraries... revisit this.
        // unset($_ENV);
        // unset($_REQUEST);
        // unset($_GET);
        // unset($_POST);
        // unset($_COOKIE);  // keep this for Session
        // unset($_FILES);
        // unset($_SERVER);

        //$GLOBALS = null;

        return $data;
    }

    static private function initHttpRequestHeaders($serverVars) {

        $headers = [];

        // Convert http headers to standard kebab-case
        foreach ($serverVars as $k => $v) {
            if (substr($k, 0, 5) === 'HTTP_') {
                $base = substr($k, 5);
                $base = str_replace('_', '-', strtolower($base));
                $headers[$base] = $v;
            }
        }

        unset($headers['referer']);
        unset($headers['cookie']);
        unset($headers['accept-language']);
        unset($headers['host']);
        unset($headers['user-agent']);

        return $headers;
    }

    static function checkPrevHash($raw) {
        if (!is_null(self::$prevHash) && $raw == self::$prevHash) {
            self::error('Hashing an already-hashed value results in a value that is easier to attack.');
        }
    }

    static function hashString($raw) {
        self::checkPrevHash($raw);

        $hash = hash('sha256', $raw);
        self::$prevHash = $hash;

        return $hash;
    }

    static function hashPassword($raw) {
        self::checkPrevHash($raw);

        Tht::module('Perf')->u_start('Password.hash');
        $hash = password_hash($raw, PASSWORD_DEFAULT);
        self::$prevHash = $hash;
        Tht::module('Perf')->u_stop();

        return $hash;
    }

    static function verifyPassword($plainText, $correctHash) {
        return password_verify($plainText, $correctHash);
    }

    static function createPassword ($plainText) {
        return new OPassword ($plainText);
    }

    static function getCsrfToken() {
        $token = Tht::module('Session')->u_get('csrfToken', '');
        if (!$token) {
            $token = Tht::module('String')->u_random(self::$CSRF_TOKEN_LENGTH, true);
            Tht::module('Session')->u_set('csrfToken', $token);
        }
        return $token;
    }

    static function getNonce() {
        if (!self::$CSP_NONCE) {
            self::$CSP_NONCE = self::randomString(self::$NONCE_LENGTH);
        }
        return self::$CSP_NONCE;
    }

    // Length = final string length, not byte length
    static function randomString($len) {

        $bytes = '';

        if (function_exists('random_bytes')) {
            $bytes = random_bytes($len);
        } else if (function_exists('mcrypt_create_iv')) {
            $bytes = mcrypt_create_iv($len, MCRYPT_DEV_URANDOM);
        } else {
            $bytes = openssl_random_pseudo_bytes($len);
        }

        $b64 = base64_encode($bytes);

        return substr($b64, 0, $len);
    }

    static function initSessionParams() {

        ini_set('session.use_only_cookies', 1);
        ini_set('session.use_strict_mode', 0);
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_trans_sid', 1);
        ini_set('session.cookie_samesite', 'Lax');

        ini_set('session.gc_maxlifetime',  Tht::getConfig('sessionDurationMins') * 60);
        ini_set('session.cookie_lifetime', self::$SESSION_COOKIE_DURATION);
        ini_set('session.sid_length',      self::$SESSION_ID_LENGTH);
    }

    static function sanitizeInputString($str) {
        if (is_array($str)) {
            foreach ($str as $k => $v) {
                $str[$k] = self::sanitizeInputString($v);
            }
        } else if (is_string($str)) {
            $str = str_replace(chr(0), '', $str);  // remove null bytes
            $str = trim($str);
        }
        return $str;
    }

    static function validateCsrfToken() {

        if (!is_null(self::$isCsrfTokenValid)) {
            return self::$isCsrfTokenValid;
        }

        self::$isCsrfTokenValid = false;

        $localCsrfToken = Tht::module('Session')->u_get('csrfToken', '');
        $post = Tht::data('requestData', 'post');
        $remoteCsrfToken = isset($post['csrfToken']) ? $post['csrfToken'] : '';

        if ($localCsrfToken && hash_equals($localCsrfToken, $remoteCsrfToken)) {
            self::$isCsrfTokenValid = true;
        }

        return self::$isCsrfTokenValid;
    }

    static function validatePhpFunction($func) {
        $func = strtolower($func);
        $func = preg_replace('/^\\\\/', '', $func);
        if (in_array($func, self::$PHP_BLACKLIST) || preg_match(self::$PHP_BLACKLIST_MATCH, $func)) {
            self::error("PHP function is blacklisted: `$func`");
        }
    }

    static function validateFilePath ($path, $checkSandbox=true) {

        if (is_uploaded_file($path)) {
            return $path;
        }

        $path = str_replace('\\', '/', $path);
        $path = preg_replace('#/{2,}#', '/', $path);

        if (strlen($path) > 1) {  $path = rtrim($path, '/');  }

        // TODO: revisit this.  Technically, anything can be in quotes.
        // Users might have a clunky root directory (e.g. Dropbox folder with parens)
        // if (preg_match('/[^a-zA-Z0-9_\-\/\.: ]/', $path)) {
        //     self::error("Illegal character in path: `$path`");
        // }
        if (!strlen($path)) {
            self::error("File path cannot be empty: `$path`");
        }
        else if (v($path)->u_is_url()) {
            self::error("Remote URL not allowed: `$path`");
        }
        else if (strpos($path, '..') !== false) {
            self::error("Parent shortcut `..` not allowed in path: `$path`");
        }
        else if (strpos($path, './') !== false) {
            self::error("Dot directory `.` not allowed in path: `$path`");
        }

        if ($checkSandbox) {
            $path = self::getSandboxedPath($path);
        }

        return $path;
    }

    // Reject any file name that has evasion patterns in it and
    // make sure the extension is in a whitelist.
    static function validateUploadedFile($file, $allowExtensions) {

        // Don't allow multiple files via []
        if (is_array($file['error'])) {
            u_Input::$lastUploadError = 'Duplicate file keys not allowed';
            return fales;
        }

        if ($file['error']) {
            if ($file['error'] == 1) {
                u_Input::$lastUploadError = 'Max upload size exceeded.';
            }
            u_Input::$lastUploadError = 'Upload error: ' . $file['error'];
            return false;
        }

        $name = $file['name'];

        if (strpos($name, '..') !== false) {
            u_Input::$lastUploadError = 'Invalid filename';
            return false;
        }
        if (strpos($name, '/') !== false) {
            u_Input::$lastUploadError = 'Invalid filename';
            return false;
        }

        // only one extension allowed
        $parts = explode('.', $name);
        if (count($parts) !== 2) {
            u_Input::$lastUploadError = 'Invalid filename';
            return false;
        }

        // Check against whitelist of extensions
        $uploadedExt = strtolower($parts[1]);
        $found = false;
        foreach ($allowExtensions as $ext) {
            if ($uploadedExt == $ext) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            u_Input::$lastUploadError = "Unsupported file extension: `$uploadedExt`";
            return false;
        }

        // Validate MIME type
        $actualMime = Tht::module('*File')->u_get_mime_type($file['tmp_name']);
        if (!$actualMime) {
            u_Input::$lastUploadError = 'Unknown file type';
            return false;
        }

        // MIME inferred from file extension
        $extMime = Tht::module('*File')->u_extension_to_mime_type($uploadedExt);

        $ok = self::validateUploadedMimeType($actualMime, $extMime);
        if (!$ok) {
            u_Input::$lastUploadError = "File type `$actualMime` does not match file extension `$uploadedExt`.";
            return false;
        }
        else {
            return $uploadedExt;
        }
    }

    static function validateUploadedMimeType($actualMime, $extMime) {

        list($extMimeCat, $x) = explode('/', $extMime);
        list($actualMimeCat, $x) = explode('/', $actualMime);

        if ($extMime == $actualMime) {
            // exact match
            return true;
        }
        else if ($actualMimeCat == 'text') {
            // text is safe
            // allow 'text/plain' for json files, which should be 'application/json'
            return true;
        }
        else if ($actualMimeCat != 'application') {
            // application must be a strict match
            return false;
        }
        else if ($actualMimeCat == $extMimeCat) {
            // <atch top-level category (e.g. 'text/html' = 'text')
            // e.g. if we expect an image, we should get an image.
            // This accounts for vagaries in actual mime types.
            return true;
        }

        return false;
    }

    // Make sure path is under data/files
    static function getSandboxedPath($path) {

        if ($path[0] !== '/') {
            return Tht::path('files', $path);
        }
        else {
            $sandboxDir = Tht::path('files');
            if (strpos($path, $sandboxDir) !== 0) {
                self::error("Path must be relative to `data/files`: `$path`");
            }
            return $path;
        }
    }

    static function validatePostRequest() {

        if (self::$isPostRequestValidated) {
            return;
        }
        self::$isPostRequestValidated = true;

        $res = Tht::module('Output');
        $req = Tht::module('Request');

        if ($req->u_method() === 'get') {
            return;
        }
        else if (Security::isCrossOrigin()) {
            $res->u_send_error(403, 'Remote Origin Not Allowed');
        }
        else if (!Security::validateCsrfToken()) {
            $res->u_send_error(403, 'Invalid or Missing \'csrfToken\' Field');
        }
        // else if (Security::isPossibleBruteForce()) {
        //     $web->u_send_error(429, 'Too Many Requests', 'Must wait 2 seconds between POST requests.');
        // }
    }

    // All lowercase, no special characters, hyphen separators, no trailing slash
    static function validateRoutePath($path) {
        $pathSize = strlen($path);
        $isTrailingSlash = $pathSize > 1 && $path[$pathSize-1] === '/';
        if (preg_match('/[^a-z0-9\-\/\.]/', $path) || $isTrailingSlash)  {
            Tht::module('Output')->u_send_error(404, 'Page address is not valid.');
        }
    }

    // WIP: Wait until we implement an Auth module
    //
    // https://stackoverflow.com/questions/549/the-definitive-guide-to-form-based-website-authentication
    // See part VI, on brute force attempts
    // Just a simple speed bump -- fast enough to not be noticeable by humans.
    // static function isPossibleBruteForce() {

    //     // Keep this very general (IP), so that it can't be invalidated by the client
    //     $userKey = 'lastPostTime:' . Tht::module('Request')->u_ip();

    //     $lastPostTime = Tht::module('Cache')->u_get($userKey, 0);
    //     $now = microtime(true);
    //     $threshTime = $lastPostTime + self::$THROTTLE_POST_SECS;
    //     $isTooSoon = $now < $threshTime;

    //     $cacheTtlSecs = ceil(self::$THROTTLE_POST_SECS + 5);
    //     $cache->u_set($userKey, $now, $cacheTtlSecs);

    //     return $isTooSoon;
    // }

    static function isCrossOrigin () {

        if (!is_null(self::$isCrossOrigin)) {
            return self::$isCrossOrigin;
        }

        if (Tht::module('Request')->u_method() !== 'get') {
           $host  = Tht::getWebRequestHeader('host');
           $origin = Tht::getWebRequestHeader('origin');
           $origin = preg_replace('/^https?:\/\//i', '', $origin);

           if (!$origin) {
               $referrer = Tht::getWebRequestHeader('referrer');
               if (!$referrer || strpos($referrer, $host) == 0) {
                   self::$isCrossOrigin = false;
               } else {
                   self::$isCrossOrigin = true;
               }
           }
           else if ($origin !== $host) {
               self::$isCrossOrigin = true;
           }
        }

        return self::$isCrossOrigin;
    }

    static function initResponseHeaders () {

        if (headers_sent($atFile, $atLine)) {
            Tht::startupError('Headers Already Sent');
        }

        // Set response headers
        header_remove('Server');
        header_remove("X-Powered-By");
        header('X-Frame-Options: deny');
        header('X-Content-Type-Options: nosniff');
        header("X-UA-Compatible: IE=Edge");

        // HSTS - 1 year duration
        $ip = Tht::getPhpGlobal('server', 'REMOTE_ADDR');
        if (!Tht::isMode('testServer') && $ip != '127.0.0.1') {
            header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
        }

        // Content Security Policy (CSP)
        $csp = Tht::getConfig('contentSecurityPolicy');
        if (!$csp) {
            $nonce = "'nonce-" . Tht::module('Web')->u_nonce() . "'";
            $eval = Tht::getConfig('xDangerAllowJsEval') ? '\'unsafe-eval\'' : '';
            $scriptSrc = "script-src $eval 'report-sample' $nonce";
            $csp = "default-src 'self' $nonce; style-src 'unsafe-inline' *; img-src data: *; media-src data: *; font-src *; " . $scriptSrc;
        }
        if ($csp != 'xDangerNone') {
            header("Content-Security-Policy: $csp");
        }

    }

    // set PHP ini
    static function initPhpIni () {

        // locale
        date_default_timezone_set(Tht::getConfig('timezone'));

        ini_set('default_charset', 'utf-8');
        mb_internal_encoding('utf-8');

        // logging
        error_reporting(E_ALL);
        ini_set('display_errors', Tht::isMode('cli') ? '1' : (Tht::getConfig('_coreDevMode') ? '1' : '0'));
        ini_set('display_startup_errors', '1');
        ini_set('log_errors', '0');  // assume we are logging all errors manually

        // file security
        ini_set('allow_url_fopen', '0');
        ini_set('allow_url_include', '0');

        // limits
        ini_set('max_execution_time', Tht::isMode('cli') ? 0 : intval(Tht::getConfig('maxExecutionTimeSecs')));
        ini_set('max_input_time', intval(Tht::getConfig('maxInputTimeSecs')));
        ini_set('memory_limit', intval(Tht::getConfig('memoryLimitMb')) . "M");

    }

    // Register an un-sandboxed version of File, for internal use.
    static function registerInternalFileModule() {
        $f = new u_File ();
        $f->xDangerDisableSandbox();
        ModuleManager::registerStdModule('*File', $f);
    }

    // https://www.owasp.org/index.php/XSS_Filter_Evasion_Cheat_Sheet
    static function validateUserUrl($sUrl) {

        $sUrl = trim($sUrl);
        $url = self::parseUrl($sUrl);
        $sUrl = urldecode($sUrl);

        if (!preg_match('!^https?\://!i', $sUrl)) {
            // must be absolute, http
            return false;
        }
        else if (preg_match('!\.\.!', $sUrl)) {
            // No parent '..' patterns
            return false;
        }
        else if (preg_match('![\'\"\0\s<>\\]!', $sUrl)) {
            // Illegal characters
            return false;
        }
        else if (strpos('&#', $sUrl) !== false) {
            // No HTML escapes allowed
            return false;
        }
        else if (strpos('%', $url['host']) !== false) {
            // No escapes allowed in host
            return false;
        }
        else if (preg_match('!^[0-9\.fxFX]+$!', $url['host'])) {
            // Can not be IP address
            return false;
        }
        else if (preg_match('!\.(loan|work|click|gdn|date|men|gq|world|life|bid)!i', $url['host'])) {
            // High spam TLD
            // https://www.spamhaus.org/statistics/tlds/
            return false;
        }
        else if (preg_match('!\.(zip|doc|xls|pdf|7z)!i', $sUrl)) {
            // High-risk file extension
            return false;
        }

        return true;
    }

    static function sanitizeHtmlPlaceholder($in) {
        $alpha = preg_replace('/[^a-z:]/', '', strtolower($in));
        if (strpos($alpha, 'javascript:') !== false) {
            $in = '(REMOVED:UNSAFE_URL)';
        }
        return $in;
    }

    static function escapeHtml($in) {
        if (OTypeString::isa($in)) {
            return 'xxx';
        }
        return htmlspecialchars($in, ENT_QUOTES|ENT_HTML5, 'UTF-8');
    }

    static function unescapeHtml($in) {
         return htmlspecialchars_decode($in, ENT_QUOTES|ENT_HTML5);
    }

    // Prevent the most common password mistakes
    static function validatePasswordStrength($val) {

        // all same character
        if (preg_match("/^(.)\\\\1{1,}$/", $val)) {
            return false;
        }
        // all digits
        else if (preg_match("/^\\d+$/", $val)) {
            return false;
        }
        // most common patterns
        else if (preg_match("/^(abcd|abc1|qwer|asdf|1qaz|passw|admin|login|welcome|access)/i", $val)) {
            return false;
        }
        // most common passwords
        else if (preg_match("/^(football|baseball|princess|starwars|trustno1|superman|iloveyou)$/i", $val)) {
            return false;
        }

        return true;
    }

    static function sanitizeUrlHash($hash) {
        $hash = preg_replace('![^a-z0-9\-]!', '-', strtolower($hash));
        $hash = rtrim($hash, '-');
        return $hash;
    }

    static function parseUrl($url) {

        // remove user
        // https://www.cvedetails.com/cve/CVE-2016-10397/
        // $url = preg_replace('!(\/+).*@!', '$1', $url);
        // if (strpos($url, '@') !== false) {
        //     $url = preg_replace('!.*@!', '', $url);
        // }

        $url = preg_replace('!\s+!', '', $url);

        preg_match('!^(.*?)#(.*)$!', $url, $m);
        if (isset($m[2])) {
            $url = $m[1] . '#' . self::sanitizeUrlHash($m[2]);
        }

        $u = parse_url($url);

        unset($u['user']);

        $fullUrl = rtrim($url, '/');
        $u['full'] = $fullUrl;

        $relativeUrl = preg_replace('#^.*?//.*?/#', '/', $fullUrl);
        $u['relative'] = $relativeUrl;

        if (!isset($u['path'])) {
            $u['path'] = '';
        }

        // path parts
        if ($u['path'] === '' || $u['path'] === '/') {
            $u['pathParts'] = OList::create([]);
            $u['page'] = '';
        }
        else {
            $pathParts = explode('/', trim($u['path'], '/'));
            $u['pathParts'] = OList::create($pathParts);
            $u['page'] = end($pathParts);
        }

        // port
        if (!isset($u['port'])) {
            if (isset($u['scheme'])) {
                if ($u['scheme'] == 'http') {
                    $u['port'] = 80;
                } else if ($u['scheme'] == 'https') {
                    $u['port'] = 443;
                } else {
                    $u['port'] = 0;
                }
            } else {
                $u['port'] = 80;
            }
        }

        if (isset($u['fragment']) && $u['fragment']) {
            $u['hash'] = $u['fragment'];
            unset($u['fragment']);
        } else {
            $u['hash'] = '';
        }

        // remove hash & query
        $u['full'] = preg_replace('!#.*!', '', $u['full']);
        $u['full'] = preg_replace('!\?.*!', '', $u['full']);

        // without the query & hash, this is effectively same as path
        unset($u['relative']);

        return $u;
    }

    // PHP behavior:
    // ?foo=1&foo=2         >>  foo=2
    // ?foo[]=1&foo[]=2     >>  foo=[1,2]
    // ?foo[a]=1&foo[b]=2   >>  foo={a:1, b:2}
    static function stringifyQuery($query) {

        $q = http_build_query(uv($query), null, '&', PHP_QUERY_RFC3986);
        $q = str_replace('%5B', '[', $q);
        $q = str_replace('%5D', ']', $q);
        $q = preg_replace('!\[([0-9]+)\]!', '[]', $q);

        if ($q) {
            return '?' . $q;
        }
        else {
            return '';
        }
    }
}



