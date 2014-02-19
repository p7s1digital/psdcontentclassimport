<?php

// Get eZ!
require_once 'autoload.php';


/**
 * Command-line interface that provides access to package-handling functions for text-based (non-binary) packages.
 *
 * @author Oliver Erdmann, o.erdmann@finaldream.de
 */
class psdPackagesCLI
{

    const MSG_CANT_REMOVE_CLASS = 'Cannot remove class %s.';

    /**
     * Properties for eZScript.
     *
     * @var array
     */
    public $scriptSettings = array(
        'description'    => 'Provides a CLI for text-based content-class installation.',
        'use-session'    => false,
        'use-modules'    => false,
        'use-extensions' => true,
    );

    /**
     * The eZScript instance.
     *
     * @var eZScript|null
     */
    protected $script = null;

    /**
     * Commanline interface for console output.
     *
     * @var eZCLI|null
     */
    protected $cli = null;

    /**
     * Holds the processed cli-arguments.
     *
     * @var array
     */
    protected $arguments = array();

    /**
     * Switch between shut-up and talkative.
     *
     * @var boolean
     */
    protected $verbose = false;

    /**
     * Constructor.
     */
    public function __construct()
    {

    }


    /**
     * Main execution loop. Specifies the command-line arguments and loops through a set of functions, each picking
     * their options.
     *
     * May exit with error-code 1, which means an eZDBNoConnectionException occurred. This can happen on the initial
     * import.
     *
     * @param array|boolean $arguments An optional array for providing arguments and therefore bypassing the
     *                                 commandline.
     *
     * @return void
     */
    public function main($arguments = false)
    {

        $this->cli = eZCLI::instance();

        // Register CLI-arguments and handlers.
        if (empty($arguments)) {

            $this->arguments = getopt(
                '',
                array(
                    'extract:',
                    'update-modified:',
                    'update-status:',
                    'install:',
                    'uninstall:',
                    'clean-up:',
                    'siteaccess:',
                    'change-object:',
                    'change-node:',
                    'ignore-version::',
                    'identifier:',
                    'verbose::',
                    'force-remove-class:',
                    'repository-path::',
                )
            );

        } else {
            $this->arguments = $arguments;
        }//end if

        $handlers = array(
            array($this, 'doExtract'),
            array($this, 'doUpdateModified'),
            array($this, 'doInstall'),
            array($this, 'doUninstall'),
            array($this, 'doChangeObject'),
            array($this, 'doChangeNode'),
            array($this, 'doUpdateStatus'),
            array($this, 'doCleanUp'),
            array($this, 'doForceRemoveClass'),
        );


        // Initialize Script and run handlers.
        $this->initScript();

        // Do a database-check. We can only proceed if a database and tables exist.
        if (!$this->checkTableExists('ezcontentclass')) {

            $this->verbose = true;
            $this->logLine('PSD Packages CLI: Database is empty, nothing to do here!');
            return;

        }

        if (is_array($this->arguments) && count($this->arguments) > 0) {

            $this->verbose = array_key_exists('verbose', $this->arguments);

            foreach ($handlers as $handler) {
                if (call_user_func($handler) === true) {
                    $this->shutdownScript();
                    return;
                }
            }

        }

        $this->printHelp();

    }


    /**
     * Checks if a specified table exists on the database. Database-settings are read from the current siteaccess.
     *
     * @param string $table Name of the table to test.
     *
     * @return boolean True if database and table exists, otherwise false.
     */
    protected function checkTableExists($table)
    {

        $ini = eZINI::instance('site.ini');

        list($server, $port, $user, $pwd, $db)
            = $ini->variableMulti('DatabaseSettings', array('Server', 'Port', 'User', 'Password', 'Database'));

        if (!empty($port)) {
            $server .= ':'.$port;
        }

        $conn = mysqli_connect($server, $user, $pwd);

        if (!$conn) {
            return false;
        }

        $val = $this->mysqlQueryFetch(sprintf('SHOW DATABASES LIKE \'%s\';', $db), $conn);

        if (empty($val)) {
            return false;
        }

        mysqli_select_db($conn, $db);

        $val = $this->mysqlQueryFetch(sprintf('SHOW TABLES WHERE Tables_in_%s=\'%s\';', $db, $table), $conn);

        if (empty($val)) {
            return false;
        }

        return true;

    }


    /**
     * Combines a mysql query and a fetch.
     *
     * @param string   $query The MYSQL-query.
     * @param resource $conn  Database-connection.
     *
     * @return mixed The result of the query or false, if the query failed.
     */
    protected function mysqlQueryFetch($query, $conn)
    {

        $res = mysqli_query($conn, $query);

        if (empty($res)) {
            return false;
        }

        $val = mysqli_fetch_array($res);

        return $val;

    }


