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

    }


    /**
     * Applies some transformations to the XML with the goal to make the code a bit easier to edit.
     *
     * Requires a preceding call of load() in order to operate on something.
     * The transformations are: converting all serialized fields to JSON, setting a new modified-date.
     *
     * @return void
     */
    public function transformXML()
    {

        $dom = $this->openXMLFile($this->fileName);

        $xPath = new DOMXPath($dom);
        // Select all nodes with names that start with "serialized-".
        $elements = $xPath->query('//*[starts-with(name(), "serialized-")]');

        $this->logLine('Transforming '.$elements->length.' elements in '.$this->fileName, __METHOD__);

        foreach ($elements as $element) {
            if (!empty($element->textContent)) {
                $val                = $this->reSerializeString($element->textContent);
                $element->nodeValue = htmlentities($val, ENT_NOQUOTES, 'UTF-8');
            }
        }

        $this->updateCreatedFromDOM($dom);
        $this->updateModifiedFromDOM($dom);

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

        if (empty($timeStamp)) {
            $timeStamp = time();
        }

        $this->logLine('New timestamp for modified-date: '.$timeStamp.'.', __METHOD__);

        if ($nodes->length > 0) {
            $nodes->item(0)->nodeValue = $timeStamp;
            $this->logLine(sprintf('New Modified timestamp: %s', $timeStamp), __METHOD__);
        }

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

        if (empty($timeStamp)) {
            $timeStamp = time();
        }

        $this->logLine('New timestamp for created-date: '.$timeStamp.'.', __METHOD__);

        if ($nodes->length > 0) {
            $nodes->item(0)->nodeValue = $timeStamp;
            $this->logLine(sprintf('New Created timestamp: %d', $timeStamp), __METHOD__);
        }

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