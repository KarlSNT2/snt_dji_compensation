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

{extends file='page.tpl'}

{block name='page_title'}
    {l s='Declaration' mod='snt_dji_compensation'} {$declaration->reference|escape:'html':'UTF-8'}
{/block}

{block name='page_content'}
    <div class="dji-compensation-declaration-view">

        {* En-tete avec informations principales *}
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h5 class="mb-0">
                    <i class="material-icons">description</i>
                    {l s='Declaration' mod='snt_dji_compensation'} <strong>{$declaration->reference|escape:'html':'UTF-8'}</strong>
                </h5>
                <span class="badge badge-{$declaration_status_class} badge-lg">{$declaration_status_label}</span>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <p><strong>{l s='Date de creation :' mod='snt_dji_compensation'}</strong> {$declaration->date_add|date_format:'%d/%m/%Y a %H:%M'}</p>
                        <p><strong>{l s='Derniere modification :' mod='snt_dji_compensation'}</strong> {$declaration->date_upd|date_format:'%d/%m/%Y a %H:%M'}</p>
                    </div>
                    <div class="col-md-6">
                        <p><strong>{l s='Produits DJI Mic/Power :' mod='snt_dji_compensation'}</strong>
                            {if $declaration->has_mic_power_products}
                                <span class="text-success">{l s='Oui' mod='snt_dji_compensation'}</span>
                            {else}
                                <span class="text-muted">{l s='Non' mod='snt_dji_compensation'}</span>
                            {/if}
                        </p>
                    </div>
                </div>

                {if $declaration->admin_notes}
                    <div class="alert alert-info mt-3">
                        <strong><i class="material-icons" style="font-size:18px;vertical-align:middle;">info</i> {l s='Notes de l\'administrateur :' mod='snt_dji_compensation'}</strong>
                        <p class="mb-0 mt-2">{$declaration->admin_notes|escape:'html':'UTF-8'|nl2br}</p>
                    </div>
                {/if}
            </div>
        </div>

        {* Fichier XLSX *}
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0">
                    <i class="material-icons">description</i>
                    {l s='Fichier de declaration' mod='snt_dji_compensation'}
                </h6>
            </div>
            <div class="card-body">
                {if $declaration->xlsx_original_filename && $xlsx_url}
                    <div class="d-flex justify-content-between align-items-center p-3 bg-light rounded">
                        <div>
                            <i class="material-icons text-success" style="font-size:24px;vertical-align:middle;">insert_drive_file</i>
                            <span class="ml-2">{$declaration->xlsx_original_filename|escape:'html':'UTF-8'}</span>
                        </div>
                        <a href="{$xlsx_url}" class="btn btn-success" target="_blank" download>
                            <i class="material-icons" style="font-size:18px;vertical-align:middle;">download</i>
                            {l s='Telecharger' mod='snt_dji_compensation'}
                        </a>
                    </div>
                {else}
                    <p class="text-muted mb-0">{l s='Aucun fichier XLSX' mod='snt_dji_compensation'}</p>
                {/if}
            </div>
        </div>

        {* Factures PDF *}
        <div class="card mb-4">
            <div class="card-header">
                <h6 class="mb-0">
                    <i class="material-icons">picture_as_pdf</i>
                    {l s='Factures' mod='snt_dji_compensation'}
                    <span class="badge badge-secondary ml-2">{$invoices|count}</span>
                </h6>
            </div>
            <div class="card-body">
                {if $invoices|count > 0}
                    <div class="list-group">
                        {foreach from=$invoices item=invoice}
                            <div class="list-group-item d-flex justify-content-between align-items-center">
                                <div>
                                    <i class="material-icons text-danger" style="font-size:20px;vertical-align:middle;">picture_as_pdf</i>
                                    <span class="ml-2">{$invoice.original_filename|escape:'html':'UTF-8'}</span>
                                    <small class="text-muted ml-2">({($invoice.file_size/1024)|string_format:"%.0f"} Ko)</small>
                                </div>
                                <a href="{$invoice.presigned_url}" class="btn btn-sm btn-outline-primary" target="_blank" download>
                                    <i class="material-icons" style="font-size:16px;">download</i>
                                </a>
                            </div>
                        {/foreach}
                    </div>
                {else}
                    <p class="text-muted mb-0">{l s='Aucune facture' mod='snt_dji_compensation'}</p>
                {/if}
            </div>
        </div>

        {* Photos de stock *}
        {if $declaration->has_mic_power_products}
            <div class="card mb-4">
                <div class="card-header">
                    <h6 class="mb-0">
                        <i class="material-icons">photo_library</i>
                        {l s='Photos de stock (DJI Mic/Power)' mod='snt_dji_compensation'}
                        <span class="badge badge-secondary ml-2">{$stock_proofs|count}</span>
                    </h6>
                </div>
                <div class="card-body">
                    {if $stock_proofs|count > 0}
                        <div class="row">
                            {foreach from=$stock_proofs item=proof}
                                <div class="col-md-4 col-sm-6 mb-3">
                                    <div class="card h-100">
                                        <div class="card-img-top bg-light text-center p-3" style="height:150px;overflow:hidden;">
                                            <img src="{$proof.presigned_url}" alt="{$proof.original_filename|escape:'html':'UTF-8'}" style="max-height:100%;max-width:100%;object-fit:contain;">
                                        </div>
                                        <div class="card-body p-2">
                                            <p class="card-text small mb-1 text-truncate" title="{$proof.original_filename|escape:'html':'UTF-8'}">
                                                {$proof.original_filename|escape:'html':'UTF-8'}
                                            </p>
                                            <div class="d-flex justify-content-between align-items-center">
                                                <span class="badge badge-info">{if $proof.product_type == 'mic'}Mic{else}Power{/if}</span>
                                                <a href="{$proof.presigned_url}" class="btn btn-sm btn-outline-primary" target="_blank" download>
                                                    <i class="material-icons" style="font-size:14px;">download</i>
                                                </a>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            {/foreach}
                        </div>
                    {else}
                        <p class="text-muted mb-0">{l s='Aucune photo de stock' mod='snt_dji_compensation'}</p>
                    {/if}
                </div>
            </div>
        {/if}

        {* Boutons de navigation *}
        <div class="form-actions d-flex justify-content-between flex-wrap">
            <a href="{$declarations_link}" class="btn btn-outline-secondary mb-2">
                <i class="material-icons">arrow_back</i>
                {l s='Retour aux declarations' mod='snt_dji_compensation'}
            </a>

            {if $declaration->isEditable()}
                <a href="{$link->getModuleLink('snt_dji_compensation', 'declaration', ['edit' => $declaration->id])}" class="btn btn-primary mb-2">
                    <i class="material-icons">edit</i>
                    {l s='Modifier cette declaration' mod='snt_dji_compensation'}
                </a>
            {/if}
        </div>
    </div>
{/block}
