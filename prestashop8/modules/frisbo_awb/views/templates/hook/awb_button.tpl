<button type="button" class="btn btn-default" data-toggle="modal" data-target="#{$frisbo_awb_modal_id|escape:'html':'UTF-8'}">
  <i class="icon-file-pdf-o"></i> {l s='AWB' mod='frisbo_awb'}
</button>

<div class="modal fade" id="{$frisbo_awb_modal_id|escape:'html':'UTF-8'}" tabindex="-1" role="dialog" aria-labelledby="{$frisbo_awb_modal_id|escape:'html':'UTF-8'}-title">
  <div class="modal-dialog" role="document">
    <div class="modal-content">
      <div class="modal-header" style="position:relative">
        <button type="button" class="close" data-dismiss="modal" aria-label="{l s='Close' mod='frisbo_awb'}" style="position:absolute; right:15px; top:15px"><span aria-hidden="true">&times;</span></button>
        <h4 class="modal-title" id="{$frisbo_awb_modal_id|escape:'html':'UTF-8'}-title">{l s='Frisbo AWB' mod='frisbo_awb'}</h4>
      </div>

      {if $frisbo_awb_generated}
        <div class="modal-body">
          <p>{l s='An AWB has already been generated for this order.' mod='frisbo_awb'}</p>
          {if $frisbo_awb_tracking_number}
            <p><strong>{l s='Tracking number:' mod='frisbo_awb'}</strong>
              {if $frisbo_awb_tracking_url}<a href="{$frisbo_awb_tracking_url|escape:'html':'UTF-8'}" target="_blank" rel="noopener noreferrer">{$frisbo_awb_tracking_number|escape:'html':'UTF-8'}</a>{else}{$frisbo_awb_tracking_number|escape:'html':'UTF-8'}{/if}
            </p>
          {/if}
        </div>
        <div class="modal-footer">
          <form method="post" action="{$frisbo_awb_action|escape:'html':'UTF-8'}" class="frisbo-awb-cancel-form" style="display:inline-block">
            <input type="hidden" name="id_order" value="{$frisbo_awb_order_id|intval}">
            <input type="hidden" name="frisbo_awb_operation" value="cancel">
            <input type="hidden" name="frisbo_awb_ajax" value="1">
            <input type="hidden" name="submitFrisboAwb" value="1">
            <button type="submit" name="submitFrisboAwb" value="1" class="btn btn-danger btn-lg">
              <i class="icon-times"></i> {l s='Cancel AWB' mod='frisbo_awb'}
            </button>
          </form>
          <form method="post" action="{$frisbo_awb_action|escape:'html':'UTF-8'}" target="_blank" style="display:inline-block">
            <input type="hidden" name="id_order" value="{$frisbo_awb_order_id|intval}">
            <input type="hidden" name="frisbo_awb_operation" value="download">
            <button type="submit" name="submitFrisboAwb" value="1" class="btn btn-primary btn-lg">
              <i class="icon-download"></i> {l s='Download' mod='frisbo_awb'}
            </button>
          </form>
        </div>
      {elseif $frisbo_awb_cancelled}
        <form method="post" action="{$frisbo_awb_action|escape:'html':'UTF-8'}" target="_blank" class="frisbo-awb-generate-form">
          <input type="hidden" name="id_order" value="{$frisbo_awb_order_id|intval}">
          <input type="hidden" name="frisbo_awb_operation" value="generate">
          <div class="modal-body">
            <p>{l s='The previous AWB was cancelled. Choose a courier to generate a new AWB.' mod='frisbo_awb'}</p>
            <div class="frisbo-awb-couriers">
              <label class="btn btn-default btn-lg btn-block active" style="text-align:left; margin-bottom:8px">
                <input type="radio" name="frisbo_awb_courier_id" value="" checked="checked"> <strong>{l s='Auto: lowest cost' mod='frisbo_awb'}</strong>
              </label>
              {foreach from=$frisbo_awb_couriers item=courier}
                <label class="btn btn-default btn-lg btn-block" style="text-align:left; margin-bottom:8px">
                  <input type="radio" name="frisbo_awb_courier_id" value="{$courier.backend_carrier_id|escape:'html':'UTF-8'}">
                  {$courier.backend_name|default:$courier.backend_carrier_id|escape:'html':'UTF-8'}
                </label>
              {/foreach}
            </div>
          </div>
          <div class="modal-footer">
            <button type="submit" name="submitFrisboAwb" value="1" class="btn btn-primary btn-lg btn-block">
              <i class="icon-magic"></i> {l s='Generate new AWB' mod='frisbo_awb'}
            </button>
          </div>
        </form>
      {else}
        <form method="post" action="{$frisbo_awb_action|escape:'html':'UTF-8'}" target="_blank" class="frisbo-awb-generate-form">
          <input type="hidden" name="id_order" value="{$frisbo_awb_order_id|intval}">
          <input type="hidden" name="frisbo_awb_operation" value="generate">
          <div class="modal-body">
            <p>{l s='Choose how Frisbo should select the courier.' mod='frisbo_awb'}</p>
            <div class="frisbo-awb-couriers">
              {if $frisbo_awb_chosen_courier}
                <label class="btn btn-default btn-lg btn-block active" style="text-align:left; margin-bottom:8px">
                  <input type="radio" name="frisbo_awb_courier_id" value="{$frisbo_awb_chosen_courier.backend_carrier_id|escape:'html':'UTF-8'}" checked="checked">
                  <strong>{l s='Chosen by customer:' mod='frisbo_awb'}</strong> {$frisbo_awb_chosen_courier.name|escape:'html':'UTF-8'}
                </label>
              {/if}
              <label class="btn btn-default btn-lg btn-block{if !$frisbo_awb_chosen_courier} active{/if}" style="text-align:left; margin-bottom:8px">
                <input type="radio" name="frisbo_awb_courier_id" value=""{if !$frisbo_awb_chosen_courier} checked="checked"{/if}> <strong>{l s='Auto: lowest cost' mod='frisbo_awb'}</strong>
              </label>
              {foreach from=$frisbo_awb_couriers item=courier}
                <label class="btn btn-default btn-lg btn-block" style="text-align:left; margin-bottom:8px">
                  <input type="radio" name="frisbo_awb_courier_id" value="{$courier.backend_carrier_id|escape:'html':'UTF-8'}">
                  {$courier.backend_name|default:$courier.backend_carrier_id|escape:'html':'UTF-8'}
                </label>
              {/foreach}
            </div>
          </div>
          <div class="modal-footer">
            <button type="submit" name="submitFrisboAwb" value="1" class="btn btn-primary btn-lg btn-block">
              <i class="icon-magic"></i> {l s='Generate' mod='frisbo_awb'}
            </button>
          </div>
        </form>
      {/if}
    </div>
  </div>
