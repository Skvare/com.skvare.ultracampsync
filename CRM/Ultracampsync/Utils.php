<?php

use CRM_Ultracampsync_ExtensionUtil as E;

/**
 * Enhanced Utils class with performance optimizations,
 * better caching, and improved error handling
 */
class CRM_Ultracampsync_Utils {

  private static $country;
  private static $state;
  private static $contactCache = [];
  private static $householdCache = [];
  private static $relationshipCache = [];
  private static $cacheExpiry = 3600; // 1 hour

  /**
   * Enhanced contact handling with caching and bulk operations
   *
   * @param array $contactParams
   * @return mixed|null Contact ID
   * @throws CRM_Core_Exception
   */
  public static function handleContact($contactParams = []) {
    $cacheKey = self::generateContactCacheKey($contactParams);

    // Check cache first
    if (isset(self::$contactCache[$cacheKey])) {
      self::logExtra("Using cached contact for key: {$cacheKey}");
      return self::$contactCache[$cacheKey];
    }
    if (!empty($contactParams['contact_id'])) {
      //return $contactParams['contact_id'];
    }

    $personId = $contactParams['PersonId'] ?? NULL;
    $contactId = NULL;

    try {
      // Try to find by Person ID first (most reliable)
      if (!empty($personId) && !empty($contactParams['person_id_field'])) {
        $contactId = self::findContactByPersonId($personId, $contactParams['person_id_field']);
      }

      // If not found by Person ID, try by name and address
      if (empty($contactId)) {
        $contactId = self::findContactByDetails($contactParams);
      }

      // Create or update contact
      $contactId = self::createOrUpdateContact($contactId, $contactParams);

      // Cache the result
      if (!empty($contactId)) {
        self::$contactCache[$cacheKey] = $contactId;
      }

      return $contactId;

    }
    catch (Exception $e) {
      self::log('Error in handleContact: ' . $e->getMessage(), 'error');
      throw $e;
    }
  }

  /**
   * Generate cache key for contact
   *
   * @param array $contactParams
   * @return string
   */
  protected static function generateContactCacheKey($contactParams) {
    $keyElements = [
      $contactParams['PersonId'] ?? '',
      $contactParams['FirstName'] ?? '',
      $contactParams['LastName'] ?? '',
      $contactParams['Email'] ?? ''
    ];

    return 'contact_' . md5(implode('|', $keyElements));
  }

  /**
   * Find contact by Person ID using optimized query
   *
   * @param string $personId
   * @param string $personIdField
   * @return int|null
   */
  protected static function findContactByPersonId($personId, $personIdField) {
    $contactId = NULL;
    $getParams = [
      'sequential' => 1,
      'custom_' . $personIdField => $personId,
      'contact_type' => 'Individual',
    ];
    $contactResult = civicrm_api3('Contact', 'get', $getParams);
    if ($contactResult['id']) {
      $contactId = $contactResult['id'];
    }
    if ($contactId) {
      self::logExtra("Found contact by Person ID {$personId}: {$contactId}");
    }

    return $contactId;
  }

  /**
   * Find contact by name and address details
   *
   * @param array $contactParams
   * @return int|null
   */
  protected static function findContactByDetails($contactParams) {
    if (empty($contactParams['FirstName']) || empty($contactParams['LastName'])) {
      return NULL;
    }
    $contactId = NULL;

    $getParams = [
      'sequential' => 1,
      'first_name' => $contactParams['FirstName'],
      'last_name' => $contactParams['LastName'],
      'contact_type' => "Individual",
      'options' => ['limit' => 1],
    ];
    if (!empty($contactParams['Email'])) {
      $getParams['email'] = $contactParams['Email'];
    }
    if (!empty($contactParams['Address']) && !empty($contactParams['City'])) {
      $getParams['street_address'] = $contactParams['Address'];
      $getParams['city'] = $contactParams['City'];
      $getParams['postal_code'] = $contactParams['ZipCode'];
      $getParams['state_province_id'] = $contactParams['StateID'];
      $getParams['country_id'] = $contactParams['CountryID'];
    }
    $contactResult = civicrm_api3('Contact', 'get', $getParams);
    if (!empty($contactResult['values']) && !empty($contactResult['id'])) {
      CRM_Ultracampsync_Utils::logExtra('Contact found by name: ' . $contactParams['FirstName'] . ' ' . $contactParams['LastName'] . ', contact ID: ' . $contactResult['id']);
      $contactId = $contactResult['id'];
    }
    return $contactId;
  }

