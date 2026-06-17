<?php

/**
 * @file plugins/generic/citeOrbit/CiteOrbitPlugin.inc.php
 *
 * CiteOrbit Reference Checking plugin (OJS 3.3).
 *
 * @class CiteOrbitPlugin
 *
 * @brief Sends a publication's references to CiteOrbit for verification and
 *        links the editorial UI to the resulting report.
 *
 * NOTE: This is the OJS 3.3 port. Unlike the 3.4 build it uses the legacy
 * global-class / import() idiom, HookRegistry::register(), Services::get()
 * instead of the Repo facade, and global route/role constants. The Form
 * Builder hooks (Form::config::before), FieldHTML, schema hooks and grid row
 * actions all exist in 3.3, so the overall architecture is unchanged.
 */

import('lib.pkp.classes.plugins.GenericPlugin');
import('lib.pkp.classes.linkAction.LinkAction');
import('lib.pkp.classes.linkAction.request.AjaxModal');
import('lib.pkp.classes.linkAction.request.OpenWindowAction');
import('lib.pkp.classes.linkAction.request.RemoteActionConfirmationModal');
import('lib.pkp.classes.core.JSONMessage');

class CiteOrbitPlugin extends GenericPlugin
{
    /** Hosted CiteOrbit (used in production — journal admins never set a URL). */
    const PROD_BASE_URL = 'https://app.citeorbit.com';

    /** CiteOrbit dev server, reached from inside the OJS container. */
    const DEV_BASE_URL = 'http://host.docker.internal:3000';

    /** Setting name for the stored CiteOrbit report id on a publication. */
    const REPORT_ID_FIELD = 'citeorbit::reportId';

    /** Setting name for the stored CiteOrbit check status on a publication. */
    const STATUS_FIELD = 'citeorbit::status';

