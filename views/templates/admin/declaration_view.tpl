{*
* 2007-2026 PrestaShop
*
* NOTICE OF LICENSE
*
* This source file is subject to the Academic Free License (AFL 3.0)
* that is bundled with this package in the file LICENSE.txt.
* It is also available through the world-wide-web at this URL:
* http://opensource.org/licenses/afl-3.0.php
* If you did not receive a copy of the license and are unable to
* obtain it through the world-wide-web, please send an email
* to license@prestashop.com so we can send you a copy immediately.
*
* DISCLAIMER
*
* Do not edit or add to this file if you wish to upgrade PrestaShop to newer
* versions in the future. If you wish to customize PrestaShop for your
* needs please refer to http://www.prestashop.com for more information.
*
*  @author    PrestaShop SA <contact@prestashop.com>
*  @copyright 2007-2026 PrestaShop SA
*  @license   http://opensource.org/licenses/afl-3.0.php  Academic Free License (AFL 3.0)
*  International Registered Trademark & Property of PrestaShop SA
*}

<div class="panel">
    <div class="panel-heading">
        <i class="icon-file-text"></i>
        {l s='Declaration' mod='snt_dji_compensation'} #{$declaration->reference|escape:'html':'UTF-8'}
    </div>

    <div class="row">
        <div class="col-md-6">
            <h4>{l s='Informations client' mod='snt_dji_compensation'}</h4>
            <table class="table">
                <tr>
                    <td><strong>{l s='Nom' mod='snt_dji_compensation'}</strong></td>
                    <td>{$customer->firstname|escape:'html':'UTF-8'} {$customer->lastname|escape:'html':'UTF-8'}</td>
                </tr>
                <tr>
                    <td><strong>{l s='Email' mod='snt_dji_compensation'}</strong></td>
                    <td>{$customer->email|escape:'html':'UTF-8'}</td>
                </tr>
                <tr>
                    <td><strong>{l s='Societe' mod='snt_dji_compensation'}</strong></td>
                    <td>{$customer->company|escape:'html':'UTF-8'}</td>
                </tr>
            </table>
        </div>
        <div class="col-md-6">
            <h4>{l s='Declaration' mod='snt_dji_compensation'}</h4>
            <table class="table">
                <tr>
                    <td><strong>{l s='Date de creation' mod='snt_dji_compensation'}</strong></td>
                    <td>{$declaration->date_add|date_format:'%d/%m/%Y %H:%M:%S'}</td>
                </tr>
                <tr>
                    <td><strong>{l s='Derniere action' mod='snt_dji_compensation'}</strong></td>
                    <td>{$declaration->date_upd|date_format:'%d/%m/%Y %H:%M:%S'}</td>
                </tr>
                <tr>
                    <td><strong>{l s='Statut' mod='snt_dji_compensation'}</strong></td>
                    <td>
                        <select id="declaration-status" class="form-control" data-id="{$declaration->id}">
                            {foreach from=$statuses key=key item=label}
                                <option value="{$key}" {if $declaration->status == $key}selected{/if}>{$label}</option>
                            {/foreach}
                        </select>
                    </td>
                </tr>
                <tr>
                    <td><strong>{l s='Produits Mic/Power' mod='snt_dji_compensation'}</strong></td>
                    <td>{if $declaration->has_mic_power_products}{l s='Oui' mod='snt_dji_compensation'}{else}{l s='Non' mod='snt_dji_compensation'}{/if}</td>
                </tr>
            </table>
        </div>
    </div>
</div>

{* Fichier XLSX *}
<div class="panel">
    <div class="panel-heading">
        <i class="icon-file-excel-o"></i>
        {l s='Fichier de declaration' mod='snt_dji_compensation'}
    </div>
    {if $declaration->xlsx_s3_key}
        <p>
            <strong>{$declaration->xlsx_original_filename|escape:'html':'UTF-8'}</strong>
            <button type="button" class="btn btn-default btn-sm download-file" data-type="xlsx" data-id="{$declaration->id}">
                <i class="icon-download"></i> {l s='Telecharger' mod='snt_dji_compensation'}
            </button>
        </p>
    {else}
        <p class="text-muted">{l s='Aucun fichier' mod='snt_dji_compensation'}</p>
    {/if}
</div>

{* Factures *}
<div class="panel">
    <div class="panel-heading">
        <i class="icon-file-pdf-o"></i>
        {l s='Factures' mod='snt_dji_compensation'} ({$invoices|count})
    </div>
    {if $invoices|count > 0}
        <table class="table">
            <thead>
                <tr>
                    <th>{l s='Fichier' mod='snt_dji_compensation'}</th>
                    <th>{l s='Taille' mod='snt_dji_compensation'}</th>
                    <th>{l s='Date' mod='snt_dji_compensation'}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$invoices item=invoice}
                    <tr>
                        <td>{$invoice.original_filename|escape:'html':'UTF-8'}</td>
                        <td>{($invoice.file_size/1024)|string_format:"%.1f"} Ko</td>
                        <td>{$invoice.date_add|date_format:'%d/%m/%Y %H:%M'}</td>
                        <td>
                            <button type="button" class="btn btn-default btn-xs download-file" data-type="invoice" data-id="{$invoice.id_invoice}">
                                <i class="icon-download"></i>
                            </button>
                        </td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    {else}
        <p class="text-muted">{l s='Aucune facture' mod='snt_dji_compensation'}</p>
    {/if}
</div>

