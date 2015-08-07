<?php

/**
 * Represents a wrapper for text-based content-class definitions (manifested by a class-file.xml inside of a package-
 * folder).
 *
 * @author Oliver Erdmann, o.erdmann@finaldream.de
 */
class psdContentClassDefinition
{

    /**
     * Filename of content-class definition.
     *
     * @var string
     */
    protected $fileName = '';

    /**
     * Class Identifier from XML-File.
     *
     * @var string
     */
    protected $classIdentifier = '';

    /**
     * Set true in order to output statistics.
     *
     * @var boolean
     */
    protected $verbose = false;

    /**
     * Commanline interface for console output.
     *
     * @var eZCLI|null
     */
    protected $cli = null;


    /**
     * Implies load().
     *
     * @param string  $fileName Filename to load.
     * @param boolean $verbose  Make cli output or not.
     */
    public function __construct($fileName, $verbose = false)
    {

        $this->cli     = eZCLI::instance();
        $this->verbose = $verbose;

        $this->load($fileName);

    }


    /**
     * Sets the context for a certain file.
     *
     * @param string $fileName File to further process.
     *
     * @throws Exception If file does not exists.
     *
     * @return void
     */
    public function load($fileName)
    {
        if (file_exists($fileName) === false) {
            throw new Exception(sprintf('File %s not found!', $fileName));
        }

        $this->fileName = $fileName;

        $this->loadClassInfo();

    }


    /**
     * Returns the class-identifier.
     *
     * @return string
     */
    public function getClassIdentifier()
    {

        return $this->classIdentifier;

    }


    /**
     * Applies some transformations to the XML with the goal to make the code a bit easier to edit.
     *
     * Requires a preceding call of load() in order to operate on something.
     * The transformations are:
     * - converting all serialized fields to JSON
     * - setting a new create- and modified-date
     * - normalize the attribute-placement
     * - add attribute comments.
     *
     * @return void
     */
    public function transformXML()
    {

        $dom = $this->openXMLFile($this->fileName);

        $this->updateSerializedFields($dom);
        $this->updateCreatedFromDOM($dom);
        $this->updateModifiedFromDOM($dom);
        $this->normalizePlacement($dom);
        $this->addAttributeInfoComments($dom);


        $dom->save($this->fileName);

    }


    /**
     * Opens an XML-file and returns the resulting DOMDocument.
     *
     * @param string $fileName The filename.
     *
     * @throws Exception If XML could not be parsed.
     *
     * @return DOMDocument
     */
    public function openXMLFile($fileName)
    {
        $dom = new DOMDocument('1.0', 'utf-8');

        $dom->preserveWhiteSpace = false;
        $dom->formatOutput       = true;

        $success = $dom->load($fileName);

        $this->logLine('Open XML file: '.$fileName, __METHOD__);

        if (!$success) {
            throw new Exception('Not an XML-Document! '.$fileName);
        }

        return $dom;

    }


    /**
     * Reads basic information on a class from the XML-definition.
     *
     * @return void
     */
    protected function loadClassInfo()
    {

        $dom   = $this->openXMLFile($this->fileName);
        $xPath = new DOMXPath($dom);
        $node  = $xPath->query(psdPackage::XPATH_IDENTIFIER);

        if ($node->length > 0) {
            $this->classIdentifier = $node->item(0)->nodeValue;
        }

    }


    /**
     * Transforms searialized Fields from PHP-Serialize to JSON. Recodes entities.
     *
     * @param DOMDocument $dom DOM to transform.
     *
     * @return void
     */
    public function updateSerializedFields(DOMDocument $dom)
    {

        $xPath = new DOMXPath($dom);
        // Select all nodes with names that start with "serialized-".
        $elements = $xPath->query('//*[starts-with(name(), "serialized-")]');

        if (!($elements instanceof DOMNodeList)) {
            return;
        }

        $this->logLine('Transforming '.$elements->length.' elements in '.$this->fileName, __METHOD__);

        foreach ($elements as $element) {
            if (!empty($element->textContent)) {
                $val                = $this->reSerializeString($element->textContent);
                $element->nodeValue = htmlentities($val, ENT_NOQUOTES, 'UTF-8');
            }
        }

    }