  /**
   * Create or update contact with optimized parameter handling
   *
   * @param int|null $contactId
   * @param array $contactParams
   * @return int|null
   */
  protected static function createOrUpdateContact($contactId, $contactParams) {
    $contactData = [
      'contact_type' => 'Individual',
      'first_name' => $contactParams['FirstName'] ?? '',
      'last_name' => $contactParams['LastName'] ?? '',
    ];

    if (!empty($contactId)) {
      $contactData['id'] = $contactId;
    }

    // Add optional fields if available
    $optionalFields = [
      'nick_name' => 'NickName',
      'middle_name' => 'MiddleName',
      'birth_date' => 'BirthDate',
      'gender_id' => 'Gender'
    ];

    foreach ($optionalFields as $civiField => $ultraField) {
      if (!empty($contactParams[$ultraField])) {
        $contactData[$civiField] = $contactParams[$ultraField];
      }
    }

    // Add custom field data
    $customFields = [
      'person_id_field' => 'PersonId',
      'account_id_field' => 'AccountId',
      'primary_contact_field' => 'PrimaryContact'
    ];

    foreach ($customFields as $fieldKey => $ultraField) {
      if (!empty($contactParams[$fieldKey]) && !empty($contactParams[$ultraField])) {
        $fieldId = $contactParams[$fieldKey];
        $contactData["custom_{$fieldId}"] = $contactParams[$ultraField];
      }
    }
    try {
      $result = civicrm_api3('Contact', 'create', $contactData);

      if (!empty($result['id'])) {
        $action = empty($contactId) ? 'created' : 'updated';
        self::logExtra("Contact {$action} with ID: {$result['id']}");
        return $result['id'];
      }

    }
    catch (CRM_Core_Exception $e) {
      self::log('Error creating/updating contact: ' . $e->getMessage(), 'error');
      throw $e;
    }

    return NULL;
  }

  /**
   * Enhanced household contact handling with caching
   *
   * @param array $contactParams
   * @param int|null $cfAccountId
   * @return mixed|null
   * @throws CRM_Core_Exception
   */
  public static function handleHouseHoldContact(array $contactParams = [], int $cfAccountId = NULL): mixed {
    $cacheKey = 'household_' . ($contactParams['AccountId'] ?? md5(serialize($contactParams)));

    // Check cache
    if (isset(self::$householdCache[$cacheKey])) {
      self::logExtra("Using cached household for key: {$cacheKey}");
      return self::$householdCache[$cacheKey];
    }

    $contactId = NULL;

    try {
      // Try to find by Account ID first
      if (!empty($cfAccountId) && !empty($contactParams['AccountId'])) {
        $contactId = self::findHouseholdByAccountId($contactParams['AccountId'], $cfAccountId);
      }

      // If not found, try by name and address
      if (empty($contactId)) {
        $contactId = self::findHouseholdByDetails($contactParams);
      }

      // Create or update household
      $contactId = self::createOrUpdateHousehold($contactId, $contactParams, $cfAccountId);

      // Cache the result
      if (!empty($contactId)) {
        self::$householdCache[$cacheKey] = $contactId;
      }

      return $contactId;

    }
    catch (Exception $e) {
      self::log('Error in handleHouseHoldContact: ' . $e->getMessage(), 'error');
      throw $e;
    }
  }

  /**
   * Find household by Account ID
   *
   * @param string $accountId
   * @param int $cfAccountId
   * @return int|null
   */
  protected static function findHouseholdByAccountId($accountId, $cfAccountId) {
    if (!empty($cfAccountId) && !empty($accountId)) {
      $getParams = [
        'sequential' => 1,
        'return' => ["id"],
        'contact_type' => 'Household',
        'custom_' . $cfAccountId => $accountId,
      ];
      CRM_Ultracampsync_Utils::logExtra('Getting household contact by custom field: custom_' . $cfAccountId . ' with value: ' . $accountId);
      $contactResult = civicrm_api3('Contact', 'get', $getParams);
      if ($contactResult['id']) {
        CRM_Ultracampsync_Utils::logExtra('Household contact found by custom field: custom_' . $cfAccountId . ' with value: ' . $accountId . ', contact ID: ' . $contactResult['id']);
        return $contactResult['id'];
      }
    }
    return NULL;
  }

  /**
   * Find household by name and address
   *
   * @param array $contactParams
   * @return int|null
   */
  protected static function findHouseholdByDetails($contactParams) {
    if (empty($contactParams['AccountName'])) {
      return NULL;
    }
    $contactId = NULL;
    $getParams = [
      'sequential' => 1,
      'household_name' => $contactParams['AccountName'],
      'contact_type' => "Household",
      'options' => ['limit' => 1],
    ];
    if (!empty($contactParams['Address']) && !empty($contactParams['City'])) {
      $getParams['street_address'] = $contactParams['Address'];
      $getParams['city'] = $contactParams['City'];
      $getParams['postal_code'] = $contactParams['ZipCode'];
      $getParams['state_province_id'] = $contactParams['StateID'];
      $getParams['country_id'] = $contactParams['CountryID'];
    }
    CRM_Ultracampsync_Utils::logExtra('Getting household contact by name: ' . $contactParams['AccountName'] . ', address: ' . $contactParams['Address'] . ', city: ' . $contactParams['City']);
    $contactResult = civicrm_api3('Contact', 'get', $getParams);
    if (!empty($contactResult['values']) && !empty($contactResult['id'])) {
      CRM_Ultracampsync_Utils::logExtra('Household contact found by name: ' . $contactParams['AccountName'] . ', contact ID: ' . $contactResult['id']);
      $contactId = $contactResult['id'];
    }
    return $contactId;
  }

