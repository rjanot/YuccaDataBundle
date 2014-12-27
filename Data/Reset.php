<?php
namespace Yucca\Bundle\DataBundle\Data;

use \Yucca\Component\ConnectionManager;
use \Symfony\Component\Console\Output\OutputInterface;

/**
 * Class Reset
 *
 * @package Yucca\Bundle\DataBundle\Data
 */
class Reset
{
    /**
     * @var \Yucca\Component\ConnectionManager
     */
    protected $yuccaConnectionManager;
    protected $yuccaConnectionsConfiguration;

    protected $schemaPath;
    protected $dataPath;

    protected $createSchemaPatchesSql = 'CREATE TABLE IF NOT EXISTS `schema_patches` (`filename` varchar(255) NOT NULL, PRIMARY KEY (`filename`)) ENGINE=InnoDB DEFAULT CHARSET=utf8;';

    /**
     * @var \Symfony\Component\Console\Output\OutputInterface
     */
    protected $output;

    /**
     * @param \Yucca\Component\ConnectionManager $yuccaConnectionManager
     * @param array                              $yuccaConnectionsConfiguration
     * @param string                             $schemaPath
     * @param string                             $dataPath
     */
    public function __construct(ConnectionManager $yuccaConnectionManager, array $yuccaConnectionsConfiguration, $schemaPath, $dataPath)
    {
        $this->yuccaConnectionManager = $yuccaConnectionManager;
        $this->yuccaConnectionsConfiguration = $yuccaConnectionsConfiguration;
        $this->schemaPath = $schemaPath;
        $this->dataPath = $dataPath;
    }

    /**
     * reset Schema
     */
    public function resetSchema($addDatas = true)
    {
        foreach ($this->yuccaConnectionsConfiguration as $connectionName => $connection) {
            if ($connection['type'] != 'doctrine' || empty($connection['options']['dbname'])) {
                continue;
            }
            if ($connection['options']['driver'] !=='pdo_mysql') {
                throw new \Exception('Can\'t handle '.$connection['options']['driver']);
            }

            $this->yuccaConnectionManager->getConnection($connectionName)->exec(
                sprintf('DROP DATABASE IF EXISTS `%s`', $connection['options']['dbname'])
            );
            $this->yuccaConnectionManager->getConnection($connectionName)->exec(
                sprintf('CREATE DATABASE `%s` DEFAULT CHARACTER SET utf8 COLLATE utf8_general_ci;', $connection['options']['dbname'])
            );
            $this->yuccaConnectionManager->getConnection($connectionName)->exec(
                sprintf('USE `%s`', $connection['options']['dbname'])
            );

            //Import main db definition
            $this->log('import schema for "'.$connectionName.'"', 'comment');
            $this->importFileToMysql(
                $connection,
                $this->schemaPath.DIRECTORY_SEPARATOR.$connectionName.'.sql'
            );

            //Import patches
            $this->patchSchema($connection, $connectionName);

            //Import datas
            if ( $addDatas ) {
                $this->log('import datas for "'.$connectionName, 'comment');
                $this->importFileToMysql(
                    $connection,
                    sprintf($this->dataPath, $connectionName)
                );
            }
        }
    }

    /**
     * @throws \Exception
     */
    public function patch()
    {
        foreach ($this->yuccaConnectionsConfiguration as $connectionName => $connection) {
            if ($connection['type'] != 'doctrine' || empty($connection['options']['dbname'])) {
                continue;
            }
            if ($connection['options']['driver'] !=='pdo_mysql') {
                throw new \Exception('Can\'t handle '.$connection['options']['driver']);
            }

            //Import patches
            $this->patchSchema($connection, $connectionName);
        }
    }

    /**
     * @param array  $connection
     * @param string $connectionName
     */
    protected function patchSchema($connection, $connectionName)
    {
        /**
         * @var $directory \DirectoryIterator|\DirectoryIterator[]
         */
        $directory = new \DirectoryIterator($this->schemaPath.DIRECTORY_SEPARATOR.$connectionName);
        $filesToImport = array();
        foreach ($directory as $file) {
            //removes . and ..
            if ($file->isDot()) {
                continue;
            }
            if (strripos($file, ".sql")==true) {
                $filesToImport[$file->getFilename()] = $file->getPathname();
            }
        }
        asort($filesToImport);


        $this->yuccaConnectionManager->getConnection($connectionName)->exec($this->createSchemaPatchesSql);
        $schemaPatchesStmt = $this->yuccaConnectionManager->getConnection($connectionName)->fetchAll(
            'SELECT `filename` FROM `schema_patches`'
        );

        $schemaPatches = array();
        foreach ($schemaPatchesStmt as $patch) {
            $schemaPatches[] = $patch['filename'];
        }

        $filesToImport = array_diff($filesToImport, $schemaPatches);

        foreach ($filesToImport as $fileName => $filePath) {
            if (in_array($fileName, $schemaPatches)) {
                $this->log('Patch '.$fileName.' already applied', 'info');
                continue;
            }
            $this->log('Patch schema with '.$fileName, 'comment');
            $this->importFileToMysql(
                $connection,
                $filePath
            );
            $this->yuccaConnectionManager->getConnection($connectionName)->insert(
                'schema_patches',
                array('filename'=>$fileName)
            );
        }
    }

    /**
     * @param array  $connection
     * @param string $file
     */
    protected function importFileToMysql($connection, $file)
    {
        exec(sprintf(
            'mysql -u"%s" -p"%s" --port="%s" -h"%s" "%s" --default-character-set="%s" < %s',
            $connection['options']['user'],
            $connection['options']['password'],
            $connection['options']['port'],
            $connection['options']['host'],
            $connection['options']['dbname'],
            $connection['options']['charset'],
            $file
        ));
    }

    /**
     * @param \Symfony\Component\Console\Output\OutputInterface $output
     *
     * @return $this
     */
    public function setOutput(OutputInterface $output)
    {
        $this->output = $output;

        return $this;
    }

    /**
     * @param string      $message
     * @param string|null $style
     *
     * @return $this
     */
    protected function log($message, $style=null)
    {
        if (isset($this->output)) {
            if ($style) {
                $this->output->writeln(sprintf('<%s>%s</%s>', $style, $message, $style));
            } else {
                $this->output->writeln($message);
            }
        }

        return $this;
    }
}