    /**
     * Init eZScript for functions the need db-access, only initializes once.
     * This function needs the --siteaccess argument set, if left blank, the DefaultAccess from site.ini is used.
     *
     * @return void
     */
    public function initScript()
    {

        if ($this->script instanceof eZScript) {
            return;
        }

        if (array_key_exists('siteaccess', $this->arguments)) {
            $this->scriptSettings['site-access'] = $this->arguments['siteaccess'];
        } else {
            $ini                                 = eZINI::instance('site.ini');
            $this->scriptSettings['site-access'] = $ini->variable('SiteSettings', 'DefaultAccess');
        }

        $this->script = eZScript::instance($this->scriptSettings);

        $this->script->startup();
        $this->script->initialize();

        $this->logLine('Initializing. Using siteaccess '.$this->scriptSettings['site-access'], __METHOD__);

    }


    /**
     * Shut's down the script, if available.
     *
     * @return void
     */
    public function shutdownScript()
    {

        if ($this->script) {
            $this->script->shutdown();
        }

    }


    /**
     * Extract-handler. Executed if --extract 'PATH' is specified in command-line.
     * Arguments need a key "extract".
     *
     * @return boolean Indicates success.
     */
    public function doExtract()
    {

        if (is_array($this->arguments) && array_key_exists('extract', $this->arguments)) {
            $pattern = $this->arguments['extract'];
        }

        if (!empty($pattern)) {

            $this->logLine('Extract binary package: '.$this->collapseArray($this->arguments), __METHOD__);

            $pkg = new psdContentClassPackage($this->verbose);
            $pkg->extractAndTransform($pattern);
            return true;
        }

        return false;

    }


    /**
     * Installs all packages covered by the given path.
     * Arguments require the key "install optional key "ignore-version".
     *
     * @return boolean
     */
    public function doInstall()
    {

        if (!array_key_exists('install', $this->arguments)) {
            return false;
        }

        // Support multiple files using wildcards.
        $files = glob($this->arguments['install']);

        foreach ($files as $file) {
            $pkg = new psdContentClassPackage($this->verbose);

            if (!$pkg->loadFromPath($file)) {
                continue;
            }

            $checkVersion = true;

            if (array_key_exists('ignore-version', $this->arguments) === true) {
                $checkVersion = false;
            }

            $this->logLine('Install package: '.$this->collapseArray($this->arguments), __METHOD__);

            $result = false;
            try {
                $result = $pkg->install($checkVersion);
            }
            catch(Exception $e) {
                if ($e instanceof psdPackageSkipException) {
                    $this->logLine('Skipped.', true);
                    continue;
                }
            }

            if ($result === true) {
                $this->cli->output('Installed Package '.$pkg->getPackageName(), true);
            } else {
                $this->cli->output('Failed installing package '.$pkg->getPackageName(), true);
            }
        }//end foreach

        return true;

    }


    /**
     * Uninstalls the packages covered by a given path.
     * Arguments require key "uninstall".
     *
     * @return boolean
     */
    public function doUninstall()
    {

        if (array_key_exists('uninstall', $this->arguments)) {

            $pkg = new psdContentClassPackage($this->verbose);

            $pkg->loadFromPath($this->arguments['uninstall']);

            $this->cli->output('Uninstalling '.$pkg->getPackageName(), true);
            $this->logLine('Uninstall package: '.$this->collapseArray($this->arguments), __METHOD__);

            if ($pkg->uninstall()) {
                $this->cli->output('Success.', true);
            } else {
                $this->cli->output('Failed.', true);
            };

            return true;
        }

        return false;

    }


    /**
     * Removes content-classes without an XML-definition from the CMS.
     * Only "removable" classes are removed (@see eZContentClass->isRemovable).
     * Arguments require key "clean-up".
     *
     * @return boolean
     */
    public function doCleanUp()
    {

        if (!array_key_exists('clean-up', $this->arguments)) {
            return false;
        }

        $contentClasses = eZContentClass::fetchAllClasses();

        $repository  = new psdPackageRepository($this->arguments['clean-up']);
        $repoClasses = $repository->getAvailableClassIdentifiers();

        foreach ($contentClasses as $class) {

            $id = $class->attribute('identifier');
            if (!in_array($id, $repoClasses)) {


                if ($class->isRemovable()) {
                    $this->cli->output('Removing class '.$id, true);
                    $class->remove(true);

                    continue;
                }

                $this->cli->output(sprintf(self::MSG_CANT_REMOVE_CLASS, $id), true);

            }
        }//end foreach

        return true;

    }


