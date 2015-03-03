<?php
namespace TYPO3\CMS\Frontend;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Backend\FrontendBackendUserAuthentication;
use TYPO3\CMS\Core\Core\Bootstrap;
use TYPO3\CMS\Core\Core\RequestHandlerInterface;
use TYPO3\CMS\Core\FrontendEditing\FrontendEditingController;
use TYPO3\CMS\Core\TimeTracker\NullTimeTracker;
use TYPO3\CMS\Core\TimeTracker\TimeTracker;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Utility\MathUtility;
use TYPO3\CMS\Core\Utility\MonitorUtility;
use TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController;
use TYPO3\CMS\Frontend\Page\PageGenerator;
use TYPO3\CMS\Frontend\Utility\CompressionUtility;
use TYPO3\CMS\Frontend\View\AdminPanelView;

/**
 * This is the main entry point of the TypoScript driven standard front-end
 *
 * Basically put, this is the script which all requests for TYPO3 delivered pages goes to in the
 * frontend (the website). The script instantiates a $TSFE object, includes libraries and does a little logic here
 * and there in order to instantiate the right classes to create the webpage.
 * Previously, this was called index_ts.php and also included the logic for the lightweight "eID" concept,
 * which is now handled in a separate request handler (EidRequestHandler).
 */
class RequestHandler implements RequestHandlerInterface {

	/**
	 * Instance of the current TYPO3 bootstrap
	 * @var Bootstrap
	 */
	protected $bootstrap;

	/**
	 * Constructor handing over the bootstrap
	 *
	 * @param Bootstrap $bootstrap
	 */
	public function __construct(Bootstrap $bootstrap) {
		$this->bootstrap = $bootstrap;
	}

