<?php

/**
 * @file plugins/generic/citeOrbit/CiteOrbitPlugin.php
 *
 * CiteOrbit Reference Checking plugin (OJS 3.5).
 *
 * @class CiteOrbitPlugin
 *
 * @brief Sends a publication's references to CiteOrbit for verification and
 *        links the editorial UI to the resulting report.
 */

namespace APP\plugins\generic\citeOrbit;

use APP\core\Application;
use APP\facades\Repo;
use APP\plugins\generic\citeOrbit\controllers\CiteOrbitHandler;
use PKP\components\forms\FieldHTML;
use PKP\db\DAORegistry;
use PKP\core\JSONMessage;
use PKP\linkAction\LinkAction;
use PKP\linkAction\request\AjaxModal;
use PKP\linkAction\request\OpenWindowAction;
use PKP\linkAction\request\RemoteActionConfirmationModal;
use PKP\plugins\GenericPlugin;
use PKP\plugins\Hook;

class CiteOrbitPlugin extends GenericPlugin
{
    /** Hosted CiteOrbit (used in production — journal admins never set a URL). */
    private const PROD_BASE_URL = 'https://app.citeorbit.com';

    /** CiteOrbit dev server, reached from inside the OJS container. */
    private const DEV_BASE_URL = 'http://host.docker.internal:3000';

    /**
     * CiteOrbit API base URL. The dev/prod choice lives in code/config, not the
     * journal UI: production uses the hosted URL. For local testing, add
     * `[citeorbit] dev_mode = On` to config.inc.php to target the dev server.
     */
    public function getApiBaseUrl(): string
    {
        return \PKP\config\Config::getVar('citeorbit', 'dev_mode') ? self::DEV_BASE_URL : self::PROD_BASE_URL;
    }

