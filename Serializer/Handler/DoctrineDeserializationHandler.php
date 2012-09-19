<?php

/*
 * Copyright 2011 Johannes M. Schmitt <schmittjoh@gmail.com>
 *
 * Licensed under the Apache License, Version 2.0 (the "License");
 * you may not use this file except in compliance with the License.
 * You may obtain a copy of the License at
 *
 * http://www.apache.org/licenses/LICENSE-2.0
 *
 * Unless required by applicable law or agreed to in writing, software
 * distributed under the License is distributed on an "AS IS" BASIS,
 * WITHOUT WARRANTIES OR CONDITIONS OF ANY KIND, either express or implied.
 * See the License for the specific language governing permissions and
 * limitations under the License.
 */

namespace JMS\SerializerBundle\Serializer\Handler;

use Symfony\Bridge\Doctrine\RegistryInterface;
use Doctrine\Common\Persistence\Proxy;
use Doctrine\Common\Persistence\Mapping\MappingException;
use JMS\SerializerBundle\Exception\RuntimeException;
use JMS\SerializerBundle\Serializer\VisitorInterface;
use JMS\SerializerBundle\Serializer\Handler\DeserializationHandlerInterface;
use JMS\SerializerBundle\Serializer\GenericSerializationVisitor;
use JMS\SerializerBundle\Serializer\XmlSerializationVisitor;

class DoctrineDeserializationHandler implements DeserializationHandlerInterface
{
    /**
     * @var \Symfony\Bridge\Doctrine\RegistryInterface
     */
    private $managerRegistry;

    /**
     * Constructor
     *
     * @param \Symfony\Bridge\Doctrine\RegistryInterface $managerRegistry
     */
    public function __construct(RegistryInterface $managerRegistry)
    {
        $this->managerRegistry = $managerRegistry;
    }

    /**
     * Get identifier
     *
     * @param array $data
     *
     * @return array
     */
    private function getIdentifier($class, $data)
    {
        if (!$class->isIdentifierComposite) {
            return array($class->identifier[0] => $data[$class->identifier[0]]);
        }

        $id = array();

        foreach ($class->identifier as $fieldName) {
            if (isset($class->associationMappings[$fieldName])) {
                $id[$fieldName] = $data[$class->associationMappings[$fieldName]['joinColumns'][0]['name']];
            } else {
                $id[$fieldName] = $data[$fieldName];
            }
        }

        return $id;
    }

    /**
     * Fetch an existing entity, or instantiate a new one
     *
     * @param mixed $objectManager
     * @param array $data
     * @param string $type
     *
     * @return mixed
     */
    private function prepareEntity($objectManager, $type, $data)
    {
        $cmf   = $objectManager->getMetadataFactory();
        $class = $cmf->getMetadataFor($type);
        $id    = $this->getIdentifier($class, $data);

        if (($obj = $objectManager->find($type, $id)) !== null) {
            return $obj;
        }

        $type = '\\' . $type;

        return new $type;
    }

    /**
     * Deserialize data returning a Proxy if it is an Entity
     *
     * @param VisitorInterface $visitor
     * @param array $data
     * @param string $type
     * @param boolean $visited
     *
     * @return mixed
     *
     * @throws \RuntimeException
     */
    public function deserialize(VisitorInterface $visitor, $data, $type, &$visited)
    {
        // Check for only class existence. Ignore invalid ones
        if (!class_exists($type)) {
            return;
        }

        // Check for valid Entity
        $objectManager = $this->managerRegistry->getEntityManagerForClass($type);

        if (!$objectManager) {
            return;
        }

        // Avoid deserializing if exclusion strategy is applied
        $navigator          = $visitor->getNavigator();
        $exclusionStrategy  = $navigator->getExclusionStrategy();
        $serializerMetadata = $navigator->getMetadataFactory()->getMetadataForClass($type);

        if (null !== $exclusionStrategy && $exclusionStrategy->shouldSkipClass($serializerMetadata)) {
            return;
        }

        // Loading proxy
        $visited = true;
        $entity  = $this->prepareEntity($objectManager, $type, $data);

        if (!$entity) {
            throw new RuntimeException(sprintf('Unable to create or retrieve entity "%s".', $type));
        }

        $visitor->setCurrentObject($entity);

        if (null === $visitor->getResult()) {
            $visitor->setResult($entity);
        }

        // Load information for properties
        foreach ($serializerMetadata->propertyMetadata as $propertyMetadata) {
            if (null !== $exclusionStrategy && $exclusionStrategy->shouldSkipProperty($propertyMetadata)) {
                continue;
            }

            if ($propertyMetadata->readOnly) {
                continue;
            }

            // try custom handler
            if (!$visitor->visitPropertyUsingCustomHandler($propertyMetadata, $data)) {
                $visitor->visitProperty($propertyMetadata, $data);
            }
        }

        // Finish object visiting
        $result = $visitor->endVisitingObject($serializerMetadata, $data, $type);

        foreach ($serializerMetadata->postDeserializeMethods as $method) {
            $method->invoke($result);
        }

        return $entity;
    }
}
