<?php

namespace ApiGoat\Deploy;

use Ahc\Env\Loader;

class Config
{

    private $build_path;
    private $db;
    private $drop_table = false;

    public function __construct($params)
    {
        
        $this->build_path =  $_SERVER["PWD"]."/.admin/";
        try{
            (new Loader)->load($_SERVER["PWD"] . '/.env');
        }catch(\Exception $e){
            echo "\033[31mCant find the .env file, is it there?\r\n";
            exit(1);
        }
        
         $dsn = "mysql:host=".env('MY_DB_HOST').";dbname=".env('MY_DB_NAME').";charset=utf8mb4";
        $options = [
            \PDO::ATTR_EMULATE_PREPARES   => false, // turn off emulation mode for "real" prepared statements
            \PDO::ATTR_ERRMODE            => \PDO::ERRMODE_EXCEPTION, //turn on errors in the form of exceptions
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC, //make the default fetch be an associative array
        ];
        try {
            $this->db = new \PDO($dsn, env('MY_DB_USER'), env('MY_DB_PASSWORD'), $options);
        } catch (\Exception $e) {
            error_log($e->getMessage());
        }

        if ($this->db) {
            echo "\033[32mDatabase found: ".env('MY_DB_NAME')."\r\n\033[31m";
        } else {
            echo "\033[31mDatabase error, please check your .env file (MY_DB_...)\r\n";
            exit(false);
        }

        if ($params[1] == 'clean') {
            echo "\033[32mCleaning\r\n\033[31m";
            $this->drop_table = true;
        }

        if (!$this->checkBaseData()) {
            $this->runSql();
            $this->runCustomSql();
            $this->setAdminUser();
        } else {
            echo "\033[32mAlready deployed\r\n\033[31m";
            
        }
        echo "\033[32mOverwriting config...\r\n\033[31m";
        $this->writeConfig();
        chmod($this->build_path."public/css/", 0777);
        chmod($this->build_path."public/js/", 0777);
    }

    private function runSql()
    {
        if (file_exists($this->build_path . 'config/Built/schema.sql')) {
            $restore = "/usr/bin/mysql -f -u " . env('MY_DB_USER') . " --password=" . env('MY_DB_PASSWORD') . " " . env('MY_DB_NAME') . " < " . $this->build_path . "config/Built/schema.sql 2>&1";
            return $this->run($restore, "SQL");
        }
    }

    private function run($cmd, $label)
    {
        exec($cmd . " 2>&1", $outputRs, $return_var);

        if ($return_var) {
            echo "\033[31m$label: NOT OK\r\n";
        } else {
            echo "\033[32m$label: OK\r\n\033[31m";
        }
    }

    public function runCustomSql()
    {
        if (file_exists($this->build_path . 'config/Built/basedata.sql') && !$this->checkBaseData()) {
            $restore = "/usr/bin/mysql -f -u " . env('MY_DB_USER') . " --password=" . env('MY_DB_PASSWORD') . " " . env('MY_DB_NAME') . " < " . $this->build_path . "config/Built/basedata.sql 2>&1";
            $this->run($restore, "Additionnal SQL (base)");
        }

        echo "\033[32mChecking '" . $this->build_path . "tmp/' for additionnal SQL :\r\n";
        if (file_exists($this->build_path . 'tmp/')) {
            if ($handle = opendir($this->build_path . 'tmp/')) {
                while (false !== ($filename = readdir($handle))) {
                    if (strstr($filename, 'custom') && substr($filename, strrpos($filename, '.')) == '.sql') {

                        echo "\033[32mFound custom SQL : " . $filename."\r\n";
                        $restore = "/usr/bin/mysql -f -u " . env('MY_DB_USER') . " --password=" . env('MY_DB_PASSWORD') . " " . env('MY_DB_NAME') . " < " . $this->build_path . "tmp/" . $filename . " 2>&1";
                        $this->run($restore, "Additionnal SQL");
                    }
                }
            }
        }

        return false;
    }