    /**
     * @copydoc Plugin::register()
     *
     * @param null|mixed $mainContextId
     */
    public function register($category, $path, $mainContextId = null)
    {
        if (!parent::register($category, $path, $mainContextId)) {
            return false;
        }
        if ($this->getEnabled($mainContextId)) {
            // Route the "Check with CiteOrbit" component handler.
            Hook::add('LoadComponentHandler', [$this, 'setupComponentHandler']);
            // Inject the "Check with CiteOrbit" button into the citations form.
            // Use ::before (Hook::run → args spread, callback receives the form
            // directly) so we can call $form->addField() before config is built.
            Hook::add('Form::config::before', [$this, 'addCheckButton']);
            // Inject the click handler script into the workflow page (the button's
            // inline JS is stripped by the form's HTML sanitizer, so we wire it
            // via a delegated listener in a real <script> instead).
            Hook::add('TemplateManager::display', [$this, 'injectWorkflowScript']);
            // Register the publication fields used to remember the last check, so
            // the "Open report" link survives a page reload.
            Hook::add('Schema::get::publication', [$this, 'addPublicationSchema']);
            // Add a "Validate with CiteOrbit" action to each PDF/DOCX file row in
            // the workflow files grids (mirrors the Texture plugin's approach).
            Hook::add('TemplateManager::fetch', [$this, 'addGridFileAction']);
            // Remember the report id per submission file so the grid can show a
            // persistent "Open report" link after validation.
            Hook::add('Schema::get::submissionFile', [$this, 'addSubmissionFileSchema']);
        }
        return true;
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

    /** Manuscript MIME types we can validate (must match the file-checks endpoint). */
    private const MANUSCRIPT_MIMES = [
        'application/pdf',
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'application/msword',
        'application/vnd.oasis.opendocument.text',
    ];

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
            Application::ROUTE_COMPONENT,
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
                $url,
                'modal_confirm'
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

    /** Setting name for the stored CiteOrbit report id on a publication. */
    public const REPORT_ID_FIELD = 'citeorbit::reportId';

    /** Setting name for the stored CiteOrbit check status on a publication. */
    public const STATUS_FIELD = 'citeorbit::status';

    /**
     * Browser-facing CiteOrbit base URL (for report links the editor opens).
     * In dev the API host is the container's host.docker.internal alias, which
     * the host browser can't resolve — swap it for localhost. No-op in prod.
     */
    public function getReportBaseUrl(): string
    {
        return str_replace('host.docker.internal', 'localhost', $this->getApiBaseUrl());
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
     * Provide the CiteOrbit component handler instance (so it can reach the plugin).
     *
     * @param string $hookName
     * @param array $params [$component, $op, &$componentInstance]
     */
    public function setupComponentHandler($hookName, $params)
    {
        $component = $params[0];
        if ($component === 'plugins.generic.citeOrbit.controllers.CiteOrbitHandler') {
            $componentInstance = & $params[2];
            $componentInstance = new CiteOrbitHandler($this);
            return true;
        }
        return false;
    }

    /**
     * Add a "Check with CiteOrbit" button to the publication citations form.
     *
     * @param string $hookName `Form::config::before`
     * @param \PKP\components\forms\FormComponent $form
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
            Application::ROUTE_COMPONENT,
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
        $publication = Repo::publication()->get($publicationId);
        $reportId = $publication ? $publication->getData(self::REPORT_ID_FIELD) : null;
        if ($reportId) {
            $reportUrl = $this->getReportBaseUrl() . '/check-references/by-check/' . $reportId;
            $html .= ' <a id="citeorbitReportLink" class="pkpButton" target="_blank" rel="noopener"'
                . ' style="margin-inline-start:0.5rem"'
                . ' href="' . htmlspecialchars($reportUrl, ENT_QUOTES) . '">'
                . __('plugins.generic.citeOrbit.button.openReport') . '</a>';
        }

        $form->addField(new FieldHTML('citeOrbitCheck', [
            'label' => __('plugins.generic.citeOrbit.displayName'),
            'description' => $html,
        ]));
        return false;
    }

    /**
     * Append the delegated click-handler script to the editorial dashboard.
     *
     * In 3.5 the submission workflow (incl. the Publication > References tab)
     * lives inside the editorial dashboard Vue SPA — there is no workflow.tpl —
     * so the script is injected on `dashboard/editors.tpl`. The click handler is
     * a delegated document listener, so it still binds the References button
     * even though Vue renders it dynamically after page load.
     *
     * @param string $hookName `TemplateManager::display`
     * @param array $args [$templateMgr, &$template, &$output]
     */
    public function injectWorkflowScript($hookName, $args)
    {
        if (($args[1] ?? '') !== 'dashboard/editors.tpl') {
            return false;
        }
        $templateMgr = $args[0];

        // Build the file-check component URL server-side (the client appends the
        // submissionFileId it reads from each file row's download link).
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        $fileCheckUrl = '';
        if ($context) {
            $fileCheckUrl = $request->getDispatcher()->url(
                $request,
                Application::ROUTE_COMPONENT,
                $context->getPath(),
                'plugins.generic.citeOrbit.controllers.CiteOrbitHandler',
                'checkFile'
            );
        }
        $prelude = 'window.CITEORBIT_FILECHECK_URL=' . json_encode($fileCheckUrl) . ';';

        $js = <<<'JS'
(function(){
    // 3.5's dashboard SPA doesn't ship the legacy .pkpButton styles, so style our
    // controls via injected CSS (covers the Vue-rendered, dynamically-added DOM).
    var st = document.createElement('style');
    st.textContent =
        '.citeorbitCheckBtn{display:inline-block;background:#1e6bb8;color:#fff;border:none;padding:0.5rem 1rem;border-radius:4px;cursor:pointer;font-weight:600;font-size:0.9rem;line-height:1.2;}'
      + '.citeorbitCheckBtn:hover{background:#17578f;}'
      + '.citeorbitFileBtn{display:inline-block;background:none;border:none;color:#1e6bb8;margin-inline-start:0.75rem;padding:0;cursor:pointer;font-weight:600;font-size:0.85rem;text-decoration:underline;font-family:inherit;white-space:nowrap;}'
      + '.citeorbitCheckBtn[data-co-armed],.citeorbitFileBtn[data-co-armed]{background:#b45309;color:#fff;text-decoration:none;border-radius:4px;padding:0.2rem 0.55rem;}'
      + '.citeorbitReportLink{display:inline-block;margin-inline-start:0.5rem;color:#1e6bb8;text-decoration:underline;font-size:0.85rem;white-space:nowrap;}';
    document.head.appendChild(st);
    function notify(msg, type){
        if (window.pkp && pkp.eventBus && pkp.eventBus.$emit) pkp.eventBus.$emit('notify', msg, type);
        else alert(msg);
    }
    function runCheck(b){
        if (b.getAttribute('data-co-busy')) return;
        b.setAttribute('data-co-busy', '1');
        var token = (window.pkp && pkp.currentUser && pkp.currentUser.csrfToken) || '';
        var body = 'csrfToken=' + encodeURIComponent(token);
        var pub = b.getAttribute('data-co-pub');           // references only
        if (pub) body = 'publicationId=' + encodeURIComponent(pub) + '&' + body;
        fetch(b.getAttribute('data-co-url'), {
            method: 'POST',
            headers: {'Content-Type': 'application/x-www-form-urlencoded'},
            body: body
        }).then(function(r){ return r.json(); }).then(function(d){
            var c = (d && d.content) || {};
            notify(c.message || 'Done', c.ok ? 'success' : 'warning');
            if (c.ok && c.reportUrl) {
                var nx = b.nextElementSibling;
                if (nx && nx.classList && nx.classList.contains('citeorbitReportLink')) nx.remove();
                var srv = document.getElementById('citeorbitReportLink'); // server-rendered (refs)
                if (srv && pub) srv.remove();
                var a = document.createElement('a');
                a.className = 'citeorbitReportLink';
                a.href = c.reportUrl; a.target = '_blank'; a.rel = 'noopener';
                a.textContent = 'Open CiteOrbit report';
                b.insertAdjacentElement('afterend', a);
            }
            b.removeAttribute('data-co-busy');
        }).catch(function(err){
            notify('Couldn\'t reach CiteOrbit. Please try again in a moment.', 'warning');
            b.removeAttribute('data-co-busy');
        });
    }
    // 3.5 opens the workflow inside a Vue modal whose focus-trap blocks clicks
    // outside its container, so a popup confirm can't be made reliably clickable.
    // Confirm inline instead: first click arms the control (relabel + amber "uses
    // credits" warning), second click sends. The controls live inside the
    // interactive workflow modal, so their clicks work.
    var armedEl = null, armedTimer = null;
    function disarm(){
        if (armedTimer) { clearTimeout(armedTimer); armedTimer = null; }
        if (armedEl) {
            if (armedEl.getAttribute('data-co-label') !== null) armedEl.textContent = armedEl.getAttribute('data-co-label');
            armedEl.removeAttribute('data-co-armed');
        }
        armedEl = null;
    }
    document.addEventListener('click', function(e){
        var b = e.target && e.target.closest ? e.target.closest('.citeorbitCheckBtn,.citeorbitFileBtn') : null;
        if (!b || b.getAttribute('data-co-busy')) return;
        e.preventDefault();
        if (armedEl === b) { disarm(); runCheck(b); return; }   // 2nd click -> send
        if (armedEl) disarm();                                   // switching controls
        if (b.getAttribute('data-co-label') === null) b.setAttribute('data-co-label', b.textContent);
        var armedTxt;
        if (b.classList.contains('citeorbitFileBtn')) {
            armedTxt = 'Click again to validate — uses credits';
        } else {
            var count = b.getAttribute('data-co-count');
            var refs = (count && count !== '0') ? (count + ' references') : 'these references';
            armedTxt = 'Click again to send ' + refs + ' — uses credits';
        }
        b.textContent = armedTxt;
        b.setAttribute('data-co-armed', '1');
        armedEl = b;
        armedTimer = setTimeout(disarm, 6000);
    }, false);

    // Inject a "Validate with CiteOrbit" control into each manuscript file row.
    // The new 3.5 file manager is a Vue component with no server-side hook, so we
    // read the submissionFileId from the row's download link and add the control
    // to the row's last cell. A MutationObserver re-adds it if Vue re-renders.
    function injectFileButtons(){
        if (!window.CITEORBIT_FILECHECK_URL) return;
        var links = document.querySelectorAll('a[href*="submissionFileId="]');
        for (var i = 0; i < links.length; i++) {
            var a = links[i];
            var name = (a.textContent || '').trim().toLowerCase();
            if (!/\.(docx|doc|odt)$/.test(name)) continue;   // types CiteOrbit handles (PDF is rejected)
            var m = a.href.match(/submissionFileId=(\d+)/);
            if (!m) continue;
            var row = a.closest('tr') || a.closest('li');
            if (!row || row.querySelector('.citeorbitFileBtn')) continue;
            var sep = window.CITEORBIT_FILECHECK_URL.indexOf('?') > -1 ? '&' : '?';
            var btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'citeorbitFileBtn';
            btn.setAttribute('data-co-file', m[1]);
            btn.setAttribute('data-co-url', window.CITEORBIT_FILECHECK_URL + sep + 'submissionFileId=' + m[1]);
            btn.textContent = 'Validate with CiteOrbit';
            // Place it in the wide FILE NAME cell, right after the filename link.
            var span = a.closest('span') || a;
            span.insertAdjacentElement('afterend', btn);
        }
    }
    var moTimer = null;
    function startFileInjection(){
        injectFileButtons();
        new MutationObserver(function(){ clearTimeout(moTimer); moTimer = setTimeout(injectFileButtons, 300); })
            .observe(document.body, {childList: true, subtree: true});
    }
    // The inline script runs in <head>, so document.body may not exist yet — wait for it.
    if (document.readyState === 'loading') { document.addEventListener('DOMContentLoaded', startFileInjection); }
    else { startFileInjection(); }
})();
JS;
        $templateMgr->addJavaScript('citeOrbitButtonHandler', $prelude . $js, ['inline' => true, 'contexts' => 'backend']);
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
