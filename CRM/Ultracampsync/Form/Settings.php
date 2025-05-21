<?php

use CRM_Ultracampsync_ExtensionUtil as E;

/**
 * Form controller class
 *
 * @see https://docs.civicrm.org/dev/en/latest/framework/quickform/
 */
class CRM_Ultracampsync_Form_Settings extends CRM_Core_Form {

  /**
   * @throws \CRM_Core_Exception
   */
  public function buildQuickForm(): void {
    // Set the form title
    $this->setTitle(E::ts('UltraCamp Sync Settings'));

    // API credentials section
    $this->add('text', 'camp_id', E::ts('Camp ID'), ['class' => 'huge'], TRUE);

    $this->add('text', 'camp_api_key', E::ts('Camp API Key'), ['class' => 'huge'], TRUE);

    // Get custom fields for events that could store the UltraCamp session ID
    $eventCustomFields = $this->getEventCustomFields('Event');
    $contactCustomFields = $this->getEventCustomFields('Contact');
    $participantCustomFields = $this->getEventCustomFields('Participant');
    $relationshiptCustomFields = $this->getEventCustomFields('Relationship');
    $this->add('select', 'session_id_field', E::ts('UltraCamp Session ID Field'), $eventCustomFields, FALSE);
    $this->add('select', 'person_id_field', E::ts('UltraCamp Person ID Field'), $contactCustomFields, FALSE);
    $this->add('select', 'account_id_field', E::ts('UltraCamp Account ID Field'), $contactCustomFields, FALSE);
    $this->add('select', 'reservation_id_field', E::ts('UltraCamp Reservation ID Field'), $participantCustomFields, FALSE);
    $this->add('select', 'relationship_id_field', E::ts('UltraCamp Relationship Custom Field'), $relationshiptCustomFields, FALSE);

    $this->addButtons([
      [
        'type' => 'submit',
        'name' => E::ts('Submit'),
        'isDefault' => TRUE,
      ],
    ]);

    // export form elements
    $this->assign('elementNames', $this->getRenderableElementNames());

    parent::buildQuickForm();
  }

  /**
   * Set default values
   *
   * @return array Default values
   */
  public function setDefaultValues() {
    $defaults = [];

    $defaults['camp_id'] = Civi::settings()->get('ultracampsync_camp_id');
    $defaults['camp_api_key'] = Civi::settings()->get('ultracampsync_camp_api_key');
    $defaults['session_id_field'] = Civi::settings()->get('ultracampsync_session_id_field');

    $defaults['person_id_field'] = Civi::settings()->get('ultracampsync_person_id_field');
    $defaults['account_id_field'] = Civi::settings()->get('ultracampsync_account_id_field');
    $defaults['reservation_id_field'] = Civi::settings()->get('ultracampsync_reservation_id_field');
    $defaults['relationship_id_field'] = Civi::settings()->get('ultracampsync_relationship_id_field');

    return $defaults;
  }

  public function postProcess(): void {
    $values = $this->exportValues();

    // Save settings
    Civi::settings()->set('ultracampsync_camp_id', $values['camp_id']);
    Civi::settings()->set('ultracampsync_camp_api_key', $values['camp_api_key']);
    Civi::settings()->set('ultracampsync_session_id_field', $values['session_id_field']);
    Civi::settings()->set('ultracampsync_person_id_field', $values['person_id_field']);
    Civi::settings()->set('ultracampsync_account_id_field', $values['account_id_field']);
    Civi::settings()->set('ultracampsync_reservation_id_field', $values['reservation_id_field']);
    Civi::settings()->set('ultracampsync_relationship_id_field', $values['relationship_id_field']);

    CRM_Core_Session::setStatus(E::ts('Settings saved successfully.'), E::ts('Settings Saved'), 'success');

    parent::postProcess();
  }

  /**
   * Get the fields/elements defined in this form.
   *
   * @return array (string)
   */
  public function getRenderableElementNames(): array {
    // The _elements list includes some items which should not be
    // auto-rendered in the loop -- such as "qfKey" and "buttons".  These
    // items don't have labels.  We'll identify renderable by filtering on
    // the 'label'.
    $elementNames = [];
    foreach ($this->_elements as $element) {
      /** @var HTML_QuickForm_Element $element */
      $label = $element->getLabel();
      if (!empty($label)) {
        $elementNames[] = $element->getName();
      }
    }
    return $elementNames;
  }


  /**
   * Get custom fields for events
   *
   * @return array Array of custom fields
   */
  protected function getEventCustomFields($entity = 'Event'): array {
    $customFields = ['' => '- Select -'];

    try {
      // Get custom groups for events
      $customGroups = civicrm_api3('CustomGroup', 'get', [
        'extends' => $entity,
        'is_active' => 1,
      ]);

      if ($customGroups['count'] > 0) {
        foreach ($customGroups['values'] as $group) {
          // Get custom fields for each group
          $fields = civicrm_api3('CustomField', 'get', [
            'custom_group_id' => $group['id'],
            'is_active' => 1,
            'data_type' => ['IN' => ['String', 'Int']],
          ]);

          if ($fields['count'] > 0) {
            foreach ($fields['values'] as $field) {
              $customFields[$field['id']] = $group['title'] . ': ' . $field['label'];
            }
          }
        }
      }
    }
    catch (CiviCRM_API3_Exception $e) {
      CRM_Core_Error::debug_log_message('Error getting custom fields: ' . $e->getMessage());
    }

    return $customFields;
  }

}