</div>

<script>
  (function () {
    var modal = document.getElementById('{$frisbo_awb_modal_id|escape:'javascript':'UTF-8'}');
    if (!modal) return;
    function hideModal() {
      if (window.jQuery && window.jQuery.fn && window.jQuery.fn.modal) {
        window.jQuery(modal).modal('hide');
      } else {
        modal.style.display = 'none';
      }
    }
    function pollShipmentStatus() {
      var attempts = 0;
      function poll() {
        if (++attempts > 120) return;
        var statusRequest = new XMLHttpRequest();
        var statusData = new FormData();
        statusData.append('id_order', '{$frisbo_awb_order_id|intval}');
        statusData.append('frisbo_awb_operation', 'status');
        statusData.append('frisbo_awb_ajax', '1');
        statusData.append('submitFrisboAwb', '1');
        statusRequest.open('POST', '{$frisbo_awb_action|escape:'javascript':'UTF-8'}', true);
        statusRequest.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
        statusRequest.onload = function () {
          var response = {};
          try { response = JSON.parse(statusRequest.responseText); } catch (ignore) {}
          if (statusRequest.status >= 200 && statusRequest.status < 300 && response.success) {
            if (response.status === 'generated' || response.status === 'failed' || response.status === 'uncertain') {
              window.location.reload();
              return;
            }
          }
          window.setTimeout(poll, 1000);
        };
        statusRequest.onerror = function () { window.setTimeout(poll, 1000); };
        statusRequest.send(statusData);
      }
      window.setTimeout(poll, 500);
    }
    var generateForms = modal.querySelectorAll('.frisbo-awb-generate-form');
    for (var formIndex = 0; formIndex < generateForms.length; formIndex++) {
      generateForms[formIndex].addEventListener('submit', function () {
        hideModal();
        pollShipmentStatus();
      });
    }
    var radios = modal.querySelectorAll('input[name="frisbo_awb_courier_id"]');
    for (var i = 0; i < radios.length; i++) {
      radios[i].addEventListener('change', function () {
        for (var j = 0; j < radios.length; j++) {
          var label = radios[j].parentNode;
          if (radios[j].checked) label.classList.add('active');
          else label.classList.remove('active');
        }
      });
    }
    var cancelForm = modal.querySelector('.frisbo-awb-cancel-form');
    if (cancelForm) cancelForm.addEventListener('submit', function (event) {
      event.preventDefault();
      if (!window.confirm('Cancel this AWB?')) return;
      var button = cancelForm.querySelector('button[type="submit"]');
      button.disabled = true;
      var request = new XMLHttpRequest();
      request.open('POST', cancelForm.action, true);
      request.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
      request.onload = function () {
        var response = {};
        try { response = JSON.parse(request.responseText); } catch (ignore) {}
        if (request.status >= 200 && request.status < 300 && response.success) {
          hideModal();
          window.location.reload();
        }
        else {
          button.disabled = false;
          window.alert(response.message || 'Could not cancel the AWB.');
        }
      };
      request.onerror = function () {
        button.disabled = false;
        window.alert('Could not cancel the AWB.');
      };
      request.send(new FormData(cancelForm));
    });
  }());
</script>
