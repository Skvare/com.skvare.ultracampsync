<?php

use CRM_Ultracampsync_ExtensionUtil as E;

/**
 * Collection of upgrade steps.
 */
class CRM_Ultracampsync_Upgrader extends CRM_Extension_Upgrader_Base {

  // By convention, functions that look like "function upgrade_NNNN()" are
  // upgrade tasks. They are executed in order (like Drupal's hook_update_N).

  /**
   * Example: Run an external SQL script when the module is installed.
   *
   * Note that if a file is present sql\auto_install that will run regardless of this hook.
   */
  public function install(): void {
    $this->installCustomGroupForEvent();
    $this->installCustomGroupForParticipant();
  }

  public function installCustomGroupForEvent(): void {
    try {
      // Create custom group for Ultracamp data
      $customGroup = civicrm_api3('CustomGroup', 'create', [
        'title' => "Ultracamp Data",
        'extends' => "Event",
        'is_active' => 1,
        'is_reserved' => 0,
        'name' => "ultracamp_data",
        'is_public' => 1,
      ]);
      $customGroupId = $customGroup['id'];
    }
    catch (Exception $e) {
      // If an exception is thrown, most likely the option group already exists,
      // in which case we'll just use that one.
      $customGroupId = civicrm_api3('CustomGroup', 'getvalue', [
        'name' => 'ultracamp_data',
        'return' => 'id',
      ]);
    }

    try {
      // Create custom field for Ultracamp session ID
      civicrm_api3('CustomField', 'create', [
        'custom_group_id' => $customGroupId,
        'label' => "Ultracamp Session ID",
        'name' => "ultracamp_session_id",
        'data_type' => "String",
        'html_type' => "Text",
        'is_active' => 1,
        'is_searchable' => 1,
        'is_view' => 0,
        'is_required' => 0,
        'weight' => 1,
        'help_pre' => 'When you add or update the session, the system will check the UltraCamp record and update any record in the error status to the new state so it can be imported into CiviCRM.',
      ]);
    }
    catch (Exception $e) {
      CRM_Core_Error::debug_log_message('Failed to create custom fields: ' . $e->getMessage());
    }
    try {
      // Create custom field for last sync date
      civicrm_api3('CustomField', 'create', [
        'custom_group_id' => $customGroupId,
        'label' => "Last Sync Date",
        'name' => "ultracamp_last_sync",
        'data_type' => "Date",
        'html_type' => "Select Date",
        'is_active' => 1,
        'is_searchable' => 1,
        'is_view' => 1,
        'is_required' => 0,
        'weight' => 2,
      ]);
    }
    catch (Exception $e) {
      CRM_Core_Error::debug_log_message('Failed to create custom fields: ' . $e->getMessage());
    }

    try {
      // Create custom field for last sync date
      civicrm_api3('CustomField', 'create', [
        'custom_group_id' => $customGroupId,
        'label' => "Stop Syncing",
        'name' => "ultracamp_stop_sync",
        'data_type' => "Boolean",
        'html_type' => "Radio",
        'is_active' => 1,
        'is_searchable' => 1,
        'is_view' => 0,
        'is_required' => 0,
        'default_value' => 0,
        'weight' => 3,
      ]);
    }
    catch (Exception $e) {
      CRM_Core_Error::debug_log_message('Failed to create custom fields: ' . $e->getMessage());
    }
  }
  /**
   * Example: Create custom group and fields for participant.
   *
   * @return void
   */
  public function installCustomGroupForParticipant(): void {
    try {
      // Create custom group for Ultracamp data
      $customGroup = civicrm_api3('CustomGroup', 'create', [
        'title' => "Ultracamp Participant",
        'extends' => "Participant",
        'is_active' => 1,
        'is_reserved' => 0,
        'name' => "ultracamp_participant",
        'is_public' => 1,
      ]);
      $customGroupId = $customGroup['id'];
    }
    catch (Exception $e) {
      // If an exception is thrown, most likely the option group already exists,
      // in which case we'll just use that one.
      $customGroupId = civicrm_api3('CustomGroup', 'getvalue', [
        'name' => 'ultracamp_participant',
        'return' => 'id',
      ]);
    }

    try {
      // Create custom field for Ultracamp session ID
      civicrm_api3('CustomField', 'create', [
        'custom_group_id' => $customGroupId,
        'label' => "Ultracamp Reservation ID",
        'name' => "ultracamp_reservation_idd",
        'data_type' => "String",
        'html_type' => "Text",
        'is_active' => 1,
        'is_searchable' => 1,
        'is_view' => 0,
        'is_required' => 0,
        'weight' => 1,
      ]);
    }
    catch (Exception $e) {
      CRM_Core_Error::debug_log_message('Failed to create custom fields: ' . $e->getMessage());
    }
  }

