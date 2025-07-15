<?php

/**
 * Enhanced Batch Processor Class
 */
class CRM_Ultracampsync_BatchProcessor {

  protected $stats = [
    'total_processed' => 0,
    'successful' => 0,
    'errors' => 0,
    'skipped' => 0,
    'contacts_created' => 0,
    'contacts_updated' => 0,
    'participants_created' => 0,
    'households_created' => 0,
    'relationships_created' => 0,
    'start_time' => NULL,
    'end_time' => NULL,
  ];

  protected $config = [];
  protected $eventSessionList = [];
  protected $relationshipTypeMapping = [];
  protected $progressCallback = NULL;

  /**
   * Process batch of UltraCamp records
   *
   * @param array $params Processing parameters
   * @return array Result array
   * @throws CRM_Core_Exception
   */
  public function process($params) {
    $this->stats['start_time'] = microtime(TRUE);
    $this->initializeConfig();
    $this->validateParams($params);

    try {
      CRM_Ultracampsync_Utils::log('Enhanced batch processing started with params: ' . print_r($params, TRUE));

      // Get records to process
      $records = $this->getRecordsToProcess($params);

      if (empty($records)) {
        return $this->completeProcessing(['message' => 'No records to process']);
      }

      CRM_Ultracampsync_Utils::log("Processing {$records['count']} records");

      // Process each record with transaction management
      $processedAccounts = $this->processRecords($records['values'], $params);

      // Process related account data
      if (!empty($processedAccounts)) {
        CRM_Ultracampsync_Utils::log("Processing account relationships for " . count($processedAccounts) . " accounts");
        $this->processAccountRelationships($processedAccounts);
      }
      else {
        CRM_Ultracampsync_Utils::log('No accounts to process for relationships');
        if (!empty($records['values'][0]['id'])) {
          $this->updateExtraRecordStatus(
            $records['values'][0]['id'],
            'warning',
            'No accounts processed, skipping relationship processing'
          );
        }
      }

      return $this->completeProcessing();

    }
    catch (Exception $e) {
      CRM_Ultracampsync_Utils::log('Batch processing failed: ' . $e->getMessage(), 'error');
      throw new CRM_Core_Exception('Batch processing failed: ' . $e->getMessage());
    }
  }

  /**
   * Initialize configuration and mappings
   */
  protected function initializeConfig() {
    $this->config = [
      'person_id_field' => Civi::settings()->get('ultracampsync_person_id_field'),
      'account_id_field' => Civi::settings()->get('ultracampsync_account_id_field'),
      'reservation_id_field' => Civi::settings()->get('ultracampsync_reservation_id_field'),
      'primary_contact_field' => Civi::settings()->get('ultracampsync_primary_contact_field'),
      'session_id_field' => Civi::settings()->get('ultracampsync_session_id_field'),
    ];

    // Validate required configuration
    foreach (['person_id_field', 'account_id_field', 'session_id_field', 'session_id_field'] as $required) {
      if (empty($this->config[$required])) {
        throw new CRM_Core_Exception("Required configuration missing: {$required}");
      }
    }

    $this->eventSessionList = CRM_Ultracampsync_Utils::getEventWithSessionId();
    $this->relationshipTypeMapping = CRM_Ultracampsync_Utils::getRelationshipTypeMapping();

    CRM_Ultracampsync_Utils::logExtra('Configuration loaded: ' . print_r($this->config, TRUE));
  }

  /**
   * Validate processing parameters
   *
   * @param array $params
   * @throws CRM_Core_Exception
   */
  protected function validateParams($params) {
    if (!empty($params['progress_callback']) && !filter_var($params['progress_callback'], FILTER_VALIDATE_URL)) {
      throw new CRM_Core_Exception('Invalid progress callback URL');
    }

    $this->progressCallback = $params['progress_callback'] ?? NULL;
  }