  /**
   * Create or update household contact
   *
   * @param int|null $contactId
   * @param array $contactParams
   * @param int|null $cfAccountId
   * @return int|null
   */
  protected static function createOrUpdateHousehold($contactId, $contactParams, $cfAccountId) {
    $householdData = [
      'contact_type' => 'Household',
      'household_name' => $contactParams['AccountName'] ?? ''
    ];

    if (!empty($contactId)) {
      $householdData['id'] = $contactId;
    }

    // Add custom field for Account ID
    if (!empty($cfAccountId) && !empty($contactParams['AccountId'])) {
      $householdData["custom_{$cfAccountId}"] = $contactParams['AccountId'];
    }
    try {
      $result = civicrm_api3('Contact', 'create', $householdData);

      if (!empty($result['id'])) {
        $action = empty($contactId) ? 'created' : 'updated';
        self::logExtra("Household {$action} with ID: {$result['id']}");
        return $result['id'];
      }

    }
    catch (CRM_Core_Exception $e) {
      self::log('Error creating/updating household: ' . $e->getMessage(), 'error');
      throw $e;
    }

    return NULL;
  }

  /**
   * Handle contact address.
   *
   * @param array $addressParams
   * @return mixed|void
   * @throws CRM_Core_Exception
   */
  public static function handleAddress(array $addressParams = []) {
    $id = $addressParams['contact_id'];
    $address_id = $id;
    $address_params = ['version' => 3, 'contact_id' => $addressParams['contact_id'], 'is_primary' => '1'];
    $existing_address = civicrm_api3('Address', 'get', $address_params);
    if ($existing_address['id']) {
      $idtype = 'id';
      $address_id = $existing_address['id'];
      CRM_Ultracampsync_Utils::logExtra('Address found by contact ID: ' . $id . ', address ID: ' . $address_id);
      return $existing_address['id'];
    }
    else {
      $idtype = 'contact_id';
    }
    try {
      $address_params = [
        'version' => '3',
        $idtype => $address_id,
        'location_type_id' => '3', // Main address id
        'is_primary' => '1',
        'street_address' => $addressParams['PersonAddress'],
        'city' => $addressParams['PersonCity'],
        'country_id' => $addressParams['PersonCountryID'],
        'state_province_id' => $addressParams['PersonStateID'],
        'postal_code' => $addressParams['PersonZip'],
      ];
      if (!empty($addressParams['master_id'])) {
        $address_params['master_id'] = $addressParams['master_id'];
      }
      CRM_Ultracampsync_Utils::logExtra('Creating/updating address with params: ' . print_r($address_params, TRUE));
      $civi_address = civicrm_api3('Address', 'create', $address_params);
      if ($civi_address['id']) {
        CRM_Ultracampsync_Utils::logExtra('Address created/updated with ID: ' . $civi_address['id']);
        return $address_id;
      }
    }
    catch (CRM_Core_Exception $e) {
      CRM_Ultracampsync_Utils::log('Error creating/updating address: ' . $e->getMessage());
    }
  }


  /**
   * Handle contact phone.
   *
   * @param array $phoneParams
   * @return void
   * @throws CRM_Core_Exception
   */
  public static function handlePhone(array $phoneParams = []): void {
    $id = $phoneParams['contact_id'];
    if (empty($phoneParams['PrimaryPhoneNumber'])) {
      return;
    }
    $phone_id = $id;
    $phone_params = ['version' => 3, 'contact_id' => $phoneParams['contact_id'],
      'is_primary' => '1'];
    $existing_phone = civicrm_api3('Phone', 'get', $phone_params);
    if ($existing_phone['id']) {
      $idtype = 'id';
      $phone_id = $existing_phone['id'];
      CRM_Ultracampsync_Utils::logExtra('Phone found by contact ID: ' . $id . ', phone ID: ' . $phone_id);
    }
    else {
      $idtype = 'contact_id';
    }
    // Check  phone type
    if ($phoneParams['PrimaryPhoneType'] == 2) {
      $phoneType = 7;
      $locationType = 9;  // Cell Phone
    }
    elseif ($phoneParams['PrimaryPhoneType'] == 1) {
      $phoneType = 'Work Phone';
      $locationType = 2; // Day Phone
    }
    else {
      $phoneType = 6; // Home Phone
      $locationType = 1;
    }
    try {
      $phone_params = [
        'version' => '3',
        $idtype => $phone_id,
        'location_type_id' => $locationType, // Main
        'is_primary' => '1',
        'phone_type_id' => $phoneType,
        'phone' => $phoneParams['PrimaryPhoneNumber'],
      ];
      CRM_Ultracampsync_Utils::logExtra('Creating/updating phone with params: ' . print_r($phone_params, TRUE));
      civicrm_api3('Phone', 'create', $phone_params);
    }
    catch (CRM_Core_Exception $e) {
      CRM_Ultracampsync_Utils::log('Error creating/updating phone: ' . $e->getMessage());
    }
  }

