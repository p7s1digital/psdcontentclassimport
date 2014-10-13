<?php
/**
 * Manages basic operations on the XML-based package-repository.
 *
 * @author Oliver Erdmann, <o.erdmann@finaldream.de>
 * @since  10.02.14
 */


class psdPackageRepository
{

    /**
     * Local path to the repository.
     *
     * @var string
     */
    protected $path;


    /**
     * Constructor.
     *
     * @param string $path Repository to load.
     *
     * @throws Exception If path is not valid.
     */
    public function __construct($path)
    {
        $this->getRealPath($path);
    }


    /**
     * Retrieves all package-folders in the repository.
     *
     * @param boolean $fullPath Returns paths including repository, if true or only the package-name, if false.
     *
     * @return string[] Array of Package-folders (REPO/PACKAGE if $fullPath, or PACKAGE, if !$fullPath).
     * @throws Exception If opening the folder fails.
     */
    public function getPackagePaths($fullPath = true)
    {

        $result = array();

        if (is_file($this->path)) {
            $pathInfo = pathinfo($this->path);
            if ($fullPath) {
                return array($pathInfo['dirname']);
            } else {
                $pathArray = explode('/', $pathInfo['dirname']);
                return array(array_pop($pathArray));
            }

        }

        $handle = opendir($this->path);

        if (!$handle) {
            throw new Exception(sprintf('Failed opening repository path %s.', $this->path));
        }

        while (false !== ($entry = readdir($handle))) {

            if ($entry == '.'
                || $entry == '..'
                || !is_dir($this->path.DIRECTORY_SEPARATOR.$entry)
            ) {
                continue;
            }

            if ($fullPath) {
                $result[] = $this->path.DIRECTORY_SEPARATOR.$entry;
            } else {
                $result[] = $entry;
            }
        }

        closedir($handle);

        return $result;

    }


    /**
     * Finds the content-class definitions inside all packages.
     *
     * @return string[] Array of paths to the respective XML-Files (REPO/PACKAGE/class-ID.xml)
     */
    public function getContentClassDefinitionFiles()
    {
        $packages = $this->getPackagePaths(false);
        $result   = array();

        foreach ($packages as $packageFolder) {

            $pkg = psdPackage::createFromPath($this->path, $packageFolder);

            // Currently content-classes only.
            $installItems = $pkg->installItemsList('ezcontentclass');


            // Loop all install-items and collect the paths to their definitions.
            foreach ($installItems as $item) {

                if (!isset($item['sub-directory']) || !isset($item['filename'])) {
                    continue;
                }

                $file = implode(
                    DIRECTORY_SEPARATOR,
                    array(
                        $this->path,
                        $packageFolder,
                        $item['sub-directory'],
                        $item['filename'].'.xml'
                    )
                );

                if (file_exists($file)) {
                    $result[] = $file;
                }

            }

        }//end foreach

        return $result;

    }


    /**
     * Queries all content-class definitions in the repo for their class-identifier.
     *
     * @return string[] Of class-identifiers provided by the repository.
     */
    public function getAvailableClassIdentifiers()
    {

        $definitions = $this->getContentClassDefinitionFiles();
        $result      = array();

        // Do this extra loop to get the class-name, because you shouldn't try guessing it from the package-name.
        foreach ($definitions as $file) {

            $def      = new psdContentClassDefinition($file);
            $result[] = $def->getClassIdentifier();

        }

        return $result;

    }


    /**
     * Returns the update-status for the current repository.
     *
     * @return array Keys represent changed class-names, values contain the kind of change (modified, removed, new)
     */
    public function getUpdateStatus()
    {

        $result = array();
        $files  = $this->getPackagePaths();

        // Check modified-date.
        foreach ($files as $file) {
            $pkg = new psdContentClassPackage();

            if (!$pkg->loadFromPath($file)) {
                continue;
            }

            if ($pkg->packageNeedsUpdate()) {
                $result[$pkg->getPackageName()] = 'modified';
            }

        }//end foreach

        // Check for new/old classes.
        $contentClasses = eZContentClass::fetchAllClasses();
        $repoClassIds   = $this->getAvailableClassIdentifiers();
        $dbClassIds     = array();

        // Prepare for next steps.
        foreach ($contentClasses as $class) {
            $dbClassIds[] = $class->attribute('identifier');
        }

        // Check for removed classes.
        $removed = array_diff($dbClassIds, $repoClassIds);
        foreach ($removed as $class) {
            $result[$class] = 'removed';
        }

        // Check for new classes.
        $new = array_diff($repoClassIds, $dbClassIds);
        foreach ($new as $class) {
            $result[$class] = 'new';
        }

        return $result;

    }

    /**
     * Get realpath of given path.
     *
     * @param string $path Path
     *
     * @return void
     *
     * @throws Exception if no valid path exist.
     */
    protected function getRealPath($path)
    {
        if (empty($path)) {
            $path = $this->getPathFromIni();
        }

        $this->path = realpath($path);

        if (!file_exists($this->path)) {
            if (!is_dir($this->path) && !is_file($this->path)) {
                throw new Exception(sprintf('%s is not a valid directory or file', $this->path));
            }
        }
    }


    /**
     * Get path from ini.
     *
     * @return string Path
     */
    protected function getPathFromIni()
    {
        $path = '';

        $ini = eZINI::instance('psdcontentclassimport.ini');

        if ($ini->hasVariable('ContentClassImportSettings', 'DefaultPackagePath')) {
            $path = $ini->variable('ContentClassImportSettings', 'DefaultPackagePath');
        }

        return $path;
    }


}