  /**
   * Get records to process based on parameters
   *
   * @param array $params
   * @return array Records and count
   */
  protected function getRecordsToProcess($params) {
    $whereConditions = ["status IN ('new', 'retry')"];
    $sqlParams = [];
    $paramIndex = 1;

    if (!empty($params['retry_errors'])) {
      $whereConditions = ["status IN ('new', 'retry', error')"];
    }

    if (!empty($params['session_id'])) {
      $whereConditions[] = "session_id = %{$paramIndex}";
      $sqlParams[$paramIndex] = [$params['session_id'], 'Integer'];
      $paramIndex++;
    }

    $whereClause = implode(' AND ', $whereConditions);
    $limit = (int)$params['limit'];

    // Get count first
    $countQuery = "SELECT COUNT(*) FROM civicrm_ultracamp WHERE {$whereClause}";
    $totalCount = CRM_Core_DAO::singleValueQuery($countQuery, $sqlParams);

    // Get records
    $selectQuery = "SELECT * FROM civicrm_ultracamp WHERE {$whereClause} ORDER BY id ASC LIMIT {$limit}";
    $dao = CRM_Core_DAO::executeQuery($selectQuery, $sqlParams);

    $records = [];
    while ($dao->fetch()) {
      $records[] = $dao->toArray();
    }

    return [
      'count' => $totalCount,
      'batch_size' => count($records),
      'values' => $records
    ];
  }

  /**
   * Process individual records with transaction management
   *
   * @param array $records
   * @param array $params
   */
  protected function processRecords($records, $params) {
    $processedAccounts = [];

    foreach ($records as $index => $record) {
      $transaction = new CRM_Core_Transaction();

      try {
        $this->stats['total_processed']++;

        $result = $this->processRecord($record);
        if ($result['success']) {
          $this->stats['successful']++;
          if (!empty($result['account_id'])) {
            $processedAccounts[$result['account_id']] = $record['id'];
          }

          // Update record status
          $this->updateRecordStatus($record['id'], 'success', $result['message']);
          $this->updateExtraRecordStatus(
            $record['id'],
            'success',
            "Processed successfully: " . $result['message']
          );

        }
        else {
          $this->stats['errors']++;
          $processedAccounts[$result['account_id']] = $record['id'];
          $this->updateRecordStatus($record['id'], 'error', $result['message']);
          $this->updateExtraRecordStatus(
            $record['id'],
            'error',
            "Processing failed: " . $result['message']
          );
        }

        $transaction->commit();

        // Send progress update
        $this->sendProgressUpdate($index + 1, count($records));

      }
      catch (Exception $e) {
        $transaction->rollback();
        $this->stats['errors']++;
        $errorMessage = 'Processing failed: ' . $e->getMessage();

        CRM_Ultracampsync_Utils::log("Error processing record {$record['id']}: {$errorMessage}", 'error');
        $this->updateRecordStatus($record['id'], 'error', $errorMessage);
        $this->updateExtraRecordStatus(
          $record['id'],
          'error',
          "Processing failed: " . $errorMessage
        );
      }
    }

    return $processedAccounts;
  }

  /**
   * Process individual record
   *
   * @param array $record
   * @return array Processing result
   */
  protected function processRecord($record) {
    // Decode and merge JSON data
    $values = array_merge($record, json_decode($record['data'], TRUE) ?: []);

    // Validate session mapping
    if (!array_key_exists($values['session_id'], $this->eventSessionList)) {
      $this->stats['skipped']++;
      return [
        'success' => FALSE,
        'account_id' => $values['account_id'] ?? NULL,
        'message' => 'Session ID not mapped to CiviCRM event: ' . $values['session_id']
      ];
    }

    $values['event_id'] = $this->eventSessionList[$values['session_id']];

    // Format address data
    CRM_Ultracampsync_Utils::formatAddress($values);

    // Handle contact creation/update
    $values = array_merge($values, $this->config);
    $contactResult = $this->handleContact($values);

    if (!$contactResult['success']) {
      return $contactResult;
    }

    $contactId = $contactResult['contact_id'];
    $values['contact_id'] = $contactId;

    // Update contact ID in record if it was missing
    if (empty($record['contact_id'])) {
      CRM_Core_DAO::setFieldValue('CRM_Ultracampsync_DAO_Ultracamp', $record['id'], 'contact_id', $contactId);
    }

    // Handle participant creation
    $participantResult = $this->handleParticipant($values);
    $this->updateExtraRecordStatus(
      $record['id'],
      $participantResult['success'] ? 'processing' : 'error',
      $participantResult['message']
    );
    if (!$participantResult['success']) {
      return $participantResult;
    }

    return [
      'success' => TRUE,
      'message' => $participantResult['message'],
      'contact_id' => $contactId,
      'account_id' => $values['account_id'] ?? NULL,
      'participant_created' => $participantResult['created']
    ];
  }

