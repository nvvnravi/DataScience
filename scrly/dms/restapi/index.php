<?php
define('USE_PHP_SESSION', 0);

include("../inc/inc.Settings.php");
include("../inc/inc.LogInit.php");
include("../inc/inc.Utils.php");
include("../inc/inc.Language.php");
include("../inc/inc.Init.php");
include("../inc/inc.DBInit.php");
include("../inc/inc.Extension.php");

if(USE_PHP_SESSION) {
    session_start();
    $userobj = null;
    if(isset($_SESSION['userid']))
        $userobj = $dms->getUser($_SESSION['userid']);
    elseif($settings->_enableGuestLogin)
        $userobj = $dms->getUser($settings->_guestID);
    else
        exit;
    $dms->setUser($userobj);
} else {
    require_once("../inc/inc.ClassSession.php");
    $session = new SeedDMS_Session($db);
    if (isset($_COOKIE["mydms_session"])) {
        $dms_session = $_COOKIE["mydms_session"];
        if(!$resArr = $session->load($dms_session)) {
            /* Delete Cookie */
            setcookie("mydms_session", $dms_session, time()-3600, $settings->_httpRoot);
            if($settings->_enableGuestLogin)
                $userobj = $dms->getUser($settings->_guestID);
            else
                exit;
        }

        /* Load user data */
        $userobj = $dms->getUser($resArr["userID"]);
        if (!is_object($userobj)) {
            /* Delete Cookie */
            setcookie("mydms_session", $dms_session, time()-3600, $settings->_httpRoot);
            if($settings->_enableGuestLogin)
                $userobj = $dms->getUser($settings->_guestID);
            else
                exit;
        }
        if($userobj->isAdmin()) {
            if($resArr["su"]) {
                $userobj = $dms->getUser($resArr["su"]);
            }
        }
        $dms->setUser($userobj);
    }
}

require "vendor/autoload.php";

function __getLatestVersionData($lc) { /* {{{ */
    $document = $lc->getDocument();
    $data = array(
        'type'=>'document',
        'id'=>(int)$document->getId(),
        'date'=>date('Y-m-d H:i:s', $document->getDate()),
        'name'=>$document->getName(),
        'comment'=>$document->getComment(),
        'keywords'=>$document->getKeywords(),
        'mimetype'=>$lc->getMimeType(),
        'version'=>$lc->getVersion(),
        'version_comment'=>$lc->getComment(),
        'version_date'=>$lc->getDate(),
        'size'=>$lc->getFileSize(),
    );
    $cats = $document->getCategories();
    if($cats) {
        $c = array();
        foreach($cats as $cat) {
            $c[] = array('id'=>(int)$cat->getID(), 'name'=>$cat->getName());
        }
        $data['categories'] = $c;
    }
    $attributes = $document->getAttributes();
    if($attributes) {
        $attrvalues = array();
        foreach($attributes as $attrdefid=>$attribute)
            $attrvalues[] = array('id'=>(int)$attrdefid, 'value'=>$attribute->getValue());
        $data['attributes'] = $attrvalues;
    }
    $attributes = $lc->getAttributes();
    if($attributes) {
        $attrvalues = array();
        foreach($attributes as $attrdefid=>$attribute)
            $attrvalues[] = array('id'=>(int)$attrdefid, 'value'=>$attribute->getValue());
        $data['version-attributes'] = $attrvalues;
    }
    return $data;
} /* }}} */

function __getFolderData($folder) { /* {{{ */
    $data = array(
        'type'=>'folder',
        'id'=>(int)$folder->getID(),
        'name'=>$folder->getName(),
        'comment'=>$folder->getComment(),
        'date'=>date('Y-m-d H:i:s', $folder->getDate()),
    );
    $attributes = $folder->getAttributes();
    if($attributes) {
        $attrvalues = array();
        foreach($attributes as $attrdefid=>$attribute)
            $attrvalues[] = array('id'=>(int)$attrdefid, 'value'=>$attribute->getValue());
        $data['attributes'] = $attrvalues;
    }
    return $data;
} /* }}} */

function __getGroupData($u) { /* {{{ */
    $data = array(
        'type'=>'group',
        'id'=>(int)$u->getID(),
        'name'=>$u->getName(),
        'comment'=>$u->getComment(),
    );
    return $data;
} /* }}} */

function __getUserData($u) { /* {{{ */
    $data = array(
        'type'=>'user',
        'id'=>(int)$u->getID(),
        'name'=>$u->getFullName(),
        'comment'=>$u->getComment(),
        'login'=>$u->getLogin(),
        'email'=>$u->getEmail(),
        'language' => $u->getLanguage(),
        'theme' => $u->getTheme(),
        'role' => $u->getRole() == SeedDMS_Core_User::role_admin ? 'admin' : ($u->getRole() == SeedDMS_Core_User::role_guest ? 'guest' : 'user'),
        'hidden'=>$u->isHidden() ? true : false,
        'disabled'=>$u->isDisabled() ? true : false,
        'isguest' => $u->isGuest() ? true : false,
        'isadmin' => $u->isAdmin() ? true : false,
    );
    if($u->getHomeFolder())
        $data['homefolder'] = (int)$u->getHomeFolder();

    $groups = $u->getGroups();
    if($groups) {
        $tmp = [];
        foreach($groups as $group)
            $tmp[] = __getGroupData($group);
        $data['groups'] = $tmp;
    }
    return $data;
} /* }}} */

function doLogin($request, $response) { /* {{{ */
    global $dms, $userobj, $session, $settings;

    $params = $request->getParsedBody();
    $username = $params['user'];
    $password = $params['pass'];

//    $userobj = $dms->getUserByLogin($username);
    $userobj = null;

    /* Authenticate against LDAP server {{{ */
    if (!$userobj && isset($settings->_ldapHost) && strlen($settings->_ldapHost)>0) {
        require_once("../inc/inc.ClassLdapAuthentication.php");
        $authobj = new SeedDMS_LdapAuthentication($dms, $settings);
        $userobj = $authobj->authenticate($username, $password);
    } /* }}} */

    /* Authenticate against SeedDMS database {{{ */
    if(!$userobj) {
        require_once("../inc/inc.ClassDbAuthentication.php");
        $authobj = new SeedDMS_DbAuthentication($dms, $settings);
        $userobj = $authobj->authenticate($username, $password);
    } /* }}} */

    if(!$userobj) {
        if(USE_PHP_SESSION) {
            unset($_SESSION['userid']);
        } else {
            setcookie("mydms_session", $session->getId(), time()-3600, $settings->_httpRoot);
        }
        return $response->withJson(array('success'=>false, 'message'=>'Login failed', 'data'=>''), 403);
    } else {
        if(USE_PHP_SESSION) {
            $_SESSION['userid'] = $userobj->getId();
        } else {
            if(!$id = $session->create(array('userid'=>$userobj->getId(), 'theme'=>$userobj->getTheme(), 'lang'=>$userobj->getLanguage()))) {
                exit;
            }

            // Set the session cookie.
            if($settings->_cookieLifetime)
                $lifetime = time() + intval($settings->_cookieLifetime);
            else
                $lifetime = 0;
            setcookie("mydms_session", $id, $lifetime, $settings->_httpRoot);
            $dms->setUser($userobj);
        }
        return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>__getUserData($userobj)), 200);
    }
} /* }}} */

function doLogout($request, $response) { /* {{{ */
    global $dms, $userobj, $session, $settings;

    if(USE_PHP_SESSION) {
        unset($_SESSION['userid']);
    } else {
        setcookie("mydms_session", $session->getId(), time()-3600, $settings->_httpRoot);
    }
    $userobj = null;
    return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>''), 200);
} /* }}} */

function setFullName($request, $response) { /* {{{ */
    global $dms, $userobj;

    if(!$userobj) {
        return $response->withJson(array('success'=>false, 'message'=>'Not logged in', 'data'=>''), 403);
        return;
    }

    $params = $request->getParsedBody();
    $userobj->setFullName($params['fullname']);
    return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$userobj->getFullName()), 200);
} /* }}} */

function setEmail($request, $response) { /* {{{ */
    global $dms, $userobj;

    if(!$userobj) {
        return $response->withJson(array('success'=>false, 'message'=>'Not logged in', 'data'=>''), 403);
        return;
    }

    $params = $request->getParsedBody();
    $userobj->setEmail($params['email']);
    return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$userid), 200);
} /* }}} */

function getLockedDocuments($request, $response) { /* {{{ */
    global $dms, $userobj;

    if(false !== ($documents = $dms->getDocumentsLockedByUser($userobj))) {
        $documents = SeedDMS_Core_DMS::filterAccess($documents, $userobj, M_READ);
        $recs = array();
        foreach($documents as $document) {
            $lc = $document->getLatestContent();
            if($lc) {
                $recs[] = __getLatestVersionData($lc);
            }
        }
        return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$recs), 200);
    } else {
        return $response->withJson(array('success'=>false, 'message'=>'', 'data'=>''), 500);
    }
} /* }}} */

function getFolder($request, $response, $args) { /* {{{ */
    global $dms, $userobj, $settings;
    $params = $request->getQueryParams();
    $forcebyname = isset($params['forcebyname']) ? $params['forcebyname'] : 0;
    $parent = isset($params['parentid']) ? $dms->getFolder($params['parentid']) : null;

    if (!isset($args['id']))
        $folder = $dms->getFolder($settings->_rootFolderID);
    elseif(ctype_digit($args['id']) && empty($forcebyname))
        $folder = $dms->getFolder($args['id']);
    else {
        $folder = $dms->getFolderByName($args['id'], $parent);
    }
    if($folder) {
        if($folder->getAccessMode($userobj) >= M_READ) {
            $data = __getFolderData($folder);
            return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$data), 200);
        } else {
            return $response->withStatus(404);
        }
    } else {
        return $response->withStatus(404);
    }
} /* }}} */

