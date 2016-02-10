<?php



/*!
  \class eZContentClassPackageHandler ezcontentclasspackagehandler.php
  \brief Handles content classes in the package system

*/

class psdContentClassPackageHandler extends eZPackageHandler
{
    const ERROR_EXISTS = 1;
    const ERROR_HAS_OBJECTS = 101;

    const ACTION_REPLACE = 1;
    const ACTION_SKIP = 2;
    const ACTION_NEW = 3;
    const ACTION_DELETE = 4;

    /*!
     Constructor
    */
    public function __construct()
    {
        $this->eZPackageHandler( 'ezcontentclass',
                                 array( 'extract-install-content' => true ) );
    }

    /*!
     Returns an explanation for the content class install item.
     Use $requestedInfo to request portion of info.
    */
    function explainInstallItem( $package, $installItem, $requestedInfo = array( 'name', 'identifier', 'description', 'language_info' ) )
    {
        if ( $installItem['filename'] )
        {
            $explainClassName = in_array( 'name', $requestedInfo );
            $explainClassIdentitier = in_array( 'identifier', $requestedInfo );
            $explainDescription = in_array( 'description', $requestedInfo );
            $explainLanguageInfo = in_array( 'language_info', $requestedInfo );

            $filename = $installItem['filename'];
            $subdirectory = $installItem['sub-directory'];
            if ( $subdirectory )
                $filepath = $subdirectory . '/' . $filename . '.xml';
            else
                $filepath = $filename . '.xml';

            $filepath = $package->path() . '/' . $filepath;

            $dom = $package->fetchDOMFromFile( $filepath );
            if ( $dom )
            {
                $languageInfo = array();

                $content = $dom->documentElement;
                $classIdentifier = $explainClassIdentitier ? $content->getElementsByTagName( 'identifier' )->item( 0 )->textContent : '';

                $className = '';
                if ( $explainClassName )
                {
                    // BC ( <= 3.8 )
                    $classNameNode = $content->getElementsByTagName( 'name' )->item( 0 );

                    if( $classNameNode )
                    {
                        $className = $classNameNode->textContent;
                    }
                    else
                    {
                        // get info about translations.
                        $serializedNameListNode = $content->getElementsByTagName( 'serialized-name-list' )->item( 0 );
                        if( $serializedNameListNode )
                        {
                            $serializedNameList = $serializedNameListNode->textContent;
                            $nameList = new eZContentClassNameList( $serializedNameList );
                            $languageInfo = $explainLanguageInfo ? $nameList->languageLocaleList() : array();
                            $className = $nameList->name();
                        }
                    }
                }

                $description = $explainDescription ? ezpI18n::tr( 'kernel/package', "Content class '%classname' (%classidentifier)", false,
                                                             array( '%classname' => $className,
                                                                    '%classidentifier' => $classIdentifier ) ) : '';
                $explainInfo = array( 'description' => $description,
                                      'language_info' => $languageInfo );
                return $explainInfo;
            }
        }
    }

    /*!
     Uninstalls all previously installed content classes.
    */
    function uninstall( $package, $installType, $parameters,
                      $name, $os, $filename, $subdirectory,
                      $content, &$installParameters,
                      &$installData )
    {
        $classRemoteID = $content->getElementsByTagName( 'remote-id' )->item( 0 )->textContent;

        $class = eZContentClass::fetchByRemoteID( $classRemoteID );

        if ( $class == null )
        {
            eZDebug::writeNotice( "Class having remote id '$classRemoteID' not found.", __METHOD__ );
            return true;
        }

        if ( $class->isRemovable() )
        {
            $choosenAction = $this->errorChoosenAction( self::ERROR_HAS_OBJECTS,
                                                        $installParameters, false, $this->HandlerType );
            if ( $choosenAction == self::ACTION_SKIP )
            {
                return true;
            }
            if ( $choosenAction != self::ACTION_DELETE )
            {
                $objectsCount = eZContentObject::fetchSameClassListCount( $class->attribute( 'id' ) );
                $name = $class->attribute( 'name' );
                if ( $objectsCount )
                {
                    $installParameters['error'] = array( 'error_code' => self::ERROR_HAS_OBJECTS,
                                                         'element_id' => $classRemoteID,
                                                         'description' => ezpI18n::tr( 'kernel/package',
                                                                                  "Removing class '%classname' will result in the removal of %objectscount object(s) of this class and all their sub-items. Are you sure you want to uninstall it?",
                                                                                  false,
                                                                                  array( '%classname' => $name,
                                                                                         '%objectscount' => $objectsCount ) ),
                                                         'actions' => array( self::ACTION_DELETE => "Uninstall class and object(s)",
                                                                             self::ACTION_SKIP => 'Skip' ) );
                    return false;
                }
            }

            eZDebug::writeNotice( sprintf( "Removing class '%s' (%d)", $class->attribute( 'name' ), $class->attribute( 'id' ) ) );

            eZContentClassOperations::remove( $class->attribute( 'id' ) );
        }

        return true;
    }