  /**
   * Enhanced contact handling with better tracking
   *
   * @param array $contactParams
   * @return array Result with success status and contact ID
   */
  protected function handleContact($contactParams) {
    try {
      $contactId = CRM_Ultracampsync_Utils::handleContact($contactParams);

      if (empty($contactId)) {
        return [
          'success' => FALSE,
          'account_id' => $contactParams['account_id'] ?? NULL,
          'message' => 'Failed to create or find contact'
        ];
      }

      // Determine if this was a create or update
      $isNew = empty($contactParams['contact_id']);
      if ($isNew) {
        $this->stats['contacts_created']++;
      }
      else {
        $this->stats['contacts_updated']++;
      }

      return [
        'success' => TRUE,
        'contact_id' => $contactId,
        'account_id' => $contactParams['account_id'] ?? NULL,
        'created' => $isNew
      ];

    }
    catch (Exception $e) {
      return [
        'success' => FALSE,
        'account_id' => $contactParams['account_id'] ?? NULL,
        'message' => 'Contact handling failed: ' . $e->getMessage()
      ];
    }
  }

  /**
   * Enhanced participant handling with better tracking
   *
   * @param array $participantParams
   * @return array Result with success status
   */
  protected function handleParticipant($participantParams) {
    try {
      $result = CRM_Ultracampsync_Utils::handleParticipant(
        $participantParams,
        $this->config['reservation_id_field']
      );

      switch ($result) {
        case 'success':
          $this->stats['participants_created']++;
          return [
            'success' => TRUE,
            'message' => 'Participant created successfully',
            'created' => TRUE,
            'account_id' => $participantParams['account_id'] ?? NULL,
          ];

        case 'exists':
          $this->stats['skipped']++;
          return [
            'success' => FALSE,
            'message' => 'Participant already exists',
            'account_id' => $participantParams['account_id'] ?? NULL,
          ];

        case 'error':
        default:
          return [
            'success' => FALSE,
            'message' => 'Failed to create participant',
            'account_id' => $participantParams['account_id'] ?? NULL,
          ];
      }

    }
    catch (Exception $e) {
      return [
        'success' => FALSE,
        'message' => 'Participant handling failed: ' . $e->getMessage(),
        'account_id' => $participantParams['account_id'] ?? NULL,
      ];
    }
  }

  /**
   * Process account relationships with improved error handling
   *
   * @param array $params
   */
  protected function processAccountRelationships($accountIds) {
    try {
      CRM_Ultracampsync_Utils::log('Processing account relationships');

      // Get unique account IDs from successfully processed records
      // $accountIds = $this->getProcessedAccountIds($params);

      if (empty($accountIds)) {
        CRM_Ultracampsync_Utils::log('No account IDs to process for relationships');
        return;
      }

      $client = new CRM_Ultracampsync_API_UltracampClient();

      foreach ($accountIds as $accountId => $recordID) {
        $this->processAccountRelationship($accountId, $client, $recordID);
      }

    }
    catch (Exception $e) {
      CRM_Ultracampsync_Utils::log('Error processing account relationships: ' . $e->getMessage(), 'error');
    }
  }

