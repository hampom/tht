<?php

namespace o;

// All sensitive operations in one place, for easier auditing

class Security {

    static private $CSP_NONCE = '';
    static private $CSRF_TOKEN_LENGTH = 32;
    static private $NONCE_LENGTH = 40;

    static private $SESSION_ID_LENGTH = 48;
    static private $SESSION_COOKIE_DURATION = 0;  // until browser is closed

    static private $THROTTLE_PASSWORD_WINDOW_SECS = 3600;

    static private $isCrossOrigin = null;
    static private $isCsrfTokenValid = null;
    static private $isDev = null;

    static private $prevHash = null;

    static private $hashIdAlphabetNormal = "0123456789abcdefghijklmnop";
    static private $hashIdAlphabetCustom = "256789bcdfghjkmnpqrstvwxyz";
    static private $hashIdAlphabetBase = 26;

    static private $PHP_BLOCKLIST_MATCH = '/pcntl_|posix_|proc_|ini_|mysql|sqlite/i';

    static private $PHP_BLOCKLIST = [
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

    // TODO: Allow multiple IPs (via list in app.jcon)
    static function isDev() {

        if (self::$isDev !== null) { return self::$isDev; }

        $ip = self::getClientIp();
        $devIp = Tht::getThtConfig('devIp');
        $isDevIp = $devIp && $devIp == $ip;

        self::$isDev = false;
        if ($isDevIp || self::isLocalServer()) {
            self::$isDev = true;
        }

        return self::$isDev;
    }

    static function getClientIp() {
        return Tht::getPhpGlobal('server', 'REMOTE_ADDR');
    }

    // Print sensitive data.  Only print to log if not in Admin mode.
    static function safePrint($data) {

        if (Security::isDev()) {
            Tht::module('Bare')->u_print($data);
        }
        else {
            Tht::module('Bare')->u_print('Info written to `data/files/app.log`');
            Tht::module('Bare')->u_log($data);
        }
    }

    // Filter super globals and move them to internal data
    static function initRequestData() {

        $data = [
            'get'     => $_GET,
            'post'    => $_POST,
            'cookie'  => $_COOKIE,
            'files'   => $_FILES,
            'server'  => $_SERVER,
            'env'     => getenv(),
            'headers' => self::initHttpRequestHeaders($_SERVER),
        ];

        if (isset($data['headers']['content-type']) && $data['headers']['content-type'] == 'application/json') {

            // Parse JSON
            $raw = file_get_contents("php://input");
            $data['post'] = Tht::module('Json')->u_decode($raw);
            $data['post']['_raw'] = $raw;
        }
        else if (isset($HTTP_RAW_POST_DATA)) {

            $data['post']['_raw'] = $HTTP_RAW_POST_DATA;
        }

        // Make env all uppercase, to be case-insensitive
        $upEnv = $data['env'];
        foreach ($data['env'] as $k => $v) {
            $upEnv[strtoupper($k)] = $v;
        }
        $data['env'] = $upEnv;

        return $data;
    }

    static public function validateHttps() {
        if (!Tht::module('Request')->u_is_https() && !Security::isDev()) {
            ErrorHandler::setHelpLink('https://certbot.eff.org/', 'Convert to HTTPS');
            self::error("Input data (GET & POST) can only be processed when the app is served as HTTPS.");
        }
    }

    static private function initHttpRequestHeaders($serverVars) {

        $headers = [];

        // Convert http headers to uniform kebab-case
        foreach ($serverVars as $k => $v) {
            if (substr($k, 0, 5) === 'HTTP_') {
                $base = substr($k, 5);
                $base = str_replace('_', '-', strtolower($base));
                $headers[$base] = $v;
            }
        }

        // Make sure these are retrieved via the Request API.  Not raw headers.
        unset($headers['referer']);
        unset($headers['cookie']);
        unset($headers['accept-language']);
        unset($headers['host']);
        unset($headers['user-agent']);

        return $headers;
    }

    // Fetch policy using 'sec-fetch-*' headers
    // https://web.dev/fetch-metadata/
    static function validateRequestOrigin() {

        $site = Tht::getPhpGlobal('headers', 'sec-fetch-site');

        // Old browser.  Just deny 'accept: image' as a stopgap.
        if (!$site) {
            $accept = Tht::getPhpGlobal('headers', 'accept');
            if (preg_match('#image/#i', $site)) {
                Tht::error(403, 'Remote request not allowed.');
            }
            return;
        }

        // Allow all requests from same origin or direct navigation
        if (in_array($site, ['same-origin', 'same-site', 'none'])) {
            return;
        }

        // Only allow navigation action for remote origin.
        $mode = Tht::getPhpGlobal('headers', 'sec-fetch-mode');
        if ($mode != 'navigate') {
            Tht::error(403, 'Only navigation is allowed from remote origin.');
        }

        // Don't allow remote navigation from objects, etc.
        $dest = Tht::getPhpGlobal('headers', 'sec-fetch-dest');
        if (in_array($dest, ['object', 'embed'])) {
            Tht::error(403, 'Remote request from objects not allowed.');
            return;
        }
    }

    static function hashString($raw, $algo='sha256', $asBinary=false) {

        self::checkPrevHash($raw);

        $hash = hash($algo, $raw, $asBinary);
        self::$prevHash = $hash;

        return $hash;
    }

    // Prevent attempts to hash a string multiple times
    static function checkPrevHash($raw) {

        if (!is_null(self::$prevHash) && $raw == self::$prevHash) {
            $shorter = substr($raw, 0, 10) . "...";
            self::error("The hash string `$shorter` can not be hashed a 2nd time. Doing so would create a less unique hash and would be less secure.");
        }
    }

    static function hashPassword($raw) {

        $perfTask = Tht::module('Perf')->u_start('Password.hash');

        self::checkPrevHash($raw);

        $hash = password_hash($raw, PASSWORD_DEFAULT);
        self::$prevHash = $hash;

        $perfTask->u_stop();

        return $hash;
    }

    static function verifyPassword($plainText, $correctHash) {

        return password_verify($plainText, $correctHash);
    }

    static function createPassword($plainText) {

        return new OPassword ($plainText);
    }

    static function getCsrfToken() {

        $token = Tht::module('Session')->u_get('csrfToken', '');

        if (!$token) {
            $token = Tht::module('String')->u_random_token(self::$CSRF_TOKEN_LENGTH);
            Tht::module('Session')->u_set('csrfToken', $token);
        }

        return $token;
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

    static function getNonce() {

        if (!self::$CSP_NONCE) {
            self::$CSP_NONCE = self::randomString(self::$NONCE_LENGTH);
        }

        return self::$CSP_NONCE;
    }

    static function randomString($len) {

        // random_int is crypto secure
        $str = '';
        for ($i = 0; $i < $len; $i += 1) {
            $str .= base_convert(random_int(0, 35), 10, 36);
        }

        return $str;
    }

    // Based on https://github.com/marekweb/opaque-id
    static function encodeHashId($i) {

        $transcoded = self::hashIdTranscode($i);
        $baseX = base_convert($transcoded, 10, self::$hashIdAlphabetBase);
        $hashId = strtr($baseX, self::$hashIdAlphabetNormal, self::$hashIdAlphabetCustom);

        return $hashId;
    }

    static function decodeHashId($hash) {

        $decoded = strtr($hash, self::$hashIdAlphabetCustom, self::$hashIdAlphabetNormal);
        $base10 = base_convert($decoded, self::$hashIdAlphabetBase, 10);
        $intId = self::hashIdTranscode($base10);

        return $intId;
    }

    static function hashIdTranscode($i) {
        $r = $i & 0xffff;
        $l = $i >> 16 & 0xffff ^ self::hashIdTransform($r);
        return (($r ^ self::hashIdTransform($l)) << 16) + $l;
    }

    static public function getRandomScrambleKey() {
        return self::randomHex(8);
    }

    static private function hashIdTransform($i) {

        $secretKeyHex = Tht::getThtConfig('scrambleNumSecretKey');

        if (!preg_match('/^[0-9a-f]{8}+$/', $secretKeyHex)) {
            $randomHex = self::getRandomScrambleKey();
            self::error("Config key `scrambleNumSecretKey` must be a 8-digit hex string.  Got: `$secretKeyHex`  Try: `$randomHex` (randomly generated)");
        }
        $secretKeyDec = hexdec($secretKeyHex);

        $i = ($secretKeyDec ^ $i) * 0x9e3b;
        return $i >> ($i & 0xf) & 0xffff;
    }

    static function randomHex($numDigits) {
        $hex = '';
        for ($i = 0; $i < $numDigits; $i += 1) {
            $hex .= dechex(rand(0,15));
        }
        return $hex;
    }

    static function createUuid($isRandom=false) {

        // https://stackoverflow.com/questions/2040240/php-function-to-generate-v4-uuid
        if ($isRandom) {
            $data = random_bytes(16);
            $data[6] = chr(ord($data[6]) & 0x0f | 0x40); // set version bit
            $data[8] = chr(ord($data[8]) & 0x3f | 0x80); // set bits 6-7 to 10
            return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($data), 4));
        }
        else {
            return uniqid('', true);
        }
    }

