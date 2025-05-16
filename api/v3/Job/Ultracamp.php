<?php
use CRM_Ultracampsync_ExtensionUtil as E;

/**
 * Job.Ultracamp API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_job_Ultracamp_spec(&$spec) {
}

/**
 * Job.Ultracamp API
 * Run the synchronization process for UltraCamp sessions to CiviCRM events.
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
function civicrm_api3_job_Ultracamp($params) {
  // Check if API credentials are configured
  $campId = Civi::settings()->get('ultracampsync_camp_id');
  $campApiKey = Civi::settings()->get('ultracampsync_camp_api_key');

  if (empty($campId) || empty($campApiKey)) {
    return civicrm_api3_create_error('UltraCamp API credentials not configured.');
  }

  // Initialize UltraCamp client
  try {
    $client = new CRM_UltracampSync_API_UltracampClient();
    $params['lastModifiedDateFrom'] = '20250101';

    $result = $client->getReservationDetails($params);
    $i = 0;
    $rowsImported = 0;
    $totalRows = count($result);
    foreach ($result as $value) {
      // reset count at 1000
      if ($i == 100) {
        $i = 0;
        CRM_Core_Error::debug_var('Ultracamp Rows imported: ', $rowsImported . ' / ' . $datasource->maxRows);
      }
      $i++;
      $rowsImported++;
      // CRM_Core_Error::debug_var('$value', $value);
      $values = [];
      $values['account_id'] = $value['AccountId'];
      $values['person_id'] = $value['PersonId'];
      $values['session_id'] = $value['SessionId'];
      $values['session_name'] = $value['SessionName'];
      $values['order_date'] = date("YmdHis", strtotime($value['OrderDate']));
      $values['data'] = json_encode($value);
      _insert_to_ultracamp_table($values);
    }
    // Set current date as last import date.
    $returnValues = "Ultracamp Update details from " . $params['lastModifiedDateFrom'];
    $returnValues .= '<br/>Imported ' . $totalRows . ' number of rows';
    CRM_Core_Error::debug_log_message($returnValues);
    CRM_Core_Error::debug_log_message('Ultracamp import Completed');

    return civicrm_api3_create_success($returnValues);
  }
  catch (Exception $e) {
    return civicrm_api3_create_error('Sync process failed: ' . $e->getMessage());
  }
}

/**
 * Function to add entry into table.
 *
 * @param array $params
 *   Contact Details.
 */
function _insert_to_ultracamp_table(array $params = []) {
  $query = "INSERT INTO civicrm_ultracamp (account_id,person_id,session_id,session_name,order_date,status,data)
    VALUES (%1, %2, %3, %4, %5, %6, %7)";
  $inputValueTypes = [
    1 => [$params['account_id'], 'String'],
    2 => [$params['person_id'] ?? '', 'String'],
    3 => [$params['session_id'] ?? '', 'String'],
    4 => [$params['session_name'] ?? '', 'String'],
    5 => [$params['order_date'] ?? '', 'String'],
    6 => ['new', 'String'],
    7 => [$params['data'] ?? '', 'String'],
  ];
  try {
    CRM_Core_DAO::executeQuery($query, $inputValueTypes);
  }
  catch (CiviCRM_API3_Exception $exception) {
    CRM_Core_Error::debug_var('Error _insert_to_gambit_table', $exception->getMessage());
  }
}
