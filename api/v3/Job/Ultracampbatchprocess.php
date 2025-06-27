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
  $spec['limit'] = [
    'type' => CRM_Utils_Type::T_STRING,
    'name' => 'limit',
    'title' => 'Limit',
  ];
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
  $limit = $params['limit'] ?? 100;
  // Get the settings from the config file.
  CRM_Ultracampsync_Utils::log('Ultracampbatchprocess Start...');
  $person_id_field = Civi::settings()->get('ultracampsync_person_id_field');
  $account_id_field = Civi::settings()->get('ultracampsync_account_id_field');
  $reservation_id_field = Civi::settings()->get('ultracampsync_reservation_id_field');
  $primary_contact_field = Civi::settings()->get('ultracampsync_primary_contact_field');
  $eventSessionList = CRM_Ultracampsync_Utils::getEventWithSessionId();
  // Init the variable
  $skipUpdate = $personImported = $rowsImported = 0;
  $personAccounts = [];
  // Prepare the query to fetch the data from the table.
  $selectQuery = "select * from civicrm_ultracamp where status = 'new' ORDER BY id ASC limit {$limit}";
  $selectDAO = CRM_Core_DAO::executeQuery($selectQuery);
  // get relationship type mapping list.
  $relationshipTypeMapping = CRM_Ultracampsync_Utils::getRelationshipTypeMapping();
  while ($selectDAO->fetch()) {
    $values = $selectDAO->toArray();
    $values = array_merge($values, json_decode($values['data'], TRUE));
    // In case session is not configured in CiviCRM event, then update the
    // record with 'missing session id' message.
    if (!array_key_exists($values['session_id'], $eventSessionList)) {
      $skipUpdate++;
      $sqlUpdate = "UPDATE civicrm_ultracamp SET status = 'error', message = 'missing session id' WHERE id = {$values['id']}";
      CRM_Core_DAO::executeQuery($sqlUpdate);
      continue; // Skip if session id not found in event list
    }
    // Get the event id from the session id.
    $eventId = $eventSessionList[$values['session_id']];
    $values['event_id'] = $eventId;
    // Format the address.
    CRM_Ultracampsync_Utils::formatAddress($values);
    CRM_Ultracampsync_Utils::log('Ultracampbatchprocess Contact');
    // Get/Created contact.
    $values['person_id_field'] = $person_id_field;
    $values['account_id_field'] = $account_id_field;
    $values['primary_contact_field'] = $primary_contact_field;
    $contactID = CRM_Ultracampsync_Utils::handleContact($values);
    CRM_Ultracampsync_Utils::log('Ultracampbatchprocess Contact created/get: ' . $contactID);
    if (!empty($contactID) && empty($values['contact_id'])) {
      // Update the contact id in the ultracamp table.
      CRM_Core_DAO::setFieldValue('CRM_Ultracampsync_DAO_Ultracamp', $values['id'], 'contact_id', $contactID);
    }
    if ($contactID && $eventId) {
      CRM_Ultracampsync_Utils::log('Ultracampbatchprocess contactID and event : Contact ID - ' . $contactID);
      $values['contact_id'] = $contactID;
      // create/update the address for contact.
      // CRM_Ultracampsync_Utils::handleAddress($values);
      // create participant record for the event.
      $isRecordCreated = CRM_Ultracampsync_Utils::handleParticipant($values, $reservation_id_field);
      if ($isRecordCreated == 'exists') {
        // if record not created, update the record in the ultracamp table
        // with message 'Participant not created'.
        $skipUpdate++;
        $sqlUpdate = "UPDATE civicrm_ultracamp SET status = 'error', message = 'Participant already exists' WHERE id = {$values['id']}";
        CRM_Core_DAO::executeQuery($sqlUpdate);
        $personAccounts[$values['account_id']] = $values['account_id'];
        continue; // Skip if participant not created
      }
      if ($isRecordCreated == 'error') {
        // if record not created, update the record in the ultracamp table
        // with message 'Participant not created'.
        $skipUpdate++;
        $sqlUpdate = "UPDATE civicrm_ultracamp SET status = 'error', message = 'Error while creating participant' WHERE id = {$values['id']}";
        CRM_Core_DAO::executeQuery($sqlUpdate);
        continue; // Skip if participant not created
      }
      else {
        // Update the message with success status.
        $sqlUpdate = "UPDATE civicrm_ultracamp SET status = 'success', message = NULL WHERE id = {$values['id']}";
        CRM_Core_DAO::executeQuery($sqlUpdate);
        // Add account ID into array for further processing account people.
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
  CRM_Ultracampsync_Utils::log('------==Start Processing Account Persons=====-----');
  CRM_Ultracampsync_Utils::logExtra('$personAccounts: ' . print_r($personAccounts, TRUE));
  // Get All person associated with account and create relationship if required.
  if (!empty($personAccounts)) {
    $client = new CRM_Ultracampsync_API_UltracampClient();
    foreach ($personAccounts as $accountId) {
      $params = [
        'accountNumber' => $accountId,
        //'accountStatus' => 'active',
        //'accountType' => 'person',
      ];
      // Get the persons associated with the account.
      CRM_Ultracampsync_Utils::log('Get peoples for accountId ' . $accountId);
      $personsData = $client->getPeoples($params);
      //CRM_Core_Error::debug_var('$personsData', $personsData);
      // Peoples record may be not restricted to registrant contact.
      $addressID = NULL;
      if (!empty($personsData)) {
        $houseHoldContactID = '';
        // Get the household name from the persons data.
        CRM_Ultracampsync_Utils::log('Prepare Household name');
        [$householdName, $primaryAddressContact] = CRM_Ultracampsync_Utils::getHouseHoldName($personsData);
        CRM_Ultracampsync_Utils::log('$householdName: ' . $householdName);
        if ($householdName) {
          $primaryAddressContact['AccountName'] = $householdName;
          CRM_Ultracampsync_Utils::formatAddress($primaryAddressContact);
          // Get the household contact ID. (create if no matching record found).
          CRM_Ultracampsync_Utils::log('Get Household Contact for name');
          $houseHoldContactID = CRM_Ultracampsync_Utils::handleHouseHoldContact($primaryAddressContact, $account_id_field);
          CRM_Ultracampsync_Utils::log('houseHoldContactID:' . $houseHoldContactID);
          if ($houseHoldContactID) {
            $primaryAddressContact['contact_id'] = $houseHoldContactID;
            $addressID = CRM_Ultracampsync_Utils::handleAddress($primaryAddressContact);
            CRM_Ultracampsync_Utils::handlePhone($primaryAddressContact);
            CRM_Ultracampsync_Utils::handleEmail($primaryAddressContact);
          }
        }
        // Iterate each person.
        CRM_Ultracampsync_Utils::log('Process peoples under account, total peoples: ' . count($personsData));
        foreach ($personsData as $personData) {
          // Get the person contact ID. (create if no matching record found).
          //CRM_Core_Error::debug_var('$personData', $personData);
          $personData['PersonId'] = $personData['Id'];
          CRM_Ultracampsync_Utils::formatAddress($personData);
          $personData['person_id_field'] = $person_id_field;
          $personData['account_id_field'] = $account_id_field;
          $personData['primary_contact_field'] = $primary_contact_field;
          CRM_Ultracampsync_Utils::logExtra('Get Person Contact for name: ' . print_r($personData, TRUE));
          $personContactID = CRM_Ultracampsync_Utils::handleContact($personData);
          CRM_Ultracampsync_Utils::log('$personContactID: ' . $personContactID);
          if (!empty($personContactID)) {
            $personImported++;
            $personData['contact_id'] = $personContactID;
            if ($addressID) {
              // Use for Shared Address.
              // $personsData['master_id'] = $addressID;
            }
            //CRM_Core_Error::debug_var('$personData', $personData);
            CRM_Ultracampsync_Utils::handleAddress($personData);
            CRM_Ultracampsync_Utils::handlePhone($personData);
            CRM_Ultracampsync_Utils::handleEmail($personData);
            // Get the person relationship type.
            $relationshipTypeFromUltraCamp = CRM_Ultracampsync_Utils::getRelationshipType($personData);
            CRM_Ultracampsync_Utils::log('Get Relationship Type ' . $relationshipTypeFromUltraCamp);
            // If relationship type existing in the mapping array then
            // get/create relationship.
            if (!empty($relationshipTypeFromUltraCamp)) {
              if (array_key_exists($relationshipTypeFromUltraCamp, $relationshipTypeMapping)) {
                $relationshipTypeID = $relationshipTypeMapping[$relationshipTypeFromUltraCamp];
                if ($houseHoldContactID) {
                  // Check if the relationship already exist, if not then create
                  // new relationship.
                  CRM_Ultracampsync_Utils::handleRelationship($personContactID, $houseHoldContactID, $relationshipTypeID, $relationshipTypeFromUltraCamp);
                }
              }
              else {
                CRM_Ultracampsync_Utils::log('Ultracampbatchprocess: Relationship type not found in mapping: ' . $relationshipTypeFromUltraCamp);
              }
            }
            else {
              CRM_Ultracampsync_Utils::log('Ultracampbatchprocess: Relationship type not found in UltraCamp data for person: ' . $personData['PersonId']);
            }
          }
        }
      }
    }
  }
  $messageArray = [
    'Number of Registrant ' . $rowsImported,
    'Number of personImported ' . $personImported,
    'Number of skipUpdate ' . $skipUpdate,
  ];
  $messageArrayString = implode(', ', $messageArray);
  CRM_Ultracampsync_Utils::log('Ultracampbatchprocess: ' . $messageArrayString);
  CRM_Ultracampsync_Utils::log('Ultracampbatchprocess completed.');
  $returnValues = [$messageArrayString];

  return civicrm_api3_create_success($returnValues, $params, 'Job', 'Ultracampbatchprocess');
}
