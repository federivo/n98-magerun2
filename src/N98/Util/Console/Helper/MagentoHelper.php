<?php

namespace N98\Util\Console\Helper;

use N98\Magento\Application;
use N98\Util\BinaryString;
use RuntimeException;
use Symfony\Component\Console\Helper\Helper as AbstractHelper;
use Symfony\Component\Console\Input\ArgvInput;
use Symfony\Component\Console\Output\ConsoleOutput;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Input\InputInterface;

/**
 * Class MagentoHelper
 *
 * @package N98\Util\Console\Helper
 */
class MagentoHelper extends AbstractHelper
{
    /**
     * @var string
     */
    protected $_magentoRootFolder = null;

    /**
     * @var int
     */
    protected $_magentoMajorVersion = \N98\Magento\Application::MAGENTO_MAJOR_VERSION_1;

    /**
     * @var bool
     */
    protected $_magentoEnterprise = false;

    /**
     * @var bool
     */
    protected $_magerunStopFileFound = false;

    /**
     * @var string
     */
    protected $_magerunStopFileFolder = null;

    /**
     * @var InputInterface
     */
    protected $input;

    /**
     * @var OutputInterface
     */
    protected $output;

    /**
     * @var array
     */
    protected $baseConfig = array();

    /**
     * @var string
     */
    protected $_customConfigFilename = 'n98-magerun2.yaml';

    /**
     * Returns the canonical name of this helper.
     *
     * @return string The canonical name
     *
     * @api
     */
    public function getName()
    {
        return 'magento';
    }

    /**
     * @param InputInterface $input
     * @param OutputInterface $output
     */
    public function __construct(InputInterface $input = null, OutputInterface $output = null)
    {
        if (null === $input) {
            $input = new ArgvInput();
        }

        if (null === $output) {
            $output = new ConsoleOutput();
        }

        $this->input = $input;
        $this->output = $output;
    }

    /**
     * Start Magento detection
     *
     * @param string $folder
     * @param array $subFolders [optional] sub-folders to check
     * @return bool
     */
    public function detect($folder, array $subFolders = array())
    {
        $folders = $this->splitPathFolders($folder);
        $folders = $this->checkMagerunFile($folders);
        $folders = $this->checkModman($folders);
        $folders = array_merge($folders, $subFolders);

        foreach (array_reverse($folders) as $searchFolder) {
            if (!is_dir($searchFolder) || !is_readable($searchFolder)) {
                continue;
            }

            $found = $this->_search($searchFolder);
            if ($found) {
                return true;
            }
        }

        return false;
    }


    /**
     * @return string
     */
    public function getRootFolder()
    {
        return $this->_magentoRootFolder;
    }

    public function getEdition()
    {
        return $this->_magentoMajorVersion;
    }

    /**
     * @return bool
     */
    public function isEnterpriseEdition()
    {
        return $this->_magentoEnterprise;
    }

    /**
     * @return int
     */
    public function getMajorVersion()
    {
        return $this->_magentoMajorVersion;
    }

    /**
     * @return boolean
     */
    public function isMagerunStopFileFound()
    {
        return $this->_magerunStopFileFound;
    }

    /**
     * @return string
     */
    public function getMagerunStopFileFolder()
    {
        return $this->_magerunStopFileFolder;
    }

    /**
     * @param string $folder
     *
     * @return array
     */
    protected function splitPathFolders($folder)
    {
        $folders = array();

        $folderParts = explode('/', $folder);
        foreach ($folderParts as $key => $part) {
            $explodedFolder = implode('/', array_slice($folderParts, 0, $key + 1));
            if ($explodedFolder !== '') {
                $folders[] = $explodedFolder;
            }
        }

        return $folders;
    }

    /**
     * Check for modman file and .basedir
     *
     * @param array $folders
     *
     * @return array
     */
    protected function checkModman(array $folders)
    {
        foreach (array_reverse($folders) as $searchFolder) {
            if (!is_readable($searchFolder)) {
                if (OutputInterface::VERBOSITY_DEBUG <= $this->output->getVerbosity()) {
                    $this->output->writeln(
                        '<debug>Folder <info>' . $searchFolder . '</info> is not readable. Skip.</debug>'
                    );
                }
                continue;
            }

            $finder = Finder::create();
            $finder
                ->files()
                ->ignoreUnreadableDirs(true)
                ->depth(0)
                ->followLinks()
                ->ignoreDotFiles(false)
                ->name('.basedir')
                ->in($searchFolder);

            $count = $finder->count();
            if ($count > 0) {
                $baseFolderContent = trim(file_get_contents($searchFolder . '/.basedir'));
                if (OutputInterface::VERBOSITY_DEBUG <= $this->output->getVerbosity()) {
                    $this->output->writeln(
                        '<debug>Found modman .basedir file with content <info>' . $baseFolderContent . '</info></debug>'
                    );
                }

                if (!empty($baseFolderContent)) {
                    array_push($folders, $searchFolder . '/../' . $baseFolderContent);
                }
            }
        }

        return $folders;
    }

