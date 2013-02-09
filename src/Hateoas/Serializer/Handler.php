<?php

namespace Hateoas\Serializer;

use Hateoas\Collection;
use Hateoas\Link;
use Hateoas\Resource;
use JMS\Serializer\Handler\SubscribingHandlerInterface;
use JMS\Serializer\GraphNavigator;
use JMS\Serializer\JsonSerializationVisitor;
use JMS\Serializer\XmlSerializationVisitor;
use Metadata\MetadataFactoryInterface;

class Handler implements SubscribingHandlerInterface
{
    private $metadataFactory;

    public static function getSubscribingMethods()
    {
        return array(
            array(
                'direction' => GraphNavigator::DIRECTION_SERIALIZATION,
                'format'    => 'xml',
                'type'      => 'Hateoas\Resource',
                'method'    => 'serializeResourceToXml',
            ),
            array(
                'direction' => GraphNavigator::DIRECTION_SERIALIZATION,
                'format'    => 'xml',
                'type'      => 'Hateoas\Collection',
                'method'    => 'serializeCollectionToXml',
            ),
            array(
                'direction' => GraphNavigator::DIRECTION_SERIALIZATION,
                'format'    => 'json',
                'type'      => 'Hateoas\Resource',
                'method'    => 'serializeResourceToJson',
            ),
            array(
                'direction' => GraphNavigator::DIRECTION_SERIALIZATION,
                'format'    => 'json',
                'type'      => 'Hateoas\Collection',
                'method'    => 'serializeCollectionToJson',
            ),
        );
    }

    public function __construct(MetadataFactoryInterface $metadataFactory)
    {
        $this->metadataFactory = $metadataFactory;
    }

    public function serializeResourceToXml(XmlSerializationVisitor $visitor, Resource $resource, array $type)
    {
        if (null === $visitor->document) {
            $reflClass = new \ReflectionClass(get_class($visitor));
            $reflProp  = $reflClass->getProperty('defaultRootName');
            $reflProp->setAccessible(true);

            if ('result' === $reflProp->getValue($visitor)) {
                if (is_object($resource->getData())
                    && null !== ($m = $this->metadataFactory->getMetadataForClass(get_class($resource->getData())))
                ) {
                    $visitor->setDefaultRootName($m->xmlRootName ?: 'resource');
                }
            }

            $visitor->document = $visitor->createDocument();
        }

        // links
        foreach ($resource->getLinks() as $link) {
            $linkNode = $visitor->getDocument()->createElement('link');
            $visitor->getCurrentNode()->appendChild($linkNode);
            $visitor->setCurrentNode($linkNode);

            if (null !== $node = $visitor->getNavigator()->accept($link, null, $visitor)) {
                $visitor->getCurrentNode()->appendChild($node);
            }

            $visitor->revertCurrentNode();
        }

        // inline data
        return $visitor->getNavigator()->accept($resource->getData(), null, $visitor);
    }

    public function serializeCollectionToXml(XmlSerializationVisitor $visitor, Collection $collection, array $type)
    {
        if (null === $visitor->document) {
            $visitor->setDefaultRootName('resources');
            $visitor->document = $visitor->createDocument();
        }

        // attributes
        foreach (array('total', 'page', 'limit') as $attr) {
            if ($value = $collection->{'get' . ucfirst($attr)}()) {
                $visitor->getCurrentNode()->setAttribute($attr, $value);
            }
        }

        // links
        foreach ($collection->getLinks() as $link) {
            $linkNode = $visitor->getDocument()->createElement('link');
            $visitor->getCurrentNode()->appendChild($linkNode);
            $visitor->setCurrentNode($linkNode);

            if (null !== $node = $visitor->getNavigator()->accept($link, null, $visitor)) {
                $visitor->getCurrentNode()->appendChild($node);
            }

            $visitor->revertCurrentNode();
        }

        // resources
        foreach ($collection->getResources() as $resource) {
            $elementName = 'resource';
            if (is_object($resource->getData())
                && null !== ($m = $this->metadataFactory->getMetadataForClass(get_class($resource->getData())))
            ) {
                $elementName = $m->xmlRootName ?: 'resource';
            }

            $entryNode = $visitor->getDocument()->createElement($elementName);
            $visitor->getCurrentNode()->appendChild($entryNode);
            $visitor->setCurrentNode($entryNode);

            if (null !== $node = $visitor->getNavigator()->accept($resource, null, $visitor)) {
                $visitor->getCurrentNode()->appendChild($node);
            }

            $visitor->revertCurrentNode();
        }
    }

    public function serializeResourceToJson(JsonSerializationVisitor $visitor, Resource $resource, array $type)
    {
        $metadata  = $this->metadataFactory
            ->getMetadataForClass(get_class($resource))
            ->propertyMetadata['links'];
        $linksName = $metadata->serializedName ?: '_links';

        // inline
        $data = $visitor->getNavigator()->accept($resource->getData(), null, $visitor);
        $data[$linksName] = $this->getLinksFrom($resource);

        $root = $visitor->getRoot();

        if (!empty($root)) {
            $visitor->setRoot($data);
        }

        return $data;
    }

    public function serializeCollectionToJson(JsonSerializationVisitor $visitor, Collection $collection, array $type)
    {
        $metadata  = $this->metadataFactory
            ->getMetadataForClass(get_class($collection))
            ->propertyMetadata['links'];
        $linksName = $metadata->serializedName ?: '_links';

        // attributes
        foreach (array('total', 'page', 'limit') as $attr) {
            if ($value = $collection->{'get' . ucfirst($attr)}()) {
                $data[$attr] = $value;
            }
        }

        // links
        $data[$linksName] = $this->getLinksFrom($collection);

        // resources
        $data['resources'] = $visitor->getNavigator()->accept($collection->getResources(), null, $visitor);

        $visitor->setRoot($data);
    }

    private function getLinksFrom($object)
    {
        $links = array();
        foreach ($object->getLinks() as $link) {
            $data         = array();
            $data['href'] = $link->getHref();

            if (null !== $type = $link->getType()) {
                $data['type'] = $type;
            }

            if (isset($links[$link->getRel()])) {
                // in order to support multiple links per "rel"
                // we need to transform the "rel" element into
                // an array, so that we can add oher "rel"
                // elements
                if (isset($links[$link->getRel()]['href'])) {
                    $element = $links[$link->getRel()];

                    $links[$link->getRel()] = array();
                    $links[$link->getRel()][] = $element;
                }

                $links[$link->getRel()][] = $data;
            } else {
                $links[$link->getRel()] = $data;
            }
        }

        return $links;
    }
}
