<?php
namespace TYPO3\CMS\Backend\Form;

/*
 * This file is part of the TYPO3 CMS project.
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Create an element object depending on type.
 */
class NodeFactory
{
    /**
     * Default registry of node name to handling class
     *
     * @var array
     */
    protected $nodeTypes = array(
        'flex' => Container\FlexFormEntryContainer::class,
        'flexFormContainerContainer' => Container\FlexFormContainerContainer::class,
        'flexFormElementContainer' => Container\FlexFormElementContainer::class,
        'flexFormNoTabsContainer' => Container\FlexFormNoTabsContainer::class,
        'flexFormSectionContainer' => Container\FlexFormSectionContainer::class,
        'flexFormTabsContainer' => Container\FlexFormTabsContainer::class,
        'fullRecordContainer' => Container\FullRecordContainer::class,
        'inline' => Container\InlineControlContainer::class,
        'inlineRecordContainer' => Container\InlineRecordContainer::class,
        'listOfFieldsContainer' => Container\ListOfFieldsContainer::class,
        'noTabsContainer' => Container\NoTabsContainer::class,
        'outerWrapContainer' => Container\OuterWrapContainer::class,
        'paletteAndSingleContainer' => Container\PaletteAndSingleContainer::class,
        'singleFieldContainer' => Container\SingleFieldContainer::class,
        'soloFieldContainer' => Container\SoloFieldContainer::class,
        'tabsContainer' => Container\TabsContainer::class,

        'check' => Element\CheckboxElement::class,
        'group' => Element\GroupElement::class,
        'input' => Element\InputTextElement::class,
        'hidden' => Element\InputHiddenElement::class,
        // rsaInput is defined with a fallback so extensions can use it even if ext:rsaauth is not loaded
        'rsaInput' => Element\InputTextElement::class,
        'imageManipulation' => Element\ImageManipulationElement::class,
        'none' => Element\NoneElement::class,
        'radio' => Element\RadioElement::class,
        'selectCheckBox' => Element\SelectCheckBoxElement::class,
        'selectMultipleSideBySide' => Element\SelectMultipleSideBySideElement::class,
        'selectTree' => Element\SelectTreeElement::class,
        'selectSingle' => Element\SelectSingleElement::class,
        'selectSingleBox' => Element\SelectSingleBoxElement::class,
        'colorpicker' => Element\InputColorPickerElement::class,
        // t3editor is defined with a fallback so extensions can use it even if ext:t3editor is not loaded
        't3editor' => Element\TextElement::class,
        'text' => Element\TextElement::class,
        'unknown' => Element\UnknownElement::class,
        'user' => Element\UserElement::class,
    );

    /**
     * Node resolver classes
     * Nested array with nodeName as key, (sorted) priority as sub key and class as value
     *
     * @var array
     */
    protected $nodeResolver = array();

    /**
     * Set up factory. Initialize additionally registered nodes.
     */
    public function __construct()
    {
        $this->registerAdditionalNodeTypesFromConfiguration();
        $this->initializeNodeResolver();
    }

    /**
     * Create a node depending on type
     *
     * @param array $data All information to decide which class should be instantiated and given down to sub nodes
     * @return AbstractNode
     * @throws Exception
     */
    public function create(array $data)
    {
        if (empty($data['renderType'])) {
            throw new Exception(
                'Missing "renderType" in TCA of field "[' . $data['tableName'] . '][' . $data['fieldName'] . ']".',
                1431452406
            );
        }
        $type = $data['renderType'];

        $className = isset($this->nodeTypes[$type]) ? $this->nodeTypes[$type] : $this->nodeTypes['unknown'];

        if (!empty($this->nodeResolver[$type])) {
            // Resolver with highest priority is called first. If it returns with a new class name,
            // it will be taken and loop is aborted, otherwise resolver with next lower priority is called.
            foreach ($this->nodeResolver[$type] as $priority => $resolverClassName) {
                /** @var NodeResolverInterface $resolver */
                $resolver = $this->instantiate($resolverClassName, $data);
                if (!$resolver instanceof NodeResolverInterface) {
                    throw new Exception(
                        'Node resolver for type ' . $type . ' at priority ' . $priority . ' must implement NodeResolverInterface',
                        1433157422
                    );
                }
                // Resolver classes do NOT receive the name of the already resolved class. Single
                // resolvers should not have dependencies to each other or the default implementation,
                // so they also shouldn't know the output of a different resolving class.
                // Additionally, the globalOptions array is NOT given by reference here, changing config is a
                // task of container classes alone and must not be abused here.
                $newClassName = $resolver->resolve();
                if ($newClassName !== null) {
                    $className = $newClassName;
                    break;
                }
            }
        }

        /** @var AbstractNode $nodeInstance */
        $nodeInstance = $this->instantiate($className, $data);
        if (!$nodeInstance instanceof NodeInterface) {
            throw new Exception('Node of type ' . get_class($nodeInstance) . ' must implement NodeInterface', 1431872546);
        }
        return $nodeInstance;
    }

