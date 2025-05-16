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

  $selectQuery = "select * from civicrm_ultracamp where status = 'new' limit 100";
  $selectDAO = CRM_Core_DAO::executeQuery($selectQuery);
  while ($selectDAO->fetch()) {
    $values = $selectDAO->toArray();
    $values = array_merge($values, json_decode($values['data'], TRUE));
    $eventId = $eventSessionList[$values['session_id']];
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
  $message = "Number of Records processed - {$rowsImported}, skipUpdate = {$skipUpdate}";
  CRM_Core_Error::debug_log_message('Ultracampbatchprocess ' . $message);
  CRM_Core_Error::debug_log_message('Ultracampbatchprocess completed.');
  $returnValues = [$message];

  return civicrm_api3_create_success($returnValues, $params, 'Job', 'Ultracampbatchprocess');
}
