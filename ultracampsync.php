<?php

require_once 'ultracampsync.civix.php';

use CRM_Ultracampsync_ExtensionUtil as E;

/**
 * Implements hook_civicrm_config().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_config/
 */
function ultracampsync_civicrm_config(&$config): void {
  _ultracampsync_civix_civicrm_config($config);
}

/**
 * Implements hook_civicrm_install().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_install
 */
function ultracampsync_civicrm_install(): void {
  _ultracampsync_civix_civicrm_install();
}

/**
 * Implements hook_civicrm_enable().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_enable
 */
function ultracampsync_civicrm_enable(): void {
  _ultracampsync_civix_civicrm_enable();
}

/**
 * Implements hook_civicrm_navigationMenu().
 *
 * @link https://docs.civicrm.org/dev/en/latest/hooks/hook_civicrm_navigationMenu
 */
function ultracampsync_civicrm_navigationMenu(&$menu) {
  _ultracampsync_civix_insert_navigation_menu($menu, 'Administer/System Settings', [
    'label' => E::ts('UltraCamp Sync Settings'),
    'name' => 'ultracamp_sync_settings',
    'url' => 'civicrm/admin/ultracamp/settings',
    'permission' => 'administer CiviCRM',
    'operator' => 'OR',
    'separator' => 0,
  ]);

  _ultracampsync_civix_navigationMenu($menu);
}

/**
 * Implementation of hook_civicrm_customPre
 */
function ultracampsync_civicrm_customPre($op, $groupID, $entityID, $params) {
  //  Ultracamp Date Custom Group.
  $cgGroupID = Civi::settings()->get('ultracampsync_event_cg_group_field');
  if (in_array($op, ['create', 'edit']) && $groupID == $cgGroupID) {
    $cfSessionID = CRM_Ultracampsync_Utils::getUltracampSessionIdCustomField(TRUE);
    foreach ($params as $param) {
      if ($param['custom_field_id'] == $cfSessionID && !empty($param['value'])) {
        $params = [
          'entityID' => $entityID,
          'custom_' . $cfSessionID => 1,
        ];
        // get before value
        $values = CRM_Core_BAO_CustomValueTable::getValues($params);
        if ($values['custom_' . $cfSessionID] != $param['value']) {
          CRM_Ultracampsync_Utils::updateUltracampRecord($param['value']);
        }
      }
    }
  }
}