    public function setAdminUser($password=null){
        $stmt = $this->db->prepare ("SELECT id_authy FROM authy WHERE is_system = ?");
        $stmt->execute(['1']);
        $users = $stmt->rowCount();

        if ($users > 0) {
            $stmt = $this->db->prepare ("INSERT INTO `authy` ( `username`, `fullname`, `email`, `passwd_hash`, `expire`, `deactivate`, `is_root`, `id_authy_group`, `is_system`, `date_creation`, `date_modification`, `id_group_creation`, `id_creation`, `id_modification`) VALUES (
                ?,?, ?,?, ?,?, ?,?, ?,?, ?,?, ?,? )", [ 
            ]);
            $stmt->execute(['apigoat', 'System user', 'info@apigoat.com', md5($password), null, '1', '2', '1', date('Y-m-d H:i:s'), date('Y-m-d H:i:s'), null, null, null]);
            if ($stmt->lastInsertId())
                echo "\033[32mCreate Admin user: OK\r\n";
            else
                echo "\033[31mCreate Admin user: NOT OK (" . $stmt->getLastError().")\r\n";
        } else {
            echo "\033[32mCreate Admin user: OK\r\n";
        }
    }

    private function checkBaseData()
    {
        if ($this->drop_table) {
            return false;
        }
        try {
            $stmt = $this->db->prepare ("SELECT config FROM config WHERE config = ?");
            $stmt->execute(['app_name']);
            $app_name = $stmt->rowCount();
            
            if ($app_name > 0) {
                return true;
            }
            return false;
        }catch(\Exception $e){
            return false;
        }
    }

    private function writeConfig()
    {
        $project_url = env("MY_PROJECT_URL").".admin".DIRECTORY_SEPARATOR;
        $project_name = 'myproject1';

        $authvar = substr(md5(env("MY_PROJECT_URL") . $project_name . random_int(1, 9999)), 5, 10);
		$cryptkey = substr(md5($project_name), 0, 8) . substr(md5(env('MY_DB_USER')), 10, 8);
		$cryptiv = substr(md5(env("MY_PROJECT_URL")), 5, 8) . substr(md5($project_name), 7, 8);

        $db_host = env("MY_DB_HOST");
        $db_name = env("MY_DB_NAME");
        $db_user = env("MY_DB_USER");
        $db_paddword = env("MY_DB_PASSWORD");


        $script = <<<EOS
<?php
ini_set('memory_limit', '128M');

if (php_sapi_name() != 'cli') {
	\$subdir_url = str_replace((isset(\$_SERVER['HTTPS']) && \$_SERVER['HTTPS'] != 'off' ? 'https' : 'http').'://' . \$_SERVER['SERVER_NAME'], '', "$project_url") ;
} else {
	\$subdir_url = "";
}

\$project_root = "{$this->build_path}";

\$defines = [
    "_DATA_SRC" => "$project_name",
    "_PROJECT_NAME" => "$project_name",
    "_PROJECT_PRFX" => "",
    "API_VERSION" => "1",
    "_DEPLOYMENT_TYPE" => "Standalone",
    "_SYSTEM_USER" => "web1",
    "_SITE_TITLE" => "",
	"_SUB_DIR_URL" => \$subdir_url,	 # Routes prefix
    "_ASSET_RELATIVE_PATH" => "",
    "_BASE_DIR" => realpath("{$this->build_path}").DIRECTORY_SEPARATOR, 
    "_AUTH_VAR" => "$authvar",
    "_CRYPT_KEY" => "$cryptkey",
    "_CRYPT_IV" => "$cryptiv",
];

\$locales = [
    LC_MONETARY => 'en_CA.UTF-8',
    LC_NUMERIC => 'en_CA.UTF-8',
    LC_TIME => 'en_CA.UTF-8'
];

if(!isset(\$skipConfig)){
    foreach(\$defines as \$define => \$val){
        define(\$define, \$val);
    }

	if (php_sapi_name() != 'cli') {
   	 	define("_SITE_URL", "https://" . \$_SERVER['SERVER_NAME'] . _SUB_DIR_URL);
	} else {
        define("_SITE_URL", "");
    }
    
	define("_SRC_URL", _SITE_URL);

    define("_INSTALL_PATH", "{$this->build_path}");
    define("_VENDOR_DIR", _BASE_DIR . "vendor/");

    define("_PROPEL_BASE_PATH", _VENDOR_DIR . "propel/propel1/");
    define("_PROPEL_RUNTIME_PATH", _PROPEL_BASE_PATH . 'runtime');
    define("_PEAR_LOG_PATH", _PROPEL_RUNTIME_PATH);
    define("_PROPEL_GEN", _PROPEL_BASE_PATH . "generator/bin/propel-gen");

    foreach(\$locales as \$locale => \$val){
        setlocale(\$locale, \$val);
    }
}
EOS;

    file_put_contents($this->build_path.'config/Built/config.php', $script);

     $script = <<<EOS
<?php
// This file generated by Propel 1.7.3-dev convert-conf target
// from XML runtime conf file /var/www/gc/p/myproject1/.admin/runtime-conf.xml
\$conf = array (
  'datasources' => 
  array (
    'myproject1' => 
    array (
      'adapter' => 'mysql',
      'connection' => 
      array (
        'dsn' => 'mysql:host=$db_host;dbname=$db_name;charset=utf8;',
        'user' => '$db_user',
        'password' => '$db_paddword',
      ),
    ),
    'default' => 'myproject1',
  ),
  'generator_version' => '1.7.3-dev',
);
\$conf['classmap'] = include(dirname(__FILE__) . DIRECTORY_SEPARATOR . 'classmap.php');
return \$conf;
EOS;

    file_put_contents($this->build_path.'config/Built/db.php', $script);

    }

}