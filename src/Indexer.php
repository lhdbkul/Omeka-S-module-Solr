<?php

/*
 * Copyright BibLibre, 2016-2017
 *
 * This software is governed by the CeCILL license under French law and abiding
 * by the rules of distribution of free software.  You can use, modify and/ or
 * redistribute the software under the terms of the CeCILL license as circulated
 * by CEA, CNRS and INRIA at the following URL "http://www.cecill.info".
 *
 * As a counterpart to the access to the source code and rights to copy, modify
 * and redistribute granted by the license, users are provided only with a
 * limited warranty and the software's author, the holder of the economic
 * rights, and the successive licensors have only limited liability.
 *
 * In this respect, the user's attention is drawn to the risks associated with
 * loading, using, modifying and/or developing or reproducing the software by
 * the user in light of its specific status of free software, that may mean that
 * it is complicated to manipulate, and that also therefore means that it is
 * reserved for developers and experienced professionals having in-depth
 * computer knowledge. Users are therefore encouraged to load and test the
 * software's suitability as regards their requirements in conditions enabling
 * the security of their systems and/or data to be ensured and, more generally,
 * to use and operate it in the same conditions as regards security.
 *
 * The fact that you are presently reading this means that you have had
 * knowledge of the CeCILL license and that you accept its terms.
 */

namespace Solr;

use Omeka\Entity\Resource;
use Search\Indexer\AbstractIndexer;
use Stringable;

class Indexer extends AbstractIndexer
{
    protected $client;
    protected $solrNode;

    public function canIndex($resourceName)
    {
        $serviceLocator = $this->getServiceLocator();
        $valueExtractorManager = $serviceLocator->get('Solr\ValueExtractorManager');
        $valueExtractor = $valueExtractorManager->get($resourceName);

        return isset($valueExtractor);
    }

    public function clearIndex()
    {
        $client = $this->getClient();
        $client->ping();
        $client->deleteByQuery('*:*');
        $client->commit();
    }

    public function indexResource(Resource $resource)
    {
        $this->getClient()->ping();
        $this->addResource($resource);
        $this->commit();
    }

    public function indexResources(array $resources)
    {
        $this->getClient()->ping();
        foreach ($resources as $resource) {
            $this->addResource($resource);
        }
        $this->commit();
    }

    public function deleteResource($resourceName, $resourceId)
    {
        $client = $this->getClient();
        $client->ping();
        $id = $this->getDocumentId($resourceName, $resourceId);
        $client->deleteById($id);
        $this->commit();
    }

    protected function getDocumentId($resourceName, $resourceId)
    {
        return sprintf('%s:%s', $resourceName, $resourceId);
    }

    protected function addResource(Resource $resource)
    {
        $serviceLocator = $this->getServiceLocator();
        $api = $serviceLocator->get('Omeka\ApiManager');
        $settings = $serviceLocator->get('Omeka\Settings');
        $valueExtractorManager = $serviceLocator->get('Solr\ValueExtractorManager');
        $transformationManager = $serviceLocator->get('Solr\TransformationManager');
        $entityManager = $serviceLocator->get('Omeka\EntityManager');
        $eventManager = $serviceLocator->get('EventManager');

        $resource = $api->read($resource->getResourceName(), $resource->getId())->getContent();

        $client = $this->getClient();

        $resourceName = $resource->resourceName();
        $id = $this->getDocumentId($resourceName, $resource->id());

        $solrNode = $this->getSolrNode();
        $solrNodeSettings = $solrNode->settings();

        $document = new SolrInputDocument;
        $document->addField('id', $id);
        $resource_name_field = $solrNodeSettings['resource_name_field'];
        $document->addField($resource_name_field, $resourceName);

        $sites_field = $solrNodeSettings['sites_field'];
        if ($sites_field) {
            if ($resourceName === 'items') {
                $sites = $api->search('sites')->getContent();
                foreach ($sites as $site) {
                    $query = ['id' => $resource->id(), 'site_id' => $site->id()];
                    $res = $api->search('items', $query)->getContent();
                    if (!empty($res)) {
                        $document->addField($sites_field, $site->id());
                    }
                }
            } elseif ($resourceName === 'item_sets') {
                $qb = $entityManager->createQueryBuilder();
                $qb->select('siteItemSet')
                    ->from('Omeka\Entity\SiteItemSet', 'siteItemSet')
                    ->innerJoin('siteItemSet.itemSet', 'itemSet')
                    ->where($qb->expr()->eq('itemSet.id', $resource->id()));
                $siteItemSets = $qb->getQuery()->getResult();
                foreach ($siteItemSets as $siteItemSet) {
                    $document->addField($sites_field, $siteItemSet->getSite()->getId());
                }
            }
        }

        $is_public_field = $solrNodeSettings['is_public_field'];
        if ($is_public_field) {
            $document->addField($is_public_field, $resource->isPublic());
        }

        $solrMappings = $api->search('solr_mappings', [
            'resource_name' => $resourceName,
            'solr_node_id' => $solrNode->id(),
        ])->getContent();

        $schema = $solrNode->schema();

        $valueExtractor = $valueExtractorManager->get($resourceName);
        foreach ($solrMappings as $solrMapping) {
            $solrField = $solrMapping->fieldName();
            $source = $solrMapping->source();
            $solrMappingSettings = $solrMapping->settings();
            $values = $valueExtractor->extractValue($resource, $source, $solrMappingSettings);

            if (!is_array($values)) {
                $values = [$values];
            }

            $transformations = $solrMappingSettings['transformations'] ?? [];
            foreach ($transformations as $transformationData) {
                $transformation = $transformationManager->get($transformationData['name']);
                $values = $transformation->transform($values, $transformationData);
            }

            $values = array_filter($values, fn ($v) => is_scalar($v) || $v instanceof Stringable);

            $schemaField = $schema->getField($solrField);
            if (!$schemaField->isMultivalued()) {
                $values = array_slice($values, 0, 1);
            }

            foreach ($values as $value) {
                $document->addField($solrField, (string) $value);
            }
        }

        $eventManager->setIdentifiers(['Solr\Indexer']);
        $eventManager->trigger('solr.indexDocument', $document, ['resource' => $resource, 'solrNode' => $solrNode]);

        try {
            $client->addDocument($document);
        } catch (\Exception $e) {
            $this->getLogger()->err($e);
            $this->getLogger()->err(sprintf('Indexing of resource %s failed', $resource->id()));
        }
    }

    protected function commit()
    {
        $this->getClient()->commit();
    }

    protected function getClient()
    {
        if (!isset($this->client)) {
            $this->client = $this->getSolrNode()->client();
        }

        return $this->client;
    }

    protected function getSolrNode()
    {
        if (!isset($this->solrNode)) {
            $api = $this->getServiceLocator()->get('Omeka\ApiManager');

            $solrNodeId = $this->getAdapterSetting('solr_node_id');
            if ($solrNodeId) {
                $response = $api->read('solr_nodes', $solrNodeId);
                $this->solrNode = $response->getContent();
            }
        }

        return $this->solrNode;
    }
}
