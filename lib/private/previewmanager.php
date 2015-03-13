<?php
/**
 * Copyright (c) 2013 Thomas Müller thomas.mueller@tmit.eu
 * This file is licensed under the Affero General Public License version 3 or
 * later.
 * See the COPYING-README file.
 *
 */
namespace OC;

use OCP\image;
use OCP\IPreview;

class PreviewManager implements IPreview {
	/** @var array */
	protected $providers = [];

	/**
	 * Constructor
	 */
	public function __construct() {
		$this->registerCoreProviders();
	}

	/**
	 * In order to improve lazy loading a closure can be registered which will be
	 * called in case preview providers are actually requested
	 *
	 * $callable has to return an instance of \OC\Preview\Provider
	 *
	 * @param string $mimeTypeRegex Regex with the mime types that are supported by this provider
	 * @param \Closure $callable
	 * @return void
	 */
	public function registerProvider($mimeTypeRegex, \Closure $callable) {
		if (!\OC::$server->getConfig()->getSystemValue('enable_previews', true)) {
			return;
		}

		if (!isset($this->providers[$mimeTypeRegex])) {
			$this->providers[$mimeTypeRegex] = [];
		}
		$this->providers[$mimeTypeRegex][] = $callable;
	}

	/**
	 * Get all providers
	 * @return array
	 */
	public function getProviders() {
		$keys = array_map('strlen', array_keys($this->providers));
		array_multisort($keys, SORT_DESC, $this->providers);

		return $this->providers;
	}

	/**
	 * Does the manager have any providers
	 * @return bool
	 */
	public function hasProviders() {
		return !empty($this->providers);
	}

	/**
	 * return a preview of a file
	 *
	 * @param string $file The path to the file where you want a thumbnail from
	 * @param int $maxX The maximum X size of the thumbnail. It can be smaller depending on the shape of the image
	 * @param int $maxY The maximum Y size of the thumbnail. It can be smaller depending on the shape of the image
	 * @param boolean $scaleUp Scale smaller images up to the thumbnail size or not. Might look ugly
	 * @return \OCP\Image
	 */
	function createPreview($file, $maxX = 100, $maxY = 75, $scaleUp = false) {
		$preview = new \OC\Preview('', '/', $file, $maxX, $maxY, $scaleUp);
		return $preview->getPreview();
	}