    /**
     * Removes content-classes without an XML-definition from the CMS.
     * Only "removable" classes are removed (@see eZContentClass->isRemovable).
     * Arguments require key "forced-remove-class" with value of content class identifier.
     *
     * @return boolean
     *
     * @throws \Exception
     */
    public function doForceRemoveClass()
    {

        if (!array_key_exists('force-remove-class', $this->arguments)) {
            return false;
        }

        if (!array_key_exists('repository-path', $this->arguments)) {
            return false;
        }

        // Get/check id.
        $contentClass = \eZContentClass::fetchByIdentifier($this->arguments['force-remove-class']);
        if (!$contentClass instanceof \eZContentClass) {
            throw new \Exception(
                sprintf(
                    'Content class "%s" was not found!',
                    $this->arguments['force-remove-class']
                )
            );
        }

        // Remove all content object instances.
        $instances = \eZContentObject::fetchSameClassList($contentClass->attribute('id'));

        if (count($instances) > 0) {
            if (!array_key_exists('dryrun', $this->arguments)) {
                $this->logLine(
                    sprintf(
                        'Found %d instances of "%s". Removing...',
                        count($instances),
                        $this->arguments['force-remove-class']
                    )
                );
                foreach ($instances as $instance) {
                    $instance->remove();
                }
            }
        }

        // Now remove the content class forced without checking isRemovable.
        $contentClass = \eZContentClass::fetch($contentClass->attribute('id'));
        if (!array_key_exists('dryrun', $this->arguments)) {
            if ($contentClass instanceof \eZContentClass) {
                $this->logLine(
                    sprintf(
                        'Removing content class definition "%s".',
                        $this->arguments['force-remove-class']
                    )
                );
                $contentClass->remove();
            } else {
                $this->logLine(
                    sprintf(
                        'Content class "%s" does not exist (anymore).',
                        $this->arguments['force-remove-class']
                    )
                );
            }
        }

        return true;

    }


    /**
     * Updates the modified-date for a given content-class definition.
     * Arguments require key "update-modified".
     *
     * @return boolean
     */
    public function doUpdateModified()
    {

        if (array_key_exists('update-modified', $this->arguments)) {

            if (file_exists($this->arguments['update-modified'])) {

                $this->logLine('Update Modified: '.$this->collapseArray($this->arguments), __METHOD__);

                $class = new psdContentClassDefinition($this->arguments['update-modified'], $this->verbose);
                $class->updateModified();
            } else {
                $this->cli->output('Failed.', true);
            }

            return true;
        }

        return false;

    }


    /**
     * Modifies an object from cli.
     *
     * Arguments require keys "change-object" and "identifier" set.
     *
     * @return boolean
     */
    public function doChangeObject()
    {

        if (array_key_exists('change-object', $this->arguments)) {

            $this->logLine('Change Object: '.$this->collapseArray($this->arguments), __METHOD__);

            $pkg = new psdContentClassPackage($this->verbose);
            $pkg->changeClassIdentifierOfObject($this->arguments['change-object'], $this->arguments['identifier']);

            return true;
        }

        return false;

    }


    /**
     * Modifies a node from cli.
     * Arguments require keys "change-node" and "identifier" set
     *
     * @throws Exception If node does not exists.
     *
     * @return boolean
     */
    public function doChangeNode()
    {

        if (array_key_exists('change-node', $this->arguments)) {

            $nodeId = $this->arguments['change-node'];
            if (!is_numeric($nodeId)) {
                $nodeId = eZURLAliasML::fetchNodeIDByPath($nodeId);
            }

            $node = eZContentObjectTreeNode::fetch($nodeId);

            if ($node === null) {
                throw new Exception(sprintf('Node %s not found.', $nodeId));
            }

            if ($node instanceof eZContentObjectTreeNode) {

                $this->logLine('Change Node: '.$this->collapseArray($this->arguments), __METHOD__);

                $object = $node->object();

                $pkg = new psdContentClassPackage($this->verbose);
                $pkg->changeClassIdentifierOfObject($object->attribute('id'), $this->arguments['identifier']);
            }

            return true;
        }//end if

        return false;

    }


    /**
     * Outputs the packages that need to be updated. If there are packages that need to be updated, the count and the
     * packages names are output. If all packages are up to date, the string "Packages are up to date." is output.
     *
     * @return boolean
     */
    public function doUpdateStatus()
    {

        if (!array_key_exists('update-status', $this->arguments)) {
            return false;
        }

        $repository = new psdPackageRepository($this->arguments['update-status']);
        $status     = $repository->getUpdateStatus();

        if (empty($status)) {
            $this->cli->output('Packages are up to date.', true);
        } else {

            ksort($status);

            $this->cli->output('Packages modified: '.count($status), true);

            foreach ($status as $name => $state) {
                $this->cli->output(sprintf('%s (%s)', $name, $state), true);
            }
        }

        return true;

    }


