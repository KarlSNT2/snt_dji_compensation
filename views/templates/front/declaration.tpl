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
    {l s='Compensation DJI' mod='snt_dji_compensation'}
{/block}

{block name='page_content'}
    <div class="dji-compensation-declaration">

        {* Telechargement du template *}
        <div class="card mb-4">
            <div class="card-body">
                <div class="row align-items-center">
                    <div class="col-md-8">
                        <h5>{l s='Fichier de declaration DJI' mod='snt_dji_compensation'}</h5>
                        <p class="mb-0">{l s='Telechargez le fichier Excel, remplissez vos ventes, puis deposez-le ci-dessous avec vos factures.' mod='snt_dji_compensation'}</p>
                    </div>
                    <div class="col-md-4 text-md-right mt-3 mt-md-0">
                        <a href="{$download_link}" class="btn btn-success btn-lg">
                            <i class="material-icons">download</i>
                            {l s='Telecharger le template' mod='snt_dji_compensation'}
                        </a>
                    </div>
                </div>
            </div>
        </div>

        {* Historique des declarations *}
        {if $declaration_history|count > 0}
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="mb-0">
                        <i class="material-icons">history</i>
                        {l s='Vos declarations' mod='snt_dji_compensation'}
                        <span class="badge badge-secondary">{$declaration_history|count}</span>
                    </h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-striped mb-0">
                            <thead>
                                <tr>
                                    <th>{l s='Reference' mod='snt_dji_compensation'}</th>
                                    <th>{l s='Date' mod='snt_dji_compensation'}</th>
                                    <th>{l s='Fichier' mod='snt_dji_compensation'}</th>
                                    <th>{l s='Statut' mod='snt_dji_compensation'}</th>
                                    <th>{l s='Actions' mod='snt_dji_compensation'}</th>
                                </tr>
                            </thead>
                            <tbody>
                                {foreach from=$declaration_history item=history}
                                    <tr>
                                        <td><strong>{$history.reference|escape:'html':'UTF-8'}</strong></td>
                                        <td>{$history.date_add|date_format:'%d/%m/%Y %H:%M'}</td>
                                        <td>
                                            {if $history.xlsx_original_filename}
                                                <small>{$history.xlsx_original_filename|escape:'html':'UTF-8'}</small>
                                            {else}
                                                <span class="text-muted">-</span>
                                            {/if}
                                        </td>
                                        <td>
                                            <span class="badge badge-{$history.status_class}">{$history.status_label}</span>
                                        </td>
                                        <td>
                                            <a href="{$link->getModuleLink('snt_dji_compensation', 'declaration', ['view' => $history.id_declaration])}" class="btn btn-sm btn-outline-secondary" title="{l s='Consulter' mod='snt_dji_compensation'}">
                                                <i class="material-icons" style="font-size:16px;">visibility</i>
                                            </a>
                                            {if $history.status == 'draft' || $history.status == 'submitted' || $history.status == 'processing'}
                                                <a href="{$link->getModuleLink('snt_dji_compensation', 'declaration', ['edit' => $history.id_declaration])}" class="btn btn-sm btn-outline-primary" title="{l s='Modifier' mod='snt_dji_compensation'}">
                                                    <i class="material-icons" style="font-size:16px;">edit</i>
                                                </a>
                                            {/if}
                                        </td>
                                    </tr>
                                {/foreach}
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        {/if}

        {* Formulaire de declaration *}
        <div class="card mb-4 border-primary">
            <div class="card-header bg-primary text-white">
                <h5 class="mb-0">
                    {if $declaration}
                        <i class="material-icons">edit</i>
                        {if $declaration->status == 'draft'}
                            {l s='Brouillon en cours' mod='snt_dji_compensation'}
                        {else}
                            {l s='Modification de declaration' mod='snt_dji_compensation'}
                        {/if}
                        - {$declaration->reference|escape:'html':'UTF-8'}
                    {else}
                        <i class="material-icons">add_circle</i>
                        {l s='Nouvelle declaration' mod='snt_dji_compensation'}
                    {/if}
                </h5>
            </div>
            <div class="card-body">
                <form method="post" enctype="multipart/form-data" id="declaration-form">
                    <input type="hidden" name="token" value="{$token}">

                    {* Etape 1 : Fichier XLSX *}
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <span class="step-number">1</span>
                                {l s='Fichier de declaration rempli' mod='snt_dji_compensation'}
                            </h6>
                        </div>
                        <div class="card-body">
                            {if $declaration && $declaration->xlsx_original_filename}
                                <div class="alert alert-success d-flex justify-content-between align-items-center">
                                    <span>
                                        <strong>{l s='Fichier actuel :' mod='snt_dji_compensation'}</strong>
                                        {$declaration->xlsx_original_filename|escape:'html':'UTF-8'}
                                    </span>
                                    {if $xlsx_url}
                                        <a href="{$xlsx_url}" class="btn btn-sm btn-success" target="_blank" title="{l s='Telecharger' mod='snt_dji_compensation'}">
                                            <i class="material-icons" style="font-size:16px;vertical-align:middle;">download</i>
                                            {l s='Telecharger' mod='snt_dji_compensation'}
                                        </a>
                                    {/if}
                                </div>
                            {/if}

                            {if $xlsx_editable}
                                <div class="dropzone-container" id="xlsx-dropzone">
                                    <input type="file" id="xlsx_file" name="xlsx_file" accept=".xlsx" class="dropzone-input">
                                    <div class="dropzone-content">
                                        <i class="material-icons">description</i>
                                        <p>{l s='Glissez votre fichier XLSX ici' mod='snt_dji_compensation'}</p>
                                        <span>{l s='ou cliquez pour parcourir' mod='snt_dji_compensation'}</span>
                                    </div>
                                    <div class="dropzone-preview" id="xlsx-preview"></div>
                                </div>
                                <small class="form-text text-muted mt-2">
                                    {l s='Format : XLSX. Taille max :' mod='snt_dji_compensation'} {$max_file_size} Mo
                                </small>
                            {else}
                                <div class="alert alert-info mb-0">
                                    <i class="material-icons" style="font-size:18px;vertical-align:middle;">lock</i>
                                    {l s='Le fichier de declaration ne peut plus etre modifie apres soumission. Vous pouvez uniquement ajouter ou supprimer des factures et photos.' mod='snt_dji_compensation'}
                                </div>
                            {/if}
                        </div>
                    </div>

                    {* Etape 2 : Factures PDF *}
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <span class="step-number">2</span>
                                {l s='Factures clients (preuves)' mod='snt_dji_compensation'}
                            </h6>
                        </div>
                        <div class="card-body">
                            {if $invoices|count > 0}
                                <div class="existing-files mb-3" id="invoices-list">
                                    <strong>{l s='Factures deja envoyees :' mod='snt_dji_compensation'} (<span class="invoices-count">{$invoices|count}</span>)</strong>
                                    <ul class="list-group mt-2">
                                        {foreach from=$invoices item=invoice}
                                            <li class="list-group-item d-flex justify-content-between align-items-center py-2" data-invoice-id="{$invoice.id_invoice}">
                                                <span>
                                                    <i class="material-icons text-danger" style="font-size:18px;vertical-align:middle;">picture_as_pdf</i>
                                                    {$invoice.original_filename|escape:'html':'UTF-8'}
                                                </span>
                                                <span>
                                                    <span class="badge badge-secondary mr-2">{($invoice.file_size/1024)|string_format:"%.0f"} Ko</span>
                                                    {if $invoice.presigned_url}
                                                        <a href="{$invoice.presigned_url}" class="btn btn-sm btn-outline-primary mr-1" target="_blank" title="{l s='Telecharger' mod='snt_dji_compensation'}">
                                                            <i class="material-icons" style="font-size:16px;">download</i>
                                                        </a>
                                                    {/if}
                                                    <button type="button" class="btn btn-sm btn-outline-danger delete-invoice-btn" data-id="{$invoice.id_invoice}" title="{l s='Supprimer' mod='snt_dji_compensation'}">
                                                        <i class="material-icons" style="font-size:16px;">delete</i>
                                                    </button>
                                                </span>
                                            </li>
                                        {/foreach}
                                    </ul>
                                </div>
                            {/if}

                            <div class="dropzone-container dropzone-multiple" id="invoices-dropzone">
                                <input type="file" id="invoices" name="invoices[]" accept=".pdf" multiple class="dropzone-input">
                                <div class="dropzone-content">
                                    <i class="material-icons">picture_as_pdf</i>
                                    <p>{l s='Glissez vos factures PDF ici' mod='snt_dji_compensation'}</p>
                                    <span>{l s='ou cliquez pour parcourir (plusieurs fichiers possibles)' mod='snt_dji_compensation'}</span>
                                </div>
                                <div class="dropzone-preview" id="invoices-preview"></div>
                            </div>
                            <small class="form-text text-muted mt-2">
                                {l s='Format : PDF. Max' mod='snt_dji_compensation'} {$max_invoices} {l s='fichiers,' mod='snt_dji_compensation'} {$max_file_size} Mo/fichier
                            </small>
                        </div>
                    </div>

                    {* Etape 3 : Photos de stock *}
                    <div class="card mb-4">
                        <div class="card-header">
                            <h6 class="mb-0">
                                <span class="step-number">3</span>
                                {l s='Photos de stock (DJI Mic / Power)' mod='snt_dji_compensation'}
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="form-group">
                                <div class="custom-control custom-checkbox">
                                    <input type="checkbox" class="custom-control-input" id="has_mic_power_products" name="has_mic_power_products" value="1" {if $declaration && $declaration->has_mic_power_products}checked{/if}>
                                    <label class="custom-control-label" for="has_mic_power_products">
                                        {l s='Ma declaration contient des produits DJI Mic ou DJI Power' mod='snt_dji_compensation'}
                                    </label>
                                </div>
                            </div>

                            <div id="stock-proofs-section" style="{if !$declaration || !$declaration->has_mic_power_products}display:none;{/if}">
                                <div class="alert alert-info">
                                    <i class="material-icons" style="font-size:18px;vertical-align:middle;">info</i>
                                    {l s='Pour DJI Mic et Power, fournissez des photos de votre stock restant.' mod='snt_dji_compensation'}
                                </div>

                                {if $stock_proofs|count > 0}
                                    <div class="existing-files mb-3" id="stock-proofs-list">
                                        <strong>{l s='Photos deja envoyees :' mod='snt_dji_compensation'} (<span class="stock-proofs-count">{$stock_proofs|count}</span>)</strong>
                                        <ul class="list-group mt-2">
                                            {foreach from=$stock_proofs item=proof}
                                                <li class="list-group-item d-flex justify-content-between align-items-center py-2" data-stock-proof-id="{$proof.id_stock_proof}">
                                                    <span>
                                                        <i class="material-icons text-primary" style="font-size:18px;vertical-align:middle;">image</i>
                                                        {$proof.original_filename|escape:'html':'UTF-8'}
                                                        <span class="badge badge-info ml-1">{if $proof.product_type == 'mic'}Mic{else}Power{/if}</span>
                                                    </span>
                                                    <span>
                                                        <span class="badge badge-secondary mr-2">{($proof.file_size/1024)|string_format:"%.0f"} Ko</span>
                                                        {if $proof.presigned_url}
                                                            <a href="{$proof.presigned_url}" class="btn btn-sm btn-outline-primary mr-1" target="_blank" title="{l s='Telecharger' mod='snt_dji_compensation'}">
                                                                <i class="material-icons" style="font-size:16px;">download</i>
                                                            </a>
                                                        {/if}
                                                        <button type="button" class="btn btn-sm btn-outline-danger delete-stock-proof-btn" data-id="{$proof.id_stock_proof}" title="{l s='Supprimer' mod='snt_dji_compensation'}">
                                                            <i class="material-icons" style="font-size:16px;">delete</i>
                                                        </button>
                                                    </span>
                                                </li>
                                            {/foreach}
                                        </ul>
                                    </div>
                                {/if}

                                <div class="dropzone-container dropzone-multiple" id="stock-proofs-dropzone">
                                    <input type="file" id="stock_proofs" name="stock_proofs[]" accept="image/*" multiple class="dropzone-input">
                                    <div class="dropzone-content">
                                        <i class="material-icons">add_photo_alternate</i>
                                        <p>{l s='Glissez vos photos ici' mod='snt_dji_compensation'}</p>
                                        <span>{l s='ou cliquez pour parcourir (plusieurs fichiers possibles)' mod='snt_dji_compensation'}</span>
                                    </div>
                                    <div class="dropzone-preview" id="stock-proofs-preview"></div>
                                </div>
                                <small class="form-text text-muted mt-2">
                                    {l s='Formats : JPG, PNG, WebP. Max' mod='snt_dji_compensation'} {$max_stock_proofs} {l s='fichiers' mod='snt_dji_compensation'}
                                </small>

                                <div id="stock-proofs-types" class="mt-3"></div>
                            </div>
                        </div>
                    </div>

                    {* Boutons *}
                    <div class="form-actions d-flex justify-content-between flex-wrap">
                        <a href="{$my_account_link}" class="btn btn-outline-secondary mb-2">
                            <i class="material-icons">arrow_back</i>
                            {l s='Retour' mod='snt_dji_compensation'}
                        </a>

                        <div>
                            {if !$declaration || $declaration->status == 'draft'}
                                <button type="submit" name="submitDeclaration" class="btn btn-secondary mb-2">
                                    <i class="material-icons">save</i>
                                    {l s='Enregistrer brouillon' mod='snt_dji_compensation'}
                                </button>
                            {/if}

                            <button type="submit" name="submitDeclaration" class="btn btn-primary mb-2 ml-2" onclick="document.querySelector('input[name=action]').value='submit';">
                                <i class="material-icons">send</i>
                                {if $declaration && $declaration->status != 'draft'}
                                    {l s='Mettre a jour' mod='snt_dji_compensation'}
                                {else}
                                    {l s='Soumettre' mod='snt_dji_compensation'}
                                {/if}
                            </button>
                            <input type="hidden" name="action" value="save">
                        </div>
                    </div>
                </form>
            </div>
        </div>
    </div>
{/block}

