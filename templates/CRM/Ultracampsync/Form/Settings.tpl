<div class="crm-block crm-form-block crm-ultracampsync-settings-form-block">
  <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="top"}
  </div>

  <h3>{ts}UltraCamp API Credentials{/ts}</h3>
  <div class="crm-section">
    <div class="label">{$form.camp_id.label}</div>
    <div class="content">{$form.camp_id.html}
      <div class="description">{ts}Your UltraCamp Camp ID{/ts}</div>
    </div>
    <div class="clear"></div>
  </div>

  <div class="crm-section">
    <div class="label">{$form.camp_api_key.label}</div>
    <div class="content">{$form.camp_api_key.html}
      <div class="description">{ts}Your UltraCamp Camp API Key{/ts}</div>
    </div>
    <div class="clear"></div>
  </div>

  <div class="crm-section">
    <div class="label">{$form.session_id_field.label}</div>
    <div class="content">{$form.session_id_field.html}
      <div class="description">{ts}Custom Field for session id in event.{/ts}</div>
    </div>
    <div class="clear"></div>
  </div>

  <div class="crm-section">
    <div class="label">{$form.person_id_field.label}</div>
    <div class="content">{$form.person_id_field.html}
      <div class="description">{ts}Custom Field for Person ID.{/ts}</div>
    </div>
    <div class="clear"></div>
  </div>

  <div class="crm-section">
    <div class="label">{$form.account_id_field.label}</div>
    <div class="content">{$form.account_id_field.html}
      <div class="description">{ts}Custom Field for Account ID.{/ts}</div>
    </div>
    <div class="clear"></div>
  </div>

  <div class="crm-section">
    <div class="label">{$form.reservation_id_field.label}</div>
    <div class="content">{$form.reservation_id_field.html}
      <div class="description">{ts}Custom Field for Reservation ID.{/ts}</div>
    </div>
    <div class="clear"></div>
  </div>


  <div class="crm-submit-buttons">
    {include file="CRM/common/formButtons.tpl" location="bottom"}
  </div>
</div>
