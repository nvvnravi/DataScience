<?php
//    MyDMS. Document Management System
//    Copyright (C) 2002-2005 Markus Westphal
//    Copyright (C) 2006-2008 Malcolm Cowe
//    Copyright (C) 2010-2011 Matteo Lucarelli
//    Copyright (C) 2010-2016 Uwe Steinmann
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

if(!isset($settings))
	require_once("../inc/inc.Settings.php");
require_once("inc/inc.Version.php");
require_once("inc/inc.LogInit.php");
require_once("inc/inc.Language.php");
require_once("inc/inc.Init.php");
require_once("inc/inc.Extension.php");
require_once("inc/inc.DBInit.php");
require_once("inc/inc.ClassUI.php");
require_once("inc/inc.Authentication.php");

$tmp = explode('.', basename($_SERVER['SCRIPT_FILENAME']));
$view = UI::factory($theme, $tmp[1], array('dms'=>$dms, 'user'=>$user));
if (!$user->isAdmin()) {
	UI::exitError(getMLText("admin_tools"),getMLText("access_denied"));
}

if(!$settings->_enableFullSearch) {
	UI::exitError(getMLText("admin_tools"),getMLText("fulltextsearch_disabled"));
}

if(!isset($_GET['action']) || $_GET['action'] == 'show') {
	if(isset($_GET['create']) && $_GET['create'] == 1) {
		if(isset($_GET['confirm']) && $_GET['confirm'] == 1) {
			$index = $indexconf['Indexer']::create($settings->_luceneDir);
			if(!$index) {
				UI::exitError(getMLText("admin_tools"),getMLText("no_fulltextindex"));
			}
			$indexconf['Indexer']::init($settings->_stopWordsFile);
		} else {
			header('Location: out.CreateIndex.php');
			exit;
		}
	} else {
		$index = $indexconf['Indexer']::open($settings->_luceneDir);
		if(!$index) {
			$index = $indexconf['Indexer']::create($settings->_luceneDir);
			if(!$index) {
				UI::exitError(getMLText("admin_tools"),getMLText("no_fulltextindex"));
			}
		}
		$indexconf['Indexer']::init($settings->_stopWordsFile);
	}
} else {
	$index = null;
}

if (!isset($_GET["folderid"]) || !is_numeric($_GET["folderid"]) || intval($_GET["folderid"])<1) {
	$folderid = $settings->_rootFolderID;
}
else {
	$folderid = intval($_GET["folderid"]);
}
$folder = $dms->getFolder($folderid);

if($view) {
	$view->setParam('index', $index);
	$view->setParam('indexconf', $indexconf);
	$view->setParam('recreate', (isset($_GET['create']) && $_GET['create']==1));
	$view->setParam('forceupdate', (isset($_GET['forceupdate']) && $_GET['forceupdate']==1));
	$view->setParam('folder', $folder);
	$view->setParam('converters', $settings->_converters['fulltext']);
	$view->setParam('timeout', $settings->_cmdTimeout);
	$view($_GET);
	exit;
}

?>
