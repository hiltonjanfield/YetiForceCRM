<?php
/*+***********************************************************************************************************************************
 * The contents of this file are subject to the YetiForce Public License Version 1.1 (the "License"); you may not use this file except
 * in compliance with the License.
 * Software distributed under the License is distributed on an "AS IS" basis, WITHOUT WARRANTY OF ANY KIND, either express or implied.
 * See the License for the specific language governing rights and limitations under the License.
 * The Original Code is YetiForce.
 * The Initial Developer of the Original Code is YetiForce. Portions created by YetiForce are Copyright (C) www.yetiforce.com. 
 * All Rights Reserved.
 *************************************************************************************************************************************/
class API_CardDAV_Model {
	const ADDRESSBOOK_NAME = 'YetiForceCRM';
	
	public $pdo = false;
	public $log = false;
	public $user = false;
	public $addressBookId = false;
	public $mailFields = [
		'Contacts' => ['email'=>'INTERNET,WORK','secondary_email'=>'INTERNET,HOME'],
		'OSSEmployees' => ['business_mail'=>'INTERNET,WORK','private_mail'=>'INTERNET,HOME'],
	];
	public $telFields = [
		'Contacts' => ['phone'=>'WORK','mobile'=>'CELL'],
		'OSSEmployees' => ['business_phone'=>'WORK','private_phone'=>'CELL'],
	];
	
	function __construct($user,$log) {
		$dbconfig = vglobal('dbconfig');
		$this->pdo = new PDO('mysql:host='.$dbconfig['db_server'].';dbname='.$dbconfig['db_name'].';charset=utf8', $dbconfig['db_username'], $dbconfig['db_password']);
		$this->pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
		$this->user = $user;
		$this->log = $log;
		global $current_user;
		$current_user = $user;
		// Autoloader
		require_once 'libraries/SabreDAV/autoload.php';
	}

	public function cardDavCrm2Dav() {
		$this->log->debug( __CLASS__ . '::' . __METHOD__ . ' | Start');
		$db = PearDatabase::getInstance();
		$syncStatus = $this->checkUnsynchronisedData();
		if($syncStatus){
			$result = $db->pquery('UPDATE dav_cards SET status = ? WHERE addressbookid = ?;',[API_DAV_Model::SYNC_REDY,$this->addressBookId]);
		}
		$this->syncCrmRecord('Contacts');
		$this->syncCrmRecord('OSSEmployees');
		$this->log->debug( __CLASS__ . '::' . __METHOD__ . ' | End');
	}

	public function cardDavDav2Crm() {
		$this->log->debug( __CLASS__ . '::' . __METHOD__ . ' | Start');
		$db = PearDatabase::getInstance();
		$result = $this->getDavCardsToSync();
		$create = $deletes = $updates = 0;
		for ($i = 0; $i < $db->num_rows($result); $i++) {
			$card = $db->raw_query_result_rowdata($result, $i);
			if (!$card['crmid']){
				$this->createRecord('Contacts',$card);
				$create++;
			}elseif(!isRecordExists($card['crmid'])){
				$this->deletedCard($card);
				$deletes++;
			}else{
				$crmLMT = strtotime($card['modifiedtime']);
				$cardLMT = $card['lastmodified'];
				if($crmLMT < $cardLMT){
					$recordModel = Vtiger_Record_Model::getInstanceById($card['crmid']);
					$this->updateRecord($recordModel, $card);
					$updates++;
				}
			}
		}
		$this->log->info("cardDavDav2Crm | create: $create | deletes: $deletes | updates: $updates");
		$this->log->debug( __CLASS__ . '::' . __METHOD__ . ' | End');
	}
	
