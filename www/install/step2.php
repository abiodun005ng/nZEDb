<?php
require_once realpath(__DIR__ . DIRECTORY_SEPARATOR . 'install.php');

use app\extensions\util\Versions;
use nzedb\db\DB;
use nzedb\db\DbUpdate;
use nzedb\Install;

$page = new InstallPage();
$page->title = 'Database Setup';

$cfg = new Install();

if (!$cfg->isInitialized()) {
	header('Location: index.php');
	die();
}

/**
 * Check if the database exists.
 *
 * @param string $dbName The name of the database to be checked.
 * @param string $dbType The type of the database, e.g., 'mysql'.
 * @param PDO $pdo Class PDO instance.
 *
 * @return bool
 */
function databaseCheck($dbName, $dbType, $pdo): bool
{
	$stmt = ($dbType === 'mysql') ? 'SHOW DATABASES' : 'SELECT datname AS Database FROM pg_database';
	$stmt = $pdo->prepare($stmt);
	$stmt->setFetchMode(PDO::FETCH_ASSOC);
	if($stmt->execute()){
		$tables = $stmt->fetchAll();
	}else{
		$tables = [];
	}

	foreach ($tables as $table) {
		if (isset($table['Database']) && $table['Database'] === $dbName) {
			return true;
		}else{
			break;
			return false;
		}
	}

	return false;
}

function copyFileToTmp(string $file)
{
	$fileTarget = '/tmp/' . pathinfo($file, PATHINFO_BASENAME);
	if (\copy($file, $fileTarget)) {
		\chmod($fileTarget, 0775);
		return $fileTarget;
	} else {
		echo 'Failed to copy file: ' . $file . '</br>' . \PHP_EOL;
		return '';
	}
}

$cfg = $cfg->getSession();

if ($page->isPostBack()) {
	$cfg->doCheck = true;

	$cfg->DB_HOST = trim($_POST['host']);
	$cfg->DB_PORT = trim($_POST['sql_port']);
	$cfg->DB_SOCKET = trim($_POST['sql_socket']);
	$cfg->DB_USER = trim($_POST['user']);
	$cfg->DB_PASSWORD = trim($_POST['pass']);
	$cfg->DB_NAME = trim($_POST['db']);
	$cfg->DB_SYSTEM = strtolower(trim($_POST['db_system']));
	$cfg->error = false;

	$validTypes = ['mysql'];
	if (!in_array($cfg->DB_SYSTEM, $validTypes, false)) {
		$cfg->emessage = 'Invalid database system. Must be one of: [' . implode(', ', $validTypes) . ']; Not: ' . $cfg->DB_SYSTEM;
		$cfg->error = true;
	} else {
		try {
			$pdo = new DB([
				'checkVersion' => true,
				'createDb'     => true,
				'dbhost'       => $cfg->DB_HOST,
				'dbname'       => $cfg->DB_NAME,
				'dbpass'       => $cfg->DB_PASSWORD,
				'dbport'       => $cfg->DB_PORT,
				'dbsock'       => $cfg->DB_SOCKET,
				'dbtype'       => $cfg->DB_SYSTEM,
				'dbuser'       => $cfg->DB_USER,
			]);
			$cfg->dbConnCheck = true;
		} catch (\PDOException $e) {
			$cfg->emessage = "Unable to connect to the SQL server.\n" . $e->getMessage();
			$cfg->error = true;
			$cfg->dbConnCheck = false;
		} catch (\RuntimeException $e) {
			$cfg->error = true;
			$cfg->emessage = $e->getMessage();
			trigger_error($e->getMessage(), E_USER_WARNING);
		}

		if (!$cfg->error) {
			try {
				if (!$pdo->isVendorVersionValid()) {
					$cfg->error = true;
					$vendor = $pdo->getVendor();
					$version = ($vendor === 'mariadb') ? DB::MINIMUM_VERSION_MARIADB : DB::MINIMUM_VERSION_MYSQL;
					$cfg->emessage = 'You are using an unsupported version of the ' . $vendor . ' server, the minimum allowed version is ' . $version;
				}
			} catch (\PDOException $e) {
				$cfg->error = true;
				$cfg->emessage = 'Could not get version from SQL server.';
			}
		}
	}

	if (!$cfg->error) {
		$cfg->setSession();

		$DbSetup = new DbUpdate(['backup' => false, 'db' => $pdo]);

		try {
			$file = copyFileToTmp(nZEDb_RES . 'db' . DS . 'schema' . DS . 'mysql-ddl.sql');
			$DbSetup->sourceSQL([
				'file' => $file,
				'host' => $cfg->DB_HOST,
				'name' => $cfg->DB_NAME,
				'pass' => $cfg->DB_PASSWORD,
				'user' => $cfg->DB_USER,
			]);

			$file = copyFileToTmp(nZEDb_RES . 'db' . DS . 'schema' . DS . 'mysql-data.sql');
			$DbSetup->processSQLFile(['filepath' => $file]);
			$DbSetup->loadTables();
		} catch (\PDOException $err) {
			$cfg->error = true;
			$cfg->emessage = 'Error inserting: (' . $err->getMessage() . ')';
		}

		if (!$cfg->error) {
			$reschk = $pdo->query('SELECT COUNT(id) AS num FROM tmux');
			if ($reschk === false) {
				$cfg->dbCreateCheck = false;
				$cfg->error = true;
				$cfg->emessage = 'Could not select data from your database, check that tables and data are properly created/inserted.';
			} else {
				$dbInstallWorked = false;
				foreach ($reschk as $row) {
					if ($row['num'] > 0) {
						$dbInstallWorked = true;
						break;
					}
				}

				if ($dbInstallWorked) {
					$ver = new Versions();
					$patch = $ver->getSQLPatchFromFile();

					if ($patch > 0) {
						$updateSettings = $pdo->exec(
							"UPDATE settings SET value = '$patch' WHERE section = '' AND subsection = '' AND name = 'sqlpatch'"
						);
					} else {
						$updateSettings = false;
					}

					if ($updateSettings) {
						header("Location: ?success");
						if (file_exists($cfg->DB_DIR . '/post_install.php')) {
							exec("php " . $cfg->DB_DIR . "/post_install.php ${pdo}");
						}
						exit();
					} else {
						$cfg->error = true;
						$cfg->emessage = "Could not update sqlpatch to '$patch' for your database.";
					}
				} else {
					$cfg->dbCreateCheck = false;
					$cfg->error = true;
					$cfg->emessage = 'Could not select data from your database.';
				}
			}
		}
	}
}

$page->smarty->assign('cfg', $cfg);
$page->smarty->assign('page', $page);
$page->content = $page->smarty->fetch('step2.tpl');
$page->render();
?>