function getFolderParent($request, $response, $args) { /* {{{ */
    global $dms, $userobj;
    if($id == 0) {
        return $response->withJson(array('success'=>true, 'message'=>'id is 0', 'data'=>''), 200);
    }
    $root = $dms->getRootFolder();
    if($root->getId() == $id) {
        return $response->withJson(array('success'=>true, 'message'=>'id is root folder', 'data'=>''), 200);
    }
    $folder = $dms->getFolder($id);
    $parent = $folder->getParent();
    if($parent) {
        $rec = __getFolderData($parent);
        return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$rec), 200);
    } else {
        return $response->withJson(array('success'=>false, 'message'=>'', 'data'=>''), 500);
    }
} /* }}} */

function getFolderPath($request, $response, $args) { /* {{{ */
    global $dms, $userobj;
    if(empty($args['id'])) {
        return $response->withJson(array('success'=>true, 'message'=>'id is 0', 'data'=>''), 200);
    }
    $folder = $dms->getFolder($args['id']);

    $path = $folder->getPath();
    $data = array();
    foreach($path as $element) {
        $data[] = array('id'=>$element->getId(), 'name'=>$element->getName());
    }
    return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$data), 200);
} /* }}} */

function getFolderAttributes($request, $response, $args) { /* {{{ */
    global $dms, $userobj;
    $folder = $dms->getFolder($args['id']);

    if($folder) {
        if ($folder->getAccessMode($userobj) >= M_READ) {
            $recs = array();
            $attributes = $folder->getAttributes();
            foreach($attributes as $attribute) {
                $recs[] = array(
                    'id'=>(int)$attribute->getId(),
                    'value'=>$attribute->getValue(),
                    'name'=>$attribute->getAttributeDefinition()->getName(),
                );
            }
            return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$recs), 200);
        } else {
            return $response->withStatus(404);
        }
    }
} /* }}} */

function getFolderChildren($request, $response, $args) { /* {{{ */
    global $dms, $userobj;
    if(empty($args['id'])) {
        $folder = $dms->getRootFolder();
        $recs = array(__getFolderData($folder));
        return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$recs), 200);
    } else {
        $folder = $dms->getFolder($args['id']);
        if($folder) {
            if($folder->getAccessMode($userobj) >= M_READ) {
                $recs = array();
                $subfolders = $folder->getSubFolders();
                $subfolders = SeedDMS_Core_DMS::filterAccess($subfolders, $userobj, M_READ);
                foreach($subfolders as $subfolder) {
                    $recs[] = __getFolderData($subfolder);
                }
                $documents = $folder->getDocuments();
                $documents = SeedDMS_Core_DMS::filterAccess($documents, $userobj, M_READ);
                foreach($documents as $document) {
                    $lc = $document->getLatestContent();
                    if($lc) {
                        $recs[] = __getLatestVersionData($lc);
                    }
                }
                return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$recs), 200);
            } else {
                return $response->withJson(array('success'=>false, 'message'=>'No access', 'data'=>''), 403);
            }
        } else {
            return $response->withStatus(404);
        }
    }
} /* }}} */

function createFolder($request, $response, $args) { /* {{{ */
    global $dms, $userobj;

    if(!$userobj) {
        return $response->withJson(array('success'=>false, 'message'=>'Not logged in', 'data'=>''), 403);
    }

    if(!ctype_digit($args['id']) || $args['id'] == 0) {
        return $response->withJson(array('success'=>false, 'message'=>'No parent folder given', 'data'=>''), 400);
        return;
    }
    $parent = $dms->getFolder($args['id']);
    if($parent) {
        if($parent->getAccessMode($userobj, 'addFolder') >= M_READWRITE) {
            $params = $request->getParsedBody();
            if(!empty($params['name'])) {
                $comment = isset($params['comment']) ? $params['comment'] : '';
								if(isset($params['sequence'])) {
										$sequence = str_replace(',', '.', $params["sequence"]);
										if (!is_numeric($sequence))
												return $response->withJson(array('success'=>false, 'message'=>getMLText("invalid_sequence"), 'data'=>''), 400);
								} else {
										$dd = $parent->getSubFolders('s');
										if(count($dd) > 1)
												$sequence = $dd[count($dd)-1]->getSequence() + 1;
										else
												$sequence = 1.0;
								}
                $newattrs = array();
                if(!empty($params['attributes'])) {
                    foreach($params['attributes'] as $attrname=>$attrvalue) {
                        $attrdef = $dms->getAttributeDefinitionByName($attrname);
                        if($attrdef) {
                            $newattrs[$attrdef->getID()] = $attrvalue;
                        }
                    }
                }
                if($folder = $parent->addSubFolder($params['name'], $comment, $userobj, $sequence, $newattrs)) {

                    $rec = __getFolderData($folder);
                    return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$rec), 201);
                } else {
                    return $response->withJson(array('success'=>false, 'message'=>'Could not create folder', 'data'=>''), 500);
                }
            } else {
                return $response->withJson(array('success'=>false, 'message'=>'Missing folder name', 'data'=>''), 400);
            }
        } else {
            return $response->withJson(array('success'=>false, 'message'=>'No access on destination folder', 'data'=>''), 403);
        }
    } else {
        return $response->withJson(array('success'=>false, 'message'=>'Could not find parent folder', 'data'=>''), 500);
    }
} /* }}} */

function moveFolder($request, $response, $args) { /* {{{ */
    global $dms, $userobj;

    if(!$userobj) {
        return $response->withJson(array('success'=>false, 'message'=>'Not logged in', 'data'=>''), 403);
    }

    if(!ctype_digit($args['id']) || $args['id'] == 0) {
        return $response->withJson(array('success'=>true, 'message'=>'No source folder given', 'data'=>''), 400);
    }

    if(!ctype_digit($args['folderid']) || $args['folderid'] == 0) {
        return $response->withJson(array('success'=>true, 'message'=>'No destination folder given', 'data'=>''), 400);
    }

    $mfolder = $dms->getFolder($args['id']);
    if($mfolder) {
        if ($mfolder->getAccessMode($userobj, 'moveFolder') >= M_READ) {
            if($folder = $dms->getFolder($args['folderid'])) {
                if($folder->getAccessMode($userobj, 'moveFolder') >= M_READWRITE) {
                    if($mfolder->setParent($folder)) {
                        return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>''), 200);
                    } else {
                        return $response->withJson(array('success'=>false, 'message'=>'Error moving folder', 'data'=>''), 500);
                    }
                } else {
                    return $response->withJson(array('success'=>false, 'message'=>'No access on destination folder', 'data'=>''), 403);
                }
            } else {
                if($folder === null)
                    $status = 400;
                else
                    $status = 500;
                return $response->withJson(array('success'=>false, 'message'=>'No destination folder', 'data'=>''), $status);
            }
        } else {
            return $response->withJson(array('success'=>false, 'message'=>'No access', 'data'=>''), 403);
        }
    } else {
        if($mfolder === null)
            $status=400;
        else
            $status=500;
        return $response->withJson(array('success'=>false, 'message'=>'No folder', 'data'=>''), $status);
    }
} /* }}} */

function deleteFolder($request, $response, $args) { /* {{{ */
    global $dms, $userobj;

    if(!$userobj) {
        return $response->withJson(array('success'=>false, 'message'=>'Not logged in', 'data'=>''), 403);
    }

    if(!ctype_digit($args['id']) || $args['id'] == 0) {
        return $response->withJson(array('success'=>true, 'message'=>'id is 0', 'data'=>''), 400);
    }
    $mfolder = $dms->getFolder($args['id']);
    if($mfolder) {
        if ($mfolder->getAccessMode($userobj, 'removeFolder') >= M_READWRITE) {
            if($mfolder->remove()) {
                return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>''), 200);
            } else {
                return $response->withJson(array('success'=>false, 'message'=>'Error deleting folder', 'data'=>''), 500);
            }
        } else {
            return $response->withJson(array('success'=>false, 'message'=>'No access', 'data'=>''), 403);
        }
    } else {
        if($mfolder === null)
            $status=400;
        else
            $status=500;
        return $response->withJson(array('success'=>false, 'message'=>'No folder', 'data'=>''), $status);
    }
} /* }}} */

