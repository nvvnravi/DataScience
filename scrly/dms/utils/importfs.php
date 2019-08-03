<?php
if(isset($_SERVER['SEEDDMS_HOME'])) {
	require_once($_SERVER['SEEDDMS_HOME']."/inc/inc.ClassSettings.php");
} else {
	require_once("../inc/inc.ClassSettings.php");
}

function usage() { /* {{{ */
	echo "Usage:\n";
	echo "  seeddms-importfs [--config <file>] [-h] [-v] -F <folder id> -d <dirname>\n";
	echo "\n";
	echo "Description:\n";
	echo "  This program uploads a directory recursively into a folder of SeedDMS.\n";
	echo "\n";
	echo "Options:\n";
	echo "  -h, --help: print usage information and exit.\n";
	echo "  -v, --version: print version and exit.\n";
	echo "  --config: set alternative config file.\n";
	echo "  --user: use this user for accessing seeddms.\n";
	echo "  --exclude: exlude files/directories by name (defaults to .svn, .gitignore).\n";
	echo "      This must be just the file or directory without the path.\n";
	echo "  --filemtime: take over modification time from file.\n";
	echo "  --foldermtime: take over modification time from folder.\n";
	echo "  --basefolder: creates the base folder\n";
	echo "  -F <folder id>: id of folder the file is uploaded to\n";
	echo "  -d <dirname>: upload this directory\n";
	echo "  -e <encoding>: encoding used by filesystem (defaults to iso-8859-1)\n";
} /* }}} */

$version = "0.0.1";
$shortoptions = "d:F:e:hv";
$longoptions = array('help', 'version', 'user:', 'basefolder', 'filemtime', 'foldermtime', 'exclude:', 'config:');
if(false === ($options = getopt($shortoptions, $longoptions))) {
	usage();
	exit(0);
}

/* Print help and exit */
if(!$options || isset($options['h']) || isset($options['help'])) {
	usage();
	exit(0);
}

/* Print version and exit */
if(isset($options['v']) || isset($options['verѕion'])) {
	echo $version."\n";
	exit(0);
}

/* Set encoding of names in filesystem */
$fsencoding = 'iso-8859-1';
if(isset($options['e'])) {
	$fsencoding = $options['e'];
}

/* Set alternative config file */
if(isset($options['config'])) {
	$settings = new Settings($options['config']);
} else {
	$settings = new Settings();
}

$excludefiles = array('.', '..');
if(isset($options['exclude'])) {
	if(is_array($options['exclude']))
		$excludefiles = array_merge($excludefiles, $options['exclude']);
	else
		$excludefiles[] = $options['exclude'];
} else {
	$excludefiles[] = '.svn';
	$excludefiles[] = '.gitignore';
}

if(isset($options['user'])) {
	$userlogin = $options['user'];
} else {
	echo "Missing user\n";
	usage();
	exit(1);
}

/* check if base folder shall be created */
$createbasefolder = false;
if(isset($options['basefolder'])) {
	$createbasefolder = true;
}

/* check if modification time shall be taken over */
$filemtime = false;
if(isset($options['filemtime'])) {
	$filemtime = true;
}
$foldermtime = false;
if(isset($options['foldermtime'])) {
	$foldermtime = true;
}

if(isset($settings->_extraPath))
	ini_set('include_path', $settings->_extraPath. PATH_SEPARATOR .ini_get('include_path'));

require_once("SeedDMS/Core.php");

if(isset($options['F'])) {
	$folderid = (int) $options['F'];
} else {
	echo "Missing folder ID\n";
	usage();
	exit(1);
}

$dirname = '';
if(isset($options['d'])) {
	$dirname = $options['d'];
} else {
	echo "Missing import directory\n";
	usage();
	exit(1);
}

$db = new SeedDMS_Core_DatabaseAccess($settings->_dbDriver, $settings->_dbHostname, $settings->_dbUser, $settings->_dbPass, $settings->_dbDatabase);
$db->connect() or die ("Could not connect to db-server \"" . $settings->_dbHostname . "\"");
$db->_debug = 1;


$dms = new SeedDMS_Core_DMS($db, $settings->_contentDir.$settings->_contentOffsetDir);
if(!$dms->checkVersion()) {
	echo "Database update needed.";
//	exit;
}

echo $settings->_contentDir.$settings->_contentOffsetDir."\n";

$dms->setRootFolderID($settings->_rootFolderID);
$dms->setMaxDirID($settings->_maxDirID);
$dms->setEnableConverting($settings->_enableConverting);
$dms->setViewOnlineFileTypes($settings->_viewOnlineFileTypes);

/* Create a global user object */
if(!($user = $dms->getUserByLogin($userlogin))) {
	echo "User with login '".$userlogin."' does not exists.";
	exit;
}

$folder = $dms->getFolder($folderid);
if (!is_object($folder)) {
	echo "Could not find specified folder\n";
	exit(1);
}

if ($folder->getAccessMode($user) < M_READWRITE) {
	echo "Not sufficient access rights\n";
	exit(1);
}

function import_folder($dirname, $folder, $filemtime, $foldermtime) {
	global $user, $excludefiles, $fsencoding;

	$d = dir($dirname);
	$sequence = 1;
	while(false !== ($entry = $d->read())) {
		$path = $dirname.'/'.$entry;
		if(!in_array($entry, $excludefiles)) {
			$name = iconv($fsencoding, 'utf-8', basename($path));
			if(is_file($path)) {
				$filetmp = $path;

				$reviewers = array();
				$approvers = array();
				$comment = '';
				$version_comment = '';
				$reqversion = 1;
				$expires = false;
				$keywords = '';
				$categories = array();

				$finfo = finfo_open(FILEINFO_MIME_TYPE);
				$mimetype = finfo_file($finfo, $path);
				$lastDotIndex = strrpos($path, ".");
				if (is_bool($lastDotIndex) && !$lastDotIndex) $filetype = ".";
				else $filetype = substr($path, $lastDotIndex);

				echo $mimetype." - ".$filetype." - ".$path."\n";
				$res = $folder->addDocument($name, $comment, $expires, $user, $keywords,
																		$categories, $filetmp, $name,
																		$filetype, $mimetype, $sequence, $reviewers,
																		$approvers, $reqversion, $version_comment);

				if (is_bool($res) && !$res) {
					echo "Could not add document to folder\n";
					exit(1);
				}
				if($filemtime) {
					$newdoc = $res[0];
					$newdoc->setDate(filemtime($path));
					$lc = $newdoc->getLatestContent();
					$lc->setDate(filemtime($path));
				}
				set_time_limit(1200);
			} elseif(is_dir($path)) {
				$newfolder = $folder->addSubFolder($name, '', $user, $sequence);
				if($foldermtime) {
					$newfolder->setDate(filemtime($path));
				}
				import_folder($path, $newfolder, $filemtime, $foldermtime);
			}
			$sequence++;
		}
	}
}

if($createbasefolder) {
	if($newfolder = $folder->addSubFolder(basename($dirname), '', $user, 1)) {
		if($foldermtime) {
			$newfolder->setDate(filemtime($dirname));
		}
		import_folder($dirname, $newfolder, $filemtime, $foldermtime);
	}
} else {
	import_folder($dirname, $folder, $filemtime, $foldermtime);
}