  /**
   * Handle contact Email.
   *
   * @param array $emailParams
   * @return void
   * @throws CRM_Core_Exception
   */
  public static function handleEmail(array $emailParams = []): void {
    if (empty($emailParams['Email'])) {
      return;
    }
    $id = $emailParams['contact_id'];
    $email_id = $id;
    $email_params = ['version' => 3, 'contact_id' => $emailParams['contact_id'],
      'is_primary' => '1'];
    $existing_phone = civicrm_api3('Email', 'get', $email_params);
    if ($existing_phone['id']) {
      $idtype = 'id';
      $email_id = $existing_phone['id'];
      CRM_Ultracampsync_Utils::logExtra('Email found by contact ID: ' . $id . ', email ID: ' . $email_id);
    }
    else {
      $idtype = 'contact_id';
    }
    try {
      $email_params = [
        'version' => '3',
        $idtype => $email_id,
        'location_type_id' => '1', // Home
        'is_primary' => '1',
        'email' => $emailParams['Email'],
      ];
      CRM_Ultracampsync_Utils::logExtra('Creating/updating email with params: ' . print_r($email_params, TRUE));
      $civi_email = civicrm_api3('Email', 'create', $email_params);
      if (!empty($civi_email['id'])) {
        CRM_Ultracampsync_Utils::logExtra('Email created/updated with ID: ' . $civi_email['id']);
      }
    }
    catch (CRM_Core_Exception $e) {
      CRM_Ultracampsync_Utils::log('Error creating/updating email: ' . $e->getMessage());
    }
  }

  /**
   * Enhanced relationship handling with duplicate prevention
   *
   * @param int $personContactID
   * @param int $houseHoldContactID
   * @param int $relationshipTypeId
   * @param string $relationshipTypeFromUltraCamp
   * @throws CRM_Core_Exception
   */
  public static function handleRelationship(int    $personContactID,
                                            int    $houseHoldContactID,
                                            int    $relationshipTypeId,
                                            string $relationshipTypeFromUltraCamp): void {

    $cacheKey = "rel_{$personContactID}_{$houseHoldContactID}_{$relationshipTypeId}";

    // Check cache to avoid duplicate processing
    if (isset(self::$relationshipCache[$cacheKey])) {
      self::logExtra("Relationship already processed (cached): {$cacheKey}");
      return;
    }

    try {
      // Check if relationship already exists
      if (self::relationshipExists($personContactID, $houseHoldContactID, $relationshipTypeId)) {
        self::logExtra("Relationship already exists: {$cacheKey}");
        self::$relationshipCache[$cacheKey] = TRUE;
        return;
      }

      // Create new relationship
      $params = [
        'contact_id_a' => $personContactID,
        'contact_id_b' => $houseHoldContactID,
        'relationship_type_id' => $relationshipTypeId,
        'is_active' => 1
      ];

      // Add custom field for relationship type
      $cfRelationship = Civi::settings()->get('ultracampsync_relationship_id_field');
      if (!empty($cfRelationship)) {
        $params["custom_{$cfRelationship}"] = $relationshipTypeFromUltraCamp;
      }

      civicrm_api3('Relationship', 'create', $params);

      self::logExtra("Relationship created: {$cacheKey}");
      self::$relationshipCache[$cacheKey] = TRUE;

    }
    catch (CRM_Core_Exception $e) {
      self::log("Error creating relationship {$cacheKey}: " . $e->getMessage(), 'error');
      throw $e;
    }
  }

  /**
   * Check if relationship already exists
   *
   * @param int $contactIdA
   * @param int $contactIdB
   * @param int $relationshipTypeId
   * @return bool
   */
  protected static function relationshipExists($contactIdA, $contactIdB, $relationshipTypeId) {
    $query = "
      SELECT COUNT(*)
      FROM civicrm_relationship
      WHERE contact_id_a = %1
      AND contact_id_b = %2
      AND relationship_type_id = %3
      AND is_active = 1
    ";

    $params = [
      1 => [$contactIdA, 'Integer'],
      2 => [$contactIdB, 'Integer'],
      3 => [$relationshipTypeId, 'Integer']
    ];

    return CRM_Core_DAO::singleValueQuery($query, $params) > 0;
  }

  /**
   * Enhanced participant handling with better duplicate detection
   *
   * @param array $participantParams
   * @param int $reservation_id_field
   * @return string
   * @throws CRM_Core_Exception
   */
  public static function handleParticipant(array $participantParams, int $reservation_id_field): string {
    try {
      // Enhanced duplicate check
      if (self::participantExists($participantParams, $reservation_id_field)) {
        self::logExtra("Participant already exists for contact {$participantParams['contact_id']}, event {$participantParams['event_id']}");
        return 'exists';
      }

      // Create participant with enhanced parameters
      $params = [
        'contact_id' => $participantParams['contact_id'],
        'event_id' => $participantParams['event_id'],
        'status_id' => 1, // Registered
        'role_id' => 1, // Attendee
        'source' => 'UltraCamp Sync',
        'register_date' => date("YmdHis", strtotime($participantParams['OrderDate'] ?? 'now'))
      ];

      // Add custom field for reservation ID
      if (!empty($reservation_id_field) && !empty($participantParams['ReservationId'])) {
        $params["custom_{$reservation_id_field}"] = $participantParams['ReservationId'];
      }

      $participant = civicrm_api3('Participant', 'create', $params);

      if (!empty($participant['id'])) {
        self::logExtra("Participant created with ID: {$participant['id']}");

        // Update the UltraCamp record with participant ID
        if (!empty($participantParams['id'])) {
          CRM_Core_DAO::setFieldValue(
            'CRM_Ultracampsync_DAO_Ultracamp',
            $participantParams['id'],
            'participant_id',
            $participant['id']
          );
        }

        return 'success';
      }

    }
    catch (CRM_Core_Exception $e) {
      self::log('Error creating participant: ' . $e->getMessage(), 'error');
      return 'error';
    }

    return 'error';
  }