function uploadDocument($request, $response, $args) { /* {{{ */
    global $dms, $userobj;

    if(!$userobj) {
        return $response->withJson(array('success'=>false, 'message'=>'Not logged in', 'data'=>''), 403);
    }

    if(!ctype_digit($args['id']) || $args['id'] == 0) {
        return $response->withJson(array('success'=>false, 'message'=>'No parent folder id given', 'data'=>''), 400);
    }

    $mfolder = $dms->getFolder($args['id']);
    if($mfolder) {
        $uploadedFiles = $request->getUploadedFiles();
        if ($mfolder->getAccessMode($userobj, 'addDocument') >= M_READWRITE) {
            $params = $request->getParsedBody();
            $docname = isset($params['name']) ? $params['name'] : '';
            $keywords = isset($params['keywords']) ? $params['keywords'] : '';
            $comment = isset($params['comment']) ? $params['comment'] : '';
            if(isset($params['sequence'])) {
                $sequence = str_replace(',', '.', $params["sequence"]);
                if (!is_numeric($sequence))
                    return $response->withJson(array('success'=>false, 'message'=>getMLText("invalid_sequence"), 'data'=>''), 400);
            } else {
                $dd = $mfolder->getDocuments('s');
                if(count($dd) > 1)
                    $sequence = $dd[count($dd)-1]->getSequence() + 1;
                else
                    $sequence = 1.0;
            }
            if(isset($params['expdate'])) {
                $tmp = explode('-', $params["expdate"]);
                if(count($tmp) != 3)
                    return $response->withJson(array('success'=>false, 'message'=>getMLText('malformed_expiration_date'), 'data'=>''), 400);
                $expires = mktime(0,0,0, $tmp[1], $tmp[2], $tmp[0]);
            } else
                $expires = 0;
            $version_comment = isset($params['version_comment']) ? $params['version_comment'] : '';
            $reqversion = (isset($params['reqversion']) && (int) $params['reqversion'] > 1) ? (int) $params['reqversion'] : 1;
            $origfilename = isset($params['origfilename']) ? $params['origfilename'] : null;
            $categories = isset($params["categories"]) ? $params["categories"] : array();
            $cats = array();
            foreach($categories as $catid) {
                if($cat = $dms->getDocumentCategory($catid))
                    $cats[] = $cat;
            }
            $attributes = isset($params["attributes"]) ? $params["attributes"] : array();
            foreach($attributes as $attrdefid=>$attribute) {
                if($attrdef = $dms->getAttributeDefinition($attrdefid)) {
                    if($attribute) {
                        if(!$attrdef->validate($attribute)) {
                            return $response->withJson(array('success'=>false, 'message'=>getAttributeValidationText($attrdef->getValidationError(), $attrdef->getName(), $attribute), 'data'=>''), 400);
                        }
                    } elseif($attrdef->getMinValues() > 0) {
                        return $response->withJson(array('success'=>false, 'message'=>getMLText("attr_min_values", array("attrname"=>$attrdef->getName())), 'data'=>''), 400);
                    }
                }
            }
            if (count($uploadedFiles) == 0) {
                return $response->withJson(array('success'=>false, 'message'=>'No file detected', 'data'=>''), 400);
            }
            $file_info = array_pop($uploadedFiles);
            if ($origfilename == null)
                $origfilename = $file_info->getClientFilename();
            if (trim($docname) == '')
                $docname = $origfilename;
            $temp = $file_info->file;
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $userfiletype = finfo_file($finfo, $temp);
            $fileType = ".".pathinfo($origfilename, PATHINFO_EXTENSION);
            finfo_close($finfo);
            $res = $mfolder->addDocument($docname, $comment, $expires, $userobj, $keywords, $cats, $temp, $origfilename ? $origfilename : basename($temp), $fileType, $userfiletype, $sequence, array(), array(), $reqversion, $version_comment, $attributes);
//            addDocumentCategories($res, $categories);
//            setDocumentAttributes($res, $attributes);

            unlink($temp);
            if($res) {
                $doc = $res[0];
                $rec = array('id'=>(int)$doc->getId(), 'name'=>$doc->getName(), 'version'=>$doc->getLatestContent()->getVersion());
                return $response->withJson(array('success'=>true, 'message'=>'Upload succeded', 'data'=>$rec), 200);
            } else {
                return $response->withJson(array('success'=>false, 'message'=>'Upload failed', 'data'=>''), 500);
            }
        } else {
            return $response->withJson(array('success'=>false, 'message'=>'No access', 'data'=>''), 403);
        }
    } else {
        if($mfolder === null)
            $status=400;
        else
            $status=500;
        return $response->withJson(array('success'=>false, 'message'=>'No folder', 'data'=>''), $status);
    }
} /* }}} */

function updateDocument($request, $response, $args) { /* {{{ */
    global $dms, $userobj;

    if(!$userobj) {
        return $response->withJson(array('success'=>false, 'message'=>'Not logged in', 'data'=>''), 403);
    }

    if(!ctype_digit($args['id']) || $args['id'] == 0) {
        return $response->withJson(array('success'=>false, 'message'=>'No document id given', 'data'=>''), 400);
    }

    $document = $dms->getDocument($args['id']);
    if($document) {
        if ($document->getAccessMode($userobj, 'updateDocument') >= M_READWRITE) {
            $params = $request->getParsedBody();
            $origfilename = isset($params['origfilename']) ? $params['origfilename'] : null;
            $comment = isset($params['comment']) ? $params['comment'] : null;
            $attributes = isset($params["attributes"]) ? $params["attributes"] : array();
            foreach($attributes as $attrdefid=>$attribute) {
                if($attrdef = $dms->getAttributeDefinition($attrdefid)) {
                    if($attribute) {
                        if(!$attrdef->validate($attribute)) {
                            return $response->withJson(array('success'=>false, 'message'=>getAttributeValidationText($attrdef->getValidationError(), $attrdef->getName(), $attribute), 'data'=>''), 400);
                        }
                    } elseif($attrdef->getMinValues() > 0) {
                        return $response->withJson(array('success'=>false, 'message'=>getMLText("attr_min_values", array("attrname"=>$attrdef->getName())), 'data'=>''), 400);
                    }
                }
            }
            $uploadedFiles = $request->getUploadedFiles();
            if (count($uploadedFiles) == 0) {
                return $response->withJson(array('success'=>false, 'message'=>'No file detected', 'data'=>''), 400);
            }
            $file_info = array_pop($uploadedFiles);
            if ($origfilename == null)
                $origfilename = $file_info->getClientFilename();
            $temp = $file_info->file;
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $userfiletype = finfo_file($finfo, $temp);
            $fileType = ".".pathinfo($origfilename, PATHINFO_EXTENSION);
            finfo_close($finfo);
            $res=$document->addContent($comment, $userobj, $temp, $origfilename, $fileType, $userfiletype, array(), array(), 0, $attributes);

            unlink($temp);
            if($res) {
                $rec = array('id'=>(int)$document->getId(), 'name'=>$document->getName(), 'version'=>$document->getLatestContent()->getVersion());
                return $response->withJson(array('success'=>true, 'message'=>'Upload succeded', 'data'=>$rec), 200);
            } else {
                return $response->withJson(array('success'=>false, 'message'=>'Upload failed', 'data'=>''), 500);
            }
        } else {
            return $response->withJson(array('success'=>false, 'message'=>'No access', 'data'=>''), 403);
        }
    } else {
        return $response->withJson(array('success'=>false, 'message'=>'No document', 'data'=>''), 400);
    }
} /* }}} */

/**
 * Old upload method which uses put instead of post
 */
function uploadDocumentPut($request, $response, $args) { /* {{{ */
    global $dms, $userobj;

    if(!$userobj) {
        return $response->withJson(array('success'=>false, 'message'=>'Not logged in', 'data'=>''), 403);
    }

    if(!ctype_digit($args['id']) || $args['id'] == 0) {
        return $response->withJson(array('success'=>false, 'message'=>'No document id given', 'data'=>''), 400);
    }
    $mfolder = $dms->getFolder($args['id']);
    if($mfolder) {
        if ($mfolder->getAccessMode($userobj, 'addDocument') >= M_READWRITE) {
            $params = $request->getQueryParams();
            $docname = isset($params['name']) ? $params['name'] : '';
            $keywords = isset($params['keywords']) ? $params['keywords'] : '';
            $origfilename = isset($params['origfilename']) ? $params['origfilename'] : null;
            $content = $request->getBody();
            $temp = tempnam('/tmp', 'lajflk');
            $handle = fopen($temp, "w");
            fwrite($handle, $content);
            fclose($handle);
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $userfiletype = finfo_file($finfo, $temp);
            $fileType = ".".pathinfo($origfilename, PATHINFO_EXTENSION);
            finfo_close($finfo);
            $res = $mfolder->addDocument($docname, '', 0, $userobj, '', array(), $temp, $origfilename ? $origfilename : basename($temp), $fileType, $userfiletype, 0);
            unlink($temp);
            if($res) {
                $doc = $res[0];
                $rec = array('id'=>(int)$doc->getId(), 'name'=>$doc->getName());
                return $response->withJson(array('success'=>true, 'message'=>'Upload succeded', 'data'=>$rec), 200);
            } else {
                return $response->withJson(array('success'=>false, 'message'=>'Upload failed', 'data'=>''), 500);
            }
        } else {
            return $response->withJson(array('success'=>false, 'message'=>'No access', 'data'=>''), 403);
        }
    } else {
        if($mfolder === null)
            $status=400;
        else
            $status=500;
        return $response->withJson(array('success'=>false, 'message'=>'No folder', 'data'=>''), $status);
    }
} /* }}} */

