<?php

namespace o;

class CliMode {

    static private $SERVER_PORT = 8888;
    static private $SERVER_HOSTNAME = 'localhost';

    static private $CLI_OPTIONS = [
        'new'    => 'new',
        'server' => 'server',
        'info'   => 'info',
        'fix'    => 'fix',
        'images' => 'images',
     // 'run'    => 'run',
    ];



    static private $options = [];

    static function main() {

        Tht::initAppPaths(true);

        self::initOptions();
        $firstOption = self::$options[0];

        if ($firstOption === self::$CLI_OPTIONS['new']) {
            self::installApp();
        }
        else if ($firstOption === self::$CLI_OPTIONS['server']) {
            $port = isset(self::$options[1]) ? self::$options[1] : 0;
            self::startTestServer($port);
        }
        else if ($firstOption === self::$CLI_OPTIONS['info']) {
            self::info();
        }
        else if ($firstOption === self::$CLI_OPTIONS['fix']) {
            self::fix();
        }
        else if ($firstOption === self::$CLI_OPTIONS['images']) {
            $actionOrDir = isset(self::$options[1]) ? self::$options[1] : 0;
            Tht::module('Image')->optimizeImages($actionOrDir);
        }
        // else if ($firstOption === self::$CLI_OPTIONS['run']) {
        //     // Tht::init();
        //     // Compiler::process(self::$options[1]);
        // }
        else {
            self::printUsage();
        }
    }

    static private function printUsage() {

        self::printHeaderBox('THT');

        echo "Version: " . Tht::getThtVersion() . "\n\n";
        echo "Usage: tht [command]\n\n";
        echo "Commands:\n\n";
        echo "  new             create an app in the current dir\n";
        echo "  fix             clear app cache and update file permissions\n";
        echo "  info            get detailed settings and install info\n";
        echo "  server          start the local test server (port: 8888)\n";
        echo "  server <port>   start the local test server on a custom port\n";
     //   echo "  images          compress images in your document root by up to 70%\n";
     // echo "tht run <filename>   (run script in scripts directory)\n";
        echo "\n";
        Tht::exitScript(0);
    }

    static function printHeaderBox($title) {
        $title = trim(strtoupper($title));
        $line = str_repeat('-', strlen($title) + 8);
        echo "\n";
        echo "+$line+\n";
        echo "|    $title    |\n";
        echo "+$line+\n\n";
    }

    static private function initOptions () {
        global $argv;
        if (count($argv) === 1) {
            self::printUsage();
        }
        self::$options = array_slice($argv, 1);
    }

    static function isAppInstalled () {
        $appRoot = Tht::path('app');
        return $appRoot && file_exists($appRoot);
    }

    static private function info() {

        $info = [

            'THT Version'  => Tht::getThtVersion(),
            'PHP Version'  => Tht::module('Php')->u_version(),

            'php.ini File' => php_ini_loaded_file(),

      //      'Document Root' => Tht::module('File')->u_document_root(),
      //      'App Root'      => Tht::module('File')->u_app_root(),
        ];


        self::printHeaderBox('THT Info');
        foreach ($info as $k => $v) {
            echo "$k:\t$v\n";
        }
        echo "\n";
    }

    static private function fix() {
        self::printHeaderBox('Fix THT App');

        $appDir = Tht::path('app');
        if (!file_exists($appDir)) {
            echo "Please cd into the app directory.\n\n";
        }

        $msg = 'Set app file permissions?';
        if (Tht::module('System')->u_confirm($msg)) {
            self::setPerms();
        }

        self::clearCache('Transpiler', 'phpCache');
        self::clearCache('App', 'kvCache');

        // TODO: create missing app directories or config file

        // TODO: check local THT version and copy to app if updated

        echo "\n--- DONE ---\n\n";
    }

    static private function clearCache($name, $dirKey) {
        echo "\n- Clearing $name Cache -";
        $num = 0;

        $files = glob(Tht::path($dirKey, '*'));
        foreach($files as $file){
            if (is_file($file)) {
                unlink($file);
                $num += 1;
            }
        }
        echo "\nCache files deleted: $num\n";
    }

    static private function setPerms() {

        echo "\n";
        $currentUser = get_current_user();

        $devUser = Tht::module('System')->u_input('Name of developer user (' . $currentUser . ')?', $currentUser);
        $wwwGroup = Tht::module('System')->u_input('Name of web server group (www-data)?', 'www-data');

        $user    = escapeshellarg($devUser);
        $group   = escapeshellarg($wwwGroup);
        $dir     = escapeshellarg(Tht::path('app'));
        $dataDir = escapeshellarg(Tht::path('data'));

        echo "\n- Setting App File Permissions -\n\n";

        self::setPerm("chown -R $user $dir");
        self::setPerm("chgrp -R $group $dir");

        self::setPerm("chmod -R 750 $dir");
        self::setPerm("chmod -R g+w $dataDir");

        echo "\n[ OK ]\n";
    }