{* Photos de stock *}
{if $declaration->has_mic_power_products}
<div class="panel">
    <div class="panel-heading">
        <i class="icon-picture-o"></i>
        {l s='Photos de stock' mod='snt_dji_compensation'} ({$stock_proofs|count})
    </div>
    {if $stock_proofs|count > 0}
        <table class="table">
            <thead>
                <tr>
                    <th>{l s='Fichier' mod='snt_dji_compensation'}</th>
                    <th>{l s='Type' mod='snt_dji_compensation'}</th>
                    <th>{l s='Taille' mod='snt_dji_compensation'}</th>
                    <th></th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$stock_proofs item=proof}
                    <tr>
                        <td>{$proof.original_filename|escape:'html':'UTF-8'}</td>
                        <td><span class="label label-info">{if $proof.product_type == 'mic'}DJI Mic{else}DJI Power{/if}</span></td>
                        <td>{($proof.file_size/1024)|string_format:"%.1f"} Ko</td>
                        <td>
                            <button type="button" class="btn btn-default btn-xs download-file" data-type="stock_proof" data-id="{$proof.id_stock_proof}">
                                <i class="icon-download"></i>
                            </button>
                        </td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    {else}
        <p class="text-muted">{l s='Aucune photo' mod='snt_dji_compensation'}</p>
    {/if}
</div>
{/if}

{* Historique des actions *}
<div class="panel">
    <div class="panel-heading">
        <i class="icon-history"></i>
        {l s='Historique des actions' mod='snt_dji_compensation'} ({$history|count})
    </div>
    {if $history|count > 0}
        <table class="table">
            <thead>
                <tr>
                    <th>{l s='Date' mod='snt_dji_compensation'}</th>
                    <th>{l s='Auteur' mod='snt_dji_compensation'}</th>
                    <th>{l s='Action' mod='snt_dji_compensation'}</th>
                    <th>{l s='Details' mod='snt_dji_compensation'}</th>
                </tr>
            </thead>
            <tbody>
                {foreach from=$history item=event}
                    <tr>
                        <td><small>{$event.date_add|date_format:'%d/%m/%Y %H:%M:%S'}</small></td>
                        <td>
                            {if $event.actor_name}{$event.actor_name|escape:'html':'UTF-8'}{else}<span class="text-muted">-</span>{/if}
                            <br><span class="label label-default">{$event.actor_type_label|escape:'html':'UTF-8'}</span>
                        </td>
                        <td>{$event.action_label|escape:'html':'UTF-8'}</td>
                        <td>{if $event.details}<small>{$event.details|escape:'html':'UTF-8'}</small>{else}<span class="text-muted">-</span>{/if}</td>
                    </tr>
                {/foreach}
            </tbody>
        </table>
    {else}
        <p class="text-muted">{l s='Aucune action enregistree' mod='snt_dji_compensation'}</p>
    {/if}
</div>

{* Notes admin *}
<div class="panel">
    <div class="panel-heading">
        <i class="icon-comment"></i>
        {l s='Notes administrateur' mod='snt_dji_compensation'}
    </div>
    <textarea id="admin-notes" class="form-control" rows="4" data-id="{$declaration->id}" placeholder="{l s='Notes internes...' mod='snt_dji_compensation'}">{$declaration->admin_notes|escape:'html':'UTF-8'}</textarea>
    <p class="help-block">{l s='Sauvegarde automatique' mod='snt_dji_compensation'}</p>
</div>

<div class="panel-footer">
    <a href="{$back_link}" class="btn btn-default">
        <i class="process-icon-back"></i> {l s='Retour' mod='snt_dji_compensation'}
    </a>
</div>

<script>
var ajaxUrl = '{$ajax_url}';

$(document).ready(function() {
    // Changement de statut
    $('#declaration-status').on('change', function() {
        var status = $(this).val();
        var id = $(this).data('id');

        $.ajax({
            url: ajaxUrl + '&ajax=1&action=updateStatus',
            type: 'POST',
            data: { id_declaration: id, status: status },
            dataType: 'json',
            success: function(response) {
                if (response.success) {
                    showSuccessMessage(response.message);
                } else {
                    showErrorMessage(response.message);
                }
            }
        });
    });

    // Notes admin
    var notesTimer;
    $('#admin-notes').on('input', function() {
        var $this = $(this);
        clearTimeout(notesTimer);
        notesTimer = setTimeout(function() {
            $.ajax({
                url: ajaxUrl + '&ajax=1&action=updateNotes',
                type: 'POST',
                data: { id_declaration: $this.data('id'), notes: $this.val() },
                dataType: 'json',
                success: function(response) {
                    if (response.success) {
                        $this.css('border-color', '#28a745');
                        setTimeout(function() { $this.css('border-color', ''); }, 1500);
                    }
                }
            });
        }, 1000);
    });

    // Telechargement fichiers
    $('.download-file').off('click').on('click', function(e) {
        e.preventDefault();
        e.stopPropagation();

        var $btn = $(this);
        if ($btn.data('loading')) return;
        $btn.data('loading', true);

        var type = $btn.data('type');
        var id = $btn.data('id');

        $.ajax({
            url: ajaxUrl + '&ajax=1&action=getFileUrl',
            type: 'POST',
            data: { type: type, id: id },
            dataType: 'json',
            success: function(response) {
                if (response.success && response.url) {
                    window.location.href = response.url;
                } else {
                    showErrorMessage(response.message || 'Erreur');
                }
            },
            complete: function() {
                $btn.data('loading', false);
            }
        });
    });
});
</script>