function uploadDocumentFile($request, $response, $args) { /* {{{ */
    global $dms, $userobj;

    if(!$userobj) {
        return $response->withJson(array('success'=>false, 'message'=>'Not logged in', 'data'=>''), 403);
    }

    if(!ctype_digit($args['id']) || $args['id'] == 0) {
        return $response->withJson(array('success'=>false, 'message'=>'No document id given', 'data'=>''), 400);
    }
    $document = $dms->getDocument($args['id']);
    if($document) {
        if ($document->getAccessMode($userobj, 'addDocumentFile') >= M_READWRITE) {
            $uploadedFiles = $request->getUploadedFiles();
            $params = $request->getParsedBody();
            $docname = $params['name'];
            $keywords = isset($params['keywords']) ? $params['keywords'] : '';
            $origfilename = $params['origfilename'];
            $comment = isset($params['comment']) ? $params['comment'] : '';
            $version = empty($params['version']) ? 0 : $params['version'];
            $public = empty($params['public']) ? 'false' : $params['public'];
            if (count($uploadedFiles) == 0) {
                return $response->withJson(array('success'=>false, 'message'=>'No file detected', 'data'=>''), 400);
            }
            $file_info = array_pop($uploadedFiles);
            if ($origfilename == null)
                $origfilename = $file_info->getClientFilename();
            if (trim($docname) == '')
                $docname = $origfilename;
            $temp = $file_info->file;
            $finfo = finfo_open(FILEINFO_MIME_TYPE);
            $userfiletype = finfo_file($finfo, $temp);
            $fileType = ".".pathinfo($origfilename, PATHINFO_EXTENSION);
            finfo_close($finfo);
            $res = $document->addDocumentFile($docname, $comment, $userobj, $temp,
                        $origfilename ? $origfilename : utf8_basename($temp),
                        $fileType, $userfiletype, $version, $public);
            unlink($temp);
            if($res) {
                return $response->withJson(array('success'=>true, 'message'=>'Upload succeded', 'data'=>$res), 201);
            } else {
                return $response->withJson(array('success'=>false, 'message'=>'Upload failed', 'data'=>''), 500);
            }
        } else {
            return $response->withJson(array('success'=>false, 'message'=>'No access', 'data'=>''), 403);
        }
    } else {
        if($document === null)
            $status=400;
        else
            $status=500;
        return $response->withJson(array('success'=>false, 'message'=>'No such document', 'data'=>''), $status);
    }
} /* }}} */

function addDocumentLink($request, $response, $args) { /* {{{ */
    global $dms, $userobj;

    if(!$userobj) {
        return $response->withJson(array('success'=>false, 'message'=>'Not logged in', 'data'=>''), 403);
    }

    if(!ctype_digit($args['id']) || $args['id'] == 0) {
        return $response->withJson(array('success'=>false, 'message'=>'No source document given', 'data'=>''), 400);
        return;
    }
    $sourcedoc = $dms->getDocument($args['id']);
    $targetdoc = $dms->getDocument($args['documentid']);
    if($sourcedoc && $targetdoc) {
        if($sourcedoc->getAccessMode($userobj, 'addDocumentLink') >= M_READ) {
					$params = $request->getParsedBody();
					  $public = !isset($params['public']) ? true : false;
						if ($sourcedoc->addDocumentLink($targetdoc->getId(), $userobj->getID(), $public)){
                return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>''), 201);
						} else {
								return $response->withJson(array('success'=>false, 'message'=>'Could not create document link', 'data'=>''), 500);
						}
        } else {
            return $response->withJson(array('success'=>false, 'message'=>'No access on source document', 'data'=>''), 403);
        }
    } else {
        return $response->withJson(array('success'=>false, 'message'=>'Could not find source or target document', 'data'=>''), 500);
    }
} /* }}} */

function getDocument($request, $response, $args) { /* {{{ */
    global $dms, $userobj;
    $document = $dms->getDocument($args['id']);
    if($document) {
        if ($document->getAccessMode($userobj) >= M_READ) {
            $lc = $document->getLatestContent();
            if($lc) {
                $data = __getLatestVersionData($lc);
                return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$data), 200);
            } else {
                return $response->withJson(array('success'=>false, 'message'=>'No access', 'data'=>''), 403);
            }
        } else {
            return $response->withJson(array('success'=>false, 'message'=>'No access', 'data'=>''), 403);
        }
    } else {
        if($document === null)
            $status=400;
        else
            $status=500;
        return $response->withJson(array('success'=>false, 'message'=>'No document', 'data'=>''), $status);
    }
} /* }}} */

function deleteDocument($request, $response, $args) { /* {{{ */
    global $dms, $userobj;
    $document = $dms->getDocument($args['id']);
    if($document) {
        if ($document->getAccessMode($userobj, 'deleteDocument') >= M_READWRITE) {
            if($document->remove()) {
                return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>''), 200);
            } else {
                return $response->withJson(array('success'=>false, 'message'=>'Error removing document', 'data'=>''), 500);
            }
        } else {
            return $response->withJson(array('success'=>false, 'message'=>'No access', 'data'=>''), 403);
        }
    } else {
        if($document === null)
            $status=400;
        else
            $status=500;
        return $response->withJson(array('success'=>false, 'message'=>'No document', 'data'=>''), $status);
    }
} /* }}} */

function moveDocument($request, $response, $args) { /* {{{ */
    global $dms, $userobj;
    $document = $dms->getDocument($args['id']);
    if($document) {
        if ($document->getAccessMode($userobj, 'moveDocument') >= M_READ) {
            if($folder = $dms->getFolder($args['folderid'])) {
                if($folder->getAccessMode($userobj, 'moveDocument') >= M_READWRITE) {
                    if($document->setFolder($folder)) {
                        return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>''), 200);
                    } else {
                        return $response->withJson(array('success'=>false, 'message'=>'Error moving document', 'data'=>''), 500);
                    }
                } else {
                    return $response->withJson(array('success'=>false, 'message'=>'No access on destination folder', 'data'=>''), 403);
                }
            } else {
              if($folder === null)
                  $status=400;
              else
                  $status=500;
                return $response->withJson(array('success'=>false, 'message'=>'No destination folder', 'data'=>''), $status);
            }
        } else {
            return $response->withJson(array('success'=>false, 'message'=>'No access', 'data'=>''), 403);
        }
    } else {
        if($document === null)
            $status=400;
        else
            $status=500;
        return $response->withJson(array('success'=>false, 'message'=>'No document', 'data'=>''), $status);
    }
} /* }}} */

function getDocumentContent($request, $response, $args) { /* {{{ */
    global $dms, $userobj;
    $document = $dms->getDocument($args['id']);

    if($document) {
        if ($document->getAccessMode($userobj) >= M_READ) {
            $lc = $document->getLatestContent();
            if($lc) {
                if (pathinfo($document->getName(), PATHINFO_EXTENSION) == $lc->getFileType())
                    $filename = $document->getName();
                else
                    $filename = $document->getName().$lc->getFileType();

                $file = $dms->contentDir . $lc->getPath();
                if(!($fh = @fopen($file, 'rb'))) {
                    return $response->withJson(array('success'=>false, 'message'=>'', 'data'=>''), 500);
                }
                $stream = new \Slim\Http\Stream($fh); // create a stream instance for the response body

                return $response->withHeader('Content-Type', $lc->getMimeType())
                    ->withHeader('Content-Description', 'File Transfer')
                    ->withHeader('Content-Transfer-Encoding', 'binary')
                    ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                    ->withHeader('Content-Length', filesize($dms->contentDir . $lc->getPath()))
                    ->withHeader('Expires', '0')
                    ->withHeader('Cache-Control', 'must-revalidate, post-check=0, pre-check=0')
                    ->withHeader('Pragma', 'no-cache')
                    ->withBody($stream);

              sendFile($dms->contentDir . $lc->getPath());
            } else {
              return $response->withJson(array('success'=>false, 'message'=>'No access', 'data'=>''), 403);
            }
        } else {
            return $response->withJson(array('success'=>false, 'message'=>'No access', 'data'=>''), 403);
        }
    } else {
        if($document === null)
            $status=400;
        else
            $status=500;
        return $response->withJson(array('success'=>false, 'message'=>'No document', 'data'=>''), $status);
    }

} /* }}} */

function getDocumentVersions($request, $response, $args) { /* {{{ */
    global $dms, $userobj;
    $document = $dms->getDocument($args['id']);

    if($document) {
        if ($document->getAccessMode($userobj) >= M_READ) {
            $recs = array();
            $lcs = $document->getContent();
            foreach($lcs as $lc) {
                $recs[] = array(
                    'version'=>$lc->getVersion(),
                    'date'=>$lc->getDate(),
                    'mimetype'=>$lc->getMimeType(),
                    'size'=>$lc->getFileSize(),
                    'comment'=>$lc->getComment(),
                );
            }
            return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$recs), 200);
        } else {
            return $response->withJson(array('success'=>false, 'message'=>'No access', 'data'=>''), 403);
        }
    } else {
        if($document === null)
            $status=400;
        else
            $status=500;
        return $response->withJson(array('success'=>false, 'message'=>'No document', 'data'=>''), $status);
    }
} /* }}} */

function getDocumentVersion($request, $response, $args) { /* {{{ */
    global $dms, $userobj;
    $document = $dms->getDocument($args['id']);

    if($document) {
        if ($document->getAccessMode($userobj) >= M_READ) {
            $lc = $document->getContentByVersion($args['version']);
            if($lc) {
                if (pathinfo($document->getName(), PATHINFO_EXTENSION) == $lc->getFileType())
                    $filename = $document->getName();
                else
                    $filename = $document->getName().$lc->getFileType();

                $file = $dms->contentDir . $lc->getPath();
                if(!($fh = @fopen($file, 'rb'))) {
                    return $response->withJson(array('success'=>false, 'message'=>'', 'data'=>''), 500);
                }
                $stream = new \Slim\Http\Stream($fh); // create a stream instance for the response body

                return $response->withHeader('Content-Type', $lc->getMimeType())
                    ->withHeader('Content-Description', 'File Transfer')
                    ->withHeader('Content-Transfer-Encoding', 'binary')
                    ->withHeader('Content-Disposition', 'attachment; filename="' . $filename . '"')
                    ->withHeader('Content-Length', filesize($dms->contentDir . $lc->getPath()))
                    ->withHeader('Expires', '0')
                    ->withHeader('Cache-Control', 'must-revalidate, post-check=0, pre-check=0')
                    ->withHeader('Pragma', 'no-cache')
                    ->withBody($stream);

                sendFile($dms->contentDir . $lc->getPath());
            } else {
              return $response->withJson(array('success'=>false, 'message'=>'No such version', 'data'=>''), 400);
            }
        } else {
            return $response->withJson(array('success'=>false, 'message'=>'No access', 'data'=>''), 403);
        }
    } else {
        if($document === null)
            $status=400;
        else
            $status=500;
        return $response->withJson(array('success'=>false, 'message'=>'No document', 'data'=>''), $status);
    }
} /* }}} */