    static private function setPerm($cmd) {
        echo $cmd . "\n";
        $ok = exec($cmd, $out, $retval);
        if ($retval) {
            echo "\n* Error setting permissions.  Run as sudo?\n\n";
            Tht::exitScript(1);
        }
    }

    static private function confirmInstall() {

        self::printHeaderBox('New App');

        if (file_exists(Tht::path('app'))) {
            echo "\nA THT app directory already exists:\n  " .  Tht::path('app') . "\n\n";
            echo "To start over, just delete or move that directory. Then rerun this command.\n\n";
            Tht::exitScript(1);
        }

        $msg = "Your Document Root is:\n  " . Tht::path('docRoot') . "\n\n";
        $msg .= "Install THT app?";

        if (!Tht::module('System')->u_confirm($msg)) {
            echo "\nPlease 'cd' to your public Document Root directory.  Then rerun this command.\n\n";
            Tht::exitScript(0);
        }

        usleep(500000);
    }

    static private function installApp () {

        self::confirmInstall();

        try {

            // create directory tree
            foreach (Tht::getAllPaths() as $id => $p) {
                if (substr($id, -4, 4) === 'File') {
                    touch($p);
                } else {
                    Tht::module('*File')->u_make_dir($p, '750');
                }
            }

            // Make a local copy of the THT runtime to app tree
            $thtBinPath = realpath(dirname($_SERVER['SCRIPT_NAME']) . '/..');
            Tht::module('*File')->u_copy_dir($thtBinPath, Tht::path('localTht'));

            self::writeAppFiles();
            self::writeStarterFiles();

            self::installDatabases();

        } catch (\Exception $e) {

            echo "Sorry, something went wrong.\n\n";
            echo "  " . $e->getMessage() . "\n\n";
            if (file_exists(Tht::path('app'))) {
                echo "Move or delete your app directories before trying again:\n\n  " . Tht::path('app');
                echo "\n\n";
            }
            Tht::exitScript(1);
        }

        self::printHeaderBox('Success!');

        echo "Your new THT app directory is here:\n  " . Tht::path('app') . "\n\n";
        echo "*  Load 'http://yoursite.com' to see if it's working.\n";
        echo "*  Or run 'tht server' to start a local web server.";
        echo "\n\n";

        Tht::exitScript(0);
    }

    static private function writeAppFiles() {

        // Front controller
        self::writeSetupFile(
            Tht::getAppFileName('frontFile'),
            StarterTemplates::controllerTemplate()
        );

        // .htaccess
        // TODO: don't overwrite previous
        self::writeSetupFile(
            '.htaccess',
            StarterTemplates::htaccessTemplate()
        );

        // Config
        self::writeSetupFile(
            Tht::path('settingsFile'),
            StarterTemplates::configTemplate()
        );
    }

    static private function writeStarterFiles() {

        self::writeSetupFile(
            Tht::path('modules', 'App.tht'),
            StarterTemplates::moduleTemplate()
        );
        self::writeSetupFile(
            Tht::path('pages', 'home.tht'),
            StarterTemplates::pageTemplate()
        );
        self::writeSetupFile(
            Tht::path('pages', 'css.tht'),
            StarterTemplates::cssTemplate()
        );
    }

    static private function writeSetupFile($name, $content) {
        file_put_contents($name, v($content)->u_trim_indent() . "\n");
    }

    static private function createDbIndex($dbh, $table, $col) {
        $dbh->u_x_danger_query("CREATE INDEX i_{$table}_{$col} ON $table ($col)");
    }

    static private function installDatabases () {

        $initDb = function ($dbId) {
            $dbFile = $dbId . '.db';
            touch(Tht::path('db', $dbFile));
        };

        $initDb('app');
    }

    static function startTestServer ($port=0, $docRoot='.') {

        $hostName = self::$SERVER_HOSTNAME;

        if (!$port) {
            $port = self::$SERVER_PORT;
        }
        else if ($port < 8000 || $port >= 9000) {
            echo "\nServer port must be in the range of 8000-8999.\n\n";
            Tht::exitScript(1);
        }

        if (!self::isAppInstalled()) {
            echo "\nCan't find app directory.  Please `cd` to your your document root and try again.\n\n";
            Tht::exitScript(1);
        }

        self::printHeaderBox('Test Server');

        echo "App directory:\n  " . Tht::path('app') . "\n\n";
        echo "Serving app at:\n  http://$hostName:$port\n\n";
        echo "Press [Ctrl-C] to stop.\n\n";

        $controller = realpath('thtApp.php');

        passthru("php -S $hostName:$port " . escapeshellarg($controller));
    }

}