	public function createCard($moduleName,$record) {
		$this->log->debug( __CLASS__ . '::' . __METHOD__ . ' | Start CRM ID:'.$record['crmid']);
		$vcard = new Sabre\VObject\Component\VCard();
		$vcard->PRODID = 'YetiForceCRM';
		if($moduleName == 'Contacts'){
			$name = $record['firstname'] . ' ' . $record['lastname'];
			$vcard->N = [ $record['lastname'], $record['firstname']];
			$org = Vtiger_Functions::getCRMRecordLabel($record['parentid']);
			if($org != '')
				$vcard->ORG = $org;
		}
		if($moduleName == 'OSSEmployees'){
			$name = $record['name'] . ' ' . $record['last_name'];
			$vcard->N = [ $record['last_name'], $record['name']];
			$vcard->ORG = Vtiger_CompanyDetails_Model::getInstanceById()->get('organizationname');
		}
		$vcard->add('FN', $name);
		foreach ($this->telFields[$moduleName] as $key => $val) {
			$vcard->add('TEL', $record[$key], ['type' => explode(',', $val)]);
		}
		foreach ($this->mailFields[$moduleName] as $key => $val) {
			$vcard->add('EMAIL', $record[$key], ['type' => explode(',', $val)]);
		}

		$cardUri = $record['crmid'].'.vcf';
        $cardData = Sabre\DAV\StringUtil::ensureUTF8($vcard->serialize());
		$etag = md5($cardData);
		$modifiedtime = time();
		$stmt = $this->pdo->prepare('INSERT INTO dav_cards (carddata, uri, lastmodified, addressbookid, size, etag, crmid, status) VALUES (?, ?, ?, ?, ?, ?, ?, ?)');
		$stmt->execute([
			$cardData,
			$cardUri,
			$modifiedtime,
			$this->addressBookId,
			strlen($cardData),
			$etag,
			$record['crmid'],
			API_DAV_Model::SYNC_COMPLETED,
		]);
		$stmt = $this->pdo->prepare('UPDATE vtiger_crmentity SET modifiedtime = ? WHERE crmid = ?;');
		$stmt->execute([
			date('Y-m-d H:i:s', $modifiedtime),
			$record['crmid']
		]);
		$this->addChange($cardUri, 1);
		$this->log->debug( __CLASS__ . '::' . __METHOD__ . ' | End');
	}

	public function updateCard($moduleName, $record, $card) {
		$this->log->debug( __CLASS__ . '::' . __METHOD__ . ' | Start CRM ID:'.$record['crmid']);
		$vcard = Sabre\VObject\Reader::read($card['carddata']);
		$vcard->PRODID = 'YetiForceCRM';
		unset($vcard->TEL);
		unset($vcard->EMAIL);
		unset($vcard->REV);
		if($moduleName == 'Contacts'){
			$name = $record['firstname'] . ' ' . $record['lastname'];
			$vcard->N = [ $record['lastname'], $record['firstname']];
			$org = Vtiger_Functions::getCRMRecordLabel($record['parentid']);
			if($org != '')
				$vcard->ORG = $org;
		}
		if($moduleName == 'OSSEmployees'){
			$name = $record['name'] . ' ' . $record['last_name'];
			$vcard->N = [ $record['last_name'], $record['name']];
			$vcard->ORG = Vtiger_CompanyDetails_Model::getInstanceById()->get('organizationname');
		}
		$vcard->FN = $name;
		foreach ($this->telFields[$moduleName] as $key => $val) {
			$vcard->add('TEL', $record[$key], ['type' => explode(',', $val)]);
		}
		foreach ($this->mailFields[$moduleName] as $key => $val) {
			$vcard->add('EMAIL', $record[$key], ['type' => explode(',', $val)]);
		}
		
        $cardData = Sabre\DAV\StringUtil::ensureUTF8($vcard->serialize());
		$etag = md5($cardData);
		$modifiedtime = time();
		$stmt = $this->pdo->prepare('UPDATE dav_cards SET carddata = ?, lastmodified = ?, size = ?, etag = ?, crmid = ?, status = ? WHERE id = ?;');
		$stmt->execute([
			$cardData,
			$modifiedtime,
			strlen($cardData),
			$etag,
			$record['crmid'],
			API_DAV_Model::SYNC_COMPLETED,
			$card['id']
		]);
		$stmt = $this->pdo->prepare('UPDATE vtiger_crmentity SET modifiedtime = ? WHERE crmid = ?;');
		$stmt->execute([
			date('Y-m-d H:i:s', $modifiedtime),
			$record['crmid']
		]);
		$this->addChange($record['crmid'].'.vcf', 2);
		$this->log->debug( __CLASS__ . '::' . __METHOD__ . ' | End');
	}
	public function deletedCard($card) {
		$this->log->debug( __CLASS__ . '::' . __METHOD__ . ' | Start Card ID:'.$card['id']);
		$this->addChange($card['crmid'].'.vcf', 3);
		$this->log->debug( __CLASS__ . '::' . __METHOD__ . ' | End');
	}
	