function updateDocumentVersion($request, $response, $args) { /* {{{ */
    global $dms, $userobj;
    $document = $dms->getDocument($args['id']);

    if($document) {
        if ($document->getAccessMode($userobj) >= M_READ) {
            $lc = $document->getContentByVersion($args['version']);
            if($lc) {
              $params = $request->getParsedBody();
              if (isset($params['comment'])) {
                $lc->setComment($params['comment']);
                return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>''), 200);
              }
            } else {
              return $response->withJson(array('success'=>false, 'message'=>'No such version', 'data'=>''), 400);
            }
        } else {
            return $response->withJson(array('success'=>false, 'message'=>'No access', 'data'=>''), 403);
        }
    } else {
        if($document === null)
            $status=400;
        else
            $status=500;
        return $response->withJson(array('success'=>false, 'message'=>'No document', 'data'=>''), $status);
    }
} /* }}} */

function getDocumentFiles($request, $response, $args) { /* {{{ */
    global $dms, $userobj;
    $document = $dms->getDocument($args['id']);

    if($document) {
        if ($document->getAccessMode($userobj) >= M_READ) {
            $recs = array();
            $files = $document->getDocumentFiles();
            foreach($files as $file) {
                $recs[] = array(
                    'id'=>(int)$file->getId(),
                    'name'=>$file->getName(),
                    'date'=>$file->getDate(),
                    'mimetype'=>$file->getMimeType(),
                    'comment'=>$file->getComment(),
                );
            }
            return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$recs), 200);
        } else {
            return $response->withJson(array('success'=>false, 'message'=>'No access', 'data'=>''), 403);
        }
    } else {
        if($document === null)
            $status=400;
        else
            $status=500;
        return $response->withJson(array('success'=>false, 'message'=>'No document', 'data'=>''), $status);
    }
} /* }}} */

function getDocumentFile($request, $response, $args) { /* {{{ */
    global $dms, $userobj;
    $document = $dms->getDocument($args['id']);

    if($document) {
        if ($document->getAccessMode($userobj) >= M_READ) {
            $lc = $document->getDocumentFile($args['fileid']);

            $file = $dms->contentDir . $lc->getPath();
            if(!($fh = @fopen($file, 'rb'))) {
                return $response->withJson(array('success'=>false, 'message'=>'', 'data'=>''), 500);
            }
            $stream = new \Slim\Http\Stream($fh); // create a stream instance for the response body

            return $response->withHeader('Content-Type', $lc->getMimeType())
                  ->withHeader('Content-Description', 'File Transfer')
                  ->withHeader('Content-Transfer-Encoding', 'binary')
                  ->withHeader('Content-Disposition', 'attachment; filename="' . $document->getName() . $lc->getFileType() . '"')
                  ->withHeader('Content-Length', filesize($dms->contentDir . $lc->getPath()))
                  ->withHeader('Expires', '0')
                  ->withHeader('Cache-Control', 'must-revalidate, post-check=0, pre-check=0')
                  ->withHeader('Pragma', 'no-cache')
                  ->withBody($stream);

            sendFile($dms->contentDir . $lc->getPath());
        } else {
            return $response->withJson(array('success'=>false, 'message'=>'No access', 'data'=>''), 403);
        }
    } else {
        if($document === null)
            $status=400;
        else
            $status=500;
        return $response->withJson(array('success'=>false, 'message'=>'No document', 'data'=>''), $status);
    }
} /* }}} */

function getDocumentLinks($request, $response, $args) { /* {{{ */
    global $dms, $userobj;
    $document = $dms->getDocument($args['id']);

    if($document) {
        if ($document->getAccessMode($userobj) >= M_READ) {
            $recs = array();
            $links = $document->getDocumentLinks();
            foreach($links as $link) {
                $recs[] = array(
                    'id'=>(int)$link->getId(),
                    'target'=>$link->getTarget(),
                    'public'=>$link->isPublic(),
                );
            }
            return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$recs), 200);
        } else {
            return $response->withJson(array('success'=>false, 'message'=>'No access', 'data'=>''), 403);
        }
    } else {
        if($document === null)
            $status=400;
        else
            $status=500;
        return $response->withJson(array('success'=>false, 'message'=>'No document', 'data'=>''), $status);
    }
} /* }}} */

function getDocumentAttributes($request, $response, $args) { /* {{{ */
    global $dms, $userobj;
    $document = $dms->getDocument($args['id']);

    if($document) {
        if ($document->getAccessMode($userobj) >= M_READ) {
            $recs = array();
            $attributes = $document->getAttributes();
            foreach($attributes as $attribute) {
                $recs[] = array(
                    'id'=>(int)$attribute->getId(),
                    'value'=>$attribute->getValue(),
                    'name'=>$attribute->getAttributeDefinition()->getName(),
                );
            }
            return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$recs), 200);
        } else {
            return $response->withJson(array('success'=>false, 'message'=>'No access', 'data'=>''), 403);
        }
    } else {
        if($document === null)
            $status=400;
        else
            $status=500;
        return $response->withJson(array('success'=>false, 'message'=>'No document', 'data'=>''), $status);
    }
} /* }}} */

function getDocumentPreview($request, $response, $args) { /* {{{ */
    global $dms, $userobj, $settings;
    require_once "SeedDMS/Preview.php";
    $document = $dms->getDocument($args['id']);

    if($document) {
        if ($document->getAccessMode($userobj) >= M_READ) {
            if($args['version'])
                $object = $document->getContentByVersion($args['version']);
            else
                $object = $document->getLatestContent();
            if(!$object)
                exit;

            if(!empty($args['width']))
                $previewer = new SeedDMS_Preview_Previewer($settings->_cacheDir, $args['width']);
            else
                $previewer = new SeedDMS_Preview_Previewer($settings->_cacheDir);
            if(!$previewer->hasPreview($object))
                $previewer->createPreview($object);

            $file = $previewer->getFileName($object, $args['width']).".png";
            if(!($fh = @fopen($file, 'rb'))) {
              return $response->withJson(array('success'=>false, 'message'=>'', 'data'=>''), 500);
            }
            $stream = new \Slim\Http\Stream($fh); // create a stream instance for the response body

            return $response->withHeader('Content-Type', 'image/png')
                  ->withHeader('Content-Description', 'File Transfer')
                  ->withHeader('Content-Transfer-Encoding', 'binary')
                  ->withHeader('Content-Disposition', 'attachment; filename=preview-"' . $document->getID() . "-" . $object->getVersion() . "-" . $width . ".png" . '"')
                  ->withHeader('Content-Length', $previewer->getFilesize($object))
                  ->withBody($stream);
        } else {
            return $response->withJson(array('success'=>false, 'message'=>'No access', 'data'=>''), 403);
        }
    } else {
        if($document === null)
            $status=400;
        else
            $status=500;
        return $response->withJson(array('success'=>false, 'message'=>'No document', 'data'=>''), $status);
    }
} /* }}} */

function removeDocumentCategory($request, $response, $args) { /* {{{ */
    global $dms, $userobj;
    $document = $dms->getDocument($args['id']);
    $category = $dms->getDocumentCategory($args['categoryId']);

    if($document && $category) {
        if ($document->getAccessMode($userobj, 'removeDocumentCategory') >= M_READWRITE) {
            $ret = $document->removeCategories(array($category));
            if ($ret)
                return $response->withJson(array('success'=>true, 'message'=>'Deleted category successfully.', 'data'=>''), 200);
            else
                return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>''), 200);
        } else {
            return $response->withJson(array('success'=>false, 'message'=>'No access', 'data'=>''), 403);
        }
    } else {
        if(!$document)
            return $response->withJson(array('success'=>false, 'message'=>'No such document', 'data'=>''), 400);
        if(!$category)
            return $response->withJson(array('success'=>false, 'message'=>'No such category', 'data'=>''), 400);
        return $response->withJson(array('success'=>false, 'message'=>'', 'data'=>''), 500);
    }
} /* }}} */

function removeDocumentCategories($request, $response, $args) { /* {{{ */
    global $dms, $userobj;
    $document = $dms->getDocument($args['id']);

    if($document) {
        if ($document->getAccessMode($userobj, 'removeDocumentCategory') >= M_READWRITE) {
            if($document->setCategories(array()))
                return $response->withJson(array('success'=>true, 'message'=>'Deleted categories successfully.', 'data'=>''), 200);
            else
                return $response->withJson(array('success'=>false, 'message'=>'', 'data'=>''), 200);
        } else {
            return $response->withJson(array('success'=>false, 'message'=>'No access', 'data'=>''), 403);
        }
    } else {
        if($document === null)
            $status=400;
        else
            $status=500;
        return $response->withJson(array('success'=>false, 'message'=>'No such document', 'data'=>''), $status);
    }
} /* }}} */

