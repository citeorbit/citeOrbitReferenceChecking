<?php

/**
 * @file plugins/generic/citeOrbit/CiteOrbitSettingsForm.php
 *
 * @class CiteOrbitSettingsForm
 *
 * @brief Per-journal settings: CiteOrbit API base URL + API key.
 */

namespace APP\plugins\generic\citeOrbit;

use APP\template\TemplateManager;
use PKP\form\Form;

class CiteOrbitSettingsForm extends Form
{
    /** @var int */
    protected $_journalId;

    /** @var object */
    protected $_plugin;

    public function __construct($plugin, $journalId)
    {
        $this->_journalId = $journalId;
        $this->_plugin = $plugin;

        parent::__construct($plugin->getTemplateResource('settingsForm.tpl'));
        $this->addCheck(new \PKP\form\validation\FormValidator($this, 'apiKey', 'required', 'plugins.generic.citeOrbit.error.key'));
        $this->addCheck(new \PKP\form\validation\FormValidatorPost($this));
        $this->addCheck(new \PKP\form\validation\FormValidatorCSRF($this));
    }

    /**
     * Initialize form data.
     */
    public function initData()
    {
        $this->setData('apiKey', $this->_plugin->getSetting($this->_journalId, 'apiKey'));
        $this->setData('defaultCitationStyle', $this->_plugin->getSetting($this->_journalId, 'defaultCitationStyle'));
    }

    /**
     * Assign form data to user-submitted data.
     */
    public function readInputData()
    {
        $this->readUserVars(['apiKey', 'defaultCitationStyle']);
    }

    /**
     * CiteOrbit CSL styles offered as the fallback "default style".
     * Keep the ids in sync with CiteOrbit's lib/csl-styles/registry.ts.
     */
    public static function citationStyleOptions(): array
    {
        return [
            '' => 'plugins.generic.citeOrbit.style.auto',
            'apa-7' => 'APA 7th edition',
            'apa-6' => 'APA 6th edition',
            'harvard-cite-them-right' => 'Harvard — Cite Them Right',
            'chicago-author-date' => 'Chicago (author-date)',
            'mla-9' => 'MLA 9th edition',
            'vancouver' => 'Vancouver',
            'ieee' => 'IEEE',
            'american-medical-association' => 'AMA',
            'american-chemical-society' => 'ACS',
            'acm' => 'ACM',
        ];
    }

    /**
     * @copydoc Form::fetch()
     *
     * @param null|mixed $template
     */
    public function fetch($request, $template = null, $display = false)
    {
        $templateMgr = TemplateManager::getManager($request);
        $templateMgr->assign('pluginName', $this->_plugin->getName());
        $options = [];
        foreach (self::citationStyleOptions() as $id => $label) {
            // The empty-value "auto" entry is a locale key; the rest are literals.
            $options[$id] = $id === '' ? __($label) : $label;
        }
        $templateMgr->assign('citationStyleOptions', $options);
        return parent::fetch($request, $template, $display);
    }

    /**
     * @copydoc Form::execute()
     */
    public function execute(...$functionArgs)
    {
        $this->_plugin->updateSetting($this->_journalId, 'apiKey', trim((string) $this->getData('apiKey')));
        $this->_plugin->updateSetting($this->_journalId, 'defaultCitationStyle', trim((string) $this->getData('defaultCitationStyle')));
        parent::execute(...$functionArgs);
    }
}
