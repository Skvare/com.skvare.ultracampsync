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
  // Get the settings from the config file.
  CRM_Core_Error::debug_log_message('Ultracampbatchprocess Start...');
  $session_id_field = Civi::settings()->get('ultracampsync_session_id_field');
  $person_id_field = Civi::settings()->get('ultracampsync_person_id_field');
  $account_id_field = Civi::settings()->get('ultracampsync_account_id_field');
  $reservation_id_field = Civi::settings()->get('ultracampsync_reservation_id_field');
  $eventSessionList = CRM_Ultracampsync_Utils::getEventWithSessionId();
  // Init the variable
  $i = 0;
  $skipUpdate = 0;
  $rowsImported = 0;
  $personAccounts = [];
  $personImported = 0;
  // Prepare the query to fetch the data from the table.
  $selectQuery = "select * from civicrm_ultracamp where status = 'new' ORDER BY id ASC limit 4";
  $selectDAO = CRM_Core_DAO::executeQuery($selectQuery);
  // get relationship type mappign list.
  $relationshipTypeMapping = CRM_Ultracampsync_Utils::getRelationshipTypeMapping();
  while ($selectDAO->fetch()) {
    $values = $selectDAO->toArray();
    $values = array_merge($values, json_decode($values['data'], TRUE));
    $eventId = $eventSessionList[$values['session_id']];
    // In case session is not configured in CiviCRM event, then udpate the
    // record with 'missing session id' message.
    if (!array_key_exists($values['session_id'], $eventSessionList)) {
      $skipUpdate++;
      $sqlUpdate = "UPDATE civicrm_ultracamp SET status = 'error', message = 'missing session id' WHERE id = {$values['id']}";
      CRM_Core_DAO::executeQuery($sqlUpdate);
      continue; // Skip if session id not found in event list
    }
    // Get the event id from the session id.
    $values['event_id'] = $eventId;
    // Format the address.
    CRM_Ultracampsync_Utils::formatAddress($values);
    CRM_Core_Error::debug_log_message('Ultracampbatchprocess Contact');
    // Get/Created contact.
    $contactID = CRM_Ultracampsync_Utils::handleContact($values, $person_id_field, $account_id_field);
    CRM_Core_Error::debug_log_message('Ultracampbatchprocess Contact created/get: ' . $contactID);
    if (!empty($contactID) && empty($values['contact_id'])) {
      // Update the contact id in the ultracamp table.
      CRM_Core_DAO::setFieldValue('CRM_Ultracampsync_DAO_Ultracamp', $values['id'], 'contact_id', $contactID);
    }
    if ($contactID && $eventId) {
      CRM_Core_Error::debug_log_message('Ultracampbatchprocess contactID and event : Contact ID - ' . $contactID);
      $values['contact_id'] = $contactID;
      // create/update the address for contact.
      // CRM_Ultracampsync_Utils::handleAddress($values);
      // create participant record for the event.
      $isRecordCreated = CRM_Ultracampsync_Utils::handleParticipant($values, $reservation_id_field);
      if (!$isRecordCreated) {
        // if record not created, update the record in the ultracamp table
        // with message 'Participant not created'.
        $skipUpdate++;
        $sqlUpdate = "UPDATE civicrm_ultracamp SET status = 'error', message = 'Participant not created' WHERE id = {$values['id']}";
        CRM_Core_DAO::executeQuery($sqlUpdate);
        continue; // Skip if participant not created
      }
      else {
        // Update the message with success status.
        $sqlUpdate = "UPDATE civicrm_ultracamp SET status = 'success', message = NULL WHERE id = {$values['id']}";
        CRM_Core_DAO::executeQuery($sqlUpdate);
        $personAccounts[$values['account_id']] = $values['account_id'];
      }
    }
    else {
      // No contact
      $skipUpdate++;
      $sqlUpdate = "UPDATE civicrm_ultracamp SET status = 'error', message = 'Contact not found' WHERE id {$values['id']}";
      CRM_Core_DAO::executeQuery($sqlUpdate);
      continue; // Skip if contact not found
    }
    $rowsImported++;
  }
  CRM_Core_Error::debug_log_message('------==Start Processing Account Persons=====-----');
  CRM_Core_Error::debug_var('$personAccounts', $personAccounts);
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
      CRM_Core_Error::debug_var('Get peoples for accountId', $accountId);
      $personsData = $client->getPeoples($params);
      //CRM_Core_Error::debug_var('$personsData', $personsData);
      // Peoples record may be not restricated to registrant contact.
      $addressID = NULL;
      if (!empty($personsData)) {
        $houseHoldContactID = '';
        // Get the household name from the persons data.
        CRM_Core_Error::debug_log_message('Prepare Household name');
        [$householdName, $primaryAddressContact] = CRM_Ultracampsync_Utils::getHouseHoldName($personsData);
        CRM_Core_Error::debug_var('$householdName', $householdName);
        if ($householdName) {
          $primaryAddressContact['AccountName'] = $householdName;
          CRM_Ultracampsync_Utils::formatAddress($primaryAddressContact);
          // Get the household contact ID. (create if no matching record found).
          CRM_Core_Error::debug_log_message('Get Household Contact for name');
          $houseHoldContactID = CRM_Ultracampsync_Utils::handleHouseHoldContact($primaryAddressContact, $account_id_field);
          CRM_Core_Error::debug_var('houseHoldContactID', $houseHoldContactID);
          if ($houseHoldContactID) {
            $primaryAddressContact['contact_id'] = $houseHoldContactID;
            $addressID = CRM_Ultracampsync_Utils::handleAddress($primaryAddressContact);
            CRM_Ultracampsync_Utils::handlePhone($primaryAddressContact);
            CRM_Ultracampsync_Utils::handleEmail($primaryAddressContact);
          }
        }
        // Iterate each person.
        CRM_Core_Error::debug_log_message('Process peoples under account');
        foreach ($personsData as $personsData) {
          // Get the person contact ID. (create if no matching record found).
          //CRM_Core_Error::debug_var('$personsData', $personsData);
          $personsData['PersonId'] = $personsData['Id'];
          CRM_Ultracampsync_Utils::formatAddress($personsData);
          $personContactID = CRM_Ultracampsync_Utils::handleContact($personsData, $person_id_field, $account_id_field);
          if (!empty($personContactID)) {
            $personImported++;
            $personsData['contact_id'] = $personContactID;
            if ($addressID) {
              $personsData['master_id'] = $addressID;
            }
            //CRM_Core_Error::debug_var('$personsData', $personsData);
            CRM_Ultracampsync_Utils::handleAddress($personsData);
            CRM_Ultracampsync_Utils::handlePhone($personsData);
            CRM_Ultracampsync_Utils::handleEmail($personsData);
            // Get the person relationship type.
            $relationshipTypeFromUltraCamp = CRM_Ultracampsync_Utils::getRelationshipType($personsData);
            // If relationship type existing in the mapping array then
            // get/create relatinship.
            if (array_key_exists($relationshipTypeFromUltraCamp, $relationshipTypeMapping)) {
              $relationshipTypeID = $relationshipTypeMapping[$relationshipTypeFromUltraCamp];
              if ($personContactID && $houseHoldContactID) {
                // Check if the relationship already exist, if not then create
                // new relationship.
                CRM_Ultracampsync_Utils::handleRelationship($personContactID, $houseHoldContactID, $relationshipTypeID, $relationshipTypeFromUltraCamp);
              }
            }
          }
        }
      }
    }
  }
  $messageArray = [
    'Number of rowsImported ' . $rowsImported,
    'Number of personImported ' . $personImported,
    'Number of skipUpdate ' . $skipUpdate,
  ];
  $messageArrayString = implode(', ', $messageArray);
  CRM_Core_Error::debug_log_message('Ultracampbatchprocess: ' . $messageArrayString);
  CRM_Core_Error::debug_log_message('Ultracampbatchprocess completed.');
  $returnValues = [$message];

  return civicrm_api3_create_success($returnValues, $params, 'Job', 'Ultracampbatchprocess');
}
