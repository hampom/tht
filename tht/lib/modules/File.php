<?php

namespace o;

// TODO: test all on Windows (path separator)

// Not implemented. No plans to, unless necessary:
//   chdir, getcwd, is_link,

// TODO:
//   is_readable, is_writeable, is_executable
//   disk_free_space, disk_total_space

class u_File extends OStdModule {

    static private $EXT_TO_MIME = null;
    static private $MIME_TO_EXT = null;

    var $suggestMethod = [
        'size' => 'getSize',
        'open' => 'read',
    ];

    private $isSandboxDisabled = false;

    function _call ($fn, $args=[], $validationList='', $checkReturn=true) {

        Tht::module('Meta')->u_no_template_mode();

        // validate each argument against a validation pattern
        $validationPatterns = explode("|", $validationList);
        $fargs = [];
        foreach ($args as $a) {
            $fargs []= $this->checkArg($a, array_shift($validationPatterns));
        }

        $perfArg = is_string($args[0]) ? $args[0] : '';
        Tht::module('Perf')->u_start('File.' . $fn, $perfArg);
        $returnVal = \call_user_func_array($fn, $fargs);
        Tht::module('Perf')->u_stop();

        // Die on a false return value
        if ($checkReturn && $returnVal === false) {
            $relevantFile = '';
            if (isset($fargs[0])) { $relevantFile = $fargs[0]; }
            Tht::error("File function failed on `" . $relevantFile . "`");
        }

        return $returnVal;
    }

    function xDangerDisableSandbox() {
        $this->isSandboxDisabled = true;
    }

    // Validate argument against pattern
    function checkArg($a, $pattern) {

        if (strpos($pattern, '*') !== false) {
            // internally set, is ok
            return $a;
        }
        else if (strpos($pattern, 'string') !== false) {
            // TODO: check type
            return $a;
        }
        else if (strpos($pattern, 'num') !== false) {
            // TODO: check type
            return $a;
        }

        if (preg_match('/path|dir|file/', $pattern)) {
            // a path
            // TODO: check is_dir or is_file
            $a = $this->validatePath($a, !$this->isSandboxDisabled);

        }

        if (strpos($pattern, 'exists') !== false) {
            // path must exist
            if (!file_exists($a)) {
                Tht::error("File does not exist: `" . Tht::getRelativePath('data', $a) . "`");
            }
            if (strpos($pattern, 'dir') !== false) {
                if (!is_dir($a)) {
                    Tht::error("Path is not a directory: `" . Tht::getRelativePath('data', $a) . "`");
                }
            }
            if (strpos($pattern, 'file') !== false) {
                if (!is_file($a)) {
                    Tht::error("Path is not a file: `" . Tht::getRelativePath('data', $a) . "`");
                }
            }
        }

        return $a;
    }

    function validatePath($path, $checkSandbox=true) {
        return Security::validateFilePath($path, $checkSandbox);
    }


    // META

    function u_x_danger_no_sandbox() {
        $this->ARGS('', func_get_args());
        $f = new u_File();
        $f->xDangerDisableSandbox();
        return $f;
    }


    // READS

    function u_read ($fileName, $single=false) {
        $this->ARGS('sf', func_get_args());
        if ($single) {
            return $this->_call('file_get_contents', [$fileName], 'file,exists');
        } else {
            return $this->_call('file', [$fileName, FILE_IGNORE_NEW_LINES], 'file,exists|*');
        }
    }

    function u_read_lines ($fileName, $fn) {
        $this->ARGS('s*', func_get_args());
        $handle = $this->_call('fopen', [$fileName, ['r']], $fileName, true);
        $accum = [];
        while (true) {
            $line = fgets($handle);
            if ($line === false) { break; }
            $line = rtrim("\n");
            $ret = $fn($line);
            if ($ret === false) {
                break;
            }
            if (get_class($ret) !== 'ONothing' && $ret !== true) {
                $accum []= $ret;
            }
        }
        fclose($handle);
        return $accum;
    }



    // WRITES