  /**
   * Enhanced participant existence check
   *
   * @param array $participantParams
   * @param int $reservation_id_field
   * @return bool
   */
  protected static function participantExists($participantParams, $reservation_id_field) {
    // Primary check: by contact, event, and reservation ID
    if (!empty($reservation_id_field) && !empty($participantParams['ReservationId'])) {
      $params = [
        'contact_id' => $participantParams['contact_id'],
        'event_id' => $participantParams['event_id'],
        'custom_' . $reservation_id_field => $participantParams['ReservationId'],
      ];
      CRM_Ultracampsync_Utils::logExtra('Checking for existing participant with params: ' . print_r($params, TRUE));
      $resultParticipant = civicrm_api3('Participant', 'get', $params);
      if (!empty($resultParticipant['values'])) {
        CRM_Ultracampsync_Utils::logExtra('Participant already exists for contact ID: ' . $participantParams['contact_id'] . ', event ID: ' . $participantParams['event_id']);
        return TRUE; // Participant already exists, no need to create again.
      }
    }

    // Secondary check: by contact and event only
    $query = "
      SELECT COUNT(*)
      FROM civicrm_participant
      WHERE contact_id = %1
      AND event_id = %2
      AND is_test = 0
    ";

    $params = [
      1 => [$participantParams['contact_id'], 'Integer'],
      2 => [$participantParams['event_id'], 'Integer']
    ];
    $queryTest = CRM_Core_DAO::composeQuery($query, $params);
    CRM_Ultracampsync_Utils::logExtra('Secondary check:Checking for existing participant with query----- ' . print_r($queryTest, TRUE));
    return CRM_Core_DAO::singleValueQuery($query, $params) > 0;
  }

  /**
   * Bulk clear caches
   */
  public static function clearCaches() {
    self::$contactCache = [];
    self::$householdCache = [];
    self::$relationshipCache = [];
    self::logExtra('All utility caches cleared');
  }

  /**
   * Get cache statistics
   *
   * @return array
   */
  public static function getCacheStats() {
    return [
      'contact_cache_size' => count(self::$contactCache),
      'household_cache_size' => count(self::$householdCache),
      'relationship_cache_size' => count(self::$relationshipCache),
      'total_cached_items' => count(self::$contactCache) + count(self::$householdCache) + count(self::$relationshipCache)
    ];
  }

  /**
   * Get the custom field ID for Ultracamp Session ID
   *
   * @return string|null
   * @throws CRM_Core_Exception
   */
  public static function getUltracampSessionIdCustomGroup(): ?string {
    $result = civicrm_api3('CustomGroup', 'get', [
      'sequential' => 1,
      'name' => 'ultracamp_session_data',
    ]);

    if ($result['count'] > 0) {
      return $result['values'][0]['id'];
    }

    return NULL;
  }

  /**
   * Get the custom field ID for Ultracamp Session ID
   *
   * @return string
   */
  public static function getUltracampSessionIdCustomField($returnID = FALSE): ?string {
    $result = civicrm_api3('CustomField', 'get', [
      'sequential' => 1,
      'custom_group_id' => 'ultracamp_session_data',
      'name' => 'ultracamp_session_id',
    ]);

    if ($result['count'] > 0) {
      if ($returnID) {
        return $result['values'][0]['id'];
      }
      return 'custom_' . $result['values'][0]['id'];
    }

    return NULL;
  }

  /**
   * Get the custom field ID for Last Sync Date
   *
   * @return string
   */
  public static function getLastSyncCustomField(): ?string {
    $result = civicrm_api3('CustomField', 'get', [
      'sequential' => 1,
      'custom_group_id' => 'ultracamp_session_data',
      'name' => 'ultracamp_last_sync',
    ]);

    if ($result['count'] > 0) {
      return 'custom_' . $result['values'][0]['id'];
    }

    return NULL;
  }

  /**
   * Get CiviCRM event by Ultracamp session ID
   *
   * @param int $sessionId
   * @return array|null
   */
  public static function getEventBySessionId(int $sessionId): ?array {
    $sessionIdField = self::getUltracampSessionIdCustomField();

    if (!$sessionIdField) {
      return NULL;
    }

    try {
      $result = civicrm_api3('Event', 'get', [
        'sequential' => 1,
        $sessionIdField => $sessionId,
        'options' => ['limit' => 1],
      ]);

      if ($result['count'] > 0) {
        return $result['values'][0];
      }
    }
    catch (Exception $e) {
      CRM_Ultracampsync_Utils::log('Error finding event by session ID: ' . $e->getMessage());
    }

    return NULL;
  }