    /**
     * Add node types from nodeRegistry to $this->nodeTypes.
     * This can be used to add new render types or to overwrite existing node types. The registered class must
     * implement the NodeInterface and will be called if a node with this renderType is rendered.
     *
     * @throws Exception if configuration is incomplete or two nodes with identical priorities are registered
     */
    protected function registerAdditionalNodeTypesFromConfiguration()
    {
        // List of additional or override nodes
        $registeredTypeOverrides = $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry'];
        // Sanitize input array
        $registeredPrioritiesForNodeNames = array();
        foreach ($registeredTypeOverrides as $override) {
            if (!isset($override['nodeName']) || !isset($override['class']) || !isset($override['priority'])) {
                throw new Exception(
                    'Key class, nodeName or priority missing for an entry in $GLOBALS[\'TYPO3_CONF_VARS\'][\'SYS\'][\'formEngine\'][\'nodeRegistry\']',
                    1432207533
                );
            }
            if ($override['priority'] < 0 || $override['priority'] > 100) {
                throw new Exception(
                    'Priority of element ' . $override['nodeName'] . ' with class ' . $override['class'] . ' is ' . $override['priority'] . ', but must between 0 and 100',
                    1432223531
                );
            }
            if (isset($registeredPrioritiesForNodeNames[$override['nodeName']][$override['priority']])) {
                throw new Exception(
                    'Element ' . $override['nodeName'] . ' already has an override registered with priority ' . $override['priority'],
                    1432223893
                );
            }
            $registeredPrioritiesForNodeNames[$override['nodeName']][$override['priority']] = '';
        }
        // Add element with highest priority to registry
        $highestPriority = array();
        foreach ($registeredTypeOverrides as $override) {
            if (!isset($highestPriority[$override['nodeName']]) || $override['priority'] > $highestPriority[$override['nodeName']]) {
                $highestPriority[$override['nodeName']] = $override['priority'];
                $this->nodeTypes[$override['nodeName']] = $override['class'];
            }
        }
    }

    /**
     * Add resolver and add them sorted to a local property.
     * This can be used to manipulate the nodeName to class resolution with own code.
     *
     * @throws Exception if configuration is incomplete or two resolver with identical priorities are registered
     */
    protected function initializeNodeResolver()
    {
        // List of node resolver
        $registeredNodeResolvers = $GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeResolver'];
        $resolversByType = array();
        foreach ($registeredNodeResolvers as $nodeResolver) {
            if (!isset($nodeResolver['nodeName']) || !isset($nodeResolver['class']) || !isset($nodeResolver['priority'])) {
                throw new Exception(
                    'Key class, nodeName or priority missing for an entry in $GLOBALS[\'TYPO3_CONF_VARS\'][\'SYS\'][\'formEngine\'][\'nodeResolver\']',
                    1433155522
                );
            }
            if ($nodeResolver['priority'] < 0 || $nodeResolver['priority'] > 100) {
                throw new Exception(
                    'Priority of element ' . $nodeResolver['nodeName'] . ' with class ' . $nodeResolver['class'] . ' is ' . $nodeResolver['priority'] . ', but must between 0 and 100',
                    1433155563
                );
            }
            if (isset($resolversByType[$nodeResolver['nodeName']][$nodeResolver['priority']])) {
                throw new Exception(
                    'Element ' . $nodeResolver['nodeName'] . ' already has a resolver registered with priority ' . $nodeResolver['priority'],
                    1433155705
                );
            }
            $resolversByType[$nodeResolver['nodeName']][$nodeResolver['priority']] = $nodeResolver['class'];
        }
        $sortedResolversByType = array();
        foreach ($resolversByType as $nodeName => $prioritiesAndClasses) {
            krsort($prioritiesAndClasses);
            $sortedResolversByType[$nodeName] = $prioritiesAndClasses;
        }
        $this->nodeResolver = $sortedResolversByType;
    }

    /**
     * Instantiate given class name
     *
     * @param string $className Given class name
     * @param array $data Main data array
     * @return object
     */
    protected function instantiate($className, array $data)
    {
        return GeneralUtility::makeInstance($className, $this, $data);
    }
}