function getAccount($request, $response) { /* {{{ */
    global $dms, $userobj;
    if($userobj) {
        return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>__getUserData($userobj)), 200);
    } else {
        return $response->withJson(array('success'=>false, 'message'=>'Not logged in', 'data'=>''), 403);
    }
} /* }}} */

/**
 * Search for documents in the database
 *
 * If the request parameter 'mode' is set to 'typeahead', it will
 * return a list of words only.
 */
function doSearch($request, $response) { /* {{{ */
    global $dms, $userobj;

    $params = $request->getQueryParams();
    $querystr = $params['query'];
    $mode = isset($params['mode']) ? $params['mode'] : '';
    if(!isset($params['limit']) || !$limit = $params['limit'])
        $limit = 5;
    if(!isset($params['offset']) || !$offset = $params['offset'])
        $offset = 0;
    if(!isset($params['searchin']) || !$searchin = explode(",",$params['searchin']))
        $searchin = array();
    if(!isset($params['objects']) || !$objects = $params['objects'])
        $objects = 0x3;
    $resArr = $dms->search($querystr, $limit, $offset, 'AND', $searchin, null, null, array(), array(), array(), array(), array(), array(), array(), $objects);
    if($resArr === false) {
        return $response->withJson(array(), 200);
    }
    $entries = array();
    $count = 0;
    if($resArr['folders']) {
        foreach ($resArr['folders'] as $entry) {
            if ($entry->getAccessMode($userobj) >= M_READ) {
                $entries[] = $entry;
                $count++;
            }
            if($count >= $limit)
                break;
        }
    }
    $count = 0;
    if($resArr['docs']) {
        foreach ($resArr['docs'] as $entry) {
            $lc = $entry->getLatestContent();
            if ($entry->getAccessMode($userobj) >= M_READ && $lc) {
                $entries[] = $entry;
                $count++;
            }
            if($count >= $limit)
                break;
        }
    }

    switch($mode) {
        case 'typeahead';
            $recs = array();
            foreach ($entries as $entry) {
            /* Passing anything back but a string does not work, because
             * the process function of bootstrap.typeahead needs an array of
             * strings.
             *
             * As a quick solution to distingish folders from documents, the
             * name will be preceeded by a 'F' or 'D'

                $tmp = array();
                if(get_class($entry) == 'SeedDMS_Core_Document') {
                    $tmp['type'] = 'folder';
                } else {
                    $tmp['type'] = 'document';
                }
                $tmp['id'] = $entry->getID();
                $tmp['name'] = $entry->getName();
                $tmp['comment'] = $entry->getComment();
             */
                if(get_class($entry) == 'SeedDMS_Core_Document') {
                    $recs[] = 'D'.$entry->getName();
                } else {
                    $recs[] = 'F'.$entry->getName();
                }
            }
            if($recs)
//                array_unshift($recs, array('type'=>'', 'id'=>0, 'name'=>$querystr, 'comment'=>''));
                array_unshift($recs, ' '.$querystr);
            return $response->withJson($recs, 200);
            break;
        default:
            $recs = array();
            foreach ($entries as $entry) {
                if(get_class($entry) == 'SeedDMS_Core_Document') {
                    $document = $entry;
                    $lc = $document->getLatestContent();
                    if($lc) {
                        $recs[] = __getLatestVersionData($lc);
                    }
                } elseif(get_class($entry) == 'SeedDMS_Core_Folder') {
                    $folder = $entry;
                    $recs[] = __getFolderData($folder);
                }
            }
            return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$recs));
            break;
    }
} /* }}} */

/**
 * Search for documents/folders with a given attribute=value
 *
 */
function doSearchByAttr($request, $response) { /* {{{ */
    global $dms, $userobj;

    $params = $request->getQueryParams();
    $attrname = $params['name'];
    $query = $params['value'];
    if(empty($params['limit']) || !$limit = $params['limit'])
        $limit = 50;
    $attrdef = $dms->getAttributeDefinitionByName($attrname);
    $entries = array();
    if($attrdef) {
        $resArr = $attrdef->getObjects($query, $limit);
        if($resArr['folders']) {
            foreach ($resArr['folders'] as $entry) {
                if ($entry->getAccessMode($userobj) >= M_READ) {
                    $entries[] = $entry;
                }
            }
        }
        if($resArr['docs']) {
            foreach ($resArr['docs'] as $entry) {
                if ($entry->getAccessMode($userobj) >= M_READ) {
                    $entries[] = $entry;
                }
            }
        }
    }
    $recs = array();
    foreach ($entries as $entry) {
        if(get_class($entry) == 'SeedDMS_Core_Document') {
            $document = $entry;
            $lc = $document->getLatestContent();
            if($lc) {
                $recs[] = __getLatestVersionData($lc);
            }
        } elseif(get_class($entry) == 'SeedDMS_Core_Folder') {
            $folder = $entry;
            $recs[] = __getFolderData($folder);
        }
    }
    return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$recs), 200);
} /* }}} */

function checkIfAdmin($request, $response) { /* {{{ */
    global $dms, $userobj;

    if(!$userobj) {
        return $response->withJson(array('success'=>false, 'message'=>'Not logged in', 'data'=>''), 200);
    }
    if(!$userobj->isAdmin()) {
        return $response->withJson(array('success'=>false, 'message'=>'You must be logged in with an administrator account to access this resource', 'data'=>''), 200);
    }

    return true;
} /* }}} */

function getUsers($request, $response) { /* {{{ */
    global $dms, $userobj;

    $check = checkIfAdmin($request, $response);
    if($check !== true)
        return $check;

    $users = $dms->getAllUsers();
    $data = [];
    foreach($users as $u)
    $data[] = __getUserData($u);

    return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$data), 200);
} /* }}} */

function createUser($request, $response) { /* {{{ */
    global $dms, $userobj;

    $check = checkIfAdmin($request, $response);
    if($check !== true)
        return $check;

    $params = $request->getParsedBody();
    if(empty(trim($params['user']))) {
        return $response->withJson(array('success'=>false, 'message'=>'Missing user login', 'data'=>''), 500);
    }
    $userName = $params['user'];
    $password = isset($params['pass']) ? $params['pass'] : '';
    if(empty(trim($params['name']))) {
        return $response->withJson(array('success'=>false, 'message'=>'Missing full user name', 'data'=>''), 500);
    }
    $fullname = $params['name'];
    $email = isset($params['email']) ? $params['email'] : '';
    $language = isset($params['language']) ? $params['language'] : null;;
    $theme = isset($params['theme']) ? $params['theme'] : null;
    $comment = isset($params['comment']) ? $params['comment'] : null;
    $role = isset($params['role']) ? $params['role'] : null;
    $roleid = $role == 'admin' ? SeedDMS_Core_User::role_admin : ($role == 'guest' ? SeedDMS_Core_User::role_guest : SeedDMS_Core_User::role_user);

    $newAccount = $dms->addUser($userName, $password, $fullname, $email, $language, $theme, $comment, $roleid);
    if ($newAccount === false) {
        return $response->withJson(array('success'=>false, 'message'=>'Account could not be created, maybe it already exists', 'data'=>''), 500);
    }

    $result = __getUserData($newAccount);
    return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$result), 201);
} /* }}} */

function deleteUser($request, $response, $args) { /* {{{ */
    global $dms, $userobj;
    $check = checkIfAdmin($request, $response);
    if($check !== true)
        return $check;

    if($user = $dms->getUser($args['id'])) {
        if($result = $user->remove($userobj, $userobj)) {
            return $response->withJson(array('success'=>$result, 'message'=>'', 'data'=>''), 200);
        } else {
            return $response->withJson(array('success'=>$result, 'message'=>'Could not delete user', 'data'=>''), 500);
        }
    } else {
        return $response->withJson(array('success'=>false, 'message'=>'No such user', 'data'=>''), 404);
    }
} /* }}} */

/**
 * Updates the password of an existing Account, the password must be PUT as a md5 string
 *
 * @param      <type>  $id     The user name or numerical identifier
 */
function changeUserPassword($request, $response, $args) { /* {{{ */
    global $dms, $userobj;

    $check = checkIfAdmin($request, $response);
    if($check !== true)
        return $check;

    $params = $request->getParsedBody();
    if ($params['password'] == null) {
        return $response->withJson(array('success'=>false, 'message'=>'You must supply a new password', 'data'=>''), 200);
    }

    $newPassword = $params['password'];

    if(ctype_digit($args['id']))
        $account = $dms->getUser($args['id']);
    else {
        $account = $dms->getUserByLogin($args['id']);
    }

    /**
     * User not found
     */
    if (!$account) {
        return $response->withJson(array('success'=>false, 'message'=>'', 'data'=>'User not found.'), 404);
        return;
    }

    $operation = $account->setPwd($newPassword);

    if (!$operation){
        return $response->withJson(array('success'=>false, 'message'=>'', 'data'=>'Could not change password.'), 404);
    }

    return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>''), 200);
} /* }}} */

function getUserById($request, $response, $args) { /* {{{ */
    global $dms, $userobj;
    $check = checkIfAdmin($request, $response);
    if($check !== true)
        return $check;
    if(ctype_digit($args['id']))
        $account = $dms->getUser($args['id']);
    else {
        $account = $dms->getUserByLogin($args['id']);
    }
    if($account) {
        $data = __getUserData($account);
        return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$data), 200);
    } else {
        return $response->withStatus(404);
    }
} /* }}} */