    static function jsonValidate($rawJsonString) {

        return json_validate($rawJsonString, 512, JSON_INVALID_UTF8_IGNORE);
    }

    // TODO: Separate json used internally for print() statements & stack traces from user-facing json methods.
    static function jsonEncode($rawData) {

        // SECURITY: Using JSON_UNESCAPED_SLASHES for internal usability.
        // Apparently allowing "</script>" inside a string will end any <script> block and create a vulnerability.
        // However, this gets escaped downstream.
        $json = json_encode($rawData, JSON_UNESCAPED_UNICODE|JSON_UNESCAPED_SLASHES|JSON_PRESERVE_ZERO_FRACTION);

        return $json;
    }

    // https://bishopfox.com/blog/json-interoperability-vulnerabilities
    // Alas, this PHP ER is still open: https://bugs.php.net/bug.php?id=78765
    static function jsonDecode($rawJsonString) {

        $jsonData = '';

        // TODO: Fail on duplicate keys. Unfortunately, the stdlib json_decode doesn't have a flag to do so.
        $jsonData = json_decode($rawJsonString, false, 512, JSON_INVALID_UTF8_SUBSTITUTE);

        if (is_null($jsonData)) {
            Tht::module('Json')->error("Unable to decode JSON string: " . json_last_error_msg());
        }

        $thtData = self::jsonToTht($jsonData);

        return $thtData;
    }