  /**
   * Process relationships for a single account
   *
   * @param $accountId
   * @param CRM_Ultracampsync_API_UltracampClient $client
   * @param $recordID
   * @return void
   */
  protected function processAccountRelationship($accountId, $client, $recordID) {
    $transaction = new CRM_Core_Transaction();

    try {
      CRM_Ultracampsync_Utils::logExtra("Processing relationships for account: {$accountId}");
      $this->updateExtraRecordStatus(
        $recordID,
        'processing',
        "Processing relationships for account: {$accountId}"
      );
      $params = ['accountNumber' => $accountId];
      $personsData = $client->getPeoples($params);

      if (empty($personsData)) {
        CRM_Ultracampsync_Utils::logExtra("No persons found for account: {$accountId}");
        $this->updateExtraRecordStatus(
          $recordID,
          'warning',
          "No persons found for account: {$accountId}"
        );
        return;
      }
      else {
        $this->updateExtraRecordStatus(
          $recordID,
          'processing',
          count($personsData) . " persons found for account: {$accountId}"
        );
        CRM_Ultracampsync_Utils::logExtra(count($personsData) . " persons found for account: {$accountId}");
      }

      // Process household and relationships
      $householdResult = $this->processHouseholdForAccount($personsData, $accountId);

      if ($householdResult['success']) {
        $this->updateExtraRecordStatus($recordID, 'processing', 'Household created: ' . $householdResult['household_name']);
        $personsNames = [];
        foreach ($personsData as $personData) {
          $personsNames[] = $personData['FirstName'] . ' ' . $personData['LastName'] . ', contact ID: ' . $personData['Id'];
        }
        CRM_Ultracampsync_Utils::logExtra("Household created: {$householdResult['household_name']}, Now Check for these members: " . implode(', ', $personsNames));
        $this->updateExtraRecordStatus(
          $recordID,
          'processing',
          "Household created: {$householdResult['household_name']}, Now Check for these members: " . implode(', ', $personsNames)
        );
        $this->processPersonRelationships($personsData, $householdResult['household_id'], $recordID);
      }
      else {
        $this->updateExtraRecordStatus($recordID, 'warning', $householdResult['message']);
      }

      $transaction->commit();

    }
    catch (Exception $e) {
      $transaction->rollback();
      CRM_Ultracampsync_Utils::log("Error processing account {$accountId}: " . $e->getMessage(), 'error');
    }
  }

  /**
   * Process household creation for account
   *
   * @param array $personsData
   * @param string $accountId
   * @return array Result with household ID
   */
  protected function processHouseholdForAccount($personsData, $accountId) {
    try {
      [$householdName, $primaryAddressContact] = CRM_Ultracampsync_Utils::getHouseHoldName($personsData);

      if (empty($householdName)) {
        return ['success' => FALSE, 'message' => 'Could not determine household name'];
      }

      $primaryAddressContact['AccountName'] = $householdName;
      CRM_Ultracampsync_Utils::formatAddress($primaryAddressContact);

      $householdId = CRM_Ultracampsync_Utils::handleHouseHoldContact(
        $primaryAddressContact,
        $this->config['account_id_field']
      );

      if (empty($householdId)) {
        return ['success' => FALSE, 'message' => 'Failed to create household'];
      }

      $this->stats['households_created']++;

      // Handle household address and contact info
      $primaryAddressContact['contact_id'] = $householdId;
      CRM_Ultracampsync_Utils::handleAddress($primaryAddressContact);
      CRM_Ultracampsync_Utils::handlePhone($primaryAddressContact);
      CRM_Ultracampsync_Utils::handleEmail($primaryAddressContact);

      return [
        'success' => TRUE,
        'household_id' => $householdId,
        'household_name' => $householdName
      ];

    }
    catch (Exception $e) {
      return [
        'success' => FALSE,
        'message' => 'Household processing failed: ' . $e->getMessage()
      ];
    }
  }