	public function createRecord($module, $card) {
		$this->log->debug( __CLASS__ . '::' . __METHOD__ . ' | Start Card ID'.$card['id']);
		$vcard = Sabre\VObject\Reader::read($card['carddata']);
		if (isset($vcard->ORG)) {
			$lead = Vtiger_Record_Model::getCleanInstance('Leads');
			$lead->set('assigned_user_id', $this->user->get('id'));
			$lead->set('company', (string) $vcard->ORG);
			$lead->set('lastname', (string) $vcard->ORG);
			$lead->set('leadstatus', 'LBL_REQUIRES_VERIFICATION');
			$lead->set('vat_id', '');
			$lead->save();
			$leadId = $lead->getId();
		}
		$head = $vcard->N->getParts();

		$rekord = Vtiger_Record_Model::getCleanInstance($module);
		$rekord->set('assigned_user_id', $this->user->get('id'));
		$rekord->set('firstname', $head[1]);
		$rekord->set('lastname', $head[0]);
		if ($leadId != '') {
			$rekord->set('parent_id', $leadId);
		}
		foreach ($this->telFields[$module] as $key => $val) {
			$rekord->set($key, $this->getCardTel($vcard, $val));
		}
		foreach ($this->mailFields[$module] as $key => $val) {
			$rekord->set($key, $this->getCardMail($vcard, $val));
		}
		$rekord->save();

		$stmt = $this->pdo->prepare('UPDATE dav_cards SET crmid = ?, status = ? WHERE id = ?;');
		$stmt->execute([
			$rekord->getId(),
			API_DAV_Model::SYNC_COMPLETED,
			$card['id']
		]);
		$stmt = $this->pdo->prepare('UPDATE vtiger_crmentity SET modifiedtime = ? WHERE crmid = ?;');
		$stmt->execute([
			date('Y-m-d H:i:s', $card['lastmodified']),
			$rekord->getId()
		]);
		$this->log->debug( __CLASS__ . '::' . __METHOD__ . ' | End');
	}

	public function updateRecord($rekord, $card) {
		$this->log->debug( __CLASS__ . '::' . __METHOD__ . ' | Start Card ID:'.$card['id']);
		$vcard = Sabre\VObject\Reader::read($card['carddata']);
		$head = $vcard->N->getParts();
		$module = $rekord->getModuleName();
		$rekord->set('mode', 'edit');
		$rekord->set('assigned_user_id', $this->user->get('id'));
		$rekord->set('firstname', $head[1]);
		$rekord->set('lastname', $head[0]);
		if ($leadId != '') {
			$rekord->set('parent_id', $leadId);
		}
		foreach ($this->telFields[$module] as $key => $val) {
			$rekord->set($key, $this->getCardTel($vcard, $val));
		}
		foreach ($this->mailFields[$module] as $key => $val) {
			$rekord->set($key, $this->getCardMail($vcard, $val));
		}
		$rekord->save();

		$stmt = $this->pdo->prepare('UPDATE dav_cards SET crmid = ?, status = ? WHERE id = ?;');
		$stmt->execute([
			$rekord->getId(),
			API_DAV_Model::SYNC_COMPLETED,
			$card['id']
		]);
		$stmt = $this->pdo->prepare('UPDATE vtiger_crmentity SET modifiedtime = ? WHERE crmid = ?;');
		$stmt->execute([
			date('Y-m-d H:i:s', $card['lastmodified']),
			$rekord->getId()
		]);
		$this->log->debug( __CLASS__ . '::' . __METHOD__ . ' | End');
	}

	public function getCrmRecordsToSync($module) {
		$db = PearDatabase::getInstance();
		if($module == 'Contacts')
			$query = 'SELECT crmid, parentid, firstname, lastname, vtiger_crmentity.modifiedtime, phone, mobile, email, secondary_email FROM vtiger_contactdetails INNER JOIN vtiger_crmentity ON vtiger_contactdetails.contactid = vtiger_crmentity.crmid WHERE vtiger_crmentity.deleted=0 AND vtiger_contactdetails.contactid > 0';
		elseif($module == 'OSSEmployees')
			$query = 'SELECT crmid, name, last_name, vtiger_crmentity.modifiedtime, business_phone, private_phone, business_mail, private_mail FROM vtiger_ossemployees INNER JOIN vtiger_crmentity ON vtiger_ossemployees.ossemployeesid = vtiger_crmentity.crmid WHERE vtiger_crmentity.deleted=0 AND vtiger_ossemployees.ossemployeesid > 0';

		$instance = CRMEntity::getInstance($module);
		$securityParameter = $instance->getUserAccessConditionsQuerySR($module, $this->user);
		if ($securityParameter != '')
			$query.= ' ' . $securityParameter;
		$result = $db->query($query);
		return $result;
	}
	/**
	 * Verify if there is any unsynchronised data
	 * @param type $user
	 */
	public function checkUnsynchronisedData() {
		$db = PearDatabase::getInstance();
		$sql = "SELECT * FROM dav_cards WHERE addressbookid = ? AND status = ?;";
		$result = $db->pquery($sql, [$this->addressBookId, API_DAV_Model::SYNC_REDY]);
		return $db->num_rows($result) == 0 ? true : false;
	}