	/**
	 * Handles a frontend request
	 *
	 * @return void
	 */
	public function handleRequest() {
		// Timetracking started
		$configuredCookieName = trim($GLOBALS['TYPO3_CONF_VARS']['BE']['cookieName']);
		if (empty($configuredCookieName)) {
			$configuredCookieName = 'be_typo_user';
		}
		if ($_COOKIE[$configuredCookieName]) {
			$GLOBALS['TT'] = new TimeTracker();
		} else {
			$GLOBALS['TT'] = new NullTimeTracker();
		}

		$GLOBALS['TT']->start();

		// Hook to preprocess the current request:
		if (is_array($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/index_ts.php']['preprocessRequest'])) {
			foreach ($GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['tslib/index_ts.php']['preprocessRequest'] as $hookFunction) {
				$hookParameters = array();
				GeneralUtility::callUserFunction($hookFunction, $hookParameters, $hookParameters);
			}
			unset($hookFunction);
			unset($hookParameters);
		}

		/** @var $GLOBALS['TSFE'] \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController */
		$GLOBALS['TSFE'] = GeneralUtility::makeInstance(
			TypoScriptFrontendController::class,
			$GLOBALS['TYPO3_CONF_VARS'],
			GeneralUtility::_GP('id'),
			GeneralUtility::_GP('type'),
			GeneralUtility::_GP('no_cache'),
			GeneralUtility::_GP('cHash'),
			GeneralUtility::_GP('jumpurl'),
			GeneralUtility::_GP('MP'),
			GeneralUtility::_GP('RDCT')
		);

		if ($GLOBALS['TYPO3_CONF_VARS']['FE']['pageUnavailable_force']
			&& !GeneralUtility::cmpIP(
				GeneralUtility::getIndpEnv('REMOTE_ADDR'),
				$GLOBALS['TYPO3_CONF_VARS']['SYS']['devIPmask'])
		) {
			$GLOBALS['TSFE']->pageUnavailableAndExit('This page is temporarily unavailable.');
		}

		$GLOBALS['TSFE']->connectToDB();
		$GLOBALS['TSFE']->sendRedirect();

		// Output compression
		// Remove any output produced until now
		ob_clean();
		if ($GLOBALS['TYPO3_CONF_VARS']['FE']['compressionLevel'] && extension_loaded('zlib')) {
			if (MathUtility::canBeInterpretedAsInteger($GLOBALS['TYPO3_CONF_VARS']['FE']['compressionLevel'])) {
				// Prevent errors if ini_set() is unavailable (safe mode)
				@ini_set('zlib.output_compression_level', $GLOBALS['TYPO3_CONF_VARS']['FE']['compressionLevel']);
			}
			ob_start(array(GeneralUtility::makeInstance(CompressionUtility::class), 'compressionOutputHandler'));
		}

		// FE_USER
		$GLOBALS['TT']->push('Front End user initialized', '');
		/** @var $GLOBALS['TSFE'] \TYPO3\CMS\Frontend\Controller\TypoScriptFrontendController */
		$GLOBALS['TSFE']->initFEuser();
		$GLOBALS['TT']->pull();

		// BE_USER
		/** @var $GLOBALS['BE_USER'] \TYPO3\CMS\Backend\FrontendBackendUserAuthentication */
		$GLOBALS['BE_USER'] = $GLOBALS['TSFE']->initializeBackendUser();

		// Process the ID, type and other parameters.
		// After this point we have an array, $page in TSFE, which is the page-record
		// of the current page, $id.
		$GLOBALS['TT']->push('Process ID', '');
		// Initialize admin panel since simulation settings are required here:
		if ($GLOBALS['TSFE']->isBackendUserLoggedIn()) {
			$GLOBALS['BE_USER']->initializeAdminPanel();
			$this->bootstrap->loadExtensionTables(TRUE);
		} else {
			$this->bootstrap->loadCachedTca();
		}
		$GLOBALS['TSFE']->checkAlternativeIdMethods();
		$GLOBALS['TSFE']->clear_preview();
		$GLOBALS['TSFE']->determineId();

		// Now, if there is a backend user logged in and he has NO access to this page,
		// then re-evaluate the id shown! _GP('ADMCMD_noBeUser') is placed here because
		// \TYPO3\CMS\Version\Hook\PreviewHook might need to know if a backend user is logged in.
		if (
			$GLOBALS['TSFE']->isBackendUserLoggedIn()
			&& (!$GLOBALS['BE_USER']->extPageReadAccess($GLOBALS['TSFE']->page) || GeneralUtility::_GP('ADMCMD_noBeUser'))
		) {
			// Remove user
			unset($GLOBALS['BE_USER']);
			$GLOBALS['TSFE']->beUserLogin = FALSE;
			// Re-evaluate the page-id.
			$GLOBALS['TSFE']->checkAlternativeIdMethods();
			$GLOBALS['TSFE']->clear_preview();
			$GLOBALS['TSFE']->determineId();
		}

		$GLOBALS['TSFE']->makeCacheHash();
		$GLOBALS['TT']->pull();

		// Admin Panel & Frontend editing
		if ($GLOBALS['TSFE']->isBackendUserLoggedIn()) {
			$GLOBALS['BE_USER']->initializeFrontendEdit();
			if ($GLOBALS['BE_USER']->adminPanel instanceof AdminPanelView) {
				$this->bootstrap
					->initializeLanguageObject()
					->initializeSpriteManager();
			}
			if ($GLOBALS['BE_USER']->frontendEdit instanceof FrontendEditingController) {
				$GLOBALS['BE_USER']->frontendEdit->initConfigOptions();
			}
		}

		// Starts the template
		$GLOBALS['TT']->push('Start Template', '');
		$GLOBALS['TSFE']->initTemplate();
		$GLOBALS['TT']->pull();
		// Get from cache
		$GLOBALS['TT']->push('Get Page from cache', '');
		$GLOBALS['TSFE']->getFromCache();
		$GLOBALS['TT']->pull();
		// Get config if not already gotten
		// After this, we should have a valid config-array ready
		$GLOBALS['TSFE']->getConfigArray();
		// Setting language and locale
		$GLOBALS['TT']->push('Setting language and locale', '');
		$GLOBALS['TSFE']->settingLanguage();
		$GLOBALS['TSFE']->settingLocale();
		$GLOBALS['TT']->pull();

		// Convert POST data to internal "renderCharset" if different from the metaCharset
		$GLOBALS['TSFE']->convPOSTCharset();

		// Check JumpUrl
		$GLOBALS['TSFE']->setExternalJumpUrl();
		$GLOBALS['TSFE']->checkJumpUrlReferer();

		$GLOBALS['TSFE']->handleDataSubmission();

		// Check for shortcut page and redirect
		$GLOBALS['TSFE']->checkPageForShortcutRedirect();
		$GLOBALS['TSFE']->checkPageForMountpointRedirect();

		// Generate page
		$GLOBALS['TSFE']->setUrlIdToken();
		$GLOBALS['TT']->push('Page generation', '');
		if ($GLOBALS['TSFE']->isGeneratePage()) {
			$GLOBALS['TSFE']->generatePage_preProcessing();
			$temp_theScript = $GLOBALS['TSFE']->generatePage_whichScript();
			if ($temp_theScript) {
				include $temp_theScript;
			} else {
				PageGenerator::pagegenInit();
				// Global content object
				$GLOBALS['TSFE']->newCObj();
				// LIBRARY INCLUSION, TypoScript
				$temp_incFiles = PageGenerator::getIncFiles();
				foreach ($temp_incFiles as $temp_file) {
					include_once './' . $temp_file;
				}
				// Content generation
				if (!$GLOBALS['TSFE']->isINTincScript()) {
					PageGenerator::renderContent();
					$GLOBALS['TSFE']->setAbsRefPrefix();
				}
			}
			$GLOBALS['TSFE']->generatePage_postProcessing();
		} elseif ($GLOBALS['TSFE']->isINTincScript()) {
			PageGenerator::pagegenInit();
			// Global content object
			$GLOBALS['TSFE']->newCObj();
			// LIBRARY INCLUSION, TypoScript
			$temp_incFiles = PageGenerator::getIncFiles();
			foreach ($temp_incFiles as $temp_file) {
				include_once './' . $temp_file;
			}
		}
		$GLOBALS['TT']->pull();

		// $GLOBALS['TSFE']->config['INTincScript']
		if ($GLOBALS['TSFE']->isINTincScript()) {
			$GLOBALS['TT']->push('Non-cached objects', '');
			$GLOBALS['TSFE']->INTincScript();
			$GLOBALS['TT']->pull();
		}
		// Output content
		$sendTSFEContent = FALSE;
		if ($GLOBALS['TSFE']->isOutputting()) {
			$GLOBALS['TT']->push('Print Content', '');
			$GLOBALS['TSFE']->processOutput();
			$sendTSFEContent = TRUE;
			$GLOBALS['TT']->pull();
		}
		// Store session data for fe_users
		$GLOBALS['TSFE']->storeSessionData();
		// Statistics
		$GLOBALS['TYPO3_MISC']['microtime_end'] = microtime(TRUE);
		$GLOBALS['TSFE']->setParseTime();
		if (isset($GLOBALS['TSFE']->config['config']['debug'])) {
			$debugParseTime = (bool)$GLOBALS['TSFE']->config['config']['debug'];
		} else {
			$debugParseTime = !empty($GLOBALS['TSFE']->TYPO3_CONF_VARS['FE']['debug']);
		}
		if ($GLOBALS['TSFE']->isOutputting() && $debugParseTime) {
			$GLOBALS['TSFE']->content .= LF . '<!-- Parsetime: ' . $GLOBALS['TSFE']->scriptParseTime . 'ms -->';
		}
		// Check JumpUrl
		$GLOBALS['TSFE']->jumpurl();
		// Preview info
		$GLOBALS['TSFE']->previewInfo();
		// Hook for end-of-frontend
		$GLOBALS['TSFE']->hook_eofe();
		// Finish timetracking
		$GLOBALS['TT']->pull();
		// Check memory usage
		MonitorUtility::peakMemoryUsage();
		// beLoginLinkIPList
		echo $GLOBALS['TSFE']->beLoginLinkIPList();

		// Admin panel
		if (
			$GLOBALS['TSFE']->isBackendUserLoggedIn()
			&& $GLOBALS['BE_USER'] instanceof FrontendBackendUserAuthentication
			&& $GLOBALS['BE_USER']->isAdminPanelVisible()
		) {
			$GLOBALS['TSFE']->content = str_ireplace('</head>', $GLOBALS['BE_USER']->adminPanel->getAdminPanelHeaderData() . '</head>', $GLOBALS['TSFE']->content);
			$GLOBALS['TSFE']->content = str_ireplace('</body>', $GLOBALS['BE_USER']->displayAdminPanel() . '</body>', $GLOBALS['TSFE']->content);
		}

		if ($sendTSFEContent) {
			echo $GLOBALS['TSFE']->content;
		}
		// Debugging Output
		if (isset($GLOBALS['error']) && is_object($GLOBALS['error']) && @is_callable(array($GLOBALS['error'], 'debugOutput'))) {
			$GLOBALS['error']->debugOutput();
		}
		if (TYPO3_DLOG) {
			GeneralUtility::devLog('END of FRONTEND session', 'cms', 0, array('_FLUSH' => TRUE));
		}
	}

	/**
	 * This request handler can handle any frontend request.
	 *
	 * @return bool If the request is not an eID request, TRUE otherwise FALSE
	 */
	public function canHandleRequest() {
		return GeneralUtility::_GP('eID') ? FALSE : TRUE;
	}

	/**
	 * Returns the priority - how eager the handler is to actually handle the
	 * request.
	 *
	 * @return int The priority of the request handler.
	 */
	public function getPriority() {
		return 50;
	}
}