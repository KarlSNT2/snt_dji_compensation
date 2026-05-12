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
	<h3><i class="icon icon-cogs"></i> {l s='SNT Compensation DJI' mod='snt_dji_compensation'}</h3>
	<p>
		{l s='Module de declaration de compensation DJI pour les clients B2B.' mod='snt_dji_compensation'}
	</p>
	<hr>
	<div class="row">
		<div class="col-md-6">
			<h4><i class="icon icon-check"></i> {l s='Statut' mod='snt_dji_compensation'}</h4>
			<ul class="list-unstyled">
				<li>
					<strong>{l s='Template XLSX :' mod='snt_dji_compensation'}</strong>
					{if $has_template}
						<span class="label label-success">{l s='OK' mod='snt_dji_compensation'}</span>
						<br><small class="text-muted">{$template_file}</small>
					{else}
						<span class="label label-danger">{l s='Non configure' mod='snt_dji_compensation'}</span>
					{/if}
				</li>
			</ul>
		</div>
		<div class="col-md-6">
			<h4><i class="icon icon-rocket"></i> {l s='Actions' mod='snt_dji_compensation'}</h4>
			<p>
				<a href="{$declarations_link}" class="btn btn-primary">
					<i class="icon icon-list"></i>
					{l s='Voir les declarations' mod='snt_dji_compensation'}
				</a>
			</p>
		</div>
	</div>
</div>

{if $template_history|count > 0}
<div class="panel">
	<h3><i class="icon icon-history"></i> {l s='Historique des templates' mod='snt_dji_compensation'}</h3>
	<p class="text-muted">
		{l s='Les anciens templates sont conserves. Vous pouvez restaurer un template precedent si necessaire.' mod='snt_dji_compensation'}
	</p>
	<div class="table-responsive">
		<table class="table table-striped">
			<thead>
				<tr>
					<th>{l s='Date' mod='snt_dji_compensation'}</th>
					<th>{l s='Fichier original' mod='snt_dji_compensation'}</th>
					<th>{l s='Taille' mod='snt_dji_compensation'}</th>
					<th>{l s='Ajoute par' mod='snt_dji_compensation'}</th>
					<th>{l s='Statut' mod='snt_dji_compensation'}</th>
					<th>{l s='Actions' mod='snt_dji_compensation'}</th>
				</tr>
			</thead>
			<tbody>
				{foreach from=$template_history item=template}
				<tr{if $template.is_active} class="success"{/if}>
					<td>{$template.date_add|date_format:'%d/%m/%Y %H:%M'}</td>
					<td>
						<strong>{$template.original_filename|escape:'html':'UTF-8'}</strong>
						<br><small class="text-muted">{$template.filename|escape:'html':'UTF-8'}</small>
					</td>
					<td>{$template.file_size_formatted}</td>
					<td>{$template.employee_name|escape:'html':'UTF-8'}</td>
					<td>
						{if $template.is_active}
							<span class="label label-success">{l s='Actif' mod='snt_dji_compensation'}</span>
						{elseif $template.file_exists}
							<span class="label label-default">{l s='Archive' mod='snt_dji_compensation'}</span>
						{else}
							<span class="label label-danger">{l s='Fichier manquant' mod='snt_dji_compensation'}</span>
						{/if}
					</td>
					<td>
						{if !$template.is_active && $template.file_exists}
							<form method="post" action="{$configure_link|escape:'html':'UTF-8'}" style="display:inline;">
								<input type="hidden" name="id_template" value="{$template.id_template}">
								<button type="submit" name="restoreTemplate" class="btn btn-sm btn-default" onclick="return confirm('{l s='Restaurer ce template ?' mod='snt_dji_compensation' js=1}');">
									<i class="icon icon-undo"></i> {l s='Restaurer' mod='snt_dji_compensation'}
								</button>
							</form>
						{elseif $template.is_active}
							<span class="text-success"><i class="icon icon-check"></i> {l s='En cours' mod='snt_dji_compensation'}</span>
						{else}
							<span class="text-muted">-</span>
						{/if}
					</td>
				</tr>
				{/foreach}
			</tbody>
		</table>
	</div>
</div>
{/if}