    /**
     * Output's the script's help-text.
     *
     * @return void
     */
    public function printHelp()
    {
        $lines = '
            PSD Packages CLI.

            Commandline-Interface for managing text-based packages and content-class definitions.

            ARGUMENTS
            --change-object   ID     Modifies an object with the given Object-ID. Must be used in conjunction with a
                                     "verb" such as: --identifier.
            --change-node     ID|URL-ALIAS
                                     Modifies an node with the given Node-ID or an eZ-Publish url-alias.
                                     Must be used in conjunction with a "verb" such as: --identifier.
            --ignore-version         Goes together with --install, installs every class, no matter what modified-date.
            --extract         PATH   Path or pattern with wildcards of .ezpkg-files to extract. Packages are extracted
                                     in the package\'s location. Version-numbers separated by a - are stripped from the
                                     new folder\'s name.
            --identifier      STRING Name of an existing class-identifier. Use with --change-object or --change-node
                                     to change the content-class of an object to the given one.
            --install         PATH   Path or wildcard-pattern of package-folder(s) to install. Installs all available
                                     classes, skips classes that have the same or newer version installed. Checks are
                                     performed against the modified-field of the content-class. Use --update-modified to
                                     change that date.
            --help                   This text.
                                     defined in the package.xml-structure. Will overwrite existing classes, unless the
                                     option --ignore-version is specified.
            --siteaccess     STRING  Siteaccess that will be needed to perform database-actions. If left blank, the
                                     DefaultAccess is taken from site.ini.
            --update-modified FILE   Sets the modified-date of a given class-file (not a package!) to now.
            --uninstall       PATH   Path or wildcard-pattern of package-folder(s) to uninstall. Will remove all
                                     content-classes, defined in the package.xml-structure.
                                     Keep in mind: only content-classes that don\'t have objects, will be removed.
                                     Requires the --siteaccess option set.
            --clean-up        REPO   Removes classes without existing package-definition from the CMS.
                                     Path specifies the packages to check.
                                     Only classes without objects will be removed.
            --update-status   REPO   Outputs the names of packages that need to be updated.
                                     Requires a path or wildcard-pattern of package-folder(s).
            --force-remove-class
                              STRING
                                     Explicitly removes a single content class including all of its instances. Use
                                     this with care because it will result in losing (a lot of) data.
                                     Requires the option --repository-path
            --repository-path STRING Used only in option --force-remove-class to provide the path to the class
                                     repository.
            --verbose                Keeps the script telling about what it\'s doing.

            DEFINITIONS:
             Package:                Is a folder, usually inside a repository, that may contain definitions of
                                     content-classes in XML-format and a package.xml with the overall
                                     package-definition. Is an XML-file with a detailed definition of a content-class.
                                     It must be installed into eZ publish, for changes to show up.
             PATH:                   Points to a folder or file and may contain wild-cards (eg. "*").
                                     Wild-cards are resolved and allow the script to process multiple files at once.
                                     In order to use wild-cards, you have to put the path in single- or double-quotes.
             REPO:                   Path to the package-repository. Must contain any number of package-folders.

            EXAMPLES:

            Convert all *.ezpkg packages into their folder-based counterparts:

                php bin/php/psdpackgescli.php --extract "path/to/repository/*.ezpgk"

            Update the modified-date for a content-class:

                php bin/php/psdpackgescli.php --update-modified "path/to/repository/mypkg/ezcontentclass/class-myclass.xml"

            Install all updated packages (skipping unchanged ones):

                php bin/php/psdpackgescli.php --install "path/to/repository/*" --siteaccess dev.project.de

            Find out if packages need to be updated:

                php bin/php/psdpackgescli.php --update-status "path/to/repository" --siteaccess dev.project.de

            Change the class-identifier of an existing object:

                php bin/php/psdpackgescli.php --change-object 1234 --identifier frontpage --siteaccess dev.project.de

            Removes all undefined classes from the CMS.

                php bin/php/psdpackgescli.php --clean-up "path/to/repository" --siteaccess dev.project.de';

            $this->cli->output($lines, true);

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


    /**
     * Creates a string of key=value-pairs from a simple associative array.
     *
     * @param array $array Must be one-dimensional, values should consist of simple data-types.
     *
     * @return string
     */
    public function collapseArray($array)
    {
        $result = '';

        if (!is_array($array)) {
            return $result;
        }

        foreach ($array as $key => $value) {
            $result .= $key.'='.$value.'; ';
        }

        return $result;

    }


}

// Run only if called directly from command-line.
if (count($_SERVER['argv']) > 0) {

    $info = pathinfo($_SERVER['argv'][0]);

    if ($info['basename'] !== 'psdpackagescli.php') {
        return;
    }

    $inst = new psdPackagesCLI();
    $inst->main();

}