function setDisabledUser($request, $response, $args) { /* {{{ */
    global $dms, $userobj;
    $check = checkIfAdmin($request, $response);
    if($check !== true)
        return $check;
    $params = $request->getParsedBody();
    if (!isset($params['disable'])) {
        return $response->withJson(array('success'=>false, 'message'=>'You must supply a disabled state', 'data'=>''), 400);
    }

    $isDisabled = false;
    $status = $params['disable'];
    if ($status == 'true' || $status == '1') {
        $isDisabled = true;
    }

    if(ctype_digit($args['id']))
        $account = $dms->getUser($args['id']);
    else {
        $account = $dms->getUserByLogin($args['id']);
    }

    if($account) {
        $account->setDisabled($isDisabled);
        $data = __getUserData($account);
        return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$data), 200);
    } else {
        return $response->withStatus(404);
    }
} /* }}} */

function getGroups($request, $response) { /* {{{ */
    global $dms, $userobj;

    $check = checkIfAdmin($request, $response);
    if($check !== true)
        return $check;

    $groups = $dms->getAllGroups();
    $data = [];
    foreach($groups as $u)
    $data[] = __getGroupData($u);

    return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$data), 200);
} /* }}} */

function createGroup($request, $response) { /* {{{ */
    global $dms, $userobj;
    $check = checkIfAdmin($request, $response);
    if($check !== true)
        return $check;
    $params = $request->getParsedBody();
    $groupName = $params['name'];
    $comment = $params['comment'];

    $newGroup = $dms->addGroup($groupName, $comment);
    if ($newGroup === false) {
        return $response->withJson(array('success'=>false, 'message'=>'Group could not be created, maybe it already exists', 'data'=>''), 500);
    }

    $result = array('id'=>(int)$newGroup->getID());
    return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$result), 201);
} /* }}} */

function getGroup($request, $response, $args) { /* {{{ */
    global $dms, $userobj;
    $check = checkIfAdmin($request, $response);
    if($check !== true)
        return $check;
    if(ctype_digit($args['id']))
        $group = $dms->getGroup($args['id']);
    else {
        $group = $dms->getGroupByName($args['id']);
    }
    if($group) {
        $data = __getGroupData($group);
        $data['users'] = array();
        foreach ($group->getUsers() as $user) {
            $data['users'][] =  array('id' => (int)$user->getID(), 'login' => $user->getLogin());
        }
        return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$data), 200);
    } else {
        return $response->withStatus(404);
    }
} /* }}} */

function changeGroupMembership($request, $response, $args, $operationType) { /* {{{ */
    global $dms, $userobj;
    $check = checkIfAdmin($request, $response);
    if($check !== true)
        return $check;

    if(ctype_digit($args['id']))
        $group = $dms->getGroup($args['id']);
    else {
        $group = $dms->getGroupByName($args['id']);
    }

   $params = $request->getParsedBody();
    if (empty($params['userid'])) {
        return $response->withJson(array('success'=>false, 'message'=>'Missing userid', 'data'=>''), 200);
    }
    $userId = $params['userid'];
    if(ctype_digit($userId))
        $user = $dms->getUser($userId);
    else {
        $user = $dms->getUserByLogin($userId);
    }

    if (!($group && $user)) {
        return $response->withStatus(404);
    }

    $operationResult = false;

    if ($operationType == 'add')
    {
        $operationResult = $group->addUser($user);
    }
    if ($operationType == 'remove')
    {
        $operationResult = $group->removeUser($user);
    }

    if ($operationResult === false)
    {
        $message = 'Could not add user to the group.';
        if ($operationType == 'remove')
        {
            $message = 'Could not remove user from group.';
        }
        return $response->withJson(array('success'=>false, 'message'=>'Something went wrong. ' . $message, 'data'=>''), 200);
    }

    $data = __getGroupData($group);
    $data['users'] = array();
    foreach ($group->getUsers() as $userObj) {
        $data['users'][] =  array('id' => (int)$userObj->getID(), 'login' => $userObj->getLogin());
    }
    return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$data), 200);
} /* }}} */

function addUserToGroup($request, $response, $args) { /* {{{ */
    return changeGroupMembership($request, $response, $args, 'add');
} /* }}} */

function removeUserFromGroup($request, $response, $args) { /* {{{ */
    return changeGroupMembership($request, $response, $args, 'remove');
} /* }}} */

function setFolderInheritsAccess($request, $response, $args) { /* {{{ */
    global $dms, $userobj;
    $check = checkIfAdmin($request, $response);
    if($check !== true)
        return $check;
    $params = $request->getParsedBody();
    if (empty($params['enable']))
    {
        return $response->withJson(array('success'=>false, 'message'=>'You must supply an "enable" value', 'data'=>''), 200);
    }

    $inherit = false;
    $status = $params['enable'];
    if ($status == 'true' || $status == '1')
    {
        $inherit = true;
    }

    if(ctype_digit($args['id']))
        $folder = $dms->getFolder($args['id']);
    else {
        $folder = $dms->getFolderByName($args['id']);
    }

    if($folder) {
        $folder->setInheritAccess($inherit);
        $folderId = $folder->getId();
        $folder = null;
        // reread from db
        $folder = $dms->getFolder($folderId);
        $success = ($folder->inheritsAccess() == $inherit);
        return $response->withJson(array('success'=>$success, 'message'=>'', 'data'=>$data), 200);
    } else {
        return $response->withStatus(404);
    }
} /* }}} */

function addUserAccessToFolder($request, $response, $args) { /* {{{ */
    return changeFolderAccess($request, $response, $args, 'add', 'user');
} /* }}} */

function addGroupAccessToFolder($request, $response, $args) { /* {{{ */
    return changeFolderAccess($request, $response, $args, 'add', 'group');
} /* }}} */

function removeUserAccessFromFolder($request, $response, $args) { /* {{{ */
    return changeFolderAccess($request, $response, $args, 'remove', 'user');
} /* }}} */

function removeGroupAccessFromFolder($request, $response, $args) { /* {{{ */
    return changeFolderAccess($request, $response, $args, 'remove', 'group');
} /* }}} */

function changeFolderAccess($request, $response, $args, $operationType, $userOrGroup) { /* {{{ */
    global $dms, $userobj;
    $check = checkIfAdmin($request, $response);
    if($check !== true)
        return $check;

    if(ctype_digit($args['id']))
        $folder = $dms->getfolder($args['id']);
    else {
        $folder = $dms->getfolderByName($args['id']);
    }
    if (!$folder) {
        return $response->withStatus(404);
    }

    $params = $request->getParsedBody();
    $userOrGroupIdInput = $params['id'];
    if ($operationType == 'add')
    {
        if ($params['id'] == null)
        {
            return $response->withJson(array('success'=>false, 'message'=>'Please PUT the user or group Id', 'data'=>''), 200);
        }

        if ($params['mode'] == null)
        {
            return $response->withJson(array('success'=>false, 'message'=>'Please PUT the access mode', 'data'=>''), 200);
        }

        $modeInput = $params['mode'];

        $mode = M_NONE;
        if ($modeInput == 'read')
        {
            $mode = M_READ;
        }
        if ($modeInput == 'readwrite')
        {
            $mode = M_READWRITE;
        }
        if ($modeInput == 'all')
        {
            $mode = M_ALL;
        }
    }


    $userOrGroupId = $userOrGroupIdInput;
    if(!ctype_digit($userOrGroupIdInput) && $userOrGroup == 'user')
    {
        $userOrGroupObj = $dms->getUserByLogin($userOrGroupIdInput);
    }
    if(!ctype_digit($userOrGroupIdInput) && $userOrGroup == 'group')
    {
        $userOrGroupObj = $dms->getGroupByName($userOrGroupIdInput);
    }
    if(ctype_digit($userOrGroupIdInput) && $userOrGroup == 'user')
    {
        $userOrGroupObj = $dms->getUser($userOrGroupIdInput);
    }
    if(ctype_digit($userOrGroupIdInput) && $userOrGroup == 'group')
    {
        $userOrGroupObj = $dms->getGroup($userOrGroupIdInput);
    }
    if (!$userOrGroupObj) {
        return $response->withStatus(404);
    }
    $userOrGroupId = $userOrGroupObj->getId();

    $operationResult = false;

    if ($operationType == 'add' && $userOrGroup == 'user')
    {
        $operationResult = $folder->addAccess($mode, $userOrGroupId, true);
    }
    if ($operationType == 'remove' && $userOrGroup == 'user')
    {
        $operationResult = $folder->removeAccess($userOrGroupId, true);
    }

    if ($operationType == 'add' && $userOrGroup == 'group')
    {
        $operationResult = $folder->addAccess($mode, $userOrGroupId, false);
    }
    if ($operationType == 'remove' && $userOrGroup == 'group')
    {
        $operationResult = $folder->removeAccess($userOrGroupId, false);
    }

    if ($operationResult === false)
    {
        $message = 'Could not add user/group access to this folder.';
        if ($operationType == 'remove')
        {
            $message = 'Could not remove user/group access from this folder.';
        }
        return $response->withJson(array('success'=>false, 'message'=>'Something went wrong. ' . $message, 'data'=>''), 200);
    }

    $data = array();
    return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$data), 200);
} /* }}} */

function getCategories($request, $response) { /* {{{ */
    global $dms, $userobj;

    if(false === ($categories = $dms->getDocumentCategories())) {
        return $response->withJson(array('success'=>false, 'message'=>'Could not get categories', 'data'=>null), 500);
    }
    $data = [];
    foreach($categories as $category)
        $data[] = ['id' => (int)$category->getId(), 'name' => $category->getName()];

    return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$data), 200);
} /* }}} */