    // Recursively convert to THT Lists and Maps
    static private function jsonToTht($obj, $key='(root)') {

        if (is_object($obj)) {
            $map = [];
            foreach (get_object_vars($obj) as $key => $val) {
                $map[$key] = self::jsonToTht($val, $key);
            }
            return OMap::create($map);
        }
        else if (is_array($obj)){
            foreach ($obj as $i => $val) {
                $obj[$i] = self::jsonToTht($obj[$i], $i);
            }
            return OList::create($obj);
        }
        else {
            if (is_float($obj) && (is_infinite($obj) || is_nan($obj))) {
                self::error("Invalid large number for JSON key: `$key`");
            }
            return $obj;
        }
    }

    // Fisher-Yates with crypto-secure random_int()
    // Assumably more secure than built-in shuffle()
    static function shuffleList($list) {

        $numEls = count($list);

        for ($i = $numEls - 1; $i > 0; $i -= 1) {
            $j = random_int(0, $i);
            $tmp = $list[$j];
            $list[$j] = $list[$i];
            $list[$i] = $tmp;
        }

        return $list;
    }

    static function initSessionParams() {

        ini_set('session.use_only_cookies', 1);
        ini_set('session.use_strict_mode', 0);
        ini_set('session.cookie_httponly', 1);
        ini_set('session.use_trans_sid', 1);
        ini_set('session.cookie_samesite', 'Lax');

        ini_set('session.gc_maxlifetime',  Tht::getThtConfig('sessionDurationHours') * 3600);
        ini_set('session.cookie_lifetime', self::$SESSION_COOKIE_DURATION);
        ini_set('session.sid_length',      self::$SESSION_ID_LENGTH);
    }

