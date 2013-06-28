<?php
/**
 * The class psdPackage is sub-classed from eZPackages. It overrides a few methods for providing bug-fixes or extending
 * them in a required way. For PSD-Projects, especially for handling text-based packages, use this class rather the
 * original implementation.
 *
 * User: Oliver Erdmann, o.erdmann@finaldream.de
 * Date: 13.09.12
 * Time: 19:22
 */

class psdPackage extends eZPackage
{

    /**
     * XPATH-Constants.
     */
    const XPATH_MODIFIED   = "/content-class/remote/modified";
    const XPATH_IDENTIFIER = "/content-class/identifier";

    /**
     * Set this to true before installing packages. If true, only newer content-classes are installed (by comparing the
     * modified-date against the one, currently installed).
     *
     * @var bool
     */
    public $checkForInstalledVersion = false;


    /**
     * Reason of override:
     * Needed a way for skipping the installation of content-classes that don't have changed.
     *
     * @param $item
     * @param $installParameters
     *
     * @return bool
     * @throws psdPackageSkipException
     */
    public function installItem( $item, &$installParameters )
    {
        $type = $item['type'];
        $name = $item['name'];
        $os = $item['os'];
        $filename = $item['filename'];
        $subdirectory = $item['sub-directory'];
        $content = false;
        if ( isset( $item['content'] ) )
            $content = $item['content'];
        $handler = $this->packageHandler( $type );
        $installResult = false;

        // Can be overridden if the version-check fails.
        $skip = false;
        if ( $handler )
        {
            if ( $handler->extractInstallContent() )
            {
                if ( !$content and
                     $filename )
                {
                    if ( $subdirectory )
                        $filepath = $subdirectory . '/' . $filename . '.xml';
                    else
                        $filepath = $filename . '.xml';

                    $filepath = $this->path() . '/' . $filepath;

                    $dom = eZPackage::fetchDOMFromFile( $filepath );
                    if ( $dom )
                    {
                        $content = $dom->documentElement;
                        if ($this->checkForInstalledVersion) {
                            $skip = $this->isRecentVersionInstalled($dom);
                        }
                    }
                    else
                    {
                        eZDebug::writeError( "Failed fetching dom from file $filepath", __METHOD__ );
                    }
                }
            }

            // Break here, if version-check failed.
            if ($skip) {
                throw new psdPackageSkipException();
            }

            $installData =& $this->InstallData[$type];
            if ( !isset( $installData ) )
                $installData = array();

            $installResult = $handler->install( $this, $type, $item,
                                                $name, $os, $filename, $subdirectory,
                                                $content, $installParameters,
                                                $installData );
        }
        return $installResult;
    }

    /*
     * Install all install items in package.
     *
     * Reason of override:
     * There is a bug in eZ System's original implementation, which rendered this function useless for our needs.
     */
    public function uninstall( $uninstallParameters = array() )
    {
        if ( $this->Parameters['install_type'] != 'install' )
            return;
        if ( !$this->isInstalled() )
            return;
        // Here, an empty function is accessed, which won't return anything useful.
        $uninstallItems = $this->Parameters['uninstall'];
        if ( !isset( $installParameters['path'] ) )
            $installParameters['path'] = false;

        $uninstallResult = true;
        foreach ( $uninstallItems as $item )
        {
            if ( !$this->uninstallItem( $item, $uninstallParameters ) )
            {
                $uninstallResult = false;
            }
        }

        $this->InstallData = array();
        $this->setInstalled( false );
        return $uninstallResult;
    }


    /**
     * Reads the modified-date from a DOMDocument.
     *
     * @param DOMDocument $dom
     *
     * @return int Timestamp.
     */
    public function getModifiedFromDOM(DOMDocument $dom) {

        $xPath  = new DOMXPath($dom);
        $nodes  = $xPath->query(self::XPATH_MODIFIED);
        $result = 0;

        if ($nodes->length > 0) {
            $result = intval($nodes->item(0)->nodeValue);
        }

        return $result;

    }


    /**
     * Reads the modified-date from a given file-name.
     *
     * @param $fileName
     *
     * @return int Timestamp.
     */
    public function getModifiedFromFile($fileName) {

        $result = 0;

        $dom = eZPackage::fetchDOMFromFile($fileName);
        if (!$dom) {
            return $result;
        }

        return $this->getModifiedFromDOM($dom);
    }


    /**
     * Get the modified-date for a currently installed content-class.
     *
     * @param $identifer
     *
     * @return int
     */
    public function getCurrentModifiedForClass($identifer){

        $class = eZContentClass::fetchByIdentifier($identifer);

        if ($class instanceof eZContentClass) {
            return $class->Modified;
        }

        return 0;
    }


    /**
     * Matches the modified-dates of a content-class definition (represented by it's DOM) against it's currently
     * installed version.
     *
     * @param DOMDocument $dom Content-Class definition.
     *
     * @return bool Matches or not.
     */
    public function isRecentVersionInstalled(DOMDocument $dom) {

        $xPath          = new DOMXPath($dom);
        $identifierNode = $xPath->query(self::XPATH_IDENTIFIER);
        $modifiedNode   = $xPath->query(self::XPATH_MODIFIED);
        $result         = false;

        if ($identifierNode->length > 0 && $modifiedNode->length > 0) {


            $modified   = $modifiedNode->item(0)->nodeValue;
            $identifier = $identifierNode->item(0)->nodeValue;

            $current = $this->getCurrentModifiedForClass($identifier);
            $result  = strcasecmp($current, $modified) >= 0;
        }

        return $result;

    }
}


/**
 * Exception that is used to promote the "skipped" state to the outside without further overrides.
 */
class psdPackageSkipException extends Exception {}