<?php
/**
 * Created by IntelliJ IDEA.
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
     * Lists absolute paths to all packages in the repository.
     *
     * @var string[]
     */
    protected $packagePaths = array();


    /**
     * Constructor.
     *
     * @param string $path Repository to load.
     *
     * @throws Exception If path is not valid.
     */
    public function __construct($path)
    {

        $this->path = realpath($path);

        if (!file_exists($this->path) || !is_dir($this->path)) {
            throw new Exception(sprintf('%s is not a valid directory', $this->path));
        }

    }


    /**
     * Retrieves all package-folders in the repository.
     *
     * @return string[] Array of Package-folders (REPO/PACKAGE)
     * @throws Exception If opening the folder fails.
     */
    public function getPackagePaths()
    {

        if (!empty($this->packagePaths)) {
            return $this->packagePaths;
        }

        $this->packagePaths = array();

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

            $this->packagePaths[] = $this->path.DIRECTORY_SEPARATOR.$entry;
        }

        closedir($handle);

        return $this->packagePaths;

    }


    /**
     * Finds the content-class definitions inside all packages.
     *
     * @return string[] Array of paths to the respective XML-Files (REPO/PACKAGE/class-ID.xml)
     */
    public function getContentClassDefinitionFiles()
    {
        $packages = $this->getPackagePaths();
        $result   = array();

        foreach ($packages as $packageFolder) {

            $pkg = psdPackage::createFromPath($packageFolder);

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

        foreach ($definitions as $file) {

            $def      = new psdContentClassDefinition($file);
            $result[] = $def->getClassIdentifier();

        }

        return $result;

    }


}