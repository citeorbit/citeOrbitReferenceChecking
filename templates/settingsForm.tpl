{**
 * plugins/generic/citeOrbit/templates/settingsForm.tpl
 *
 * CiteOrbit plugin settings form: API base URL + API key.
 *}
<script>
	$(function() {ldelim}
		$('#citeOrbitSettingsForm').pkpHandler('$.pkp.controllers.form.AjaxFormHandler');
	{rdelim});
</script>
<form class="pkp_form" id="citeOrbitSettingsForm" method="POST" action="{url router=\PKP\core\PKPApplication::ROUTE_COMPONENT op="manage" category="generic" plugin=$pluginName verb="settings" save=true}">
	{csrf}
	{fbvFormArea id="citeOrbitSettingsFormArea"}
		{fbvFormSection label="plugins.generic.citeOrbit.apiKey" description="plugins.generic.citeOrbit.apiKey.description"}
			{fbvElement type="text" id="apiKey" value=$apiKey size=$fbvStyles.size.LARGE password=true}
		{/fbvFormSection}
		{fbvFormSection label="plugins.generic.citeOrbit.defaultStyle" description="plugins.generic.citeOrbit.defaultStyle.description"}
			{fbvElement type="select" id="defaultCitationStyle" from=$citationStyleOptions selected=$defaultCitationStyle translate=false}
		{/fbvFormSection}
	{/fbvFormArea}
	{fbvFormButtons submitText="common.save"}
</form>