  /**
   * Get all events with Ultracamp session ID
   * @return array
   */
  public static function getEventWithSessionId(): array {
    $events = [];
    $cfSessionId = Civi::settings()->get('ultracampsync_session_id_field');
    try {
      $result = civicrm_api3('Event', 'get', [
        'sequential' => 1,
        'return' => ["id", "custom_" . $cfSessionId],
        'options' => ['limit' => 0],
      ]);

      if (count($result['values']) > 0) {
        foreach ($result['values'] as $value) {
          if (array_key_exists('custom_' . $cfSessionId, $value)) {
            $sessionId = $value['custom_' . $cfSessionId];
            if (!empty($sessionId)) {
              $events[$sessionId] = $value['id'];
            }
          }
        }

      }
    }
    catch (Exception $e) {
      CRM_Ultracampsync_Utils::log('Error finding event by session ID: ' . $e->getMessage());
    }
    return $events;
  }

  /**
   * Update last sync timestamp
   */
  public static function updateLastSyncTimestamp(): string {
    $now = date('Y-m-d H:i:s');
    Civi::settings()->set('ultracampsync_last_sync', $now);
    return $now;
  }

  /**
   * Get number of events mapped to Ultracamp sessions
   *
   * @return int
   */
  public static function getMappedEventsCount(): int {
    $sessionIdField = self::getUltracampSessionIdCustomField();

    if (!$sessionIdField) {
      return 0;
    }

    try {
      $result = civicrm_api3('Event', 'getcount', [
        $sessionIdField => ['IS NOT NULL' => 1],
      ]);

      return $result;
    }
    catch (Exception $e) {
      CRM_Ultracampsync_Utils::log('Error counting mapped events: ' . $e->getMessage());
      return 0;
    }
  }

  /**
   * Log message to CiviCRM log
   *
   * @param string $message
   * @param string $level
   * @param array $context Additional context data
   */
  public static function log(string $message, string $level = 'info', array $context = []) {
    $logger = Civi::log();
    $logMessage = '[UltracampSync] ' . $message;

    if (!empty($context)) {
      $logMessage .= ' Context: ' . json_encode($context);
    }

    $logger->log($level, $logMessage);
  }

  /**
   * Enhanced logging with structured data
   *
   * @param string $message
   * @param string $level
   * @param array $context
   */
  public static function logExtra(string $message, string $level = 'info', array $context = []) {
    if (Civi::settings()->get('ultracampsync_debug_enable')) {
      self::log($message, $level, $context);
    }
  }

  public static function country() {
    $result = civicrm_api3('address', 'getoptions', ['field' => 'country_id']);
    $country = array_flip($result['values']);
    $country = array_change_key_case($country, CASE_LOWER);
    // alternative country names (actual name in english -> alternative names).
    $countryMatchingNames = [
      '1228' => ['United States', 'US', 'USA', 'United States Of America'],
      '1226' => ['United Kingdom', 'Great Britain', 'England', 'Scotland', 'Wales', 'Northern Ireland', 'Royaume-Uni'],
      '1246' => ['Isle of Man', 'British Isles'],
      '1070' => ['Ethiopia', 'Ethiopia Africa'],
      '1217' => ['Trinidad and Tobago', 'West Indies', 'Trinidad'],
      '1083' => ['Ghana', 'Ghana West Africa'],
      '1210' => ['Tanzania, United Republic of', 'Tanzania Africa', 'Tanzania'],
      '1112' => ['Kenya', 'Kenya East Africa'],
      '1225' => ['United Arab Emirates', 'DUBAI (UAE)', 'Dubai', 'UAE'],
      '1115' => ['Korea, Republic of', 'Korea', 'South Korea', 'Republic Of Korea'],
      '1162' => ['Oman', 'Sultanet of Oman'],
      '1177' => ['Russian Federation', 'Russia', 'Tatarstan'],
      '1009' => ['Antigua and Barbuda', 'Antigua'],
      '1041' => ['Cayman Islands', 'Caymen Islands'],
      '1248' => ['Curaçao', 'Curacao'],
      '1032' => ['Brunei Darussalam', 'Brunei'],
      '1051' => ['Congo, Republic of the', 'Congo', 'Zaire'],
      '1103' => ['Iran, Islamic Republic of', 'Iran'],
      '1105' => ['Ireland', 'North Ireland'],
      '1045' => ['China', 'Peoples Republic of China'],
      '1184' => ['Saint Vincent and the Grenadines', 'Saint Vincent'],
      '1200' => ['Sudan', 'Sudan Africa'],
      '1206' => ['Syrian Arab Republic', 'Syrian Arab Republic', 'Syria'],
      '1227' => ['United States Minor Outlying Islands', 'US Minor Outlying Islands'],
    ];
    // Build country code list with lower case letters
    foreach ($countryMatchingNames as $countryCode => $countryAliase) {
      foreach ($countryAliase as $countryAlias) {
        $country[strtolower($countryAlias)] = $countryCode;
      }
    }
    return $country;
  }