  /**
   * Process person relationships for household
   *
   * @param array $personsData
   * @param int $householdId
   * @param int $recordID
   */
  protected function processPersonRelationships($personsData, $householdId, $recordID) {
    foreach ($personsData as $personData) {
      try {
        $this->processPersonRelationship($personData, $householdId, $recordID);
      }
      catch (Exception $e) {
        CRM_Ultracampsync_Utils::log("Error processing person relationship: " . $e->getMessage(), 'error');
        $this->updateExtraRecordStatus(
          $recordID,
          'error',
          'Failed to process person relationship: ' . $e->getMessage()
        );
      }
    }
  }

  /**
   * Process relationship for individual person
   *
   * @param array $personData
   * @param int $householdId
   * @param int $recordID
   */
  protected function processPersonRelationship($personData, $householdId, $recordID) {
    $personData['PersonId'] = $personData['Id'];
    CRM_Ultracampsync_Utils::formatAddress($personData);

    $personData = array_merge($personData, $this->config);
    try {
      $personContactId = CRM_Ultracampsync_Utils::handleContact($personData);
    }
    catch (Exception $e) {
      $this->updateExtraRecordStatus(
        $recordID,
        'error',
        'Failed to handle person contact: ' . $e->getMessage()
      );
      return;
    }
    if (empty($personContactId)) {
      $this->UpdateExtraRecordStatus(
        $recordID,
        'error',
        'Failed to create or find person contact ' . $personData['Id']
      );
      return;
    }

    // Handle person address and contact info
    $personData['contact_id'] = $personContactId;
    try {
      CRM_Ultracampsync_Utils::handleAddress($personData);
    }
    catch (Exception $e) {
      $this->updateExtraRecordStatus(
        $recordID,
        'error',
        'Failed to handle person address: ' . $e->getMessage()
      );
    }
    try {
      CRM_Ultracampsync_Utils::handlePhone($personData);
    }
    catch (Exception $e) {
      $this->updateExtraRecordStatus(
        $recordID,
        'error',
        'Failed to handle person phone: ' . $e->getMessage()
      );
    }

    try {
      CRM_Ultracampsync_Utils::handleEmail($personData);
    }
    catch (Exception $e) {
      $this->updateExtraRecordStatus(
        $recordID,
        'error',
        'Failed to handle person email: ' . $e->getMessage()
      );
    }

    // Process relationship
    $relationshipType = CRM_Ultracampsync_Utils::getRelationshipType($personData);

    if (empty($relationshipType) || !array_key_exists($relationshipType, $this->relationshipTypeMapping)) {
      CRM_Ultracampsync_Utils::logExtra("Unknown or unmapped relationship type: " . $relationshipType);
      $this->updateExtraRecordStatus(
        $recordID,
        'warning',
        "Unknown or unmapped relationship type: {$relationshipType}, using default 'Other Extended Family'"
      );
      // Set Default relationship type if not found.
      $relationshipType = 'Other Extended Family';
    }

    $relationshipTypeId = $this->relationshipTypeMapping[$relationshipType];
    try {
      CRM_Ultracampsync_Utils::handleRelationship(
        $personContactId,
        $householdId,
        $relationshipTypeId,
        $relationshipType
      );
    }
    catch (Exception $e) {
      $this->updateExtraRecordStatus(
        $recordID,
        'error',
        'Failed to create relationship: ' . $e->getMessage()
      );
      CRM_Ultracampsync_Utils::log("Failed to create relationship for person ID {$personData['Id']}: " . $e->getMessage(), 'error');
      return;
    }

    $this->stats['relationships_created']++;
  }

