<?php

use CRM_Ultracampsync_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/quickform/
 */
class CRM_Ultracampsync_Utils {

  /**
   * @param $contactParams
   * @param $cfPersonId
   * @param $cfAccountId
   * @return mixed|null
   * @throws CRM_Core_Exception
   */
  public static function handleContact($contactParams = [], $cfPersonId = NULL, $cfAccountId = NULL) {
    $personId = $contactParams['PersonId'];
    $contactID = NULL;
    if (!empty($cfAccountId)) {
      $contactResult = civicrm_api3('Contact', 'get', [
        'sequential' => 1,
        'return' => ["id"],
        'custom_' . $cfPersonId => $personId,
      ]);
      if ($contactResult['id']) {
        $contactID = $contactResult['id'];
      }
      return $contactID;
    }

    if (empty($contactID)) {
      $get_params = [
        'contact_type' => 'Individual',
        'first_name' => $contactParams['first_name'],
        'last_name' => $contactParams['last_name'],
      ];
      $contactResult = civicrm_api3('Contact', 'get', $get_params);
      if (!empty($contactResult['id'])) {
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
    if (!empty($cfPersonId) && !empty($contactParams['PersonId'])) {
      $newContactParam['custom_' . $cfPersonId] = $contactParams['PersonId'];
    }
    if (!empty($cfAccountId) && !empty($contactParams['AccountId'])) {
      $newContactParam['custom_' . $cfAccountId] = $contactParams['AccountId'];
    }
    try {
      $contactCreateResult = civicrm_api3('Contact', 'create', $newContactParam);
      if (!empty($contactCreateResult['id'])) {
        $contactID = $contactCreateResult['id'];
      }
    }
    catch (CRM_Core_Exception $e) {
      CRM_Core_Error::debug_log_message('Error creating/updating contact: ' . $e->getMessage());
    }

    return $contactID;
  }

  /**
   * Handle household contact.
   *
   * @param $contactParams
   * @param $cfAccountId
   * @param $accountId
   * @return mixed|null
   * @throws CRM_Core_Exception
   */
  public static function handleHouseHoldContact($contactParams = [], $cfAccountId = NULL) {
    $contactID = NULL;
    if (!empty($cfAccountId)) {
      $contactResult = civicrm_api3('Contact', 'get', [
        'sequential' => 1,
        'return' => ["id"],
        'contact_type' => 'Household',
        'custom_' . $cfAccountId => $contactParams['AccountId'],
      ]);
      if ($contactResult['id']) {
        $contactID = $contactResult['id'];
      }
      return $contactID;
    }

    if (empty($contactID)) {
      $get_params = [
        'contact_type' => 'Household',
        'household_name' => $contactParams['AccountName'],
      ];
      $contactResult = civicrm_api3('Contact', 'get', $get_params);
      if (!empty($contactResult['id'])) {
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
      $contactCreateResult = civicrm_api3('Contact', 'create', $newContactParam);
      if (!empty($contactCreateResult['id'])) {
        $contactID = $contactCreateResult['id'];
      }
    }
    catch (CRM_Core_Exception $e) {
      CRM_Core_Error::debug_log_message('Household: Error creating/updating contact: ' . $e->getMessage());
    }

    return $contactID;
  }

  /**
   * Handle contact address.
   *
   * @param $addressParams
   * @return void
   * @throws CRM_Core_Exception
   */
  public static function handleAddress($addressParams = []) {
    $id = $addressParams['contact_id'];
    $address_id = $id;
    $address_params = ['version' => 3, 'contact_id' => $addressParams['contact_id'], 'is_primary' => '1'];
    $existing_address = civicrm_api3('Address', 'get', $address_params);
    if ($existing_address['id']) {
      $idtype = 'id';
      $address_id = $existing_address['id'];
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
      $civi_address = civicrm_api3('Address', 'create', $address_params);
    }
    catch (CRM_Core_Exception $e) {
      CRM_Core_Error::debug_log_message('Error creating/updating address: ' . $e->getMessage());
    }
  }

  /**
   * Handle Participant.
   *
   * @param $parcipantParams
   * @param $reservation_id_field
   * @return bool
   * @throws CRM_Core_Exception
   */
  public static function handleParticipant($parcipantParams = [], $reservation_id_field) {
    // check if participants already exists.
    $params = [
      'contact_id' => $parcipantParams['contact_id'],
      'event_id' => $parcipantParams['event_id'],
      'custom_' . $reservation_id_field => $parcipantParams['ReservationId'],
    ];

    $resultParticipant = civicrm_api3('Participant', 'get', $params);
    if (!empty($resultParticipant['values'])) {
      CRM_Core_Error::debug_log_message('Participant already exists: ' . $resultParticipant['values'][0]['id']);
      return TRUE;
    }
    $params['status_id'] = 1;  // 5 = pending from pay later, 1 = registered
    $params['role_id'] = 1; // 1 = attendee
    $params['source'] = 'Ultracamp Sync';
    if (!empty($reservation_id_field)) {
      $params['custom_' . $reservation_id_field] = $parcipantParams['ReservationId'];
    }
    $params['register_date'] = date("YmdHis", strtotime($parcipantParams['OrderDate']));
    try {
      $participant = civicrm_api3('Participant', 'create', $params);
      if (!empty($participant['id']) && !empty($parcipantParams['id'])) {
        CRM_Core_DAO::setFieldValue('CRM_Ultracampsync_DAO_Ultracamp', $parcipantParams['id'], 'participant_id', $participant['id']);
      }
    }
    catch (CRM_Core_Exception $e) {
      CRM_Core_Error::debug_log_message('Error creating/updating participant: ' . $e->getMessage());
      return FALSE;
    }
    return TRUE;
  }

  /**
   * Get UltraCamp programs for dropdown
   *
   * @return array Array of programs
   */
  protected function getUltracampPrograms() {
    $sessions = ['' => '- None -'];
    try {
      $client = new CRM_UltracampSync_API_UltracampClient();
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
      CRM_Core_Error::debug_log_message('Error getting UltraCamp session: ' . $e->getMessage());
    }

    return $sessions;
  }

  /**
   * Get the custom field ID for Ultracamp Session ID
   *
   * @return string
   */
  public static function getUltracampSessionIdCustomField() {
    $result = civicrm_api3('CustomField', 'get', [
      'sequential' => 1,
      'custom_group_id' => 'ultracamp_data',
      'name' => 'ultracamp_session_id',
    ]);

    if ($result['count'] > 0) {
      return 'custom_' . $result['values'][0]['id'];
    }

    return NULL;
  }

  /**
   * Get the custom field ID for Last Sync Date
   *
   * @return string
   */
  public static function getLastSyncCustomField() {
    $result = civicrm_api3('CustomField', 'get', [
      'sequential' => 1,
      'custom_group_id' => 'ultracamp_data',
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
  public static function getEventBySessionId($sessionId) {
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
      CRM_Core_Error::debug_log_message('Error finding event by session ID: ' . $e->getMessage());
    }

    return NULL;
  }

  public static function getEventWithSessionId() {
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
          $sessionId = $value['custom_' . $cfSessionId];
          if (!empty($sessionId)) {
            $events[$sessionId] = $value['id'];
          }
        }

      }
    }
    catch (Exception $e) {
      CRM_Core_Error::debug_log_message('Error finding event by session ID: ' . $e->getMessage());
    }
    return $events;
  }

  /**
   * Update last sync timestamp
   */
  public static function updateLastSyncTimestamp() {
    $now = date('Y-m-d H:i:s');
    Civi::settings()->set('ultracampsync_last_sync', $now);
    return $now;
  }

  /**
   * Get number of events mapped to Ultracamp sessions
   *
   * @return int
   */
  public static function getMappedEventsCount() {
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
      CRM_Core_Error::debug_log_message('Error counting mapped events: ' . $e->getMessage());
      return 0;
    }
  }

  /**
   * Log message to CiviCRM log
   *
   * @param string $message
   * @param string $level
   */
  public static function log($message, $level = 'info') {
    $logger = Civi::log();
    $logger->log($level, '[UltracampSync] ' . $message);
  }

  public static function country() {
    $result = civicrm_api3('address', 'getoptions', ['field' => 'country_id']);
    $country = array_flip($result['values']);
    $country = array_change_key_case($country, CASE_LOWER);
    // alternative country names (acutal name in english -> alternative names).
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
  static function state() {
    // @TODO Restrict to enabled counrty only
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
  public static function recordStatus() {
    return [
      'new' => E::ts('New'),
      'error' => E::ts('Error'),
      'success' => E::ts('Success'),
      'processing' => E::ts('Processing'),
    ];
  }

  public static function getRelationshipTypeMapping() {
    return [
      'Daughter' => '34', // Child of
      'Father/Husband' => '7',
      'Granddaughter' => '39',
      'Grandfather' => '35',
      'Grandmother' => '35',
      'Grandson' => '39',
      'Individual Adult' => '',
      'Mother/Wife' => '7',
      'Nephew' => '36',
      'Niece' => '36',
      'Non-Family' => '38',
      'Other Extended Family' => '41',
      'Priest' => '',
      'Religious' => '',
      'Son' => '34',
    ];
  }


  public static function getRelationshipType($people) {
    $relationshipType = '';
    if (!empty($people['CustomQuestions'])) {
      foreach ($people['CustomQuestions'] as $customQuestion) {
        if ($customQuestion['Name'] == 'Relationship') {
          $relationshipType = $customQuestion['Answer'];
          break;
        }
      }
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
   */
  public static function handleRelationship($personContactID, $houseHoldContactID, $relationshipTypeId) {
    $params = [
      'contact_id_a' => $personContactID,
      'contact_id_b' => $houseHoldContactID,
      'relationship_type_id' => $relationshipTypeId,
    ];
    $result = civicrm_api3('Relationship', 'get', $params);
    if (empty($result['count'])) {
      $params['relationship_type_id'] = $relationshipTypeId;
      $params['contact_id_a'] = $personContactID;
      $params['contact_id_b'] = $houseHoldContactID;
      try {
        civicrm_api3('Relationship', 'create', $params);
      }
      catch (CRM_Core_Exception $e) {
        CRM_Core_Error::debug_log_message('Error creating relationship: ' . $e->getMessage());
      }
    }
  }

  /**
   * Get Household name from people.
   *
   * @param array $peoples
   * @return string
   */
  public static function getHouseHoldName($peoples) {
    $primaryContact = [];
    $secondoryContact = [];
    $genderWiseContact = [];
    $houseHoldName = '';
    foreach ($peoples as $people) {
      if (!empty($people['PrimaryContact'])) {
        $primaryContact = ['first_name' => $people['FirstName'], 'last_name' => $people['LastName'], 'gender' => $people['Gender'], 'peopleID' => $people['Id']];
        $gender = $people['Gender'] ?? 'PrimaryGender';
        $genderWiseContact[$gender] = ['first_name' => $people['FirstName'], 'last_name' => $people['LastName'], 'gender' => $people['Gender']];
      }
      if (!empty($people['SecondaryContact'])) {
        $secondoryContact = ['first_name' => $people['FirstName'], 'last_name' => $people['LastName'], 'gender' => $people['Gender'], 'peopleID' => $people['Id']];
        $gender = $people['Gender'] ?? 'secondoryGender';
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
    if (!empty($primaryContact) && !empty($secondoryContact) &&
      array_key_exists('Female', $genderWiseContact) && array_key_exists('Male', $genderWiseContact)) {
      $houseHoldName = $genderWiseContact['Male']['last_name'] . ' ' .
        $genderWiseContact['Male']['first_name'] . ' & ' .
        $genderWiseContact['Female']['first_name'] . '  Family';
    }
    elseif (!empty($primaryContact) && !empty($secondoryContact) && count($genderWiseContact) == 1) {
      $houseHoldName = $primaryContact['last_name'] . ' ' .
        $primaryContact['first_name'] . ' & ' .
        $secondoryContact['first_name'] . '  Family';
    }
    elseif (!empty($primaryContact) && empty($secondoryContact)) {
      $houseHoldName = $primaryContact['last_name'] . ' ' .
        $primaryContact['first_name'] . ' Family';
    }
    return $houseHoldName;
  }
}