    function u_write ($filePath, $data, $mode='replace') {
        $this->ARGS('s*s', func_get_args());
        $data = uv($data);
        if (is_array($data)) {
            $data = implode($data, "\n");
        }
        $mode = trim(strtolower($mode));

        if (!in_array($mode, ['replace', 'append', 'restore'])) {
            Tht::error("Unknown write mode `$mode`. Supported modes: `replace` (default), `append`, `restore`");
        }

        // Only write if the file does not exist
        if ($mode == 'restore' && $this->u_exists($filePath)) {
            return false;
        }

        // Make sure parent dir exists
        $parentPath = $this->u_parent_dir($filePath);
        if (!$this->u_is_dir($parentPath)) {
            Tht::error("Parent dir does not exist: `$parentPath`");
        }

        $arg = $mode == 'append' ? LOCK_EX|FILE_APPEND : LOCK_EX;
        return $this->_call('file_put_contents', [$filePath, $data, $arg], 'path|*|*');
    }

    function u_log ($data, $fileName='app.log') {
        $this->ARGS('*s', func_get_args());
        if (is_array($data) || is_object($data)) {
            $data = Tht::module('Json')->u_format($data);
        } else {
            $data = trim($data);
            $data = str_replace("\n", '\\n', $data);
        }
        $line = '[' . strftime('%Y-%m-%d %H:%M:%S') . "]  " . $data . "\n";

        return $this->_call('file_put_contents', [Tht::path('files', $fileName), $line, LOCK_EX|FILE_APPEND], 'file|string|*');
    }


    // PATHS

    function u_parse_path ($path) {

        $this->ARGS('s', func_get_args());

        $path = str_replace('\\', '/', $path);
        $this->validatePath($path, false);
        $info = $this->_call('pathinfo', [$path]);

        $dirs = explode('/', trim($info['dirname'], '/'));
        $dirList = [];
        foreach ($dirs as $d) {
            if ($d !== '.' && $d !== '') {
                $dirList []= $d;
            }
        }

        return OMap::create([
            'dirList'       => $dirList,
            'dirPath'       => $info['dirname'],
            'fileNameShort' => $info['filename'],
            'fileName'      => $info['basename'],
            'fileExt'       => $info['extension'],
            'fullPath'      => $path,
        ]);
    }

    function u_join_path () {
        $parts = func_get_args();
        $path = implode('/', uv($parts));
        $path = $this->validatePath($path, false);
        return $path;
    }

    function u_clean_path ($path) {
        $this->ARGS('s', func_get_args());
        return $this->validatePath($path, false);
    }

    function u_full_path ($relPath) {
        $this->ARGS('s', func_get_args());
        return $this->_call('realpath', [$relPath], 'path');
    }

    function u_relative_path ($fullPath, $rootPath) {

        $this->ARGS('ss', func_get_args());

        $rootPath = $this->validatePath($rootPath, false);
        $fullPath = $this->validatePath($fullPath, false);

        // TODO: both must be absolute

        if (!$this->u_has_root_path($fullPath, $rootPath)) {
            Tht::error('Root path not found in full path.', [ 'fullPath' => $fullPath, 'rootPath' => $rootPath ]);
        }

        $relPath = substr($fullPath, strlen($rootPath));
        $relPath = ltrim($relPath, '/');

        return $relPath;
    }

    function u_root_path ($fullPath, $relPath) {
        $this->ARGS('ss', func_get_args());
        $relPath  = $this->validatePath($relPath, false);
        $fullPath = $this->validatePath($fullPath, false);

        // TODO: assert rel and absolute

        if (!$this->u_has_root_path($fullPath, $rootPath)) {
            Tht::error('Root path not found in full path.', [ 'fullPath' => $fullPath, 'rootPath' => $rootPath ]);
        }

        $relPath = substr($fullPath, strlen($rootPath));
        $relPath = ltrim($relPath, '/');

        return $relPath;
    }

    // TODO: don't work in substrings.  Work in path segments.

    function u_has_root_path($fullPath, $rootPath) {
        $this->ARGS('ss', func_get_args());
        $fullPath = $this->validatePath($fullPath, false);
        $rootPath = $this->validatePath($rootPath, false);
        return strpos($fullPath, $rootPath) === 0;
    }

    function u_has_relative_path($fullPath, $relPath) {
        $this->ARGS('ss', func_get_args());
        $fullPath = $this->validatePath($fullPath, false);
        $relPath  = $this->validatePath($relPath, false);
        $offset = strlen($fullPath) - strlen($relPath);
        return strpos($fullPath, $relPath) === $offset;
    }

    function u_is_relative_path($p) {
        $this->ARGS('s', func_get_args());
        $p = $this->validatePath($p, false);
        return $p[0] !== '/';
    }

