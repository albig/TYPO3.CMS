<?php
namespace TYPO3\CMS\Workspaces\Domain\Record;

/**
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

use TYPO3\CMS\Workspaces\Service\StagesService;

/**
 * Combined record class
 */
class WorkspaceRecord extends AbstractRecord{

	/**
	 * @var array
	 */
	protected $internalStages = array(
		StagesService::STAGE_EDIT_ID => array(
			'name' => 'edit',
			'label' => 'LLL:EXT:lang/locallang_mod_user_ws.xlf:stage_editing'
		),
		StagesService::STAGE_PUBLISH_ID => array(
			'name' => 'publish',
			'label' => 'LLL:EXT:workspaces/Resources/Private/Language/locallang_mod.xlf:stage_ready_to_publish'
		),
		StagesService::STAGE_PUBLISH_EXECUTE_ID => array(
			'name' => 'execute',
			'label' => 'LLL:EXT:lang/locallang_mod_user_ws.xlf:stage_publish'
		),
	);

	/**
	 * @var array
	 */
	protected $internalStageFieldNames = array(
		'notification_defaults',
		'notification_preselection',
		'allow_notificaton_settings'
	);

	/**
	 * @var array
	 */
	protected $owners;

	/**
	 * @var array
	 */
	protected $members;

	/**
	 * @var StageRecord[]
	 */
	protected $stages;

	/**
	 * @param int $uid
	 * @param array $record
	 * @return WorkspaceRecord
	 */
	static public function get($uid, array $record = NULL) {
		if (empty($uid)) {
			$record = array();
		} elseif (empty($record)) {
			$record = static::fetch('sys_workspace', $uid);
		}
		return new static($record);
	}

	/**
	 * @return array
	 */
	public function getOwners() {
		if (!isset($this->owners)) {
			$this->owners = $this->getStagesService()->resolveBackendUserIds($this->record['adminusers']);
		}
		return $this->owners;
	}

	/**
	 * @return array
	 */
	public function getMembers() {
		if (!isset($this->members)) {
			$this->members = $this->getStagesService()->resolveBackendUserIds($this->record['members']);
		}
		return $this->members;
	}

	/**
	 * @return StageRecord[]
	 */
	public function getStages() {
		if (!isset($this->stages)) {
			$this->stages = array();
			$this->addStage($this->createInternalStage(StagesService::STAGE_EDIT_ID));

			$records = self::getDatabaseConnection()->exec_SELECTgetRows(
				'*', 'sys_workspace_stage',
				'deleted=0 AND parentid=' . $this->getUid() . ' AND parenttable='
					. self::getDatabaseConnection()->fullQuoteStr('sys_workspace', 'sys_workspace_stage'),
				'', 'sorting'
			);
			if (!empty($records)) {
				foreach ($records as $record) {
					$this->addStage(StageRecord::build($this, $record['uid'], $record));
				}
			}

			$this->addStage($this->createInternalStage(StagesService::STAGE_PUBLISH_ID));
			$this->addStage($this->createInternalStage(StagesService::STAGE_PUBLISH_EXECUTE_ID));
		}

		return $this->stages;
	}

	/**
	 * @param int $stageId
	 * @return NULL|StageRecord
	 */
	public function getStage($stageId) {
		$stageId = (int)$stageId;
		$this->getStages();
		if (!isset($this->stages[$stageId])) {
			return NULL;
		}
		return $this->stages[$stageId];
	}

	/**
	 * @param int $stageId
	 * @return NULL|StageRecord
	 */
	public function getPreviousStage($stageId) {
		$stageId = (int)$stageId;
		$stageIds = array_keys($this->getStages());
		$stageIndex = array_search($stageId, $stageIds);

		// catches "0" (edit stage) as well
		if (empty($stageIndex)) {
			return NULL;
		}

		$previousStageId = $stageIds[$stageIndex - 1];
		return $this->stages[$previousStageId];
	}

	/**
	 * @param int $stageId
	 * @return NULL|StageRecord
	 */
	public function getNextStage($stageId) {
		$stageId = (int)$stageId;
		$stageIds = array_keys($this->getStages());
		$stageIndex = array_search($stageId, $stageIds);

		if ($stageIndex === FALSE || !isset($stageIds[$stageIndex + 1])) {
			return NULL;
		}

		$nextStageId = $stageIds[$stageIndex + 1];
		return $this->stages[$nextStageId];
	}

	/**
	 * @param StageRecord $stage
	 */
	protected function addStage(StageRecord $stage) {
		$this->stages[$stage->getUid()] = $stage;
	}

	/**
	 * @param int $stageId
	 * @return StageRecord
	 * @throws \RuntimeException
	 */
	protected function createInternalStage($stageId) {
		$stageId = (int)$stageId;

		if (!isset($this->internalStages[$stageId])) {
			throw new \RuntimeException('Invalid internal stage "' . $stageId . '"');
		}

		$record = array(
			'uid' => $stageId,
			'title' => static::getLanguageService()->sL($this->internalStages[$stageId]['label'])
		);

		$fieldNamePrefix = $this->internalStages[$stageId]['name'] . '_';
		foreach ($this->internalStageFieldNames as $fieldName) {
			$record[$fieldName] = $this->record[$fieldNamePrefix . $fieldName];
		}

		$stage = StageRecord::build($this, $stageId, $record);
		$stage->setInternal(TRUE);
		return $stage;
	}

}