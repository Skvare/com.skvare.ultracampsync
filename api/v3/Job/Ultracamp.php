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
  $spec['last_modified_date_from'] = [
    'type' => CRM_Utils_Type::T_STRING,
    'name' => 'last_modified_date_from',
    'title' => 'Last Modified Date From',
    //'api.default' => 'previous.day',
  ];

  $spec['order_date_from'] = [
    'type' => CRM_Utils_Type::T_STRING,
    'name' => 'order_date_from',
    'title' => 'Order Date From',
    //'api.default' => 'previous.day',
  ];

  $spec['session_id'] = [
    'type' => CRM_Utils_Type::T_STRING,
    'name' => 'session_id',
    'title' => 'Session ID',
  ];
  $spec['delete_old'] = [
    'type' => CRM_Utils_Type::T_STRING,
    'name' => 'delete_old',
    'title' => 'Delete old records after (default: -1 year)',
    'api.default' => '-1 year',
    'description' => 'Delete old records from database. Specify 0 to disable. Default is "-1 year"',
  ];
  $spec['use_last_sync_date'] = [
    'type' => CRM_Utils_Type::T_BOOLEAN,
    'name' => 'use_last_sync_date',
    'title' => 'Use Last synd date',
    'api.default' => FALSE,
    'description' => 'Use last sync date to fetch records from UltraCamp',
  ];
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
  // validate the period value.
  $relativeDateLastModifiedDateFrom = explode('.', $params['last_modified_date_from'], 2);
  if (count($relativeDateLastModifiedDateFrom) == 2) {
    // convert relative date to actual date.
    [$dateLastModifiedDateFrom, $to] = CRM_Utils_Date::getFromTo($params['last_modified_date_from'], '', '');
    if (empty($dateLastModifiedDateFrom)) {
      throw new API_Exception('Invalid relative date format', 'last_modified_date_from');
    }
  }
  else {
    $dateLastModifiedDateFrom = CRM_Utils_Array::value('last_modified_date_from', $params);
  }

  $relativeOrderDateFrom = explode('.', $params['order_date_from'], 2);
  if (count($relativeOrderDateFrom) == 2) {
    // convert relative date to actual date.
    [$dateOrderDateFrom, $to] = CRM_Utils_Date::getFromTo($params['order_date_from'], '', '');
    if (empty($dateOrderDateFrom)) {
      throw new API_Exception('Invalid relative date format', 'order_date_from');
    }
  }
  else {
    $dateOrderDateFrom = CRM_Utils_Array::value('order_date_from', $params);
  }

  if (empty($dateLastModifiedDateFrom) && empty($dateOrderDateFrom)) {
    return civicrm_api3_create_error('Last modified date or Order Date From date is required.');
  }
  $dateAvailable = [];
  if (!empty($dateLastModifiedDateFrom)) {
    $dateLastModifiedDateFrom = date('Ymd', strtotime($dateLastModifiedDateFrom));
    $dateAvailable[] = $dateLastModifiedDateFrom;

  }
  if (!empty($dateOrderDateFrom)) {
    $dateOrderDateFrom = date('Ymd', strtotime($dateOrderDateFrom));
    $dateAvailable[] = $dateOrderDateFrom;
  }
  if ($params['use_last_sync_date']) {
    $dateLastModifiedDateFrom = Civi::settings()->get('ultracampsync_last_sync_date');
    if (empty($dateLastModifiedDateFrom) && !empty($dateLastModifiedDateFrom)) {
      return civicrm_api3_create_error('Last sync date is not set.');
    }
    $dateLastModifiedDateFrom = date('Ymd', strtotime($dateLastModifiedDateFrom));
  }

  $currentDate = date('Ymd');

  $ultracampsyncLastSyncDate = Civi::settings()->get('ultracampsync_last_sync_date');

  // Check if API credentials are configured
  $campId = Civi::settings()->get('ultracampsync_camp_id');
  $campApiKey = Civi::settings()->get('ultracampsync_camp_api_key');

  if (empty($campId) || empty($campApiKey)) {
    return civicrm_api3_create_error('UltraCamp API credentials not configured.');
  }

  if ($params['delete_old'] !== 0 && !empty($params['delete_old'])) {
    // Delete all locally recorded ultracamp that are older than 1 year
    $oldUltraCampCount = \Civi\Api4\Ultracamp::get(FALSE)
      ->selectRowCount()
      ->addWhere('order_date', '<', $params['delete_old'])
      ->execute()
      ->count();
    if (!empty($oldUltraCampCount)) {
      \Civi\Api4\Ultracamp::delete(FALSE)
        ->addWhere('order_date', '<', $params['delete_old'])
        ->execute();
    }
  }

  // Initialize UltraCamp client
  try {
    $client = new CRM_UltracampSync_Client();
    if (!empty($dateLastModifiedDateFrom)) {
      $params['lastModifiedDateFrom'] = $dateLastModifiedDateFrom;
    }
    if (!empty($dateOrderDateFrom)) {
      $params['orderDateFrom'] = $dateOrderDateFrom;
    }
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
      $values = [];
      $values['account_id'] = $value['AccountId'];
      $values['reservation_id'] = $value['ReservationId'];
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
    Civi::settings()->set('ultracampsync_last_sync_date', $currentDate);
    CRM_Core_Error::debug_log_message('Ultracamp Updated ultracampsync_last_sync_date to ' . $currentDate);

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
  $query = "INSERT INTO civicrm_ultracamp (account_id,person_id,session_id,session_name,order_date,status,data,reservation_id)
    VALUES (%1, %2, %3, %4, %5, %6, %7, %8)";
  $inputValueTypes = [
    1 => [$params['account_id'], 'String'],
    2 => [$params['person_id'] ?? '', 'String'],
    3 => [$params['session_id'] ?? '', 'String'],
    4 => [$params['session_name'] ?? '', 'String'],
    5 => [$params['order_date'] ?? '', 'String'],
    6 => ['new', 'String'],
    7 => [$params['data'] ?? '', 'String'],
    8 => [$params['reservation_id'] ?? '', 'String'],
  ];
  try {
    CRM_Core_DAO::executeQuery($query, $inputValueTypes);
  }
  catch (CiviCRM_API3_Exception $exception) {
    CRM_Core_Error::debug_var('Error _insert_to_ultracamp_table', $exception->getMessage());
  }
}