    /**
     * Creates or update a contentclass as defined in the xml structure.
     *
     * @param psdPackage    $package
     * @param string        $installType
     * @param array         $parameters
     * @param string        $name
     * @param string        $os
     * @param string        $filename
     * @param string        $subdirectory
     * @param DOMElement    $content
     * @param array         $installParameters
     * @param array         $installData
     *
     * @return bool
     */
    function install(
        $package,
        $installType,
        $parameters,
        $name,
        $os,
        $filename,
        $subdirectory,
        $content,
        &$installParameters,
        &$installData
    )
    {
        $serializedNameListNode = $content->getElementsByTagName('serialized-name-list')->item( 0 );
        $serializedNameList     = $serializedNameListNode ? $this->unserializeJSON($serializedNameListNode->textContent) : false;
        $classNameList          = new eZContentClassNameList($serializedNameList);

        if ($classNameList->isEmpty()) {
            // for backward compatibility( <= 3.8 )
            $classNameList->initFromString($content->getElementsByTagName('name')->item(0)->textContent);
        }
        $classNameList->validate();

        $serializedDescriptionListNode = $content->getElementsByTagName('serialized-description-list')->item(0);
        $serializedDescriptionList     = $serializedDescriptionListNode ? $this->unserializeJSON($serializedDescriptionListNode->textContent) : false;
        $classDescriptionList          = new eZSerializedObjectNameList($serializedDescriptionList);

        $classIdentifier        = $content->getElementsByTagName('identifier')->item(0)->textContent;
        $classRemoteID          = $content->getElementsByTagName('remote-id')->item(0)->textContent;
        $classObjectNamePattern = $content->getElementsByTagName('object-name-pattern')->item(0)->textContent;
        $classURLAliasPattern   = is_object($content->getElementsByTagName('url-alias-pattern')->item(0)) ?
            $content->getElementsByTagName('url-alias-pattern')->item(0)->textContent :
            null;
        $classIsContainer = $content->getAttribute('is-container');

        if ($classIsContainer !== false)
            $classIsContainer = $classIsContainer == 'true' ? 1 : 0;

        /** @var DOMNode $classRemoteNode */
        $classRemoteNode   = $content->getElementsByTagName('remote')->item(0);
        $classID           = $classRemoteNode->getElementsByTagName('id')->item(0)->textContent;
        $classGroupsNode   = $classRemoteNode->getElementsByTagName('groups')->item(0);
        $classCreated      = $classRemoteNode->getElementsByTagName('created')->item(0)->textContent;
        $classModified     = $classRemoteNode->getElementsByTagName('modified')->item(0)->textContent;
        $classCreatorNode  = $classRemoteNode->getElementsByTagName('creator')->item(0);
        $classModifierNode = $classRemoteNode->getElementsByTagName('modifier')->item(0);

        $classAttributesNode = $content->getElementsByTagName('attributes')->item(0);

        $dateTime     = time();
        $classCreated = $dateTime;

        if (empty($classModified)) {
            $classModified = "0";
        }

        $userID = false;

        if (isset($installParameters['user_id'])) {
            $userID = $installParameters['user_id'];
        }

        $class = eZContentClass::fetchByRemoteID($classRemoteID);

        if ($class) {
            $className   = $class->name();
            $description = ezpI18n::tr(
                'kernel/package',
                "Class '%classname' already exists.",
                false,
                ['%classname' => $className]
            );

            $chosenAction = $this->errorChoosenAction(
                self::ERROR_EXISTS,
                $installParameters,
                $description,
                $this->HandlerType
            );

            switch ($chosenAction) {
                case eZPackage::NON_INTERACTIVE:
                case self::ACTION_REPLACE:
                    // Create Definition array for syncing.
                    $classDefinition = [
                        'version'                     => 0,
                        'serialized_name_list'        => $classNameList->serializeNames(),
                        'serialized_description_list' => $classDescriptionList->serializeNames(),
                        'identifier'                  => $classIdentifier,
                        'remote_id'                   => $classRemoteID,
                        'contentobject_name'          => $classObjectNamePattern,
                        'url_alias_name'              => $classURLAliasPattern,
                        'is_container'                => $classIsContainer,
                        'created'                     => $classCreated,
                        'modified'                    => $classModified
                    ];

                    if ($content->hasAttribute('sort-field')) {
                        $classDefinition['sort_field'] = eZContentObjectTreeNode::sortFieldID(
                            $content->getAttribute('sort-field')
                        );
                    }

                    if ($content->hasAttribute('sort-order')) {
                        $classDefinition['sort_order'] = $content->getAttribute('sort-order');
                    }

                    if ( $content->hasAttribute('always-available')) {
                        $classDefinition['always_available'] = ($content->getAttribute( 'always-available') === 'true' ? 1 : 0 );
                    }

                    // Update class attribute.
                    foreach ($classDefinition as $key => $value) {
                        $class->setAttribute($key, $value);
                    }
                    $class->NameList = $classNameList;
                    $class->store();

                    // Merge the existing class attributes with new ones.
                    $this->mergeWithExisting($class, $classAttributesNode);

                    // add class to a class group
                    $this->addClassToClassGroup($class, $classGroupsNode);

                    eZDebug::writeNotice("Class '$className' will be merged.", __METHOD__);
                    return true;

                case self::ACTION_SKIP:
                    return true;

                case self::ACTION_NEW:
                    $class->setAttribute('remote_id', eZRemoteIdUtility::generate('class'));
                    $class->store();
                    $classNameList->appendGroupName(" (imported)");
                    break;

                default:
                    $installParameters['error'] = [
                        'error_code'  => self::ERROR_EXISTS,
                        'element_id'  => $classRemoteID,
                        'description' => $description,
                        'actions'     => []
                    ];

                    if ($class->isRemovable())
                    {
                        $errorMsg     = ezpI18n::tr( 'kernel/package', "Replace existing class" );
                        $objectsCount = eZContentObject::fetchSameClassListCount( $class->attribute( 'id' ) );
                        if ($objectsCount)
                            $errorMsg .= ' '.ezpI18n::tr(
                                'kernel/package',
                                "(Warning! $objectsCount content object(s) and their sub-items will be removed)"
                            );
                        $installParameters['error']['actions'][self::ACTION_REPLACE] = $errorMsg;
                    }

                    $installParameters['error']['actions'][self::ACTION_SKIP] = ezpI18n::tr(
                        'kernel/package', 'Skip installing this class'
                    );
                    $installParameters['error']['actions'][self::ACTION_NEW] = ezpI18n::tr(
                        'kernel/package', 'Keep existing and create a new one'
                    );
                    return false;
                }
        }

        unset($class);

        // Try to create a unique class identifier
        $currentClassIdentifier = $classIdentifier;
        $unique                 = false;

        while (!$unique) {
            $classList = eZContentClass::fetchByIdentifier($currentClassIdentifier);
            if ($classList) {
                // "increment" class identifier
                if (preg_match( '/^(.*)_(\d+)$/', $currentClassIdentifier, $matches)) {
                    $currentClassIdentifier = $matches[1] . '_' . ( $matches[2] + 1 );
                } else {
                    $currentClassIdentifier = $currentClassIdentifier . '_1';
                }
            } else {
                $unique = true;
            }

            unset($classList);
        }

        $classIdentifier = $currentClassIdentifier;

        $values = [
            'version'                     => 0,
            'serialized_name_list'        => $classNameList->serializeNames(),
            'serialized_description_list' => $classDescriptionList->serializeNames(),
            'create_lang_if_not_exist'    => true,
            'identifier'                  => $classIdentifier,
            'remote_id'                   => $classRemoteID,
            'contentobject_name'          => $classObjectNamePattern,
            'url_alias_name'              => $classURLAliasPattern,
            'is_container'                => $classIsContainer,
            'created'                     => $classCreated,
            'modified'                    => $classModified
        ];

        if ($content->hasAttribute('sort-field')) {
            $values['sort_field'] = eZContentObjectTreeNode::sortFieldID($content->getAttribute('sort-field'));
        } else {
            eZDebug::writeNotice(
                'The sort field was not specified in the content class package. '.
                'This property is exported and imported since eZ Publish 4.0.2',
                __METHOD__
            );
        }

        if ($content->hasAttribute('sort-order')) {
            $values['sort_order'] = $content->getAttribute('sort-order');
        } else {
            eZDebug::writeNotice(
                'The sort order was not specified in the content class package. '.
                'This property is exported and imported since eZ Publish 4.0.2',
                __METHOD__
            );
        }

        if ($content->hasAttribute('always-available')) {
            $values['always_available'] = ($content->getAttribute('always-available') === 'true' ? 1 : 0);
        } else {
            eZDebug::writeNotice(
                'The default object availability was not specified in the content class package. ' .
                'This property is exported and imported since eZ Publish 4.0.2',
                __METHOD__
            );
        }

        // create class
        $class = eZContentClass::create($userID, $values);
        $class->store();

        $classID = $class->attribute('id');

        if (!isset($installData['classid_list'])) {
            $installData['classid_list'] = [];
        }

        if (!isset($installData['classid_map'])) {
            $installData['classid_map'] = [];
        }

        $installData['classid_list'][]        = $class->attribute( 'id' );
        $installData['classid_map'][$classID] = $class->attribute( 'id' );

        // create class attributes
        $classAttributeList = $classAttributesNode->getElementsByTagName('attribute');

        foreach ($classAttributeList as $classAttributeNode) {
            $isNotSupported = strtolower($classAttributeNode->getAttribute('unsupported')) == 'true';

            if ($isNotSupported) {
                continue;
            }

            $attributeDatatype                  = $classAttributeNode->getAttribute('datatype');
            $attributeIsRequired                = strtolower($classAttributeNode->getAttribute('required')) == 'true';
            $attributeIsSearchable              = strtolower($classAttributeNode->getAttribute('searchable')) == 'true';
            $attributeIsInformationCollector    = strtolower($classAttributeNode->getAttribute('information-collector')) == 'true';
            $attributeIsTranslatable            = strtolower($classAttributeNode->getAttribute('translatable')) == 'true';
            $attributeSerializedNameListNode    = $classAttributeNode->getElementsByTagName( 'serialized-name-list')->item(0);
            $attributeSerializedNameListContent = $attributeSerializedNameListNode ? $this->unserializeJSON($attributeSerializedNameListNode->textContent) : false;
            $attributeSerializedNameList        = new eZSerializedObjectNameList($attributeSerializedNameListContent);

            if ($attributeSerializedNameList->isEmpty()) {
                // for backward compatibility( <= 3.8 )
                $attributeSerializedNameList->initFromString(
                    $classAttributeNode->getElementsByTagName('name')->item(0)->textContent
                );
            }

            $attributeSerializedNameList->validate();

            $attributeSerializedDescriptionListNode    = $classAttributeNode->getElementsByTagName('serialized-description-list')->item(0);
            $attributeSerializedDescriptionListContent = $attributeSerializedDescriptionListNode ? $this->unserializeJSON($attributeSerializedDescriptionListNode->textContent) : false;
            $attributeSerializedDescriptionList        = new eZSerializedObjectNameList( $attributeSerializedDescriptionListContent );

            $attributeCategoryNode = $classAttributeNode->getElementsByTagName('category')->item(0);
            $attributeCategory     = $attributeCategoryNode ? $attributeCategoryNode->textContent : '';

            $attributeSerializedDataTextNode    = $classAttributeNode->getElementsByTagName('serialized-description-text')->item(0);
            $attributeSerializedDataTextContent = $attributeSerializedDataTextNode ? $this->unserializeJSON($attributeSerializedDataTextNode->textContent) : false;
            $attributeSerializedDataText        = new eZSerializedObjectNameList($attributeSerializedDataTextContent);

            $attributeIdentifier            = $classAttributeNode->getElementsByTagName('identifier')->item(0)->textContent;
            $attributePlacement             = $classAttributeNode->getElementsByTagName('placement')->item(0)->textContent;
            $attributeDatatypeParameterNode = $classAttributeNode->getElementsByTagName('datatype-parameters')->item(0);

            $classAttribute = $class->fetchAttributeByIdentifier($attributeIdentifier);

            if (!$classAttribute) {
                $classAttribute = eZContentClassAttribute::create(
                    $class->attribute('id'),
                    $attributeDatatype,
                    [
                        'version'                     => 0,
                        'identifier'                  => $attributeIdentifier,
                        'serialized_name_list'        => $attributeSerializedNameList->serializeNames(),
                        'serialized_description_list' => $attributeSerializedDescriptionList->serializeNames(),
                        'category'                    => $attributeCategory,
                        'serialized_data_text'        => $attributeSerializedDataText->serializeNames(),
                        'is_required'                 => $attributeIsRequired,
                        'is_searchable'               => $attributeIsSearchable,
                        'is_information_collector'    => $attributeIsInformationCollector,
                        'can_translate'               => $attributeIsTranslatable,
                        'placement'                   => $attributePlacement
                    ]
                );

                $dataType = $classAttribute->dataType();
                $classAttribute->store();
                $dataType->unserializeContentClassAttribute(
                    $classAttribute,
                    $classAttributeNode,
                    $attributeDatatypeParameterNode
                );
                $classAttribute->sync();
            }
        }

        // add class to a class group
        $this->addClassToClassGroup($class, $classGroupsNode);

        return true;

    }


