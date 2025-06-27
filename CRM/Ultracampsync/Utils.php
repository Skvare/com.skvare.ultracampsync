<?php

use CRM_Ultracampsync_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/quickform/
 */
class CRM_Ultracampsync_Utils {

  private static $country;
  private static $state;

  /**
   * @param $contactParams
   * @return mixed|null
   * @throws CRM_Core_Exception
   */
  public static function handleContact($contactParams = []) {
    if (!empty($contactParams['contact_id'])) {
      //return $contactParams['contact_id'];
    }
    $personId = $contactParams['PersonId'];
    $contactID = NULL;
    if (!empty($contactParams['person_id_field'])) {
      $get_params = [
        'sequential' => 1,
        'return' => ["id", 'first_name', 'last_name',
          'custom_' . $contactParams['account_id_field'],
          'custom_' . $contactParams['person_id_field']],
        'custom_' . $contactParams['person_id_field'] => $personId,
        'contact_type' => 'Individual',
      ];
      $contactResult = civicrm_api3('Contact', 'get', $get_params);
      if ($contactResult['id']) {
        $contactID = $contactResult['id'];
        $contactResultArray = reset($contactResult['values']);
        if (!empty($contactParams['AccountId']) && !empty($contactResultArray['custom_' . $contactParams['account_id_field']])) {
          CRM_Ultracampsync_Utils::logExtra('Contact found by Person ID: ' . $personId . ', contact ID: ' . $contactID);
          return $contactID;
          /*
          // Update the account ID if it exists
          $updateParams = [
            'id' => $contactID,
            'custom_' . $contactParams['account_id_field'] => $contactParams['AccountId'],
          ];
          try {
            civicrm_api3('Contact', 'create', $updateParams);
            CRM_Ultracampsync_Utils::log('Updated contact account ID: ' . $contactParams['AccountId'] . ' for contact ID: ' . $contactID);
          }
          catch (CRM_Core_Exception $e) {
            CRM_Ultracampsync_Utils::log('Error updating contact account ID: ' . $e->getMessage());
          }
          */
        }

      }
    }
    if (empty($contactID)) {
      $get_params = [
        'sequential' => 1,
        'first_name' => $contactParams['FirstName'],
        'last_name' => $contactParams['LastName'],
        'street_address' => $contactParams['Address'],
        'city' => $contactParams['City'],
        'postal_code' => $contactParams['ZipCode'],
        'state_province_id' => $contactParams['StateID'],
        'country_id' => $contactParams['CountryID'],
        'contact_type' => "Individual",
        'options' => ['limit' => 1],
      ];
      $contactResult = civicrm_api3('Contact', 'get', $get_params);
      if (!empty($contactResult['values']) && !empty($contactResult['id'])) {
        CRM_Ultracampsync_Utils::logExtra('Contact found by name: ' . $contactParams['FirstName'] . ' ' . $contactParams['LastName'] . ', contact ID: ' . $contactResult['id']);
        $contactID = $contactResult['id'];
      }
    }

    $newContactParam = [];
    if (!empty($contactID)) {
      $newContactParam['id'] = $contactID;
    }
    $newContactParam['contact_type'] = 'Individual';
    $newContactParam['first_name'] = $contactParams['FirstName'];
    $newContactParam['last_name'] = $contactParams['LastName'];
    $newContactParam['nick_name'] = $contactParams['NickName'];
    $newContactParam['middle_name'] = $contactParams['MiddleName'];
    $newContactParam['birth_date'] = $contactParams['BirthDate'];
    $newContactParam['gender_id'] = $contactParams['Gender'];
    if (!empty($contactParams['person_id_field']) && !empty($contactParams['PersonId'])) {
      $newContactParam['custom_' . $contactParams['person_id_field']] = $contactParams['PersonId'];
    }
    if (!empty($contactParams['account_id_field']) && !empty($contactParams['AccountId'])) {
      $newContactParam['custom_' . $contactParams['account_id_field']] = $contactParams['AccountId'];
    }
    if (!empty($contactParams['primary_contact_field']) && !empty($contactParams['PrimaryContact'])) {
      $newContactParam['custom_' . $contactParams['primary_contact_field']] = 1;
    }
    try {
      CRM_Ultracampsync_Utils::logExtra('Creating/updating contact with params: ' . print_r($newContactParam, TRUE));
      $contactCreateResult = civicrm_api3('Contact', 'create', $newContactParam);
      if (!empty($contactCreateResult['id'])) {
        CRM_Ultracampsync_Utils::logExtra('Contact created/updated with ID: ' . $contactCreateResult['id']);
        $contactID = $contactCreateResult['id'];
      }
    }
    catch (CRM_Core_Exception $e) {
      CRM_Ultracampsync_Utils::log('Error creating/updating contact: ' . $e->getMessage());
    }

    return $contactID;
  }