    function u_is_absolute_path($p) {
        $this->ARGS('s', func_get_args());
        $p = $this->validatePath($p, false);
        return $p[0] === '/';
    }

    // TODO: different for abs and rel paths
    // TODO: bounds check
    function u_parent_dir($p) {
        $this->ARGS('s', func_get_args());
        $p = rtrim($p, '/');
        $p = $this->validatePath($p, false);
        $parentPath = preg_replace('~/.*?$~', '', $p);
        return strlen($parentPath) ? $parentPath : '/';
    }

    function u_app_root() {
        $this->ARGS('', func_get_args());
        Tht::module('Meta')->u_no_template_mode();
        return Tht::path('app');
    }

    function u_document_root() {
        $this->ARGS('', func_get_args());
        Tht::module('Meta')->u_no_template_mode();
        return Tht::path('docRoot');
    }



    // MOVE, etc.

    function u_delete ($filePath) {
        $this->ARGS('s', func_get_args());
        if (is_dir($filePath)) {
            Tht::error("Argument 1 for `delete` must not be a directory: `$filePath`.  Suggestion: `File.deleteDir()`");
        }
        return $this->_call('unlink', [$filePath], 'file');
    }

    function u_delete_dir ($dirPath) {
        $this->ARGS('s', func_get_args());
        $checkPath = $this->validatePath($dirPath, !$this->isSandboxDisabled);
        if (is_dir($checkPath)) {
            $this->deleteDirRecursive($dirPath);
        }
        else {
            Tht::error("Argument 1 for `deleteDir` is not a directory: `$dirPath`");
        }
    }

    function deleteDirRecursive ($dirPath) {
        $this->ARGS('s', func_get_args());
        $dirPath = $this->validatePath($dirPath, !$this->isSandboxDisabled);

        // recursively delete dir contents
        $dirHandle = opendir($dirPath);
        while (true) {
            $file = readdir($dirHandle);
            if (!$file) { break; }
            if ($file === "." || $file === "..") {
                continue;
            }
            $subPath = $dirPath . "/" . $file;

            if (is_dir($subPath)) {
                $this->deleteDirRecursive($subPath);
            } else {
                // delete file
                $this->_call('unlink', [$subPath], 'file');
            }
        }
        closedir($dirHandle);

        $this->_call('rmdir', [$dirPath], 'dir');
    }

    function u_copy ($source, $dest) {
        $this->ARGS('ss', func_get_args());
        if (is_dir($source)) {
            Tht::error("Argument 1 for `copy` must not be a directory: `$source`.  Suggestion: `File.copyDir()`");
        }
        return $this->_call('copy', [$source, $dest], 'file,exists|path');
    }

    function u_copy_dir ($source, $dest) {
        $this->ARGS('ss', func_get_args());
        if (is_dir($source)) {
            $this->copyDirRecursive($source, $dest);
        }
        else {
            Tht::error("Argument 1 for `copyDir` is not a directory: `$source`");
        }
    }

    function copyDirRecursive($source, $dest) {
        $this->ARGS('ss', func_get_args());
        if (!is_dir($dest)) {
            $this->_call('mkdir', [$dest, 0755]);
        }

        // recursively copy dir contents
        $dirHandle = opendir($source);
        while (true) {
            $file = readdir($dirHandle);
            if (!$file) { break; }
            if ($file === "." || $file === "..") {
                continue;
            }
            $subSource = $source . "/" . $file;
            $subDest = $dest . "/" . $file;

            if (is_dir($subSource)) {
                $this->copyDirRecursive($subSource, $subDest);
            } else {
                // copy file
                $this->_call('copy', [$subSource, $subDest], '');
            }
        }

        closedir($dirHandle);
    }

    function u_move ($oldName, $newName) {
        $this->ARGS('ss', func_get_args());
        return $this->_call('rename', [$oldName, $newName], 'path,exists|path');
    }

    function u_exists ($path) {
        $this->ARGS('s', func_get_args());
        return $this->_call('file_exists', [$path], 'path', false);
    }

    // TODO: no path?
    function u_find ($pattern, $dirOnly=false) {
        $this->ARGS('sf', func_get_args());
        $flags = $dirOnly ? GLOB_BRACE|GLOB_ONLYDIR : GLOB_BRACE;
        return $this->_call('glob', [$pattern, $flags], 'string|*');
    }

    function u_touch ($file, $time=null, $atime=null) {
        $this->ARGS('snn', func_get_args());
        if (!$time) { $time = time(); }
        if (!$atime) { $atime = time(); }
        return $this->_call('touch', [$file, $time, $atime], 'file|num|num');
    }


