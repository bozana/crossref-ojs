<?php

/**
 * @file plugins/generic/crossref/filter/PreprintCrossrefXmlFilter.php
 *
 * Copyright (c) 2014-2025 Simon Fraser University
 * Copyright (c) 2000-2025 John Willinsky
 * Distributed under The MIT License. For full terms see the file LICENSE.
 *
 * @class PreprintCrossrefXmlFilter
 *
 * @ingroup plugins_generic_crossref
 *
 * @brief Class that converts articles preprints (Author Originals) to a Crossref XML document.
 */

namespace APP\plugins\generic\crossref\filter;

use APP\core\Application;
use APP\plugins\generic\crossref\CrossrefExportDeployment;
use APP\publication\enums\VersionStage;
use APP\publication\Publication;
use APP\submission\Submission;
use DOMDocument;
use DOMElement;
use PKP\context\Context;
use PKP\filter\FilterGroup;

class PreprintCrossrefXmlFilter extends ArticleCrossrefXmlFilter
{
    // Processed posted_contents i.e. preprints (AO) DOIs
    public array $preprintsDois = [];

    /**
     * Constructor
     *
     * @param FilterGroup $filterGroup
     */
    public function __construct($filterGroup)
    {
        parent::__construct($filterGroup);
        $this->setDisplayName('Crossref XML preprint export');
    }

    /**
     * @see \PKP\filter\Filter::process()
     *
     * @param array $pubObjects Array of Submissions
     *
     * @return \DOMDocument
     */
    public function &process(&$pubObjects)
    {
        // Create the XML document
        $doc = new \DOMDocument('1.0', 'utf-8');
        $doc->preserveWhiteSpace = false;
        $doc->formatOutput = true;
		/** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();

        // Create the root node
        $rootNode = $this->createRootNode($doc);
        $doc->appendChild($rootNode);

        // Create and append the 'head' node and all parts inside it
        $rootNode->appendChild($this->createHeadNode($doc));

        // Create and append the 'body' node, that contains everything
        $bodyNode = $doc->createElementNS($deployment->getNamespace(), 'body');
        $rootNode->appendChild($bodyNode);

        $preprintVersionStages = [VersionStage::AUTHOR_ORIGINAL->value];

        foreach ($pubObjects as $pubObject) {
            if (!$context->getData(Context::SETTING_DOI_VERSIONING)) {
                $publication = $pubObject->getCurrentPublication();
                if (!in_array($publication->getData('versionStage'), $preprintVersionStages)) {
                    continue;
                }
                $bodyNode->appendChild($this->createPostedContentNode($doc, $publication, $pubObject));
            } else {
                $latestMinorPublications = $this->getLatestMinorPublications($pubObject->getData('publications'), $preprintVersionStages);
                foreach ($latestMinorPublications as $versionStage) {
                    foreach ($versionStage as $publication) {
                        $bodyNode->appendChild($this->createPostedContentNode($doc, $publication, $pubObject));
						$this->preprintsDois[] = $publication->getDoi();
                    }
                }
            }
        }
        return $doc;
    }

    /**
     * Create and return the posted content node 'posted_content'.
     */
    public function createPostedContentNode(DOMDocument $doc, Publication $publication, Submission $submission): DOMElement
    {
        /** @var CrossrefExportDeployment $deployment */
        $deployment = $this->getDeployment();
        $context = $deployment->getContext();
        $request = Application::get()->getRequest();

        $locale = $publication->getData('locale');

        $postedContentNode = $doc->createElementNS($deployment->getNamespace(), 'posted_content');
        $postedContentNode->setAttribute('type', 'preprint');
        $postedContentNode->setAttribute('language', \Locale::getPrimaryLanguage($locale));

        // contributors
        $authors = $publication->getData('authors');
        if ($authors->count() != 0) {
            $contributorsNode = $this->createContributorsNode($doc, $publication);
            $postedContentNode->appendChild($contributorsNode);
        }

        // Titles
        $titlesNode = $doc->createElementNS($deployment->getNamespace(), 'titles');
        $titlesNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'title'));
        $node->appendChild($doc->createTextNode($publication->getLocalizedTitle($locale, 'html')));
        if ($subtitle = $publication->getLocalizedSubTitle($locale, 'html')) {
            $titlesNode->appendChild($node = $doc->createElementNS($deployment->getNamespace(), 'subtitle'));
            $node->appendChild($doc->createTextNode($publication->getLocalizedTitle($subtitle, 'html')));
        }
        $postedContentNode->appendChild($titlesNode);

        // Posted date
        $postedContentNode->appendChild($this->createDateNode($doc, $publication->getData('datePublished'), 'posted_date'));

        // abstract
        $this->appendAbstractNode($doc, $postedContentNode, $publication);

        // license
        $this->appendProgramNode($doc, $postedContentNode, $publication);

		if ($context->getData(Context::SETTING_DOI_VERSIONING) && $this->preprintsDois) {
			// rel:program
			$this->appendRelationships($doc, $postedContentNode, [], $this->preprintsDois);

		}

        // DOI data
        $dispatcher = $this->_getDispatcher($request);
        if ($context->getData(Context::SETTING_DOI_VERSIONING)) {
            $url = $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), 'preprint', 'view', [$publication->getData('urlPath') ?? $submission->getId(), 'version', $publication->getId()], null, null, true, '');
        } else {
            $url = $dispatcher->url($request, Application::ROUTE_PAGE, $context->getPath(), 'preprint', 'view', [$publication->getData('urlPath') ?? $submission->getId()], null, null, true, '');
        }
        $postedContentNode->appendChild($this->createDOIDataNode($doc, $publication->getDoi(), $url));

        return $postedContentNode;
    }
}
