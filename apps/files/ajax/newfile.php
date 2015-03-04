<?php
/**
 * @author Andreas Fischer <bantu@owncloud.com>
 * @author Georg Ehrke <georg@owncloud.com>
 * @author Jörn Friedrich Dreyer <jfd@butonic.de>
 * @author Lukas Reschke <lukas@owncloud.com>
 * @author Morris Jobke <hey@morrisjobke.de>
 * @author Robin Appelman <icewind@owncloud.com>
 * @author Thomas Müller <thomas.mueller@tmit.eu>
 * @author Vincent Petry <pvince81@owncloud.com>
 *
 * @copyright Copyright (c) 2015, ownCloud, Inc.
 * @license AGPL-3.0
 *
 * This code is free software: you can redistribute it and/or modify
 * it under the terms of the GNU Affero General Public License, version 3,
 * as published by the Free Software Foundation.
 *
 * This program is distributed in the hope that it will be useful,
 * but WITHOUT ANY WARRANTY; without even the implied warranty of
 * MERCHANTABILITY or FITNESS FOR A PARTICULAR PURPOSE. See the
 * GNU Affero General Public License for more details.
 *
 * You should have received a copy of the GNU Affero General Public License, version 3,
 * along with this program.  If not, see <http://www.gnu.org/licenses/>
 *
 */
// Init owncloud
global $eventSource;

\OCP\JSON::checkLoggedIn();
\OCP\JSON::callCheck();

\OC::$server->getSession()->close();

// Get the params
$dir = isset( $_REQUEST['dir'] ) ? '/'.trim((string)$_REQUEST['dir'], '/\\') : '';
$filename = isset( $_REQUEST['filename'] ) ? trim((string)$_REQUEST['filename'], '/\\') : '';

$l10n = \OC::$server->getL10N('files');

$result = array(
	'success' 	=> false,
	'data'		=> NULL
);
$trimmedFileName = trim($filename);

if($trimmedFileName === '') {
	$result['data'] = array('message' => (string)$l10n->t('File name cannot be empty.'));
	OCP\JSON::error($result);
	exit();
}
if($trimmedFileName === '.' || $trimmedFileName === '..') {
	$result['data'] = array('message' => (string)$l10n->t('"%s" is an invalid file name.', $trimmedFileName));
	OCP\JSON::error($result);
	exit();
}

if(!OCP\Util::isValidFileName($filename)) {
	$result['data'] = array('message' => (string)$l10n->t("Invalid name, '\\', '/', '<', '>', ':', '\"', '|', '?' and '*' are not allowed."));
	OCP\JSON::error($result);
	exit();
}

if (!\OC\Files\Filesystem::file_exists($dir . '/')) {
	$result['data'] = array('message' => (string)$l10n->t(
			'The target folder has been moved or deleted.'),
			'code' => 'targetnotfound'
		);
	OCP\JSON::error($result);
	exit();
}

$target = $dir.'/'.$filename;

if (\OC\Files\Filesystem::file_exists($target)) {
	$result['data'] = array('message' => (string)$l10n->t(
			'The name %s is already used in the folder %s. Please choose a different name.',
			array($filename, $dir))
		);
	OCP\JSON::error($result);
	exit();
}

$success = false;
$templateManager = OC_Helper::getFileTemplateManager();
$mimeType = OC_Helper::getMimetypeDetector()->detectPath($target);
$content = $templateManager->getTemplate($mimeType);

if($content) {
	$success = \OC\Files\Filesystem::file_put_contents($target, $content);
} else {
	$success = \OC\Files\Filesystem::touch($target);
}

if($success) {
	$meta = \OC\Files\Filesystem::getFileInfo($target);
	OCP\JSON::success(array('data' => \OCA\Files\Helper::formatFileInfo($meta)));
	return;
}

OCP\JSON::error(array('data' => array( 'message' => $l10n->t('Error when creating the file') )));