	public function getAddressBookId() {
		$db = PearDatabase::getInstance();
		$sql = "SELECT dav_addressbooks.id FROM dav_addressbooks INNER JOIN dav_principals ON dav_principals.uri = dav_addressbooks.principaluri WHERE dav_principals.userid = ? AND dav_addressbooks.uri = ?;";
		$result = $db->pquery($sql, [$this->user->getId(), self::ADDRESSBOOK_NAME]);
		$this->addressBookId = $db->query_result_raw($result, 0, 'id');
	}

	public function getCardDetail($crmid) {
		$db = PearDatabase::getInstance();
		$sql = "SELECT * FROM dav_cards WHERE addressbookid = ? AND crmid = ?;";
		$result = $db->pquery($sql, [$this->addressBookId, $crmid]);
		return $db->num_rows($result) > 0 ? $db->raw_query_result_rowdata($result, 0) : false;
	}
	public function getDavCardsToSync() {
		$db = PearDatabase::getInstance();
		$query = 'SELECT dav_cards.*, vtiger_crmentity.modifiedtime FROM dav_cards LEFT JOIN vtiger_crmentity ON vtiger_crmentity.crmid = dav_cards.crmid WHERE addressbookid = ?';
		$result = $db->pquery($query,[$this->addressBookId]);
		return $result;
	}
	public function syncCrmRecord($module) {
		$db = PearDatabase::getInstance();
		$create = $deletes = $updates = 0;
		$result = $this->getCrmRecordsToSync($module);
		for ($i = 0; $i < $db->num_rows($result); $i++) {
			$record = $db->raw_query_result_rowdata($result, $i);
			$card = $this->getCardDetail($record['crmid']);
			if ($card == false){
				$this->createCard($module,$record);
				$create++;
			}else{
				$crmLMT = strtotime($record['modifiedtime']);
				$cardLMT = $card['lastmodified'];
				if($crmLMT > $cardLMT){
					$this->updateCard($module,$record, $card);
					$updates++;
				}
			}
		}
		$this->log->info("syncCrmRecord $module | create: $create | deletes: $deletes | updates: $updates");
	}
	public function getCardTel($vcard,$type) {
		if(!isset($vcard->TEL));
			return '';
		foreach ($vcard->TEL as $t) {
			foreach ($t->parameters() as $k => $p) {
				if($p->getValue() == $type && $t->getValue() != ''){
					return $t->getValue();
				}
			}
		}
		return '';
	}
	public function getCardMail($vcard,$type) {
		if(!isset($vcard->EMAIL));
			return '';
		foreach ($vcard->EMAIL as $e) {
			foreach ($e->parameters() as $k => $p) {
				if($p->getValue() == $type && $e->getValue() != ''){
					return $e->getValue();
				}
			}
		}
		return '';
	}
    /**
     * Adds a change record to the addressbookchanges table.
     *
     * @param mixed $addressBookId
     * @param string $objectUri
     * @param int $operation 1 = add, 2 = modify, 3 = delete
     * @return void
     */
    protected function addChange($objectUri, $operation) {
        $stmt = $this->pdo->prepare('INSERT INTO dav_addressbookchanges  (uri, synctoken, addressbookid, operation) SELECT ?, synctoken, ?, ? FROM dav_addressbooks WHERE id = ?');
        $stmt->execute([
            $objectUri,
            $this->addressBookId,
            $operation,
            $this->addressBookId
        ]);
        $stmt = $this->pdo->prepare('UPDATE dav_addressbooks SET synctoken = synctoken + 1 WHERE id = ?');
        $stmt->execute([
            $this->addressBookId
        ]);

    }
}