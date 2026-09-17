<form method="post" action="{$frisbo_awb_action|escape:'html':'UTF-8'}" target="_blank" style="display:inline-block">
  <input type="hidden" name="id_order" value="{$frisbo_awb_order_id|intval}">
  <button type="submit" name="submitFrisboAwb" value="1" class="btn btn-default">
    <i class="icon-file-pdf-o"></i> {l s='AWB' mod='frisbo_awb'}
  </button>
</form>