  /**
   * Function populate all state
   * @return array
   */
  static function state(): array {
    $query = "SELECT id, name, abbreviation, country_id FROM `civicrm_state_province` ORDER BY `name` ASC";
    $dao = CRM_Core_DAO::executeQuery($query);
    $state = [];
    while ($dao->fetch()) {
      $state[$dao->country_id][strtolower($dao->name)] = $dao->id;
      $state[$dao->country_id][strtolower($dao->abbreviation)] = $dao->id;
    }

    return $state;
  }

  /**
   * Product Variant Status.
   *
   * @return string[]
   */
  public static function recordStatus(): array {
    return [
      'new' => E::ts('New'),
      'error' => E::ts('Error'),
      'success' => E::ts('Success'),
      'processing' => E::ts('Processing'),
    ];
  }

  /**
   * Relationship Type Mapping.
   *
   * @return string[]
   */
  public static function getRelationshipTypeMapping(): array {
    return [
      'Daughter' => '34', // Child of
      'Father/Husband' => '7',
      'Granddaughter' => '39',
      'Grandfather' => '35',
      'Grandmother' => '35',
      'Grandson' => '39',
      'Individual Adult' => '41',
      'Mother/Wife' => '7',
      'Nephew' => '36',
      'Niece' => '36',
      'Non-Family' => '38',
      'Other Extended Family' => '41',
      'Priest' => '41',
      'Religious' => '41',
      'Son' => '34',
    ];
  }

  /**
   * @param $people
   * @return mixed|string
   */
  public static function getRelationshipType($people): mixed {
    $relationshipType = '';
    if (!empty($people['CustomQuestions'])) {
      foreach ($people['CustomQuestions'] as $customQuestion) {
        if ($customQuestion['Name'] == 'Relationship') {
          $relationshipType = $customQuestion['Answer'];
          break;
        }
      }
    }
    if (empty($relationshipType)) {
      $relationshipType = 'Other Extended Family'; // Default relationship type if not found
    }
    return $relationshipType;
  }

  /**
   * Get Household name from people.
   *
   * @param array $peoples
   * @return array
   */
  public static function getHouseHoldName(array $peoples): array {
    $primaryContact = $secondaryContact = $genderWiseContact = $primaryAddress = [];
    $houseHoldName = '';
    foreach ($peoples as $people) {
      if (!empty($people['PrimaryContact'])) {
        $primaryContact = ['first_name' => $people['FirstName'], 'last_name' => $people['LastName'], 'gender' => $people['Gender'], 'peopleID' => $people['Id']];
        $gender = $people['Gender'] ?? 'PrimaryGender';
        $genderWiseContact[$gender] = ['first_name' => $people['FirstName'], 'last_name' => $people['LastName'], 'gender' => $people['Gender']];
        $primaryAddress = $people;
      }
      if (!empty($people['SecondaryContact'])) {
        $secondaryContact = ['first_name' => $people['FirstName'], 'last_name' => $people['LastName'], 'gender' => $people['Gender'], 'peopleID' => $people['Id']];
        $gender = $people['Gender'] ?? 'secondaryGender';
        $genderWiseContact[$gender] = ['first_name' => $people['FirstName'], 'last_name' => $people['LastName'], 'gender' => $people['Gender']];
      }
    }

    /*
     if both primary and secondary contact present then generate householdname
     Household name uses these fields in each Person record:

    PrimaryContact (true/false): get this contact in an account. Should only be 1 per account.
    SecondaryContact (true/false): get this contact in an account. Should only be 1 per account.,
    Gender: get the gender of the PrimaryContact and SecondaryContact

    Create household name: "[MALE LASTNAME], [MALE FNAME] & [FEMALE FNAME] Family"

    If there is no secondary contact, then it is: "[PRIMARY LASTNAME], [PRIMARY FNAME] Family"
    If the gender of Primary or Secondary contact is unknown or they're the same, then household name is: "[PRIMARYCONTACT LASTNAME], [PRIMARYCONTACT FNAME] & [SECONDARYCONTACT FNAME] Family"
    */
    if (!empty($primaryContact) && !empty($secondaryContact) &&
      array_key_exists('Female', $genderWiseContact) && array_key_exists('Male', $genderWiseContact)) {
      $houseHoldName = $genderWiseContact['Male']['last_name'] . ' ' .
        $genderWiseContact['Male']['first_name'] . ' & ' .
        $genderWiseContact['Female']['first_name'] . ' Family';
    }
    elseif (!empty($primaryContact) && !empty($secondaryContact) && count($genderWiseContact) == 1) {
      $houseHoldName = $primaryContact['last_name'] . ' ' .
        $primaryContact['first_name'] . ' & ' .
        $secondaryContact['first_name'] . ' Family';
    }
    elseif (!empty($primaryContact) && empty($secondaryContact)) {
      $houseHoldName = $primaryContact['last_name'] . ' ' .
        $primaryContact['first_name'] . ' Family';
    }
    return [$houseHoldName, $primaryAddress];
  }