    /**
     * Add class to class groups.
     *
     * @param eZContentClass    $class              Content class object.
     * @param DOMNode           $classGroupsNode    Class group dom node.
     */
    function addClassToClassGroup($class, $classGroupsNode)
    {
        $classGroupsList = $classGroupsNode->getElementsByTagName('group');
        $inClassGroupList = [];

        foreach ($classGroupsList as $classGroupNode) {

            // Find class group by name.
            $classGroupName = $classGroupNode->getAttribute('name');
            $classGroupID   = $classGroupNode->getAttribute('id');

            if ($classGroup = eZContentClassGroup::fetchByName($classGroupName)) {
                // Use it.
            } elseif ($classGroup = eZContentClassGroup::fetch($classGroupID)) {
                // Update name and use it.
                $classGroup->setAttribute('name', $classGroupName);
                $classGroup->store();
            } else {
                // Create it.
                $classGroup = eZContentClassGroup::create();
                $classGroup->setAttribute('id', $classGroupID);
                $classGroup->setAttribute('name', $classGroupName);
                $classGroup->store();
            }

            $inClassGroupList[] = $classGroup->attribute('id');

            // Append if class is not in group.
            if (!$class->inGroup($classGroup->attribute('id'))) {
                $classGroup->appendClass($class);
            }
        }

        // Remove class from deprecated class group.
        foreach ($class->attribute('ingroup_id_list') as $groupID) {
            if (!in_array($groupID, $inClassGroupList)) {
                eZClassFunctions::removeGroup($class->attribute('id'), null, [$groupID]);
            }
        }
    }