    static function sanitizeInputString($str) {

        if (is_array($str)) {
            foreach ($str as $k => $v) {
                $str[$k] = self::sanitizeInputString($v);
            }
        }
        else if (is_string($str)) {
            // Remove invisible control chars, including null
            $str = preg_replace('/\p{C}/u', '', $str);
            $str = trim($str);
        }

        return $str;
    }

    static function validatePhpFunction($func) {

        $func = strtolower($func);
        $func = preg_replace('/^\\\\/', '', $func);

        if (in_array($func, self::$PHP_BLOCKLIST) || preg_match(self::$PHP_BLOCKLIST_MATCH, $func)) {
            Tht::module('Php')->error("PHP function is blocklisted: `$func`");
        }
    }

    // https://stackoverflow.com/questions/1976007/what-characters-are-forbidden-in-windows-and-linux-directory-names
    // Plus, added more, including spaces.
    static function findInvalidCharInPath($path) {

        // Ignore windows drive
        $path = preg_replace('#^/?[A-Za-z]:#', '', $path);

        $found = preg_match('#([,;:%|="?*<>\x00-\x1F])#x', $path, $m);
        if (!$found) { return ''; }

        $char = $m[1];

        $ascii = ord($char);
        if ($ascii <= 31) { $char = 'x' . dechex($ascii); }

        return $char;
    }

    static function sanitizeForFileName($fileName) {

        $fileName = preg_replace('#[\x00-\x1F]#', '', $fileName);
        $fileName = preg_replace('#[/\\\\,;:%|="\'?*<>.\s]#', '_', $fileName);

        return $fileName;
    }

    static function validateFilePath($oPath) {

        $path = $oPath;

        if (is_uploaded_file($path)) {
            return $path;
        }

        $path = str_replace('\\', '/', $path);

        // Combine '//' to '/', unless it is a windows share (beginning of string)
        $path = preg_replace('#(?<!^)/{2,}#', '/', $path);

        if (strlen($path) > 1) {  $path = rtrim($path, '/');  }

        if (!strlen($path)) {
            Tht::module('File')->error("File path can not be empty.");
        }
        else if (preg_match('#[a-zA-Z]{2,}:/#', $path)) {
            Tht::module('File')->error("Remote URL not allowed: `$oPath`");
        }
        else if (str_contains($path, '..')) {
            Tht::module('File')->error("Parent shortcut `..` not allowed in path: `$oPath`");
        }
        else if (preg_match('#(^|/)\./#', $path)) {
            Tht::module('File')->error("Dot directory `.` not allowed in path: `$oPath`");
        }

        // TODO: decide what to do with this.
        else if ($char = self::findInvalidCharInPath($path)) {
            Tht::module('File')->error("Invalid character `$char` found in path: `$oPath`");
        }

        return $path;
    }

