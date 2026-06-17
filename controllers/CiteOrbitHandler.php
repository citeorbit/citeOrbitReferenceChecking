<?php

/**
 * @file plugins/generic/citeOrbit/controllers/CiteOrbitHandler.php
 *
 * @class CiteOrbitHandler
 *
 * @brief Component handler: read a publication's references, send them to the
 *        CiteOrbit API, and return a user-facing message.
 */

namespace APP\plugins\generic\citeOrbit\controllers;

use APP\core\Application;
use APP\core\Services;
use APP\facades\Repo;
use APP\handler\Handler;
use APP\notification\NotificationManager;
use APP\plugins\generic\citeOrbit\CiteOrbitPlugin;
use PKP\core\JSONMessage;
use PKP\db\DAORegistry;
use PKP\notification\Notification;
use PKP\plugins\PluginRegistry;
use PKP\security\authorization\ContextAccessPolicy;
use PKP\security\Role;

class CiteOrbitHandler extends Handler
{
    /** @var \APP\plugins\generic\citeOrbit\CiteOrbitPlugin */
    public $plugin;

    public function __construct($plugin = null)
    {
        parent::__construct();
        $this->plugin = $plugin ?: PluginRegistry::getPlugin('generic', 'citeorbitplugin');
        $this->addRoleAssignment(
            [Role::ROLE_ID_MANAGER, Role::ROLE_ID_SUB_EDITOR, Role::ROLE_ID_ASSISTANT],
            ['check', 'checkFile']
        );
    }

    /**
     * @copydoc PKPHandler::authorize()
     */
    public function authorize($request, &$args, $roleAssignments)
    {
        $this->addPolicy(new ContextAccessPolicy($request, $roleAssignments));
        return parent::authorize($request, $args, $roleAssignments);
    }

    /**
     * Read the publication's references, POST them to CiteOrbit, return a message.
     */
    public function check($args, $request)
    {
        $context = $request->getContext();
        $publicationId = (int) $request->getUserVar('publicationId');

        $base = $this->plugin->getApiBaseUrl();
        $key = $this->plugin->getSetting($context->getId(), 'apiKey');
        if (!$key) {
            return $this->msg(false, __('plugins.generic.citeOrbit.error.key'));
        }

        $publication = Repo::publication()->get($publicationId);
        if (!$publication) {
            return $this->msg(false, 'Publication not found.');
        }

        $citationDao = DAORegistry::getDAO('CitationDAO');
        $citations = $citationDao->getByPublicationId($publicationId)->toArray();
        $refs = [];
        $pos = 1;
        foreach ($citations as $citation) {
            $raw = trim((string) $citation->getRawCitation());
            if ($raw === '') {
                continue;
            }
            $refs[] = ['citation_id' => $citation->getId(), 'position' => $pos++, 'raw' => $raw];
        }
        if (empty($refs)) {
            return $this->msg(false, 'No references found to check.');
        }

        $submission = Repo::submission()->get($publication->getData('submissionId'));
        $payload = [
            'submission_id' => $submission ? $submission->getId() : 0,
            'publication_id' => $publicationId,
            'article_title' => $publication->getLocalizedTitle(),
            'journal_abbreviation' => $context->getLocalizedData('abbreviation') ?: $context->getLocalizedData('acronym'),
            'citation_style' => $this->resolveCitationStyle($context),
            'references' => $refs,
        ];

        $httpClient = Application::get()->getHttpClient();
        try {
            $response = $httpClient->request('POST', rtrim($base, '/') . '/api/ojs/reference-checks', [
                'headers' => ['Authorization' => 'Bearer ' . $key],
                'json' => $payload,
                'http_errors' => false,
                'timeout' => 30,
            ]);
            $body = json_decode((string) $response->getBody(), true) ?: [];
        } catch (\Throwable $e) {
            // Keep the technical detail in the server log; show the editor a
            // clean message (no internal URLs / cURL internals).
            error_log('[CiteOrbit] connection error: ' . $e->getMessage());
            return $this->msg(false, "Couldn't reach CiteOrbit. Please try again in a moment.");
        }

        $code = $body['code'] ?? '';
        $message = $body['message'] ?? 'Unexpected response from CiteOrbit.';

        if ($code === 'queued') {
            $reportId = $body['report_id'] ?? '';
            // Persist on the publication so the "Open report" link survives a
            // reload (best-effort: a check already succeeded regardless).
            try {
                Repo::publication()->edit($publication, [
                    CiteOrbitPlugin::REPORT_ID_FIELD => $reportId,
                    CiteOrbitPlugin::STATUS_FIELD => 'queued',
                ]);
            } catch (\Throwable $e) {
                error_log('[CiteOrbit] could not persist report id: ' . $e->getMessage());
            }
            $reportUrl = rtrim($this->plugin->getReportBaseUrl(), '/') . '/check-references/by-check/' . $reportId;
            return $this->msg(true, $message, ['reportUrl' => $reportUrl, 'reportId' => $reportId]);
        }

        if (!empty($body['topup_url'])) {
            $message .= ' (' . $body['topup_url'] . ')';
        }
        return $this->msg(false, $message);
    }