    function add( $packageType, $package, $cli, $parameters )
    {
        foreach ( $parameters['class-list'] as $classItem )
        {
            $classID = $classItem['id'];
            $classIdentifier = $classItem['identifier'];
            $classValue = $classItem['value'];
            $cli->notice( "Adding class $classValue to package" );
            $this->addClass( $package, $classID, $classIdentifier );
        }
    }

    /**
     * Merge the existing class with new values.
     *
     * @param eZContentClass $class
     * @param $values
     *
     * @return bool
     */
    public function mergeExistingClass( eZContentClass $class, $values )
    {
        foreach( $values as $key => $value )
        {
            $class->setAttribute( $key, $value );
        }

        if (isset($values['serialized_name_list'])) {
            $class->NameList = new eZContentClassNameList();
            $class->NameList->initFromSerializedList($values['serialized_name_list']);
        }

        if (isset($values['serialized_description_list'])) {
            $class->DescriptionList = new eZContentClassNameList();
            $class->DescriptionList->initFromSerializedList($values['serialized_description_list']);
        }

        $class->store();
        eZDebug::writeNotice( 'Class ' . $class->attribute( 'identifier' ) . ' updated.' );
        return true;
    }


    /**
     * Merges the existing class-attributes with the imported xml content.
     * The diff between the attributes is searched by identifer.
     * Processing types:
     * - attribute_was_found : we replace the name and other data.
     * - attribute_was_not_found : we create a new one.
     *
     * In the aftercheck we loop through the existing attributes and check if they was commited.
     *
     * @param eZContentClass $class
     * @param \DOMElement $classAttributesNode
     *
     * @return void
     */
    public function mergeWithExisting(eZContentClass $class, DOMElement $classAttributesNode)
    {
        // Merged attribute container.
        $syncAttributesIdentifierList = [];

        // Loop through the XML-Nodes.
        /** @var DOMNode[] $classAttributeList */
        $classAttributeList = $classAttributesNode->getElementsByTagName( 'attribute' );

        foreach ($classAttributeList as $classAttributeNode) {

            // If the attribute does not support serialization and imports. Skip it.
            $isNotSupported = strtolower($classAttributeNode->getAttribute('unsupported')) == 'true';
            if ($isNotSupported) {
                continue;
            }

            // Get all informations about the attribute.
            $attributeDatatype                  = $classAttributeNode->getAttribute('datatype');
            $attributeIsRequired                = strtolower($classAttributeNode->getAttribute('required')) == 'true';
            $attributeIsSearchable              = strtolower($classAttributeNode->getAttribute('searchable')) == 'true';
            $attributeIsInformationCollector    = strtolower($classAttributeNode->getAttribute('information-collector')) == 'true';
            $attributeIsTranslatable            = strtolower( $classAttributeNode->getAttribute('translatable')) == 'true';
            $attributeSerializedNameListNode    = $classAttributeNode->getElementsByTagName('serialized-name-list')->item(0);
            $attributeSerializedNameListContent = $attributeSerializedNameListNode ? $this->unserializeJSON($attributeSerializedNameListNode->textContent) : false;
            $attributeSerializedNameList        = new eZSerializedObjectNameList( $attributeSerializedNameListContent );

            if ($attributeSerializedNameList->isEmpty()) {
                // for backward compatibility( <= 3.8 )
                $attributeSerializedNameList->initFromString(
                    $classAttributeNode->getElementsByTagName('name')->item(0)->textContent
                );
            }
            $attributeSerializedNameList->validate();

            $attributeSerializedDescriptionListNode    = $classAttributeNode->getElementsByTagName('serialized-description-list')->item(0);
            $attributeSerializedDescriptionListContent = $attributeSerializedDescriptionListNode ? $this->unserializeJSON($attributeSerializedDescriptionListNode->textContent) : false;
            $attributeSerializedDescriptionList        = new eZSerializedObjectNameList($attributeSerializedDescriptionListContent);

            $attributeCategoryNode = $classAttributeNode->getElementsByTagName('category')->item(0);
            $attributeCategory     = $attributeCategoryNode ? $attributeCategoryNode->textContent : '';

            $attributeSerializedDataTextNode    = $classAttributeNode->getElementsByTagName('serialized-description-text')->item(0);
            $attributeSerializedDataTextContent = $attributeSerializedDataTextNode ? $this->unserializeJSON($attributeSerializedDataTextNode->textContent) : false;
            $attributeSerializedDataText        = new eZSerializedObjectNameList($attributeSerializedDataTextContent);

            $attributeIdentifier            = $classAttributeNode->getElementsByTagName('identifier')->item(0)->textContent;
            $attributePlacement             = $classAttributeNode->getElementsByTagName('placement')->item(0)->textContent;
            $attributeDatatypeParameterNode = $classAttributeNode->getElementsByTagName('datatype-parameters')->item(0);

            // Collect all needed Parameters in an array.
            $attributeParameters = [
                'version'                     => 0,
                'identifier'                  => $attributeIdentifier,
                'serialized_name_list'        => $attributeSerializedNameList->serializeNames(),
                'serialized_description_list' => $attributeSerializedDescriptionList->serializeNames(),
                'category'                    => $attributeCategory,
                'serialized_data_text'        => $attributeSerializedDataText->serializeNames(),
                'is_required'                 => $attributeIsRequired,
                'is_searchable'               => $attributeIsSearchable,
                'is_information_collector'    => $attributeIsInformationCollector,
                'can_translate'               => $attributeIsTranslatable,
                'placement'                   => $attributePlacement
            ];

            // Search for the attribute in existing class.
            $classAttribute = $class->fetchAttributeByIdentifier($attributeIdentifier);

            // Detect a change of data-type. If is changed, remove it and all existing object attribute of it.
            if ($classAttribute instanceof eZContentClassAttribute && $classAttribute->DataTypeString !== $attributeDatatype) {
                $oldDatatype = $classAttribute->DataTypeString;

                // Remove existing object attribute
                foreach (eZContentObjectAttribute::fetchSameClassAttributeIDList($classAttribute->attribute('id')) as $objectAttribute)
                {
                    /** @var eZContentObjectAttribute $objectAttribute */
                    $objectAttribute->removeThis($objectAttribute->attribute('id'));
                }

                // Remove attribute and clear.
                $classAttribute->removeThis();
                $classAttribute = null;

                eZDebug::writeNotice(
                    "*Attribute $attributeIdentifier in class ".
                    $class->attribute( 'identifier' ).
                    ' has changed the datatype from '.
                    $oldDatatype.' to '.
                    $attributeDatatype.'.',
                    __METHOD__);
            }

            // If the attribute was found, we override the params.
            if ($classAttribute instanceof eZContentClassAttribute) {
                foreach($attributeParameters as $key => $value) {

                    // Special handling of certain default-fields, which are not covered by setAttribute.
                    switch($key) {
                        case 'serialized_name_list':
                            $nameList = new eZSerializedObjectNameList();
                            $nameList->initFromSerializedList($value);

                            if (!$nameList->isEmpty()) {
                                $classAttribute->setName($nameList->name(), $nameList->defaultLanguageLocale());
                            }
                            break;

                        case 'serialized_description_list':
                            $nameList = new eZSerializedObjectNameList();
                            $nameList->initFromSerializedList($value);

                            if (!$nameList->isEmpty()) {
                                $classAttribute->setDescription($nameList->name(), $nameList->defaultLanguageLocale());
                            }
                            break;

                        case 'serialized_data_text':
                            $nameList = new eZSerializedObjectNameList();
                            $nameList->initFromSerializedList($value);

                            if (!$nameList->isEmpty()) {
                                $classAttribute->setDataTextI18n($nameList->name(), $nameList->defaultLanguageLocale());
                            }
                            break;

                        default:
                            $classAttribute->setAttribute($key, $value);
                    }
                }

                // We need to Update the datatype-parameters, in case there is a change.
                $dataType = $classAttribute->dataType();
                if ($dataType) {
                    $dataType->unserializeContentClassAttribute($classAttribute, $classAttributeNode, $attributeDatatypeParameterNode);
                }

                $classAttribute->store();
                eZDebug::writeNotice(
                    "*Attribute $attributeIdentifier in class ".$class->attribute('identifier').' was merged.',
                    __METHOD__
                );
            } else { // Create new class-attribute if nothing was found.
                $classAttribute = eZContentClassAttribute::create(
                    $class->attribute('id'),
                    $attributeDatatype,
                    $attributeParameters
                );
                $dataType = $classAttribute->dataType();
                $classAttribute->store();

                if (!$dataType) {
                    continue;
                }

                $dataType->unserializeContentClassAttribute(
                    $classAttribute,
                    $classAttributeNode,
                    $attributeDatatypeParameterNode
                );
                $classAttribute->sync();
                $classAttribute->initializeObjectAttributes();
                eZDebug::writeNotice(
                    "+Attribute $attributeIdentifier in class ".$class->attribute('identifier').' created.',
                    __METHOD__
                );
            }
            $syncAttributesIdentifierList[] = $attributeIdentifier;
        }

        // Store the class with new attributes.
        $class->store();

        // Get now the existing attributes in class.
        $existingClassAttributes = $class->fetchAttributes();

        // Loop throug the existing attributes and check if available in new xml.
        foreach ($existingClassAttributes as $classAttribute) {
            // Skip processing if error in class-definition was found.
            if (!$classAttribute instanceof eZContentClassAttribute) {
                eZDebug::writeWarning( "Fetched attribute not instanceof eZContentClassAttribute.", __METHOD__ );
                continue;
            }

            // If the current attribute was not synced - remove it and all existing object attribute of it.
            $attributeIdentifier = $classAttribute->attribute('identifier');
            if (!in_array( $attributeIdentifier, $syncAttributesIdentifierList)) {
                eZDebug::writeNotice(
                    "-Attribute $attributeIdentifier in class ".$class->attribute('identifier').' removed.',
                    __METHOD__
                );

                // Remove existing object attribute
                foreach (eZContentObjectAttribute::fetchSameClassAttributeIDList($classAttribute->attribute('id')) as $objectAttribute) {
                    /** @var eZContentObjectAttribute $objectAttribute */
                    $objectAttribute->removeThis($objectAttribute->attribute('id'));
                }

                // Remove class attribute
                $classAttribute->removeThis();
            }
        }
    }