    // Reject any file name that has evasion patterns in it and
    // make sure the extension is in a whitelist.
    static function validateUploadedFile($fileKey, $sAllowExtensions, $maxSizeMb, $uploadDir) {

        $result = Tht::module('Result');

        if (!$sAllowExtensions || !is_string($sAllowExtensions)) {
            $this->error("Input field `$key` with validation type `file` requires an `ext` rule with a comma-delimited list of allowed extensions.");
        }
        $allowExtensions = preg_split('/\s*,\s*/', $sAllowExtensions);


        $files = Tht::getPhpGlobal('files', '*');
        $file = $files[$fileKey] ?? null;
        if (!$file) {
            return $result->u_fail('missing_file_key');
        }

        // Don't allow multiple files via []
        if (is_array($file['error'])) {
            return $result->u_fail('Duplicate file keys not allowed.');
        }

        if ($file['error']) {
            if ($file['error'] == 1) {
                $actualSizeMb = round($file['size'] / 1_000_000, 1);
                $maxSize = ini_get('upload_max_filesize');
                return $result->u_fail('File size too large: ' . $actualSizeMb . 'MB  (Max: ' . $maxSize . ')');
            }
            return $result->u_fail('Upload error: ' . $file['error']);
        }

        if (!$file['size']) {
            return $result->u_fail('File is empty.');
        }


        $name = $file['name'];

        if (str_contains($name, '..')) {
            return $result->u_fail('Invalid filename.');
        }
        if (str_contains($name, '/')) {
            return $result->u_fail('Invalid filename.');
        }

        // only one extension allowed
        $parts = explode('.', $name);
        if (count($parts) == 1) {
            return $result->u_fail('Missing filename extension.');
        }

        // Check against allowlist of extensions
        $uploadedExt = strtolower(array_pop($parts));
        $found = false;
        foreach ($allowExtensions as $ext) {
            if ($uploadedExt == $ext) {
                $found = true;
                break;
            }
        }
        if (!$found) {
            return $result->u_fail("Unsupported file extension: `$uploadedExt`");
        }

        // Validate file size
        $sizeMb = round($file['size'] / 1_000_000, 1);
        if ($sizeMb > $maxSizeMb) {
            return $result->u_fail("File size ($sizeMb MB) can not be larger than $maxSizeMb MB.");
        }
        else if ($sizeMb === 0) {
            return $result->u_fail("File can not be empty.");
        }

        // Validate MIME type
        $actualMime = PathTypeString::create($file['tmp_name'])->u_get_mime_type();
        if (!$actualMime) {
            return $result->u_fail('Unknown file type.');
        }

        // MIME inferred from file extension
        $extMime = Tht::module('File')->u_extension_to_mime_type($uploadedExt);
        $ok = self::validateUploadedMimeType($actualMime, $extMime);
        if (!$ok) {
            return $result->u_fail("File type `$actualMime` does not match file extension: `$uploadedExt`");
        }

        // Convert relative path to app/data/uploads
        if ($uploadDir->u_is_relative()) {
            $uploadRoot = new DirTypeString(Tht::path('uploads'));
            $uploadDir = $uploadRoot->u_append_path($uploadDir);
        }

        // Make sure dir exists
        $uploadDir->u_make_dir();

        // Move file to dir with a random name
        $newName = Tht::module('String')->u_random_token(30) . '.' . $uploadedExt;
        $newFile = new FileTypeString($newName);
        $newPath = $uploadDir->u_append_path($newFile);
        $sNewPath = $newPath->u_render_string();

        $moveOk = move_uploaded_file($file['tmp_name'], $sNewPath);
        if (!$moveOk) {
            Tht::error("Unable to move uploaded file for input field: `$key`  To Path: `$sNewPath`");
        }

        return $result->u_ok(new FileTypeString($sNewPath));
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
            // Match top-level category (e.g. 'text/html' = 'text')
            // e.g. if we expect an image, we should get an image.
            // This accounts for vagaries in actual mime types.
            return true;
        }

