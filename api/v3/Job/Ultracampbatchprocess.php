<?php
use CRM_Ultracampsync_ExtensionUtil as E;

/**
 * Job.Ultracampbatchprocess API specification (optional)
 * This is used for documentation and validation.
 *
 * @param array $spec description of fields supported by this API call
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/api-architecture/
 */
function _civicrm_api3_job_Ultracampbatchprocess_spec(&$spec) {
  $spec['limit'] = [
    'type' => CRM_Utils_Type::T_STRING,
    'name' => 'limit',
    'title' => 'Limit',
    'description' => 'Maximum number of records to process in this batch',
    'api.default' => 100,
  ];

  $spec['retry_errors'] = [
    'type' => CRM_Utils_Type::T_BOOLEAN,
    'name' => 'retry_errors',
    'title' => 'Retry Error Records',
    'description' => 'Whether to retry processing records in error status',
    'api.default' => FALSE,
  ];

  $spec['order_date_from'] = [
    'type' => CRM_Utils_Type::T_STRING,
    'name' => 'order_date_from',
    'title' => 'Order Date From',
  ];

  $spec['session_id'] = [
    'type' => CRM_Utils_Type::T_INT,
    'name' => 'session_id',
    'title' => 'Specific Session ID',
    'description' => 'Process only records for this session ID',
  ];

  $spec['progress_callback'] = [
    'type' => CRM_Utils_Type::T_STRING,
    'name' => 'progress_callback',
    'title' => 'Progress Callback URL',
    'description' => 'URL to send progress updates to',
  ];
}

/**
 * Enhanced Job.Ultracampbatchprocess API
 *
 * @param array $params
 * @return array API result descriptor
 * @throws API_Exception
 */
function civicrm_api3_job_Ultracampbatchprocess($params) {
  $processor = new CRM_Ultracampsync_BatchProcessor();
  return $processor->process($params);
}