    public function unserializeJSON($str) {

        $result = json_decode((string)$str, true);

        if ($result !== null) {
            return serialize($result);
        }

        $result = unserialize($str);
        if ($result !== false) {
            return $str;
        }

        return serialize(array());
    }

    /*!
     \static
     Adds the content class with ID \a $classID to the package.
     If \a $classIdentifier is \c false then it will be fetched from the class.
    */
    static function addClass( $package, $classID, $classIdentifier = false )
    {
        $class = false;
        if ( is_numeric( $classID ) )
            $class = eZContentClass::fetch( $classID );
        if ( !$class )
            return;
        $classNode = eZContentClassPackageHandler::classDOMTree( $class );
        if ( !$classNode )
            return;
        if ( !$classIdentifier )
            $classIdentifier = $class->attribute( 'identifier' );
        $package->appendInstall( 'ezcontentclass', false, false, true,
                                 'class-' . $classIdentifier, 'ezcontentclass',
                                 array( 'content' => $classNode ) );
        $package->appendProvides( 'ezcontentclass', 'contentclass', $class->attribute( 'identifier' ) );
        $package->appendInstall( 'ezcontentclass', false, false, false,
                                 'class-' . $classIdentifier, 'ezcontentclass',
                                 array( 'content' => false ) );
    }