    /**
     * Check for magerun stop-file
     *
     * @param array $folders
     *
     * @return array
     */
    protected function checkMagerunFile(array $folders)
    {
        foreach (array_reverse($folders) as $searchFolder) {
            if (!is_readable($searchFolder)) {
                if (OutputInterface::VERBOSITY_DEBUG <= $this->output->getVerbosity()) {
                    $this->output->writeln(
                        sprintf('<debug>Folder <info>%s</info> is not readable. Skip.</debug>', $searchFolder)
                    );
                }
                continue;
            }
            $stopFile = '.' . pathinfo($this->_customConfigFilename, PATHINFO_FILENAME);
            $finder = Finder::create();
            $finder
                ->files()
                ->ignoreUnreadableDirs(true)
                ->depth(0)
                ->followLinks()
                ->ignoreDotFiles(false)
                ->name($stopFile)
                ->in($searchFolder);

            $count = $finder->count();
            if ($count > 0) {
                $this->_magerunStopFileFound  = true;
                $this->_magerunStopFileFolder = $searchFolder;
                $magerunFilePath              = $searchFolder . '/' . $stopFile;
                $magerunFileContent           = trim(file_get_contents($magerunFilePath));
                if (OutputInterface::VERBOSITY_DEBUG <= $this->output->getVerbosity()) {
                    $message = sprintf(
                        '<debug>Found stopfile \'%s\' file with content <info>%s</info></debug>',
                        $stopFile,
                        $magerunFileContent
                    );
                    $this->output->writeln($message);
                }

                array_push($folders, $searchFolder . '/' . $magerunFileContent);
            }
        }

        return $folders;
    }

    /**
     * @param string $searchFolder
     *
     * @return bool
     */
    protected function _search($searchFolder)
    {
        if (OutputInterface::VERBOSITY_DEBUG <= $this->output->getVerbosity()) {
            $this->output->writeln('<debug>Search for Magento in folder <info>' . $searchFolder . '</info></debug>');
        }

        if (!is_dir($searchFolder . '/app')) {
            return false;
        }

        $finder = Finder::create();
        $finder
            ->ignoreUnreadableDirs(true)
            ->depth(0)
            ->followLinks()
            ->name('Mage.php')
            ->name('bootstrap.php')
            ->name('autoload.php')
            ->in($searchFolder . '/app');

        if ($finder->count() > 0) {
            $files = iterator_to_array($finder, false);
            /* @var $file \SplFileInfo */

            $hasMageFile = false;
            foreach ($files as $file) {
                if ($file->getFilename() == 'Mage.php') {
                    $hasMageFile = true;
                }
            }

            $this->_magentoRootFolder = $searchFolder;

            // Magento 2 does not have a god class and thus if this file is not there it is version 2
            if ($hasMageFile == false) {
                $this->_magentoMajorVersion = Application::MAGENTO_MAJOR_VERSION_2;
            } else {
                $this->_magentoMajorVersion = Application::MAGENTO_MAJOR_VERSION_1;
            }

            if (OutputInterface::VERBOSITY_DEBUG <= $this->output->getVerbosity()) {
                $this->output->writeln(
                    '<debug>Found Magento in folder <info>' . $this->_magentoRootFolder . '</info></debug>'
                );
            }

            return true;
        }

        return false;
    }

    /**
     * @return array
     * @throws RuntimeException
     */
    public function getBaseConfig()
    {
        if (!$this->baseConfig) {
            $this->initBaseConfig();
        }

        return $this->baseConfig;
    }

    private function initBaseConfig()
    {
        $this->baseConfig = [];

        $application = $this->getApplication();

        $configFiles = [
            'app/etc/config.php',
            'app/etc/env.php'
        ];

        foreach ($configFiles as $configFileName) {
            $this->addBaseConfig($application->getMagentoRootFolder(), $configFileName);
        }
    }

    /**
     * private getter for application that has magento detected
     *
     * @return Application|\Symfony\Component\Console\Application
     */
    private function getApplication()
    {
        $command = $this->getHelperSet()->getCommand();
        if ($command == null) {
            $application = new Application();
        } else {
            $application = $command->getApplication(); /* @var $application Application */
        }
        $application->detectMagento();

        return $application;
    }

    private function addBaseConfig($root, $configFileName)
    {
        $configFile = $root . '/' . $configFileName;
        if (!(is_file($configFile) && is_readable($configFile))) {
            throw new RuntimeException(sprintf('%s is not readable', $configFileName));
        }

        $config = @include $configFile;

        if (!is_array($config)) {
            throw new RuntimeException(sprintf('%s is corrupted. Please check it.', $configFileName));
        }

        $this->baseConfig = array_merge($this->baseConfig, $config);
    }
}
