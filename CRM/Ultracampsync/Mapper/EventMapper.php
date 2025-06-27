<?php
use CRM_Ultracampsync_ExtensionUtil as E;

/**
 * Event Mapper
 * Maps UltraCamp sessions to CiviCRM events
 */
class CRM_Ultracampsync_Mapper_EventMapper {

  /**
   * Map UltraCamp session to CiviCRM event format
   *
   * @param array $session UltraCamp session data
   * @return array CiviCRM event data
   */
  public function mapSessionToEvent($session) {
    if (empty($session)) {
      return NULL;
    }

    // Get custom field mappings from settings
    $fieldMappings = Civi::settings()->get('ultracampsync_field_mappings');
    if (empty($fieldMappings)) {
      $fieldMappings = $this->getDefaultFieldMappings();
    }

    // Build the event data
    $eventData = [
      'event_type_id' => Civi::settings()->get('ultracampsync_default_event_type_id'),
      'is_public' => 1,
      'is_active' => 1,
    ];

    // Apply mappings
    foreach ($fieldMappings as $eventField => $sessionField) {
      if (!empty($sessionField) && isset($session[$sessionField])) {
        $eventData[$eventField] = $session[$sessionField];
      }
    }

    // Map special fields
    if (!empty($session['name'])) {
      $eventData['title'] = $session['name'];
    }

    if (!empty($session['description'])) {
      $eventData['description'] = $session['description'];
    }

    if (!empty($session['startDate'])) {
      // Convert format from YYYY-MM-DD to CiviCRM date format
      $eventData['start_date'] = date('YmdHis', strtotime($session['startDate']));
    }

    if (!empty($session['endDate'])) {
      // Convert format from YYYY-MM-DD to CiviCRM date format
      $eventData['end_date'] = date('YmdHis', strtotime($session['endDate']));
    }

    // Store UltraCamp session ID in custom field if available
    $ultracampIdField = Civi::settings()->get('ultracampsync_session_id_field');
    if (!empty($ultracampIdField) && !empty($session['id'])) {
      $eventData['custom_' . $ultracampIdField] = $session['id'];
    }

    // Add registration fields if available
    if (!empty($session['registrationOpen']) && $session['registrationOpen'] === TRUE) {
      $eventData['is_online_registration'] = 1;

      if (!empty($session['registrationStartDate'])) {
        $eventData['registration_start_date'] = date('YmdHis', strtotime($session['registrationStartDate']));
      }

      if (!empty($session['registrationEndDate'])) {
        $eventData['registration_end_date'] = date('YmdHis', strtotime($session['registrationEndDate']));
      }
    }

    // Handle capacity/max participants
    if (!empty($session['capacity'])) {
      $eventData['max_participants'] = $session['capacity'];
      $eventData['has_waitlist'] = 1; // Enable waitlist by default if capacity is set
    }

    // Add session code as event summary if available
    if (!empty($session['code'])) {
      $eventData['summary'] = 'Session Code: ' . $session['code'];
    }

    return $eventData;
  }

  /**
   * Get default field mappings
   *
   * @return array Default field mappings
   */
  public function getDefaultFieldMappings() {
    return [
      'title' => 'name',
      'description' => 'description',
      'start_date' => 'startDate',
      'end_date' => 'endDate',
      'summary' => 'code',
      'max_participants' => 'capacity',
    ];
  }

  /**
   * Find existing CiviCRM event by UltraCamp session ID
   *
   * @param int $sessionId UltraCamp session ID
   * @return int|null CiviCRM event ID if found, NULL otherwise
   */
  public function findExistingEventBySessionId($sessionId) {
    $ultracampIdField = Civi::settings()->get('ultracampsync_session_id_field');

    if (empty($ultracampIdField) || empty($sessionId)) {
      return NULL;
    }

    try {
      $result = civicrm_api3('Event', 'get', [
        'custom_' . $ultracampIdField => $sessionId,
        'options' => ['limit' => 1],
        'return' => ['id'],
      ]);

      if ($result['count'] > 0) {
        return reset($result['values'])['id'];
      }
    }
    catch (CiviCRM_API3_Exception $e) {
      CRM_Core_Error::debug_log_message('Error finding event by UltraCamp session ID: ' . $e->getMessage());
    }

    return NULL;
  }

  /**
   * Create or update CiviCRM event from UltraCamp session
   *
   * @param array $session UltraCamp session data
   * @return array Result containing event ID and status
   */
  public function createOrUpdateEvent($session) {
    if (empty($session['id'])) {
      return [
        'success' => FALSE,
        'message' => 'Session ID is missing',
      ];
    }

    $eventData = $this->mapSessionToEvent($session);
    if (empty($eventData)) {
      return [
        'success' => FALSE,
        'message' => 'Failed to map session data to event',
      ];
    }

    // Try to find existing event
    $eventId = $this->findExistingEventBySessionId($session['id']);
    $action = 'create';

    if ($eventId) {
      $eventData['id'] = $eventId;
      $action = 'update';
    }

    try {
      $result = civicrm_api3('Event', 'create', $eventData);

      return [
        'success' => TRUE,
        'event_id' => $result['id'],
        'action' => $action,
        'message' => "Event {$action}d successfully",
      ];
    }
    catch (CiviCRM_API3_Exception $e) {
      return [
        'success' => FALSE,
        'message' => "Failed to {$action} event: " . $e->getMessage(),
      ];
    }
  }
}