    function handleAddParameters( $packageType, $package, $cli, $arguments )
    {
        return $this->handleParameters( $packageType, $package, $cli, 'add', $arguments );
    }

    /*!
     \private
    */
    function handleParameters( $packageType, $package, $cli, $type, $arguments )
    {
        $classList = false;
        foreach ( $arguments as $argument )
        {
            if ( $argument[0] == '-' )
            {
                if ( strlen( $argument ) > 1 and
                     $argument[1] == '-' )
                {
                }
                else
                {
                }
            }
            else
            {
                if ( $classList === false )
                {
                    $classList = array();
                    $classArray = explode( ',', $argument );
                    $error = false;
                    foreach ( $classArray as $classID )
                    {
                        if ( in_array( $classID, $classList ) )
                        {
                            $cli->notice( "Content class $classID already in list" );
                            continue;
                        }
                        if ( is_numeric( $classID ) )
                        {
                            if ( !eZContentClass::exists( $classID, 0, false, false ) )
                            {
                                $cli->error( "Content class with ID $classID does not exist" );
                                $error = true;
                            }
                            else
                            {
                                unset( $class );
                                $class = eZContentClass::fetch( $classID );
                                $classList[] = array( 'id' => $classID,
                                                      'identifier' => $class->attribute( 'identifier' ),
                                                      'value' => $classID );
                            }
                        }
                        else
                        {
                            $realClassID = eZContentClass::exists( $classID, 0, false, true );
                            if ( !$realClassID )
                            {
                                $cli->error( "Content class with identifier $classID does not exist" );
                                $error = true;
                            }
                            else
                            {
                                unset( $class );
                                $class = eZContentClass::fetch( $realClassID );
                                $classList[] = array( 'id' => $realClassID,
                                                      'identifier' => $class->attribute( 'identifier' ),
                                                      'value' => $classID );
                            }
                        }
                    }
                    if ( $error )
                        return false;
                }
            }
        }
        if ( $classList === false )
        {
            $cli->error( "No class ids chosen" );
            return false;
        }
        return array( 'class-list' => $classList );
    }

