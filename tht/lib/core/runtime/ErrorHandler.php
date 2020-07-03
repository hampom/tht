<?php

namespace o;

class ThtError extends \Exception {
    function u_message () {
        return $this->getMessage();
    }
}

class StartupError extends \Exception {}


class ErrorHandler {

    static private $MEMORY_BUFFER_BYTES = 1024;

    static private $trapErrors = false;
    static private $trappedError = null;
    static private $errorDoc = null;
    static private $origins = [];
    static private $subOrigins = [];
    static private $topLevelFunction = [];
    static private $objectDetails = [];
    static private $memoryBuffer = '';

    static private function initErrorHandler () {

        // Reserve memory in case of out-of-memory error. Enough to call handleShutdown.
        self::$memoryBuffer = str_repeat('*', self::$MEMORY_BUFFER_BYTES);

        set_error_handler('\o\ErrorHandler::handlePhpRuntimeError');
        register_shutdown_function('\o\Tht::handleShutdown');
    }

    static function catchErrors($fnCallback) {

        self::initErrorHandler();

        try {
            return $fnCallback();
        }
        catch (StartupError $e) {
            self::handleStartupError($e);
        }
        catch (\ThtError $e) {
            // User exceptions
            self::handleThtRuntimeError($e);
        }
        catch (\TypeError $e) {
            // Catch these separately because they have extra caller info
            self::handleThtRuntimeError($e);
        }
        catch (\Error $e) {
            // Internal exceptions
            self::handleThtRuntimeError($e);
        }
        catch (\Exception $e) {
            // User exceptions
            self::handleThtRuntimeError($e);
        }
    }

    static function addOrigin($c) {
        self::$origins []= $c;
    }

    static function addSubOrigin($c) {
        self::$subOrigins []= $c;
    }

    static function addObjectDetails($title, $details) {
        self::$objectDetails = [
            'title' => $title,
            'details' => $details,
        ];
    }

    static function setTopLevelFunction($file, $fun) {
        $file = preg_replace('/.*\/(pages\/.*)/', '$1', $file);
        $file = preg_replace('/\.tht/', '', $file);
        $fun = preg_replace('/.*u_/', '', $fun);
        self::$topLevelFunction = [
            'file' => $file,
            'fun' => v($fun)->u_to_camel_case()
        ];
    }

    static function startTrapErrors() {
        self::$trapErrors = true;
        self::$trappedError = null;
    }

    static function endTrapErrors() {
        $trapped = self::$trappedError;
        self::resetState();
        return $trapped;
    }

    // Primarily used after try/catches
    static function resetState() {
        self::$trapErrors = false;
        self::$errorDoc = null;
        self::$origins = [];
        self::$subOrigins = [];
        self::$trappedError = null;
    }

    static function setErrorDoc($link, $name) {
        $link = str_replace('o\\u_', '', $link);
        $name = str_replace('o\\u_', '', $name);
        self::$errorDoc = ['link' => $link, 'name' => $name];
    }

    static function setOopErrorDoc() {
        self::setErrorDoc('/language-tour/classes-and-objects', 'Classes & Objects');
    }

    static private function clearMemoryBuffer() {
        self::$memoryBuffer = '';
    }

    static function printError($e) {

        if (ErrorHandler::$trapErrors) {
            ErrorHandler::$trappedError = $e;
            return;
        }

        if (!is_null(self::$errorDoc)) {
            $e['errorDoc'] = self::$errorDoc;
        }

        $e['objectDetails'] = null;
        if (!is_null(self::$objectDetails)) {
            $e['objectDetails'] = self::$objectDetails;
        }

        $e = self::initOrigin($e);

        $e['entry'] = self::$topLevelFunction;

        // Dynamically add output class, since this will rarely be needed
        require_once(__DIR__ . '/../runtime/ErrorHandlerOutput.php');

        ErrorHandlerOutput::printError($e);
    }

    static function printInlineWarning($msg) {
        $msg = htmlspecialchars($msg);
        print '<div style="background-color: #a33; color: white; font-size: 20px; padding: 16px 16px; font-family: sans-serif;">';
        print "THT Warning: " . $msg;
        print '</div>';
    }

    static function initOrigin($e) {
        $e['subOrigin'] = '';
        if (count(self::$origins)) {
            $e['subOrigin'] = implode('.', self::$origins);
        }
        if (count(self::$subOrigins)) {
            $e['subOrigin'] .= '.' . implode('.', self::$subOrigins);
        }
        if ($e['subOrigin']) {
            $e['origin'] .= '.' . $e['subOrigin'];
        }
        return $e;
    }