    // DIRS

    function u_make_dir ($dir, $perms='775') {
        $this->ARGS('ss', func_get_args());
        if ($this->u_exists($dir)) {
            return false;
        }
        $perms = octdec($perms);
        return $this->_call('mkdir', [$dir, $perms, true], 'path|num|*', null);
    }

    function u_open_dir ($d) {
        $this->ARGS('s', func_get_args());
        $dh = $this->_call('opendir', [$d], 'dir,exists');
        return new \FileDir ($dh);
    }

    // TODO: integrate with find/glob?
    // TODO: recursive
    // TODO: functional interface for better perf?
    function u_read_dir ($d, $filter = 'none') {

        $this->ARGS('ss', func_get_args());
        if (!in_array($filter, ['none', 'files', 'dirs'])) {
            Tht::error("Unknown filter `$filter`. Supported filters: `none` (default), `files`, `dirs`");
        }

        $files = $this->_call('scandir', [$d], 'dir,exists');

            $filteredFiles = [];
            foreach ($files as $f) {
                if ($f === '.' || $f === '..' || $f === '.DS_Store') {
                    continue;
                }

                if ($filter && $filter !== 'none') {
                    $isDir = is_dir($f);
                    if ($filter === 'dirs') {
                        if ($isDir) {  $filteredFiles []= $f;  }
                    } else if ($filter === 'files') {
                        if (!$isDir) {  $filteredFiles []= $f;  }
                    }
                }
                else {
                    $filteredFiles []= $f;
                }
            }
            $files = $filteredFiles;

        return $files;
    }

    // TODO: file ext filter
    // TODO: merge with read_dir
    function u_for_files($dirPath, $fn, $goDeep=false) {

        $this->ARGS('scf', func_get_args());
        $dirPath = $this->validatePath($dirPath, !$this->isSandboxDisabled);

        $agg = [];
        $dirStack = [$dirPath];
        $dirHandle = null;

        $ignoreFiles = ['.', '..', '.DS_Store', 'thumbs.db', 'desktop.ini'];

        while (true) {

            if (!$dirHandle) {
                if (count($dirStack)) {
                    $dirPath = array_pop($dirStack);
                    $dirHandle = opendir($dirPath);
                }
                else {
                    break;
                }
            }

            $file = readdir($dirHandle);
            if (!$file) {
                // last file in dir
                closedir($dirHandle);
                $dirHandle = null;
                continue;
            }
            else if (in_array($file, $ignoreFiles)) {
                continue;
            }

            $subPath = $dirPath . "/" . $file;
            $isDir = is_dir($subPath);
            if ($goDeep && $isDir) {
                $dirStack []= $subPath;
            }
            else {
                $fileInfo = $this->u_parse_path($subPath);
                $fileInfo['isDir'] = $isDir;
                $fileInfo['isFile'] = !$isDir;
                $ret = $fn($fileInfo);
                if ($ret === false) {
                    break;
                }
                if (!is_null($ret)) {
                    $agg []= $ret;
                }
            }
        }
        return OList::create($agg);
    }


    // FILE ATTRIBUTES

    function u_get_size ($f) {
        $this->ARGS('s', func_get_args());
        return $this->_call('filesize', [$f], 'file,exists');
    }

    function u_get_modify_time ($f) {
        $this->ARGS('s', func_get_args());
        return $this->_call('filemtime', [$f], 'path,exists');
    }

    function u_get_create_time ($f) {
        $this->ARGS('s', func_get_args());
        return $this->_call('filectime', [$f], 'path,exists');
    }

    function u_get_access_time ($f) {
        $this->ARGS('s', func_get_args());
        return $this->_call('fileatime', [$f], 'path,exists');
    }

    function u_is_dir ($f) {
        $this->ARGS('s', func_get_args());
        return $this->_call('is_dir', [$f], 'path', false);
    }

    function u_is_file ($f) {
        $this->ARGS('s', func_get_args());
        return $this->_call('is_file', [$f], 'path', false);
    }

    function u_get_mime_type ($f) {
        $this->ARGS('s', func_get_args());
        $finfo = finfo_open(FILEINFO_MIME_TYPE);
        return $this->_call('finfo_file', [$finfo, $f], '*|file,exists', true);
    }