function getCategory($request, $response, $args) { /* {{{ */
    global $dms, $userobj;

    if(!ctype_digit($args['id'])) {
        return $response->withJson(array('success'=>false, 'message'=>'No such category', 'data'=>''), 400);
    }

    $category = $dms->getDocumentCategory($args['id']);
    if($category) {
        $data = array();
        $data['id'] = (int)$category->getId();
        $data['name'] = $category->getName();
        return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$data), 200);
    } else {
        return $response->withStatus(404);
    }
} /* }}} */

function createCategory($request, $response) { /* {{{ */
    global $dms, $userobj;
    $check = checkIfAdmin($request, $response);
    if($check !== true)
        return $check;

    $params = $request->getParsedBody();
    if (empty($params['category'])) {
        return $response->withJson(array('success'=>false, 'message'=>'Need a category.', 'data'=>''), 400);
    }

    $catobj = $dms->getDocumentCategoryByName($params['category']);
    if($catobj) {
        return $response->withJson(array('success'=>false, 'message'=>'Category already exists', 'data'=>''), 409);
    } else {
        if($data = $dms->addDocumentCategory($params['category'])) {
            return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>array('id'=>(int)$data->getID())), 201);
        } else {
            return $response->withJson(array('success'=>false, 'message'=>'Could not add category', 'data'=>''), 500);
        }
    }
} /* }}} */

function deleteCategory($request, $response, $args) { /* {{{ */
    global $dms, $userobj;
    $check = checkIfAdmin($request, $response);
    if($check !== true)
        return $check;

    if($category = $dms->getDocumentCategory($args['id'])) {
        if($result = $category->remove()) {
            return $response->withJson(array('success'=>$result, 'message'=>'', 'data'=>''), 200);
        } else {
            return $response->withJson(array('success'=>$result, 'message'=>'Could not delete category', 'data'=>''), 500);
        }
    } else {
        return $response->withJson(array('success'=>false, 'message'=>'No such category', 'data'=>''), 404);
    }
} /* }}} */

/**
 * Updates the name of an existing category
 *
 * @param      <type>  $id     The user name or numerical identifier
 */
function changeCategoryName($request, $response, $args) { /* {{{ */
    global $dms, $userobj;

    $check = checkIfAdmin($request, $response);
    if($check !== true)
        return $check;

    $params = $request->getParsedBody();
    if (empty($params['name']))
    {
        return $response->withJson(array('success'=>false, 'message'=>'You must supply a new name', 'data'=>''), 200);
    }

    $newname = $params['name'];

    $category = null;
    if(ctype_digit($args['id']))
        $category = $dms->getDocumentCategory($args['id']);

    /**
     * Category not found
     */
    if (!$category) {
        return $response->withStatus(404);
    }

    if (!$category->setName($newname)) {
        return $response->withJson(array('success'=>false, 'message'=>'', 'data'=>'Could not change name.'), 200);
    }

    return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>''), 200);
} /* }}} */

function getAttributeDefinitions($request, $response) { /* {{{ */
    global $dms, $userobj;

    $attrdefs = $dms->getAllAttributeDefinitions();
    $data = [];
    foreach($attrdefs as $attrdef)
        $data[] = ['id' => (int)$attrdef->getId(), 'name' => $attrdef->getName(), 'type'=>(int)$attrdef->getType(), 'objtype'=>(int)$attrdef->getObjType(), 'min'=>(int)$attrdef->getMinValues(), 'max'=>(int)$attrdef->getMaxValues(), 'multiple'=>$attrdef->getMultipleValues()?true:false, 'valueset'=>$attrdef->getValueSetAsArray()];

    return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>$data), 200);
} /* }}} */

/**
 * Updates the name of an existing attribute definition
 *
 * @param      <type>  $id     The user name or numerical identifier
 */
function changeAttributeDefinitionName($request, $response, $args) { /* {{{ */
    global $dms, $userobj;

    $check = checkIfAdmin($request, $response);
    if($check !== true)
        return $check;

    $params = $request->getParsedBody();
    if ($params['name'] == null) {
        return $response->withJson(array('success'=>false, 'message'=>'You must supply a new name', 'data'=>''), 200);
    }

    $newname = $params['name'];

    $attrdef = null;
    if(ctype_digit($args['id']))
        $attrdef = $dms->getAttributeDefinition($args['id']);

    /**
     * Category not found
     */
    if (!$attrdef) {
        return $response->withStatus(404);
    }

    if (!$attrdef->setName($newname)) {
        return $response->withJson(array('success'=>false, 'message'=>'', 'data'=>'Could not change name.'), 200);
        return;
    }

    return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>''), 200);
} /* }}} */

function clearFolderAccessList($request, $response, $args) { /* {{{ */
    global $dms, $userobj;

    $check = checkIfAdmin($request, $response);
    if($check !== true)
        return $check;

    if(ctype_digit($args['id']))
        $folder = $dms->getFolder($args['id']);
    else {
        $folder = $dms->getFolderByName($args['id']);
    }
    if (!$folder) {
        return $response->withStatus(404);
    }
    if (!$folder->clearAccessList()) {
        return $response->withJson(array('success'=>false, 'message'=>'Something went wrong. Could not clear access list for this folder.', 'data'=>''), 200);
    }
    return $response->withJson(array('success'=>true, 'message'=>'', 'data'=>''), 200);
} /* }}} */

function echoData($request, $response) { /* {{{ */
    echo $request->getBody();
} /* }}} */

//$app = new Slim(array('mode'=>'development', '_session.handler'=>null));
$app = new \Slim\App();

// use post for create operation
// use get for retrieval operation
// use put for update operation
// use delete for delete operation
$app->post('/login', 'doLogin');
$app->get('/logout', 'doLogout');
$app->get('/account', 'getAccount');
$app->get('/search', 'doSearch');
$app->get('/searchbyattr', 'doSearchByAttr');
$app->get('/folder/', 'getFolder');
$app->get('/folder/{id}', 'getFolder');
$app->post('/folder/{id}/move/{folderid}', 'moveFolder');
$app->delete('/folder/{id}', 'deleteFolder');
$app->get('/folder/{id}/children', 'getFolderChildren');
$app->get('/folder/{id}/parent', 'getFolderParent');
$app->get('/folder/{id}/path', 'getFolderPath');
$app->get('/folder/{id}/attributes', 'getFolderAttributes');
$app->post('/folder/{id}/createfolder', 'createFolder');
$app->put('/folder/{id}/document', 'uploadDocumentPut');
$app->post('/folder/{id}/document', 'uploadDocument');
$app->get('/document/{id}', 'getDocument');
$app->post('/document/{id}/attachment', 'uploadDocumentFile');
$app->post('/document/{id}/update', 'updateDocument');
$app->delete('/document/{id}', 'deleteDocument');
$app->post('/document/{id}/move/{folderid}', 'moveDocument');
$app->get('/document/{id}/content', 'getDocumentContent');
$app->get('/document/{id}/versions', 'getDocumentVersions');
$app->get('/document/{id}/version/{version}', 'getDocumentVersion');
$app->put('/document/{id}/version/{version}', 'updateDocumentVersion');
$app->get('/document/{id}/files', 'getDocumentFiles');
$app->get('/document/{id}/file/{fileid}', 'getDocumentFile');
$app->get('/document/{id}/links', 'getDocumentLinks');
$app->post('/document/{id}/link/{documentid}', 'addDocumentLink');
$app->get('/document/{id}/attributes', 'getDocumentAttributes');
$app->get('/document/{id}/preview/{version}/{width}', 'getDocumentPreview');
$app->delete('/document/{id}/categories', 'removeDocumentCategories');
$app->delete('/document/{id}/category/{categoryId}', 'removeDocumentCategory');
$app->put('/account/fullname', 'setFullName');
$app->put('/account/email', 'setEmail');
$app->get('/account/documents/locked', 'getLockedDocuments');
$app->get('/users', 'getUsers');
$app->delete('/users/{id}', 'deleteUser');
$app->post('/users', 'createUser');
$app->get('/users/{id}', 'getUserById');
$app->put('/users/{id}/disable', 'setDisabledUser');
$app->put('/users/{id}/password', 'changeUserPassword');
$app->post('/groups', 'createGroup');
$app->get('/groups', 'getGroups');
$app->get('/groups/{id}', 'getGroup');
$app->put('/groups/{id}/addUser', 'addUserToGroup');
$app->put('/groups/{id}/removeUser', 'removeUserFromGroup');
$app->put('/folder/{id}/setInherit', 'setFolderInheritsAccess');
$app->put('/folder/{id}/access/group/add', 'addGroupAccessToFolder'); //
$app->put('/folder/{id}/access/user/add', 'addUserAccessToFolder'); //
$app->put('/folder/{id}/access/group/remove', 'removeGroupAccessFromFolder');
$app->put('/folder/{id}/access/user/remove', 'removeUserAccessFromFolder');
$app->put('/folder/{id}/access/clear', 'clearFolderAccessList');
$app->get('/categories', 'getCategories');
$app->get('/categories/{id}', 'getCategory');
$app->delete('/categories/{id}', 'deleteCategory');
$app->post('/categories', 'createCategory');
$app->put('/categories/{id}/name', 'changeCategoryName');
$app->get('/attributedefinitions', 'getAttributeDefinitions');
$app->put('/attributedefinitions/{id}/name', 'changeAttributeDefinitionName');
$app->any('/echo', 'echoData');
$app->run();

?>
