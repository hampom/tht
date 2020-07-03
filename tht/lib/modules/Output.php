<?php

namespace o;

class u_Output extends OStdModule {

    private $gzipBufferOpen = false;
    public $sentResponseType = '';

    function u_run_route($path) {
        $this->ARGS('s', func_get_args());
        WebMode::runRoute($path);
        return new \o\ONothing('runRoute');
    }

    function u_redirect ($lUrl, $code=303) {
        $this->ARGS('*n', func_get_args());

        $url = OTypeString::getUntyped($lUrl, 'url');
        header('Location: ' . $url, true, $code);
        Tht::exitScript(0);
    }

    function u_set_response_code ($code) {
        $this->ARGS('n', func_get_args());
        http_response_code($code);

        return new \o\ONothing('setResponseCode');
    }

    function u_set_header ($name, $value, $multiple=false) {
        $this->ARGS('ssf', func_get_args());

        if ($this->hasPreOutput()) {
            $this->error("Can't set response header because output was already sent: `$name: $value`");
        }

        $value = preg_replace('/\s+/', ' ', $value);
        $name = preg_replace('/[^a-z0-9\-]/', '', strtolower($name));
        header($name . ': ' . $value, !$multiple);

        return new \o\ONothing('setHeader');
    }

    function u_set_cache_header ($expiry='+1 year') {
        $this->ARGS('s', func_get_args());

        $this->u_set_header('Expires', gmdate('D, d M Y H:i:s \G\M\T', strtotime($expiry)));

        return new \o\ONothing('setCacheHeader');
    }



    // SEND DOCUMENTS
    // --------------------------------------------

    // function u_print_block($h, $title='') {
    //     $html = OTypeString::getUntyped($h);
    //     $this->u_send_json([
    //         'status' => 'ok',
    //         'title' => $title,
    //         'html' => $html
    //     ]);
    // }

    function output($out) {
        $this->startGzip();
        print $out;
    }

    function sendByType($lout) {
        $type = $lout->u_string_type();

        if ($type == 'css') {
            return $this->u_send_css($lout);
        }
        else if ($type == 'js') {
            return $this->u_send_js($lout);
        }
        else if ($type == 'url') {
            Tht::module('Output')->u_redirect($lout);
        }
    }

    function renderChunks($chunks) {

        // Normalize. Could be a single TypeString, OList, or a PHP array
        if (! (is_object($chunks) && v($chunks)->u_type() == 'list')) {
            $chunks = OList::create([ $chunks ]);
        }

        $out = '';
        foreach ($chunks->val as $c) {
            $out .= OTypeString::getUntyped($c, '');
        }
        return $out;
    }

    function u_send_json ($map) {
        $this->ARGS('m', func_get_args());

        $this->u_set_header('Content-Type', 'application/json; charset=utf-8');
        $this->output(json_encode(uv($map)));

        $this->sentResponseType = 'json';

        Tht::module('Web')->u_skip_hit_counter(true);

        return new \o\ONothing('sendJson');
    }

    function u_send_text ($text) {
        $this->ARGS('s', func_get_args());

        $this->u_set_header('Content-Type', 'text/plain; charset=utf-8');

        $this->output($text);

        $this->sentResponseType = 'text';

        return new \o\ONothing('sendText');
    }

    function u_send_css ($chunks) {

        $this->ARGS('*', func_get_args());

        $this->u_set_header('Content-Type', 'text/css; charset=utf-8');
        $this->u_set_cache_header();

        $out = $this->renderChunks($chunks);
        $this->output($out);

        $this->sentResponseType = 'css';

        Tht::module('Web')->u_skip_hit_counter(true);

        return new \o\ONothing('sendCss');
    }

    function u_send_js ($chunks) {

        $this->ARGS('*', func_get_args());

        $this->u_set_header('Content-Type', 'application/javascript; charset=utf-8');
        $this->u_set_cache_header();

        $out = "(function(){\n";
        $out .= $this->renderChunks($chunks);
        $out .= "\n})();";

        $this->output($out);

        $this->sentResponseType = 'js';

        Tht::module('Web')->u_skip_hit_counter(true);

        return new \o\ONothing('sendJs');
    }

    function u_send_html ($html) {
        $html = OTypeString::getUntyped($html, 'html');
        $this->output($html);

        $this->sentResponseType = 'html';

        return new \o\ONothing('sendHtml');
    }

    function u_x_danger_send ($s) {
        $this->ARGS('s', func_get_args());
        print $s;
        $this->sentResponseType = 'raw';
        return new \o\ONothing('xDangerSend');
    }

    // Print a well-formed HTML document with sensible defaults
    function u_send_page ($doc) {

        $this->ARGS('m', func_get_args());

        $val = [];

        $val['body'] = '';

        if ($doc['body']) {
            $chunks = [];
            if (OList::isa($doc['body'])) {
                $chunks = $doc['body'];
            } else {
                $chunks = [$doc['body']];
            }

            foreach ($chunks as $c) {
                $val['body'] .= OTypeString::getUntyped($c, 'html');
            }
        }

        // if (u_Web::u_is_ajax()) {
        //     u_Web::u_send_block($body, $header['title']);
        //     return;
        // }

        $val['css'] = $this->assetTags(
            'css',
            $doc['css'],
            OTypeString::create('html', '<link rel="stylesheet" href="{url}" />'),
            OTypeString::create('html', '<style>{body}</style>')
        );

        $val['js'] = $this->assetTags(
            'js',
            $doc['js'],
            OTypeString::create('html', '<script src="{url}" nonce="{nonce}"></script>'),
            OTypeString::create('html', '<script nonce="{nonce}">{body}</script>')
        );

        $val['title'] = $doc['title'];
        $val['description'] = isset($doc['description']) ? $doc['description'] : '';

        // TODO: get updateTime of the files, allow base64 urls
        $val['image'] = $this->headTag($doc['image'], '<meta property="og:image" content="{url}">');
        $val['icon'] = $this->headTag($doc['icon'], '<link rel="icon" href="{url}">');

        $val['bodyClass'] = Tht::module('Web')->getClassProp($doc['bodyClass']);

        $val['comment'] = '';
        if (isset($doc['comment'])) {
            $val['comment'] = "\n<!--\n\n" . v(v(v($doc['comment'])->u_stringify())->u_indent(4))->u_trim_right() . "\n\n-->";
        }

        $out = $this->pageTemplate($val);

        $this->output($out);

        $this->sentResponseType = 'html';

        return new \o\ONothing('sendPage');
    }