class StarterTemplates {

    static private $FRONT_PATH_APP     = '../app';
    static private $FRONT_PATH_DATA    = '../app/data';
    static private $FRONT_PATH_RUNTIME = '../app/.tht/main/Tht.php';

    static function controllerTemplate() {

        $appRoot  = self::$FRONT_PATH_APP;
        $dataRoot = self::$FRONT_PATH_DATA;
        $thtMain  = self::$FRONT_PATH_RUNTIME;

        return <<<EOC

        <?php

        define('APP_ROOT', '$appRoot');
        define('DATA_ROOT', '$dataRoot');
        define('THT_RUNTIME', '$thtMain');

        return require_once(THT_RUNTIME);
EOC;

    }

    static function htaccessTemplate() {

        return <<<EOC

        ### THT APP

        DirectoryIndex index.html index.php thtApp.php
        Options -Indexes

        # Redirect all non-static URLs to THT app
        RewriteEngine On
        RewriteCond %{REQUEST_FILENAME} !-f
        RewriteCond %{REQUEST_FILENAME} !-d
        RewriteRule  ^(.*)$ /thtApp.php [QSA,NC,L]

        # Uncomment to redirect to HTTPS
        # RewriteCond %{HTTPS} off
        # RewriteRule (.*) https://%{HTTP_HOST}%{REQUEST_URI}

        ### END THT APP

EOC;
    }

    static function moduleTemplate() {

        return <<<EOC

        fn sendPage(\$appName, \$bodyHtml) {

            Response.sendPage({
                title: \$appName,
                body: siteHtml(\$appName, \$bodyHtml),
                css: url'/css',  // route to `pages/css.tht`
            })
        }

        tm siteHtml(\$appName, \$body) {

            <header> {{ \$appName }}

            <main>
                {{ \$body }}
            </>
        }
EOC;
    }

    static function pageTemplate() {

        return <<<EOC

        // Call `sendPage` function in 'app/modules/App.tht'
        App.sendPage('My App', bodyHtml())

        tm bodyHtml() {

            <.row>
                <.col>

                    <h1> App Ready

                    <div class="subline"> {{ Web.icon('check') }}  This app is ready for development.

                    <p>
                        You can edit this page at:<br />
                        <code> app/pages/home.tht
                    </>

                </>
            </>
        }
EOC;
    }

    static function cssTemplate() {

        return <<<EOC

        Response.sendCss(css())

        tm css() {

            {{ Css.plugin('base', 700) }}

            header {
                padding: 1rem 2rem;
                background-color: #eee;
                font-weight: bold;
            }

            header a {
                text-decoration: none;
                color: #333;
            }

            body {
                font-size: 2rem;
                color: #222;
            }

            .subline {
                width: 100%;
                font-size: 2.5rem;
                color: #394;
                margin-bottom: 4rem;
                margin-top: -3rem;
                border-bottom: solid 1px #d6d6e6;
                padding-bottom: 2rem;
            }

            code {
                font-weight: bold;
            }
        }
EOC;
    }

    static function configTemplate() {

        return <<<EOC
        {
            //
            //  App Settings
            //
            //  See: https://tht-lang.org/reference/app-settings
            //

            // Dynamic URL routes
            // See: https://tht-lang.org/reference/url-router
            routes: {
                // /post/{postId}:  post.tht
            }


            // Custom app settings.  Read via `Settings.get(key)`
            app: {
                // myVar: 1234
            }

            // Core settings
            tht: {
                // Server timezone
                // See: http://php.net/manual/en/timezones.php
                // Examples:
                //    America/Los_Angeles
                //    Europe/Berlin
                timezone: UTC

                // Print performance timing info
                // See: https://tht-lang.org/reference/perf-panel
                showPerfPanel: false

                // Auto-send anonymous error messages to the THT
                // developers. This helps us improve the usability
                // of THT. THanks!
                sendErrors: true
            }

            // Database settings
            // See: https://tht-lang.org/manual/module/db
            databases: {

                // Default sqlite file in 'data/db'
                default: {
                    file: app.db
                }

                // Other database
                // Accessible via e.g. `Db.use('exampleDb')`
                // exampleDb: {
                //     driver: mysql // or 'pgsql'
                //     server: localhost
                //     database: example
                //     username: dbuser
                //
                //     Set password in Environment variable: 'THT_DB_PASSWORD_(KEY)'
                //     e.g. THT_DB_PASSWORD_EXAMPLEDB="myp@ssw0rd"
                // }
            }
        }

EOC;

    }
}