    /**
     * Resolve the CiteOrbit citation-style id for this journal.
     *
     * Priority: the journal's Citation Style Language "primary" style (mapped to
     * CiteOrbit's id) wins; if that style has no CiteOrbit equivalent, fall back
     * to the plugin's "default style" setting; otherwise null (CiteOrbit defaults
     * to apa-7).
     */
    private function resolveCitationStyle($context): ?string
    {
        // OJS Citation Style Language id => CiteOrbit CSL id.
        $map = [
            'apa' => 'apa-7',
            'apa-6th-edition' => 'apa-6',
            'vancouver' => 'vancouver',
            'ieee' => 'ieee',
            'chicago-author-date' => 'chicago-author-date',
            'harvard-cite-them-right' => 'harvard-cite-them-right',
            'modern-language-association' => 'mla-9',
            'ama' => 'american-medical-association',
            'american-medical-association' => 'american-medical-association',
            'acs-nano' => 'american-chemical-society',
            'turabian-fullnote-bibliography' => 'turabian-author-date',
            'acm-sig-proceedings' => 'acm',
        ];

        $cslPlugin = PluginRegistry::getPlugin('generic', 'citationstylelanguageplugin');
        // A null primary style means the CSL plugin uses its own default (apa).
        $journalStyle = $cslPlugin ? $cslPlugin->getSetting($context->getId(), 'primaryCitationStyle') : null;
        $journalStyle = $journalStyle ?: 'apa';

        if (isset($map[$journalStyle])) {
            return $map[$journalStyle];
        }
        // Unmapped journal style → plugin default (if the admin set one).
        $fallback = $this->plugin->getSetting($context->getId(), 'defaultCitationStyle');
        return $fallback ?: null;
    }