        return false;
    }

    static function isCrossSiteRequest() {

        // https://developer.mozilla.org/en-US/docs/Web/HTTP/Headers/Sec-Fetch-Site
        $site = Tht::getPhpGlobal('headers', 'sec-fetch-site');

        // Only allow requests from same-site and browser action (address-bar/bookmarks, etc)
        if ($site == 'cross-site') {
            return true;
        }

        return false;
    }

    static function validatePostRequest() {

        if (self::isGetRequest()) {
            return;
        }

        // Make an exception for API calls
        $path = Tht::module('Request')->u_get_url()->u_get_path();
        if (strpos($path, '/api/') === 0) {
            return;
        }

        if (self::isCrossSiteRequest()) {
            Tht::module('Output')->u_send_error(403, 'Cross-Site POST Not Allowed');
        }

        if (!Security::validateCsrfToken()) {
            if (self::isDev()) {
                ErrorHandler::setHelpLink('/manual/module/form/csrf-tag', 'Form.csrfTag');
                Tht::error("Invalid or missing `csrfToken` input field in POST request.");
            }
            else {
                Tht::module('Output')->u_send_error(403, 'Invalid or Missing \'csrfToken\' Field');
            }
        }
    }

    static function isGetRequest() {

        $reqMethod = Tht::module('Request')->u_get_method();

        return ($reqMethod === 'get' || $reqMethod === 'head');
    }

    // All lowercase, no special characters, hyphen separators, no trailing slash
    static function validateRoutePath($path) {

        $pathSize = strlen($path);
        $isTrailingSlash = $pathSize > 1 && $path[$pathSize-1] === '/';

        if ($isTrailingSlash)  {
            Tht::module('Output')->u_send_error(400, 'Page address is not valid.', new HtmlTypeString('Remove trailing slash "/" from path.'));
        }
        else if (preg_match('/[^a-z0-9\-\/\.]/', $path))  {
            Tht::module('Output')->u_send_error(400, 'Page address is not valid.', new HtmlTypeString('Path must be lowercase, numbers, or characters: <code>-./</code>'));
        }
        else if (preg_match('#//#', $path))  {
            Tht::module('Output')->u_send_error(400, 'Page address is not valid.', new HtmlTypeString('Path contains empty segment (<code>//</code>).'));
        }
    }

    // Disabled for now.  Might be too restrictive.
    // static function preventDestructiveSql($sql) {
    //     $db = Tht::module('Db');
    //     if (self::isGetRequest()) {
    //         if (preg_match('/\b(update|delete|drop)\b/i', $sql, $m)) {
    //             $db->error("Can't execute a destructive SQL command (`" . $m[1] . "`) in an HTTP GET request (i.e. normal page view).");
    //         }
    //     }
    // }

    // https://stackoverflow.com/questions/549/the-definitive-guide-to-form-based-website-authentication
    // See part VI, on brute force attempts
    static function rateLimitedPasswordCheck($plainTextAttempt, $correctHash) {

        $isCorrectMatch = Security::verifyPassword($plainTextAttempt, $correctHash);

        // Default: 30 failed attempts allowed every 60 minutes
        // See: tht.dev/manual/class/password/check#rate-limiting
        $attemptsAllowedPerHour = Tht::getThtConfig('passwordAttemptsPerHour');
        if (!$attemptsAllowedPerHour) {
            return $isCorrectMatch;
        }

        // Truncate so the full hash isn't leaked elsewhere,
        // but is still unique enough for this purpose
        $pwHashkey = substr(hash('sha256', $plainTextAttempt), 0, 40);

        $ip = Tht::module('Request')->u_get_ip();

        // Check if this IP has successfully used this password in the past
        $allowKey = 'pwAllow:' . $pwHashkey;
        $allowList = Tht::module('Cache')->u_get($allowKey, []);

        $isInAllowList = in_array($ip, $allowList);

        if (!$isInAllowList) {

            // Track both by IP and individual password to stifle some botnet attempts.
            // This should be checked even if the password is correct (could be brute forced)
            $ipKey = 'pwThrottleIp:' . $ip;
            $pwKey = 'pwThrottlePw:' . $pwHashkey;

            if (self::isOverPasswordRateLimit($ipKey, $attemptsAllowedPerHour)) { return false; }
            if (self::isOverPasswordRateLimit($pwKey, $attemptsAllowedPerHour)) { return false; }
        }

        // Add IP to allowList - 10 days
        if ($isCorrectMatch && !$isInAllowList) {
            $allowList []= $ip;
            Tht::module('Cache')->u_set($allowKey, $allowList, 10 * 24 * 3600);
        }

        return $isCorrectMatch;
    }

    static private function isOverPasswordRateLimit($key, $attemptsAllowedPerHour) {

        $attempts = Tht::module('Cache')->u_get($key, []);
        $nowSecs = floor(microtime(true));

        foreach ($attempts as $tryTime) {
            if ($tryTime > $nowSecs - self::$THROTTLE_PASSWORD_WINDOW_SECS) {
                $recentAttempts []= $tryTime;
            }
        }

        $recentAttempts []= $nowSecs;

        if (count($recentAttempts) > $attemptsAllowedPerHour) {
            return true;
        }

        // Only write if allowed attempt, to prevent flooding cache
        $paddingSecs = 10;
        $cacheTtlSecs = self::$THROTTLE_PASSWORD_WINDOW_SECS + $paddingSecs;
        Tht::module('Cache')->u_set($key, $recentAttempts, $cacheTtlSecs);

        return false;
    }

    public static function isLocalServer() {

        $ip = self::getClientIp();

        return Tht::isMode('testServer') || $ip == '127.0.0.1' || $ip == '::1';
    }

    public static function assertIsOutsideDocRoot($path) {

        $docRoot = Tht::normalizeWinPath(
            Tht::getPhpGlobal('server', 'DOCUMENT_ROOT')
        );
        $inDocRoot = Tht::hasRootPath($path, $docRoot);

        if ($inDocRoot) {
            self::error("(Security) File `$path` can not be inside the Document Root.");
        }
    }

    static function initResponseHeaders() {

        if (headers_sent($atFile, $atLine)) {
            Tht::startupError("Unable to set response headers because output was already sent.");
        }

        // Set response headers
        header_remove('Server');
        header_remove("X-Powered-By");

        // This overlaps with frame-ancestors 'none' in the CSP
        // https://infosec.mozilla.org/guidelines/web_security.html#x-frame-options
        header('X-Frame-Options: deny');

        header('X-Content-Type-Options: nosniff');

        // HSTS - 1 year duration
        if (!self::isLocalServer()) {
            header("Strict-Transport-Security: max-age=31536000; includeSubDomains");
        }

        $csp = self::getCsp();
        if ($csp != 'xDangerNone') {
            header("Content-Security-Policy: $csp");
        }

        header('Cross-Origin-Resource-Policy: same-site');

        header('Cross-Origin-Embedder-Policy: require-corp');
        header('Cross-Origin-Opener-Policy: same-origin');
    }

    // Content Security Policy (CSP)
    static function getCsp() {

        $csp = Tht::getThtConfig('contentSecurityPolicy');

        if (!$csp) {
            $nonce = "'nonce-" . Tht::module('Web')->u_nonce() . "'";
            $eval = Tht::getThtConfig('xDangerAllowJsEval') ? '\'unsafe-eval\'' : '';

            // Yuck, apparently nonces don't work on iframes (https://github.com/w3c/webappsec-csp/issues/116)
            // TODO: make this a config param for whitelist
            $frame = "*";

            $csp = "default-src 'self'; script-src 'strict-dynamic' $eval $nonce; style-src 'unsafe-inline' *; img-src data: *; media-src data: *; font-src *; frame-src $frame; frame-ancestors 'none'";
        }

        return $csp;
    }

    // Set PHP ini
    static function initPhpIni() {

        // locale
        date_default_timezone_set(Tht::getTimezone());

        // encoding
        ini_set('default_charset', 'utf-8');
        mb_internal_encoding('utf-8');

        // resource limits
        ini_set('max_execution_time', Tht::getThtConfig('maxRunTimeSecs'));
        $memLimitMb = Tht::getThtConfig('maxMemoryMb');
        if ($memLimitMb < 1) {
            Tht::configError('Config value `maxMemoryMb` must be 1 MB or higher.');
        }
        ini_set('memory_limit', intval($memLimitMb) . "M");
    }

    // https://www.owasp.org/index.php/XSS_Filter_Evasion_Cheat_Sheet
    static function validateUserUrl($sUrl) {

        $sUrl = trim($sUrl);
        $url = self::parseUrl($sUrl);
        $sUrl = urldecode($sUrl);

        if (!preg_match('!^https?\://!i', $sUrl)) {
            // must be absolute
            return false;
        }
        else if (preg_match('!\.\.!', $sUrl)) {
            // No parent '..' patterns
            return false;
        }
        else if (preg_match('![\'\"\0\s<>\\\\]!', $sUrl)) {
            // Illegal characters
            return false;
        }
        else if (str_contains('&#', $sUrl)) {
            // No HTML escapes allowed
            return false;
        }
        else if (str_contains('%', $url['host'])) {
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

        if (str_contains($alpha, 'javascript:')) {
            $in = '(REMOVED:UNSAFE_URL)';
        }

        return $in;
    }

    static function escapeHtml($str, $options = '') {

        if (OTypeString::isa($str)) {
            $type = $str->u_string_type();
            $str = $str->u_render_string();
            if ($type == 'html') {
                return $str;
            }
        }

        if ($options == 'removeTags') {
            $str = self::removeHtmlTags($str);
        }

        return htmlspecialchars($str, ENT_QUOTES|ENT_HTML5, 'UTF-8');
    }

    static function escapeHtmlAllChars($str) {
        $str = mb_convert_encoding($str, 'UTF-32', 'UTF-8');
        $nums = unpack("N*", $str);
        $out = '';
        while (count($nums)) {
            $n = array_shift($nums);
            $out .= "&#$n;";
        }
        return $out;
    }

    // Only remove complete tags.  Assume standalone `<` and `>` will be escaped.
    static function removeHtmlTags($in) {

         return preg_replace('/<.*?>/s', '', $in);
    }

    static function unescapeHtml($in) {

         return htmlspecialchars_decode($in, ENT_QUOTES|ENT_HTML5);
    }

    static function sanitizeUrlHash($hash) {

        $hash = ltrim($hash, '#');
        $hash = preg_replace('![^a-z0-9\-]+!u', '-', strtolower($hash));
        $hash = trim($hash, '-');

        return $hash;
    }

    static function parseUrl($url) {

        // Remove user
        // https://www.cvedetails.com/cve/CVE-2016-10397/
        // $url = preg_replace('!(\/+).*@!', '$1', $url);
        // if (str_contains($url, '@')) {
        //     $url = preg_replace('!.*@!', '', $url);
        // }

        $url = preg_replace('!\s+!', '', $url);

        preg_match('!^(.*?)#(.*)$!', $url, $m);
        if (isset($m[2])) {
            $url = $m[1] . '#' . self::sanitizeUrlHash($m[2]);
        }

        $u = parse_url($url);

        if ($u === false) {
            Tht::error("Unable to parse URL: `" . $url . "`");
        }

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
                }
                else if ($u['scheme'] == 'https') {
                    $u['port'] = 443;
                }
                else {
                    $u['port'] = 0;
                }
            }
            else {
                $u['port'] = 80;
            }
        }

        if (isset($u['fragment']) && $u['fragment']) {
            $u['hash'] = $u['fragment'];
            unset($u['fragment']);
        }
        else {
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
    // ?foo=1&foo=2        -->  foo=2
    // ?foo[]=1&foo[]=2    -->  foo=[1,2]
    // ?foo[a]=1&foo[b]=2  -->  foo={a:1, b:2}
    static function stringifyQuery($queryMap) {

        $q = http_build_query(unv($queryMap), '', '&', PHP_QUERY_RFC3986);
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