	/**
	 * returns true if the passed mime type is supported
	 *
	 * @param string $mimeType
	 * @return boolean
	 */
	function isMimeSupported($mimeType = '*') {
		if (!\OC::$server->getConfig()->getSystemValue('enable_previews', true)) {
			return false;
		}

		$providerMimeTypes = array_keys($this->providers);
		foreach ($providerMimeTypes as $supportedMimeType) {
			if (preg_match($supportedMimeType, $mimeType)) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Check if a preview can be generated for a file
	 *
	 * @param \OCP\Files\FileInfo $file
	 * @return bool
	 */
	function isAvailable($file) {
		if (!\OC::$server->getConfig()->getSystemValue('enable_previews', true)) {
			return false;
		}

		$mount = $file->getMountPoint();
		if ($mount and !$mount->getOption('previews', true)){
			return false;
		}

		foreach ($this->providers as $supportedMimeType => $providers) {
			if (preg_match($supportedMimeType, $file->getMimetype())) {
				foreach ($providers as $closure) {
					$provider = $closure();
					if (!($provider instanceof \OC\Preview\Provider)) {
						continue;
					}

					/** @var $provider \OC\Preview\Provider */
					if ($provider->isAvailable($file)) {
						return true;
					}
				}
			}
		}
		return false;
	}

	/** @var array */
	protected $defaultProviders;

	/**
	 * List of enabled default providers
	 *
	 * The following providers are enabled by default:
	 *  - OC\Preview\Image
	 *  - OC\Preview\MP3
	 *  - OC\Preview\TXT
	 *  - OC\Preview\MarkDown
	 *
	 * The following providers are disabled by default due to performance or privacy concerns:
	 *  - OC\Preview\MSOfficeDoc
	 *  - OC\Preview\MSOffice2003
	 *  - OC\Preview\MSOffice2007
	 *  - OC\Preview\OpenDocument
	 *  - OC\Preview\StarOffice
	 *  - OC\Preview\SVG
	 *  - OC\Preview\Movie
	 *  - OC\Preview\PDF
	 *  - OC\Preview\TIFF
	 *  - OC\Preview\Illustrator
	 *  - OC\Preview\Postscript
	 *  - OC\Preview\Photoshop
	 *  - OC\Preview\Font
	 *
	 * @return array
	 */
	protected function getEnabledDefaultProvider() {
		if ($this->defaultProviders !== null) {
			return $this->defaultProviders;
		}

		$this->defaultProviders = \OC::$server->getConfig()->getSystemValue('enabledPreviewProviders', [
			'OC\Preview\Image',
			'OC\Preview\MP3',
			'OC\Preview\TXT',
			'OC\Preview\MarkDown',
		]);
		return $this->defaultProviders;
	}

	/**
	 * Register the default providers (if enabled)
	 *
	 * @param string $class
	 * @param string $mimeType
	 */
	protected function registerCoreProvider($class, $mimeType) {
		if (in_array(trim($class, '\\'), $this->getEnabledDefaultProvider())) {
			$this->registerProvider($mimeType, function () use ($class) {
				return new $class([]);
			});
		}
	}

	/**
	 * Register the default providers (if enabled)
	 */
	protected function registerCoreProviders() {
		$this->registerCoreProvider('OC\Preview\TXT', '/text\/plain/');
		$this->registerCoreProvider('OC\Preview\MarkDown', '/text\/(x-)?markdown/');
		$this->registerCoreProvider('OC\Preview\Image', '/image\/(?!tiff$)(?!svg.*).*/');
		$this->registerCoreProvider('OC\Preview\MP3', '/audio\/mpeg/');

		// SVG, Office and Bitmap require imagick
		if (extension_loaded('imagick')) {
			$checkImagick = new \Imagick();

			$imagickProviders = [
				'SVG'	=> ['mimetype' => '/image\/svg\+xml/', 'class' => '\OC\Preview\SVG'],
				'TIFF'	=> ['mimetype' => '/image\/tiff/', 'class' => '\OC\Preview\TIFF'],
				'PDF'	=> ['mimetype' => '/application\/pdf/', 'class' => '\OC\Preview\PDF'],
				'AI'	=> ['mimetype' => '/application\/illustrator/', 'class' => '\OC\Preview\Illustrator'],
				'PSD'	=> ['mimetype' => '/application\/x-photoshop/', 'class' => '\OC\Preview\Photoshop'],
				'EPS'	=> ['mimetype' => '/application\/postscript/', 'class' => '\OC\Preview\Postscript'],
				'TTF'	=> ['mimetype' => '/application\/(?:font-sfnt|x-font$)/', 'class' => '\OC\Preview\Font'],
			];

			foreach ($imagickProviders as $queryFormat => $provider) {
				$class = $provider['class'];
				if (!in_array(trim($class, '\\'), $this->getEnabledDefaultProvider())) {
					continue;
				}

				if (count($checkImagick->queryFormats($queryFormat)) === 1) {
					$this->registerCoreProvider($class, $provider['mimetype']);
				}
			}

			if (count($checkImagick->queryFormats('PDF')) === 1) {
				// Office previews are currently not supported on Windows
				if (!\OC_Util::runningOnWindows() && \OC_Helper::is_function_enabled('shell_exec')) {
					$officeFound = is_string(\OC::$server->getConfig()->getSystemValue('preview_libreoffice_path', null));

					if (!$officeFound) {
						//let's see if there is libreoffice or openoffice on this machine
						$whichLibreOffice = shell_exec('command -v libreoffice');
						$officeFound = !empty($whichLibreOffice);
						if (!$officeFound) {
							$whichOpenOffice = shell_exec('command -v openoffice');
							$officeFound = !empty($whichOpenOffice);
						}
					}

					if ($officeFound) {
						$this->registerCoreProvider('\OC\Preview\MSOfficeDoc', '/application\/msword/');
						$this->registerCoreProvider('\OC\Preview\MSOffice2003', '/application\/vnd.ms-.*/');
						$this->registerCoreProvider('\OC\Preview\MSOffice2007', '/application\/vnd.openxmlformats-officedocument.*/');
						$this->registerCoreProvider('\OC\Preview\OpenDocument', '/application\/vnd.oasis.opendocument.*/');
						$this->registerCoreProvider('\OC\Preview\StarOffice', '/application\/vnd.sun.xml.*/');
					}
				}
			}
		}

		// Video requires avconv or ffmpeg and is therefor
		// currently not supported on Windows.
		if (in_array('OC\Preview\Movie', $this->getEnabledDefaultProvider()) && !\OC_Util::runningOnWindows()) {
			$avconvBinary = \OC_Helper::findBinaryPath('avconv');
			$ffmpegBinary = ($avconvBinary) ? null : \OC_Helper::findBinaryPath('ffmpeg');

			if ($avconvBinary || $ffmpegBinary) {
				// FIXME // a bit hacky but didn't want to use subclasses
				\OC\Preview\Movie::$avconvBinary = $avconvBinary;
				\OC\Preview\Movie::$ffmpegBinary = $ffmpegBinary;

				$this->registerCoreProvider('\OC\Preview\Movie', '/video\/.*/');
			}
		}
	}
}