    /**
     * Updates the modified-field of a given DOM-Structure. Requires the DOM to be a Content-Class Definition.
     *
     * @param DOMDocument $dom       The DOM Document.
     * @param int         $timeStamp The unix timestamp.
     *
     * @return void
     */
    public function updateModifiedFromDOM(DOMDocument $dom, $timeStamp = 0)
    {

        $xPath = new DOMXPath($dom);
        $nodes = $xPath->query(psdPackage::XPATH_MODIFIED);

        if (!($nodes instanceof DOMNodeList) || $nodes->length < 1) {
            return;
        }

        if (empty($timeStamp)) {
            $timeStamp = time();
        }

        $nodes->item(0)->nodeValue = $timeStamp;
        $this->logLine(sprintf('New "Modified" timestamp: %s', $timeStamp), __METHOD__);

    }


    /**
     * Updates the modified-field for the current file. Requires a call of load().
     *
     * @param int $timeStamp The unix timestamp.
     *
     * @return void
     */
    public function updateModified($timeStamp = 0)
    {

        $dom = $this->openXMLFile($this->fileName);

        $this->logLine('Update modified-date for '.$this->fileName, __METHOD__);

        $this->updateModifiedFromDOM($dom, $timeStamp);
        $this->normalizePlacement($dom);
        $this->addAttributeInfoComments($dom);
        $this->translateNamespaces($dom);

        $dom->save($this->fileName);

    }


    /**
     * Updates the created-field of a given DOM-Structure. Requires the DOM to be a Content-Class Definition.
     *
     * @param DOMDocument $dom       The DOM Document.
     * @param int         $timeStamp The unix timestamp.
     *
     * @return void
     */
    public function updateCreatedFromDOM(DOMDocument $dom, $timeStamp = 0)
    {

        $xPath = new DOMXPath($dom);
        $nodes = $xPath->query(psdPackage::XPATH_MODIFIED);

        if (!($nodes instanceof DOMNodeList) || $nodes->length < 1) {
            return;
        }

        if (empty($timeStamp)) {
            $timeStamp = time();
        }

        $nodes->item(0)->nodeValue = $timeStamp;
        $this->logLine(sprintf('New "Created" timestamp: %d', $timeStamp), __METHOD__);

    }


    /**
     * Updates the modified-field for the current file. Requires a call of load().
     *
     * @param int $timeStamp The unix timestamp.
     *
     * @return void
     */
    public function updateCreated($timeStamp = 0)
    {

        $dom = $this->openXMLFile($this->fileName);

        $this->logLine('Update created-date for '.$this->fileName, __METHOD__);

        $this->updateCreatedFromDOM($dom, $timeStamp);

        $dom->save($this->fileName);

    }


    /**
     * Loops through all placement-tags and undates the numbering to an linear sequence.
     *
     * @param DOMDocument $dom The DOM Document.
     *
     * @return void.
     */
    public function normalizePlacement(DOMDocument $dom)
    {

        $xPath     = new DOMXPath($dom);
        $namespace = $dom->lookupNamespaceUri('ezcontentclass-attri');

        $xPath->registerNamespace('ezcontentclass-attri', $namespace);

        $nodes = $xPath->query(psdPackage::XPATH_PLACEMENT);

        if (!($nodes instanceof DOMNodeList) || $nodes->length < 1) {
            return;
        }

        $this->logLine('Update placement for: '.$nodes->length.' nodes.', __METHOD__);

        for ($i = 0, $j = $nodes->length; $i < $j; $i++) {
            $nodes->item($i)->nodeValue = $i + 1;
        }

    }