{block name='javascript_bottom' append}
    <script>
        var djiProductTypes = {$product_types|json_encode};
        var djiMaxFileSizeBytes = {$max_file_size_bytes};
    </script>
    <script>
        (function() {
            console.log('DJI Delete Script loaded');

            var deleteAjaxUrl = '{$link->getModuleLink('snt_dji_compensation', 'declaration')|escape:'javascript'}';
            var urlSeparator = (deleteAjaxUrl.indexOf('?') === -1) ? '?' : '&';
            var csrfToken = '{$token|escape:'javascript'}';
            var declarationId = {if $declaration}{$declaration->id}{else}0{/if};
            var confirmDeleteMsg = '{l s='Etes-vous sur de vouloir supprimer ce fichier ?' mod='snt_dji_compensation' js=1}';

            function handleDelete(type, id, listItem) {
                var paramName = (type === 'invoice') ? 'id_invoice' : 'id_stock_proof';
                var actionName = (type === 'invoice') ? 'deleteInvoice' : 'deleteStockProof';
                var url = deleteAjaxUrl + urlSeparator + 'ajax=1&action=' + actionName + '&' + paramName + '=' + id + '&token=' + csrfToken + '&edit=' + declarationId;

                console.log('Delete URL:', url);

                fetch(url, {
                    method: 'POST',
                    headers: { 'X-Requested-With': 'XMLHttpRequest' }
                })
                .then(function(response) {
                    if (!response.ok) {
                        throw new Error('HTTP ' + response.status);
                    }
                    return response.text();
                })
                .then(function(text) {
                    try {
                        return JSON.parse(text);
                    } catch (e) {
                        console.error('Reponse non-JSON:', text);
                        throw new Error('Reponse invalide du serveur');
                    }
                })
                .then(function(data) {
                    if (data.success) {
                        listItem.remove();
                        var countClass = (type === 'invoice') ? '.invoices-count' : '.stock-proofs-count';
                        var listId = (type === 'invoice') ? 'invoices-list' : 'stock-proofs-list';
                        var countEl = document.querySelector(countClass);
                        if (countEl) countEl.textContent = data.count;
                        if (data.count == 0) {
                            var listContainer = document.getElementById(listId);
                            if (listContainer) listContainer.remove();
                        }
                    } else {
                        alert(data.message || 'Erreur');
                    }
                })
                .catch(function(err) {
                    console.error('Erreur AJAX:', err);
                    alert('Erreur: ' + err.message);
                });
            }

            function initDeleteButtons() {
                var invoiceBtns = document.querySelectorAll('.delete-invoice-btn');
                var stockBtns = document.querySelectorAll('.delete-stock-proof-btn');

                console.log('Invoice buttons found:', invoiceBtns.length);
                console.log('Stock proof buttons found:', stockBtns.length);

                invoiceBtns.forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        if (!confirm(confirmDeleteMsg)) return;
                        handleDelete('invoice', this.dataset.id, this.closest('li'));
                    });
                });

                stockBtns.forEach(function(btn) {
                    btn.addEventListener('click', function(e) {
                        e.preventDefault();
                        e.stopPropagation();
                        if (!confirm(confirmDeleteMsg)) return;
                        handleDelete('stock_proof', this.dataset.id, this.closest('li'));
                    });
                });
            }

            if (document.readyState === 'loading') {
                document.addEventListener('DOMContentLoaded', initDeleteButtons);
            } else {
                initDeleteButtons();
            }
        })();
    </script>
{/block}