  /**
   * Handle household contact.
   *
   * @param array $contactParams
   * @param int|null $cfAccountId
   * @return mixed|null
   * @throws CRM_Core_Exception
   */
  public static function handleHouseHoldContact(array $contactParams = [], int $cfAccountId = NULL): mixed {
    $contactID = NULL;
    if (!empty($cfAccountId) && !empty($contactParams['AccountId'])) {
      $get_params = [
        'sequential' => 1,
        'return' => ["id"],
        'contact_type' => 'Household',
        'custom_' . $cfAccountId => $contactParams['AccountId'],
      ];
      CRM_Ultracampsync_Utils::logExtra('Getting household contact by custom field: custom_' . $cfAccountId . ' with value: ' . $contactParams['AccountId']);
      $contactResult = civicrm_api3('Contact', 'get', $get_params);
      if ($contactResult['id']) {
        CRM_Ultracampsync_Utils::logExtra('Household contact found by custom field: custom_' . $cfAccountId . ' with value: ' . $contactParams['AccountId'] . ', contact ID: ' . $contactResult['id']);
        return $contactResult['id'];
      }
    }

    if (empty($contactID)) {
      $get_params = [
        'sequential' => 1,
        'household_name' => $contactParams['AccountName'],
        'street_address' => $contactParams['Address'],
        'city' => $contactParams['City'],
        'postal_code' => $contactParams['ZipCode'],
        'state_province_id' => $contactParams['StateID'],
        'country_id' => $contactParams['CountryID'],
        'contact_type' => "Household",
        'options' => ['limit' => 1],
      ];
      CRM_Ultracampsync_Utils::logExtra('Getting household contact by name: ' . $contactParams['AccountName'] . ', address: ' . $contactParams['Address'] . ', city: ' . $contactParams['City']);
      $contactResult = civicrm_api3('Contact', 'get', $get_params);
      if (!empty($contactResult['values']) && !empty($contactResult['id'])) {
        CRM_Ultracampsync_Utils::logExtra('Household contact found by name: ' . $contactParams['AccountName'] . ', contact ID: ' . $contactResult['id']);
        $contactID = $contactResult['id'];
      }
    }

    $newContactParam = [];
    if (!empty($contactID)) {
      $newContactParam['id'] = $contactID;
    }
    $newContactParam['contact_type'] = 'Household';
    $newContactParam['household_name'] = $contactParams['AccountName'];
    if (!empty($cfAccountId) && !empty($contactParams['AccountId'])) {
      $newContactParam['custom_' . $cfAccountId] = $contactParams['AccountId'];
    }
    try {
      CRM_Ultracampsync_Utils::logExtra('Creating/updating household contact with params: ' . print_r($newContactParam, TRUE));
      $contactCreateResult = civicrm_api3('Contact', 'create', $newContactParam);
      if (!empty($contactCreateResult['id'])) {
        CRM_Ultracampsync_Utils::logExtra('Household contact created/updated with ID: ' . $contactCreateResult['id']);
        $contactID = $contactCreateResult['id'];
      }
    }
    catch (CRM_Core_Exception $e) {
      CRM_Ultracampsync_Utils::log('Household: Error creating/updating contact: ' . $e->getMessage());
    }
    return $contactID;
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
   * Handle Participant.
   *
   * @param array $participantParams
   * @param int $reservation_id_field
   * @return string
   * @throws CRM_Core_Exception
   */
  public static function handleParticipant(array $participantParams, int $reservation_id_field): string {
    // check if participants already exists.
    $params = [
      'contact_id' => $participantParams['contact_id'],
      'event_id' => $participantParams['event_id'],
      'custom_' . $reservation_id_field => $participantParams['ReservationId'],
    ];
    CRM_Ultracampsync_Utils::logExtra('Checking for existing participant with params: ' . print_r($params, TRUE));
    $resultParticipant = civicrm_api3('Participant', 'get', $params);
    if (!empty($resultParticipant['values'])) {
      CRM_Ultracampsync_Utils::logExtra('Participant already exists for contact ID: ' . $participantParams['contact_id'] . ', event ID: ' . $participantParams['event_id']);
      return 'exists'; // Participant already exists, no need to create again.
    }
    $params['status_id'] = 1;  // 5 = pending from pay later, 1 = registered
    $params['role_id'] = 1; // 1 = attendee
    $params['source'] = 'Ultra camp Sync';
    if (!empty($reservation_id_field)) {
      $params['custom_' . $reservation_id_field] = $participantParams['ReservationId'];
    }
    $params['register_date'] = date("YmdHis", strtotime($participantParams['OrderDate']));
    try {
      $participant = civicrm_api3('Participant', 'create', $params);
      if (!empty($participant['id']) && !empty($participantParams['id'])) {
        CRM_Ultracampsync_Utils::logExtra('Participant created with ID: ' . $participant['id']);
        CRM_Core_DAO::setFieldValue('CRM_Ultracampsync_DAO_Ultracamp', $participantParams['id'], 'participant_id', $participant['id']);
      }
    }
    catch (CRM_Core_Exception $e) {
      CRM_Ultracampsync_Utils::log('Error creating/updating participant: ' . $e->getMessage());
      return 'error';
    }
    return 'success';
  }

  /**
   * Get UltraCamp programs for dropdown
   *
   * @return array Array of programs
   */
  protected function getUltracampPrograms(): array {
    $sessions = ['' => '- None -'];
    try {
      $client = new CRM_Ultracampsync_API_UltracampClient();
      $sessionsList = $client->getSessions();

      if (!empty($sessionsList)) {
        foreach ($sessionsList as $session) {
          if (!empty($session['id']) && !empty($session['name'])) {
            $sessions[$session['id']] = $session['name'];
          }
        }
      }
    }
    catch (Exception $e) {
      CRM_Ultracampsync_Utils::log('Error getting UltraCamp session: ' . $e->getMessage());
    }

    return $sessions;
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

      if ($result['values'] > 0) {
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
   */
  public static function log(string $message, string $level = 'info') {
    $logger = Civi::log();
    $logger->log($level, '[UltracampSync] ' . $message);
  }

  /**
   * Log message to CiviCRM log
   *
   * @param string $message
   * @param string $level
   */
  public static function logExtra(string $message, string $level = 'info') {
    if (Civi::settings()->get('ultracampsync_debug_enable')) {
      $logger = Civi::log();
      $logger->log($level, '[UltracampSync] ' . $message);
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
   * Handle relationship.
   * Check Relationship record exist before creating new one.
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
    $params = [
      'contact_id_a' => $personContactID,
      'contact_id_b' => $houseHoldContactID,
      'relationship_type_id' => $relationshipTypeId,
    ];
    try {
      $result = civicrm_api3('Relationship', 'get', $params);
    }
    catch (CRM_Core_Exception $e) {
      CRM_Ultracampsync_Utils::log('Error checking relationship: ' . $e->getMessage());
      $result = ['count' => 0, 'values' => []];
    }
    if (empty($result['count'])) {
      $params['relationship_type_id'] = $relationshipTypeId;
      $params['contact_id_a'] = $personContactID;
      $params['contact_id_b'] = $houseHoldContactID;
      $cf_relationship = Civi::settings()->get('ultracampsync_relationship_id_field');
      if (!empty($cf_relationship) && !empty($relationshipTypeFromUltraCamp)) {
        $params['custom_' . $cf_relationship] = $relationshipTypeFromUltraCamp;
      }
      try {
        civicrm_api3('Relationship', 'create', $params);
      }
      catch (CRM_Core_Exception $e) {
        CRM_Ultracampsync_Utils::log('Error creating relationship: ' . $e->getMessage());
      }
    }
    else {
      CRM_Ultracampsync_Utils::log('handleRelationship: Relationship already exists.');
    }
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
}
