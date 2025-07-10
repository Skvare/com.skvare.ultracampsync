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
  ];

  $spec['order_date_from'] = [
    'type' => CRM_Utils_Type::T_STRING,
    'name' => 'order_date_from',
    'title' => 'Order Date From',
  ];

  $spec['order_date_to'] = [
    'type' => CRM_Utils_Type::T_STRING,
    'name' => 'order_date_to',
    'title' => 'Order Date To',
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
    'title' => 'Use Last sync date',
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
 * @return array API result descriptor
 *
 * @see civicrm_api3_create_success
 * @throws CRM_Core_Exception
 */
function civicrm_api3_job_Ultracamp($params) {
  try {
    // Validate API credentials first
    _validateUltracampCredentials();

    // Process and validate date parameters
    $dateParams = _processDateParameters($params);

    // Clean up old records if requested
    _deleteOldRecords($params['delete_old'] ?? '-1 year');

    // Sync data from UltraCamp
    $syncResult = _syncUltracampData($dateParams);

    // Update last sync date
    _updateLastSyncDate();

    return civicrm_api3_create_success(_formatSuccessMessage($syncResult, $dateParams));

  }
  catch (Exception $e) {
    CRM_Ultracampsync_Utils::log('Ultracamp sync failed: ' . $e->getMessage());
    return civicrm_api3_create_error('Sync process failed: ' . $e->getMessage());
  }
}

/**
 * Validate that UltraCamp API credentials are configured.
 *
 * @throws CRM_Core_Exception
 */
function _validateUltracampCredentials() {
  $campId = Civi::settings()->get('ultracampsync_camp_id');
  $campApiKey = Civi::settings()->get('ultracampsync_camp_api_key');

  if (empty($campId) || empty($campApiKey)) {
    throw new CRM_Core_Exception('UltraCamp API credentials not configured.');
  }
}

/**
 * Process and validate date parameters.
 *
 * @param array $params Input parameters
 *
 * @return array Processed date parameters
 * @throws CRM_Core_Exception
 */
function _processDateParameters($params) {
  $dateParams = [];

  // Process last modified date
  if (!empty($params['last_modified_date_from'])) {
    $dateParams['lastModifiedDateFrom'] = _processDateInput($params['last_modified_date_from'], 'last_modified_date_from');
  }

  // Process order date
  if (!empty($params['order_date_from'])) {
    $dateParams['orderDateFrom'] = _processDateInput($params['order_date_from'], 'order_date_from');
  }

  if (!empty($params['order_date_to'])) {
    $dateParams['orderDateTo'] = _processDateInput($params['order_date_to'], 'order_date_to');
  }

  // Use last sync date if requested
  if (!empty($params['use_last_sync_date'])) {
    $lastSyncDate = Civi::settings()->get('ultracampsync_last_sync_date');
    if (empty($lastSyncDate)) {
      throw new CRM_Core_Exception('Last sync date is not set.');
    }
    $dateParams['orderDateFrom'] = _formatDateForApi($lastSyncDate);
  }

  // Validate that at least one date parameter is provided
  if (empty($dateParams['lastModifiedDateFrom']) && empty($dateParams['orderDateFrom'])) {
    throw new CRM_Core_Exception('Last modified date or Order Date From date is required.');
  }

  return $dateParams;
}

/**
 * Process a single date input (relative or absolute).
 *
 * @param string $dateInput The date input to process
 * @param string $fieldName Field name for error reporting
 *
 * @return string Formatted date (Ymd format)
 * @throws CRM_Core_Exception
 */
function _processDateInput($dateInput, $fieldName) {
  // Check if it's a relative date (contains a dot)
  if (strpos($dateInput, '.') !== FALSE) {
    return _processRelativeDate($dateInput, $fieldName);
  }

  // Process as absolute date
  return _formatDateForApi($dateInput);
}

/**
 * Process relative date format.
 *
 * @param string $relativeDate Relative date string
 * @param string $fieldName Field name for error reporting
 *
 * @return string Formatted date (Ymd format)
 * @throws CRM_Core_Exception
 */
function _processRelativeDate($relativeDate, $fieldName) {
  [$fromDate, $toDate] = CRM_Utils_Date::getFromTo($relativeDate, '', '');

  if (empty($fromDate)) {
    throw new CRM_Core_Exception("Invalid relative date format for {$fieldName}");
  }

  return _formatDateForApi($fromDate);
}

/**
 * Format date for API consumption (Ymd format).
 *
 * @param string $dateString Date string to format
 *
 * @return string Formatted date (Ymd format)
 * @throws CRM_Core_Exception
 */
function _formatDateForApi($dateString) {
  $timestamp = strtotime($dateString);

  if ($timestamp === FALSE) {
    throw new CRM_Core_Exception("Invalid date format: {$dateString}");
  }

  return date('Ymd', $timestamp);
}

/**
 * Delete old records from the database.
 *
 * @param string|int $deleteOld Delete threshold (e.g., '-1 year') or 0 to disable
 */
function _deleteOldRecords($deleteOld) {
  if ($deleteOld === 0 || $deleteOld === '0') {
    return;
  }

  try {
    $oldRecordCount = \Civi\Api4\Ultracamp::get(FALSE)
      ->selectRowCount()
      ->addWhere('order_date', '<', $deleteOld)
      ->execute()
      ->count();

    if ($oldRecordCount > 0) {
      \Civi\Api4\Ultracamp::delete(FALSE)
        ->addWhere('order_date', '<', $deleteOld)
        ->execute();

      CRM_Ultracampsync_Utils::log("Deleted {$oldRecordCount} old Ultracamp records");
    }
  }
  catch (Exception $e) {
    CRM_Ultracampsync_Utils::log('Failed to delete old records: ' . $e->getMessage());
  }
}

/**
 * Sync data from UltraCamp API.
 *
 * @param array $dateParams Processed date parameters
 *
 * @return array Sync result information
 * @throws Exception
 */
function _syncUltracampData($dateParams) {
  $client = new CRM_Ultracampsync_API_UltracampClient();
  $reservations = $client->getReservationDetails($dateParams);

  $totalRows = count($reservations);
  $rowsImported = 0;
  $progressCounter = 0;

  foreach ($reservations as $reservation) {
    // Log progress every 100 records
    if (++$progressCounter >= 100) {
      CRM_Ultracampsync_Utils::log("Ultracamp import progress: {$rowsImported} / {$totalRows}");
      $progressCounter = 0;
    }

    _insertReservationRecord($reservation);
    $rowsImported++;
  }

  return [
    'total_rows' => $totalRows,
    'rows_imported' => $rowsImported,
  ];
}

/**
 * Insert a single reservation record into the database.
 *
 * @param array $reservation Reservation data from UltraCamp
 */
function _insertReservationRecord($reservation) {
  $values = [
    'account_id' => $reservation['AccountId'] ?? '',
    'reservation_id' => $reservation['ReservationId'] ?? '',
    'person_id' => $reservation['PersonId'] ?? '',
    'session_id' => $reservation['SessionId'] ?? '',
    'session_name' => $reservation['SessionName'] ?? '',
    'order_date' => _formatOrderDate($reservation['OrderDate'] ?? ''),
    'data' => json_encode($reservation),
  ];

  _insertToUltracampTable($values);
}

/**
 * Format order date for database storage.
 *
 * @param string $orderDate Order date from API
 *
 * @return string Formatted date (YmdHis format)
 */
function _formatOrderDate($orderDate) {
  if (empty($orderDate)) {
    return '';
  }

  $timestamp = strtotime($orderDate);
  return $timestamp !== FALSE ? date('YmdHis', $timestamp) : '';
}

/**
 * Update the last sync date setting.
 */
function _updateLastSyncDate() {
  $currentDate = date('Ymd');
  Civi::settings()->set('ultracampsync_last_sync_date', $currentDate);
  CRM_Ultracampsync_Utils::log("Updated ultracampsync_last_sync_date to {$currentDate}");
}

/**
 * Format success message for API response.
 *
 * @param array $syncResult Sync result data
 * @param array $dateParams Date parameters used
 *
 * @return string Formatted success message
 */
function _formatSuccessMessage($syncResult, $dateParams) {
  $message = "Ultracamp sync completed successfully.\n";
  $message .= "Parameters used: " . json_encode($dateParams, JSON_PRETTY_PRINT) . "\n";
  $message .= "Imported {$syncResult['rows_imported']} out of {$syncResult['total_rows']} records.\n";
  $message .= "Last sync date updated to: " . date('Y-m-d');

  CRM_Ultracampsync_Utils::log($message);
  return $message;
}

/**
 * Insert record into the ultracamp table.
 *
 * @param array $params Record data to insert
 */
function _insertToUltracampTable(array $params = []) {
  $query = "INSERT INTO civicrm_ultracamp
            (account_id, person_id, session_id, session_name, order_date, status, data, reservation_id)
            VALUES (%1, %2, %3, %4, %5, %6, %7, %8)";

  $queryParams = [
    1 => [$params['account_id'], 'String'],
    2 => [$params['person_id'], 'String'],
    3 => [$params['session_id'], 'String'],
    4 => [$params['session_name'], 'String'],
    5 => [$params['order_date'], 'String'],
    6 => ['new', 'String'],
    7 => [$params['data'], 'String'],
    8 => [$params['reservation_id'], 'String'],
  ];

  try {
    CRM_Core_DAO::executeQuery($query, $queryParams);
  }
  catch (Exception $e) {
    CRM_Ultracampsync_Utils::log('Error inserting Ultracamp record: ' . $e->getMessage());
    throw $e;
  }
}
