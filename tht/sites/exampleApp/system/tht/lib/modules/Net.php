<?php

namespace o;

class u_Net extends OStdModule {

    function u_url_status($lUrl) {

        $this->ARGS('*', func_get_args());

        return $this->request('urlStatus', 'HEAD', $lUrl, '', []);
    }

    function u_http_get($lUrl, $headers=[]) {

        $this->ARGS('*mm', func_get_args());

        return $this->request('httpGet', 'GET', $lUrl, '', $headers);
    }

    function u_http_post($lUrl, $postData, $headers=[]) {

        $this->ARGS('**m', func_get_args());

        return $this->request('httpPost', 'POST', $lUrl, $postData, $headers);
    }

    function u_http_request($method, $lUrl, $postData='', $headers=[]) {

        $this->ARGS('s**m', func_get_args());

        return $this->request('httpRequest', $method, $lUrl, $postData, $headers);
    }

    private function formatHeaders($headersMap) {
        $sHeaders = '';
        foreach ($headersMap as $k => $v) {
            $sHeaders .= "$k: $v\r\n";
        }
        return $sHeaders;
    }

    private function request($functionName, $method, $lUrl, $postData, $headers) {

        $url = OTypeString::getUntyped($lUrl, 'url');
        if (!preg_match('/http(s?):\/\//i', $url)) {
            Tht::error('`' . $functionName . '()` expects a URL starting with `http://` or `https://`');
        }

        $method = strtoupper($method);
        $headers = unv($headers);

        // Add content-type for POST
        if ($method == 'POST') {
            $hasContentType = false;
            foreach ($headers as $k => $v) {
                if (strtolower($k) == 'content-type') {
                    $hasContentType = true;
                    break;
                }
            }
            if (!$hasContentType) {
                $headers['Content-type'] = 'application/x-www-form-urlencoded';
            }
        }

        if (!is_string($postData)) {
            if (!OMap::isa($postData)) {
                Tht::error('`postData` must be a String or a Map.');
            }
            $postData = http_build_query(unv($postData));
        }

        $opts = [
            'http' => [
                'method' => $method,
                'header' => $this->formatHeaders($headers),
                'content' => $postData,
            ]
        ];
        $context = stream_context_create($opts);


        Tht::module('Perf')->u_start('Net.httpRequest', $method . ' ' . $url);

        set_error_handler(function() { /* ignore errors */ });

        if ($functionName == 'urlStatus') {
            $response = get_headers($url, 0, $context);
        }
        else {
            $response = file_get_contents($url, false, $context);
        }

        restore_error_handler();

        Tht::module('Perf')->u_stop();

        if ($functionName == 'urlStatus') {
            if (!$response) {
                return 0;
            }

            $didMatch = preg_match('/http.*?(\d{3})\b/i', $response[0], $m);

            if (!$didMatch || !$m || !$m[1]) {
                return 0;
            }

            return (int)$m[1];
        }
        else if ($response === false) {
           Tht::error("Unable to open URL: `$url`");
        }

        return $response;
    }
}