    /**
     * Loops through all placement-tags and undates the numbering to an linear sequence.
     *
     * @param DOMDocument $dom The DOM Document.
     *
     * @return void.
     */
    public function translateNamespaces(DOMDocument $dom)
    {

        $xPath = new DOMXPath($dom);
        // Select all nodes with names that start with "serialized-".
        $elements = $xPath->query('//*[starts-with(name(), "serialized-")]');

        if (!($elements instanceof DOMNodeList)) {
            return;
        }

        $this->logLine('Translate '.$elements->length.' elements in '.$this->fileName, __METHOD__);

        $currentLocaleCode = eZLocale::currentLocaleCode();

        /** @var eZContentLanguage[] $languageList */
        $languageList = eZContentLanguage::fetchList();
        $localeList   = [];

        foreach ($languageList as $language) {
            $localeList[] = $language->attribute('locale');
        }

        foreach ($elements as $element) {
            $content = json_decode($element->textContent, true);

            if (!empty($content[$currentLocaleCode])) {
                $defaultContent = $content[$currentLocaleCode];
            } else {
                $defaultContent = '';
            }

            foreach ($localeList as $locale) {
                if (empty($content[$locale])) {
                    $content[$locale] = $defaultContent;
                }
            }
            $content['always-available'] = $currentLocaleCode;

            $element->nodeValue = htmlentities(json_encode($content), ENT_NOQUOTES, 'UTF-8');
        }

    }


    /**
     * Inserts comments containing the attribute-identifier before each attribute-node.
     * This is done in order to find attributes more easily.
     *
     * @param DOMDocument $dom Dom to transform.
     *
     * @return void
     */
    public function addAttributeInfoComments(DOMDocument $dom)
    {

        $xPath     = new DOMXPath($dom);
        $namespace = $dom->lookupNamespaceUri('ezcontentclass-attri');

        $xPath->registerNamespace('ezcontentclass-attri', $namespace);

        $nodes = $xPath->query(psdPackage::XPATH_ATTRIBUTES);

        if (!($nodes instanceof DOMNodeList) || $nodes->length < 1) {
            return;
        }


        // Loop the ezcontentclass-attri:attributes nodes.
        for ($i = 0, $il = $nodes->length; $i < $il; $i++) {

            $attributes = $nodes->item($i);

            if (!($attributes instanceof DOMNode) || $attributes->childNodes->length < 1) {
                continue;
            }

            $children = array();

            // Cache the attributes in order to prevent the loop from being modified.
            for ($j = 0, $jl = $attributes->childNodes->length; $j < $jl; $j++) {

                $child = $attributes->childNodes->item($j);

                if ($child instanceof DOMNode && $child->nodeName == 'attribute') {
                    $children[] = $child;
                }

            }

            // Loop the attribute-nodes.
            foreach ($children as $child) {

                // If the current attribute-node is preceded by a comment, remove it first.
                if ($child->previousSibling instanceof DOMComment) {
                    $attributes->removeChild($child->previousSibling);
                }

                // Get the identifier.
                $identifier = $xPath->query('identifier', $child);

                if (!($identifier instanceof DOMNodeList) || $identifier->length < 1) {
                    continue;
                }

                $commentValue = sprintf(' %s ', $identifier->item(0)->nodeValue);

                $comment = new DOMComment($commentValue);

                // Add the generated comment before the attribute.
                $attributes->insertBefore($comment, $child);

            }//end foreach

        }//end for

    }


    /**
     * Tries to change a serialized string to JSON. If the input is not serialized, it's returned as is.
     *
     * @param string $str Serialized string.
     *
     * @return mixed|string
     */
    public function reSerializeString($str)
    {

        $result = @unserialize($str);

        if ($result === false) {
            $result = $str;
        } else {
            $result = json_encode($result);
        }

        return $result;

    }


    /**
     * Writes a line to the console if $verbose is enabled.
     *
     * @param string $str    Message to be written.
     * @param string $method Optional Method name, only used for debug-log.
     *
     * @return void
     */
    public function logLine($str, $method = '')
    {

        eZDebug::writeNotice('*'.__CLASS__.': '.$str, $method);

        if (!$this->verbose) {
            return;
        }

        $this->cli->output($str, true);

    }


}