  /**
   * Format address.
   *
   * @param array $values
   * @return array
   */
  public static function formatAddress(array &$values): array {
    if (NULL === self::$country) {
      self::$country = CRM_Ultracampsync_Utils::country();
    }
    if (NULL === self::$state) {
      self::$state = CRM_Ultracampsync_Utils::state();
    }
    $addressFieldMapping = [
      'Address' => 'PersonAddress',
      'City' => 'PersonCity',
      'ZipCode' => 'PersonZip',
      'State' => 'PersonState',
      'StateID' => 'PersonStateID',
      'Country' => 'PersonCountry',
      'CountryID' => 'PersonCountryID',
    ];
    if (!empty($values['PersonCountry'])) {
      $values['PersonCountryID'] = self::$country[strtolower($values['PersonCountry'])];
      if (!empty($values['PersonState']) && !empty($values['PersonCountryID'])) {
        $values['PersonStateID'] = self::$state[$values['PersonCountryID']][strtolower($values['PersonState'])];
      }
    }
    if (!empty($values['Country'])) {
      $values['CountryID'] = self::$country[strtolower($values['Country'])];
      if (!empty($values['State']) && !empty($values['CountryID'])) {
        $values['StateID'] = self::$state[$values['CountryID']][strtolower($values['State'])];
      }
    }
    foreach ($addressFieldMapping as $addressField => $personAddress) {
      if (array_key_exists($addressField, $values)) {
        $values[$personAddress] = $values[$addressField];
      }
    }
    return $values;
  }

  /**
   * Update Ultracamp record status to 'new' for retry.
   *
   * @param int $sessionID
   * @return void
   * @throws \Civi\Core\Exception\DBQueryException
   */
  public static function updateUltracampRecord(int $sessionID): void {
    $updateQuery = "UPDATE `civicrm_ultracamp` SET `status` = 'new', message = 'retry' WHERE `status` = 'error' AND `session_id` = %1";
    CRM_Core_DAO::executeQuery($updateQuery, $params = [
      1 => [$sessionID, 'Integer'],
    ]);
  }

  public static function validateReservationData($data) {
    $errors = [];

    // Required fields validation
    $requiredFields = ['AccountId', 'PersonId', 'SessionId', 'ReservationId'];
    foreach ($requiredFields as $field) {
      if (empty($data[$field])) {
        $errors[] = "Missing required field: {$field}";
      }
    }

    // Data type validation
    if (!empty($data['OrderDate']) && !strtotime($data['OrderDate'])) {
      $errors[] = "Invalid OrderDate format";
    }

    // Email validation
    if (!empty($data['Email']) && !filter_var($data['Email'], FILTER_VALIDATE_EMAIL)) {
      $errors[] = "Invalid email format";
    }

    return $errors;
  }

  public static function processReservationWithTransaction(&$reservationData) {
    $transaction = new CRM_Core_Transaction();

    try {
      // Validate data first
      $validationErrors = self::validateReservationData($reservationData);
      if (!empty($validationErrors)) {
        throw new CRM_Core_Exception('Validation failed: ' . implode(', ', $validationErrors));
      }

      // Process in order
      $contactId = CRM_Ultracampsync_Utils::handleContact($reservationData);
      CRM_Ultracampsync_Utils::log('Ultracampbatchprocess Contact created/get: ' . $contactId);
      //$reservationData['contact_id'] = $contactId;
      $reservationIdField = $reservationData['reservation_id_field'];
      $participantResult = CRM_Ultracampsync_Utils::handleParticipant($reservationData, $reservationIdField);

      if ($participantResult === 'error') {
        throw new CRM_Core_Exception('Failed to create participant');
      }
      elseif ($isRecordCreated == 'exists') {
        // if record not created, update the record in the ultracamp table
        // with message 'Participant already exists'.
        throw new CRM_Core_Exception('Participant already exists');
      }
      else {
        // Update status to success
        CRM_Core_DAO::setFieldValue('CRM_Ultracampsync_DAO_Ultracamp', $reservationData['id'], 'status', 'success');
      }
      $transaction->commit();
      return ['success' => TRUE, 'contact_id' => $contactId, 'result' => $participantResult, 'error' => ''];

    }
    catch (Exception $e) {
      $transaction->rollback();

      // Update status to error
      CRM_Core_DAO::setFieldValue('CRM_Ultracampsync_DAO_Ultracamp',
        $reservationData['id'], 'status', 'error');
      CRM_Core_DAO::setFieldValue('CRM_Ultracampsync_DAO_Ultracamp',
        $reservationData['id'], 'message', $e->getMessage());

      CRM_Ultracampsync_Utils::log('Transaction failed for reservation ' .
        $reservationData['reservation_id'] . ': ' . $e->getMessage(), 'error');

      return ['success' => FALSE, 'contact_id' => '', 'result' => $participantResult, 'error' => $e->getMessage()];
    }
  }
}
