<?php
//    SeedDMS (Formerly MyDMS) Document Management System
//    Copyright (C) 2002-2005  Markus Westphal
//    Copyright (C) 2006-2008 Malcolm Cowe
//
//    This program is free software; you can redistribute it and/or modify
//    it under the terms of the GNU General Public License as published by
//    the Free Software Foundation; either version 2 of the License, or
//    (at your option) any later version.
//
//    This program is distributed in the hope that it will be useful,
//    but WITHOUT ANY WARRANTY; without even the implied warranty of
//    MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE.  See the
//    GNU General Public License for more details.
//
//    You should have received a copy of the GNU General Public License
//    along with this program; if not, write to the Free Software
//    Foundation, Inc., 675 Mass Ave, Cambridge, MA 02139, USA.

include("inc/inc.Settings.php");

if(true) {
	include("inc/inc.LogInit.php");
	include("inc/inc.Utils.php");
	include("inc/inc.Language.php");
	include("inc/inc.Init.php");
	include("inc/inc.Extension.php");
	include("inc/inc.DBInit.php");
	include("inc/inc.Authentication.php");

	require "vendor/autoload.php";

	$c = new \Slim\Container(); //Create Your container
	$c['notFoundHandler'] = function ($c) use ($settings, $dms, $user, $theme) {
		return function ($request, $response) use ($c, $settings, $dms, $user, $theme) {
			$uri = $request->getUri();
			if($uri->getBasePath())
				$file = $uri->getPath();
			else
				$file = substr($uri->getPath(), 1);
			if(file_exists($file) && is_file($file)) {
				$_SERVER['SCRIPT_FILENAME'] = basename($file);
				include($file);
				exit;
			}
//			print_r($request->getUri());
//			exit;
			return $c['response']
				->withStatus(302)
				->withHeader('Location', isset($settings->_siteDefaultPage) && strlen($settings->_siteDefaultPage)>0 ? $settings->_httpRoot.$settings->_siteDefaultPage : $settings->_httpRoot."out/out.ViewFolder.php");
		};
	};
	$app = new \Slim\App($c);

	if(isset($GLOBALS['SEEDDMS_HOOKS']['initDMS'])) {
		foreach($GLOBALS['SEEDDMS_HOOKS']['initDMS'] as $hookObj) {
			if (method_exists($hookObj, 'addRoute')) {
				$hookObj->addRoute(array('dms'=>$dms, 'user'=>$user, 'app'=>$app, 'settings'=>$settings));
			}
		}
	}

	$app->run();
} else {

	header("Location: ". (isset($settings->_siteDefaultPage) && strlen($settings->_siteDefaultPage)>0 ? $settings->_siteDefaultPage : "out/out.ViewFolder.php"));
?>
<html>
<head>
	<title>SCRlyDMS</title>
</head>

<body>


</body>
</html>
<?php } ?>