    /*!
     \static
     Creates the DOM tree for the content class \a $class and returns the root node.
    */
    static function classDOMTree( $class )
    {
        if ( !$class )
        {
            $retValue = false;
            return $retValue;
        }

        $dom = new DOMDocument( '1.0', 'utf-8' );
        $classNode = $dom->createElement( 'content-class' );
        $dom->appendChild( $classNode );

        $serializedNameListNode = $dom->createElement( 'serialized-name-list' );
        $serializedNameListNode->appendChild( $dom->createTextNode( $class->attribute( 'serialized_name_list' ) ) );
        $classNode->appendChild( $serializedNameListNode );

        $identifierNode = $dom->createElement( 'identifier' );
        $identifierNode->appendChild( $dom->createTextNode( $class->attribute( 'identifier' ) ) );
        $classNode->appendChild( $identifierNode );

        $serializedDescriptionListNode = $dom->createElement( 'serialized-description-list' );
        $serializedDescriptionListNode->appendChild( $dom->createTextNode( $class->attribute( 'serialized_description_list' ) ) );
        $classNode->appendChild( $serializedDescriptionListNode );

        $remoteIDNode = $dom->createElement( 'remote-id' );
        $remoteIDNode->appendChild( $dom->createTextNode( $class->attribute( 'remote_id' ) ) );
        $classNode->appendChild( $remoteIDNode );

        $objectNamePatternNode = $dom->createElement( 'object-name-pattern' );
        $objectNamePatternNode->appendChild( $dom->createTextNode( $class->attribute( 'contentobject_name' ) ) );
        $classNode->appendChild( $objectNamePatternNode );

        $urlAliasPatternNode = $dom->createElement( 'url-alias-pattern' );
        $urlAliasPatternNode->appendChild( $dom->createTextNode( $class->attribute( 'url_alias_name' ) ) );
        $classNode->appendChild( $urlAliasPatternNode );

        $isContainer = $class->attribute( 'is_container' ) ? 'true' : 'false';
        $classNode->setAttribute( 'is-container', $isContainer );
        $classNode->setAttribute( 'always-available', $class->attribute( 'always_available' ) ? 'true' : 'false' );
        $classNode->setAttribute( 'sort-field', eZContentObjectTreeNode::sortFieldName( $class->attribute( 'sort_field' ) ) );
        $classNode->setAttribute( 'sort-order', $class->attribute( 'sort_order' ) );

        // Remote data start
        $remoteNode = $dom->createElement( 'remote' );
        $classNode->appendChild( $remoteNode );

        $ini = eZINI::instance();
        $siteName = $ini->variable( 'SiteSettings', 'SiteURL' );

        $classURL = 'http://' . $siteName . '/class/view/' . $class->attribute( 'id' );
        $siteURL = 'http://' . $siteName . '/';

        $siteUrlNode = $dom->createElement( 'site-url' );
        $siteUrlNode->appendChild( $dom->createTextNode( $siteURL ) );
        $remoteNode->appendChild( $siteUrlNode );

        $urlNode = $dom->createElement( 'url' );
        $urlNode->appendChild( $dom->createTextNode( $classURL ) );
        $remoteNode->appendChild( $urlNode );

        $classGroupsNode = $dom->createElement( 'groups' );

        $classGroupList = eZContentClassClassGroup::fetchGroupList( $class->attribute( 'id' ),
                                                                    $class->attribute( 'version' ) );
        foreach ( $classGroupList as $classGroupLink )
        {
            $classGroup = eZContentClassGroup::fetch( $classGroupLink->attribute( 'group_id' ) );
            if ( $classGroup )
            {
                unset( $groupNode );
                $groupNode = $dom->createElement( 'group' );
                $groupNode->setAttribute( 'id', $classGroup->attribute( 'id' ) );
                $groupNode->setAttribute( 'name', $classGroup->attribute( 'name' ) );
                $classGroupsNode->appendChild( $groupNode );
            }
        }
        $remoteNode->appendChild( $classGroupsNode );

        $idNode = $dom->createElement( 'id' );
        $idNode->appendChild( $dom->createTextNode( $class->attribute( 'id' ) ) );
        $remoteNode->appendChild( $idNode );
        $createdNode = $dom->createElement( 'created' );
        $createdNode->appendChild( $dom->createTextNode( $class->attribute( 'created' ) ) );
        $remoteNode->appendChild( $createdNode );
        $modifiedNode = $dom->createElement( 'modified' );
        $modifiedNode->appendChild( $dom->createTextNode( $class->attribute( 'modified' ) ) );
        $remoteNode->appendChild( $modifiedNode );

        $creatorNode = $dom->createElement( 'creator' );
        $remoteNode->appendChild( $creatorNode );
        $creatorIDNode = $dom->createElement( 'user-id' );
        $creatorIDNode->appendChild( $dom->createTextNode( $class->attribute( 'creator_id' ) ) );
        $creatorNode->appendChild( $creatorIDNode );
        $creator = $class->attribute( 'creator' );
        if ( $creator )
        {
            $creatorLoginNode = $dom->createElement( 'user-login' );
            $creatorLoginNode->appendChild( $dom->createTextNode( $creator->attribute( 'login' ) ) );
            $creatorNode->appendChild( $creatorLoginNode );
        }

        $modifierNode = $dom->createElement( 'modifier' );
        $remoteNode->appendChild( $modifierNode );
        $modifierIDNode = $dom->createElement( 'user-id' );
        $modifierIDNode->appendChild( $dom->createTextNode( $class->attribute( 'modifier_id' ) ) );
        $modifierNode->appendChild( $modifierIDNode );
        $modifier = $class->attribute( 'modifier' );
        if ( $modifier )
        {
            $modifierLoginNode = $dom->createElement( 'user-login' );
            $modifierLoginNode->appendChild( $dom->createTextNode( $modifier->attribute( 'login' ) ) );
            $modifierNode->appendChild( $modifierLoginNode );
        }
        // Remote data end

        $attributesNode = $dom->createElementNS( 'http://ezpublish/contentclassattribute', 'ezcontentclass-attribute:attributes' );
        $classNode->appendChild( $attributesNode );

        $attributes = $class->fetchAttributes();
        foreach( $attributes as $attribute )
        {
            $attributeNode = $dom->createElement( 'attribute' );
            $attributeNode->setAttribute( 'datatype', $attribute->attribute( 'data_type_string' ) );
            $required = $attribute->attribute( 'is_required' ) ? 'true' : 'false';
            $attributeNode->setAttribute( 'required' , $required );
            $searchable = $attribute->attribute( 'is_searchable' ) ? 'true' : 'false';
            $attributeNode->setAttribute( 'searchable' , $searchable );
            $informationCollector = $attribute->attribute( 'is_information_collector' ) ? 'true' : 'false';
            $attributeNode->setAttribute( 'information-collector' , $informationCollector );
            $translatable = $attribute->attribute( 'can_translate' ) ? 'true' : 'false';
            $attributeNode->setAttribute( 'translatable' , $translatable );

            $attributeRemoteNode = $dom->createElement( 'remote' );
            $attributeNode->appendChild( $attributeRemoteNode );

            $attributeIDNode = $dom->createElement( 'id' );
            $attributeIDNode->appendChild( $dom->createTextNode( $attribute->attribute( 'id' ) ) );
            $attributeRemoteNode->appendChild( $attributeIDNode );

            $attributeSerializedNameListNode = $dom->createElement( 'serialized-name-list' );
            $attributeSerializedNameListNode->appendChild( $dom->createTextNode( $attribute->attribute( 'serialized_name_list' ) ) );
            $attributeNode->appendChild( $attributeSerializedNameListNode );

            $attributeIdentifierNode = $dom->createElement( 'identifier' );
            $attributeIdentifierNode->appendChild( $dom->createTextNode( $attribute->attribute( 'identifier' ) ) );
            $attributeNode->appendChild( $attributeIdentifierNode );

            $attributeSerializedDescriptionListNode = $dom->createElement( 'serialized-description-list' );
            $attributeSerializedDescriptionListNode->appendChild( $dom->createTextNode( $attribute->attribute( 'serialized_description_list' ) ) );
            $attributeNode->appendChild( $attributeSerializedDescriptionListNode );

            $attributeCategoryNode = $dom->createElement( 'category' );
            $attributeCategoryNode->appendChild( $dom->createTextNode( $attribute->attribute( 'category' ) ) );
            $attributeNode->appendChild( $attributeCategoryNode );

            $attributeSerializedDataTextNode = $dom->createElement( 'serialized-data-text' );
            $attributeSerializedDataTextNode->appendChild( $dom->createTextNode( $attribute->attribute( 'serialized_data_text' ) ) );
            $attributeNode->appendChild( $attributeSerializedDataTextNode );

            $attributePlacementNode = $dom->createElement( 'placement' );
            $attributePlacementNode->appendChild( $dom->createTextNode( $attribute->attribute( 'placement' ) ) );
            $attributeNode->appendChild( $attributePlacementNode );

            $attributeParametersNode = $dom->createElement( 'datatype-parameters' );
            $attributeNode->appendChild( $attributeParametersNode );

            $dataType = $attribute->dataType();
            if ( is_object( $dataType ) )
            {
                $dataType->serializeContentClassAttribute( $attribute, $attributeNode, $attributeParametersNode );
            }

            $attributesNode->appendChild( $attributeNode );
        }
        eZDebug::writeDebug( $dom->saveXML(), 'content class package XML' );
        return $classNode;
    }

    function contentclassDirectory()
    {
        return 'ezcontentclass';
    }
}

?>
