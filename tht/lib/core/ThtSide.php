<?php

require_once('../../lib/core/Tht.php');

// Global interface

class Tht {

    static function page($url) {
        return \o\Tht::sideloadPage($url);
    }

    static function module($mod) {
        return \o\Tht::sideloadModule($mod);
    }
}
