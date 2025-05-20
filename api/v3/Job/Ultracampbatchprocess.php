<?php
use CRM_Ultracampsync_ExtensionUtil as E;

/**
 * Job.Ultracampbatchprocess API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_job_Ultracampbatchprocess_spec(&$spec) {
}

/**
 * Job.Ultracampbatchprocess API
 *
 * @param array $params
 *
 * @return array
 *   API result descriptor
 *
 * @see civicrm_api3_create_success
 *
 * @throws API_Exception
 */
function civicrm_api3_job_Ultracampbatchprocess($params) {
  CRM_Core_Error::debug_log_message('Ultracampbatchprocess Start...');
  $session_id_field = Civi::settings()->get('ultracampsync_session_id_field');
  $person_id_field = Civi::settings()->get('ultracampsync_person_id_field');
  $account_id_field = Civi::settings()->get('ultracampsync_account_id_field');
  $reservation_id_field = Civi::settings()->get('ultracampsync_reservation_id_field');
  $eventSessionList = CRM_Ultracampsync_Utils::getEventWithSessionId();
  $state = CRM_Ultracampsync_Utils::state();
  $country = CRM_Ultracampsync_Utils::country();
  $i = 0;
  $skipUpdate = 0;
  $rowsImported = 0;
  $personAccounts = [];
  $selectQuery = "select * from civicrm_ultracamp where status = 'new' limit 100";
  $selectDAO = CRM_Core_DAO::executeQuery($selectQuery);
  $relationshipTypeMapping = CRM_Ultracampsync_Utils::getRelationshipTypeMapping();
  while ($selectDAO->fetch()) {
    $values = $selectDAO->toArray();
    $values = array_merge($values, json_decode($values['data'], TRUE));
    $eventId = $eventSessionList[$values['session_id']];
    $personAccounts[$values['account_id']] = $values['account_id'];
    if (!array_key_exists($values['session_id'], $eventSessionList)) {
      $skipUpdate++;
      $sqlUpdate = "UPDATE civicrm_ultracamp SET status = 'error', message = 'missing session id' WHERE id = {$values['id']}";
      CRM_Core_DAO::executeQuery($sqlUpdate);
      continue; // Skip if session id not found in event list
    }
    $values['event_id'] = $eventId;
    if ($values['PersonCountry']) {
      $values['PersonCountryID'] = $country[strtolower($values['PersonCountry'])];
      if (!empty($values['PersonState']) && !empty($values['PersonCountryID'])) {
        $values['PersonStateID'] = $state[$values['PersonCountryID']][strtolower($values['PersonState'])];
      }
    }

    $contactID = CRM_Ultracampsync_Utils::handleContact($values, $person_id_field, $account_id_field);
    if (!empty($contactID) && empty($values['contact_id'])) {
      CRM_Core_DAO::setFieldValue('CRM_Ultracampsync_DAO_Ultracamp', $values['id'], 'contact_id', $contactID);
    }
    if ($contactID && $eventId) {
      $values['contact_id'] = $contactID;
      CRM_Ultracampsync_Utils::handleAddress($values);
      $isRecordCreated = CRM_Ultracampsync_Utils::handleParticipant($values, $reservation_id_field);
      if (!$isRecordCreated) {
        $skipUpdate++;
        $sqlUpdate = "UPDATE civicrm_ultracamp SET status = 'error', message = 'Participant not created' WHERE id = {$values['id']}";
        CRM_Core_DAO::executeQuery($sqlUpdate);
        continue; // Skip if participant not created
      }
      else {
        $sqlUpdate = "UPDATE civicrm_ultracamp SET status = 'success' WHERE id = {$values['id']}";
        CRM_Core_DAO::executeQuery($sqlUpdate);
      }
    }
    else {
      $skipUpdate++;
      $sqlUpdate = "UPDATE civicrm_ultracamp SET status = 'error', message = 'Contact not found' WHERE id {$values['id']}";
      CRM_Core_DAO::executeQuery($sqlUpdate);
      continue; // Skip if contact not found
    }
    $rowsImported++;
  }
  // Get All person associated with account and create relationship if required.
  if (!empty($personAccounts)) {
    $client = new CRM_UltracampSync_API_UltracampClient();
    foreach ($personAccounts as $accountId) {
      $params = [
        'accountNumber' => $accountId,
        //'accountStatus' => 'active',
        //'accountType' => 'person',
      ];
      // Get the persons associated with the account.
      $personsData = $client->getPeoples($params);
      // Peoples record may be not restricated to registrant contact.
      if (!empty($personsData)) {
        $houseHoldContactID = '';
        // Get the household name from the persons data.
        $householdName = CRM_Ultracampsync_Utils::getHouseHoldName($personsData);
        if ($householdName) {
          $householdParms = [];
          $householdParms['AccountName'] = $householdName;
          $householdParms['AccountId'] = $accountId;
          // Get the household contact ID. (create if no matching record found).
          $houseHoldContactID = CRM_Ultracampsync_Utils::handleHouseHoldContact($householdParms, $account_id_field);
        }
        // Iterate each person.
        foreach ($personsData as $personsData) {
          // Get the person contact ID. (create if no matching record found).
          $personContactID = CRM_Ultracampsync_Utils::handleContact($personsData, $person_id_field, $account_id_field);
          // Get the person relationship type.
          $relationshipTypeFromUltraCamp = CRM_Ultracampsync_Utils::getRelationshipType($personsData);
          // If relationship type existing in the mapping array then
          // get/create relatinship.
          if (array_key_exists($relationshipTypeFromUltraCamp, $relationshipTypeMapping)) {
            $relationshipTypeID = $relationshipTypeMapping[$relationshipTypeFromUltraCamp];
            if ($personContactID && $houseHoldContactID) {
              // Check if the relationship already exist, if not then create
              // new relationship.
              CRM_Ultracampsync_Utils::handleRelationship($personContactID, $houseHoldContactID, $relationshipTypeID);
            }
          }
        }
      }
    }
  }
  $message = "Number of Records processed - {$rowsImported}, skipUpdate = {$skipUpdate}";
  CRM_Core_Error::debug_log_message('Ultracampbatchprocess ' . $message);
  CRM_Core_Error::debug_log_message('Ultracampbatchprocess completed.');
  $returnValues = [$message];

  return civicrm_api3_create_success($returnValues, $params, 'Job', 'Ultracampbatchprocess');
}
