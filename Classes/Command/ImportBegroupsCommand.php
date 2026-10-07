<?php

namespace Npostnik\BePermissions\Command;

use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationExtensionNotConfiguredException;
use TYPO3\CMS\Core\Configuration\Exception\ExtensionConfigurationPathDoesNotExistException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Yaml\Yaml;
use TYPO3\CMS\Core\Configuration\ExtensionConfiguration;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Core\Database\ConnectionPool;

class ImportBegroupsCommand extends Command
{

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $this->importBegroups($output);

        return Command::SUCCESS;
    }

    /**
     * @param OutputInterface $output
     * @return int|null
     * @throws ExtensionConfigurationExtensionNotConfiguredException
     * @throws ExtensionConfigurationPathDoesNotExistException
     */
    protected function importBegroups($output)
    {
        $targetFolder = GeneralUtility::makeInstance(ExtensionConfiguration::class)
            ->get('be_permissions', 'targetFolder');
        if (empty($targetFolder)) {
            $output->writeLn('targetFolder is empty');
            return Command::FAILURE;
        }

        $targetFolder = GeneralUtility::getFileAbsFileName($targetFolder);

        $files = array_diff(scandir($targetFolder), ['..', '.']);

        $existingBegroup = $this->getExistingGroups();

        foreach ($files as $file) {
            $groupData = Yaml::parse(file_get_contents($targetFolder . $file));

            if(array_key_exists($groupData['uid'], $existingBegroup)) {
                $this->updateBegroup($groupData);
                $output->writeLn(sprintf('Update permissions for "%s" from %s', $groupData['title'], $file));
            } else {
                $this->insertBegroup($groupData);
                $output->writeLn(sprintf('Insert permissions for "%s" from %s', $groupData['title'], $file));
            }
        }
        return null;
    }

    protected function getExistingGroups(): array
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('be_groups');

        $groups = $queryBuilder
            ->select('uid', 'pid', 'title')
            ->from('be_groups')
            ->executeQuery()
            ->fetchAllAssociative();

        $begroups = [];
        foreach ($groups as $group) {
            $begroups[$group['uid']] = $group['title'];
        }
        return $begroups;
    }

    protected function updateBegroup($groupData)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('be_groups');

        $queryBuilder->update('be_groups');
        $queryBuilder->where(
            $queryBuilder->expr()->eq('uid', $queryBuilder->createNamedParameter($groupData['uid'], Connection::PARAM_INT))
        );
        foreach ($groupData as $key => $value) {
            if($key == 'uid') {
                continue;
            }
            if(is_array($value)) {
                $value = implode(',', $value);
            }
            $queryBuilder->set($key, $value);
        }
        // The export omits empty fields, so fields missing in the file have to be cleared explicitly
        foreach ($this->getClearableFields() as $field) {
            if (!array_key_exists($field, $groupData)) {
                $queryBuilder->set($field, '');
            }
        }
        $queryBuilder->executeStatement();
    }

    /**
     * List/text columns of be_groups that the export leaves out when empty.
     */
    protected function getClearableFields(): array
    {
        $fields = [
            'non_exclude_fields', 'explicit_allowdeny', 'allowed_languages', 'custom_options',
            'db_mountpoints', 'pagetypes_select', 'tables_select', 'tables_modify', 'groupMods',
            'file_mountpoints', 'file_permissions', 'category_perms', 'availableWidgets',
            'mfa_providers', 'TSconfig', 'description', 'subgroup',
        ];
        $columns = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable('be_groups')
            ->createSchemaManager()
            ->introspectTable('be_groups')
            ->getColumns();
        $existing = array_map(static fn($column) => $column->getName(), $columns);

        return array_values(array_intersect($fields, $existing));
    }

    protected function insertBegroup($groupData)
    {
        $queryBuilder = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getQueryBuilderForTable('be_groups');

        $queryBuilder->insert('be_groups');
        $insertValues = [
            'tstamp' => time(),
            'crdate' => time(),
            'workspace_perms' => 0,
        ];
        foreach ($groupData as $key => $value) {
            if($key == 'uid') {
                continue;
            }
            if(is_array($value)) {
                $value = implode(',', $value);
            }
            $insertValues[$key] = $value;
        }

        $queryBuilder->values($insertValues);
        $queryBuilder->executeStatement();
    }

}