    static function parseInlineTrace($message) {
        if (!preg_match('/Stack trace:/i', $message)) {
            return null;
        }

        $trace = [];
        // example:
        // #0 /dir/cache/php/00300703_pages_home.tht.php(123): tht\pages\home_x\u_do_something('a')
        preg_match_all('/#\d+\s+(\S+?)\((\d+)\):\s+(\S+?)\n/', $message, $lines, PREG_SET_ORDER);
        foreach($lines as $line) {
            $fun = preg_replace('/\(.*\)/', '', $line[3]);
            $frame = [
                'file' => $line[1],
                'line' => $line[2],
                'function' => $fun,
            ];
            $trace []= $frame;
        }
        return $trace;
    }

    // Called from ErrorHandlerOutput
    static function saveTelemetry($error) {

        if (!Security::isAdmin() || !isset($error['src']) || $error['category'] != 'compiler') {
            return;
        }

        $cacheKey = 'tht.lastError|' . $error['src']['file'];

        $prevError = Tht::module('Cache')->u_get($cacheKey, '');
        if ($prevError && $prevError['message'] == $error['message']) {
            return;
        }

        $srcLine = preg_replace('/^\d+:\s*/', '', $error['srcLine']);
        $srcLine = preg_replace('/\s*\^\s*$/', '', $srcLine);

        $sendError = [
            'type'    => $error['origin'],
            'time'    => time(),
            'srcFile' => $error['src']['file'],
            'srcLine' => $srcLine,
            'message' => $error['message'],
        ];

        Tht::module('Cache')->u_set($cacheKey, $sendError, 0);
    }

    // Only send if error is followed by a good compile
    static function sendTelemetry($thtFile) {

        if (!Security::isAdmin() || !Compiler::getDidCompile() || !Tht::getConfig('sendErrors')) {
            return;
        }

        $relPath = Tht::getRelativePath('app', $thtFile);
        $cacheKey = 'tht.lastError|' . $relPath;
        $error = Tht::module('Cache')->u_get($cacheKey, '');

        if (!$error) {
            return;
        }

        Tht::module('Cache')->u_delete($cacheKey);

        require_once(__DIR__ . '/../compiler/SourceAnalyzer.php');

        $sa = new SourceAnalyzer ($thtFile);
        $stats = $sa->getCurrentStats();

        $mergeStats = [
            'linesInFile'      => $stats['numLines'],
            'functionsInFile'  => $stats['numFunctions'],
            'linesPerFunction' => $stats['numLinesPerFunction'],
            'totalWorkTime'    => $stats['totalWorkTime'],
            'numCompiles'      => $stats['numCompiles'],
        ];

        $error = array_merge($error, $mergeStats);

        $error['fixDurationSecs'] = time() - $error['time'];
        $error['thtVersion'] = Tht::getThtVersion(true);
        $error['phpVersion'] = PHP_VERSION_ID;
        $error['os'] = Tht::module('System')->u_os();

        try {
            $tUrl = new UrlTypeString(Tht::getConfig('_sendErrorUrl'));
            $res = Tht::module('Net')->u_http_post($tUrl, OMap::create($error));
        }
        catch (\Exception $e) {
            // Drop on floor
        }
    }




    // Handlers
    // --------------------------------


    static function handlePhpRuntimeError ($severity, $message, $phpFile, $phpLine) {

        $trace = debug_backtrace(0);

        self::printError([
            'category' => 'runtime',
            'origin'  => 'php.runtime',
            'message' => $message,
            'phpFile' => $phpFile,
            'phpLine' => $phpLine,
            'trace'   => $trace
        ]);
    }

    static function handleShutdown () {

        $error = error_get_last();

        if (!$error) {
            return;
        }

        $types = [ E_ERROR, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR ];
        if (!in_array($error['type'], $types)) {
            return;
        }

        self::handleResourceErrors($error);

        $trace = self::parseInlineTrace($error['message']);

        self::printError([
            'category' => 'runtime',
            'origin'  => 'php.shutdown',
            'message' => $error['message'],
            'phpFile' => $error['file'],
            'phpLine' => $error['line'],
            'trace'   => $trace,
        ]);
    }

    static function handleResourceErrors($error) {
        // Show minimal error message for memory and execution errors.
        preg_match('/Allowed memory size of (\d+)/i', $error['message'], $m);
        if ($m) {
            $max = Tht::getConfig('memoryLimitMb');
            print "<b>Page Error: Max memory limit exceeded ($max MB).  See `memoryLimitMb` in `app.jcon`.</b>";
            Tht::exitScript(1);
        }

        preg_match('/Maximum execution time of (\d+)/i', $error['message'], $m);
        if ($m) {
            $max = Tht::getConfig('maxExecutionTimeSecs');
            print "<b>Page Error: Max execution time exceeded ($max seconds).  See `maxExecutionTimeSecs` in `app.jcon`.</b>";
            Tht::exitScript(1);
        }
    }