    function u_extension_to_mime_type($ext) {

        $this->ARGS('s', func_get_args());

        $this->initMimeMap();

        $ext = strtolower($ext);
        $ext = ltrim($ext, '.');
        if (isset(self::$EXT_TO_MIME[$ext])) {
            return self::$EXT_TO_MIME[$ext];
        }
        else {
            return 'application/octet-stream';
        }
    }

    function u_mime_type_to_extension($mime) {

        $this->ARGS('s', func_get_args());

        $this->initMimeMap();

        $mime = strtolower($mime);
        if (isset(self::$MIME_TO_EXT[$mime])) {
            return self::$MIME_TO_EXT[$mime];
        }
        else {
            return '';
        }
    }

    // A list of the most common MIME types.
    // https://developer.mozilla.org/en-US/docs/Web/HTTP/Basics_of_HTTP/MIME_types/Complete_list_of_MIME_types
    function initMimeMap() {

        if (self::$EXT_TO_MIME) {
            return;
        }

        self::$EXT_TO_MIME = [
           'aac'  => 'audio/aac',
           'abw'  => 'application/x-abiword',
           'arc'  => 'application/x-freearc',
           'avi'  => 'video/x-msvideo',
           'azw'  => 'application/vnd.amazon.ebook',
           'bin'  => 'application/octet-stream',
           'bmp'  => 'image/bmp',
           'bz'   => 'application/x-bzip',
           'bz2'  => 'application/x-bzip2',
           'css'  => 'text/css',
           'csv'  => 'text/csv',
           'doc'  => 'application/msword',
           'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
           'eot'  => 'application/vnd.ms-fontobject',
           'epub' => 'application/epub+zip',
           'flv'  => 'video/x-flv',
           'gz'   => 'application/gzip',
           'gif'  => 'image/gif',
           'htm'  => 'text/html',
           'html' => 'text/html',
           'ico'  => 'image/vnd.microsoft.icon',
           'ics'  => 'text/calendar',
           'jar'  => 'application/java-archive',
           'jpeg' => 'image/jpeg',
           'jpg'  => 'image/jpeg',
           'js'   => 'text/javascript',
           'json' => 'application/json',
           'mid'  => 'audio/midi',
           'midi' => 'audio/midi',
           'mjs'  => 'text/javascript',
           'mov'  => 'video/quicktime',
           'mp3'  => 'audio/mpeg',
           'mp4'  => 'video/mpeg',
           'mpeg' => 'video/mpeg',
           'mpkg' => 'application/vnd.apple.installer+xml',
           'odp'  => 'application/vnd.oasis.opendocument.presentation',
           'ods'  => 'application/vnd.oasis.opendocument.spreadsheet',
           'odt'  => 'application/vnd.oasis.opendocument.text',
           'oga'  => 'audio/ogg',
           'ogv'  => 'video/ogg',
           'ogx'  => 'application/ogg',
           'otf'  => 'font/otf',
           'png'  => 'image/png',
           'pdf'  => 'application/pdf',
           'php'  => 'application/php',
           'ppt'  => 'application/vnd.ms-powerpoint',
           'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
           'rar'  => 'application/x-rar-compressed',
           'rtf'  => 'application/rtf',
           'sh'   => 'application/x-sh',
           'svg'  => 'image/svg+xml',
           'swf'  => 'application/x-shockwave-flash',
           'tar'  => 'application/x-tar',
           'tif'  => 'image/tiff',
           'tiff' => 'image/tiff',
           'ts'   => 'video/mp2t',
           'ttf'  => 'font/ttf',
           'txt'  => 'text/plain',
           'vsd'  => 'application/vnd.visio',
           'wav'  => 'audio/wav',
           'weba' => 'audio/webm',
           'webm' => 'video/webm',
           'webp' => 'image/webp',
           'wmv'  => 'video/x-ms-wmv',
           'woff' => 'font/woff',
           'woff2' => 'font/woff2',
           'xhtml' => 'application/xhtml+xml',
           'xls'  => 'application/vnd.ms-excel',
           'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
           'xml'  => 'text/xml',
           'xul'  => 'application/vnd.mozilla.xul+xml',
           'zip'  => 'application/zip',
           '3gp'  => 'video/3gpp audio/3gpp',
           '3g2'  => 'video/3gpp2 audio/3gpp2',
           '7z'   => 'application/x-7z-compressed',
       ];

       self::$MIME_TO_EXT = array_flip(self::$EXT_TO_MIME);
    }

}

