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
