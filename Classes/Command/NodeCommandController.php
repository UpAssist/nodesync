<?php

namespace UpAssist\NodeSync\Command;

use Neos\ContentRepository\Domain\Model\NodeInterface;
use Neos\ContentRepository\Domain\Repository\NodeDataRepository;
use Neos\ContentRepository\Domain\Repository\WorkspaceRepository;
use Neos\ContentRepository\Domain\Service\Context;
use Neos\Eel\FlowQuery\FlowQuery;
use Neos\Flow\Annotations as Flow;
use Neos\Flow\Cli\CommandController;
use Neos\Flow\Cli\Exception\StopCommandException;
use Neos\Flow\Package\PackageManager;
use Neos\Flow\Persistence\Exception\IllegalObjectTypeException;
use Neos\Neos\Controller\CreateContentContextTrait;
use Neos\Neos\Domain\Service\ContentContext;

class NodeCommandController extends CommandController
{
    use CreateContentContextTrait;

    /**
     * @var NodeDataRepository
     * @Flow\Inject
     */
    protected NodeDataRepository $nodeDataRepository;

    /**
     * @var WorkspaceRepository
     * @Flow\Inject
     */
    protected WorkspaceRepository $workspaceRepository;

    /**
     * @var PackageManager
     * @Flow\Inject
     */
    protected PackageManager $packageManager;

    /**
     * Sync the node tree to a different dimensions set.
     *
     * @param string $siteNodeName The siteNodeName
     * @param array $from The dimensions which should be taken as a base (i.e. '{"country":["nl"], "language":["nl"]}')
     * @param array $to The target dimensions for the new nodes (i.e. '{"country":["de"], "language":["de"]}')
     * @return void
     * @throws \Neos\Eel\Exception
     */
    public function syncTreeCommand(string $siteNodeName, array $from, array $to): void
    {
        $from = json_decode(reset($from), true);
        $targetDimensions = json_decode(reset($to), true);

        /** @var Context $context */
        $context = $this->getContext('live', $from);
        $siteNode = $context->getNode('/sites/' . $siteNodeName);
        $documentNodeQuery = new FlowQuery([$siteNode]);
        $documentNodeQuery->pushOperation('find', ['[instanceof Neos.Neos:Document]']);
        $documentNodes = $documentNodeQuery->get();
        array_unshift($documentNodes, $siteNode);

        $this->output->outputLine('Found %s document nodes', [sizeof($documentNodes)]);
        $this->output->progressStart(sizeof($documentNodes));

        $newContext = $this->getContext('live', $targetDimensions);
        /** @var NodeInterface $documentNode */
        foreach ($documentNodes as $documentNode) {
            $newContext->adoptNode($documentNode, true);
            $this->nodeDataRepository->persistEntities();
            $this->output->progressAdvance();
        }
        $this->output->progressFinish();
        $this->quit();
    }

    /**
     * Translate the whole node tree (only when sync is set to true!)
     * The package 'Sitegeist.LostInTranslation' is required for this.
     *
     * Usually this command is not necessary, but when you copy for example
     * a language that does not exist in the new dimensions values (ie. you
     * created a new country DE but your base language is NL you'll end up
     * with dutch text in your DE tree). Running this command will make sure
     * your content gets translated.
     *
     * @param string $siteNodeName The siteNodeName
     * @param array $from The dimensions which should be taken as a base (i.e. '{"country":["nl"], "language":["nl"]}')
     * @return void
     * @throws \Neos\Eel\Exception
     * @throws StopCommandException
     * @throws IllegalObjectTypeException
     */
    public function syncTranslationsCommand(string $siteNodeName, array $from): void
    {
        if (!isset($this->packageManager->getAvailablePackages()['Sitegeist.LostInTranslation'])) {
            $this->output->outputLine('To use this command, you need to have the package Sitegeist.LostInTranslation installed');
            $this->quit();
        }

        $from = json_decode(reset($from), true);

        $context = $this->getContext('live', $from);
        $siteNode = $context->getNode('/sites/' . $siteNodeName);
        $documentNodeQuery = new FlowQuery([$siteNode]);
        $documentNodeQuery->pushOperation('find', ['[instanceof Neos.Neos:Document]']);
        $documentNodes = $documentNodeQuery->get();
        array_unshift($documentNodes, $siteNode);

        $this->output->outputLine('Found %s document nodes to translate', [sizeof($documentNodes)]);
        $this->output->progressStart(sizeof($documentNodes));

        $translationService = $this->objectManager->get(\Sitegeist\LostInTranslation\ContentRepository\NodeTranslationService::class);
        /** @var NodeInterface $documentNode */
        foreach ($documentNodes as $documentNode) {
            $translationService->afterNodePublish($documentNode, $context->getWorkspace());
            $this->output->progressAdvance();
        }

        $this->output->progressFinish();
        $this->quit();
    }

    /**
     * @param string $workspaceName
     * @param array $dimensions
     * @return ContentContext
     */
    protected function getContext(string $workspaceName = 'live', array $dimensions = [])
    {
        return $this->createContentContext($workspaceName, $dimensions);
    }
}
