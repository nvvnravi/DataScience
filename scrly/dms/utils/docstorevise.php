<?php
if(isset($_SERVER['SEEDDMS_HOME'])) {
	ini_set('include_path', $_SERVER['SEEDDMS_HOME']. PATH_SEPARATOR .ini_get('include_path'));
}

function usage() { /* {{{ */
	echo "Usage:\n";
	echo "  seeddms-docs-to-revise [--config <file>] [-u <user>] [-h] [-v] [-t] [-q] [-o] [-f <email>] [-w] [-b <base>] [-c] -d <days> -D <days>\n";
	echo "\n";
	echo "Description:\n";
	echo "  Check for files which will expire in the next days and inform the\n";
	echo "  the owner and all users watching the document.\n";
	echo "\n";
	echo "Options:\n";
	echo "  -h, --help: print usage information and exit.\n";
	echo "  -v, --version: print version and exit.\n";
	echo "  --config=<file>: set alternative config file.\n";
	echo "  -u <user>: login name of user\n";
	echo "  -t: run in test mode (will not send any mails)\n";
	echo "  -q: be quite (just output error messages)\n";
} /* }}} */

$version = "0.0.2";
$tableformat = " %-10s %5d %2d %2d %-60s";
$tableformathtml = "<tr><td>%s</td><td>%s</td></tr>";
$baseurl = "http://localhost/";
$mailfrom = "uwe@steinman.cx";

$shortoptions = "u:tqhv";
$longoptions = array('help', 'version', 'config:');
if(false === ($options = getopt($shortoptions, $longoptions))) {
	usage();
	exit(0);
}

/* Print help and exit */
if(isset($options['h']) || isset($options['help'])) {
	usage();
	exit(0);
}

/* Print version and exit */
if(isset($options['v']) || isset($options['verѕion'])) {
	echo $version."\n";
	exit(0);
}

/* Set alternative config file */
if(isset($options['config'])) {
	define('SEEDDMS_CONFIG_FILE', $options['config']);
}
require_once("../inc/inc.Settings.php");
require_once("inc/inc.Init.php");
require_once("inc/inc.Language.php");
require_once("inc/inc.Extension.php");
require_once("inc/inc.DBInit.php");

$usernames = array();
if(isset($options['u'])) {
	$usernames = explode(',', $options['u']);
}

$dryrun = false;
if(isset($options['t'])) {
	$dryrun = true;
	echo "Running in test mode will not send any mail.\n";
}
$quite = false;
if(isset($options['q'])) {
	$quite = true;
}

$docs = $dms->getDocumentList('DueRevision', null, false, 's');
$body = '';
if (count($docs)>0) {
	$body .= sprintf($tableformat."\n", getMLText("revisiondate", array(), ""), "ID", "V", "S", getMLText("name", array(), ""));	
	$body .= "---------------------------------------------------------------------------------\n";
	foreach($docs as $res)
		$body .= sprintf($tableformat."\n", (!$res["revisiondate"] ? "-":substr($res["revisiondate"], 0, 10)), $res["id"], $res['version'], $res['status'], $res["name"]);
} else {
	$body .= getMLText("no_docs_to_look_at", array(), "")."\n\n";
}
echo $body;