    function pageTemplate($val) {
        return <<<HTML
<!doctype html>$val[comment]
<html>
<head>
<title>$val[title]</title>
<meta name="description" content="$val[description]"/>
<meta name="viewport" content="width=device-width, initial-scale=1.0"/>
<meta property="og:title" content="$val[title]"/>
<meta property="og:description" content="$val[description]"/>
$val[image] $val[icon] $val[css]
</head>
<body class="$val[bodyClass]">
$val[body]
$val[js]
</body>
</html>
HTML;
    }

    function u_send_error ($code, $title='', $desc='') {

        $this->ARGS('nss', func_get_args());

        http_response_code($code);

        if ($code !== 500) {
            // User custom error page
            WebMode::runStaticRoute($code);
        }

        // User custom error page
        // $errorPage = Tht::module('File')->u_document_path($code . '.html');
        // if (file_exists($errorPage)) {
        //     print(file_get_contents($errorPage));
        //     exit(1);
        // }

        if (!Tht::module('Request')->u_is_ajax()) {

            if (!$title) {
                $title = $code === 404 ? 'Page Not Found' : 'Website Error';
            }

            ?><html><head><title><?= $title ?></title></head><body>
            <div style="text-align: center; color:#333; font-family: <?= Tht::module('Css')->u_font('sansSerif') ?>;">
            <h1 style="margin-top: 40px;"><?= $title ?></h1>
            <?php if ($desc) { ?>
            <div style="margin-top: 40px;"><?= $desc ?></div>
            <?php } ?>
            <div style="margin-top: 40px"><a style="text-decoration: none; font-size: 20px;" href="/">Home Page</a></div></div>
            </body></html><?php
        }

        Tht::exitScript(1);
    }

    function headTag($val, $template) {
        if (!$val) { return ''; }
        $tHtml = OTypeString::create('html', $template);
        return $tHtml->u_fill(OMap::create(['url' => $val]))->u_stringify() . "\n";
    }

    // print css & js tags
    function assetTags ($type, $paths, $incTag, $blockTag) {

        $paths = uv($paths);
        if (!is_array($paths)) {
            $paths = !$paths ? [] : [$paths];
        }
        // if ($type == 'js') {
        //     $jsData = Tht::module('Js')->u_plugin('jsData');
        //     if ($jsData) { array_unshift($paths, $jsData); }
        // }

        if (!count($paths)) { return ''; }

        $nonce = Tht::module('Web')->u_nonce();

        $includes = [];
        $blocks = [];
        foreach ($paths as $path) {

            if (!OTypeString::isa($path)) {
                $this->error("Path must be a `url` TypeString or `$type` TypeString: `$path`");
            }

            if (HtmlTypeString::isa($path)) {
                $tag = $path->u_stringify();
                $blocks []= $tag;
            }
            else if (!UrlTypeString::isa($path)){

                // Inline it in the HTML document
                $str = $path->u_stringify();
                if ($type == 'js' && !preg_match('#\s*\(function\(\)\{#', $str)) {
                    $str = "(function(){" . $str . "})();";
                }

                $vals = [
                    'body' => OTypeString::create('html', $str),
                ];
                if ($type == 'js') {
                    $vals['nonce'] = $nonce;
                }
                $blockTag->u_fill(OMap::create($vals));
                $blocks []= $blockTag->u_stringify();
            }
            else {
                if ($path->u_is_relative()) {
                    // TODO: Link to asset, with cache time set to file modtime

                    // $basePath = ;
                    // if (defined('BASE_URL')) {
                    //     $basePath = preg_replace('#' . BASE_URL . '#', '', $basePath);
                    // }

                    // $filePath = Tht::getThtFileName(Tht::path('pages', $basePath));
                    // $path->u_query([ 'cache' => filemtime($filePath));
                }

                $vals = [
                    'url' => $path
                ];
                if ($type == 'js') {
                    $vals['nonce'] = $nonce;
                }
                $incTag->u_fill(OMap::create($vals));
                $includes []= $incTag->u_stringify();
            }
        }

        $sIncludes = implode("\n", $includes);
        $sBlocks = implode("\n\n", $blocks);

        return $sIncludes . "\n" . $sBlocks;
    }

    function startGzip ($forceGzip=false) {
        if ($this->gzipBufferOpen) { return; }
        if ($forceGzip || Tht::getConfig('compressOutput')) {
            if ($this->hasPreOutput()) {
                ErrorHandler::printInlineWarning('(Response module) Can\'t enable GZIP compression because output was already sent. Solution: Either delay this output or set `compressOutput` = `false` in `app.jcon` (not recommended).');
            }
            ob_start("ob_gzhandler");
            $this->gzipBufferOpen = true;
        }
    }

    function endGzip ($forceGzip=false) {
        if ($this->gzipBufferOpen) {
            ob_flush();
        }
    }

    function hasPreOutput() {
        $ob = ob_get_length();
        if ($ob) {
            ob_flush();
        }
        return $ob || headers_sent($atFile, $atLine);
    }
}