    // Errors not related to a source file (e.g. config errors)
    static function handleConfigError ($message) {
        self::printError([
            'category' => 'startup',
            'origin'  => 'tht.settings',
            'message' => $message,
            'phpFile' => '',
            'phpLine' => 0,
            'trace'   => null
        ]);
    }

    // Triggered by Tht::error
    static function handleThtRuntimeError ($error) {

        $trace = $error->getTrace();
        $frame = [];

        $eFile = $error->getFile();
        $eLine = $error->getLine();

        $hasTempFrame = false;
        if ($eFile && $eLine) {
            $frame0 = ['file' => $eFile, 'line' => $eLine];
            array_unshift($trace, $frame0);
            $hasTempFrame = true;
        }

        // Find the first frame within THT space
        // Otherwise line is always "throw new ThtError"
        foreach ($trace as $f) {
            if (!isset($f['file'])) {
                $f['file'] = '(anon)';
            }
            if (strpos($f['file'], '.tht') !== false) {
                $frame = $f;
                break;
            }
        }

        $file = isset($frame['file']) ? $frame['file'] : '';
        $line = isset($frame['line']) ? $frame['line'] : '';

        if ($hasTempFrame) {
            array_shift($trace);
        }

        self::printError([
            'category' => 'runtime',
            'origin'  => 'tht.runtime',
            'message' => $error->getMessage(),
            'phpFile' => $file,
            'phpLine' => $line,
            'trace'   => $trace
        ]);
    }

    // PHP exception during startup
    static function handleStartupError ($error) {

        $phpFile = $error->getFile();
        $phpLine = $error->getLine();
        $message = $error->getMessage();

        preg_match("/with message '(.*)' in \//i", $message, $match);
        $msg = (isset($match[1]) ? $match[1] : $message);

        print '<h2>Startup Error</h2>' . $message;
        Tht::exitScript(1);
    }

    // In theory, this should never leak through to end users
    static function handleLeakedPhpRuntimeError ($error) {

        $phpFile = $error->getFile();
        $phpLine = $error->getLine();
        $message = $error->getMessage();

        preg_match("/with message '(.*)' in \//i", $message, $match);
        $msg = (isset($match[1]) ? $match[1] : $message);

        self::printError([
            'category'  => 'runtime',
            'origin'    => 'php.runtime.leaked',
            'message'   => $message,
            'phpFile'   => $phpFile,
            'phpLine'   => $phpLine,
            'trace'     => $error->getTrace(),
            '_rawTrace' => true
        ]);
    }

    static function handlePhpParseError ($msg) {

        $matches = [];
        $found = preg_match('/in (.*?) on line (\d+)/', $msg, $matches);

        if ($found) {
            $phpFile = $matches[1];
            $phpLine = $matches[2];
            $found2 = preg_match('/:(.*) in/', $msg, $matches);
            $phpMsg = $found2 ? trim($matches[1]) : '';
        }
        else {
            $phpFile = '';
            $phpLine = '';
            $phpMsg = $msg;
        }

        self::printError([
            'category' => 'compiler',
            'origin'   => 'php.parser',
            'message'  => $phpMsg,
            'phpFile'  => $phpFile,
            'phpLine'  => $phpLine,
            'trace'    => null
        ]);
    }

    static function handleThtCompilerError ($msg, $srcToken, $srcFile, $isLineError=false) {

        $srcPos = explode(',', $srcToken[TOKEN_POS]);
        $src = [
            'file' => $srcFile,
            'line' => $srcPos[0],
            'pos'  => $isLineError ? -1 : $srcPos[1]
        ];

        self::printError([
            'category' => 'compiler',
            'origin'   => 'tht.compiler',
            'message'  => $msg,
            'phpFile'  => '',
            'phpLine'  => '',
            'trace'    => Tht::getConfig('_coreDevMode') ? debug_backtrace() : null,
            'src'      => $src
        ]);
    }

    static function handleJconError ($msg, $srcFile, $lineNum, $line) {

        $src = [
            'file'    => $srcFile,
            'line'    => $lineNum,
            'pos'     => null,
            'srcLine' => $lineNum . ':  ' . trim($line),
        ];

        self::printError([
            'category' => 'runtime',
            'origin'   => 'jcon.parser',
            'message'  => $msg,
            'phpFile'  => '',
            'phpLine'  => '',
            'trace'    => null,
            'src'      => $src,
        ]);
    }

}