  /**
   * Get processed account IDs from recent successful records
   *
   * @param array $params
   * @return array Account IDs
   */
  protected function getProcessedAccountIds($params) {
    $limit = (int)$params['limit'];
    $whereConditions = ["status = 'success'", "account_id IS NOT NULL"];

    if (!empty($params['session_id'])) {
      $whereConditions[] = "session_id = " . (int)$params['session_id'];
    }

    $whereClause = implode(' AND ', $whereConditions);

    $query = "
      SELECT DISTINCT account_id
      FROM civicrm_ultracamp
      WHERE {$whereClause}
      ORDER BY id DESC
      LIMIT {$limit}
    ";

    $dao = CRM_Core_DAO::executeQuery($query);
    $accountIds = [];

    while ($dao->fetch()) {
      $accountIds[] = $dao->account_id;
    }

    return $accountIds;
  }

  /**
   * Update record status in database
   *
   * @param int $recordId
   * @param string $status
   * @param string $message
   */
  protected function updateRecordStatus($recordId, $status, $message = NULL) {
    $updateQuery = "UPDATE civicrm_ultracamp SET status = %1, message = %2 WHERE id = %3";
    $params = [
      1 => [$status, 'String'],
      2 => [$message, 'String'],
      3 => [$recordId, 'Integer']
    ];

    CRM_Core_DAO::executeQuery($updateQuery, $params);
  }

  /**
   * Update record status in database
   *
   * @param int $recordId
   * @param string $status
   * @param string $message
   */
  protected function updateExtraRecordStatus($recordId, $status, $message = NULL) {
    // Get message_for_relelated_contact and append to existing message.
    $existingMessage = CRM_Core_DAO::getFieldValue('CRM_Ultracampsync_DAO_Ultracamp', $recordId, 'message_for_relelated_contact', 'id');
    if (!empty($existingMessage)) {
      $message = $existingMessage . '::' . $message;
    }
    $updateQuery = "UPDATE civicrm_ultracamp SET message_for_relelated_contact = %2 WHERE id = %3";
    $params = [
      //1 => [$status, 'String'],
      2 => [$message, 'String'],
      3 => [$recordId, 'Integer']
    ];
    CRM_Core_DAO::executeQuery($updateQuery, $params);
  }

  /**
   * Send progress update if callback URL is configured
   *
   * @param int $current
   * @param int $total
   */
  protected function sendProgressUpdate($current, $total) {
    if (empty($this->progressCallback)) {
      return;
    }

    $progress = [
      'current' => $current,
      'total' => $total,
      'percentage' => round(($current / $total) * 100, 2),
      'stats' => $this->stats
    ];

    // Send async HTTP request (implement as needed)
    $this->sendAsyncRequest($this->progressCallback, $progress);
  }

  /**
   * Send asynchronous HTTP request for progress updates
   *
   * @param string $url
   * @param array $data
   */
  protected function sendAsyncRequest($url, $data) {
    // Implementation would depend on your environment
    // This is a placeholder for async progress reporting
    CRM_Ultracampsync_Utils::logExtra("Progress update: " . json_encode($data));
  }

  /**
   * Complete processing and return results
   *
   * @param array $additionalData
   * @return array
   */
  protected function completeProcessing($additionalData = []) {
    $this->stats['end_time'] = microtime(TRUE);
    $this->stats['duration'] = round($this->stats['end_time'] - $this->stats['start_time'], 2);

    $message = sprintf(
      'Batch processing completed: %d processed, %d successful,%d contact created, %d participant created, %d households created, %d relationships created, %d errors, %d skipped (%.2fs)',
      $this->stats['total_processed'],
      $this->stats['successful'],
      $this->stats['contacts_created'],
      $this->stats['participants_created'],
      $this->stats['households_created'],
      $this->stats['relationships_created'],
      $this->stats['errors'],
      $this->stats['skipped'],
      $this->stats['duration']
    );

    CRM_Ultracampsync_Utils::log($message);

    $result = array_merge([
      'message' => $message,
      'stats' => $this->stats
    ], $additionalData);

    return civicrm_api3_create_success([$result], [], 'Job', 'Ultracampbatchprocess');
  }
}