    /** Manuscript MIME types we can validate (must match the file-checks endpoint). */
    private const MANUSCRIPT_MIMES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/msword',
        'application/vnd.oasis.opendocument.text',
    ];

    /**
     * CiteOrbit API base URL. The dev/prod choice lives in code/config, not the
     * journal UI: production uses the hosted URL. For local testing, add
     * `[citeorbit] dev_mode = On` to config.inc.php to target the dev server.
     */
    public function getApiBaseUrl()
    {
        return Config::getVar('citeorbit', 'dev_mode') ? self::DEV_BASE_URL : self::PROD_BASE_URL;
    }

    /**
     * Browser-facing CiteOrbit base URL (for report links the editor opens).
     * In dev the API host is the container's host.docker.internal alias, which
     * the host browser can't resolve — swap it for localhost. No-op in prod.
     */
    public function getReportBaseUrl()
    {
        return str_replace('host.docker.internal', 'localhost', $this->getApiBaseUrl());
    }

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        $success = parent::register($category, $path, $mainContextId);
        if (!Config::getVar('general', 'installed') || defined('RUNNING_UPGRADE')) {
            return $success;
        }
        if ($success && $this->getEnabled($mainContextId)) {
            // Route the "Check with CiteOrbit" component handler.
            HookRegistry::register('LoadComponentHandler', array($this, 'setupComponentHandler'));
            // Inject the "Check with CiteOrbit" button into the citations form.
            HookRegistry::register('Form::config::before', array($this, 'addCheckButton'));
            // Inject the click handler script into the workflow page (the button's
            // inline JS is stripped by the form's HTML sanitizer, so we wire it
            // via a delegated listener in a real <script> instead).
            HookRegistry::register('TemplateManager::display', array($this, 'injectWorkflowScript'));
            // Register the publication fields used to remember the last check, so
            // the "Open report" link survives a page reload.
            HookRegistry::register('Schema::get::publication', array($this, 'addPublicationSchema'));
            // Add a "Validate with CiteOrbit" action to each PDF/DOCX file row in
            // the workflow files grids (mirrors the Texture plugin's approach).
            HookRegistry::register('TemplateManager::fetch', array($this, 'addGridFileAction'));
            // Remember the report id per submission file so the grid can show a
            // persistent "Open report" link after validation.
            HookRegistry::register('Schema::get::submissionFile', array($this, 'addSubmissionFileSchema'));
        }
        return $success;
    }

    /**
     * Register the citeorbit::reportId field on the submission file schema.
     *
     * @param string $hookName `Schema::get::submissionFile`
     * @param array $args [&$schema]
     */
    public function addSubmissionFileSchema($hookName, $args)
    {
        $schema = $args[0];
        $schema->properties->{self::REPORT_ID_FIELD} = (object) [
            'type' => 'string',
            'apiSummary' => true,
            'validation' => ['nullable'],
        ];
        return false;
    }

    /**
     * Provide the CiteOrbit component handler (so the component router can find
     * the plugin handler class outside the controllers/ package).
     *
     * In 3.3 the LoadComponentHandler hook only carries [&$component, &$op];
     * returning true tells the router to instantiate $component as-is (with no
     * package restriction), so we just import the file and claim the component.
     *
     * @param string $hookName `LoadComponentHandler`
     * @param array $params [&$component, &$op]
     */
    public function setupComponentHandler($hookName, $params)
    {
        $component = $params[0];
        if ($component === 'plugins.generic.citeOrbit.controllers.CiteOrbitHandler') {
            import($component);
            return true;
        }
        return false;
    }

    /**
     * Add a per-file "Validate with CiteOrbit" link to workflow file grid rows.
     *
     * @param string $hookName `TemplateManager::fetch`
     * @param array $args [$templateMgr, $resourceName]
     */
    public function addGridFileAction($hookName, $args)
    {
        $templateMgr = $args[0];
        $resourceName = $args[1] ?? '';
        if ($resourceName !== 'controllers/grid/gridRow.tpl') {
            return false;
        }
        $row = $templateMgr->getTemplateVars('row');
        if (!is_object($row) || !method_exists($row, 'getData') || !method_exists($row, 'addAction')) {
            return false;
        }
        $data = $row->getData();
        if (!is_array($data) || empty($data['submissionFile'])) {
            return false;
        }
        $submissionFile = $data['submissionFile'];
        $mime = strtolower((string) $submissionFile->getData('mimetype'));
        if (!in_array($mime, self::MANUSCRIPT_MIMES, true)) {
            return false;
        }

        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) {
            return false;
        }
        $url = $request->getDispatcher()->url(
            $request,
            ROUTE_COMPONENT,
            $context->getPath(),
            'plugins.generic.citeOrbit.controllers.CiteOrbitHandler',
            'checkFile',
            null,
            ['submissionFileId' => $submissionFile->getData('id')]
        );
        $row->addAction(new LinkAction(
            'citeOrbitValidateFile',
            new RemoteActionConfirmationModal(
                $request->getSession(),
                __('plugins.generic.citeOrbit.confirmFile'),
                __('plugins.generic.citeOrbit.button.checkFile'),
                $url
            ),
            __('plugins.generic.citeOrbit.button.checkFile'),
            null
        ));

        // Once validated, show a persistent "Open report" link on the row.
        $reportId = $submissionFile->getData(self::REPORT_ID_FIELD);
        if ($reportId) {
            $reportUrl = rtrim($this->getReportBaseUrl(), '/') . '/check-references/by-check/' . $reportId;
            $row->addAction(new LinkAction(
                'citeOrbitOpenReport',
                new OpenWindowAction($reportUrl),
                __('plugins.generic.citeOrbit.button.openReport'),
                null
            ));
        }
        return false;
    }

    /**
     * Register the citeorbit::reportId / citeorbit::status publication fields.
     *
     * @param string $hookName `Schema::get::publication`
     * @param array $args [&$schema]
     */
    public function addPublicationSchema($hookName, $args)
    {
        $schema = $args[0];
        foreach ([self::REPORT_ID_FIELD, self::STATUS_FIELD] as $prop) {
            $schema->properties->{$prop} = (object) [
                'type' => 'string',
                'apiSummary' => true,
                'validation' => ['nullable'],
            ];
        }
        return false;
    }

    /**
     * Add a "Check with CiteOrbit" button to the publication citations form.
     *
     * @param string $hookName `Form::config::before`
     * @param FormComponent $form
     */
    public function addCheckButton($hookName, $form)
    {
        if (!is_object($form) || ($form->id ?? '') !== 'citations') {
            return false;
        }
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$context) {
            return false;
        }
        // The citations form's action URL carries the publication id.
        $publicationId = 0;
        if (preg_match('#/publications/(\d+)#', (string) ($form->action ?? ''), $m)) {
            $publicationId = (int) $m[1];
        }
        if (!$publicationId) {
            return false;
        }

        $url = $request->getDispatcher()->url(
            $request,
            ROUTE_COMPONENT,
            $context->getPath(),
            'plugins.generic.citeOrbit.controllers.CiteOrbitHandler',
            'check'
        );

        // Reference count, shown in the confirmation prompt before spending credits.
        $citationDao = DAORegistry::getDAO('CitationDAO');
        $refCount = count($citationDao->getByPublicationId($publicationId)->toArray());

        $label = __('plugins.generic.citeOrbit.button.check');
        // The click is wired by a delegated listener (see injectWorkflowScript);
        // the data-* attributes survive the form's HTML sanitizer.
        $html = '<button type="button" class="pkpButton citeorbitCheckBtn"'
            . ' data-co-url="' . htmlspecialchars($url, ENT_QUOTES) . '"'
            . ' data-co-pub="' . $publicationId . '"'
            . ' data-co-count="' . $refCount . '">'
            . htmlspecialchars($label) . '</button>';

        // If a check was run before, render the persistent "Open report" link on
        // page load (the JS reuses this same id, so there's never a duplicate).
        $publication = Services::get('publication')->get($publicationId);
        $reportId = $publication ? $publication->getData(self::REPORT_ID_FIELD) : null;
        if ($reportId) {
            $reportUrl = $this->getReportBaseUrl() . '/check-references/by-check/' . $reportId;
            $html .= ' <a id="citeorbitReportLink" class="pkpButton" target="_blank" rel="noopener"'
                . ' style="margin-inline-start:0.5rem"'
                . ' href="' . htmlspecialchars($reportUrl, ENT_QUOTES) . '">'
                . __('plugins.generic.citeOrbit.button.openReport') . '</a>';
        }

        $form->addField(new \PKP\components\forms\FieldHTML('citeOrbitCheck', [
            'label' => __('plugins.generic.citeOrbit.displayName'),
            'description' => $html,
        ]));
        return false;
    }

    /**
     * Append the delegated click-handler script to the workflow page.
     *
     * @param string $hookName `TemplateManager::display`
     * @param array $args [$templateMgr, &$template, &$output]
     */
    public function injectWorkflowScript($hookName, $args)
    {
        if (($args[1] ?? '') !== 'workflow/workflow.tpl') {
            return false;
        }
        $templateMgr = $args[0];
        // The workflow page doesn't preload the OpenWindowRequest link-action JS,
        // so our "Open report" grid link can't bind (renders dead/grey). Load it.
        $templateMgr->addJavaScript(
            'citeOrbitOpenWindowReq',
            Application::get()->getRequest()->getBaseUrl() . '/lib/pkp/js/classes/linkAction/OpenWindowRequest.js',
            ['contexts' => 'backend']
        );
        $js = <<<'JS'
(function(){
    function notify(msg, type){
        if (window.pkp && pkp.eventBus && pkp.eventBus.$emit) pkp.eventBus.$emit('notify', msg, type);
        else alert(msg);
    }
    function runCheck(b){
        if (b.getAttribute('data-co-busy')) return;
        b.setAttribute('data-co-busy', '1');
        var token = (window.pkp && pkp.currentUser && pkp.currentUser.csrfToken) || '';
        fetch(b.getAttribute('data-co-url'), {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: 'publicationId=' + encodeURIComponent(b.getAttribute('data-co-pub')) +
                  '&csrfToken=' + encodeURIComponent(token)
        }).then(function(r){ return r.json(); }).then(function(d){
            var c = (d && d.content) || {};
            notify(c.message || 'Done', c.ok ? 'success' : 'warning');
            if (c.ok && c.reportUrl) {
                var prev = document.getElementById('citeorbitReportLink');
                if (prev) prev.remove();
                var a = document.createElement('a');
                a.id = 'citeorbitReportLink';
                a.href = c.reportUrl; a.target = '_blank'; a.rel = 'noopener';
                a.className = 'pkpButton';
                a.style.marginInlineStart = '0.5rem';
                a.textContent = 'Open CiteOrbit report';
                if (b.parentNode) b.parentNode.appendChild(a);
            }
            b.removeAttribute('data-co-busy');
        }).catch(function(err){
            notify('Couldn\'t reach CiteOrbit. Please try again in a moment.', 'warning');
            b.removeAttribute('data-co-busy');
        });
    }
    // Lightweight in-page confirmation modal (avoids the native browser dialog).
    function confirmModal(message, onConfirm){
        var ov = document.createElement('div');
        ov.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:100000;display:flex;align-items:center;justify-content:center;';
        var card = document.createElement('div');
        card.style.cssText = 'background:#fff;max-width:440px;width:90%;border-radius:6px;box-shadow:0 12px 40px rgba(0,0,0,0.25);padding:1.5rem 1.5rem 1.25rem;font-family:inherit;';
        var h = document.createElement('h2');
        h.textContent = 'Validate with CiteOrbit';
        h.style.cssText = 'margin:0 0 0.75rem;font-size:1.1rem;color:#002c40;';
        var p = document.createElement('p');
        p.textContent = message;
        p.style.cssText = 'margin:0 0 1.25rem;color:#333;line-height:1.5;';
        var row = document.createElement('div');
        row.style.cssText = 'display:flex;gap:0.5rem;justify-content:flex-end;';
        var cancel = document.createElement('button');
        cancel.type = 'button'; cancel.className = 'pkpButton'; cancel.textContent = 'Cancel';
        cancel.style.cssText = 'background:#e8eaed;color:#1a1a1a;';
        var ok = document.createElement('button');
        ok.type = 'button'; ok.className = 'pkpButton'; ok.textContent = 'Send to CiteOrbit';
        function close(){ if (ov.parentNode) ov.parentNode.removeChild(ov); }
        cancel.addEventListener('click', close);
        ov.addEventListener('click', function(ev){ if (ev.target === ov) close(); });
        ok.addEventListener('click', function(){ close(); onConfirm(); });
        row.appendChild(cancel); row.appendChild(ok);
        card.appendChild(h); card.appendChild(p); card.appendChild(row);
        ov.appendChild(card); document.body.appendChild(ov);
        ok.focus();
    }
    document.addEventListener('click', function(e){
        var b = e.target && e.target.closest ? e.target.closest('.citeorbitCheckBtn') : null;
        if (!b || b.getAttribute('data-co-busy')) return;
        e.preventDefault();
        var count = b.getAttribute('data-co-count');
        var refs = (count && count !== '0') ? (count + ' references') : 'these references';
        confirmModal(
            'Send ' + refs + ' to CiteOrbit for validation? This uses credits from your CiteOrbit workspace.',
            function(){ runCheck(b); }
        );
    }, false);
})();
JS;
        $templateMgr->addJavaScript('citeOrbitButtonHandler', $js, ['inline' => true, 'contexts' => 'backend']);
        return false;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    public function getDisplayName()
    {
        return __('plugins.generic.citeOrbit.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    public function getDescription()
    {
        return __('plugins.generic.citeOrbit.description');
    }

    /**
     * @copydoc Plugin::getActions()
     */
    public function getActions($request, $verb)
    {
        $router = $request->getRouter();
        return array_merge(
            $this->getEnabled() ? [
                new LinkAction(
                    'settings',
                    new AjaxModal(
                        $router->url($request, null, null, 'manage', null, ['verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic']),
                        $this->getDisplayName()
                    ),
                    __('manager.plugins.settings'),
                    null
                ),
            ] : [],
            parent::getActions($request, $verb)
        );
    }

    /**
     * @copydoc Plugin::manage()
     */
    public function manage($args, $request)
    {
        switch ($request->getUserVar('verb')) {
            case 'settings':
                $this->import('CiteOrbitSettingsForm');
                $context = $request->getContext();
                $form = new CiteOrbitSettingsForm($this, $context->getId());
                if ($request->getUserVar('save')) {
                    $form->readInputData();
                    if ($form->validate()) {
                        $form->execute();
                        return new JSONMessage(true);
                    }
                } else {
                    $form->initData();
                }
                return new JSONMessage(true, $form->fetch($request));
        }
        return parent::manage($args, $request);
    }
}
