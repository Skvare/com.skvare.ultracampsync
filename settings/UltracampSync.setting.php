<?php
use CRM_UltracampSync_ExtensionUtil as E;

return [
  'ultracampsync_camp_id' => [
    'group_name' => 'UltraCamp Sync Settings',
    'group' => 'ultracampsync',
    'name' => 'ultracampsync_camp_id',
    'type' => 'String',
    'default' => '',
    'add' => '1.0',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('Your UltraCamp Camp ID'),
    'help_text' => E::ts('Your UltraCamp Camp ID'),
  ],
  'ultracampsync_camp_api_key' => [
    'group_name' => 'UltraCamp Sync Settings',
    'group' => 'ultracampsync',
    'name' => 'ultracampsync_camp_api_key',
    'type' => 'String',
    'default' => '',
    'add' => '1.0',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('Your UltraCamp Camp API Key'),
    'help_text' => E::ts('Your UltraCamp Camp API Key'),
  ],
  'ultracampsync_session_id_field' => [
    'group_name' => 'UltraCamp Sync Settings',
    'group' => 'ultracampsync',
    'name' => 'ultracampsync_session_id_field',
    'type' => 'String',
    'default' => '',
    'add' => '1.0',
    'is_domain' => 1,
    'is_contact' => 0,
    'description' => E::ts('Custom Field for session id in event.'),
    'help_text' => E::ts('Custom Field for session id in event.'),
  ],
];
