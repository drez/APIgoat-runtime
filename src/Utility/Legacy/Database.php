<?php
#session_start();

define('MYSQL_ONCE', '1');
define('MYSQL_FOREACH_ARRAY', '2');
define('MYSQL_INSERT_ID', '3');
define('MYSQL_NUM_ROW', '4');
define('MYSQL_FOR_ARRAY', '5');
define('MYSQL_AFFECTED', '6');
define('MYSQL_NUM_ARRAY', '7');
define('MYSQL_OBJ_ARRAY', '8');
/*
* Fonction de connection a la base de donnee
*/
global $cn;

function execSQL($sql, $return = '1', $debug = false, $base_dir = _BASE_DIR, &$error = '')
{
	if (!file_exists($base_dir . "config/Built/db.php")) {
		throw new Exception("Error legacy-db-4: missing db.php");
	}
	$conf = require($base_dir . "config/Built/db.php");
	$source = $conf['datasources']['default'];
	try {

		$initPdo = new PDO($conf['datasources'][$source]['connection']['dsn'], $conf['datasources'][$source]['connection']['user'], $conf['datasources'][$source]['connection']['password']);
	} catch (PDOException $e) {
		throw new Exception("Error legacy-db-1: " . $e->getMessage());
	}
	if ($debug)
		echo "<br /> - $debug :" . $sql . "<br />";
	try {
		$con = $initPdo->prepare($sql);
	} catch (PDOException $e) {
		throw new Exception("Error legacy-db-2: " . $e->getMessage());
	}
	$rs = $con->execute();
	if ($rs) {
		switch ($return) {
			case '1':
				return $con->fetch(PDO::FETCH_BOTH);
				//return mysql_fetch_array($rs);
				break;
			case '2':
				while ($res[] = $con->fetch(PDO::FETCH_BOTH));
				return $res;
				break;
			case '3':
				return $initPdo->lastInsertId();
				break;
			case '4':
				return $con->rowCount();
				break;
			case '5':
				while ($res[] = $con->fetch(PDO::FETCH_NUM));
				return $res;
				break;
			case '6':
				return $con->rowCount();
				break;
			case '7':
				while ($res[] = $con->fetch(PDO::FETCH_NUM));
				return $res;
				break;
			case '8':
				while ($res[] = $con->fetch(PDO::FETCH_OBJ));
				return $res;
				break;
		}
	} else {
		foreach ($con->errorInfo() as $info) {
			$error = $info . "<br >";
		}
		return false;
	}
}


/*CREATE TABLE IF NOT EXISTS `authy_errors` (
  `id_authy_errors` int(11) NOT NULL AUTO_INCREMENT,
  `Msg` int(11) NOT NULL,
  `Info` int(11) NOT NULL,
  PRIMARY KEY (`id_authy_errors`)
) ENGINE=MyISAM DEFAULT CHARSET=latin1 AUTO_INCREMENT=1 ;*/
function log_error($error, $position = '')
{

	$sql = "INSERT INTO `authy_errors` (`Msg`, `Info`) VALUES ('" . $error . "', '" . $position . "')";
	$rs = mysql_query($sql);
	$lErrorHandler = array(
		'HANDLER_1' => array(
			'fr' => "Une erreur s'est produite",
			'en' => ''
		)
	);
	if (_DEBUG == 'yes') {
		echo mysql_error();
		echo $error . " " . $position;
	}
	return $lErrorHandler['HANDLER_1'][$_SESSION[_AUTH_VAR]->lang];
}