    /**
     * Read a submission file, upload it to CiteOrbit's file-check endpoint, and
     * surface the outcome as an OJS notification.
     */
    public function checkFile($args, $request)
    {
        $context = $request->getContext();
        $submissionFileId = (int) $request->getUserVar('submissionFileId');
        $key = $this->plugin->getSetting($context->getId(), 'apiKey');
        if (!$key) {
            return $this->notify($request, Notification::NOTIFICATION_TYPE_ERROR, __('plugins.generic.citeOrbit.error.key'));
        }

        $submissionFile = Repo::submissionFile()->get($submissionFileId);
        if (!$submissionFile) {
            return $this->notify($request, Notification::NOTIFICATION_TYPE_ERROR, 'File not found.');
        }

        // PDF is not supported for manuscript validation — reject before doing
        // any work (no upload, no credits spent).
        if (strtolower((string) $submissionFile->getData('mimetype')) === 'application/pdf') {
            return $this->notify($request, Notification::NOTIFICATION_TYPE_WARNING, 'CiteOrbit does not support PDF file types.');
        }

        $fileService = Services::get('file');
        $file = $fileService->get($submissionFile->getData('fileId'));
        if (!$file || !$fileService->fs->has($file->path)) {
            return $this->notify($request, Notification::NOTIFICATION_TYPE_ERROR, 'This file could not be read on the server.');
        }
        try {
            $contents = $fileService->fs->read($file->path);
        } catch (\Throwable $e) {
            error_log('[CiteOrbit] file read failed: ' . $e->getMessage());
            return $this->notify($request, Notification::NOTIFICATION_TYPE_ERROR, 'This file could not be read on the server.');
        }
        $filename = $submissionFile->getLocalizedData('name') ?: 'manuscript';
        $mime = (string) $submissionFile->getData('mimetype');
        $submissionId = (int) $submissionFile->getData('submissionId');

        $base = $this->plugin->getApiBaseUrl();
        $httpClient = Application::get()->getHttpClient();
        try {
            $response = $httpClient->request('POST', rtrim($base, '/') . '/api/ojs/file-checks', [
                'headers' => ['Authorization' => 'Bearer ' . $key],
                'multipart' => [
                    ['name' => 'file', 'contents' => $contents, 'filename' => $filename, 'headers' => ['Content-Type' => $mime]],
                    ['name' => 'submission_id', 'contents' => (string) $submissionId],
                    ['name' => 'journal_abbreviation', 'contents' => (string) ($context->getLocalizedData('abbreviation') ?: $context->getLocalizedData('acronym'))],
                    ['name' => 'citation_style', 'contents' => (string) $this->resolveCitationStyle($context)],
                    ['name' => 'ojs_file_id', 'contents' => (string) $submissionFileId],
                ],
                'http_errors' => false,
                'timeout' => 60,
            ]);
            $body = json_decode((string) $response->getBody(), true) ?: [];
        } catch (\Throwable $e) {
            error_log('[CiteOrbit] file-check connection error: ' . $e->getMessage());
            return $this->notify($request, Notification::NOTIFICATION_TYPE_ERROR, "Couldn't reach CiteOrbit. Please try again in a moment.");
        }

        $code = $body['code'] ?? '';
        $message = $body['message'] ?? 'Unexpected response from CiteOrbit.';
        if ($code === 'queued') {
            $reportId = $body['report_id'] ?? '';
            // Persist on the file so the grid shows a persistent "Open report"
            // link (best-effort). Refresh this row so the link appears at once.
            try {
                Repo::submissionFile()->edit($submissionFile, [CiteOrbitPlugin::REPORT_ID_FIELD => $reportId]);
            } catch (\Throwable $e) {
                error_log('[CiteOrbit] could not persist file report id: ' . $e->getMessage());
            }
            return $this->notify($request, Notification::NOTIFICATION_TYPE_SUCCESS, __('plugins.generic.citeOrbit.notify.queuedFile'), $submissionFileId);
        }
        $type = ($code === 'insufficient_credits') ? Notification::NOTIFICATION_TYPE_WARNING : Notification::NOTIFICATION_TYPE_ERROR;
        return $this->notify($request, $type, $message);
    }

    private function notify($request, int $type, string $message, ?int $elementId = null): JSONMessage
    {
        $mgr = new NotificationManager();
        $mgr->createTrivialNotification($request->getUser()->getId(), $type, ['contents' => $message]);
        // Return a data-changed event (not a bare JSONMessage) so OJS refreshes
        // the affected grid row (revealing the new "Open report" link) and
        // fetches the pending notification.
        return \PKP\db\DAO::getDataChangedEvent($elementId);
    }

    private function msg(bool $ok, string $message, array $extra = []): JSONMessage
    {
        return new JSONMessage(true, array_merge(['ok' => $ok, 'message' => $message], $extra));
    }
}