  /**
   * Example: Create custom group and fields for participant.
   *
   * @return void
   */
  public function installCustomGroupForRelationship(): void {
    try {
      // Create custom group for Ultracamp data
      $customGroup = civicrm_api3('CustomGroup', 'create', [
        'title' => "Ultracamp Relationship",
        'extends' => "Relationship",
        'is_active' => 1,
        'is_reserved' => 0,
        'name' => "ultracamp_relationship",
        'is_public' => 1,
      ]);
      $customGroupId = $customGroup['id'];
    }
    catch (Exception $e) {
      // If an exception is thrown, most likely the option group already exists,
      // in which case we'll just use that one.
      $customGroupId = civicrm_api3('CustomGroup', 'getvalue', [
        'name' => 'ultracamp_relationship',
        'return' => 'id',
      ]);
    }

    try {
      // Create custom field for Ultracamp relationship on relationship type ID
      civicrm_api3('CustomField', 'create', [
        'custom_group_id' => $customGroupId,
        'label' => "Person Relationship",
        'name' => "ultracamp_relationship_to_account",
        'data_type' => "String",
        'html_type' => "Text",
        'is_active' => 1,
        'is_searchable' => 1,
        'is_view' => 0,
        'is_required' => 0,
        'weight' => 1,
      ]);
    }
    catch (Exception $e) {
      CRM_Core_Error::debug_log_message('Failed to create custom fields: ' . $e->getMessage());
    }
  }


  public function upgrade_1001(): bool {
    $this->ctx->log->info('Adding custom field for participant 1001');
    $this->installCustomGroupForParticipant();
    return TRUE;
  }

  public function upgrade_1003(): bool {
    $this->ctx->log->info('Adding custom field for relationship type 1002');
    $this->installCustomGroupForRelationship();
    return TRUE;
  }

  /**
   * Example: Work with entities usually not available during the install step.
   *
   * This method can be used for any post-install tasks. For example, if a step
   * of your installation depends on accessing an entity that is itself
   * created during the installation (e.g., a setting or a managed entity), do
   * so here to avoid order of operation problems.
   */
  // public function postInstall(): void {
  //  $customFieldId = civicrm_api3('CustomField', 'getvalue', array(
  //    'return' => array("id"),
  //    'name' => "customFieldCreatedViaManagedHook",
  //  ));
  //  civicrm_api3('Setting', 'create', array(
  //    'myWeirdFieldSetting' => array('id' => $customFieldId, 'weirdness' => 1),
  //  ));
  // }

  /**
   * Example: Run an external SQL script when the module is uninstalled.
   *
   * Note that if a file is present sql\auto_uninstall that will run regardless of this hook.
   */
  // public function uninstall(): void {
  //   $this->executeSqlFile('sql/my_uninstall.sql');
  // }

  /**
   * Example: Run a simple query when a module is enabled.
   */
  // public function enable(): void {
  //  CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 1 WHERE bar = "whiz"');
  // }

  /**
   * Example: Run a simple query when a module is disabled.
   */
  // public function disable(): void {
  //   CRM_Core_DAO::executeQuery('UPDATE foo SET is_active = 0 WHERE bar = "whiz"');
  // }

  /**
   * Example: Run a couple simple queries.
   *
   * @return TRUE on success
   * @throws CRM_Core_Exception
   */
  // public function upgrade_4200(): bool {
  //   $this->ctx->log->info('Applying update 4200');
  //   CRM_Core_DAO::executeQuery('UPDATE foo SET bar = "whiz"');
  //   CRM_Core_DAO::executeQuery('DELETE FROM bang WHERE willy = wonka(2)');
  //   return TRUE;
  // }

  /**
   * Example: Run an external SQL script.
   *
   * @return TRUE on success
   * @throws CRM_Core_Exception
   */
  // public function upgrade_4201(): bool {
  //   $this->ctx->log->info('Applying update 4201');
  //   // this path is relative to the extension base dir
  //   $this->executeSqlFile('sql/upgrade_4201.sql');
  //   return TRUE;
  // }

  /**
   * Example: Run a slow upgrade process by breaking it up into smaller chunk.
   *
   * @return TRUE on success
   * @throws CRM_Core_Exception
   */
  // public function upgrade_4202(): bool {
  //   $this->ctx->log->info('Planning update 4202'); // PEAR Log interface

  //   $this->addTask(E::ts('Process first step'), 'processPart1', $arg1, $arg2);
  //   $this->addTask(E::ts('Process second step'), 'processPart2', $arg3, $arg4);
  //   $this->addTask(E::ts('Process second step'), 'processPart3', $arg5);
  //   return TRUE;
  // }
  // public function processPart1($arg1, $arg2) { sleep(10); return TRUE; }
  // public function processPart2($arg3, $arg4) { sleep(10); return TRUE; }
  // public function processPart3($arg5) { sleep(10); return TRUE; }

  /**
   * Example: Run an upgrade with a query that touches many (potentially
   * millions) of records by breaking it up into smaller chunks.
   *
   * @return TRUE on success
   * @throws CRM_Core_Exception
   */
  // public function upgrade_4203(): bool {
  //   $this->ctx->log->info('Planning update 4203'); // PEAR Log interface

  //   $minId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(min(id),0) FROM civicrm_contribution');
  //   $maxId = CRM_Core_DAO::singleValueQuery('SELECT coalesce(max(id),0) FROM civicrm_contribution');
  //   for ($startId = $minId; $startId <= $maxId; $startId += self::BATCH_SIZE) {
  //     $endId = $startId + self::BATCH_SIZE - 1;
  //     $title = E::ts('Upgrade Batch (%1 => %2)', array(
  //       1 => $startId,
  //       2 => $endId,
  //     ));
  //     $sql = '
  //       UPDATE civicrm_contribution SET foobar = apple(banana()+durian)
  //       WHERE id BETWEEN %1 and %2
  //     ';
  //     $params = array(
  //       1 => array($startId, 'Integer'),
  //       2 => array($endId, 'Integer'),
  //     );
  //     $this->addTask($title, 'executeSql', $sql, $params);
  //   }
  //   return TRUE;
  // }

}
